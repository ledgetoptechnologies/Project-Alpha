<?php
// src/cron/generate_recurring_invoices.php
// Run this script via cron: php /var/www/src/cron/generate_recurring_invoices.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/recurring_billing.php';
require_once __DIR__ . '/../utils/project_invoice_billing.php';

$logPrefix = '[generate_recurring_invoices]';
$jobName = 'generate_recurring_invoices';

// Check if cron is enabled in settings
if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping invoice generation.");
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

@error_log("$logPrefix Starting invoice generation run at " . date('Y-m-d H:i:s'));

try {
    $today = date('Y-m-d');
    $invoicesGenerated = 0;
    $errors = 0;
    $catchUpPasses = 0;
    $maxCatchUpPasses = 36;

    do {
    $catchUpPasses++;

    // Refetch after each pass so contracts that remain overdue generate the
    // next missed invoice during the same cron run.
    $query = 'SELECT * FROM contracts
              WHERE status = ?
              AND contract_type = "long_term"
              AND next_invoice_date IS NOT NULL
              AND next_invoice_date <= ?
              AND (signed_pdf_path IS NOT NULL AND signed_pdf_path != \'\')
              ORDER BY next_invoice_date ASC';

    $stmt = $pdo->prepare($query);
    $stmt->execute(['active', $today]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contracts as $contract) {
        $invoiceId = generate_recurring_invoice($pdo, $contract, $appConfig);
        if ($invoiceId !== null) {
            $invoicesGenerated++;
        } elseif ($invoiceId === null) {
            // A null can also mean idempotency guard tripped or no invoice needed.
            // Track actual errors through helper logging rather than here.
            // We only count an error if the helper threw/logged failure; we
            // leave $errors untouched because failures are already logged.
        }
    }

    } while (!empty($contracts) && $catchUpPasses < $maxCatchUpPasses);

    if ($catchUpPasses >= $maxCatchUpPasses && !empty($contracts)) {
        $errors++;
        @error_log("$logPrefix Catch-up stopped at {$maxCatchUpPasses} passes to avoid an infinite loop.");
    }

    @error_log("$logPrefix Completed: $invoicesGenerated invoices generated across $catchUpPasses catch-up pass(es), $errors errors");

    $projectInvoicesGenerated = 0;
    $projectBillingResult = [
        'processed' => 0,
        'generated' => 0,
        'existing' => 0,
        'empty' => 0,
        'drafted' => 0,
        'delivered' => 0,
        'already_delivered' => 0,
        'delivery_pending' => 0,
        'delivery_failed' => 0,
    ];
    try {
        $projectBillingResult = project_invoice_generate_due_monthly_result($pdo, $appConfig);
        $projectInvoicesGenerated = $projectBillingResult['generated'];
        $errors += $projectBillingResult['delivery_pending'] + $projectBillingResult['delivery_failed'];
        @error_log(sprintf(
            '%s Project monthly billing: %d generated, %d existing, %d empty, %d drafted, %d delivered, %d already delivered, %d pending/retrying, %d failed',
            $logPrefix,
            $projectBillingResult['generated'],
            $projectBillingResult['existing'],
            $projectBillingResult['empty'],
            $projectBillingResult['drafted'],
            $projectBillingResult['delivered'],
            $projectBillingResult['already_delivered'],
            $projectBillingResult['delivery_pending'],
            $projectBillingResult['delivery_failed']
        ));
    } catch (Throwable $e) {
        $errors++;
        @error_log("$logPrefix Project monthly billing failed: " . $e->getMessage());
    }

    // Delivery is centralized in the durable worker. Reminders are scheduled
    // only by send_invoice_reminders.php so overlapping jobs cannot double-send.
    $deliveryStats = invoice_notification_process($pdo, $appConfig);
    $projectDeliveryStats = project_invoice_notification_process($pdo, $appConfig);
    foreach (['sent', 'retry', 'suppressed'] as $metric) {
        $deliveryStats[$metric] += $projectDeliveryStats[$metric];
    }
    @error_log(sprintf(
        '%s Delivery worker: %d sent, %d retrying, %d suppressed',
        $logPrefix, $deliveryStats['sent'], $deliveryStats['retry'], $deliveryStats['suppressed']
    ));
    if ($deliveryStats['retry'] > 0) {
        $errors += $deliveryStats['retry'];

    }
    $runResult = "Generated {$invoicesGenerated} recurring invoice(s), {$projectInvoicesGenerated} project invoice(s); project delivery {$projectBillingResult['delivered']} sent/{$projectBillingResult['delivery_pending']} pending/{$projectBillingResult['delivery_failed']} failed; delivery {$deliveryStats['sent']} sent/{$deliveryStats['retry']} retry/{$deliveryStats['suppressed']} suppressed; {$errors} error(s); {$catchUpPasses} catch-up pass(es)";
    if ($errors > 0) {
        throw new RuntimeException($runResult);
    }
    cron_state_mark_success($pdo, $jobName, $runResult);

    // Update last run timestamp in settings (legacy support)
    $configMount = '/var/www/config';
    $projectConfig = __DIR__ . '/../../config';
    $configDir = is_dir($configMount) ? $configMount : $projectConfig;
    $settingsFile = $configDir . '/settings.json';

    if (is_readable($settingsFile) && is_writable($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $settings['cron_last_run'] = date('Y-m-d H:i:s');
        @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    cron_state_mark_failure($pdo, $jobName, $e);
    exit(1);
}

exit(0);
