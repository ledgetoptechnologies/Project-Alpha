<?php
// src/controllers/api_keys_revoke.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/api_keys_schema.php';
require_once __DIR__ . '/../utils/api_v2_authorization_generation.php';
require_once __DIR__ . '/../utils/audit.php';

if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? 'user') !== 'admin')) {
  header('Location: /?page=api-keys&error=' . urlencode('Only admins can revoke API keys'));
  exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=api-keys&error=' . urlencode('Invalid key')); exit; }

try {
  pa_ensure_api_keys_schema($pdo);
  $pdo->beginTransaction();
  $hasV2Binding = api_v2_authorization_generation_column_exists($pdo, 'api_keys', 'api_v2_application_id');
  $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
  $key = $pdo->prepare('SELECT id,revoked_at' . ($hasV2Binding ? ',api_v2_application_id' : '') . ' FROM api_keys WHERE id=?' . $lock);
  $key->execute([$id]);
  $current = $key->fetch(PDO::FETCH_ASSOC);
  if ($current && $current['revoked_at'] === null) {
    $revocation = $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE id=? AND revoked_at IS NULL');
    $revocation->execute([$id]);
    if ($revocation->rowCount() !== 1) throw new RuntimeException('API key revocation was not applied.');
    if ($hasV2Binding && ($current['api_v2_application_id'] ?? null) !== null) {
      api_v2_advance_authorization_generation($pdo, (int)$current['api_v2_application_id']);
    }
  }
  $pdo->commit();
  header('Location: /?page=api-keys&revoked=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  header('Location: /?page=api-keys&error=' . urlencode('Failed to revoke key'));
  exit;
}
