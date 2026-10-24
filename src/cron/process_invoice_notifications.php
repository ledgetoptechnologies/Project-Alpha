<?php
// Deliver due durable email jobs independently of the daily reminder schedule.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/invoice_notifications.php';
require_once __DIR__ . '/../utils/project_invoice_notifications.php';

$jobName = 'process_invoice_notifications';
$logPrefix = '[' . $jobName . ']';

if (empty($appConfig['cron_enabled'])) {
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

// Each processor claims at most 100 eligible rows and rechecks current settings.
// Do not schedule new reminders here: only the daily reminder job creates them.
$stats = ['claimed' => 0, 'sent' => 0, 'retry' => 0, 'suppressed' => 0];
$errors = [];
foreach (['invoice_notification_process', 'project_invoice_notification_process'] as $processor) {
    try {
        $batch = $processor($pdo, $appConfig);
        foreach ($stats as $metric => $value) {
            $stats[$metric] += $batch[$metric];
        }
    } catch (Throwable $error) {
        // A broken queue must not prevent the other invoice type from draining.
        $errors[] = $processor . ': ' . $error->getMessage();
    }
}

$result = sprintf(
    '%d claimed; %d sent; %d retrying; %d suppressed',
    $stats['claimed'], $stats['sent'], $stats['retry'], $stats['suppressed']
);
if ($errors) {
    $error = new RuntimeException($result . '; ' . implode('; ', $errors));
    @error_log($logPrefix . ' Failed: ' . $error->getMessage());
    cron_state_mark_failure($pdo, $jobName, $error);
    exit(1);
}

@error_log($logPrefix . ' Completed: ' . $result);
cron_state_mark_success($pdo, $jobName, $result);
exit(0);
