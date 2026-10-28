<?php

function backup_archive_key(): string
{
    return trim((string)(getenv('BACKUP_ENCRYPTION_KEY') ?: ''));
}

function backup_add_tree(ZipArchive $zip, string $sourceRoot, string $archiveRoot, string $key = ''): void
{
    if (!is_dir($sourceRoot)) {
        return;
    }
    $sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceRoot) + 1));
        if ($archiveRoot === 'config' && preg_match('#^(logs|audits)/#', $relative)) {
            continue;
        }
        $entry = trim($archiveRoot, '/') . '/' . $relative;
        if (!$zip->addFile($file->getPathname(), $entry)) {
            throw new RuntimeException('Could not add ' . $entry . ' to backup archive.');
        }
        if ($key !== '' && !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $key)) {
            throw new RuntimeException('Could not encrypt backup entry ' . $entry . '.');
        }
    }
}

function backup_create_archive(string $databaseFile, string $archiveFile, bool $full, string $key = ''): void
{
    if ($full && trim($key) === '') {
        throw new RuntimeException('Full backups require BACKUP_ENCRYPTION_KEY.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archiveFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create backup archive.');
    }
    $open = true;
    try {
        if (!$zip->addFile($databaseFile, 'database.sql.gz')) {
            throw new RuntimeException('Could not add database dump to backup archive.');
        }
        if ($key !== '' && !$zip->setEncryptionName('database.sql.gz', ZipArchive::EM_AES_256, $key)) {
            throw new RuntimeException('Could not encrypt database backup.');
        }
        $manifest = json_encode([
            'format' => 1,
            'type' => $full ? 'full' : 'database',
            'created_at' => gmdate(DATE_ATOM),
            'encrypted' => $key !== '',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $zip->addFromString('manifest.json', $manifest ?: '{}');
        if ($key !== '') {
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $key);
        }
        if ($full) {
            backup_add_tree($zip, '/var/www/src/uploads', 'uploads', $key);
            backup_add_tree($zip, '/var/www/config', 'config', $key);
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not finalize backup archive.');
        }
        $open = false;
    } catch (Throwable $error) {
        if ($open) {
            @$zip->close();
        }
        @unlink($archiveFile);
        throw $error;
    }
}

/** @return array{database_file:string,zip:?ZipArchive,full:bool} */
function backup_open_restore_source(string $sourceFile, string $tempDir, string $key = ''): array
{
    if (!str_ends_with(strtolower($sourceFile), '.zip')) {
        return ['database_file' => $sourceFile, 'zip' => null, 'full' => false];
    }
    $zip = new ZipArchive();
    if ($zip->open($sourceFile) !== true) {
        throw new RuntimeException('The backup archive could not be opened.');
    }
    if ($key !== '') {
        $zip->setPassword($key);
    }
    $stream = $zip->getStream('database.sql.gz');
    if (!$stream) {
        $zip->close();
        throw new RuntimeException('The backup archive is invalid or its encryption key is incorrect.');
    }
    $databaseFile = $tempDir . DIRECTORY_SEPARATOR . 'database.sql.gz';
    $out = fopen($databaseFile, 'wb');
    if (!$out) {
        fclose($stream);
        $zip->close();
        throw new RuntimeException('Could not prepare the restore workspace.');
    }
    stream_copy_to_stream($stream, $out);
    fclose($stream);
    fclose($out);
    $full = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (str_starts_with($name, 'uploads/') || str_starts_with($name, 'config/')) {
            $full = true;
            break;
        }
    }
    return ['database_file' => $databaseFile, 'zip' => $zip, 'full' => $full];
}

function backup_restore_full_files(ZipArchive $zip): void
{
    $roots = ['uploads/' => '/var/www/src/uploads', 'config/' => '/var/www/config'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '../') || str_starts_with($name, '/')) {
            throw new RuntimeException('Unsafe path found in backup archive.');
        }
        foreach ($roots as $prefix => $targetRoot) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            if (str_ends_with($name, '/')) {
                continue 2;
            }
            $relative = substr($name, strlen($prefix));
            if ($prefix === 'config/' && preg_match('#^(logs|audits)/#', $relative)) {
                continue 2;
            }
            $target = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create restore directory.');
            }
            $input = $zip->getStream($name);
            $output = fopen($target, 'wb');
            if (!$input || !$output) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Could not restore ' . $name . '.');
            }
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            continue 2;
        }
    }
}
