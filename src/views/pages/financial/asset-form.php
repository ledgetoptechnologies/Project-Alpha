<?php
// src/views/pages/financial/asset-form.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$editId = (int)($_GET['id'] ?? 0);

$asset = null;
if ($editId > 0) {
    [$assetScopeWhere, $assetScopeParams] = finance_scope_clause($pdo, 'a', (int)($_SESSION['user']['id'] ?? 0), $orgId, 'created_by');
    $stmt = $pdo->prepare('
        SELECT a.*, v.name AS vendor_name
        FROM financial_assets a
        LEFT JOIN vendors v ON v.id = a.vendor_id
        WHERE a.id = ? AND ' . $assetScopeWhere . '
    ');
    $stmt->execute(array_merge([$editId], $assetScopeParams));
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        header('Location: /?page=financial/assets-list');
        exit;
    }
}

$vendorsQ = $pdo->prepare('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name');
$vendorsQ->execute();
$vendors = $vendorsQ->fetchAll(PDO::FETCH_ASSOC);

$categoriesQ = $pdo->prepare('SELECT id, name FROM expense_categories ORDER BY name');
$categoriesQ->execute();
$categories = $categoriesQ->fetchAll(PDO::FETCH_ASSOC);

[$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', (int)($_SESSION['user']['id'] ?? 0), $orgId, 'created_by');
$expensesQ = $pdo->prepare('
    SELECT e.id, e.vendor_id, e.category_id, e.expense_date, e.total_amount, e.amount, e.description, v.name AS vendor_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    WHERE ' . $expenseScopeWhere . ' AND e.status != "void"
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 200
');
$expensesQ->execute($expenseScopeParams);
$expenses = $expensesQ->fetchAll(PDO::FETCH_ASSOC);

$typesQ = $pdo->prepare('SELECT DISTINCT asset_type FROM financial_assets WHERE asset_type IS NOT NULL AND asset_type <> "" ORDER BY asset_type');
$typesQ->execute();
$existingTypes = $typesQ->fetchAll(PDO::FETCH_COLUMN);
$commonTypes = ['Computer', 'Vehicle', 'Tool', 'Equipment', 'Furniture', 'Phone', 'Software', 'Building Improvement'];
$assetTypes = array_values(array_unique(array_merge($commonTypes, array_map('strval', $existingTypes))));

$statusOptions = ['planned' => 'Planned', 'active' => 'Active', 'maintenance' => 'Maintenance', 'retired' => 'Retired', 'sold' => 'Sold', 'lost' => 'Lost', 'disposed' => 'Disposed'];
$methodOptions = ['none' => 'Do not depreciate', 'straight_line' => 'Straight-line'];

$value = static function (string $key, string $default = '') use ($asset): string {
    return htmlspecialchars((string)($asset[$key] ?? $default));
};
$selected = static function (string $key, string $value, string $default = '') use ($asset): string {
    return (string)($asset[$key] ?? $default) === $value ? 'selected' : '';
};
?>

<div class="asset-form-page">
  <div class="page-head">
    <div>
      <h2><?php echo $editId > 0 ? 'Edit Asset' : 'Add Asset'; ?></h2>
      <p class="muted" style="margin:4px 0 0">Track purchase details, status, warranty, and depreciation.</p>
    </div>
    <a href="/?page=financial/assets-list" class="btn btn-sm">Back to Assets</a>
  </div>

  <form id="assetForm" method="post" action="/?page=financial/asset-handler" class="asset-form-grid">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('asset')); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="<?php echo $editId > 0 ? 'update' : 'create'; ?>">
  <?php if ($editId > 0): ?><input type="hidden" name="id" value="<?php echo $editId; ?>"><?php endif; ?>
  <input type="hidden" name="vendor_id" id="assetVendorId" value="<?php echo (int)($asset['vendor_id'] ?? 0); ?>">

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

    <section class="card asset-form-section">
      <div class="card-head">
        <h3 class="card-title">Identity</h3>
      </div>
      <div class="grid grid-2">
        <div class="field">
          <label class="label">Asset Name *</label>
          <input type="text" name="name" required class="input" value="<?php echo $value('name'); ?>" placeholder="2019 Ford Transit, Dell Laptop, Generator">
        </div>
        <div class="field">
          <label class="label">Asset Tag</label>
          <input type="text" name="asset_tag" class="input" value="<?php echo $value('asset_tag'); ?>" placeholder="ASSET-001">
        </div>
      </div>
      <div class="grid grid-3">
        <div class="field">
          <label class="label">Type</label>
          <input type="text" name="asset_type" list="assetTypeList" class="input" value="<?php echo $value('asset_type'); ?>" placeholder="Equipment">
          <datalist id="assetTypeList">
            <?php foreach ($assetTypes as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"></option><?php endforeach; ?>
          </datalist>
        </div>
        <div class="field">
          <label class="label">Serial Number</label>
          <input type="text" name="serial_number" class="input" value="<?php echo $value('serial_number'); ?>" placeholder="Serial, VIN, model code">
        </div>
        <div class="field">
          <label class="label">Location</label>
          <input type="text" name="location" class="input" value="<?php echo $value('location'); ?>" placeholder="Shop, vehicle, client site">
        </div>
      </div>
    </section>

    <section class="card asset-form-section">
      <div class="card-head">
        <h3 class="card-title">Purchase</h3>
      </div>
      <div class="field">
        <label class="label">Linked Expense</label>
        <select name="expense_id" id="assetExpenseId" class="input">
          <option value="0">No linked expense</option>
          <option value="new">Make new expense from this asset</option>
          <?php foreach ($expenses as $expense):
            $amount = (float)($expense['total_amount'] ?? $expense['amount']);
            $label = trim(($expense['vendor_name'] ?: 'No vendor') . ' / ' . ($expense['expense_date'] ?: '-') . ' / $' . number_format($amount, 2) . ' / ' . mb_strimwidth((string)($expense['description'] ?? ''), 0, 60, '...'));
          ?>
            <option
              value="<?php echo (int)$expense['id']; ?>"
              data-vendor-id="<?php echo (int)($expense['vendor_id'] ?? 0); ?>"
              data-vendor-name="<?php echo htmlspecialchars((string)($expense['vendor_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
              data-category-id="<?php echo (int)($expense['category_id'] ?? 0); ?>"
              data-expense-date="<?php echo htmlspecialchars((string)($expense['expense_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
              data-amount="<?php echo htmlspecialchars(number_format($amount, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
              <?php echo (int)($asset['expense_id'] ?? 0) === (int)$expense['id'] ? 'selected' : ''; ?>
            ><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted text-sm" style="margin:6px 0 0">Choose an existing expense to prefill purchase details, or create a matching expense when the asset is saved.</p>
      </div>
      <div class="grid grid-2">
        <div class="field">
          <label class="label">Vendor</label>
          <input type="text" name="vendor_name" id="assetVendorName" list="assetVendorList" class="input" value="<?php echo htmlspecialchars((string)($asset['vendor_name'] ?? '')); ?>" placeholder="Start typing or select vendor">
          <datalist id="assetVendorList">
            <?php foreach ($vendors as $vendor): ?><option value="<?php echo htmlspecialchars($vendor['name']); ?>" data-id="<?php echo (int)$vendor['id']; ?>"></option><?php endforeach; ?>
          </datalist>
        </div>
        <div class="field">
          <label class="label">Expense Category</label>
          <select name="category_id" id="assetCategoryId" class="input">
            <option value="0">No category</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?php echo (int)$category['id']; ?>" <?php echo (int)($asset['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid grid-3">
        <div class="field">
          <label class="label">Purchase Date</label>
          <input type="date" name="purchase_date" id="assetPurchaseDate" class="input" value="<?php echo $value('purchase_date', date('Y-m-d')); ?>">
        </div>
        <div class="field">
          <label class="label">Purchase Cost</label>
          <input type="number" name="purchase_cost" id="assetPurchaseCost" step="0.01" min="0" class="input" value="<?php echo $value('purchase_cost', '0.00'); ?>">
        </div>
        <div class="field">
          <label class="label">Warranty Expires</label>
          <input type="date" name="warranty_expires_on" class="input" value="<?php echo $value('warranty_expires_on'); ?>">
        </div>
      </div>
      <div class="field">
        <input type="hidden" name="create_expense_from_asset" id="assetCreateExpenseFlag" value="0">
      </div>
    </section>

    <section class="card asset-form-section">
      <div class="card-head">
        <h3 class="card-title">Depreciation</h3>
      </div>
      <div class="grid grid-2">
        <div class="field">
          <label class="label">Method</label>
          <select name="depreciation_method" id="assetDepreciationMethod" class="input">
            <?php foreach ($methodOptions as $method => $label): ?>
              <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $selected('depreciation_method', $method, 'none'); ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">Depreciation Start</label>
          <input type="date" name="depreciation_start_date" class="input" value="<?php echo $value('depreciation_start_date'); ?>">
        </div>
      </div>
      <div class="grid grid-3">
        <div class="field">
          <label class="label">Useful Life (months)</label>
          <input type="number" name="useful_life_months" id="assetUsefulLife" min="1" step="1" class="input" value="<?php echo $value('useful_life_months'); ?>">
        </div>
        <div class="field">
          <label class="label">Salvage Value</label>
          <input type="number" name="salvage_value" id="assetSalvageValue" step="0.01" min="0" class="input" value="<?php echo $value('salvage_value', '0.00'); ?>">
        </div>
        <div class="field">
          <label class="label">Estimated Monthly</label>
          <input type="text" id="assetMonthlyEstimate" readonly class="input" style="background:var(--surface-2);font-weight:600">
        </div>
      </div>
    </section>

    <section class="card asset-form-section">
      <div class="card-head">
        <h3 class="card-title">Lifecycle</h3>
      </div>
      <div class="grid grid-3">
        <div class="field">
          <label class="label">Status</label>
          <select name="status" class="input">
            <?php foreach ($statusOptions as $status => $label): ?>
              <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected('status', $status, 'active'); ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">Disposed On</label>
          <input type="date" name="disposed_on" class="input" value="<?php echo $value('disposed_on'); ?>">
        </div>
        <div class="field">
          <label class="label">Disposal Value</label>
          <input type="number" name="disposal_value" step="0.01" min="0" class="input" value="<?php echo $value('disposal_value'); ?>">
        </div>
      </div>
      <div class="field">
        <label class="label">Notes</label>
        <textarea name="notes" rows="4" class="input" placeholder="Maintenance notes, condition, warranty account, insurance details"><?php echo $value('notes'); ?></textarea>
      </div>
    </section>

    <div class="asset-form-actions">
      <a href="/?page=financial/assets-list" class="btn">Cancel</a>
      <button type="submit" class="btn btn-primary"><?php echo $editId > 0 ? 'Update Asset' : 'Create Asset'; ?></button>
    </div>
  </form>
</div>

<script>
(function() {
  const form = document.getElementById('assetForm');
  const vendorName = document.getElementById('assetVendorName');
  const vendorId = document.getElementById('assetVendorId');
  const expenseSelect = document.getElementById('assetExpenseId');
  const createExpenseFlag = document.getElementById('assetCreateExpenseFlag');
  const category = document.getElementById('assetCategoryId');
  const purchaseDate = document.getElementById('assetPurchaseDate');
  const method = document.getElementById('assetDepreciationMethod');
  const cost = document.getElementById('assetPurchaseCost');
  const life = document.getElementById('assetUsefulLife');
  const salvage = document.getElementById('assetSalvageValue');
  const monthly = document.getElementById('assetMonthlyEstimate');

  function updateVendorId() {
    const value = vendorName.value;
    let found = false;
    document.querySelectorAll('#assetVendorList option').forEach(function(option) {
      if (option.value === value) {
        vendorId.value = option.dataset.id || '0';
        found = true;
      }
    });
    if (!found) vendorId.value = '0';
  }

  function applyLinkedExpense() {
    const option = expenseSelect.options[expenseSelect.selectedIndex];
    if (!option) return;

    createExpenseFlag.value = option.value === 'new' ? '1' : '0';
    if (option.value === '0' || option.value === 'new') return;

    if (option.dataset.vendorName) {
      vendorName.value = option.dataset.vendorName;
      vendorId.value = option.dataset.vendorId || '0';
    }
    if (option.dataset.categoryId && option.dataset.categoryId !== '0') {
      category.value = option.dataset.categoryId;
    }
    if (option.dataset.expenseDate) {
      purchaseDate.value = option.dataset.expenseDate;
    }
    if (option.dataset.amount) {
      cost.value = option.dataset.amount;
      updateDepreciationEstimate();
    }
  }

  function updateDepreciationEstimate() {
    const purchaseCost = parseFloat(cost.value) || 0;
    const salvageValue = parseFloat(salvage.value) || 0;
    const months = parseInt(life.value, 10) || 0;
    if (method.value === 'none' || months <= 0) {
      monthly.value = '$0.00';
      return;
    }
    monthly.value = '$' + (Math.max(0, purchaseCost - salvageValue) / months).toFixed(2);
  }

  vendorName.addEventListener('change', updateVendorId);
  expenseSelect.addEventListener('change', applyLinkedExpense);
  [method, cost, life, salvage].forEach(function(input) {
    input.addEventListener('input', updateDepreciationEstimate);
    input.addEventListener('change', updateDepreciationEstimate);
  });
  updateDepreciationEstimate();

  form.addEventListener('submit', function() {
    updateVendorId();
    if (expenseSelect.value === 'new') createExpenseFlag.value = '1';
  });
})();
</script>
