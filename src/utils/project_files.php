<?php

declare(strict_types=1);

function project_files_storage_root(): string
{
    return dirname(__DIR__) . '/uploads/projects';
}

function project_files_project_dir(int $projectId): string
{
    return project_files_storage_root() . '/' . $projectId;
}

function project_files_folder_dir(int $projectId, ?int $folderId): string
{
    $base = project_files_project_dir($projectId);
    return $folderId && $folderId > 0 ? $base . '/folder_' . $folderId : $base;
}

function project_files_db_path(int $projectId, ?int $folderId, string $storedName): string
{
    $folderPart = $folderId && $folderId > 0 ? '/folder_' . $folderId : '';
    return '/src/uploads/projects/' . $projectId . $folderPart . '/' . $storedName;
}

function project_files_safe_stored_name(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
    return 'project_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
}

function project_files_detect_mime(string $path, ?string $fallback = null): string
{
    if (is_file($path) && function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            if (PHP_VERSION_ID < 80500) {
                @finfo_close($finfo);
            }
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }
    return $fallback ?: 'application/octet-stream';
}

/** Only passive content types may render under the application's origin. */
function project_files_delivery_policy(string $detectedMime, bool $download): array
{
    $mime = strtolower(trim(explode(';', $detectedMime, 2)[0]));
    $safeInline = in_array($mime, [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'text/plain',
        'text/csv',
    ], true);

    return [
        'content_type' => $safeInline ? $mime : 'application/octet-stream',
        'disposition' => ($download || !$safeInline) ? 'attachment' : 'inline',
        'safe_inline' => $safeInline,
    ];
}

function project_files_kind(?string $mimeType, string $fileName): string
{
    $mimeType = strtolower((string)$mimeType);
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($mimeType === 'application/pdf' || $extension === 'pdf') {
        return 'pdf';
    }
    if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
        return 'image';
    }
    if (in_array($extension, ['doc', 'docx', 'odt', 'rtf'], true)) {
        return 'document';
    }
    if (in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true)) {
        return 'spreadsheet';
    }
    if (in_array($extension, ['ppt', 'pptx', 'odp'], true)) {
        return 'presentation';
    }
    if (in_array($extension, ['txt', 'md', 'json', 'xml'], true) || str_starts_with($mimeType, 'text/')) {
        return 'text';
    }
    return 'file';
}

function project_files_format_size(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
