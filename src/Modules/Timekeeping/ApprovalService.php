<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;
use App\Services\TimeApprovalPolicy;
use App\Services\TimeBillingAllocationService;
use App\Services\TimeSubmissionService;
use App\Services\WorkerEarningService;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final class ApprovalService
{
    private readonly TimeApprovalPolicy $policy;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditRecorder $audit,
        private readonly ApprovedTimeConsumer $billing,
        ?TimeApprovalPolicy $policy = null
    ) {
        $this->policy = $policy ?? new TimeApprovalPolicy($pdo);
    }

    public function approve(int $approverId, string $entryId): string
    {
        $this->policy->assertCanReviewEntry($approverId, $entryId, 'approve');
        return $this->finalize($approverId, $entryId, false);
    }

    public function selfConfirmOwner(int $ownerId, string $entryId, ?string $billingRateOverride = null): string
    {
        return $this->finalize($ownerId, $entryId, true, false, false, $billingRateOverride);
    }

    /**
     * Confirm time for an admin/owner account while retaining the worker
     * profile's ordinary compensation policy.
     */
    public function selfConfirmAdministrator(
        int $administratorId,
        string $entryId,
        ?string $billingRateOverride = null
    ): string
    {
        $this->policy->assertCanAdministrativelySelfConfirmEntry($administratorId, $entryId);
        return $this->finalize($administratorId, $entryId, false, false, true, $billingRateOverride);
    }

    public function canSelfConfirmAdministrator(int $userId): bool
    {
        return $this->policy->canAdministrativelySelfConfirm($userId);
    }

    /** Confirm a pending admin entry, or return its existing projection. */
    public function ensureAdministratorProjection(
        int $administratorId,
        string $entryId,
        ?string $billingRateOverride = null
    ): string
    {
        $this->policy->assertCanAdministrativelySelfConfirmEntry($administratorId, $entryId);
        $existing = $this->pdo->prepare(
            "SELECT t.status,t.workflow_status,s.id snapshot_id
             FROM work_time_entries t
             LEFT JOIN work_approval_snapshots s ON s.id=(
                SELECT s2.id FROM work_approval_snapshots s2
                WHERE s2.time_entry_id=t.id AND s2.voided_at IS NULL
                ORDER BY s2.entry_revision DESC LIMIT 1
             )
             WHERE t.id=? AND t.user_id=?"
        );
        $existing->execute([$entryId, $administratorId]);
        $entry = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            throw new DomainException('Administrative time entry not found.');
        }
        $snapshotId = (string)($entry['snapshot_id'] ?? '');
        if ($entry['status'] === 'approved' && $entry['workflow_status'] === 'confirmed' && $snapshotId !== '') {
            return $snapshotId;
        }
        return $this->selfConfirmAdministrator($administratorId, $entryId, $billingRateOverride);
    }

    /** Materialize or repair the immutable projection for verified-owner time. */
    public function ensureOwnerProjection(
        int $ownerId,
        string $entryId,
        ?string $billingRateOverride = null
    ): string
    {
        $existing = $this->pdo->prepare(
            "SELECT t.status,t.workflow_status,s.id snapshot_id
             FROM work_time_entries t
             LEFT JOIN work_approval_snapshots s ON s.id=(
                SELECT s2.id FROM work_approval_snapshots s2
                WHERE s2.time_entry_id=t.id AND s2.voided_at IS NULL
                ORDER BY s2.entry_revision DESC LIMIT 1
             )
             WHERE t.id=? AND t.user_id=?"
        );
        $existing->execute([$entryId, $ownerId]);
        $entry = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            throw new DomainException('Owner time entry not found.');
        }
        $snapshotId = (string)($entry['snapshot_id'] ?? '');
        if ($entry['status'] === 'approved' && $entry['workflow_status'] === 'confirmed' && $snapshotId !== '') {
            return $snapshotId;
        }
        return $this->finalize($ownerId, $entryId, true, true, false, $billingRateOverride);
    }

    public function canSelfConfirmOwner(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.role,wp.relationship_type,COALESCE(wp.relationship_review_required,0) relationship_review_required
             FROM users u
             LEFT JOIN worker_profiles wp ON wp.user_id=u.id AND wp.status='active'
             WHERE u.id=? AND u.deleted_at IS NULL AND u.is_disabled=0
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($identity)
            && ($identity['relationship_type'] ?? '') === 'owner'
            && empty($identity['relationship_review_required']);
    }

    /** @return array{confirmed:int,failed:list<string>} */
    public function reconcileVerifiedOwnerEntries(int $ownerId): array
    {
        if (!$this->canSelfConfirmOwner($ownerId)) {
            throw new DomainException('Verify the owner relationship before reconciling owner time.');
        }
        $statement = $this->pdo->prepare(
            "SELECT t.id
             FROM work_time_entries t
             JOIN pay_periods period
               ON DATE(t.start_time) BETWEEN period.period_start AND period.period_end
              AND period.status='open'
             WHERE t.user_id=?
               AND t.end_time IS NOT NULL
               AND t.duration_seconds>0
               AND t.status='review'
               AND t.workflow_status IN ('draft','returned','submitted')
             ORDER BY t.start_time,t.id"
        );
        $statement->execute([$ownerId]);
        $confirmed = 0;
        $failed = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
            try {
                $this->ensureOwnerProjection($ownerId, (string)$entryId);
                $confirmed++;
            } catch (Throwable $error) {
                $failed[] = (string)$entryId;
                @error_log('[owner-time-reconciliation] ' . $entryId . ': ' . $error->getMessage());
            }
        }
        return ['confirmed' => $confirmed, 'failed' => $failed];
    }

    /**
     * Keep an owner entry editable if snapshot materialization fails after the
     * capture service committed it. Owner entries are excluded from ordinary
     * self-review, so leaving one in review would make it unreachable.
     */
    public function returnOwnerForRepair(int $ownerId, string $entryId): void
    {
        if (!$this->canSelfConfirmOwner($ownerId)) {
            throw new DomainException('Only an owner can recover owner time confirmation.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM work_time_entries WHERE id=? AND user_id=? AND status='review' FOR UPDATE"
            );
            $stmt->execute([$entryId, $ownerId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Owner time is no longer awaiting confirmation.');
            }
            $reason = 'Owner confirmation could not be completed. Review the entry and resubmit it.';
            $this->pdo->prepare(
                "UPDATE work_time_entries
                 SET status='rejected',workflow_status='returned',owner_self_confirmed=0,
                     rejection_reason=?,reviewed_by=NULL,reviewed_at=NULL
                 WHERE id=?"
            )->execute([$reason, $entryId]);
            $this->audit->record(
                'time_entry.owner_confirmation_deferred',
                'work_time_entry',
                $entryId,
                $ownerId,
                $entry,
                ['status' => 'rejected', 'recoverable' => true]
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function finalize(
        int $approverId,
        string $entryId,
        bool $ownerSelfConfirmation,
        bool $allowLegacyApproved = false,
        bool $administrativeSelfConfirmation = false,
        ?string $billingRateOverride = null
    ): string
    {
        $billingRateOverride = $this->normalizeBillingRateOverride($billingRateOverride);
        $settings = WorkforceSettings::load($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT t.*,u.email,u.username,u.role user_role,ep.first_name,ep.last_name,ep.hourly_rate employee_rate,
                        wp.id worker_profile_id,wp.relationship_type,wp.compensation_policy,
                        COALESCE(wp.relationship_review_required,0) relationship_review_required,
                        jwc.catalog_work_component_id,
                        jwc.client_billing_rate_snapshot service_activity_billing_rate,
                        wtb.default_billing_rate work_type_billing_rate,
                        pa.pay_rate_override,p.name project_name,c.name client_name,
                        i.doc_number invoice_number,i.invoice_type
                 FROM work_time_entries t JOIN users u ON u.id=t.user_id
                 LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id
                 LEFT JOIN worker_profiles wp ON wp.user_id=t.user_id AND wp.status='active'
                 LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
                 LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
                 LEFT JOIN work_type_billing_defaults wtb ON wtb.work_type_id=t.work_type_id
                 LEFT JOIN projects p ON p.id=t.project_id
                 LEFT JOIN clients c ON c.id=t.client_id
                 LEFT JOIN invoices i ON i.id=t.invoice_id
                 LEFT JOIN project_assignments pa ON pa.project_id=t.project_id AND pa.user_id=t.user_id
                 WHERE t.id=? FOR UPDATE"
            );
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            $selfConfirmation = $ownerSelfConfirmation || $administrativeSelfConfirmation;
            $readyStatus = $entry && (
                ($entry['status'] === 'review'
                    && (($selfConfirmation && in_array((string)($entry['workflow_status'] ?? ''), ['draft','returned','submitted'], true))
                        || (!$selfConfirmation && (string)($entry['workflow_status'] ?? '') === 'submitted')))
                || ($ownerSelfConfirmation && $allowLegacyApproved
                    && $entry['status'] === 'approved' && !empty($entry['owner_self_confirmed']))
            );
            if (!$readyStatus || empty($entry['end_time'])) {
                throw new DomainException('Entry is not ready for approval.');
            }
            if ((int)$entry['user_id'] === $approverId && !$selfConfirmation) {
                throw new DomainException('You cannot approve your own time entry.');
            }
            if ($ownerSelfConfirmation) {
                $isOwner = ($entry['relationship_type'] ?? '') === 'owner'
                    && empty($entry['relationship_review_required']);
                if ((int)$entry['user_id'] !== $approverId || !$isOwner) {
                    throw new DomainException('Only an owner can self-confirm their own time entry.');
                }
            }
            if ($administrativeSelfConfirmation) {
                $isAdminRole = in_array((string)($entry['user_role'] ?? ''), ['admin', 'owner'], true);
                $isTimeManager = !$isAdminRole && WorkforceSettings::canManageAllTime($this->pdo, $approverId);
                if ((int)$entry['user_id'] !== $approverId
                    || (!$isAdminRole && !$isTimeManager)) {
                    throw new DomainException('Only an administrator or time manager can self-confirm their own time entry.');
                }
            }
            $entry['default_hourly_rate'] = $settings['default_hourly_rate'];
            $entry['default_billing_rate'] = $entry['service_activity_billing_rate']
                ?? $entry['work_type_billing_rate']
                ?? $settings['default_billing_rate'];
            $entry['currency'] = $settings['currency'];
            if ((int) $entry['revision'] > 1 && $this->hasPaidAccrual($entryId)) {
                throw new DomainException('Paid time cannot be replaced by a correction. Return the pay accrual to pending first.');
            }
            $payPolicyReady = !empty($entry['worker_profile_id'])
                && empty($entry['relationship_review_required'])
                && (string)($entry['compensation_policy'] ?? '') === 'rules';
            $effectivePayable = (int)$entry['is_payable'] === 1
                && empty($entry['work_assignment_id'])
                && $payPolicyReady;
            $payRate = $entry['pay_rate_override'] ?? $entry['employee_rate'] ?? $entry['default_hourly_rate'];
            $catalogPay=null;
            if($effectivePayable&&!empty($entry['worker_profile_id'])&&!empty($entry['work_type_id'])){
                $rules=new CompensationRuleService($this->pdo);
                $rule=$rules->resolve((int)$entry['worker_profile_id'],(int)$entry['work_type_id'],!empty($entry['catalog_work_component_id'])?(int)$entry['catalog_work_component_id']:null);
                if($rule['method']==='nonpayable'||$rule['eligibility_trigger']!=='completed_approved')$effectivePayable=false;
                elseif($rule['method']==='percentage')throw new DomainException('Percentage compensation must be linked to a catalog assignment with an eligible client-price basis.');
                else{$catalogPay=$rules->calculate($rule,['duration_seconds'=>(int)$entry['duration_seconds'],'quantity'=>1]);$payRate=$rule['amount'];}
            }
            $billingRate = $billingRateOverride ?? $this->billingRate($entry);
            $snapshotId = Uuid::v4();
            $employeeName = trim((string) $entry['first_name'] . ' ' . (string) $entry['last_name']);
            if ($employeeName === '') {
                $employeeName = (string) ($entry['username'] ?: $entry['email']);
            }
            $payAmount = $effectivePayable && $payRate !== null
                ? ($catalogPay['amount']??DecimalMoney::payAmount((int) $entry['duration_seconds'], (string) $payRate))
                : null;
            $snapshot = [
                'id' => $snapshotId,
                'time_entry_id' => $entryId,
                'entry_revision' => (int) $entry['revision'],
                'employee_user_id' => (int) $entry['user_id'],
                'employee_name' => $employeeName,
                'client_id' => $entry['client_id'] !== null ? (int)$entry['client_id'] : null,
                'client_name' => (string)($entry['client_name'] ?? ''),
                'project_id' => $entry['project_id'] !== null ? (int) $entry['project_id'] : null,
                'project_name' => (string) ($entry['project_name'] ?? ''),
                'invoice_id' => $entry['invoice_id'] !== null ? (int)$entry['invoice_id'] : null,
                'invoice_number' => $this->invoiceLabel($entry),
                'start_time' => (string) $entry['start_time'],
                'end_time' => (string) $entry['end_time'],
                'duration_seconds' => (int) $entry['duration_seconds'],
                'description' => (string) $entry['description'],
                'billable' => (int) $entry['billable'],
                'is_payable' => $effectivePayable ? 1 : 0,
                'pay_rate' => $payRate,
                'billing_rate' => $billingRate,
                'pay_amount' => $payAmount,
                'currency' => (string) $entry['currency'],
                'approved_by' => $approverId,
            ];
            $insert = $this->pdo->prepare(
                'INSERT INTO work_approval_snapshots
                 (id,time_entry_id,entry_revision,employee_user_id,employee_name,client_id,client_name,project_id,project_name,invoice_id,invoice_number,
                  start_time,end_time,duration_seconds,description,billable,is_payable,pay_rate,billing_rate,pay_amount,currency,approved_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute(array_values($snapshot));
            $billingState = (int)$entry['billable'] === 1
                ? ($billingRate !== null && (float)$billingRate > 0 ? 'ready' : 'rate_needed')
                : (in_array((string)($entry['billing_state'] ?? ''), ['internal','fixed_price_included','decide_later'], true)
                    ? (string)$entry['billing_state'] : 'decide_later');
            $compensationState = match (true) {
                (string)($entry['compensation_policy'] ?? '') === 'owner_no_pay' => 'owner_no_pay',
                (string)($entry['compensation_policy'] ?? '') === 'nonpayable' => 'nonpayable',
                !empty($entry['work_assignment_id']) => 'provisional',
                $effectivePayable => $payAmount === null ? 'needs_setup' : 'eligible',
                $payPolicyReady => 'nonpayable',
                default => 'needs_setup',
            };
            $this->pdo->prepare(
                "UPDATE work_time_entries
                 SET status='approved',workflow_status='confirmed',billing_state=?,compensation_state=?,
                     owner_self_confirmed=?,rejection_reason='',reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6)
                 WHERE id=?"
            )->execute([
                $billingState,
                $compensationState,
                $ownerSelfConfirmation ? 1 : (int)$entry['owner_self_confirmed'],
                $approverId,
                $entryId,
            ]);
            if ((int) $entry['revision'] > 1) {
                $this->pdo->prepare(
                    "UPDATE work_pay_accruals a
                     JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
                     SET a.status='voided'
                     WHERE s.time_entry_id=? AND s.entry_revision<? AND a.status='pending'"
                )->execute([$entryId, $entry['revision']]);
            }
            if ($effectivePayable && $payAmount !== null) {
                $hours = number_format(((int) $entry['duration_seconds']) / 3600, 4, '.', '');
                $this->pdo->prepare(
                    "INSERT INTO work_pay_accruals
                     (id,approval_snapshot_id,employee_user_id,employee_name,hours,rate,amount,currency,status)
                     VALUES (?,?,?,?,?,?,?,?,'pending')"
                )->execute([Uuid::v4(), $snapshotId, $entry['user_id'], $employeeName, $hours, $payRate, $payAmount, $entry['currency']]);
            }
            if ($effectivePayable) {
                $hours = number_format(((int)$entry['duration_seconds']) / 3600, 4, '.', '');
                (new WorkerEarningService($this->pdo))->record(
                    'time_entry',
                    $entryId,
                    (int)$entry['revision'],
                    (int)$entry['worker_profile_id'],
                    'hourly',
                    $hours,
                    $payRate !== null ? (string)$payRate : null,
                    $payAmount !== null ? (string)$payAmount : null,
                    (string)$entry['currency'],
                    [
                        'approval_snapshot_id' => $snapshotId,
                        'time_entry_revision' => (int)$entry['revision'],
                        'duration_seconds' => (int)$entry['duration_seconds'],
                        'rate_source' => $catalogPay !== null ? 'compensation_rule' : 'worker_or_business_default',
                    ],
                    $approverId,
                    $payAmount === null ? 'needs_setup' : 'eligible',
                    $entryId
                );
            }
            $billingTreatment = match (true) {
                (int)$entry['billable'] === 1 => 'hourly',
                (string)($entry['billing_state'] ?? '') === 'fixed_price_included' => 'fixed_price_included',
                (string)($entry['billing_state'] ?? '') === 'internal' => 'internal',
                default => 'undecided',
            };
            (new TimeBillingAllocationService($this->pdo))->allocate(
                $entryId,
                (int)$entry['revision'],
                $billingTreatment,
                (int)$entry['duration_seconds'],
                $billingTreatment === 'hourly' && $billingRate !== null ? (string)$billingRate : null,
                (string)$entry['currency'],
                $approverId,
                [
                    'client_id' => $entry['client_id'],
                    'project_id' => $entry['project_id'],
                    'job_id' => $entry['job_id'],
                    'invoice_id' => $entry['invoice_id'],
                ],
                'approval:' . $snapshotId
            );
            $this->billing->consume($snapshot);
            if(!empty($entry['work_assignment_id'])){
                $pending=$this->pdo->prepare("SELECT COUNT(*) FROM work_time_entries WHERE work_assignment_id=? AND id<>? AND status NOT IN ('approved','cancelled','voided')");$pending->execute([$entry['work_assignment_id'],$entryId]);
                if((int)$pending->fetchColumn()===0){$assignment=$this->pdo->prepare("SELECT status,JSON_UNQUOTE(JSON_EXTRACT(compensation_snapshot,'$.eligibility_trigger')) trigger_name FROM work_assignments WHERE id=?");$assignment->execute([$entry['work_assignment_id']]);$assignment=$assignment->fetch(PDO::FETCH_ASSOC);if($assignment&&$assignment['status']==='completed'&&$assignment['trigger_name']==='completed_approved')(new JobWorkPlanningService($this->pdo,new CompensationRuleService($this->pdo)))->markEligible((int)$entry['work_assignment_id'],['trigger_event'=>'completed_approved'],$approverId);}
            }
            $this->audit->record(
                $ownerSelfConfirmation
                    ? 'time_entry.owner_self_confirmed'
                    : ($administrativeSelfConfirmation ? 'time_entry.administratively_self_confirmed' : 'time_entry.approved'),
                'work_time_entry',
                $entryId,
                $approverId,
                $entry,
                $snapshot,
                $snapshotId
            );
            if (!empty($entry['current_submission_id'])) {
                (new TimeSubmissionService($this->pdo))->recordDecision(
                    (string)$entry['current_submission_id'],
                    $entryId,
                    (int)$entry['revision'],
                    'confirmed',
                    $approverId
                );
            }
            $this->pdo->commit();
            return $snapshotId;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function reject(int $approverId, string $entryId, string $reason): void
    {
        $this->policy->assertCanReviewEntry($approverId, $entryId, 'reject');
        if (trim($reason) === '') {
            throw new DomainException('A rejection reason is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $entryStmt = $this->pdo->prepare(
                "SELECT * FROM work_time_entries
                 WHERE id=? AND status='review' AND workflow_status='submitted' FOR UPDATE"
            );
            $entryStmt->execute([$entryId]);
            $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Entry is not awaiting review.');
            }
            $this->pdo->prepare(
                "UPDATE work_time_entries
                 SET status='rejected',workflow_status='returned',rejection_reason=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6)
                 WHERE id=?"
            )->execute([trim($reason), $approverId, $entryId]);
            if (!empty($entry['current_submission_id'])) {
                (new TimeSubmissionService($this->pdo))->recordDecision(
                    (string)$entry['current_submission_id'],
                    $entryId,
                    (int)$entry['revision'],
                    'returned',
                    $approverId,
                    trim($reason)
                );
            }
            $this->audit->record('time_entry.rejected', 'work_time_entry', $entryId, $approverId, [], ['reason' => trim($reason)]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function correct(int $approverId, string $entryId, array $input): void
    {
        $this->policy->assertCanReviewEntry($approverId, $entryId, 'correct');
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('A correction reason is required.');
        }
        $timezone = (string)WorkforceSettings::load($this->pdo)['timezone'];
        $tz = new DateTimeZone($timezone ?: 'UTC');
        $start = $this->parseLocalDateTime((string) ($input['start_time'] ?? ''), $tz);
        $end = $this->parseLocalDateTime((string) ($input['end_time'] ?? ''), $tz);
        if ($end <= $start) {
            throw new DomainException('End time must follow start time.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM work_time_entries WHERE id=? AND status='approved' FOR UPDATE");
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Only an approved entry can be corrected.');
            }
            if ($this->hasPaidAccrual($entryId)) {
                throw new DomainException('Paid time cannot be corrected. Return the pay accrual to pending first.');
            }
            if ($this->billingProjectionIsInvoiced($entryId, (int) $entry['revision'])) {
                throw new DomainException('Invoiced time cannot be corrected. Reverse or adjust the invoice first.');
            }
            $earningStmt = $this->pdo->prepare(
                'SELECT id,status FROM worker_earnings WHERE work_time_entry_id=? AND source_revision=? FOR UPDATE'
            );
            $earningStmt->execute([$entryId, (int)$entry['revision']]);
            $earning = $earningStmt->fetch(PDO::FETCH_ASSOC);
            if ($earning && in_array((string)$earning['status'], ['included','settled'], true)) {
                throw new DomainException('Time included on a worker statement cannot be edited. Create a reviewed pay adjustment instead.');
            }
            if ($earning && in_array((string)$earning['status'], ['provisional','needs_setup','eligible','approved'], true)) {
                (new WorkerEarningService($this->pdo))->transition(
                    (string)$earning['id'],
                    'voided',
                    $approverId,
                    'Time entry corrected: ' . $reason
                );
            }
            $projectId = !empty($input['project_id']) ? (int) $input['project_id'] : null;
            if ($projectId) {
                $assigned = $this->pdo->prepare(
                    'SELECT 1 FROM project_assignments WHERE project_id=? AND user_id=? AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6))'
                );
                $assigned->execute([$projectId, $entry['user_id']]);
                if (!$assigned->fetchColumn()) {
                    throw new DomainException('The corrected project is not assigned to this employee.');
                }
            }
            $revisionId = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([$revisionId, $entryId, $entry['revision'], json_encode($entry, JSON_THROW_ON_ERROR), $reason, $approverId]);
            $breaks = $this->pdo->prepare('SELECT COALESCE(SUM(duration_seconds),0) FROM work_time_breaks WHERE time_entry_id=?');
            $breaks->execute([$entryId]);
            $duration = max(0, $end->getTimestamp() - $start->getTimestamp() - (int) $breaks->fetchColumn());
            $startUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $endUtc = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare(
                "UPDATE work_time_entries SET project_id=?,start_time=?,end_time=?,duration_seconds=?,description=?,billable=?,is_payable=?,
                 revision=revision+1,status='review',workflow_status='draft',
                 billing_state=?,compensation_state=?,current_submission_id=NULL,submitted_at=NULL,
                 reviewed_by=NULL,reviewed_at=NULL,rejection_reason='' WHERE id=?"
            )->execute([
                $projectId, $startUtc, $endUtc, $duration, trim((string) ($input['description'] ?? $entry['description'])),
                !empty($input['billable']) ? 1 : 0,
                !empty($input['is_payable']) ? 1 : 0,
                !empty($input['billable']) ? 'ready' : 'internal',
                !empty($input['is_payable']) ? 'provisional' : (($entry['compensation_state'] ?? '') === 'owner_no_pay' ? 'owner_no_pay' : 'nonpayable'),
                $entryId,
            ]);
            $this->audit->record('time_entry.correction_requested', 'work_time_entry', $entryId, $approverId, $entry, ['reason' => $reason, 'revision' => (int) $entry['revision'] + 1], $revisionId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function void(int $approverId, string $entryId, string $reason): void
    {
        $this->policy->assertCanReviewEntry($approverId, $entryId, 'void');
        if (trim($reason) === '') {
            throw new DomainException('A void reason is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $entryStmt = $this->pdo->prepare("SELECT * FROM work_time_entries WHERE id=? AND status='approved' FOR UPDATE");
            $entryStmt->execute([$entryId]);
            $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Only an approved entry can be voided.');
            }
            $snapshotStmt = $this->pdo->prepare(
                'SELECT * FROM work_approval_snapshots
                 WHERE time_entry_id=? AND entry_revision<=? AND voided_at IS NULL
                 ORDER BY entry_revision DESC LIMIT 1 FOR UPDATE'
            );
            $snapshotStmt->execute([$entryId, $entry['revision']]);
            $snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
            if (!$snapshot || $snapshot['voided_at'] !== null) {
                throw new DomainException('The approval snapshot is unavailable or already voided.');
            }
            if ($this->billingProjectionIsInvoiced($entryId, (int) $entry['revision'])) {
                throw new DomainException('Invoiced time cannot be voided. Reverse or adjust the invoice first.');
            }
            $payStatus = $this->pdo->prepare('SELECT status FROM work_pay_accruals WHERE approval_snapshot_id=? FOR UPDATE');
            $payStatus->execute([$snapshot['id']]);
            if ($payStatus->fetchColumn() === 'paid') {
                throw new DomainException('Paid time cannot be voided. Return the pay accrual to pending first.');
            }
            $earningStmt = $this->pdo->prepare(
                'SELECT id,status FROM worker_earnings
                 WHERE work_time_entry_id=? AND source_revision<=?
                 ORDER BY source_revision DESC LIMIT 1 FOR UPDATE'
            );
            $earningStmt->execute([$entryId, (int)$entry['revision']]);
            $earning = $earningStmt->fetch(PDO::FETCH_ASSOC);
            if ($earning && in_array((string)$earning['status'], ['included','settled'], true)) {
                throw new DomainException('Time included on a worker statement cannot be voided. Create a reviewed pay adjustment instead.');
            }
            if ($earning && in_array((string)$earning['status'], ['provisional','needs_setup','eligible','approved'], true)) {
                (new WorkerEarningService($this->pdo))->transition(
                    (string)$earning['id'],
                    'voided',
                    $approverId,
                    trim($reason)
                );
            }
            $revisionId = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([$revisionId, $entryId, $entry['revision'], json_encode($entry, JSON_THROW_ON_ERROR), 'Void: ' . trim($reason), $approverId]);
            $this->pdo->prepare(
                "UPDATE work_time_entries
                 SET status='voided',workflow_status='voided',compensation_state='voided',
                     billing_state=CASE WHEN billing_state IN ('partially_invoiced','invoiced','ready','rate_needed') THEN 'reversed' ELSE billing_state END,
                     revision=revision+1
                 WHERE id=?"
            )->execute([$entryId]);
            $this->pdo->prepare(
                'UPDATE work_approval_snapshots SET voided_at=UTC_TIMESTAMP(6),voided_by=?,void_reason=? WHERE id=?'
            )->execute([$approverId, trim($reason), $snapshot['id']]);
            $this->pdo->prepare("UPDATE work_pay_accruals SET status='voided' WHERE approval_snapshot_id=? AND status='pending'")->execute([$snapshot['id']]);
            $this->billing->void($snapshot);
            $this->audit->record('time_entry.voided', 'work_time_entry', $entryId, $approverId, $entry, ['reason' => trim($reason)], $revisionId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private function billingRate(array $entry): ?string
    {
        if ((int) $entry['billable'] !== 1) {
            return null;
        }
        if (!empty($entry['project_id'])) {
            $stmt = $this->pdo->prepare(
                "SELECT amount FROM billing_rate_rules WHERE scope_type='project' AND project_id=?
                 AND effective_from<=DATE(?) AND (effective_until IS NULL OR effective_until>=DATE(?))
                 ORDER BY effective_from DESC,id DESC LIMIT 1"
            );
            $stmt->execute([$entry['project_id'], $entry['start_time'], $entry['start_time']]);
            if (($rate = $stmt->fetchColumn()) !== false) {
                return (string)$rate;
            }
        }
        if (!empty($entry['client_id'])) {
            $stmt = $this->pdo->prepare(
                "SELECT amount FROM billing_rate_rules WHERE scope_type='client' AND client_id=?
                 AND effective_from<=DATE(?) AND (effective_until IS NULL OR effective_until>=DATE(?))
                 ORDER BY effective_from DESC,id DESC LIMIT 1"
            );
            $stmt->execute([$entry['client_id'], $entry['start_time'], $entry['start_time']]);
            if (($rate = $stmt->fetchColumn()) !== false) {
                return (string)$rate;
            }
        }
        return $entry['default_billing_rate'] !== null ? (string)$entry['default_billing_rate'] : null;
    }

    private function normalizeBillingRateOverride(?string $rate): ?string
    {
        $rate = trim((string)$rate);
        if ($rate === '') {
            return null;
        }
        if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate <= 0) {
            throw new DomainException('Enter a positive hourly billing rate.');
        }
        return number_format(round((float)$rate, 4), 4, '.', '');
    }

    private function hasPaidAccrual(string $entryId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.status FROM work_pay_accruals a
             JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
             WHERE s.time_entry_id=? FOR UPDATE"
        );
        $stmt->execute([$entryId]);
        return in_array('paid', $stmt->fetchAll(PDO::FETCH_COLUMN), true);
    }

    private function billingProjectionIsInvoiced(string $entryId, int $revision): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM work_approval_snapshots s
             JOIN work_billing_consumptions c ON c.approval_snapshot_id=s.id
                AND c.consumption_type IN ('approved','correction')
             JOIN time_entries t ON t.id=c.billing_time_entry_id
             WHERE s.time_entry_id=? AND s.entry_revision<=?
               AND (t.billed=1 OR t.invoice_item_id IS NOT NULL)
             ORDER BY s.entry_revision DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$entryId, $revision]);
        return (bool) $stmt->fetchColumn();
    }

    private function invoiceLabel(array $entry): string
    {
        if (empty($entry['invoice_number']) && empty($entry['invoice_id'])) {
            return '';
        }
        $prefix = match ((string)($entry['invoice_type'] ?? 'regular')) {
            'long_term' => 'LTI-',
            'on_demand' => 'ODI-',
            default => 'I-',
        };
        return $prefix . (string)($entry['invoice_number'] ?: $entry['invoice_id']);
    }

    private function parseLocalDateTime(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed
            || $parsed->format('Y-m-d\\TH:i') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('Enter valid local start and end times.');
        }
        return $parsed;
    }

}
