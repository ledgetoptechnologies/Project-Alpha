<?php
// src/controllers/contract/long_term_contract_start_billing.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/recurring_billing.php';
require_once __DIR__ . '/../../utils/recurring_services.php';
require_once __DIR__ . '/../../utils/contract_billing_start.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/long-term-contracts-list&error=Invalid%20contract%20ID');
    exit;
}
require_record_ownership($pdo, 'contracts', $id);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term" FOR UPDATE');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception('Contract not found');
    }
    if (empty($contract['signed_pdf_path'])) {
        throw new Exception('Upload signed contract first');
    }
    if (!pa_long_term_can_start_billing($pdo, $contract)) {
        throw new Exception('Billing is already scheduled or cannot be started for this contract status.');
    }

    $startDate = pa_long_term_billing_start_date($pdo, $contract);
    $update = $pdo->prepare('UPDATE contracts SET status="active", next_invoice_date=? WHERE id=? AND contract_type="long_term"');
    $update->execute([$startDate, $id]);
    pa_recurring_services_activate($pdo, $id, $startDate);

    $pdo->commit();

    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term"');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($contract && $contract['status'] === 'active' && !empty($contract['next_invoice_date']) && $contract['next_invoice_date'] <= date('Y-m-d')) {
        try {
            $invoiceId = generate_recurring_invoice($pdo, $contract, $appConfig);
            recurring_invoice_send_on_generate_if_enabled($pdo, $invoiceId, $appConfig);
            if ($invoiceId !== null) {
                header('Location: /?page=invoice/invoice-details&id=' . (int)$invoiceId . '&generated=1');
                exit;
            }
        } catch (Throwable $e) {
            @error_log('[long_term_contract_start_billing] First invoice generation failed: ' . $e->getMessage());
        }
    }

    header('Location: /?page=contract/long-term-contracts-list&billing_started=1');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[long_term_contract_start_billing] Error: ' . $e->getMessage());
    header('Location: /?page=contract/long-term-contracts-list&error=' . urlencode($e->getMessage()));
    exit;
}
