<?php
// src/views/pages/clients-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus=api_v2_directory_management_status($pdo);
if($directoryManagementStatus['effective']){ echo '<div class="alert alert-info">'.htmlspecialchars(API_V2_DIRECTORY_MANAGEMENT_LABEL).'</div>'; return; }
require_once __DIR__ . '/../../../config/db.php';
?>
<section>
  <h2>Create Client</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <div id="orgValidationBanner" style="display:none;padding:12px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;margin-bottom:16px;color:#856404">
    <strong>⚠️ Organization doesn't exist yet.</strong> You can create it using the button below.
  </div>
  <div id="clientDuplicateBanner" style="display:none;padding:12px 16px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;margin-bottom:16px;color:#9a3412">
    <strong>Possible duplicate client.</strong> <span data-duplicate-client-message></span>
  </div>
  <form method="post" action="/?page=clients-create" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <label>
      <div>Name</div>
      <input required type="text" id="clientNameInput" name="name" placeholder="First Last" autocomplete="name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Email</div>
      <input type="email" name="email" placeholder="email@example.com" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Phone</div>
      <input type="tel" name="phone" autocomplete="tel" placeholder="(555) 123-4567" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label style="position:relative">
      <div>Organization</div>
      <input type="text" id="orgInput" placeholder="Type to search organizations..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      <input type="hidden" id="orgId" name="organization_id" value="">
      <div id="orgSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #ddd;border-radius:8px;display:none;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>
      <button type="button" id="createOrgBtn" style="margin-top:8px;padding:8px 12px;background:#f0f0f0;border:1px solid #ddd;border-radius:8px;cursor:pointer;font-size:14px">
        + Create New Organization
      </button>
    </label>


    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Address</legend>
      <div id="orgAddressAutofillNotice" style="display:none;margin-bottom:8px;color:#166534;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:8px;padding:8px 10px;font-size:13px">
        Address filled from the selected organization.
      </div>
      <label>
        <div>Address line 1</div><input id="clientAddressLine1" name="address_line1" autocomplete="address-line1" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Apartment / Suite</div><input id="clientAddressLine2" name="address_line2" autocomplete="address-line2" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
        <label>
          <div>City</div><input id="clientCity" name="city" autocomplete="address-level2" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>State</div><input id="clientState" name="state" autocomplete="address-level1" value="<?php echo htmlspecialchars($appConfig['primary_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Postal (zip)</div><input id="clientPostal" name="postal" autocomplete="postal-code" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>
      <label>
        <div>Country</div><input id="clientCountry" name="country" autocomplete="country-name" value="USA" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </fieldset>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" placeholder="Internal notes" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create</button>
  </form>
  <!-- Create Organization Modal (moved outside the main form to avoid nesting) -->
  <div id="createOrgModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center;flex-direction:column;-webkit-align-items:center;-webkit-justify-content:center">
    <div style="background:#fff;padding:24px;border-radius:12px;max-width:400px;box-shadow:0 20px 25px rgba(0,0,0,0.15)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3 style="margin:0;font-size:18px">Create New Organization</h3>
        <button type="button" id="closeCreateOrgModal" style="background:none;border:none;font-size:24px;cursor:pointer;color:#999">&times;</button>
      </div>
      <form id="createOrgForm" style="display:grid;gap:12px">
        <input type="hidden" id="createOrgCsrf" name="csrf" value="">
        <label>
          <div style="font-weight:500;margin-bottom:4px">Organization Name</div>
          <input type="text" id="createOrgNameInput" name="name" required placeholder="Organization name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <button type="button" id="cancelCreateOrgModal" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer">Cancel</button>
          <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Create</button>
        </div>
      </form>
    </div>
  </div>

  <script type="module" src="<?php echo htmlspecialchars(asset_url('/assets/js/clients-create-logic.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
