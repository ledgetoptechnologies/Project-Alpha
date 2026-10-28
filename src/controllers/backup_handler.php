<?php
// src/controllers/backup_handler.php
// Handle backup and restore operations from Settings page

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/backup_archive.php';
require_once __DIR__ . '/../utils/acl.php';

if (acl_user_role($pdo, (int)($_SESSION['user']['id'] ?? 0)) !== 'admin') {
    http_response_code(403);
    exit('Only an installation administrator can manage backups.');
}

$action = $_POST['action'] ?? '';
$backupsDir = '/var/www/backups';
$settingsUrl = '/?page=settings&tab=backup';

function backup_run_php_script(string $script): array
{
    if (basename($script) === 'backup_database.php') {
        return backup_run_database_inline($script);
    }

    if (!function_exists('proc_open')) {
        return [['Could not start backup process because proc_open is disabled.'], 1];
    }

    $output = [];
    $returnCode = 1;
    $process = proc_open(
        [PHP_BINARY, $script],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return [['Could not start backup process.'], 1];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);
    $combined = trim((string)$stdout . "\n" . (string)$stderr);
    if ($combined !== '') {
        $output = preg_split('/\r\n|\r|\n/', $combined) ?: [];
    }

    return [$output, $returnCode];
}

function backup_run_database_inline(string $script): array
{
    if (!is_file($script)) {
        return [['Backup script was not found.'], 1];
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    ignore_user_abort(true);

    $output = [];
    $returnCode = 1;
    ob_start();
    try {
        require_once $script;
        if (!function_exists('backup_database_run')) {
            throw new RuntimeException('Backup runner is unavailable.');
        }
        $returnCode = backup_database_run([]);
        $captured = trim((string)ob_get_clean());
        if ($captured !== '') {
            $output = preg_split('/\r\n|\r|\n/', $captured) ?: [];
        }
    } catch (Throwable $e) {
        $captured = trim((string)ob_get_clean());
        if ($captured !== '') {
            $output = preg_split('/\r\n|\r|\n/', $captured) ?: [];
        }
        $output[] = $e->getMessage();
        $returnCode = 1;
    }

    return [$output, $returnCode];
}

function backup_restore_database_stream(string $databaseSource, string $host, string $port, string $user, string $password, string $database): array
{
    if (!function_exists('proc_open')) {
        return [['Could not start mysql restore process because proc_open is disabled.'], 1];
    }

    $command = ['mysql', '-h', $host, '-P', $port, '-u', $user, $database];
    $previousPassword = getenv('MYSQL_PWD');
    if ($password !== '') {
        putenv('MYSQL_PWD=' . $password);
    } else {
        putenv('MYSQL_PWD');
    }

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        if ($previousPassword === false) {
            putenv('MYSQL_PWD');
        } else {
            putenv('MYSQL_PWD=' . $previousPassword);
        }
        return [['Could not start mysql restore process.'], 1];
    }

    $returnCode = 1;
    try {
        $isGzip = str_ends_with(strtolower($databaseSource), '.gz');
        $input = $isGzip ? gzopen($databaseSource, 'rb') : fopen($databaseSource, 'rb');
        if (!$input) {
            throw new RuntimeException('Could not open database backup for restore.');
        }

        try {
            while ($isGzip ? !gzeof($input) : !feof($input)) {
                $chunk = $isGzip ? gzread($input, 1024 * 1024) : fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read database backup during restore.');
                }
                if ($chunk === '') {
                    continue;
                }
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = fwrite($pipes[0], substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Could not stream database backup to mysql.');
                    }
                    $offset += $written;
                }
            }
        } finally {
            $isGzip ? gzclose($input) : fclose($input);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);
        $combined = trim((string)$stdout . "\n" . (string)$stderr);
        return [$combined !== '' ? (preg_split('/\r\n|\r|\n/', $combined) ?: []) : [], $returnCode];
    } catch (Throwable $e) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
        return [[$e->getMessage()], $returnCode];
    } finally {
        if ($previousPassword === false) {
            putenv('MYSQL_PWD');
        } else {
            putenv('MYSQL_PWD=' . $previousPassword);
        }
    }
}

switch ($action) {
    case 'backup_now':
        $backupScript = __DIR__ . '/../cron/backup_database.php';
        [$output, $returnCode] = backup_run_php_script($backupScript);
        if ($returnCode === 0) {
            $_SESSION['flash_backup'] = [
                'type' => 'success',
                'message' => 'Backup completed successfully.'
            ];
        } else {
            $errorOutput = implode("\n", $output);
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Backup failed: ' . ($errorOutput ?: 'Unknown error (exit code ' . $returnCode . ')')
            ];
        }
        header('Location: ' . $settingsUrl);
        exit;

    case 'update_settings':
        $retentionDays = (int)($_POST['retention_days'] ?? 10);
        $backupHour = (int)($_POST['backup_hour'] ?? 2);
        $backupMode = ($_POST['backup_mode'] ?? 'database') === 'full' ? 'full' : 'database';
        if ($backupMode === 'full' && backup_archive_key() === '') {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Set BACKUP_ENCRYPTION_KEY on web and cron before enabling full backups.',
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }
        $backupTimezone = (string)($appConfig['timezone'] ?? date_default_timezone_get() ?: 'UTC');
        if (!in_array($backupTimezone, DateTimeZone::listIdentifiers(), true)) {
            $backupTimezone = 'UTC';
        }

        if ($retentionDays < 0 || $retentionDays > 365) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Retention days must be between 0 and 365.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }
        if ($backupHour < 0 || $backupHour > 23) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Backup hour must be between 0 and 23.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }

        // Save to app_config
        $setting = $pdo->prepare('INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)');
        $setting->execute(['backup_retention_days', (string)$retentionDays]);
        $setting->execute(['backup_hour', (string)$backupHour]);
        $setting->execute(['backup_mode', $backupMode]);

        $_SESSION['flash_backup'] = [
            'type' => 'success',
            'message' => "Backup settings saved. Mode: {$backupMode}; retention: {$retentionDays} backups; schedule check: " . str_pad($backupHour, 2, '0', STR_PAD_LEFT) . ":30 {$backupTimezone}."
        ];
        header('Location: ' . $settingsUrl);
        exit;

    case 'restore':
        if (acl_user_role($pdo, (int)($_SESSION['user']['id'] ?? 0)) !== 'admin') {
            http_response_code(403);
            exit('Only an installation administrator can restore a backup.');
        }
        $backupFile = $_POST['backup_file'] ?? '';
        $uploadFile = $_FILES['backup_upload'] ?? [];
        $confirmed = ($_POST['confirm_restore'] ?? '') === 'yes';

        if (!$confirmed) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'You must confirm the restore operation.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }

        $sourceFile = '';
        $backupRoot = realpath($backupsDir);
        $relative = str_replace('\\', '/', (string)$backupFile);
        if ($backupRoot && $relative !== '' && !str_contains($relative, '..') && preg_match('#^(daily|weekly|monthly)/[A-Za-z0-9._-]+(?:\.sql\.gz|\.(?:db|full)\.zip)$#', $relative)) {
            $candidate = realpath($backupRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if ($candidate && is_file($candidate) && str_starts_with($candidate, $backupRoot . DIRECTORY_SEPARATOR)) {
                $sourceFile = $candidate;
            }
        } elseif (!empty($uploadFile['tmp_name']) && is_uploaded_file($uploadFile['tmp_name'])) {
            $name = strtolower((string)($uploadFile['name'] ?? ''));
            if (preg_match('/(?:\.sql(?:\.gz)?|\.(?:db|full)\.zip)$/', $name) && (int)($uploadFile['size'] ?? 0) <= 500 * 1024 * 1024) {
                $sourceFile = $uploadFile['tmp_name'];
            }
        }

        if (empty($sourceFile)) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'No backup file selected or uploaded.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }

        // Create emergency backup before restore
        $backupScript = __DIR__ . '/../cron/backup_database.php';
        [$backupOutput, $backupCode] = backup_run_php_script($backupScript);
        if ($backupCode !== 0) {
            $_SESSION['flash_backup'] = ['type' => 'error', 'message' => 'Restore stopped because the pre-restore backup failed.'];
            header('Location: ' . $settingsUrl);
            exit;
        }

        $restoreWorkspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pa_restore_' . bin2hex(random_bytes(8));
        if (!mkdir($restoreWorkspace, 0700, true)) {
            $_SESSION['flash_backup'] = ['type' => 'error', 'message' => 'Could not create a secure restore workspace.'];
            header('Location: ' . $settingsUrl);
            exit;
        }
        try {
            $restoreSource = backup_open_restore_source($sourceFile, $restoreWorkspace, backup_archive_key());
            $databaseSource = $restoreSource['database_file'];
        } catch (Throwable $e) {
            @rmdir($restoreWorkspace);
            $_SESSION['flash_backup'] = ['type' => 'error', 'message' => $e->getMessage()];
            header('Location: ' . $settingsUrl);
            exit;
        }
        
        // Apply restore
        $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
        $user = getenv('MYSQL_USER') ?: 'root';
        $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '';
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        [$output, $returnCode] = backup_restore_database_stream($databaseSource, $host, $port, $user, $pass, $db);

        if ($returnCode === 0 && !empty($restoreSource['full']) && $restoreSource['zip'] instanceof ZipArchive) {
            try {
                backup_restore_full_files($restoreSource['zip']);
            } catch (Throwable $e) {
                $returnCode = 1;
                $output[] = 'Database restored, but application files failed: ' . $e->getMessage();
            }
        }
        if ($restoreSource['zip'] instanceof ZipArchive) {
            $restoreSource['zip']->close();
        }
        if ($databaseSource !== $sourceFile) {
            @unlink($databaseSource);
        }
        @rmdir($restoreWorkspace);

        if ($returnCode === 0) {
            $_SESSION['flash_backup'] = [
                'type' => 'success',
                'message' => !empty($restoreSource['full'])
                    ? 'Database, uploads, and configuration restored successfully. An emergency backup was created first.'
                    : 'Database restored successfully. An emergency backup was created before restore.'
            ];
        } else {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Restore failed. Error: ' . implode(' ', $output)
            ];
        }
        header('Location: ' . $settingsUrl);
        exit;
}
