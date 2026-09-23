<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\Uuid;
use DomainException;
use PDO;
use Throwable;

/** Unified compensation ledger for approved time and fixed assignments. */
final class WorkerEarningService
{
    private const SOURCE_TYPES = ['time_entry', 'work_assignment', 'adjustment', 'mileage', 'manual', 'legacy'];
    private const METHODS = ['hourly', 'fixed', 'base_overage', 'percentage', 'reimbursement', 'adjustment', 'manual'];

    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        'provisional' => ['needs_setup', 'eligible', 'voided'],
        'needs_setup' => ['provisional', 'eligible', 'voided'],
        'eligible' => ['approved', 'voided'],
        'approved' => ['included', 'adjusted', 'voided'],
        'included' => ['settled', 'adjusted', 'voided'],
        'settled' => ['adjusted'],
        'adjusted' => [],
        'voided' => [],
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string,mixed> $calculationSnapshot
     * @return array{id:string,source_key:string,status:string,amount:?string}
     */
    public function record(
        string $sourceType,
        string $sourceId,
        int $sourceRevision,
        int $workerProfileId,
        string $method,
        string $quantity,
        ?string $rate,
        ?string $amount,
        string $currency,
        array $calculationSnapshot,
        int $actorId,
        string $status = 'provisional',
        ?string $workTimeEntryId = null,
        ?int $workAssignmentId = null,
        ?int $payPeriodId = null
    ): array {
        if (!in_array($sourceType, self::SOURCE_TYPES, true) || !in_array($method, self::METHODS, true)) {
            throw new DomainException('Choose a valid earning source and calculation method.');
        }
        if ($sourceId === '' || $sourceRevision <= 0 || $workerProfileId <= 0 || $actorId <= 0) {
            throw new DomainException('Worker earning data is incomplete.');
        }
        self::assertKnownStatus($status);
        $quantity = self::normalizeNonnegativeDecimal($quantity, 4, 'Earning quantity');
        $rate = self::normalizeNullableNonnegativeDecimal($rate, 4, 'Earning rate');
        $amount = self::normalizeNullableNonnegativeDecimal($amount, 2, 'Earning amount');
        if ($amount === null && !in_array($status, ['provisional', 'needs_setup'], true)) {
            throw new DomainException('A calculated amount is required before an earning becomes eligible.');
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('Earning currency must be a three-letter code.');
        }
        $sourceKey = self::sourceKey($sourceType, $sourceId, $sourceRevision);

        return $this->transaction(function () use (
            $sourceType,
            $sourceId,
            $sourceRevision,
            $workerProfileId,
            $method,
            $quantity,
            $rate,
            $amount,
            $currency,
            $calculationSnapshot,
            $actorId,
            $status,
            $workTimeEntryId,
            $workAssignmentId,
            $payPeriodId,
            $sourceKey
        ): array {
            $workerStatement = $this->pdo->prepare('SELECT * FROM worker_profiles WHERE id=? FOR UPDATE');
            $workerStatement->execute([$workerProfileId]);
            $worker = $workerStatement->fetch(PDO::FETCH_ASSOC);
            if (!$worker) {
                throw new DomainException('Worker profile not found.');
            }
            // Compensation policy, never relationship or account ACL role,
            // determines whether this worker can receive an earning.
            if ((string)$worker['compensation_policy'] === 'owner_no_pay') {
                throw new DomainException('This worker profile is configured as nonpayable.');
            }
            if ((int)$worker['relationship_review_required'] === 1
                || in_array((string)$worker['compensation_policy'], ['needs_review', 'needs_setup'], true)) {
                throw new DomainException('Reconcile this worker relationship before creating earnings.');
            }
            if ((string)$worker['compensation_policy'] === 'nonpayable') {
                throw new DomainException('This worker profile is configured as nonpayable.');
            }

            $existingStatement = $this->pdo->prepare(
                'SELECT id,source_key,status,amount FROM worker_earnings WHERE source_key=?'
            );
            $existingStatement->execute([$sourceKey]);
            $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'id' => (string)$existing['id'],
                    'source_key' => (string)$existing['source_key'],
                    'status' => (string)$existing['status'],
                    'amount' => $existing['amount'] === null ? null : number_format((float)$existing['amount'], 2, '.', ''),
                ];
            }

            if ($sourceType === 'time_entry' && $workTimeEntryId !== $sourceId) {
                throw new DomainException('Time-entry earnings must identify their source time entry.');
            }
            if ($sourceType === 'work_assignment' && (string)$workAssignmentId !== $sourceId) {
                throw new DomainException('Assignment earnings must identify their source assignment.');
            }
            $earningId = Uuid::v4();
            $snapshot = json_encode(
                $calculationSnapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $insert = $this->pdo->prepare(
                'INSERT INTO worker_earnings
                 (id,source_key,source_type,source_id,source_revision,worker_profile_id,work_time_entry_id,
                  work_assignment_id,pay_period_id,status,method,quantity,rate,amount,currency,calculation_snapshot,
                  eligible_by,eligible_at,approved_by,approved_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $isEligible = $status === 'eligible';
            $isApproved = $status === 'approved';
            $insert->execute([
                $earningId,
                $sourceKey,
                $sourceType,
                $sourceId,
                $sourceRevision,
                $workerProfileId,
                $workTimeEntryId,
                $workAssignmentId,
                $payPeriodId,
                $status,
                $method,
                $quantity,
                $rate,
                $amount,
                $currency,
                $snapshot,
                $isEligible || $isApproved ? $actorId : null,
                $isEligible || $isApproved ? gmdate('Y-m-d H:i:s.u') : null,
                $isApproved ? $actorId : null,
                $isApproved ? gmdate('Y-m-d H:i:s.u') : null,
            ]);
            $this->recordEvent($earningId, null, $status, 'earning_created', $snapshot, $actorId);
            if ($workTimeEntryId !== null) {
                $this->syncTimeCompensationState($workTimeEntryId, $status);
            }
            return ['id' => $earningId, 'source_key' => $sourceKey, 'status' => $status, 'amount' => $amount];
        });
    }

    public function transition(string $earningId, string $toStatus, int $actorId, ?string $reason = null): void
    {
        if ($earningId === '' || $actorId <= 0) {
            throw new DomainException('Choose an earning and authenticated actor.');
        }
        self::assertKnownStatus($toStatus);
        $this->transaction(function () use ($earningId, $toStatus, $actorId, $reason): void {
            $statement = $this->pdo->prepare('SELECT * FROM worker_earnings WHERE id=? FOR UPDATE');
            $statement->execute([$earningId]);
            $earning = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$earning) {
                throw new DomainException('Worker earning not found.');
            }
            $fromStatus = (string)$earning['status'];
            self::assertTransition($fromStatus, $toStatus);
            if ($fromStatus === $toStatus) {
                return;
            }
            if ($toStatus === 'included') {
                throw new DomainException('Use statement inclusion so the earning and statement line are linked atomically.');
            }
            if ($toStatus === 'settled' && empty($earning['statement_line_id'])) {
                throw new DomainException('Only an earning included on a statement can be settled.');
            }
            if (in_array($toStatus, ['eligible', 'approved', 'included', 'settled'], true)
                && $earning['amount'] === null) {
                throw new DomainException('Resolve earning setup before advancing its status.');
            }
            $cleanReason = trim((string)$reason);
            if (in_array($toStatus, ['adjusted', 'voided'], true) && $cleanReason === '') {
                throw new DomainException('Adjusted or voided earnings require a reason.');
            }

            $sets = ['status=?'];
            $parameters = [$toStatus];
            if ($toStatus === 'eligible') {
                $sets[] = 'eligible_by=?';
                $sets[] = 'eligible_at=UTC_TIMESTAMP(6)';
                $parameters[] = $actorId;
            } elseif ($toStatus === 'approved') {
                $sets[] = 'approved_by=?';
                $sets[] = 'approved_at=UTC_TIMESTAMP(6)';
                $parameters[] = $actorId;
            } elseif ($toStatus === 'settled') {
                $sets[] = 'settled_at=UTC_TIMESTAMP(6)';
            } elseif ($toStatus === 'voided') {
                $sets[] = 'voided_by=?';
                $sets[] = 'voided_at=UTC_TIMESTAMP(6)';
                $sets[] = 'void_reason=?';
                $parameters[] = $actorId;
                $parameters[] = $cleanReason;
            }
            $parameters[] = $earningId;
            $parameters[] = $fromStatus;
            $update = $this->pdo->prepare(
                'UPDATE worker_earnings SET ' . implode(',', $sets) . ' WHERE id=? AND status=?'
            );
            $update->execute($parameters);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The earning changed before its status was updated.');
            }
            $eventSnapshot = json_encode([
                'source_key' => $earning['source_key'],
                'amount' => $earning['amount'],
                'currency' => $earning['currency'],
                'reason' => $cleanReason !== '' ? $cleanReason : null,
            ], JSON_THROW_ON_ERROR);
            $this->recordEvent($earningId, $fromStatus, $toStatus, $cleanReason, $eventSnapshot, $actorId);
            if (!empty($earning['work_time_entry_id'])) {
                $this->syncTimeCompensationState((string)$earning['work_time_entry_id'], $toStatus);
            }
        });
    }

    public function includeOnStatement(string $earningId, int $statementLineId, int $actorId): void
    {
        if ($earningId === '' || $statementLineId <= 0 || $actorId <= 0) {
            throw new DomainException('Choose an earning and statement line.');
        }
        $this->transaction(function () use ($earningId, $statementLineId, $actorId): void {
            $statement = $this->pdo->prepare('SELECT * FROM worker_earnings WHERE id=? FOR UPDATE');
            $statement->execute([$earningId]);
            $earning = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$earning || (string)$earning['status'] !== 'approved') {
                throw new DomainException('Only an approved earning can be included on a statement.');
            }
            $line = $this->pdo->prepare(
                'SELECT l.id,s.worker_profile_id FROM worker_statement_lines l
                 JOIN worker_statements s ON s.id=l.worker_statement_id WHERE l.id=? FOR UPDATE'
            );
            $line->execute([$statementLineId]);
            $statementLine = $line->fetch(PDO::FETCH_ASSOC);
            if (!$statementLine || (int)$statementLine['worker_profile_id'] !== (int)$earning['worker_profile_id']) {
                throw new DomainException('Statement line does not belong to this worker.');
            }
            $linkLine = $this->pdo->prepare(
                'UPDATE worker_statement_lines SET worker_earning_id=? WHERE id=? AND worker_earning_id IS NULL'
            );
            $linkLine->execute([$earningId, $statementLineId]);
            if ($linkLine->rowCount() !== 1) {
                throw new DomainException('This statement line already contains another earning.');
            }
            $update = $this->pdo->prepare(
                "UPDATE worker_earnings SET status='included',statement_line_id=? WHERE id=? AND status='approved'"
            );
            $update->execute([$statementLineId, $earningId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The earning changed before statement inclusion completed.');
            }
            $snapshot = json_encode(['statement_line_id' => $statementLineId], JSON_THROW_ON_ERROR);
            $this->recordEvent($earningId, 'approved', 'included', 'included_on_statement', $snapshot, $actorId);
            if (!empty($earning['work_time_entry_id'])) {
                $this->syncTimeCompensationState((string)$earning['work_time_entry_id'], 'included');
            }
        });
    }

    public static function sourceKey(string $sourceType, string $sourceId, int $sourceRevision): string
    {
        if ($sourceId === '' || $sourceRevision <= 0) {
            throw new DomainException('Earning source identity is incomplete.');
        }
        return $sourceType . ':' . $sourceId . ':' . $sourceRevision;
    }

    public static function assertTransition(string $fromStatus, string $toStatus): void
    {
        self::assertKnownStatus($fromStatus);
        self::assertKnownStatus($toStatus);
        if ($fromStatus === $toStatus) {
            return;
        }
        if (!in_array($toStatus, self::TRANSITIONS[$fromStatus], true)) {
            throw new DomainException("Worker earning cannot move from {$fromStatus} to {$toStatus}.");
        }
    }

    private static function assertKnownStatus(string $status): void
    {
        if (!array_key_exists($status, self::TRANSITIONS)) {
            throw new DomainException('Unknown worker-earning status.');
        }
    }

    private function syncTimeCompensationState(string $timeEntryId, string $earningStatus): void
    {
        $state = match ($earningStatus) {
            'needs_setup' => 'needs_setup',
            'provisional' => 'provisional',
            'eligible' => 'eligible',
            'approved' => 'approved',
            'included' => 'included',
            'settled' => 'settled',
            'adjusted' => 'adjusted',
            'voided' => 'voided',
        };
        $this->pdo->prepare('UPDATE work_time_entries SET compensation_state=? WHERE id=?')
            ->execute([$state, $timeEntryId]);
    }

    private function recordEvent(
        string $earningId,
        ?string $fromStatus,
        string $toStatus,
        ?string $reason,
        string $snapshot,
        int $actorId
    ): void {
        $this->pdo->prepare(
            'INSERT INTO worker_earning_events
             (worker_earning_id,from_status,to_status,reason,event_snapshot,actor_id)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $earningId,
            $fromStatus,
            $toStatus,
            trim((string)$reason) !== '' ? trim((string)$reason) : null,
            $snapshot,
            $actorId,
        ]);
    }

    private static function normalizeNonnegativeDecimal(string $value, int $scale, string $label): string
    {
        if (!is_numeric($value) || (float)$value < 0) {
            throw new DomainException("{$label} cannot be negative.");
        }
        return number_format((float)$value, $scale, '.', '');
    }

    private static function normalizeNullableNonnegativeDecimal(?string $value, int $scale, string $label): ?string
    {
        if (trim((string)$value) === '') {
            return null;
        }
        return self::normalizeNonnegativeDecimal((string)$value, $scale, $label);
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
