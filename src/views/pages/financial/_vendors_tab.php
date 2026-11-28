<?php
// src/views/pages/financial/_vendors_tab.php (Setup > Vendors)
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
[$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');

$stmt = $pdo->prepare("
    SELECT v.*, ec.name as default_category_name, COUNT(e.id) as expense_count, COALESCE(SUM(e.amount),0) as total_spent
    FROM vendors v
    LEFT JOIN expenses e ON e.vendor_id = v.id AND {$expenseScopeWhere}
    LEFT JOIN expense_categories ec ON v.default_category_id = ec.id
    GROUP BY v.id
    ORDER BY v.name
");
$stmt->execute($expenseScopeParams);
$vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalVendors = count($vendors);
$totalSpend = array_sum(array_column($vendors, 'total_spent'));
$activeVendors = array_reduce($vendors, function ($c, $v) { return $c + ((int)$v['is_active'] === 1 ? 1 : 0); }, 0);

$csrf = csrf_token();
?>

<section>
  <?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:20px">
    <div class="grid-3">
      <div>
        <div class="label-muted">Total Vendors</div>
        <div class="font-600" style="font-size:22px"><?php echo number_format($totalVendors); ?></div>
      </div>
      <div>
        <div class="label-muted">Total Spend</div>
        <div class="font-600" style="font-size:22px">$<?php echo number_format((float)$totalSpend, 2); ?></div>
      </div>
      <div>
        <div class="label-muted">Active Vendors</div>
        <div class="font-600" style="font-size:22px"><?php echo number_format($activeVendors); ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Website</th>
            <th>Default Category</th>
            <th class="text-right">Expenses</th>
            <th class="text-right">Total Spent</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($vendors)): ?>
            <tr>
              <td colspan="8" style="text-align:center">
                <div class="muted-note" style="padding:32px">
                  No vendors yet. <a href="/?page=financial/vendor-form">Add your first vendor</a>.
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($vendors as $vendor): ?>
              <tr>
                <td><?php echo htmlspecialchars($vendor['name']); ?><?php if ((int)$vendor['is_active'] !== 1): ?> <span class="status-pill status-pill--inactive">inactive</span><?php endif; ?></td>
                <td><?php echo htmlspecialchars($vendor['email'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($vendor['phone'] ?? '—'); ?></td>
                <td>
                  <?php if (!empty($vendor['website'])): ?>
                    <a href="<?php echo htmlspecialchars($vendor['website']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($vendor['website']); ?></a>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($vendor['default_category_name'] ?? '—'); ?></td>
                <td class="text-right"><?php echo number_format((int)$vendor['expense_count']); ?></td>
                <td class="text-right">$<?php echo number_format((float)$vendor['total_spent'], 2); ?></td>
                <td>
                  <div class="flex" style="gap:6px">
                    <?php if ((int)$vendor['is_active'] === 1): ?>
                      <a href="/?page=financial/vendor-form&amp;id=<?php echo (int)$vendor['id']; ?>" class="btn btn-sm">Edit</a>
                      <form method="post" action="/?page=financial/vendor-handler" style="display:inline" onsubmit="return confirm('Deactivate this vendor? Expenses will retain this vendor reference.')">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('vendor')); ?>">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <input type="hidden" name="id" value="<?php echo (int)$vendor['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Deactivate</button>
                      </form>
                    <?php else: ?>
                      <span class="muted text-sm">Inactive</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
