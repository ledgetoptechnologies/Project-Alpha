<?php
// src/controllers/api_keys_revoke.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/api_keys_schema.php';

if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? 'user') !== 'admin')) {
  header('Location: /?page=api-keys&error=' . urlencode('Only admins can revoke API keys'));
  exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=api-keys&error=' . urlencode('Invalid key')); exit; }

try {
  pa_ensure_api_keys_schema($pdo);
  $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE id=?')->execute([$id]);
  header('Location: /?page=api-keys&revoked=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=api-keys&error=' . urlencode('Failed to revoke key'));
  exit;
}
