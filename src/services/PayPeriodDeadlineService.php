<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** Sends deadline reminders and confirms completed time at the configured cutoff. */
final class PayPeriodDeadlineService
{
    private const REMINDER_HOURS = [4, 2, 1];
    private string $timezoneName = 'UTC';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param callable(array<string,mixed>,int,DateTimeImmutable):bool $sendReminder
     * @return array{reminders:int,confirmed:int,exceptions:int,failures:int}
     */
    public function run(DateTimeImmutable $now, callable $sendReminder): array
    {
        $settings = $this->settings();
        $this->timezoneName = $settings['timezone'];
        $timezone = new DateTimeZone($settings['timezone']);
        $localNow = $now->setTimezone($timezone);
        $stats = ['reminders' => 0, 'confirmed' => 0, 'exceptions' => 0, 'failures' => 0];

        $periods = $this->pdo->prepare(
            "SELECT * FROM pay_periods
             WHERE status='open' AND period_end BETWEEN ? AND ?
             ORDER BY period_end"
        );
        $periods->execute([
            $localNow->sub(new DateInterval('P1D'))->format('Y-m-d'),
            $localNow->add(new DateInterval('P1D'))->format('Y-m-d'),
        ]);

        foreach ($periods->fetchAll(PDO::FETCH_ASSOC) as $period) {
            $deadline = new DateTimeImmutable(
                (string)$period['period_end'] . ' ' . $settings['deadline_time'] . ':00',
                $timezone
            );
            foreach (self::REMINDER_HOURS as $hours) {
                $scheduled = $deadline->sub(new DateInterval('PT' . $hours . 'H'));
                if ($localNow < $scheduled || $localNow >= $scheduled->add(new DateInterval('PT15M'))) {
                    continue;
                }
                foreach ($this->workersAwaitingApproval((int)$period['id'], $period) as $worker) {
                    if (!$this->claimEvent((int)$period['id'], (int)$worker['worker_profile_id'], 'reminder_' . $hours . 'h', $scheduled)) {
                        continue;
                    }
                    try {
                        if ($sendReminder($worker, $hours, $deadline)) {
                            $this->completeEvent((int)$period['id'], (int)$worker['worker_profile_id'], 'reminder_' . $hours . 'h', [
                                'recipient' => (string)$worker['email'],
                            ]);
                            $stats['reminders']++;
                        } else {
                            $this->failEvent((int)$period['id'], (int)$worker['worker_profile_id'], 'reminder_' . $hours . 'h', 'Email delivery failed.');
                            $stats['failures']++;
                        }
                    } catch (Throwable $error) {
                        $this->failEvent((int)$period['id'], (int)$worker['worker_profile_id'], 'reminder_' . $hours . 'h', $error->getMessage());
                        $stats['failures']++;
                    }
                }
            }

            if (!empty($settings['auto_confirm']) && $localNow >= $deadline) {
                foreach ($this->workersForPeriod((int)$period['id'], $period) as $worker) {
                    $result = $this->autoConfirmWorker($period, $worker, $deadline);
                    $stats['confirmed'] += $result['confirmed'];
                    $stats['exceptions'] += $result['exceptions'];
                    $stats['failures'] += $result['failures'];
                }
            }
        }

        return $stats;
    }

    /** @return array{confirmed:int,exceptions:int,failures:int} */
    private function autoConfirmWorker(array $period, array $worker, DateTimeImmutable $deadline): array
    {
        $periodId = (int)$period['id'];
        $workerId = (int)$worker['worker_profile_id'];
        if (!$this->claimEvent($periodId, $workerId, 'auto_confirm', $deadline)) {
            return ['confirmed' => 0, 'exceptions' => 0, 'failures' => 0];
        }

        $result = ['confirmed' => 0, 'exceptions' => 0, 'failures' => 0];
        try {
            $draft = $this->entryIds($workerId, $period, "workflow_status IN ('draft','returned') AND end_time IS NOT NULL AND duration_seconds>0");
            if ($draft !== []) {
                (new TimeSubmissionService($this->pdo))->submit(
                    $periodId,
                    $workerId,
                    (int)$worker['user_id'],
                    $draft,
                    'Automatically submitted at the pay-period deadline.'
                );
            }

            $reviewerId = $this->deadlineReviewerId((int)$worker['user_id']);
            $approval = new ApprovalService(
                $this->pdo,
                new AuditRecorder($this->pdo),
                new BillingTimeConsumer($this->pdo),
                new TimeApprovalPolicy($this->pdo)
            );
            foreach ($this->entryIds($workerId, $period, "workflow_status='submitted' AND end_time IS NOT NULL AND duration_seconds>0") as $entryId) {
                try {
                    $approval->approve($reviewerId, $entryId);
                    $result['confirmed']++;
                } catch (Throwable $error) {
                    $result['failures']++;
                }
            }

            $incomplete = count($this->entryIds(
                $workerId,
                $period,
                "(end_time IS NULL OR duration_seconds<=0 OR workflow_status IN ('draft','returned','submitted')) AND workflow_status<>'voided'"
            ));
            $result['exceptions'] = $incomplete;
            $status = $incomplete === 0 ? 'accepted' : 'submitted';
            $this->pdo->prepare(
                "INSERT INTO worker_period_submissions
                 (pay_period_id,worker_profile_id,status,submitted_at,accepted_by,accepted_at,notes)
                 VALUES (?,?,?,UTC_TIMESTAMP(6),?,CASE WHEN ?='accepted' THEN UTC_TIMESTAMP(6) ELSE NULL END,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status),submitted_at=VALUES(submitted_at),
                   accepted_by=VALUES(accepted_by),accepted_at=VALUES(accepted_at),notes=VALUES(notes)"
            )->execute([
                $periodId,
                $workerId,
                $status,
                $reviewerId,
                $status,
                $incomplete === 0
                    ? 'Automatically confirmed at the configured deadline.'
                    : $incomplete . ' incomplete or unresolved entr' . ($incomplete === 1 ? 'y remains.' : 'ies remain.'),
            ]);
            $this->completeEvent($periodId, $workerId, 'auto_confirm', $result);
        } catch (Throwable $error) {
            $this->failEvent($periodId, $workerId, 'auto_confirm', $error->getMessage());
            $result['failures']++;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function workersAwaitingApproval(int $periodId, array $period): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT wp.id worker_profile_id,wp.display_name,wp.user_id,u.email
             FROM worker_profiles wp JOIN users u ON u.id=wp.user_id
             WHERE wp.status='active' AND wp.compensation_policy='rules'
               AND u.deleted_at IS NULL AND u.is_disabled=0 AND u.email<>''
               AND EXISTS (
                 SELECT 1 FROM work_time_entries t
                 WHERE t.worker_profile_id=wp.id AND t.start_time>=? AND t.start_time<?
                   AND t.workflow_status NOT IN ('confirmed','voided')
               )
             ORDER BY wp.display_name,u.email"
        );
        [$periodStart, $periodEnd] = $this->periodUtcBounds($period);
        $stmt->execute([$periodStart, $periodEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    private function workersForPeriod(int $periodId, array $period): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT wp.id worker_profile_id,wp.display_name,wp.user_id,u.email
             FROM worker_profiles wp JOIN users u ON u.id=wp.user_id
             JOIN work_time_entries t ON t.worker_profile_id=wp.id
             WHERE wp.status='active' AND wp.compensation_policy='rules'
               AND t.start_time>=? AND t.start_time<?
               AND t.workflow_status<>'voided' AND u.deleted_at IS NULL AND u.is_disabled=0
             ORDER BY wp.display_name,u.email"
        );
        [$periodStart, $periodEnd] = $this->periodUtcBounds($period);
        $stmt->execute([$periodStart, $periodEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,string> */
    private function entryIds(int $workerId, array $period, string $predicate): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM work_time_entries
             WHERE worker_profile_id=? AND start_time>=? AND start_time<? AND {$predicate}
             ORDER BY start_time,id"
        );
        [$periodStart, $periodEnd] = $this->periodUtcBounds($period);
        $stmt->execute([$workerId, $periodStart, $periodEnd]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{0:string,1:string} Local period dates expressed as an exclusive UTC range. */
    private function periodUtcBounds(array $period): array
    {
        $timezone = new DateTimeZone($this->timezoneName);
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable((string)$period['period_start'] . ' 00:00:00', $timezone);
        $end = (new DateTimeImmutable((string)$period['period_end'] . ' 00:00:00', $timezone))
            ->add(new DateInterval('P1D'));
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s.u'),
        ];
    }

    private function deadlineReviewerId(int $workerUserId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users
             WHERE id<>? AND role IN ('admin','owner') AND deleted_at IS NULL AND is_disabled=0
             ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END,id LIMIT 1"
        );
        $stmt->execute([$workerUserId]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id <= 0) {
            throw new \DomainException('An active administrator is required for deadline confirmation.');
        }
        return $id;
    }

    private function claimEvent(int $periodId, int $workerId, string $eventType, DateTimeImmutable $scheduled): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO workforce_deadline_events
             (pay_period_id,worker_profile_id,event_type,status,scheduled_for,attempt_count)
             VALUES (?,?,?,'pending',?,1)"
        );
        $stmt->execute([$periodId, $workerId, $eventType, $scheduled->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
        if ($stmt->rowCount() === 1) {
            return true;
        }
        $retry = $this->pdo->prepare(
            "UPDATE workforce_deadline_events
             SET status='pending',attempt_count=attempt_count+1,details=NULL,completed_at=NULL
             WHERE pay_period_id=? AND worker_profile_id=? AND event_type=?
               AND status='failed' AND attempt_count<3"
        );
        $retry->execute([$periodId, $workerId, $eventType]);
        return $retry->rowCount() === 1;
    }

    private function completeEvent(int $periodId, int $workerId, string $eventType, array $details): void
    {
        $this->pdo->prepare(
            "UPDATE workforce_deadline_events SET status='completed',completed_at=UTC_TIMESTAMP(6),details=?
             WHERE pay_period_id=? AND worker_profile_id=? AND event_type=?"
        )->execute([json_encode($details, JSON_THROW_ON_ERROR), $periodId, $workerId, $eventType]);
    }

    private function failEvent(int $periodId, int $workerId, string $eventType, string $message): void
    {
        $this->pdo->prepare(
            "UPDATE workforce_deadline_events SET status='failed',completed_at=UTC_TIMESTAMP(6),details=?
             WHERE pay_period_id=? AND worker_profile_id=? AND event_type=?"
        )->execute([json_encode(['error' => substr($message, 0, 1000)], JSON_THROW_ON_ERROR), $periodId, $workerId, $eventType]);
    }

    /** @return array{timezone:string,deadline_time:string,auto_confirm:bool} */
    private function settings(): array
    {
        $values = [];
        $stmt = $this->pdo->query(
            "SELECT config_key,config_value FROM app_config
             WHERE organization_id=0 AND config_key IN
               ('workforce_timezone','timezone','workforce_period_deadline_time','workforce_period_auto_confirm')"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string)$row['config_key']] = (string)$row['config_value'];
        }
        $timezone = $values['workforce_timezone'] ?? $values['timezone'] ?? 'UTC';
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            $timezone = 'UTC';
        }
        $deadline = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $values['workforce_period_deadline_time'] ?? '')
            ? $values['workforce_period_deadline_time']
            : '20:00';
        return [
            'timezone' => $timezone,
            'deadline_time' => $deadline,
            'auto_confirm' => ($values['workforce_period_auto_confirm'] ?? '1') === '1',
        ];
    }
}
