<?php
// src/views/pages/financial/expense-report.php
// Filterable expense report with CSV export
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);

// Fetch filter options
$catStmt = $pdo->prepare('SELECT id, name FROM expense_categories ORDER BY name');
$catStmt->execute();
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$vendorStmt = $pdo->prepare('SELECT id, name FROM vendors WHERE is_active=1 ORDER BY name');
$vendorStmt->execute();
$vendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

$clientStmt = $pdo->prepare('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name');
$clientStmt->execute();
$clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);

// Filters
$start = $_GET['start'] ?? date('Y') . '-01-01';
$end = $_GET['end'] ?? date('Y') . '-12-31';
$categoryId = (int)($_GET['category_id'] ?? 0);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$billable = $_GET['billable'] ?? '';
$taxDeductible = $_GET['tax_deductible'] ?? '';
$status = $_GET['status'] ?? '';
$groupBy = $_GET['group_by'] ?? 'none';

[$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');
$where = [$expenseScopeWhere];
$params = $expenseScopeParams;

if ($start) { $where[] = 'e.expense_date >= ?'; $params[] = $start; }
if ($end) { $where[] = 'e.expense_date <= ?'; $params[] = $end; }
if ($categoryId > 0) { $where[] = 'e.category_id = ?'; $params[] = $categoryId; }
if ($vendorId > 0) { $where[] = 'e.vendor_id = ?'; $params[] = $vendorId; }
if ($clientId > 0) { $where[] = 'e.client_id = ?'; $params[] = $clientId; }
if ($billable === '1') { $where[] = 'e.is_billable = 1'; }
if ($billable === '0') { $where[] = 'e.is_billable = 0'; }
if ($taxDeductible === '1') { $where[] = 'e.is_tax_deductible = 1'; }
if ($taxDeductible === '0') { $where[] = 'e.is_tax_deductible = 0'; }
if ($status === 'all') {
    // Explicit audit view, including voided expenses.
} elseif ($status !== '') {
    $where[] = 'e.status = ?';
    $params[] = $status;
} else {
    $where[] = "e.status != 'void'";
}

$whereSQL = implode(' AND ', $where);

// Summary totals
$summaryStmt = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount),0) as total_amount,
           COALESCE(SUM(e.total_amount),0) as grand_total,
           COALESCE(SUM(CASE WHEN e.is_billable=1 THEN e.total_amount ELSE 0 END),0) as billable_total,
           COALESCE(SUM(CASE WHEN e.is_tax_deductible=1 THEN e.total_amount ELSE 0 END),0) as deductible_total,
           COUNT(*) as count
    FROM expenses e WHERE {$whereSQL}
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Build query based on group_by
if ($groupBy === 'category') {
    $groupStmt = $pdo->prepare("
        SELECT ec.name as group_name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
        FROM expenses e
        LEFT JOIN expense_categories ec ON ec.id = e.category_id
        WHERE {$whereSQL}
        GROUP BY ec.id, ec.name ORDER BY total DESC
    ");
    $groupStmt->execute($params);
    $grouped = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($groupBy === 'vendor') {
    $groupStmt = $pdo->prepare("
        SELECT COALESCE(v.name,'(No Vendor)') as group_name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE {$whereSQL}
        GROUP BY v.id, v.name ORDER BY total DESC
    ");
    $groupStmt->execute($params);
    $grouped = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($groupBy === 'month') {
    $groupStmt = $pdo->prepare("
        SELECT DATE_FORMAT(e.expense_date, '%Y-%m') as group_name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
        FROM expenses e
        WHERE {$whereSQL}
        GROUP BY DATE_FORMAT(e.expense_date, '%Y-%m') ORDER BY group_name
    ");
    $groupStmt->execute($params);
    $grouped = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $grouped = [];
}

// Detail records
$detailStmt = $pdo->prepare("
    SELECT e.*, v.name as vendor_name, ec.name as category_name, c.name as client_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN clients c ON c.id = e.client_id
    WHERE {$whereSQL}
    ORDER BY e.expense_date DESC
");
$detailStmt->execute($params);
$details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$scheduleSql = 'SELECT * FROM audit_schedules WHERE (organization_id IS NULL OR organization_id = 0) AND report_type="expense"';
$scheduleParams = [];
if (!in_array(acl_user_role($pdo, (int)$userId), ['admin', 'owner', 'staff'], true)) {
    $scheduleSql .= ' AND created_by=?';
    $scheduleParams[] = (int)$userId;
}
$scheduleStmt = $pdo->prepare($scheduleSql . ' ORDER BY created_at DESC');
$scheduleStmt->execute($scheduleParams);
$expenseSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

// Build export URL with current filters
$exportParams = http_build_query(array_filter([
    'start' => $start, 'end' => $end, 'category_id' => $categoryId,
    'vendor_id' => $vendorId, 'client_id' => $clientId,
    'billable' => $billable, 'tax_deductible' => $taxDeductible, 'status' => $status,
]));
?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
  <div style="display:flex;gap:8px;margin-bottom:18px">
    <a class="btn" href="/?page=financial/audit">Audit Export</a>
    <a class="btn btn-primary" href="/?page=financial/expense-report">Expense Reports</a>
  </div>
  <div class="page-head">
    <h2>Expense Report</h2>
    <a href="/?page=financial/expense-export&<?php echo htmlspecialchars($exportParams); ?>" class="btn btn-sm">Export CSV</a>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:20px">
    <form method="get" action="/" class="filter-form">
      <input type="hidden" name="page" value="financial/expense-report">
      <div class="field">
        <label class="label-muted">Start Date</label>
        <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="input">
      </div>
      <div class="field">
        <label class="label-muted">End Date</label>
        <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="input">
      </div>
      <div class="field">
        <label class="label-muted">Category</label>
        <select name="category_id" class="input">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Vendor</label>
        <select name="vendor_id" class="input">
          <option value="0">All Vendors</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?php echo (int)$v['id']; ?>" <?php echo $vendorId === (int)$v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Client</label>
        <select name="client_id" class="input">
          <option value="0">All Clients</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo $clientId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Billable</label>
        <select name="billable" class="input">
          <option value="">All</option>
          <option value="1" <?php echo $billable === '1' ? 'selected' : ''; ?>>Billable Only</option>
          <option value="0" <?php echo $billable === '0' ? 'selected' : ''; ?>>Non-Billable Only</option>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Tax Deductible</label>
        <select name="tax_deductible" class="input">
          <option value="">All</option>
          <option value="1" <?php echo $taxDeductible === '1' ? 'selected' : ''; ?>>Deductible Only</option>
          <option value="0" <?php echo $taxDeductible === '0' ? 'selected' : ''; ?>>Non-Deductible Only</option>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Status</label>
        <select name="status" class="input">
          <option value="">Active statuses</option>
          <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All statuses including void</option>
          <?php foreach (['pending' => 'Pending', 'confirmed' => 'Confirmed', 'reimbursed' => 'Reimbursed', 'void' => 'Void'] as $value => $label): ?>
            <option value="<?php echo $value; ?>" <?php echo $status === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Group By</label>
        <select name="group_by" class="input">
          <option value="none" <?php echo $groupBy === 'none' ? 'selected' : ''; ?>>No Grouping</option>
          <option value="category" <?php echo $groupBy === 'category' ? 'selected' : ''; ?>>By Category</option>
          <option value="vendor" <?php echo $groupBy === 'vendor' ? 'selected' : ''; ?>>By Vendor</option>
          <option value="month" <?php echo $groupBy === 'month' ? 'selected' : ''; ?>>By Month</option>
        </select>
      </div>
      <div class="field filter-actions">
        <button type="submit" class="btn btn-primary">Apply Filters</button>
      </div>
    </form>
  </div>

  <!-- Summary Cards -->
  <div class="grid grid-3" style="margin-bottom:20px">
    <div class="card card-tight">
      <div class="muted text-sm">Total Expenses</div>
      <div class="font-600" style="font-size:20px"><?php echo money_format_total($summary['grand_total']); ?></div>
      <div class="muted-note"><?php echo (int)$summary['count']; ?> expenses</div>
    </div>
    <div class="card card-tight">
      <div class="muted text-sm">Billable</div>
      <div class="font-600" style="font-size:20px"><?php echo money_format_total($summary['billable_total']); ?></div>
    </div>
    <div class="card card-tight">
      <div class="muted text-sm">Tax-Deductible</div>
      <div class="font-600" style="font-size:20px"><?php echo money_format_total($summary['deductible_total']); ?></div>
    </div>
  </div>

  <section style="margin-bottom:20px;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:20px 0">
    <div class="page-head" style="margin-bottom:16px">
      <h3 style="margin:0">Scheduled Expense Reports</h3>
    </div>
    <form method="post" action="/?page=financial/audit-schedule-handler" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="report_type" value="expense">
      <input type="hidden" name="category_id" value="<?php echo $categoryId; ?>">
      <input type="hidden" name="vendor_id" value="<?php echo $vendorId; ?>">
      <input type="hidden" name="client_id" value="<?php echo $clientId; ?>">
      <input type="hidden" name="billable" value="<?php echo htmlspecialchars($billable); ?>">
      <input type="hidden" name="tax_deductible" value="<?php echo htmlspecialchars($taxDeductible); ?>">
      <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
      <label class="field">
        <span class="label-muted">Frequency</span>
        <select name="schedule_frequency" class="input">
          <option value="weekly">Weekly</option>
          <option value="monthly" selected>Monthly</option>
          <option value="quarterly">Quarterly</option>
          <option value="annually">Annually</option>
        </select>
      </label>
      <label class="field">
        <span class="label-muted">Report Period</span>
        <select name="schedule_date_range" class="input">
          <option value="last_week">Previous week</option>
          <option value="last_month" selected>Previous month</option>
          <option value="last_quarter">Previous quarter</option>
          <option value="last_year">Previous year</option>
          <option value="current_year">Current year to date</option>
        </select>
      </label>
      <label class="field">
        <span class="label-muted">Recipient</span>
        <input type="email" name="schedule_email[]" class="input" required placeholder="name@example.com">
      </label>
      <button type="submit" class="btn btn-primary">Create Schedule</button>
    </form>

    <?php if ($expenseSchedules): ?>
      <div class="pa-table-wrap" style="margin-top:18px">
        <table class="pa-table">
          <thead><tr><th>Frequency</th><th>Period</th><th>Recipients</th><th>Next Run</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($expenseSchedules as $schedule): ?>
            <?php $scheduleEmails = json_decode((string)$schedule['email_addresses'], true) ?: []; ?>
            <tr>
              <td><?php echo htmlspecialchars(ucfirst((string)$schedule['frequency'])); ?></td>
              <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$schedule['date_range_type']))); ?></td>
              <td><?php echo htmlspecialchars(implode(', ', $scheduleEmails)); ?></td>
              <td><?php echo !empty($schedule['next_run_at']) ? htmlspecialchars(date('M j, Y', strtotime((string)$schedule['next_run_at']))) : 'Not scheduled'; ?></td>
              <td><span class="status-pill <?php echo !empty($schedule['is_active']) ? 'status-pill--active' : ''; ?>"><?php echo !empty($schedule['is_active']) ? 'Active' : 'Paused'; ?></span></td>
              <td style="white-space:nowrap">
                <form method="post" action="/?page=financial/audit-schedule-handler" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="report_type" value="expense"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>">
                  <button type="submit" class="btn btn-sm"><?php echo !empty($schedule['is_active']) ? 'Pause' : 'Resume'; ?></button>
                </form>
                <form method="post" action="/?page=financial/audit-schedule-handler" style="display:inline" onsubmit="return confirm('Delete this schedule?')">
                  <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="report_type" value="expense"><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>">
                  <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if (!empty($grouped)): ?>
  <!-- Grouped Summary -->
  <div class="card" style="margin-bottom:20px">
    <h3 class="card-title" style="margin-bottom:12px">Summary by <?php echo htmlspecialchars($groupBy); ?></h3>
    <table class="pa-table">
      <thead>
        <tr>
          <th><?php echo ucfirst($groupBy); ?></th>
          <th class="text-right">Count</th>
          <th class="text-right">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grouped as $g): ?>
          <tr>
            <td><?php echo htmlspecialchars($g['group_name']); ?></td>
            <td class="text-right"><?php echo (int)$g['count']; ?></td>
            <td class="text-right font-600"><?php echo htmlspecialchars(money_format_total($g['total'])); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Detail Table -->
  <div class="card">
    <h3 class="card-title" style="margin-bottom:12px">Expense Details</h3>
    <?php if (empty($details)): ?>
      <p class="muted" style="text-align:center;padding:32px">No expenses found for the selected filters.</p>
    <?php else: ?>
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Vendor</th>
            <th>Description</th>
            <th>Category</th>
            <th class="text-right">Amount</th>
            <th class="text-right">Total</th>
            <th>Billable</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($details as $d): ?>
            <tr>
              <td><?php echo htmlspecialchars($d['expense_date']); ?></td>
              <td><?php echo htmlspecialchars($d['vendor_name'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars(mb_substr($d['description'] ?? '', 0, 40)); ?></td>
              <td><?php echo htmlspecialchars($d['category_name'] ?? '—'); ?></td>
              <td class="text-right"><?php echo htmlspecialchars(money_format_total($d['amount'])); ?></td>
              <td class="text-right font-600"><?php echo htmlspecialchars(money_format_total($d['total_amount'] ?? $d['amount'])); ?></td>
              <td><?php echo $d['is_billable'] ? '<span class="status-pill status-pill--active">Billable</span>' : '<span class="muted">—</span>'; ?></td>
              <td><span class="status-pill status-pill--<?php echo htmlspecialchars(strtolower($d['status'])); ?>"><?php echo htmlspecialchars($d['status']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
function money_format_total($val) {
    return '$' . number_format((float)$val, 2);
}
?>
