<?php
// src/controllers/contract/long_term_contract_terminate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/recurring_services.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/contracts-list&kind=recurring&error=Invalid%20contract%20ID');
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
    
    if ($contract['status'] === 'completed' || $contract['status'] === 'cancelled') {
        throw new Exception('Contract is already terminated');
    }
    
    // Update contract status to completed and set end_date to today if not set
    $endDate = $contract['end_date'] ?: date('Y-m-d');
    $update = $pdo->prepare('UPDATE contracts SET status=?, end_date=?, next_invoice_date=NULL, completed_at=COALESCE(completed_at,NOW()) WHERE id=? AND contract_type="long_term"');
    $update->execute(['completed', $endDate, $id]);
    pa_recurring_services_end($pdo, $id, $endDate);
    
    $pdo->commit();
    
    header('Location: /?page=contract/contracts-list&kind=recurring&terminated=1');
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[long_term_contract_terminate] Error: ' . $e->getMessage());
    header('Location: /?page=contract/contracts-list&kind=recurring&error=' . urlencode($e->getMessage()));
    exit;
}
