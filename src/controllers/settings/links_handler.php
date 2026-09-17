<?php
// src/controllers/settings/links_handler.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/link_provider_config.php';
require_once __DIR__ . '/../../utils/resolver_link_policy.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    exit('Authentication required');
}

if (!csrf_validate()) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

try {
    if (isset($_POST['managed_delivery_enabled'], $_POST['provider_enabled_r2'])) {
        throw new DomainException('Managed Delivery and the standalone direct-R2 resolver cannot be enabled together. Your saved R2 credentials were not removed.');
    }
    // Ensure app_config table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 0,
        config_key VARCHAR(100) NOT NULL,
        config_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_config (organization_id, config_key),
        INDEX idx_config_key (config_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $hasOrgColumn = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_config' AND COLUMN_NAME='organization_id'")->fetchColumn();
    if (!$hasOrgColumn) {
        $pdo->exec("ALTER TABLE app_config ADD COLUMN organization_id INT NOT NULL DEFAULT 0 AFTER id");
    }
    
    // Ensure link_resolver_config table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS link_resolver_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(50) NOT NULL UNIQUE,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        credentials TEXT,
        default_expiration_days INT DEFAULT 365,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $readConfig = function (string $key, $default = null) use ($pdo) {
        $stmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    };

    // Save only the global settings that are currently present on this form.
    $globalSettings = [
        'link_resolver_enabled' => isset($_POST['link_resolver_enabled']) ? 1 : 0,
        'org_level_links_only' => isset($_POST['org_level_links_only']) ? 1 : 0,
        'link_resolver_daily_scan_enabled' => isset($_POST['link_resolver_daily_scan_enabled']) ? 1 : 0,
        'link_resolver_invoice_auto_attach_enabled' => isset($_POST['link_resolver_invoice_auto_attach_enabled']) ? 1 : 0,
        'project_specific_links_enabled' => isset($_POST['project_specific_links_enabled']) ? 1 : 0,
        'invoice_content_links_enabled' => isset($_POST['invoice_content_links_enabled']) ? 1 : 0,
    ];
    $scanMode = (string)($_POST['link_resolver_scan_mode'] ?? 'quick');
    if (!in_array($scanMode, ['quick', 'full'], true)) {
        $scanMode = 'quick';
    }
    $globalSettings['link_resolver_scan_mode'] = $scanMode;
    $missingLinksBehavior = (string)($_POST['invoice_missing_content_links_behavior'] ?? 'warn');
    if (!in_array($missingLinksBehavior, ['send', 'warn', 'block'], true)) {
        $missingLinksBehavior = 'warn';
    }
    $globalSettings['invoice_missing_content_links_behavior'] = $missingLinksBehavior;
    if (isset($_POST['default_link_expiration_days'])) {
        $globalSettings['default_link_expiration_days'] = max(1, (int)$_POST['default_link_expiration_days']);
    }
    if (isset($_POST['link_expiration_checker_enabled'])) {
        $globalSettings['link_expiration_checker'] = 1;
    }
    if (isset($_POST['link_expiration_email_enabled'])) {
        $globalSettings['link_expiration_email_enabled'] = 1;
    }
    if (isset($_POST['dropbox_app_key'])) {
        $globalSettings['dropbox_app_key'] = trim((string)$_POST['dropbox_app_key']);
    }
    if (isset($_POST['dropbox_app_secret']) && trim((string)$_POST['dropbox_app_secret']) !== '') {
        $globalSettings['dropbox_app_secret'] = trim((string)$_POST['dropbox_app_secret']);
    }
    
    $pdo->beginTransaction();
    // Discard old disabled-provider URLs before re-enabling any provider.
    pa_remove_disabled_resolver_links($pdo);
    foreach ($globalSettings as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO app_config (organization_id, config_key, config_value)
            VALUES (0, ?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute([$key, $value]);
    }
    
    // Process provider configurations
    $providers = ['dropbox', 'gdrive', 's3', 'r2'];
    
    foreach ($providers as $provider) {
        $isEnabled = isset($_POST["provider_enabled_{$provider}"]) ? 1 : 0;
        $existingRow = pa_link_provider_best_row($pdo, $provider);
        $existingCredentials = $existingRow ? pa_link_provider_credentials_from_row($existingRow) : [];
        
        // Build credentials array based on provider
        $credentials = [];
        
        if ($provider === 'dropbox') {
            // For Dropbox, preserve existing OAuth credentials if present
            // Only update access token if provided, otherwise keep existing OAuth tokens
            $accessToken = trim((string)($_POST["{$provider}_access_token"] ?? ''));
            if (!empty($accessToken)) {
                // Legacy access token provided
                $credentials = [
                    'access_token' => $accessToken,
                    'root_path' => $_POST["{$provider}_root_path"] ?? '/'
                ];
            } elseif ($existingCredentials) {
                // Preserve saved OAuth or legacy credentials when no replacement is supplied.
                $credentials = $existingCredentials;
                // Update root path if changed
                $credentials['root_path'] = $_POST["{$provider}_root_path"] ?? ($existingCredentials['root_path'] ?? '/');
            } else {
                // No credentials at all
                $credentials = [
                    'root_path' => $_POST["{$provider}_root_path"] ?? '/'
                ];
            }
        } elseif ($provider === 'gdrive') {
            $serviceAccount = trim((string)($_POST["{$provider}_credentials"] ?? ''));
            $credentials = [
                'service_account' => $serviceAccount !== '' ? $serviceAccount : (string)($existingCredentials['service_account'] ?? ''),
                'root_path' => $_POST["{$provider}_root_path"] ?? ''
            ];
        } elseif ($provider === 's3') {
            $accessKey = trim((string)($_POST["{$provider}_access_key"] ?? ''));
            $secretKey = trim((string)($_POST["{$provider}_secret_key"] ?? ''));
            $credentials = [
                'access_key' => $accessKey !== '' ? $accessKey : (string)($existingCredentials['access_key'] ?? ''),
                'secret_key' => $secretKey !== '' ? $secretKey : (string)($existingCredentials['secret_key'] ?? ''),
                'bucket' => trim((string)($_POST["{$provider}_bucket"] ?? '')),
                'region' => trim((string)($_POST["{$provider}_region"] ?? 'us-east-1')) ?: 'us-east-1',
                'public_base_url' => trim((string)($_POST["{$provider}_public_base_url"] ?? '')),
                'root_path' => $_POST["{$provider}_root_path"] ?? ''
            ];
            if (!empty($existingCredentials['session_token'])) {
                $credentials['session_token'] = (string)$existingCredentials['session_token'];
            }
        } elseif ($provider === 'r2') {
            $accessKey = trim((string)($_POST["{$provider}_access_key"] ?? ''));
            $secretKey = trim((string)($_POST["{$provider}_secret_key"] ?? ''));
            $credentials = [
                'account_id' => trim((string)($_POST["{$provider}_account_id"] ?? '')),
                'access_key' => $accessKey !== '' ? $accessKey : (string)($existingCredentials['access_key'] ?? ''),
                'secret_key' => $secretKey !== '' ? $secretKey : (string)($existingCredentials['secret_key'] ?? ''),
                'bucket' => trim((string)($_POST["{$provider}_bucket"] ?? '')),
                'endpoint' => trim((string)($_POST["{$provider}_endpoint"] ?? '')),
                'public_base_url' => trim((string)($_POST["{$provider}_public_base_url"] ?? '')),
                'root_path' => $_POST["{$provider}_root_path"] ?? ''
            ];
            if (!empty($existingCredentials['session_token'])) {
                $credentials['session_token'] = (string)$existingCredentials['session_token'];
            }
        }
        
        $expirationDays = (int)($globalSettings['default_link_expiration_days'] ?? $readConfig('default_link_expiration_days', 365));
        if ($existingRow && (!$globalSettings['link_resolver_enabled'] || !$isEnabled)) {
            $credentials = $existingCredentials;
        }
        pa_link_provider_save($pdo, $provider, $isEnabled, $credentials, $expirationDays);
    }

    (new \App\Services\ManagedDeliveryService())->saveConfig($pdo, [
        'enabled' => isset($_POST['managed_delivery_enabled']),
        'guest_links_enabled' => isset($_POST['managed_delivery_guest_links_enabled']),
    ]);
    pa_remove_disabled_resolver_links($pdo);
    $pdo->commit();
    
    header('Location: /?page=settings&tab=links&saved=1');
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[LinksHandler] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=links&saved=0&error=' . urlencode($e->getMessage()));
    exit;
}
