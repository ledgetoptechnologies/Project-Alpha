<?php
// src/controllers/organization/organization_departments.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/LinkResolverService.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$action = (string)($_POST['action'] ?? '');
$organizationId = (int)($_POST['organization_id'] ?? 0);
$redirect = '/?page=organization/organization-view&id=' . max(0, $organizationId);

function organization_department_created_link_transition(PDO $pdo, int $organizationId, int $departmentId): void
{
    try {
        $pdo->prepare('UPDATE organizations SET link_strategy = "department_links_only", updated_at = NOW() WHERE id = ?')
            ->execute([$organizationId]);

        $run = static function () use ($pdo, $organizationId, $departmentId): void {
            try {
                $service = new LinkResolverService($pdo);
                $service->autoGenerateForDepartment($departmentId);
                $service->removeResolverOrganizationLinks($organizationId);
                $service->autoGenerateForOrganization($organizationId);
            } catch (Throwable $e) {
                @error_log('[organization_departments] background link transition failed: ' . $e->getMessage());
            }
        };

        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(static function () use ($run): void {
                @fastcgi_finish_request();
                $run();
            });
        } else {
            $run();
        }
    } catch (Throwable $e) {
        @error_log('[organization_departments] link transition failed: ' . $e->getMessage());
    }
}

try {
    if ($organizationId <= 0) {
        throw new RuntimeException('Invalid organization');
    }
    require_record_ownership($pdo, 'organizations', $organizationId);
    if (api_v2_directory_management_guard($pdo, 'unit', $action)) {
        throw new RuntimeException(API_V2_DIRECTORY_MANAGEMENT_LABEL);
    }
    $projection = new \App\Services\PortalProjectionMutationService();
    $beforeScopes = $projection->organizationScopes($pdo, $organizationId);
    $createdDepartmentTransition = null;
    $pdo->beginTransaction();
    api_v2_directory_management_acquire_shared_gate($pdo, true);

    if ($action === 'save_link_strategy') {
        $strategy = (string)($_POST['link_strategy'] ?? 'overall_folder');
        if (!in_array($strategy, ['department_links_only', 'overall_folder', 'shared_folder'], true)) {
            $strategy = 'overall_folder';
        }
        $stmt = $pdo->prepare('UPDATE organizations SET link_strategy = ?, source_version = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$strategy, portal_projection_source_version(), $organizationId]);
        $flag = 'link_strategy_saved=1';
    } elseif ($action === 'save_department') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $creatingDepartment = $departmentId <= 0;
        $existingDepartmentCount = 0;
        if ($creatingDepartment) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM organization_departments WHERE organization_id = ?');
            $countStmt->execute([$organizationId]);
            $existingDepartmentCount = (int)$countStmt->fetchColumn();
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $folderName = trim((string)($_POST['folder_name'] ?? ''));
        $aliasesText = trim((string)($_POST['folder_aliases'] ?? ''));
        $resolverMode = (string)($_POST['resolver_mode'] ?? 'auto_attach');
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Department name is required');
        }
        if (!in_array($resolverMode, ['auto_attach', 'review', 'manual_only', 'excluded'], true)) {
            $resolverMode = 'manual_only';
        }

        $aliases = [];
        foreach (preg_split('/\r\n|\r|\n/', $aliasesText) ?: [] as $alias) {
            $alias = trim($alias);
            if ($alias !== '') {
                $aliases[] = $alias;
            }
        }
        $aliases = array_values(array_unique($aliases));
        $aliasesJson = $aliases ? json_encode($aliases, JSON_UNESCAPED_SLASHES) : null;

        if ($departmentId > 0) {
            $owner = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ? LIMIT 1');
            $owner->execute([$departmentId]);
            if ((int)($owner->fetchColumn() ?: 0) !== $organizationId) {
                throw new RuntimeException('Department not found');
            }
            $stmt = $pdo->prepare('
                UPDATE organization_departments
                SET name = ?, folder_name = ?, folder_aliases = ?, resolver_mode = ?, notes = ?, source_version = ?, updated_at = NOW()
                WHERE id = ? AND organization_id = ?
            ');
            $stmt->execute([$name, $folderName !== '' ? $folderName : null, $aliasesJson, $resolverMode, $notes !== '' ? $notes : null, portal_projection_source_version(), $departmentId, $organizationId]);
            api_v2_directory_record($pdo, 'unit', $departmentId);
            $flag = 'department_saved=1';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO organization_departments (organization_id, name, folder_name, folder_aliases, resolver_mode, notes, source_version)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$organizationId, $name, $folderName !== '' ? $folderName : null, $aliasesJson, $resolverMode, $notes !== '' ? $notes : null, portal_projection_source_version()]);
            $departmentId = (int)$pdo->lastInsertId();
            api_v2_directory_record($pdo, 'unit', $departmentId);
            if ($existingDepartmentCount === 0) {
                $createdDepartmentTransition = [$organizationId, $departmentId];
            }
            $flag = 'department_created=1';
        }
    } elseif ($action === 'delete_department') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        if ($departmentId <= 0) {
            throw new RuntimeException('Invalid department');
        }
        $identity = $pdo->prepare('SELECT public_id FROM organization_departments WHERE id=? AND organization_id=?');
        $identity->execute([$departmentId,$organizationId]);$departmentPublicId=$identity->fetchColumn();
        if(!is_string($departmentPublicId))throw new RuntimeException('Department not found');
        api_v2_directory_record_delete($pdo,'unit',$departmentPublicId);
        $stmt = $pdo->prepare('DELETE FROM organization_departments WHERE id = ? AND organization_id = ?');
        $stmt->execute([$departmentId, $organizationId]);
        $flag = 'department_deleted=1';
    } elseif ($action === 'assign_contact') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($departmentId <= 0 || $clientId <= 0) {
            throw new RuntimeException('Invalid department contact');
        }
        $dept = $pdo->prepare('SELECT 1 FROM organization_departments WHERE id = ? AND organization_id = ? LIMIT 1');
        $dept->execute([$departmentId, $organizationId]);
        if (!$dept->fetchColumn()) {
            throw new RuntimeException('Department not found');
        }
        $client = $pdo->prepare('SELECT 1 FROM clients WHERE id = ? AND organization_id = ? AND archived = 0 LIMIT 1');
        $client->execute([$clientId, $organizationId]);
        if (!$client->fetchColumn()) {
            throw new RuntimeException('Client is not in this organization');
        }
        $stmt = $pdo->prepare('
            INSERT INTO organization_department_contacts (department_id, client_id, role, is_primary)
            VALUES (?, ?, "contact", ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role), is_primary = GREATEST(is_primary, VALUES(is_primary))
        ');
        $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;
        if ($isPrimary) {
            $pdo->prepare('UPDATE organization_department_contacts SET is_primary = 0 WHERE department_id = ?')
                ->execute([$departmentId]);
        }
        $stmt->execute([$departmentId, $clientId, $isPrimary]);
        api_v2_directory_record($pdo, 'unit', $departmentId);
        $flag = 'department_contact_added=1';
    } elseif ($action === 'set_primary_contact') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($departmentId <= 0 || $clientId <= 0) {
            throw new RuntimeException('Invalid department contact');
        }
        $stmt = $pdo->prepare('
            SELECT 1
            FROM organization_department_contacts odc
            JOIN organization_departments od ON od.id = odc.department_id
            WHERE odc.department_id = ? AND odc.client_id = ? AND od.organization_id = ?
            LIMIT 1
        ');
        $stmt->execute([$departmentId, $clientId, $organizationId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Department contact not found');
        }
        $pdo->prepare('UPDATE organization_department_contacts SET is_primary = 0 WHERE department_id = ?')
            ->execute([$departmentId]);
        $pdo->prepare('UPDATE organization_department_contacts SET is_primary = 1 WHERE department_id = ? AND client_id = ?')
            ->execute([$departmentId, $clientId]);
        api_v2_directory_record($pdo, 'unit', $departmentId);
        $flag = 'department_contact_primary=1';
    } elseif ($action === 'remove_contact') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($departmentId <= 0 || $clientId <= 0) {
            throw new RuntimeException('Invalid department contact');
        }
        $stmt = $pdo->prepare('
            DELETE odc
            FROM organization_department_contacts odc
            JOIN organization_departments od ON od.id = odc.department_id
            WHERE odc.department_id = ? AND odc.client_id = ? AND od.organization_id = ?
        ');
        $stmt->execute([$departmentId, $clientId, $organizationId]);
        api_v2_directory_record($pdo, 'unit', $departmentId);
        $flag = 'department_contact_removed=1';
    } else {
        throw new RuntimeException('Invalid department action');
    }

    $projection->afterMutation($pdo, array_merge($beforeScopes, $projection->organizationScopes($pdo, $organizationId)));
    $pdo->commit();
    if ($createdDepartmentTransition !== null) {
        organization_department_created_link_transition($pdo, $createdDepartmentTransition[0], $createdDepartmentTransition[1]);
    }
    header('Location: ' . $redirect . '&' . $flag);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[organization_departments] ' . $e->getMessage());
    header('Location: ' . $redirect . '&error=' . urlencode($e->getMessage()));
    exit;
}
