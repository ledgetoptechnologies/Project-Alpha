-- Generic API v2 unit lifecycle. A unit is an organization_department; it is
-- deliberately distinct from PA's internal business_units. This migration is
-- default-inert and grants no scope or route.
ALTER TABLE organization_departments
    ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
    ADD COLUMN deleted_at DATETIME(6) NULL AFTER archived,
    ADD INDEX idx_organization_departments_lifecycle (organization_id, archived, deleted_at);

-- A unit has at most one primary contact. NULL values remain non-conflicting,
-- while duplicate legacy primaries make the migration fail closed for review.
ALTER TABLE organization_department_contacts
    ADD COLUMN primary_department_id INT GENERATED ALWAYS AS (IF(is_primary=1,department_id,NULL)) STORED,
    ADD UNIQUE KEY uq_organization_department_primary (primary_department_id);

-- The composite foreign keys require their child constraints to be absent
-- while the shared resource discriminator is widened.
ALTER TABLE api_v2_directory_resource_changes DROP FOREIGN KEY fk_api_v2_directory_change_state;
ALTER TABLE api_v2_directory_external_bindings DROP FOREIGN KEY fk_api_v2_directory_external_binding_resource;
ALTER TABLE api_v2_directory_resource_state MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_resource_changes MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_external_bindings MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_binding_command_receipts MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_binding_revision_refresh_receipts MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_create_command_receipts MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_lifecycle_command_receipts MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_binding_revoke_command_receipts MODIFY resource_type ENUM('client','organization','unit') NOT NULL;
ALTER TABLE api_v2_directory_resource_changes ADD CONSTRAINT fk_api_v2_directory_change_state
    FOREIGN KEY (resource_type,public_id) REFERENCES api_v2_directory_resource_state(resource_type,public_id) ON DELETE RESTRICT;
ALTER TABLE api_v2_directory_external_bindings ADD CONSTRAINT fk_api_v2_directory_external_binding_resource
    FOREIGN KEY (resource_type,public_id) REFERENCES api_v2_directory_resource_state(resource_type,public_id) ON DELETE RESTRICT;

CREATE TABLE api_v2_directory_unit_profile_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_revision BIGINT UNSIGNED NOT NULL,
    expected_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_revision BIGINT UNSIGNED NOT NULL,
    result_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_authorization_generation BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(application_pk,history_epoch,command_id),
    CONSTRAINT fk_api_v2_unit_profile_receipt_application FOREIGN KEY(application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_unit_profile_receipt_command CHECK(command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_unit_profile_receipt_request CHECK(request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_unit_profile_receipt_public CHECK(public_id REGEXP '^[0-9a-f]{32}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_v2_directory_unit_contact_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_name ENUM('assign','remove','set_primary') NOT NULL,
    unit_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_unit_revision BIGINT UNSIGNED NOT NULL,
    expected_authorization_generation BIGINT UNSIGNED NOT NULL,
    result_unit_revision BIGINT UNSIGNED NOT NULL,
    result_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_authorization_generation BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(application_pk,history_epoch,command_id),
    CONSTRAINT fk_api_v2_unit_contact_receipt_application FOREIGN KEY(application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_unit_contact_receipt_command CHECK(command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_unit_contact_receipt_request CHECK(request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_unit_contact_receipt_public CHECK(unit_public_id REGEXP '^[0-9a-f]{32}$' AND client_public_id REGEXP '^[0-9a-f]{32}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
