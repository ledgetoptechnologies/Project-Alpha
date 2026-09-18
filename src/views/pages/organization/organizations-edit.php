<?php
// src/views/pages/organization/organizations-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus=api_v2_directory_management_status($pdo);
if($directoryManagementStatus['effective']){ echo '<div class="alert alert-info">'.htmlspecialchars(API_V2_DIRECTORY_MANAGEMENT_LABEL).'</div>'; return; }
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/escaper.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    echo '<p>Organization not found.</p>';
    return;
}
?>
<section>
  <h2>Edit Organization</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <form method="post" action="/?page=organization/organizations-update" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <label>
      <div>Organization Name</div>
      <input required type="text" name="name" value="<?php echo htmlspecialchars($org['name']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label><div>General Email</div><input type="email" name="general_email" autocomplete="email" value="<?php echo htmlspecialchars((string)($org['general_email'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <label><div>General Phone</div><input name="general_phone" maxlength="50" autocomplete="tel" value="<?php echo htmlspecialchars((string)($org['general_phone'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
    </div>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Organization Address</legend>
      <label>
        <div>Address line 1</div>
        <input name="address_line1" autocomplete="address-line1" value="<?php echo htmlspecialchars((string)($org['address_line1'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Apartment / Suite</div>
        <input name="address_line2" autocomplete="address-line2" value="<?php echo htmlspecialchars((string)($org['address_line2'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
        <label>
          <div>City</div>
          <input name="city" autocomplete="address-level2" value="<?php echo htmlspecialchars((string)($org['city'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>State</div>
          <input name="state" autocomplete="address-level1" value="<?php echo htmlspecialchars((string)($org['state'] ?: ($appConfig['primary_state'] ?? ''))); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Postal</div>
          <input name="postal_code" autocomplete="postal-code" value="<?php echo htmlspecialchars((string)($org['postal_code'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>
      <label>
        <div>Country</div>
        <input name="country" autocomplete="country-name" value="<?php echo htmlspecialchars((string)($org['country'] ?? 'USA')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </fieldset>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($org['notes'] ?? ''); ?></textarea>
    </label>
    <label>
      <div>Tax Exempt Form (PDF, JPG, PNG)</div>
      <?php if (!empty($org['tax_exempt_file'])): ?>
        <div style="margin-bottom:6px">
          Current file: <a href="/?page=serve-upload&file=<?php echo e(rawurlencode('organizations/' . $org['tax_exempt_file'])); ?>" target="_blank">View</a>
          <label style="margin-left:12px;font-size:small"><input type="checkbox" name="remove_tax_file" value="1"> Remove file</label>
        </div>
      <?php endif; ?>
      <input type="file" name="tax_exempt_file" accept="application/pdf,image/jpeg,image/png">
      <div style="font-size:small;color:var(--muted);margin-top:4px">Upload a PDF, JPG, or PNG to attach a tax-exempt document to this organization.</div>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Changes</button>
      <a href="/?page=organization/organization-view&id=<?php echo $id; ?>" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none">Cancel</a>
      <button type="button" id="deleteOrgBtn" style="padding:10px 14px;border-radius:8px;border:0;background:#fee2e2;color:#991b1b;cursor:pointer">Delete Organization</button>
    </div>
  </form>

  <?php
  // Include links section
  $entityType = 'organization';
  $entityId = (int)$org['id'];
  include __DIR__ . '/../../components/links_section.php';
  ?>
</section>

<!-- Delete organization form (outside main form to avoid nesting) -->
<form id="deleteOrgForm" method="post" action="/?page=organization/organizations-delete" style="display:none">
  <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
  <input type="hidden" name="id" value="<?php echo $id; ?>">
</form>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/organization-edit-logic.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
