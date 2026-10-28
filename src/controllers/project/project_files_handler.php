<?php
// src/controllers/project/project_files_handler.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/upload_validator.php';
require_once __DIR__ . '/../../utils/project_files.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
csrf_verify_post_or_redirect('project/project-files');

$action = (string)($_POST['action'] ?? '');
$projectId = (int)($_POST['project_id'] ?? 0);
$redirect = '/?page=project/projects-details&id=' . $projectId . '#project-files';
$userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
$movedFilePath = null;

function project_files_redirect(int $projectId, string $param, string $message): never
{
    header('Location: /?page=project/projects-details&id=' . $projectId . '&' . $param . '=' . urlencode($message) . '#project-files');
    exit;
}

function project_files_owned_folder(PDO $pdo, int $projectId, int $folderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM project_file_folders WHERE id = ? AND project_id = ? LIMIT 1');
    $stmt->execute([$folderId, $projectId]);
    $folder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$folder) {
        project_files_redirect($projectId, 'file_error', 'Folder not found.');
    }
    return $folder;
}

function project_files_owned_file(PDO $pdo, int $projectId, int $fileId): array
{
    $stmt = $pdo->prepare('SELECT * FROM project_files WHERE id = ? AND project_id = ? LIMIT 1');
    $stmt->execute([$fileId, $projectId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        project_files_redirect($projectId, 'file_error', 'File not found.');
    }
    return $file;
}

try {
    if ($projectId <= 0) {
        header('Location: /?page=project/projects-list&error=' . urlencode('Invalid project.'));
        exit;
    }
    require_record_ownership($pdo, 'projects', $projectId);

    if ($action === 'create_folder') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            project_files_redirect($projectId, 'file_error', 'Folder name is required.');
        }
        $exists = $pdo->prepare('SELECT id FROM project_file_folders WHERE project_id = ? AND name = ? LIMIT 1');
        $exists->execute([$projectId, $name]);
        if ($exists->fetchColumn()) {
            project_files_redirect($projectId, 'file_error', 'A folder with that name already exists.');
        }
        $stmt = $pdo->prepare('INSERT INTO project_file_folders (project_id, name, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$projectId, $name, $userId]);
        $folderId = (int)$pdo->lastInsertId();
        $folderDir = project_files_folder_dir($projectId, $folderId);
        if (!is_dir($folderDir) && !@mkdir($folderDir, 0755, true)) {
            project_files_redirect($projectId, 'file_error', 'Folder was saved, but storage could not be created.');
        }
        project_files_redirect($projectId, 'file_msg', 'Folder created.');
    }

    if ($action === 'rename_folder') {
        $folderId = (int)($_POST['folder_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($folderId <= 0 || $name === '') {
            project_files_redirect($projectId, 'file_error', 'Folder name is required.');
        }
        project_files_owned_folder($pdo, $projectId, $folderId);
        $exists = $pdo->prepare('SELECT id FROM project_file_folders WHERE project_id = ? AND name = ? AND id <> ? LIMIT 1');
        $exists->execute([$projectId, $name, $folderId]);
        if ($exists->fetchColumn()) {
            project_files_redirect($projectId, 'file_error', 'A folder with that name already exists.');
        }
        $stmt = $pdo->prepare('UPDATE project_file_folders SET name = ? WHERE id = ? AND project_id = ?');
        $stmt->execute([$name, $folderId, $projectId]);
        project_files_redirect($projectId, 'file_msg', 'Folder renamed.');
    }

    if ($action === 'delete_folder') {
        $folderId = (int)($_POST['folder_id'] ?? 0);
        if ($folderId <= 0) {
            project_files_redirect($projectId, 'file_error', 'Invalid folder.');
        }
        project_files_owned_folder($pdo, $projectId, $folderId);
        $filesStmt = $pdo->prepare('SELECT file_path FROM project_files WHERE project_id = ? AND folder_id = ?');
        $filesStmt->execute([$projectId, $folderId]);
        $files = $filesStmt->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM project_file_folders WHERE id = ? AND project_id = ?')->execute([$folderId, $projectId]);
        foreach ($files as $dbPath) {
            $path = realpath(dirname(__DIR__, 2) . '/../' . ltrim((string)$dbPath, '/'));
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
        $folderDir = project_files_folder_dir($projectId, $folderId);
        if (is_dir($folderDir)) {
            @rmdir($folderDir);
        }
        project_files_redirect($projectId, 'file_msg', 'Folder deleted.');
    }

    if ($action === 'upload_file') {
        if (!$userId || !rate_limit_check($pdo, 'project_file_upload_user_' . $userId, 20, 3600, false)) {
            project_files_redirect($projectId, 'file_error', 'Upload limit reached. Please try again later.');
        }
        $folderId = (int)($_POST['folder_id'] ?? 0) ?: null;
        if ($folderId) {
            project_files_owned_folder($pdo, $projectId, $folderId);
        }
        if (!isset($_FILES['project_file']) || ($_FILES['project_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            project_files_redirect($projectId, 'file_error', 'Choose a file to upload.');
        }
        $file = $_FILES['project_file'];
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            project_files_redirect($projectId, 'file_error', 'Invalid upload.');
        }
        $fileSize = @filesize((string)$file['tmp_name']);
        if ($fileSize === false || $fileSize <= 0) {
            project_files_redirect($projectId, 'file_error', 'File is empty.');
        }
        if ($fileSize > PROJECT_FILE_MAX_BYTES) {
            project_files_redirect($projectId, 'file_error', 'Project files must be 250 MB or smaller.');
        }
        $scanError = scan_clamav((string)$file['tmp_name']);
        if ($scanError !== null) {
            project_files_redirect($projectId, 'file_error', $scanError);
        }

        $targetDir = project_files_folder_dir($projectId, $folderId);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            project_files_redirect($projectId, 'file_error', 'Could not create project upload folder.');
        }
        if (!is_writable($targetDir)) {
            @chmod($targetDir, 0755);
        }
        if (!is_writable($targetDir)) {
            project_files_redirect($projectId, 'file_error', 'Project upload folder is not writable.');
        }

        $originalName = basename((string)($file['name'] ?? 'project-file'));
        $storedName = project_files_safe_stored_name($originalName);
        $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
        $pdo->beginTransaction();
        project_files_assert_upload_quota($pdo, $projectId, $fileSize);
        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }
        $movedFilePath = $targetPath;
        $mimeType = project_files_detect_mime($targetPath, (string)($file['type'] ?? ''));
        $dbPath = project_files_db_path($projectId, $folderId, $storedName);

        $stmt = $pdo->prepare('
            INSERT INTO project_files
                (project_id, folder_id, original_name, display_name, stored_name, file_path, mime_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $projectId,
            $folderId,
            $originalName,
            $originalName,
            $storedName,
            $dbPath,
            $mimeType,
            $fileSize,
            $userId,
        ]);
        $pdo->commit();
        $movedFilePath = null;
        project_files_redirect($projectId, 'file_msg', 'File uploaded.');
    }

    if ($action === 'rename_file') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        if ($fileId <= 0 || $displayName === '') {
            project_files_redirect($projectId, 'file_error', 'File name is required.');
        }
        project_files_owned_file($pdo, $projectId, $fileId);
        $stmt = $pdo->prepare('UPDATE project_files SET display_name = ? WHERE id = ? AND project_id = ?');
        $stmt->execute([$displayName, $fileId, $projectId]);
        project_files_redirect($projectId, 'file_msg', 'File renamed.');
    }

    if ($action === 'delete_file') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if ($fileId <= 0) {
            project_files_redirect($projectId, 'file_error', 'Invalid file.');
        }
        $file = project_files_owned_file($pdo, $projectId, $fileId);
        $pdo->prepare('DELETE FROM project_files WHERE id = ? AND project_id = ?')->execute([$fileId, $projectId]);
        $path = realpath(dirname(__DIR__, 2) . '/../' . ltrim((string)$file['file_path'], '/'));
        if ($path && is_file($path)) {
            @unlink($path);
        }
        project_files_redirect($projectId, 'file_msg', 'File deleted.');
    }

    project_files_redirect($projectId, 'file_error', 'Invalid project file action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($movedFilePath !== null && is_file($movedFilePath)) {
        @unlink($movedFilePath);
    }
    @error_log('[project_files_handler] ' . $e->getMessage());
    project_files_redirect($projectId, 'file_error', $e instanceof LengthException ? $e->getMessage() : 'Project file action failed.');
}
