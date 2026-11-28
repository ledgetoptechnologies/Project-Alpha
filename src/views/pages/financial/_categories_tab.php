<?php
// src/views/pages/financial/_categories_tab.php (Setup > Categories)
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
[$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');

$stmt = $pdo->prepare("
    SELECT c.*, pc.name as parent_name, COUNT(e.id) as expense_count, COALESCE(SUM(e.amount),0) as total_amount
    FROM expense_categories c
    LEFT JOIN expense_categories pc ON c.parent_id = pc.id
    LEFT JOIN expenses e ON e.category_id = c.id AND {$expenseScopeWhere}
    GROUP BY c.id
    ORDER BY c.is_system DESC, c.name
");
$stmt->execute($expenseScopeParams);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = csrf_token();
?>

<section>
  <?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Parent Category</th>
            <th>Tax Deductible</th>
            <th>Color</th>
            <th class="text-right">Expenses</th>
            <th class="text-right">Total Amount</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($categories)): ?>
            <tr>
              <td colspan="7" style="text-align:center">
                <div class="muted-note" style="padding:32px">No categories found.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($categories as $category): ?>
              <tr>
                <td>
                  <?php if ((int)$category['is_system'] === 1): ?>
                    <span class="muted" title="System category" style="margin-right:4px">🔒</span>
                  <?php endif; ?>
                  <?php echo htmlspecialchars($category['name']); ?>
                </td>
                <td><?php echo htmlspecialchars($category['parent_name'] ?? '—'); ?></td>
                <td>
                  <?php if ((int)$category['tax_deductible'] === 1): ?>
                    <span class="status-pill status-pill--success" title="Tax deductible">✓</span>
                  <?php else: ?>
                    <span class="status-pill status-pill--inactive" title="Not tax deductible">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($category['color'])): ?>
                    <span class="status-pill" style="background-color:<?php echo htmlspecialchars($category['color']); ?>;color:#fff;"><?php echo htmlspecialchars($category['color']); ?></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td class="text-right"><?php echo number_format((int)$category['expense_count']); ?></td>
                <td class="text-right">$<?php echo number_format((float)$category['total_amount'], 2); ?></td>
                <td>
                  <div class="flex" style="gap:6px">
                    <button type="button" class="btn btn-sm" onclick='editCategory(<?php echo json_encode($category, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG); ?>)'>Edit</button>
                    <?php if ((int)$category['is_system'] === 0): ?>
                      <form method="post" action="/?page=financial/category-handler" style="display:inline" onsubmit="return confirm('Delete this category? Expenses using it will have no category.')">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('category')); ?>">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$category['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                      </form>
                    <?php else: ?>
                      <span class="muted text-sm" title="System categories cannot be deleted">Locked</span>
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

<div id="categoryModal" class="card" style="display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(500px,90vw);z-index:1000">
  <div class="card-head">
    <h3 class="card-title" id="modalTitle">Edit Category</h3>
    <button type="button" class="btn btn-sm" onclick="closeModal()">Close</button>
  </div>
  <form method="post" action="/?page=financial/category-handler">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('category')); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="action" id="categoryAction" value="update">
    <input type="hidden" name="id" id="categoryId">

    <div class="field">
      <label for="cat-name" class="label">Name *</label>
      <input type="text" id="cat-name" name="name" required class="input">
    </div>

    <div class="field">
      <label for="cat-parent" class="label">Parent Category</label>
      <select id="cat-parent" name="parent_id" class="input">
        <option value="">— None —</option>
        <?php foreach ($categories as $opt): ?>
          <option value="<?php echo (int)$opt['id']; ?>"><?php echo htmlspecialchars($opt['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" id="taxDeductibleField">
      <label class="label">
        <input type="checkbox" id="cat-tax" name="tax_deductible" value="1" checked>
        Tax Deductible
      </label>
    </div>

    <div class="field">
      <label for="cat-color" class="label">Color</label>
      <input type="color" id="cat-color" name="color" class="input" style="padding:4px;height:40px">
    </div>

    <div class="flex flex-end">
      <button type="submit" class="btn btn-primary">Save Category</button>
    </div>
  </form>
</div>

<script>
(function() {
  var modal = document.getElementById('categoryModal');
  var backdrop = document.createElement('div');
  backdrop.id = 'modalBackdrop';
  backdrop.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:999';
  document.body.appendChild(backdrop);

  function openModal() {
    modal.style.display = 'block';
    backdrop.style.display = 'block';
  }

  function resetParentOptions() {
    var parent = document.getElementById('cat-parent');
    parent.disabled = false;
    for (var i = 0; i < parent.options.length; i++) {
      parent.options[i].disabled = false;
    }
  }

  window.closeModal = function() {
    modal.style.display = 'none';
    backdrop.style.display = 'none';
  };

  window.createCategory = function() {
    document.getElementById('categoryAction').value = 'create';
    document.getElementById('categoryId').value = '';
    document.getElementById('cat-name').value = '';
    document.getElementById('cat-parent').value = '';
    document.getElementById('cat-tax').checked = true;
    document.getElementById('cat-color').value = '#3b82f6';
    document.getElementById('taxDeductibleField').style.display = 'block';
    document.getElementById('modalTitle').textContent = 'Add Category';
    resetParentOptions();
    openModal();
  };

  window.editCategory = function(category) {
    document.getElementById('categoryAction').value = 'update';
    document.getElementById('categoryId').value = category.id;
    document.getElementById('cat-name').value = category.name || '';
    document.getElementById('cat-parent').value = category.parent_id || '';
    document.getElementById('cat-tax').checked = parseInt(category.tax_deductible, 10) === 1;
    document.getElementById('cat-color').value = category.color || '#3b82f6';
    resetParentOptions();

    var taxField = document.getElementById('taxDeductibleField');
    if (parseInt(category.is_system, 10) === 1) {
      taxField.style.display = 'none';
      document.getElementById('cat-parent').disabled = true;
    } else {
      taxField.style.display = 'block';
      document.getElementById('cat-parent').disabled = false;
      // Prevent setting a category as its own parent
      for (var i = 0; i < document.getElementById('cat-parent').options.length; i++) {
        document.getElementById('cat-parent').options[i].disabled =
          parseInt(document.getElementById('cat-parent').options[i].value, 10) === parseInt(category.id, 10);
      }
    }

    document.getElementById('modalTitle').textContent = parseInt(category.is_system, 10) === 1 ? 'Edit System Category' : 'Edit Category';
    openModal();
  };

  backdrop.addEventListener('click', window.closeModal);
})();
</script>
