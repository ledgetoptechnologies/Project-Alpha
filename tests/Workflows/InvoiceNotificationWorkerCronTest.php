<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceNotificationWorkerCronTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/invoice-worker-' . bin2hex(random_bytes(8));
        foreach (['cron', 'config', 'utils'] as $directory) {
            mkdir($this->directory . '/' . $directory, 0700, true);
        }
        copy(dirname(__DIR__, 2) . '/src/cron/process_invoice_notifications.php', $this->directory . '/cron/worker.php');
        file_put_contents($this->directory . '/config/db.php', <<<'PHP'
<?php
$pdo = new PDO('sqlite::memory:');
$appConfig = [
    'cron_enabled' => getenv('TEST_CRON_ENABLED'),
    'invoice_auto_send_due_7days' => 0,
    'invoice_auto_send_overdue_weekly' => 0,
];
function worker_test_event(array $event): void { echo json_encode($event), PHP_EOL; }
function invoice_notification_process(PDO $pdo, array $config, $sender = null, $now = null, int $limit = 100): array {
    worker_test_event(['queue' => 'invoice', 'limit' => $limit, 'config' => $config]);
    if (getenv('TEST_INVOICE_FAILURE')) throw new RuntimeException('invoice queue unavailable');
    return ['claimed' => 3, 'sent' => 1, 'retry' => 1, 'suppressed' => 1];
}
function project_invoice_notification_process(PDO $pdo, array $config, $sender = null, $now = null, int $limit = 100): array {
    worker_test_event(['queue' => 'project', 'limit' => $limit, 'config' => $config]);
    if (getenv('TEST_PROJECT_FAILURE')) throw new RuntimeException('project queue unavailable');
    return ['claimed' => 4, 'sent' => 2, 'retry' => 1, 'suppressed' => 1];
}
function cron_state_mark_success(PDO $pdo, string $jobName, ?string $result = null): void {
    worker_test_event(['status' => 'success', 'job' => $jobName, 'result' => $result]);
}
function cron_state_mark_failure(PDO $pdo, string $jobName, Throwable $error): void {
    worker_test_event(['status' => 'failed', 'job' => $jobName, 'error' => $error->getMessage()]);
}
PHP);
        // Stub only the dependencies; execute the actual cron script unchanged.
        foreach (['config/app.php', 'utils/cron_state.php', 'utils/invoice_notifications.php', 'utils/project_invoice_notifications.php'] as $file) {
            file_put_contents($this->directory . '/' . $file, '<?php');
        }
    }

    protected function tearDown(): void
    {
        foreach (['cron', 'config', 'utils'] as $directory) {
            foreach (glob($this->directory . '/' . $directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory . '/' . $directory);
        }
        rmdir($this->directory);
    }

    public function testDisabledCronDoesNotProcessEitherQueue(): void
    {
        [$exitCode, $events] = $this->runWorker(['TEST_CRON_ENABLED' => '0']);
        self::assertSame(0, $exitCode);
        self::assertSame([[
            'status' => 'success',
            'job' => 'process_invoice_notifications',
            'result' => 'Cron disabled',
        ]], $events);
    }

    public function testBothQueuesDrainInBoundedBatchesWithReminderSchedulingDisabled(): void
    {
        [$exitCode, $events, $log] = $this->runWorker();
        self::assertSame(0, $exitCode, $log);
        self::assertSame(['invoice', 'project'], array_column($events, 'queue'));
        foreach (array_slice($events, 0, 2) as $event) {
            self::assertSame(100, $event['limit']);
            self::assertSame(0, $event['config']['invoice_auto_send_due_7days']);
            self::assertSame(0, $event['config']['invoice_auto_send_overdue_weekly']);
        }
        self::assertSame('success', $events[2]['status']);
        self::assertSame('7 claimed; 3 sent; 2 retrying; 2 suppressed', $events[2]['result']);
        self::assertStringContainsString('2 retrying', $log);
        // No reminder-scheduling functions exist in this fixture: calling one fails.
    }

    public function testBrokenInvoiceQueueDoesNotBlockProjectQueueAndMarksFailure(): void
    {
        [$exitCode, $events, $log] = $this->runWorker(['TEST_INVOICE_FAILURE' => '1']);
        self::assertSame(1, $exitCode);
        self::assertSame(['invoice', 'project'], array_column($events, 'queue'));
        self::assertSame('failed', $events[2]['status']);
        self::assertStringContainsString('4 claimed; 2 sent; 1 retrying; 1 suppressed', $events[2]['error']);
        self::assertStringContainsString('invoice queue unavailable', $events[2]['error']);
        self::assertStringContainsString('Failed:', $log);
    }

    public function testProjectQueueFailureIsRecordedAfterInvoiceQueueCompletes(): void
    {
        [$exitCode, $events] = $this->runWorker(['TEST_PROJECT_FAILURE' => '1']);
        self::assertSame(1, $exitCode);
        self::assertSame('failed', $events[2]['status']);
        self::assertStringContainsString('3 claimed; 1 sent; 1 retrying; 1 suppressed', $events[2]['error']);
        self::assertStringContainsString('project queue unavailable', $events[2]['error']);
    }

    public function testWorkerIsScheduledEveryFiveMinutesAndRegisteredForStatus(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringContainsString(
            '*/5 * * * * root . /etc/environment && php /var/www/src/cron/process_invoice_notifications.php',
            (string)file_get_contents($root . '/cron/crontab')
        );
        self::assertStringContainsString("'process_invoice_notifications'", (string)file_get_contents($root . '/src/utils/cron_state.php'));
    }

    private function runWorker(array $environment = []): array
    {
        $environment = array_merge(getenv(), [
            'TEST_CRON_ENABLED' => '1',
            'TEST_INVOICE_FAILURE' => '',
            'TEST_PROJECT_FAILURE' => '',
        ], $environment);
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_log=', $this->directory . '/cron/worker.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $events = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), preg_split('/\R/', trim($stdout)) ?: []);
        return [$exitCode, $events, $stderr];
    }
}
