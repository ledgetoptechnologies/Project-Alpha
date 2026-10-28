<?php
// src/controllers/clients_purge.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../../utils/api_v2_directory_management.php';
require_once __DIR__ . '/../../utils/client_record_authorization.php';
if (api_v2_directory_management_guard($pdo,'client','delete')) { header('Location: /?page=client/clients-list&directory_managed=1'); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=client/clients-list&error=Invalid%20client');
  exit;
}

try {
$projection = new App\Services\PortalProjectionMutationService();
portal_projection_mutate(
  $pdo,
  static fn(): array => $projection->lockedClientScopes($pdo, $id),
  static function () use ($pdo, $id): void {
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $lock = $pdo->prepare('SELECT public_id, created_by FROM clients WHERE id=?' . $suffix);
    $lock->execute([$id]);
    $client = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$client) return;
    if (!pa_client_record_mutation_allowed($pdo, (int)($_SESSION['user']['id'] ?? 0), $client['created_by'] ?? null)) {
      throw new DomainException('You do not have access to purge this client.');
    }
    api_v2_directory_record_delete($pdo, 'client', (string)$client['public_id']);
    $delete = $pdo->prepare('DELETE FROM clients WHERE id=?');
    $delete->execute([$id]);
    if ($delete->rowCount() !== 1) throw new DomainException('Client changed while preparing deletion.');
  },
  static fn(): array => [],
  false,
  static fn() => api_v2_directory_management_acquire_shared_gate($pdo)
);
  header('Location: /?page=client/clients-list&deleted=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=client/clients-edit&id='.$id.'&error=Delete%20failed');
  exit;
}
