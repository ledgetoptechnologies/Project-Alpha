<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/upload_validator.php';
require_once __DIR__ . '/../../utils/recurring_services.php';
require_once __DIR__ . '/../../utils/recurring_billing.php';

$contractId = (int)($_POST['contract_id'] ?? 0);
$serviceId = max(0, (int)($_POST['service_id'] ?? 0));
if ($contractId <= 0) {
    header('Location: /?page=contract/long-term-contracts-list&error=' . urlencode('Invalid contract.'));
    exit;
}
require_record_ownership($pdo, 'contracts', $contractId);

$name = trim((string)($_POST['name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$amount = trim((string)($_POST['amount'] ?? '0'));
$rateBasis = (string)($_POST['rate_basis'] ?? 'per_period');
$monthlyRate = null;
$intervalCount = max(1, (int)($_POST['billing_interval_count'] ?? 1));
$intervalUnit = strtolower((string)($_POST['billing_interval_unit'] ?? 'month'));
$effectiveFrom = trim((string)($_POST['effective_from'] ?? date('Y-m-d')));
$effectiveUntil = trim((string)($_POST['effective_until'] ?? '')) ?: null;
$nextInvoiceDate = trim((string)($_POST['next_invoice_date'] ?? $effectiveFrom));
$approved = !empty($_POST['client_approved']);
$prorationAmount = round(max(0, (float)($_POST['proration_amount'] ?? 0)), 2);
$prorationDescription = trim((string)($_POST['proration_description'] ?? ''));
$sendProration = !empty($_POST['send_proration']);
$prorationRequestKey=strtolower(trim((string)($_POST['generation_key']??'')));
if(!preg_match('/^[a-f0-9]{32}$/',$prorationRequestKey))$prorationRequestKey=bin2hex(random_bytes(16));
$prorationGenerationKey=hash('sha256','recurring-proration:'.$contractId.':'.$prorationRequestKey);
$userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;

try {
    if (!in_array($rateBasis, ['per_period','monthly'], true)) throw new DomainException('Invalid rate basis.');
    if ($rateBasis === 'monthly') {
        $monthlyRate = pa_recurring_rate_money(pa_recurring_rate_minor((string)($_POST['monthly_rate'] ?? '0')));
        $amount = pa_recurring_service_period_amount($monthlyRate, $intervalCount, $intervalUnit);
    } else {
        $amount = pa_recurring_rate_money(pa_recurring_rate_minor($amount));
    }
} catch (Throwable $error) {
    header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode($error->getMessage()));
    exit;
}

if ($name === '' || (float)$amount <= 0) {
    header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode('Service name and a positive recurring amount are required.'));
    exit;
}
if (!in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
    $intervalUnit = 'month';
}
foreach ([$effectiveFrom, $nextInvoiceDate] as $requiredDate) {
    if (!pa_recurring_valid_date($requiredDate)) {
        header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode('Effective and next invoice dates are required.'));
        exit;
    }
}
if ($effectiveUntil !== null && (!pa_recurring_valid_date($effectiveUntil) || $effectiveUntil < $effectiveFrom)) {
    header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode('The service end date must be on or after its effective date.'));
    exit;
}
if ($effectiveUntil !== null && $nextInvoiceDate > $effectiveUntil) {
    header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode('The next invoice date must fall within the service effective dates.'));
    exit;
}
if ($prorationAmount > 0 && !$approved) {
    header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode('Client approval is required before generating a prorated invoice.'));
    exit;
}

$storedAddendum = null;
$storedAddendumPath = null;
if (!empty($_FILES['signed_addendum']) && (int)($_FILES['signed_addendum']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $uploadError = null;
    $uploadDir = __DIR__ . '/../../uploads/contract_amendments';
    $storedAddendum = validate_and_store_upload(
        $_FILES['signed_addendum'],
        ['application/pdf' => 'pdf'],
        15 * 1024 * 1024,
        $uploadDir,
        $uploadError,
        ['reject_archives' => true, 'require_pdf_header' => true, 'reject_pdf_active_content' => true]
    );
    if ($storedAddendum === null) {
        header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode($uploadError ?: 'Could not upload the signed addendum.'));
        exit;
    }
    $storedAddendumPath = $uploadDir . DIRECTORY_SEPARATOR . $storedAddendum;
    $approved = true;
}
$addendumUrl = $storedAddendum === null ? null : '/?page=serve-upload&file=' . rawurlencode('contract_amendments/' . $storedAddendum);

$prorationInvoiceId = null;
$idempotentReplay = false;
try {
    $pdo->beginTransaction();
    $contractStmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term" FOR UPDATE');
    $contractStmt->execute([$contractId]);
    $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract || ($contract['pricing_type'] ?? '') !== 'per_invoice') {
        throw new RuntimeException('Recurring services require a per-invoice long-term contract.');
    }
    if($prorationAmount>0){$existingProration=$pdo->prepare('SELECT id FROM invoices WHERE contract_id=? AND generation_key=? LIMIT 1');$existingProration->execute([$contractId,$prorationGenerationKey]);$existingProrationId=(int)$existingProration->fetchColumn();if($existingProrationId>0){$prorationInvoiceId=$existingProrationId;$idempotentReplay=true;if($storedAddendumPath!==null&&is_file($storedAddendumPath)){if(!unlink($storedAddendumPath)){throw new RuntimeException('Could not discard the duplicate signed addendum upload.');}$storedAddendum=null;$storedAddendumPath=null;$addendumUrl=null;}$pdo->commit();}}

    if (!$idempotentReplay) {
    $oldService = null;
    if ($serviceId > 0) {
        $serviceStmt = $pdo->prepare('SELECT * FROM contract_recurring_services WHERE id=? AND contract_id=? FOR UPDATE');
        $serviceStmt->execute([$serviceId, $contractId]);
        $oldService = $serviceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldService || ($oldService['status'] ?? '') === 'ended') {
            throw new RuntimeException('Recurring service not found or already ended.');
        }
    }

    $approvalStatus = $approved ? 'approved' : 'pending';
    $contractStatus = strtolower((string)$contract['status']);
    $serviceStatus = !$approved ? 'pending' : ($contractStatus === 'active' ? 'active' : ($contractStatus === 'paused' ? 'paused' : 'pending'));
    if ($oldService) {
        $renewalBoundary = pa_recurring_change_boundary($oldService, date('Y-m-d'));
        if ($nextInvoiceDate !== $renewalBoundary) throw new DomainException('The renewal date changed while this form was open. Reload and review the new effective date before saving.');
        $nextInvoiceDate = $renewalBoundary;
        $effectiveFrom = $nextInvoiceDate;
        $newService = array_replace($oldService, [
            'name' => substr($name, 0, 190), 'description' => $description ?: null,
            'amount' => $amount, 'billing_interval_count' => $intervalCount,
            'billing_interval_unit' => $intervalUnit, 'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil, 'next_invoice_date' => $nextInvoiceDate,
            'rate_basis' => $rateBasis, 'monthly_rate' => $monthlyRate,
        ]);
        pa_recurring_service_queue_change($pdo, $oldService, pa_recurring_service_snapshot($newService), $approved, $addendumUrl, $userId);
    } else {
        if ($nextInvoiceDate < $effectiveFrom) throw new DomainException('The first invoice date cannot precede the service start date.');
        $pdo->prepare('
            INSERT INTO contract_recurring_services
                (contract_id,name,description,amount,billing_interval_count,billing_interval_unit,
                 effective_from,effective_until,next_invoice_date,status,approval_status,is_base,created_by,rate_basis,monthly_rate)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)
        ')->execute([
            $contractId, substr($name, 0, 190), $description ?: null, $amount, $intervalCount,
            $intervalUnit, $effectiveFrom, $effectiveUntil, $nextInvoiceDate, $serviceStatus,
            $approvalStatus, $userId, $rateBasis, $monthlyRate,
        ]);
        $serviceId = (int)$pdo->lastInsertId();
    }

    if (!$oldService) {
        $serviceStmt = $pdo->prepare('SELECT * FROM contract_recurring_services WHERE id=?');
        $serviceStmt->execute([$serviceId]);
        $newService = $serviceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    pa_recurring_service_record_amendment(
        $pdo,
        $contractId,
        $serviceId,
        $oldService ? 'service_updated' : 'service_added',
        $approvalStatus,
        $effectiveFrom,
        ($oldService ? 'Recurring service updated: ' : 'Recurring service added: ') . $name,
        $oldService ? pa_recurring_service_snapshot($oldService) : null,
        pa_recurring_service_snapshot($newService),
        $addendumUrl,
        $userId
    );

    if ($prorationAmount > 0) {
        $prorationInvoiceId = generate_recurring_proration_invoice(
            $pdo, $contractId, $serviceId, $prorationAmount,
            $prorationDescription ?: 'Prorated charge for ' . $name,
            $appConfig,
            $prorationGenerationKey
        );
        pa_recurring_service_record_amendment(
            $pdo, $contractId, $serviceId, 'proration', 'approved', date('Y-m-d'),
            'Prorated charge created for ' . $name, null,
            ['invoice_id' => $prorationInvoiceId, 'subtotal' => $prorationAmount],
            $addendumUrl, $userId
        );
    }

    pa_recurring_service_sync_contract_next_date($pdo, $contractId);
    $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($storedAddendumPath !== null && is_file($storedAddendumPath)) {
        @unlink($storedAddendumPath);
    }
    header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode($e->getMessage()));
    exit;
}

$sendResult = '';
if ($prorationInvoiceId !== null && $sendProration) {
    $sendResult = invoice_send_finalized($pdo, $prorationInvoiceId, $appConfig, 'amendment_proration') ? '&proration_sent=1' : '&proration_send_error=1';
}
$scheduledResult = '';
if (!$idempotentReplay && $approved && ($contract['status'] ?? '') === 'active' && $nextInvoiceDate <= date('Y-m-d')) {
    $freshStmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term"');
    $freshStmt->execute([$contractId]);
    $freshContract = $freshStmt->fetch(PDO::FETCH_ASSOC);
    if ($freshContract) {
        $scheduledInvoiceId = generate_recurring_invoice($pdo, $freshContract, $appConfig);
        if ($scheduledInvoiceId !== null) {
            $emailed = recurring_invoice_send_on_generate_if_enabled($pdo, $scheduledInvoiceId, $appConfig);
            $scheduledResult = '&service_invoice_generated=1' . ($emailed ? '&service_invoice_sent=1' : '');
        }
    }
}
$idempotentResult = $idempotentReplay ? '&idempotent=1' : '';
header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_saved=1' . $sendResult . $scheduledResult . $idempotentResult . '#recurring-services');
exit;
