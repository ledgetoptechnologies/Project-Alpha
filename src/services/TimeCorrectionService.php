<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\Uuid;
use App\Modules\Timekeeping\WorkforceSettings;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

require_once __DIR__ . '/../utils/document_pricing_carry_forward.php';

/** Worker-requested or admin-created corrections to an already recorded time entry. */
final class TimeCorrectionService
{
    private const EDITABLE_FIELDS = [
        'client_id','project_id','invoice_id','job_id','work_type_id','work_assignment_id',
        'start_time','end_time','duration_seconds','description','billable','is_payable',
    ];

    private readonly AuditRecorder $audit;

    public function __construct(private readonly PDO $pdo, ?AuditRecorder $audit = null)
    {
        $this->audit = $audit ?? new AuditRecorder($pdo);
    }

    /** @param array<string,mixed> $proposedChanges */
    public function request(string $timeEntryId, array $proposedChanges, string $reason, int $requestedBy): string
    {
        if (trim($reason) === '' || $requestedBy <= 0) {
            throw new DomainException('A time correction requires a reason and authenticated requester.');
        }
        return $this->transaction(function () use ($timeEntryId, $proposedChanges, $reason, $requestedBy): string {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare('SELECT * FROM work_time_entries WHERE id=?' . $lock);
            $statement->execute([$timeEntryId]);
            $entry = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$entry || (string)$entry['workflow_status'] !== 'confirmed' || (string)$entry['status'] !== 'approved') {
                throw new DomainException('Use correction requests only for confirmed time. Draft time can still be edited directly.');
            }
            if ((int)$entry['user_id'] !== $requestedBy && !$this->canManage($requestedBy)) {
                throw new DomainException('You may request a correction only for your own time.');
            }
            $pending = $this->pdo->prepare(
                "SELECT id FROM time_correction_requests
                 WHERE time_entry_id=? AND original_revision=? AND status='pending'
                 LIMIT 1" . $lock
            );
            $pending->execute([$timeEntryId, (int)$entry['revision']]);
            if ($pending->fetchColumn()) {
                throw new DomainException('A correction for this time revision is already awaiting review.');
            }
            $proposed = $this->normalizeProposal($entry, $proposedChanges);
            $id = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO time_correction_requests
                 (id,time_entry_id,original_revision,original_snapshot,proposed_snapshot,reason,requested_by)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $id, $timeEntryId, $entry['revision'], self::json($entry), self::json($proposed), trim($reason), $requestedBy,
            ]);
            $this->audit->record('time_correction.requested', 'time_correction_request', $id, $requestedBy, [], [
                'time_entry_id' => $timeEntryId,
                'original_revision' => (int)$entry['revision'],
                'proposed' => $proposed,
            ]);
            return $id;
        });
    }

    /** @return array<string,mixed> */
    public function approve(
        string $requestId,
        int $actorId,
        ?int $nextOpenPayPeriodId = null,
        ?string $notes = null,
        ?string $manualWorkerDelta = null
    ): array
    {
        return $this->transaction(function () use ($requestId, $actorId, $nextOpenPayPeriodId, $notes, $manualWorkerDelta): array {
            if ($actorId <= 0 || !$this->canManage($actorId)) {
                throw new DomainException('Correction-management permission is required to approve a correction.');
            }
            $request = $this->requestForUpdate($requestId);
            $entryStatement = $this->pdo->prepare('SELECT * FROM work_time_entries WHERE id=? FOR UPDATE');
            $entryStatement->execute([$request['time_entry_id']]);
            $entry = $entryStatement->fetch(PDO::FETCH_ASSOC);
            if (!$entry || (int)$entry['revision'] !== (int)$request['original_revision']) {
                throw new DomainException('The time entry changed after this correction was requested. Submit a new correction.');
            }
            $proposed = json_decode((string)$request['proposed_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $proposed = $this->normalizeProposal($entry, is_array($proposed) ? $proposed : []);
            $proposed = $this->validateBillingContext($proposed, $entry);
            $oldDuration = (int)$entry['duration_seconds'];
            $newDuration = (int)$proposed['duration_seconds'];
            $durationDelta = $newDuration - $oldDuration;

            $revisionId = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([$revisionId, $entry['id'], $entry['revision'], self::json($entry), 'Approved correction: ' . $request['reason'], $actorId]);
            $this->pdo->prepare(
                'UPDATE work_time_entries SET client_id=?,project_id=?,invoice_id=?,job_id=?,work_type_id=?,work_assignment_id=?,
                 start_time=?,end_time=?,duration_seconds=?,description=?,billable=?,is_payable=?,revision=revision+1 WHERE id=? AND revision=?'
            )->execute([
                $proposed['client_id'], $proposed['project_id'], $proposed['invoice_id'], $proposed['job_id'],
                $proposed['work_type_id'], $proposed['work_assignment_id'], $proposed['start_time'], $proposed['end_time'],
                $newDuration, $proposed['description'], $proposed['billable'], $proposed['is_payable'],
                $entry['id'], $entry['revision'],
            ]);

            $pay = $this->compensationImpact($entry, $proposed, $actorId, $manualWorkerDelta);
            $statementEffect = ['action' => 'none', 'adjustment_id' => null, 'original_statement_id' => null, 'replacement_statement_id' => null];
            if ($pay['earning_id'] !== null && empty($pay['new_earning']) && abs((float)$pay['delta']) >= 0.005) {
                $statementEffect = (new WorkerStatementCorrectionService($this->pdo))->applyDelta(
                    $pay['earning_id'], $pay['delta'], (string)$request['reason'], $actorId, $nextOpenPayPeriodId
                );
            }
            $billing = $this->billingImpact($entry, $proposed, $actorId);

            $effectSnapshot = [
                'time' => ['old_duration_seconds' => $oldDuration, 'new_duration_seconds' => $newDuration],
                'compensation' => $pay,
                'statement' => $statementEffect,
                'billing' => $billing,
            ];
            $this->pdo->prepare(
                'INSERT INTO time_correction_effects
                 (correction_request_id,original_worker_earning_id,compensation_adjustment_id,original_statement_id,
                  replacement_statement_id,duration_delta_seconds,worker_amount_delta,billing_amount_delta,currency,
                  statement_action,billing_action,effect_snapshot)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $requestId, $pay['earning_id'], $statementEffect['adjustment_id'], $statementEffect['original_statement_id'],
                $statementEffect['replacement_statement_id'], $durationDelta, $pay['delta'], $billing['delta'],
                $pay['currency'] ?: $billing['currency'], $statementEffect['action'], $billing['action'], self::json($effectSnapshot),
            ]);
            $this->pdo->prepare(
                "UPDATE time_correction_requests SET status='approved',resolved_by=?,resolved_at=UTC_TIMESTAMP(6),
                 resolution_notes=?,applied_revision=? WHERE id=?"
            )->execute([$actorId, $notes === null ? null : trim($notes), (int)$entry['revision'] + 1, $requestId]);
            $this->audit->record('time_correction.approved', 'time_correction_request', $requestId, $actorId, $entry, $proposed, $revisionId);
            return $effectSnapshot + ['applied_revision' => (int)$entry['revision'] + 1];
        });
    }

    public function reject(string $requestId, int $actorId, string $notes): void
    {
        if ($actorId <= 0 || trim($notes) === '' || !$this->canManage($actorId)) {
            throw new DomainException('Correction-management permission and a reason are required.');
        }
        $this->transaction(function () use ($requestId, $actorId, $notes): void {
            $this->requestForUpdate($requestId);
            $this->pdo->prepare(
                "UPDATE time_correction_requests SET status='rejected',resolved_by=?,resolved_at=UTC_TIMESTAMP(6),resolution_notes=? WHERE id=?"
            )->execute([$actorId, trim($notes), $requestId]);
            $this->audit->record('time_correction.rejected', 'time_correction_request', $requestId, $actorId, [], ['notes' => trim($notes)]);
        });
    }

    /** @return array<string,mixed> */
    private function requestForUpdate(string $requestId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_correction_requests WHERE id=? FOR UPDATE');
        $statement->execute([$requestId]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$request || (string)$request['status'] !== 'pending') {
            throw new DomainException('Pending time correction request not found.');
        }
        return $request;
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $changes @return array<string,mixed> */
    private function normalizeProposal(array $entry, array $changes): array
    {
        $proposal = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            $proposal[$field] = array_key_exists($field, $changes) ? $changes[$field] : ($entry[$field] ?? null);
        }
        foreach (['client_id','project_id','invoice_id','job_id','work_type_id','work_assignment_id'] as $field) {
            $proposal[$field] = (int)($proposal[$field] ?? 0) > 0 ? (int)$proposal[$field] : null;
        }
        foreach (['billable','is_payable'] as $field) {
            $proposal[$field] = empty($proposal[$field]) ? 0 : 1;
        }
        $proposal['description'] = mb_substr(trim((string)$proposal['description']), 0, 2000);
        $utc = new DateTimeZone('UTC');
        $workforceTimezone = new DateTimeZone((string)WorkforceSettings::load($this->pdo)['timezone']);
        try {
            $startValue = (string)$proposal['start_time'];
            $endValue = (string)$proposal['end_time'];
            // Browser datetime-local controls use the configured Workforce
            // timezone. Existing database snapshots contain a space and are
            // already UTC, so unchanged fields must not be shifted again.
            $start = new DateTimeImmutable($startValue, str_contains($startValue, 'T') ? $workforceTimezone : $utc);
            $end = new DateTimeImmutable($endValue, str_contains($endValue, 'T') ? $workforceTimezone : $utc);
        } catch (Throwable) {
            throw new DomainException('Correction start and end times are invalid.');
        }
        if ($end <= $start) {
            throw new DomainException('Correction end time must follow the start time.');
        }
        $proposal['start_time'] = $start->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $proposal['end_time'] = $end->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $proposal['duration_seconds'] = $end->getTimestamp() - $start->getTimestamp();
        return $proposal;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{earning_id:?string,delta:string,currency:string,method:?string,requires_review:bool,new_earning:bool}
     */
    private function compensationImpact(
        array $before,
        array $after,
        int $actorId,
        ?string $manualWorkerDelta
    ): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM worker_earnings WHERE work_time_entry_id=? AND status<>'voided'
             ORDER BY source_revision DESC,created_at DESC LIMIT 1 FOR UPDATE"
        );
        $statement->execute([(string)$before['id']]);
        $earning = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$earning) {
            if (empty($after['is_payable'])) {
                return [
                    'earning_id' => null,
                    'delta' => '0.00',
                    'currency' => 'USD',
                    'method' => null,
                    'requires_review' => false,
                    'new_earning' => false,
                ];
            }
            if (!empty($after['work_assignment_id'])) {
                throw new DomainException('Assigned-work compensation is controlled by its assignment and cannot be enabled from a time correction.');
            }
            $profile = $this->pdo->prepare(
                "SELECT wp.id,wp.relationship_type,wp.compensation_policy,
                        COALESCE(wp.relationship_review_required,0) relationship_review_required,
                        jwc.catalog_work_component_id
                 FROM work_time_entries t
                 JOIN worker_profiles wp ON wp.user_id=t.user_id AND wp.status='active'
                 LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
                 LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
                 WHERE t.id=? LIMIT 1"
            );
            $profile->execute([(string)$before['id']]);
            $worker = $profile->fetch(PDO::FETCH_ASSOC);
            if (!$worker
                || (string)$worker['compensation_policy'] !== 'rules'
                || !empty($worker['relationship_review_required'])
                || empty($after['work_type_id'])) {
                throw new DomainException('Configure an eligible worker compensation policy and Work Activity before enabling pay.');
            }
            $rule = (new CompensationRuleService($this->pdo))->resolve(
                (int)$worker['id'],
                (int)$after['work_type_id'],
                !empty($worker['catalog_work_component_id']) ? (int)$worker['catalog_work_component_id'] : null
            );
            if ((string)$rule['method'] === 'nonpayable'
                || (string)$rule['eligibility_trigger'] !== 'completed_approved'
                || (string)$rule['method'] === 'percentage') {
                throw new DomainException('The selected Work Activity does not have an immediately payable compensation rule.');
            }
            $calculation = (new CompensationRuleService($this->pdo))->calculate($rule, [
                'duration_seconds' => (int)$after['duration_seconds'],
                'quantity' => 1,
            ]);
            $record = (new WorkerEarningService($this->pdo))->record(
                'time_entry',
                (string)$before['id'],
                (int)$before['revision'] + 1,
                (int)$worker['id'],
                (string)$calculation['method'],
                number_format(((int)$after['duration_seconds']) / 3600, 4, '.', ''),
                (string)$rule['amount'],
                (string)$calculation['amount'],
                (string)$calculation['currency'],
                $calculation,
                $actorId,
                'eligible',
                (string)$before['id']
            );
            return [
                'earning_id' => (string)$record['id'],
                'delta' => number_format((float)$calculation['amount'], 2, '.', ''),
                'currency' => (string)$calculation['currency'],
                'method' => (string)$calculation['method'],
                'requires_review' => false,
                'new_earning' => true,
            ];
        }
        $method = (string)$earning['method'];
        $newAmount = empty($after['is_payable']) ? 0.0 : (float)$earning['amount'];
        $requiresReview = false;
        if (!empty($after['is_payable']) && $method === 'hourly' && $earning['rate'] !== null) {
            $newAmount = round(((int)$after['duration_seconds'] / 3600) * (float)$earning['rate'], 2);
        } elseif (!empty($after['is_payable']) && $method === 'base_overage') {
            $snapshot = json_decode((string)$earning['calculation_snapshot'], true);
            $rule = is_array($snapshot) ? ($snapshot['rule_snapshot'] ?? $snapshot['calculation']['rule_snapshot'] ?? []) : [];
            $baseAmount = (float)($rule['amount'] ?? $earning['rate'] ?? 0);
            $includedMinutes = max(0, (int)($rule['included_minutes'] ?? 0));
            $overageRate = max(0.0, (float)($rule['overage_rate'] ?? 0));
            $quantity = max(0.0, (float)$earning['quantity']);
            if ($rule === [] || $quantity <= 0) {
                if ($manualWorkerDelta === null || !is_numeric($manualWorkerDelta)) {
                    throw new DomainException('This base-plus-overage earning lacks its original rule snapshot and requires a manual pay delta.');
                }
                $newAmount = (float)$earning['amount'] + round((float)$manualWorkerDelta, 2);
                if ($newAmount < 0) {
                    throw new DomainException('A manual worker pay correction cannot reduce the earning below zero.');
                }
                $requiresReview = true;
            } else {
                $includedSeconds = $includedMinutes * 60 * $quantity;
                $newAmount = round(($baseAmount * $quantity) + (max(0, (int)$after['duration_seconds'] - $includedSeconds) / 3600 * $overageRate), 2);
            }
        } elseif (!empty($after['is_payable']) && !in_array($method, ['fixed'], true)) {
            if ($manualWorkerDelta === null || !is_numeric($manualWorkerDelta)) {
                throw new DomainException('This compensation method requires an explicit manual worker pay delta.');
            }
            $newAmount = (float)$earning['amount'] + round((float)$manualWorkerDelta, 2);
            if ($newAmount < 0) {
                throw new DomainException('A manual worker pay correction cannot reduce the earning below zero.');
            }
            $requiresReview = true;
        }
        return [
            'earning_id' => (string)$earning['id'],
            'delta' => number_format($newAmount - (float)$earning['amount'], 2, '.', ''),
            'currency' => (string)$earning['currency'],
            'method' => $method,
            'requires_review' => $requiresReview,
            'new_earning' => false,
        ];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return array{delta:?string,currency:string,action:string} */
    private function billingImpact(array $before, array $after, int $actorId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.*,i.status invoice_status,i.finalized_at FROM work_time_billing_allocations a
             LEFT JOIN invoices i ON i.id=a.invoice_id
             WHERE a.time_entry_id=? AND a.entry_revision<=? AND a.status<>'reversed'
             ORDER BY a.entry_revision DESC,a.id DESC LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$before['id'], $before['revision']]);
        $allocation = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$allocation) {
            return ['delta' => null, 'currency' => 'USD', 'action' => 'none'];
        }
        $amounts = $this->correctedBillingAmounts($allocation, $before, $after);
        $delta = $amounts['old'] === null || $amounts['new'] === null
            ? null
            : number_format($amounts['new'] - $amounts['old'], 2, '.', '');
        $draft = $allocation['invoice_id'] === null
            || ((string)$allocation['invoice_status'] === 'draft' && $allocation['finalized_at'] === null);
        if ($draft) {
            $allocationService = new TimeBillingAllocationService($this->pdo);
            $allocationService->reverse((int)$allocation['id'], $actorId, 'Approved time correction');
            $sourceInvoiceId = (int)($allocation['invoice_id'] ?? 0);
            $targetInvoiceId = (int)($after['invoice_id'] ?? 0);
            $replacementTreatment = !empty($after['billable']) ? 'hourly' : 'internal';
            $replacementRate = $replacementTreatment === 'hourly' && $allocation['rate'] !== null
                ? (string)$allocation['rate']
                : null;
            $sameInvoice = $replacementTreatment === 'hourly'
                && $replacementRate !== null
                && $sourceInvoiceId > 0
                && $sourceInvoiceId === $targetInvoiceId;
            $replacement = $allocationService->allocate(
                (string)$before['id'], (int)$before['revision'] + 1, $replacementTreatment,
                (int)$after['duration_seconds'], $replacementRate,
                (string)$allocation['currency'], $actorId,
                [
                    'client_id' => $after['client_id'], 'project_id' => $after['project_id'], 'job_id' => $after['job_id'],
                    'invoice_id' => $after['invoice_id'],
                    'invoice_item_id' => $sameInvoice ? $allocation['invoice_item_id'] : null,
                    'correction_of_allocation_id' => (int)$allocation['id'],
                ],
                'time-correction:' . $before['id'] . ':' . ((int)$before['revision'] + 1)
            );
            if ((string)$allocation['status'] === 'invoiced' && !empty($allocation['invoice_id']) && !empty($allocation['invoice_item_id'])) {
                if ($sameInvoice) {
                    $allocationService->markInvoiced((int)$replacement['id'], $sourceInvoiceId, (int)$allocation['invoice_item_id']);
                    $this->refreshDraftInvoice($sourceInvoiceId, (int)$allocation['invoice_item_id'], $actorId, (string)$allocation['currency']);
                } else {
                    $this->detachBillingProjection((string)$before['id'], $sourceInvoiceId, (int)$allocation['invoice_item_id']);
                    $this->refreshDraftInvoice($sourceInvoiceId, (int)$allocation['invoice_item_id'], $actorId, (string)$allocation['currency']);
                    if ($targetInvoiceId > 0 && $replacementTreatment === 'hourly' && $replacementRate !== null) {
                        $this->attachReplacementToDraftInvoice(
                            (string)$before['id'], (int)$replacement['id'], $targetInvoiceId,
                            (int)$after['duration_seconds'], (float)$replacementRate, $actorId, (string)$allocation['currency']
                        );
                    }
                }
            }
            if ($amounts['model'] === 'base_overage' && $sourceInvoiceId > 0 && $delta !== null && abs((float)$delta) >= 0.005) {
                $this->applyDraftBaseOverageDelta($sourceInvoiceId, (float)$delta, $actorId, (string)$allocation['currency']);
            }
        }
        $action = !$draft ? 'admin_review' : ((int)($allocation['invoice_id'] ?? 0) > 0 ? 'draft_update' : 'none');
        return ['delta' => $delta, 'currency' => (string)$allocation['currency'], 'action' => $action];
    }

    /** @return array{old:?float,new:?float,model:string} */
    private function correctedBillingAmounts(array $allocation, array $before, array $after): array
    {
        if (!empty($before['work_assignment_id'])) {
            $statement = $this->pdo->prepare(
                'SELECT j.client_billing_treatment_snapshot,j.client_billing_rate_snapshot,
                        j.client_included_minutes_snapshot,j.client_overage_rate_snapshot,j.planned_quantity
                 FROM work_assignments a JOIN job_work_components j ON j.id=a.job_work_component_id
                 WHERE a.id=?'
            );
            $statement->execute([(int)$before['work_assignment_id']]);
            $snapshot = $statement->fetch(PDO::FETCH_ASSOC);
            if ($snapshot && (string)$snapshot['client_billing_treatment_snapshot'] === 'base_overage') {
                foreach (['client_billing_rate_snapshot','client_included_minutes_snapshot','client_overage_rate_snapshot'] as $field) {
                    if ($snapshot[$field] === null || !is_numeric((string)$snapshot[$field])) {
                        throw new DomainException('This base-plus-overage billing record lacks its immutable pricing snapshot. Apply an explicit invoice adjustment before approving the time correction.');
                    }
                }
                $otherDuration = $this->pdo->prepare(
                    "SELECT COALESCE(SUM(duration_seconds),0) FROM work_time_entries
                     WHERE work_assignment_id=? AND id<>? AND workflow_status='confirmed' AND status='approved'"
                );
                $otherDuration->execute([(int)$before['work_assignment_id'], (string)$before['id']]);
                $otherSeconds = (int)$otherDuration->fetchColumn();
                $quantity = max(1.0, (float)($snapshot['planned_quantity'] ?? 1));
                $base = max(0.0, (float)$snapshot['client_billing_rate_snapshot']) * $quantity;
                $includedSeconds = max(0, (int)$snapshot['client_included_minutes_snapshot']) * 60 * $quantity;
                $overageRate = max(0.0, (float)$snapshot['client_overage_rate_snapshot']);
                $calculate = static fn(int $seconds): float => round(
                    $base + (max(0, $seconds - $includedSeconds) / 3600 * $overageRate),
                    2
                );
                return [
                    'old' => $calculate($otherSeconds + (int)$before['duration_seconds']),
                    'new' => empty($after['billable'])
                        ? 0.0
                        : $calculate($otherSeconds + (int)$after['duration_seconds']),
                    'model' => 'base_overage',
                ];
            }
        }
        if ((string)$allocation['treatment'] === 'hourly' && $allocation['rate'] !== null) {
            return [
                'old' => $allocation['amount'] === null
                    ? round(((int)$before['duration_seconds'] / 3600) * (float)$allocation['rate'], 2)
                    : (float)$allocation['amount'],
                'new' => empty($after['billable'])
                    ? 0.0
                    : round(((int)$after['duration_seconds'] / 3600) * (float)$allocation['rate'], 2),
                'model' => 'hourly',
            ];
        }
        $amount = $allocation['amount'] === null ? null : (float)$allocation['amount'];
        return [
            'old' => $amount,
            'new' => empty($after['billable']) ? 0.0 : $amount,
            'model' => (string)$allocation['treatment'],
        ];
    }

    private function detachBillingProjection(string $entryId, int $invoiceId, int $invoiceItemId): void
    {
        $this->pdo->prepare(
            'UPDATE time_entries bt
             JOIN work_billing_consumptions c ON c.billing_time_entry_id=bt.id
             JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id
             SET bt.billed=0,bt.invoice_id=NULL,bt.invoice_item_id=NULL
             WHERE s.time_entry_id=? AND bt.invoice_id=? AND bt.invoice_item_id=?'
        )->execute([$entryId, $invoiceId, $invoiceItemId]);
    }

    private function attachReplacementToDraftInvoice(
        string $entryId,
        int $allocationId,
        int $invoiceId,
        int $durationSeconds,
        float $rate,
        int $actorId,
        string $currency
    ): void {
        if ($rate <= 0) {
            throw new DomainException('The corrected time lacks the immutable hourly billing rate required for the destination invoice.');
        }
        if(\pricing_invoice_is_fixed_total_installment($this->pdo,$invoiceId)){
            throw new DomainException('Fixed-total installment invoices are immutable. Amend and reallocate the contract instead.');
        }
        $invoice = $this->pdo->prepare("SELECT id FROM invoices WHERE id=? AND status='draft' AND finalized_at IS NULL FOR UPDATE");
        $invoice->execute([$invoiceId]);
        if (!$invoice->fetchColumn()) {
            throw new DomainException('The correction destination must remain a mutable draft invoice.');
        }
        $projection = $this->pdo->prepare(
            'SELECT bt.id FROM time_entries bt
             JOIN work_billing_consumptions c ON c.billing_time_entry_id=bt.id
             JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id
             WHERE s.time_entry_id=? ORDER BY s.entry_revision DESC,c.id DESC LIMIT 1 FOR UPDATE'
        );
        $projection->execute([$entryId]);
        $billingTimeEntryId = (int)($projection->fetchColumn() ?: 0);
        if ($billingTimeEntryId <= 0) {
            throw new DomainException('The corrected time billing projection is unavailable for the destination invoice.');
        }
        $labels = $this->pdo->prepare(
            'SELECT wt.name,jwc.item_library_id FROM work_time_entries t
             LEFT JOIN work_types wt ON wt.id=t.work_type_id
             LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
             LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
             WHERE t.id=?'
        );
        $labels->execute([$entryId]);
        $label = $labels->fetch(PDO::FETCH_ASSOC) ?: [];
        $hours = round($durationSeconds / 3600, 2);
        $lineTotal = round(($durationSeconds / 3600) * $rate, 2);
        $this->pdo->prepare(
            "INSERT INTO invoice_items
             (invoice_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,is_extra_charge,time_entry_id,hours)
             VALUES (?,?,?,'',?,?,?,'hour',0,?,?)"
        )->execute([
            $invoiceId,
            !empty($label['item_library_id']) ? (int)$label['item_library_id'] : null,
            trim((string)($label['name'] ?? '')) ?: 'Tracked time',
            $hours,
            number_format($rate, 4, '.', ''),
            number_format($lineTotal, 2, '.', ''),
            $billingTimeEntryId,
            $hours,
        ]);
        $invoiceItemId = (int)$this->pdo->lastInsertId();
        $updated = $this->pdo->prepare(
            'UPDATE time_entries SET billed=1,invoice_id=?,invoice_item_id=?,rate=? WHERE id=? AND billed=0'
        );
        $updated->execute([$invoiceId, $invoiceItemId, number_format($rate, 4, '.', ''), $billingTimeEntryId]);
        if ($updated->rowCount() !== 1) {
            throw new DomainException('The corrected billing projection changed before it could be moved.');
        }
        (new TimeBillingAllocationService($this->pdo))->markInvoiced($allocationId, $invoiceId, $invoiceItemId);
        $this->refreshDraftInvoice($invoiceId, $invoiceItemId, $actorId, $currency);
    }

    private function applyDraftBaseOverageDelta(int $invoiceId, float $delta, int $actorId, string $currency): void
    {
        $statement = $this->pdo->prepare("SELECT * FROM invoices WHERE id=? AND status='draft' AND finalized_at IS NULL FOR UPDATE");
        $statement->execute([$invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new DomainException('Base-plus-overage time can be recalculated automatically only on a mutable draft invoice.');
        }
        if(\pricing_invoice_is_fixed_total_installment($this->pdo,$invoiceId)){
            throw new DomainException('Fixed-total installment invoices are immutable. Amend and reallocate the contract instead.');
        }
        $revision = max(1, (int)($invoice['revision_number'] ?? 1)) + 1;
        $this->pdo->prepare(
            'INSERT INTO invoice_adjustments
             (invoice_id,adjustment_type,label,description,quantity,unit_price,amount,affects_total,revision_number,created_by)
             VALUES (?,?,?,?,1,?,?,1,?,?)'
        )->execute([
            $invoiceId,
            $delta < 0 ? 'credit' : 'charge',
            'Base-plus-overage time correction',
            'Automatically recalculated from the immutable Job pricing snapshot.',
            number_format(abs($delta), 2, '.', ''),
            number_format(abs($delta), 2, '.', ''),
            $revision,
            $actorId,
        ]);
        $eligible=\pricing_adjustments_enabled($this->pdo)
            && (int)($invoice['organization_id']??0)>0
            && (int)($invoice['project_id']??0)>0;
        if($eligible){
            \pricing_finalize_frozen_document_revision($this->pdo,(int)$invoice['organization_id'],'invoice',$invoiceId,$actorId,$currency);
        }else{
            $total=max(0.0,(float)$invoice['total']+$delta);
            $this->pdo->prepare('UPDATE invoices SET total=?,balance_due=GREATEST(0,?-COALESCE(amount_paid,0)) WHERE id=?')
                ->execute([number_format($total,2,'.',''),number_format($total,2,'.',''),$invoiceId]);
            \DocumentRevisionService::snapshotAndSave($this->pdo,'invoice',$invoiceId,$actorId,true);
        }
    }

    private function refreshDraftInvoice(int $invoiceId, int $invoiceItemId, int $actorId, string $currency): void
    {
        $invoiceStatement = $this->pdo->prepare("SELECT * FROM invoices WHERE id=? AND status='draft' AND finalized_at IS NULL FOR UPDATE");
        $invoiceStatement->execute([$invoiceId]);
        $invoice = $invoiceStatement->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new DomainException('The corrected billing line is no longer on a mutable draft invoice.');
        }
        if(\pricing_invoice_is_fixed_total_installment($this->pdo,$invoiceId)){
            throw new DomainException('Fixed-total installment invoices are immutable. Amend and reallocate the contract instead.');
        }
        $lineStatement = $this->pdo->prepare('SELECT unit_price FROM invoice_items WHERE id=? AND invoice_id=? FOR UPDATE');
        $lineStatement->execute([$invoiceItemId, $invoiceId]);
        $rate = $lineStatement->fetchColumn();
        if ($rate === false) {
            throw new DomainException('The corrected draft invoice line no longer exists.');
        }
        $secondsStatement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(duration_seconds),0) FROM work_time_billing_allocations
             WHERE invoice_item_id=? AND status='invoiced' AND treatment='hourly'"
        );
        $secondsStatement->execute([$invoiceItemId]);
        $seconds = (int)$secondsStatement->fetchColumn();
        $quantity = round($seconds / 3600, 2);
        $lineTotal = round(($seconds / 3600) * (float)$rate, 2);
        if ($seconds <= 0) {
            $this->pdo->prepare('DELETE FROM invoice_items WHERE id=? AND invoice_id=?')
                ->execute([$invoiceItemId, $invoiceId]);
        } else {
            $this->pdo->prepare('UPDATE invoice_items SET quantity=?,hours=?,line_total=? WHERE id=?')
                ->execute([$quantity, $quantity, $lineTotal, $invoiceItemId]);
        }
        $subtotalStatement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(line_total),0) FROM invoice_items WHERE invoice_id=? AND COALESCE(pricing_status,'standard')='standard'"
        );
        $subtotalStatement->execute([$invoiceId]);
        $subtotal = (float)$subtotalStatement->fetchColumn();
        $this->pdo->prepare('UPDATE invoices SET subtotal=? WHERE id=?')
            ->execute([number_format($subtotal,2,'.',''),$invoiceId]);
        $eligible=\pricing_adjustments_enabled($this->pdo)
            && (int)($invoice['organization_id']??0)>0
            && (int)($invoice['project_id']??0)>0;
        if($eligible){
            \pricing_finalize_frozen_document_revision($this->pdo,(int)$invoice['organization_id'],'invoice',$invoiceId,$actorId,$currency);
        }else{
            $discount=match((string)$invoice['discount_type']){
                'percent'=>max(0.0,min(100.0,(float)$invoice['discount_value']))*$subtotal/100,
                'fixed'=>min($subtotal,max(0.0,(float)$invoice['discount_value'])),
                default=>0.0,
            };
            $tax=max(0.0,(float)$invoice['tax_percent'])*max(0.0,$subtotal-$discount)/100;
            $total=max(0.0,$subtotal-$discount+$tax);
            $this->pdo->prepare('UPDATE invoices SET tax_amount=?,total=?,balance_due=GREATEST(0,?-COALESCE(amount_paid,0)) WHERE id=?')
                ->execute([$tax,$total,$total,$invoiceId]);
            \DocumentRevisionService::snapshotAndSave($this->pdo,'invoice',$invoiceId,$actorId,true);
        }
    }

    /** @param array<string,mixed> $proposal @param array<string,mixed> $original @return array<string,mixed> */
    private function validateBillingContext(array $proposal, array $original): array
    {
        if (!empty($proposal['invoice_id'])) {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare(
                'SELECT client_id,project_id,job_id,status,finalized_at FROM invoices WHERE id=?' . $lock
            );
            $statement->execute([$proposal['invoice_id']]);
            $invoice = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new DomainException('Correction invoice not found.');
            }
            $invoiceChanged = (int)($original['invoice_id'] ?? 0) !== (int)$proposal['invoice_id'];
            if ($invoiceChanged
                && ((string)$invoice['status'] !== 'draft' || $invoice['finalized_at'] !== null)) {
                throw new DomainException('A correction can move time only to a mutable draft invoice.');
            }
            foreach (['client_id','project_id','job_id'] as $field) {
                $fromInvoice = (int)($invoice[$field] ?? 0) ?: null;
                if ($proposal[$field] !== null && $fromInvoice !== null && (int)$proposal[$field] !== $fromInvoice) {
                    throw new DomainException('The proposed time context conflicts with the selected invoice.');
                }
                $proposal[$field] ??= $fromInvoice;
            }
        }
        if (!empty($proposal['job_id'])) {
            $statement = $this->pdo->prepare('SELECT client_id,project_id FROM jobs WHERE id=?');
            $statement->execute([$proposal['job_id']]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                throw new DomainException('Correction Job not found.');
            }
            foreach (['client_id','project_id'] as $field) {
                $fromJob = (int)($job[$field] ?? 0) ?: null;
                if ($proposal[$field] !== null && $fromJob !== null && (int)$proposal[$field] !== $fromJob) {
                    throw new DomainException('The proposed time context conflicts with the selected Job.');
                }
                $proposal[$field] ??= $fromJob;
            }
        }
        if ((int)($proposal['billable'] ?? 0) === 1 && empty($proposal['job_id'])) {
            throw new DomainException('Client-billable corrected time must be assigned to a canonical Job.');
        }
        return $proposal;
    }

    private function canManage(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (function_exists('user_can') && \user_can($this->pdo, $userId, 'workforce.corrections.manage')) {
            return true;
        }
        $user = $this->pdo->prepare('SELECT role FROM users WHERE id=? AND is_disabled=0 AND deleted_at IS NULL');
        $user->execute([$userId]);
        $role = (string)$user->fetchColumn();
        if (in_array($role, ['admin','owner'], true)) {
            return true;
        }
        $permission = $this->pdo->prepare(
            "SELECT COALESCE(
                (SELECT allowed FROM user_permissions_overrides WHERE user_id=? AND permission='workforce.corrections.manage' ORDER BY organization_id IS NULL LIMIT 1),
                (SELECT rp.allowed FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.name=? AND rp.permission='workforce.corrections.manage' LIMIT 1),0)"
        );
        $permission->execute([$userId, $role]);
        return (bool)$permission->fetchColumn();
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction();
        try {
            $result = $callback();
            if ($owns) $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
