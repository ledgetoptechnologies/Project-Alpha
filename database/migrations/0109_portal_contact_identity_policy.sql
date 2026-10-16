-- Explicitly selected, bound-only contact-profile writes preserve existing
-- login authority. No rows are backfilled and legacy clients keep their policy.
CREATE TABLE IF NOT EXISTS portal_client_contact_identity_policy (
    client_id INT NOT NULL,
    portal_principal_id BIGINT UNSIGNED NULL,
    login_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    application_pk BIGINT UNSIGNED NOT NULL,
    history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (client_id),
    KEY idx_portal_contact_identity_login (login_email),
    CONSTRAINT fk_portal_contact_identity_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_portal_contact_identity_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_portal_contact_identity_application FOREIGN KEY (application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
