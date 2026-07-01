CREATE TABLE IF NOT EXISTS api_keys (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @api_keys_item_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'item_name'
);

SET @api_keys_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'name'
);

SET @sql := IF(
    @api_keys_item_name_exists = 1 AND @api_keys_name_exists = 0,
    'ALTER TABLE api_keys CHANGE COLUMN item_name name VARCHAR(255) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_organization_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'organization_id'
);

SET @sql := IF(
    @api_keys_organization_id_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN organization_id INT NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'name'
);

SET @sql := IF(
    @api_keys_name_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '''' AFTER organization_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_key_prefix_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'key_prefix'
);

SET @sql := IF(
    @api_keys_key_prefix_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN key_prefix VARCHAR(32) NOT NULL DEFAULT '''' AFTER name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_key_hash_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'key_hash'
);

SET @sql := IF(
    @api_keys_key_hash_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN key_hash CHAR(64) NULL AFTER key_prefix',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_scopes_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'scopes'
);

SET @sql := IF(
    @api_keys_scopes_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN scopes VARCHAR(1024) NULL DEFAULT ''full'' AFTER key_hash',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_allowed_ips_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'allowed_ips'
);

SET @sql := IF(
    @api_keys_allowed_ips_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN allowed_ips TEXT NULL AFTER scopes',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_created_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'created_at'
);

SET @sql := IF(
    @api_keys_created_at_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER allowed_ips',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_last_used_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'last_used_at'
);

SET @sql := IF(
    @api_keys_last_used_at_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN last_used_at TIMESTAMP NULL AFTER created_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_revoked_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'revoked_at'
);

SET @sql := IF(
    @api_keys_revoked_at_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN revoked_at TIMESTAMP NULL AFTER last_used_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE api_keys
SET scopes = 'full'
WHERE scopes IS NULL OR scopes = '';

CREATE TABLE IF NOT EXISTS api_usage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_usage_key_time (api_key_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
