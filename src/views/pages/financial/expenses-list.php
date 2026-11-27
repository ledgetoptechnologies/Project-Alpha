<?php
// src/views/pages/financial/expenses-list.php
// Unified assets and expenses hub: Assets, Expenses, Receipts, Mileage, Vendors, Categories, Audit

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$csrfToken = csrf_sf_token('expense');

$tabs = [
    'overview'   => ['label' => 'Overview',   'file' => '_overview_tab.php',   'hint' => 'Assets and expense snapshot'],
    'assets'     => ['label' => 'Assets',     'file' => '_assets_tab.php',     'hint' => 'Equipment and depreciation'],
    'expenses'   => ['label' => 'Expenses',   'file' => '_expenses_tab.php',   'hint' => 'Spending ledger'],
    'mileage'    => ['label' => 'Mileage',    'file' => 'mileage-list.php',    'hint' => 'Business trips'],
    'recurring'  => ['label' => 'Recurring',  'file' => '_recurring_expenses_tab.php', 'hint' => 'Expense schedules'],
    'receipts'   => ['label' => 'Receipts',   'file' => 'receipts-list.php',   'hint' => 'Expense documents'],
    'vendors'    => ['label' => 'Vendors',    'file' => 'vendors-list.php',    'hint' => 'Suppliers'],
    'categories' => ['label' => 'Categories', 'file' => 'categories-list.php', 'hint' => 'Tax buckets'],
    'audit'      => ['label' => 'Audit',      'file' => 'audit.php',           'hint' => 'Export reviews'],
];

// The Audit page needs financial.audit on its own route; the tab must not bypass that.
$canAudit = ($_SESSION['user']['role'] ?? '') === 'admin' || user_can($pdo, $userId, 'financial.audit', 0);
if (!$canAudit) {
    unset($tabs['audit']);
}

$active = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$active])) $active = 'overview';

$stats = [
    'assets' => 0,
    'expenses' => 0,
    'recurring' => 0,
    'receipts' => 0,
    'mileage' => 0,
    'vendors' => 0,
    'categories' => 0,
    'audit' => 0,
];

try {
    [$assetScopeWhere, $assetScopeParams] = finance_scope_clause($pdo, 'a', $userId, $orgId, 'created_by');
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM financial_assets a WHERE {$assetScopeWhere} AND a.status NOT IN ('disposed','lost')");
    $countStmt->execute($assetScopeParams);
    $stats['assets'] = (int)$countStmt->fetchColumn();

    [$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e WHERE {$expenseScopeWhere} AND e.status != 'void'");
    $countStmt->execute($expenseScopeParams);
    $stats['expenses'] = (int)$countStmt->fetchColumn();

    [$recurringScopeWhere, $recurringScopeParams] = finance_scope_clause($pdo, 're', $userId, $orgId, 'created_by');
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM recurring_expenses re WHERE {$recurringScopeWhere} AND re.status='active'");
    $countStmt->execute($recurringScopeParams);
    $stats['recurring'] = (int)$countStmt->fetchColumn();

    [$receiptScopeWhere, $receiptScopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'uploaded_by');
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM receipts r WHERE {$receiptScopeWhere}");
    $countStmt->execute($receiptScopeParams);
    $stats['receipts'] = (int)$countStmt->fetchColumn();

    [$mileageScopeWhere, $mileageScopeParams] = finance_scope_clause($pdo, 'm', $userId, $orgId, 'user_id');
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mileage_logs m WHERE {$mileageScopeWhere}");
    $countStmt->execute($mileageScopeParams);
    $stats['mileage'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM vendors WHERE is_active=1");
    $countStmt->execute();
    $stats['vendors'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expense_categories");
    $countStmt->execute();
    $stats['categories'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_schedules WHERE (?=0 OR organization_id=?)');
    $countStmt->execute([$orgId, $orgId]);
    $stats['audit'] = (int)$countStmt->fetchColumn();
} catch (Throwable $error) {
    error_log('Financial hub summary failed: ' . $error->getMessage());
}
?>

<div class="expenses-hub">
  <div class="finance-page-head expenses-hub__head">
    <div>
      <p class="finance-eyebrow">Financial workspace</p>
      <h2>Assets &amp; Expenses</h2>
      <p class="finance-subtitle">Track owned assets, depreciation, spending, receipts, mileage, vendors, categories, and audit exports from one place.</p>
    </div>
  </div>

  <div class="expenses-hub__tabs" role="tablist" aria-label="Expenses sections">
    <?php foreach ($tabs as $id => $t): ?>
      <a href="/?page=financial/expenses-list&tab=<?php echo htmlspecialchars($id); ?>"
         class="expenses-hub__tab <?php echo $active === $id ? 'active' : ''; ?>"
         data-tab="<?php echo htmlspecialchars($id); ?>"
         role="tab"
         aria-selected="<?php echo $active === $id ? 'true' : 'false'; ?>">
        <span><?php echo htmlspecialchars($t['label']); ?></span>
        <small><?php echo htmlspecialchars($t['hint']); ?></small>
        <?php if ($id !== 'overview'): ?><b><?php echo number_format($stats[$id] ?? 0); ?></b><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="expenses-hub__panel active" id="tab-<?php echo htmlspecialchars($active); ?>" role="tabpanel">
    <?php
    $tabBufferLevel = ob_get_level();
    ob_start();
    try {
        include __DIR__ . '/' . $tabs[$active]['file'];
        echo ob_get_clean();
    } catch (Throwable $error) {
        while (ob_get_level() > $tabBufferLevel) {
            ob_end_clean();
        }
        error_log(sprintf('Financial hub tab "%s" failed: %s', $active, $error->getMessage()));
        http_response_code(500);
        ?>
        <div class="alert alert-danger" role="alert">
          <strong>This financial section could not be loaded.</strong>
          <span>Please try again. If the problem continues, an administrator can review the application log for the <?php echo htmlspecialchars($tabs[$active]['label']); ?> section.</span>
        </div>
        <?php
    }
    ?>
  </div>
</div>
