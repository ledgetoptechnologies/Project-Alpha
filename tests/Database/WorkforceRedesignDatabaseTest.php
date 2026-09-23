<?php

declare(strict_types=1);

use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;
use App\Modules\Timekeeping\TimekeepingService;
use App\Services\PayPeriodService;
use App\Services\TimeApprovalPolicy;
use App\Services\TimeReviewQueueService;
use App\Services\TimeSubmissionService;
use App\Services\WorkerEarningService;
use PHPUnit\Framework\TestCase;

final class WorkforceRedesignDatabaseTest extends TestCase
{
    public function testDraftSubmissionApprovalBillingEarningAndStatementLifecycle(): void
    {
        if (getenv('WORKFORCE_DB_TESTS') !== '1') {
            self::markTestSkipped('Set WORKFORCE_DB_TESTS=1 only for an isolated verification database.');
        }

        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: 'db',
                getenv('DB_PORT') ?: '3306',
                getenv('MYSQL_DATABASE') ?: 'project_alpha'
            ),
            getenv('MYSQL_USER') ?: 'root',
            getenv('MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $suffix = bin2hex(random_bytes(5));
        $password = password_hash('WorkforceVerification!123', PASSWORD_DEFAULT);
        $insertUser = $pdo->prepare('INSERT INTO users (email,username,password_hash,role) VALUES (?,?,?,?)');
        $insertUser->execute(["admin-{$suffix}@example.test", "admin-{$suffix}", $password, 'admin']);
        $adminId = (int)$pdo->lastInsertId();
        $insertUser->execute(["worker-{$suffix}@example.test", "worker-{$suffix}", $password, 'employee']);
        $workerUserId = (int)$pdo->lastInsertId();
        $insertUser->execute(["owner-{$suffix}@example.test", "owner-{$suffix}", $password, 'owner']);
        $ownerUserId = (int)$pdo->lastInsertId();

        $workerProfile = $pdo->prepare(
            'INSERT INTO worker_profiles
             (user_id,relationship_type,time_review_policy,compensation_policy,status,display_name,currency)
             VALUES (?,?,?,?,\'active\',?,\'USD\')'
        );
        $workerProfile->execute([$workerUserId, 'employee', 'manager_review', 'rules', 'Verification Worker']);
        $workerProfileId = (int)$pdo->lastInsertId();
        $workerProfile->execute([$ownerUserId, 'owner', 'self_confirm', 'owner_no_pay', 'Verification Owner']);
        $ownerProfileId = (int)$pdo->lastInsertId();
        self::assertGreaterThan(0, $ownerProfileId);

        $pdo->prepare(
            'INSERT INTO employee_profiles (user_id,first_name,last_name,hourly_rate,currency)
             VALUES (?,\'Verification\',\'Worker\',\'25.0000\',\'USD\')'
        )->execute([$workerUserId]);
        $config = $pdo->prepare(
            'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?)
             ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)'
        );
        foreach ([
            'timezone' => 'UTC',
            'workforce_currency' => 'USD',
            'workforce_default_hourly_rate' => '20.0000',
            'workforce_default_billing_rate' => '125.0000',
            'workforce_require_project' => '0',
            'workforce_require_work_type' => '0',
            'workforce_require_description' => '0',
        ] as $key => $value) {
            $config->execute([$key, $value]);
        }

        $audit = new AuditRecorder($pdo);
        $time = new TimekeepingService($pdo, $audit);
        $approval = new ApprovalService($pdo, $audit, new BillingTimeConsumer($pdo));
        $periods = new PayPeriodService($pdo);
        $latestPeriodEnd = $pdo->query('SELECT MAX(period_end) FROM pay_periods')->fetchColumn();
        $workDate = $latestPeriodEnd
            ? (new DateTimeImmutable((string)$latestPeriodEnd . ' 09:00:00', new DateTimeZone('UTC')))->modify('+30 days')
            : new DateTimeImmutable('2036-01-15 09:00:00', new DateTimeZone('UTC'));
        $pdo->prepare("INSERT INTO jobs (job_code,status,created_by) VALUES (?,'active',?)")
            ->execute(['QA-WORK-' . $suffix, $workerUserId]);
        $jobId = (int)$pdo->lastInsertId();
        $entryId = $time->saveManual($workerUserId, [
            'capture_mode' => 'duration',
            'work_date' => '2026-07-15',
            'duration_minutes' => '120',
            'start_time' => $workDate->format('Y-m-d\\TH:i'),
            'end_time' => $workDate->modify('+2 hours')->format('Y-m-d\\TH:i'),
            'description' => 'Canonical Workforce verification',
            'job_id' => $jobId,
            'billing_treatment' => 'ready',
            'entered_by_user_id' => $workerUserId,
        ]);

        $draft = $this->row($pdo, 'SELECT * FROM work_time_entries WHERE id=?', [$entryId]);
        self::assertSame('draft', $draft['workflow_status']);
        self::assertSame((string)$workerProfileId, (string)$draft['worker_profile_id']);
        self::assertSame((string)$workerUserId, (string)$draft['entered_by_user_id']);
        self::assertSame('provisional', $draft['compensation_state']);
        self::assertSame('ready', $draft['billing_state']);
        $queue = new TimeReviewQueueService($pdo, new TimeApprovalPolicy($pdo));
        self::assertSame([], $queue->pendingFor($adminId), 'Draft time must not leak into the review queue.');

        $period = $periods->periodFor($workDate);
        $submission = (new TimeSubmissionService($pdo))->submit(
            (int)$period['id'],
            $workerProfileId,
            $workerUserId,
            [$entryId],
            'Ready for review'
        );
        self::assertSame(1, $submission['entry_count']);
        self::assertSame([$entryId], array_column($queue->pendingFor($adminId), 'id'));

        $approval->approve($adminId, $entryId);
        $confirmed = $this->row($pdo, 'SELECT * FROM work_time_entries WHERE id=?', [$entryId]);
        self::assertSame('confirmed', $confirmed['workflow_status']);
        self::assertSame('ready', $confirmed['billing_state']);
        self::assertSame('eligible', $confirmed['compensation_state']);
        self::assertSame('confirmed', $this->value(
            $pdo,
            'SELECT decision FROM time_submission_entries WHERE submission_id=? AND time_entry_id=?',
            [$submission['id'], $entryId]
        ));

        $allocation = $this->row(
            $pdo,
            'SELECT * FROM work_time_billing_allocations WHERE time_entry_id=? AND entry_revision=1 AND status<>\'reversed\'',
            [$entryId]
        );
        self::assertSame('hourly', $allocation['treatment']);
        self::assertSame('ready', $allocation['status']);
        self::assertSame('250.00', $allocation['amount']);
        $earning = $this->row($pdo, 'SELECT * FROM worker_earnings WHERE work_time_entry_id=?', [$entryId]);
        self::assertSame('eligible', $earning['status']);
        self::assertSame('50.00', $earning['amount']);

        (new WorkerEarningService($pdo))->transition(
            (string)$earning['id'],
            'approved',
            $adminId,
            'Verification approval'
        );
        // A later policy change governs future work, not this immutable
        // already-approved earning.
        $pdo->prepare("UPDATE worker_profiles SET compensation_policy='nonpayable' WHERE id=?")
            ->execute([$workerProfileId]);
        // This test runs against a shared isolated QA schema that may contain
        // unrelated active worker fixtures from prior verification passes.
        $closed = $periods->close((int)$period['id'], $adminId, true);
        self::assertTrue($closed['closed']);
        self::assertCount(1, $closed['statement_ids']);
        $statementId = (int)$closed['statement_ids'][0];
        self::assertSame('included', $this->value($pdo, 'SELECT status FROM worker_earnings WHERE id=?', [$earning['id']]));
        self::assertSame((string)$earning['id'], $this->value(
            $pdo,
            'SELECT worker_earning_id FROM worker_statement_lines WHERE worker_statement_id=?',
            [$statementId]
        ));
        $periods->settleStatement($statementId, $adminId);
        self::assertSame('settled', $this->value($pdo, 'SELECT status FROM worker_earnings WHERE id=?', [$earning['id']]));
        self::assertSame('settled', $this->value($pdo, 'SELECT compensation_state FROM work_time_entries WHERE id=?', [$entryId]));

        $ownerEntryId = $time->saveManual($ownerUserId, [
            'capture_mode' => 'duration',
            'start_time' => $workDate->modify('+1 day')->format('Y-m-d\\TH:i'),
            'end_time' => $workDate->modify('+1 day +1 hour')->format('Y-m-d\\TH:i'),
            'description' => 'Owner operations work',
            'billing_treatment' => 'nonbillable',
            'entered_by_user_id' => $ownerUserId,
        ]);
        // A verified owner can recover a pending entry selected for an invoice;
        // older deployments could leave this state submitted before linking.
        $pdo->prepare("UPDATE work_time_entries SET workflow_status='submitted' WHERE id=?")
            ->execute([$ownerEntryId]);
        $approval->ensureOwnerProjection($ownerUserId, $ownerEntryId);
        self::assertSame('confirmed', $this->value($pdo, 'SELECT workflow_status FROM work_time_entries WHERE id=?', [$ownerEntryId]));
        self::assertSame('owner_no_pay', $this->value($pdo, 'SELECT compensation_state FROM work_time_entries WHERE id=?', [$ownerEntryId]));
        self::assertSame('0', $this->value($pdo, 'SELECT COUNT(*) FROM worker_earnings WHERE work_time_entry_id=?', [$ownerEntryId]));

        $pdo->prepare("UPDATE worker_profiles SET compensation_policy='rules' WHERE id=?")
            ->execute([$ownerProfileId]);
        $paidOwnerEntryId = $time->saveManual($ownerUserId, [
            'capture_mode' => 'duration',
            'start_time' => $workDate->modify('+2 days')->format('Y-m-d\\TH:i'),
            'end_time' => $workDate->modify('+2 days +1 hour')->format('Y-m-d\\TH:i'),
            'description' => 'Compensated owner operations work',
            'billing_treatment' => 'nonbillable',
            'entered_by_user_id' => $ownerUserId,
        ]);
        $approval->ensureOwnerProjection($ownerUserId, $paidOwnerEntryId);
        self::assertSame('eligible', $this->value($pdo, 'SELECT compensation_state FROM work_time_entries WHERE id=?', [$paidOwnerEntryId]));
        self::assertSame('1', $this->value($pdo, 'SELECT COUNT(*) FROM worker_earnings WHERE work_time_entry_id=? AND status="eligible"', [$paidOwnerEntryId]));
    }

    private function value(PDO $pdo, string $sql, array $parameters): string
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return (string)$statement->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function row(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
