<?php
// src/controllers/clients_restore.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../../utils/api_v2_directory_management.php';
require_once __DIR__ . '/../../utils/client_record_authorization.php';
if (api_v2_directory_management_guard($pdo,'client','restore')) { header('Location: /?page=client/archived-clients&directory_managed=1'); exit; }

$id = (int)($_POST['id'] ?? 0); // archived_clients.id
if ($id <= 0) {
  header('Location: /?page=client/archived-clients&error=Invalid%20request');
  exit;
}

$pdo->beginTransaction();
try {
  api_v2_directory_management_acquire_shared_gate($pdo);
  $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
  $archiveStmt = $pdo->prepare('SELECT created_by FROM archived_clients WHERE id=?' . $suffix);
  $archiveStmt->execute([$id]);
  $archive = $archiveStmt->fetch(PDO::FETCH_ASSOC);
  if (!$archive || !pa_client_record_mutation_allowed($pdo, (int)($_SESSION['user']['id'] ?? 0), $archive['created_by'] ?? null)) {
    throw new DomainException('You do not have access to restore this client.');
  }
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
