<?php
// src/controllers/accounts/accounts_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/permission_catalog.php';
require_once __DIR__ . '/../../utils/admin_account_policy.php';
require_once __DIR__ . '/../../Modules/Timekeeping/WorkforceSettings.php';
require_once __DIR__ . '/../../utils/external_ops.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}


$userId = (int)($_POST['user_id'] ?? 0);
$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$postedRoleId = (int)($_POST['role_id'] ?? 0);
$postedLegacyRole = trim($_POST['role'] ?? 'user');
$forceReset = !empty($_POST['force_reset']);
$isDisabled = !empty($_POST['is_disabled']);
$customIntegrationAccessPresent = array_key_exists('custom_integration_access_present', $_POST);
$customIntegrationAccessRequested = !empty($_POST['custom_integration_access']);
$documentSenderEnabled = !empty($_POST['document_sender_enabled']);
$documentSenderName = trim((string)($_POST['document_sender_name'] ?? ''));
$documentSenderCompany = trim((string)($_POST['document_sender_company'] ?? ''));
$documentSenderAddressLine1 = trim((string)($_POST['document_sender_address_line1'] ?? ''));
$documentSenderAddressLine2 = trim((string)($_POST['document_sender_address_line2'] ?? ''));
$documentSenderCity = trim((string)($_POST['document_sender_city'] ?? ''));
$documentSenderState = trim((string)($_POST['document_sender_state'] ?? ''));
$documentSenderPostal = trim((string)($_POST['document_sender_postal'] ?? ''));
$documentSenderCountry = trim((string)($_POST['document_sender_country'] ?? ''));
$documentSenderPhone = trim((string)($_POST['document_sender_phone'] ?? ''));
$documentSenderEmail = trim((string)($_POST['document_sender_email'] ?? ''));

// Validation
if ($userId <= 0) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Invalid email address'));
    exit;
}

if ($documentSenderEmail !== '' && !filter_var($documentSenderEmail, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Invalid document sender email address'));
    exit;
}

$orgId = null;

$selectedRole = null;
if ($postedRoleId > 0) {
    try {
        $roleStmt = $pdo->prepare('SELECT id, name, organization_id FROM roles WHERE id = ? AND (organization_id IS NULL OR is_system = 1) LIMIT 1');
        $roleStmt->execute([$postedRoleId]);
        $selectedRole = $roleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $selectedRole = null;
    }
}

if (!$selectedRole) {
    $fallbackRoleName = $postedLegacyRole === 'admin' ? 'admin' : 'member';
    $fallbackRoleId = role_id_by_name($pdo, $fallbackRoleName, $orgId);
    if ($fallbackRoleId === null && $fallbackRoleName !== 'member') {
        $fallbackRoleName = 'member';
        $fallbackRoleId = role_id_by_name($pdo, 'member', $orgId);
    }
    if ($fallbackRoleId !== null) {
        $selectedRole = ['id' => $fallbackRoleId, 'name' => $fallbackRoleName, 'organization_id' => $orgId];
    }
}

$roleName = (string)($selectedRole['name'] ?? 'member');
$roleId = isset($selectedRole['id']) ? (int)$selectedRole['id'] : null;
$role = in_array($roleName, ['admin', 'owner', 'staff', 'member', 'employee'], true) ? $roleName : 'member';

$employeeFirstName = trim((string)($_POST['employee_first_name'] ?? ''));
$employeeLastName = trim((string)($_POST['employee_last_name'] ?? ''));
$employeeStatus = trim((string)($_POST['employee_status'] ?? 'active'));
$employeeHiredAt = trim((string)($_POST['employee_hired_at'] ?? ''));
$employeeHourlyRate = trim((string)($_POST['employee_hourly_rate'] ?? ''));
$employeeCanViewPay = !empty($_POST['employee_can_view_pay']);
$employeeProjectIds = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['employee_project_ids'] ?? [])),
    static fn(int $id): bool => $id > 0
)));
$employeeProjectRates = (array)($_POST['employee_project_rates'] ?? []);

if ($role === 'employee') {
    if ($employeeFirstName === '') {
        header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Employee first name is required'));
        exit;
    }
    if (!in_array($employeeStatus, ['active', 'inactive', 'terminated'], true)) {
        header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Invalid employment status'));
        exit;
    }
    if ($employeeHiredAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $employeeHiredAt)) {
        header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Invalid hire date'));
        exit;
    }
    if ($employeeHourlyRate !== '' && (!is_numeric($employeeHourlyRate) || (float)$employeeHourlyRate < 0)) {
        header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Employee hourly rate must be zero or greater'));
        exit;
    }
    foreach ($employeeProjectIds as $projectId) {
        $rate = trim((string)($employeeProjectRates[$projectId] ?? ''));
        if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0)) {
            header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Project pay rates must be zero or greater'));
            exit;
        }
    }
}
$accountDisabled = $isDisabled || ($role === 'employee' && $employeeStatus !== 'active');

// Check if email is taken by another user
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
$stmt->execute([$email, $userId]);
if ($stmt->fetch()) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Email already exists'));
    exit;
}
if ($username !== '') {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? AND deleted_at IS NULL');
    $stmt->execute([$username, $userId]);
    if ($stmt->fetch()) {
        header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Username already exists'));
        exit;
    }
}

// Update user
try {
    $pdo->beginTransaction();
    $lockedAccountStmt = $pdo->prepare('SELECT email,username,role,is_disabled,force_password_reset,auth_version FROM users WHERE id=? FOR UPDATE');
    $lockedAccountStmt->execute([$userId]);
    $previousAccount = $lockedAccountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$previousAccount) {
        throw new DomainException('User not found');
    }

    $permissionSnapshot = static function (PDO $pdo, int $targetUserId, string $targetRole, ?array $posted = null): array {
        if ($targetRole === 'admin') {
            return ['*' => true];
        }
        $catalog = permission_catalog_flat();
        if ($posted !== null) {
            $snapshot = [];
            foreach ($catalog as $permission => $_group) {
                $allowKey = 'allow_' . str_replace('.', '_', $permission);
                $snapshot[$permission] = !empty($posted[$allowKey]);
            }
            ksort($snapshot);
            return $snapshot;
        }
        $rules = [];
        $targetRoleId = role_id_by_name($pdo, $targetRole, null);
        if ($targetRoleId !== null) {
            $rolePermissions = $pdo->prepare('SELECT permission,allowed FROM role_permissions WHERE role_id=?');
            $rolePermissions->execute([$targetRoleId]);
            foreach ($rolePermissions->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rules[(string)$row['permission']] = (bool)$row['allowed'];
            }
        }
        $overrides = $pdo->prepare('SELECT permission,allowed FROM user_permissions_overrides WHERE user_id=? AND organization_id IS NULL');
        $overrides->execute([$targetUserId]);
        foreach ($overrides->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rules[(string)$row['permission']] = (bool)$row['allowed'];
        }
        $snapshot = [];
        foreach ($catalog as $permission => $_group) {
            $module = explode('.', $permission, 2)[0];
            $snapshot[$permission] = $rules[$permission] ?? $rules[$module . '.*'] ?? false;
        }
        ksort($snapshot);
        return $snapshot;
    };
    $permissionsBefore = $permissionSnapshot($pdo, $userId, (string)$previousAccount['role']);
    $permissionsAfter = !empty($_POST['save_account_permissions'])
        ? $permissionSnapshot($pdo, $userId, $role, $_POST)
        : $permissionSnapshot($pdo, $userId, $role);
    $securitySensitiveChange = App\Security\AccountSessionPolicy::requiresGlobalRevocation(
        $previousAccount,
        [
            'email' => $email,
            'username' => $username,
            'role' => $role,
            'is_disabled' => $accountDisabled ? 1 : 0,
            'force_password_reset' => $forceReset ? 1 : 0,
        ],
        $permissionsBefore !== $permissionsAfter
    );

    assert_not_removing_final_active_admin($pdo, $userId, $role === 'admin' && !$accountDisabled);
    if ($accountDisabled) {
        $managedProjectStmt = $pdo->prepare("SELECT name FROM projects WHERE manager_user_id=? AND status NOT IN ('completed','cancelled') ORDER BY id LIMIT 1 FOR UPDATE");
        $managedProjectStmt->execute([$userId]);
        $managedProjectName = $managedProjectStmt->fetchColumn();
        if ($managedProjectName !== false) {
            throw new DomainException('Choose a different Project Manager for ' . (string)$managedProjectName . ' before disabling this account.');
        }
    }
    $previousAssignmentStmt = $pdo->prepare('SELECT id,project_id,user_id,assigned_at,ends_at,created_by,created_at,updated_at FROM project_assignments WHERE user_id=? AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6)) FOR UPDATE');
    $previousAssignmentStmt->execute([$userId]);
    $previousAssignments = [];
    foreach ($previousAssignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $previousAssignments[(int)$assignment['project_id']] = $assignment;
    }

    $effectiveEmployeeStatus = $accountDisabled && $employeeStatus !== 'terminated' ? 'inactive' : $employeeStatus;
    if ($role !== 'employee' || $effectiveEmployeeStatus !== 'active') {
        $timerStmt = $pdo->prepare("SELECT 1 FROM work_time_entries WHERE user_id=? AND status='running' LIMIT 1");
        $timerStmt->execute([$userId]);
        if ($timerStmt->fetchColumn()) {
            throw new DomainException('Stop this user\'s running timer before disabling Workforce access');
        }
    }

    $stmt = $pdo->prepare('UPDATE users SET
        email = ?,
        username = ?,
        role = ?,
        is_disabled = ?,
        force_password_reset = ?,
        document_sender_enabled = ?,
        document_sender_name = ?,
        document_sender_company = ?,
        document_sender_address_line1 = ?,
        document_sender_address_line2 = ?,
        document_sender_city = ?,
        document_sender_state = ?,
        document_sender_postal = ?,
        document_sender_country = ?,
        document_sender_phone = ?,
        document_sender_email = ?,
        auth_version = auth_version + ?
        WHERE id = ?');
    $stmt->execute([
        $email,
        $username ?: null,
        $role,
        $accountDisabled ? 1 : 0,
        $forceReset ? 1 : 0,
        $documentSenderEnabled ? 1 : 0,
        $documentSenderName !== '' ? $documentSenderName : null,
        $documentSenderCompany !== '' ? $documentSenderCompany : null,
        $documentSenderAddressLine1 !== '' ? $documentSenderAddressLine1 : null,
        $documentSenderAddressLine2 !== '' ? $documentSenderAddressLine2 : null,
        $documentSenderCity !== '' ? $documentSenderCity : null,
        $documentSenderState !== '' ? $documentSenderState : null,
        $documentSenderPostal !== '' ? $documentSenderPostal : null,
        $documentSenderCountry !== '' ? $documentSenderCountry : null,
        $documentSenderPhone !== '' ? $documentSenderPhone : null,
        $documentSenderEmail !== '' ? $documentSenderEmail : null,
        $securitySensitiveChange ? 1 : 0,
        $userId
    ]);

    $displayName = $role === 'employee'
        ? trim($employeeFirstName . ' ' . $employeeLastName)
        : ($username !== '' ? $username : $email);
    $member=$pdo->prepare('SELECT tm.id,tm.profile_source FROM team_members tm WHERE tm.user_id=? LIMIT 1');$member->execute([$userId]);$teamMember=$member->fetch(PDO::FETCH_ASSOC);
    if(!$teamMember){
        $pdo->prepare("INSERT INTO team_members (user_id,display_name,email,is_active,profile_source) VALUES (?,?,?,?, 'pa')")->execute([$userId,$displayName,$email,$accountDisabled?0:1]);
    }elseif(($teamMember['profile_source']??'pa')==='pa'){
        $pdo->prepare('UPDATE team_members SET display_name=?,email=?,is_active=? WHERE id=?')->execute([$displayName,$email,$accountDisabled?0:1,(int)$teamMember['id']]);
    }
    if ($role === 'employee') {
        $settings = \App\Modules\Timekeeping\WorkforceSettings::load($pdo);
        $currency = (string)$settings['currency'];
        $terminatedAt = $effectiveEmployeeStatus === 'terminated' ? date('Y-m-d') : null;
        $pdo->prepare(
            "INSERT INTO employee_profiles (user_id,first_name,last_name,employment_status,hourly_rate,currency,employee_can_view_pay,hired_at,terminated_at)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name),employment_status=VALUES(employment_status),
                hourly_rate=VALUES(hourly_rate),currency=VALUES(currency),employee_can_view_pay=VALUES(employee_can_view_pay),
                hired_at=VALUES(hired_at),terminated_at=VALUES(terminated_at)"
        )->execute([
            $userId,
            $employeeFirstName,
            $employeeLastName,
            $effectiveEmployeeStatus,
            $employeeHourlyRate !== '' ? $employeeHourlyRate : null,
            $currency,
            $employeeCanViewPay ? 1 : 0,
            $employeeHiredAt !== '' ? $employeeHiredAt : null,
            $terminatedAt,
        ]);

        if ($employeeProjectIds) {
            $placeholders = implode(',', array_fill(0, count($employeeProjectIds), '?'));
            $projectStmt = $pdo->prepare(
                "SELECT id FROM projects WHERE id IN ($placeholders) AND status NOT IN ('completed','cancelled')"
            );
            $projectStmt->execute($employeeProjectIds);
            $validProjects = array_map('intval', $projectStmt->fetchAll(PDO::FETCH_COLUMN));
            if (count($validProjects) !== count($employeeProjectIds)) {
                throw new DomainException('One or more selected projects are unavailable');
            }
        }

        $pdo->prepare('UPDATE project_assignments pa JOIN projects p ON p.id=pa.project_id SET pa.ends_at=UTC_TIMESTAMP(6) WHERE pa.user_id=? AND (pa.ends_at IS NULL OR pa.ends_at>UTC_TIMESTAMP(6)) AND (p.manager_user_id IS NULL OR p.manager_user_id<>?)')->execute([$userId,$userId]);
        $assignmentStmt = $pdo->prepare(
            'INSERT INTO project_assignments (project_id,user_id,pay_rate_override,created_by) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE pay_rate_override=VALUES(pay_rate_override),ends_at=NULL,created_by=VALUES(created_by)'
        );
        if ($effectiveEmployeeStatus === 'active') {
            foreach ($employeeProjectIds as $projectId) {
                $rate = trim((string)($employeeProjectRates[$projectId] ?? ''));
                $assignmentStmt->execute([$projectId, $userId, $rate !== '' ? $rate : null, (int)$_SESSION['user']['id']]);
            }
        }
    } else {
        $pdo->prepare("UPDATE employee_profiles SET employment_status='inactive' WHERE user_id=?")->execute([$userId]);
        $pdo->prepare('UPDATE project_assignments pa JOIN projects p ON p.id=pa.project_id SET pa.ends_at=UTC_TIMESTAMP(6) WHERE pa.user_id=? AND (pa.ends_at IS NULL OR pa.ends_at>UTC_TIMESTAMP(6)) AND (p.manager_user_id IS NULL OR p.manager_user_id<>?)')->execute([$userId,$userId]);
    }

    // Keep an existing worker relationship independent from the account ACL
    // role. Only create a default relationship when an Employee or explicit
    // Owner account has no worker profile yet.
    $workerStmt = $pdo->prepare('SELECT id,relationship_type,status FROM worker_profiles WHERE user_id=?');
    $workerStmt->execute([$userId]);
    $workerProfile = $workerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $defaultWorkerRelationship = $role === 'owner' ? 'owner' : ($role === 'employee' ? 'employee' : null);
    $workerStatus = $accountDisabled
        ? 'inactive'
        : ($role === 'employee'
            ? $effectiveEmployeeStatus
            : ($role === 'owner' ? 'active' : (string)($workerProfile['status'] ?? 'active')));
    $workerCurrency = isset($currency) ? (string)$currency : (string)\App\Modules\Timekeeping\WorkforceSettings::load($pdo)['currency'];
    if ($workerProfile) {
        $pdo->prepare(
            'UPDATE worker_profiles SET display_name=?,currency=?,status=?,hired_at=COALESCE(?,hired_at),ended_at=? WHERE id=?'
        )->execute([
            $displayName,
            $workerCurrency,
            $workerStatus,
            $role === 'employee' && $employeeHiredAt !== '' ? $employeeHiredAt : null,
            $workerStatus === 'terminated' ? date('Y-m-d') : null,
            (int)$workerProfile['id'],
        ]);
    } elseif ($defaultWorkerRelationship !== null) {
        $workerReviewPolicy = $defaultWorkerRelationship === 'owner' ? 'self_confirm' : 'manager_review';
        // Account role selects the initial relationship only. Compensation is
        // intentionally left unresolved until an authorized policy is chosen.
        $workerCompensationPolicy = 'needs_setup';
        $pdo->prepare(
            'INSERT INTO worker_profiles (user_id,relationship_type,time_review_policy,compensation_policy,status,display_name,currency,hired_at,ended_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $defaultWorkerRelationship,
            $workerReviewPolicy,
            $workerCompensationPolicy,
            $workerStatus,
            $displayName,
            $workerCurrency,
            $role === 'employee' && $employeeHiredAt !== '' ? $employeeHiredAt : null,
            $workerStatus === 'terminated' ? date('Y-m-d') : null,
        ]);
    }

    if (!empty($_POST['save_account_permissions'])) {
        $allPermissions = permission_catalog_flat();
        $wipeStmt = $pdo->prepare('DELETE FROM user_permissions_overrides WHERE user_id = ? AND organization_id IS NULL AND permission = ?');
        foreach ($allPermissions as $perm => $_group) {
            $wipeStmt->execute([$userId, $perm]);
        }

        if ($role !== 'admin') {
            $insertStmt = $pdo->prepare('INSERT INTO user_permissions_overrides (user_id, organization_id, permission, allowed) VALUES (?, ?, ?, ?)');
            foreach ($allPermissions as $perm => $_group) {
                $allowKey = 'allow_' . str_replace('.', '_', $perm);
                $denyKey  = 'deny_' . str_replace('.', '_', $perm);
                if (!empty($_POST[$allowKey])) {
                    $insertStmt->execute([$userId, $orgId, $perm, 1]);
                } elseif (!empty($_POST[$denyKey])) {
                    $insertStmt->execute([$userId, $orgId, $perm, 0]);
                }
            }
        }
    }

    $externalOpsConfig = pa_external_ops_delivery_config($pdo);
    if (!empty($externalOpsConfig['enabled'])) {
        $externalOps = new \App\Services\ExternalOpsIntegrationService();
        $externalOpsApplicationKey = (string)$externalOpsConfig['application_key'];
        $externalOpsActorId = (int)$_SESSION['user']['id'];
        if ($customIntegrationAccessPresent) {
            if ($customIntegrationAccessRequested) {
                if ($accountDisabled) {
                    throw new DomainException('Custom integrations access requires an active account. Clear Disable account or turn off custom integrations access.');
                }
                $externalOps->grantAccountAccess(
                    $pdo,
                    $userId,
                    $externalOpsApplicationKey,
                    $externalOpsActorId,
                    (string)($externalOpsConfig['label'] ?? 'External operations')
                );
            } else {
                $externalOps->revokeAccountAccess(
                    $pdo,
                    $userId,
                    $externalOpsApplicationKey,
                    $externalOpsActorId,
                    (string)($externalOpsConfig['label'] ?? 'External operations')
                );
            }
        } else {
            // Backward compatibility for a form loaded before this control existed.
            $externalOps->refreshGrantedAccount($pdo, $userId, $externalOpsApplicationKey, $externalOpsActorId);
        }
        $currentAssignmentStmt = $pdo->prepare('SELECT id,project_id,user_id,assigned_at,ends_at,created_by,created_at,updated_at,1 active FROM project_assignments WHERE user_id=? AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6))');
        $currentAssignmentStmt->execute([$userId]);
        $currentAssignments = [];
        foreach ($currentAssignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $currentAssignments[(int)$assignment['project_id']] = $assignment;
            $externalOps->enqueueProjectionChange($pdo,$externalOpsApplicationKey,'project_assignment',(int)$assignment['id'],'upsert',$assignment);
        }
        foreach (array_diff_key($previousAssignments,$currentAssignments) as $assignment) {
            $assignment['active'] = 0;
            $externalOps->enqueueProjectionChange($pdo,$externalOpsApplicationKey,'project_assignment',(int)$assignment['id'],'revoke',$assignment);
        }
    }

    if ($securitySensitiveChange) {
        App\Security\SessionRevocation::revokeUserSessions($pdo, $userId);
    }
    $pdo->commit();

    audit_log($pdo, 'user.update', 'user', $userId, ['email' => $email, 'role' => $role, 'acl_role' => $roleName, 'role_id' => $roleId, 'is_disabled' => $accountDisabled ? 1 : 0, 'document_sender_enabled' => $documentSenderEnabled ? 1 : 0, 'custom_integration_access' => $customIntegrationAccessPresent ? ($customIntegrationAccessRequested ? 1 : 0) : null, 'project_assignments' => count($employeeProjectIds), 'sessions_revoked' => $securitySensitiveChange]);
    if ($securitySensitiveChange && $userId === (int)$_SESSION['user']['id']) {
        $_SESSION = [];
        session_destroy();
        header('Location: /?page=login&error=' . urlencode('Your account security settings changed. Please sign in again.'));
        exit;
    }
    header('Location: /?page=account-edit&id=' . $userId . '&success=updated' . ($securitySensitiveChange ? '&sessions_revoked=1' : ''));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Failed to update user: ' . $e->getMessage());
    $message = $e instanceof DomainException ? $e->getMessage() : 'Failed to update user';
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode($message));
}
exit;
