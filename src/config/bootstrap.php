<?php
// src/config/bootstrap.php
// Ensures required tables exist (idempotent)

require_once __DIR__ . '/db.php';

// Load globally-available utility helpers
require_once __DIR__ . '/../utils/escaper.php';
require_once __DIR__ . '/../utils/security_headers.php';
require_once __DIR__ . '/../utils/rate_limiter.php';

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            username VARCHAR(50) NULL,
            role ENUM('admin','owner','staff','member','user') NOT NULL DEFAULT 'member',
            force_password_reset TINYINT(1) NOT NULL DEFAULT 0,
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
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Idempotent column additions for older databases
    try { $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL AFTER password_hash"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN force_password_reset TINYINT(1) NOT NULL DEFAULT 0 AFTER role"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER force_password_reset"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_name VARCHAR(255) NULL AFTER document_sender_enabled"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_company VARCHAR(255) NULL AFTER document_sender_name"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_address_line1 VARCHAR(255) NULL AFTER document_sender_company"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_address_line2 VARCHAR(255) NULL AFTER document_sender_address_line1"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_city VARCHAR(120) NULL AFTER document_sender_address_line2"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_state VARCHAR(120) NULL AFTER document_sender_city"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_postal VARCHAR(40) NULL AFTER document_sender_state"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_country VARCHAR(120) NULL AFTER document_sender_postal"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_phone VARCHAR(80) NULL AFTER document_sender_country"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN document_sender_email VARCHAR(255) NULL AFTER document_sender_phone"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN is_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER force_password_reset"); } catch (Throwable $e) {}

    // Login attempts for throttling
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(255) NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (ip),
            INDEX (email),
            INDEX (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // API keys and usage
    require_once __DIR__ . '/../utils/api_keys_schema.php';
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            key_prefix VARCHAR(32) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            scopes VARCHAR(1024) NULL,
            allowed_ips TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            UNIQUE KEY uq_key_hash (key_hash),
            INDEX (key_prefix),
            INDEX (revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_usage (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            api_key_id INT NOT NULL,
            used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (api_key_id, used_at),
            CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    try { pa_ensure_api_keys_schema($pdo); } catch (Throwable $e) {}
    // Two-Factor Authentication tables
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_2fa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            secret VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            backup_codes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            enabled_at TIMESTAMP NULL,
            INDEX idx_user_2fa_user (user_id),
            CONSTRAINT fk_user_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_2fa_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ip VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_2fa_attempts_user (user_id),
            INDEX idx_2fa_attempts_time (attempted_at),
            CONSTRAINT fk_2fa_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    // Fail closed (but do not break public assets). If creation fails, login/setup will error later visibly.
}
