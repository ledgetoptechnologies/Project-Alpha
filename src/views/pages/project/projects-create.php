<?php
// src/views/pages/project/projects-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$activeOrgId = request_client_org_id();
$activeOrgName = '';
$activeOrgId = (int)$activeOrgId;
if ($activeOrgId > 0) {
    try {
        $activeOrgStmt = $pdo->prepare('SELECT name FROM organizations WHERE id = ? LIMIT 1');
        $activeOrgStmt->execute([$activeOrgId]);
        $activeOrgName = (string)($activeOrgStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $activeOrgName = '';
    }
}
$isAdmin = (($_SESSION['user']['role'] ?? '') === 'admin');
$projectManagers = $pdo->query(
    "SELECT u.id,
            COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) AS name
     FROM users u
     LEFT JOIN worker_profiles wp ON wp.user_id=u.id
     LEFT JOIN team_members tm ON tm.user_id=u.id
     WHERE u.is_disabled=0 AND u.deleted_at IS NULL
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);
$defaultManagerUserId = $userId;
if ($isAdmin) {
    $clients = $pdo->query('SELECT id, name, email FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} elseif ($activeOrgId > 0) {
    if (acl_user_has_org_wide_scope($pdo, $userId, $activeOrgId)) {
        $stmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND archived = 0
            ORDER BY name
        ');
        $stmt->execute([$activeOrgId]);
    } else {
        $stmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND created_by = ? AND archived = 0
            ORDER BY name
        ');
        $stmt->execute([$activeOrgId, $userId]);
    }
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id IS NULL AND created_by = ? AND archived = 0
        ORDER BY name
    ');
    $stmt->execute([$userId]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Organizations are selected via search in the form; no need to prefetch list.
// no parent projects in this view — no need to fetch projects list

$departments = [];
if ($activeOrgId > 0) {
    $deptStmt = $pdo->prepare('
        SELECT id, name, folder_name
        FROM organization_departments
        WHERE organization_id = ?
        ORDER BY name
    ');
    $deptStmt->execute([$activeOrgId]);
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<section>
  <h2>Create Project</h2>
  <h3 id="projectNamePreview" style="margin-top:8px;margin-bottom:16px;color:#333;font-size:18px"></h3>
  <style>
    .project-client-picker {
      border: 1px solid #d1d5db;
      border-radius: 8px;
      background: #fff;
      overflow: hidden;
    }
    .project-client-picker__selected {
      display: grid;
      gap: 6px;
      padding: 8px;
      min-height: 44px;
      border-bottom: 1px solid #edf2f7;
      background: #f8fafc;
    }
    .project-client-picker__empty {
      color: var(--muted);
      font-size: 13px;
      padding: 4px 2px;
    }
    .project-client-picker__item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      padding: 8px 10px;
      border: 1px solid #dbe3ef;
      border-radius: 8px;
      background: #fff;
    }
    .project-client-picker__name {
      font-weight: 700;
      color: #111827;
    }
    .project-client-picker__meta {
      display: block;
      margin-top: 2px;
      color: var(--muted);
      font-size: 12px;
    }
    .project-client-picker__remove {
      width: 26px;
      height: 26px;
      border: 1px solid #fecaca;
      border-radius: 999px;
      background: #fff;
      color: #b91c1c;
      cursor: pointer;
      font-weight: 800;
      line-height: 1;
    }
    .project-client-picker__search {
      position: relative;
      padding: 8px;
    }
    .project-client-picker__suggestions {
      display: none;
      position: absolute;
      z-index: 70;
      left: 8px;
      right: 8px;
      top: calc(100% - 4px);
      max-height: 220px;
      overflow: auto;
      border: 1px solid #dbe3ef;
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 12px 28px rgba(15,23,42,0.12);
    }
    .project-client-picker__suggestion {
      padding: 9px 10px;
      cursor: pointer;
      border-bottom: 1px solid #f1f5f9;
    }
    .project-client-picker__suggestion:last-child {
      border-bottom: 0;
    }
    .project-client-picker__suggestion:hover {
      background: #f8fafc;
    }
    .project-client-picker__badge {
      display: inline-flex;
      margin-left: 6px;
      padding: 1px 6px;
      border-radius: 999px;
      background: #dcfce7;
      color: #166534;
      font-size: 11px;
      font-weight: 700;
    }
  </style>
  <form method="post" action="/?page=project/projects-create" style="display:grid;gap:12px;max-width:680px">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="project_invoice_recipients_present" value="1">
    
    <label>
      <div>Project Name</div>
      <input id="projectNameInput" type="text" name="name" required placeholder="Project name" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>

    <label style="position:relative">
      <div>Organization</div>
      <input id="orgInputProject" type="text" name="organization_search" placeholder="Search organization..." autocomplete="off" value="<?php echo htmlspecialchars($activeOrgName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      <input type="hidden" name="organization_id" id="organization_id_create" value="<?php echo $activeOrgId > 0 ? (int)$activeOrgId : ''; ?>">
      <div id="orgSuggestProject" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:220px;overflow:auto"></div>
    </label>

    <label>
      <div>Department</div>
      <select id="projectDepartmentSelect" name="department_id" data-empty-label="No department / org-level project" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
        <option value="">No department / org-level project</option>
        <?php foreach ($departments as $department): ?>
          <option value="<?php echo (int)$department['id']; ?>">
            <?php echo htmlspecialchars($department['name'] . (!empty($department['folder_name']) ? ' — ' . $department['folder_name'] : '')); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">
        Choose a department only when this project belongs to a specific group within the organization. Leave blank for organization-level work.
      </div>
    </label>

    <label>
      <div>Project Manager</div>
      <select id="projectManagerSelect" name="manager_user_id" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
        <option value="">Unassigned</option>
        <?php foreach ($projectManagers as $manager): ?>
          <option value="<?php echo (int)$manager['id']; ?>" <?php echo $defaultManagerUserId === (int)$manager['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$manager['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">The manager is added to the Project Team.</div>
    </label>


     <label style="grid-column:1/2;position:relative">
        <div>Primary billed contact</div>
        <input id="clientInput" type="text" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientId" type="hidden" name="client_id">
        <div id="clientSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>

    <label>
      <div>Additional Project Clients</div>
      <div class="project-client-picker" data-project-client-picker="project" data-empty-text="No additional clients selected.">
        <div class="project-client-picker__selected" data-picker-selected></div>
        <div class="project-client-picker__search">
          <input type="text" class="input" data-picker-search placeholder="Select an organization first" autocomplete="off" disabled>
          <div class="project-client-picker__suggestions" data-picker-suggestions></div>
        </div>
        <div data-picker-hidden></div>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">Search and add only the extra clients who should be part of this project. The primary client above is attached automatically.</div>
    </label>

    <label>
      <div>Project Invoice Email Recipients</div>
      <div class="project-client-picker" data-project-client-picker="invoice" data-empty-text="No invoice email recipients selected.">
        <div class="project-client-picker__selected" data-picker-selected></div>
        <div class="project-client-picker__search">
          <input type="text" class="input" data-picker-search placeholder="Select an organization first" autocomplete="off" disabled>
          <div class="project-client-picker__suggestions" data-picker-suggestions></div>
        </div>
        <div data-picker-hidden></div>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">Choose one or more saved contacts from this organization. The primary billed contact is added by default when it has an email, but can be removed after another delivery recipient is selected.</div>
    </label>

    <label style="display:flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid #dbe3ef;border-radius:8px">
      <input id="projectOrganizationInvoiceEmail" type="checkbox" name="project_invoice_use_organization_email" value="1" disabled style="margin-top:3px">
      <span>
        <strong>Company email</strong>
        <small id="projectOrganizationInvoiceEmailHelp" style="display:block;color:var(--muted)">Select an organization with a saved company email.</small>
      </span>
    </label>

    <!-- Parent projects removed per spec: Projects have no parents -->

    <div style="display:flex;gap:8px">
      <label style="flex:1">
        <div>Estimated Start</div>
        <input type="date" name="estimated_start" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      </label>
      <label style="flex:1">
        <div>Estimated End</div>
        <input type="date" name="estimated_end" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      </label>
    </div>

    <?php if (!empty($appConfig['job_project_locations_enabled'])): $projectServiceLocations=$pdo->query('SELECT id,name,city,state FROM service_locations WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); ?>
    <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:12px"><legend>Service locations</legend>
      <p class="muted">A Project may include several locations. Documents use the default unless their Job selects another allowed location.</p>
      <label><div>Allowed locations</div><select name="service_location_ids[]" multiple size="5" style="width:100%;padding:8px"><?php foreach($projectServiceLocations as $location): ?><option value="<?php echo (int)$location['id']; ?>"><?php echo htmlspecialchars($location['name'].($location['city']?' — '.$location['city'].', '.$location['state']:'')); ?></option><?php endforeach; ?></select></label>
      <label><div>Default location</div><select name="default_service_location_id" style="width:100%;padding:8px"><option value="">No default</option><?php foreach($projectServiceLocations as $location): ?><option value="<?php echo (int)$location['id']; ?>"><?php echo htmlspecialchars($location['name']); ?></option><?php endforeach; ?></select></label>
    </fieldset>
    <?php endif; ?>

    <div style="padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Project Invoice Billing</div>
      <label style="display:block;margin-bottom:8px">
        <div>Billing Period</div>
        <select name="invoice_billing_period" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
          <option value="monthly" selected>Monthly project billing</option>
          <option value="per_invoice">Each invoice is due on its own terms</option>
        </select>
      </label>
      <label>
        <div>Project NET Days</div>
        <input type="number" name="invoice_net_terms_days" min="0" step="1" placeholder="Use system default" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      </label>
      <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px">
        <input type="checkbox" name="project_invoice_auto_email" value="1" checked style="margin-top:3px">
        <span>
          <span style="font-weight:600">Auto-email monthly project invoices</span>
          <span style="display:block;font-size:12px;color:var(--muted)">Cron-generated project invoices are emailed to the selected project invoice recipients.</span>
        </span>
      </label>
      <div style="font-size:13px;color:#4b5563;margin-top:8px">Monthly project billing sets invoice due dates from the end of the work month plus NET days.</div>
    </div>

    <label>
      <div>Notes</div>
      <textarea name="notes" rows="5" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%" placeholder="Optional project notes"></textarea>
    </label>

    <div>
      <button type="submit" style="padding:8px 12px;border-radius:8px;background:var(--nav-accent);border:0;color:#fff">Create Project</button>
      <a href="/?page=project/projects-list" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;margin-left:8px;">Cancel</a>
    </div>
  </form>
</section>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/client-selection-dropdown-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/project-form.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
