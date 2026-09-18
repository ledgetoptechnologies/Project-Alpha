-- Durable idempotency for the dormant API v2 organization profile writer.
-- This migration neither enables the route nor grants its dedicated scope.
CREATE TABLE IF NOT EXISTS api_v2_directory_organization_profile_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_revision BIGINT UNSIGNED NOT NULL,
    expected_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_revision BIGINT UNSIGNED NOT NULL,
    result_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk, command_id),
    CONSTRAINT fk_api_v2_org_profile_receipt_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_org_profile_receipt_command CHECK (command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_org_profile_receipt_hash CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_org_profile_receipt_public_id CHECK (public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_org_profile_receipt_expected_revision CHECK (expected_revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_org_profile_receipt_expected_generation CHECK (expected_authorization_generation BETWEEN 0 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_org_profile_receipt_result_revision CHECK (result_revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_org_profile_receipt_projection_hash CHECK (result_projection_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
