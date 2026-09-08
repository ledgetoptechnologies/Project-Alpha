<?php
// src/views/pages/organization/organization-view.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/resolver_link_policy.php';
require_once __DIR__ . '/../../../utils/external_ops.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<p>Organization not found.</p>';
    return;
}
require_record_ownership($pdo, 'organizations', $id);
$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    echo '<p>Organization not found.</p>';
    return;
}

$portalRootAccess = null;
$portalLoginConfigured = false;
$canManagePortalLogin = user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'users.manage', 0)
    && user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'settings.manage', 0);
try {
    $portalConfig = pa_external_ops_delivery_config($pdo);
    $portalState = (new \App\Services\PortalClientProvisioningService())->status($pdo, (string)$portalConfig['application_key']);
    $portalLoginConfigured = !empty($portalState['configured']);
    $portalStmt = $pdo->prepare("SELECT * FROM portal_client_access_roots WHERE root_type='organization' AND root_public_id=?");
    $portalStmt->execute([(string)$org['public_id']]);
    $portalRootAccess = $portalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $error) {
    error_log('[organization_view] Client portal status unavailable: ' . $error->getMessage());
}

// Get clients in this organization
$clientStmt = $pdo->prepare('SELECT id, name, email, phone FROM clients WHERE organization_id = ? AND archived = 0 ORDER BY name ASC');
$clientStmt->execute([$id]);
$clients = $clientStmt->fetchAll();

// Get clients that are safe to attach without leaking contacts from other organizations.
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$canAttachAnyUnassignedClient = acl_user_has_org_wide_scope($pdo, $currentUserId, $id);
if (($_SESSION['user']['role'] ?? '') === 'admin' || $canAttachAnyUnassignedClient) {
    $availableStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id IS NULL AND archived = 0
        ORDER BY name ASC
    ');
    $availableStmt->execute();
} else {
    $availableStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id IS NULL AND created_by = ? AND archived = 0
        ORDER BY name ASC
    ');
    $availableStmt->execute([$currentUserId]);
}
$availableClients = $availableStmt->fetchAll();

$departmentStmt = $pdo->prepare('
    SELECT od.*,
           COUNT(DISTINCT odc.client_id) AS contact_count
    FROM organization_departments od
    LEFT JOIN organization_department_contacts odc ON odc.department_id = od.id
    WHERE od.organization_id = ?
    GROUP BY od.id
    ORDER BY od.name ASC
');
$departmentStmt->execute([$id]);
$departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);

$departmentContacts = [];
if ($departments) {
    $ids = array_map(static fn($row) => (int)$row['id'], $departments);
    $contactStmt = $pdo->prepare('
        SELECT odc.department_id, odc.is_primary, c.id, c.name, c.email
        FROM organization_department_contacts odc
        JOIN clients c ON c.id = odc.client_id
        WHERE odc.department_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
        ORDER BY odc.is_primary DESC, c.name ASC
    ');
    $contactStmt->execute($ids);
    foreach ($contactStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $departmentContacts[(int)$row['department_id']][] = $row;
    }
}

$departmentLinks = [];
if ($departments) {
    $ids = array_map(static fn($row) => (int)$row['id'], $departments);
    $linkStmt = $pdo->prepare('
        SELECT entity_id, id, title, url, link_type, include_on_invoices
        FROM entity_links
        WHERE entity_type = "department" AND entity_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
          AND link_type <> "resolver_blacklist"
          AND ' . pa_resolver_link_visibility_sql() . '
        ORDER BY include_on_invoices DESC, title ASC, id ASC
    ');
    $linkStmt->execute($ids);
    foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $departmentLinks[(int)$row['entity_id']][] = $row;
    }
}

$orgAddressLines = array_values(array_filter([
    trim((string)($org['address_line1'] ?? '')),
    trim((string)($org['address_line2'] ?? '')),
    trim(implode(', ', array_filter([
        trim((string)($org['city'] ?? '')),
        trim((string)($org['state'] ?? '')),
    ]))),
    trim((string)($org['postal_code'] ?? '')),
    trim((string)($org['country'] ?? '')),
], static fn($value) => $value !== ''));
$taxFileUrl = !empty($org['tax_exempt_file'])
    ? '/?page=serve-upload&file=' . e(rawurlencode('organizations/' . $org['tax_exempt_file']))
    : '';
?>
<style>
  .org-view { max-width: 1440px; margin: 0 auto; padding-bottom: 32px; }
  .org-view__header { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; margin-bottom: 18px; }
  .org-view__title { margin: 0; font-size: 30px; line-height: 1.15; overflow-wrap:anywhere; }
  .org-view__meta { margin-top: 6px; color: var(--muted); font-size: 13px; }
  .org-view__actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .org-view__button { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; color: inherit; text-decoration: none; font-size: 13px; cursor: pointer; }
  .org-view__button--primary { background: var(--nav-accent); border-color: var(--nav-accent); color: #fff; }
  .org-view__stats { display: grid; grid-template-columns: repeat(3, minmax(140px, 1fr)); gap: 12px; margin: 0 0 18px; }
  .org-view__stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; box-shadow: 0 6px 18px rgba(11,18,32,0.05); }
  .org-view__stat span { display: block; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
  .org-view__stat strong { display: block; margin-top: 6px; font-size: 24px; line-height: 1; }
  .org-view__layout { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 18px; align-items: start; }
  .org-view__sidebar { display: grid; gap: 14px; position: sticky; top: 16px; }
  .org-view__main { min-width: 0; }
  .org-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; box-shadow: 0 6px 18px rgba(11,18,32,0.05); }
  /* The sidebar is already a grid with a gap; a card margin here doubled that spacing. */
  .org-card__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
  .org-card__title { margin: 0; font-size: 17px; }
  .org-detail-list { display: grid; gap: 12px; margin: 0; }
  .org-detail-list dt { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
  .org-detail-list dd { margin: 4px 0 0; line-height: 1.5; }
  .org-empty { padding: 20px; text-align: center; color: var(--muted); border: 1px dashed #d1d5db; border-radius: 8px; background: #f9fafb; }
  .org-link-strategy { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.05);margin-bottom:18px; }
  .org-link-strategy__options { display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:12px 0; }
  .org-link-strategy__option { border:1px solid #dbe3ef;border-radius:8px;padding:12px;background:#fff;display:flex;gap:10px;align-items:flex-start; }
  .org-link-strategy__option strong { display:block;font-size:13px;margin-bottom:3px; }
  .org-link-strategy__option span { display:block;font-size:12px;color:var(--muted);line-height:1.4; }
  .org-dept-card { border:1px solid #dfe6ef;border-radius:8px;background:#fff;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,.035); }
  .org-dept-card__header { display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb; }
  .org-dept-card__title { margin:0;font-size:18px;line-height:1.25; }
  .org-dept-card__meta { margin-top:5px;color:var(--muted);font-size:12px;display:flex;gap:8px;flex-wrap:wrap; }
  .org-dept-card__body { display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;padding:14px 16px; }
  .org-dept-card__section-title { font-weight:700;margin-bottom:8px; }
  .org-dept-card__actions { display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end; }
  .org-departments { background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin:24px 0; }
  .org-departments__head { display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px; }
  .org-departments__grid { display:grid;gap:14px; }
  .org-dept-contact-row { display:flex;justify-content:space-between;gap:8px;align-items:center;border:1px solid #eef2f7;border-radius:8px;padding:8px;min-width:0; }
  .org-dept-contact-row__identity { min-width:0;overflow-wrap:anywhere; }
  .org-dept-contact-row__email { color:var(--muted);font-size:12px;overflow-wrap:anywhere; }
  .org-dept-contact-row__actions { display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;min-width:0; }
  .org-dept-contact-assignment { display:flex;gap:8px;margin-top:10px;min-width:0; }
  .org-dept-contact-assignment select { flex:1 1 12rem;min-width:0;padding:8px;border:1px solid #ddd;border-radius:8px; }
  .org-dept-contact-assignment label { display:flex;align-items:center;gap:5px;font-size:12px;color:#374151;white-space:nowrap; }
  .org-danger-zone { margin-top:18px;border-color:#fecaca;background:#fffafa; }
  .org-danger-zone__head { display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap; }
  .org-danger-zone__description { color:var(--muted);font-size:13px; }
  .org-danger-zone .org-view__button--danger { border-color:#fca5a5;color:#b91c1c; }
  .org-department-modal { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:20px; }
  .org-department-modal__dialog { background:#fff;border-radius:12px;padding:22px;max-width:640px;width:min(640px,100%);max-height:calc(100dvh - 40px);overflow-y:auto;box-shadow:0 24px 60px rgba(15,23,42,0.22); }
  @media (max-width: 960px) {
    .org-view__header { display: grid; }
    .org-view__actions { justify-content: flex-start; }
    .org-view__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .org-view__layout { grid-template-columns: 1fr; }
    .org-view__sidebar { position: static; order: -1; }
  }
  @media (max-width: 600px) {
    .org-view { padding-bottom:24px; }
    .org-view__stats { grid-template-columns:1fr; }
    .org-departments__head { align-items:stretch;flex-direction:column; }
    .org-departments__head .org-view__button { width:100%;text-align:center; }
    .org-dept-card__header { flex-direction:column; }
    .org-dept-card__actions { justify-content:flex-start; }
    .org-dept-card__body { grid-template-columns:minmax(0,1fr);padding:14px; }
    .org-dept-contact-row { align-items:flex-start;flex-direction:column; }
    .org-dept-contact-row__actions { justify-content:flex-start; }
    .org-dept-contact-assignment { align-items:stretch;flex-direction:column; }
    .org-dept-contact-assignment select { flex-basis:auto;width:100%; }
    .org-dept-contact-assignment label { white-space:normal; }
    .org-department-modal { padding:10px; }
    .org-department-modal__dialog { max-height:calc(100dvh - 20px);padding:16px; }
  }
</style>

<section class="org-view">
  <div class="org-view__header">
    <div>
      <h2 class="org-view__title"><?php echo htmlspecialchars($org['name']); ?></h2>
      <div class="org-view__meta">Created <?php echo htmlspecialchars(date('F j, Y', strtotime($org['created_at']))); ?></div>
    </div>
    <div class="org-view__actions">
      <a class="org-view__button org-view__button--primary" href="/?page=organization/organizations-edit&id=<?php echo $id; ?>">Edit Organization</a>
      <form method="post" action="/?page=organization/organizations-upload" enctype="multipart/form-data" style="display:inline-block;margin:0">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <label class="org-view__button" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
          <input type="file" name="tax_exempt_file" accept="application/pdf,image/*" style="display:none" data-submit-on-file required>
          <span style="pointer-events:none">Upload Tax Exempt</span>
        </label>
      </form>
      <a class="org-view__button" href="/?page=organization/organizations-list">Back to List</a>
    </div>
  </div>

  <?php if (!empty($_GET['client_added'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Client added to organization.</div>
  <?php elseif (!empty($_GET['client_removed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Client removed from organization.</div>
  <?php elseif (!empty($_GET['notes_updated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Notes updated successfully.</div>
  <?php elseif (!empty($_GET['uploaded'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Tax exempt form uploaded successfully.</div>
  <?php elseif (!empty($_GET['department_created']) || !empty($_GET['department_saved'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Department saved.</div>
  <?php elseif (!empty($_GET['link_strategy_saved'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Organization link strategy saved.</div>
  <?php elseif (!empty($_GET['department_deleted'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Department deleted.</div>
  <?php elseif (!empty($_GET['department_contact_added']) || !empty($_GET['department_contact_removed']) || !empty($_GET['department_contact_primary'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Department contact updated.</div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="org-view__stats">
    <div class="org-view__stat"><span>Clients</span><strong><?php echo count($clients); ?></strong></div>
    <div class="org-view__stat"><span>Departments</span><strong><?php echo count($departments); ?></strong></div>
    <div class="org-view__stat"><span>Tax Exempt</span><strong><?php echo !empty($org['tax_exempt_file']) ? 'Yes' : 'No'; ?></strong></div>
  </div>

  <div class="org-view__layout">
    <aside class="org-view__sidebar">
      <div class="org-card">
        <div class="org-card__head">
          <h3 class="org-card__title">Details</h3>
        </div>
        <dl class="org-detail-list">
          <div>
            <dt>Name</dt>
            <dd><?php echo htmlspecialchars($org['name']); ?></dd>
          </div>
          <div>
            <dt>Address</dt>
            <dd>
              <?php if ($orgAddressLines): ?>
                <?php echo implode('<br>', array_map('htmlspecialchars', $orgAddressLines)); ?>
              <?php else: ?>
                <span style="color:var(--muted)">No organization address saved.</span>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>Notes</dt>
            <dd>
              <div id="notesDisplay" style="color:var(--muted);min-height:40px;padding:8px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;background:#f9fafb" onclick="toggleNotesEdit()">
                <?php echo !empty($org['notes']) ? nl2br(htmlspecialchars($org['notes'])) : '<em style="color:#999">Click to add notes...</em>'; ?>
              </div>
              <form id="notesForm" method="post" action="/?page=organization/organization-update-notes" style="display:none;margin-top:8px">
                <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <textarea name="notes" id="notesTextarea" rows="5" style="width:100%;padding:8px;border-radius:8px;border:1px solid #ddd;font-family:inherit"><?php echo htmlspecialchars($org['notes'] ?? ''); ?></textarea>
                <div style="display:flex;gap:8px;margin-top:8px">
                  <button type="submit" class="org-view__button org-view__button--primary">Save</button>
                  <button type="button" onclick="toggleNotesEdit()" class="org-view__button">Cancel</button>
                </div>
              </form>
            </dd>
          </div>
          <div>
            <dt>General Contact</dt>
            <dd>
              <?php if (!empty($org['general_email'])): ?><div><a href="mailto:<?php echo htmlspecialchars((string)$org['general_email']); ?>"><?php echo htmlspecialchars((string)$org['general_email']); ?></a></div><?php endif; ?>
              <?php if (!empty($org['general_phone'])): ?><div><?php echo htmlspecialchars((string)$org['general_phone']); ?></div><?php endif; ?>
              <?php if (empty($org['general_email']) && empty($org['general_phone'])): ?><span style="color:var(--muted)">No general contact saved.</span><?php endif; ?>
            </dd>
          </div>
        </dl>
      </div>

      <div class="org-card" id="assigned-services">
        <?php
        $portalServiceAssignmentSubjectType = 'organization';
        $portalServiceAssignmentSubjectId = $id;
        $portalServiceAssignmentEntityPermission = 'organizations.manage';
        include __DIR__ . '/../../components/portal_service_assignments.php';
        ?>
      </div>

      <div class="org-card">
        <div class="org-card__head">
          <h3 class="org-card__title">Tax Exempt Form</h3>
        </div>
        <?php if (!empty($org['tax_exempt_file'])): ?>
          <?php $isPdf = strtolower(pathinfo($org['tax_exempt_file'], PATHINFO_EXTENSION)) === 'pdf'; ?>
          <?php if ($isPdf): ?>
            <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
              <embed src="<?php echo $taxFileUrl; ?>" type="application/pdf" width="100%" height="260px" />
            </div>
          <?php else: ?>
            <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
              <img src="<?php echo $taxFileUrl; ?>" style="width:100%;height:auto" alt="Tax Exempt Form" />
            </div>
          <?php endif; ?>
          <div style="display:flex;gap:8px;margin-top:8px;font-size:small">
            <a href="<?php echo $taxFileUrl; ?>" target="_blank" class="org-view__button">View Full</a>
            <a href="<?php echo $taxFileUrl; ?>" download class="org-view__button">Download</a>
          </div>
          <?php if (!empty($org['tax_exempt_uploaded_at'])): ?>
            <div style="font-size:small;color:var(--muted);margin-top:8px">Uploaded <?php echo htmlspecialchars(date('F j, Y', strtotime($org['tax_exempt_uploaded_at']))); ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="org-empty">No tax exempt form uploaded.</div>
        <?php endif; ?>
      </div>

      <div class="org-card">
        <div class="org-card__head">
          <h3 class="org-card__title">Clients (<?php echo count($clients); ?>)</h3>
        </div>
        <div style="position:relative;margin-bottom:12px">
          <input type="text" id="clientSearchInput" placeholder="Search clients to add..." autocomplete="off" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:8px;font-size:small">
          <div id="clientSearchResults" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:8px;display:none;max-height:220px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);z-index:50;margin-top:4px"></div>
        </div>

        <?php if (empty($clients)): ?>
          <div class="org-empty">
            No clients in this organization yet.
          </div>
        <?php else: ?>
          <div style="display:grid;gap:8px">
            <?php foreach ($clients as $client): ?>
              <div style="display:grid;gap:4px;border:1px solid #eef2f7;border-radius:8px;padding:10px">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:start">
                  <a href="/?page=client/client-details&id=<?php echo (int)$client['id']; ?>" style="font-weight:700;text-decoration:none;color:inherit">
                    <?php echo htmlspecialchars($client['name']); ?>
                  </a>
                  <form method="post" action="/?page=organization/organization-remove-client" style="margin:0" onsubmit="return confirm('Remove <?php echo e(substr(json_encode((string)$client['name'], JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), 1, -1)); ?> from this organization?')">
                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="client_id" value="<?php echo (int)$client['id']; ?>">
                    <input type="hidden" name="organization_id" value="<?php echo $id; ?>">
                    <button type="submit" style="padding:4px 8px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c;font-size:12px">Remove</button>
                  </form>
                </div>
                <?php if (!empty($client['email']) || !empty($client['phone'])): ?>
                  <div style="font-size:12px;color:var(--muted);line-height:1.5">
                    <?php if (!empty($client['email'])): ?><div><?php echo htmlspecialchars($client['email']); ?></div><?php endif; ?>
                    <?php if (!empty($client['phone'])): ?><div><?php echo htmlspecialchars($client['phone']); ?></div><?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </aside>

    <main class="org-view__main">
      <?php
        $hasDepartments = !empty($departments);
        $storedLinkStrategy = (string)($org['link_strategy'] ?? 'overall_folder');
        if (!in_array($storedLinkStrategy, ['department_links_only', 'overall_folder', 'shared_folder'], true)) {
            $storedLinkStrategy = 'overall_folder';
        }
        $linkStrategy = (!$hasDepartments && $storedLinkStrategy === 'department_links_only')
            ? 'overall_folder'
            : $storedLinkStrategy;
      ?>
      <div class="org-link-strategy">
        <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap">
          <div>
            <h3 style="margin:0 0 4px">Automatic Link Strategy</h3>
            <p style="margin:0;color:var(--muted);font-size:13px;line-height:1.45">
              <?php if ($hasDepartments): ?>
                PA resolves provider folders by department unless you choose an org-level sharing mode.
              <?php else: ?>
                PA uses the overall organization folder until the first department is added.
              <?php endif; ?>
            </p>
            <?php if (!$hasDepartments): ?>
              <p style="margin:6px 0 0;color:var(--muted);font-size:12px;line-height:1.45">
                When a first department is created, PA switches this organization to department links only, scans that department, and removes resolver-created org folder links.
              </p>
            <?php endif; ?>
          </div>
          <form method="post" action="/?page=organization/organization-departments" style="margin:0">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="save_link_strategy">
            <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
            <div class="org-link-strategy__options">
              <?php foreach ([
                'department_links_only' => ['Department links only', 'Default once departments exist. PA removes resolver-created org folder links and generates department links.'],
                'overall_folder' => ['Overall org folder', 'Default before departments exist. PA resolves the folder that exactly matches the organization.'],
                'shared_folder' => ['_shared folder', 'PA resolves a folder named _shared inside the organization folder and shares it with department recipients.'],
              ] as $value => [$label, $help]): ?>
                <label class="org-link-strategy__option">
                  <input type="radio" name="link_strategy" value="<?php echo e($value); ?>" <?php echo $linkStrategy === $value ? 'checked' : ''; ?> style="margin-top:3px">
                  <span><strong><?php echo e($label); ?></strong><span><?php echo e($help); ?></span></span>
                </label>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="org-view__button org-view__button--primary">Save Link Strategy</button>
          </form>
        </div>
      </div>

      <?php
        $entityType = 'organization';
        $entityId = $id;
        require __DIR__ . '/../../components/links_section.php';
      ?>

  <div class="org-departments">
    <div class="org-departments__head">
      <div>
        <h3 style="margin:0 0 4px">Departments</h3>
        <p style="margin:0;color:var(--muted);font-size:13px">Optional groups inside this organization for teams, locations, or departments.</p>
      </div>
      <button type="button" class="org-view__button org-view__button--primary" onclick="openDepartmentModal()">Add Department</button>
    </div>

    <?php if (empty($departments)): ?>
      <div class="org-empty">
        No departments yet. Add one only when this organization needs separate groups or link rules.
      </div>
    <?php else: ?>
      <div class="org-departments__grid">
        <?php foreach ($departments as $department): ?>
          <?php
            $deptId = (int)$department['id'];
            $aliases = [];
            if (!empty($department['folder_aliases'])) {
                $decodedAliases = json_decode((string)$department['folder_aliases'], true);
                if (is_array($decodedAliases)) {
                    $aliases = array_values(array_filter(array_map('strval', $decodedAliases)));
                }
            }
            $departmentPayload = [
                'id' => $deptId,
                'name' => (string)$department['name'],
                'folder_name' => (string)($department['folder_name'] ?? ''),
                'resolver_mode' => (string)($department['resolver_mode'] ?? 'manual_only'),
                'folder_aliases' => implode("\n", $aliases),
                'notes' => (string)($department['notes'] ?? ''),
            ];
            $resolverLabels = [
                'manual_only' => 'Manual links only',
                'review' => 'Review matches',
                'auto_attach' => 'Auto attach exact matches',
                'excluded' => 'Excluded from resolver',
            ];
          ?>
          <div class="org-dept-card" id="department-<?php echo $deptId; ?>">
            <div class="org-dept-card__header">
              <div>
                <h4 class="org-dept-card__title"><?php echo e((string)$department['name']); ?></h4>
                <div class="org-dept-card__meta">
                  <span><?php echo (int)$department['contact_count']; ?> contact<?php echo (int)$department['contact_count'] === 1 ? '' : 's'; ?></span>
                  <span><?php echo count($departmentLinks[$deptId] ?? []); ?> link<?php echo count($departmentLinks[$deptId] ?? []) === 1 ? '' : 's'; ?></span>
                  <span><?php echo e($resolverLabels[(string)($department['resolver_mode'] ?? 'manual_only')] ?? 'Manual links only'); ?></span>
                </div>
                <?php if (!empty($department['folder_name']) || $aliases || !empty($department['notes'])): ?>
                  <div style="margin-top:8px;color:#4b5563;font-size:13px;line-height:1.45">
                    <?php if (!empty($department['folder_name'])): ?><div><strong>Folder:</strong> <?php echo e((string)$department['folder_name']); ?></div><?php endif; ?>
                    <?php if ($aliases): ?><div><strong>Aliases:</strong> <?php echo e(implode(', ', $aliases)); ?></div><?php endif; ?>
                    <?php if (!empty($department['notes'])): ?><div><strong>Notes:</strong> <?php echo e((string)$department['notes']); ?></div><?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="org-dept-card__actions">
                <button type="button"
                        class="org-view__button"
                        data-department="<?php echo e(json_encode($departmentPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)); ?>"
                        onclick="openDepartmentModal(this)">
                  Edit
                </button>
                <button type="button" class="org-view__button" onclick="showAddManualLinkModal('department', <?php echo $deptId; ?>)">Add Link</button>
              </div>
            </div>

            <div class="org-dept-card__body">
              <div>
                <div class="org-dept-card__section-title">Department Contacts</div>
                <?php if (empty($departmentContacts[$deptId])): ?>
                  <div style="color:var(--muted);font-size:13px">No contacts assigned. Contacts can stay organization-only.</div>
                <?php else: ?>
                  <div style="display:grid;gap:6px">
                    <?php foreach ($departmentContacts[$deptId] as $contact): ?>
                      <div class="org-dept-contact-row">
                        <span class="org-dept-contact-row__identity">
                          <strong><?php echo e((string)$contact['name']); ?></strong>
                          <?php if (!empty($contact['is_primary'])): ?><span style="margin-left:6px;padding:2px 6px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11px;font-weight:700">Primary</span><?php endif; ?>
                          <?php if (!empty($contact['email'])): ?><span class="org-dept-contact-row__email"> <?php echo e((string)$contact['email']); ?></span><?php endif; ?>
                        </span>
                        <div class="org-dept-contact-row__actions">
                          <?php if (empty($contact['is_primary'])): ?>
                            <form method="post" action="/?page=organization/organization-departments" style="margin:0">
                              <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                              <input type="hidden" name="action" value="set_primary_contact">
                              <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
                              <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
                              <input type="hidden" name="client_id" value="<?php echo (int)$contact['id']; ?>">
                              <button type="submit" style="padding:4px 8px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;color:#166534;font-size:12px">Make Primary</button>
                            </form>
                          <?php endif; ?>
                          <form method="post" action="/?page=organization/organization-departments" style="margin:0">
                            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="remove_contact">
                            <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
                            <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
                            <input type="hidden" name="client_id" value="<?php echo (int)$contact['id']; ?>">
                            <button type="submit" style="padding:4px 8px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c;font-size:12px">Remove</button>
                          </form>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <form method="post" action="/?page=organization/organization-departments" class="org-dept-contact-assignment">
                  <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                  <input type="hidden" name="action" value="assign_contact">
                  <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
                  <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
                  <select name="client_id">
                    <option value="">Assign org contact...</option>
                    <?php foreach ($clients as $client): ?>
                      <option value="<?php echo (int)$client['id']; ?>"><?php echo e((string)$client['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label>
                    <input type="checkbox" name="is_primary" value="1">
                    Primary
                  </label>
                  <button type="submit" class="btn btn-sm">Assign</button>
                </form>
              </div>
              <div>
                <div class="org-dept-card__section-title">Department Links</div>
                <?php if (empty($departmentLinks[$deptId])): ?>
                  <div style="color:var(--muted);font-size:13px">No department links yet.</div>
                <?php else: ?>
                  <div style="display:grid;gap:6px">
                    <?php foreach ($departmentLinks[$deptId] as $link): ?>
                      <a href="<?php echo e((string)$link['url']); ?>" target="_blank" rel="noopener" style="display:block;border:1px solid #eef2f7;border-radius:8px;padding:8px;text-decoration:none;color:inherit">
                        <strong><?php echo e((string)($link['title'] ?: $link['link_type'])); ?></strong>
                        <?php if (!empty($link['include_on_invoices'])): ?><span style="font-size:12px;color:#1d4ed8"> · invoices</span><?php endif; ?>
                        <span style="display:block;font-size:12px;color:var(--muted);word-break:break-all"><?php echo e((string)$link['url']); ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <details style="margin:0 16px 14px;border-top:1px solid #e5e7eb;padding-top:12px">
              <summary style="cursor:pointer;font-weight:700">Assigned services</summary>
              <div style="margin-top:12px">
                <?php
                $portalServiceAssignmentSubjectType = 'department';
                $portalServiceAssignmentSubjectId = $deptId;
                $portalServiceAssignmentEntityPermission = 'organizations.manage';
                include __DIR__ . '/../../components/portal_service_assignments.php';
                ?>
              </div>
            </details>

            <form method="post" action="/?page=organization/organization-departments" onsubmit="return confirm('Delete this department? Contacts and clients will not be deleted.')" style="padding:0 16px 14px">
              <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="delete_department">
              <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
              <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
              <button type="submit" style="padding:6px 10px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c;font-size:small">Delete Department</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

    </main>
  </div>

  <?php if($portalLoginConfigured):$portalRootActive=(string)($portalRootAccess['access_state']??'active')==='active';?>
    <section class="org-card org-danger-zone" aria-labelledby="organizationPortalAccessTitle">
      <div class="org-danger-zone__head">
        <div>
          <h3 class="org-card__title" id="organizationPortalAccessTitle" style="margin-bottom:5px">Portal access</h3>
          <div class="org-danger-zone__description"><?=$portalRootActive?'Revoke portal login only when this organization should no longer be able to use it. Eligible contacts still need a verified sign-in and explicit content grant.':'Portal login is revoked. Project Alpha records are preserved.'?></div>
        </div>
        <?php if($canManagePortalLogin):?>
          <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('<?=$portalRootActive?'Revoke portal login for this organization and tombstone its workspace?':'Restore portal login and re-evaluate eligible organization contacts?'?>')">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="action" value="set-client-portal-root"><input type="hidden" name="return_to" value="organization-view"><input type="hidden" name="organization_id" value="<?=$id?>"><input type="hidden" name="root_type" value="organization"><input type="hidden" name="root_public_id" value="<?=htmlspecialchars((string)$org['public_id'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="access_state" value="<?=$portalRootActive?'revoked':'active'?>">
            <button class="org-view__button org-view__button--danger"><?=$portalRootActive?'Revoke portal login':'Restore portal login'?></button>
          </form>
        <?php endif;?>
      </div>
    </section>
  <?php endif;?>
</section>

<div id="departmentModal" class="org-department-modal" role="dialog" aria-modal="true" aria-labelledby="departmentModalTitle" aria-hidden="true">
  <div class="org-department-modal__dialog" tabindex="-1">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px">
      <h3 id="departmentModalTitle" style="margin:0">Add Department</h3>
      <button type="button" onclick="closeDepartmentModal()" aria-label="Close add department" style="border:0;background:#fff;font-size:24px;line-height:1;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <form id="departmentForm" method="post" action="/?page=organization/organization-departments" style="display:grid;gap:12px">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="action" value="save_department">
      <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="department_id" id="departmentIdInput" value="">
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Department Name</div>
        <input name="name" id="departmentNameInput" required placeholder="Department name" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
      </label>
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Folder Name</div>
        <input name="folder_name" id="departmentFolderInput" placeholder="Folder name" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
      </label>
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Resolver Mode</div>
        <select name="resolver_mode" id="departmentResolverInput" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
          <option value="manual_only">Manual links only</option>
          <option value="review">Review matches</option>
          <option value="auto_attach" selected>Auto attach exact matches</option>
          <option value="excluded">Exclude from resolver</option>
        </select>
      </label>
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Folder Aliases <span style="font-weight:400;color:var(--muted)">(one per line)</span></div>
        <textarea name="folder_aliases" id="departmentAliasesInput" rows="3" placeholder="Alternate folder name&#10;Short folder name" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px"></textarea>
      </label>
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Notes</div>
        <textarea name="notes" id="departmentNotesInput" rows="2" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px"></textarea>
      </label>
      <div style="display:flex;gap:10px;margin-top:4px">
        <button type="submit" class="org-view__button org-view__button--primary" style="flex:1">Save Department</button>
        <button type="button" onclick="closeDepartmentModal()" class="org-view__button" style="flex:1">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="organizationViewData"
     data-org-id="<?php echo $id; ?>"
     data-csrf="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"
     data-available-clients="<?php echo htmlspecialchars(json_encode($availableClients), ENT_QUOTES, 'UTF-8'); ?>"
     hidden></div>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/organization-view-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
