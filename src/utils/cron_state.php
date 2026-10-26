<?php
// src/utils/cron_state.php
// Shared helpers for recording cron run status and looking up catch-up windows.

function cron_state_now(): string {
    return date('Y-m-d H:i:s');
}

function cron_state_ensure_schema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cron_job_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(100) NOT NULL,
                last_run DATETIME NULL,
                status ENUM('running','completed','failed','success') NOT NULL DEFAULT 'running',
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                result TEXT NULL,
                error_message TEXT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cron_job_name (job_name),
                INDEX idx_job_name (job_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $columns = [
            'last_run' => 'DATETIME NULL AFTER job_name',
            'started_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status',
            'completed_at' => 'TIMESTAMP NULL AFTER started_at',
            'result' => 'TEXT NULL AFTER completed_at',
            'error_message' => 'TEXT NULL AFTER result',
            'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER error_message',
        ];
        $check = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
             LIMIT 1'
        );
        foreach ($columns as $name => $definition) {
            $check->execute(['cron_job_runs', $name]);
            if ($check->fetchColumn() === false) {
                $pdo->exec("ALTER TABLE cron_job_runs ADD COLUMN {$name} {$definition}");
            }
        }

        try {
            $pdo->exec("ALTER TABLE cron_job_runs MODIFY COLUMN status ENUM('running','completed','failed','success') NOT NULL DEFAULT 'running'");
        } catch (Throwable $ignored) {
        }

        $index = $pdo->prepare(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND non_unique = 0
             LIMIT 1'
        );
        $index->execute(['cron_job_runs', 'job_name']);
        if ($index->fetchColumn() === false) {
            $pdo->exec('ALTER TABLE cron_job_runs ADD UNIQUE KEY uq_cron_job_name (job_name)');
        }

        $jobs = [
            'rotate_logs',
            'generate_recurring_invoices',
            'generate_recurring_expenses',
            'backup_database',
            'auto_terminate_contracts',
            'link_expiration_checker',
            'daily_link_resolver',
            'process_audit_schedules',
            'send_invoice_reminders',
            'process_invoice_notifications',
            'process_autopay_collections',
            'process_workforce_deadlines',
            'stripe_reconciliation',
            'sync_merchant_rate',
            'managed_delivery_intents',
        ];
        if (filter_var(getenv('NOTIFICATION_RELAY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            $jobs[] = 'process_notification_relay';
        }
        $seed = $pdo->prepare('
            INSERT INTO cron_job_runs (job_name, last_run, status)
            SELECT ?, NULL, "success"
            WHERE NOT EXISTS (SELECT 1 FROM cron_job_runs WHERE job_name = ?)
        ');
        foreach ($jobs as $job) {
            $seed->execute([$job, $job]);
        }
    } catch (Throwable $e) {
        @error_log('[cron_state] Failed to ensure cron_job_runs schema: ' . $e->getMessage());
    }

    $ensured = true;
}

function cron_state_last_run(PDO $pdo, string $jobName): ?string {
    try {
        cron_state_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT last_run FROM cron_job_runs WHERE job_name = ?');
        $stmt->execute([$jobName]);
        $lastRun = $stmt->fetchColumn();
        return $lastRun ? (string)$lastRun : null;
    } catch (Throwable $e) {
        @error_log("[cron_state] Failed to read last_run for {$jobName}: " . $e->getMessage());
        return null;
    }
}

function cron_state_mark_success(PDO $pdo, string $jobName, ?string $result = null): void {
    try {
        cron_state_ensure_schema($pdo);
        $now = cron_state_now();
        $stmt = $pdo->prepare('
            INSERT INTO cron_job_runs (job_name, last_run, status, completed_at, result, error_message)
            VALUES (?, ?, "success", ?, ?, NULL)
            ON DUPLICATE KEY UPDATE
                last_run = VALUES(last_run),
                status = "success",
                completed_at = VALUES(completed_at),
                result = VALUES(result),
                error_message = NULL
        ');
        $stmt->execute([$jobName, $now, $now, $result]);
    } catch (Throwable $e) {
        @error_log("[cron_state] Failed to mark success for {$jobName}: " . $e->getMessage());
    }
}

function cron_state_mark_failure(PDO $pdo, string $jobName, Throwable $error): void {
    try {
        cron_state_ensure_schema($pdo);
        $message = substr($error->getMessage(), 0, 1000);
        $now = cron_state_now();
        $stmt = $pdo->prepare('
            INSERT INTO cron_job_runs (job_name, last_run, status, completed_at, error_message)
            VALUES (?, ?, "failed", ?, ?)
            ON DUPLICATE KEY UPDATE
                last_run = VALUES(last_run),
                status = "failed",
                completed_at = VALUES(completed_at),
                error_message = VALUES(error_message)
        ');
        $stmt->execute([$jobName, $now, $now, $message]);
    } catch (Throwable $e) {
        @error_log("[cron_state] Failed to mark failure for {$jobName}: " . $e->getMessage());
    }
}
