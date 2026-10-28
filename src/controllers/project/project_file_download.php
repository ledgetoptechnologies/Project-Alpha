<?php
// src/controllers/project/project_file_download.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_files.php';

$fileId = (int)($_GET['id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM project_files WHERE id = ? LIMIT 1');
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) {
    http_response_code(404);
    exit;
}

$projectId = (int)$file['project_id'];
require_record_ownership($pdo, 'projects', $projectId);

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
