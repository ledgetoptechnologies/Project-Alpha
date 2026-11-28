<?php
// src/views/pages/financial/asset-detail.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/financial_assets.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=financial/assets-list');
    exit;
}

[$assetScopeWhere, $assetScopeParams] = finance_scope_clause($pdo, 'a', $userId, $orgId, 'created_by');
$stmt = $pdo->prepare('
    SELECT a.*, v.name AS vendor_name, ec.name AS category_name,
           e.expense_date AS linked_expense_date, e.total_amount AS linked_expense_total, e.amount AS linked_expense_amount,
           e.description AS linked_expense_description
    FROM financial_assets a
    LEFT JOIN vendors v ON v.id = a.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = a.category_id
    LEFT JOIN expenses e ON e.id = a.expense_id
    WHERE a.id = ? AND ' . $assetScopeWhere . '
');
$stmt->execute(array_merge([$id], $assetScopeParams));
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) {
    header('Location: /?page=financial/assets-list');
    exit;
}

$depreciation = financial_asset_depreciation($asset);
$statusClass = financial_asset_status_class($asset['status']);
$linkedExpenseTotal = (float)($asset['linked_expense_total'] ?? $asset['linked_expense_amount'] ?? 0);
?>

<div class="asset-detail-page">
  <div class="page-head">
    <div>
      <h2><?php echo htmlspecialchars($asset['name']); ?></h2>
      <p class="muted" style="margin:4px 0 0">
        <?php echo htmlspecialchars($asset['asset_tag'] ?: 'No asset tag'); ?>
        <?php if (!empty($asset['asset_type'])): ?> / <?php echo htmlspecialchars($asset['asset_type']); ?><?php endif; ?>
      </p>
    </div>
    <div class="finance-actions">
      <a href="/?page=financial/asset-form&id=<?php echo $id; ?>" class="btn btn-primary">Edit Asset</a>
      <a href="/?page=financial/assets-list" class="btn">Back to Assets</a>
    </div>
  </div>

  <?php if (!empty($_GET['created'])): ?><div class="alert alert-success">Asset created.</div><?php endif; ?>
  <?php if (!empty($_GET['updated'])): ?><div class="alert alert-success">Asset updated.</div><?php endif; ?>

  <div class="expense-summary asset-summary">
    <div class="expense-stat">
      <span>Purchase Cost</span>
      <strong><?php echo financial_asset_money((float)$asset['purchase_cost']); ?></strong>
      <small><?php echo htmlspecialchars(financial_asset_date($asset['purchase_date'])); ?></small>
    </div>
    <div class="expense-stat">
      <span>Book Value</span>
      <strong><?php echo financial_asset_money($depreciation['book_value']); ?></strong>
      <small>Calculated as of today</small>
    </div>
    <div class="expense-stat">
      <span>Accumulated Depreciation</span>
      <strong><?php echo financial_asset_money($depreciation['accumulated']); ?></strong>
      <small><?php echo number_format((int)$depreciation['elapsed_months']); ?> months elapsed</small>
    </div>
    <div class="expense-stat">
      <span>Status</span>
      <strong><span class="status-pill status-pill--<?php echo $statusClass; ?>"><?php echo htmlspecialchars(financial_asset_status_label($asset['status'])); ?></span></strong>
      <small><?php echo htmlspecialchars($asset['location'] ?: 'No location'); ?></small>
    </div>
  </div>

  <div class="finance-grid finance-grid--details asset-detail-grid">
    <section class="finance-panel">
      <div class="finance-panel__head">
        <div>
          <h3 class="finance-panel__title">Asset Details</h3>
          <p class="finance-panel__meta">Core identifying and purchase information.</p>
        </div>
      </div>
      <div class="asset-detail-list">
        <div><span>Asset tag</span><strong><?php echo htmlspecialchars($asset['asset_tag'] ?: '-'); ?></strong></div>
        <div><span>Type</span><strong><?php echo htmlspecialchars($asset['asset_type'] ?: '-'); ?></strong></div>
        <div><span>Serial number</span><strong><?php echo htmlspecialchars($asset['serial_number'] ?: '-'); ?></strong></div>
        <div><span>Location</span><strong><?php echo htmlspecialchars($asset['location'] ?: '-'); ?></strong></div>
        <div><span>Vendor</span><strong><?php echo htmlspecialchars($asset['vendor_name'] ?: '-'); ?></strong></div>
        <div><span>Category</span><strong><?php echo htmlspecialchars($asset['category_name'] ?: '-'); ?></strong></div>
        <div><span>Warranty expires</span><strong><?php echo htmlspecialchars(financial_asset_date($asset['warranty_expires_on'])); ?></strong></div>
      </div>
    </section>

    <aside class="finance-panel">
      <div class="finance-panel__head">
        <div>
          <h3 class="finance-panel__title">Depreciation</h3>
          <p class="finance-panel__meta">Current straight-line estimate.</p>
        </div>
      </div>
      <div class="asset-detail-list">
        <div><span>Method</span><strong><?php echo htmlspecialchars($asset['depreciation_method'] === 'none' ? 'Do not depreciate' : 'Straight-line'); ?></strong></div>
        <div><span>Start date</span><strong><?php echo htmlspecialchars(financial_asset_date($asset['depreciation_start_date'] ?: $asset['purchase_date'])); ?></strong></div>
        <div><span>Useful life</span><strong><?php echo $asset['useful_life_months'] ? number_format((int)$asset['useful_life_months']) . ' months' : '-'; ?></strong></div>
        <div><span>Salvage value</span><strong><?php echo financial_asset_money((float)$asset['salvage_value']); ?></strong></div>
        <div><span>Monthly depreciation</span><strong><?php echo financial_asset_money($depreciation['monthly']); ?></strong></div>
        <div><span>Depreciable basis</span><strong><?php echo financial_asset_money($depreciation['depreciable_basis']); ?></strong></div>
      </div>
    </aside>
  </div>

  <div class="finance-grid finance-grid--details asset-detail-grid">
    <section class="finance-panel">
      <div class="finance-panel__head">
        <div>
          <h3 class="finance-panel__title">Lifecycle</h3>
          <p class="finance-panel__meta">Status, disposal, and notes.</p>
        </div>
      </div>
      <div class="asset-detail-list">
        <div><span>Status</span><strong><?php echo htmlspecialchars(financial_asset_status_label($asset['status'])); ?></strong></div>
        <div><span>Disposed on</span><strong><?php echo htmlspecialchars(financial_asset_date($asset['disposed_on'])); ?></strong></div>
        <div><span>Disposal value</span><strong><?php echo $asset['disposal_value'] !== null ? financial_asset_money((float)$asset['disposal_value']) : '-'; ?></strong></div>
        <div><span>Created</span><strong><?php echo htmlspecialchars(financial_asset_date($asset['created_at'])); ?></strong></div>
      </div>
      <?php if (!empty($asset['notes'])): ?>
        <div class="asset-notes">
          <span class="label-muted">Notes</span>
          <p><?php echo nl2br(htmlspecialchars($asset['notes'])); ?></p>
        </div>
      <?php endif; ?>
    </section>

    <aside class="finance-panel">
      <div class="finance-panel__head">
        <div>
          <h3 class="finance-panel__title">Linked Expense</h3>
          <p class="finance-panel__meta">Optional source expense record.</p>
        </div>
      </div>
      <?php if (!empty($asset['expense_id'])): ?>
        <div class="asset-linked-expense">
          <strong><?php echo financial_asset_money($linkedExpenseTotal); ?></strong>
          <span><?php echo htmlspecialchars(financial_asset_date($asset['linked_expense_date'])); ?></span>
          <p><?php echo htmlspecialchars($asset['linked_expense_description'] ?: 'No description'); ?></p>
          <a href="/?page=financial/expense-detail&id=<?php echo (int)$asset['expense_id']; ?>" class="btn btn-sm">View Expense</a>
        </div>
      <?php else: ?>
        <div class="finance-empty" style="padding:28px 16px">
          <strong>No linked expense</strong>
          <p class="muted">Edit the asset to connect a purchase expense later.</p>
        </div>
      <?php endif; ?>

      <?php if (!in_array((string)$asset['status'], ['disposed', 'lost'], true)): ?>
        <form id="assetDisposeForm" method="post" action="/?page=financial/asset-handler" class="asset-dispose-form" onsubmit="return confirm('Mark this asset as disposed?')">
          <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('asset')); ?>">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          <button type="submit" class="btn btn-sm btn-danger">Mark Disposed</button>
        </form>
      <?php endif; ?>
    </aside>
  </div>
</div>
