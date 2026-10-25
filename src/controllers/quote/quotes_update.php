<?php
// src/controllers/quotes_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/document_recurring_rate.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';
require_once __DIR__ . '/../../utils/mileage.php';
require_once __DIR__ . '/../../utils/document_locations.php';
require_once __DIR__ . '/../../utils/catalog_documents.php';
require_once __DIR__ . '/../../utils/project_selection.php';
require_once __DIR__ . '/../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/DocumentPolicy.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
$id = (int)($_POST['id'] ?? 0);
$jobClientLockMessage='A document cannot be moved to another client while it belongs to a Job. Clone it into a new Job instead.';
require_record_ownership($pdo, 'quotes', $id);
try { $existingQuote=DocumentPolicy::assertMutable($pdo,'quote',$id); } catch(DocumentLockedException $locked){http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'document_locked','message'=>$locked->getMessage(),'request_id'=>bin2hex(random_bytes(8))]);exit;}
$client_id = (int)($_POST['client_id'] ?? 0);
$requestedServiceLocationId = !empty($_POST['service_location_id']) ? (int)$_POST['service_location_id'] : null;
if (!empty($existingQuote['job_id']) && $client_id !== (int)$existingQuote['client_id']) {
  http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'job_client_conflict','message'=>$jobClientLockMessage,'request_id'=>bin2hex(random_bytes(8))]);exit;
}
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);
$fulfillment_date = !empty($_POST['fulfillment_date']) ? $_POST['fulfillment_date'] : null;
$scope = trim((string)($_POST['scope'] ?? '')) ?: null;
$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
$billingUnits = $_POST['item_billing_unit'] ?? [];
$catalogIds = $_POST['item_library_id'] ?? [];
$travelRule=mileage_rule_from_post($_POST,['rate'=>(float)($appConfig['default_mileage_rate']??0.670),'included'=>(float)($appConfig['default_mileage_included_miles']??0)]);
if ($id<=0 || $client_id<=0) { header('Location: /?page=quote/quotes-list&error=Invalid'); exit; }
$items=[];$subtotal=0.0;
for($i=0;$i<count($item);$i++){
  $itm=trim((string)($item[$i]??'')); $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($itm===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $unit=$billing_mode==='hourly'?'hour':catalog_document_unit((string)($billingUnits[$i]??'each')); $items[]=['i'=>$itm,'d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line,'u'=>$unit,'catalog_id'=>max(0,(int)($catalogIds[$i]??0))];
}
$recurringRate = null;
$recurringQuote = ($existingQuote['quote_type'] ?? '') === 'long_term' && ($existingQuote['pricing_type'] ?? '') === 'per_invoice';
if ($recurringQuote) {
  $intervalCount = (int)($_POST['billing_interval_count'] ?? $existingQuote['billing_interval_count'] ?? 1);
  $intervalUnit = (string)($_POST['billing_interval_unit'] ?? $existingQuote['billing_interval_unit'] ?? 'month');
  try {
    if ($intervalCount < 1 || !in_array($intervalUnit, ['day','week','month','year'], true)) throw new DomainException('Choose a valid billing frequency.');
    $recurringRate = pa_document_recurring_rate($_POST, $existingQuote, $intervalCount, $intervalUnit, 'per_invoice', (string)($_POST['price_per_invoice'] ?? $existingQuote['price_per_invoice'] ?? '0'));
    if ((float)$recurringRate['amount'] <= 0) throw new DomainException('Amount per invoice must be greater than zero.');
    $subtotal = (float)$recurringRate['amount'];
    $items = [];
  } catch (DomainException|InvalidArgumentException $e) {
    header('Location: /?page=quote/quotes-edit&id=' . $id . '&error=' . rawurlencode($e->getMessage())); exit;
  }
}
$travelItem=mileage_document_travel_item($travelRule);if($travelItem&&$travelItem['pricing_status']!=='variable')$subtotal+=(float)$travelItem['line_total'];
$discount_amount=0.0; if($discount_type==='percent'){$discount_amount=max(0,min(100,$discount_value))*$subtotal/100;} elseif($discount_type==='fixed'){$discount_amount=max(0,$discount_value);} $tax=max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);
// Extract custom field values from POST
$customFieldValues = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFieldValues) ? json_encode($customFieldValues) : null;
$organizationId = resolve_client_context_org_id($pdo, $client_id, !empty($existingQuote['project_id']) ? (int)$existingQuote['project_id'] : null, request_client_org_id() ?: null);
$showContactOnDocument = $organizationId && !empty($_POST['show_contact_on_document']) ? 1 : 0;

$pdo->beginTransaction();
try{
  $existingQuote=DocumentPolicy::assertMutable($pdo,'quote',$id,'commercial',true);
  require_record_ownership($pdo,'quotes',$id);
  if(!empty($existingQuote['job_id'])&&$client_id!==(int)$existingQuote['client_id'])throw new DomainException($jobClientLockMessage);
  $serviceLocationId = document_resolve_service_location($pdo,$client_id,!empty($existingQuote['project_id'])?(int)$existingQuote['project_id']:null,!empty($existingQuote['job_id'])?(int)$existingQuote['job_id']:null,$requestedServiceLocationId);
  $pdo->prepare('UPDATE quotes SET client_id=?, organization_id=?, show_contact_on_document=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, deposit_type=?, deposit_amount=?, fulfillment_date=?, scope=?, custom_fields=?, service_location_id=? WHERE id=?')->execute([$client_id,$organizationId,$showContactOnDocument,$billing_mode,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$deposit_type,$deposit_value,$fulfillment_date,$scope,$customFieldsJson,$serviceLocationId,$id]);
  if ($recurringQuote && $recurringRate) {
    $pdo->prepare('UPDATE quotes SET billing_interval_count=?,billing_interval_unit=?,price_per_invoice=? WHERE id=?')->execute([$intervalCount,$intervalUnit,$recurringRate['amount'],$id]);
    pa_document_recurring_rate_save($pdo, 'quote', $id, $recurringRate);
  }
  // Upsert project notes if provided and project_code is known
  $row = $pdo->prepare('SELECT project_code FROM quotes WHERE id=?');
  $row->execute([$id]);
  $pc = (string)$row->fetchColumn();
  $pn = trim((string)($_POST['project_notes'] ?? ''));
  $pt = trim((string)($_POST['project_terms'] ?? ''));
  if ($pc !== '') {
    $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes, terms) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes), terms=VALUES(terms)');
    $up->execute([$pc, $client_id, $pn !== '' ? $pn : null, $pt !== '' ? $pt : null]);
  }
  $pdo->prepare('DELETE FROM quote_items WHERE quote_id=?')->execute([$id]);
  $ins=$pdo->prepare('INSERT INTO quote_items (quote_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,pricing_status,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?)');
  foreach($items as $it){$catalog=catalog_document_snapshot($pdo,(int)($it['catalog_id']??0),$it);$ins->execute([$id,$catalog['item_library_id'],$it['i'],$it['d'],$it['q'],$it['p'],$it['t'],$it['u'],$billing_mode==='hourly'?'estimate':'standard',$catalog['catalog_snapshot']]);}
  if($travelItem)$pdo->prepare('INSERT INTO quote_items (quote_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status) VALUES (?,?,?,?,?,?,?,1,?)')->execute([$id,$travelItem['item'],$travelItem['description'],$travelItem['quantity'],$travelItem['unit_price'],$travelItem['line_total'],$travelItem['billing_unit'],$travelItem['pricing_status']]);
  $quoteOrg=$pdo->prepare('SELECT organization_id FROM quotes WHERE id=?');$quoteOrg->execute([$id]);mileage_save_document_rule($pdo,'quote',$id,($quoteOrg->fetchColumn()?:null),$client_id,(int)($_SESSION['user']['id']??0),$travelRule);
  pricing_apply_posted_override($pdo,(int)$organizationId,'quote',$id,(int)($_SESSION['user']['id']??0),$_POST);
    document_issuer_apply_post($pdo, $appConfig, 'quote', $id, $_POST);
  pricing_finalize_document_revision($pdo,$organizationId!==null?(int)$organizationId:null,'quote',$id,(int)($_SESSION['user']['id']??0),true,(string)($appConfig['workforce_currency']??'USD'));
  if(!empty($existingQuote['job_id']))ScheduleService::syncJob($pdo,(int)$existingQuote['job_id'],(string)($appConfig['timezone']??'UTC'),(int)($_SESSION['user']['id']??0));
  $pdo->commit();
}catch(Throwable $e){$pdo->rollBack();$error=$e instanceof DocumentLockedException?$e->getMessage():($e instanceof DomainException&&hash_equals($jobClientLockMessage,$e->getMessage())?$jobClientLockMessage:'Update failed');header('Location: /?page=quote/quote-details&id='.$id.'&error='.rawurlencode($error));exit;}
header('Location: /?page=quote/quote-details&id=' . $id . '&updated=1');
exit;
