<?php
// src/controllers/accounts/accounts_create.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../Modules/Timekeeping/WorkforceSettings.php';
require_once __DIR__ . '/../../utils/external_ops.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}


$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$postedRoleId = (int)($_POST['role_id'] ?? 0);
$postedLegacyRole = trim($_POST['role'] ?? 'user');
$password = $_POST['password'] ?? '';
$forceReset = !empty($_POST['force_reset']);

// Validation
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid email address'));
    exit;
}

if ($username !== '') {
    $usernameCheck = $pdo->prepare('SELECT id FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1');
    $usernameCheck->execute([$username]);
    if ($usernameCheck->fetchColumn()) {
        header('Location: /?page=accounts&action=create&error=' . urlencode('Username already exists'));
        exit;
    }
}

$pwdErr = password_policy_error((string)$password);
if ($pwdErr !== null) {
    header('Location: /?page=accounts&error=' . urlencode($pwdErr));
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
$employeeHourlyRate = trim((string)($_POST['employee_hourly_rate'] ?? ''));
$employeeCanViewPay = !empty($_POST['employee_can_view_pay']);
$employeeProjectIds = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['employee_project_ids'] ?? [])),
    static fn(int $id): bool => $id > 0
)));
$employeeProjectRates = (array)($_POST['employee_project_rates'] ?? []);
$workerCompensationPolicy = trim((string)($_POST['worker_compensation_policy'] ?? 'needs_setup'));
$allowedWorkerCompensationPolicies = ['needs_setup', 'rules', 'nonpayable'];
if (!in_array($workerCompensationPolicy, $allowedWorkerCompensationPolicies, true)) {
    $workerCompensationPolicy = 'needs_setup';
}

if ($role === 'employee') {
    if ($employeeFirstName === '') {
        header('Location: /?page=accounts&action=create&error=' . urlencode('Employee first name is required'));
        exit;
    }
    if ($employeeHourlyRate !== '' && (!is_numeric($employeeHourlyRate) || (float)$employeeHourlyRate < 0)) {
        header('Location: /?page=accounts&action=create&error=' . urlencode('Employee hourly rate must be zero or greater'));
        exit;
    }
    foreach ($employeeProjectIds as $projectId) {
        $rate = trim((string)($employeeProjectRates[$projectId] ?? ''));
        if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0)) {
            header('Location: /?page=accounts&action=create&error=' . urlencode('Project pay rates must be zero or greater'));
            exit;
        }
    }
}

// Check if email already exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    header('Location: /?page=accounts&error=' . urlencode('Email already exists'));
    exit;
}

// Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Insert user
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, force_password_reset) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, $username ?: null, $passwordHash, $role, $forceReset ? 1 : 0]);
    $newUserId = (int)$pdo->lastInsertId();
    $displayName = $role === 'employee'
        ? trim($employeeFirstName . ' ' . $employeeLastName)
        : ($username !== '' ? $username : $email);
    $pdo->prepare("INSERT INTO team_members (user_id,display_name,email,is_active,profile_source) VALUES (?,?,?,?, 'pa')")
        ->execute([$newUserId,$displayName,$email,1]);
    if ($role === 'employee') {
        $settings = \App\Modules\Timekeeping\WorkforceSettings::load($pdo);
        $currency = (string)$settings['currency'];
        $pdo->prepare(
            'INSERT INTO employee_profiles (user_id,first_name,last_name,hourly_rate,currency,employee_can_view_pay,hired_at)
             VALUES (?,?,?,?,?,?,CURRENT_DATE)'
        )->execute([
            $newUserId,
            $employeeFirstName,
            $employeeLastName,
            $employeeHourlyRate !== '' ? $employeeHourlyRate : null,
            $currency,
            $employeeCanViewPay ? 1 : 0,
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
            $assignmentStmt = $pdo->prepare(
                'INSERT INTO project_assignments (project_id,user_id,pay_rate_override,created_by) VALUES (?,?,?,?)'
            );
            foreach ($employeeProjectIds as $projectId) {
                $rate = trim((string)($employeeProjectRates[$projectId] ?? ''));
                $assignmentStmt->execute([$projectId, $newUserId, $rate !== '' ? $rate : null, (int)$_SESSION['user']['id']]);
            }
        }
    }

    // Authentication role and worker relationship are separate. Employee and
    // explicit owner roles receive a linked worker profile automatically;
    // administrators are not silently classified as owners.
    if (in_array($role, ['employee', 'owner'], true)) {
        $settings ??= \App\Modules\Timekeeping\WorkforceSettings::load($pdo);
        $workerCurrency = (string)$settings['currency'];
        $workerRelationship = $role === 'owner' ? 'owner' : 'employee';
        $workerReviewPolicy = $workerRelationship === 'owner' ? 'self_confirm' : 'manager_review';
        $pdo->prepare(
            'INSERT INTO worker_profiles (user_id,relationship_type,time_review_policy,compensation_policy,status,display_name,currency,hired_at)
             VALUES (?,?,?, ?,"active",?,?,?)'
        )->execute([
            $newUserId,
            $workerRelationship,
            $workerReviewPolicy,
            $workerCompensationPolicy,
            $displayName,
            $workerCurrency,
            $role === 'employee' ? date('Y-m-d') : null,
        ]);
    }

    require_once __DIR__ . '/../../utils/permission_catalog.php';
    if ($role !== 'admin') {
        $allPermissions = permission_catalog_flat();
        try {
            $insertStmt = $pdo->prepare('INSERT INTO user_permissions_overrides (user_id, organization_id, permission, allowed) VALUES (?, ?, ?, ?)');
            foreach ($allPermissions as $perm => $_) {
                $allowKey = 'allow_' . str_replace('.', '_', $perm);
                $denyKey  = 'deny_' . str_replace('.', '_', $perm);
                if (!empty($_POST[$allowKey])) {
                    $insertStmt->execute([$newUserId, $orgId, $perm, 1]);
                } elseif (!empty($_POST[$denyKey])) {
                    $insertStmt->execute([$newUserId, $orgId, $perm, 0]);
                }
            }
        } catch (Throwable $e) { /* non-fatal — ACL tables may not exist */ }
    }

    $externalOpsConfig = pa_external_ops_delivery_config($pdo);
    if (!empty($externalOpsConfig['enabled'])) {
        $externalOps = new \App\Services\ExternalOpsIntegrationService();
        $assignmentEvents = $pdo->prepare('SELECT id,project_id,user_id,assigned_at,ends_at,created_by,created_at,updated_at,1 active FROM project_assignments WHERE user_id=? AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6))');
        $assignmentEvents->execute([$newUserId]);
        foreach ($assignmentEvents->fetchAll(PDO::FETCH_ASSOC) as $assignmentEvent) {
            $externalOps->enqueueProjectionChange(
                $pdo,
                (string)$externalOpsConfig['application_key'],
                'project_assignment',
                (int)$assignmentEvent['id'],
                'upsert',
                $assignmentEvent
            );
        }
    }

    $pdo->commit();
    audit_log($pdo, 'user.create', 'user', $newUserId, ['email' => $email, 'role' => $role, 'acl_role' => $roleName, 'role_id' => $roleId, 'team_member_created'=>true, 'project_assignments' => count($employeeProjectIds)]);
    header('Location: /?page=accounts&created=1');
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('Failed to create user: ' . $e->getMessage());
    $message = $e instanceof DomainException ? $e->getMessage() : 'Failed to create user';
    header('Location: /?page=accounts&action=create&error=' . urlencode($message));
}
exit;
