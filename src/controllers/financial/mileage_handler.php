<?php

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/mileage.php';

use App\Services\WorkforceAccessService;

function mileage_handler_is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function mileage_handler_finish(bool $success, string $message, string $redirect, int $status = 200, array $extra = []): never
{
    $payload = ['success' => $success, 'message' => $message, 'redirect' => $redirect] + $extra;
    if (mileage_handler_is_ajax()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
    if ($success) {
        header('Location: ' . $redirect);
    } else {
        $join = str_contains($redirect, '?') ? '&' : '?';
        header('Location: ' . $redirect . $join . 'error=' . rawurlencode($message));
    }
    exit;
}

$submitted = (string)($_POST['_token'] ?? '');
$csrfOk = $submitted !== '' ? csrf_sf_is_valid('mileage', $submitted) : csrf_validate();
if (!$csrfOk) {
    mileage_handler_finish(false, 'Invalid request (CSRF validation failed)', '/?page=financial/mileage-create', 400);
}
$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) {
    mileage_handler_finish(false, 'Authentication required', '/?page=login', 401);
}
$canManageMileage = user_can($pdo, $userId, 'financial.manage', 0);
$workforceActor = (new WorkforceAccessService($pdo))->actor($userId);
$canLogOwnMileage = $canManageMileage || isset(($workforceActor['capabilities'] ?? [])['*'])
    || isset(($workforceActor['capabilities'] ?? [])['mileage.self']);
if (!$canLogOwnMileage) {
    mileage_handler_finish(false, 'Permission denied', '/?page=financial/mileage-list', 403);
}

$action = (string)($_POST['action'] ?? '');
$orgId = request_client_org_id();
$id = max(0, (int)($_POST['id'] ?? 0));
$fallback = $id > 0 ? '/?page=financial/mileage-create&id=' . $id : '/?page=financial/mileage-create';

try {
    if ($action === 'delete') {
        if ($id <= 0) throw new InvalidArgumentException('Invalid mileage entry ID.');
        [$scope, $params] = finance_scope_clause($pdo, 'm', $userId, $orgId, 'traveler_user_id');
        $check = $pdo->prepare('SELECT m.id,(SELECT COUNT(*) FROM mileage_charge_allocations a WHERE a.mileage_log_id=m.id) allocation_count,(SELECT COUNT(*) FROM mileage_charge_allocations a WHERE a.mileage_log_id=m.id AND a.billed=1) billed_allocations FROM mileage_logs m WHERE m.id=? AND ' . $scope);
        $check->execute(array_merge([$id], $params));
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['billed_allocations'] > 0 || (!$canManageMileage && (int)$row['allocation_count'] > 0)) throw new RuntimeException('Mileage entry not found or it has protected client charges.');
        $pdo->prepare('DELETE FROM mileage_logs WHERE id=?')->execute([$id]);
        audit_log($pdo, 'mileage.delete', 'mileage_log', $id, ['organization_id' => $orgId]);
        mileage_handler_finish(true, 'Mileage entry deleted.', mileage_list_url($pdo, $userId) . '&deleted=1');
    }

    if (!in_array($action, ['create', 'update'], true)) throw new InvalidArgumentException('Invalid action.');
    if ($action === 'update' && $id <= 0) throw new InvalidArgumentException('Invalid mileage entry ID.');

    $tripDate = trim((string)($_POST['trip_date'] ?? ''));
    $entryMode = (string)($_POST['entry_mode'] ?? 'simple');
    $entryMode = in_array($entryMode, ['simple', 'total_trip'], true) ? $entryMode : 'simple';
    $enteredMiles = (float)($_POST['miles'] ?? 0);
    $includeReturn = $entryMode === 'simple' && !empty($_POST['round_trip']);
    $loggedMiles = mileage_logged_miles($entryMode, $enteredMiles, $includeReturn);
    $purpose = (string)($_POST['purpose'] ?? 'business');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) throw new InvalidArgumentException('Enter a valid trip date.');
    if ($enteredMiles <= 0 || $loggedMiles <= 0) throw new InvalidArgumentException('Miles must be greater than zero.');
    if (!in_array($purpose, ['business','medical','moving','charitable','personal'], true)) throw new InvalidArgumentException('Invalid trip purpose.');

    $allocations = mileage_parse_allocations($_POST, $entryMode, $loggedMiles);
    if (!$canManageMileage) $allocations = [];
    $trackingSessionId=max(0,(int)($_POST['tracking_session_id']??0));
    $source='manual';
    $start = trim((string)($_POST['start_location'] ?? '')) ?: null;
    $end = trim((string)($_POST['end_location'] ?? '')) ?: null;
    $description = trim((string)($_POST['description'] ?? '')) ?: null;
    $travelerUserId = $userId;
    if ($canManageMileage && acl_user_has_org_wide_scope($pdo, $userId, 0)) {
        $requestedTravelerId = max(0, (int)($_POST['traveler_user_id'] ?? $userId));
        $travelerCheck = $pdo->prepare('SELECT id FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1');
        $travelerCheck->execute([$requestedTravelerId]);
        if (!$travelerCheck->fetchColumn()) {
            throw new InvalidArgumentException('Choose a valid traveler.');
        }
        $travelerUserId = $requestedTravelerId;
    }
    $workerStatement = $pdo->prepare('SELECT id,relationship_type FROM worker_profiles WHERE user_id=? AND status="active" LIMIT 1');
    $workerStatement->execute([$travelerUserId]);
    $travelerWorker = $workerStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    $travelerWorkerId = $travelerWorker ? (int)$travelerWorker['id'] : null;
    $defaultTreatment = match ((string)($travelerWorker['relationship_type'] ?? '')) {
        'owner' => 'organization_mileage',
        'employee' => 'worker_reimbursement',
        'contractor' => 'contractor_record_only',
        default => 'nonreimbursable',
    };
    $financialTreatment = (string)($_POST['financial_treatment'] ?? $defaultTreatment);
    if (!$canManageMileage) $financialTreatment = $defaultTreatment;
    if (!in_array($financialTreatment, ['organization_mileage','worker_reimbursement','contractor_record_only','nonreimbursable'], true)) {
        throw new InvalidArgumentException('Choose a valid mileage financial treatment.');
    }

    $pdo->beginTransaction();
    if($trackingSessionId>0){
        if($action!=='create')throw new RuntimeException('Tracked sessions can only create new mileage entries.');
        $tracking=$pdo->prepare('SELECT id FROM mileage_tracking_sessions WHERE id=? AND user_id=? AND status="draft_review" FOR UPDATE');
        $tracking->execute([$trackingSessionId,$userId]);if(!$tracking->fetchColumn())throw new RuntimeException('Tracked trip is unavailable or already finalized.');
        $source='gps';$entryMode='total_trip';$includeReturn=false;$loggedMiles=round($enteredMiles,3);$travelerUserId=$userId;
    }
    if ($action === 'update') {
        [$scope, $params] = finance_scope_clause($pdo, 'm', $userId, $orgId, 'traveler_user_id');
        $check = $pdo->prepare('SELECT m.id FROM mileage_logs m WHERE m.id=? AND ' . $scope . ' FOR UPDATE');
        $check->execute(array_merge([$id], $params));
        if (!$check->fetchColumn()) throw new RuntimeException('Mileage entry not found.');
        $pdo->prepare(
            'UPDATE mileage_logs SET user_id=?,traveler_user_id=?,traveler_worker_id=?,financial_treatment=?,entry_mode=?,trip_date=?,start_location=?,end_location=?,miles=?,logged_miles=?,purpose=?,description=?,round_trip=?,review_status="finalized" WHERE id=?'
        )->execute([$travelerUserId,$travelerUserId,$travelerWorkerId,$financialTreatment,$entryMode,$tripDate,$start,$end,$enteredMiles,$loggedMiles,$purpose,$description,$includeReturn ? 1 : 0,$id]);
    } else {
        $pdo->prepare(
            'INSERT INTO mileage_logs (organization_id,user_id,recorded_by_user_id,traveler_user_id,traveler_worker_id,financial_treatment,source,entry_mode,trip_date,start_location,end_location,miles,logged_miles,tracking_session_id,purpose,description,round_trip,review_status,is_billable)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"finalized",0)'
        )->execute([$orgId ?: null,$travelerUserId,$userId,$travelerUserId,$travelerWorkerId,$financialTreatment,$source,$entryMode,$tripDate,$start,$end,$enteredMiles,$loggedMiles,$trackingSessionId?:null,$purpose,$description,$includeReturn ? 1 : 0]);
        $id = (int)$pdo->lastInsertId();
    }
    if ($canManageMileage) mileage_replace_allocations($pdo, $id, $orgId ?: null, $userId, $allocations);
    if($trackingSessionId>0)$pdo->prepare('UPDATE mileage_tracking_sessions SET status="finalized",finalized_at=NOW(3),calculated_miles=? WHERE id=?')->execute([$loggedMiles,$trackingSessionId]);
    audit_log($pdo, $action === 'create' ? 'mileage.create' : 'mileage.update', 'mileage_log', $id, [
        'organization_id' => $orgId, 'recorded_by_user_id' => $userId, 'traveler_user_id' => $travelerUserId,
        'traveler_worker_id' => $travelerWorkerId, 'financial_treatment' => $financialTreatment,
        'source' => $source, 'entry_mode' => $entryMode,
        'entered_miles' => $enteredMiles, 'logged_miles' => $loggedMiles,
        'client_charge_count' => count($allocations),
        'client_charge_total' => array_sum(array_column($allocations, 'client_charge')),
    ]);
    $pdo->commit();
    mileage_handler_finish(true, $action === 'create' ? 'Mileage entry logged.' : 'Mileage entry updated.', mileage_list_url($pdo, $userId) . '&' . ($action === 'create' ? 'created' : 'updated') . '=1', 200, ['id' => $id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[mileage_handler] ' . $e->getMessage());
    mileage_handler_finish(false, $e->getMessage(), $fallback, 400);
}
