-- Migration 0079: durable, default-safe client portal provisioning state.
--
-- Project Alpha remains authoritative for portal eligibility.  These records
-- distinguish an explicit administrator revocation from an automatically
-- evaluated contact record, so reconciliation cannot silently restore access.

CREATE TABLE IF NOT EXISTS portal_client_access_roots (
    root_type ENUM('organization','standalone_client') NOT NULL,
    root_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_state ENUM('active','revoked') NOT NULL DEFAULT 'active',
    state_reason VARCHAR(191) NULL,
    last_reconciled_at DATETIME(6) NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (root_type,root_public_id),
    KEY idx_portal_client_access_roots_state (access_state,last_reconciled_at),
    CONSTRAINT fk_portal_client_access_roots_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_client_access_roots_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_client_login_eligibility (
    client_id INT NOT NULL,
    portal_principal_id BIGINT UNSIGNED NULL,
    manual_state ENUM('automatic','revoked') NOT NULL DEFAULT 'automatic',
    eligibility_status ENUM('eligible','review_required','revoked') NOT NULL DEFAULT 'review_required',
    review_reason ENUM('none','missing_email','invalid_email','duplicate_email','non_human_record','principal_conflict','root_revoked','client_inactive') NOT NULL DEFAULT 'missing_email',
    canonical_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_reconciled_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (client_id),
    KEY idx_portal_client_login_status (eligibility_status,review_reason,last_reconciled_at),
    KEY idx_portal_client_login_email (canonical_email,eligibility_status),
    KEY idx_portal_client_login_principal (portal_principal_id),
    CONSTRAINT fk_portal_client_login_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_client_login_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_client_login_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_client_login_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
