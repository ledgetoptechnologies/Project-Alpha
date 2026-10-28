<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

if (acl_user_role($pdo, (int)($_SESSION['user']['id'] ?? 0)) !== 'admin') {
    http_response_code(403);
    exit('Only an installation administrator can download backups.');
}

$root = realpath('/var/www/backups');
$relative = str_replace('\\', '/', (string)($_GET['file'] ?? ''));
if (!$root || $relative === '' || str_contains($relative, '..') || !preg_match('#^(daily|weekly|monthly)/[A-Za-z0-9._-]+(?:\.sql\.gz|\.(?:db|full)\.zip)$#', $relative)) {
    http_response_code(404);
    echo 'Backup not found.';
    exit;
}

$path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
if (!$path || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    echo 'Backup not found.';
    exit;
}

header('Content-Type: ' . (str_ends_with(strtolower($path), '.zip') ? 'application/zip' : 'application/gzip'));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
