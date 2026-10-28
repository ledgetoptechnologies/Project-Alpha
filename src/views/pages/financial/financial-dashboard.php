<?php
// src/views/pages/financial/financial-dashboard.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/payment_accounting.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);

// Date range filter (default: current year)
$defaultStartDate = date('Y') . '-01-01';
$defaultEndDate = date('Y') . '-12-31';
$start = !empty($_GET['start']) ? $_GET['start'] : $defaultStartDate;
$end = !empty($_GET['end']) ? $_GET['end'] : $defaultEndDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $defaultStartDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = $defaultEndDate;

$incomeExpr = payment_accounting_net_income_expr('p');
[$incomeScopeWhere, $incomeScopeParams] = finance_scope_clause($pdo, 'i', $userId, 0, 'created_by');
$incomeStmt = $pdo->prepare("SELECT COALESCE(SUM({$incomeExpr}),0) as total FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id WHERE p.status='succeeded' AND (?=0 OR COALESCE(p.organization_id,i.organization_id,0)=?) AND p.payment_date BETWEEN ? AND ? AND {$incomeScopeWhere}");
$incomeStmt->execute(array_merge([$orgId, $orgId, $start, $end], $incomeScopeParams));
$totalIncome = (float)$incomeStmt->fetchColumn();

[$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');
[$mileageScopeWhere, $mileageScopeParams] = finance_scope_clause($pdo, 'm', $userId, $orgId, 'user_id');
[$receiptScopeWhere, $receiptScopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'uploaded_by');

$expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as total, COUNT(*) as count FROM expenses e WHERE {$expenseScopeWhere} AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?");
$expenseStmt->execute(array_merge($expenseScopeParams, [$start, $end]));
$expenseSummary = $expenseStmt->fetch(PDO::FETCH_ASSOC);
$totalExpenses = (float)$expenseSummary['total'];
$expenseCount = (int)$expenseSummary['count'];
$netProfit = $totalIncome - $totalExpenses;

$mileageStmt = $pdo->prepare("SELECT COALESCE(SUM((SELECT SUM(a.client_charge) FROM mileage_charge_allocations a WHERE a.mileage_log_id=m.id)),0) as total, COALESCE(SUM(COALESCE(m.logged_miles,m.miles * CASE WHEN m.round_trip=1 THEN 2 ELSE 1 END)),0) as miles, COUNT(*) as trips FROM mileage_logs m WHERE {$mileageScopeWhere} AND m.purpose='business' AND m.trip_date BETWEEN ? AND ?");
$mileageStmt->execute(array_merge($mileageScopeParams, [$start, $end]));
$mileageSummary = $mileageStmt->fetch(PDO::FETCH_ASSOC);
$totalClientTravelCharges = (float)($mileageSummary['total'] ?? 0);
$totalMiles = (float)($mileageSummary['miles'] ?? 0);
$totalTrips = (int)($mileageSummary['trips'] ?? 0);

$receiptStmt = $pdo->prepare("SELECT COUNT(*) as count FROM receipts r WHERE {$receiptScopeWhere} AND r.created_at BETWEEN ? AND ?");
$receiptStmt->execute(array_merge($receiptScopeParams, [$start . ' 00:00:00', $end . ' 23:59:59']));
$receiptCount = (int)$receiptStmt->fetchColumn();

$catStmt = $pdo->prepare("
    SELECT ec.name, ec.color, COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as total, COUNT(e.id) as count
    FROM expense_categories ec
    LEFT JOIN expenses e ON e.category_id = ec.id AND {$expenseScopeWhere} AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    GROUP BY ec.id
    HAVING total > 0
    ORDER BY total DESC
");
$catStmt->execute(array_merge($expenseScopeParams, [$start, $end]));
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
$categoryMax = 0;
foreach ($categories as $c) $categoryMax = max($categoryMax, (float)$c['total']);

$vendorStmt = $pdo->prepare("
    SELECT v.name, COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as total, COUNT(e.id) as count
    FROM vendors v
    LEFT JOIN expenses e ON e.vendor_id = v.id AND {$expenseScopeWhere} AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    WHERE v.is_active = 1
    GROUP BY v.id
    HAVING total > 0
    ORDER BY total DESC
    LIMIT 8
");
$vendorStmt->execute(array_merge($expenseScopeParams, [$start, $end]));
$vendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

$recentStmt = $pdo->prepare("
    SELECT e.id, e.expense_date, COALESCE(e.total_amount, e.amount, 0) AS display_total, e.description, e.status, ec.name as category, v.name as vendor
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN vendors v ON v.id = e.vendor_id
    WHERE {$expenseScopeWhere} AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 10
");
$recentStmt->execute(array_merge($expenseScopeParams, [$start, $end]));
$recentExpenses = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$statusStmt = $pdo->prepare("
    SELECT status, COUNT(*) as count, COALESCE(SUM(COALESCE(total_amount, amount, 0)),0) as total
    FROM expenses e
    WHERE {$expenseScopeWhere} AND e.expense_date BETWEEN ? AND ?
    GROUP BY status
    ORDER BY total DESC
");
$statusStmt->execute(array_merge($expenseScopeParams, [$start, $end]));
$statusSummary = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

$incomeTrendStmt = $pdo->prepare("
    SELECT DATE_FORMAT(p.payment_date, '%Y-%m') as period, COALESCE(SUM({$incomeExpr}),0) as total
    FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id
    WHERE p.status='succeeded' AND (?=0 OR COALESCE(p.organization_id,i.organization_id,0)=?) AND p.payment_date BETWEEN ? AND ? AND {$incomeScopeWhere}
    GROUP BY period
");
$incomeTrendStmt->execute(array_merge([$orgId, $orgId, $start, $end], $incomeScopeParams));
$incomeByMonth = [];
foreach ($incomeTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $incomeByMonth[$row['period']] = (float)$row['total'];
}

$expenseTrendStmt = $pdo->prepare("
    SELECT DATE_FORMAT(e.expense_date, '%Y-%m') as period, COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) as total
    FROM expenses e
    WHERE {$expenseScopeWhere} AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    GROUP BY period
");
$expenseTrendStmt->execute(array_merge($expenseScopeParams, [$start, $end]));
$expensesByMonth = [];
foreach ($expenseTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $expensesByMonth[$row['period']] = (float)$row['total'];
}

$trendMonths = [];
$cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
$endMonth = new DateTimeImmutable(substr($end, 0, 7) . '-01');
while ($cursor <= $endMonth) {
    $trendMonths[] = $cursor->format('Y-m');
    $cursor = $cursor->modify('+1 month');
}
if (count($trendMonths) > 12) {
    $trendMonths = array_slice($trendMonths, -12);
}
$trendMax = 1.0;
foreach ($trendMonths as $month) {
    $trendMax = max($trendMax, $incomeByMonth[$month] ?? 0, $expensesByMonth[$month] ?? 0);
}

if (!function_exists('finance_dashboard_money')) {
    function finance_dashboard_money(float $amount): string { return '$' . number_format($amount, 2); }
}
if (!function_exists('finance_dashboard_date')) {
    function finance_dashboard_date(?string $date): string { return $date ? date('M j, Y', strtotime($date)) : '-'; }
}
if (!function_exists('finance_dashboard_status_class')) {
    function finance_dashboard_status_class(?string $status): string {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$status)) ?: 'pending';
    }
}

$netClass = $netProfit >= 0 ? 'success' : 'danger';
$profitMargin = $totalIncome > 0 ? ($netProfit / $totalIncome) * 100 : 0;
$expenseRatio = $totalIncome > 0 ? ($totalExpenses / $totalIncome) * 100 : 0;
$avgExpense = $expenseCount > 0 ? $totalExpenses / $expenseCount : 0;
?>

<section class="finance-dashboard">
  <div class="finance-page-head">
    <div>
      <p class="finance-eyebrow">Financial workspace</p>
      <h2>Financial Dashboard</h2>
      <p class="finance-subtitle"><?php echo finance_dashboard_date($start); ?> to <?php echo finance_dashboard_date($end); ?></p>
    </div>
    <div class="finance-actions">
      <a href="/?page=financial/expenses-list" class="btn btn-primary">Assets &amp; Expenses</a>
      <a href="/?page=financial/asset-form" class="btn">Add Asset</a>
      <a href="/?page=financial/expense-create" class="btn">Add Expense</a>
      <a href="/?page=financial/expense-report" class="btn">Reports</a>
    </div>
  </div>

  <div class="finance-toolbar">
    <form method="get" action="/" class="finance-filter">
      <input type="hidden" name="page" value="financial/financial-dashboard">
      <label><span class="label-muted">Start</span><input type="date" name="start" class="input" value="<?php echo htmlspecialchars($start); ?>"></label>
      <label><span class="label-muted">End</span><input type="date" name="end" class="input" value="<?php echo htmlspecialchars($end); ?>"></label>
      <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="/?page=financial/financial-dashboard" class="btn">Reset</a>
      </div>
    </form>
  </div>

  <div class="finance-kpis">
    <article class="finance-kpi">
      <div class="finance-kpi__icon success">$</div>
      <div>
        <div class="finance-kpi__label">Income</div>
        <div class="finance-kpi__value"><?php echo finance_dashboard_money($totalIncome); ?></div>
        <div class="finance-kpi__meta">Collected payments</div>
      </div>
    </article>
    <article class="finance-kpi">
      <div class="finance-kpi__icon danger">-</div>
      <div>
        <div class="finance-kpi__label">Expenses</div>
        <div class="finance-kpi__value"><?php echo finance_dashboard_money($totalExpenses); ?></div>
        <div class="finance-kpi__meta"><?php echo number_format($expenseCount); ?> items, avg <?php echo finance_dashboard_money($avgExpense); ?></div>
      </div>
    </article>
    <article class="finance-kpi">
      <div class="finance-kpi__icon <?php echo $netClass; ?>">=</div>
      <div>
        <div class="finance-kpi__label">Net Profit</div>
        <div class="finance-kpi__value <?php echo $netClass; ?>"><?php echo finance_dashboard_money($netProfit); ?></div>
        <div class="finance-kpi__meta"><?php echo number_format($profitMargin, 1); ?>% margin</div>
      </div>
    </article>
    <article class="finance-kpi">
      <div class="finance-kpi__icon info">mi</div>
      <div>
        <div class="finance-kpi__label">Client Travel Charges</div>
        <div class="finance-kpi__value"><?php echo finance_dashboard_money($totalClientTravelCharges); ?></div>
        <div class="finance-kpi__meta"><?php echo number_format($totalMiles, 1); ?> mi / <?php echo number_format($totalTrips); ?> trips</div>
      </div>
    </article>
    <article class="finance-kpi">
      <div class="finance-kpi__icon warning">rc</div>
      <div>
        <div class="finance-kpi__label">Receipts</div>
        <div class="finance-kpi__value"><?php echo number_format($receiptCount); ?></div>
        <div class="finance-kpi__meta">Uploaded in range</div>
      </div>
    </article>
  </div>

  <div class="finance-grid finance-grid--main">
    <div class="finance-panel finance-panel--wide">
      <div class="finance-panel__head">
        <div>
          <h3 class="finance-panel__title">Income vs. Expenses</h3>
          <p class="finance-panel__meta">Last <?php echo count($trendMonths); ?> month<?php echo count($trendMonths) === 1 ? '' : 's'; ?> in selected range</p>
        </div>
      </div>
      <?php if (empty($trendMonths)): ?>
        <p class="dash-empty">No trend data for the selected period.</p>
      <?php else: ?>
        <div class="finance-trend" aria-label="Income and expense trend">
          <?php foreach ($trendMonths as $month):
            $incomeValue = $incomeByMonth[$month] ?? 0;
            $expenseValue = $expensesByMonth[$month] ?? 0;
            $incomeHeight = max(2, round(($incomeValue / $trendMax) * 100));
            $expenseHeight = max(2, round(($expenseValue / $trendMax) * 100));
          ?>
            <div class="finance-trend__month">
              <div class="finance-trend__bars">
                <span class="finance-trend__bar income" style="height:<?php echo $incomeHeight; ?>%" title="Income <?php echo finance_dashboard_money($incomeValue); ?>"></span>
                <span class="finance-trend__bar expense" style="height:<?php echo $expenseHeight; ?>%" title="Expenses <?php echo finance_dashboard_money($expenseValue); ?>"></span>
              </div>
              <span class="finance-trend__label"><?php echo htmlspecialchars(date('M', strtotime($month . '-01'))); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="finance-legend"><span class="income"></span> Income <span class="expense"></span> Expenses</div>
      <?php endif; ?>
    </div>

    <div class="finance-panel">
      <div class="finance-panel__head">
        <h3 class="finance-panel__title">Operating Ratio</h3>
      </div>
      <div class="finance-meter">
        <div class="finance-meter__label"><span>Expense ratio</span><strong><?php echo number_format($expenseRatio, 1); ?>%</strong></div>
        <div class="finance-meter__track"><span style="width:<?php echo min(100, max(0, round($expenseRatio))); ?>%"></span></div>
      </div>
      <?php if (empty($statusSummary)): ?>
        <p class="dash-empty">No expense statuses in range.</p>
      <?php else: ?>
        <div class="finance-status-list">
          <?php foreach ($statusSummary as $status): ?>
            <div class="finance-status-list__item">
              <span class="status-pill status-pill--<?php echo finance_dashboard_status_class($status['status']); ?>"><?php echo htmlspecialchars(ucfirst($status['status'])); ?></span>
              <strong><?php echo finance_dashboard_money((float)$status['total']); ?></strong>
              <span class="muted text-sm"><?php echo number_format((int)$status['count']); ?> item<?php echo (int)$status['count'] === 1 ? '' : 's'; ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="finance-grid finance-grid--details">
    <div class="finance-panel finance-panel--wide">
      <div class="finance-panel__head">
        <h3 class="finance-panel__title">Spending by Category</h3>
        <a href="/?page=financial/expenses-list&tab=categories" class="btn btn-sm">Manage Categories</a>
      </div>
      <?php if (empty($categories)): ?>
        <p class="dash-empty">No expenses for the selected period.</p>
      <?php else: ?>
        <div class="finance-bars">
          <?php foreach ($categories as $c):
            $catTotal = (float)$c['total'];
            $catPercent = $totalExpenses > 0 ? round(($catTotal / $totalExpenses) * 100, 1) : 0;
            $barWidth = $categoryMax > 0 ? round(($catTotal / $categoryMax) * 100) : 0;
            $barColor = !empty($c['color']) ? htmlspecialchars($c['color']) : 'var(--nav-accent)';
          ?>
            <div class="finance-bar">
              <div class="finance-bar__top">
                <strong title="<?php echo htmlspecialchars($c['name']); ?>"><?php echo htmlspecialchars($c['name']); ?></strong>
                <span><?php echo finance_dashboard_money($catTotal); ?> / <?php echo $catPercent; ?>%</span>
              </div>
              <div class="finance-bar__track"><div class="finance-bar__fill" style="width:<?php echo $barWidth; ?>%;background:<?php echo $barColor; ?>"></div></div>
              <div class="finance-bar__meta"><?php echo number_format((int)$c['count']); ?> expense<?php echo (int)$c['count'] === 1 ? '' : 's'; ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="finance-panel">
      <div class="finance-panel__head">
        <h3 class="finance-panel__title">Top Vendors</h3>
        <a href="/?page=financial/expenses-list&tab=vendors" class="btn btn-sm">Manage</a>
      </div>
      <?php if (empty($vendors)): ?>
        <p class="dash-empty">No vendor spending for the selected period.</p>
      <?php else: ?>
        <div class="finance-list">
          <?php foreach ($vendors as $v): ?>
            <div class="finance-list__item">
              <div>
                <strong><?php echo htmlspecialchars($v['name']); ?></strong>
                <span><?php echo number_format((int)$v['count']); ?> expense<?php echo (int)$v['count'] === 1 ? '' : 's'; ?></span>
              </div>
              <b><?php echo finance_dashboard_money((float)$v['total']); ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="finance-panel">
    <div class="finance-panel__head">
      <h3 class="finance-panel__title">Recent Expenses</h3>
      <a href="/?page=financial/expenses-list&tab=expenses" class="btn btn-sm">View All</a>
    </div>
    <?php if (empty($recentExpenses)): ?>
      <p class="dash-empty">No expenses for the selected period.</p>
    <?php else: ?>
      <div class="pa-table-wrap">
        <table class="pa-table">
          <thead><tr><th>Date</th><th>Vendor / Description</th><th>Category</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($recentExpenses as $e): ?>
              <tr>
                <td><?php echo finance_dashboard_date($e['expense_date']); ?></td>
                <td><strong><?php echo htmlspecialchars($e['vendor'] ?: '-'); ?></strong><div class="muted text-sm"><?php echo htmlspecialchars(mb_strimwidth($e['description'] ?? '', 0, 60, '...')); ?></div></td>
                <td><?php echo htmlspecialchars($e['category'] ?: 'Uncategorized'); ?></td>
                <td class="text-right"><?php echo finance_dashboard_money((float)$e['display_total']); ?></td>
                <td><span class="status-badge status-<?php echo finance_dashboard_status_class($e['status']); ?>"><?php echo htmlspecialchars(ucfirst($e['status'])); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
