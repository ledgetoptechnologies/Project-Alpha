-- Optional, explicitly configured control plane for an externally managed directory.
-- The policy is dormant until the application, key, route, identity and release
-- evidence checks in the application all pass. No API key or policy is created here.
CREATE TABLE IF NOT EXISTS api_v2_directory_management_policy (
    singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    configured_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ownership_active TINYINT(1) NOT NULL DEFAULT 0,
    application_pk BIGINT UNSIGNED NULL,
    source_instance_id CHAR(36) NULL,
    application_id CHAR(36) NULL,
    history_epoch CHAR(36) NULL,
    release_attestation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_effective TINYINT(1) NOT NULL DEFAULT 0,
    last_reason VARCHAR(64) NOT NULL DEFAULT 'not_configured',
    configured_by INT NULL,
    configured_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_api_v2_directory_management_singleton CHECK (singleton = 1),
    CONSTRAINT chk_api_v2_directory_management_boolean CHECK (configured_enabled IN (0,1) AND ownership_active IN (0,1)),
    CONSTRAINT chk_api_v2_directory_management_state CHECK (
        (configured_enabled = 0 AND ownership_active = 0)
        OR (configured_enabled = 1 AND application_pk IS NOT NULL AND source_instance_id IS NOT NULL
            AND application_id IS NOT NULL AND history_epoch IS NOT NULL AND release_attestation_sha256 IS NOT NULL)
    ),
    CONSTRAINT chk_api_v2_directory_management_source CHECK (source_instance_id IS NULL OR source_instance_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_management_application CHECK (application_id IS NULL OR application_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_management_epoch CHECK (history_epoch IS NULL OR history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_management_digest CHECK (release_attestation_sha256 IS NULL OR release_attestation_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT fk_api_v2_directory_management_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT fk_api_v2_directory_management_user FOREIGN KEY (configured_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO api_v2_directory_management_policy(singleton) VALUES (1);

CREATE TABLE IF NOT EXISTS api_v2_directory_management_attestations (
    attestation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attestation_json LONGTEXT NOT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_api_v2_directory_management_attestation_user FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_v2_directory_management_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    outcome VARCHAR(32) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    application_pk BIGINT UNSIGNED NULL,
    actor_user_id INT NULL,
    target_type VARCHAR(32) NULL,
    action_name VARCHAR(96) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_api_v2_directory_management_audit_created (created_at),
    CONSTRAINT fk_api_v2_directory_management_audit_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE SET NULL,
    CONSTRAINT fk_api_v2_directory_management_audit_user FOREIGN KEY (actor_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
