<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/**
 * Turns an unscheduled Service Library selection into a real Job context.
 *
 * Call prepare() from the same transaction that saves the time entry so a
 * failed entry cannot leave an empty ad-hoc Job behind.
 */
final class UnscheduledServiceJobService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CompensationRuleService $compensation
    ) {}

    public function activities(?int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id service_item_id,i.item_name service_name,i.unit_price,
                    c.id catalog_work_component_id,c.work_type_id,c.name activity_name,
                    CASE i.client_pricing_model WHEN 'hourly' THEN 'hourly' WHEN 'base_overage' THEN 'base_overage' ELSE 'fixed_price_included' END client_billing_treatment,
                    CASE WHEN i.client_pricing_model='hourly' THEN i.unit_price ELSE NULL END client_billing_rate,
                    i.client_included_minutes,i.client_overage_rate,i.pricing_currency client_billing_currency
             FROM item_library i
             JOIN catalog_work_components c ON c.item_library_id=i.id AND c.is_active=1
             WHERE i.is_active=1 AND (i.organization_id IS NULL OR i.organization_id=?)
             ORDER BY i.item_name,c.display_order,c.name,c.id"
        );
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{client_id:?int,project_id:?int,job_id:int,work_type_id:int,work_assignment_id:?int,billing_treatment:string}
     */
    public function prepare(
        int $userId,
        array $input,
        bool $manageAll,
        ?int $organizationId
    ): array {
        $serviceItemId = (int)($input['service_item_id'] ?? 0);
        $catalogComponentId = (int)($input['catalog_work_component_id'] ?? 0);
        if ($serviceItemId <= 0 || $catalogComponentId <= 0) {
            throw new DomainException('Choose both a Service and the activity performed, or record the entry as unclassified work.');
        }

        $component = $this->component($serviceItemId, $catalogComponentId, $organizationId);
        $clientId = $manageAll && !empty($input['client_id']) ? (int)$input['client_id'] : null;
        $projectId = !empty($input['project_id']) ? (int)$input['project_id'] : null;
        if ($projectId) {
            $visibility = ProjectLifecycleSchema::visibility($this->pdo, '');
            $project = $this->pdo->prepare("SELECT client_id FROM projects WHERE id=? AND status NOT IN ('completed','cancelled') AND {$visibility}");
            $project->execute([$projectId]);
            $projectClient = $project->fetchColumn();
            if ($projectClient === false) {
                throw new DomainException('Choose an active project.');
            }
            $projectClientId = (int)$projectClient ?: null;
            if ($clientId && $projectClientId && $clientId !== $projectClientId) {
                throw new DomainException('The selected project does not belong to that client.');
            }
            $clientId ??= $projectClientId;
            if (!$manageAll) {
                $assignment = $this->pdo->prepare(
                    'SELECT 1 FROM project_assignments
                     WHERE project_id=? AND user_id=?
                       AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6))'
                );
                $assignment->execute([$projectId, $userId]);
                if (!$assignment->fetchColumn()) {
                    throw new DomainException('The selected project is not assigned to this worker.');
                }
            }
        }

        $jobId = !empty($input['job_id']) ? (int)$input['job_id'] : null;
        if ($jobId) {
            $job = $this->pdo->prepare(
                "SELECT j.id,j.client_id,j.project_id,j.status,j.job_origin,
                        EXISTS(
                            SELECT 1 FROM job_work_components jwc
                            JOIN work_assignments wa ON wa.job_work_component_id=jwc.id
                            JOIN worker_profiles wp ON wp.id=wa.worker_profile_id
                            WHERE jwc.job_id=j.id AND wp.user_id=?
                              AND wa.status NOT IN ('declined','cancelled')
                        ) worker_has_access
                 FROM jobs j WHERE j.id=? AND j.archived=0 FOR UPDATE"
            );
            $job->execute([$userId, $jobId]);
            $job = $job->fetch(PDO::FETCH_ASSOC);
            if (!$job || (string)$job['status'] === 'cancelled') {
                throw new DomainException('Choose an available Job.');
            }
            if (!$manageAll && empty($job['worker_has_access'])) {
                throw new DomainException('That service Job is not assigned to this worker.');
            }
            $jobClientId = !empty($job['client_id']) ? (int)$job['client_id'] : null;
            $jobProjectId = !empty($job['project_id']) ? (int)$job['project_id'] : null;
            if ($clientId && $jobClientId && $clientId !== $jobClientId) {
                throw new DomainException('The selected service Job belongs to another client.');
            }
            if ($projectId && $jobProjectId && $projectId !== $jobProjectId) {
                throw new DomainException('The selected service Job belongs to another project.');
            }
            $clientId ??= $jobClientId;
            $projectId ??= $jobProjectId;
            $this->pdo->prepare("UPDATE jobs SET status='active',completed_at=NULL WHERE id=?")
                ->execute([$jobId]);
        } else {
            throw new DomainException('Choose a Job before assigning client-billable Service work. Leave the Service blank to keep the time entry unclassified for later review.');
        }

        [$jobComponentId, $assignmentId] = $this->materializeActivity(
            $jobId,
            $userId,
            $component
        );

        return [
            'client_id' => $clientId,
            'project_id' => $projectId,
            'job_id' => $jobId,
            'work_type_id' => (int)$component['work_type_id'],
            'work_assignment_id' => $assignmentId,
            'billing_treatment' => $this->entryBillingTreatment((string)$component['client_billing_treatment']),
        ];
    }

    public function completeForTimeEntry(string $entryId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.job_id,t.work_assignment_id,wa.job_work_component_id
             FROM work_time_entries t
             JOIN jobs j ON j.id=t.job_id AND j.job_origin='unscheduled_time'
             LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
             WHERE t.id=?"
        );
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            return;
        }
        if (!empty($entry['work_assignment_id'])) {
            $this->pdo->prepare(
                "UPDATE work_assignments
                 SET status='completed',completed_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND status IN ('planned','accepted','in_progress','completed')"
            )->execute([(int)$entry['work_assignment_id']]);
        }
        if (!empty($entry['job_work_component_id'])) {
            $this->pdo->prepare("UPDATE job_work_components SET status='completed' WHERE id=?")
                ->execute([(int)$entry['job_work_component_id']]);
        }
        $this->pdo->prepare("UPDATE jobs SET status='completed',completed_at=UTC_TIMESTAMP(6) WHERE id=?")
            ->execute([(int)$entry['job_id']]);
    }

    private function component(int $serviceItemId, int $componentId, ?int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*,i.item_name service_name,i.unit_price,
                    CASE i.client_pricing_model WHEN 'hourly' THEN 'hourly' WHEN 'base_overage' THEN 'base_overage' ELSE 'fixed_price_included' END authoritative_client_billing_treatment,
                    CASE WHEN i.client_pricing_model='hourly' THEN i.unit_price ELSE NULL END authoritative_client_billing_rate,
                    i.client_included_minutes authoritative_client_included_minutes,
                    i.client_overage_rate authoritative_client_overage_rate,
                    i.pricing_currency authoritative_client_billing_currency,
                    wt.default_compensation_method activity_compensation_method,
                    wt.default_amount activity_compensation_amount,wt.default_base_minutes activity_included_minutes,
                    wt.default_overage_rate activity_overage_rate,wt.default_percentage activity_percentage,
                    wt.default_percentage_basis activity_percentage_basis,
                    wt.default_eligibility_trigger activity_eligibility_trigger,wt.currency activity_currency
             FROM catalog_work_components c
             JOIN item_library i ON i.id=c.item_library_id
             JOIN work_types wt ON wt.id=c.work_type_id
             WHERE c.id=? AND c.item_library_id=? AND c.is_active=1 AND i.is_active=1
               AND wt.is_active=1 AND (i.organization_id IS NULL OR i.organization_id=?)"
        );
        $stmt->execute([$componentId, $serviceItemId, $organizationId]);
        $component = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$component) {
            throw new DomainException('Choose an active activity from that Service.');
        }
        return $component;
    }

    /** @return array{0:int,1:?int} */
    private function materializeActivity(int $jobId, int $userId, array $component): array
    {
        $key = hash('sha256', 'unscheduled_time:' . $jobId . ':' . $component['id']);
        $billingTreatment = (string)($component['authoritative_client_billing_treatment'] ?? $component['client_billing_treatment']);
        $billingRateSnapshot = in_array($billingTreatment, ['fixed_price_included', 'base_overage'], true)
            ? $component['unit_price']
            : ($component['authoritative_client_billing_rate'] ?? $component['client_billing_rate']);
        $compensationRule = [
            'method' => $component['activity_compensation_method'] ?? $component['compensation_method'],
            'amount' => $component['activity_compensation_amount'] ?? $component['compensation_amount'],
            'included_minutes' => $component['activity_included_minutes'] ?? $component['included_minutes'],
            'overage_rate' => $component['activity_overage_rate'] ?? $component['overage_rate'],
            'percentage' => $component['activity_percentage'] ?? $component['percentage'],
            'percentage_basis' => $component['activity_percentage_basis'] ?? $component['percentage_basis'],
            'eligibility_trigger' => $component['activity_eligibility_trigger'] ?? $component['eligibility_trigger'],
            'currency' => $component['activity_currency'] ?? $component['currency'],
            'source' => 'work_activity_default',
            'source_line_total' => (string)$component['unit_price'],
        ];
        $stmt = $this->pdo->prepare(
            "INSERT INTO job_work_components
             (job_id,item_library_id,catalog_work_component_id,work_type_id,source_type,idempotency_key,
              name,description,planned_quantity,expected_duration_minutes,assignment_required,compensation_snapshot,
              client_billing_treatment_snapshot,client_billing_rate_snapshot,client_included_minutes_snapshot,
              client_overage_rate_snapshot,client_billing_currency_snapshot,created_by)
             VALUES (?,?,?,?,'catalog',?,?,?,1,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status='in_progress'"
        );
        $stmt->execute([
            $jobId,
            $component['item_library_id'],
            $component['id'],
            $component['work_type_id'],
            $key,
            $component['name'],
            $component['description'],
            $component['expected_duration_minutes'],
            $component['assignment_required'],
            json_encode($compensationRule, JSON_THROW_ON_ERROR),
            $billingTreatment,
            $billingRateSnapshot,
            $component['authoritative_client_included_minutes'] ?? $component['client_included_minutes'],
            $component['authoritative_client_overage_rate'] ?? $component['client_overage_rate'],
            $component['authoritative_client_billing_currency'] ?? $component['client_billing_currency'],
            $userId,
        ]);
        $jobComponentId = (int)$this->pdo->lastInsertId();

        $worker = $this->pdo->prepare("SELECT id FROM worker_profiles WHERE user_id=? AND status='active' LIMIT 1");
        $worker->execute([$userId]);
        $workerProfileId = (int)$worker->fetchColumn();
        if ($workerProfileId <= 0) {
            return [$jobComponentId, null];
        }

        $resolvedRule = $this->compensation->resolve(
            $workerProfileId,
            (int)$component['work_type_id'],
            (int)$component['id'],
            null
        );
        $assignment = $this->pdo->prepare(
            "SELECT id FROM work_assignments
             WHERE job_work_component_id=? AND worker_profile_id=?
               AND status NOT IN ('declined','cancelled')
             ORDER BY id LIMIT 1 FOR UPDATE"
        );
        $assignment->execute([$jobComponentId, $workerProfileId]);
        $assignmentId = (int)$assignment->fetchColumn();
        if ($assignmentId > 0) {
            $this->pdo->prepare(
                "UPDATE work_assignments
                 SET status='in_progress',completed_at=NULL,
                     compensation_snapshot=COALESCE(compensation_snapshot,?)
                 WHERE id=?"
            )->execute([json_encode($resolvedRule, JSON_THROW_ON_ERROR), $assignmentId]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO work_assignments
                 (job_work_component_id,worker_profile_id,status,compensation_snapshot,currency,responded_at)
                 VALUES (?,?,'in_progress',?,?,UTC_TIMESTAMP(6))"
            )->execute([
                $jobComponentId,
                $workerProfileId,
                json_encode($resolvedRule, JSON_THROW_ON_ERROR),
                $resolvedRule['currency'] ?? $component['currency'] ?? 'USD',
            ]);
            $assignmentId = (int)$this->pdo->lastInsertId();
        }
        return [$jobComponentId, $assignmentId];
    }

    private function entryBillingTreatment(string $treatment): string
    {
        return match ($treatment) {
            'hourly' => 'ready',
            'fixed_price_included', 'base_overage' => 'included_fixed',
            default => 'nonbillable',
        };
    }
}
