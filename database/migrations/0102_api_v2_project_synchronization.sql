-- Default-inert, application-scoped Project synchronization. This migration
-- grants no capability, creates no external binding, and enables no route.

CREATE TABLE IF NOT EXISTS api_v2_project_authorization_state (
    application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    authorization_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_api_v2_project_auth_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_project_auth_generation CHECK (authorization_generation BETWEEN 0 AND 9223372036854775807)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO api_v2_project_authorization_state(application_pk,authorization_generation)
SELECT id,0 FROM api_v2_applications;

CREATE TABLE IF NOT EXISTS api_v2_project_external_bindings (
    application_pk BIGINT UNSIGNED NOT NULL,
    external_id VARBINARY(764) NOT NULL,
    project_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_revision BIGINT UNSIGNED NOT NULL,
    project_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk,external_id),
    UNIQUE KEY uq_api_v2_project_binding_public (application_pk,project_public_id),
    CONSTRAINT fk_api_v2_project_binding_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT fk_api_v2_project_binding_project FOREIGN KEY (project_public_id)
        REFERENCES projects(public_id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_project_binding_external CHECK (OCTET_LENGTH(external_id) BETWEEN 1 AND 764),
    CONSTRAINT chk_api_v2_project_binding_public CHECK (project_public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_project_binding_revision CHECK (project_revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_project_binding_hash CHECK (project_projection_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_v2_project_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_type ENUM('bind','create','update','refresh') NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARBINARY(764) NOT NULL,
    project_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_revision BIGINT UNSIGNED NULL,
    expected_prior_revision BIGINT UNSIGNED NULL,
    expected_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expected_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_revision BIGINT UNSIGNED NOT NULL,
    result_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_portal_publish_enabled TINYINT(1) NOT NULL,
    result_public_project_enabled TINYINT(1) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk,history_epoch,command_id),
    CONSTRAINT fk_api_v2_project_command_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT fk_api_v2_project_command_project FOREIGN KEY (project_public_id)
        REFERENCES projects(public_id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_project_command_epoch CHECK (history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_project_command_uuid CHECK (command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_project_command_request CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_project_command_external CHECK (OCTET_LENGTH(external_id) BETWEEN 1 AND 764),
    CONSTRAINT chk_api_v2_project_command_public CHECK (project_public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_project_command_expected_revision CHECK ((expected_revision IS NULL OR expected_revision BETWEEN 1 AND 9223372036854775807) AND (expected_prior_revision IS NULL OR expected_prior_revision BETWEEN 1 AND 9223372036854775807)),
    CONSTRAINT chk_api_v2_project_command_expected_hash CHECK (expected_projection_sha256 IS NULL OR expected_projection_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_project_command_expected_generation CHECK (expected_authorization_generation BETWEEN 0 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_project_command_result CHECK (result_revision BETWEEN 1 AND 9223372036854775807 AND result_projection_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_project_command_result_generation CHECK (result_authorization_generation BETWEEN 0 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_project_command_presentation CHECK (result_portal_publish_enabled IN (0,1) AND result_public_project_enabled IN (0,1)),
    CONSTRAINT chk_api_v2_project_command_shape CHECK ((command_type='create' AND expected_revision IS NULL AND expected_prior_revision IS NULL AND expected_projection_sha256 IS NULL) OR (command_type IN ('bind','update') AND expected_revision IS NOT NULL AND expected_prior_revision IS NULL AND expected_projection_sha256 IS NOT NULL) OR (command_type='refresh' AND expected_revision IS NOT NULL AND expected_prior_revision IS NOT NULL AND expected_projection_sha256 IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_v2_project_backfill_attestations (
    attestation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attestation_json LONGTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_api_v2_project_attestation_hash CHECK (attestation_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
