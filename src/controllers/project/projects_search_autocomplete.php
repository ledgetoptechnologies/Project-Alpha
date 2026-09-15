<?php
// src/controllers/project/projects_search_autocomplete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
header('Content-Type: application/json');
$term = trim((string)($_GET['term'] ?? ''));
if ($term === '') { echo json_encode([]); exit; }

$where = ['p.name LIKE ?', 'p.archived_at IS NULL'];
$params = ['%'.$term.'%'];

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', (int)$_SESSION['user']['id']);
if ($scopeWhere !== '') {
    $where[] = trim($scopeWhere);
    $params = array_merge($params, $scopeParams);
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$sql = "SELECT p.id, p.name, p.client_id, c.name as client_name, p.organization_id, o.name as organization_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN organizations o ON o.id = p.organization_id
        {$whereSQL}
        ORDER BY p.name
        LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format results for autocomplete
$formatted = array_map(function($row) {
    return [
        'id' => $row['id'],
        'name' => $row['name'],
        'client_name' => $row['client_name'],
        'organization_name' => $row['organization_name']
    ];
}, $results);

echo json_encode($formatted);
