<?php
// src/controllers/public_quote_action.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Verify CSRF (we skipped global preflight intentionally)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_quote_action', 30, 60)) {
  http_response_code(429);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><title>Rate limited</title></head><body><h1>Rate limited</h1></body></html>';
  exit;
}
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/recurring_services.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/smtp.php';
require_once __DIR__ . '/../../utils/project_billing.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/mileage.php';
require_once __DIR__ . '/../../utils/job_work_materialization.php';
require_once __DIR__ . '/../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../utils/document_organization.php';
require_once __DIR__ . '/../../services/ProjectContractEligibilityGuardService.php';
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('public_quote_action', $submitted)) {
  header('Location: /?page=public-doc&error=' . urlencode('Invalid request'));
  exit;
}

try {
  $qid = 0;
  $token = isset($_POST['token']) ? (string)$_POST['token'] : '';
  $action = isset($_POST['action']) ? strtolower((string)$_POST['action']) : '';
  if (!in_array($action, ['approve','deny'], true)) { throw new Exception('badaction'); }

  // This first read locates the quote only. Authority is revalidated under
  // locks below, after locking the quote first to match staff approval order.
  $st = $pdo->prepare('SELECT document_type, document_id FROM public_links WHERE token=? LIMIT 1');
  $st->execute([$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('notfound'); }
  if ($row['document_type'] !== 'quote') { throw new Exception('notquote'); }
  $qid = (int)$row['document_id'];

  $pdo->beginTransaction();
  $q = $pdo->prepare('SELECT q.*, c.name AS client_name, c.email AS client_email FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=? FOR UPDATE');
  $q->execute([$qid]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) { throw new Exception('nofile'); }
  $lockedLink=$pdo->prepare('SELECT document_type,document_id,expires_at,revoked FROM public_links WHERE token=? LIMIT 1 FOR UPDATE');
  $lockedLink->execute([$token]);$authority=$lockedLink->fetch(PDO::FETCH_ASSOC);
  if(!$authority||$authority['document_type']!=='quote'||(int)$authority['document_id']!==$qid)throw new DomainException('Public quote authority changed.');

  $expectedStatus=$action==='approve'?'approved':'rejected';
  $currentStatus=(string)$quote['status'];
  if($currentStatus!=='pending'){
    if($currentStatus===$expectedStatus){
      pa_public_link_terminalize($pdo,'quote',$qid,$action==='approve'?'approved':'denied');
      $pdo->commit();header('Location: /?page=public-doc&token='.rawurlencode($token).'&ok=1');exit;
    }
    $pdo->rollBack();http_response_code(409);header('Content-Type: text/plain; charset=utf-8');echo 'This quote already has a different response.';exit;
  }
  if((int)$authority['revoked']===1||strtotime((string)$authority['expires_at'])<time()){
    $pdo->rollBack();http_response_code(410);header('Content-Type: text/plain; charset=utf-8');echo 'This quote link is no longer active.';exit;
  }

  $changed = false;
  if ($action === 'deny') {
    if ((string)$quote['status'] === 'pending') {
      $pdo->prepare('UPDATE quotes SET status="rejected" WHERE id=?')->execute([$qid]);
      $changed = true;
    }
  } else {
    if ((string)$quote['status'] === 'pending') {
      // Approve quote by reusing minimal sequence under the authority locks.
        // Load items
        $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
        $items->execute([$qid]);
        $qitems = $items->fetchAll(PDO::FETCH_ASSOC);

        // Ensure project_code
        $projectCode = $quote['project_code'] ?? null;
        if (!$projectCode) {
          $projectCode = 'PA-' . date('Y') . '-' . str_pad((string)$qid, 4, '0', STR_PAD_LEFT);
          $pdo->prepare('UPDATE quotes SET project_code=? WHERE id=?')->execute([$projectCode, $qid]);
        }

        // Mark approved
        $pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?')->execute([$qid]);

        $quoteType = $quote['quote_type'] ?? 'regular';

        // Resolve creator + organization for derived records (no session user for public link)
        $fallbackUserId = 1;
        $quoteCreator = (int)($quote['created_by'] ?? 0) ?: $fallbackUserId;
        $quoteOrgId = pa_document_effective_organization_id($pdo, 'quote', $qid);
        $billingMode = (($quote['billing_mode'] ?? 'fixed') === 'hourly') ? 'hourly' : 'fixed';
        $depositType = $quote['deposit_type'] ?? 'none';
        $depositValue = (float)($quote['deposit_amount'] ?? 0);
        $quoteTotal = (float)($quote['total'] ?? 0);
        $contractDepositAmount = 0.0;
        if ($depositType === 'percent') {
          $contractDepositAmount = max(0, min(100, $depositValue)) * $quoteTotal / 100;
        } elseif ($depositType === 'fixed') {
          $contractDepositAmount = min(max(0, $depositValue), $quoteTotal);
        }

        $projectId = !empty($quote['project_id']) ? (int)$quote['project_id'] : null;
        (new App\Services\ProjectContractEligibilityGuardService($pdo))->assertCanCreateOrAttach($projectId);

        // Create contract (pending)
        $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, contract_type, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, billing_start_mode, scope, organization_id, show_contact_on_document, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([
             $qid,
             (int)$quote['client_id'],
             $projectId,
             'pending',
             $quoteType,
             $billingMode,
             $quote['discount_type'],
             $quote['discount_value'],
             $quote['tax_percent'],
             $quote['subtotal'],
             $quote['total'],
             $projectCode,
             $depositType,
             $contractDepositAmount,
             $quote['start_date'] ?? null,
             $quote['end_date'] ?? null,
             $quote['billing_interval_count'] ?? 1,
             $quote['billing_interval_unit'] ?? 'month',
             $quote['pricing_type'] ?? ($quoteType === 'on_demand' ? 'on_demand' : null),
             $quote['price_per_invoice'] ?? null,
             $quoteType === 'long_term' ? 'on_upload' : null,
             $quote['scope'] ?? null,
             $quoteOrgId,
             (int)($quote['show_contact_on_document'] ?? 0),
             $quoteCreator
           ]);
        $contract_id = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE contracts SET job_id=?,service_location_id=? WHERE id=?')
          ->execute([!empty($quote['job_id'])?(int)$quote['job_id']:null,!empty($quote['service_location_id'])?(int)$quote['service_location_id']:null,$contract_id]);
        if ($quoteType === 'long_term' && ($quote['pricing_type'] ?? '') === 'per_invoice') {
          pa_recurring_service_ensure_base($pdo, $contract_id);
        }

        $ci = $pdo->prepare('INSERT INTO contract_items (contract_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($qitems as $it) {
          $ci->execute([
            $contract_id,
            $it['item_library_id']??null,
            $it['item'] ?? ($it['description'] ?? 'Item'),
            $it['description'],
            $it['quantity'],
            $it['unit_price'],
            $it['line_total'],
            in_array(($it['billing_unit'] ?? 'each'),['each','hour','day','mile','project'],true)?$it['billing_unit']:'each',
            (int)($it['is_travel']??0),
            $it['pricing_status']??'standard',
            $it['catalog_snapshot']??null
          ]);
        }
        mileage_copy_document_rule($pdo,$qid,$contract_id,$quoteOrgId,(int)$quote['client_id'],$quoteCreator);

        if ($quoteType === 'regular') {
          // Create a private draft. Contract completion is the billing event.
          $invoiceProjectId = !empty($quote['project_id']) ? (int)$quote['project_id'] : null;
          $projectBillingContext = project_invoice_billing_context($pdo, $invoiceProjectId, $appConfig, null, true);
          $invoiceSubtotal=0.0;
          foreach($qitems as $it){if(($it['pricing_status']??'standard')!=='standard')continue;$invoiceSubtotal+=(float)$it['line_total'];}
          $invoiceDiscount=0.0;
          if(($quote['discount_type']??'none')==='percent')$invoiceDiscount=max(0,min(100,(float)$quote['discount_value']))*$invoiceSubtotal/100;
          elseif(($quote['discount_type']??'none')==='fixed')$invoiceDiscount=min($invoiceSubtotal,max(0,(float)$quote['discount_value']));
          $invoiceTaxable=max(0,$invoiceSubtotal-$invoiceDiscount);
          $invoiceTotal=$invoiceTaxable+(max(0,(float)$quote['tax_percent'])*$invoiceTaxable/100);
          $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, invoice_type, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, payment_terms_days, due_date_source, project_code, fulfillment_date, organization_id, show_contact_on_document, created_by, collection_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
             ->execute([$contract_id, $qid, (int)$quote['client_id'], $invoiceProjectId, 'regular', $billingMode, $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $invoiceSubtotal, $invoiceTotal, 'draft', $projectBillingContext['due_date'], $projectBillingContext['net_terms_days'], 'terms', $projectCode, $quote['fulfillment_date'] ?? null, $quoteOrgId, (int)($quote['show_contact_on_document'] ?? 0), $quoteCreator, $projectBillingContext['collection_mode']]);
          $invoice_id = (int)$pdo->lastInsertId();
          $pdo->prepare('UPDATE invoices SET job_id=?,service_location_id=? WHERE id=?')
            ->execute([!empty($quote['job_id'])?(int)$quote['job_id']:null,!empty($quote['service_location_id'])?(int)$quote['service_location_id']:null,$invoice_id]);

          $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
          foreach ($qitems as $it) {
            if(($it['pricing_status']??'standard')!=='standard')continue;
            $ii->execute([
              $invoice_id,
              $it['item_library_id']??null,
              $it['item'] ?? ($it['description'] ?? 'Item'),
              $it['description'],
              $it['quantity'],
              $it['unit_price'],
              $it['line_total'],
              in_array(($it['billing_unit'] ?? 'each'),['each','hour','day','mile','project'],true)?$it['billing_unit']:'each',
              (int)($it['is_travel']??0),
              $it['pricing_status']??'standard',
              $it['catalog_snapshot']??null
            ]);
          }
        }

        // Assign doc_numbers
        $cMaxStmt = $pdo->prepare('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = ?');
        $cMaxStmt->execute([$quoteType]);
        $cMax = (int)$cMaxStmt->fetchColumn();
        $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
        if (!empty($invoice_id)) {
          $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "regular"')->fetchColumn();
          $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);
        }

        pricing_finalize_derived_document_revision(
          $pdo,$quoteOrgId!==null?(int)$quoteOrgId:null,'contract',$contract_id,$quoteCreator,
          (string)($appConfig['workforce_currency']??'USD'),'quote',$qid,(int)($quote['revision_number']??1),
          $depositType==='percent'&&$quoteOrgId!==null
            ? static fn(array $pricing)=>pricing_recompute_contract_percentage_deposit($pdo,(int)$quoteOrgId,$contract_id,(string)$depositValue)
            : null
        );
        if(!empty($invoice_id))pricing_finalize_derived_document_revision(
          $pdo,$quoteOrgId!==null?(int)$quoteOrgId:null,'invoice',(int)$invoice_id,$quoteCreator,
          (string)($appConfig['workforce_currency']??'USD'),'quote',$qid,(int)($quote['revision_number']??1)
        );

        catalog_plan_document_work($pdo,'quote',$qid,$quoteCreator);
        $changed = true;
    }
  }

  pa_public_link_terminalize($pdo, 'quote', $qid, $action === 'approve' ? 'approved' : 'denied');
  $pdo->commit();

  // Email admin only if status changed via public link
  if ($changed) {
    // Send a notification to the first admin
    try {
  require_once __DIR__ . '/../../utils/notifications.php';
      notify_admin_quote_change($pdo, $appConfig, $quote, $action === 'approve' ? 'approve' : 'deny');
    } catch (Throwable $e) {
      // Ignore notification failures but keep normal flow
    }
  }

  // Redirect back to public view with success notice always (even if no change due to non-pending)
  header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&ok=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  // If an exception occurred after we updated the quote status (e.g. while creating contract/invoice),
  // prefer to show the user success if the quote actually changed. This avoids confusing the client
  // when backend follow-up tasks fail but the primary action succeeded.
  $t = isset($_POST['token']) ? (string)$_POST['token'] : '';
  try {
    if (isset($qid) && $qid > 0) {
      $chk = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
      $chk->execute([$qid]);
      $s = (string)($chk->fetchColumn() ?: '');
      if ($s === 'approved' || $s === 'rejected') {
        header('Location: /?page=public-doc&token=' . rawurlencode($t) . '&ok=1');
        exit;
      }
    }
  } catch (Throwable $_e) {
    // ignore and fallthrough to generic error
  }
  header('Location: /?page=public-doc&token=' . rawurlencode($t) . '&error=' . urlencode('Unable to record response'));
  exit;
}
