<?php
// src/views/pages/financial/vendor-form.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();

// Fetch categories for default_category_id dropdown
$catStmt = $pdo->prepare("
    SELECT id, name
    FROM expense_categories
    ORDER BY name
");
$catStmt->execute();
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Edit mode
$vendor = null;
$vendorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vendorId > 0) {
    $vStmt = $pdo->prepare("
        SELECT *
        FROM vendors
        WHERE id = ? AND is_active = 1
    ");
    $vStmt->execute([$vendorId]);
    $vendor = $vStmt->fetch(PDO::FETCH_ASSOC);
}

$isEdit = $vendor !== false && $vendor !== null;
$title = $isEdit ? 'Edit Vendor' : 'Add Vendor';
$csrf = csrf_token();
?>

<section>
  <div class="page-head">
    <h2><?php echo htmlspecialchars($title); ?></h2>
    <a href="/?page=financial/setup&amp;tab=vendors" class="btn">Back to Vendors</a>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="card" style="max-width:700px">
    <form method="post" action="/?page=financial/vendor-handler">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('vendor')); ?>">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="action" value="<?php echo $isEdit ? 'update' : 'create'; ?>">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?php echo (int)$vendor['id']; ?>">
      <?php endif; ?>

      <div class="field">
        <label for="name" class="label">Vendor Name *</label>
        <input type="text" id="name" name="name" required class="input" value="<?php echo htmlspecialchars($vendor['name'] ?? ''); ?>" placeholder="Acme Supplies">
      </div>

      <div class="field-row grid-2">
        <div class="field">
          <label for="email" class="label">Email</label>
          <input type="email" id="email" name="email" class="input" value="<?php echo htmlspecialchars($vendor['email'] ?? ''); ?>" placeholder="billing@example.com">
        </div>
        <div class="field">
          <label for="phone" class="label">Phone</label>
          <input type="tel" id="phone" name="phone" class="input" value="<?php echo htmlspecialchars($vendor['phone'] ?? ''); ?>" placeholder="(555) 123-4567">
        </div>
      </div>

      <div class="field-row grid-2">
        <div class="field">
          <label for="website" class="label">Website</label>
          <input type="url" id="website" name="website" class="input" value="<?php echo htmlspecialchars($vendor['website'] ?? ''); ?>" placeholder="https://example.com">
        </div>
        <div class="field">
          <label for="tax_id" class="label">Tax ID</label>
          <input type="text" id="tax_id" name="tax_id" class="input" value="<?php echo htmlspecialchars($vendor['tax_id'] ?? ''); ?>" placeholder="12-3456789">
        </div>
      </div>

      <div class="field">
        <label for="default_category_id" class="label">Default Category</label>
        <select id="default_category_id" name="default_category_id" class="input">
          <option value="">— None —</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?php echo (int)$category['id']; ?>" <?php echo (isset($vendor['default_category_id']) && (int)$vendor['default_category_id'] === (int)$category['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($category['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="notes" class="label">Notes</label>
        <textarea id="notes" name="notes" class="input" rows="4" placeholder="Payment terms, account numbers, etc."><?php echo htmlspecialchars($vendor['notes'] ?? ''); ?></textarea>
      </div>

      <div class="field">
        <label for="address" class="label">Address</label>
        <textarea id="address" name="address" class="input" rows="3" placeholder="Billing or physical address"><?php echo htmlspecialchars($vendor['address'] ?? ''); ?></textarea>
      </div>

      <div class="flex flex-end">
        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update Vendor' : 'Create Vendor'; ?></button>
      </div>
    </form>
  </div>
</section>
