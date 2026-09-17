-- Durable operator-requested recovery for terminal portal projection rows.
--
-- A recovery never rewrites or replays the failed payload. It records the
-- exact failed-row cutoff, queues a fresh complete workspace generation, and
-- becomes complete only when the receiver acknowledges that generation's
-- activation record. The original dead-letter rows remain immutable evidence.

CREATE TABLE IF NOT EXISTS portal_projection_recoveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    workspace_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    route_type ENUM('portal') NOT NULL DEFAULT 'portal',
    failed_row_cutoff_id BIGINT UNSIGNED NOT NULL,
    source_generation CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activation_delivery_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('queued','complete','failed') NOT NULL DEFAULT 'queued',
    requested_by INT NULL,
    completed_at DATETIME(6) NULL,
    failed_at DATETIME(6) NULL,
    last_error_code VARCHAR(64) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_projection_recovery_activation (activation_delivery_id),
    KEY idx_portal_projection_recovery_workspace (integration_profile_id,workspace_public_id,route_type,state,id),
    CONSTRAINT fk_portal_projection_recovery_profile FOREIGN KEY (integration_profile_id)
        REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_portal_projection_recovery_actor FOREIGN KEY (requested_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
