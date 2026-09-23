<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

/** Materializes internal Job work without exposing compensation on documents. */
final class JobWorkPlanningService
{
    private const DOCUMENTS = [
        'quote' => ['table' => 'quotes', 'items' => 'quote_items', 'parent' => 'quote_id'],
        'contract' => ['table' => 'contracts', 'items' => 'contract_items', 'parent' => 'contract_id'],
        'invoice' => ['table' => 'invoices', 'items' => 'invoice_items', 'parent' => 'invoice_id'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CompensationRuleService $compensation
    ) {}

    public function materializeDocument(string $type, int $documentId, int $actorId): array
    {
        $meta = self::DOCUMENTS[$type] ?? null;
        if ($meta === null) {
            throw new DomainException('Unsupported document type.');
        }
        $document = $this->pdo->prepare("SELECT id,job_id,revision_number FROM {$meta['table']} WHERE id=?");
        $document->execute([$documentId]);
        $document = $document->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new DomainException('Document not found.');
        }
        if (empty($document['job_id'])) {
            return [];
        }
        $items = $this->pdo->prepare(
            "SELECT id,item_library_id,quantity,unit_price,line_total,billing_unit,catalog_snapshot FROM {$meta['items']}
             WHERE {$meta['parent']}=? AND item_library_id IS NOT NULL ORDER BY id"
        );
        $items->execute([$documentId]);

        $ids = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $snapshot=is_string($line['catalog_snapshot']??null)?json_decode($line['catalog_snapshot'],true):null;
            $snapshotComponents=is_array($snapshot)&&is_array($snapshot['work_components']??null)?$snapshot['work_components']:null;
            $snapshotBundleItems=is_array($snapshot)&&is_array($snapshot['bundle_items']??null)?$snapshot['bundle_items']:null;
            $ids = array_merge($ids, $this->materializeItem(
                (int)$document['job_id'],
                (int)$line['item_library_id'],
                (float)$line['quantity'],
                $actorId,
                $type,
                $documentId,
                (int)$line['id'],
                (int)($document['revision_number'] ?? 1),
                $line,$snapshotComponents,$snapshotBundleItems
            ));
        }
        return $ids;
    }

    public function materializeCatalog(int $jobId, int $itemLibraryId, float $quantity, int $actorId): array
    {
        return $this->materializeItem($jobId, $itemLibraryId, $quantity, $actorId, 'catalog');
    }

    public function offer(int $assignmentId, int $workerProfileId, int $actorId, ?array $override = null): array
    {
        return $this->transaction(function () use ($assignmentId, $workerProfileId, $actorId, $override): array {
            $assignment = $this->assignmentForUpdate($assignmentId);
            if ($assignment['status'] !== 'planned') {
                throw new DomainException('Only a planned assignment can be offered.');
            }
            $worker=$this->pdo->prepare("SELECT compensation_policy,relationship_review_required,currency FROM worker_profiles WHERE id=? AND status='active'");$worker->execute([$workerProfileId]);
            $worker=$worker->fetch(PDO::FETCH_ASSOC);
            if(!$worker) throw new DomainException('Choose an active worker.');
            if(!empty($worker['relationship_review_required'])||in_array((string)$worker['compensation_policy'],['needs_setup','needs_review'],true)) throw new DomainException('Configure this worker compensation policy before offering work.');
            $nonpayablePolicy=in_array((string)$worker['compensation_policy'],['nonpayable','owner_no_pay'],true);
            $rule = $nonpayablePolicy
                ? ['method'=>'nonpayable','currency'=>(string)$worker['currency'],'source'=>'worker_policy_nonpayable']
                : ($override !== null
                ? $override + ['source' => 'assignment_override']
                : $this->compensation->resolve(
                    $workerProfileId,
                    (int)$assignment['work_type_id'],
                    $assignment['catalog_work_component_id'] !== null ? (int)$assignment['catalog_work_component_id'] : null,
                    null
                ));
            if (!$nonpayablePolicy && $override === null && !str_starts_with((string)($rule['source'] ?? ''), 'worker_')) {
                $plannedRule = json_decode((string)($assignment['planned_compensation_snapshot'] ?? ''), true);
                if (is_array($plannedRule)) {
                    $rule = $plannedRule + ['source' => 'job_component_snapshot'];
                }
            }
            $preview = $this->compensation->calculate($rule, [
                'duration_seconds' => (int)$assignment['expected_duration_minutes'] * 60,
                'quantity' => (float)$assignment['planned_quantity'],
                'line_gross' => (float)$assignment['source_line_total'],
                'line_net' => (float)$assignment['source_line_total'],
                'cash_collected' => 0,
            ]);
            $this->pdo->prepare(
                "UPDATE work_assignments SET worker_profile_id=?,status='offered',compensation_override=?,compensation_snapshot=?,estimated_pay=?,currency=?,offered_by=?,offered_at=UTC_TIMESTAMP(6),decline_reason=NULL WHERE id=?"
            )->execute([
                $workerProfileId,
                $override === null ? null : json_encode($override, JSON_THROW_ON_ERROR),
                json_encode($preview['rule_snapshot'], JSON_THROW_ON_ERROR),
                $preview['amount'], $preview['currency'], $actorId, $assignmentId,
            ]);
            return $preview;
        });
    }

    public function accept(int $assignmentId, int $workerProfileId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_assignments SET status='accepted',responded_at=UTC_TIMESTAMP(6)
             WHERE id=? AND worker_profile_id=? AND status='offered'"
        );
        $stmt->execute([$assignmentId, $workerProfileId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('This assignment is not available to accept.');
        }
    }

    public function decline(int $assignmentId, int $workerProfileId, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A decline reason is required.');
        }
        return $this->transaction(function () use ($assignmentId, $workerProfileId, $reason): int {
            $assignment = $this->assignmentForUpdate($assignmentId);
            if ((int)$assignment['worker_profile_id'] !== $workerProfileId || $assignment['status'] !== 'offered') {
                throw new DomainException('This assignment is not available to decline.');
            }
            $this->pdo->prepare("UPDATE work_assignments SET status='declined',responded_at=UTC_TIMESTAMP(6),decline_reason=? WHERE id=?")
                ->execute([$reason, $assignmentId]);
            $this->pdo->prepare("INSERT INTO work_assignments (job_work_component_id,status,currency) VALUES (?,'planned',?)")
                ->execute([$assignment['job_work_component_id'], $assignment['currency']]);
            return (int)$this->pdo->lastInsertId();
        });
    }

    public function start(int $assignmentId, int $workerProfileId): void
    {
        $this->workerTransition($assignmentId, $workerProfileId, ['accepted'], 'in_progress');
        $this->pdo->prepare("UPDATE job_work_components jwc JOIN work_assignments wa ON wa.job_work_component_id=jwc.id SET jwc.status='in_progress' WHERE wa.id=? AND jwc.status='planned'")
            ->execute([$assignmentId]);
    }

    public function complete(int $assignmentId, int $workerProfileId): void
    {
        $this->workerTransition($assignmentId, $workerProfileId, ['accepted', 'in_progress'], 'completed');
        $this->pdo->prepare("UPDATE work_assignments SET completed_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$assignmentId]);
        $this->pdo->prepare("UPDATE job_work_components jwc JOIN work_assignments wa ON wa.job_work_component_id=jwc.id SET jwc.status='completed' WHERE wa.id=?")
            ->execute([$assignmentId]);
    }

    public function markEligible(int $assignmentId, array $context, int $actorId): array
    {
        return $this->transaction(function () use ($assignmentId, $context, $actorId): array {
            $assignment = $this->assignmentForUpdate($assignmentId);
            if ($assignment['status'] !== 'completed') {
                throw new DomainException('Only completed work can become eligible.');
            }
            if (!empty($assignment['relationship_review_required'])
                || in_array((string)($assignment['compensation_policy'] ?? ''), ['needs_setup','needs_review'], true)) {
                throw new DomainException('Configure this worker compensation policy before releasing work.');
            }
            $rule = json_decode((string)$assignment['compensation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $triggerEvent=(string)($context['trigger_event']??'completed_approved');
            if($triggerEvent!==(string)($rule['eligibility_trigger']??'completed_approved')){
                throw new DomainException('This compensation is not eligible until its configured trigger occurs.');
            }
            if(!array_key_exists('duration_seconds',$context)){
                $duration=$this->pdo->prepare("SELECT COALESCE(SUM(duration_seconds),0) FROM work_time_entries WHERE work_assignment_id=? AND status='approved'");
                $duration->execute([$assignmentId]);$context['duration_seconds']=(int)$duration->fetchColumn();
            }
            $context['line_gross']??=(float)$assignment['source_line_total'];
            $context['line_net']??=(float)$assignment['source_line_total'];
            $context['quantity'] ??= (float)$assignment['planned_quantity'];
            $preview = $this->compensation->calculate($rule, $context);
            $eligibilitySnapshot = [
                'trigger_event' => $triggerEvent,
                'context' => $context,
                'calculation' => $preview,
            ];
            $this->pdo->prepare("UPDATE work_assignments SET status='eligible',estimated_pay=?,currency=?,eligibility_snapshot=?,eligible_by=?,eligible_at=UTC_TIMESTAMP(6) WHERE id=?")
                ->execute([$preview['amount'], $preview['currency'], json_encode($eligibilitySnapshot, JSON_THROW_ON_ERROR), $actorId ?: null, $assignmentId]);
            if ((string)($preview['method'] ?? '') !== 'nonpayable') {
                $earningActor = $actorId > 0
                    ? $actorId
                    : (int)($assignment['offered_by'] ?? $assignment['worker_user_id'] ?? 0);
                if ($earningActor <= 0) {
                    throw new DomainException('An authenticated actor is required to release worker compensation.');
                }
                $ruleSnapshot = (array)($preview['rule_snapshot'] ?? []);
                $rate = match ((string)$preview['method']) {
                    'percentage' => isset($ruleSnapshot['percentage']) ? (string)$ruleSnapshot['percentage'] : null,
                    'hourly', 'fixed', 'base_overage' => isset($ruleSnapshot['amount']) ? (string)$ruleSnapshot['amount'] : null,
                    default => null,
                };
                (new WorkerEarningService($this->pdo))->record(
                    'work_assignment',
                    (string)$assignmentId,
                    1,
                    (int)$assignment['worker_profile_id'],
                    (string)$preview['method'],
                    (string)($preview['quantity'] ?? '1'),
                    $rate,
                    (string)$preview['amount'],
                    (string)$preview['currency'],
                    $eligibilitySnapshot,
                    $earningActor,
                    'eligible',
                    null,
                    $assignmentId
                );
            }
            return $preview + ['released_by' => $actorId];
        });
    }

    /** Release completed assignment compensation tied to a fully paid invoice. */
    public function releaseInvoicePaid(int $invoiceId, int $actorId = 0): array
    {
        $invoice = $this->pdo->prepare('SELECT id,job_id,quote_id,contract_id,total,revision_number FROM invoices WHERE id=? AND status="paid"');
        $invoice->execute([$invoiceId]);
        $invoice = $invoice->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || empty($invoice['job_id'])) return [];

        $quoteId = !empty($invoice['quote_id']) ? (int)$invoice['quote_id'] : null;
        if ($quoteId === null && !empty($invoice['contract_id'])) {
            $quote = $this->pdo->prepare('SELECT quote_id FROM contracts WHERE id=?');
            $quote->execute([(int)$invoice['contract_id']]);
            $quoteId = ($value = $quote->fetchColumn()) !== false && $value !== null ? (int)$value : null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT wa.id,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(jwc.compensation_snapshot,'$.source_line_total')),'0') source_line_total
             FROM work_assignments wa JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
             WHERE jwc.job_id=? AND wa.status='completed'
               AND JSON_UNQUOTE(JSON_EXTRACT(wa.compensation_snapshot,'$.eligibility_trigger'))='invoice_paid'
               AND ((jwc.source_type='invoice' AND jwc.source_document_id=?)
                 OR (jwc.source_type='contract' AND jwc.source_document_id=?)
                 OR (jwc.source_type='quote' AND jwc.source_document_id=?))"
        );
        $stmt->execute([(int)$invoice['job_id'], $invoiceId, (int)($invoice['contract_id'] ?? 0), (int)($quoteId ?? 0)]);
        $released = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $basis = max(0.0, (float)$assignment['source_line_total']);
            try {
                $released[(int)$assignment['id']] = $this->markEligible((int)$assignment['id'], [
                    'trigger_event' => 'invoice_paid',
                    'line_gross' => $basis,
                    'line_net' => $basis,
                    'cash_collected' => $basis,
                    'invoice_id' => $invoiceId,
                    'invoice_revision' => (int)($invoice['revision_number'] ?? 1),
                ], $actorId);
            } catch (DomainException $error) {
                // Another payment event may have released it first.
                if (!str_contains($error->getMessage(), 'Only completed work')) throw $error;
            }
        }
        return $released;
    }

    public function approvePayable(int $assignmentId, int $actorId): void
    {
        $this->transaction(function () use ($assignmentId, $actorId): void {
            $assignment = $this->assignmentForUpdate($assignmentId);
            if ((string)$assignment['status'] !== 'eligible') {
                throw new DomainException('Only eligible compensation can be approved.');
            }
            $stmt = $this->pdo->prepare(
                "UPDATE work_assignments SET status='approved_payable',approved_pay=estimated_pay,approved_by=?,approved_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND status='eligible'"
            );
            $stmt->execute([$actorId, $assignmentId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Only eligible compensation can be approved.');
            }
            $earning = $this->pdo->prepare(
                "SELECT id FROM worker_earnings
                 WHERE source_type='work_assignment' AND source_id=? AND source_revision=1 AND status='eligible'
                 LIMIT 1 FOR UPDATE"
            );
            $earning->execute([(string)$assignmentId]);
            $earningId = (string)($earning->fetchColumn() ?: '');
            if ($earningId !== '') {
                (new WorkerEarningService($this->pdo))->transition(
                    $earningId,
                    'approved',
                    $actorId,
                    'Assignment compensation approved'
                );
            }
        });
    }

    public function settle(int $assignmentId): void
    {
        $stmt = $this->pdo->prepare("UPDATE work_assignments SET status='settled',settled_at=UTC_TIMESTAMP(6) WHERE id=? AND status='approved_payable'");
        $stmt->execute([$assignmentId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Only approved compensation can be settled.');
        }
    }

    private function materializeItem(
        int $jobId,
        int $itemLibraryId,
        float $quantity,
        int $actorId,
        string $sourceType,
        ?int $documentId = null,
        ?int $lineId = null,
        ?int $revision = null,
        array $sourceLine = [],
        ?array $snapshotComponents = null,
        ?array $snapshotBundleItems = null
    ): array {
        if($snapshotBundleItems!==null)$bundleItems=$snapshotBundleItems;
        else{$bundle = $this->pdo->prepare('SELECT child_item_library_id item_library_id,quantity FROM catalog_bundle_items WHERE bundle_item_library_id=? ORDER BY display_order,child_item_library_id');$bundle->execute([$itemLibraryId]);$bundleItems=$bundle->fetchAll(PDO::FETCH_ASSOC);}
        $ids = [];
        foreach ($bundleItems as $child) {
            $ids = array_merge($ids, $this->materializeItem(
                $jobId,(int)($child['item_library_id']??$child['child_item_library_id']??0),$quantity*(float)$child['quantity'],$actorId,
                $sourceType,$documentId,$lineId,$revision,$sourceLine,is_array($child['work_components']??null)?$child['work_components']:null,[]
            ));
        }
        if($snapshotComponents!==null){$components=$snapshotComponents;}
        else{$stmt = $this->pdo->prepare(
            'SELECT c.*,i.is_active item_active,i.unit_price service_unit_price,
                    i.client_pricing_model service_pricing_model,i.client_included_minutes service_included_minutes,
                    i.client_overage_rate service_overage_rate,i.pricing_currency service_pricing_currency,
                    wt.default_compensation_method activity_compensation_method,
                    wt.default_amount activity_compensation_amount,
                    wt.default_base_minutes activity_included_minutes,
                    wt.default_overage_rate activity_overage_rate,
                    wt.default_percentage activity_percentage,
                    wt.default_percentage_basis activity_percentage_basis,
                    wt.default_eligibility_trigger activity_eligibility_trigger,
                    wt.currency activity_currency
             FROM catalog_work_components c
             JOIN item_library i ON i.id=c.item_library_id
             JOIN work_types wt ON wt.id=c.work_type_id
             WHERE c.item_library_id=? AND c.is_active=1 ORDER BY c.display_order,c.id'
        );$stmt->execute([$itemLibraryId]);$components = $stmt->fetchAll(PDO::FETCH_ASSOC);}
        if (!$components) return $ids;

        $componentIds = $this->transaction(function () use ($components, $jobId, $itemLibraryId, $quantity, $actorId, $sourceType, $documentId, $lineId, $revision, $sourceLine): array {
            $created = [];
            foreach ($components as $component) {
                $plannedQuantity = match ($component['quantity_behavior']) {
                    'per_unit' => max(0, $quantity),
                    'fixed' => max(0, (float)($component['fixed_quantity'] ?? 1)),
                    default => 1.0,
                };
                $key = hash('sha256', implode(':', [
                    $sourceType, (string)($documentId ?? $jobId), (string)($lineId ?? $itemLibraryId), (string)$component['id'],
                ]));
                $rule = [
                    'method' => $component['activity_compensation_method'] ?? $component['compensation_method'],
                    'amount' => $component['activity_compensation_amount'] ?? $component['compensation_amount'],
                    'included_minutes' => $component['activity_included_minutes'] ?? $component['included_minutes'],
                    'overage_rate' => $component['activity_overage_rate'] ?? $component['overage_rate'],
                    'percentage' => $component['activity_percentage'] ?? $component['percentage'],
                    'percentage_basis' => $component['activity_percentage_basis'] ?? $component['percentage_basis'],
                    'eligibility_trigger' => $component['activity_eligibility_trigger'] ?? $component['eligibility_trigger'],
                    'currency' => $component['activity_currency'] ?? $component['currency'],
                    'source' => 'work_activity_default',
                    'source_line_total' => (string)($sourceLine['line_total'] ?? '0.00'),
                ];
                $clientTreatment = isset($component['service_pricing_model'])
                    ? match ((string)$component['service_pricing_model']) {
                        'hourly' => 'hourly',
                        'base_overage' => 'base_overage',
                        default => 'fixed_price_included',
                    }
                    : (string)($component['client_billing_treatment'] ?? 'fixed_price_included');
                $serviceUnitPrice = $sourceLine['unit_price']
                    ?? $component['service_unit_price']
                    ?? 0;
                $clientRateSnapshot = in_array($clientTreatment, ['fixed_price_included', 'base_overage'], true)
                    ? $serviceUnitPrice
                    : ($component['client_billing_rate'] ?? $serviceUnitPrice);
                $this->pdo->prepare(
                    "INSERT INTO job_work_components
                     (job_id,item_library_id,catalog_work_component_id,work_type_id,source_type,source_document_id,source_line_id,source_revision,idempotency_key,name,description,planned_quantity,expected_duration_minutes,assignment_required,compensation_snapshot,
                      client_billing_treatment_snapshot,client_billing_rate_snapshot,client_included_minutes_snapshot,client_overage_rate_snapshot,client_billing_currency_snapshot,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),planned_quantity=IF(status='planned',VALUES(planned_quantity),planned_quantity),source_revision=GREATEST(COALESCE(source_revision,0),COALESCE(VALUES(source_revision),0))"
                )->execute([
                    $jobId, $itemLibraryId, $component['id'], $component['work_type_id'], $sourceType,
                    $documentId, $lineId, $revision, $key, $component['name'], $component['description'],
                    $plannedQuantity, $component['expected_duration_minutes'], $component['assignment_required'],
                    json_encode($rule, JSON_THROW_ON_ERROR),
                    $clientTreatment,
                    $clientRateSnapshot,
                    $component['service_included_minutes'] ?? $component['client_included_minutes'] ?? null,
                    $component['service_overage_rate'] ?? $component['client_overage_rate'] ?? null,
                    $component['service_pricing_currency'] ?? $component['client_billing_currency'] ?? $component['currency'] ?? 'USD',
                    $actorId,
                ]);
                $componentId = (int)$this->pdo->lastInsertId();
                $created[] = $componentId;
                if ((int)$component['assignment_required'] === 1) {
                    $check = $this->pdo->prepare("SELECT 1 FROM work_assignments WHERE job_work_component_id=? AND status NOT IN ('declined','cancelled') LIMIT 1");
                    $check->execute([$componentId]);
                    if (!$check->fetchColumn()) {
                        $this->pdo->prepare("INSERT INTO work_assignments (job_work_component_id,status,currency) VALUES (?,'planned',?)")
                            ->execute([$componentId, $rule['currency'] ?? 'USD']);
                    }
                }
            }
            return $created;
        });
        return array_merge($ids,$componentIds);
    }

    private function assignmentForUpdate(int $assignmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT wa.*,wp.user_id worker_user_id,wp.compensation_policy,wp.relationship_review_required,jwc.work_type_id,jwc.catalog_work_component_id,jwc.expected_duration_minutes,jwc.planned_quantity,
                    jwc.compensation_snapshot planned_compensation_snapshot,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(jwc.compensation_snapshot,"$.source_line_total")),"0") source_line_total
             FROM work_assignments wa JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
             LEFT JOIN worker_profiles wp ON wp.id=wa.worker_profile_id
             WHERE wa.id=? FOR UPDATE'
        );
        $stmt->execute([$assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Assignment not found.');
        }
        return $row;
    }

    private function workerTransition(int $assignmentId, int $workerProfileId, array $from, string $to): void
    {
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $stmt = $this->pdo->prepare("UPDATE work_assignments SET status=? WHERE id=? AND worker_profile_id=? AND status IN ({$placeholders})");
        $stmt->execute(array_merge([$to, $assignmentId, $workerProfileId], $from));
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('The assignment is not in the required state.');
        }
    }

    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
