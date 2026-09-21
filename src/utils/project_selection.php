<?php
declare(strict_types=1);

function pa_project_client_org_id(PDO $pdo, int $clientId): ?int
{
    $stmt = $pdo->prepare('SELECT organization_id FROM clients WHERE id = ? LIMIT 1');
    $stmt->execute([$clientId]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null || (int)$value <= 0) {
        return null;
    }
    return (int)$value;
}

/**
 * @return array{0:list<string>,1:list<int|string>}
 */
function pa_active_project_filter_for_client(PDO $pdo, int $clientId): array
{
    $statusClause = 'p.status IN ("active","not_started") AND p.archived_at IS NULL';
    $orgId = pa_project_client_org_id($pdo, $clientId);
    if ($orgId !== null) {
        return [['p.organization_id = ?', $statusClause], [$orgId]];
    }

    return [['p.client_id = ?', '(p.organization_id IS NULL OR p.organization_id = 0)', $statusClause], [$clientId]];
}

function pa_project_is_active_for_client(PDO $pdo, int $projectId, int $clientId, int $userId = 0): bool
{
    if ($projectId <= 0) {
        return false;
    }

    [$where, $params] = pa_active_project_filter_for_client($pdo, $clientId);
    $where[] = 'p.id = ?';
    $params[] = $projectId;

    if (function_exists('scope_clause') && $userId > 0) {
        [$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', $userId);
        if ($scopeWhere !== '') {
            $where[] = trim($scopeWhere);
            $params = array_merge($params, $scopeParams);
        }
    }

    $stmt = $pdo->prepare('SELECT 1 FROM projects p WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}
