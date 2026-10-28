<?php
// src/controllers/financial/financial_api.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

header('Content-Type: application/json');
$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorId <= 0 || !user_can($pdo, $actorId, 'financial.view', 0)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// Get filters from query params
$startDate = $_GET['start_date'] ?? date('Y') . '-01-01';
$endDate = $_GET['end_date'] ?? date('Y') . '-12-31';
$clientId = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : null;
if ($clientId !== null && ($clientId <= 0 || !can_access_record($pdo, 'clients', $clientId, $actorId))) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit;
}
$groupBy = $_GET['group_by'] ?? 'month'; // month, week, day

// Validate groupBy
if (!in_array($groupBy, ['month', 'week', 'day'])) {
    $groupBy = 'month';
}

// Build date format for MySQL based on grouping
$dateFormat = match($groupBy) {
    'month' => '%Y-%m',
    'week' => '%Y-W%u',
    'day' => '%Y-%m-%d',
    default => '%Y-%m'
};

// Base query - get paid invoices with payment dates
$query = "
    SELECT 
        DATE_FORMAT(p.created_at, ?) as period,
        SUM(p.amount) as total_income,
        COUNT(DISTINCT i.id) as invoice_count
    FROM payments p
    JOIN invoices i ON i.id = p.invoice_id
    WHERE p.status = 'succeeded'
    AND DATE(p.created_at) BETWEEN ? AND ?
";

$params = [$dateFormat, $startDate, $endDate];
[$invoiceScopeWhere, $invoiceScopeParams] = finance_scope_clause($pdo, 'i', $actorId, request_client_org_id(), 'created_by');
$query .= " AND {$invoiceScopeWhere}";
$params = array_merge($params, $invoiceScopeParams);

// Add client filter if specified
if ($clientId !== null) {
    $query .= " AND i.client_id = ?";
    $params[] = $clientId;
}

$query .= " GROUP BY period ORDER BY period ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $response = [
        'success' => true,
        'data' => array_map(function($row) {
            return [
                'period' => $row['period'],
                'income' => (float)$row['total_income'],
                'invoice_count' => (int)$row['invoice_count']
            ];
        }, $results),
        'filters' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'client_id' => $clientId,
            'group_by' => $groupBy
        ]
    ];
    
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch financial data'
    ]);
}
exit;
