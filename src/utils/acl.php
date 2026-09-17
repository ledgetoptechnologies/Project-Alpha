<?php
// src/utils/acl.php
// Permission resolver + data scoping helpers for the single-business model.
// PA users belong to the app/business install. `organizations` are customer records.

function acl_default_user_org_id(PDO $pdo, int $userId): int
{
    return 0;
}

function acl_effective_permission_org_id(PDO $pdo, int $userId, ?int $organizationId = null): int
{
    return 0;
}

function acl_user_role(PDO $pdo, int $userId): string
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    try {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $role = (string)($stmt->fetchColumn() ?: 'member');
    } catch (Throwable $e) {
        $role = 'member';
    }

    $cache[$userId] = in_array($role, ['admin', 'owner', 'staff', 'member', 'employee'], true) ? $role : 'member';
    return $cache[$userId];
}

function user_role_on_org(PDO $pdo, int $userId, int $orgId): ?string
{
    return acl_user_role($pdo, $userId);
}

function user_org_ids(PDO $pdo, int $userId): array
{
    return [];
}

function request_client_org_id(): int
{
    $raw = $_POST['organization_id']
        ?? $_POST['org_id']
        ?? $_GET['organization_id']
        ?? $_GET['org_id']
        ?? 0;
    return max(0, (int)$raw);
}

function resolve_client_context_org_id(PDO $pdo, int $clientId = 0, ?int $projectId = null, ?int $requestedOrgId = null): ?int
{
    $requestedOrgId = (int)($requestedOrgId ?? 0);
    $projectId = (int)($projectId ?? 0);
    try {
        if ($projectId > 0) {
            $stmt = $pdo->prepare('SELECT organization_id FROM projects WHERE id = ? LIMIT 1');
            $stmt->execute([$projectId]);
            $orgId = (int)($stmt->fetchColumn() ?: 0);
            if ($orgId > 0) {
                return $orgId;
            }
        }

        if ($clientId > 0) {
            $stmt = $pdo->prepare('SELECT organization_id FROM clients WHERE id = ? LIMIT 1');
            $stmt->execute([$clientId]);
            $orgId = (int)($stmt->fetchColumn() ?: 0);
            if ($orgId > 0) {
                return $orgId;
            }
        }

    } catch (Throwable $e) {
    }

    // A requested organization is only a fallback when no client or project
    // relationship establishes authoritative context. Never let a stale form
    // value override either relationship.
    return $requestedOrgId > 0 ? $requestedOrgId : null;
}

function compute_permissions_hash(PDO $pdo, int $userId, int $organizationId = 0): string
{
    $role = acl_user_role($pdo, $userId);
    $roleId = role_id_by_name($pdo, $role, null);

    try {
        $stmt = $pdo->prepare('SELECT permission, allowed FROM user_permissions_overrides WHERE user_id = ? AND organization_id IS NULL ORDER BY permission');
        $stmt->execute([$userId]);
        $overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $overrides = [];
    }

    return hash('sha256', json_encode(['role' => $role, 'role_id' => $roleId, 'overrides' => $overrides]));
}

function user_permissions(PDO $pdo, int $userId, ?int $organizationId = null): array
{
    static $cache = [];
    $role = acl_user_role($pdo, $userId);
    $key = "{$userId}:global:{$role}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if ($role === 'admin') {
        $cache[$key] = ['*' => true];
        return $cache[$key];
    }

    $permissions = [];

    try {
        $roleId = role_id_by_name($pdo, $role, null);
        if ($roleId !== null) {
            $stmt = $pdo->prepare('SELECT permission, allowed FROM role_permissions WHERE role_id = ?');
            $stmt->execute([$roleId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $permissions[$row['permission']] = (bool)$row['allowed'];
            }
        }
    } catch (Throwable $e) {
        $permissions = [];
    }

    try {
        $stmt = $pdo->prepare('SELECT permission, allowed
            FROM user_permissions_overrides
            WHERE user_id = ? AND organization_id IS NULL');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $permissions[$row['permission']] = (bool)$row['allowed'];
        }
    } catch (Throwable $e) {
        // Keep role-derived permissions if overrides are unavailable.
    }

    $cache[$key] = $permissions;
    return $permissions;
}

function user_can(PDO $pdo, int $userId, string $permission, ?int $organizationId = null): bool
{
    $perms = user_permissions($pdo, $userId, $organizationId);
    if (!empty($perms['*'])) {
        return true;
    }
    if (isset($perms[$permission])) {
        return $perms[$permission];
    }

    $parts = explode('.', $permission);
    while (count($parts) > 1) {
        array_pop($parts);
        $wildcard = implode('.', $parts) . '.*';
        if (isset($perms[$wildcard])) {
            return $perms[$wildcard];
        }
    }

    return false;
}

function acl_user_has_org_wide_scope(PDO $pdo, int $userId, ?int $organizationId = null): bool
{
    $servicePrincipal = $GLOBALS['pa_service_principal'] ?? null;
    if (is_array($servicePrincipal) && ($servicePrincipal['type'] ?? null) === 'api_key') {
        // API endpoint scopes are checked before controller dispatch. Preserve
        // the historical global read projection without impersonating an
        // interactive administrator account.
        return true;
    }
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    return in_array(acl_user_role($pdo, $userId), ['owner', 'staff'], true);
}

function acl_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$key] = false;
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function scope_clause(PDO $pdo, string $tableAlias, int $userId): array
{
    if (acl_user_has_org_wide_scope($pdo, $userId, 0)) {
        return ['', []];
    }

    return ["{$tableAlias}.created_by = ?", [$userId]];
}

function finance_scope_clause(PDO $pdo, string $tableAlias, int $userId, int $clientOrgId = 0, string $ownerColumn = 'created_by'): array
{
    $where = [];
    $params = [];

    if ($clientOrgId > 0) {
        $where[] = "{$tableAlias}.organization_id = ?";
        $params[] = $clientOrgId;
    }

    if (!acl_user_has_org_wide_scope($pdo, $userId, 0)) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $ownerColumn)) {
            $ownerColumn = 'created_by';
        }
        $where[] = "{$tableAlias}.{$ownerColumn} = ?";
        $params[] = $userId;
    }

    return [$where ? implode(' AND ', $where) : '1=1', $params];
}

function can_access_record(PDO $pdo, string $table, int $recordId, int $userId): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    if ($table === 'organizations') {
        return acl_user_has_org_wide_scope($pdo, $userId, 0);
    }

    $select = 'organization_id';
    if (acl_table_has_column($pdo, $table, 'created_by')) {
        $select .= ', created_by';
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    if (acl_user_has_org_wide_scope($pdo, $userId, 0)) {
        return true;
    }

    return isset($row['created_by']) && (int)$row['created_by'] === $userId;
}

function role_id_by_name(PDO $pdo, string $roleName, ?int $orgId = null): ?int
{
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM roles
             WHERE name = ? AND (organization_id = ? OR organization_id IS NULL)
             ORDER BY (organization_id IS NULL) ASC, is_system DESC
             LIMIT 1'
        );
        $stmt->execute([$roleName, $orgId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function require_record_ownership(PDO $pdo, string $table, int $recordId): void
{
    if (!can_access_record($pdo, $table, $recordId, (int)($_SESSION['user']['id'] ?? 0))) {
        require_once __DIR__ . '/acl_middleware.php';
        deny_response($table . '/view');
    }
}
