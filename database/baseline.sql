-- Project Alpha 0.5.0 - Database Baseline
-- Destructive reset baseline. Databases from 0.4.x and earlier are not
-- upgraded in place; initialize this file only against an empty database.
-- ============================================================================

-- ============================================================================
-- MODULE 001: Authentication & Identity
-- ============================================================================

-- USERS
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    username VARCHAR(50) NULL,
    role ENUM('admin', 'owner', 'staff', 'member', 'employee', 'user') NOT NULL DEFAULT 'member',
    force_password_reset TINYINT(1) NOT NULL DEFAULT 0,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    totp_reenroll_required TINYINT(1) NOT NULL DEFAULT 0,
    document_sender_enabled TINYINT(1) NOT NULL DEFAULT 0,
    document_sender_name VARCHAR(255) NULL,
    document_sender_company VARCHAR(255) NULL,
    document_sender_address_line1 VARCHAR(255) NULL,
    document_sender_address_line2 VARCHAR(255) NULL,
    document_sender_city VARCHAR(120) NULL,
    document_sender_state VARCHAR(120) NULL,
    document_sender_postal VARCHAR(40) NULL,
    document_sender_country VARCHAR(120) NULL,
    document_sender_phone VARCHAR(80) NULL,
    document_sender_email VARCHAR(255) NULL,
    is_disabled TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    tos_accepted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASSWORD RESETS
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT(1) NOT NULL DEFAULT 0,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_resets_user (user_id),
    INDEX idx_resets_token (token),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LOGIN ATTEMPTS
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(255) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_ip (ip),
    INDEX idx_attempts_email (email),
    INDEX idx_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LOGIN 2FA ATTEMPTS
CREATE TABLE IF NOT EXISTS login_2fa_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_2fa_attempts_user (user_id),
    INDEX idx_2fa_attempts_ip (ip),
    INDEX idx_2fa_attempts_time (attempted_at),
    CONSTRAINT fk_2fa_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER 2FA SETTINGS
CREATE TABLE IF NOT EXISTS user_2fa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    secret VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    enabled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TRUSTED DEVICES
CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_token VARCHAR(64) NOT NULL,
    device_name VARCHAR(255) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent_hash VARCHAR(64) NOT NULL,
    last_verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_trusted_devices_user (user_id),
    INDEX idx_trusted_devices_token (device_token),
    INDEX idx_trusted_devices_expires (expires_at),
    CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TRUSTED IPs
CREATE TABLE IF NOT EXISTS trusted_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trusted_ips_address (ip_address),
    CONSTRAINT fk_trusted_ips_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER NOTIFICATION PREFERENCES
CREATE TABLE IF NOT EXISTS user_notification_preferences (
    user_id    INT          NOT NULL,
    notify_processor_invoice_paid TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 002: Organizations, Roles, API Keys & Webhooks
-- ============================================================================

-- ORGANIZATIONS
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    name VARCHAR(150) NOT NULL,
    general_email VARCHAR(255) NULL,
    general_phone VARCHAR(50) NULL,
    notes TEXT NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    postal_code VARCHAR(32) NULL,
    country VARCHAR(100) NULL,
    tax_exempt_file VARCHAR(255) NULL,
    tax_exempt_uploaded_at TIMESTAMP NULL,
    link_strategy ENUM('department_links_only','overall_folder','shared_folder') NOT NULL DEFAULT 'overall_folder',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_organizations_name (name),
    UNIQUE KEY uq_organizations_public_id (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLES
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    organization_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_name_org (name, organization_id),
    INDEX idx_roles_org (organization_id),
    INDEX idx_roles_system (is_system),
    CONSTRAINT fk_roles_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLE PERMISSIONS
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_permission (role_id, permission),
    INDEX idx_rp_role (role_id),
    INDEX idx_rp_permission (permission),
    CONSTRAINT fk_rp_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER PERMISSION OVERRIDES
CREATE TABLE IF NOT EXISTS user_permissions_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NULL,
    permission VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_org_permission (user_id, organization_id, permission),
    INDEX idx_upo_user (user_id),
    INDEX idx_upo_permission (permission),
    CONSTRAINT fk_upo_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_upo_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed system roles
INSERT INTO roles (id, name, description, is_system, organization_id) VALUES
    (1, 'admin', 'Super-admin bypass; manages app, all orgs, all data.', 1, NULL),
    (2, 'owner', 'Full access within assigned orgs.', 1, NULL),
    (3, 'staff', 'Operational access within assigned orgs; no settings/users/billing.', 1, NULL),
    (4, 'member', 'Read-mostly within assigned orgs; record-level scoping.', 1, NULL)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    is_system = VALUES(is_system);

-- Seed system role permissions
INSERT INTO role_permissions (role_id, permission, allowed) VALUES
    (1, '*', 1),
    (1, 'payments.view', 1), (1, 'payments.create', 1),
    (2, 'quotes.*', 1), (2, 'contracts.*', 1), (2, 'invoices.*', 1),
    (2, 'clients.*', 1), (2, 'projects.*', 1), (2, 'jobs.*', 1),
    (2, 'financial.view', 1), (2, 'financial.manage', 1), (2, 'financial.export', 1), (2, 'financial.audit', 1),
    (2, 'reports.view', 1), (2, 'settings.view', 1), (2, 'settings.manage', 1),
    (2, 'users.view', 1), (2, 'users.manage', 1), (2, 'users.reset_password', 1),
    (2, 'api_keys.*', 1), (2, 'billing.*', 1), (2, 'organizations.*', 1),
    (2, 'public_links.*', 1), (2, 'time_tracking.*', 1), (2, 'profile.*', 1),
    (2, 'payments.view', 1), (2, 'payments.create', 1),
    (3, 'quotes.*', 1), (3, 'contracts.*', 1), (3, 'invoices.*', 1),
    (3, 'clients.*', 1), (3, 'projects.*', 1), (3, 'jobs.*', 1),
    (3, 'organizations.*', 1), (3, 'public_links.*', 1),
    (3, 'payments.view', 1), (3, 'payments.create', 1),
    (3, 'financial.view', 0), (3, 'financial.manage', 0), (3, 'financial.export', 0), (3, 'financial.audit', 0),
    (3, 'reports.view', 0),
    (3, 'settings.view', 0), (3, 'settings.manage', 0),
    (3, 'users.view', 0), (3, 'users.manage', 0), (3, 'users.reset_password', 0),
    (3, 'api_keys.*', 0), (3, 'billing.*', 0),
    (3, 'time_tracking.*', 1), (3, 'profile.*', 1), (3, '2fa.manage', 0),
    (4, 'quotes.view', 1), (4, 'quotes.create', 1), (4, 'quotes.edit', 1), (4, 'quotes.send', 1), (4, 'quotes.approve', 1), (4, 'quotes.reject', 1),
    (4, 'contracts.view', 1), (4, 'contracts.create', 1), (4, 'contracts.edit', 1), (4, 'contracts.sign', 1), (4, 'contracts.complete', 1), (4, 'contracts.void', 1), (4, 'contracts.send', 1),
    (4, 'invoices.view', 1), (4, 'invoices.create', 1), (4, 'invoices.edit', 1), (4, 'invoices.void', 1), (4, 'invoices.mark_paid', 1), (4, 'invoices.send', 1),
    (4, 'payments.view', 1), (4, 'payments.create', 1),
    (4, 'clients.view', 1), (4, 'clients.create', 1), (4, 'clients.edit', 1), (4, 'clients.onboarding', 1), (4, 'clients.delete', 1), (4, 'clients.purge', 1), (4, 'clients.restore', 1),
    (4, 'projects.view', 1), (4, 'projects.create', 1), (4, 'projects.edit', 1), (4, 'projects.delete', 1), (4, 'projects.search', 1),
    (4, 'jobs.view', 1), (4, 'jobs.edit', 1), (4, 'jobs.search', 1),
    (4, 'organizations.view', 1), (4, 'organizations.manage', 1),
    (4, 'public_links.view', 1), (4, 'public_links.create', 1), (4, 'public_links.revoke', 1), (4, 'public_links.manage', 1),
    (4, 'time_tracking.view', 0), (4, 'time_tracking.manage', 0),
    (4, 'reports.view', 0),
    (4, 'financial.view', 0), (4, 'financial.manage', 0), (4, 'financial.export', 0), (4, 'financial.audit', 0),
    (4, 'billing.view', 0), (4, 'billing.manage', 0),
    (4, 'users.view', 0), (4, 'users.manage', 0), (4, 'users.reset_password', 0), (4, 'users.delete', 0),
    (4, 'api_keys.view', 0), (4, 'api_keys.manage', 0),
    (4, 'settings.view', 0), (4, 'settings.manage', 0),
    (4, '2fa.manage', 0),
    (4, 'profile.view', 1), (4, 'profile.edit', 1)
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- Seed void permission keys for system roles (owner/staff/member allowed)
INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'contracts.void', CASE WHEN name IN ('owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'invoices.void', CASE WHEN name IN ('owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'clients.onboarding', CASE WHEN name IN ('admin','owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- API KEYS
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(255) NOT NULL,
    key_prefix VARCHAR(32) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    scopes VARCHAR(1024) NULL,
    allowed_ips TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    UNIQUE KEY uq_key_hash (key_hash),
    INDEX idx_api_keys_prefix (key_prefix),
    INDEX idx_api_keys_revoked (revoked_at),
    INDEX idx_api_keys_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API USAGE
CREATE TABLE IF NOT EXISTS api_usage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_usage_key_time (api_key_id, used_at),
    CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INTERNAL NOTIFICATION RELAY (disabled unless explicitly configured)
CREATE TABLE IF NOT EXISTS notification_relay_key_state (
    api_key_id INT NOT NULL PRIMARY KEY,
    active_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_relay_state_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_relay_rate_buckets (
    api_key_id INT NOT NULL,
    bucket_type ENUM('key_minute', 'recipient_hour') NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    window_key BIGINT UNSIGNED NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (api_key_id, bucket_type, subject_hash, window_key),
    INDEX idx_notification_relay_rate_window (bucket_type, window_key),
    CONSTRAINT fk_notification_relay_rate_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_relay_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    action_name VARCHAR(64) NOT NULL,
    template_name VARCHAR(64) NOT NULL,
    recipient_alias VARCHAR(64) NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    recipient_hash CHAR(64) NOT NULL,
    variables_json JSON NOT NULL,
    idempotency_hash CHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status ENUM('pending', 'processing', 'retry', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TIMESTAMP NULL,
    lock_token CHAR(32) NULL,
    sent_at TIMESTAMP NULL,
    last_error_code VARCHAR(64) NULL,
    source_ip VARCHAR(45) NULL,
    source_user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_relay_idempotency (api_key_id, idempotency_hash),
    INDEX idx_notification_relay_due (status, next_attempt_at, id),
    INDEX idx_notification_relay_recipient (recipient_hash, created_at),
    CONSTRAINT fk_notification_relay_queue_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_relay_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue_id BIGINT NULL,
    queue_reference BIGINT NULL,
    api_key_id INT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_relay_event_queue (queue_reference, created_at),
    INDEX idx_notification_relay_event_key (api_key_id, created_at),
    CONSTRAINT fk_notification_relay_event_queue FOREIGN KEY (queue_id) REFERENCES notification_relay_queue(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_relay_event_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SYNC CONTRACT V2 FOUNDATION (route disabled by default)
CREATE TABLE IF NOT EXISTS sync_source_identity (
    singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    source_instance_id CHAR(36) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_sync_source_identity_singleton CHECK (singleton = 1),
    UNIQUE KEY uq_sync_source_instance_id (source_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_resource_state (
    resource_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(191) NOT NULL,
    resource_version BIGINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    present TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (resource_type, resource_id),
    INDEX idx_sync_resource_updated (updated_at),
    CONSTRAINT chk_sync_resource_version CHECK (resource_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_event_log (
    sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL,
    source_instance_id CHAR(36) NOT NULL,
    resource_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(191) NOT NULL,
    resource_version BIGINT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    payload JSON NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_sync_event_id (event_id),
    INDEX idx_sync_event_resource (resource_type, resource_id, resource_version),
    INDEX idx_sync_event_occurred (occurred_at),
    CONSTRAINT fk_sync_event_source
        FOREIGN KEY (source_instance_id) REFERENCES sync_source_identity(source_instance_id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_sync_event_version CHECK (resource_version >= 1),
    CONSTRAINT chk_sync_event_action CHECK (action IN ('upsert', 'delete'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_snapshot_sessions (
    snapshot_id CHAR(36) NOT NULL PRIMARY KEY,
    source_instance_id CHAR(36) NOT NULL,
    api_key_id INT NOT NULL,
    high_water_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    generated_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_sync_snapshot_expiry (expires_at),
    INDEX idx_sync_snapshot_key (api_key_id, expires_at),
    CONSTRAINT fk_sync_snapshot_source
        FOREIGN KEY (source_instance_id) REFERENCES sync_source_identity(source_instance_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sync_snapshot_api_key
        FOREIGN KEY (api_key_id) REFERENCES api_keys(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ACTIVITY LOG
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    document_type VARCHAR(20) NULL,
    document_id INT NULL,
    client_id INT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_type (event_type),
    INDEX idx_activity_doc (document_type, document_id),
    INDEX idx_activity_client (client_id),
    INDEX idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WEBHOOKS
CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(500) NOT NULL,
    events JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    secret VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_webhooks_active (is_active),
    INDEX idx_webhooks_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WEBHOOK DELIVERIES
-- ============================================================================
-- MODULE 003: Projects & Clients
-- ============================================================================

-- CLIENTS
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    organization_id INT NULL,
    client_type ENUM('unknown','business','consumer') NOT NULL DEFAULT 'unknown',
    created_by INT NULL,
    config JSON NULL,
    stripe_customer_id VARCHAR(255) NULL,
    stripe_payment_method_id VARCHAR(255) NULL,
    auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    archive_payload JSON NULL,
    notes TEXT NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clients_name (name),
    INDEX idx_clients_email (email),
    INDEX idx_clients_org (organization_id),
    INDEX idx_clients_stripe_customer (stripe_customer_id),
    INDEX idx_clients_archived (archived),
    INDEX idx_clients_deleted (deleted_at),
    UNIQUE KEY uq_clients_public_id (public_id),
    CONSTRAINT fk_clients_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CLIENT ONBOARDING
CREATE TABLE IF NOT EXISTS client_onboarding_invitations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    target_organization_id INT NULL,
    client_id INT NULL,
    invited_email VARCHAR(255) NULL,
    token_hash CHAR(64) NOT NULL,
    token_enc TEXT NULL,
    status ENUM('pending','verified','submitted','approved','rejected','revoked','expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    verification_code_hash VARCHAR(255) NULL,
    code_expires_at DATETIME NULL,
    verification_attempts SMALLINT NOT NULL DEFAULT 0,
    last_code_sent_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    sent_at DATETIME NULL,
    notify_on_submit TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_token (token_hash),
    INDEX idx_client_onboarding_owner (organization_id,status,created_at),
    INDEX idx_client_onboarding_email (invited_email),
    CONSTRAINT fk_client_onboarding_owner FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_target_org FOREIGN KEY (target_organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_onboarding_submissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id BIGINT NOT NULL,
    proposed_data JSON NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_submission (invitation_id),
    INDEX idx_client_onboarding_review (status,created_at),
    CONSTRAINT fk_client_onboarding_submission_invite FOREIGN KEY (invitation_id) REFERENCES client_onboarding_invitations(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_onboarding_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECTS
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    client_id INT NULL,
    parent_id INT NULL,
    organization_id INT NULL,
    department_id INT NULL,
    business_unit_id INT NULL,
    manager_user_id INT NULL,
    created_by INT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('not_started', 'active', 'overdue', 'completed', 'cancelled') NOT NULL DEFAULT 'not_started',
    invoice_billing_period ENUM('per_invoice','monthly') NOT NULL DEFAULT 'monthly',
    invoice_net_terms_days INT NULL,
    project_invoice_auto_email TINYINT(1) NOT NULL DEFAULT 1,
    public_project_enabled TINYINT(1) NOT NULL DEFAULT 0,
    public_project_token VARCHAR(64) NULL,
    public_project_require_password TINYINT(1) NOT NULL DEFAULT 0,
    public_project_password_hash VARCHAR(255) NULL,
    public_project_can_view_documents TINYINT(1) NOT NULL DEFAULT 1,
    public_project_can_view_invoices TINYINT(1) NOT NULL DEFAULT 1,
    public_project_can_upload TINYINT(1) NOT NULL DEFAULT 0,
    public_project_can_request_changes TINYINT(1) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    estimated_start DATE NULL,
    estimated_end DATE NULL,
    budget DECIMAL(12, 2) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projects_client (client_id),
    INDEX idx_projects_org (organization_id),
    INDEX idx_projects_department (department_id),
    INDEX idx_projects_business_unit (business_unit_id, status),
    INDEX idx_projects_manager (manager_user_id, status),
    INDEX idx_projects_status (status),
    INDEX idx_projects_parent (parent_id),
    UNIQUE KEY uq_projects_public_project_token (public_project_token),
    UNIQUE KEY uq_projects_public_id (public_id),
    CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_projects_parent FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_projects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CLIENT PORTAL FOUNDATION (disabled by default; no public route is installed)
CREATE TABLE IF NOT EXISTS portal_principals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    authorization_version INT UNSIGNED NOT NULL DEFAULT 1,
    activated_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_principals_public_id (public_id),
    KEY idx_portal_principals_state (enabled, revoked_at),
    CONSTRAINT fk_portal_principals_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_principals_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_principal_clients (
    portal_principal_id BIGINT UNSIGNED NOT NULL,
    client_id INT NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (portal_principal_id, client_id),
    KEY idx_portal_principal_clients_client (client_id),
    CONSTRAINT fk_portal_principal_clients_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_principal_clients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_principal_clients_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_identity_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    portal_principal_id BIGINT UNSIGNED NOT NULL,
    issuer VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    bound_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_identity_issuer_subject (issuer, subject_hash),
    KEY idx_portal_identity_principal_state (portal_principal_id, enabled, revoked_at),
    CONSTRAINT fk_portal_identity_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_identity_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_identity_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_organization_entitlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    portal_principal_id BIGINT UNSIGNED NOT NULL,
    organization_id INT NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_org_entitlement (portal_principal_id, organization_id, capability),
    KEY idx_portal_org_entitlement_lookup (organization_id, capability, enabled, revoked_at),
    CONSTRAINT fk_portal_org_entitlement_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_org_entitlement_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_org_entitlement_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_org_entitlement_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_project_entitlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    portal_principal_id BIGINT UNSIGNED NOT NULL,
    project_id INT NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_project_entitlement (portal_principal_id, project_id, capability),
    KEY idx_portal_project_entitlement_lookup (project_id, capability, enabled, revoked_at),
    CONSTRAINT fk_portal_project_entitlement_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_project_entitlement_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_project_entitlement_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_project_entitlement_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ORGANIZATION DEPARTMENTS
CREATE TABLE IF NOT EXISTS organization_departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    folder_name VARCHAR(150) NULL,
    folder_aliases JSON NULL,
    resolver_mode ENUM('auto_attach','review','manual_only','excluded') NOT NULL DEFAULT 'manual_only',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_department_name (organization_id, name),
    INDEX idx_org_departments_org (organization_id),
    CONSTRAINT fk_org_departments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_department_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    client_id INT NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'contact',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department_contact (department_id, client_id),
    INDEX idx_department_contacts_department (department_id),
    INDEX idx_department_contacts_client (client_id),
    CONSTRAINT fk_department_contacts_department FOREIGN KEY (department_id) REFERENCES organization_departments(id) ON DELETE CASCADE,
    CONSTRAINT fk_department_contacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT META
CREATE TABLE IF NOT EXISTS project_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    project_code VARCHAR(64) NULL,
    client_id INT NULL,
    meta_key VARCHAR(100) NULL,
    meta_value TEXT NULL,
    notes TEXT NULL,
    terms TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_meta_key (project_id, meta_key),
    UNIQUE KEY uq_project_meta_code (project_code),
    INDEX idx_project_meta_client (client_id),
    CONSTRAINT fk_project_meta_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_meta_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT COUNTERS
CREATE TABLE IF NOT EXISTS project_counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    project_id INT NULL,
    counter_type VARCHAR(50) NOT NULL,
    counter_value INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_counter (organization_id, project_id, counter_type),
    INDEX idx_counters_org (organization_id),
    INDEX idx_counters_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT DOCUMENTS
CREATE TABLE IF NOT EXISTS project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    document_type ENUM('quote', 'contract', 'invoice', 'recurring_invoice', 'receipt', 'form', 'other') NOT NULL DEFAULT 'other',
    document_id INT NOT NULL,
    file_path VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_docs_project (project_id),
    INDEX idx_project_docs_type (document_type, document_id),
    CONSTRAINT fk_project_docs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT FILE UPLOADS
CREATE TABLE IF NOT EXISTS project_file_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    client_upload_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_file_folder_name (project_id, name),
    INDEX idx_project_file_folders_project (project_id),
    INDEX idx_project_file_folders_created_by (created_by),
    CONSTRAINT fk_project_file_folders_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_file_folders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    folder_id INT NULL,
    original_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(190) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_files_project (project_id),
    INDEX idx_project_files_folder (folder_id),
    INDEX idx_project_files_uploaded_by (uploaded_by),
    CONSTRAINT fk_project_files_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_files_folder FOREIGN KEY (folder_id) REFERENCES project_file_folders(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_public_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    message TEXT NULL,
    file_id INT NULL,
    client_label VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_public_events_project (project_id),
    INDEX idx_project_public_events_type (event_type),
    CONSTRAINT fk_project_public_events_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_public_events_file FOREIGN KEY (file_id) REFERENCES project_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT CLIENTS
CREATE TABLE IF NOT EXISTS project_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    client_id INT NOT NULL,
    department_id INT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'contact',
    is_primary_billing TINYINT(1) NOT NULL DEFAULT 0,
    send_project_invoices TINYINT(1) NOT NULL DEFAULT 1,
    can_view_invoice_links TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_client (project_id, client_id),
    INDEX idx_project_clients_project (project_id),
    INDEX idx_project_clients_client (client_id),
    INDEX idx_project_clients_department (department_id),
    CONSTRAINT fk_project_clients_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_clients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_clients_department FOREIGN KEY (department_id) REFERENCES organization_departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT INVOICE RECIPIENTS
-- Delivery recipients are deliberately separate from project membership. A
-- project can therefore bill a saved contact outside the owning organization,
-- or a one-off email address, without granting project access or changing the
-- billed client.
CREATE TABLE IF NOT EXISTS project_invoice_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    client_id INT NULL,
    organization_id INT NULL,
    manual_email VARCHAR(254) NULL,
    manual_name VARCHAR(190) NULL,
    recipient_key VARCHAR(300) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_recipient (project_id, recipient_key),
    INDEX idx_project_invoice_recipients_project (project_id, sort_order),
    INDEX idx_project_invoice_recipients_client (client_id),
    INDEX idx_project_invoice_recipients_organization (organization_id),
    CONSTRAINT fk_project_invoice_recipients_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoice_recipients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoice_recipients_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT chk_project_invoice_recipient_target CHECK (
        (client_id IS NOT NULL AND organization_id IS NULL AND manual_email IS NULL)
        OR (client_id IS NULL AND organization_id IS NOT NULL AND manual_email IS NULL)
        OR (client_id IS NULL AND organization_id IS NULL AND manual_email IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT INVOICES
CREATE TABLE IF NOT EXISTS project_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    organization_id INT NULL,
    primary_client_id INT NULL,
    doc_number INT NULL,
    status ENUM('draft','sent','unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
    billing_period_start DATE NOT NULL,
    billing_period_end DATE NOT NULL,
    due_date DATE NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    finalized_at TIMESTAMP NULL DEFAULT NULL,
    finalization_source VARCHAR(50) NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_checkout_expires_at DATETIME NULL,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_period (project_id, billing_period_start, billing_period_end),
    INDEX idx_project_invoices_project (project_id),
    INDEX idx_project_invoices_status (status),
    INDEX idx_project_invoices_due (due_date),
    CONSTRAINT fk_project_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoices_primary_client FOREIGN KEY (primary_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    invoice_id INT NOT NULL,
    invoice_doc_number INT NULL,
    invoice_date DATE NULL,
    invoice_due_date DATE NULL,
    invoice_status VARCHAR(50) NULL,
    invoice_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid_at_generation DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_due_at_generation DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_applied DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_child_invoice (invoice_id),
    INDEX idx_project_invoice_items_parent (project_invoice_id),
    INDEX idx_project_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_project_invoice_items_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'on_generate',
    delivery_key VARCHAR(100) NOT NULL DEFAULT 'default',
    recipient_key CHAR(64) NOT NULL DEFAULT '',
    delivery_status ENUM('pending','processing','retry','sent','suppressed') NOT NULL DEFAULT 'sent',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_attempt_at DATETIME NULL,
    claimed_at DATETIME NULL,
    last_error TEXT NULL,
    email_to VARCHAR(255) NOT NULL,
    email_subject VARCHAR(255) NULL,
    email_body TEXT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_notification_delivery (project_invoice_id, notification_type, delivery_key, recipient_key),
    INDEX idx_project_invoice_notif_parent (project_invoice_id),
    INDEX idx_project_invoice_notif_due (delivery_status, next_attempt_at),
    CONSTRAINT fk_project_invoice_notif_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    disputed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'stripe',
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    status ENUM('processing','succeeded','failed') NOT NULL DEFAULT 'processing',
    payment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_payment_session (stripe_session_id),
    UNIQUE KEY uq_project_payment_intent (stripe_payment_intent_id),
    INDEX idx_project_payment_parent (project_invoice_id),
    CONSTRAINT fk_project_payment_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ENTITY LINKS
CREATE TABLE IF NOT EXISTS entity_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    title VARCHAR(255) NULL,
    url VARCHAR(500) NOT NULL,
    link_type VARCHAR(50) NOT NULL DEFAULT 'manual',
    link_source ENUM('manual','resolver') NOT NULL DEFAULT 'manual',
    include_on_invoices TINYINT(1) NOT NULL DEFAULT 0,
    visibility_scope ENUM('entity_only','all_departments','selected_departments','org_contacts') NOT NULL DEFAULT 'entity_only',
    selected_department_ids JSON NULL,
    resolver_mode ENUM('auto_attach','review','manual_only','excluded') NOT NULL DEFAULT 'manual_only',
    expiration_date DATE NULL,
    is_expired TINYINT(1) NOT NULL DEFAULT 0,
    ignore_auto_generation TINYINT(1) NOT NULL DEFAULT 0,
    last_verified TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_link_entity (entity_type, entity_id),
    INDEX idx_link_type (link_type),
    INDEX idx_link_source (link_source),
    INDEX idx_link_invoice (include_on_invoices, is_expired),
    INDEX idx_link_visibility (visibility_scope),
    INDEX idx_link_expired (is_expired),
    INDEX idx_link_expiration (expiration_date),
    INDEX idx_link_ignore (ignore_auto_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 004: Quotes, Contracts & Invoices (Separate Tables)
-- ============================================================================

-- QUOTES
CREATE TABLE IF NOT EXISTS quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    show_contact_on_document TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','approved','denied','rejected','expired') NOT NULL DEFAULT 'draft',
    quote_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice','fixed_total','on_demand') NULL,
    price_per_invoice DECIMAL(12,2) NULL,
    invoice_count INT NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    custom_fields JSON NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quotes_client (client_id),
    INDEX idx_quotes_project (project_id),
    INDEX idx_quotes_org (organization_id),
    INDEX idx_quotes_status (status),
    INDEX idx_quotes_type (quote_type),
    INDEX idx_quotes_doc_number (doc_number),
    INDEX idx_quotes_project_code (project_code),
    CONSTRAINT fk_quotes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_quotes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_quotes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QUOTE ITEMS
CREATE TABLE IF NOT EXISTS quote_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each',
    is_travel TINYINT(1) NOT NULL DEFAULT 0,
    pricing_status ENUM('standard','estimate','variable') NOT NULL DEFAULT 'standard',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quote_items_quote (quote_id),
    INDEX idx_quote_items_sort (sort_order),
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACTS
CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    show_contact_on_document TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','active','paused','completed','cancelled','denied','void') NOT NULL DEFAULT 'pending',
    contract_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice','fixed_total','on_demand') NULL,
    price_per_invoice DECIMAL(12,2) NULL,
    total_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0,
    next_invoice_date DATE NULL,
    billing_start_mode ENUM('on_upload','manual') NULL DEFAULT 'on_upload',
    last_invoice_date DATE NULL,
    invoice_count INT NULL,
    invoices_generated INT NOT NULL DEFAULT 0,
    invoice_generation_type ENUM('set_amount','itemized','general_writeup') NOT NULL DEFAULT 'set_amount',
    signed_pdf_path VARCHAR(255) NULL,
    signed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    scheduled_date DATE NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    memo TEXT NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    custom_fields JSON NULL,
    auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
    payment_method_id INT NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contracts_client (client_id),
    INDEX idx_contracts_project (project_id),
    INDEX idx_contracts_org (organization_id),
    INDEX idx_contracts_status (status),
    INDEX idx_contracts_type (contract_type),
    INDEX idx_contracts_doc_number (doc_number),
    INDEX idx_contracts_project_code (project_code),
    INDEX idx_contracts_quote (quote_id),
    INDEX idx_contracts_next_invoice (next_invoice_date),
    INDEX idx_contracts_auto_pay (auto_pay_enabled),
    INDEX idx_contracts_stripe_sub (stripe_subscription_id),
    CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACT ITEMS
CREATE TABLE IF NOT EXISTS contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each',
    is_travel TINYINT(1) NOT NULL DEFAULT 0,
    pricing_status ENUM('standard','estimate','variable') NOT NULL DEFAULT 'standard',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contract_items_contract (contract_id),
    INDEX idx_contract_items_sort (sort_order),
    CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACT SIGNATURES
CREATE TABLE IF NOT EXISTS contract_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    signatory_type ENUM('client','admin','witness') NOT NULL DEFAULT 'client',
    signer_title VARCHAR(190) NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    signature_data TEXT NULL,
    signed_at TIMESTAMP NULL,
    signed_by_user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cs_contract (contract_id),
    INDEX idx_cs_contract_order (contract_id, display_order),
    INDEX idx_cs_type (signatory_type),
    CONSTRAINT fk_cs_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_user FOREIGN KEY (signed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACT NOTES
-- INVOICES
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    quote_id INT NULL,
    client_id INT NOT NULL,
    recipient_presentation_mode VARCHAR(32) NOT NULL DEFAULT 'named',
    project_id INT NULL,
    organization_id INT NULL,
    show_contact_on_document TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','sent','unpaid','partial','paid','overdue','cancelled','void') NOT NULL DEFAULT 'draft',
    invoice_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    is_deposit_invoice TINYINT(1) NOT NULL DEFAULT 0,
    parent_contract_type ENUM('contract','long_term_contract','on_demand_contract') NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    payment_terms_days SMALLINT UNSIGNED NULL,
    due_date_source ENUM('terms','manual') NOT NULL DEFAULT 'manual',
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    paid_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    voided_by INT NULL,
    void_reason VARCHAR(500) NULL,
    void_previous_status VARCHAR(32) NULL,
    sent_at TIMESTAMP NULL,
    finalized_at TIMESTAMP NULL,
    finalized_by INT NULL,
    finalization_source VARCHAR(50) NULL,
    collection_mode ENUM('direct','project_aggregate') NOT NULL DEFAULT 'direct',
    terms TEXT NULL,
    notes TEXT NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_checkout_expires_at DATETIME NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    last_auto_pay_attempt TIMESTAMP NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_recipient_presentation (recipient_presentation_mode),
    INDEX idx_invoices_contract (contract_id),
    INDEX idx_invoices_quote (quote_id),
    INDEX idx_invoices_project (project_id),
    INDEX idx_invoices_org (organization_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_type (invoice_type),
    INDEX idx_invoices_doc_number (doc_number),
    INDEX idx_invoices_project_code (project_code),
    INDEX idx_invoices_due_date (due_date),
    INDEX idx_invoices_voided_at (voided_at),
    INDEX idx_invoices_auto_pay_attempt (last_auto_pay_attempt),
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVOICE ITEMS
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each',
    is_travel TINYINT(1) NOT NULL DEFAULT 0,
    pricing_status ENUM('standard','estimate','variable') NOT NULL DEFAULT 'standard',
    hours DECIMAL(10,2) DEFAULT NULL,
    time_entry_id INT DEFAULT NULL,
    is_extra_charge TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_items_invoice (invoice_id),
    INDEX idx_invoice_items_sort (sort_order),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVOICE NOTIFICATIONS
CREATE TABLE IF NOT EXISTS invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'reminder',
    delivery_key VARCHAR(100) NOT NULL DEFAULT 'default',
    recipient_key CHAR(64) NOT NULL DEFAULT '',
    delivery_status ENUM('pending','processing','retry','sent','suppressed') NOT NULL DEFAULT 'sent',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_attempt_at DATETIME NULL,
    claimed_at DATETIME NULL,
    last_error TEXT NULL,
    sent_at TIMESTAMP NULL,
    email_to VARCHAR(255) NULL,
    email_subject VARCHAR(255) NULL,
    email_body TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inv_notif_invoice (invoice_id),
    INDEX idx_inv_notif_type (notification_type),
    INDEX idx_inv_notif_delivery_due (delivery_status, next_attempt_at),
    UNIQUE INDEX uq_invoice_notification_delivery (invoice_id, notification_type, delivery_key, recipient_key),
    CONSTRAINT fk_inv_notif_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- project_invoice_items is created with the Projects module, before invoices.
-- Add its cross-module foreign key only after both tables exist, and keep init
-- safe to rerun against an existing installation.
SET @has_project_invoice_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'project_invoice_items'
      AND constraint_name = 'fk_project_invoice_items_invoice'
      AND constraint_type = 'FOREIGN KEY'
);
SET @project_invoice_fk_sql := IF(
    @has_project_invoice_fk = 0,
    'ALTER TABLE project_invoice_items ADD CONSTRAINT fk_project_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE project_invoice_fk_stmt FROM @project_invoice_fk_sql;
EXECUTE project_invoice_fk_stmt;
DEALLOCATE PREPARE project_invoice_fk_stmt;

-- RECURRING INVOICES
-- RECURRING INVOICE ITEMS
-- QUOTE HISTORY
-- CONTRACT HISTORY
-- INVOICE HISTORY
-- ============================================================================
-- MODULE 005: Financial
-- ============================================================================

-- PAYMENTS
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    invoice_id INT NULL,
    job_id INT NULL,
    project_invoice_payment_id BIGINT NULL,
    contract_id INT NULL,
    organization_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    disputed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    surcharge_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    surcharge_refunded TINYINT(1) NOT NULL DEFAULT 0,
    surcharge_refund_amount DECIMAL(10,2) NULL,
    payment_method ENUM('cash', 'check', 'card', 'bank_transfer', 'stripe', 'other') NOT NULL DEFAULT 'cash',
    payment_date DATE NOT NULL,
    reference_number VARCHAR(255) NULL,
    notes TEXT NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    auto_pay_attempt TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('succeeded', 'failed', 'pending', 'reversed') NOT NULL DEFAULT 'succeeded',
    reversed_at TIMESTAMP NULL,
    reversed_by INT NULL,
    reversal_reason VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_client (client_id),
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_job (job_id),
    INDEX idx_payments_project_payment (project_invoice_payment_id),
    INDEX idx_payments_contract (contract_id),
    INDEX idx_payments_date (payment_date),
    UNIQUE KEY uq_payments_stripe_session (stripe_session_id),
    UNIQUE KEY uq_payments_stripe_pi (stripe_payment_intent_id),
    CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENT ALLOCATION CORRECTIONS
CREATE TABLE IF NOT EXISTS payment_corrections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    moved_payment_id INT NULL,
    reversed_payment_id INT NULL,
    reversed_payment_ids JSON NULL,
    source_invoice_id INT NULL,
    target_invoice_id INT NULL,
    corrected_by INT NULL,
    source_voided TINYINT(1) NOT NULL DEFAULT 0,
    cleared_local_refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    processor_refund_verified_amount DECIMAL(12,2) NULL,
    reason VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_corrections_moved (moved_payment_id),
    INDEX idx_payment_corrections_reversed (reversed_payment_id),
    INDEX idx_payment_corrections_source (source_invoice_id),
    INDEX idx_payment_corrections_target (target_invoice_id),
    INDEX idx_payment_corrections_created (created_at),
    CONSTRAINT fk_payment_correction_moved FOREIGN KEY (moved_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_reversed FOREIGN KEY (reversed_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_source FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_target FOREIGN KEY (target_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_user FOREIGN KEY (corrected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENT INTENTS
CREATE TABLE IF NOT EXISTS payment_intents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_intent_stripe (stripe_payment_intent_id),
    INDEX idx_payment_intents_invoice (invoice_id),
    CONSTRAINT fk_payment_intents_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUTO PAY LOG
CREATE TABLE IF NOT EXISTS auto_pay_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    invoice_id INT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('succeeded','failed','pending') NOT NULL DEFAULT 'pending',
    stripe_payment_intent_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auto_pay_client (client_id),
    INDEX idx_auto_pay_invoice (invoice_id),
    INDEX idx_auto_pay_status (status),
    CONSTRAINT fk_auto_pay_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_auto_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUTO PAY BETA FOUNDATION
-- Unavailable in production. No route, UI, cron job, or enabled processor uses these tables.
CREATE TABLE IF NOT EXISTS autopay_authorizations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    client_id INT NOT NULL,
    scope_type ENUM('account','contract','project') NOT NULL,
    contract_id INT NULL,
    project_id INT NULL,
    status ENUM('pending','active','revoked','expired') NOT NULL DEFAULT 'pending',
    stripe_customer_id VARCHAR(255) NULL,
    stripe_payment_method_id VARCHAR(255) NULL,
    consent_version VARCHAR(50) NOT NULL,
    consent_snapshot MEDIUMTEXT NOT NULL,
    consent_email VARCHAR(255) NOT NULL,
    consent_ip VARCHAR(45) NULL,
    consent_user_agent VARCHAR(500) NULL,
    amount_limit DECIMAL(12,2) NULL,
    variable_notice_days SMALLINT NOT NULL DEFAULT 10,
    confirmed_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_autopay_auth_client (client_id),
    INDEX idx_autopay_auth_scope (scope_type,contract_id,project_id),
    INDEX idx_autopay_auth_status (status),
    CONSTRAINT fk_autopay_auth_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_autopay_auth_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_autopay_auth_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_authorization_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    authorization_id BIGINT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_autopay_event_auth (authorization_id),
    CONSTRAINT fk_autopay_event_auth FOREIGN KEY (authorization_id) REFERENCES autopay_authorizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_scheduled_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    authorization_id BIGINT NOT NULL,
    invoice_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    scheduled_for DATETIME NOT NULL,
    status ENUM('scheduled','processing','succeeded','failed','cancelled') NOT NULL DEFAULT 'scheduled',
    idempotency_key VARCHAR(100) NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    last_error TEXT NULL,
    attempted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_autopay_attempt_idempotency (idempotency_key),
    INDEX idx_autopay_attempt_due (status,scheduled_for),
    INDEX idx_autopay_attempt_invoice (invoice_id),
    CONSTRAINT fk_autopay_attempt_auth FOREIGN KEY (authorization_id) REFERENCES autopay_authorizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_autopay_attempt_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_advance_notices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    scheduled_attempt_id BIGINT NOT NULL,
    email_to VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    charge_date DATE NOT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_autopay_notice_attempt (scheduled_attempt_id,email_to),
    CONSTRAINT fk_autopay_notice_attempt FOREIGN KEY (scheduled_attempt_id) REFERENCES autopay_scheduled_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_access_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    authorization_id BIGINT NOT NULL,
    purpose ENUM('confirm','manage','revoke','recover') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at TIMESTAMP NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_autopay_token_hash (token_hash),
    INDEX idx_autopay_token_auth (authorization_id),
    CONSTRAINT fk_autopay_token_auth FOREIGN KEY (authorization_id) REFERENCES autopay_authorizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    status ENUM('processing','processed','failed') NOT NULL DEFAULT 'processing',
    attempts SMALLINT NOT NULL DEFAULT 1,
    last_error TEXT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_event_id (stripe_event_id),
    INDEX idx_stripe_event_status (status,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_receipts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    invoice_id INT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    public_token VARCHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    email_to VARCHAR(255) NULL,
    emailed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_receipt_payment (payment_id),
    UNIQUE KEY uq_payment_receipt_number (receipt_number),
    UNIQUE KEY uq_payment_receipt_token (public_token),
    CONSTRAINT fk_payment_receipt_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_receipt_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_refunds (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stripe_refund_id VARCHAR(255) NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    payment_id INT NULL,
    project_invoice_payment_id BIGINT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL,
    reason VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_refund_id (stripe_refund_id),
    INDEX idx_stripe_refund_payment (payment_id),
    INDEX idx_stripe_refund_project_payment (project_invoice_payment_id),
    CONSTRAINT fk_stripe_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_stripe_refund_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_disputes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stripe_dispute_id VARCHAR(255) NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    payment_id INT NULL,
    project_invoice_payment_id BIGINT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL,
    reason VARCHAR(100) NULL,
    evidence_due_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_dispute_id (stripe_dispute_id),
    INDEX idx_stripe_dispute_payment (payment_id),
    INDEX idx_stripe_dispute_project_payment (project_invoice_payment_id),
    CONSTRAINT fk_stripe_dispute_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_stripe_dispute_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENT METHODS
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    provider VARCHAR(100) NULL,
    type ENUM('cash', 'check', 'card', 'bank_transfer', 'stripe', 'other') NOT NULL DEFAULT 'cash',
    config JSON NULL,
    last_four VARCHAR(4) NULL,
    exp_month INT NULL,
    exp_year INT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    stripe_payment_method_id VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pm_user (user_id),
    INDEX idx_pm_org (organization_id),
    INDEX idx_pm_provider (provider),
    CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TAX RATES
CREATE TABLE IF NOT EXISTS tax_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    country VARCHAR(100) NULL DEFAULT 'USA',
    rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    county VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    zip_code VARCHAR(10) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_org (organization_id),
    INDEX idx_tax_county (county),
    INDEX idx_tax_state (state),
    INDEX idx_tax_zip (zip_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fips_counties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    county_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fips (state_fips, county_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_jurisdictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    jurisdiction_type ENUM('state','county','city','special') NOT NULL DEFAULT 'county',
    state_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    county_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    city_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    special_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    total_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_jurisdiction_state (state_fips, county_fips),
    INDEX idx_tax_jurisdiction_code (state_fips, county_fips, jurisdiction_code),
    INDEX idx_tax_jurisdiction_county_active (state_fips, county_fips, jurisdiction_type, is_active),
    INDEX idx_tax_jurisdiction_state_code (state_fips, jurisdiction_code, jurisdiction_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_boundaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zip5_start VARCHAR(5) NOT NULL,
    zip4_start VARCHAR(4) NOT NULL,
    zip5_end VARCHAR(5) NOT NULL,
    zip4_end VARCHAR(4) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_boundaries_state_zip (state_fips, zip5_start),
    INDEX idx_tax_boundaries_zip_range (zip5_start, zip5_end),
    INDEX idx_tax_boundaries_county (state_fips, county_fips),
    INDEX idx_tax_boundaries_jurisdiction (state_fips, county_fips, jurisdiction_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_boundaries_stage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_key VARCHAR(32) NOT NULL,
    zip5_start VARCHAR(5) NOT NULL,
    zip4_start VARCHAR(4) NOT NULL,
    zip5_end VARCHAR(5) NOT NULL,
    zip4_end VARCHAR(4) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_boundary_stage_batch (batch_key),
    INDEX idx_tax_boundary_stage_state_zip (state_fips, zip5_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_zip_complexity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zip5 VARCHAR(5) NOT NULL,
    is_complex TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(50) DEFAULT NULL,
    state_fips VARCHAR(2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_zip_complexity_state_zip (state_fips, zip5),
    INDEX idx_tax_zip_complexity_zip5 (zip5),
    INDEX idx_tax_zip_complexity_state (state_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_import_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    file_type ENUM('fips','rates','boundaries') NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    content_hash CHAR(64) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state_tax_rate DECIMAL(8,4) NULL,
    imported_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_import_file (state_fips, file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_import_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    phase VARCHAR(80) NOT NULL DEFAULT 'starting',
    message VARCHAR(255) NULL,
    fips_rows INT NOT NULL DEFAULT 0,
    rate_rows INT NOT NULL DEFAULT 0,
    boundary_rows INT NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    INDEX idx_tax_import_runs_state (state_fips, started_at),
    INDEX idx_tax_import_runs_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ITEM LIBRARY
CREATE TABLE IF NOT EXISTS item_library (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    item_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    client_pricing_model ENUM('fixed','hourly','base_overage') NOT NULL DEFAULT 'fixed',
    client_included_minutes INT UNSIGNED NULL,
    client_overage_rate DECIMAL(12,4) NULL,
    pricing_currency CHAR(3) NOT NULL DEFAULT 'USD',
    category VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_item_lib_org (organization_id),
    INDEX idx_item_lib_item_name (item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- EXPENSE CATEGORIES (IRS Schedule C aligned)
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT NULL DEFAULT NULL,
    tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(7) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exp_cat_org (organization_id),
    INDEX idx_exp_cat_parent (parent_id),
    CONSTRAINT fk_exp_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_cat_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DEFAULT ORGANIZATION (seed early so financial module FKs resolve)
INSERT INTO organizations (name) VALUES ('Default Organization')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Pre-seed IRS Schedule C categories
INSERT INTO expense_categories (organization_id, name, is_system) VALUES
(NULL, 'Advertising', 1),
(NULL, 'Car & Truck Expenses', 1),
(NULL, 'Commissions & Fees', 1),
(NULL, 'Contract Labor', 1),
(NULL, 'Depletion', 1),
(NULL, 'Depreciation', 1),
(NULL, 'Employee Benefits', 1),
(NULL, 'Insurance', 1),
(NULL, 'Interest - Mortgage', 1),
(NULL, 'Interest - Other', 1),
(NULL, 'Legal & Professional Services', 1),
(NULL, 'Office Expense', 1),
(NULL, 'Pension & Profit-Sharing', 1),
(NULL, 'Rent - Equipment', 1),
(NULL, 'Rent - Vehicles/Machinery', 1),
(NULL, 'Rent - Other', 1),
(NULL, 'Repairs & Maintenance', 1),
(NULL, 'Supplies', 1),
(NULL, 'Taxes & Licenses', 1),
(NULL, 'Travel & Meals', 1),
(NULL, 'Utilities', 1),
(NULL, 'Wages', 1),
(NULL, 'Other', 1);

-- VENDORS (was receipt_stores, extended with email/phone/website/tax_id/category)
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    website VARCHAR(255) NULL,
    tax_id VARCHAR(50) NULL,
    default_category_id INT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    address TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vendor_org_name (organization_id, name),
    INDEX idx_vendor_org (organization_id),
    CONSTRAINT fk_vendor_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_vendor_default_cat FOREIGN KEY (default_category_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RECEIPTS
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    store_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    receipt_date DATE NOT NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_size BIGINT UNSIGNED NULL,
    mime_type VARCHAR(150) NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_receipt_org (organization_id),
    INDEX idx_receipt_store (store_id),
    INDEX idx_receipt_client (client_id),
    INDEX idx_receipt_project (project_id),
    INDEX idx_receipt_date (receipt_date),
    CONSTRAINT fk_receipts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_store FOREIGN KEY (store_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RECURRING EXPENSE TEMPLATES
CREATE TABLE IF NOT EXISTS recurring_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(500) NOT NULL,
    interval_count INT NOT NULL DEFAULT 1,
    interval_unit ENUM('week','month','year') NOT NULL DEFAULT 'month',
    start_date DATE NOT NULL,
    next_expense_date DATE NULL,
    end_date DATE NULL,
    last_generated_date DATE NULL,
    generated_count INT NOT NULL DEFAULT 0,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    is_tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rec_exp_org (organization_id),
    INDEX idx_rec_exp_vendor (vendor_id),
    INDEX idx_rec_exp_category (category_id),
    INDEX idx_rec_exp_client (client_id),
    INDEX idx_rec_exp_project (project_id),
    INDEX idx_rec_exp_due (status, next_expense_date),
    CONSTRAINT fk_rec_exp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- EXPENSES
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    receipt_id INT NULL DEFAULT NULL,
    recurring_expense_id INT NULL DEFAULT NULL,
    recurring_occurrence_date DATE NULL DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    total_amount DECIMAL(12,2) NULL DEFAULT NULL,
    expense_date DATE NOT NULL,
    description TEXT NULL,
    payment_method ENUM('cash','check','card','bank_transfer','paypal','venmo','other') NULL DEFAULT NULL,
    reference_number VARCHAR(255) NULL DEFAULT NULL,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    is_tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    is_reimbursed TINYINT(1) NOT NULL DEFAULT 0,
    is_reconciled TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','confirmed','reimbursed','void') NOT NULL DEFAULT 'confirmed',
    notes TEXT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exp_org (organization_id),
    INDEX idx_exp_vendor (vendor_id),
    INDEX idx_exp_category (category_id),
    INDEX idx_exp_client (client_id),
    INDEX idx_exp_project (project_id),
    INDEX idx_exp_recurring (recurring_expense_id),
    UNIQUE INDEX uq_exp_recurring_occurrence (recurring_expense_id, recurring_occurrence_date),
    INDEX idx_exp_date (expense_date),
    INDEX idx_exp_status (status),
    INDEX idx_exp_billable (is_billable),
    CONSTRAINT fk_exp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_recurring FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FINANCIAL ASSETS
CREATE TABLE IF NOT EXISTS financial_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    expense_id INT NULL DEFAULT NULL,
    asset_tag VARCHAR(80) NULL DEFAULT NULL,
    name VARCHAR(190) NOT NULL,
    asset_type VARCHAR(100) NULL DEFAULT NULL,
    serial_number VARCHAR(190) NULL DEFAULT NULL,
    status ENUM('planned','active','maintenance','retired','sold','lost','disposed') NOT NULL DEFAULT 'active',
    location VARCHAR(190) NULL DEFAULT NULL,
    purchase_date DATE NULL DEFAULT NULL,
    purchase_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    depreciation_method ENUM('none','straight_line') NOT NULL DEFAULT 'straight_line',
    depreciation_start_date DATE NULL DEFAULT NULL,
    useful_life_months INT NULL DEFAULT NULL,
    salvage_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    warranty_expires_on DATE NULL DEFAULT NULL,
    disposed_on DATE NULL DEFAULT NULL,
    disposal_value DECIMAL(12,2) NULL DEFAULT NULL,
    notes TEXT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fin_asset_org_tag (organization_id, asset_tag),
    INDEX idx_fin_asset_org (organization_id),
    INDEX idx_fin_asset_vendor (vendor_id),
    INDEX idx_fin_asset_category (category_id),
    INDEX idx_fin_asset_expense (expense_id),
    INDEX idx_fin_asset_status (status),
    INDEX idx_fin_asset_purchase_date (purchase_date),
    CONSTRAINT fk_fin_asset_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 005.1: Time Tracking
-- ============================================================================
CREATE TABLE IF NOT EXISTS time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    user_id INT NOT NULL,
    client_id INT NULL,
    project_id INT NULL,
    project_code VARCHAR(64) NULL,
    contract_id INT NULL,
    invoice_id INT NULL,
    service_item_id INT NULL,
    description TEXT NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    billable TINYINT(1) DEFAULT 1,
    billed TINYINT(1) DEFAULT 0,
    rate DECIMAL(10,2) DEFAULT 0,
    invoice_item_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_time_entries_user (user_id),
    INDEX idx_time_entries_client (client_id),
    INDEX idx_time_entries_project (project_id),
    INDEX idx_time_entries_project_code (project_code),
    INDEX idx_time_entries_contract (contract_id),
    INDEX idx_time_entries_invoice (invoice_id),
    INDEX idx_time_entries_billable (billable),
    INDEX idx_time_entries_billed (billed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MILEAGE LOGS
CREATE TABLE IF NOT EXISTS mileage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    user_id INT NULL DEFAULT NULL,
    recorded_by_user_id INT NULL DEFAULT NULL,
    traveler_user_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    source ENUM('manual','gps') NOT NULL DEFAULT 'manual',
    entry_mode ENUM('simple','total_trip') NOT NULL DEFAULT 'simple',
    trip_date DATE NOT NULL,
    start_location VARCHAR(255) NULL DEFAULT NULL,
    end_location VARCHAR(255) NULL DEFAULT NULL,
    miles DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    logged_miles DECIMAL(10,3) NULL,
    tracking_session_id BIGINT NULL,
    purpose ENUM('business','medical','moving','charitable','personal') NOT NULL DEFAULT 'business',
    description TEXT NULL,
    review_status ENUM('draft','finalized') NOT NULL DEFAULT 'finalized',
    round_trip TINYINT(1) NOT NULL DEFAULT 0,
    bill_return_trip TINYINT(1) NOT NULL DEFAULT 0,
    mileage_rate DECIMAL(5,3) NOT NULL DEFAULT 0.670,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    billed TINYINT(1) NOT NULL DEFAULT 0,
    invoice_id INT NULL DEFAULT NULL,
    invoice_item_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_org (organization_id),
    INDEX idx_mileage_recorded_by (recorded_by_user_id),
    INDEX idx_mileage_traveler (traveler_user_id),
    INDEX idx_mileage_date (trip_date),
    INDEX idx_mileage_client (client_id),
    INDEX idx_mileage_purpose (purpose),
    INDEX idx_mileage_billable_billed (is_billable, billed),
    INDEX idx_mileage_invoice (invoice_id),
    INDEX idx_mileage_tracking_session (tracking_session_id),
    CONSTRAINT fk_mileage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_recorded_by FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_traveler FOREIGN KEY (traveler_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL, client_id INT NULL, project_id INT NULL,
    name VARCHAR(150) NOT NULL,
    address_line1 VARCHAR(255) NULL, address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL, state VARCHAR(100) NULL, postal_code VARCHAR(32) NULL,
    country VARCHAR(100) NULL DEFAULT 'US', archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_location_org (organization_id), INDEX idx_service_location_client (client_id),
    INDEX idx_service_location_project (project_id),
    CONSTRAINT fk_service_location_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_location_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_location_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_location_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_mileage_origins (
    id INT AUTO_INCREMENT PRIMARY KEY, organization_id INT NULL, user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Billing origin', location_enc TEXT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_origin_org_user (organization_id,user_id),
    CONSTRAINT fk_mileage_origin_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_origin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS travel_distance_cache (
    id INT AUTO_INCREMENT PRIMARY KEY, origin_id INT NOT NULL, service_location_id INT NOT NULL,
    one_way_miles DECIMAL(10,3) NOT NULL, source ENUM('manual','routing') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_travel_distance_pair (origin_id,service_location_id),
    CONSTRAINT fk_travel_distance_origin FOREIGN KEY (origin_id) REFERENCES user_mileage_origins(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_distance_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS travel_billing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY, organization_id INT NULL,
    scope_type ENUM('organization','client','quote','contract') NOT NULL,
    client_id INT NULL, quote_id INT NULL, contract_id INT NULL,
    charge_method ENUM('actual_trip','origin_distance','fixed_fee','none') NOT NULL DEFAULT 'actual_trip',
    mileage_rate DECIMAL(10,4) NOT NULL DEFAULT 0, included_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    charge_return TINYINT(1) NOT NULL DEFAULT 0, fixed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    origin_id INT NULL, service_location_id INT NULL, estimated_one_way_miles DECIMAL(10,3) NULL,
    terms_text TEXT NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_travel_rule_scope (scope_type,organization_id,client_id,quote_id,contract_id),
    CONSTRAINT fk_travel_rule_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_travel_rule_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_rule_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_rule_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_rule_origin FOREIGN KEY (origin_id) REFERENCES user_mileage_origins(id) ON DELETE SET NULL,
    CONSTRAINT fk_travel_rule_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_travel_rule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mileage_tracking_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, organization_id INT NULL, user_id INT NOT NULL,
    status ENUM('active','draft_review','finalized','discarded') NOT NULL DEFAULT 'active',
    started_at DATETIME(3) NOT NULL, stopped_at DATETIME(3) NULL, finalized_at DATETIME(3) NULL,
    calculated_miles DECIMAL(10,3) NOT NULL DEFAULT 0, point_count INT NOT NULL DEFAULT 0,
    last_point_at DATETIME(3) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tracking_org_user_status (organization_id,user_id,status), INDEX idx_tracking_retention (status,finalized_at),
    CONSTRAINT fk_tracking_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tracking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mileage_tracking_points (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, session_id BIGINT NOT NULL, sequence_no INT NOT NULL,
    captured_at DATETIME(3) NOT NULL, latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL,
    accuracy_m DECIMAL(8,2) NULL, speed_mps DECIMAL(8,2) NULL, accepted TINYINT(1) NOT NULL DEFAULT 1,
    rejection_reason VARCHAR(60) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tracking_point_sequence (session_id,sequence_no), INDEX idx_tracking_point_time (session_id,captured_at),
    CONSTRAINT fk_tracking_point_session FOREIGN KEY (session_id) REFERENCES mileage_tracking_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mileage_logs ADD CONSTRAINT fk_mileage_tracking_session FOREIGN KEY (tracking_session_id) REFERENCES mileage_tracking_sessions(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS mileage_charge_allocations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, mileage_log_id INT NOT NULL, organization_id INT NULL,
    client_id INT NOT NULL, project_id INT NULL, contract_id INT NULL, service_location_id INT NULL, origin_id INT NULL,
    charge_method ENUM('actual_trip','origin_distance','fixed_fee') NOT NULL DEFAULT 'actual_trip',
    pricing_distance_miles DECIMAL(10,3) NOT NULL DEFAULT 0, included_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    charge_return TINYINT(1) NOT NULL DEFAULT 0, billable_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    mileage_rate DECIMAL(10,4) NOT NULL DEFAULT 0, fixed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    client_charge DECIMAL(12,2) NOT NULL DEFAULT 0, rule_snapshot JSON NULL,
    billed TINYINT(1) NOT NULL DEFAULT 0, invoice_id INT NULL, invoice_item_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_allocation_log (mileage_log_id), INDEX idx_mileage_allocation_client_unbilled (client_id,billed),
    INDEX idx_mileage_allocation_invoice (invoice_id),
    CONSTRAINT fk_mileage_allocation_log FOREIGN KEY (mileage_log_id) REFERENCES mileage_logs(id) ON DELETE CASCADE,
    CONSTRAINT fk_mileage_allocation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_mileage_allocation_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_origin FOREIGN KEY (origin_id) REFERENCES user_mileage_origins(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FORM CATEGORIES
CREATE TABLE IF NOT EXISTS form_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM('file', 'folder') NOT NULL DEFAULT 'folder',
    description TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_form_cat_org (organization_id),
    INDEX idx_form_cat_type (type),
    CONSTRAINT fk_form_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_cat_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FORM DOCUMENTS
CREATE TABLE IF NOT EXISTS form_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    category_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NULL,
    mime_type VARCHAR(150) NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NULL,
    status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_form_doc_org (organization_id),
    INDEX idx_form_doc_category (category_id),
    INDEX idx_form_doc_client (client_id),
    INDEX idx_form_doc_project (project_id),
    CONSTRAINT fk_form_docs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_category FOREIGN KEY (category_id) REFERENCES form_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_docs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DISCOUNTS
CREATE TABLE IF NOT EXISTS discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    discount_type ENUM('percent', 'fixed') NOT NULL DEFAULT 'percent',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_discounts_org (organization_id),
    INDEX idx_discounts_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 006: Audit, Notifications & System
-- ============================================================================

-- SYSTEM AUDIT
CREATE TABLE IF NOT EXISTS system_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id INT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_org (organization_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUDIT SCHEDULES
CREATE TABLE IF NOT EXISTS audit_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    report_type ENUM('audit','expense') NOT NULL DEFAULT 'audit',
    frequency ENUM('weekly', 'monthly', 'quarterly', 'annually') NOT NULL DEFAULT 'monthly',
    date_range_type VARCHAR(50) NOT NULL DEFAULT 'current_year',
    accounting_basis ENUM('cash','accrual') NOT NULL DEFAULT 'cash',
    email_addresses TEXT NOT NULL,
    include_invoices TINYINT(1) NOT NULL DEFAULT 1,
    include_unpaid_invoices TINYINT(1) NOT NULL DEFAULT 0,
    include_contracts TINYINT(1) NOT NULL DEFAULT 0,
    include_quotes TINYINT(1) NOT NULL DEFAULT 0,
    generate_csv TINYINT(1) NOT NULL DEFAULT 1,
    include_pdfs TINYINT(1) NOT NULL DEFAULT 0,
    options JSON NULL,
    filters JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    next_run_at DATETIME NULL,
    last_run_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_audit_sched_org (organization_id),
    INDEX idx_audit_sched_type (organization_id, report_type, is_active),
    INDEX idx_audit_sched_active (is_active),
    INDEX idx_audit_sched_next (next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUDIT SCHEDULE LOGS
CREATE TABLE IF NOT EXISTS audit_schedule_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    result_summary TEXT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_log_schedule (schedule_id),
    INDEX idx_audit_log_status (status),
    CONSTRAINT fk_audit_log_schedule FOREIGN KEY (schedule_id) REFERENCES audit_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTIFICATION SETTINGS
-- NOTIFICATION LOG
-- IN-APP NOTIFICATIONS
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRON JOB RUNS
CREATE TABLE IF NOT EXISTS cron_job_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    last_run DATETIME NULL,
    status ENUM('running', 'completed', 'failed', 'success') NOT NULL DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    result TEXT NULL,
    error_message TEXT NULL,
    UNIQUE KEY uq_cron_job_name (job_name),
    INDEX idx_cron_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- APP CONFIG
CREATE TABLE IF NOT EXISTS app_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 0,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_config (organization_id, config_key),
    INDEX idx_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 007: Public Links & Document Customization
-- ============================================================================

-- PUBLIC LINKS
CREATE TABLE IF NOT EXISTS public_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    document_type VARCHAR(50) NOT NULL,
    document_id INT NOT NULL,
    expires_at DATETIME NULL,
    expire_when_paid TINYINT(1) NOT NULL DEFAULT 0,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    redirect VARCHAR(500) NULL,
    access_count INT NOT NULL DEFAULT 0,
    last_accessed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_links_token (token),
    INDEX idx_public_links_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LINK RESOLVER CONFIG
CREATE TABLE IF NOT EXISTS link_resolver_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(100) NOT NULL,
    config_key VARCHAR(100) NULL,
    config_value TEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    credentials JSON NULL,
    default_expiration_days INT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_link_resolver (provider, config_key),
    INDEX idx_link_resolver_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOCUMENT CUSTOM FIELDS
CREATE TABLE IF NOT EXISTS document_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    document_type VARCHAR(50) NOT NULL DEFAULT 'quote',
    field_name VARCHAR(100) NULL,
    field_key VARCHAR(100) NULL,
    field_label VARCHAR(100) NULL,
    field_data_type VARCHAR(50) NULL,
    field_type ENUM('text', 'number', 'date', 'boolean', 'select', 'textarea') NOT NULL DEFAULT 'text',
    field_options JSON NULL,
    default_value TEXT NULL,
    min_value DECIMAL(12,2) NULL,
    max_value DECIMAL(12,2) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_cf_org (organization_id),
    INDEX idx_doc_cf_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed built-in custom fields (Deposit Required, Fulfillment Date)
INSERT IGNORE INTO document_custom_fields (document_type, field_key, field_label, field_type, is_required, is_builtin, is_enabled, display_order) VALUES
  ('regular', 'deposit', 'Deposit Required', 'text', 0, 1, 1, 1),
  ('long_term', 'deposit', 'Deposit Required', 'text', 0, 1, 1, 1),
  ('on_demand', 'deposit', 'Deposit Required', 'text', 0, 1, 1, 1),
  ('regular', 'fulfillment_date', 'Fulfillment Date (Estimated)', 'date', 0, 1, 1, 2),
  ('long_term', 'fulfillment_date', 'Fulfillment Date (Estimated)', 'date', 0, 1, 1, 2),
  ('on_demand', 'fulfillment_date', 'Fulfillment Date (Estimated)', 'date', 0, 1, 1, 2);

-- DOCUMENT SETTINGS
CREATE TABLE IF NOT EXISTS document_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 0,
    document_type VARCHAR(50) NOT NULL,
    settings JSON NULL,
    setting_key VARCHAR(100) NULL,
    setting_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_settings (organization_id, document_type, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CUSTOM FIELD VALUES
-- RATE LIMITS
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_created (identifier, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 007.1: Archive & Migration Tracking Tables
-- ============================================================================

CREATE TABLE IF NOT EXISTS archived_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    organization_id INT NULL,
    client_type ENUM('unknown','business','consumer') NULL,
    notes TEXT NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    created_at TIMESTAMP NULL,
    portal_principal_id BIGINT UNSIGNED NULL,
    portal_manual_state ENUM('automatic','revoked') NULL,
    portal_canonical_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    portal_identity_binding_ids_json JSON NULL,
    portal_principal_authorization_version INT UNSIGNED NULL,
    portal_principal_disabled_for_archive TINYINT(1) NULL,
    portal_principal_was_present TINYINT(1) NOT NULL DEFAULT 0,
    portal_entitlement_ids_json JSON NULL,
    portal_affected_workspace_ids_json JSON NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archived_clients_client (client_id),
    INDEX idx_archived_clients_org (organization_id),
    INDEX idx_archived_clients_name (name),
    INDEX idx_archived_clients_portal_principal (portal_principal_id),
    UNIQUE KEY uq_archived_clients_public_id (public_id),
    CONSTRAINT fk_archived_clients_portal_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archived_entities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    organization_id INT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    payload JSON NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arch_entities_client (client_id),
    INDEX idx_arch_entities_org (organization_id),
    INDEX idx_arch_entities_type (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 007.2: Business Integrations, Locations & Document Revisions
-- ============================================================================

CREATE TABLE IF NOT EXISTS email_provider_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('smtp','gmail') NOT NULL,
    display_name VARCHAR(150) NULL,
    sender_email VARCHAR(255) NULL,
    sender_name VARCHAR(255) NULL,
    credentials_enc LONGTEXT NOT NULL,
    status ENUM('configured','connected','reauth_required','disabled','error') NOT NULL DEFAULT 'configured',
    token_expires_at DATETIME NULL,
    last_verified_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_provider (provider),
    INDEX idx_email_provider_status (status),
    CONSTRAINT fk_email_provider_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_provider_state (
    id TINYINT NOT NULL PRIMARY KEY,
    active_connection_id INT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_state_connection FOREIGN KEY (active_connection_id) REFERENCES email_provider_connections(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_state_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO email_provider_state (id,active_connection_id) VALUES (1,NULL);

CREATE TABLE IF NOT EXISTS email_delivery_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    message_key VARCHAR(190) NOT NULL,
    provider_connection_id INT NULL,
    document_type ENUM('quote','contract','invoice','project_invoice','onboarding','notification','other') NOT NULL DEFAULT 'other',
    document_id INT NULL,
    document_revision INT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    provider_message_id VARCHAR(255) NULL,
    status ENUM('pending','sent','failed','unknown') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(1000) NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_delivery_message_key (message_key),
    INDEX idx_email_delivery_document (document_type,document_id,document_revision),
    INDEX idx_email_delivery_status (status,created_at),
    CONSTRAINT fk_email_delivery_provider FOREIGN KEY (provider_connection_id) REFERENCES email_provider_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(150) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    postal_code VARCHAR(32) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    google_place_id VARCHAR(255) NULL,
    source ENUM('manual','google') NOT NULL DEFAULT 'manual',
    legacy_key VARCHAR(190) NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_address_legacy_key (legacy_key),
    INDEX idx_address_place_id (google_place_id),
    INDEX idx_address_archived (archived),
    CONSTRAINT fk_address_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS address_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    address_id INT NOT NULL,
    entity_type ENUM('client','organization','project','job') NOT NULL,
    entity_id INT NOT NULL,
    purpose ENUM('billing','mailing','service','other') NOT NULL DEFAULT 'other',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_address_assignment (entity_type,entity_id,purpose,address_id),
    INDEX idx_address_assignment_default (entity_type,entity_id,purpose,is_default),
    CONSTRAINT fk_address_assignment_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE service_locations ADD COLUMN address_id INT NULL AFTER project_id,
    ADD INDEX idx_service_location_address (address_id),
    ADD CONSTRAINT fk_service_location_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    organization_id INT NULL,
    project_id INT NULL,
    job_code VARCHAR(64) NOT NULL,
    job_origin ENUM('planned','unscheduled_time') NOT NULL DEFAULT 'planned',
    status ENUM('not_started','active','completed','cancelled') NOT NULL DEFAULT 'not_started',
    completed_at DATETIME(6) NULL,
    default_service_location_id INT NULL,
    notes TEXT NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_client_code (client_id,job_code),
    INDEX idx_job_project (project_id),
    INDEX idx_job_created (created_at),
    INDEX idx_job_archived (archived),
    INDEX idx_job_origin_status (job_origin,status,updated_at),
    CONSTRAINT fk_job_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_location FOREIGN KEY (default_service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments ADD CONSTRAINT fk_payments_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS project_service_locations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    service_location_id INT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_service_location (project_id,service_location_id),
    INDEX idx_project_service_default (project_id,is_default),
    CONSTRAINT fk_project_service_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_migration_issues (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    job_code VARCHAR(64) NOT NULL,
    issue_code VARCHAR(80) NOT NULL,
    details JSON NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_migration_issue (client_id,job_code,issue_code),
    CONSTRAINT fk_job_migration_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE quotes ADD COLUMN job_id INT NULL AFTER project_id,
    ADD COLUMN service_location_id INT NULL AFTER job_id,
    ADD COLUMN revision_number INT NOT NULL DEFAULT 1,
    ADD COLUMN last_sent_revision INT NULL,
    ADD COLUMN revision_updated_at DATETIME NULL,
    ADD INDEX idx_quotes_job (job_id), ADD INDEX idx_quotes_service_location (service_location_id),
    ADD CONSTRAINT fk_quotes_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_quotes_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL;

ALTER TABLE contracts ADD COLUMN job_id INT NULL AFTER project_id,
    ADD COLUMN service_location_id INT NULL AFTER job_id,
    ADD COLUMN revision_number INT NOT NULL DEFAULT 1,
    ADD COLUMN last_sent_revision INT NULL,
    ADD COLUMN revision_updated_at DATETIME NULL,
    ADD COLUMN signed_revision_number INT NULL,
    ADD COLUMN signed_pdf_sha256 CHAR(64) NULL,
    ADD INDEX idx_contracts_job (job_id), ADD INDEX idx_contracts_service_location (service_location_id),
    ADD CONSTRAINT fk_contracts_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_contracts_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL;

ALTER TABLE invoices ADD COLUMN job_id INT NULL AFTER project_id,
    ADD COLUMN service_location_id INT NULL AFTER job_id,
    ADD COLUMN revision_number INT NOT NULL DEFAULT 1,
    ADD COLUMN last_sent_revision INT NULL,
    ADD COLUMN revision_updated_at DATETIME NULL,
    ADD COLUMN credit_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD INDEX idx_invoices_job (job_id), ADD INDEX idx_invoices_service_location (service_location_id),
    ADD CONSTRAINT fk_invoices_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_invoices_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS document_revisions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('quote','contract','invoice') NOT NULL,
    document_id INT NOT NULL,
    revision_number INT NOT NULL,
    snapshot JSON NOT NULL,
    content_hash CHAR(64) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_revision (document_type,document_id,revision_number),
    INDEX idx_document_revision_created (created_at),
    CONSTRAINT fk_document_revision_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_deliveries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('quote','contract','invoice') NOT NULL,
    document_id INT NOT NULL,
    revision_number INT NOT NULL,
    email_delivery_id BIGINT NULL,
    recipient VARCHAR(255) NULL,
    delivered_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_delivery (document_type,document_id,revision_number),
    CONSTRAINT fk_document_delivery_email FOREIGN KEY (email_delivery_id) REFERENCES email_delivery_log(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_address_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('quote','contract','invoice') NOT NULL,
    document_id INT NOT NULL,
    revision_number INT NOT NULL,
    purpose ENUM('billing','service') NOT NULL,
    address_id INT NULL,
    snapshot JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_address_snapshot (document_type,document_id,revision_number,purpose),
    CONSTRAINT fk_document_snapshot_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_adjustments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    adjustment_type ENUM('charge','credit') NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    revision_number INT NOT NULL DEFAULT 1,
    superseded_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_adjustment_invoice (invoice_id),
    INDEX idx_invoice_adjustment_current (invoice_id,superseded_at,revision_number),
    CONSTRAINT fk_invoice_adjustment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_adjustment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_estimate_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_mileage_origin_id INT NOT NULL,
    service_location_id INT NOT NULL,
    travel_mode ENUM('DRIVE') NOT NULL DEFAULT 'DRIVE',
    distance_miles DECIMAL(10,3) NOT NULL,
    duration_seconds INT NULL,
    provider ENUM('google_routes') NOT NULL DEFAULT 'google_routes',
    attribution VARCHAR(100) NOT NULL DEFAULT 'Google Maps',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_route_estimate_pair (user_mileage_origin_id,service_location_id,travel_mode),
    INDEX idx_route_estimate_expiry (expires_at),
    CONSTRAINT fk_route_estimate_origin FOREIGN KEY (user_mileage_origin_id) REFERENCES user_mileage_origins(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_estimate_destination FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    job_id INT NULL,
    service_location_id INT NULL,
    title VARCHAR(255) NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 1,
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Chicago',
    status ENUM('planned','confirmed','completed','cancelled') NOT NULL DEFAULT 'planned',
    source_type ENUM('project','job','manual') NOT NULL,
    source_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_schedule_range (starts_at,ends_at), INDEX idx_schedule_project (project_id), INDEX idx_schedule_job (job_id),
    UNIQUE KEY uq_schedule_source (source_type,source_id),
    CONSTRAINT fk_schedule_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    worker_name_snapshot VARCHAR(255) NOT NULL,
    worker_email_snapshot VARCHAR(255) NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'other',
    title VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    signed_on DATE NULL,
    expires_on DATE NULL,
    status ENUM('current','archived') NOT NULL DEFAULT 'current',
    worker_visible TINYINT(1) NOT NULL DEFAULT 0,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    uploaded_by INT NULL,
    archived_by INT NULL,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_worker_document_path (file_path),
    INDEX idx_worker_documents_user_status (user_id,status,created_at),
    INDEX idx_worker_documents_expiry (expires_on,status),
    INDEX idx_worker_documents_hash (content_sha256),
    CONSTRAINT fk_worker_document_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_document_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_document_archiver FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The current baseline includes the authoritative time record because later
-- workforce tables reference it. Migration 0039 remains idempotent for
-- upgraded and fresh databases and creates the rest of the timekeeping set.
CREATE TABLE IF NOT EXISTS work_time_entries (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    project_id INT NULL,
    start_time DATETIME(6) NOT NULL,
    end_time DATETIME(6) NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NOT NULL,
    tags JSON NOT NULL,
    billable TINYINT(1) NOT NULL DEFAULT 0,
    is_payable TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('running','review','rejected','approved','voided','cancelled') NOT NULL DEFAULT 'review',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    rejection_reason VARCHAR(1000) NOT NULL DEFAULT '',
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_work_time_user_start (user_id,start_time),
    INDEX idx_work_time_project_status (project_id,status),
    INDEX idx_work_time_review (status,start_time),
    CONSTRAINT fk_work_time_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_time_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_time_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Workforce identities, scoped access, catalog fulfillment, assignment pay,
-- pay periods, and immutable catalog/document compensation snapshots.

INSERT INTO app_config (organization_id,config_key,config_value) VALUES
    (0,'workforce_pay_period_cadence','biweekly'),
    (0,'workforce_pay_period_anchor',''),
    (0,'workforce_pay_period_custom_days','14'),
    (0,'workforce_period_deadline_time','20:00'),
    (0,'workforce_period_auto_confirm','1')
ON DUPLICATE KEY UPDATE config_value=config_value;

CREATE TABLE IF NOT EXISTS business_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(32) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_business_unit_code (code),
    INDEX idx_business_unit_active (is_active,name),
    CONSTRAINT fk_business_unit_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE projects
    ADD CONSTRAINT fk_projects_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL;

ALTER TABLE projects
    ADD CONSTRAINT fk_projects_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS worker_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    relationship_type VARCHAR(50) NOT NULL DEFAULT 'employee',
    relationship_review_required TINYINT(1) NOT NULL DEFAULT 0,
    relationship_review_reason VARCHAR(255) NULL,
    relationship_reviewed_by INT NULL,
    relationship_reviewed_at DATETIME(6) NULL,
    time_review_policy ENUM('manager_review','self_confirm','auto_confirm') NOT NULL DEFAULT 'manager_review',
    compensation_policy ENUM('rules','nonpayable','owner_no_pay','needs_setup','needs_review') NOT NULL DEFAULT 'rules',
    status ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    owner_internal_cost_rate DECIMAL(12,4) NULL,
    hired_at DATE NULL,
    ended_at DATE NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_profile_user (user_id),
    INDEX idx_worker_profile_status (status,relationship_type),
    INDEX idx_worker_relationship_review (relationship_review_required,status),
    CONSTRAINT fk_worker_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_relationship_reviewer FOREIGN KEY (relationship_reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_worker_document_profile := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_documents' AND column_name='worker_profile_id');
SET @sql := IF(@has_worker_document_profile=0, 'ALTER TABLE worker_documents ADD COLUMN worker_profile_id INT NULL AFTER user_id, ADD INDEX idx_worker_documents_profile_status (worker_profile_id,status,created_at), ADD CONSTRAINT fk_worker_document_profile FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS worker_business_units (
    worker_profile_id INT NOT NULL,
    business_unit_id INT NOT NULL,
    is_lead TINYINT(1) NOT NULL DEFAULT 0,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ends_at DATETIME(6) NULL,
    PRIMARY KEY (worker_profile_id,business_unit_id),
    INDEX idx_worker_unit_active (business_unit_id,ends_at),
    CONSTRAINT fk_worker_unit_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_unit_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_unit_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_business_units (
    client_id INT NOT NULL,
    business_unit_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (client_id,business_unit_id),
    INDEX idx_client_unit_unit (business_unit_id,client_id),
    CONSTRAINT fk_client_unit_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_unit_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_unit_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_capability_scopes (
    worker_profile_id INT NOT NULL,
    capability VARCHAR(100) NOT NULL,
    access_scope ENUM('own','assigned','business_unit','all') NOT NULL DEFAULT 'own',
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    granted_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (worker_profile_id,capability),
    INDEX idx_worker_capability_lookup (capability,access_scope,allowed),
    CONSTRAINT fk_worker_capability_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_capability_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(64) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    default_compensation_method ENUM('nonpayable','hourly','fixed','base_overage','percentage') NOT NULL DEFAULT 'nonpayable',
    default_amount DECIMAL(12,4) NULL,
    default_base_minutes INT UNSIGNED NULL,
    default_overage_rate DECIMAL(12,4) NULL,
    default_percentage DECIMAL(7,4) NULL,
    default_percentage_basis ENUM('gross_line','net_line','cash_collected') NOT NULL DEFAULT 'net_line',
    default_eligibility_trigger ENUM('completed_approved','delivered','invoice_paid','manual_release') NOT NULL DEFAULT 'completed_approved',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_work_type_code (code),
    INDEX idx_work_type_active (is_active,name),
    CONSTRAINT fk_work_type_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_item_entry_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='entry_type');
SET @sql := IF(@has_item_entry_type=0, 'ALTER TABLE item_library ADD COLUMN entry_type ENUM(''product'',''service'',''fee'',''bundle'') NOT NULL DEFAULT ''product'' AFTER description, ADD COLUMN billing_unit ENUM(''each'',''hour'',''day'',''mile'',''project'') NOT NULL DEFAULT ''each'' AFTER unit_price, ADD COLUMN tax_behavior ENUM(''inherit'',''taxable'',''exempt'') NOT NULL DEFAULT ''inherit'' AFTER billing_unit, ADD COLUMN fulfillment_notes TEXT NULL AFTER tax_behavior, ADD INDEX idx_item_lib_type_active (entry_type,is_active)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- All document line billing units must be able to snapshot every catalog unit.
ALTER TABLE quote_items MODIFY COLUMN billing_unit ENUM('each','hour','day','mile','project') NOT NULL DEFAULT 'each';
ALTER TABLE contract_items MODIFY COLUMN billing_unit ENUM('each','hour','day','mile','project') NOT NULL DEFAULT 'each';
ALTER TABLE invoice_items MODIFY COLUMN billing_unit ENUM('each','hour','day','mile','project') NOT NULL DEFAULT 'each';

CREATE TABLE IF NOT EXISTS catalog_bundle_items (
    bundle_item_library_id INT NOT NULL,
    child_item_library_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (bundle_item_library_id,child_item_library_id),
    INDEX idx_catalog_bundle_child (child_item_library_id),
    CONSTRAINT fk_catalog_bundle_parent FOREIGN KEY (bundle_item_library_id) REFERENCES item_library(id) ON DELETE CASCADE,
    CONSTRAINT fk_catalog_bundle_child FOREIGN KEY (child_item_library_id) REFERENCES item_library(id) ON DELETE RESTRICT,
    CONSTRAINT chk_catalog_bundle_quantity CHECK (quantity > 0),
    CONSTRAINT chk_catalog_bundle_not_self CHECK (bundle_item_library_id <> child_item_library_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_work_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_library_id INT NOT NULL,
    work_type_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    quantity_behavior ENUM('per_line','per_unit','fixed') NOT NULL DEFAULT 'per_line',
    fixed_quantity DECIMAL(10,2) NULL,
    expected_duration_minutes INT UNSIGNED NULL,
    assignment_required TINYINT(1) NOT NULL DEFAULT 1,
    client_billing_treatment ENUM('hourly','fixed_price_included','base_overage','internal') NOT NULL DEFAULT 'fixed_price_included',
    client_billing_rate DECIMAL(12,4) NULL,
    client_included_minutes INT UNSIGNED NULL,
    client_overage_rate DECIMAL(12,4) NULL,
    client_billing_currency CHAR(3) NOT NULL DEFAULT 'USD',
    compensation_method ENUM('nonpayable','hourly','fixed','base_overage','percentage') NOT NULL DEFAULT 'nonpayable',
    compensation_amount DECIMAL(12,4) NULL,
    included_minutes INT UNSIGNED NULL,
    overage_rate DECIMAL(12,4) NULL,
    percentage DECIMAL(7,4) NULL,
    percentage_basis ENUM('gross_line','net_line','cash_collected') NOT NULL DEFAULT 'net_line',
    eligibility_trigger ENUM('completed_approved','delivered','invoice_paid','manual_release') NOT NULL DEFAULT 'completed_approved',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    active_item_library_id INT GENERATED ALWAYS AS (IF(is_active=1,item_library_id,NULL)) STORED,
    active_work_type_id INT GENERATED ALWAYS AS (IF(is_active=1,work_type_id,NULL)) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_catalog_component_item (item_library_id,is_active,display_order),
    INDEX idx_catalog_component_work_type (work_type_id),
    UNIQUE KEY uq_catalog_active_service_link (active_item_library_id),
    UNIQUE KEY uq_catalog_active_activity_link (active_work_type_id),
    CONSTRAINT fk_catalog_component_item FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE RESTRICT,
    CONSTRAINT fk_catalog_component_work_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_link_migration_review (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    catalog_work_component_id INT NOT NULL,
    item_library_id INT NOT NULL,
    work_type_id INT NOT NULL,
    conflict_type ENUM('service_multiple_activities','activity_multiple_services') NOT NULL,
    review_status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
    details JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    resolved_at DATETIME(6) NULL,
    UNIQUE KEY uq_catalog_link_review (catalog_work_component_id,conflict_type),
    INDEX idx_catalog_link_review_status (review_status,conflict_type),
    CONSTRAINT fk_catalog_link_review_component FOREIGN KEY (catalog_work_component_id)
      REFERENCES catalog_work_components(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_compensation_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    worker_profile_id INT NOT NULL,
    work_type_id INT NULL,
    catalog_work_component_id INT NULL,
    compensation_method ENUM('nonpayable','hourly','fixed','base_overage','percentage') NOT NULL,
    compensation_amount DECIMAL(12,4) NULL,
    included_minutes INT UNSIGNED NULL,
    overage_rate DECIMAL(12,4) NULL,
    percentage DECIMAL(7,4) NULL,
    percentage_basis ENUM('gross_line','net_line','cash_collected') NOT NULL DEFAULT 'net_line',
    eligibility_trigger ENUM('completed_approved','delivered','invoice_paid','manual_release') NOT NULL DEFAULT 'completed_approved',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_worker_rule_component (worker_profile_id,catalog_work_component_id,effective_from),
    INDEX idx_worker_rule_type (worker_profile_id,work_type_id,effective_from),
    CONSTRAINT fk_worker_rule_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_rule_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_rule_component FOREIGN KEY (catalog_work_component_id) REFERENCES catalog_work_components(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_rule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_worker_rule_scope CHECK ((work_type_id IS NOT NULL) <> (catalog_work_component_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorphic document-line references intentionally retain the immutable source
-- even if a document is later voided. Only the catalog entry has a real FK.
SET @has_quote_catalog := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quote_items' AND column_name='item_library_id');
SET @sql := IF(@has_quote_catalog=0, 'ALTER TABLE quote_items ADD COLUMN item_library_id INT NULL AFTER quote_id, ADD COLUMN catalog_snapshot JSON NULL AFTER pricing_status, ADD INDEX idx_quote_item_catalog (item_library_id), ADD CONSTRAINT fk_quote_item_catalog FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_contract_catalog := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contract_items' AND column_name='item_library_id');
SET @sql := IF(@has_contract_catalog=0, 'ALTER TABLE contract_items ADD COLUMN item_library_id INT NULL AFTER contract_id, ADD COLUMN catalog_snapshot JSON NULL AFTER pricing_status, ADD INDEX idx_contract_item_catalog (item_library_id), ADD CONSTRAINT fk_contract_item_catalog FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_invoice_catalog := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoice_items' AND column_name='item_library_id');
SET @sql := IF(@has_invoice_catalog=0, 'ALTER TABLE invoice_items ADD COLUMN item_library_id INT NULL AFTER invoice_id, ADD COLUMN catalog_snapshot JSON NULL AFTER pricing_status, ADD INDEX idx_invoice_item_catalog (item_library_id), ADD CONSTRAINT fk_invoice_item_catalog FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS job_work_components (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    item_library_id INT NULL,
    catalog_work_component_id INT NULL,
    work_type_id INT NOT NULL,
    source_type ENUM('quote','contract','invoice','catalog','manual','time_entry') NOT NULL,
    source_document_id INT NULL,
    source_line_id INT NULL,
    source_revision INT NULL,
    idempotency_key CHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    planned_quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    expected_duration_minutes INT UNSIGNED NULL,
    assignment_required TINYINT(1) NOT NULL DEFAULT 1,
    compensation_snapshot JSON NOT NULL,
    client_billing_treatment_snapshot ENUM('hourly','fixed_price_included','base_overage','internal') NULL,
    client_billing_rate_snapshot DECIMAL(12,4) NULL,
    client_included_minutes_snapshot INT UNSIGNED NULL,
    client_overage_rate_snapshot DECIMAL(12,4) NULL,
    client_billing_currency_snapshot CHAR(3) NULL,
    status ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_job_work_idempotency (idempotency_key),
    INDEX idx_job_work_job_status (job_id,status),
    INDEX idx_job_work_source (source_type,source_document_id,source_line_id),
    CONSTRAINT fk_job_work_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_job_work_item FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_work_catalog_component FOREIGN KEY (catalog_work_component_id) REFERENCES catalog_work_components(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_work_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_job_work_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_work_component_id BIGINT NOT NULL,
    worker_profile_id INT NULL,
    status ENUM('planned','offered','accepted','declined','in_progress','completed','eligible','approved_payable','settled','cancelled') NOT NULL DEFAULT 'planned',
    compensation_override JSON NULL,
    compensation_snapshot JSON NULL,
    eligibility_snapshot JSON NULL,
    estimated_pay DECIMAL(12,2) NULL,
    approved_pay DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    offered_by INT NULL,
    offered_at DATETIME(6) NULL,
    responded_at DATETIME(6) NULL,
    decline_reason VARCHAR(1000) NULL,
    completed_at DATETIME(6) NULL,
    eligible_at DATETIME(6) NULL,
    eligible_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME(6) NULL,
    settled_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_assignment_worker_status (worker_profile_id,status,offered_at),
    INDEX idx_assignment_component_status (job_work_component_id,status),
    CONSTRAINT fk_assignment_component FOREIGN KEY (job_work_component_id) REFERENCES job_work_components(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_offerer FOREIGN KEY (offered_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_eligibility_actor FOREIGN KEY (eligible_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_work_job := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='job_id');
SET @sql := IF(@has_work_job=0, 'ALTER TABLE work_time_entries ADD COLUMN job_id INT NULL AFTER project_id, ADD COLUMN work_type_id INT NULL AFTER job_id, ADD COLUMN work_assignment_id BIGINT NULL AFTER work_type_id, ADD COLUMN entry_mode ENUM(''timer'',''exact'',''duration'') NOT NULL DEFAULT ''exact'' AFTER work_assignment_id, ADD COLUMN owner_self_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_payable, ADD COLUMN internal_cost_rate DECIMAL(12,4) NULL AFTER owner_self_confirmed, ADD INDEX idx_work_time_job (job_id,status), ADD INDEX idx_work_time_type (work_type_id), ADD INDEX idx_work_time_assignment (work_assignment_id), ADD CONSTRAINT fk_work_time_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL, ADD CONSTRAINT fk_work_time_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE SET NULL, ADD CONSTRAINT fk_work_time_assignment FOREIGN KEY (work_assignment_id) REFERENCES work_assignments(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS pay_periods (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    cadence ENUM('weekly','biweekly','semimonthly','monthly','custom') NOT NULL DEFAULT 'biweekly',
    status ENUM('open','closing','closed') NOT NULL DEFAULT 'open',
    closed_by INT NULL,
    closed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_pay_period_dates (period_start,period_end),
    INDEX idx_pay_period_status (status,period_start),
    CONSTRAINT fk_pay_period_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_pay_period_dates CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_period_submissions (
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    status ENUM('not_submitted','submitted','accepted','adjusted') NOT NULL DEFAULT 'not_submitted',
    submitted_at DATETIME(6) NULL,
    accepted_by INT NULL,
    accepted_at DATETIME(6) NULL,
    notes TEXT NULL,
    PRIMARY KEY (pay_period_id,worker_profile_id),
    CONSTRAINT fk_period_submission_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_period_submission_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_period_submission_acceptor FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_statements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    statement_type ENUM('employee_pay','contractor_settlement') NOT NULL,
    status ENUM('draft','issued','settled','voided') NOT NULL DEFAULT 'draft',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    adjustment_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    contractor_invoice_path VARCHAR(500) NULL,
    contractor_invoice_sha256 CHAR(64) NULL,
    issued_at DATETIME(6) NULL,
    settled_at DATETIME(6) NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_statement_period (pay_period_id,worker_profile_id),
    INDEX idx_worker_statement_status (worker_profile_id,status),
    CONSTRAINT fk_worker_statement_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_statement_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_statement_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_statement_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    worker_statement_id BIGINT NOT NULL,
    work_assignment_id BIGINT NULL,
    work_time_entry_id CHAR(36) NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 1,
    rate DECIMAL(12,4) NULL,
    amount DECIMAL(12,2) NOT NULL,
    calculation_snapshot JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_statement_assignment (worker_statement_id,work_assignment_id),
    UNIQUE KEY uq_statement_time_entry (worker_statement_id,work_time_entry_id),
    INDEX idx_statement_line_time (work_time_entry_id),
    CONSTRAINT fk_statement_line_statement FOREIGN KEY (worker_statement_id) REFERENCES worker_statements(id) ON DELETE CASCADE,
    CONSTRAINT fk_statement_line_assignment FOREIGN KEY (work_assignment_id) REFERENCES work_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_statement_line_time FOREIGN KEY (work_time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compensation_adjustments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    worker_profile_id INT NOT NULL,
    pay_period_id BIGINT NOT NULL,
    source_assignment_id BIGINT NULL,
    adjustment_type ENUM('credit','debit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    source_snapshot JSON NULL,
    status ENUM('pending','reviewed','applied','voided') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    statement_line_id BIGINT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_comp_adjustment_period (pay_period_id,worker_profile_id,status),
    CONSTRAINT fk_comp_adjustment_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_comp_adjustment_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_comp_adjustment_assignment FOREIGN KEY (source_assignment_id) REFERENCES work_assignments(id) ON DELETE SET NULL,
    CONSTRAINT fk_comp_adjustment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_comp_adjustment_line FOREIGN KEY (statement_line_id) REFERENCES worker_statement_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_comp_adjustment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_mileage_traveler_worker := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='traveler_worker_id');
SET @sql := IF(@has_mileage_traveler_worker=0, 'ALTER TABLE mileage_logs ADD COLUMN traveler_worker_id INT NULL AFTER user_id, ADD COLUMN financial_treatment ENUM(''organization_mileage'',''worker_reimbursement'',''contractor_record_only'',''nonreimbursable'') NOT NULL DEFAULT ''organization_mileage'' AFTER traveler_worker_id, ADD INDEX idx_mileage_worker_treatment (traveler_worker_id,financial_treatment,trip_date), ADD CONSTRAINT fk_mileage_traveler_worker FOREIGN KEY (traveler_worker_id) REFERENCES worker_profiles(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Employee profiles are introduced by migration 0039 after the baseline. Seed
-- installation owners here; migration 0045 adds existing employees on upgrade.
INSERT INTO worker_profiles (user_id,relationship_type,status,display_name,currency,hired_at,ended_at)
SELECT u.id,
       'owner',
       CASE WHEN u.is_disabled=1 OR u.deleted_at IS NOT NULL THEN 'inactive' ELSE 'active' END,
       COALESCE(NULLIF(u.username,''),u.email),
       'USD',NULL,NULL
FROM users u
WHERE u.role IN ('admin','owner')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),status=VALUES(status);

UPDATE worker_documents d
JOIN worker_profiles wp ON wp.user_id=d.user_id
SET d.worker_profile_id=wp.id
WHERE d.worker_profile_id IS NULL;

UPDATE item_library
SET entry_type='service',billing_unit='hour'
WHERE category='Hourly';

UPDATE mileage_logs m
JOIN worker_profiles wp ON wp.user_id=m.user_id
SET m.traveler_worker_id=wp.id,
    m.financial_treatment=CASE
        WHEN wp.relationship_type='contractor' THEN 'contractor_record_only'
        WHEN wp.relationship_type='employee' THEN 'worker_reimbursement'
        ELSE 'organization_mileage'
    END
WHERE m.traveler_worker_id IS NULL;

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r JOIN (
    SELECT 'workforce.catalog.manage' permission UNION ALL
    SELECT 'workforce.assignments.manage' UNION ALL
    SELECT 'workforce.pay_periods.manage' UNION ALL
    SELECT 'workforce.statements.manage' UNION ALL
    SELECT 'workforce.business_units.manage'
) p
WHERE r.name IN ('admin','owner') AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r JOIN (
    SELECT 'workforce.assignments.self' permission UNION ALL
    SELECT 'workforce.statements.self' UNION ALL
    SELECT 'workforce.directory.search'
) p
WHERE r.name='employee' AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);


CREATE TABLE IF NOT EXISTS passkey_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id VARBINARY(1024) NOT NULL,
    user_handle VARBINARY(64) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    credential_record LONGTEXT NOT NULL,
    signature_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports JSON NULL,
    aaguid CHAR(36) NULL,
    backup_eligible TINYINT(1) NULL,
    backup_status TINYINT(1) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_by INT NULL,
    UNIQUE KEY uq_passkey_credential_id (credential_id),
    INDEX idx_passkey_user_active (user_id,revoked_at),
    INDEX idx_passkey_user_handle (user_handle),
    CONSTRAINT fk_passkey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_passkey_revoker FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_passkey_record_json CHECK (JSON_VALID(credential_record)),
    CONSTRAINT chk_passkey_display_name CHECK (CHAR_LENGTH(TRIM(display_name)) BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passkey_challenges (
    id CHAR(64) PRIMARY KEY,
    user_id INT NULL,
    ceremony ENUM('registration','authentication') NOT NULL,
    challenge_hash BINARY(32) NOT NULL,
    session_hash BINARY(32) NOT NULL,
    context_json JSON NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_passkey_challenge_expiry (expires_at,consumed_at),
    INDEX idx_passkey_challenge_user (user_id,ceremony,created_at),
    CONSTRAINT fk_passkey_challenge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passkey_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    ceremony ENUM('registration','authentication','management') NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    failure_code VARCHAR(80) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_passkey_attempt_ip (ip_address,ceremony,attempted_at),
    INDEX idx_passkey_attempt_user (user_id,ceremony,attempted_at),
    CONSTRAINT fk_passkey_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OPTIONAL EXTERNAL OPERATIONS INTEGRATION
CREATE TABLE IF NOT EXISTS application_entitlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    application_key VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    manual_enabled TINYINT(1) NOT NULL DEFAULT 0,
    automatic_enabled TINYINT(1) NOT NULL DEFAULT 0,
    oversight_enabled TINYINT(1) NOT NULL DEFAULT 0,
    role_key VARCHAR(64) NOT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_application_entitlement (user_id, application_key),
    INDEX idx_application_entitlement_app (application_key, enabled, user_id),
    INDEX idx_application_entitlement_effective (application_key,enabled,manual_enabled,automatic_enabled,user_id),
    CONSTRAINT fk_application_entitlement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_application_entitlement_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_application_entitlement_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_application_entitlement_role CHECK (role_key IN ('role-admin','role-operator'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_entitlement_business_units (
    entitlement_id BIGINT UNSIGNED NOT NULL,
    business_unit_id INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (entitlement_id, business_unit_id),
    INDEX idx_entitlement_business_unit (business_unit_id, entitlement_id),
    CONSTRAINT fk_entitlement_scope_entitlement FOREIGN KEY (entitlement_id) REFERENCES application_entitlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_entitlement_scope_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_unit_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT NOT NULL,
    user_id INT NOT NULL,
    membership_role ENUM('member','head') NOT NULL DEFAULT 'member',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ended_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_business_unit_membership_pair (business_unit_id,user_id,ended_at),
    INDEX idx_business_unit_membership_user (user_id,ended_at,is_primary),
    INDEX idx_business_unit_membership_unit (business_unit_id,ended_at,membership_role),
    CONSTRAINT fk_business_unit_membership_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_business_unit_membership_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_business_unit_membership_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_entitlement_oversight_units (
    entitlement_id BIGINT UNSIGNED NOT NULL,
    business_unit_id INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (entitlement_id, business_unit_id),
    INDEX idx_entitlement_oversight_unit (business_unit_id, entitlement_id),
    CONSTRAINT fk_entitlement_oversight_entitlement FOREIGN KEY (entitlement_id) REFERENCES application_entitlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_entitlement_oversight_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL,
    integration_key VARCHAR(64) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    payload_json JSON NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    delivered_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_integration_outbox_event (event_id),
    INDEX idx_integration_outbox_due (integration_key, delivered_at, next_attempt_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    business_unit_id INT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    scheduled_start_at DATETIME(6) NULL,
    scheduled_end_at DATETIME(6) NULL,
    location VARCHAR(500) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_operations_project (project_id, status, scheduled_start_at),
    INDEX idx_operations_business_unit (business_unit_id, status, scheduled_start_at),
    CONSTRAINT fk_operations_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_operations_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_operations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_operations_status CHECK (status IN ('draft','scheduled','in_progress','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operation_assignments (
    operation_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    assignment_role VARCHAR(100) NULL,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (operation_id, user_id),
    INDEX idx_operation_assignment_user (user_id, operation_id),
    CONSTRAINT fk_operation_assignment_operation FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE CASCADE,
    CONSTRAINT fk_operation_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_operation_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id BIGINT UNSIGNED NULL,
    project_id INT NOT NULL,
    business_unit_id INT NULL,
    assignee_user_id INT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'todo',
    due_at DATETIME(6) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_tasks_project (project_id, status, due_at),
    INDEX idx_tasks_operation (operation_id, status, due_at),
    INDEX idx_tasks_assignee (assignee_user_id, status, due_at),
    INDEX idx_tasks_business_unit (business_unit_id, status, due_at),
    CONSTRAINT fk_tasks_operation FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_tasks_status CHECK (status IN ('todo','in_progress','blocked','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_assignments (
    task_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (task_id, user_id),
    INDEX idx_task_assignment_user (user_id, task_id),
    CONSTRAINT fk_task_assignment_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canonical Workforce workflow foundation. Legacy status, pay-accrual, and
-- billing-projection fields remain present for read compatibility.
ALTER TABLE work_time_entries
    ADD COLUMN worker_profile_id INT NULL AFTER user_id,
    ADD COLUMN entered_by_user_id INT NULL AFTER worker_profile_id,
    ADD COLUMN workflow_status ENUM('running','draft','submitted','returned','confirmed','voided') NOT NULL DEFAULT 'draft' AFTER status,
    ADD COLUMN billing_state ENUM('decide_later','internal','fixed_price_included','rate_needed','ready','partially_invoiced','invoiced','reversed') NOT NULL DEFAULT 'decide_later' AFTER workflow_status,
    ADD COLUMN compensation_state ENUM('owner_no_pay','nonpayable','needs_setup','provisional','eligible','approved','included','settled','adjusted','voided') NOT NULL DEFAULT 'provisional' AFTER billing_state,
    ADD COLUMN submitted_at DATETIME(6) NULL AFTER rejection_reason,
    ADD INDEX idx_work_time_worker_status (worker_profile_id,status,start_time),
    ADD INDEX idx_work_time_recorder (entered_by_user_id,created_at),
    ADD INDEX idx_work_time_workflow (workflow_status,start_time,worker_profile_id),
    ADD INDEX idx_work_time_billing_state (billing_state,workflow_status),
    ADD INDEX idx_work_time_compensation_state (compensation_state,workflow_status),
    ADD CONSTRAINT fk_work_time_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_work_time_recorder FOREIGN KEY (entered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS time_submissions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    submission_sequence INT UNSIGNED NOT NULL,
    status ENUM('submitted','partially_reviewed','returned','confirmed','voided') NOT NULL DEFAULT 'submitted',
    source ENUM('workflow','legacy_backfill') NOT NULL DEFAULT 'workflow',
    legacy_submission_key VARCHAR(190) NULL,
    notes TEXT NULL,
    submitted_by INT NULL,
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_submission_sequence (pay_period_id,worker_profile_id,submission_sequence),
    UNIQUE KEY uq_time_submission_legacy (legacy_submission_key),
    INDEX idx_time_submission_review (status,submitted_at,worker_profile_id),
    CONSTRAINT fk_time_submission_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_submission_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_submission_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_submission_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_submission_entries (
    submission_id CHAR(36) NOT NULL,
    time_entry_id CHAR(36) NOT NULL,
    entry_revision INT UNSIGNED NOT NULL,
    entry_snapshot JSON NOT NULL,
    decision ENUM('pending','confirmed','returned','withdrawn','voided') NOT NULL DEFAULT 'pending',
    decision_reason VARCHAR(1000) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (submission_id,time_entry_id),
    INDEX idx_submission_entry_time (time_entry_id,entry_revision),
    INDEX idx_submission_entry_decision (decision,submission_id),
    CONSTRAINT fk_submission_entry_submission FOREIGN KEY (submission_id) REFERENCES time_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_entry_time FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_submission_entry_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE work_time_entries
    ADD COLUMN current_submission_id CHAR(36) NULL AFTER submitted_at,
    ADD INDEX idx_work_time_submission (current_submission_id),
    ADD CONSTRAINT fk_work_time_current_submission FOREIGN KEY (current_submission_id) REFERENCES time_submissions(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS work_type_billing_defaults (
    work_type_id INT NOT NULL PRIMARY KEY,
    default_treatment ENUM('undecided','internal','fixed_price_included','hourly') NOT NULL DEFAULT 'undecided',
    default_billing_rate DECIMAL(12,4) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_work_type_billing_default_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_type_billing_default_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_type_billing_default_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_work_type_default_billing_rate CHECK (default_billing_rate IS NULL OR default_billing_rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_time_billing_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allocation_key CHAR(64) NOT NULL,
    time_entry_id CHAR(36) NOT NULL,
    entry_revision INT UNSIGNED NOT NULL,
    treatment ENUM('undecided','internal','fixed_price_included','hourly') NOT NULL DEFAULT 'undecided',
    status ENUM('pending','rate_needed','ready','invoiced','reversed') NOT NULL DEFAULT 'pending',
    duration_seconds INT UNSIGNED NOT NULL,
    quantity DECIMAL(12,4) NOT NULL,
    rate DECIMAL(12,4) NULL,
    amount DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    client_id INT NULL,
    project_id INT NULL,
    job_id INT NULL,
    invoice_id INT NULL,
    invoice_item_id INT NULL,
    allocation_snapshot JSON NOT NULL,
    created_by INT NULL,
    reversed_by INT NULL,
    reversed_at DATETIME(6) NULL,
    reversal_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_billing_allocation_key (allocation_key),
    INDEX idx_time_billing_entry (time_entry_id,entry_revision,status),
    INDEX idx_time_billing_queue (status,treatment,created_at),
    INDEX idx_time_billing_invoice (invoice_id,invoice_item_id),
    CONSTRAINT fk_time_billing_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_billing_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_reverser FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_time_billing_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_time_billing_amount CHECK (amount IS NULL OR amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_earnings (
    id CHAR(36) NOT NULL PRIMARY KEY,
    source_key VARCHAR(190) NOT NULL,
    source_type ENUM('time_entry','work_assignment','adjustment','mileage','manual','legacy') NOT NULL,
    source_id VARCHAR(64) NOT NULL,
    source_revision INT UNSIGNED NOT NULL DEFAULT 1,
    worker_profile_id INT NOT NULL,
    work_time_entry_id CHAR(36) NULL,
    work_assignment_id BIGINT NULL,
    pay_period_id BIGINT NULL,
    status ENUM('provisional','needs_setup','eligible','approved','included','settled','adjusted','voided') NOT NULL DEFAULT 'provisional',
    method ENUM('hourly','fixed','base_overage','percentage','reimbursement','adjustment','manual') NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 1,
    rate DECIMAL(12,4) NULL,
    amount DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    calculation_snapshot JSON NOT NULL,
    eligible_by INT NULL,
    eligible_at DATETIME(6) NULL,
    approved_by INT NULL,
    approved_at DATETIME(6) NULL,
    statement_line_id BIGINT NULL,
    settled_at DATETIME(6) NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_earning_source (source_key),
    UNIQUE KEY uq_worker_earning_statement_line (statement_line_id),
    INDEX idx_worker_earning_queue (worker_profile_id,status,created_at),
    INDEX idx_worker_earning_period (pay_period_id,status,worker_profile_id),
    INDEX idx_worker_earning_time (work_time_entry_id,source_revision),
    INDEX idx_worker_earning_assignment (work_assignment_id),
    CONSTRAINT fk_worker_earning_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_time FOREIGN KEY (work_time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_assignment FOREIGN KEY (work_assignment_id) REFERENCES work_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_eligible_actor FOREIGN KEY (eligible_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_earning_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_earning_statement_line FOREIGN KEY (statement_line_id) REFERENCES worker_statement_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_earning_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_worker_earning_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_worker_earning_amount CHECK (amount IS NULL OR amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_earning_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_earning_id CHAR(36) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    reason VARCHAR(1000) NULL,
    event_snapshot JSON NOT NULL,
    actor_id INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_worker_earning_event (worker_earning_id,created_at),
    CONSTRAINT fk_worker_earning_event_earning FOREIGN KEY (worker_earning_id) REFERENCES worker_earnings(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_earning_event_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE worker_statement_lines
    ADD COLUMN worker_earning_id CHAR(36) NULL AFTER worker_statement_id,
    ADD UNIQUE KEY uq_statement_earning (worker_earning_id),
    ADD CONSTRAINT fk_statement_line_earning FOREIGN KEY (worker_earning_id) REFERENCES worker_earnings(id) ON DELETE RESTRICT;

-- Append-only workforce corrections, worker payments, payroll exports, and client credits.

SET @has_statement_version := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_statements' AND column_name='statement_version');
SET @sql := IF(@has_statement_version=0, 'ALTER TABLE worker_statements DROP INDEX uq_worker_statement_period, ADD COLUMN statement_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER worker_profile_id, ADD COLUMN replaces_statement_id BIGINT NULL AFTER statement_version, ADD COLUMN voided_by INT NULL AFTER settled_at, ADD COLUMN voided_at DATETIME(6) NULL AFTER voided_by, ADD COLUMN void_reason VARCHAR(1000) NULL AFTER voided_at, ADD UNIQUE KEY uq_worker_statement_version (pay_period_id,worker_profile_id,statement_version), ADD INDEX idx_worker_statement_replacement (replaces_statement_id), ADD CONSTRAINT fk_worker_statement_replacement FOREIGN KEY (replaces_statement_id) REFERENCES worker_statements(id) ON DELETE SET NULL, ADD CONSTRAINT fk_worker_statement_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_invoice_credit_applied := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='credit_applied');
SET @sql := IF(@has_invoice_credit_applied=0, 'ALTER TABLE invoices ADD COLUMN credit_applied DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_paid', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @invoice_status_supports_credit := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='status' AND column_type LIKE '%credited%');
SET @sql := IF(@invoice_status_supports_credit=0, 'ALTER TABLE invoices MODIFY COLUMN status ENUM(''draft'',''sent'',''unpaid'',''partial'',''paid'',''credited'',''overdue'',''cancelled'',''void'') NOT NULL DEFAULT ''draft''', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS time_correction_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    time_entry_id CHAR(36) NOT NULL,
    original_revision INT UNSIGNED NOT NULL,
    original_snapshot JSON NOT NULL,
    proposed_snapshot JSON NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_by INT NOT NULL,
    resolved_by INT NULL,
    resolved_at DATETIME(6) NULL,
    resolution_notes VARCHAR(1000) NULL,
    applied_revision INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_time_correction_queue (status,created_at),
    INDEX idx_time_correction_entry (time_entry_id,original_revision),
    CONSTRAINT fk_time_correction_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_correction_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_correction_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workforce_deadline_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    event_type ENUM('reminder_4h','reminder_2h','reminder_1h','auto_confirm') NOT NULL,
    status ENUM('pending','completed','failed','skipped') NOT NULL DEFAULT 'pending',
    scheduled_for DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    details JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_workforce_deadline_event (pay_period_id,worker_profile_id,event_type),
    INDEX idx_workforce_deadline_due (status,scheduled_for),
    CONSTRAINT fk_workforce_deadline_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_workforce_deadline_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cron_job_runs (job_name,last_run,status)
VALUES ('process_workforce_deadlines',NULL,'success');

CREATE TABLE IF NOT EXISTS time_correction_effects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correction_request_id CHAR(36) NOT NULL,
    original_worker_earning_id CHAR(36) NULL,
    compensation_adjustment_id BIGINT NULL,
    original_statement_id BIGINT NULL,
    replacement_statement_id BIGINT NULL,
    duration_delta_seconds INT NOT NULL DEFAULT 0,
    worker_amount_delta DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_amount_delta DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    statement_action ENUM('none','draft_rebuild','void_reissue','next_period_adjustment') NOT NULL DEFAULT 'none',
    billing_action ENUM('none','draft_update','admin_review') NOT NULL DEFAULT 'none',
    effect_snapshot JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_correction_effect (correction_request_id),
    INDEX idx_time_correction_effect_statement (original_statement_id,replacement_statement_id),
    CONSTRAINT fk_time_correction_effect_request FOREIGN KEY (correction_request_id) REFERENCES time_correction_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_time_correction_effect_earning FOREIGN KEY (original_worker_earning_id) REFERENCES worker_earnings(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_effect_adjustment FOREIGN KEY (compensation_adjustment_id) REFERENCES compensation_adjustments(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_effect_original_statement FOREIGN KEY (original_statement_id) REFERENCES worker_statements(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_effect_replacement_statement FOREIGN KEY (replacement_statement_id) REFERENCES worker_statements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_payment_records (
    id CHAR(36) NOT NULL PRIMARY KEY,
    worker_profile_id INT NOT NULL,
    record_source ENUM('admin_confirmed','legacy_statement_backfill') NOT NULL DEFAULT 'admin_confirmed',
    legacy_statement_id BIGINT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    payment_method VARCHAR(50) NOT NULL,
    reference_number VARCHAR(255) NULL,
    notes VARCHAR(1000) NULL,
    status ENUM('confirmed','voided') NOT NULL DEFAULT 'confirmed',
    created_by INT NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_payment_legacy_statement (legacy_statement_id),
    INDEX idx_worker_payment_worker (worker_profile_id,payment_date,status),
    CONSTRAINT fk_worker_payment_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_payment_legacy_statement FOREIGN KEY (legacy_statement_id) REFERENCES worker_statements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_payment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_payment_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_worker_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_payment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_payment_record_id CHAR(36) NOT NULL,
    worker_statement_id BIGINT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_payment_statement (worker_payment_record_id,worker_statement_id),
    INDEX idx_worker_payment_allocation_statement (worker_statement_id),
    CONSTRAINT fk_worker_payment_allocation_record FOREIGN KEY (worker_payment_record_id) REFERENCES worker_payment_records(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_payment_allocation_statement FOREIGN KEY (worker_statement_id) REFERENCES worker_statements(id) ON DELETE RESTRICT,
    CONSTRAINT chk_worker_payment_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO worker_payment_records
    (id,worker_profile_id,record_source,legacy_statement_id,payment_date,amount,currency,payment_method,reference_number,notes,status,created_by)
SELECT LOWER(CONCAT(
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),1,8),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),9,4),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),13,4),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),17,4),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),21,12))),
       s.worker_profile_id,'legacy_statement_backfill',s.id,
       DATE(COALESCE(s.settled_at,s.issued_at,s.updated_at,s.created_at)),
       s.total_amount,s.currency,'legacy',CONCAT('Legacy statement #',s.id),
       'Backfilled from a statement already marked settled before Worker Payment Records were introduced.',
       'confirmed',s.created_by
FROM worker_statements s
WHERE s.status='settled' AND s.total_amount>0;

INSERT IGNORE INTO worker_payment_allocations (worker_payment_record_id,worker_statement_id,amount)
SELECT p.id,s.id,s.total_amount
FROM worker_statements s
JOIN worker_payment_records p ON p.legacy_statement_id=s.id AND p.record_source='legacy_statement_backfill'
WHERE s.status='settled' AND s.total_amount>0;

CREATE TABLE IF NOT EXISTS payroll_exports (
    id CHAR(36) NOT NULL PRIMARY KEY,
    export_key VARCHAR(190) NOT NULL,
    pay_period_id BIGINT NULL,
    status ENUM('generated','voided') NOT NULL DEFAULT 'generated',
    format VARCHAR(20) NOT NULL DEFAULT 'csv',
    file_name VARCHAR(255) NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    csv_content MEDIUMTEXT NOT NULL,
    row_count INT UNSIGNED NOT NULL,
    gross_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NULL,
    created_by INT NOT NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_export_key (export_key),
    INDEX idx_payroll_export_period (pay_period_id,status,created_at),
    CONSTRAINT fk_payroll_export_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE SET NULL,
    CONSTRAINT fk_payroll_export_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_export_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_export_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_export_id CHAR(36) NOT NULL,
    worker_earning_id CHAR(36) NOT NULL,
    export_row_number INT UNSIGNED NOT NULL,
    signed_amount DECIMAL(12,2) NOT NULL,
    row_snapshot JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_export_row_number (payroll_export_id,export_row_number),
    UNIQUE KEY uq_payroll_export_earning (payroll_export_id,worker_earning_id),
    CONSTRAINT fk_payroll_export_row_export FOREIGN KEY (payroll_export_id) REFERENCES payroll_exports(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_export_row_earning FOREIGN KEY (worker_earning_id) REFERENCES worker_earnings(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_credits (
    id CHAR(36) NOT NULL PRIMARY KEY,
    client_id INT NOT NULL,
    organization_id INT NULL,
    source_invoice_id INT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    original_amount DECIMAL(12,2) NOT NULL,
    remaining_amount DECIMAL(12,2) NOT NULL,
    status ENUM('available','partially_applied','applied','refunded','voided') NOT NULL DEFAULT 'available',
    reason VARCHAR(1000) NOT NULL,
    created_by INT NOT NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_client_credit_balance (client_id,organization_id,currency,status),
    CONSTRAINT fk_client_credit_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_invoice FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_client_credit_amount CHECK (original_amount > 0 AND remaining_amount >= 0 AND remaining_amount <= original_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_credit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_credit_id CHAR(36) NOT NULL,
    event_type ENUM('issued','allocated','allocation_reversed','refund_recorded','voided') NOT NULL,
    invoice_id INT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_id INT NULL,
    reference_number VARCHAR(255) NULL,
    reason VARCHAR(1000) NULL,
    event_snapshot JSON NOT NULL,
    actor_id INT NOT NULL,
    reverses_event_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_client_credit_event (client_credit_id,created_at),
    INDEX idx_client_credit_invoice (invoice_id),
    CONSTRAINT fk_client_credit_event_credit FOREIGN KEY (client_credit_id) REFERENCES client_credits(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_event_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_event_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_event_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_event_reversal FOREIGN KEY (reverses_event_id) REFERENCES client_credit_events(id) ON DELETE SET NULL,
    CONSTRAINT chk_client_credit_event_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_correction_billing_resolutions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correction_effect_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('invoice_adjustment','move_to_draft','absorb') NOT NULL,
    source_invoice_id INT NULL,
    target_invoice_id INT NULL,
    invoice_adjustment_id BIGINT NULL,
    client_credit_id CHAR(36) NULL,
    signed_amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    actor_id INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_correction_billing_resolution (correction_effect_id),
    CONSTRAINT fk_time_correction_resolution_effect FOREIGN KEY (correction_effect_id) REFERENCES time_correction_effects(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_correction_resolution_source_invoice FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_target_invoice FOREIGN KEY (target_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_adjustment FOREIGN KEY (invoice_adjustment_id) REFERENCES invoice_adjustments(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_credit FOREIGN KEY (client_credit_id) REFERENCES client_credits(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r
JOIN (
    SELECT 'workforce.corrections.manage' permission UNION ALL
    SELECT 'workforce.payments.manage' UNION ALL
    SELECT 'workforce.payroll_exports.manage' UNION ALL
    SELECT 'billing.client_credits.manage'
) p
WHERE r.name IN ('owner','admin')
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

CREATE TABLE schema_migrations (
    version INT UNSIGNED NOT NULL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    checksum CHAR(64) NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, filename, checksum)
VALUES (0, 'baseline.sql', NULL);

-- ============================================================================
-- MODULE 008: Seed Data
-- ============================================================================

-- DEFAULT APP CONFIG
INSERT INTO app_config (config_key, config_value) VALUES
    ('brand_name', 'Project Alpha'),
    ('from_company', ''),
    ('timezone', 'UTC'),
    ('primary_state', ''),
    ('cron_enabled', '1'),
    ('link_resolver_enabled', '0'),
    ('link_resolver_scan_mode', 'quick'),
    ('link_resolver_daily_scan_enabled', '0'),
    ('link_resolver_invoice_auto_attach_enabled', '0'),
    ('project_specific_links_enabled', '0'),
    ('invoice_content_links_enabled', '0'),
    ('invoice_missing_content_links_behavior', 'warn'),
    ('invoice_auto_send_due_7days', '1'),
    ('invoice_auto_send_overdue_weekly', '1'),
    ('invoice_auto_email_on_generate', '1'),
    ('payment_receipts_enabled', '1'),
    ('notify_signed_contract_uploaded', '1'),
    ('notify_invoice_paid', '1'),
    ('notify_invoice_paid_regular', '1'),
    ('notify_invoice_paid_on_demand', '1'),
    ('notify_invoice_paid_long_term', '1'),
    ('notify_invoice_paid_project', '1'),
    ('notify_client_onboarding_submit', '1'),
    ('client_portal_enabled', '0'),
    ('managed_delivery_enabled', '0'),
    ('managed_delivery_intent_url', ''),
    ('managed_delivery_profile_id', '0'),
    ('managed_delivery_guest_links_enabled', '0'),
    ('workforce_allow_non_admin_time_management', '0'),
    ('workforce_allow_non_admin_time_approval', '0'),
    ('default_mileage_rate', '0.670'),
    ('default_mileage_include_return_trip', '1'),
    ('default_mileage_bill_return_trip', '0'),
    ('default_mileage_included_miles', '0.000'),
    ('default_mileage_charge_method', 'actual_trip'),
    ('mileage_tracking_enabled', '0'),
    ('address_route_assistance_enabled', '0'),
    ('job_project_locations_enabled', '0'),
    ('email_no_reply_notice_enabled', '0'),
    ('email_no_reply_notice_text', 'This is an automated message. Please do not reply to this email.')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ============================================================================
-- MODULE 009: Webhook Event Log
-- ============================================================================
-- Records every Stripe webhook delivery attempt for observability / debugging.
CREATE TABLE IF NOT EXISTS webhook_event_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(100) NOT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_type VARCHAR(100) NULL,
    event_id VARCHAR(100) NULL,
    signature_present TINYINT(1) NOT NULL DEFAULT 0,
    signature_valid TINYINT(1) NULL,
    ip_address VARCHAR(45) NULL,
    payload_size INT NOT NULL DEFAULT 0,
    http_response_code INT NULL,
    error_message TEXT NULL,
    raw_payload LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_event_log_received_at (received_at),
    INDEX idx_webhook_event_log_endpoint (endpoint),
    INDEX idx_webhook_event_log_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
