<?php
// src/controllers/contract/on_demand_contract_activate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/job_work_materialization.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/contracts-list&kind=as_needed&error=Invalid%20ID');
    exit;
}
require_record_ownership($pdo, 'contracts', $id);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="on_demand"');
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

    $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type="on_demand"')->execute(['active', $id]);
    catalog_plan_direct_contract($pdo, $id, (int)($_SESSION['user']['id'] ?? 0));
    $pdo->commit();
    header('Location: /?page=contract/contracts-list&kind=as_needed&activated=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: /?page=contract/contracts-list&kind=as_needed&error=' . urlencode($e->getMessage()));
}
exit;
