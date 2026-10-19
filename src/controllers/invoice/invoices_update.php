<?php
// src/controllers/invoices_update.php
require_once __DIR__ . '/../../utils/workforce_availability.php';
pa_workforce_require_no_invoice_imports($_POST);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/invoice_workforce_history.php';
require_once __DIR__ . '/../../utils/general_recipient_invoices.php';
require_once __DIR__ . '/../../utils/document_locations.php';
require_once __DIR__ . '/../../utils/catalog_documents.php';
require_once __DIR__ . '/../../utils/project_selection.php';
require_once __DIR__ . '/../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../services/DocumentPolicy.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';
require_once __DIR__ . '/../../services/WorkTimeBillingContextService.php';

use App\Services\WorkTimeBillingContextService;
$id = (int)($_POST['id'] ?? 0);
$fixedTotalLockMessage='A fixed-total contract installment is an immutable allocation. Amend the contract instead.';
require_record_ownership($pdo, 'invoices', $id);
$statusStmt = $pdo->prepare('SELECT * FROM invoices WHERE id=?');
$statusStmt->execute([$id]);
$invoiceState = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$fixedTotalInstallment = pricing_invoice_is_fixed_total_installment($pdo,$id);
if($fixedTotalInstallment){
  http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'fixed_total_installment_locked','message'=>$fixedTotalLockMessage,'request_id'=>bin2hex(random_bytes(8))]);exit;
}
$invoiceStatus = strtolower((string)($invoiceState['status'] ?? ''));
$isDraft = $invoiceStatus === 'draft';
try { $invoiceState=DocumentPolicy::assertMutable($pdo,'invoice',$id,'monetary_adjustment'); } catch(DocumentLockedException $locked){http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'document_locked','message'=>$locked->getMessage(),'request_id'=>bin2hex(random_bytes(8))]);exit;}
$isGeneralRecipientInvoice = pa_invoice_is_general_recipient($invoiceState);
$client_id = (int)($_POST['client_id'] ?? 0);
$requestedServiceLocationId = !empty($_POST['service_location_id']) ? (int)$_POST['service_location_id'] : null;
if (!empty($invoiceState['job_id']) && $client_id !== (int)$invoiceState['client_id']) {
  http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'job_client_conflict','message'=>'A document cannot be moved to another client while it belongs to a Job. Create it under a new Job instead.','request_id'=>bin2hex(random_bytes(8))]);exit;
}
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$due_date = $_POST['due_date'] ?? null;
$normalizedDueDate = $due_date && trim((string)$due_date) !== '' ? trim((string)$due_date) : null;
$storedDueDate = $invoiceState['due_date'] ?: null;
$dueDateSource = $normalizedDueDate === $storedDueDate ? (string)($invoiceState['due_date_source'] ?? 'manual') : 'manual';
$fulfillment_date = !empty($_POST['fulfillment_date']) ? $_POST['fulfillment_date'] : null;
if ($id<=0 || $client_id<=0) { header('Location: /?page=invoice/invoices-list&error=Invalid'); exit; }
if ($isGeneralRecipientInvoice) {
  $changesPrivateRelationship = $client_id !== (int)($invoiceState['client_id'] ?? 0)
    || $requestedServiceLocationId !== null;
  if ($changesPrivateRelationship || !pa_general_recipient_invoice_is_eligible($invoiceState)) {
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . rawurlencode('General-recipient invoices cannot be assigned to a client relationship, service location, project, contract, or Job.'));
    exit;
  }
}

// Detect whether the DB has the is_extra_charge column (migration may not have been applied)
$hasExtraChargeCol = false;
try {
  $colStmt = $pdo->prepare("SHOW COLUMNS FROM invoice_items LIKE 'is_extra_charge'");
  $colStmt->execute();
  $hasExtraChargeCol = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // If the query fails, assume column is missing and continue (migration step required)
  $hasExtraChargeCol = false;
}

// Fetch existing items to preserve contract items and update extra charges
if ($hasExtraChargeCol) {
  $existingItems = $pdo->prepare('SELECT id, is_extra_charge FROM invoice_items WHERE invoice_id=?');
  $existingItems->execute([$id]);
  $existingMap = [];
  foreach ($existingItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existingMap[$row['id']] = (int)$row['is_extra_charge'];
  }
} else {
  // Older schema: no extra charge column, treat all existing items as contract items (0)
  $existingItems = $pdo->prepare('SELECT id FROM invoice_items WHERE invoice_id=?');
  $existingItems->execute([$id]);
  $existingMap = [];
  foreach ($existingItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existingMap[$row['id']] = 0;
  }
}

$workforceHistory = invoice_workforce_history($pdo, $id);
$preservedWorkforceItems = $workforceHistory['items'];

// Process extra charges (new and updated)
$extraItems = $_POST['extra_item'] ?? [];
$extraDescs = $_POST['extra_desc'] ?? [];
$extraQtys = $_POST['extra_qty'] ?? [];
$ExtraPrices = $_POST['extra_price'] ?? [];
$extraIds = $_POST['extra_id'] ?? [];
$extraUnits = $_POST['extra_billing_unit'] ?? [];
$extraTypes = $_POST['extra_adjustment_type'] ?? [];
$extraCatalogIds = $_POST['extra_item_library_id'] ?? [];

$extraItemsArr = [];
$subtotal = 0.0;

for ($i = 0; $i < count($extraItems); $i++) {
  $itm = trim((string)($extraItems[$i] ?? ''));
  $d = trim((string)($extraDescs[$i] ?? ''));
  $q = (float)($extraQtys[$i] ?? 0);
  $p = (float)($ExtraPrices[$i] ?? 0);
  $adjustmentType = ($extraTypes[$i] ?? 'charge') === 'credit' ? 'credit' : 'charge';
  $eid = (int)($extraIds[$i] ?? 0);
  if (isset($preservedWorkforceItems[$eid])) continue;
  
  if ($itm === '' || $q <= 0 || $p < 0) continue;
  
  $p = abs($p);
  $signedPrice = $adjustmentType === 'credit' ? -$p : $p;
  $line = $q * $signedPrice;
  $subtotal += $line;
  $unit = $billing_mode==='hourly'?'hour':catalog_document_unit((string)($extraUnits[$i]??'each'));
  $extraItemsArr[] = ['id'=>$eid,'i'=>$itm,'d'=>$d,'q'=>$q,'p'=>$signedPrice,'t'=>$line,'u'=>$unit,'type'=>$adjustmentType,'catalog_id'=>max(0,(int)($extraCatalogIds[$i]??0))];
}

// Fetch all existing items to calculate subtotal including contract items
$allExistingItems = $hasExtraChargeCol
  ? $pdo->prepare('SELECT id, description, quantity, unit_price, line_total, is_extra_charge FROM invoice_items WHERE invoice_id=?')
  : $pdo->prepare('SELECT id, description, quantity, unit_price, line_total, 0 AS is_extra_charge FROM invoice_items WHERE invoice_id=?');
$allExistingItems->execute([$id]);
$contractSubtotal = 0.0;
foreach ($allExistingItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
  if ((int)($item['is_extra_charge'] ?? 0) === 0 || isset($preservedWorkforceItems[(int)$item['id']])) {
    $contractSubtotal += isset($preservedWorkforceItems[(int)$item['id']])
      ? (float)$item['line_total']
      : (float)$item['quantity'] * (float)$item['unit_price'];
  }
}
$subtotal = $contractSubtotal; // Reset and add extras

// Add extra charges subtotal
foreach ($extraItemsArr as $ex) {
  $subtotal += $ex['t'];
}

$discount_amount = 0.0;
if ($discount_type === 'percent') {
  $discount_amount = max(0, min(100, $discount_value)) * $subtotal / 100;
} elseif ($discount_type === 'fixed') {
  $discount_amount = max(0, $discount_value);
}
$tax = max(0, $tax_percent) * max(0, $subtotal - $discount_amount) / 100;
$total = max(0, $subtotal - $discount_amount + $tax);

// Extract custom field values from POST
$customFieldValues = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFieldValues) ? json_encode($customFieldValues) : null;
$organizationId = $isGeneralRecipientInvoice ? null : resolve_client_context_org_id($pdo, $client_id, !empty($invoiceState['project_id']) ? (int)$invoiceState['project_id'] : null, request_client_org_id() ?: null);
$showContactOnDocument = $organizationId && !empty($_POST['show_contact_on_document']) ? 1 : 0;

// The legacy compatibility helper can issue MySQL DDL, which implicitly commits.
// Complete it before taking the document lock or changing an invoice/revision.
invoice_ensure_payments_schema($pdo);
$pdo->beginTransaction();
try {
  $invoiceState=DocumentPolicy::assertMutable($pdo,'invoice',$id,'monetary_adjustment',true);
  require_record_ownership($pdo,'invoices',$id);
  if (invoice_workforce_history($pdo, $id) !== $workforceHistory) throw new DomainException('Invoice history changed; reload before saving.');
  if ($workforceHistory['linked'] && $client_id !== (int)$invoiceState['client_id']) throw new DomainException('Historical time and mileage must retain their original client.');
  if(pricing_invoice_is_fixed_total_installment($pdo,$id))throw new DomainException($fixedTotalLockMessage);
  $invoiceStatus=strtolower((string)($invoiceState['status']??''));
  $isDraft=$invoiceStatus==='draft';
  $isGeneralRecipientInvoice=pa_invoice_is_general_recipient($invoiceState);
  if(!empty($invoiceState['job_id'])&&$client_id!==(int)$invoiceState['client_id'])throw new DomainException('A document cannot be moved to another client while it belongs to a Job. Create it under a new Job instead.');
  if($isGeneralRecipientInvoice&&($client_id!==(int)($invoiceState['client_id']??0)||$requestedServiceLocationId!==null||!pa_general_recipient_invoice_is_eligible($invoiceState)))throw new DomainException('General-recipient invoices cannot be assigned to a private client relationship.');
  $storedDueDate=$invoiceState['due_date']?:null;
  $dueDateSource=$normalizedDueDate===$storedDueDate?(string)($invoiceState['due_date_source']??'manual'):'manual';
  $organizationId=$isGeneralRecipientInvoice?null:resolve_client_context_org_id($pdo,$client_id,!empty($invoiceState['project_id'])?(int)$invoiceState['project_id']:null,request_client_org_id()?:null);
  $showContactOnDocument=$organizationId&&!empty($_POST['show_contact_on_document'])?1:0;
  $serviceLocationId = $isGeneralRecipientInvoice
    ? null
    : document_resolve_service_location($pdo,$client_id,!empty($invoiceState['project_id'])?(int)$invoiceState['project_id']:null,!empty($invoiceState['job_id'])?(int)$invoiceState['job_id']:null,$requestedServiceLocationId);
  $pdo->prepare('UPDATE invoices
    SET client_id=?, organization_id=?, show_contact_on_document=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?,
        subtotal=?, tax_amount=?, total=?, balance_due=GREATEST(0,?-COALESCE(amount_paid,0)),
        due_date=?, due_date_source=?, fulfillment_date=?, custom_fields=?, service_location_id=?
    WHERE id=?')
    ->execute([
      $client_id, $organizationId, $showContactOnDocument, $billing_mode, $discount_type, $discount_value, $tax_percent,
      $subtotal, $tax, $total, $total,
      $normalizedDueDate, $dueDateSource, $fulfillment_date, $customFieldsJson, $serviceLocationId, $id,
    ]);
  
  $row = $pdo->prepare('SELECT project_code FROM invoices WHERE id=?');
  $row->execute([$id]);
  $pc = (string)$row->fetchColumn();
  $pn = trim((string)($_POST['project_notes'] ?? ''));
  if ($pc !== '' && $pn !== '') {
    $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
    $up->execute([$pc, $client_id, $pn]);
  }
  
  // Delete only extra charge items (if column exists), otherwise do not delete contract items
  if ($hasExtraChargeCol) {
    if (pa_workforce_enabled()) {
    $releaseExtraTime = $pdo->prepare('
      UPDATE time_entries te
      INNER JOIN invoice_items ii ON ii.id = te.invoice_item_id
      SET te.billed = 0, te.invoice_item_id = NULL, te.invoice_id = NULL
      WHERE ii.invoice_id = ? AND ii.is_extra_charge = 1
    ');
    $releaseExtraTime->execute([$id]);
    }
    $preservedIds = array_keys($preservedWorkforceItems);
    $preserveSql = $preservedIds ? ' AND id NOT IN (' . implode(',', array_fill(0, count($preservedIds), '?')) . ')' : '';
    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=? AND is_extra_charge=1' . $preserveSql)->execute(array_merge([$id], $preservedIds));

    // Insert new extra charges with the flag
    $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,is_extra_charge,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,1,?)');
    foreach ($extraItemsArr as $it) {
      $catalog=catalog_document_snapshot($pdo,(int)($it['catalog_id']??0),$it);
      $ins->execute([$id,$catalog['item_library_id'],$it['i'],$it['d'],$it['q'],$it['p'],$it['t'],$it['u'],$catalog['catalog_snapshot']]);
    }
  } else {
    // Schema doesn't have is_extra_charge yet: append entries as regular invoice_items
    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($extraItemsArr as $it) {
      $catalog=catalog_document_snapshot($pdo,(int)($it['catalog_id']??0),$it);
      $ii->execute([$id,$catalog['item_library_id'],$it['i'],$it['d'],$it['q'],$it['p'],$it['t'],$it['u'],$catalog['catalog_snapshot']]);
    }
  }
  $nextInvoiceRevision=max(1,(int)($invoiceState['revision_number']??1))+1;
  // Manual extras are replaceable invoice-editor state. Tracked-time and
  // correction adjustments are append-only audit records owned by their
  // respective domain services and must survive an unrelated invoice edit.
  $pdo->prepare(
    "UPDATE invoice_adjustments
     SET superseded_at=NOW()
     WHERE invoice_id=? AND superseded_at IS NULL
       AND label NOT IN ('Tracked time','Time correction','Base-plus-overage time correction')"
  )->execute([$id]);
  $adjustmentInsert=$pdo->prepare('INSERT INTO invoice_adjustments (invoice_id,adjustment_type,label,description,quantity,unit_price,amount,revision_number,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
  foreach($extraItemsArr as $it){$adjustmentInsert->execute([$id,$it['type'],$it['i'],$it['d']?:null,$it['q'],$it['p'],$it['t'],$nextInvoiceRevision,(int)($_SESSION['user']['id']??0)?:null]);}

  if (pa_workforce_enabled() && !$isGeneralRecipientInvoice) {
    $syncTimeEntries = $pdo->prepare('
      UPDATE time_entries te
      LEFT JOIN invoice_items ii ON ii.id = te.invoice_item_id
      SET te.client_id = ?, te.invoice_id = ?
      WHERE te.invoice_id = ? OR ii.invoice_id = ?
    ');
    $syncTimeEntries->execute([$client_id, $id, $id, $id]);

    (new WorkTimeBillingContextService($pdo))->synchronizeInvoice(
      $id,
      (int)($_SESSION['user']['id'] ?? 0)
    );
  }

  if (!empty($invoiceState['finalized_at']) || invoice_effective_paid_total($pdo,$id)>0.005) {
    invoice_refresh_payment_totals($pdo, $id, false);
  }
  if ($organizationId !== null) {
    pricing_apply_posted_override($pdo,(int)$organizationId,'invoice',$id,(int)($_SESSION['user']['id']??0),$_POST);
  }
    document_issuer_apply_post($pdo, $appConfig, 'invoice', $id, $_POST);
  pricing_finalize_document_revision($pdo,$organizationId !== null ? (int)$organizationId : null,'invoice',$id,(int)($_SESSION['user']['id']??0),true,(string)($appConfig['workforce_currency']??'USD'));
  if(!$isDraft)$pdo->prepare('UPDATE public_links SET revoked=1 WHERE document_type="invoice" AND document_id=?')->execute([$id]);
  
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('[invoices_update] ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
  $error=$e instanceof DocumentLockedException||($e instanceof DomainException&&hash_equals($fixedTotalLockMessage,$e->getMessage()))?$e->getMessage():'Update failed';
  header('Location: /?page=invoice/invoice-details&id='.$id.'&error='.rawurlencode($error));
  exit;
}
header('Location: /?page=invoice/invoice-details&id=' . $id . '&updated=1');
exit;
