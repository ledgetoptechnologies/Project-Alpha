<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

final class PayPeriodService
{
    public function __construct(private readonly PDO $pdo) {}

    public function periodFor(DateTimeImmutable $date): array
    {
        [$cadence, $anchor, $customDays] = $this->configuration();
        [$start, $end] = $this->bounds($date, $cadence, $anchor, $customDays);
        $this->pdo->prepare(
            'INSERT INTO pay_periods (period_start,period_end,cadence) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        )->execute([$start->format('Y-m-d'), $end->format('Y-m-d'), $cadence]);
        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM pay_periods WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function submit(int $periodId, int $workerProfileId, ?string $notes = null): void
    {
        $this->assertOpen($periodId);
        $this->pdo->prepare(
            "INSERT INTO worker_period_submissions (pay_period_id,worker_profile_id,status,submitted_at,notes)
             VALUES (?,?,'submitted',UTC_TIMESTAMP(6),?)
             ON DUPLICATE KEY UPDATE status='submitted',submitted_at=VALUES(submitted_at),notes=VALUES(notes)"
        )->execute([$periodId, $workerProfileId, trim((string)$notes) ?: null]);
    }

    /** @return array{closed:bool,warnings:array<int,string>,statement_ids:array<int,int>} */
    public function close(int $periodId, int $actorId, bool $force = false): array
    {
        return $this->transaction(function () use ($periodId, $actorId, $force): array {
            $period = $this->periodForUpdate($periodId);
            if ($period['status'] !== 'open') {
                throw new DomainException('Only an open pay period can be closed.');
            }
            $missing = $this->pdo->prepare(
                "SELECT wp.display_name FROM worker_profiles wp
                 WHERE wp.status='active' AND wp.compensation_policy='rules'
                   AND NOT EXISTS (SELECT 1 FROM worker_period_submissions s WHERE s.pay_period_id=? AND s.worker_profile_id=wp.id AND s.status IN ('submitted','accepted','adjusted'))
                 ORDER BY wp.display_name"
            );
            $missing->execute([$periodId]);
            $warnings = array_map(
                static fn(string $name): string => ($name ?: 'Unnamed worker') . ' has not submitted this period.',
                $missing->fetchAll(PDO::FETCH_COLUMN)
            );
            if ($warnings && !$force) {
                return ['closed' => false, 'warnings' => $warnings, 'statement_ids' => []];
            }

            $this->pdo->prepare("UPDATE pay_periods SET status='closing' WHERE id=?")->execute([$periodId]);
            $workers = $this->pdo->prepare(
                "SELECT DISTINCT wp.id,wp.relationship_type,wp.currency FROM worker_profiles wp JOIN (
                   SELECT e.worker_profile_id
                   FROM worker_earnings e
                   LEFT JOIN work_time_entries wt ON wt.id=e.work_time_entry_id
                   LEFT JOIN work_assignments wa0 ON wa0.id=e.work_assignment_id
                   WHERE e.status='approved'
                     AND (e.pay_period_id=? OR (e.pay_period_id IS NULL AND
                          COALESCE(DATE(wt.start_time),DATE(wa0.completed_at),DATE(e.eligible_at),DATE(e.approved_at),DATE(e.created_at)) BETWEEN ? AND ?))
                   UNION ALL
                   SELECT wa.worker_profile_id FROM work_assignments wa
                   WHERE wa.status='approved_payable'
                     AND DATE(COALESCE(wa.completed_at,wa.approved_at,wa.eligible_at,wa.created_at)) BETWEEN ? AND ?
                     AND NOT EXISTS (SELECT 1 FROM worker_earnings le WHERE le.work_assignment_id=wa.id)
                   UNION ALL
                   SELECT wp2.id FROM work_pay_accruals a
                   JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
                   JOIN worker_profiles wp2 ON wp2.user_id=a.employee_user_id
                   WHERE a.status='pending' AND DATE(s.start_time) BETWEEN ? AND ?
                     AND NOT EXISTS (SELECT 1 FROM worker_earnings le WHERE le.work_time_entry_id=s.time_entry_id)
                   UNION ALL
                   SELECT ca.worker_profile_id FROM compensation_adjustments ca
                   WHERE ca.pay_period_id=? AND ca.status='reviewed'
                 ) payable ON payable.worker_profile_id=wp.id"
            );
            $workers->execute([
                $periodId, $period['period_start'], $period['period_end'],
                $period['period_start'], $period['period_end'],
                $period['period_start'], $period['period_end'],
                $periodId,
            ]);
            $statementIds = [];
            foreach ($workers->fetchAll(PDO::FETCH_ASSOC) as $worker) {
                $statementIds[] = $this->buildStatement($period, $worker, $actorId);
            }
            $this->pdo->prepare("UPDATE pay_periods SET status='closed',closed_by=?,closed_at=UTC_TIMESTAMP(6) WHERE id=?")
                ->execute([$actorId, $periodId]);
            return ['closed' => true, 'warnings' => $warnings, 'statement_ids' => $statementIds];
        });
    }

    public function settleStatement(int $statementId, ?int $actorId = null): void
    {
        $this->transaction(function () use ($statementId, $actorId): void {
            $stmt = $this->pdo->prepare("SELECT status FROM worker_statements WHERE id=? FOR UPDATE");
            $stmt->execute([$statementId]);
            if ($stmt->fetchColumn() !== 'issued') {
                throw new DomainException('Only an issued statement can be settled.');
            }
            $earnings = $this->pdo->prepare(
                "SELECT e.id,e.source_key,e.amount,e.currency,e.work_time_entry_id
                 FROM worker_earnings e
                 JOIN worker_statement_lines l ON l.worker_earning_id=e.id
                 WHERE l.worker_statement_id=? AND e.status='included' FOR UPDATE"
            );
            $earnings->execute([$statementId]);
            $earnings = $earnings->fetchAll(PDO::FETCH_ASSOC);
            foreach ($earnings as $earning) {
                $update = $this->pdo->prepare(
                    "UPDATE worker_earnings SET status='settled',settled_at=UTC_TIMESTAMP(6)
                     WHERE id=? AND status='included'"
                );
                $update->execute([$earning['id']]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('An earning changed before the statement was settled.');
                }
                $this->pdo->prepare(
                    "INSERT INTO worker_earning_events
                     (worker_earning_id,from_status,to_status,reason,event_snapshot,actor_id)
                     VALUES (?,'included','settled','statement_settled',JSON_OBJECT('statement_id',?,'source_key',?,'amount',?,'currency',?),?)"
                )->execute([
                    $earning['id'], $statementId, $earning['source_key'], $earning['amount'],
                    $earning['currency'], ($actorId ?? 0) > 0 ? $actorId : null,
                ]);
                if (!empty($earning['work_time_entry_id'])) {
                    $this->pdo->prepare("UPDATE work_time_entries SET compensation_state='settled' WHERE id=?")
                        ->execute([$earning['work_time_entry_id']]);
                }
            }
            $this->pdo->prepare("UPDATE worker_statements SET status='settled',settled_at=UTC_TIMESTAMP(6) WHERE id=?")
                ->execute([$statementId]);
            $this->pdo->prepare(
                "UPDATE work_assignments wa JOIN worker_statement_lines l ON l.work_assignment_id=wa.id
                 SET wa.status='settled',wa.settled_at=UTC_TIMESTAMP(6) WHERE l.worker_statement_id=? AND wa.status='approved_payable'"
            )->execute([$statementId]);
            $this->pdo->prepare(
                "UPDATE work_pay_accruals a JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
                 JOIN worker_statement_lines l ON l.work_time_entry_id=s.time_entry_id
                 SET a.status='paid',a.paid_at=UTC_TIMESTAMP(6) WHERE l.worker_statement_id=? AND a.status='pending'"
            )->execute([$statementId]);
        });
    }

    public function recordAdjustment(int $workerProfileId,int $payPeriodId,string $type,float $amount,string $reason,?int $sourceAssignmentId,int $actorId): int
    {
        if(!in_array($type,['credit','debit'],true)||$amount<=0||trim($reason)==='')throw new DomainException('Enter a valid adjustment type, amount, and reason.');
        $this->assertOpen($payPeriodId);
        $snapshot=null;
        if($sourceAssignmentId){$stmt=$this->pdo->prepare("SELECT id,worker_profile_id,status,approved_pay,compensation_snapshot FROM work_assignments WHERE id=? AND worker_profile_id=? AND status IN ('approved_payable','settled')");$stmt->execute([$sourceAssignmentId,$workerProfileId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Adjustments may reference only approved or settled compensation for this worker.');$snapshot=json_encode($row,JSON_THROW_ON_ERROR);}
        $this->pdo->prepare("INSERT INTO compensation_adjustments (worker_profile_id,pay_period_id,source_assignment_id,adjustment_type,amount,reason,source_snapshot,status,created_by) VALUES (?,?,?,?,?,?,?,'pending',?)")
            ->execute([$workerProfileId,$payPeriodId,$sourceAssignmentId,$type,number_format($amount,2,'.',''),trim($reason),$snapshot,$actorId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function reviewAdjustment(int $adjustmentId,int $actorId): void
    {
        $stmt=$this->pdo->prepare("UPDATE compensation_adjustments a JOIN pay_periods p ON p.id=a.pay_period_id SET a.status='reviewed',a.reviewed_by=?,a.reviewed_at=UTC_TIMESTAMP(6) WHERE a.id=? AND a.status='pending' AND p.status='open'");
        $stmt->execute([$actorId,$adjustmentId]);
        if($stmt->rowCount()!==1)throw new DomainException('Only a pending adjustment in an open period can be reviewed.');
    }

    private function buildStatement(array $period, array $worker, int $actorId): int
    {
        $type = $worker['relationship_type'] === 'contractor' ? 'contractor_settlement' : 'employee_pay';
        $this->pdo->prepare(
            "INSERT INTO worker_statements (pay_period_id,worker_profile_id,statement_type,status,currency,created_by)
             VALUES (?,?,?,'draft',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        )->execute([$period['id'], $worker['id'], $type, $worker['currency'], $actorId]);
        $statementId = (int)$this->pdo->lastInsertId();

        $earnings = $this->pdo->prepare(
            "SELECT e.*,wt.description AS time_description,wt.start_time AS time_worked_at,
                    jwc.name AS assignment_name,wa.completed_at AS assignment_completed_at
             FROM worker_earnings e
             LEFT JOIN work_time_entries wt ON wt.id=e.work_time_entry_id
             LEFT JOIN work_assignments wa ON wa.id=e.work_assignment_id
             LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
             WHERE e.worker_profile_id=? AND e.status='approved'
               AND (e.pay_period_id=? OR (e.pay_period_id IS NULL AND
                    COALESCE(DATE(wt.start_time),DATE(wa.completed_at),DATE(e.eligible_at),DATE(e.approved_at),DATE(e.created_at)) BETWEEN ? AND ?))
             ORDER BY COALESCE(wt.start_time,wa.completed_at,e.eligible_at,e.approved_at,e.created_at),e.created_at
             FOR UPDATE"
        );
        $earnings->execute([$worker['id'], $period['id'], $period['period_start'], $period['period_end']]);
        $earningService = new WorkerEarningService($this->pdo);
        foreach ($earnings->fetchAll(PDO::FETCH_ASSOC) as $earning) {
            $description = trim((string)($earning['assignment_name'] ?: $earning['time_description']));
            if ($description === '') {
                $description = match ((string)$earning['source_type']) {
                    'mileage' => 'Mileage reimbursement',
                    'adjustment' => 'Compensation adjustment',
                    default => 'Approved work',
                };
            }
            $this->pdo->prepare(
                'INSERT INTO worker_statement_lines
                 (worker_statement_id,work_assignment_id,work_time_entry_id,description,quantity,rate,amount,calculation_snapshot)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $statementId,
                $earning['work_assignment_id'],
                $earning['work_time_entry_id'],
                mb_substr($description, 0, 500),
                $earning['quantity'],
                $earning['rate'],
                $earning['amount'],
                $earning['calculation_snapshot'],
            ]);
            $lineId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE worker_earnings SET pay_period_id=? WHERE id=? AND pay_period_id IS NULL')
                ->execute([$period['id'], $earning['id']]);
            $earningService->includeOnStatement((string)$earning['id'], $lineId, $actorId);
        }

        // Compatibility only: legacy compensation is used when no canonical
        // earning exists for the source row.
        $assignments = $this->pdo->prepare(
            "SELECT wa.id,wa.approved_pay,wa.compensation_snapshot,jwc.name,jwc.planned_quantity
             FROM work_assignments wa JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
             WHERE wa.worker_profile_id=? AND wa.status='approved_payable'
               AND DATE(COALESCE(wa.completed_at,wa.approved_at,wa.eligible_at,wa.created_at)) BETWEEN ? AND ?
               AND NOT EXISTS (SELECT 1 FROM worker_earnings e WHERE e.work_assignment_id=wa.id)"
        );
        $assignments->execute([$worker['id'], $period['period_start'], $period['period_end']]);
        foreach ($assignments->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $this->pdo->prepare(
                'INSERT INTO worker_statement_lines (worker_statement_id,work_assignment_id,description,quantity,amount,calculation_snapshot)
                 VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id'
            )->execute([
                $statementId, $assignment['id'], $assignment['name'], $assignment['planned_quantity'],
                $assignment['approved_pay'], $assignment['compensation_snapshot'],
            ]);
        }

        $time=$this->pdo->prepare(
            "SELECT a.amount,a.hours,a.rate,s.time_entry_id,s.description,s.pay_rate,s.duration_seconds,s.approved_at
             FROM work_pay_accruals a JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
             JOIN worker_profiles wp ON wp.user_id=a.employee_user_id
             WHERE wp.id=? AND a.status='pending' AND DATE(s.start_time) BETWEEN ? AND ?
               AND NOT EXISTS (SELECT 1 FROM worker_earnings e WHERE e.work_time_entry_id=s.time_entry_id)"
        );
        $time->execute([$worker['id'],$period['period_start'],$period['period_end']]);
        foreach($time->fetchAll(PDO::FETCH_ASSOC) as $line){
            $this->pdo->prepare('INSERT INTO worker_statement_lines (worker_statement_id,work_time_entry_id,description,quantity,rate,amount,calculation_snapshot) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id')
                ->execute([$statementId,$line['time_entry_id'],$line['description']?:'Approved time',$line['hours'],$line['rate'],$line['amount'],json_encode($line,JSON_THROW_ON_ERROR)]);
        }

        $adjustments = $this->pdo->prepare(
            "SELECT * FROM compensation_adjustments WHERE worker_profile_id=? AND pay_period_id=? AND status='reviewed' FOR UPDATE"
        );
        $adjustments->execute([$worker['id'], $period['id']]);
        foreach ($adjustments->fetchAll(PDO::FETCH_ASSOC) as $adjustment) {
            $signed = $adjustment['adjustment_type'] === 'debit' ? -(float)$adjustment['amount'] : (float)$adjustment['amount'];
            $earning = $earningService->record(
                'adjustment',
                (string)$adjustment['id'],
                1,
                (int)$worker['id'],
                'adjustment',
                '1',
                null,
                (string)$adjustment['amount'],
                (string)$worker['currency'],
                [
                    'adjustment_id' => (int)$adjustment['id'],
                    'direction' => (string)$adjustment['adjustment_type'],
                    'reason' => (string)$adjustment['reason'],
                    'source_snapshot' => $adjustment['source_snapshot'],
                ],
                $actorId,
                'approved',
                null,
                null,
                (int)$period['id']
            );
            $this->pdo->prepare(
                'INSERT INTO worker_statement_lines (worker_statement_id,description,quantity,amount,calculation_snapshot) VALUES (?, ?, 1, ?, ?)'
            )->execute([$statementId, $adjustment['reason'], number_format($signed, 2, '.', ''), json_encode($adjustment, JSON_THROW_ON_ERROR)]);
            $lineId = (int)$this->pdo->lastInsertId();
            $earningService->includeOnStatement((string)$earning['id'], $lineId, $actorId);
            $this->pdo->prepare("UPDATE compensation_adjustments SET status='applied',statement_line_id=? WHERE id=?")
                ->execute([$lineId, $adjustment['id']]);
        }

        $totals = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN COALESCE(e.source_type,'')<>'adjustment'
                                           AND (e.id IS NOT NULL OR l.work_assignment_id IS NOT NULL OR l.work_time_entry_id IS NOT NULL)
                                      THEN l.amount ELSE 0 END),0) gross,
                    COALESCE(SUM(CASE WHEN e.source_type='adjustment'
                                           OR (e.id IS NULL AND l.work_assignment_id IS NULL AND l.work_time_entry_id IS NULL)
                                      THEN l.amount ELSE 0 END),0) adjustments,
                    COALESCE(SUM(l.amount),0) total
             FROM worker_statement_lines l
             LEFT JOIN worker_earnings e ON e.id=l.worker_earning_id
             WHERE l.worker_statement_id=?"
        );
        $totals->execute([$statementId]);
        $totals = $totals->fetch(PDO::FETCH_ASSOC);
        $this->pdo->prepare(
            "UPDATE worker_statements SET gross_amount=?,adjustment_amount=?,total_amount=?,status='issued',issued_at=UTC_TIMESTAMP(6) WHERE id=?"
        )->execute([$totals['gross'], $totals['adjustments'], $totals['total'], $statementId]);
        return $statementId;
    }

    private function configuration(): array
    {
        $stmt = $this->pdo->query(
            "SELECT config_key,config_value FROM app_config WHERE organization_id=0
             AND config_key IN ('workforce_pay_period_cadence','workforce_pay_period_anchor','workforce_pay_period_custom_days')"
        );
        $values = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $cadence = in_array($values['workforce_pay_period_cadence'] ?? '', ['weekly','biweekly','semimonthly','monthly','custom'], true)
            ? $values['workforce_pay_period_cadence'] : 'biweekly';
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($values['workforce_pay_period_anchor'] ?? ''))
            ?: new DateTimeImmutable('1970-01-05');
        return [$cadence, $anchor, max(1, min(366, (int)($values['workforce_pay_period_custom_days'] ?? 14)))];
    }

    private function bounds(DateTimeImmutable $date, string $cadence, DateTimeImmutable $anchor, int $customDays): array
    {
        $date = $date->setTime(0, 0);
        if ($cadence === 'semimonthly') {
            $start = (int)$date->format('j') <= 15 ? $date->modify('first day of this month') : $date->setDate((int)$date->format('Y'), (int)$date->format('n'), 16);
            $end = (int)$date->format('j') <= 15 ? $start->setDate((int)$start->format('Y'), (int)$start->format('n'), 15) : $date->modify('last day of this month');
            return [$start, $end];
        }
        if ($cadence === 'monthly') {
            $start = $date->modify('first day of this month');
            return [$start, $date->modify('last day of this month')];
        }
        $days = $cadence === 'weekly' ? 7 : ($cadence === 'custom' ? $customDays : 14);
        $delta = (int)$anchor->diff($date)->format('%r%a');
        $periodOffset = (int)floor($delta / $days) * $days;
        $start = $anchor->add(new DateInterval('P' . abs($periodOffset) . 'D'));
        if ($periodOffset < 0) {
            $start = $anchor->sub(new DateInterval('P' . abs($periodOffset) . 'D'));
        }
        return [$start, $start->add(new DateInterval('P' . ($days - 1) . 'D'))];
    }

    private function assertOpen(int $periodId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM pay_periods WHERE id=? AND status='open'");
        $stmt->execute([$periodId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('The pay period is closed. Late work must be entered as a next-period adjustment.');
        }
    }

    private function periodForUpdate(int $periodId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pay_periods WHERE id=? FOR UPDATE');
        $stmt->execute([$periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            throw new DomainException('Pay period not found.');
        }
        return $period;
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
