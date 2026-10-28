<?php
// src/views/pages/settings/backup.php
// Database backup settings page

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/cron_state.php';
require_once __DIR__ . '/../../../utils/acl.php';

// Get existing database-only and full backups.
$backupsDir = '/var/www/backups';
$backupMode = 'database';
$backupEncryptionEnabled = trim((string)(getenv('BACKUP_ENCRYPTION_KEY') ?: '')) !== '';

// Read retention from env (matches backup_database.php)
$retentionDays = (int)(getenv('BACKUP_RETENTION_DAYS') ?: '10');

// Read backup hour from app_config (default 2 = 2:30 AM in the configured PA timezone)
$backupHour = 2;
try {
    $cfgStmt = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = 'backup_hour'");
    $cfgStmt->execute();
    $cfgRow = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    if ($cfgRow) $backupHour = (int)$cfgRow['config_value'];
} catch (Exception $e) {}

try {
    $modeStmt = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='backup_mode'");
    $modeStmt->execute();
    $backupMode = $modeStmt->fetchColumn() === 'full' ? 'full' : 'database';
} catch (Throwable $e) {}
$backupTimezone = (string)($appConfig['timezone'] ?? date_default_timezone_get() ?: 'UTC');
if (!in_array($backupTimezone, DateTimeZone::listIdentifiers(), true)) {
    $backupTimezone = 'UTC';
}
$backupTimeZoneObj = new DateTimeZone($backupTimezone);

// Also check if retention is overridden in app_config
try {
    $cfgStmt2 = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = 'backup_retention_days'");
    $cfgStmt2->execute();
    $cfgRow2 = $cfgStmt2->fetch(PDO::FETCH_ASSOC);
    if ($cfgRow2) $retentionDays = (int)$cfgRow2['config_value'];
} catch (Exception $e) {}
$retentionLabel = $retentionDays > 0 ? "{$retentionDays} daily backups" : 'Unlimited (retention disabled)';

$backups = [];
if (is_dir($backupsDir)) {
    // Check daily/ subdirectory first (where the backup script writes)
    $searchDirs = [
        $backupsDir . '/daily',
        $backupsDir . '/weekly',
        $backupsDir . '/monthly',
    ];
    foreach ($searchDirs as $searchDir) {
        if (!is_dir($searchDir)) continue;
        $files = array_merge(glob($searchDir . '/*.sql.gz') ?: [], glob($searchDir . '/*.zip') ?: []);
        foreach ($files as $file) {
            if (is_file($file)) {
                $isZip = str_ends_with(strtolower($file), '.zip');
                $isEncrypted = false;
                if ($isZip) {
                    $archive = new ZipArchive();
                    if ($archive->open($file) === true) {
                        $isEncrypted = $archive->getFromName('manifest.json') === false;
                        $archive->close();
                    }
                }
                $backups[] = [
                    'file' => basename($file),
                    'path' => $file,
                    'relative' => basename($searchDir) . '/' . basename($file),
                    'size' => round(filesize($file) / 1024, 1),
                    'created' => date('Y-m-d H:i:s', filemtime($file)),
                    'mode' => str_ends_with(strtolower($file), '.full.zip') ? 'Full' : 'Database',
                    'encrypted' => $isEncrypted,
                ];
            }
        }
    }
}

// Sort by newest first
usort($backups, function($a, $b) {
    return strtotime($b['created']) - strtotime($a['created']);
});

// Check last backup
$lastBackup = isset($backups[0]) ? $backups[0]['created'] : 'Never';
$backupCount = count($backups);
$databaseName = getenv('MYSQL_DATABASE') ?: 'project_alpha';
$todayBackupFiles = [];
if (is_dir($backupsDir . '/daily')) {
    $todayPrefix = $backupsDir . '/daily/' . $databaseName . '_' . date('Y-m-d') . '_';
    $todayBackupFiles = array_merge(
        glob($todayPrefix . '*.sql.gz') ?: [],
        glob($todayPrefix . '*.zip') ?: []
    );
}
$todayBackupExists = !empty($todayBackupFiles);

$cronStatus = null;
$cronStatusError = '';
try {
    cron_state_ensure_schema($pdo);
    $cronStmt = $pdo->prepare('SELECT job_name, last_run, status, started_at, completed_at, result, error_message, updated_at FROM cron_job_runs WHERE job_name = ? LIMIT 1');
    $cronStmt->execute(['backup_database']);
    $cronStatus = $cronStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $cronStatusError = 'Cron status unavailable: ' . $e->getMessage();
}

$nowLocal = new DateTimeImmutable('now', $backupTimeZoneObj);
$scheduledTodayLocal = $nowLocal->setTime($backupHour, 30);
if (!$todayBackupExists && $nowLocal >= $scheduledTodayLocal) {
    $nextCronCheck = ((int)$nowLocal->format('i') < 30) ? $nowLocal : $nowLocal->modify('+1 hour');
    $nextExpectedLocal = $nextCronCheck->setTime((int)$nextCronCheck->format('G'), 30);
} else {
    $nextExpectedLocal = $scheduledTodayLocal;
}
if ($nextExpectedLocal <= $nowLocal && $todayBackupExists) {
    $nextExpectedLocal = $nextExpectedLocal->modify('+1 day');
}
$nextExpectedUtc = $nextExpectedLocal->setTimezone(new DateTimeZone('UTC'));

$backupDirChecks = [];
foreach ([
    'Backup root' => $backupsDir,
    'Daily backups' => $backupsDir . '/daily',
    'Weekly backups' => $backupsDir . '/weekly',
    'Monthly backups' => $backupsDir . '/monthly',
] as $label => $dir) {
    $backupDirChecks[] = [
        'label' => $label,
        'path' => $dir,
        'exists' => is_dir($dir),
        'readable' => is_dir($dir) && is_readable($dir),
        'writable' => is_dir($dir) && is_writable($dir),
    ];
}

$zeroBackupDiagnostics = [];
if ($backupCount === 0) {
    if (!is_dir($backupsDir)) {
        $zeroBackupDiagnostics[] = 'Backup root does not exist: ' . $backupsDir;
    } elseif (!is_readable($backupsDir)) {
        $zeroBackupDiagnostics[] = 'Backup root is not readable by the web process.';
    }
    foreach ($backupDirChecks as $check) {
        if (!$check['exists']) {
            $zeroBackupDiagnostics[] = $check['label'] . ' directory is missing.';
        } elseif (!$check['writable']) {
            $zeroBackupDiagnostics[] = $check['label'] . ' directory is not writable.';
        }
    }
    if ($cronStatus === null) {
        $zeroBackupDiagnostics[] = 'No cron_job_runs row has been recorded for backup_database yet.';
    } elseif (($cronStatus['status'] ?? '') === 'failed') {
        $message = trim((string)($cronStatus['error_message'] ?? ''));
        $zeroBackupDiagnostics[] = 'Last recorded backup cron run failed' . ($message !== '' ? ': ' . $message : '.');
    } elseif (($cronStatus['status'] ?? '') === 'running') {
        $zeroBackupDiagnostics[] = 'The backup cron is currently marked running.';
    }
    if (!$todayBackupExists && $nowLocal >= $scheduledTodayLocal) {
        $zeroBackupDiagnostics[] = 'The configured backup time has passed today; cron will catch up on the next hourly check if the cron service is running.';
    }
    $zeroBackupDiagnostics[] = 'Next expected scheduled run: ' . $nextExpectedLocal->format('Y-m-d H:i') . ' ' . $backupTimezone . ' (' . $nextExpectedUtc->format('Y-m-d H:i') . ' UTC).';
}

// Handle flash messages
$flash = isset($_SESSION['flash_backup']) ? $_SESSION['flash_backup'] : null;
unset($_SESSION['flash_backup']);

?>

<div class="settings-section" id="settings-backup">
    <h3>Backups</h3>
    
    <?php if ($flash): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
        <?php echo htmlspecialchars($flash['message']); ?>
    </div>
    <?php endif; ?>

    <div class="backup-status-card">
        <div class="status-item">
            <span class="status-label">Last Backup:</span>
            <span class="status-value"><?php echo htmlspecialchars($lastBackup); ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Total Backups:</span>
            <span class="status-value"><?php echo $backupCount; ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Retention:</span>
            <span class="status-value"><?php echo htmlspecialchars($retentionLabel); ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Schedule:</span>
            <span class="status-value">Daily at <?php echo str_pad((string)$backupHour, 2, '0', STR_PAD_LEFT); ?>:30 <?php echo htmlspecialchars($backupTimezone); ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Next Expected Check:</span>
            <span class="status-value"><?php echo htmlspecialchars($nextExpectedLocal->format('Y-m-d H:i')); ?> <?php echo htmlspecialchars($backupTimezone); ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Encryption:</span>
            <span class="status-value"><?php echo $backupEncryptionEnabled ? 'AES-256 enabled' : 'Not configured'; ?></span>
        </div>
    </div>

    <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:1.5rem">
        <legend style="padding:0 8px;font-weight:600">Backup Diagnostics</legend>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-bottom:1rem">
            <div class="status-item">
                <span class="status-label">Cron Job:</span>
                <span class="status-value">backup_database</span>
            </div>
            <div class="status-item">
                <span class="status-label">Cron Status:</span>
                <span class="status-value"><?php echo htmlspecialchars($cronStatus['status'] ?? ($cronStatusError !== '' ? 'Unavailable' : 'Not recorded')); ?></span>
            </div>
            <div class="status-item">
                <span class="status-label">Last Cron Run:</span>
                <span class="status-value"><?php echo htmlspecialchars($cronStatus['last_run'] ?? 'Never'); ?></span>
            </div>
            <div class="status-item">
                <span class="status-label">Completed At:</span>
                <span class="status-value"><?php echo htmlspecialchars($cronStatus['completed_at'] ?? 'Not completed'); ?></span>
            </div>
        </div>
        <?php if ($cronStatusError !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($cronStatusError); ?></div>
        <?php endif; ?>
        <?php if ($cronStatus): ?>
            <div style="display:grid;gap:0.5rem;margin-bottom:1rem">
                <div><strong>Last result:</strong> <span class="text-muted"><?php echo htmlspecialchars(trim((string)($cronStatus['result'] ?? '')) ?: 'No result message recorded.'); ?></span></div>
                <div><strong>Last failure:</strong> <span class="text-muted"><?php echo htmlspecialchars(trim((string)($cronStatus['error_message'] ?? '')) ?: 'No failure recorded.'); ?></span></div>
            </div>
        <?php endif; ?>

        <div style="overflow-x:auto">
            <table class="backup-table">
                <thead>
                    <tr>
                        <th>Directory</th>
                        <th>Path</th>
                        <th>Exists</th>
                        <th>Readable</th>
                        <th>Writable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backupDirChecks as $check): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($check['label']); ?></td>
                        <td><code><?php echo htmlspecialchars($check['path']); ?></code></td>
                        <td><?php echo $check['exists'] ? 'Yes' : 'No'; ?></td>
                        <td><?php echo $check['readable'] ? 'Yes' : 'No'; ?></td>
                        <td><?php echo $check['writable'] ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </fieldset>

    <hr>

    <h4>Backup Configuration</h4>
    <form method="POST" action="/?page=settings-backup" class="backup-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="update_settings">

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1.5rem; padding:1rem; background:#f8f9fa; border-radius:8px; margin-bottom:1rem;">
            <div class="form-group" style="margin:0;">
                <label for="retention_days" style="font-size:0.85rem; color:#6c757d; margin-bottom:0.35rem; display:block;">Retention (days)</label>
                <input type="number" name="retention_days" id="retention_days"
                       value="<?php echo htmlspecialchars($retentionDays); ?>"
                       min="0" max="365" class="input" style="width:100%; padding:0.5rem; border:1px solid #ddd; border-radius:6px; font-size:0.95rem;">
                <span class="help-text" style="display:block; margin-top:0.35rem; font-size:0.8rem;">Keep this many daily backups. 0 = keep all (no auto-cleanup).</span>
            </div>

            <div class="form-group" style="margin:0;">
                <label for="backup_mode" style="font-size:0.85rem; color:#6c757d; margin-bottom:0.35rem; display:block;">Backup Mode</label>
                <select name="backup_mode" id="backup_mode" class="input" style="width:100%; padding:0.5rem; border:1px solid #ddd; border-radius:6px; font-size:0.95rem;">
                    <option value="database" <?php echo $backupMode === 'database' ? 'selected' : ''; ?>>Database only</option>
                    <option value="full" <?php echo $backupMode === 'full' ? 'selected' : ''; ?>>Full: database, uploads, and configuration</option>
                </select>
                <span class="help-text" style="display:block; margin-top:0.35rem; font-size:0.8rem;">Full backups require BACKUP_ENCRYPTION_KEY on both web and cron services. Keep the key separately for restores.</span>
            </div>

            <div class="form-group" style="margin:0;">
                <label for="backup_hour" style="font-size:0.85rem; color:#6c757d; margin-bottom:0.35rem; display:block;">Backup Time (<?php echo htmlspecialchars($backupTimezone); ?>)</label>
                <select name="backup_hour" id="backup_hour" class="input" style="width:100%; padding:0.5rem; border:1px solid #ddd; border-radius:6px; font-size:0.95rem;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <option value="<?php echo $h; ?>" <?php echo ($h == $backupHour) ? 'selected' : ''; ?>>
                        <?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?>:30 <?php echo htmlspecialchars($backupTimezone); ?>
                    </option>
                    <?php endfor; ?>
                </select>
                <span class="help-text" style="display:block; margin-top:0.35rem; font-size:0.8rem;">The cron service checks hourly at :30 using the PA system timezone. If the configured time was missed, the next hourly check creates today's missing backup.</span>
            </div>
        </div>

        <div style="display:flex; gap:0.75rem; align-items:center;">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>

    <form method="POST" action="/?page=settings-backup" class="backup-form" style="display:flex;gap:0.75rem;align-items:center;">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="backup_now">
        <button type="submit" class="btn btn-primary">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;vertical-align:middle;margin-right:0.3rem;">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Backup Now
        </button>
        <span class="help-text" style="font-size:0.8rem;">Creates a new backup immediately.</span>
    </form>

    <hr>

    <?php if (acl_user_role($pdo, (int)($_SESSION['user']['id'] ?? 0)) === 'admin'): ?>
    <h4>Restore from Backup</h4>
    <form method="POST" action="/?page=settings-backup" class="backup-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="restore">
        
        <div class="form-group">
            <label>Select Backup File</label>
            <select name="backup_file" class="input">
                <option value="">-- Select a backup --</option>
                <?php foreach ($backups as $b): ?>
                <option value="<?php echo htmlspecialchars($b['relative']); ?>">
                    <?php echo htmlspecialchars($b['file']); ?> (<?php echo $b['size']; ?>KB, <?php echo htmlspecialchars($b['created']); ?><?php echo $b['encrypted'] ? ', encrypted' : ''; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Or upload a backup file</label>
            <input type="file" name="backup_upload" accept=".sql,.sql.gz,.zip" class="input">
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="confirm_restore" value="yes" required>
                I understand this will overwrite the current database
            </label>
        </div>
        
        <button type="submit" class="btn btn-danger">
            Restore Database
        </button>
        <span class="warning-text">Warning: This will overwrite all current data. Make a backup first!</span>
    </form>
    <?php endif; ?>

    <hr>

    <h4>Existing Backups</h4>
    <?php if (empty($backups)): ?>
        <div style="padding:1rem;border:1px solid #facc15;background:#fffbeb;border-radius:8px;color:#713f12;margin-bottom:1rem">
            <strong>No backups found.</strong>
            <ul style="margin:0.75rem 0 0 1.25rem;padding:0">
                <?php foreach ($zeroBackupDiagnostics as $diagnostic): ?>
                    <li><?php echo htmlspecialchars($diagnostic); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <p class="muted">Run "Backup Now" after resolving directory or cron issues, or wait for the next scheduled backup.</p>
    <?php else: ?>
        <div class="pa-table-wrap">
            <table class="pa-table">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Mode</th>
                        <th>Encrypted</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($b['file']); ?></td>
                        <td><?php echo $b['size']; ?> KB</td>
                        <td><?php echo htmlspecialchars($b['created']); ?></td>
                        <td><?php echo htmlspecialchars($b['mode']); ?></td>
                        <td><?php echo $b['encrypted'] ? 'Yes' : 'No'; ?></td>
                        <td>
                            <a href="/?page=settings/backup-download&amp;file=<?php echo urlencode($b['relative']); ?>" class="btn btn-sm" data-skip-nav>Download</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.backup-status-card {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}
.status-item { display: flex; flex-direction: column; }
.status-label { font-size: 0.85rem; color: #6c757d; margin-bottom: 0.25rem; }
.status-value { font-weight: 600; font-size: 1rem; }
.backup-form { margin: 1rem 0; }
.backup-form .btn { display: inline-flex; align-items: center; gap: 0.5rem; margin-right: 1rem; }
.backup-form .icon { width: 18px; height: 18px; }
.help-text { color: #6c757d; font-size: 0.9rem; }
.warning-text { color: #dc3545; font-size: 0.9rem; font-weight: 500; }
.form-group { margin-bottom: 1rem; }
.form-control { width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; margin-top: 0.25rem; }
.btn-danger { background: #dc3545; color: white; }
.btn-danger:hover { background: #c82333; }
.backup-table { width: 100%; border-collapse: collapse; }
.backup-table th, .backup-table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #e9ecef; }
.backup-table th { font-weight: 600; background: #f8f9fa; }
.badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500; }
.badge-daily { background: #d4edda; color: #155724; }
.badge-weekly { background: #fff3cd; color: #856404; }
.badge-monthly { background: #cce5ff; color: #004085; }
.btn-sm { padding: 0.25rem 0.5rem; font-size: 0.85rem; }
.btn-secondary { background: #6c757d; color: white; text-decoration: none; border-radius: 4px; }
.flash-success { background: #d4edda; color: #155724; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
.flash-error { background: #f8d7da; color: #721c24; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
.text-muted { color: #6c757d; }
</style>
