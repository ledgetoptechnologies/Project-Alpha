<?php

declare(strict_types=1);

use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;

require_once __DIR__ . '/../../utils/workforce_availability.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/api_response.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) api_json_failure(401, 'authentication_required', 'Authentication is required.');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $_POST;
if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) $input = $decoded;
}
if ($method !== 'GET') {
    $headerToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    if (!csrf_validate() && !($headerToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $headerToken))) {
        api_json_failure(403, 'csrf_failed', 'The request could not be verified.');
    }
}

$action = trim((string)($_GET['action'] ?? $input['action'] ?? 'list'));
if (!pa_workforce_enabled() && !in_array($action, ['list', 'detail'], true)) {
    api_json_failure(404, 'workforce_unavailable', 'Workforce planning is temporarily unavailable.');
}
$canManage = in_array((string)($_SESSION['user']['role'] ?? ''), ['admin','owner'], true)
    || user_can($pdo, $userId, 'workforce.catalog.manage', 0);
$compensation = new CompensationRuleService($pdo);
$planning = new JobWorkPlanningService($pdo, $compensation);

try {
    if ($method === 'GET' && $action === 'list') {
        $stmt = $pdo->prepare(
            'SELECT i.*,COUNT(c.id) work_component_count FROM item_library i
             LEFT JOIN catalog_work_components c ON c.item_library_id=i.id AND c.is_active=1
             WHERE (?=1 OR i.is_active=1) AND (?="" OR i.item_name LIKE ? OR i.description LIKE ?)
             GROUP BY i.id ORDER BY i.is_active DESC,i.item_name LIMIT 250'
        );
        $q = trim((string)($_GET['q'] ?? ''));
        $stmt->execute([$canManage ? 1 : 0, $q, "%{$q}%", "%{$q}%"]);
        api_json_success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'GET' && $action === 'detail') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM item_library WHERE id=?'); $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) api_json_failure(404, 'catalog_item_not_found', 'Catalog item not found.');
        $stmt = $pdo->prepare('SELECT * FROM catalog_work_components WHERE item_library_id=? ORDER BY display_order,id');
        $stmt->execute([$id]); $item['work_components'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT child_item_library_id item_library_id,quantity,display_order FROM catalog_bundle_items WHERE bundle_item_library_id=? ORDER BY display_order');
        $stmt->execute([$id]); $item['bundle_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_json_success(['data' => $item]);
    }
    if($method==='GET'&&$action==='compensation_rules'){
        if(!$canManage)api_json_failure(403,'permission_denied','Catalog management permission is required.');
        $stmt=$pdo->prepare('SELECT * FROM worker_compensation_rules WHERE (?=0 OR worker_profile_id=?) ORDER BY effective_from DESC,id DESC');$workerId=(int)($_GET['worker_profile_id']??0);$stmt->execute([$workerId,$workerId]);api_json_success(['data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method !== 'POST') api_json_failure(405, 'method_not_allowed', 'Use POST for this operation.');
    if (!$canManage) api_json_failure(403, 'permission_denied', 'Catalog management permission is required.');

    if ($action === 'preview') {
        $preview = $compensation->calculate(
            is_array($input['rule'] ?? null) ? $input['rule'] : [],
            is_array($input['context'] ?? null) ? $input['context'] : []
        );
        api_json_success(['data' => $preview]);
    }
    if ($action === 'materialize') {
        if (!empty($input['document_type']) && !empty($input['document_id'])) {
            $ids = $planning->materializeDocument((string)$input['document_type'], (int)$input['document_id'], $userId);
        } else {
            $ids = $planning->materializeCatalog((int)($input['job_id'] ?? 0), (int)($input['item_library_id'] ?? 0), (float)($input['quantity'] ?? 1), $userId);
        }
        api_json_success(['data' => ['job_work_component_ids' => $ids]], 201);
    }
    if($action==='save_compensation_rule'){
        $workerId=(int)($input['worker_profile_id']??0);$workTypeId=(int)($input['work_type_id']??0)?:null;$componentId=(int)($input['catalog_work_component_id']??0)?:null;$method=(string)($input['compensation_method']??'nonpayable');$basis=(string)($input['percentage_basis']??'net_line');$trigger=(string)($input['eligibility_trigger']??'completed_approved');
        if($workerId<=0||(($workTypeId===null)===($componentId===null)))throw new DomainException('Choose one worker and either a Work Type or catalog component.');
        if(!in_array($method,['nonpayable','hourly','fixed','base_overage','percentage'],true)||!in_array($basis,['gross_line','net_line','cash_collected'],true)||!in_array($trigger,['completed_approved','delivered','invoice_paid','manual_release'],true))throw new DomainException('Invalid compensation rule.');
        if($basis==='cash_collected'&&$method==='percentage'&&$trigger!=='invoice_paid')throw new DomainException('Cash-collected percentages require the invoice-paid trigger.');
        $pdo->prepare('INSERT INTO worker_compensation_rules (worker_profile_id,work_type_id,catalog_work_component_id,compensation_method,compensation_amount,included_minutes,overage_rate,percentage,percentage_basis,eligibility_trigger,currency,effective_from,effective_until,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$workerId,$workTypeId,$componentId,$method,($input['compensation_amount']??'')===''?null:max(0,(float)$input['compensation_amount']),($input['included_minutes']??'')===''?null:max(0,(int)$input['included_minutes']),($input['overage_rate']??'')===''?null:max(0,(float)$input['overage_rate']),($input['percentage']??'')===''?null:min(100,max(0,(float)$input['percentage'])),$basis,$trigger,strtoupper((string)($input['currency']??'USD')),(string)($input['effective_from']??gmdate('Y-m-d')),($input['effective_until']??'')?:null,$userId]);
        api_json_success(['data'=>['id'=>(int)$pdo->lastInsertId()]],201);
    }
    api_json_failure(404, 'operation_not_found', 'The catalog operation was not found.');
} catch (DomainException $error) {
    api_json_failure(422, 'invalid_catalog_operation', $error->getMessage());
} catch (PDOException $error) {
    error_log('[CatalogV1][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(503, 'schema_out_of_date', 'Catalog compensation is unavailable until the latest migration is applied.');
} catch (Throwable $error) {
    error_log('[CatalogV1][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(500, 'internal_error', 'The catalog request could not be completed.');
}
