<?php
// src/controllers/contract/on_demand_contract_resume.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/on_demand_contract_status.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/on-demand-contracts-list&error=Invalid%20ID');
    exit;
}

try {
    pa_on_demand_contract_change_status($pdo, $id, (int)($_SESSION['user']['id'] ?? 0), 'paused', 'active');
    header('Location: /?page=contract/on-demand-contracts-list');
} catch (Throwable $e) {
    header('Location: /?page=contract/on-demand-contracts-list&error=' . urlencode($e->getMessage()));
}
exit;
