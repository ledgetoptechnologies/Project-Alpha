<?php
/**
 * Pure PHP database backup script (avoids SSL issues with mysqldump)
 * Creates timestamped SQL dump to /var/www/backups/
 * Retention: configurable daily, 4 weekly, 12 monthly
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/backup_archive.php';

function backup_database_configured_hour(PDO $pdo): int
{
    $backupHour = 2;
    try {
        $hourStmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key="backup_hour"');
        $hourStmt->execute();
        $configuredHour = $hourStmt->fetchColumn();
        if ($configuredHour !== false) {
            $backupHour = max(0, min(23, (int)$configuredHour));
        }
    } catch (Throwable $e) {
        // Use the safe default when settings are unavailable.
    }
    return $backupHour;
}

function backup_database_ensure_directories(array $directories): void
{
    foreach ($directories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create backup directory {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("Backup directory is not writable: {$directory}");
        }
    }
}

function backup_database_today_artifacts(string $dailyDir, string $databaseName): array
{
    $prefix = $dailyDir . '/' . $databaseName . '_' . date('Y-m-d') . '_';
    return array_merge(
        glob($prefix . '*.sql.gz') ?: [],
        glob($prefix . '*.zip') ?: []
    );
}

function backup_database_retention_days(PDO $pdo): int
{
    $retentionDays = (int)(getenv('BACKUP_RETENTION_DAYS') ?: '10');
    try {
        $cfgStmt = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = 'backup_retention_days'");
        $cfgStmt->execute();
        $cfgRow = $cfgStmt->fetch(PDO::FETCH_ASSOC);
        if ($cfgRow !== false) {
            $retentionDays = (int)$cfgRow['config_value'];
        }
    } catch (Throwable $e) {
        // Environment/default retention remains in effect.
    }
    return $retentionDays;
}

function backup_database_run(array $argv = []): int
{
    global $pdo, $appConfig;

    $jobName = 'backup_database';
    $appTimezone = date_default_timezone_get();
    $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $backupDir = '/var/www/backups';
    $dailyDir = $backupDir . '/daily';
    $weeklyDir = $backupDir . '/weekly';
    $monthlyDir = $backupDir . '/monthly';
    $scheduledRun = in_array('--scheduled', $argv, true);

    $filepath = '';
    $gz = null;
    $backupVerified = false;

    try {
        backup_database_ensure_directories([$backupDir, $dailyDir, $weeklyDir, $monthlyDir]);

        if ($scheduledRun) {
            $backupHour = backup_database_configured_hour($pdo);
            if (backup_database_today_artifacts($dailyDir, $db)) {
                cron_state_mark_success($pdo, $jobName, 'Scheduled backup already exists for today');
                return 0;
            }

            $currentHour = (int)date('G');
            if ($currentHour < $backupHour) {
                cron_state_mark_success($pdo, $jobName, sprintf(
                    'Waiting for configured backup window (%02d:30 %s)',
                    $backupHour,
                    (string)($appConfig['timezone'] ?? $appTimezone)
                ));
                return 0;
            }
        }

        $backupMode = 'database';
        try {
            $modeStmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key="backup_mode"');
            $modeStmt->execute();
            $configuredMode = (string)($modeStmt->fetchColumn() ?: 'database');
            $backupMode = $configuredMode === 'full' ? 'full' : 'database';
        } catch (Throwable $e) {
            // Database-only remains the safe default.
        }
        $encryptionKey = backup_archive_key();
        if ($backupMode === 'full' && $encryptionKey === '') {
            throw new RuntimeException('Full backups require BACKUP_ENCRYPTION_KEY on the backup worker.');
        }

        $date = date('Y-m-d_H-i-s');
        $filename = $db . '_' . $date . '.sql.gz';
        $filepath = $dailyDir . '/' . $filename;

        $gz = gzopen($filepath, 'wb9');
        if (!$gz) {
            throw new RuntimeException("Could not open {$filepath} for writing");
        }

        gzwrite($gz, "-- Project Alpha Database Backup\n");
        gzwrite($gz, "-- Generated: " . date('Y-m-d H:i:s') . " {$appTimezone}\n");
        gzwrite($gz, "-- Database: {$db}\n\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
        gzwrite($gz, "SET NAMES utf8mb4;\n\n");

        $tables = [];
        $stmt = $pdo->query('SHOW TABLES');
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        foreach ($tables as $table) {
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
            $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $create = $stmt->fetch(PDO::FETCH_ASSOC);
            gzwrite($gz, $create['Create Table'] . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $firstRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($firstRow !== false) {
                gzwrite($gz, "INSERT INTO `{$table}` VALUES\n");
                $first = true;
                $row = $firstRow;
                do {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                    }
                    if (!$first) {
                        gzwrite($gz, ",\n");
                    }
                    gzwrite($gz, '(' . implode(', ', $values) . ')');
                    $first = false;
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                } while ($row !== false);
                gzwrite($gz, ";\n\n");
            }
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        $gz = null;

        if (!file_exists($filepath) || filesize($filepath) < 100) {
            throw new RuntimeException('Backup file too small or missing');
        }

        if ($backupMode === 'full' || $encryptionKey !== '') {
            $archiveSuffix = $backupMode === 'full' ? '.full.zip' : '.db.zip';
            $archiveFilename = $db . '_' . $date . $archiveSuffix;
            $archivePath = $dailyDir . '/' . $archiveFilename;
            backup_create_archive($filepath, $archivePath, $backupMode === 'full', $encryptionKey);
            if (!is_file($archivePath) || filesize($archivePath) < 100) {
                @unlink($archivePath);
                throw new RuntimeException('Backup archive is missing or empty.');
            }
            @unlink($filepath);
            $filename = $archiveFilename;
            $filepath = $archivePath;
        }

        $backupVerified = true;
        $size = round(filesize($filepath) / 1024, 1);
        @error_log("[Backup] SUCCESS: {$filepath} ({$size}KB)");

        if (date('w') === '0') {
            $weeklyFile = $weeklyDir . '/' . $filename;
            if (copy($filepath, $weeklyFile)) {
                @error_log("[Backup] Weekly copy: {$weeklyFile}");
            } else {
                @error_log("[Backup] Weekly copy failed: {$weeklyFile}");
            }
        }

        if (date('j') === '1') {
            $monthlyFile = $monthlyDir . '/' . $filename;
            if (copy($filepath, $monthlyFile)) {
                @error_log("[Backup] Monthly copy: {$monthlyFile}");
            } else {
                @error_log("[Backup] Monthly copy failed: {$monthlyFile}");
            }
        }

        $retentionDays = backup_database_retention_days($pdo);
        if ($retentionDays > 0) {
            $files = array_merge(glob($dailyDir . '/*.sql.gz') ?: [], glob($dailyDir . '/*.zip') ?: []);
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            foreach (array_slice($files, $retentionDays) as $old) {
                @unlink($old);
                @error_log("[Backup] Removed old (retention={$retentionDays}d): {$old}");
            }
        } else {
            @error_log('[Backup] Retention disabled (BACKUP_RETENTION_DAYS=0), keeping all backups');
        }

        foreach ([$weeklyDir => 4, $monthlyDir => 12] as $dir => $keep) {
            $files = array_merge(glob($dir . '/*.sql.gz') ?: [], glob($dir . '/*.zip') ?: []);
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
                @error_log("[Backup] Removed old archive: {$old}");
            }
        }

        echo "Backup complete: {$filepath} ({$size}KB)\n";
        cron_state_mark_success($pdo, $jobName, "Created {$filepath} ({$size}KB)");
        return 0;
    } catch (Throwable $e) {
        if (is_resource($gz)) {
            gzclose($gz);
        }
        if (!$backupVerified && $filepath !== '' && is_file($filepath)) {
            @unlink($filepath);
        }
        @error_log('[Backup] FAILED: ' . $e->getMessage());
        cron_state_mark_failure($pdo, $jobName, $e);
        echo 'Backup failed: ' . $e->getMessage() . "\n";
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(backup_database_run($argv ?? []));
}
