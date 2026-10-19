<?php
// src/controllers/time-tracking/time_entries_unbilled.php
// AJAX endpoint returning unbilled billable time entries as JSON
require_once __DIR__ . '/../../utils/workforce_availability.php';
pa_workforce_require_available('time-tracking/unbilled');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/time_tracking_schema.php';
require_once __DIR__ . '/../../utils/acl.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$projectCode = trim((string)($_GET['project_code'] ?? ''));
$contractId = (int)($_GET['contract_id'] ?? 0);
$invoiceId = (int)($_GET['invoice_id'] ?? 0);

header('Content-Type: application/json');
try {
    pa_time_tracking_ensure_schema($pdo);
} catch (Throwable $e) {
    @error_log('[TimeTrackingUnbilled] Schema repair failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Time tracking storage is not ready.']);
    exit;
}

if ($userId === 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$canViewAll = ($_SESSION['user']['role'] ?? '') === 'admin' || user_can($pdo, $userId, 'billing.view', 0);
$params = $canViewAll ? [1, 0] : [$userId, 1, 0];
$sql = 'SELECT te.id, te.started_at, te.ended_at, te.project_code, te.contract_id, te.invoice_id, te.description, te.hours, te.rate, (te.hours * te.rate) AS amount, c.name AS client_name, COALESCE(il.item_name, "Tracked Time") AS service_name, wbc.approval_snapshot_id AS adjustment_group_id
        FROM time_entries te
        LEFT JOIN clients c ON te.client_id = c.id
        LEFT JOIN item_library il ON il.id = te.service_item_id
        LEFT JOIN work_billing_consumptions wbc ON wbc.billing_time_entry_id=te.id
        WHERE ' . ($canViewAll ? '' : 'te.user_id = ? AND ') . 'te.billable = ? AND te.billed = ?
          AND COALESCE(te.external_status, "approved") = "approved"';
if ($clientId > 0) {
    $sql .= ' AND (te.client_id = ? OR te.client_id IS NULL OR te.client_id = 0)';
    $params[] = $clientId;
}
if ($projectCode !== '') {
    $sql .= ' AND te.project_code = ?';
    $params[] = $projectCode;
}
if ($contractId > 0) {
    $sql .= ' AND te.contract_id = ?';
    $params[] = $contractId;
}
if ($invoiceId > 0) {
    $sql .= ' AND te.invoice_id = ?';
    $params[] = $invoiceId;
}
$sql .= ' ORDER BY te.started_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $start = $row['started_at'] ? date('M j, g:i A', strtotime($row['started_at'])) : '';
    $end = $row['ended_at'] ? date('g:i A', strtotime($row['ended_at'])) : '';
    $bits = [];
    if ($start !== '') {
        $bits[] = $end !== '' ? ($start . '-' . $end) : $start;
    }
    if (!empty($row['project_code'])) {
        $bits[] = 'Job ' . $row['project_code'];
    }
    if (!empty($row['description'])) {
        $bits[] = $row['description'];
    }
    $row['detail'] = implode(' | ', $bits);
}
unset($row);
echo json_encode($rows);
exit;
