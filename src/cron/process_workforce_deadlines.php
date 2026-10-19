<?php

declare(strict_types=1);

use App\Services\PayPeriodDeadlineService;

require_once __DIR__ . '/../utils/workforce_availability.php';
// Also protect already-installed cron schedules and manual invocation.
if (!pa_workforce_enabled()) { exit(0); }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$jobName = 'process_workforce_deadlines';
if (empty($appConfig['cron_enabled'])) {
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

try {
    $service = new PayPeriodDeadlineService($pdo);
    $stats = $service->run(new DateTimeImmutable('now'), static function (array $worker, int $hours, DateTimeImmutable $deadline): bool {
        $name = htmlspecialchars(trim((string)($worker['display_name'] ?? '')) ?: 'there', ENT_QUOTES, 'UTF-8');
        $deadlineLabel = $deadline->format('M j, Y \a\t g:i A T');
        [$sent] = EmailService::sendEmail(
            (string)$worker['email'],
            'Time approval deadline in ' . $hours . ' hour' . ($hours === 1 ? '' : 's'),
            '<p>Hello ' . $name . ',</p><p>Your Project Alpha time period has not been approved yet. '
              . 'Please review and approve your completed entries before <strong>' . htmlspecialchars($deadlineLabel, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
              . '<p>Completed entries are automatically confirmed and locked at the deadline. Later changes require an administrator correction.</p>',
            ['document_type' => 'notification', 'message_key' => 'workforce-deadline:' . (int)$worker['worker_profile_id'] . ':' . $deadline->format('YmdHi') . ':' . $hours]
        );
        return (bool)$sent;
    });
    cron_state_mark_success($pdo, $jobName, json_encode($stats, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    cron_state_mark_failure($pdo, $jobName, $error);
    throw $error;
}
