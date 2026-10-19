<?php
// src/config/app.php
// Preferred settings path: config volume mounted at /var/www/config
// Secrets (Stripe keys, SMTP password, encryption key) are loaded from environment variables
// or .env file first; non-sensitive settings come from settings.json.

require_once __DIR__ . '/../utils/invoice_numbers.php';

$settingsPrimary = '/var/www/config/settings.json';
$settingsProject = __DIR__ . '/../../config/settings.json';
$settingsPublic  = __DIR__ . '/../../public/assets/settings.json';
$settingsFallback = __DIR__ . '/settings.json';

$appConfig = [
    'brand_name' => 'Project Alpha',
    'logo_path'  => null,
    // User info for documents
    'from_company' => null,
    'document_issuer_mode' => 'company',
    'from_name' => null,
    'from_address_line1' => null,
    'from_address_line2' => null,
    'from_city' => null,
    'from_state' => null,
    'from_postal' => null,
    'from_country' => null,
    'from_email' => null,
    'from_phone' => null,
    // Terms for contracts
    'terms' => null,
    'net_terms_days' => 30,
    'payment_methods' => ['Cash','Check','Bank Transfer'],
    'quotes_show_terms' => 1,
    // App preferences
    'cron_enabled' => 1,
    'timezone' => 'UTC',
    'workforce_currency' => 'USD',
    'financial_base_currency' => null,
    'workforce_default_hourly_rate' => null,
    'workforce_default_billing_rate' => null,
    'workforce_require_project' => 0,
    'workforce_require_description' => 0,
    'workforce_allow_non_admin_time_management' => 0,
    'workforce_allow_non_admin_time_approval' => 0,
    'default_mileage_rate' => 0.670,
    'default_mileage_included_miles' => 0,
    'default_mileage_charge_method' => 'actual_trip',
    'default_mileage_include_return_trip' => 1,
    'default_mileage_bill_return_trip' => 0,
    'mileage_tracking_enabled' => 0,
    'primary_state' => null,
    'documents_valid_days' => 14,
    // Automatic invoice email settings
    'invoice_auto_send_due_7days' => 1,
    'invoice_auto_send_overdue_weekly' => 1,
    'invoice_auto_email_on_generate' => 1,
    'invoice_auto_email_on_contract_complete' => 1,
    'payment_receipts_enabled' => 1,
    'notify_signed_contract_uploaded' => 1,
    'notify_invoice_paid' => 1,
    'notify_invoice_paid_regular' => 1,
    'notify_invoice_paid_on_demand' => 1,
    'notify_invoice_paid_long_term' => 1,
    'notify_invoice_paid_project' => 1,
    'notify_client_onboarding_submit' => 1,
    'email_no_reply_notice_enabled' => 0,
    'email_no_reply_notice_text' => 'This is an automated message. Please do not reply to this email.',
    // Link resolver / invoice content links are intentionally opt-in.
    'link_resolver_enabled' => 0,
    'org_level_links_only' => 0,
    'default_link_expiration_days' => 365,
    'link_resolver_scan_mode' => 'quick',
    'link_resolver_daily_scan_enabled' => 0,
    'link_resolver_invoice_auto_attach_enabled' => 0,
    'project_specific_links_enabled' => 0,
    'invoice_content_links_enabled' => 0,
    'invoice_missing_content_links_behavior' => 'warn',
    // SMTP (loaded from app_config with fallback to settings.json)
    'smtp_host' => null,
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_username' => null,
    'smtp_password_enc' => null,
    'smtp_from_email' => null,
    'smtp_from_name' => null,
    // Installation-level Google integration configuration. Secrets are encrypted.
    'google_oauth_client_id' => null,
    'google_oauth_client_secret_enc' => null,
    'google_maps_browser_key' => null,
    'google_routes_api_key_enc' => null,
    'address_route_assistance_enabled' => 0,
    'job_project_locations_enabled' => 0,
    // Stripe (loaded from environment/.env, not stored in settings.json)
    'stripe_publishable_key' => null,
    '_stripe_secret_key' => null,
    '_stripe_webhook_secret' => null,
    'stripe_secret_key_enc' => null,
    'stripe_webhook_secret_enc' => null,
    'stripe_surcharge_type' => 'split',
    'stripe_surcharge_percent' => 2.9,
    'stripe_surcharge_fixed' => 0.3,
    'stripe_surcharge_split_percent' => 50,
    'stripe_surcharge_message' => 'Online card payments include a disclosed processing fee when enabled. Other accepted payment methods use the invoice amount.',
    // qoute defaults
    'quote_auto_create_contract' => 1,
    'quote_auto_create_invoice' => 1,
];

// Load .env file if it exists (project root, config volume, or container root)
$envPaths = [__DIR__ . '/../../.env', '/var/www/config/.env', '/var/www/.env'];
foreach ($envPaths as $envPath) {
    if (is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // skip comments
            if (strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Remove quotes if present
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[strlen($value)-1] === '"') || ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Load secrets from environment variables (they override anything in settings.json)
$secretKeys = [
    'stripe_publishable_key',
    'stripe_secret_key_enc',
    'stripe_webhook_secret_enc',
    'smtp_password_enc',
    'encryption_key',
];
foreach ($secretKeys as $key) {
    $envValue = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? false;
    if ($envValue !== false && $envValue !== '') {
        $appConfig[$key] = $envValue;
    }
}

$secretEnvAliases = [
    'STRIPE_PUBLISHABLE_KEY' => 'stripe_publishable_key',
    'STRIPE_SECRET_KEY' => '_stripe_secret_key',
    'stripe_secret_key' => '_stripe_secret_key',
    'STRIPE_WEBHOOK_SECRET' => '_stripe_webhook_secret',
    'stripe_webhook_secret' => '_stripe_webhook_secret',
];
foreach ($secretEnvAliases as $envKey => $configKey) {
    $envValue = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey) ?? false;
    if ($envValue !== false && $envValue !== '') {
        $appConfig[$configKey] = $envValue;
    }
}

$paths = [$settingsPrimary, $settingsProject, $settingsPublic, $settingsFallback];
foreach ($paths as $path) {
    if (is_readable($path)) {
        $json = @file_get_contents($path);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                // Don't let settings.json override secrets that came from environment
                foreach ($secretKeys as $secretKey) {
                    if (isset($data[$secretKey]) && empty($appConfig[$secretKey])) {
                        $appConfig[$secretKey] = $data[$secretKey];
                    }
                    unset($data[$secretKey]); // remove from merge to prevent overwriting env values
                }
                $appConfig = array_merge($appConfig, $data);
                break;
            }
        }
    }
}

// Apply timezone if configured (defaults to UTC)
$tz = (string)($appConfig['timezone'] ?? 'UTC');
if ($tz !== '') {
    try {
        date_default_timezone_set($tz);
    } catch (Throwable $e) {
        // ignore invalid timezone values
        date_default_timezone_set('UTC');
    }
}

// Load Stripe keys and SMTP config from app_config DB table (UI-entered keys, encrypted)
// This takes precedence over env-vars and settings.json so that keys entered
// via the Settings UI are used. Env-vars still work as a fallback. Unit tests
// explicitly skip this eager runtime lookup so collection never requires MySQL.
if (!filter_var(getenv('APP_SKIP_DB_CONFIG') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
try {
    if (!isset($pdo)) {
        require_once __DIR__ . '/db.php';
    }
    if (isset($pdo)) {
        $appConfigKeys = [
            'financial_base_currency',
            // Stripe + SMTP (already loaded from DB)
            'stripe_publishable_key', 'stripe_secret_key_enc', 'stripe_webhook_secret_enc',
            'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password_enc',
            'smtp_from_email', 'smtp_from_name',
            'google_oauth_client_id', 'google_oauth_client_secret_enc',
            'google_maps_browser_key', 'google_routes_api_key_enc',
            'address_route_assistance_enabled', 'job_project_locations_enabled',
            // General UI settings (now stored in app_config DB)
            'document_issuer_mode', 'brand_name', 'logo_path', 'from_company', 'from_name', 'from_address_line1', 'from_address_line2',
            'from_city', 'from_state', 'from_postal', 'from_country', 'from_email', 'from_phone',
            'app_host', 'public_links_in_email', 'primary_state', 'timezone',
            'workforce_currency', 'workforce_default_hourly_rate', 'workforce_default_billing_rate',
            'workforce_require_project', 'workforce_require_description',
            'workforce_allow_non_admin_time_management', 'workforce_allow_non_admin_time_approval',
            'default_mileage_rate', 'default_mileage_included_miles', 'default_mileage_charge_method',
            'default_mileage_include_return_trip', 'default_mileage_bill_return_trip', 'mileage_tracking_enabled',
            'terms', 'long_term_terms', 'on_demand_terms',
            'net_terms_days', 'documents_valid_days', 'payment_methods',
            'quote_auto_create_contract', 'quote_auto_create_invoice', 'quotes_show_terms',
            'invoice_auto_send_due_7days', 'invoice_auto_send_overdue_weekly', 'invoice_auto_email_on_generate',
            'invoice_auto_email_on_contract_complete',
            'payment_receipts_enabled',
            'notify_signed_contract_uploaded',
            'notify_invoice_paid', 'notify_invoice_paid_regular', 'notify_invoice_paid_on_demand',
            'notify_invoice_paid_long_term', 'notify_invoice_paid_project',
            'notify_client_onboarding_submit',
            'email_no_reply_notice_enabled', 'email_no_reply_notice_text',
            'auto_terminate_contracts', 'link_expiration_checker',
            'contract_expiring_warning', 'contract_expiring_days', 'contract_expired_alert',
            'payment_failure_alert',
            'link_expiration_warning', 'link_expiration_warning_days',
            'invoice_show_terms', 'invoice_show_project_code', 'invoice_show_due_date',
            'quote_scope_enabled', 'contract_scope_enabled', 'contract_memo_enabled',
            'signature_agreement', 'review_link', 'suppress_assets_warning',
            'cron_enabled', 'cron_schedule', 'cron_custom', 'cron_last_run',
            'contract_custom_sections_json',
            'contract_settlement_enabled',
            'backup_hour', 'backup_retention_days',
            'link_resolver_enabled', 'default_link_expiration_days', 'link_resolver_scan_mode', 'org_level_links_only',
            'link_resolver_daily_scan_enabled', 'link_resolver_invoice_auto_attach_enabled',
            'project_specific_links_enabled', 'invoice_content_links_enabled',
            'invoice_missing_content_links_behavior',
            'link_expiration_email_enabled',
            'processor_import_standalone_income', 'processor_import_auto_create_clients',
            // Surcharge settings (user-editable via billing settings)
            'stripe_surcharge_type', 'stripe_surcharge_percent', 'stripe_surcharge_fixed',
            'stripe_surcharge_split_percent', 'stripe_surcharge_message',
            // Merchant rate sync (set by cron, read-only for user)
            'stripe_effective_rate_pct', 'stripe_effective_fixed', 'stripe_effective_rate_synced_at',
        ];
        $placeholders = implode(',', array_fill(0, count($appConfigKeys), '?'));
        $cfgStmt = $pdo->prepare("SELECT config_key, config_value FROM app_config WHERE config_key IN ($placeholders)");
        $cfgStmt->execute($appConfigKeys);
        if ($cfgStmt) {
            while ($row = $cfgStmt->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['config_key'];
                $val = $row['config_value'];
                if (in_array($key, ['stripe_secret_key_enc', 'stripe_webhook_secret_enc', 'smtp_password_enc', 'google_oauth_client_secret_enc', 'google_routes_api_key_enc'], true)
                    && trim((string)$val) === '') {
                    continue;
                }
                $appConfig[$key] = $val;
            }
        }

        // Deserialize JSON-encoded settings
        if (isset($appConfig['contract_custom_sections_json'])) {
            $decoded = json_decode($appConfig['contract_custom_sections_json'], true);
            if (is_array($decoded)) { $appConfig['contract_custom_sections'] = $decoded; }
            unset($appConfig['contract_custom_sections_json']);
        }
        if (isset($appConfig['payment_methods']) && is_string($appConfig['payment_methods'])) {
            $decoded = json_decode($appConfig['payment_methods'], true);
            if (is_array($decoded)) { $appConfig['payment_methods'] = $decoded; }
        }
    }
} catch (Throwable $e) {
    // DB not available yet or table doesn't exist — fall through to env vars
}
}
