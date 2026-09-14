-- Durable, application-scoped idempotency for generic directory binding commands.
-- No route or capability is enabled by this migration.
CREATE TABLE IF NOT EXISTS api_v2_directory_binding_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    resource_type ENUM('client','organization') NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARBINARY(764) NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resource_revision BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk, resource_type, command_id),
    CONSTRAINT fk_api_v2_directory_receipt_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_directory_receipt_command_id CHECK (command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_directory_receipt_request_hash CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_directory_receipt_external_id CHECK (OCTET_LENGTH(external_id) BETWEEN 1 AND 764),
    CONSTRAINT chk_api_v2_directory_receipt_public_id CHECK (public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_directory_receipt_revision CHECK (resource_revision BETWEEN 1 AND 9223372036854775807)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
