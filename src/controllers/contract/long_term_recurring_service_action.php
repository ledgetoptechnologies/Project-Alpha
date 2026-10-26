<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/recurring_services.php';
require_once __DIR__ . '/../../utils/recurring_billing.php';

$contractId = (int)($_POST['contract_id'] ?? 0);
$serviceId = (int)($_POST['service_id'] ?? 0);
$action = strtolower(trim((string)($_POST['service_action'] ?? '')));
if ($contractId <= 0 || $serviceId <= 0 || !in_array($action, ['approve', 'pause', 'resume', 'end', 'approve_change', 'cancel_change'], true)) {
    header('Location: /?page=contract/long-term-contracts-list&error=' . urlencode('Invalid recurring service action.'));
    exit;
}
require_record_ownership($pdo, 'contracts', $contractId);

try {
    $pdo->beginTransaction();
    $contractLock = $pdo->prepare('SELECT id FROM contracts WHERE id=? FOR UPDATE');
    $contractLock->execute([$contractId]);
    $stmt = $pdo->prepare('
        SELECT s.*,c.status AS contract_status
        FROM contract_recurring_services s
        JOIN contracts c ON c.id=s.contract_id
        WHERE s.id=? AND s.contract_id=? AND c.contract_type="long_term"
        FOR UPDATE
    ');
    $stmt->execute([$serviceId, $contractId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) {
        throw new RuntimeException('Recurring service not found.');
    }

    $type = 'service_' . $action . ($action === 'end' ? 'ed' : 'd');
    $summaryVerb = ucfirst($action);
    $approval = (string)$service['approval_status'];
    $scheduledChange = null;
    if (in_array($action, ['approve_change','cancel_change'], true)) {
        $scheduledChange = pa_recurring_service_change_action($pdo, $service, (int)($_POST['change_id'] ?? 0), $action);
        $type = 'service_updated';
        $approval = $action === 'approve_change' ? 'approved' : 'rejected';
        $summaryVerb = $action === 'approve_change' ? 'Approved scheduled change for' : 'Cancelled scheduled change for';
    } elseif ($action === 'approve') {
        $status = ($service['contract_status'] ?? '') === 'active' ? 'active' : (($service['contract_status'] ?? '') === 'paused' ? 'paused' : 'pending');
        $pdo->prepare('UPDATE contract_recurring_services SET approval_status="approved",status=? WHERE id=?')->execute([$status, $serviceId]);
        $type = 'service_approved';
        $approval = 'approved';
    } elseif ($action === 'pause') {
        if (($service['status'] ?? '') !== 'active') throw new RuntimeException('Only an active service can be paused.');
        $pdo->prepare('UPDATE contract_recurring_services SET status="paused" WHERE id=?')->execute([$serviceId]);
        $type = 'service_paused';
    } elseif ($action === 'resume') {
        if (($service['status'] ?? '') !== 'paused' || ($service['approval_status'] ?? '') !== 'approved') throw new RuntimeException('Only an approved paused service can be resumed.');
        if (($service['contract_status'] ?? '') !== 'active') throw new RuntimeException('Resume the long-term contract before resuming an individual service.');
        $pdo->prepare('UPDATE contract_recurring_services SET status="active" WHERE id=?')->execute([$serviceId]);
        $type = 'service_resumed';
    } else {
        $pdo->prepare("UPDATE contract_recurring_service_changes SET state='cancelled' WHERE recurring_service_id=? AND state IN ('pending','approved')")->execute([$serviceId]);
        $pdo->prepare('UPDATE contract_recurring_services SET status="ended",effective_until=COALESCE(effective_until,CURDATE()),next_invoice_date=NULL WHERE id=?')->execute([$serviceId]);
        $type = 'service_ended';
    }

    $updatedStmt = $pdo->prepare('SELECT * FROM contract_recurring_services WHERE id=?');
    $updatedStmt->execute([$serviceId]);
    $updated = $updatedStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    pa_recurring_service_record_amendment(
        $pdo, $contractId, $serviceId, $type, $approval, $scheduledChange['effective_date'] ?? date('Y-m-d'),
        $summaryVerb . ' recurring service: ' . (string)$service['name'],
        $scheduledChange ? json_decode($scheduledChange['old_values'], true, 32, JSON_THROW_ON_ERROR) : pa_recurring_service_snapshot($service),
        $scheduledChange ? json_decode($scheduledChange['new_values'], true, 32, JSON_THROW_ON_ERROR) : pa_recurring_service_snapshot($updated), $scheduledChange['signed_document_path'] ?? null,
        (int)($_SESSION['user']['id'] ?? 0) ?: null
    );
    pa_recurring_service_sync_contract_next_date($pdo, $contractId);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_error=' . urlencode($e->getMessage()) . '#recurring-services');
    exit;
}

$generationResult = '';
if (in_array($action, ['approve','approve_change'], true) && ($service['contract_status'] ?? '') === 'active' && !empty($service['next_invoice_date']) && $service['next_invoice_date'] <= date('Y-m-d')) {
    $contractStmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term"');
    $contractStmt->execute([$contractId]);
    $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
    if ($contract) {
        $invoiceId = generate_recurring_invoice($pdo, $contract, $appConfig);
        if ($invoiceId !== null) {
            $sent = recurring_invoice_send_on_generate_if_enabled($pdo, $invoiceId, $appConfig);
            $generationResult = '&service_invoice_generated=1' . ($sent ? '&service_invoice_sent=1' : '');
        }
    }
}
header('Location: /?page=contract/long-term-contract-details&id=' . $contractId . '&service_updated=1' . $generationResult . '#recurring-services');
exit;
