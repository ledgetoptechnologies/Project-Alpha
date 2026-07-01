<?php

function pa_api_keys_table_has_column(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['api_keys', $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function pa_ensure_api_keys_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organization_id INT NULL,
            name VARCHAR(255) NOT NULL,
            key_prefix VARCHAR(32) NOT NULL,
            key_hash CHAR(64) NULL,
            scopes VARCHAR(1024) NULL,
            allowed_ips TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            INDEX idx_api_keys_prefix (key_prefix),
            INDEX idx_api_keys_revoked (revoked_at),
            INDEX idx_api_keys_org (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (pa_api_keys_table_has_column($pdo, 'item_name') && !pa_api_keys_table_has_column($pdo, 'name')) {
        try {
            $pdo->exec('ALTER TABLE api_keys CHANGE COLUMN item_name name VARCHAR(255) NOT NULL');
        } catch (Throwable $e) {
            @error_log('[ApiKeysSchema] Failed to rename item_name to name: ' . $e->getMessage());
        }
    }

    $columns = [
        'organization_id' => 'INT NULL AFTER id',
        'name' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER organization_id",
        'key_prefix' => "VARCHAR(32) NOT NULL DEFAULT '' AFTER name",
        'key_hash' => 'CHAR(64) NULL AFTER key_prefix',
        'scopes' => "VARCHAR(1024) NULL DEFAULT 'full' AFTER key_hash",
        'allowed_ips' => 'TEXT NULL AFTER scopes',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER allowed_ips',
        'last_used_at' => 'TIMESTAMP NULL AFTER created_at',
        'revoked_at' => 'TIMESTAMP NULL AFTER last_used_at',
    ];

    foreach ($columns as $column => $definition) {
        if (!pa_api_keys_table_has_column($pdo, $column)) {
            try {
                $pdo->exec("ALTER TABLE api_keys ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                @error_log('[ApiKeysSchema] Failed to add api_keys.' . $column . ': ' . $e->getMessage());
            }
        }
    }

    try {
        $pdo->exec('UPDATE api_keys SET scopes = COALESCE(NULLIF(scopes, ""), "full") WHERE scopes IS NULL OR scopes = ""');
    } catch (Throwable $e) {
        @error_log('[ApiKeysSchema] Failed to normalize api key scopes: ' . $e->getMessage());
    }

    foreach ([
        'CREATE UNIQUE INDEX uq_key_hash ON api_keys (key_hash)',
        'CREATE INDEX idx_api_keys_prefix ON api_keys (key_prefix)',
        'CREATE INDEX idx_api_keys_revoked ON api_keys (revoked_at)',
        'CREATE INDEX idx_api_keys_org ON api_keys (organization_id)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Index likely already exists under this or an older generated name.
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_usage (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            api_key_id INT NOT NULL,
            used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_api_usage_key_time (api_key_id, used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}
