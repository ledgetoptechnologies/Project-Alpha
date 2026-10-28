<?php
declare(strict_types=1);

const TAX_IMPORT_MAX_FILE_BYTES = 400 * 1024 * 1024;
const TAX_IMPORT_MAX_USER_BYTES = 1200 * 1024 * 1024;
const TAX_IMPORT_MAX_ACTIVE_UPLOADS = 3;
const TAX_IMPORT_CHUNK_TTL_SECONDS = 24 * 60 * 60;

/** @param array{count:int,bytes:int,current_bytes:int} $usage */
function tax_import_chunk_within_quota(array $usage, bool $newUpload, int $chunkIndex, int $chunkSize, int $fileSize): bool
{
    if ($fileSize <= 0 || $fileSize > TAX_IMPORT_MAX_FILE_BYTES || $chunkSize <= 0
        || ($newUpload && $usage['count'] >= TAX_IMPORT_MAX_ACTIVE_UPLOADS)) {
        return false;
    }
    $nextBytes = $chunkIndex === 0 ? $chunkSize : $usage['current_bytes'] + $chunkSize;
    return $nextBytes <= $fileSize
        && $usage['bytes'] - $usage['current_bytes'] + $nextBytes <= TAX_IMPORT_MAX_USER_BYTES;
}

function tax_import_chunk_root(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'project_alpha_tax_import_chunks';
}

function tax_import_chunk_cleanup_stale(string $root, int $now): void
{
    if (!is_dir($root)) {
        return;
    }
    foreach (new DirectoryIterator($root) as $entry) {
        if (!$entry->isDir() || $entry->isDot() || $entry->isLink()
            || !preg_match('/^[a-f0-9]{32}$/', $entry->getFilename())) {
            continue;
        }
        $dir = $entry->getPathname();
        $metaPath = $dir . DIRECTORY_SEPARATOR . 'meta.json';
        $meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
        $updatedAt = is_array($meta) ? (int)($meta['updated_at'] ?? 0) : $entry->getMTime();
        if ($updatedAt > $now - TAX_IMPORT_CHUNK_TTL_SECONDS) {
            continue;
        }
        foreach (['upload.part', 'source.csv', 'source.txt', 'meta.json'] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/** @return array{count:int,bytes:int,current_bytes:int} */
function tax_import_chunk_usage(string $root, int $userId, string $currentUploadId): array
{
    $count = 0;
    $bytes = 0;
    $currentBytes = 0;
    if (!is_dir($root)) {
        return ['count' => 0, 'bytes' => 0, 'current_bytes' => 0];
    }
    foreach (new DirectoryIterator($root) as $entry) {
        if (!$entry->isDir() || $entry->isDot() || $entry->isLink()
            || !preg_match('/^[a-f0-9]{32}$/', $entry->getFilename())) {
            continue;
        }
        $dir = $entry->getPathname();
        $metaPath = $dir . DIRECTORY_SEPARATOR . 'meta.json';
        $meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
        if (!is_array($meta) || (int)($meta['user_id'] ?? 0) !== $userId) {
            continue;
        }
        $count++;
        $size = 0;
        foreach (['upload.part', 'source.csv', 'source.txt'] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && !is_link($path)) {
                $size += (int)filesize($path);
            }
        }
        $bytes += $size;
        if ($entry->getFilename() === $currentUploadId) {
            $currentBytes = $size;
        }
    }
    return ['count' => $count, 'bytes' => $bytes, 'current_bytes' => $currentBytes];
}
