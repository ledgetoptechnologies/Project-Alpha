<?php
// src/controllers/clients_restore.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

$id = (int)($_POST['id'] ?? 0); // archived_clients.id
if ($id <= 0) {
  header('Location: /?page=client/archived-clients&error=Invalid%20request');
  exit;
}

$pdo->beginTransaction();
try {
  $restored=(new App\Services\ClientArchivePortalStateService())->consumeAndRestore(
    $pdo,
    $id,
    (int)($_SESSION['user']['id'] ?? 0)
  );
  $clientId=(int)$restored['client_id'];
  api_v2_directory_record($pdo, 'client', $clientId);

  $projection=new App\Services\PortalProjectionMutationService();
  $projection->afterMutation($pdo,$projection->clientScopes($pdo,$clientId));
  $projection->queueWorkspaceIds($pdo,$restored['affected_workspace_ids']);

  $pdo->commit();
  header('Location: /?page=client/clients-list&restored=1');
  exit;
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=client/archived-clients&error=Restore%20failed');
  exit;
}
