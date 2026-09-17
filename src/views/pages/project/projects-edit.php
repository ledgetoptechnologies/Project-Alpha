<?php
// src/views/pages/project/projects-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../../utils/public_project_links.php';
require_once __DIR__ . '/../../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../../config/app.php';

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) {
    header('Location: /?page=project/projects-list');
    exit;
}
require_record_ownership($pdo, 'projects', $projectId);
pa_project_public_link_ensure_schema($pdo);

$stmt = $pdo->prepare('
    SELECT p.*, c.name AS client_name, o.name AS organization_name,
           o.general_email AS organization_general_email, od.name AS department_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN organizations o ON o.id = p.organization_id
    LEFT JOIN organization_departments od ON od.id = p.department_id
    WHERE p.id = ?
');
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
    header('Location: /?page=project/projects-list');
    exit;
}
$projectServiceLocations=[];$projectSelectedLocationIds=[];$projectDefaultLocationId=0;
if(!empty($appConfig['job_project_locations_enabled'])){
  $projectServiceLocations=$pdo->query('SELECT id,name,city,state FROM service_locations WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
  $selectedLocations=$pdo->prepare('SELECT service_location_id,is_default FROM project_service_locations WHERE project_id=?');$selectedLocations->execute([$projectId]);
  foreach($selectedLocations->fetchAll(PDO::FETCH_ASSOC) as $selectedLocation){$projectSelectedLocationIds[]=(int)$selectedLocation['service_location_id'];if(!empty($selectedLocation['is_default']))$projectDefaultLocationId=(int)$selectedLocation['service_location_id'];}
}

$projectOrganizationId = (int)($project['organization_id'] ?? 0);
$businessUnits = $pdo->query('SELECT id,name,code,is_active FROM business_units ORDER BY is_active DESC,name,id')->fetchAll(PDO::FETCH_ASSOC);
$projectManagers = $pdo->query(
    "SELECT u.id,
            COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) AS name,
            (SELECT bum.business_unit_id FROM business_unit_memberships bum
             JOIN business_units bu ON bu.id=bum.business_unit_id AND bu.is_active=1
             WHERE bum.user_id=u.id AND bum.is_primary=1
               AND (bum.ended_at IS NULL OR bum.ended_at>CURRENT_TIMESTAMP)
             ORDER BY bum.id DESC LIMIT 1) AS primary_business_unit_id
     FROM users u
     LEFT JOIN worker_profiles wp ON wp.user_id=u.id
     LEFT JOIN team_members tm ON tm.user_id=u.id
     WHERE u.is_disabled=0 AND u.deleted_at IS NULL
        OR u.id=" . (int)($project['manager_user_id'] ?? 0) . "
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);
$projectDepartments = [];
if ($projectOrganizationId > 0) {
    $deptStmt = $pdo->prepare('
        SELECT id, name, folder_name
        FROM organization_departments
        WHERE organization_id = ?
        ORDER BY name
    ');
    $deptStmt->execute([$projectOrganizationId]);
    $projectDepartments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}

$projectClientsSendSelect = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices')
    ? 'pc.send_project_invoices'
    : '1 AS send_project_invoices';
$projectClientsLinkSelect = project_invoice_table_has_column($pdo, 'project_clients', 'can_view_invoice_links')
    ? 'pc.can_view_invoice_links'
    : '1 AS can_view_invoice_links';
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.email, pc.is_primary_billing, {$projectClientsSendSelect}, {$projectClientsLinkSelect}
    FROM project_clients pc
    JOIN clients c ON c.id = pc.client_id
    WHERE pc.project_id = ?
    ORDER BY pc.is_primary_billing DESC, pc.sort_order ASC, c.name ASC
");
$stmt->execute([$projectId]);
$projectClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($projectOrganizationId > 0) {
    $allClientsStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id = ? AND archived = 0
        ORDER BY name
    ');
    $allClientsStmt->execute([$projectOrganizationId]);
    $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $activeOrgId = request_client_org_id();
    if ($activeOrgId > 0) {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([$activeOrgId]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (($_SESSION['user']['role'] ?? '') === 'admin') {
        $allClients = $pdo->query('SELECT id, name, email FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id IS NULL AND created_by = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([(int)($_SESSION['user']['id'] ?? 0)]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$selectedProjectClientIds = array_map(static fn($row) => (int)$row['id'], $projectClients);
$savedProjectInvoiceRecipients = project_invoice_saved_recipients($pdo, $projectId);
if (project_invoice_table_has_column($pdo, 'project_invoice_recipients', 'recipient_key')) {
    $selectedProjectInvoiceRecipientIds = array_values(array_map(
        static fn($row) => (int)$row['id'],
        array_filter($savedProjectInvoiceRecipients, static fn($row) => !empty($row['id']))
    ));
    $useOrganizationInvoiceEmail = count(array_filter(
        $savedProjectInvoiceRecipients,
        static fn($row) => !empty($row['organization_id'])
    )) > 0;
} else {
    $selectedProjectInvoiceRecipientIds = array_map(
        static fn($row) => (int)$row['id'],
        array_values(array_filter($projectClients, static fn($row) => !empty($row['send_project_invoices'])))
    );
    $useOrganizationInvoiceEmail = false;
}
$selectedProjectInvoiceLinkClientIds = array_map(
    static fn($row) => (int)$row['id'],
    array_values(array_filter($projectClients, static fn($row) => !empty($row['can_view_invoice_links'])))
);

$projectDepartmentContactIds = [];
$projectDepartmentPrimaryContactIds = [];
if (!empty($project['department_id'])) {
    $deptContactStmt = $pdo->prepare('SELECT client_id, is_primary FROM organization_department_contacts WHERE department_id = ?');
    $deptContactStmt->execute([(int)$project['department_id']]);
    foreach ($deptContactStmt->fetchAll(PDO::FETCH_ASSOC) as $deptContact) {
        $clientId = (int)$deptContact['client_id'];
        $projectDepartmentContactIds[] = $clientId;
        if (!empty($deptContact['is_primary'])) {
            $projectDepartmentPrimaryContactIds[] = $clientId;
        }
    }
}

$projectSettingsClients = [];
$projectSettingsClientIds = [];
foreach ($allClients as $client) {
    $clientId = (int)$client['id'];
    $projectSettingsClientIds[$clientId] = true;
    $projectSettingsClients[] = [
        'id' => $clientId,
        'name' => (string)($client['name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'is_selected' => in_array($clientId, $selectedProjectClientIds, true) ? 1 : 0,
        'is_invoice_recipient' => in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 1 : 0,
        'can_view_links' => in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 1 : 0,
        'is_primary' => (int)($project['client_id'] ?? 0) === $clientId ? 1 : 0,
        'is_department_contact' => in_array($clientId, $projectDepartmentContactIds, true) ? 1 : 0,
        'is_primary_department_contact' => in_array($clientId, $projectDepartmentPrimaryContactIds, true) ? 1 : 0,
    ];
}
foreach ($projectClients as $client) {
    $clientId = (int)$client['id'];
    if (isset($projectSettingsClientIds[$clientId])) {
        continue;
    }
    $projectSettingsClients[] = [
        'id' => $clientId,
        'name' => (string)($client['name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'is_selected' => 1,
        'is_invoice_recipient' => in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 1 : 0,
        'can_view_links' => in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 1 : 0,
        'is_primary' => (int)($project['client_id'] ?? 0) === $clientId ? 1 : 0,
        'is_department_contact' => in_array($clientId, $projectDepartmentContactIds, true) ? 1 : 0,
        'is_primary_department_contact' => in_array($clientId, $projectDepartmentPrimaryContactIds, true) ? 1 : 0,
    ];
}
foreach ($savedProjectInvoiceRecipients as $recipient) {
    $clientId = (int)($recipient['id'] ?? 0);
    if ($clientId <= 0 || isset($projectSettingsClientIds[$clientId])) {
        continue;
    }
    $projectSettingsClientIds[$clientId] = true;
    $projectSettingsClients[] = [
        'id' => $clientId,
        'name' => (string)($recipient['name'] ?? ''),
        'email' => (string)($recipient['email'] ?? ''),
        'is_selected' => 0,
        'is_invoice_recipient' => 1,
        'can_view_links' => 0,
        'is_primary' => 0,
        'is_department_contact' => 0,
        'is_primary_department_contact' => 0,
    ];
}

$currentBillingPeriod = (string)($project['invoice_billing_period'] ?? 'per_invoice');
$autoEmailEnabled = $currentBillingPeriod === 'monthly'
    && (!array_key_exists('project_invoice_auto_email', $project) || !empty($project['project_invoice_auto_email']));
$billingTransitionImpact = [
    'count' => 0,
    'draft_count' => 0,
    'finalized_count' => 0,
    'balance' => 0.0,
    'statement_count' => 0,
    'statement_balance' => 0.0,
];
try {
    $impactStmt = $pdo->prepare('
        SELECT i.status, i.total,
               COALESCE((
                   SELECT SUM(
                       CASE
                           WHEN pay.amount - COALESCE(pay.refunded_amount, 0) - COALESCE(pay.disputed_amount, 0) > 0
                           THEN pay.amount - COALESCE(pay.refunded_amount, 0) - COALESCE(pay.disputed_amount, 0)
                           ELSE 0
                       END
                   )
                   FROM payments pay
                   WHERE pay.invoice_id = i.id AND pay.status = "succeeded"
               ), 0) AS effective_amount_paid
        FROM invoices i
        LEFT JOIN project_invoice_items pii ON pii.invoice_id = i.id
        WHERE i.project_id = ?
          AND COALESCE(i.collection_mode, "direct") = "project_aggregate"
          AND pii.invoice_id IS NULL
          AND i.status NOT IN ("paid", "void", "cancelled")
    ');
    $impactStmt->execute([$projectId]);
    foreach ($impactStmt->fetchAll(PDO::FETCH_ASSOC) as $impactInvoice) {
        $balance = max(0.0, (float)($impactInvoice['total'] ?? 0) - (float)($impactInvoice['effective_amount_paid'] ?? 0));
        if ($balance <= 0.005) {
            continue;
        }
        $billingTransitionImpact['count']++;
        $billingTransitionImpact['balance'] += $balance;
        if (strtolower((string)($impactInvoice['status'] ?? '')) === 'draft') {
            $billingTransitionImpact['draft_count']++;
        } else {
            $billingTransitionImpact['finalized_count']++;
        }
    }
    $statementImpactStmt = $pdo->prepare('
        SELECT COUNT(*) AS statement_count, COALESCE(SUM(balance_due), 0) AS statement_balance
        FROM project_invoices
        WHERE project_id = ? AND status NOT IN ("paid", "void", "cancelled")
    ');
    $statementImpactStmt->execute([$projectId]);
    $statementImpact = $statementImpactStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $billingTransitionImpact['statement_count'] = (int)($statementImpact['statement_count'] ?? 0);
    $billingTransitionImpact['statement_balance'] = (float)($statementImpact['statement_balance'] ?? 0);
} catch (Throwable $billingTransitionLoadError) {
    error_log('[projects-edit] billing transition preview unavailable: ' . $billingTransitionLoadError->getMessage());
}
$publicProjectUrl = pa_project_public_url($appConfig ?? [], (string)($project['public_project_token'] ?? ''));
$publicProjectHasCode = trim((string)($project['public_project_password_hash'] ?? '')) !== '';
?>

<style>
.project-edit-page{max-width:1120px;margin:0 auto;padding:24px}.project-edit-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.project-edit-header h1{margin:0;font-size:26px}.project-edit-subtitle{margin-top:4px;color:var(--muted);font-size:13px}.project-edit-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px;align-items:start}.project-edit-nav{position:sticky;top:16px;display:grid;gap:8px;border:1px solid #dfe3e8;border-radius:8px;background:#fff;padding:10px}.project-edit-nav a{padding:9px 10px;border-radius:6px;text-decoration:none;color:#111827;font-weight:650;font-size:13px}.project-edit-nav a:hover{background:#f3f4f6}.project-edit-form{display:grid;gap:16px}.project-edit-section{border:1px solid #dfe3e8;border-radius:8px;background:#fff;padding:16px;display:grid;gap:12px}.project-edit-section h2{margin:0;font-size:17px}.project-edit-section p{margin:0;color:var(--muted);font-size:13px;line-height:1.45}.project-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.project-field{display:grid;gap:5px}.project-field>span,.project-field>div:first-child{font-size:13px;font-weight:700}.project-field input,.project-field select,.project-field textarea{width:100%;padding:9px 10px;border:1px solid #cfd5dc;border-radius:6px;background:#fff}.project-field small{color:var(--muted);font-size:12px;line-height:1.4}.project-check{display:flex;align-items:flex-start;gap:8px;font-size:13px}.project-check input{width:auto;margin-top:2px}.project-info-box{padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;color:#1e3a8a;font-size:12px;line-height:1.45}.project-pill{display:inline-flex;align-items:center;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;background:#dbeafe;color:#1d4ed8;white-space:nowrap}.project-pill--primary{background:#dcfce7;color:#166534}.project-settings-picker{display:grid;gap:8px}.project-settings-picker__selected{display:grid;gap:8px;min-height:42px}.project-settings-picker__empty{padding:10px;border:1px dashed #d1d5db;border-radius:8px;color:var(--muted);background:#fff;font-size:13px}.project-settings-picker__item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fff}.project-settings-picker__name{font-weight:700}.project-settings-picker__meta{display:block;color:var(--muted);font-size:12px;margin-top:2px}.project-settings-picker__remove{border:0;background:#f3f4f6;color:#111827;border-radius:999px;width:28px;height:28px;cursor:pointer;font-weight:800}.project-settings-picker__search{position:relative}.project-settings-picker__suggestions{position:absolute;left:0;right:0;top:100%;z-index:40;display:none;max-height:210px;overflow:auto;border:1px solid #dfe3e8;border-radius:8px;background:#fff;box-shadow:0 12px 24px rgba(15,23,42,.12)}.project-settings-picker__suggestion{padding:9px 10px;border-bottom:1px solid #eef2f7;cursor:pointer}.project-settings-picker__suggestion:hover{background:#f8fafc}.project-edit-actions{position:sticky;bottom:12px;z-index:5;display:flex;justify-content:flex-end;gap:8px;padding:12px;border:1px solid #dfe3e8;border-radius:8px;background:rgba(255,255,255,.96);box-shadow:0 10px 24px rgba(15,23,42,.12)}@media(max-width:900px){.project-edit-layout{grid-template-columns:1fr}.project-edit-nav{position:static;display:flex;flex-wrap:wrap}.project-edit-grid{grid-template-columns:1fr}}@media(max-width:640px){.project-edit-page{padding:16px}.project-edit-header{display:grid}.project-edit-actions{position:static;display:grid}}
.project-billing-transition{display:grid;gap:12px;padding:14px;border:1px solid #f59e0b;border-radius:8px;background:#fffbeb}.project-billing-transition[hidden]{display:none}.project-billing-transition__summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.project-billing-transition__metric{padding:10px;border:1px solid #fde68a;border-radius:7px;background:#fff}.project-billing-transition__metric strong{display:block;font-size:18px}.project-billing-choice{display:flex;align-items:flex-start;gap:9px;padding:11px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}.project-billing-choice input{margin-top:3px}.project-billing-choice small{display:block;margin-top:3px;color:var(--muted);line-height:1.4}.project-billing-link-note{padding:10px 12px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;color:#166534;font-size:12px;line-height:1.45}@media(max-width:640px){.project-billing-transition__summary{grid-template-columns:1fr}}
</style>

<div class="project-edit-page">
  <div class="project-edit-header">
    <div>
      <a href="/?page=project/projects-details&amp;id=<?php echo $projectId; ?>" style="color:var(--nav-accent);text-decoration:none;font-size:14px">Back to Project</a>
      <h1>Edit Project</h1>
      <div class="project-edit-subtitle"><?php echo htmlspecialchars((string)$project['name']); ?></div>
    </div>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:14px"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <?php echo pricing_adjustment_assignment_controls($pdo,$projectOrganizationId,'project',$projectId,'/?page=project/projects-edit&id='.$projectId,csrf_token()); ?>

  <div class="project-edit-layout">
    <nav class="project-edit-nav" aria-label="Project edit sections">
      <a href="#project-basics">Basics</a>
      <a href="#project-contacts">Contacts</a>
      <a href="#project-billing">Invoice Defaults</a>
      <a href="#project-schedule">Schedule &amp; Notes</a>
      <a href="#project-public-link">Public Link</a>
    </nav>

    <form method="post" action="/?page=project/projects-update" class="project-edit-form" data-project-billing-transition>
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo $projectId; ?>">
      <input type="hidden" name="organization_id" value="<?php echo (int)($project['organization_id'] ?? 0); ?>">

      <section id="project-basics" class="project-edit-section">
        <h2>Basics</h2>
        <p>Set the project name and its organization or department context.</p>
        <div class="project-edit-grid">
          <label class="project-field">
            <span>Project Name</span>
            <input name="name" required value="<?php echo htmlspecialchars((string)$project['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          </label>
          <label class="project-field">
            <span>Organization</span>
            <input value="<?php echo htmlspecialchars((string)($project['organization_name'] ?? 'No organization'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" disabled>
          </label>
        </div>
        <?php if (!empty($appConfig['job_project_locations_enabled'])): ?>
        <div class="project-edit-grid"><label class="project-field"><span>Allowed service locations</span><select name="service_location_ids[]" multiple size="5"><?php foreach($projectServiceLocations as $location): ?><option value="<?php echo (int)$location['id']; ?>" <?php echo in_array((int)$location['id'],$projectSelectedLocationIds,true)?'selected':''; ?>><?php echo htmlspecialchars($location['name'].($location['city']?' — '.$location['city'].', '.$location['state']:'')); ?></option><?php endforeach; ?></select></label><label class="project-field"><span>Default service location</span><select name="default_service_location_id"><option value="">No default</option><?php foreach($projectServiceLocations as $location): ?><option value="<?php echo (int)$location['id']; ?>" <?php echo $projectDefaultLocationId===(int)$location['id']?'selected':''; ?>><?php echo htmlspecialchars($location['name']); ?></option><?php endforeach; ?></select></label></div>
        <?php endif; ?>
        <label class="project-field">
          <span>Department</span>
          <select name="department_id">
            <option value="">No department / org-level project</option>
            <?php foreach ($projectDepartments as $department): ?>
              <?php $departmentId = (int)$department['id']; ?>
              <option value="<?php echo $departmentId; ?>" <?php echo (int)($project['department_id'] ?? 0) === $departmentId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($department['name'] . (!empty($department['folder_name']) ? ' - ' . $department['folder_name'] : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small>Department selection controls department link inheritance for project invoices.</small>
        </label>
        <label class="project-field">
          <span>Project Manager</span>
          <select id="projectManagerSelect" name="manager_user_id">
            <option value="">Unassigned</option>
            <?php foreach ($projectManagers as $manager): ?>
              <option value="<?php echo (int)$manager['id']; ?>" data-primary-business-unit="<?php echo (int)($manager['primary_business_unit_id'] ?? 0); ?>" <?php echo (int)($project['manager_user_id'] ?? 0) === (int)$manager['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$manager['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <small>The manager is always kept on the Project Team. Changing the manager does not remove the former manager.</small>
          <small id="projectManagerUnitSuggestion"></small>
        </label>
        <label class="project-field">
          <span>Business Unit / Division</span>
          <select id="projectBusinessUnitSelect" name="business_unit_id" data-project-current-unit="<?php echo (int)($project['business_unit_id'] ?? 0); ?>">
            <option value="">Unassigned</option>
            <?php foreach ($businessUnits as $businessUnit): ?>
              <option value="<?php echo (int)$businessUnit['id']; ?>" <?php echo (int)($project['business_unit_id'] ?? 0) === (int)$businessUnit['id'] ? 'selected' : ''; ?> <?php echo empty($businessUnit['is_active']) && (int)($project['business_unit_id'] ?? 0) !== (int)$businessUnit['id'] ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)$businessUnit['name'] . (!empty($businessUnit['code']) ? ' (' . (string)$businessUnit['code'] . ')' : '') . (empty($businessUnit['is_active']) ? ' - inactive' : '')); ?></option>
            <?php endforeach; ?>
          </select>
          <input id="projectBusinessUnitTouched" type="hidden" name="business_unit_user_selected" value="0">
          <small>Operations and Tasks inherit the Project's business unit automatically.</small>
        </label>
      </section>

      <section id="project-contacts" class="project-edit-section" data-project-settings-contact-manager>
        <h2>Contacts</h2>
        <p>The primary billed contact is kept for invoice ownership and history. Delivery recipients are selected separately from saved contacts in this organization or its company email.</p>
        <input type="hidden" name="project_invoice_recipients_present" value="1">
        <script type="application/json" data-project-settings-clients><?php echo json_encode($projectSettingsClients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
        <div class="project-info-box">Primary department contacts are marked when the current department has one saved on the organization.</div>
        <label class="project-field">
          <span>Primary billed contact</span>
          <select name="client_id" data-project-primary-select></select>
          <small>This client appears as the primary billed contact for generated project invoices.</small>
        </label>
        <div class="project-edit-grid">
          <div class="project-settings-picker" data-project-settings-picker="project" data-empty-text="No project contacts selected.">
            <div class="label">Project contacts</div>
            <div class="project-settings-picker__selected" data-picker-selected></div>
            <div class="project-settings-picker__search">
              <input type="text" class="input" data-picker-search placeholder="Type a client name or email..." autocomplete="off">
              <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
            </div>
            <div data-picker-hidden></div>
          </div>
          <div class="project-settings-picker" data-project-settings-picker="invoice" data-empty-text="No invoice email recipients selected.">
            <div class="label">Project invoice email recipients</div>
            <div class="project-settings-picker__selected" data-picker-selected></div>
            <div class="project-settings-picker__search">
              <input type="text" class="input" data-picker-search placeholder="Type a client name or email..." autocomplete="off">
              <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
            </div>
            <div data-picker-hidden></div>
          </div>
        </div>
        <?php $organizationGeneralEmail = trim((string)($project['organization_general_email'] ?? '')); ?>
        <label class="project-check" style="padding:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fff">
          <input type="checkbox" name="project_invoice_use_organization_email" value="1" <?php echo $useOrganizationInvoiceEmail ? 'checked' : ''; ?> <?php echo filter_var($organizationGeneralEmail, FILTER_VALIDATE_EMAIL) ? '' : 'disabled'; ?>>
          <span>
            <strong>Company email</strong>
            <small style="display:block;color:var(--muted)"><?php echo $organizationGeneralEmail !== '' ? htmlspecialchars($organizationGeneralEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' — select explicitly to include it.' : 'No company email is saved for this organization.'; ?></small>
          </span>
        </label>
        <div class="project-settings-picker" data-project-settings-picker="links" data-empty-text="No invoice content-link viewers selected.">
          <div class="label">Invoice content-link viewers</div>
          <div class="project-settings-picker__selected" data-picker-selected></div>
          <div class="project-settings-picker__search">
            <input type="text" class="input" data-picker-search placeholder="Type a client name or email..." autocomplete="off">
            <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
          </div>
          <div data-picker-hidden></div>
        </div>
      </section>

      <section id="project-billing" class="project-edit-section">
        <h2>Invoice Defaults</h2>
        <p>These settings control generated project invoices and automatic delivery.</p>
        <div class="project-edit-grid">
          <label class="project-field">
            <span>Billing Period</span>
            <select name="invoice_billing_period" data-project-billing-period data-original-billing-period="<?php echo htmlspecialchars($currentBillingPeriod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
              <option value="monthly" <?php echo ($project['invoice_billing_period'] ?? 'monthly') === 'monthly' ? 'selected' : ''; ?>>Monthly project billing</option>
              <option value="per_invoice" <?php echo ($project['invoice_billing_period'] ?? '') === 'per_invoice' ? 'selected' : ''; ?>>Each invoice on its own</option>
            </select>
            <input type="hidden" name="invoice_billing_period_original" value="<?php echo htmlspecialchars($currentBillingPeriod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <input type="hidden" name="monthly_auto_email_confirmed" value="0" data-monthly-auto-email-confirmed>
          </label>
          <label class="project-field">
            <span>Project NET Days</span>
            <input type="number" min="0" step="1" name="invoice_net_terms_days" value="<?php echo htmlspecialchars((string)($project['invoice_net_terms_days'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="System default">
          </label>
        </div>
        <?php if ($currentBillingPeriod === 'monthly' || (int)$billingTransitionImpact['count'] > 0): ?>
        <fieldset class="project-billing-transition" data-billing-transition-panel data-has-unresolved="<?php echo (int)$billingTransitionImpact['count'] > 0 ? '1' : '0'; ?>">
          <legend style="font-weight:800;padding:0 5px"><?php echo $currentBillingPeriod === 'monthly' ? 'Review monthly invoices before switching' : 'Resolve invoices from previous monthly billing'; ?></legend>
          <p><?php echo $currentBillingPeriod === 'monthly' ? 'Changing to per-invoice billing affects future invoices.' : 'This Project already uses per-invoice billing, but invoices from its previous monthly setting still need a collection path.'; ?> Existing Project Invoices remain available and payable, and their public links are not revoked, rotated, or replaced.</p>
          <div class="project-billing-transition__summary" aria-label="Billing transition impact">
            <div class="project-billing-transition__metric"><strong><?php echo (int)$billingTransitionImpact['count']; ?></strong><span>unassigned monthly invoice(s)</span></div>
            <div class="project-billing-transition__metric"><strong>$<?php echo number_format((float)$billingTransitionImpact['balance'], 2); ?></strong><span>outstanding to resolve</span></div>
            <div class="project-billing-transition__metric"><strong><?php echo (int)$billingTransitionImpact['draft_count']; ?> / <?php echo (int)$billingTransitionImpact['finalized_count']; ?></strong><span>draft / finalized</span></div>
          </div>
          <?php if ((int)$billingTransitionImpact['statement_count'] > 0): ?>
            <div class="project-info-box"><?php echo (int)$billingTransitionImpact['statement_count']; ?> existing open Project Invoice(s), with $<?php echo number_format((float)$billingTransitionImpact['statement_balance'], 2); ?> due, remain unchanged and continue using their current links.</div>
          <?php endif; ?>
          <div style="font-weight:700">Resolve all unassigned monthly invoices together</div>
          <label class="project-billing-choice">
            <input type="radio" name="billing_transition_strategy" value="final_project_statement" checked>
            <span><strong>Create one final Project Invoice</strong><small>Collect every eligible unassigned monthly invoice into one closing statement before applying per-invoice billing.</small></span>
          </label>
          <label class="project-billing-choice">
            <input type="radio" name="billing_transition_strategy" value="convert_to_direct">
            <span><strong>Convert them to individual invoices</strong><small>Keep their existing numbers, balances, content, payments, and links, but make each invoice independently collectible.</small></span>
          </label>
          <div style="font-weight:700">Delivery</div>
          <label class="project-billing-choice">
            <input type="radio" name="delivery_action" value="review" checked>
            <span><strong>Review before sending</strong><small>Complete the transition without emailing clients automatically.</small></span>
          </label>
          <label class="project-billing-choice">
            <input type="radio" name="delivery_action" value="send_all">
            <span><strong>Send after the transition</strong><small>Email the resulting statement or converted invoices to valid saved recipients. Invalid recipients are reported before delivery.</small></span>
          </label>
          <div class="project-billing-link-note"><strong>Existing links stay valid.</strong> This billing preference change does not revoke, expire, regenerate, or replace existing invoice, Project Invoice, content, or payment links.</div>
        </fieldset>
        <?php else: ?>
          <input type="hidden" name="billing_transition_strategy" value="final_project_statement">
          <input type="hidden" name="delivery_action" value="review">
        <?php endif; ?>
        <label class="project-check" data-monthly-auto-email>
          <input type="checkbox" name="project_invoice_auto_email" value="1" <?php echo $autoEmailEnabled ? 'checked' : ''; ?>>
          <span>
            <strong>Automatically email monthly project invoices</strong>
            <small style="display:block;color:var(--muted)">Uses the selected project invoice email recipients after the monthly invoice is generated.</small>
          </span>
        </label>
      </section>

      <section id="project-schedule" class="project-edit-section">
        <h2>Schedule &amp; Notes</h2>
        <div class="project-edit-grid">
          <label class="project-field">
            <span>Estimated Start</span>
            <input type="date" name="estimated_start" value="<?php echo htmlspecialchars((string)($project['estimated_start'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          </label>
          <label class="project-field">
            <span>Estimated End</span>
            <input type="date" name="estimated_end" value="<?php echo htmlspecialchars((string)($project['estimated_end'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          </label>
        </div>
        <label class="project-field">
          <span>Notes</span>
          <textarea name="notes" rows="5"><?php echo htmlspecialchars((string)($project['notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
        </label>
      </section>

      <section id="project-public-link" class="project-edit-section">
        <h2>Public Project Link</h2>
        <p>Share a long-term project portal link with optional access-code protection and link-specific permissions.</p>
        <label class="project-check">
          <input type="checkbox" name="public_project_enabled" value="1" <?php echo !empty($project['public_project_enabled']) ? 'checked' : ''; ?>>
          <span>
            <strong>Enable public project link</strong>
            <small style="display:block;color:var(--muted)">The link stays active until you turn it off here.</small>
          </span>
        </label>
        <?php if ($publicProjectUrl !== ''): ?>
          <label class="project-field">
            <span>Project portal URL</span>
            <input readonly value="<?php echo htmlspecialchars($publicProjectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" onclick="this.select()">
            <small>Copy this URL for the client. Turning the link off blocks access without deleting the token.</small>
          </label>
        <?php else: ?>
          <div class="project-info-box">A share URL will be generated after you save with the public link enabled.</div>
        <?php endif; ?>
        <div class="project-edit-grid">
          <label class="project-check">
            <input type="checkbox" name="public_project_require_password" value="1" <?php echo !empty($project['public_project_require_password']) ? 'checked' : ''; ?>>
            <span>
              <strong>Require an access code</strong>
              <small style="display:block;color:var(--muted)">Use a simple shared code like Football2026 when the link needs a light gate.</small>
            </span>
          </label>
          <label class="project-field">
            <span>Access code</span>
            <input type="text" name="public_project_password" autocomplete="new-password" placeholder="<?php echo $publicProjectHasCode ? 'Leave blank to keep current code' : 'Set a code before requiring one'; ?>">
            <small><?php echo $publicProjectHasCode ? 'A code is already saved. Enter a new one only if you want to replace it.' : 'Codes are stored as a password hash.'; ?></small>
          </label>
        </div>
        <div class="project-edit-grid">
          <label class="project-check">
            <input type="checkbox" name="public_project_can_view_documents" value="1" <?php echo !array_key_exists('public_project_can_view_documents', $project) || !empty($project['public_project_can_view_documents']) ? 'checked' : ''; ?>>
            <span><strong>View project docs and files</strong><small style="display:block;color:var(--muted)">Shows linked quotes, contracts, and project files.</small></span>
          </label>
          <label class="project-check">
            <input type="checkbox" name="public_project_can_view_invoices" value="1" <?php echo !array_key_exists('public_project_can_view_invoices', $project) || !empty($project['public_project_can_view_invoices']) ? 'checked' : ''; ?>>
            <span><strong>View project invoices</strong><small style="display:block;color:var(--muted)">Shows project invoices and regular invoices tied to this project.</small></span>
          </label>
          <label class="project-check">
            <input type="checkbox" name="public_project_can_upload" value="1" <?php echo !empty($project['public_project_can_upload']) ? 'checked' : ''; ?>>
            <span><strong>Allow uploads</strong><small style="display:block;color:var(--muted)">Uploaded files are saved to this project's file area.</small></span>
          </label>
          <label class="project-check">
            <input type="checkbox" name="public_project_can_request_changes" value="1" <?php echo !empty($project['public_project_can_request_changes']) ? 'checked' : ''; ?>>
            <span><strong>Allow update requests</strong><small style="display:block;color:var(--muted)">Lets the link visitor send notes or requested changes without editing records directly.</small></span>
          </label>
        </div>
      </section>

      <div class="project-edit-actions">
        <a class="btn" href="/?page=project/projects-details&amp;id=<?php echo $projectId; ?>">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Project</button>
      </div>
    </form>
  </div>
</div>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/project-settings.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
