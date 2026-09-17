<?php
// src/controllers/contract/on_demand_invoice_generate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/project_billing.php';
require_once __DIR__ . '/../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/invoice_notifications.php';
require_once __DIR__ . '/../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../utils/document_organization.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';

@error_log('[on_demand_invoice_generate] POST received', 0);

$contract_id = (int)($_POST['id'] ?? 0);
$sendEmail = !empty($_POST['send_email']);
$requestGenerationKey=strtolower(trim((string)($_POST['generation_key']??'')));
if(!preg_match('/^[a-f0-9]{32}$/',$requestGenerationKey))$requestGenerationKey=bin2hex(random_bytes(16));
$generationKey=hash('sha256','on-demand:'.$contract_id.':'.$requestGenerationKey);

if ($contract_id <= 0) {
    @error_log('[on_demand_invoice_generate] invalid contract_id', 0);
    header('Location: /?page=contract/on-demand-contracts-list&error=Invalid%20contract%20ID');
    exit;
}

require_record_ownership($pdo, 'contracts', $contract_id);

$pdo->beginTransaction();

try {
    // Fetch the on-demand contract
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = ? AND contract_type = "on_demand" FOR UPDATE');
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    $existingGeneration=$pdo->prepare('SELECT id,collection_mode FROM invoices WHERE contract_id=? AND generation_key=? LIMIT 1 FOR UPDATE');
    $existingGeneration->execute([$contract_id,$generationKey]);
    $existingInvoice=$existingGeneration->fetch(PDO::FETCH_ASSOC)?:[];
    $existingInvoiceId=(int)($existingInvoice['id']??0);
    if($existingInvoiceId>0){
        $existingCollectionMode=trim((string)($existingInvoice['collection_mode']??''))?:'direct';
        $pdo->commit();
        // A prior request may have committed the invoice and then crashed before
        // finalization/delivery. Replays must finish those idempotent side effects.
        if ($sendEmail) {
            $contractCreator = (int)($contract['created_by'] ?? 0) ?: 1;
            invoice_finalize($pdo, $existingInvoiceId, $appConfig, 'on_demand_send', $contractCreator);
            // Replay the collection policy stored on the committed invoice.
            // The Project default may have changed since the first request.
            if ($existingCollectionMode === 'direct') {
                $clientStmt = $pdo->prepare('SELECT email FROM clients WHERE id = ?');
                $clientStmt->execute([(int)$contract['client_id']]);
                $to = trim((string)$clientStmt->fetchColumn());
                $deliveryStatus = filter_var($to, FILTER_VALIDATE_EMAIL) ? 'pending' : 'suppressed';
                invoice_notification_enqueue(
                    $pdo,
                    $existingInvoiceId,
                    'on_demand_generate',
                    'generated',
                    $to,
                    $deliveryStatus,
                    $deliveryStatus === 'suppressed' ? 'Missing or invalid client email.' : null
                );
                invoice_notification_process($pdo, $appConfig, null, null, 10, $existingInvoiceId);
            }
        }
        header('Location: /?page=contract/on-demand-invoices-list&contract_id='.$contract_id.'&invoice_generated=1&idempotent=1');exit;
    }
    
    // Check if contract is active
    if ($contract['status'] !== 'active') {
        throw new Exception('Contract must be active to generate invoices');
    }
    
    // Check if contract has ended
    if (!empty($contract['end_date']) && $contract['end_date'] < date('Y-m-d')) {
        throw new Exception('Contract has ended');
    }
    
    $clientId = $contract['client_id'];
    $projectCode = $contract['project_code'];
    $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
    $billingMode = ($contract['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
    
    // Resolve creator + organization for derived invoice (no session in on-demand generator)
    $fallbackUserId = 1;
    $contractCreator = (int)($contract['created_by'] ?? 0) ?: $fallbackUserId;
    $contractOrgId = pa_document_effective_organization_id($pdo, 'contract', $contract_id);
    
    // Calculate invoice amount. Older on-demand contracts may have a zero
    // price_per_invoice even though subtotal/contract_items were saved.
    $subtotal = (float)($contract['price_per_invoice'] ?? 0);
    if ($subtotal <= 0) {
        $subtotal = (float)($contract['subtotal'] ?? 0);
    }
    
    // Apply discount and tax
    $discountType = $contract['discount_type'] ?? 'none';
    $discountValue = (float)($contract['discount_value'] ?? 0);
    $discount = 0;
    
    if ($discountType === 'percent') {
        $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
    } elseif ($discountType === 'fixed') {
        $discount = $discountValue;
    }
    
    $taxable = max(0, $subtotal - $discount);
    $tax = max(0, (float)$contract['tax_percent']) * $taxable / 100;
    $total = max(0, $taxable + $tax);
    
    // Create invoice
    $projectBillingContext = project_invoice_billing_context($pdo, $projectId, $appConfig, date('Y-m-d'), true);
    $projectMonthlyBilling = $projectBillingContext['collection_mode'] === 'project_aggregate';
    $dueDate = (string)$projectBillingContext['due_date'];
    $paymentTermsDays = (int)$projectBillingContext['net_terms_days'];
    
    $insertInvoice = $pdo->prepare('
        INSERT INTO invoices (
            contract_id, client_id, project_id, project_code, invoice_type, billing_mode,
            discount_type, discount_value, tax_percent, 
            subtotal, total, balance_due, status, due_date, payment_terms_days, due_date_source, created_at, organization_id, show_contact_on_document, created_by, generation_key, collection_mode
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "terms", ?, ?, ?, ?, ?, ?)
    ');
    
    $insertInvoice->execute([
        $contract_id,
        $clientId,
        $projectId,
        $projectCode,
        'on_demand',
        $billingMode,
        $discountType,
        $discountValue,
        $contract['tax_percent'],
        $subtotal,
        $total,
        $total,
        'draft',
        $dueDate,
        $paymentTermsDays,
        date('Y-m-d H:i:s'),
        $contractOrgId,
        (int)($contract['show_contact_on_document'] ?? 0),
        $contractCreator,
        $generationKey,
        $projectBillingContext['collection_mode']
    ]);
    
    $invoiceId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE invoices SET job_id=?,service_location_id=? WHERE id=?')
        ->execute([!empty($contract['job_id']) ? (int)$contract['job_id'] : null,!empty($contract['service_location_id']) ? (int)$contract['service_location_id'] : null,$invoiceId]);
    // Assign doc number
    $docNumber = pa_next_invoice_doc_number($pdo, 'on_demand');
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$docNumber, $invoiceId]);
    
    // Add invoice items. Preserve itemized contracts; flat contracts get one line.
    $contractItemsStmt = $pdo->prepare('SELECT item_library_id,item,description,quantity,unit_price,line_total,billing_unit,catalog_snapshot FROM contract_items WHERE contract_id=? ORDER BY sort_order,id');
    $contractItemsStmt->execute([$contract_id]);
    $contractItems = $contractItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($contractItems)) {
        $insertItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($contractItems as $item) {
            $insertItem->execute([
                $invoiceId,
                $item['item_library_id']??null,
                $item['item'] ?? '',
                $item['description'] ?? '',
                (float)($item['quantity'] ?? 0),
                (float)($item['unit_price'] ?? 0),
                (float)($item['line_total'] ?? 0),
                $item['billing_unit'] ?? ($billingMode === 'hourly' ? 'hour' : 'each'),
                $item['catalog_snapshot']??null,
            ]);
        }
    } else {
        $description = 'On-demand service fee';
        if (!empty($contract['scope'])) {
            $description .= ' - ' . substr($contract['scope'], 0, 100);
        }

        $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?)')
            ->execute([$invoiceId, $description, 1, $subtotal, $subtotal, $billingMode === 'hourly' ? 'hour' : 'each']);
    }
    
    pricing_finalize_derived_document_revision(
        $pdo,$contractOrgId,'invoice',$invoiceId,$contractCreator,(string)($appConfig['workforce_currency']??'USD'),
        'contract',$contract_id,pricing_contract_source_revision($contract)
    );
    $finalTotal=$pdo->prepare('SELECT total FROM invoices WHERE id=?');$finalTotal->execute([$invoiceId]);$totalMoney=(string)$finalTotal->fetchColumn();$total=(float)$totalMoney;

    // Update contract from the exact frozen-contract invoice result.
    $newTotalInvoiced = pricing_minor_to_money(pricing_money_to_minor((string)($contract['total_invoiced']??'0'))+pricing_money_to_minor($totalMoney));
    $newInvoiceCount = (int)$contract['invoice_count'] + 1;
    $pdo->prepare('UPDATE contracts SET total_invoiced=?, invoice_count=?, last_invoice_date=? WHERE id=? AND contract_type = "on_demand"')
        ->execute([$newTotalInvoiced, $newInvoiceCount, date('Y-m-d'), $contract_id]);
    
    $pdo->commit();
    
    @error_log("[on_demand_invoice_generate] Generated invoice " . pa_invoice_label($docNumber, 'on_demand') . " for contract ODC-{$contract['doc_number']} (\${$total})");

    // Generate-only remains private. The send choice finalizes first; monthly
    // project children are collected later through the project statement.
    if ($sendEmail) {
        invoice_finalize($pdo, $invoiceId, $appConfig, 'on_demand_send', $contractCreator);
    }
    if ($sendEmail && !$projectMonthlyBilling) {
        $clientStmt = $pdo->prepare('SELECT email FROM clients WHERE id = ?');
        $clientStmt->execute([$clientId]);
        $to = trim((string)$clientStmt->fetchColumn());
        $deliveryStatus = filter_var($to, FILTER_VALIDATE_EMAIL) ? 'pending' : 'suppressed';
        $deliveryError = $deliveryStatus === 'suppressed' ? 'Missing or invalid client email.' : null;
        invoice_notification_enqueue(
            $pdo,
            $invoiceId,
            'on_demand_generate',
            'generated',
            $to,
            $deliveryStatus,
            $deliveryError
        );
        // Try immediately for interactive feedback. A transport/PDF failure stays
        // durable and is retried by the normal invoice delivery worker.
        invoice_notification_process($pdo, $appConfig, null, null, 10, $invoiceId);
    }
    $redirect = '/?page=contract/on-demand-invoices-list&contract_id=' . $contract_id . '&invoice_generated=1';
    if ($sendEmail) {
        $redirect .= $projectMonthlyBilling ? '&project_billing=1' : '&email_requested=1';
    }
    header('Location: ' . $redirect);
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[on_demand_invoice_generate] exception: ' . $e->getMessage(), 0);
    $msg = substr($e->getMessage(), 0, 200);
    header('Location: /?page=contract/on-demand-contracts-list&error=' . urlencode($msg));
    exit;
}
