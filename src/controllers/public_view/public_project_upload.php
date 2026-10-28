<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/upload_validator.php';
require_once __DIR__ . '/../../utils/project_files.php';
require_once __DIR__ . '/../../utils/public_project_links.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$token = (string)($_POST['token'] ?? '');
$redirect = '/?page=public-project&token=' . rawurlencode($token);

if (!csrf_validate()) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Invalid request.'));
    exit;
}
if (!rate_limit_check($pdo, 'public_project_upload_' . hash('sha256', $token), 10, 60)) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Too many uploads. Please wait a minute and try again.'));
    exit;
}

$project = pa_project_public_resolve($pdo, $token);
if (!$project || !pa_project_public_is_unlocked($project, $token)) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Project access is not available.'));
    exit;
}
if (empty($project['public_project_can_upload'])) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Uploads are not enabled for this project link.'));
    exit;
}
if (!isset($_FILES['project_file'])) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Choose a file to upload.'));
    exit;
}

$projectId = (int)$project['id'];
$targetDir = project_files_folder_dir($projectId, null);
$fileSize = @filesize((string)($_FILES['project_file']['tmp_name'] ?? ''));
if ($fileSize === false || $fileSize <= 0 || $fileSize > 20 * 1024 * 1024) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Upload must be a nonempty file of 20 MB or less.'));
    exit;
}
$allowedMap = [
    'application/pdf' => 'pdf',
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'text/plain' => ['txt', 'csv', 'md'],
    'text/csv' => 'csv',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => ['xls', 'csv'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
];

$error = null;
$storedName = null;
try {
    $pdo->beginTransaction();
    project_files_assert_upload_quota($pdo, $projectId, $fileSize);
    $storedName = validate_and_store_upload(
        $_FILES['project_file'],
        $allowedMap,
        20 * 1024 * 1024,
        $targetDir,
        $error,
        [
            'reject_archives' => true,
            'max_image_pixels' => 25000000,
            'require_pdf_header' => true,
            'reject_pdf_active_content' => true,
        ]
    );
    if ($storedName === null) {
        throw new RuntimeException($error ?: 'Upload failed.');
    }

    $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
    $originalName = basename((string)($_FILES['project_file']['name'] ?? 'project-file'));
    $mimeType = project_files_detect_mime($targetPath, (string)($_FILES['project_file']['type'] ?? ''));
    $dbPath = project_files_db_path($projectId, null, $storedName);

    $stmt = $pdo->prepare('
        INSERT INTO project_files
            (project_id, folder_id, original_name, display_name, stored_name, file_path, mime_type, file_size, client_visible, uploaded_by)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, NULL)
    ');
    $stmt->execute([
        $projectId,
        $originalName,
        $originalName,
        $storedName,
        $dbPath,
        $mimeType,
        $fileSize,
    ]);
    $fileId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $uploadError) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($storedName !== null) {
        $failedPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($failedPath)) {
            @unlink($failedPath);
        }
    }
    @error_log('[public_project_upload] ' . $uploadError->getMessage());
    $message = $uploadError instanceof LengthException ? $uploadError->getMessage() : ($error ?: 'Upload failed.');
    header('Location: ' . $redirect . '&error=' . rawurlencode($message));
    exit;
}
$clientLabel = trim((string)($_POST['client_label'] ?? ''));
pa_project_public_log_event($pdo, $projectId, 'upload', null, $fileId, mb_substr($clientLabel, 0, 190));

header('Location: ' . $redirect . '&uploaded=1');
exit;
