<?php
// src/controllers/financial/audit_export.php
// Pure PHP audit generation - no Python dependency
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csv.php';

// Get form parameters
$startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('January 1 ' . date('Y')));
$endDate = $_POST['end_date'] ?? date('Y-m-d');
$accountingBasis = ($_POST['accounting_basis'] ?? 'cash') === 'accrual' ? 'accrual' : 'cash';

// CSRF check: accept legacy 'csrf' or Symfony '_token'
$csrfOk = false;
$submitted = $_POST['_token'] ?? '';
if (is_string($submitted) && $submitted !== '') {
    $csrfOk = csrf_sf_is_valid('audit', $submitted);
} elseif (!empty($_POST['csrf'])) {
    $csrfOk = csrf_validate();
}
if (!$csrfOk) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Invalid request (CSRF)';
    exit;
}
$includeInvoices = isset($_POST['include_invoices']) && $_POST['include_invoices'] === '1';
$includeUnpaidInvoices = isset($_POST['include_unpaid_invoices']) && $_POST['include_unpaid_invoices'] === '1';
$includeContracts = isset($_POST['include_contracts']) && $_POST['include_contracts'] === '1';
$includeQuotes = isset($_POST['include_quotes']) && $_POST['include_quotes'] === '1';
$generateCsv = isset($_POST['generate_csv']) && $_POST['generate_csv'] === '1';
$includePdfs = isset($_POST['include_pdfs']) && $_POST['include_pdfs'] === '1';
$enableScheduling = isset($_POST['enable_scheduling']) && $_POST['enable_scheduling'] === '1';
$activeOrgId = request_client_org_id();

// Scheduling is handled only by audit_schedule_handler, which records a
// creator and enforces report and export permissions.
if ($enableScheduling) {
    http_response_code(400);
    exit('Use the scheduled report form to create an audit schedule.');
}

// Validate dates
if (!$startDate || !$endDate) {
    header('Location: /?page=financial/audit&error=' . urlencode('Please select a valid date range.'));
    exit;
}

try {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
} catch (Exception $e) {
    header('Location: /?page=financial/audit&error=' . urlencode('Invalid date format. Please use the date picker.'));
    exit;
}

if ($start > $end) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

try {
    // Fetch invoices
    $invoices = [];
    if ($includeInvoices) {
        $statusFilter = $includeUnpaidInvoices 
            ? "i.status IN ('paid', 'partial', 'unpaid')" 
            : "i.status IN ('paid', 'partial')";
        
        if ($accountingBasis === 'cash') {
            $invoiceQuery = "
                SELECT i.id,i.doc_number,i.invoice_type,i.client_id,c.name AS client_name,i.project_code,
                       i.subtotal,i.tax_percent,i.tax_amount AS tax,i.tax_county,i.discount_value,
                       i.total,i.status,MIN(p.payment_date) AS report_date,i.created_at,i.due_date,
                       SUM(GREATEST(p.amount-p.refunded_amount-p.disputed_amount,0)) AS amount_paid,
                       GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') AS payment_methods
                FROM invoices i
                JOIN clients c ON i.client_id=c.id
                JOIN payments p ON i.id=p.invoice_id AND p.status='succeeded'
                WHERE p.payment_date BETWEEN ? AND ? AND (?=0 OR i.organization_id=?)
                GROUP BY i.id
                ORDER BY report_date ASC,i.doc_number ASC
            ";
            $stmt = $pdo->prepare($invoiceQuery);
            $stmt->execute([$startDate, $endDate, $activeOrgId, $activeOrgId]);
        } else {
        $invoiceQuery = "
            SELECT 
                i.id,
                i.doc_number,
                i.invoice_type,
                i.client_id,
                c.name as client_name,
                i.project_code,
                i.subtotal,
                i.tax_percent,
                i.tax_amount as tax,
                i.tax_county,
                i.discount_value,
                i.total,
                i.status,
                DATE(COALESCE(i.finalized_at,i.created_at)) AS report_date,
                i.created_at,
                i.due_date,
                COALESCE(SUM(CASE WHEN p.status = 'succeeded' THEN p.amount ELSE 0 END), 0) as amount_paid,
                GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods
            FROM invoices i
            LEFT JOIN clients c ON i.client_id = c.id
            LEFT JOIN payments p ON i.id = p.invoice_id
            WHERE COALESCE(i.finalized_at,i.created_at) BETWEEN ? AND ? AND {$statusFilter}
              AND i.status <> 'draft'
              AND (?=0 OR i.organization_id=?)
            GROUP BY i.id
            ORDER BY i.created_at ASC, i.doc_number ASC
        ";
        
        $stmt = $pdo->prepare($invoiceQuery);
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $activeOrgId, $activeOrgId]);
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
        $contractQuery = "
            SELECT 
                c.id,
                c.doc_number,
                c.client_id,
                cl.name as client_name,
                c.project_code,
                c.contract_type,
                c.total,
                c.status,
                c.created_at,
                c.start_date,
                c.end_date,
                c.discount_type,
                c.discount_value,
                c.tax_percent
            FROM contracts c
            LEFT JOIN clients cl ON c.client_id = cl.id
            WHERE c.created_at BETWEEN ? AND ? AND (?=0 OR c.organization_id=?)
            ORDER BY c.created_at ASC, c.doc_number ASC
        ";
        $contractStmt = $pdo->prepare($contractQuery);
        $contractStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $activeOrgId, $activeOrgId]);
        $contracts = $contractStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch quotes
    $quotes = [];
    if ($includeQuotes) {
        $quoteQuery = "
            SELECT 
                q.id,
                q.doc_number,
                q.client_id,
                cl.name as client_name,
                q.project_code,
                q.quote_type,
                q.total,
                q.status,
                q.created_at,
                q.discount_type,
                q.discount_value,
                q.tax_percent
            FROM quotes q
            LEFT JOIN clients cl ON q.client_id = cl.id
            WHERE q.created_at BETWEEN ? AND ? AND (?=0 OR q.organization_id=?)
            ORDER BY q.created_at ASC, q.doc_number ASC
        ";
        $quoteStmt = $pdo->prepare($quoteQuery);
        $quoteStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $activeOrgId, $activeOrgId]);
        $quotes = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Check if we have any data
    if (empty($invoices) && empty($contracts) && empty($quotes)) {
        header('Location: /?page=financial/audit&error=' . urlencode('No records found for the selected date range and options.'));
        exit;
    }

    // Create temp directory for files
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit_' . uniqid();
    if (!mkdir($tmpDir, 0755, true)) {
        throw new Exception('Could not create temporary directory');
    }

    $filesToZip = [];

    // Generate CSV
    if ($generateCsv) {
        $csvFile = $tmpDir . DIRECTORY_SEPARATOR . 'audit_report.csv';
        $fp = fopen($csvFile, 'w');
        if (!$fp) {
            throw new Exception('Could not create CSV file');
        }

        // Write BOM for Excel UTF-8 compatibility
        fwrite($fp, "\xEF\xBB\xBF");

        // Headers
        $headers = ['Date', 'Client', 'Doc Number', 'Document Type', 'Status', 'Tax %', 'Tax County', 'Amount Paid', 'Payment Method', 'Discount', 'Total', 'Running Total'];
        csv_write_row($fp, $headers);

        $runningTotal = 0;

        // Write invoices
        foreach ($invoices as $inv) {
            $amountPaid = (float)($inv['amount_paid'] ?? 0);
            $runningTotal += $amountPaid;
            
            csv_write_row($fp, [
                substr($inv['report_date'] ?? $inv['created_at'] ?? '', 0, 10),
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

        // Write contracts
        foreach ($contracts as $contract) {
            $total = (float)($contract['total'] ?? 0);
            
            csv_write_row($fp, [
                substr($contract['created_at'] ?? '', 0, 10),
                $contract['client_name'] ?? '',
                $contract['doc_number'] ?? $contract['id'],
                'Contract',
                ucfirst($contract['status'] ?? ''),
                '',
                '',
                '',
                '',
                '',
                '$' . number_format($total, 2),
                ''
            ]);
        }

        // Write quotes
        foreach ($quotes as $quote) {
            $total = (float)($quote['total'] ?? 0);
            
            csv_write_row($fp, [
                substr($quote['created_at'] ?? '', 0, 10),
                $quote['client_name'] ?? '',
                $quote['doc_number'] ?? $quote['id'],
                'Quote',
                ucfirst($quote['status'] ?? ''),
                '',
                '',
                '',
                '',
                '',
                '$' . number_format($total, 2),
                ''
            ]);
        }

        // Summary row
        csv_write_row($fp, ['', '', '', '', '', '', '', '', '', '', '', '']);
        csv_write_row($fp, ['SUMMARY', '', '', '', '', '', '', '', '', '', '', '']);
        csv_write_row($fp, ['Total Invoices:', count($invoices), '', '', '', '', '', 'Total Collected:', '$' . number_format($runningTotal, 2), '', '', '']);
        if ($includeContracts) {
            csv_write_row($fp, ['Total Contracts:', count($contracts), '', '', '', '', '', '', '', '', '', '']);
        }
        if ($includeQuotes) {
            csv_write_row($fp, ['Total Quotes:', count($quotes), '', '', '', '', '', '', '', '', '', '']);
        }

        fclose($fp);
        $filesToZip['audit_report.csv'] = $csvFile;
    }

    // Generate manifest
    $manifestFile = $tmpDir . DIRECTORY_SEPARATOR . 'MANIFEST.txt';
    $manifest = "=== FINANCIAL AUDIT REPORT ===\n\n";
    $manifest .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $manifest .= "Audit Period: {$startDate} to {$endDate}\n\n";
    $manifest .= "Accounting Basis: " . ucfirst($accountingBasis) . "\n\n";
    $manifest .= "REPORT CONFIGURATION:\n";
    $manifest .= "- Include Invoices (Paid/Partial): " . ($includeInvoices ? 'Yes' : 'No') . "\n";
    $manifest .= "- Include Unpaid Invoices: " . ($includeUnpaidInvoices ? 'Yes' : 'No') . "\n";
    $manifest .= "- Include Contracts: " . ($includeContracts ? 'Yes' : 'No') . "\n";
    $manifest .= "- Include Quotes: " . ($includeQuotes ? 'Yes' : 'No') . "\n\n";
    $manifest .= "REPORT SUMMARY:\n";
    $manifest .= "- Total Invoices: " . count($invoices) . "\n";
    if ($includeContracts) {
        $manifest .= "- Total Contracts: " . count($contracts) . "\n";
    }
    if ($includeQuotes) {
        $manifest .= "- Total Quotes: " . count($quotes) . "\n";
    }
    $totalCollected = array_sum(array_column($invoices, 'amount_paid'));
    $manifest .= "- Total Collected: $" . number_format($totalCollected, 2) . "\n\n";
    $manifest .= "FILES INCLUDED:\n";
    if ($generateCsv) {
        $manifest .= "- audit_report.csv: Detailed audit data\n";
    }
    $manifest .= "- MANIFEST.txt: This file\n";
    
    file_put_contents($manifestFile, $manifest);
    $filesToZip['MANIFEST.txt'] = $manifestFile;

    // Create ZIP file
    $zipFilename = 'audit_' . str_replace('-', '', $startDate) . '-' . str_replace('-', '', $endDate) . '_' . date('Ymd_His') . '.zip';
    $zipPath = $tmpDir . DIRECTORY_SEPARATOR . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new Exception('Could not create ZIP file. Ensure the ZipArchive extension is enabled.');
    }

    foreach ($filesToZip as $name => $path) {
        $zip->addFile($path, $name);
    }
    $zip->close();

    $orgId = request_client_org_id() ?: 0;
    $artifactDir = '/var/www/config/audits/' . $orgId . '/' . date('Ymd_His');
    if (!is_dir($artifactDir)) {
        @mkdir($artifactDir, 0750, true);
    }
    if (is_dir($artifactDir) && is_writable($artifactDir)) {
        @copy($zipPath, $artifactDir . DIRECTORY_SEPARATOR . $zipFilename);
    }

    // Audit the data export
    audit_log($pdo, 'data.export', 'audit_report', null, [
        'period' => $startDate . ' to ' . $endDate,
        'invoices' => count($invoices),
        'contracts' => count($contracts),
        'quotes' => count($quotes),
        'accounting_basis' => $accountingBasis,
    ]);

    // Send file for download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    readfile($zipPath);

    // Cleanup
    foreach ($filesToZip as $path) {
        @unlink($path);
    }
    @unlink($zipPath);
    @rmdir($tmpDir);
    
    exit;

} catch (Throwable $e) {
    error_log('Audit export error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    
    // Cleanup on error
    if (isset($tmpDir) && is_dir($tmpDir)) {
        array_map('unlink', glob("$tmpDir/*") ?: []);
        @rmdir($tmpDir);
    }
    
    header('Location: /?page=financial/audit&error=' . urlencode('Failed to generate audit: ' . $e->getMessage()));
    exit;
}
