<?php
// src/controllers/clients_purge.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=client/clients-list&error=Invalid%20client');
  exit;
}

try {
  $projection=new App\Services\PortalProjectionMutationService();portal_projection_mutate($pdo,static fn():array=>$projection->lockedClientScopes($pdo,$id),static function()use($pdo,$id):void{$lock=$pdo->prepare('SELECT public_id FROM clients WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$lock->execute([$id]);$publicId=$lock->fetchColumn();if($publicId===false)return;api_v2_directory_record_delete($pdo,'client',(string)$publicId);$delete=$pdo->prepare('DELETE FROM clients WHERE id=?');$delete->execute([$id]);if($delete->rowCount()!==1)throw new DomainException('Client changed while preparing deletion.');},static fn():array=>[]);
  header('Location: /?page=client/clients-list&deleted=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=client/clients-edit&id='.$id.'&error=Delete%20failed');
  exit;
}
