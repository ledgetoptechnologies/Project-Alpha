<?php
// src/controllers/contract/on_demand_contracts_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/project_selection.php';
require_once __DIR__ . '/../../utils/contract_signatures.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/mileage.php';
require_once __DIR__ . '/../../utils/document_locations.php';
require_once __DIR__ . '/../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../services/JobAssignmentService.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';
require_once __DIR__ . '/../../services/ProjectContractEligibilityGuardService.php';

@error_log('[on_demand_contracts_create] POST received', 0);

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$requestedServiceLocationId = !empty($_POST['service_location_id']) ? (int)$_POST['service_location_id'] : null;
$return_to_project = (int)($_POST['return_to_project'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);

// On-demand contract specific fields - accepts both prefixed (od_) and non-prefixed field names
$start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : (!empty($_POST['od_start_date']) ? $_POST['od_start_date'] : date('Y-m-d'));
$end_date = null; // On-demand contracts are always ongoing until terminated
$billing_interval_count = 1;
$billing_interval_unit = 'month';

// Price can come from a flat amount or line items.
$od_pricing_mode = in_array(($_POST['od_pricing_mode'] ?? 'items'), ['items', 'flat'], true) ? $_POST['od_pricing_mode'] : 'items';
$price_per_invoice = max(0.0, (float)($_POST['od_flat_amount'] ?? $_POST['price_per_invoice'] ?? 0));
$scope = trim((string)($_POST['scope'] ?? ''));

if ($client_id <= 0) {
    $client_name = trim((string)($_POST['client'] ?? ''));
    if ($client_name !== '') {
        try {
            $st = $pdo->prepare('SELECT id FROM clients WHERE name = ? LIMIT 1');
            $st->execute([$client_name]);
            $cid = (int)$st->fetchColumn();
            if ($cid <= 0) {
                $st = $pdo->prepare('SELECT id FROM clients WHERE name LIKE ? ORDER BY name LIMIT 1');
                $st->execute(['%'.$client_name.'%']);
                $cid = (int)$st->fetchColumn();
            }
            if ($cid > 0) { $client_id = $cid; }
        } catch (Throwable $e) { @error_log('[on_demand_contracts_create] resolve by name failed: '.$e->getMessage(), 0); }
    }
}

if ($client_id <= 0) {
    @error_log('[on_demand_contracts_create] invalid client_id', 0);
    header('Location: /?page=contract/contracts-create&error=Please%20select%20a%20client');
    exit;
}
if ($project_id && !pa_project_is_active_for_client($pdo, $project_id, $client_id, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=contract/contracts-create&error=' . urlencode('Select an active or not-started project for this client or organization.'));
    exit;
}

// On-demand contracts can have line items OR a flat price - check if we have either.
$hasLineItems = $od_pricing_mode === 'items'
    && !empty($_POST['item'])
    && is_array($_POST['item'])
    && count(array_filter($_POST['item'], static fn($value) => trim((string)$value) !== '')) > 0;
if ($od_pricing_mode === 'flat' && $price_per_invoice <= 0) {
    header('Location: /?page=contract/contracts-create&error=Enter%20a%20flat%20contract%20amount');
    exit;
}
if (!$hasLineItems && $price_per_invoice <= 0) {
    header('Location: /?page=contract/contracts-create&error=Please%20add%20items%20or%20enter%20a%20flat%20amount');
    exit;
}

// Calculate subtotal from line items or flat price
$items = [];
$subtotal = 0.0;

if ($hasLineItems) {
    $item = $_POST['item'] ?? [];
    $desc = $_POST['item_desc'] ?? [];
    $qty = $_POST['item_qty'] ?? [];
    $price = $_POST['item_price'] ?? [];
    $billingUnits = $_POST['item_billing_unit'] ?? [];
    $catalogIds = $_POST['item_library_id'] ?? [];
    
    for ($i = 0; $i < count($item); $i++) {
        $itm = trim((string)($item[$i] ?? ''));
        $d = trim((string)($desc[$i] ?? ''));
        $q = (float)($qty[$i] ?? 0);
        $p = (float)($price[$i] ?? 0);
        if ($itm === '' || $q <= 0) continue;
        $line = $q * $p;
        $subtotal += $line;
        $requestedUnit = (string)($billingUnits[$i] ?? 'each');
        $unit = $billing_mode === 'hourly' ? 'hour' : catalog_document_unit($requestedUnit);
        $items[] = ['i' => $itm, 'd' => $d, 'q' => $q, 'p' => $p, 't' => $line, 'u' => $unit,'catalog_id'=>max(0,(int)($catalogIds[$i]??0))];
    }
}

// Fallback: if no valid line items were entered, use the flat amount if provided.
if (empty($items)) {
    $subtotal = $price_per_invoice;
} else {
    $price_per_invoice = $subtotal;
}

$travelRule=mileage_rule_from_post($_POST,['rate'=>(float)($appConfig['default_mileage_rate']??0.670),'included'=>(float)($appConfig['default_mileage_included_miles']??0)]);$travelItem=mileage_document_travel_item($travelRule);if($travelItem&&$travelItem['pricing_status']!=='variable')$subtotal+=(float)$travelItem['line_total'];
$discount_amount = 0.0; 
if($discount_type === 'percent'){ 
    $discount_amount = max(0, min(100, $discount_value)) * $subtotal / 100; 
} elseif($discount_type === 'fixed'){ 
    $discount_amount = max(0, $discount_value); 
}

$tax = max(0, $tax_percent) * max(0, $subtotal - $discount_amount) / 100;
$total = max(0, $subtotal - $discount_amount + $tax);

// Calculate deposit amount
$deposit_amount = 0.0;
if($deposit_type === 'percent') { 
    $deposit_amount = max(0, min(100, $deposit_value)) * $total / 100; 
}
elseif($deposit_type === 'fixed') { 
    $deposit_amount = max(0, $deposit_value); 
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$sessionUserId = (int)($_SESSION['user']['id'] ?? 0) ?: 1;
$contractOrgId = resolve_client_context_org_id($pdo, $client_id, $project_id, request_client_org_id());
$showContactOnDocument = $contractOrgId && !empty($_POST['show_contact_on_document']) ? 1 : 0;

$pdo->beginTransaction();
try{
    (new App\Services\ProjectContractEligibilityGuardService($pdo))->assertCanCreateOrAttach($project_id);
    // Get project code
    $projectCode = 'PA-'.date('Y').'-001';
    try { 
        $projectCode = project_next_code($pdo, $client_id); 
    } catch (Throwable $e) { 
        @error_log('[on_demand_contracts_create] project_next_code failed: '.$e->getMessage(), 0); 
    }

    // Insert on-demand contract into the unified contracts table
    $sql = 'INSERT INTO contracts (
        client_id, project_id, project_code, status, contract_type, billing_mode, start_date, end_date,
        billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice,
        discount_type, discount_value, tax_percent, subtotal, total,
        deposit_type, deposit_amount, deposit_paid,
        total_invoiced, invoice_count, scope, organization_id, show_contact_on_document, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    
    $pdo->prepare($sql)->execute([
        $client_id, $project_id, $projectCode, 'draft', 'on_demand', $billing_mode, $start_date, $end_date,
        $billing_interval_count, $billing_interval_unit, 'on_demand', $price_per_invoice,
        $discount_type, $discount_value, $tax_percent, $subtotal, $total,
        $deposit_type, $deposit_amount, 0,
        0, 0, $scope, $contractOrgId, $showContactOnDocument, $sessionUserId
    ]);
    
    $contract_id = (int)$pdo->lastInsertId();
    $jobId = JobAssignmentService::ensureForCode($pdo, $client_id, $projectCode, $project_id ?: null, $sessionUserId);
    $serviceLocationId = document_resolve_service_location($pdo,$client_id,$project_id,$jobId,$requestedServiceLocationId);
    $pdo->prepare('UPDATE contracts SET job_id=?,service_location_id=? WHERE id=?')->execute([$jobId, $serviceLocationId, $contract_id]);

    // Assign doc number
    $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = "on_demand"')->fetchColumn();
    $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $contract_id]);

    // Save line items if we have them
    if (!empty($items)) {
        $ins = $pdo->prepare('INSERT INTO contract_items (contract_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($items as $it) {
            $catalog=catalog_document_snapshot($pdo,(int)($it['catalog_id']??0),$it);
            $ins->execute([$contract_id,$catalog['item_library_id'],$it['i'],$it['d'],$it['q'],$it['p'],$it['t'],$it['u'],$catalog['catalog_snapshot']]);
        }
    }
    if($travelItem)$pdo->prepare('INSERT INTO contract_items (contract_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status) VALUES (?,?,?,?,?,?,?,1,?)')->execute([$contract_id,$travelItem['item'],$travelItem['description'],$travelItem['quantity'],$travelItem['unit_price'],$travelItem['line_total'],$travelItem['billing_unit'],$travelItem['pricing_status']]);
    mileage_save_document_rule($pdo,'contract',$contract_id,$contractOrgId?:null,$client_id,$sessionUserId,$travelRule);

    // Save project notes
    $notes = trim((string)($_POST['project_notes'] ?? ''));
    if ($notes !== '') {
        try {
            $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
            $up->execute([$projectCode, $client_id, $notes]);
        } catch (Throwable $e) {
            @error_log('[on_demand_contracts_create] project_meta upsert failed: '.$e->getMessage(), 0);
        }
    }

    // Save contract signatures
    $signatureTitles = $_POST['signature_titles'] ?? [];
    $signatureOrders = $_POST['signature_orders'] ?? [];
    $signatureRequired = $_POST['signature_required'] ?? [];
    
    if (!empty($signatureTitles)) {
        pa_save_contract_signatures($pdo, $contract_id, $signatureTitles, $signatureOrders, $signatureRequired);
    }

    // Add to project_documents if project_id is set
    if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "contract", ?)')->execute([$project_id, $contract_id]);
    }

    pricing_apply_posted_override($pdo,(int)$contractOrgId,'contract',$contract_id,$sessionUserId,$_POST);
    document_issuer_apply_post($pdo, $appConfig, 'contract', $contract_id, $_POST);
    pricing_finalize_document_revision(
        $pdo,$contractOrgId!==null?(int)$contractOrgId:null,'contract',$contract_id,$sessionUserId,false,
        (string)($appConfig['workforce_currency']??'USD'),
        $deposit_type==='percent'&&$contractOrgId!==null
          ? static fn(array $pricing)=>pricing_recompute_contract_percentage_deposit($pdo,(int)$contractOrgId,$contract_id,(string)$deposit_value)
          : null
    );
    $pdo->commit();
} catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[on_demand_contracts_create] exception: '.$e->getMessage(), 0);
    $msg = substr($e->getMessage(), 0, 200);
    header('Location: /?page=contract/contracts-create&error=' . urlencode($msg));
    exit;
}

if ($return_to_project > 0) {
    header('Location: /?page=project/projects-details&id=' . $return_to_project . '&created=contract');
} else {
    header('Location: /?page=contract/on-demand-contracts-list&created=1');
}
exit;
