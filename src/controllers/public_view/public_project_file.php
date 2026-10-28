<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_files.php';
require_once __DIR__ . '/../../utils/public_project_links.php';

$token = (string)($_GET['token'] ?? '');
$fileId = (int)($_GET['id'] ?? 0);
$project = pa_project_public_resolve($pdo, $token);
if (!$project || !pa_project_public_is_unlocked($project, $token) || empty($project['public_project_can_view_documents']) || $fileId <= 0) {
    http_response_code(404);
    exit;
}

$file = pa_project_public_visible_files($pdo, (int)$project['id'], $fileId)[0] ?? null;
if (!$file) {
    http_response_code(404);
    exit;
}

$storageRoot = realpath(project_files_storage_root());
$path = realpath(dirname(__DIR__, 2) . '/../' . ltrim((string)$file['file_path'], '/'));
if (!$storageRoot || !$path || !is_file($path) || strpos($path, $storageRoot) !== 0) {
    http_response_code(404);
    exit;
}

$mimeType = project_files_detect_mime($path, (string)($file['mime_type'] ?? ''));
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
$fileName = (string)($file['display_name'] ?: $file['original_name'] ?: basename($path));
$delivery = project_files_delivery_policy($mimeType, $download);

header('Content-Type: ' . $delivery['content_type']);
header('X-Content-Type-Options: nosniff');
if (!$delivery['safe_inline']) {
    header("Content-Security-Policy: sandbox; default-src 'none'");
}
header('Content-Disposition: ' . $delivery['disposition'] . '; filename="' . str_replace('"', '', basename($fileName)) . '"');
header('Content-Length: ' . filesize($path));
@readfile($path);
exit;
