<?php
// src/views/pages/financial/_expense_report_tab.php (Reports > Expense report)
// Filterable expense report; CSV export and schedules need financial.manage.
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$canManageFinance = $canManageFinance ?? session_user_can($pdo, 'financial.manage');
$reportMoney = static fn($value): string => '$' . number_format((float)$value, 2);

$categories = $pdo->query('SELECT id, name FROM expense_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$vendors = $pdo->query('SELECT id, name FROM vendors WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Filters
$start = (string)($_GET['start'] ?? date('Y') . '-01-01');
$end = (string)($_GET['end'] ?? date('Y') . '-12-31');
$categoryId = (int)($_GET['category_id'] ?? 0);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$billable = (string)($_GET['billable'] ?? '');
$taxDeductible = (string)($_GET['tax_deductible'] ?? '');
$status = (string)($_GET['status'] ?? '');
$groupBy = in_array($_GET['group_by'] ?? '', ['category', 'vendor', 'month'], true) ? (string)$_GET['group_by'] : 'none';

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

$summaryStmt = $pdo->prepare("
    SELECT COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as grand_total,
           COALESCE(SUM(CASE WHEN e.is_billable=1 THEN COALESCE(e.total_amount, e.amount, 0) ELSE 0 END),0) as billable_total,
           COALESCE(SUM(CASE WHEN e.is_tax_deductible=1 THEN COALESCE(e.total_amount, e.amount, 0) ELSE 0 END),0) as deductible_total,
           COUNT(*) as count
    FROM expenses e WHERE {$whereSQL}
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$groupQueries = [
    'category' => "SELECT ec.name as group_name, COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as total, COUNT(e.id) as count
                   FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id
                   WHERE {$whereSQL} GROUP BY ec.id, ec.name ORDER BY total DESC",
    'vendor' => "SELECT COALESCE(v.name,'(No Vendor)') as group_name, COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as total, COUNT(e.id) as count
                 FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                 WHERE {$whereSQL} GROUP BY v.id, v.name ORDER BY total DESC",
    'month' => "SELECT DATE_FORMAT(e.expense_date, '%Y-%m') as group_name, COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as total, COUNT(e.id) as count
                FROM expenses e
                WHERE {$whereSQL} GROUP BY DATE_FORMAT(e.expense_date, '%Y-%m') ORDER BY group_name",
];
$grouped = [];
if (isset($groupQueries[$groupBy])) {
    $groupStmt = $pdo->prepare($groupQueries[$groupBy]);
    $groupStmt->execute($params);
    $grouped = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
}

$detailStmt = $pdo->prepare("
    SELECT e.*, v.name as vendor_name, ec.name as category_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    WHERE {$whereSQL}
    ORDER BY e.expense_date DESC
");
$detailStmt->execute($params);
$details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$exportParams = http_build_query(array_filter([
    'start' => $start, 'end' => $end, 'category_id' => $categoryId,
    'vendor_id' => $vendorId, 'client_id' => $clientId,
    'billable' => $billable, 'tax_deductible' => $taxDeductible, 'status' => $status,
]));
$selected = static fn($current, $value): string => (string)$current === (string)$value ? ' selected' : '';
?>
<div class="fin-report">
  <form method="get" action="/" class="card filter-form fin-report__filters">
    <input type="hidden" name="page" value="financial/reports">
    <input type="hidden" name="tab" value="expense">
    <div class="field"><label class="label-muted">Start</label><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="input"></div>
    <div class="field"><label class="label-muted">End</label><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="input"></div>
    <div class="field"><label class="label-muted">Group by</label>
      <select name="group_by" class="input">
        <?php foreach (['none' => 'No grouping', 'category' => 'Category', 'vendor' => 'Vendor', 'month' => 'Month'] as $value => $label): ?>
          <option value="<?php echo $value; ?>"<?php echo $selected($groupBy, $value); ?>><?php echo $label; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label class="label-muted">Category</label>
      <select name="category_id" class="input">
        <option value="0">All categories</option>
        <?php foreach ($categories as $cat): ?><option value="<?php echo (int)$cat['id']; ?>"<?php echo $selected($categoryId, $cat['id']); ?>><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label class="label-muted">Vendor</label>
      <select name="vendor_id" class="input">
        <option value="0">All vendors</option>
        <?php foreach ($vendors as $v): ?><option value="<?php echo (int)$v['id']; ?>"<?php echo $selected($vendorId, $v['id']); ?>><?php echo htmlspecialchars($v['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label class="label-muted">Client</label>
      <select name="client_id" class="input">
        <option value="0">All clients</option>
        <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>"<?php echo $selected($clientId, $c['id']); ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label class="label-muted">Billable</label>
      <select name="billable" class="input">
        <option value="">All</option>
        <option value="1"<?php echo $selected($billable, '1'); ?>>Billable only</option>
        <option value="0"<?php echo $selected($billable, '0'); ?>>Non-billable only</option>
      </select>
    </div>
    <div class="field"><label class="label-muted">Tax deductible</label>
      <select name="tax_deductible" class="input">
        <option value="">All</option>
        <option value="1"<?php echo $selected($taxDeductible, '1'); ?>>Deductible only</option>
        <option value="0"<?php echo $selected($taxDeductible, '0'); ?>>Non-deductible only</option>
      </select>
    </div>
    <div class="field"><label class="label-muted">Status</label>
      <select name="status" class="input">
        <option value="">Active statuses</option>
        <option value="all"<?php echo $selected($status, 'all'); ?>>All, including void</option>
        <?php foreach (['pending' => 'Pending', 'confirmed' => 'Confirmed', 'reimbursed' => 'Reimbursed', 'void' => 'Void'] as $value => $label): ?>
          <option value="<?php echo $value; ?>"<?php echo $selected($status, $value); ?>><?php echo $label; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field filter-actions">
      <button type="submit" class="btn btn-primary">Apply</button>
      <?php if ($canManageFinance): ?>
        <a href="/?page=financial/expense-export&amp;<?php echo htmlspecialchars($exportParams); ?>" class="btn" data-skip-nav download>Export CSV</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="expense-summary">
    <div class="expense-stat"><span>Total</span><strong><?php echo $reportMoney($summary['grand_total']); ?></strong><small><?php echo (int)$summary['count']; ?> expenses</small></div>
    <div class="expense-stat"><span>Billable</span><strong><?php echo $reportMoney($summary['billable_total']); ?></strong></div>
    <div class="expense-stat"><span>Tax deductible</span><strong><?php echo $reportMoney($summary['deductible_total']); ?></strong></div>
  </div>

  <?php if ($canManageFinance): ?>
    <details class="card fin-schedule">
      <summary>Schedule this report</summary>
      <form method="post" action="/?page=financial/audit-schedule-handler" class="fin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="report_type" value="expense">
        <input type="hidden" name="category_id" value="<?php echo $categoryId; ?>">
        <input type="hidden" name="vendor_id" value="<?php echo $vendorId; ?>">
        <input type="hidden" name="client_id" value="<?php echo $clientId; ?>">
        <input type="hidden" name="billable" value="<?php echo htmlspecialchars($billable); ?>">
        <input type="hidden" name="tax_deductible" value="<?php echo htmlspecialchars($taxDeductible); ?>">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <div class="fin-form__grid">
          <label class="field"><span class="label-muted">Every</span>
            <select name="schedule_frequency" class="input">
              <option value="weekly">Week</option>
              <option value="monthly" selected>Month</option>
              <option value="quarterly">Quarter</option>
              <option value="annually">Year</option>
            </select>
          </label>
          <label class="field"><span class="label-muted">Period</span>
            <select name="schedule_date_range" class="input">
              <option value="last_week">Previous week</option>
              <option value="last_month" selected>Previous month</option>
              <option value="last_quarter">Previous quarter</option>
              <option value="last_year">Previous year</option>
              <option value="current_year">Current year to date</option>
            </select>
          </label>
          <label class="field"><span class="label-muted">Send to</span>
            <input type="email" name="schedule_email[]" class="input" required placeholder="name@example.com">
          </label>
        </div>
        <div class="fin-form__actions"><button type="submit" class="btn btn-primary">Save schedule</button></div>
      </form>
    </details>
  <?php endif; ?>

  <?php if ($grouped): ?>
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead><tr><th><?php echo htmlspecialchars(ucfirst($groupBy)); ?></th><th class="text-right">Count</th><th class="text-right">Total</th></tr></thead>
        <tbody>
          <?php foreach ($grouped as $g): ?>
            <tr>
              <td><?php echo htmlspecialchars((string)($g['group_name'] ?? 'Uncategorized')); ?></td>
              <td class="text-right"><?php echo (int)$g['count']; ?></td>
              <td class="text-right font-600"><?php echo $reportMoney($g['total']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!$details): ?>
    <div class="finance-empty"><strong>No expenses found for the selected filters.</strong></div>
  <?php else: ?>
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead><tr><th>Date</th><th>Vendor</th><th>Description</th><th>Category</th><th class="text-right">Amount</th><th class="text-right">Total</th><th>Billable</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($details as $d): ?>
            <tr>
              <td><a href="/?page=financial/expense-detail&amp;id=<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars((string)$d['expense_date']); ?></a></td>
              <td><?php echo htmlspecialchars($d['vendor_name'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars(mb_substr($d['description'] ?? '', 0, 40)); ?></td>
              <td><?php echo htmlspecialchars($d['category_name'] ?? '—'); ?></td>
              <td class="text-right"><?php echo $reportMoney($d['amount']); ?></td>
              <td class="text-right font-600"><?php echo $reportMoney($d['total_amount'] ?? $d['amount']); ?></td>
              <td><?php echo $d['is_billable'] ? '<span class="status-pill status-pill--active">Billable</span>' : '<span class="muted">—</span>'; ?></td>
              <td><span class="status-pill status-pill--<?php echo htmlspecialchars(strtolower((string)$d['status'])); ?>"><?php echo htmlspecialchars((string)$d['status']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
