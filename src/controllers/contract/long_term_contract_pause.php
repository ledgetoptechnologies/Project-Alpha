<?php
// src/controllers/contract/long_term_contract_pause.php
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
    
    if ($contract['status'] !== 'active') {
        throw new Exception('Only active contracts can be paused');
    }
    
    // Update contract status to paused
    $update = $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type="long_term"');
    $update->execute(['paused', $id]);
    pa_recurring_services_pause($pdo, $id);
    
    $pdo->commit();
    
    header('Location: /?page=contract/contracts-list&kind=recurring&paused=1');
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[long_term_contract_pause] Error: ' . $e->getMessage());
    header('Location: /?page=contract/contracts-list&kind=recurring&error=' . urlencode($e->getMessage()));
    exit;
}
