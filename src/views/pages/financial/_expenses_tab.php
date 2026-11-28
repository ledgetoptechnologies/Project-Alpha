<?php
// src/views/pages/financial/_expenses_tab.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);

$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$categoryId = (int)($_GET['category_id'] ?? 0);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$billable = $_GET['billable'] ?? '';
$status = $_GET['status'] ?? '';
$recurringExpenseId = (int)($_GET['recurring_expense_id'] ?? 0);

[$scopeWhere, $scopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');

$where = [$scopeWhere];
$params = $scopeParams;
if ($start) { $where[] = 'e.expense_date >= ?'; $params[] = $start; }
if ($end) { $where[] = 'e.expense_date <= ?'; $params[] = $end; }
if ($categoryId > 0) { $where[] = 'e.category_id = ?'; $params[] = $categoryId; }
if ($vendorId > 0) { $where[] = 'e.vendor_id = ?'; $params[] = $vendorId; }
if ($clientId > 0) { $where[] = 'e.client_id = ?'; $params[] = $clientId; }
if ($billable === '1') { $where[] = 'e.is_billable = 1'; }
if ($billable === '0') { $where[] = 'e.is_billable = 0'; }
if ($status === 'all') {
    // Explicit audit view, including voided expenses.
} elseif ($status !== '') {
    $where[] = 'e.status = ?';
    $params[] = $status;
} else {
    $where[] = "e.status != 'void'";
}
if ($recurringExpenseId > 0) { $where[] = 'e.recurring_expense_id = ?'; $params[] = $recurringExpenseId; }
$whereSQL = implode(' AND ', $where);

$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, [50, 100], true)) $per = 50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e WHERE {$whereSQL}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT e.*, v.name as vendor_name, ec.name as category_name, c.name as client_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN clients c ON c.id = e.client_id
    WHERE {$whereSQL}
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT $per OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sumStmt = $pdo->prepare("
    SELECT COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as grand_total,
           COALESCE(SUM(CASE WHEN e.is_billable=1 THEN COALESCE(e.total_amount, e.amount, 0) ELSE 0 END),0) as billable_total,
           COALESCE(SUM(CASE WHEN e.is_tax_deductible=1 THEN COALESCE(e.total_amount, e.amount, 0) ELSE 0 END),0) as deductible_total,
           COALESCE(SUM(CASE WHEN e.is_billable=1 AND e.is_reimbursed=0 THEN COALESCE(e.total_amount, e.amount, 0) ELSE 0 END),0) as unreimbursed_total
    FROM expenses e WHERE {$whereSQL}
");
$sumStmt->execute($params);
$summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

$cats = $pdo->prepare('SELECT id, name FROM expense_categories ORDER BY name');
$cats->execute();
$categories = $cats->fetchAll(PDO::FETCH_ASSOC);

$vendorsQ = $pdo->prepare('SELECT id, name FROM vendors WHERE is_active=1 ORDER BY name');
$vendorsQ->execute();
$vendors = $vendorsQ->fetchAll(PDO::FETCH_ASSOC);

$clientsQ = $pdo->prepare('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name');
$clientsQ->execute();
$clients = $clientsQ->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $per));
$activeFilterCount = count(array_filter([$start, $end, $categoryId, $vendorId, $clientId, $billable, $status, $recurringExpenseId], function ($value) {
    return $value !== '' && $value !== 0 && $value !== '0';
}));

if (!function_exists('expense_tab_money')) {
    function expense_tab_money(float $amount): string { return '$' . number_format($amount, 2); }
}
if (!function_exists('expense_tab_date')) {
    function expense_tab_date(?string $date): string { return $date ? date('M j, Y', strtotime($date)) : '-'; }
}
if (!function_exists('expense_tab_status_class')) {
    function expense_tab_status_class(?string $status): string {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$status)) ?: 'pending';
    }
}
if (!function_exists('expense_tab_page_url')) {
    function expense_tab_page_url(int $page, array $filters): string {
        $query = array_filter($filters, function ($value) {
            return $value !== '' && $value !== null && $value !== 0 && $value !== '0';
        });
        $query['page'] = 'financial/expenses-list';
        $query['tab'] = 'expenses';
        $query['p'] = $page;
        return '/?' . http_build_query($query);
    }
}

$paginationFilters = [
    'start' => $start,
    'end' => $end,
    'category_id' => $categoryId,
    'vendor_id' => $vendorId,
    'client_id' => $clientId,
    'billable' => $billable,
    'status' => $status,
    'recurring_expense_id' => $recurringExpenseId,
    'per_page' => $per,
];
?>

<section class="expense-ledger">
  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Expense created successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Expense updated successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['deleted'])): ?>
    <div class="alert alert-success">Expense deleted.</div>
  <?php endif; ?>
  <?php if ($recurringExpenseId > 0): ?>
    <div class="alert" style="background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a">Showing generated history for recurring expense #<?php echo $recurringExpenseId; ?>. <a href="/?page=financial/expenses-list&tab=recurring">Back to recurring schedules</a></div>
  <?php endif; ?>

  <div class="expense-summary">
    <div class="expense-stat">
      <span>Total</span>
      <strong><?php echo expense_tab_money((float)$summary['grand_total']); ?></strong>
      <small><?php echo number_format($total); ?> matching expense<?php echo $total === 1 ? '' : 's'; ?></small>
    </div>
    <div class="expense-stat">
      <span>Billable</span>
      <strong><?php echo expense_tab_money((float)$summary['billable_total']); ?></strong>
      <small>Potential client recovery</small>
    </div>
    <div class="expense-stat">
      <span>Unreimbursed</span>
      <strong><?php echo expense_tab_money((float)$summary['unreimbursed_total']); ?></strong>
      <small>Billable and open</small>
    </div>
    <div class="expense-stat">
      <span>Tax Deductible</span>
      <strong><?php echo expense_tab_money((float)$summary['deductible_total']); ?></strong>
      <small>Marked deductible</small>
    </div>
  </div>

  <div class="expense-filter-panel">
    <div class="expense-filter-panel__head">
      <strong>Filters</strong>
      <span class="muted text-sm"><?php echo $activeFilterCount; ?> active</span>
    </div>
    <form method="get" action="/" class="expense-filter-grid">
      <input type="hidden" name="page" value="financial/expenses-list">
      <input type="hidden" name="tab" value="expenses">
      <?php if ($recurringExpenseId > 0): ?><input type="hidden" name="recurring_expense_id" value="<?php echo $recurringExpenseId; ?>"><?php endif; ?>
      <label><span class="label-muted">Start</span><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="input"></label>
      <label><span class="label-muted">End</span><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="input"></label>
      <label>
        <span class="label-muted">Category</span>
        <select name="category_id" class="input">
          <option value="0">All categories</option>
          <?php foreach ($categories as $cat): ?><option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryId===(int)$cat['id']?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>
        <span class="label-muted">Vendor</span>
        <select name="vendor_id" class="input">
          <option value="0">All vendors</option>
          <?php foreach ($vendors as $v): ?><option value="<?php echo (int)$v['id']; ?>" <?php echo $vendorId===(int)$v['id']?'selected':''; ?>><?php echo htmlspecialchars($v['name']); ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>
        <span class="label-muted">Client</span>
        <select name="client_id" class="input">
          <option value="0">All clients</option>
          <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $clientId===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>
        <span class="label-muted">Billable</span>
        <select name="billable" class="input">
          <option value="">All</option>
          <option value="1" <?php echo $billable==='1'?'selected':''; ?>>Billable</option>
          <option value="0" <?php echo $billable==='0'?'selected':''; ?>>Non-billable</option>
        </select>
      </label>
      <label>
        <span class="label-muted">Status</span>
        <select name="status" class="input">
          <option value="">Active statuses</option>
          <option value="all" <?php echo $status==='all'?'selected':''; ?>>All statuses including void</option>
          <option value="confirmed" <?php echo $status==='confirmed'?'selected':''; ?>>Confirmed</option>
          <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>Pending</option>
          <option value="reimbursed" <?php echo $status==='reimbursed'?'selected':''; ?>>Reimbursed</option>
          <option value="void" <?php echo $status==='void'?'selected':''; ?>>Void</option>
        </select>
      </label>
      <label>
        <span class="label-muted">Rows</span>
        <select name="per_page" class="input">
          <option value="50" <?php echo $per === 50 ? 'selected' : ''; ?>>50</option>
          <option value="100" <?php echo $per === 100 ? 'selected' : ''; ?>>100</option>
        </select>
      </label>
      <div class="expense-filter-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="/?page=financial/expenses-list&tab=expenses" class="btn">Reset</a>
      </div>
    </form>
  </div>

  <?php if (empty($expenses)): ?>
    <div class="finance-empty">
      <strong>No expenses found</strong>
      <p class="muted">Adjust the filters or add a new expense to start the ledger.</p>
    </div>
  <?php else: ?>
    <div class="pa-table-wrap expense-table-wrap">
      <table class="pa-table expense-table">
        <thead>
          <tr>
            <th>Expense</th>
            <th>Category / Client</th>
            <th>Flags</th>
            <th class="text-right">Amount</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $e):
            $totalAmount = (float)($e['total_amount'] ?? $e['amount']);
            $description = trim((string)($e['description'] ?? ''));
          ?>
          <tr>
            <td>
              <div class="expense-primary">
                <strong><?php echo htmlspecialchars($e['vendor_name'] ?: 'No vendor'); ?></strong>
                <span><?php echo htmlspecialchars(expense_tab_date($e['expense_date'])); ?></span>
                <?php if ($description !== ''): ?><small><?php echo htmlspecialchars(mb_strimwidth($description, 0, 84, '...')); ?></small><?php endif; ?>
              </div>
            </td>
            <td>
              <div class="expense-primary">
                <strong><?php echo htmlspecialchars($e['category_name'] ?: 'Uncategorized'); ?></strong>
                <span><?php echo htmlspecialchars($e['client_name'] ?: 'No client'); ?></span>
              </div>
            </td>
            <td>
              <div class="expense-tags">
                <?php if ((int)$e['is_billable'] === 1): ?><span class="status-pill status-pill--active">Billable</span><?php endif; ?>
                <?php if ((int)$e['is_tax_deductible'] === 1): ?><span class="status-pill status-pill--paid">Deductible</span><?php endif; ?>
                <?php if ((int)$e['is_reconciled'] === 1): ?><span class="status-pill status-pill--completed">Reconciled</span><?php endif; ?>
                <?php if ((int)$e['is_reimbursed'] === 1): ?><span class="status-pill status-pill--reimbursed">Reimbursed</span><?php endif; ?>
                <?php if (!empty($e['recurring_expense_id'])): ?><span class="status-pill status-pill--active">Recurring</span><?php endif; ?>
                <?php if ((int)$e['is_billable'] !== 1 && (int)$e['is_tax_deductible'] !== 1 && (int)$e['is_reconciled'] !== 1 && (int)$e['is_reimbursed'] !== 1): ?><span class="muted text-sm">None</span><?php endif; ?>
              </div>
            </td>
            <td class="text-right">
              <div class="expense-amount">
                <strong><?php echo expense_tab_money($totalAmount); ?></strong>
              </div>
            </td>
            <td><span class="status-pill status-pill--<?php echo expense_tab_status_class($e['status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$e['status'])); ?></span></td>
            <td>
              <div class="expense-row-actions">
                <a href="/?page=financial/expense-detail&id=<?php echo (int)$e['id']; ?>" class="btn btn-sm">View</a>
                <a href="/?page=financial/expense-create&id=<?php echo (int)$e['id']; ?>" class="btn btn-sm">Edit</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination-row">
      <?php if ($pageN > 1): ?>
        <a href="<?php echo htmlspecialchars(expense_tab_page_url($pageN - 1, $paginationFilters)); ?>" class="btn btn-sm">Previous</a>
      <?php endif; ?>
      <span class="muted text-sm">Page <?php echo number_format($pageN); ?> of <?php echo number_format($totalPages); ?></span>
      <?php if ($pageN < $totalPages): ?>
        <a href="<?php echo htmlspecialchars(expense_tab_page_url($pageN + 1, $paginationFilters)); ?>" class="btn btn-sm">Next</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
