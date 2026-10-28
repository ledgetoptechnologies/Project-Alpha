<?php
// src/cron/process_audit_schedules.php
// Run daily via cron to check for due audit schedules, generate reports, and email them
// Usage: php /var/www/src/cron/process_audit_schedules.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/csv.php';
require_once __DIR__ . '/../utils/email_identity.php';
require_once __DIR__ . '/../utils/expense_schedule_authorization.php';
require_once __DIR__ . '/../services/EmailService.php';

$logPrefix = '[process_audit_schedules]';
$jobName = 'process_audit_schedules';

if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping.");
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

@error_log("$logPrefix Starting audit schedule check at " . date('Y-m-d H:i:s'));

// Build mailer config
$smtpPass = '';
if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
    $encVal = $appConfig['smtp_password_enc'];
    if (strpos($encVal, 'plain::') === 0) { $smtpPass = substr($encVal, 7); }
    else { $pt = crypto_decrypt($encVal); if (is_string($pt)) { $smtpPass = $pt; } }
}
$mailCfg = [
    'host' => (string)($appConfig['smtp_host'] ?? ''),
    'port' => (int)($appConfig['smtp_port'] ?? 587),
    'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
    'username' => (string)($appConfig['smtp_username'] ?? ''),
    'password' => $smtpPass,
];
$fromEmail = (string)($appConfig['from_email'] ?? 'no-reply@localhost');
$fromName = pa_email_sender_name($appConfig);

$processed = 0;
$errors = 0;

try {
    // Find all active schedules that are due
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('
        SELECT * FROM audit_schedules 
        WHERE is_active = 1 AND next_run_at IS NOT NULL AND next_run_at <= ?
        ORDER BY next_run_at ASC
    ');
    $stmt->execute([$now]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($schedules)) {
        @error_log("$logPrefix No schedules due. Exiting.");
        cron_state_mark_success($pdo, $jobName, 'No schedules due');
        exit(0);
    }

    @error_log("$logPrefix Found " . count($schedules) . " schedule(s) to process.");

    foreach ($schedules as $schedule) {
        try {
            $schedId = (int)$schedule['id'];

            // Calculate date range based on schedule settings
            [$startDate, $endDate] = calculateDateRange($schedule['date_range_type']);

            $reportType = ($schedule['report_type'] ?? 'audit') === 'expense' ? 'expense' : 'audit';
            $organizationId = (int)($schedule['organization_id'] ?? 0);
            if (!pa_report_schedule_creator_authorized($pdo, (int)($schedule['created_by'] ?? 0), $reportType)) {
                throw new DomainException('Schedule creator is inactive or no longer has report export permission.');
            }
            if ($reportType === 'expense') {
                $result = processExpenseSchedule(
                    $pdo,
                    $schedule,
                    $startDate,
                    $endDate,
                    $mailCfg,
                    $fromEmail,
                    $fromName
                );
                $pdo->prepare('INSERT INTO audit_schedule_logs (schedule_id,status,started_at,completed_at,result_summary) VALUES (?,"completed",?,NOW(),?)')
                    ->execute([$schedId, $now, $result]);
                advanceSchedule($pdo, $schedule);
                $processed++;
                continue;
            }

            $includeInvoices = (bool)$schedule['include_invoices'];
            $includeUnpaidInvoices = (bool)$schedule['include_unpaid_invoices'];
            $includeContracts = (bool)$schedule['include_contracts'];
            $includeQuotes = (bool)$schedule['include_quotes'];
            $accountingBasis = ($schedule['accounting_basis'] ?? 'cash') === 'accrual' ? 'accrual' : 'cash';

            // Fetch invoices
            $invoices = [];
            if ($includeInvoices) {
                $statusFilter = $includeUnpaidInvoices
                    ? "i.status IN ('paid', 'partial', 'unpaid')"
                    : "i.status IN ('paid', 'partial')";

                if ($accountingBasis === 'cash') {
                    $stmt = $pdo->prepare("
                    SELECT
                        i.id, i.doc_number, i.invoice_type, c.name as client_name, i.project_code,
                        i.subtotal, i.tax_percent, i.tax_amount as tax, i.tax_county,
                        i.discount_value, i.total, i.status, MIN(p.payment_date) AS created_at, i.due_date,
                        SUM(GREATEST(p.amount-p.refunded_amount-p.disputed_amount,0)) as amount_paid,
                        GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods
                    FROM invoices i
                    JOIN clients c ON i.client_id = c.id
                    JOIN payments p ON i.id = p.invoice_id AND p.status='succeeded'
                    WHERE p.payment_date BETWEEN ? AND ? AND (?=0 OR i.organization_id=?)
                    GROUP BY i.id
                    ORDER BY created_at ASC
                ");
                    $stmt->execute([$startDate, $endDate, $organizationId, $organizationId]);
                } else {
                    $stmt = $pdo->prepare("
                    SELECT 
                        i.id, i.doc_number, i.invoice_type, c.name as client_name, i.project_code,
                        i.subtotal, i.tax_percent, i.tax_amount as tax, i.tax_county,
                        i.discount_value, i.total, i.status, COALESCE(i.finalized_at,i.created_at) AS created_at, i.due_date,
                        COALESCE(SUM(CASE WHEN p.status = 'succeeded' THEN p.amount ELSE 0 END), 0) as amount_paid,
                        GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods
                    FROM invoices i
                    LEFT JOIN clients c ON i.client_id = c.id
                    LEFT JOIN payments p ON i.id = p.invoice_id
                    WHERE COALESCE(i.finalized_at,i.created_at) BETWEEN ? AND ? AND {$statusFilter}
                      AND i.status <> 'draft' AND (?=0 OR i.organization_id=?)
                    GROUP BY i.id
                    ORDER BY i.created_at ASC
                ");
                    $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $organizationId, $organizationId]);
                }
                $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($accountingBasis === 'accrual') {
                    foreach ($invoices as &$invoiceRow) {
                        $invoiceRow['amount_paid'] = (float)$invoiceRow['total'];
                    }
                    unset($invoiceRow);
                }
            }

            // Fetch contracts
            $contracts = [];
            if ($includeContracts) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.doc_number, cl.name as client_name, c.project_code,
                           c.contract_type, c.total, c.status, c.created_at, c.start_date, c.end_date
                    FROM contracts c LEFT JOIN clients cl ON c.client_id = cl.id
                    WHERE c.created_at BETWEEN ? AND ? AND (?=0 OR c.organization_id=?)
                    ORDER BY c.created_at ASC
                ");
                $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $organizationId, $organizationId]);
                $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Fetch quotes
            $quotes = [];
            if ($includeQuotes) {
                $stmt = $pdo->prepare("
                    SELECT q.id, q.doc_number, cl.name as client_name, q.project_code,
                           q.quote_type, q.total, q.status, q.created_at
                    FROM quotes q LEFT JOIN clients cl ON q.client_id = cl.id
                    WHERE q.created_at BETWEEN ? AND ? AND (?=0 OR q.organization_id=?)
                    ORDER BY q.created_at ASC
                ");
                $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $organizationId, $organizationId]);
                $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Skip if no data
            if (empty($invoices) && empty($contracts) && empty($quotes)) {
                @error_log("$logPrefix Schedule #{$schedId}: No data for period {$startDate} to {$endDate}. Advancing.");
                advanceSchedule($pdo, $schedule);
                continue;
            }

            // Generate CSV
            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit_sched_' . $schedId . '_' . uniqid();
            @mkdir($tmpDir, 0755, true);

            $csvFile = $tmpDir . DIRECTORY_SEPARATOR . 'audit_report.csv';
            $fp = fopen($csvFile, 'w');
            fwrite($fp, "\xEF\xBB\xBF"); // BOM
            csv_write_row($fp, ['Date', 'Client', 'Doc Number', 'Document Type', 'Status', 'Tax %', 'Tax County', 'Amount Paid', 'Payment Method', 'Discount', 'Total', 'Running Total']);

            $runningTotal = 0;
            foreach ($invoices as $inv) {
                $amountPaid = (float)($inv['amount_paid'] ?? 0);
                $runningTotal += $amountPaid;
                csv_write_row($fp, [
                    substr($inv['created_at'] ?? '', 0, 10),
                    $inv['client_name'] ?? '',
                    pa_invoice_label_from_row($inv),
                    'Invoice',
                    ucfirst($inv['status'] ?? ''),
                    number_format((float)($inv['tax_percent'] ?? 0), 2) . '%',
                    $inv['tax_county'] ?? '',
                    '$' . number_format($amountPaid, 2),
                    $inv['payment_methods'] ?? '',
                    '$' . number_format((float)($inv['discount_value'] ?? 0), 2),
                    '$' . number_format((float)($inv['total'] ?? 0), 2),
                    '$' . number_format($runningTotal, 2)
                ]);
            }
            foreach ($contracts as $c) {
                csv_write_row($fp, [
                    substr($c['created_at'] ?? '', 0, 10), $c['client_name'] ?? '',
                    $c['doc_number'] ?? $c['id'], 'Contract (' . ($c['contract_type'] ?? 'regular') . ')',
                    ucfirst($c['status'] ?? ''), '', '', '', '', '',
                    '$' . number_format((float)($c['total'] ?? 0), 2), ''
                ]);
            }
            foreach ($quotes as $q) {
                csv_write_row($fp, [
                    substr($q['created_at'] ?? '', 0, 10), $q['client_name'] ?? '',
                    $q['doc_number'] ?? $q['id'], 'Quote (' . ($q['quote_type'] ?? 'regular') . ')',
                    ucfirst($q['status'] ?? ''), '', '', '', '', '',
                    '$' . number_format((float)($q['total'] ?? 0), 2), ''
                ]);
            }

            // Summary
            csv_write_row($fp, []);
            csv_write_row($fp, ['SUMMARY']);
            csv_write_row($fp, ['Period:', $startDate . ' to ' . $endDate]);
            csv_write_row($fp, ['Total Invoices:', count($invoices), '', '', '', '', '', 'Total Collected:', '$' . number_format($runningTotal, 2)]);
            if ($includeContracts) csv_write_row($fp, ['Total Contracts:', count($contracts)]);
            if ($includeQuotes) csv_write_row($fp, ['Total Quotes:', count($quotes)]);
            fclose($fp);

            // Email the CSV to all recipients
            $emails = json_decode($schedule['email_addresses'], true);
            if (!is_array($emails)) $emails = [];

            $subject = ucfirst($schedule['frequency']) . ' Audit Report — ' . $startDate . ' to ' . $endDate;
            $body = '<h2>Financial Audit Report</h2>';
            $body .= '<p><strong>Period:</strong> ' . $startDate . ' to ' . $endDate . '</p>';
            $body .= '<p><strong>Invoices:</strong> ' . count($invoices) . ' | <strong>Total Collected:</strong> $' . number_format($runningTotal, 2) . '</p>';
            if ($includeContracts) $body .= '<p><strong>Contracts:</strong> ' . count($contracts) . '</p>';
            if ($includeQuotes) $body .= '<p><strong>Quotes:</strong> ' . count($quotes) . '</p>';
            $body .= '<p>The full CSV report is attached to this email.</p>';

            $csvContent = file_get_contents($csvFile);
            $attachmentName = 'audit_' . str_replace('-', '', $startDate) . '-' . str_replace('-', '', $endDate) . '.csv';
            $attachments = [['filename' => $attachmentName, 'content' => $csvContent, 'mime' => 'text/csv']];

            $artifactDir = '/var/www/config/audits/' . $organizationId . '/scheduled';
            if (!is_dir($artifactDir)) {
                @mkdir($artifactDir, 0750, true);
            }
            if (is_dir($artifactDir) && is_writable($artifactDir)) {
                @copy($csvFile, $artifactDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_schedule_' . $schedId . '_' . $attachmentName);
            }

            $sentCount = 0;
            foreach ($emails as $email) {
                $email = trim($email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                try {
                    [$ok, $err] = EmailService::sendEmail($email, $subject, $body, ['attachments'=>$attachments,'document_type'=>'notification']);
                    if ($ok) { $sentCount++; }
                    else { @error_log("$logPrefix Failed to email {$email}: {$err}"); }
                } catch (Throwable $e) {
                    @error_log("$logPrefix Email error for {$email}: " . $e->getMessage());
                }
            }

            // Cleanup temp
            @unlink($csvFile);
            @rmdir($tmpDir);

            // Log the run
            try {
                $pdo->prepare('INSERT INTO audit_schedule_logs (schedule_id, status, started_at, completed_at, result_summary) VALUES (?, ?, ?, NOW(), ?)')
                    ->execute([$schedId, 'completed', $now, "{$accountingBasis} basis; sent to {$sentCount}/" . count($emails) . " recipients. Invoices: " . count($invoices) . ", Total: \${$runningTotal}"]);
            } catch (Throwable $e) { /* ignore logging failures */ }

            // Advance to next run
            advanceSchedule($pdo, $schedule);
            $processed++;

            @error_log("$logPrefix Schedule #{$schedId}: Processed. Emailed {$sentCount} recipients.");

        } catch (Throwable $e) {
            $errors++;
            @error_log("$logPrefix Error processing schedule #{$schedule['id']}: " . $e->getMessage());
            try {
                $pdo->prepare('INSERT INTO audit_schedule_logs (schedule_id, status, started_at, completed_at, error_message) VALUES (?, ?, ?, NOW(), ?)')
                    ->execute([$schedule['id'], 'failed', $now, substr($e->getMessage(), 0, 500)]);
            } catch (Throwable $e2) { /* ignore */ }
            // Still advance so we don't get stuck
            advanceSchedule($pdo, $schedule);
        }
    }

    @error_log("$logPrefix Completed: {$processed} processed, {$errors} errors");
    cron_state_mark_success($pdo, $jobName, "{$processed} processed; {$errors} errors");

} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    cron_state_mark_failure($pdo, $jobName, $e);
    exit(1);
}

exit(0);

function processExpenseSchedule(
    PDO $pdo,
    array $schedule,
    string $startDate,
    string $endDate,
    array $mailCfg,
    string $fromEmail,
    string $fromName
): string {
    $organizationId = (int)($schedule['organization_id'] ?? 0);
    $creatorId = (int)($schedule['created_by'] ?? 0);
    [$scopeWhere, $scopeParams] = pa_expense_schedule_scope($pdo, $creatorId, $organizationId);
    $emails = json_decode((string)($schedule['email_addresses'] ?? ''), true);
    $emails = is_array($emails) ? array_values(array_filter($emails, 'is_string')) : [];
    if (!pa_expense_schedule_recipients_allowed($pdo, $creatorId, $emails)) {
        throw new DomainException('Expense schedule recipients are no longer authorized.');
    }

    $filters = json_decode((string)($schedule['filters'] ?? ''), true);
    $filters = is_array($filters) ? $filters : [];
    $where = ['e.expense_date BETWEEN ? AND ?', '(' . $scopeWhere . ')'];
    $params = array_merge([$startDate, $endDate], $scopeParams);
    foreach (['category_id', 'vendor_id', 'client_id'] as $field) {
        $value = max(0, (int)($filters[$field] ?? 0));
        if ($value > 0) {
            $where[] = 'e.' . $field . '=?';
            $params[] = $value;
        }
    }
    if (in_array((string)($filters['billable'] ?? ''), ['0', '1'], true)) {
        $where[] = 'e.is_billable=?';
        $params[] = (int)$filters['billable'];
    }
    if (in_array((string)($filters['tax_deductible'] ?? ''), ['0', '1'], true)) {
        $where[] = 'e.is_tax_deductible=?';
        $params[] = (int)$filters['tax_deductible'];
    }
    if (in_array((string)($filters['status'] ?? ''), ['pending', 'confirmed', 'reimbursed', 'void'], true)) {
        $where[] = 'e.status=?';
        $params[] = $filters['status'];
    }

    $stmt = $pdo->prepare(
        'SELECT e.expense_date,e.description,e.amount,e.tax_amount,e.total_amount,e.payment_method,
                e.is_billable,e.is_tax_deductible,e.status,
                v.name AS vendor_name,ec.name AS category_name,c.name AS client_name
         FROM expenses e
         LEFT JOIN vendors v ON v.id=e.vendor_id
         LEFT JOIN expense_categories ec ON ec.id=e.category_id
         LEFT JOIN clients c ON c.id=e.client_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY e.expense_date,e.id'
    );
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'expense_sched_' . (int)$schedule['id'] . '_' . bin2hex(random_bytes(4));
    if (!mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Could not create expense report workspace.');
    }
    $csvFile = $tmpDir . DIRECTORY_SEPARATOR . 'expense_report.csv';
    $fp = fopen($csvFile, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Could not create expense report.');
    }
    fwrite($fp, "\xEF\xBB\xBF");
    csv_write_row($fp, ['Date', 'Vendor', 'Description', 'Category', 'Client', 'Amount', 'Tax', 'Total', 'Payment Method', 'Billable', 'Tax Deductible', 'Status']);
    $total = 0.0;
    foreach ($expenses as $expense) {
        $rowTotal = (float)($expense['total_amount'] ?? $expense['amount'] ?? 0);
        $total += $rowTotal;
        csv_write_row($fp, [
            $expense['expense_date'] ?? '',
            $expense['vendor_name'] ?? '',
            $expense['description'] ?? '',
            $expense['category_name'] ?? '',
            $expense['client_name'] ?? '',
            number_format((float)($expense['amount'] ?? 0), 2, '.', ''),
            number_format((float)($expense['tax_amount'] ?? 0), 2, '.', ''),
            number_format($rowTotal, 2, '.', ''),
            $expense['payment_method'] ?? '',
            !empty($expense['is_billable']) ? 'Yes' : 'No',
            !empty($expense['is_tax_deductible']) ? 'Yes' : 'No',
            $expense['status'] ?? '',
        ]);
    }
    csv_write_row($fp, []);
    csv_write_row($fp, ['Summary', '', count($expenses) . ' expenses', '', '', '', '', number_format($total, 2, '.', '')]);
    fclose($fp);

    $attachmentName = 'expenses_' . str_replace('-', '', $startDate) . '-' . str_replace('-', '', $endDate) . '.csv';
    $content = file_get_contents($csvFile);
    if ($content === false) {
        throw new RuntimeException('Could not read generated expense report.');
    }
    $attachments = [['filename' => $attachmentName, 'content' => $content, 'mime' => 'text/csv']];
    $subject = ucfirst((string)$schedule['frequency']) . ' Expense Report - ' . $startDate . ' to ' . $endDate;
    $body = '<h2>Expense Report</h2><p><strong>Period:</strong> ' . htmlspecialchars($startDate) . ' to ' . htmlspecialchars($endDate) . '</p>'
        . '<p><strong>Expenses:</strong> ' . count($expenses) . ' | <strong>Total:</strong> $' . number_format($total, 2) . '</p>'
        . '<p>The CSV report is attached.</p>';
    $sent = 0;
    foreach ($emails as $email) {
        $email = trim((string)$email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        [$ok, $error] = EmailService::sendEmail($email, $subject, $body, ['attachments'=>$attachments,'document_type'=>'notification']);
        if ($ok) {
            $sent++;
        } else {
            @error_log('[process_audit_schedules] Expense report email failed: ' . $error);
        }
    }

    $artifactDir = '/var/www/config/reports/' . $organizationId . '/scheduled';
    if (!is_dir($artifactDir)) {
        @mkdir($artifactDir, 0750, true);
    }
    if (is_dir($artifactDir) && is_writable($artifactDir)) {
        @copy($csvFile, $artifactDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_schedule_' . (int)$schedule['id'] . '_' . $attachmentName);
    }
    @unlink($csvFile);
    @rmdir($tmpDir);

    return 'Expense report sent to ' . $sent . '/' . count($emails) . ' recipients; ' . count($expenses) . ' expenses; total $' . number_format($total, 2);
}

/**
 * Calculate date range based on schedule type
 */
function calculateDateRange(string $type): array {
    $now = new DateTime();
    switch ($type) {
        case 'last_week':
            $end = (clone $now)->modify('last sunday');
            $start = (clone $end)->modify('-6 days');
            break;
        case 'last_month':
            $start = (clone $now)->modify('first day of last month')->setTime(0, 0);
            $end = (clone $now)->modify('last day of last month')->setTime(23, 59, 59);
            break;
        case 'last_quarter':
            $currentMonth = (int)$now->format('n');
            $currentQuarter = (int)ceil($currentMonth / 3);
            $prevQuarter = $currentQuarter - 1;
            $prevYear = (int)$now->format('Y');
            if ($prevQuarter <= 0) { $prevQuarter = 4; $prevYear--; }
            $startMonth = ($prevQuarter - 1) * 3 + 1;
            $endMonth = $prevQuarter * 3;
            $start = new DateTime("{$prevYear}-" . str_pad($startMonth, 2, '0', STR_PAD_LEFT) . "-01");
            $end = new DateTime("{$prevYear}-" . str_pad($endMonth, 2, '0', STR_PAD_LEFT) . "-01");
            $end->modify('last day of this month');
            break;
        case 'last_year':
            $lastYear = (int)$now->format('Y') - 1;
            $start = new DateTime("{$lastYear}-01-01");
            $end = new DateTime("{$lastYear}-12-31");
            break;
        case 'current_year':
            $start = new DateTime($now->format('Y') . '-01-01');
            $end = clone $now;
            break;
        case 'all_time':
            $start = new DateTime('2020-01-01');
            $end = clone $now;
            break;
        default:
            $start = new DateTime($now->format('Y') . '-01-01');
            $end = clone $now;
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/**
 * Advance a schedule to its next run date
 */
function advanceSchedule(PDO $pdo, array $schedule): void {
    $frequency = $schedule['frequency'];
    $now = new DateTime();

    switch ($frequency) {
        case 'weekly':
            $next = new DateTime('next monday');
            if ($next <= $now) $next->modify('+1 week');
            break;
        case 'monthly':
            $next = new DateTime('first day of next month');
            break;
        case 'quarterly':
            $currentMonth = (int)$now->format('n');
            $qStarts = [1, 4, 7, 10];
            $nextQ = null;
            foreach ($qStarts as $m) {
                if ($m > $currentMonth) { $nextQ = $m; break; }
            }
            if ($nextQ === null) {
                $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            } else {
                $next = new DateTime($now->format('Y') . '-' . str_pad($nextQ, 2, '0', STR_PAD_LEFT) . '-01');
            }
            break;
        case 'annually':
            $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            break;
        default:
            $next = new DateTime('first day of next month');
    }

    $pdo->prepare('UPDATE audit_schedules SET last_run_at = NOW(), next_run_at = ? WHERE id = ?')
        ->execute([$next->format('Y-m-d 06:00:00'), $schedule['id']]);
}
