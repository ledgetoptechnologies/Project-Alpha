<?php
// src/views/pages/clients-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus=api_v2_directory_management_status($pdo);
if($directoryManagementStatus['effective']){ echo '<div class="alert alert-info">'.htmlspecialchars(API_V2_DIRECTORY_MANAGEMENT_LABEL).'</div>'; return; }
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
$id = (int)($_GET['id'] ?? 0);
require_record_ownership($pdo, 'clients', $id);
$st = $pdo->prepare('SELECT c.*, o.name AS organization_name FROM clients c LEFT JOIN organizations o ON o.id = c.organization_id WHERE c.id=?');
$st->execute([$id]);
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) {
  echo '<p>Client not found.</p>';
  return;
}

// Fetch all organizations for dropdown
$orgStmt = $pdo->query('SELECT id, name FROM organizations ORDER BY name ASC');
$organizations = $orgStmt->fetchAll();
?>
<section>
  <h2>Edit Client</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <div id="orgValidationBannerEdit" style="display:none;padding:12px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;margin-bottom:16px;color:#856404">
    <strong>⚠️ Organization doesn't exist yet.</strong> You can create it using the button below.
  </div>
  <form method="post" action="/?page=clients-update" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$client['id']; ?>">
    <label>
      <div>Name</div>
      <input required type="text" name="name" value="<?php echo htmlspecialchars($client['name']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Email</div>
      <input type="email" name="email" value="<?php echo htmlspecialchars($client['email'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Phone</div>
      <input type="tel" name="phone" autocomplete="tel" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label style="position:relative">
      <div>Organization</div>
      <input type="text" id="orgInputEdit" placeholder="Type to search organizations (leave blank for none)..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" value="<?php echo htmlspecialchars($client['organization_name'] ?? ''); ?>">
      <input type="hidden" id="orgIdEdit" name="organization_id" value="<?php echo (int)($client['organization_id'] ?? 0); ?>">
      <small style="display:block;margin-top:4px;color:var(--muted)">Clear the text field to remove from organization</small>
      <div id="orgSuggestEdit" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #ddd;border-radius:8px;display:none;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>
      <button type="button" id="createOrgBtnEdit" style="margin-top:8px;padding:8px 12px;background:#f0f0f0;border:1px solid #ddd;border-radius:8px;cursor:pointer;font-size:14px">
        + Create New Organization
      </button>
    </label>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Address</legend>
      <label>
        <div>Address line 1</div><input name="address_line1" value="<?php echo htmlspecialchars($client['address_line1'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Apartment / Suite</div><input name="address_line2" value="<?php echo htmlspecialchars($client['address_line2'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
        <label>
          <div>City</div><input name="city" value="<?php echo htmlspecialchars($client['city'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>State</div><input name="state" value="<?php echo htmlspecialchars($client['state'] ?? 'WI'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Postal (zip)</div><input name="postal" value="<?php echo htmlspecialchars($client['postal_code'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>
    </fieldset>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($client['notes'] ?? ''); ?></textarea>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;font-size:small">Save</button>
      <a href="/?page=client/clients-list" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;font-size:small">Cancel</a>
      <form method="post" action="/?page=clients-delete" onsubmit="return confirm('Archive this client and all associated documents? This will remove them from active lists.');" style="display:inline-block;margin-left:auto">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$client['id']; ?>">
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:#fee2e2;color:#991b1b;font-size:small">Archive Client</button>
      </form>
      <form method="post" action="/?page=clients-purge" onsubmit="return confirm('PERMANENTLY delete this client and ALL related quotes, contracts, invoices, and payments? This cannot be undone.');" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$client['id']; ?>">
      </form>
    </div>
  </form>

  <?php
  // Include links section
  $entityType = 'client';
  $entityId = (int)$client['id'];
  include __DIR__ . '/../../components/links_section.php';
  ?>

  <!-- Create Organization Modal -->
  <div id="createOrgModalEdit" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center;flex-direction:column">
    <div style="background:#fff;padding:24px;border-radius:12px;max-width:400px;box-shadow:0 20px 25px rgba(0,0,0,0.15)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3 style="margin:0;font-size:18px">Create New Organization</h3>
        <button type="button" id="closeCreateOrgModalEdit" style="background:none;border:none;font-size:24px;cursor:pointer;color:#999">&times;</button>
      </div>
      <form id="createOrgFormEdit" style="display:grid;gap:12px">
        <input type="hidden" id="createOrgCsrfEdit" name="csrf" value="">
        <label>
          <div style="font-weight:500;margin-bottom:4px">Organization Name</div>
          <input type="text" id="createOrgNameInputEdit" name="name" required placeholder="Organization name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <button type="button" id="cancelCreateOrgModalEdit" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer">Cancel</button>
          <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Create</button>
        </div>
      </form>
    </div>
  </div>

  <script src="<?php echo htmlspecialchars(asset_url('/assets/js/clients-edit-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
