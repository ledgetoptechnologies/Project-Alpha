<?php
// src/views/pages/auth/account-edit.php
// Admin-only page for editing a user account
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/workforce_availability.php';
require_once __DIR__ . '/../../../utils/document_sender.php';
require_once __DIR__ . '/../../../utils/worker_documents.php';
require_once __DIR__ . '/../../../utils/external_ops.php';

// Require admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}

$csrf = csrf_token();
$userId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, email, username, role, is_disabled, force_password_reset, created_at,
    document_sender_enabled, document_sender_name, document_sender_company,
    document_sender_address_line1, document_sender_address_line2,
    document_sender_city, document_sender_state, document_sender_postal,
    document_sender_country, document_sender_phone, document_sender_email,deleted_at
    FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: /?page=accounts&error=' . urlencode('User not found'));
    exit;
}

$externalOpsConfig = pa_external_ops_delivery_config($pdo);
$externalOpsAvailable = !empty($externalOpsConfig['enabled']);
$externalOpsEntitlementExists = false;
$externalOpsEntitlementEnabled = false;
$externalOpsManualEnabled = false;
$externalOpsWorkerStatus = '';
$externalOpsEmploymentStatus = '';
$externalOpsDeliveryStatus = null;
if ($externalOpsAvailable) {
    try {
        $externalOpsStatement = $pdo->prepare(
            'SELECT ae.id entitlement_id,ae.enabled,ae.manual_enabled,wp.status worker_status,ep.employment_status
             FROM users u
             LEFT JOIN application_entitlements ae ON ae.user_id=u.id AND ae.application_key=?
             LEFT JOIN worker_profiles wp ON wp.user_id=u.id
             LEFT JOIN employee_profiles ep ON ep.user_id=u.id
             WHERE u.id=? LIMIT 1'
        );
        $externalOpsStatement->execute([(string)$externalOpsConfig['application_key'], $userId]);
        $externalOpsValue = $externalOpsStatement->fetch(PDO::FETCH_ASSOC);
        $externalOpsEntitlementExists = is_array($externalOpsValue) && !empty($externalOpsValue['entitlement_id']);
        $externalOpsEntitlementEnabled = $externalOpsEntitlementExists && !empty($externalOpsValue['enabled']);
        $externalOpsManualEnabled = $externalOpsEntitlementExists && !empty($externalOpsValue['manual_enabled']);
        $externalOpsWorkerStatus = (string)($externalOpsValue['worker_status'] ?? '');
        $externalOpsEmploymentStatus = (string)($externalOpsValue['employment_status'] ?? '');
        $delivery = $pdo->prepare("SELECT delivered_at,attempts,last_error FROM integration_outbox WHERE integration_key=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.user.id'))=? ORDER BY id DESC LIMIT 1");
        $delivery->execute([(string)$externalOpsConfig['application_key'], (string)$userId]);
        $externalOpsDeliveryStatus = $delivery->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $error) {
        @error_log('[account-edit] external Ops entitlement load failed: ' . $error->getMessage());
    }
}

$employeeProfile = [
    'first_name' => '',
    'last_name' => '',
    'employment_status' => 'active',
    'hourly_rate' => '',
    'employee_can_view_pay' => 1,
    'hired_at' => '',
];
$employeeAssignments = [];
$activeProjects = [];
try {
    $profileStmt = $pdo->prepare('SELECT first_name,last_name,employment_status,hourly_rate,employee_can_view_pay,hired_at FROM employee_profiles WHERE user_id=? LIMIT 1');
    $profileStmt->execute([$userId]);
    $loadedProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
    if ($loadedProfile) {
        $employeeProfile = array_merge($employeeProfile, $loadedProfile);
    }
    $projectStmt = $pdo->prepare(
        "SELECT p.id,p.name,a.id assignment_id,a.pay_rate_override,a.ends_at
         FROM projects p LEFT JOIN project_assignments a ON a.project_id=p.id AND a.user_id=?
         WHERE p.status NOT IN ('completed','cancelled') ORDER BY p.name"
    );
    $projectStmt->execute([$userId]);
    $activeProjects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activeProjects as $project) {
        if (!empty($project['assignment_id']) && ($project['ends_at'] === null || strtotime((string)$project['ends_at']) > time())) {
            $employeeAssignments[(int)$project['id']] = $project['pay_rate_override'];
        }
    }
} catch (Throwable $e) {
    @error_log('[account-edit] employee profile load failed: ' . $e->getMessage());
}

$personnelDocuments = [];
$personnelDocumentsAvailable = true;
$personnelDocumentCategories = worker_document_category_labels();
try {
    $documentStmt = $pdo->prepare(
        'SELECT d.*,u.username uploaded_by_username,u.email uploaded_by_email
         FROM worker_documents d LEFT JOIN users u ON u.id=d.uploaded_by
         WHERE d.user_id=?
         ORDER BY (d.status="archived"),COALESCE(d.expires_on,"9999-12-31"),d.created_at DESC'
    );
    $documentStmt->execute([$userId]);
    $personnelDocuments = $documentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $personnelDocumentsAvailable = false;
    @error_log('[account-edit] personnel document load failed: ' . $e->getMessage());
}

// Get 2FA status
$twofaEnabled = false;
$activePasskeyCount = 0;
try {
    $st = $pdo->prepare('SELECT enabled FROM user_2fa WHERE user_id = ?');
    $st->execute([$userId]);
    $twofaEnabled = (bool)$st->fetchColumn();
    $passkeyStatement = $pdo->prepare('SELECT COUNT(*) FROM passkey_credentials WHERE user_id=? AND revoked_at IS NULL');
    $passkeyStatement->execute([$userId]);
    $activePasskeyCount = (int)$passkeyStatement->fetchColumn();
} catch (Throwable $e) {}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$availableRoles = [];
$userAclRoleId = null;
try {
    $roleStmt = $pdo->query('SELECT id, name, description, is_system, organization_id FROM roles WHERE organization_id IS NULL OR is_system = 1 ORDER BY CASE name WHEN "member" THEN 0 WHEN "staff" THEN 1 WHEN "owner" THEN 2 WHEN "admin" THEN 3 ELSE 4 END, is_system DESC, name');
    $availableRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    @error_log('[account-edit] role load failed: ' . $e->getMessage());
}

if (empty($availableRoles)) {
    $availableRoles = [
        ['id' => 0, 'name' => 'member', 'description' => 'Default user access.', 'is_system' => 1, 'organization_id' => null],
        ['id' => -1, 'name' => 'admin', 'description' => 'Full administrative access.', 'is_system' => 1, 'organization_id' => null],
    ];
}

if ($userAclRoleId === null) {
    $currentRoleName = in_array($user['role'], ['admin', 'owner', 'staff', 'member', 'employee'], true) ? $user['role'] : 'member';
    foreach ($availableRoles as $roleRow) {
        if ($roleRow['name'] === $currentRoleName) {
            $userAclRoleId = (int)$roleRow['id'];
            break;
        }
    }
}

$targetRole = $user['role'];
foreach ($availableRoles as $roleRow) {
    if ((int)$roleRow['id'] === (int)$userAclRoleId) {
        $targetRole = (string)$roleRow['name'];
        break;
    }
}

require_once __DIR__ . '/../../../utils/permission_catalog.php';
$permissionGroupsEdit = permission_catalog();
$flatEditPerms = [];
foreach ($permissionGroupsEdit as $group => $permissions) {
    foreach ($permissions as $perm) {
        $flatEditPerms[$perm] = true;
    }
}

$roleDefaults = [];
$roleMeta = [];
foreach ($availableRoles as $roleRow) {
    $roleId = (int)$roleRow['id'];
    $roleName = (string)$roleRow['name'];
    $roleMeta[(string)$roleId] = [
        'name' => $roleName,
        'isAdmin' => $roleName === 'admin',
        'isEmployee' => $roleName === 'employee',
    ];
    $roleDefaults[(string)$roleId] = [];
    foreach ($flatEditPerms as $perm => $_) {
        $roleDefaults[(string)$roleId][$perm] = $roleName === 'admin';
    }
}

try {
    $ids = array_values(array_filter(array_map(static fn($r) => (int)$r['id'], $availableRoles), static fn($id) => $id > 0));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rpStmt = $pdo->prepare("SELECT role_id, permission, allowed FROM role_permissions WHERE role_id IN ($placeholders)");
        $rpStmt->execute($ids);
        $rawRolePerms = [];
        foreach ($rpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rawRolePerms[(int)$row['role_id']][(string)$row['permission']] = (bool)$row['allowed'];
        }
        foreach ($availableRoles as $roleRow) {
            $roleId = (int)$roleRow['id'];
            $roleName = (string)$roleRow['name'];
            if ($roleName === 'admin') {
                continue;
            }
            foreach ($flatEditPerms as $perm => $_) {
                $module = explode('.', $perm, 2)[0] ?? $perm;
                $roleDefaults[(string)$roleId][$perm] =
                    $rawRolePerms[$roleId][$perm]
                    ?? $rawRolePerms[$roleId][$module . '.*']
                    ?? false;
            }
        }
    }
} catch (Throwable $e) {
    @error_log('[account-edit] role defaults load failed: ' . $e->getMessage());
}
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
    <h2 style="margin:0;">Edit User</h2>
    <a href="/?page=accounts" style="padding:8px 14px;border-radius:6px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151;font-size:14px;">&larr; Back to Accounts</a>
  </div>

  <?php if ($success === '2fa_disabled'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">2FA has been disabled for this user.</div>
  <?php elseif ($success === 'pwd_reset'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Password reset successfully.</div>
  <?php elseif ($success === 'updated'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User updated successfully.</div>
  <?php elseif (!empty($success)): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4"><?php echo e((string)$success); ?></div>
  <?php elseif (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
  <?php endif; ?>

  <style>
    .pa-edit-layout { display: grid; gap: 24px; align-items: start; }
    .pa-edit-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; }
    .pa-edit-card h3 { margin: 0 0 16px 0; }
    .pa-edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .pa-edit-grid label { display: flex; flex-direction: column; gap: 4px; }
    .pa-edit-sender-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .pa-edit-sender-grid label { display: flex; flex-direction: column; gap: 4px; }
    .pa-edit-grid input,
    .pa-edit-grid select,
    .pa-edit-sender-grid input { width: 100%; box-sizing: border-box; }
    .pa-edit-actionbar { margin-top: 4px; }
    .pa-edit-employee-panel { margin-top: 20px; padding-top: 18px; border-top: 1px solid #e5e7eb; }
    .pa-edit-employee-panel[hidden] { display: none; }
    .pa-edit-projects { display: grid; gap: 10px; margin-top: 14px; }
    .pa-edit-project { display: grid; grid-template-columns: minmax(220px, 1fr) 180px; gap: 16px; align-items: center; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
    .pa-edit-project > label { display: flex; flex-direction: row; align-items: center; gap: 8px; }
    .pa-edit-secondary { display: grid; gap: 16px; grid-template-columns: repeat(3, 1fr); }
    .pa-personnel-upload { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end; }
    .pa-personnel-upload .pa-span-2 { grid-column:span 2; }
    .pa-personnel-table { width:100%;border-collapse:collapse;margin-bottom:18px; }
    .pa-personnel-table th,.pa-personnel-table td { padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top; }
    #permissions-panel-edit.pa-hidden {
      opacity: 0;
      max-height: 0 !important;
      padding-top: 0 !important;
      padding-bottom: 0 !important;
      margin: 0 !important;
      border-width: 0 !important;
      pointer-events: none;
    }
    @media (max-width: 960px) {
      .pa-edit-secondary { grid-template-columns: 1fr; }
    }
    @media (max-width: 720px) {
      .pa-edit-grid { grid-template-columns: 1fr; }
      .pa-edit-sender-grid { grid-template-columns: 1fr; }
      .pa-edit-project { grid-template-columns: 1fr; }
      .pa-personnel-upload { grid-template-columns:1fr; }
      .pa-personnel-upload .pa-span-2 { grid-column:auto; }
    }
  </style>

  <div class="pa-edit-layout">
    <form method="post" action="/?page=accounts-update" id="account-edit-form">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

    <!-- Account Details -->
    <div class="pa-edit-card">
      <h3>Account Details</h3>

        <div class="pa-edit-grid">
          <label>
            <span style="font-weight:600">Email *</span>
            <input required type="email" name="email" value="<?php echo e($user['email']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
          </label>

          <label>
            <span style="font-weight:600">Username</span>
            <input type="text" name="username" value="<?php echo e($user['username'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
          </label>

          <label>
            <span style="font-weight:600">Role *</span>
            <select required name="role_id" id="account-role-select" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
              <?php foreach ($availableRoles as $roleRow): ?>
                <?php
                  $roleId = (int)$roleRow['id'];
                  $roleName = (string)$roleRow['name'];
                  $roleLabel = ucwords(str_replace('_', ' ', $roleName));
                  $roleScope = (int)($roleRow['is_system'] ?? 0) === 1 ? 'System' : 'Custom';
                ?>
                <option value="<?php echo $roleId; ?>" <?php echo $roleId === (int)$userAclRoleId ? 'selected' : ''; ?>>
                  <?php echo e($roleLabel . ' (' . $roleScope . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:16px;">
          <input type="checkbox" name="is_disabled" value="1" <?php echo ($user['is_disabled'] ?? 0) ? 'checked' : ''; ?>
          <span>Disable account (prevents login)</span>
        </label>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:8px;">
          <input type="checkbox" name="force_reset" value="1" <?php echo ($user['force_password_reset'] ?? 0) ? 'checked' : ''; ?>
          <span>Force password change on next login</span>
        </label>


        <div style="margin-top:20px;padding-top:18px;border-top:1px solid #e5e7eb;">
          <h3 style="margin:0 0 10px 0;font-size:16px;">Document Sender Info</h3>
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:14px;">
            <input type="checkbox" name="document_sender_enabled" id="document-sender-enabled" value="1" <?php echo !empty($user['document_sender_enabled']) ? 'checked' : ''; ?> style="margin-top:3px;">
            <span>
              <span style="display:block;font-weight:600;">Use this user's info on documents they create</span>
              <span style="display:block;color:#6b7280;font-size:13px;">When off, quotes, contracts, and invoices use the system default business info from Settings.</span>
            </span>
          </label>

          <div id="document-sender-fields" class="pa-edit-sender-grid">
            <label>
              <span style="font-weight:600">Name</span>
              <input type="text" name="document_sender_name" value="<?php echo e($user['document_sender_name'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Company</span>
              <input type="text" name="document_sender_company" value="<?php echo e($user['document_sender_company'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Phone</span>
              <input type="tel" name="document_sender_phone" autocomplete="tel" value="<?php echo e($user['document_sender_phone'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Email</span>
              <input type="email" name="document_sender_email" value="<?php echo e($user['document_sender_email'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Address Line 1</span>
              <input type="text" name="document_sender_address_line1" value="<?php echo e($user['document_sender_address_line1'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Apartment / Suite</span>
              <input type="text" name="document_sender_address_line2" value="<?php echo e($user['document_sender_address_line2'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">City</span>
              <input type="text" name="document_sender_city" value="<?php echo e($user['document_sender_city'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">State</span>
              <input type="text" name="document_sender_state" value="<?php echo e($user['document_sender_state'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Postal Code</span>
              <input type="text" name="document_sender_postal" value="<?php echo e($user['document_sender_postal'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Country</span>
              <input type="text" name="document_sender_country" value="<?php echo e($user['document_sender_country'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
          </div>
        </div>

<?php if (pa_workforce_enabled()): ?>
        <div id="employee-profile-panel" class="pa-edit-employee-panel" <?php echo $targetRole === 'employee' ? '' : 'hidden'; ?>>
          <h3 style="margin:0 0 8px;font-size:16px;">Employee Profile &amp; Workforce Access</h3>
          <p style="margin:0 0 16px;color:#6b7280;font-size:14px;">The Employee role supplies the default self-service ACL. Employees see only their assigned projects and never direct client or invoice details.</p>
          <div class="pa-edit-grid">
            <label>
              <span style="font-weight:600">First name *</span>
              <input class="input" type="text" name="employee_first_name" value="<?php echo e($employeeProfile['first_name']); ?>" data-employee-required autocomplete="given-name">
            </label>
            <label>
              <span style="font-weight:600">Last name</span>
              <input class="input" type="text" name="employee_last_name" value="<?php echo e($employeeProfile['last_name']); ?>" autocomplete="family-name">
            </label>
            <label>
              <span style="font-weight:600">Employment status</span>
              <select class="input" name="employee_status">
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'terminated' => 'Terminated'] as $statusValue => $statusLabel): ?>
                  <option value="<?php echo $statusValue; ?>" <?php echo $employeeProfile['employment_status'] === $statusValue ? 'selected' : ''; ?>><?php echo $statusLabel; ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span style="font-weight:600">Hire date</span>
              <input class="input" type="date" name="employee_hired_at" value="<?php echo e($employeeProfile['hired_at']); ?>">
            </label>
            <label>
              <span style="font-weight:600">Hourly pay rate</span>
              <input class="input" type="number" name="employee_hourly_rate" min="0" step="0.0001" value="<?php echo e($employeeProfile['hourly_rate']); ?>" placeholder="Use business fallback">
            </label>
            <label style="display:flex;flex-direction:row;align-items:center;gap:8px;align-self:end;padding-bottom:10px;">
              <input type="checkbox" name="employee_can_view_pay" value="1" <?php echo !empty($employeeProfile['employee_can_view_pay']) ? 'checked' : ''; ?> style="width:auto">
              <span>Employee can view their pay accruals</span>
            </label>
          </div>
          <h4 style="margin:20px 0 6px">Project assignments</h4>
          <p style="margin:0;color:#6b7280;font-size:13px;">Selected projects appear in the employee time tracker. A project-specific pay rate is optional.</p>
          <div class="pa-edit-projects">
            <?php foreach ($activeProjects as $project): ?>
              <?php $projectId = (int)$project['id']; $assigned = array_key_exists($projectId, $employeeAssignments); ?>
              <div class="pa-edit-project">
                <label><input type="checkbox" name="employee_project_ids[]" value="<?php echo $projectId; ?>" <?php echo $assigned ? 'checked' : ''; ?> style="width:auto"><span><?php echo e($project['name']); ?></span></label>
                <input class="input" type="number" name="employee_project_rates[<?php echo $projectId; ?>]" min="0" step="0.0001" value="<?php echo $assigned ? e($employeeAssignments[$projectId]) : ''; ?>" placeholder="Optional pay rate">
              </div>
            <?php endforeach; ?>
            <?php if (!$activeProjects): ?><p style="margin:0;color:#6b7280">No active projects are available.</p><?php endif; ?>
          </div>
        </div>

<?php endif; ?>
        <div class="pa-edit-actionbar">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;">Save Changes</button>
        </div>
    </div>

    <!-- Permissions (full-width card) -->
    <?php
      $permissionsEmbedInParentForm = true;
      include __DIR__ . '/../account/permissions_overrides.php';
      unset($permissionsEmbedInParentForm);
    ?>
    <?php if ($externalOpsAvailable):
      $externalOpsTerminated = in_array($externalOpsWorkerStatus, ['terminated'], true) || in_array($externalOpsEmploymentStatus, ['terminated'], true);
      $externalOpsInactive = !empty($user['is_disabled']) || $externalOpsWorkerStatus === 'inactive' || $externalOpsEmploymentStatus === 'inactive';
      $externalOpsSelected = $externalOpsEntitlementEnabled && $externalOpsManualEnabled;
      $externalOpsEffective = $externalOpsEntitlementEnabled && $externalOpsManualEnabled && !$externalOpsInactive && !$externalOpsTerminated && empty($user['deleted_at']);
      $externalOpsRoleLabel = $user['role'] === 'admin' ? 'Global external administrator' : 'Assigned-work operator';
      $deliveryLabel = !$externalOpsDeliveryStatus ? 'No access event has been queued' : (!empty($externalOpsDeliveryStatus['delivered_at']) ? 'Delivered ' . $externalOpsDeliveryStatus['delivered_at'] : (!empty($externalOpsDeliveryStatus['last_error']) ? 'Retrying after a delivery error' : 'Queued for delivery'));
      $externalOpsCannotGrant = !$externalOpsSelected && ($externalOpsTerminated || !empty($user['deleted_at']));
    ?>
    <div class="pa-edit-card" data-custom-integration-permission style="margin:16px 0;">
      <input type="hidden" name="custom_integration_access_present" value="1">
      <h3 style="margin:0 0 8px;font-size:16px;">Custom integrations access</h3>
      <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:10px;">
        <input type="checkbox" name="custom_integration_access" value="1" <?php echo $externalOpsSelected ? 'checked' : ''; ?> <?php echo $externalOpsCannotGrant ? 'disabled' : ''; ?> style="margin-top:3px;">
        <span><strong>Allow this account to use the configured custom integration</strong><br><small style="color:#6b7280">This is the same access selection shown in Settings &gt; Custom integrations.</small></span>
      </label>
      <p style="margin:0 0 6px"><strong><?php echo $externalOpsEffective ? 'Access granted' : ($externalOpsSelected ? 'Selected, but unavailable while the account or worker is inactive' : (!empty($user['deleted_at']) ? 'Unavailable — deleted' : ($externalOpsTerminated ? 'Unavailable — terminated' : 'Not granted'))); ?></strong></p>
      <p style="margin:0 0 6px;color:#6b7280;font-size:13px">Role: <?php echo e($externalOpsRoleLabel); ?>. Project, Operation, and Task assignments separately control what this user can see.</p>
      <p style="margin:0;color:#6b7280;font-size:13px">Synchronization: <?php echo e($deliveryLabel); ?></p>
    </div>
    <?php endif; ?>
    </form>
    <script>
      window.PA_EDIT_ROLE_DEFAULTS = <?php echo json_encode($roleDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      window.PA_EDIT_ROLE_META = <?php echo json_encode($roleMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

      function initAccountEditForm(context) {
        var root = context && context.root ? context.root : document;
        var form = root.querySelector('#account-edit-form');
        if (!form || form.dataset.accountEditReady === '1') return;
        form.dataset.accountEditReady = '1';
        var roleSelect = root.querySelector('#account-role-select');
        var panel = root.querySelector('#permissions-panel-edit');
        var grid = root.querySelector('#permissions-grid-edit');
        var adminNote = root.querySelector('#admin-permissions-note-edit');
        var senderToggle = root.querySelector('#document-sender-enabled');
        var senderFields = root.querySelector('#document-sender-fields');
        var employeePanel = root.querySelector('#employee-profile-panel');

        function selectedRoleMeta() {
          if (!roleSelect || !window.PA_EDIT_ROLE_META) return {};
          return window.PA_EDIT_ROLE_META[roleSelect.value] || {};
        }

        function selectedRoleDefaults() {
          if (roleSelect && window.PA_EDIT_ROLE_DEFAULTS && window.PA_EDIT_ROLE_DEFAULTS[roleSelect.value]) {
            return window.PA_EDIT_ROLE_DEFAULTS[roleSelect.value];
          }
          return {};
        }

        function applyRoleDefaults() {
          var defaults = selectedRoleDefaults();
          Object.keys(defaults).forEach(function(perm) {
            var key = perm.replace(/\./g, '_');
            var allowCb = root.querySelector('#permissions-panel-edit input[name="allow_' + key + '"]');
            var denyCb = root.querySelector('#permissions-panel-edit input[name="deny_' + key + '"]');
            if (allowCb && denyCb) {
              var allowed = !!defaults[perm];
              allowCb.checked = allowed;
              denyCb.checked = !allowed;
            }
          });
        }

        function updatePermissionsForRole(applyDefaultsForRole) {
          if (!roleSelect || !panel) return;
          var meta = selectedRoleMeta();
          if (employeePanel) {
            employeePanel.hidden = !meta.isEmployee;
            employeePanel.querySelectorAll('[data-employee-required]').forEach(function(input) {
              input.required = !!meta.isEmployee;
            });
          }
          if (meta.isAdmin) {
            panel.classList.add('pa-hidden');
            if (adminNote) adminNote.style.display = 'block';
            if (grid) grid.style.display = 'none';
          } else {
            panel.classList.remove('pa-hidden');
            if (adminNote) adminNote.style.display = 'none';
            if (grid) grid.style.display = 'block';
            if (applyDefaultsForRole) {
              applyRoleDefaults();
            }
          }
        }

        if (roleSelect) {
          roleSelect.addEventListener('change', function() {
            updatePermissionsForRole(true);
          });
          updatePermissionsForRole(false);
        }

        function updateSenderFields() {
          if (!senderToggle || !senderFields) return;
          senderFields.style.opacity = senderToggle.checked ? '1' : '0.45';
          senderFields.querySelectorAll('input').forEach(function(input) {
            input.readOnly = !senderToggle.checked;
          });
        }

        if (senderToggle) {
          senderToggle.addEventListener('change', updateSenderFields);
          updateSenderFields();
        }

        root.querySelectorAll('#permissions-panel-edit input[name^="allow_"], #permissions-panel-edit input[name^="deny_"]').forEach(function(checkbox) {
          if (checkbox.dataset.permissionPairReady === '1') return;
          checkbox.dataset.permissionPairReady = '1';
          checkbox.addEventListener('change', function() {
            var isAllow = this.name.indexOf('allow_') === 0;
            var pairedName = isAllow ? this.name.replace('allow_', 'deny_') : this.name.replace('deny_', 'allow_');
            var paired = root.querySelector('#permissions-panel-edit input[name="' + pairedName + '"]');
            if (paired) paired.checked = !this.checked;
          });
        });
      }
      initAccountEditForm.pageInitializerId = 'account-edit-form';
      if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage('account-edit', initAccountEditForm);
      } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAccountEditForm, { once: true });
      } else {
        initAccountEditForm();
      }
    </script>

    <div class="pa-edit-card" id="personnel-documents">
      <h3 style="margin-bottom:6px">Personnel Documents &amp; Agreements</h3>
      <p style="margin:0 0 16px;color:#6b7280;font-size:14px">Keep signed equipment-use agreements, waivers, policies, certifications, and other worker records with this account. Uploaded files are integrity-hashed and are never replaced in place.</p>
      <?php if (!empty($_GET['document_msg'])): ?>
        <div class="alert alert-success"><?php echo e((string)$_GET['document_msg']); ?></div>
      <?php endif; ?>
      <?php if (!empty($_GET['document_error'])): ?>
        <div class="alert alert-danger"><?php echo e((string)$_GET['document_error']); ?></div>
      <?php endif; ?>

      <?php if (!$personnelDocumentsAvailable): ?>
        <div class="alert alert-warning">Personnel documents are unavailable until database migration 0044 is applied.</div>
      <?php else: ?>
        <?php if ($personnelDocuments): ?>
          <div style="overflow:auto">
            <table class="pa-personnel-table">
              <thead><tr><th>Document</th><th>Signed / expires</th><th>Status</th><th>File</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach ($personnelDocuments as $document): ?>
                <?php
                  $isArchived = ($document['status'] ?? '') === 'archived';
                  $isExpired = !$isArchived && !empty($document['expires_on']) && (string)$document['expires_on'] < date('Y-m-d');
                  $statusLabel = $isArchived ? 'Archived' : ($isExpired ? 'Expired' : 'Current');
                  $statusStyle = $isArchived ? 'background:#f3f4f6;color:#4b5563' : ($isExpired ? 'background:#fef3c7;color:#92400e' : 'background:#d1fae5;color:#065f46');
                ?>
                <tr>
                  <td><strong><?php echo e((string)$document['title']); ?></strong><div style="font-size:12px;color:#6b7280"><?php echo e($personnelDocumentCategories[$document['category']] ?? ucwords(str_replace('_', ' ', (string)$document['category']))); ?></div><?php if (!empty($document['notes'])): ?><div style="font-size:12px;margin-top:4px"><?php echo nl2br(e((string)$document['notes'])); ?></div><?php endif; ?></td>
                  <td><?php echo !empty($document['signed_on']) ? e((string)$document['signed_on']) : 'Not recorded'; ?><div style="font-size:12px;color:#6b7280">Expires: <?php echo !empty($document['expires_on']) ? e((string)$document['expires_on']) : 'No expiration'; ?></div></td>
                  <td><span style="display:inline-flex;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700;<?php echo $statusStyle; ?>"><?php echo $statusLabel; ?></span><div style="font-size:11px;color:#6b7280;margin-top:4px">SHA-256: <?php echo e(substr((string)$document['content_sha256'], 0, 12)); ?>&hellip;</div></td>
                  <td><a class="btn btn-sm" href="/?page=worker-document-download&amp;id=<?php echo (int)$document['id']; ?>" target="_blank" rel="noopener">View</a> <a class="btn btn-sm" href="/?page=worker-document-download&amp;id=<?php echo (int)$document['id']; ?>&amp;download=1">Download</a><div style="font-size:11px;color:#6b7280;margin-top:4px"><?php echo e((string)$document['original_name']); ?></div></td>
                  <td>
                    <form method="post" action="/?page=worker-documents" onsubmit="return confirm('<?php echo $isArchived ? 'Restore this worker document?' : 'Archive this worker document? The signed file will be retained.'; ?>')">
                      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                      <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                      <input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>">
                      <input type="hidden" name="action" value="<?php echo $isArchived ? 'restore' : 'archive'; ?>">
                      <button class="btn btn-sm" type="submit"><?php echo $isArchived ? 'Restore' : 'Archive'; ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;color:#6b7280">No personnel documents are attached yet.</p>
        <?php endif; ?>

        <form method="post" action="/?page=worker-documents" enctype="multipart/form-data" class="pa-personnel-upload">
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
          <input type="hidden" name="action" value="upload">
          <label><span style="display:block;font-weight:600;margin-bottom:4px">Document type</span><select class="input" name="category"><?php foreach ($personnelDocumentCategories as $categoryKey => $categoryLabel): ?><option value="<?php echo e($categoryKey); ?>"><?php echo e($categoryLabel); ?></option><?php endforeach; ?></select></label>
          <label class="pa-span-2"><span style="display:block;font-weight:600;margin-bottom:4px">Title *</span><input class="input" name="title" maxlength="255" required placeholder="Drone equipment use agreement"></label>
          <label><span style="display:block;font-weight:600;margin-bottom:4px">Signed date</span><input class="input" type="date" name="signed_on"></label>
          <label><span style="display:block;font-weight:600;margin-bottom:4px">Expiration date</span><input class="input" type="date" name="expires_on"></label>
          <label class="pa-span-2"><span style="display:block;font-weight:600;margin-bottom:4px">Notes</span><input class="input" name="notes" maxlength="5000" placeholder="Equipment covered, limitations, renewal details"></label>
          <label><span style="display:block;font-weight:600;margin-bottom:4px">Signed file *</span><input class="input" type="file" name="worker_document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required></label>
          <button class="btn btn-primary" type="submit">Attach document</button>
        </form>
        <p style="margin:10px 0 0;color:#6b7280;font-size:12px">PDF, Word, JPG, or PNG; maximum 15 MB. Archiving preserves the original file and audit history.</p>
      <?php endif; ?>
    </div>

    <!-- Secondary actions: 2FA, Reset Password, Danger Zone -->
    <div class="pa-edit-secondary">
      <div class="pa-edit-card">
        <h3>Two-Factor Authentication</h3>
        <p style="margin:0 0 12px;">Status:
          <span style="padding:4px 10px;border-radius:4px;font-size:13px;font-weight:600;<?php echo $twofaEnabled ? 'background:#d1fae5;color:#065f46' : 'background:#f3f4f6;color:#374151'; ?>">
            <?php echo $twofaEnabled ? 'Enabled' : 'Not enabled'; ?>
          </span>
        </p>
        <?php if ($twofaEnabled): ?>
          <form method="post" action="/?page=2fa-admin-disable" onsubmit="return confirm('This will remove the authenticator requirement for this user. Are you sure?')" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
            <button type="submit" style="padding:8px 14px;border-radius:6px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-size:14px;cursor:pointer;font-weight:600;">Disable 2FA for this user</button>
          </form>
        <?php else: ?>
          <?php if ($userId === (int)($_SESSION['user']['id'] ?? 0)): ?>
            <p style="color:#6b7280;font-size:13px;margin:0 0 12px;">Two-factor authentication is not enabled for your account. Set it up from My Account.</p>
            <a href="/?page=2fa-setup" class="btn btn-sm btn-primary">Set Up 2FA</a>
          <?php else: ?>
            <p style="color:#6b7280;font-size:13px;margin:0;">The user has not set up two-factor authentication. They can enable it from their own Account page.</p>
          <?php endif; ?>
        <?php endif; ?>
        <div style="border-top:1px solid #e5e7eb;margin-top:18px;padding-top:16px">
          <p style="margin:0 0 10px"><strong>Passkeys:</strong> <?php echo $activePasskeyCount; ?> active</p>
          <?php if ($activePasskeyCount > 0): ?>
            <form method="post" action="/?page=passkey-admin-reset" onsubmit="return confirm('Revoke every passkey for this user and sign out their existing sessions?')">
              <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
              <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
              <button type="submit" class="btn btn-sm btn-danger">Revoke all passkeys</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="pa-edit-card">
        <h3>Reset Password</h3>
        <form method="post" action="/?page=accounts-reset-password" style="display:grid;gap:12px;margin-top:12px;">
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
          <label>
            <span style="font-weight:600">New Password</span>
            <input required minlength="8" type="password" name="new_password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;" placeholder="Min 8 characters">
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="force_reset" value="1">
            <span>Force password change on next login</span>
          </label>
          <button type="submit" style="padding:8px 14px;border-radius:6px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-size:14px;cursor:pointer;font-weight:600;">Reset Password</button>
        </form>
      </div>

      <div class="pa-edit-card" style="border-color:#fca5a5;">
        <h3 style="color:#991b1b">Danger Zone</h3>
        <p style="color:#6b7280;font-size:14px;margin:8px 0 16px;">Permanently delete this user account. Quotes, invoices, contracts, and other business records will remain in the system and are not affected.</p>
        <form method="post" action="/?page=accounts-delete" onsubmit="return confirm('Are you sure you want to permanently delete this user? This cannot be undone. Related business records will NOT be deleted.')">
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-weight:600;cursor:pointer;">Delete User Account</button>
        </form>
      </div>
    </div>
  </div>
</section>
