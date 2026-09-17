<?php

declare(strict_types=1);

require_once __DIR__ . '/escaper.php';
require_once __DIR__ . '/resolver_link_policy.php';

function pa_config_value(PDO $pdo, string $key, $default = null)
{
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function pa_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return $cache[$key] = false;
    }
    try {
        $stmt = $pdo->prepare('
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            LIMIT 1
        ');
        $stmt->execute([$table, $column]);
        return $cache[$key] = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

/**
 * @return list<array{id:int,title:?string,url:string,link_type:string,entity_type:string,entity_id:int,source_label:string}>
 */
function pa_invoice_links_for_project_invoice(PDO $pdo, int $projectInvoiceId, ?array $recipientClientIds = null): array
{
    $projectDepartmentSelect = pa_table_has_column($pdo, 'projects', 'department_id') ? 'p.department_id' : 'NULL AS department_id';
    $stmt = $pdo->prepare("
        SELECT pi.id, pi.project_id, pi.organization_id, pi.primary_client_id,
               p.organization_id AS project_organization_id, {$projectDepartmentSelect}
        FROM project_invoices pi
        JOIN projects p ON p.id = pi.project_id
        WHERE pi.id = ?
        LIMIT 1
    ");
    $stmt->execute([$projectInvoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return [];
    }

    return pa_invoice_links_for_context(
        $pdo,
        (int)$invoice['project_id'],
        (int)($invoice['project_organization_id'] ?: $invoice['organization_id'] ?: 0) ?: null,
        (int)($invoice['department_id'] ?? 0) ?: null,
        (int)($invoice['primary_client_id'] ?? 0) ?: null,
        $recipientClientIds
    );
}

/**
 * @return list<array{id:int,title:?string,url:string,link_type:string,entity_type:string,entity_id:int,source_label:string}>
 */
function pa_invoice_links_for_invoice(PDO $pdo, int $invoiceId): array
{
    $projectDepartmentSelect = pa_table_has_column($pdo, 'projects', 'department_id') ? 'p.department_id' : 'NULL AS department_id';
    $stmt = $pdo->prepare("
        SELECT i.project_id, i.organization_id, i.client_id,
               p.organization_id AS project_organization_id,
               c.organization_id AS client_organization_id,
               {$projectDepartmentSelect}
        FROM invoices i
        LEFT JOIN projects p ON p.id = i.project_id
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return [];
    }

    return pa_invoice_links_for_context(
        $pdo,
        (int)($invoice['project_id'] ?? 0) ?: null,
        (int)($invoice['project_organization_id'] ?: $invoice['organization_id'] ?: $invoice['client_organization_id'] ?: 0) ?: null,
        (int)($invoice['department_id'] ?? 0) ?: null,
        (int)($invoice['client_id'] ?? 0) ?: null,
        null
    );
}

/**
 * @return list<array{id:int,title:?string,url:string,link_type:string,entity_type:string,entity_id:int,source_label:string}>
 */
function pa_invoice_links_for_context(
    PDO $pdo,
    ?int $projectId,
    ?int $organizationId,
    ?int $departmentId,
    ?int $primaryClientId,
    ?array $recipientClientIds
): array {
    if ((string)pa_config_value($pdo, 'invoice_content_links_enabled', '0') !== '1') {
        return [];
    }
    if (!pa_table_has_column($pdo, 'entity_links', 'include_on_invoices')) {
        return [];
    }

    $links = pa_invoice_links_query_for_context(
        $pdo,
        $projectId,
        $organizationId,
        $departmentId,
        $primaryClientId,
        $recipientClientIds
    );
    if ($links) {
        return $links;
    }

    pa_invoice_links_run_just_in_time_refresh(
        $pdo,
        $projectId,
        $organizationId,
        $departmentId,
        $primaryClientId,
        $recipientClientIds
    );

    return pa_invoice_links_query_for_context(
        $pdo,
        $projectId,
        $organizationId,
        $departmentId,
        $primaryClientId,
        $recipientClientIds
    );
}

/**
 * @return list<array{id:int,title:?string,url:string,link_type:string,entity_type:string,entity_id:int,source_label:string}>
 */
function pa_invoice_links_query_for_context(
    PDO $pdo,
    ?int $projectId,
    ?int $organizationId,
    ?int $departmentId,
    ?int $primaryClientId,
    ?array $recipientClientIds
): array {

    $projectSpecificEnabled = (string)pa_config_value($pdo, 'project_specific_links_enabled', '0') === '1';
    $candidateClauses = [];
    $params = [];

    if ($projectSpecificEnabled && $projectId) {
        $candidateClauses[] = '(el.entity_type = "project" AND el.entity_id = ?)';
        $params[] = $projectId;
    }

    if ($departmentId) {
        $candidateClauses[] = '(el.entity_type = "department" AND el.entity_id = ?)';
        $params[] = $departmentId;
    }

    if ($organizationId) {
        if ($departmentId) {
            $candidateClauses[] = '(
                el.entity_type = "organization" AND el.entity_id = ? AND (
                    el.visibility_scope = "all_departments"
                    OR (el.visibility_scope = "selected_departments" AND JSON_CONTAINS(COALESCE(el.selected_department_ids, JSON_ARRAY()), CAST(? AS JSON)))
                )
            )';
            $params[] = $organizationId;
            $params[] = (string)$departmentId;
        } else {
            $candidateClauses[] = '(el.entity_type = "organization" AND el.entity_id = ? AND el.visibility_scope IN ("entity_only","org_contacts","all_departments"))';
            $params[] = $organizationId;
        }
    }

    $requestedClientIds = $recipientClientIds === null
        ? null
        : array_values(array_unique(array_filter(array_map('intval', $recipientClientIds), static fn($id) => $id > 0)));
    $clientIds = [];
    if ($projectId && pa_table_has_column($pdo, 'project_clients', 'can_view_invoice_links')) {
        $linkClientQuery = 'SELECT client_id FROM project_clients WHERE project_id = ? AND can_view_invoice_links = 1';
        $linkClientParams = [$projectId];
        if ($recipientClientIds !== null) {
            if (!$requestedClientIds) {
                $linkClientQuery = '';
            } else {
                $linkClientQuery .= ' AND client_id IN (' . implode(',', array_fill(0, count($requestedClientIds), '?')) . ')';
                $linkClientParams = array_merge($linkClientParams, $requestedClientIds);
            }
        }
        if ($linkClientQuery !== '') {
            $stmt = $pdo->prepare($linkClientQuery);
            $stmt->execute($linkClientParams);
            $clientIds = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        }
    } elseif (!$projectId) {
        // Standalone invoices retain their direct client context. Project
        // invoices never infer link access from the delivery recipient.
        $clientIds = $requestedClientIds ?? ($primaryClientId ? [$primaryClientId] : []);
    }
    if ($clientIds) {
        $candidateClauses[] = '(el.entity_type = "client" AND el.entity_id IN (' . implode(',', array_fill(0, count($clientIds), '?')) . '))';
        $params = array_merge($params, $clientIds);
    }

    if (!$candidateClauses) {
        return [];
    }

    $sql = '
        SELECT el.id, el.title, el.url, el.link_type, el.entity_type, el.entity_id
        FROM entity_links el
        WHERE el.include_on_invoices = 1
          AND el.is_expired = 0
          AND ' . pa_resolver_link_visibility_sql('el') . '
          AND (' . implode(' OR ', $candidateClauses) . ')
        ORDER BY
          CASE el.entity_type
            WHEN "project" THEN 1
            WHEN "department" THEN 2
            WHEN "client" THEN 3
            WHEN "organization" THEN 4
            ELSE 5
          END,
          el.title ASC,
          el.id ASC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = [];
    $links = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $url = (string)$row['url'];
        if ($url === '' || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $row['id'] = (int)$row['id'];
        $row['entity_id'] = (int)$row['entity_id'];
        $row['source_label'] = match ((string)$row['entity_type']) {
            'project' => 'Project override',
            'department' => 'Department',
            'client' => 'Client',
            'organization' => 'Organization',
            default => 'Link',
        };
        $links[] = $row;
    }

    return $links;
}

function pa_invoice_links_run_just_in_time_refresh(
    PDO $pdo,
    ?int $projectId,
    ?int $organizationId,
    ?int $departmentId,
    ?int $primaryClientId,
    ?array $recipientClientIds
): void {
    if ((string)pa_config_value($pdo, 'link_resolver_invoice_auto_attach_enabled', '0') !== '1') {
        return;
    }
    if ((string)pa_config_value($pdo, 'link_resolver_enabled', '0') !== '1') {
        return;
    }

    $servicePath = __DIR__ . '/../services/LinkResolverService.php';
    if (!is_file($servicePath)) {
        return;
    }
    require_once $servicePath;
    if (!class_exists('LinkResolverService')) {
        return;
    }

    try {
        $service = new LinkResolverService($pdo);
        if ($departmentId) {
            $service->refreshLinks('department', $departmentId);
            return;
        }
        if ($organizationId) {
            $service->refreshLinks('organization', $organizationId);
            return;
        }

        $clientIds = [];
        if ($recipientClientIds !== null) {
            $clientIds = array_values(array_unique(array_filter(array_map('intval', $recipientClientIds), static fn($id) => $id > 0)));
        } elseif ($primaryClientId) {
            $clientIds[] = $primaryClientId;
        }
        if ($projectId && pa_table_has_column($pdo, 'project_clients', 'can_view_invoice_links')) {
            $stmt = $pdo->prepare('SELECT client_id FROM project_clients WHERE project_id = ? AND can_view_invoice_links = 1');
            $stmt->execute([$projectId]);
            $clientIds = array_values(array_unique(array_map('intval', array_merge($clientIds, $stmt->fetchAll(PDO::FETCH_COLUMN)))));
        }

        foreach ($clientIds as $clientId) {
            $service->refreshLinks('client', $clientId);
        }
    } catch (Throwable $e) {
        @error_log('[invoice_links] Just-in-time link resolver refresh failed: ' . $e->getMessage());
    }
}

function pa_invoice_links_html(array $links): string
{
    if (!$links) {
        return '';
    }
    $html = '<div style="border:1px solid #dbeafe;background:#eff6ff;border-radius:8px;padding:14px;margin:18px 0">';
    $html .= '<div style="font-weight:700;margin-bottom:8px">View your content here:</div><ul style="margin:0;padding-left:20px">';
    foreach ($links as $link) {
        $title = trim((string)($link['title'] ?? '')) ?: pa_invoice_link_type_label((string)($link['link_type'] ?? 'Link'));
        $html .= '<li style="margin:4px 0"><a href="' . e((string)$link['url']) . '" target="_blank" rel="noopener noreferrer">' . e($title) . '</a></li>';
    }
    $html .= '</ul></div>';
    return $html;
}

function pa_invoice_link_type_label(string $linkType): string
{
    return match ($linkType) {
        'manual_webodm_map' => 'WebODM Map',
        'manual_webodm_model' => 'WebODM Model',
        'manual_dropbox' => 'Dropbox Link',
        'manual_gdrive' => 'Google Drive Link',
        'manual_onedrive' => 'OneDrive Link',
        'manual_external' => 'External Link',
        'auto_dropbox' => 'Dropbox Folder',
        'auto_gdrive' => 'Google Drive Folder',
        'auto_s3' => 'S3 Folder',
        'auto_r2' => 'Cloudflare R2 Folder',
        default => ucwords(str_replace(['manual_', 'auto_', '_'], ['', '', ' '], $linkType ?: 'Link')),
    };
}
