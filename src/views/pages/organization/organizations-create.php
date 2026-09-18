<?php
// src/views/pages/organization/organizations-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus=api_v2_directory_management_status($pdo);
if($directoryManagementStatus['effective']){ echo '<div class="alert alert-info">'.htmlspecialchars(API_V2_DIRECTORY_MANAGEMENT_LABEL).'</div>'; return; }
require_once __DIR__ . '/../../../config/app.php';
?>
<section>
  <h2>Create Organization</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <form method="post" action="/?page=organization/organizations-create" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <?php if (!empty($_GET['return_to'])): ?>
      <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to']); ?>">
    <?php endif; ?>
    <label>
      <div>Organization Name</div>
      <input required type="text" name="name" placeholder="Organization name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label><div>General Email</div><input type="email" name="general_email" autocomplete="email" placeholder="billing@example.com" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <label><div>General Phone</div><input name="general_phone" maxlength="50" autocomplete="tel" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
    </div>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Organization Address</legend>
      <label>
        <div>Address line 1</div>
        <input name="address_line1" autocomplete="address-line1" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Apartment / Suite</div>
        <input name="address_line2" autocomplete="address-line2" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
        <label>
          <div>City</div>
          <input name="city" autocomplete="address-level2" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>State</div>
          <input name="state" autocomplete="address-level1" value="<?php echo htmlspecialchars((string)($appConfig['primary_state'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Postal</div>
          <input name="postal_code" autocomplete="postal-code" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>
      <label>
        <div>Country</div>
        <input name="country" autocomplete="country-name" value="USA" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </fieldset>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="4" placeholder="Internal notes about this organization" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <label>
      <div>Tax Exempt Form (optional)</div>
      <input type="file" name="tax_exempt_file" accept="application/pdf,image/jpeg,image/png">
      <div style="font-size:small;color:var(--muted);margin-top:4px">Optional PDF/JPG/PNG tax-exempt document.</div>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Organization</button>
      <a href="/?page=organization/organizations-list" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none">Cancel</a>
    </div>
  </form>
</section>
