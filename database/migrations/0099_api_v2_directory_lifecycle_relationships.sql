-- Safe API v2 directory lifecycle, relationship, and authority-revocation receipts.
-- Commands are dormant until their individual feature flags are enabled.

ALTER TABLE organizations
    ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER link_strategy,
    ADD COLUMN deleted_at DATETIME(6) NULL AFTER archived,
    ADD INDEX idx_organizations_archived (archived, deleted_at);

CREATE TABLE IF NOT EXISTS api_v2_directory_lifecycle_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    resource_type ENUM('client','organization') NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_name ENUM('archive','restore') NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_revision BIGINT UNSIGNED NOT NULL,
    expected_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_revision BIGINT UNSIGNED NOT NULL,
    result_authorization_generation BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk, resource_type, history_epoch, command_id),
    CONSTRAINT fk_api_v2_directory_lifecycle_receipt_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_directory_lifecycle_receipt_command CHECK (command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_lifecycle_receipt_epoch CHECK (history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_lifecycle_receipt_request CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_directory_lifecycle_receipt_public CHECK (public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_directory_lifecycle_receipt_revision CHECK (expected_revision BETWEEN 1 AND 9223372036854775807 AND result_revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_directory_lifecycle_receipt_generation CHECK (expected_authorization_generation BETWEEN 0 AND 9223372036854775807 AND result_authorization_generation BETWEEN 0 AND 9223372036854775807)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_v2_directory_relationship_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_name ENUM('assign','remove','move') NOT NULL,
    client_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_client_revision BIGINT UNSIGNED NOT NULL,
    expected_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_client_revision BIGINT UNSIGNED NOT NULL,
    result_authorization_generation BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk, history_epoch, command_id),
    CONSTRAINT fk_api_v2_directory_relationship_receipt_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_directory_relationship_receipt_command CHECK (command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_relationship_receipt_epoch CHECK (history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_relationship_receipt_request CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_directory_relationship_receipt_client CHECK (client_public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_directory_relationship_receipt_revision CHECK (expected_client_revision BETWEEN 1 AND 9223372036854775807 AND result_client_revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_directory_relationship_receipt_generation CHECK (expected_authorization_generation BETWEEN 0 AND 9223372036854775807 AND result_authorization_generation BETWEEN 0 AND 9223372036854775807)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_v2_directory_binding_revoke_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    resource_type ENUM('client','organization') NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARBINARY(764) NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_resource_revision BIGINT UNSIGNED NOT NULL,
    expected_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_authorization_generation BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk, resource_type, history_epoch, command_id),
    CONSTRAINT fk_api_v2_directory_binding_revoke_receipt_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_directory_binding_revoke_receipt_command CHECK (command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_binding_revoke_receipt_epoch CHECK (history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_binding_revoke_receipt_request CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_directory_binding_revoke_receipt_external CHECK (OCTET_LENGTH(external_id) BETWEEN 1 AND 764),
    CONSTRAINT chk_api_v2_directory_binding_revoke_receipt_public CHECK (public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_directory_binding_revoke_receipt_revision CHECK (expected_resource_revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_directory_binding_revoke_receipt_generation CHECK (expected_authorization_generation BETWEEN 0 AND 9223372036854775807 AND result_authorization_generation BETWEEN 1 AND 9223372036854775807)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
