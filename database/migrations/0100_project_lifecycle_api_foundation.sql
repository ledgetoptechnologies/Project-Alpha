-- Permanent Project lifecycle and dormant API v2 management foundation.
-- Existing browser-created Projects retain their portal projection behavior;
-- API-created shared Projects must opt in separately and therefore start dark.

UPDATE projects SET status='active' WHERE status='overdue';
ALTER TABLE projects
    MODIFY COLUMN status ENUM('not_started','active','completed','cancelled') NOT NULL DEFAULT 'not_started',
    ADD COLUMN archived_at DATETIME(6) NULL AFTER completed_at,
    ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER source_version,
    ADD COLUMN portal_publish_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER archived_at,
    ADD INDEX idx_projects_lifecycle (archived_at,status,estimated_end),
    ADD CONSTRAINT chk_projects_revision CHECK (revision BETWEEN 1 AND 9223372036854775807),
    ADD CONSTRAINT chk_projects_portal_publish CHECK (portal_publish_enabled IN (0,1));

-- Protect legacy Projects immediately, before the bounded canonical-history
-- backfill runs. This contains identity only and is never an API projection.
CREATE TABLE IF NOT EXISTS project_retention_guards (
    project_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    established_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_project_retention_project FOREIGN KEY (project_public_id) REFERENCES projects(public_id) ON DELETE RESTRICT,
    CONSTRAINT chk_project_retention_public CHECK (project_public_id REGEXP '^[0-9a-f]{32}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO project_retention_guards (project_public_id) SELECT public_id FROM projects;

CREATE TABLE IF NOT EXISTS project_changes (
    project_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    action_name ENUM('baseline','create','update','status','complete','cancel','archive','restore') NOT NULL,
    projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    application_pk BIGINT UNSIGNED NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_user_id INT NULL,
    changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (project_public_id,revision),
    KEY idx_project_changes_application (application_pk,changed_at),
    CONSTRAINT fk_project_changes_project FOREIGN KEY (project_public_id) REFERENCES projects(public_id) ON DELETE RESTRICT,
    CONSTRAINT fk_project_changes_application FOREIGN KEY (application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT fk_project_changes_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_project_changes_public CHECK (project_public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_project_changes_revision CHECK (revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_project_changes_projection CHECK (projection_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing Projects are intentionally not synthesized with a SQL hash. The
-- bounded backfill command uses the same canonical PHP projector as writers;
-- API reads fail closed until that operator-reviewed backfill is complete.

CREATE TABLE IF NOT EXISTS api_v2_project_lifecycle_command_receipts (
    application_pk BIGINT UNSIGNED NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_name ENUM('complete','cancel','archive','restore') NOT NULL,
    project_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_revision BIGINT UNSIGNED NOT NULL,
    result_revision BIGINT UNSIGNED NOT NULL,
    result_status ENUM('not_started','active','completed','cancelled') NOT NULL,
    result_completed_at DATETIME(6) NULL,
    result_archived_at DATETIME(6) NULL,
    outcome ENUM('applied','noop','blocked') NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (application_pk,history_epoch,command_id),
    CONSTRAINT fk_api_v2_project_receipt_application FOREIGN KEY (application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT fk_api_v2_project_receipt_project FOREIGN KEY (project_public_id) REFERENCES projects(public_id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_project_receipt_epoch CHECK (history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_project_receipt_command CHECK (command_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_api_v2_project_receipt_request CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_project_receipt_revision CHECK (expected_revision BETWEEN 1 AND 9223372036854775807 AND result_revision BETWEEN 1 AND 9223372036854775807)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
