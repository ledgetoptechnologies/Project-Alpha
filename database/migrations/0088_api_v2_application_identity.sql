-- Generic API v2 identity. No application or API-key grant is created implicitly.
CREATE TABLE IF NOT EXISTS api_v2_history_identity (
    singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    source_instance_id CHAR(36) NOT NULL,
    history_epoch CHAR(36) NOT NULL,
    CONSTRAINT chk_api_v2_history_singleton CHECK (singleton = 1),
    CONSTRAINT chk_api_v2_source_uuid CHECK (source_instance_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_epoch_uuid CHECK (history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Generate independent random values and set RFC 4122 version/variant bits.
INSERT IGNORE INTO api_v2_history_identity (singleton, source_instance_id, history_epoch)
SELECT 1,
    LOWER(CONCAT(SUBSTR(source_hex,1,8),'-',SUBSTR(source_hex,9,4),'-4',SUBSTR(source_hex,14,3),'-8',SUBSTR(source_hex,18,3),'-',SUBSTR(source_hex,21,12))),
    LOWER(CONCAT(SUBSTR(epoch_hex,1,8),'-',SUBSTR(epoch_hex,9,4),'-4',SUBSTR(epoch_hex,14,3),'-8',SUBSTR(epoch_hex,18,3),'-',SUBSTR(epoch_hex,21,12)))
FROM (SELECT HEX(RANDOM_BYTES(16)) AS source_hex, HEX(RANDOM_BYTES(16)) AS epoch_hex) AS generated;

CREATE TABLE IF NOT EXISTS api_v2_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id CHAR(36) NOT NULL,
    name VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_api_v2_application_id (application_id),
    CONSTRAINT chk_api_v2_application_uuid CHECK (application_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE api_keys ADD COLUMN api_v2_application_id BIGINT UNSIGNED NULL;
ALTER TABLE api_keys ADD INDEX idx_api_keys_v2_application (api_v2_application_id);
ALTER TABLE api_keys ADD CONSTRAINT fk_api_keys_v2_application
    FOREIGN KEY (api_v2_application_id) REFERENCES api_v2_applications(id) ON DELETE RESTRICT;
