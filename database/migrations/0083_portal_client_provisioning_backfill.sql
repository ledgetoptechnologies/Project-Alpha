-- Durable, bounded historical client-portal provisioning reconciliation.
-- Rows are scoped to the exact producer profile and portal root so separate
-- Project Alpha instances and explicit access revocations never bleed together.
CREATE TABLE IF NOT EXISTS portal_client_provisioning_backfill (
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    root_type ENUM('organization','standalone_client') NOT NULL,
    root_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contract_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('complete','retry','failed') NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    last_error_code VARCHAR(64) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (integration_profile_id, root_type, root_public_id),
    KEY idx_portal_client_backfill_due (integration_profile_id, state, next_attempt_at),
    CONSTRAINT fk_portal_client_backfill_profile FOREIGN KEY (integration_profile_id)
        REFERENCES portal_integration_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
