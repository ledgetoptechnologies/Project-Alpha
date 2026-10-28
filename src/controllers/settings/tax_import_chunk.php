<?php

require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/tax_import_chunk_limits.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
$quotaLock = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }
    if (!csrf_validate()) {
        http_response_code(403);
        throw new RuntimeException('Invalid request (CSRF).');
    }
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(403);
        throw new RuntimeException('Sign in to upload tax import files.');
    }
    if (!rate_limit_check($pdo, 'tax_import_chunk_user_' . $userId, 180, 3600, false)) {
        http_response_code(429);
        throw new RuntimeException('Tax import upload rate limit reached.');
    }

    $field = (string)($_POST['field'] ?? '');
    $allowed = [
        'fips_file' => ['txt'],
        'rate_file' => ['csv'],
        'boundary_file' => ['csv'],
    ];
    if (!isset($allowed[$field])) {
        throw new RuntimeException('Invalid tax import file field.');
    }

    $uploadId = (string)($_POST['upload_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        throw new RuntimeException('Invalid upload token.');
    }

    $fileName = basename((string)($_POST['file_name'] ?? ''));
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileName === '' || !in_array($ext, $allowed[$field], true)) {
        throw new RuntimeException('Invalid file type for this tax import source.');
    }

    $chunkIndex = max(0, (int)($_POST['chunk_index'] ?? -1));
    $totalChunks = max(1, (int)($_POST['total_chunks'] ?? 0));
    $fileSize = max(0, (int)($_POST['file_size'] ?? 0));
    if ($chunkIndex >= $totalChunks || $totalChunks > 100 || $fileSize <= 0
        || $fileSize > TAX_IMPORT_MAX_FILE_BYTES) {
        throw new RuntimeException('Invalid upload chunk metadata.');
    }
    if (!isset($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload chunk was not received.');
    }
    if (!is_uploaded_file((string)$_FILES['chunk']['tmp_name'])) {
        throw new RuntimeException('Upload chunk could not be verified.');
    }

    $chunkSize = (int)($_FILES['chunk']['size'] ?? 0);
    if ($chunkSize <= 0 || $chunkSize > 12 * 1024 * 1024) {
        throw new RuntimeException('Upload chunk is too large.');
    }

    $root = tax_import_chunk_root();
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create tax import upload folder.');
    }
    $quotaLock = fopen($root . DIRECTORY_SEPARATOR . '.quota.lock', 'c');
    if (!$quotaLock || !flock($quotaLock, LOCK_EX)) {
        throw new RuntimeException('Could not lock tax import upload quota.');
    }
    tax_import_chunk_cleanup_stale($root, time());
    $dir = taxImportChunkDir($uploadId);
    if (is_link($dir)) {
        throw new RuntimeException('Invalid tax import upload folder.');
    }
    $usage = tax_import_chunk_usage($root, $userId, $uploadId);
    $existingMetaPath = $dir . DIRECTORY_SEPARATOR . 'meta.json';
    $existingMeta = is_file($existingMetaPath) ? taxImportReadChunkMeta($existingMetaPath) : null;
    if ($existingMeta !== null && ((int)($existingMeta['user_id'] ?? 0) !== $userId
        || ($existingMeta['session_hash'] ?? '') !== hash('sha256', session_id()))) {
        throw new RuntimeException('Upload token belongs to another session.');
    }
    if (!tax_import_chunk_within_quota($usage, $existingMeta === null, $chunkIndex, $chunkSize, $fileSize)) {
        throw new RuntimeException('Tax import upload quota exceeded.');
    }
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create tax import upload folder.');
    }

    $metaPath = $dir . DIRECTORY_SEPARATOR . 'meta.json';
    $partPath = $dir . DIRECTORY_SEPARATOR . 'upload.part';
    $finalPath = $dir . DIRECTORY_SEPARATOR . 'source.' . $ext;
    $sessionHash = hash('sha256', session_id());

    if ($chunkIndex === 0) {
        @unlink($partPath);
        @unlink($finalPath);
        $meta = [
            'field' => $field,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'extension' => $ext,
            'total_chunks' => $totalChunks,
            'session_hash' => $sessionHash,
            'user_id' => $userId,
            'received_chunks' => 0,
            'complete' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ];
    } else {
        $meta = taxImportReadChunkMeta($metaPath);
        if (($meta['session_hash'] ?? '') !== $sessionHash || ($meta['field'] ?? '') !== $field) {
            throw new RuntimeException('Upload chunk does not match the current tax import session.');
        }
        if ((int)($meta['total_chunks'] ?? 0) !== $totalChunks || (int)($meta['file_size'] ?? 0) !== $fileSize) {
            throw new RuntimeException('Upload chunk metadata changed.');
        }
        if ((int)($meta['user_id'] ?? 0) !== $userId || !empty($meta['complete'])
            || (int)($meta['received_chunks'] ?? -1) !== $chunkIndex) {
            throw new RuntimeException('Upload chunks must arrive once and in order.');
        }
    }
    if ($chunkIndex === 0 && file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES)) === false) {
        throw new RuntimeException('Could not record tax import upload metadata.');
    }

    $out = fopen($partPath, $chunkIndex === 0 ? 'wb' : 'ab');
    $in = fopen((string)$_FILES['chunk']['tmp_name'], 'rb');
    if (!$out || !$in) {
        throw new RuntimeException('Could not append upload chunk.');
    }
    $copied = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($copied !== $chunkSize) {
        throw new RuntimeException('Upload chunk could not be saved completely.');
    }

    $meta['updated_at'] = time();
    $meta['received_chunks'] = $chunkIndex + 1;
    $complete = $chunkIndex + 1 === $totalChunks;
    if ($complete) {
        clearstatcache(true, $partPath);
        if ((int)filesize($partPath) !== $fileSize) {
            throw new RuntimeException('Uploaded file size did not match the expected size.');
        }
        if (!rename($partPath, $finalPath)) {
            throw new RuntimeException('Could not finalize uploaded tax import file.');
        }
        $meta['complete'] = true;
        $meta['final_path'] = $finalPath;
    }
    if (file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        throw new RuntimeException('Could not record tax import upload progress.');
    }

    echo json_encode([
        'ok' => true,
        'complete' => $complete,
        'token' => $uploadId,
        'received_chunks' => $chunkIndex + 1,
        'total_chunks' => $totalChunks,
    ]);
} catch (Throwable $e) {
    @error_log('[tax-import-chunk] ' . $e->getMessage());
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
} finally {
    if (is_resource($quotaLock)) {
        flock($quotaLock, LOCK_UN);
        fclose($quotaLock);
    }
}

function taxImportChunkDir(string $uploadId): string
{
    return tax_import_chunk_root() . DIRECTORY_SEPARATOR . $uploadId;
}

function taxImportReadChunkMeta(string $path): array
{
    $json = is_readable($path) ? file_get_contents($path) : false;
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException('Upload chunk metadata was not found.');
    }
    return $data;
}
