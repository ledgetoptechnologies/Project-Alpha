<?php
// src/controllers/contract/long_term_contract_activate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/recurring_billing.php';
require_once __DIR__ . '/../../utils/contract_billing_start.php';
require_once __DIR__ . '/../../utils/recurring_services.php';
require_once __DIR__ . '/../../utils/job_work_materialization.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/long-term-contracts-list&error=Invalid%20contract%20ID');
    exit;
}
require_record_ownership($pdo, 'contracts', $id);

try {
    $pdo->beginTransaction();

    // Get contract details
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term" FOR UPDATE');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception('Contract not found');
    }

    if ($contract['status'] !== 'pending') {
        throw new Exception('Only pending contracts can be activated');
    }

    if (empty($contract['signed_pdf_path'])) {
        throw new Exception('Upload signed contract first');
    }

    $nextInvoiceDate = pa_long_term_billing_start_date($pdo, $contract);

    // Update contract status to active
    $update = $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=? WHERE id=? AND contract_type="long_term"');
    $update->execute(['active', $nextInvoiceDate, $id]);
    pa_recurring_services_activate($pdo, $id, $nextInvoiceDate);
    catalog_plan_direct_contract($pdo, $id, (int)($_SESSION['user']['id'] ?? 0));

    $pdo->commit();

    // Reload contract so the helper sees the active state.
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term"');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generate the first invoice immediately if the contract is now due.
    if ($contract && $contract['status'] === 'active' && !empty($contract['next_invoice_date']) && $contract['next_invoice_date'] <= date('Y-m-d')) {
        try {
            $invoiceId = generate_recurring_invoice($pdo, $contract, $appConfig);
            recurring_invoice_send_on_generate_if_enabled($pdo, $invoiceId, $appConfig);
        } catch (Throwable $e) {
            @error_log('[long_term_contract_activate] First invoice generation failed: ' . $e->getMessage());
        }
    }

    header('Location: /?page=contract/long-term-contracts-list&activated=1');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[long_term_contract_activate] Error: ' . $e->getMessage());
    header('Location: /?page=contract/long-term-contracts-list&error=' . urlencode($e->getMessage()));
    exit;
}
