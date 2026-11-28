<?php
// src/controllers/financial/mileage_export.php
// Mileage log CSV (Reports > Mileage log). Same driver scope as the mileage list.

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csv.php';
require_once __DIR__ . '/../../utils/mileage.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$viewer = mileage_viewer($pdo, $userId);
if (!$viewer['can_log']) {
    http_response_code(403);
    exit('Mileage access is not enabled for this account.');
}

$filters = [];
foreach (['driver', 'purpose'] as $key) {
    $filters[$key] = (string)($_GET[$key] ?? '');
}
foreach (['start', 'end'] as $key) {
    $value = (string)($_GET[$key] ?? '');
    $filters[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}
$result = mileage_log_rows($pdo, $userId, request_client_org_id(), $filters, $viewer['all_drivers']);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="mileage-log-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
csv_write_row($out, ['Date', 'Driver', 'From', 'To', 'Purpose', 'Description', 'Round trip', 'Miles', 'Source', 'Clients', 'Client charges']);
$totalMiles = 0.0;
foreach ($result['rows'] as $log) {
    $miles = (float)$log['canonical_logged_miles'];
    $totalMiles += $miles;
    csv_write_row($out, [
        $log['trip_date'],
        $log['driver_name'],
        $log['start_location'] ?? '',
        $log['end_location'] ?? '',
        ucfirst((string)$log['purpose']),
        $log['description'] ?? '',
        !empty($log['round_trip']) ? 'Yes' : 'No',
        number_format($miles, 3, '.', ''),
        ($log['source'] ?? 'manual') === 'gps' ? 'GPS' : 'Manual',
        $log['client_names'] ?? '',
        number_format((float)$log['client_charge_total'], 2, '.', ''),
    ]);
}
csv_write_row($out, ['Total', '', '', '', '', '', '', number_format($totalMiles, 3, '.', ''), '', '', '']);
fclose($out);
