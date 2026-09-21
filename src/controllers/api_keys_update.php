<?php
// src/controllers/api_keys_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/api_keys_schema.php';
require_once __DIR__ . '/../utils/api_scopes.php';
require_once __DIR__ . '/../utils/api_v2_authorization_generation.php';
require_once __DIR__ . '/../utils/audit.php';

if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? 'user') !== 'admin')) {
  header('Location: /?page=api-keys&error=' . urlencode('Only admins can update API keys'));
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$allowedIps = trim((string)($_POST['allowed_ips'] ?? '')) ?: null;
$scopes = api_normalize_scopes($_POST['scopes'] ?? []);
$returnToEdit = (string)($_POST['return_to'] ?? '') === 'edit';
$redirectBase = $returnToEdit && $id > 0 ? '/?page=api-keys-edit&id=' . $id : '/?page=api-keys';

if ($id <= 0) {
  header('Location: /?page=api-keys&error=' . urlencode('Invalid key'));
  exit;
}
if ($name === '') {
  header('Location: ' . $redirectBase . '&error=' . urlencode('Name is required'));
  exit;
}
if (!$scopes) {
  header('Location: ' . $redirectBase . '&error=' . urlencode('Select at least one API scope'));
  exit;
}

try {
  pa_ensure_api_keys_schema($pdo);
  $pdo->beginTransaction();
  $hasV2Binding = api_v2_authorization_generation_column_exists($pdo, 'api_keys', 'api_v2_application_id');
  $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
  $key = $pdo->prepare('SELECT id,name,scopes,allowed_ips,revoked_at' . ($hasV2Binding ? ',api_v2_application_id' : '') . ' FROM api_keys WHERE id=?' . $lock);
  $key->execute([$id]);
  $current = $key->fetch(PDO::FETCH_ASSOC);
  if (!$current || $current['revoked_at'] !== null) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    header('Location: ' . $redirectBase . '&error=' . urlencode('API key was not updated'));
    exit;
  }
  $storedScopes = api_scopes_to_storage($scopes);
  $authorizationChanged = api_v2_key_authorization_changed($current['scopes'] ?? '', $current['allowed_ips'] ?? null, $storedScopes, $allowedIps);
  if (!hash_equals((string)$current['name'], $name) || $authorizationChanged) {
    $stmt = $pdo->prepare('UPDATE api_keys SET name=?,scopes=?,allowed_ips=? WHERE id=? AND revoked_at IS NULL');
    $stmt->execute([$name, $storedScopes, $allowedIps, $id]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('API key update was not applied.');
  }
  if ($authorizationChanged && $hasV2Binding && ($current['api_v2_application_id'] ?? null) !== null) {
    api_v2_advance_authorization_generation($pdo, (int)$current['api_v2_application_id']);
  }
  $pdo->commit();
  header('Location: ' . $redirectBase . '&updated=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  @error_log('[api_keys_update] Failed to update API key: ' . $e->getMessage());
  header('Location: ' . $redirectBase . '&error=' . urlencode('Failed to update key'));
  exit;
}
