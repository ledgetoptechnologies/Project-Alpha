<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/expense_schedule_authorization.php';

$requestedType = ($_POST['report_type'] ?? 'audit') === 'expense' ? 'expense' : 'audit';
$redirectPage = $requestedType === 'expense' ? 'financial/expense-report' : 'financial/audit';
csrf_verify_post_or_redirect($redirectPage);

$action = (string)($_POST['action'] ?? 'create');
$userId = (int)($_SESSION['user']['id'] ?? 0);
$organizationId = request_client_org_id();
$scheduleOrgId = $organizationId > 0 ? $organizationId : null;
$requiredPermission = $requestedType === 'expense' ? 'financial.manage' : 'financial.audit';
if ($action === 'create' && !user_can($pdo, $userId, $requiredPermission, 0)) {
    require_once __DIR__ . '/../../utils/acl_middleware.php';
    deny_response($redirectPage);
}

try {
    if ($action === 'create') {
        $frequency = (string)($_POST['schedule_frequency'] ?? 'monthly');
        $dateRangeType = (string)($_POST['schedule_date_range'] ?? 'current_year');
        $validFrequencies = ['weekly', 'monthly', 'quarterly', 'annually'];
        $validDateRanges = ['last_week', 'last_month', 'last_quarter', 'last_year', 'current_year', 'all_time'];
        if (!in_array($frequency, $validFrequencies, true) || !in_array($dateRangeType, $validDateRanges, true)) {
            throw new RuntimeException('Invalid report schedule.');
        }

        $emails = array_values(array_unique(array_slice(array_filter(array_map(
            static fn($email) => trim((string)$email),
            $_POST['schedule_email'] ?? []
        ), static fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false), 0, 5)));
        if (!$emails) {
            throw new RuntimeException('At least one valid recipient email is required.');
        }
        if ($requestedType === 'expense' && !pa_expense_schedule_recipients_allowed($pdo, $userId, $emails)) {
            throw new RuntimeException('Expense schedule recipients must match your account email.');
        }

        $accountingBasis = ($_POST['accounting_basis'] ?? 'cash') === 'accrual' ? 'accrual' : 'cash';
        $includeInvoices = $requestedType === 'audit' ? !empty($_POST['include_invoices']) : false;
        $includeUnpaid = $requestedType === 'audit' && !empty($_POST['include_unpaid_invoices']);
        $includeContracts = $requestedType === 'audit' && !empty($_POST['include_contracts']);
        $includeQuotes = $requestedType === 'audit' && !empty($_POST['include_quotes']);
        $includePdfs = $requestedType === 'audit' && !empty($_POST['include_pdfs']);

        $filters = null;
        if ($requestedType === 'expense') {
            $billable = (string)($_POST['billable'] ?? '');
            $taxDeductible = (string)($_POST['tax_deductible'] ?? '');
            $status = (string)($_POST['status'] ?? '');
            $filters = [
                'category_id' => max(0, (int)($_POST['category_id'] ?? 0)),
                'vendor_id' => max(0, (int)($_POST['vendor_id'] ?? 0)),
                'client_id' => max(0, (int)($_POST['client_id'] ?? 0)),
                'billable' => in_array($billable, ['0', '1'], true) ? $billable : '',
                'tax_deductible' => in_array($taxDeductible, ['0', '1'], true) ? $taxDeductible : '',
                'status' => in_array($status, ['pending', 'confirmed', 'reimbursed', 'void'], true) ? $status : '',
            ];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_schedules
             (organization_id,created_by,report_type,frequency,date_range_type,accounting_basis,email_addresses,
              include_invoices,include_unpaid_invoices,include_contracts,include_quotes,generate_csv,
              include_pdfs,filters,next_run_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $scheduleOrgId,
            $userId,
            $requestedType,
            $frequency,
            $dateRangeType,
            $accountingBasis,
            json_encode($emails),
            $includeInvoices ? 1 : 0,
            $includeUnpaid ? 1 : 0,
            $includeContracts ? 1 : 0,
            $includeQuotes ? 1 : 0,
            1,
            $includePdfs ? 1 : 0,
            $filters === null ? null : json_encode($filters),
            calculateNextRunTime($frequency),
        ]);

        header('Location: /?page=' . $redirectPage . '&scheduled=1');
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Invalid schedule.');
    }
    $existing = $pdo->prepare('SELECT report_type, created_by FROM audit_schedules WHERE id=?');
    $existing->execute([$id]);
    $stored = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$stored) {
        throw new RuntimeException('Schedule not found.');
    }
    $storedType = (string)$stored['report_type'];
    $redirectPage = $storedType === 'expense' ? 'financial/expense-report' : 'financial/audit';
    $storedPermission = $storedType === 'expense' ? 'financial.manage' : 'financial.audit';
    if (!user_can($pdo, $userId, $storedPermission, 0)) {
        throw new RuntimeException('You do not have permission to manage this schedule.');
    }
    if (!in_array(acl_user_role($pdo, $userId), ['admin', 'owner', 'staff'], true)
        && (int)($stored['created_by'] ?? 0) !== $userId) {
        throw new RuntimeException('You do not have access to this schedule.');
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM audit_schedules WHERE id=?')->execute([$id]);
        header('Location: /?page=' . $redirectPage . '&schedule_deleted=1');
        exit;
    }
    if ($action === 'toggle') {
        $pdo->prepare('UPDATE audit_schedules SET is_active=NOT is_active WHERE id=?')
            ->execute([$id]);
        header('Location: /?page=' . $redirectPage . '&schedule_updated=1');
        exit;
    }

    throw new RuntimeException('Unsupported schedule action.');
} catch (Throwable $e) {
    @error_log('[scheduled_reports] ' . $e->getMessage());
    header('Location: /?page=' . $redirectPage . '&error=' . urlencode($e->getMessage()));
    exit;
}

function calculateNextRunTime(string $frequency): string
{
    $now = new DateTimeImmutable();
    $next = match ($frequency) {
        'weekly' => $now->modify('next monday'),
        'quarterly' => nextQuarterStart($now),
        'annually' => new DateTimeImmutable(((int)$now->format('Y') + 1) . '-01-01'),
        default => $now->modify('first day of next month'),
    };
    return $next->setTime(6, 0)->format('Y-m-d H:i:s');
}

function nextQuarterStart(DateTimeImmutable $now): DateTimeImmutable
{
    $year = (int)$now->format('Y');
    $month = (int)$now->format('n');
    foreach ([1, 4, 7, 10] as $quarterMonth) {
        if ($quarterMonth > $month) {
            return new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarterMonth));
        }
    }
    return new DateTimeImmutable(($year + 1) . '-01-01');
}
