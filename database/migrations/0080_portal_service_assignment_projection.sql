-- Migration 0080: explicit, default-off service-assignment projection producer.
--
-- This reuses the existing portal integration profile, signing credentials and
-- durable outbox. It does not infer assignments from catalog visibility,
-- quotes, contracts, invoices, portal entitlements or workspace membership.

SET @pa_service_assignment_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_integration_profiles' AND column_name='service_assignment_projection_enabled')=0,'ALTER TABLE portal_integration_profiles ADD COLUMN service_assignment_projection_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER catalog_projection_enabled','SELECT 1');PREPARE pa_service_assignment_stmt FROM @pa_service_assignment_sql;EXECUTE pa_service_assignment_stmt;DEALLOCATE PREPARE pa_service_assignment_stmt;

ALTER TABLE portal_projection_outbox
    MODIFY COLUMN route_type ENUM('portal','catalog','service_assignments') NOT NULL;

CREATE TABLE IF NOT EXISTS portal_service_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (CONCAT('a',SUBSTRING(LOWER(HEX(RANDOM_BYTES(16))),2))),
    subject_type ENUM('organization','standalone_client','department','client','project') NOT NULL,
    subject_public_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    service_public_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    effective_from DATETIME(3) NULL,
    effective_until DATETIME(3) NULL,
    deleted_at DATETIME(6) NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_service_assignment_public_id (public_id),
    KEY idx_portal_service_assignment_subject (subject_type,subject_public_id,deleted_at,active,public_id),
    KEY idx_portal_service_assignment_service (service_public_id,deleted_at,active,public_id),
    CONSTRAINT fk_portal_service_assignment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_service_assignment_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_portal_service_assignment_public_id CHECK (public_id REGEXP '^[a-z0-9][a-z0-9_-]{0,127}$'),
    CONSTRAINT chk_portal_service_assignment_subject_id CHECK (subject_public_id REGEXP '^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$'),
    CONSTRAINT chk_portal_service_assignment_service_id CHECK (service_public_id REGEXP '^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$'),
    CONSTRAINT chk_portal_service_assignment_window CHECK (effective_from IS NULL OR effective_until IS NULL OR effective_until>effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_service_assignment_projection_state (
    integration_profile_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    source_generation VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_sequence BIGINT UNSIGNED NOT NULL,
    snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_portal_service_assignment_state_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE CASCADE,
    CONSTRAINT chk_portal_service_assignment_state_hash CHECK (snapshot_hash REGEXP '^[a-f0-9]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_service_assignment_projection_records (
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    assignment_public_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_version VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (integration_profile_id,assignment_public_id),
    CONSTRAINT fk_portal_service_assignment_record_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE CASCADE,
    CONSTRAINT chk_portal_service_assignment_record_hash CHECK (payload_hash REGEXP '^[a-f0-9]{64}$'),
    CONSTRAINT chk_portal_service_assignment_record_json CHECK (JSON_VALID(record_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_service_assignment_projection_receipts (
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (integration_profile_id,idempotency_hash),
    CONSTRAINT fk_portal_service_assignment_receipt_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT chk_portal_service_assignment_receipt_idempotency CHECK (idempotency_hash REGEXP '^[a-f0-9]{64}$'),
    CONSTRAINT chk_portal_service_assignment_receipt_payload CHECK (payload_hash REGEXP '^[a-f0-9]{64}$'),
    CONSTRAINT chk_portal_service_assignment_receipt_json CHECK (JSON_VALID(result_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER portal_service_assignment_receipt_no_update BEFORE UPDATE ON portal_service_assignment_projection_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='service-assignment-receipt-immutable';
CREATE TRIGGER portal_service_assignment_receipt_no_delete BEFORE DELETE ON portal_service_assignment_projection_receipts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='service-assignment-receipt-immutable';
