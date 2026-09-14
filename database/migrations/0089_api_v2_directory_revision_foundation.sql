-- Default-inert directory revision foundation. No existing resource is backfilled;
-- initial ordinary client/organization writers are partial, and no directory
-- endpoint is advertised or routed until complete mutation coverage exists.
-- This is independent of the legacy Sync Contract v2 UUID-v1 source identity.
CREATE TABLE IF NOT EXISTS api_v2_directory_resource_state (
    resource_type ENUM('client','organization') NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    present TINYINT(1) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (resource_type, public_id),
    CONSTRAINT chk_api_v2_directory_public_id CHECK (public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_directory_revision CHECK (revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_directory_projection_hash CHECK (projection_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_directory_present CHECK (present IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A writer must append one change for each committed resource revision in the
-- same transaction. No global sequence is claimed: AUTO_INCREMENT allocation
-- does not imply commit order under concurrent MySQL transactions.
CREATE TABLE IF NOT EXISTS api_v2_directory_resource_changes (
    resource_type ENUM('client','organization') NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    action ENUM('upsert','delete') NOT NULL,
    changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (resource_type, public_id, revision),
    CONSTRAINT fk_api_v2_directory_change_state FOREIGN KEY (resource_type, public_id)
        REFERENCES api_v2_directory_resource_state(resource_type, public_id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_directory_change_revision CHECK (revision BETWEEN 1 AND 9223372036854775807)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generation is an application-specific authorization watermark, not a key
-- usage count or resource content revision. A writer must advance it atomically
-- for every access-changing mutation before any consumer may rely on it.
CREATE TABLE IF NOT EXISTS api_v2_directory_authorization_state (
    application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    authorization_generation BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_api_v2_directory_auth_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
