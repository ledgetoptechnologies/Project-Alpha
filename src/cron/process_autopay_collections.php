<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/autopay_beta.php';
// Check environment before loading database configuration or doing any financial work.
if (!autopay_beta_enabled()) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "AutoPay disabled.\n");
    exit(0);
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/autopay_collection.php';
require_once __DIR__ . '/../utils/cron_state.php';

$jobName = 'process_autopay_collections';
if (empty($appConfig['cron_enabled']) || !pa_autopay_enabled($appConfig)) {
    cron_state_mark_success($pdo, $jobName, 'AutoPay or cron disabled');
    exit(0);
}
try {
    $counts = [];
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $result = pa_autopay_run_one($pdo, $appConfig);
        $status = $result['status'];
        if (in_array($status, ['idle', 'disabled', 'busy'], true)) break;
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    cron_state_mark_success($pdo, $jobName, json_encode($counts, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    cron_state_mark_failure($pdo, $jobName, new RuntimeException('AutoPay worker failed; inspect collection state.'));
    exit(1);
}
