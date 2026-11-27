<?php
// src/controllers/contract/on_demand_contract_resume.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/contracts-list&kind=as_needed&error=Invalid%20ID');
    exit;
}

try {
    $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type="on_demand"')->execute(['active', $id]);
    header('Location: /?page=contract/contracts-list&kind=as_needed');
} catch (Throwable $e) {
    header('Location: /?page=contract/contracts-list&kind=as_needed&error=' . urlencode($e->getMessage()));
}
exit;
