<?php
// src/controllers/api_keys_create.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/api_keys_schema.php';

if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? 'user') !== 'admin')) {
  header('Location: /?page=api-keys&error=' . urlencode('Only admins can create API keys'));
  exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$allowed_ips = trim((string)($_POST['allowed_ips'] ?? '')) ?: null;
if ($name === '') {
  header('Location: /?page=api-keys&error=' . urlencode('Name is required'));
  exit;
}

try {
  pa_ensure_api_keys_schema($pdo);
  $prefix = substr(bin2hex(random_bytes(6)), 0, 12);
  $secret = 'pa_live_' . $prefix . '_' . bin2hex(random_bytes(32));
  $hash = hash('sha256', $secret);
  $st = $pdo->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, scopes, allowed_ips) VALUES (?,?,?,?,?)');
  $st->execute([$name, $prefix, $hash, 'full', $allowed_ips]);
  $_SESSION['flash_api_key'] = $secret;
  header('Location: /?page=api-keys&created=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=api-keys&error=' . urlencode('Failed to create key'));
  exit;
}
