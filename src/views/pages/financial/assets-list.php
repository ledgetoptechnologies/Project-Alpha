<?php
// src/views/pages/financial/assets-list.php
// Assets: owned equipment with purchase cost, book value and this year's depreciation.
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/financial_assets.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);

$assetSearch = trim((string)($_GET['asset_search'] ?? ''));
$assetStatus = (string)($_GET['asset_status'] ?? '');
$assetType = trim((string)($_GET['asset_type'] ?? ''));
$assetVendorId = (int)($_GET['asset_vendor_id'] ?? 0);

[$assetScopeWhere, $assetScopeParams] = finance_scope_clause($pdo, 'a', $userId, $orgId, 'created_by');
$where = [$assetScopeWhere];
$params = $assetScopeParams;
if ($assetSearch !== '') {
    $where[] = '(a.name LIKE ? OR a.asset_tag LIKE ? OR a.serial_number LIKE ? OR a.location LIKE ?)';
    $like = '%' . $assetSearch . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($assetStatus !== '') {
    $where[] = 'a.status = ?';
    $params[] = $assetStatus;
}
if ($assetType !== '') {
    $where[] = 'a.asset_type = ?';
    $params[] = $assetType;
}
if ($assetVendorId > 0) {
    $where[] = 'a.vendor_id = ?';
    $params[] = $assetVendorId;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT a.*, v.name AS vendor_name, ec.name AS category_name
    FROM financial_assets a
    LEFT JOIN vendors v ON v.id = a.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = a.category_id
    WHERE {$whereSql}
    ORDER BY
        CASE a.status WHEN 'active' THEN 1 WHEN 'maintenance' THEN 2 WHEN 'planned' THEN 3 ELSE 4 END,
        a.purchase_date DESC,
        a.name ASC
");
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeStmt = $pdo->prepare('SELECT DISTINCT asset_type FROM financial_assets a WHERE ' . $assetScopeWhere . ' AND asset_type IS NOT NULL AND asset_type <> "" ORDER BY asset_type');
$typeStmt->execute($assetScopeParams);
$assetTypes = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

$vendorStmt = $pdo->prepare('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name');
$vendorStmt->execute();
$assetVendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'purchase_cost' => 0.0,
    'book_value' => 0.0,
    'year_depreciation' => 0.0,
    'active_count' => 0,
];
// This year's depreciation = accumulated as of today minus accumulated at last year end.
$lastYearEnd = new DateTimeImmutable((date('Y') - 1) . '-12-31');
foreach ($assets as $asset) {
    $depreciation = financial_asset_depreciation($asset);
    $summary['purchase_cost'] += (float)$asset['purchase_cost'];
    $summary['book_value'] += $depreciation['book_value'];
    $summary['year_depreciation'] += $depreciation['accumulated'] - financial_asset_depreciation($asset, $lastYearEnd)['accumulated'];
    if (in_array((string)$asset['status'], ['active', 'maintenance'], true)) {
        $summary['active_count']++;
    }
}

$assetFilterCount = count(array_filter([$assetSearch, $assetStatus, $assetType, $assetVendorId], static function ($value) {
    return $value !== '' && $value !== 0 && $value !== '0';
}));
$statusOptions = ['planned' => 'Planned', 'active' => 'Active', 'maintenance' => 'Maintenance', 'retired' => 'Retired', 'sold' => 'Sold', 'lost' => 'Lost', 'disposed' => 'Disposed'];
?>

<section class="expense-ledger asset-ledger">
  <div class="page-head">
    <h2>Assets</h2>
    <?php if (session_user_can($pdo, 'financial.manage')): ?>
      <a href="/?page=financial/asset-form" class="btn btn-primary">+ Add asset</a>
    <?php endif; ?>
  </div>

  <?php if (!empty($_GET['disposed'])): ?>
    <div class="alert alert-success">Asset marked disposed.</div>
  <?php endif; ?>

  <div class="expense-summary asset-summary">
    <div class="expense-stat">
      <span>Assets</span>
      <strong><?php echo number_format(count($assets)); ?></strong>
      <small><?php echo number_format($summary['active_count']); ?> active or maintenance</small>
    </div>
    <div class="expense-stat">
      <span>Purchase Cost</span>
      <strong><?php echo financial_asset_money($summary['purchase_cost']); ?></strong>
      <small>Filtered asset basis</small>
    </div>
    <div class="expense-stat">
      <span>Book Value</span>
      <strong><?php echo financial_asset_money($summary['book_value']); ?></strong>
      <small>Calculated as of today</small>
    </div>
    <div class="expense-stat">
      <span>Depreciation, <?php echo date('Y'); ?></span>
      <strong><?php echo financial_asset_money($summary['year_depreciation']); ?></strong>
      <small>Straight-line, through today</small>
    </div>
  </div>

  <div class="expense-filter-panel">
    <div class="expense-filter-panel__head">
      <strong>Filters</strong>
      <span class="muted text-sm"><?php echo $assetFilterCount; ?> active</span>
    </div>
    <form method="get" action="/" class="expense-filter-grid">
      <input type="hidden" name="page" value="financial/assets-list">
      <label>
        <span class="label-muted">Search</span>
        <input type="search" name="asset_search" class="input" value="<?php echo htmlspecialchars($assetSearch); ?>" placeholder="Name, tag, serial, location">
      </label>
      <label>
        <span class="label-muted">Status</span>
        <select name="asset_status" class="input">
          <option value="">All statuses</option>
          <?php foreach ($statusOptions as $value => $label): ?>
            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $assetStatus === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span class="label-muted">Type</span>
        <select name="asset_type" class="input">
          <option value="">All types</option>
          <?php foreach ($assetTypes as $type): ?>
            <option value="<?php echo htmlspecialchars((string)$type); ?>" <?php echo $assetType === (string)$type ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$type); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span class="label-muted">Vendor</span>
        <select name="asset_vendor_id" class="input">
          <option value="0">All vendors</option>
          <?php foreach ($assetVendors as $vendor): ?>
            <option value="<?php echo (int)$vendor['id']; ?>" <?php echo $assetVendorId === (int)$vendor['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($vendor['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="expense-filter-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="/?page=financial/assets-list" class="btn">Reset</a>
      </div>
    </form>
  </div>

  <?php if (empty($assets)): ?>
    <div class="finance-empty">
      <strong>No assets found</strong>
      <p class="muted">Add equipment, vehicles, tools, computers, furniture, or other tracked assets.</p>
    </div>
  <?php else: ?>
    <div class="pa-table-wrap expense-table-wrap">
      <table class="pa-table expense-table asset-table">
        <thead>
          <tr>
            <th>Asset</th>
            <th>Purchase / Vendor</th>
            <th>Depreciation</th>
            <th class="text-right">Book Value</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($assets as $asset):
            $depreciation = financial_asset_depreciation($asset);
          ?>
          <tr>
            <td>
              <div class="expense-primary">
                <strong><?php echo htmlspecialchars($asset['name']); ?></strong>
                <span><?php echo htmlspecialchars($asset['asset_tag'] ?: 'No asset tag'); ?></span>
                <small><?php echo htmlspecialchars(trim(($asset['asset_type'] ?: 'Asset') . (($asset['serial_number'] ?? '') !== '' ? ' / SN ' . $asset['serial_number'] : ''))); ?></small>
              </div>
            </td>
            <td>
              <div class="expense-primary">
                <strong><?php echo financial_asset_money((float)$asset['purchase_cost']); ?></strong>
                <span><?php echo htmlspecialchars(financial_asset_date($asset['purchase_date'])); ?></span>
                <small><?php echo htmlspecialchars($asset['vendor_name'] ?: 'No vendor'); ?></small>
              </div>
            </td>
            <td>
              <div class="expense-primary">
                <strong><?php echo htmlspecialchars($asset['depreciation_method'] === 'none' ? 'No depreciation' : ((int)$asset['useful_life_months'] . ' months')); ?></strong>
                <span><?php echo financial_asset_money($depreciation['monthly']); ?> / month</span>
                <small><?php echo financial_asset_money($depreciation['accumulated']); ?> accumulated</small>
              </div>
            </td>
            <td class="text-right">
              <div class="expense-amount">
                <strong><?php echo financial_asset_money($depreciation['book_value']); ?></strong>
                <span><?php echo htmlspecialchars($asset['location'] ?: 'No location'); ?></span>
              </div>
            </td>
            <td><span class="status-pill status-pill--<?php echo financial_asset_status_class($asset['status']); ?>"><?php echo htmlspecialchars(financial_asset_status_label($asset['status'])); ?></span></td>
            <td>
              <div class="expense-row-actions">
                <a href="/?page=financial/asset-detail&id=<?php echo (int)$asset['id']; ?>" class="btn btn-sm">View</a>
                <a href="/?page=financial/asset-form&id=<?php echo (int)$asset['id']; ?>" class="btn btn-sm">Edit</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
