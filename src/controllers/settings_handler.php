<?php
require_once __DIR__ . '/../utils/workforce_availability.php';
// src/controllers/settings_handler.php
// Save settings and handle logo upload, then redirect (PRG)
require_once __DIR__ . '/../utils/payment_methods.php';
require_once __DIR__ . '/../utils/financial_reporting_currency.php';

// Route specific tabs to dedicated handlers
$tab = $_POST['tab'] ?? $_GET['tab'] ?? '';
$docTab = isset($_POST['doc_tab']) ? (string)$_POST['doc_tab'] : (string)($_GET['doc_tab'] ?? '');
$docTab = preg_replace('/[^a-z]/i', '', $docTab);
$isSystemTab = $tab === 'system';
$isDocumentsQuoteTab = $tab === 'documents' && $docTab === 'quotes';
$isDocumentsContractTab = $tab === 'documents' && $docTab === 'contracts';
$isDocumentsInvoiceTab = $tab === 'documents' && $docTab === 'invoices';
$isDocumentsCustomizationTab = $tab === 'documents' && $docTab === 'customization';
$isNotificationsTab = $tab === 'notifications';
$isWorkflowTab = $tab === 'workflow';
$isBillingTab = $tab === 'billing';

// Prefer dedicated config mount if present

// Optional: account password change for logged-in user
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
if (!empty($_POST['change_password']) && $uid > 0) {
    require_once __DIR__ . '/../config/db.php';
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (strlen($new) < 8) {
        header('Location: /?page=settings&tab=account&pwd_error=' . urlencode('Password must be at least 8 characters'));
        exit;
    }
    if ($new !== $confirm) {
        header('Location: /?page=settings&tab=account&pwd_error=' . urlencode('Passwords do not match'));
        exit;
    }
    try {
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
        $st->execute([$uid]);
        $hash = (string)$st->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            header('Location: /?page=settings&tab=account&pwd_error=' . urlencode('Current password is incorrect'));
            exit;
        }
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$newHash, $uid]);
        header('Location: /?page=settings&tab=account&pwd=1');
        exit;
    } catch (Throwable $e) {
        header('Location: /?page=settings&tab=account&pwd_error=' . urlencode('Failed to update password'));
        exit;
    }
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/acl.php';
if ($tab === 'account') {
    http_response_code(400);
    exit('No account change was requested.');
}
if ($uid <= 0 || !user_can($pdo,$uid,'settings.manage',0)) {
    http_response_code(403);
    exit('You do not have permission to change installation settings.');
}
if ($tab === 'links') {
    require_once __DIR__ . '/settings/links_handler.php';
    exit;
}
if ($isSystemTab && !in_array((string)($_SESSION['user']['role'] ?? ''), ['admin','owner'], true)) {
    http_response_code(403);
    exit('Only an installation administrator can change integration settings.');
}
if (array_key_exists('financial_base_currency', $_POST)) {
    require_once __DIR__ . '/../utils/csrf.php';
    try {
        $financialReportingCurrency = financial_reporting_currency_change($_POST['financial_base_currency'], $isSystemTab,
            $uid > 0 && user_can($pdo, $uid, 'settings.manage', 0), (string)($_SESSION['user']['role'] ?? ''), csrf_validate());
    } catch (InvalidArgumentException $error) {
        http_response_code(400); exit(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'));
    } catch (RuntimeException) {
        http_response_code(403); exit('Reporting currency settings access denied.');
    }
}
require_once __DIR__ . '/../utils/upload_validator.php';

$configMount = '/var/www/config';
$projectConfig = __DIR__ . '/../../config';
$configDir = is_dir($configMount) ? $configMount : $projectConfig;
$uploadsDir = $configDir . '/uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
}
$settingsFile = $configDir . '/settings.json';

// default settings
$settings = [
    'brand_name' => 'Project Alpha',
    'logo_path'  => null,
    'from_company' => null,
    'from_name' => null,
    'from_address_line1' => null,
    'from_address_line2' => null,
    'from_city' => null,
    'from_state' => null,
    'from_postal' => null,
    'from_country' => null,
    'from_email' => null,
    'from_phone' => null,
    'terms' => null,
    'net_terms_days' => 30,
    'payment_methods' => ['cash','check','bank_transfer'],
    'timezone' => 'UTC',
    'workforce_currency' => 'USD',
    'workforce_default_hourly_rate' => null,
    'workforce_default_billing_rate' => null,
    'workforce_default_capture_mode' => 'duration',
    'workforce_default_billing_treatment' => 'undecided',
    'workforce_require_project' => 0,
    'workforce_require_work_type' => 0,
    'workforce_require_description' => 0,
    'workforce_allow_non_admin_time_management' => 0,
    'workforce_allow_non_admin_time_approval' => 0,
    'default_mileage_rate' => 0.670,
    'default_mileage_included_miles' => 0,
    'default_mileage_charge_method' => 'actual_trip',
    'default_mileage_include_return_trip' => 1,
    'default_mileage_bill_return_trip' => 0,
    'mileage_tracking_enabled' => 0,
    'address_route_assistance_enabled' => 0,
    'job_project_locations_enabled' => 0,
    // App extras
    'primary_state' => null,
    'documents_valid_days' => 14,
    // Quote auto-create on approval (default enabled)
    'quote_auto_create_contract' => 1,
    'quote_auto_create_invoice' => 1,
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
    // SMTP configuration (optional)
    'smtp_host' => null,
    'smtp_port' => 587,
    'smtp_secure' => 'tls', // tls|ssl|none
    'smtp_username' => null,
    'smtp_password_enc' => null,
    'processor_import_standalone_income' => 0,
    'processor_import_auto_create_clients' => 0,
];

// Read current settings. If public file is writable prefer it; if public exists but is not writable prefer internal fallback
$fallbackRead = __DIR__ . '/../config/settings.json';
if (is_readable($settingsFile) && is_writable(dirname($settingsFile))) {
    $data = json_decode(@file_get_contents($settingsFile), true);
    if (is_array($data)) {
        $settings = array_merge($settings, $data);
    }
} elseif (is_readable($fallbackRead)) {
    $data = json_decode(@file_get_contents($fallbackRead), true);
    if (is_array($data)) {
        $settings = array_merge($settings, $data);
    }
} elseif (is_readable($settingsFile)) {
    // last resort: public is readable but not writable and fallback doesn't exist; use public to avoid losing data
    $data = json_decode(@file_get_contents($settingsFile), true);
    if (is_array($data)) {
        $settings = array_merge($settings, $data);
    }
}

// Production reads app_config with higher precedence than settings.json, so use it
// as the base here too. Otherwise saving one tab can overwrite newer DB values
// with older JSON defaults.
try {
    require_once __DIR__ . '/../config/db.php';
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
    $cfgRows = $pdo->query('SELECT config_key, config_value FROM app_config WHERE organization_id=0')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (is_array($cfgRows)) {
        foreach ($cfgRows as $key => $val) {
            if ($key === 'payment_methods') {
                $decoded = json_decode((string)$val, true);
                $settings[$key] = is_array($decoded) ? $decoded : $val;
            } elseif ($key === 'contract_custom_sections_json') {
                $decoded = json_decode((string)$val, true);
                if (is_array($decoded)) {
                    $settings['contract_custom_sections'] = $decoded;
                }
            } else {
                $settings[$key] = $val;
            }
        }
    }
} catch (Throwable $e) {
    // app_config may not exist on a fresh install; settings.json remains the fallback.
}

if (array_key_exists('financial_base_currency', $_POST)) {
    $settings['financial_base_currency'] = $financialReportingCurrency;
}

// Prepare target and existing file contents early so we can ensure an encryption key
$target = $settingsFile;
$existing = [];
if (is_readable($target)) {
    $existing = json_decode(@file_get_contents($target), true) ?: [];
}
// Encryption key is sourced exclusively from the APP_ENCRYPTION_KEY env var
// (see src/utils/crypto.php). We no longer generate or persist a plaintext
// key inside settings.json — the repo is public and that pattern leaked a key.
if (getenv('APP_ENCRYPTION_KEY') === false || getenv('APP_ENCRYPTION_KEY') === '') {
    @error_log('[settings] APP_ENCRYPTION_KEY not set — secret fields cannot be encrypted/saved');
}
// Drop any legacy plaintext key still sitting in the file.
if (isset($existing['encryption_key'])) {
    unset($existing['encryption_key']);
}

if (isset($_POST['brand_name'])) {
    $brand = trim((string)$_POST['brand_name']);
    if ($brand !== '') {
        // Basic length clamp
        $settings['brand_name'] = mb_substr($brand, 0, 100);
    }
}

// Application domain (was MISSING — never saved before)
if (isset($_POST['app_host'])) {
    $h = trim((string)$_POST['app_host']);
    $h = preg_replace('#^https?://#', '', $h);
    $h = rtrim($h, '/');
    $settings['app_host'] = $h !== '' ? mb_substr($h, 0, 255) : null;
}
// Public links in email checkbox (was MISSING — never saved before)
if ($isSystemTab || isset($_POST['app_host']) || isset($_POST['public_links_in_email'])) {
    $settings['public_links_in_email'] = !empty($_POST['public_links_in_email']) ? 1 : 0;
}

// From and contact fields
foreach (['from_company','from_name','from_address_line1','from_address_line2','from_city','from_state','from_postal','from_country','from_email','from_phone'] as $k) {
    if (isset($_POST[$k])) {
        $val = trim((string)$_POST[$k]);
        $settings[$k] = $val !== '' ? mb_substr($val, 0, 200) : null;
    }
}
// Primary state default
if (isset($_POST['primary_state'])) {
    $settings['primary_state'] = trim((string)$_POST['primary_state']) ?: null;
}
// Terms
if (isset($_POST['terms'])) {
    $t = trim((string)$_POST['terms']);
    $settings['terms'] = $t !== '' ? mb_substr($t, 0, 20000) : null;
}
if (isset($_POST['long_term_terms'])) {
    $lt = trim((string)$_POST['long_term_terms']);
    $settings['long_term_terms'] = $lt !== '' ? mb_substr($lt, 0, 20000) : null;
}
// Document issuer policy is independent of divisions and mail transport.
if (isset($_POST['document_issuer_mode'])) {
    $mode = (string)$_POST['document_issuer_mode'];
    if (!in_array($mode, ['company', 'creator'], true)) {
        http_response_code(400);
        exit('Choose company or original creator for the document issuer.');
    }
    $settings['document_issuer_mode'] = $mode;
}
// Documents valid days (moved to Documents → Customization tab)
if (isset($_POST['documents_valid_days'])) {
    $dv = (int)$_POST['documents_valid_days'];
    if ($dv < 0) $dv = 0;
    $settings['documents_valid_days'] = $dv;
}
// Toggle terms on quotes (moved to Documents → Quotes tab)
if ($isDocumentsQuoteTab || isset($_POST['quotes_show_terms'])) {
    $settings['quotes_show_terms'] = !empty($_POST['quotes_show_terms']) ? 1 : 0;
}

// On-demand document terms (new)
if (isset($_POST['on_demand_terms'])) {
    $od = trim((string)$_POST['on_demand_terms']);
    $settings['on_demand_terms'] = $od !== '' ? mb_substr($od, 0, 20000) : null;
}
// Billing defaults
if (isset($_POST['net_terms_days'])) {
    $n = (int)$_POST['net_terms_days'];
    if ($n < 0) $n = 0;
    $settings['net_terms_days'] = $n;
}

if (!empty($_FILES['logo']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $f = $_FILES['logo'];
    $allowedMap = [
        'image/jpeg'    => ['jpg', 'jpeg'],
        'image/png'     => ['png'],
        'image/gif'     => ['gif'],
        'image/webp'    => ['webp'],
        'image/svg+xml' => ['svg'],
    ];
    $uploadError = null;
    $storedName = validate_and_store_upload($f, $allowedMap, 5 * 1024 * 1024, $uploadsDir, $uploadError);
    if ($storedName !== null) {
        // File validated and stored — serve via controller
        $settings['logo_path'] = '/?page=serve-upload&file=' . rawurlencode($storedName);
    } elseif ($uploadError !== null) {
        // Surface the error to the user instead of silently ignoring
        $settings['_logo_upload_error'] = $uploadError;
    }
}

// Billing defaults
if (isset($_POST['net_terms_days'])) {
    $nd = (int)$_POST['net_terms_days'];
    if ($nd < 0) $nd = 0;
    $settings['net_terms_days'] = $nd;
}
// Suppress assets warning checkbox
if (isset($_POST['suppress_assets_warning'])) {
    $settings['suppress_assets_warning'] = !empty($_POST['suppress_assets_warning']) ? 1 : 0;
}
// Payment methods (JSON from modernized UI)
if (isset($_POST['payment_methods_json'])) {
    $jsonData = json_decode((string)$_POST['payment_methods_json'], true);
    if (is_array($jsonData)) {
        $methods = [];
        foreach ($jsonData as $item) {
            if (is_array($item) && !empty($item['name'])) {
                $methods[] = trim($item['name']);
            } elseif (is_string($item) && trim($item) !== '') {
                $methods[] = trim($item);
            }
        }
        $settings['payment_methods'] = pa_normalized_payment_method_values($methods);
    }
} elseif (isset($_POST['payment_methods'])) {
    // Fallback: textarea lines for backward compatibility
    $lines = preg_split('/\r?\n/', (string)$_POST['payment_methods']);
    $methods = [];
    foreach ($lines as $ln) {
        $m = trim($ln);
        if ($m !== '') { $methods[] = $m; }
    }
    $settings['payment_methods'] = pa_normalized_payment_method_values($methods);
}

// Time zone
if (isset($_POST['timezone'])) {
    $tz = trim((string)$_POST['timezone']);
    // basic validation: must be a known timezone ID
    if (in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
        $settings['timezone'] = $tz;
    }
}

// Preserve shelved defaults; other Workflow settings remain editable.
if (pa_workforce_enabled() && $isWorkflowTab) {
    $currency = strtoupper(trim((string)($_POST['workforce_currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        header('Location: /?page=settings&tab=workflow&error=' . urlencode('Workforce currency must be a three-letter ISO code.'));
        exit;
    }
    $settings['workforce_currency'] = $currency;
    foreach (['workforce_default_hourly_rate', 'workforce_default_billing_rate'] as $rateKey) {
        $rate = trim((string)($_POST[$rateKey] ?? ''));
        if ($rate !== '' && !preg_match('/^\d+(?:\.\d{1,4})?$/', $rate)) {
            header('Location: /?page=settings&tab=workflow&error=' . urlencode('Workforce rates must be non-negative decimals with at most four places.'));
            exit;
        }
        $settings[$rateKey] = $rate !== '' ? $rate : null;
    }
    $captureMode = (string)($_POST['workforce_default_capture_mode'] ?? 'duration');
    $settings['workforce_default_capture_mode'] = in_array($captureMode, ['duration', 'timer', 'exact'], true)
        ? $captureMode
        : 'duration';
    $billingTreatment = (string)($_POST['workforce_default_billing_treatment'] ?? 'undecided');
    $settings['workforce_default_billing_treatment'] = in_array($billingTreatment, ['undecided', 'nonbillable', 'included_fixed', 'ready'], true)
        ? $billingTreatment
        : 'undecided';
    $settings['workforce_require_project'] = !empty($_POST['workforce_require_project']) ? 1 : 0;
    $settings['workforce_require_work_type'] = !empty($_POST['workforce_require_work_type']) ? 1 : 0;
    $settings['workforce_require_description'] = !empty($_POST['workforce_require_description']) ? 1 : 0;
}

if ($isWorkflowTab) {
    $mileageRate = trim((string)($_POST['default_mileage_rate'] ?? ''));
    if ($mileageRate === '' || !preg_match('/^\d+(?:\.\d{1,3})?$/', $mileageRate)) {
        header('Location: /?page=settings&tab=workflow&error=' . urlencode('Default mileage rate must be a non-negative number with at most three decimal places.'));
        exit;
    }
    $settings['default_mileage_rate'] = number_format((float)$mileageRate, 3, '.', '');
    $includedMiles = trim((string)($_POST['default_mileage_included_miles'] ?? '0'));
    if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $includedMiles)) {
        header('Location: /?page=settings&tab=workflow&error=' . urlencode('Included mileage must be a non-negative number with at most three decimal places.'));
        exit;
    }
    $chargeMethod = (string)($_POST['default_mileage_charge_method'] ?? 'actual_trip');
    $settings['default_mileage_included_miles'] = number_format((float)$includedMiles, 3, '.', '');
    $settings['default_mileage_charge_method'] = in_array($chargeMethod, ['actual_trip','origin_distance','fixed_fee','none'], true) ? $chargeMethod : 'actual_trip';
    $settings['default_mileage_include_return_trip'] = !empty($_POST['default_mileage_include_return_trip']) ? 1 : 0;
    $settings['default_mileage_bill_return_trip'] = !empty($_POST['default_mileage_bill_return_trip']) ? 1 : 0;
    $settings['mileage_tracking_enabled'] = !empty($_POST['mileage_tracking_enabled']) ? 1 : 0;
    $settings['address_route_assistance_enabled'] = !empty($_POST['address_route_assistance_enabled']) ? 1 : 0;
    $settings['job_project_locations_enabled'] = !empty($_POST['job_project_locations_enabled']) ? 1 : 0;
    if (pa_workforce_enabled()) {
        $settings['workforce_allow_non_admin_time_management'] = !empty($_POST['workforce_allow_non_admin_time_management']) ? 1 : 0;
        $settings['workforce_allow_non_admin_time_approval'] = !empty($_POST['workforce_allow_non_admin_time_approval']) ? 1 : 0;
    }
}

// SMTP settings
if (isset($_POST['smtp_host'])) {
    $settings['smtp_host'] = trim((string)$_POST['smtp_host']) ?: null;
}
if (isset($_POST['smtp_port'])) {
    $settings['smtp_port'] = (int)$_POST['smtp_port'] ?: 587;
}
if (isset($_POST['smtp_secure'])) {
    $sec = strtolower((string)$_POST['smtp_secure']);
    if (!in_array($sec, ['tls','ssl','none'], true)) $sec = 'tls';
    $settings['smtp_secure'] = $sec;
}
if (isset($_POST['smtp_username'])) {
    $settings['smtp_username'] = trim((string)$_POST['smtp_username']) ?: null;
}
if (!empty($_POST['smtp_password'])) {
    require_once __DIR__ . '/../utils/crypto.php';
    $enc = crypto_encrypt((string)$_POST['smtp_password']);
    if ($enc) { $settings['smtp_password_enc'] = $enc; }
}
if (isset($_POST['smtp_from_email'])) {
    $settings['smtp_from_email'] = trim((string)$_POST['smtp_from_email']) ?: null;
}
if (isset($_POST['smtp_from_name'])) {
    $settings['smtp_from_name'] = trim((string)$_POST['smtp_from_name']) ?: null;
}
if ($isSystemTab && isset($_POST['google_oauth_client_id'])) {
    $settings['google_oauth_client_id'] = trim((string)$_POST['google_oauth_client_id']) ?: null;
}
if ($isSystemTab && !empty($_POST['google_oauth_client_secret'])) {
    require_once __DIR__ . '/../utils/crypto.php';
    $encrypted = crypto_encrypt((string)$_POST['google_oauth_client_secret']);
    if ($encrypted === null) {
        header('Location: /?page=settings&tab=system&error=' . urlencode('APP_ENCRYPTION_KEY is required before saving Google credentials.'));
        exit;
    }
    $settings['google_oauth_client_secret_enc'] = $encrypted;
}
if ($isSystemTab && isset($_POST['google_maps_browser_key'])) {
    $settings['google_maps_browser_key'] = trim((string)$_POST['google_maps_browser_key']) ?: null;
}
if ($isSystemTab && !empty($_POST['google_routes_api_key'])) {
    require_once __DIR__ . '/../utils/crypto.php';
    $encrypted = crypto_encrypt((string)$_POST['google_routes_api_key']);
    if ($encrypted === null) {
        header('Location: /?page=settings&tab=system&error=' . urlencode('APP_ENCRYPTION_KEY is required before saving the Routes key.'));
        exit;
    }
    $settings['google_routes_api_key_enc'] = $encrypted;
}

// Persist SMTP configuration to app_config table so it takes precedence over settings.json
$smtpConfigKeys = [];
foreach (['smtp_host','smtp_port','smtp_secure','smtp_username','smtp_from_email','smtp_from_name'] as $k) {
    if (isset($settings[$k])) {
        $smtpConfigKeys[$k] = $settings[$k];
    }
}
foreach (['google_oauth_client_id','google_oauth_client_secret_enc','google_maps_browser_key','google_routes_api_key_enc'] as $k) {
    if (array_key_exists($k, $settings)) {
        $smtpConfigKeys[$k] = $settings[$k];
    }
}
if (!empty($settings['smtp_password_enc'])) {
    $smtpConfigKeys['smtp_password_enc'] = $settings['smtp_password_enc'];
}
if (!empty($smtpConfigKeys)) {
    require_once __DIR__ . '/../config/db.php';
    $stmtConfig = $pdo->prepare(
        'INSERT INTO app_config (organization_id, config_key, config_value)
         VALUES (0, ?, ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    );
    foreach ($smtpConfigKeys as $key => $val) {
        // Optional integration fields can be cleared, but app_config values are NOT NULL.
        $stmtConfig->execute([$key, (string)($val ?? '')]);
    }

    if (!empty($settings['smtp_host'])) {
        require_once __DIR__ . '/../services/EmailService.php';
        require_once __DIR__ . '/../services/EmailProviderManager.php';
        try {
            $smtpCredentials = EmailService::getSmtpConfig(array_merge($appConfig ?? [], $settings));
            $manager = new EmailProviderManager($pdo, array_merge($appConfig ?? [], $settings));
            $connectionId = $manager->upsertSmtp(
                $smtpCredentials,
                EmailService::getFromEmail(array_merge($appConfig ?? [], $settings), $smtpCredentials),
                EmailService::getFromName(array_merge($appConfig ?? [], $settings)),
                $uid ?: null
            );
            $active = $manager->activeConnection();
            if ($active === null) {
                $manager->activate($connectionId, $uid ?: null);
            }
        } catch (Throwable $emailProviderError) {
            @error_log('[settings] SMTP provider sync failed: ' . $emailProviderError->getMessage());
        }
    }

    // Also set in current request so they're available immediately
    foreach ($smtpConfigKeys as $key => $val) {
        putenv("$key=$val");
        $_ENV[$key] = $val;
        $appConfig[$key] = $val;
    }
}

// General UI settings persistence: store all non-secret settings in app_config DB
$generalConfigKeys = [
    'financial_base_currency',
    'document_issuer_mode', 'brand_name', 'logo_path', 'from_company', 'from_name', 'from_address_line1', 'from_address_line2',
    'from_city', 'from_state', 'from_postal', 'from_country', 'from_email', 'from_phone',
    'app_host', 'public_links_in_email', 'primary_state', 'timezone',
    'workforce_currency', 'workforce_default_hourly_rate', 'workforce_default_billing_rate',
    'workforce_default_capture_mode', 'workforce_default_billing_treatment',
    'workforce_require_project', 'workforce_require_work_type', 'workforce_require_description',
    'workforce_allow_non_admin_time_management', 'workforce_allow_non_admin_time_approval',
    'default_mileage_rate', 'default_mileage_included_miles', 'default_mileage_charge_method',
    'default_mileage_include_return_trip', 'default_mileage_bill_return_trip', 'mileage_tracking_enabled',
    'address_route_assistance_enabled', 'job_project_locations_enabled',
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
    'cron_enabled', 'cron_schedule', 'cron_custom',
    'processor_import_standalone_income', 'processor_import_auto_create_clients',
];
if (!pa_workforce_enabled()) {
    foreach (array_keys($settings) as $key) {
        if (str_starts_with($key, 'workforce_')) { unset($settings[$key]); }
    }
}
// contract_custom_sections is an array — serialize to JSON for DB storage
if (isset($settings['contract_custom_sections'])) {
    $settings['contract_custom_sections_json'] = json_encode($settings['contract_custom_sections']);
    $generalConfigKeys[] = 'contract_custom_sections_json';
}
if (!pa_workforce_enabled()) {
    $generalConfigKeys = array_values(array_filter($generalConfigKeys, static fn(string $key): bool => !str_starts_with($key, 'workforce_')));
}
if (!empty($generalConfigKeys)) {
    require_once __DIR__ . '/../config/db.php';
    try {
        $stmtGen = $pdo->prepare(
            'INSERT INTO app_config (organization_id, config_key, config_value)
             VALUES (0, ?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        foreach ($generalConfigKeys as $key) {
            if (array_key_exists($key, $settings)) {
                $val = $settings[$key];
                if (is_array($val)) { $val = json_encode($val); }
                if ($val === null) { $val = ''; }  // app_config.config_value is NOT NULL
                $stmtGen->execute([$key, (string)$val]);
            }
        }
    } catch (Throwable $e) {
        // DB or app_config table not available (fresh install, migration pending) —
        // settings.json fallback below still persists the values. Log and continue.
        @error_log('[settings] app_config DB write failed: ' . $e->getMessage() . ' — falling back to settings.json');
    }
}

// Cron/recurring invoice settings
if ($isNotificationsTab || isset($_POST['cron_enabled'])) {
    $settings['cron_enabled'] = !empty($_POST['cron_enabled']) ? 1 : 0;
}
if (isset($_POST['cron_schedule'])) {
    $sched = trim((string)$_POST['cron_schedule']);
    $allowed = ['hourly','every_6hours','daily_midnight','daily_2am','daily_6am','daily_noon','custom'];
    if (in_array($sched, $allowed, true)) {
        $settings['cron_schedule'] = $sched;
    }
}
if (isset($_POST['cron_custom'])) {
    $settings['cron_custom'] = trim((string)$_POST['cron_custom']) ?: '0 2 * * *';
}

// Automatic invoice email settings
if ($isNotificationsTab || isset($_POST['invoice_auto_send_due_7days'])) {
    $settings['invoice_auto_send_due_7days'] = !empty($_POST['invoice_auto_send_due_7days']) ? 1 : 0;
}
if ($isNotificationsTab || isset($_POST['invoice_auto_send_overdue_weekly'])) {
    $settings['invoice_auto_send_overdue_weekly'] = !empty($_POST['invoice_auto_send_overdue_weekly']) ? 1 : 0;
}
if ($isWorkflowTab || isset($_POST['invoice_auto_email_on_generate'])) {
    $settings['invoice_auto_email_on_generate'] = !empty($_POST['invoice_auto_email_on_generate']) ? 1 : 0;
}
if ($isWorkflowTab || isset($_POST['invoice_auto_email_on_contract_complete'])) {
    $settings['invoice_auto_email_on_contract_complete'] = !empty($_POST['invoice_auto_email_on_contract_complete']) ? 1 : 0;
}

// System automation settings
if ($isNotificationsTab || isset($_POST['auto_terminate_contracts'])) {
    $settings['auto_terminate_contracts'] = !empty($_POST['auto_terminate_contracts']) ? 1 : 0;
}
if ($isNotificationsTab || isset($_POST['link_expiration_checker'])) {
    $settings['link_expiration_checker'] = !empty($_POST['link_expiration_checker']) ? 1 : 0;
}

// Contract notification settings
if ($isNotificationsTab || isset($_POST['contract_expiring_warning'])) {
    $settings['contract_expiring_warning'] = !empty($_POST['contract_expiring_warning']) ? 1 : 0;
}
if (isset($_POST['contract_expiring_days'])) {
    $settings['contract_expiring_days'] = max(1, min(90, (int)$_POST['contract_expiring_days']));
}
if ($isNotificationsTab || isset($_POST['contract_expired_alert'])) {
    $settings['contract_expired_alert'] = !empty($_POST['contract_expired_alert']) ? 1 : 0;
}
if ($isNotificationsTab || isset($_POST['notify_signed_contract_uploaded'])) {
    $settings['notify_signed_contract_uploaded'] = !empty($_POST['notify_signed_contract_uploaded']) ? 1 : 0;
}

// Payment notification settings
if ($isNotificationsTab || isset($_POST['payment_failure_alert'])) {
    $settings['payment_failure_alert'] = !empty($_POST['payment_failure_alert']) ? 1 : 0;
}
if ($isNotificationsTab || isset($_POST['payment_receipts_enabled'])) {
    $settings['payment_receipts_enabled'] = !empty($_POST['payment_receipts_enabled']) ? 1 : 0;
}
foreach (['notify_invoice_paid', 'notify_invoice_paid_regular', 'notify_invoice_paid_on_demand', 'notify_invoice_paid_long_term', 'notify_invoice_paid_project'] as $notifyKey) {
    if ($isNotificationsTab || isset($_POST[$notifyKey])) {
        $settings[$notifyKey] = !empty($_POST[$notifyKey]) ? 1 : 0;
    }
}
if ($isNotificationsTab || isset($_POST['notify_client_onboarding_submit'])) {
    $settings['notify_client_onboarding_submit'] = !empty($_POST['notify_client_onboarding_submit']) ? 1 : 0;
}
if ($isNotificationsTab || isset($_POST['email_no_reply_notice_enabled'])) {
    $settings['email_no_reply_notice_enabled'] = !empty($_POST['email_no_reply_notice_enabled']) ? 1 : 0;
}
if (isset($_POST['email_no_reply_notice_text'])) {
    $notice = trim((string)$_POST['email_no_reply_notice_text']);
    $settings['email_no_reply_notice_text'] = $notice !== ''
        ? mb_substr($notice, 0, 500)
        : 'This is an automated message. Please do not reply to this email.';
}
// Link expiration warning settings
if ($isNotificationsTab || isset($_POST['link_expiration_warning'])) {
    $settings['link_expiration_warning'] = !empty($_POST['link_expiration_warning']) ? 1 : 0;
}
if (isset($_POST['link_expiration_warning_days'])) {
    $settings['link_expiration_warning_days'] = max(1, min(90, (int)$_POST['link_expiration_warning_days']));
}

// Invoice document settings
if ($isDocumentsInvoiceTab || isset($_POST['invoice_show_terms'])) {
    $settings['invoice_show_terms'] = !empty($_POST['invoice_show_terms']) ? 1 : 0;
}
if ($isDocumentsInvoiceTab || isset($_POST['invoice_show_project_code'])) {
    $settings['invoice_show_project_code'] = !empty($_POST['invoice_show_project_code']) ? 1 : 0;
}
if ($isDocumentsInvoiceTab || isset($_POST['invoice_show_due_date'])) {
    $settings['invoice_show_due_date'] = !empty($_POST['invoice_show_due_date']) ? 1 : 0;
}

// Quote settings: missing checkboxes mean "off" only when this subtab is saved.
if ($isDocumentsQuoteTab || isset($_POST['quote_scope_enabled'])) {
    $settings['quote_scope_enabled'] = !empty($_POST['quote_scope_enabled']) ? 1 : 0;
}
if ($isWorkflowTab || isset($_POST['quote_auto_create_contract'])) {
    $settings['quote_auto_create_contract'] = !empty($_POST['quote_auto_create_contract']) ? 1 : 0;
}
if ($isWorkflowTab || isset($_POST['quote_auto_create_invoice'])) {
    $settings['quote_auto_create_invoice'] = !empty($_POST['quote_auto_create_invoice']) ? 1 : 0;
}

// Contract settings
if ($isDocumentsContractTab || isset($_POST['contract_scope_enabled'])) {
    $settings['contract_scope_enabled'] = !empty($_POST['contract_scope_enabled']) ? 1 : 0;
}
if ($isDocumentsContractTab || isset($_POST['contract_memo_enabled'])) {
    $settings['contract_memo_enabled'] = !empty($_POST['contract_memo_enabled']) ? 1 : 0;
}
if (isset($_POST['signature_agreement'])) {
    $sig = trim((string)$_POST['signature_agreement']);
    $settings['signature_agreement'] = $sig !== '' ? mb_substr($sig, 0, 500) : 'By signing below, I acknowledge that this is a multi-page contract and that I have read and agree to the terms and conditions.';
}

// Custom contract sections
if (isset($_POST['section_title']) && is_array($_POST['section_title'])) {
    $sections = [];
    $titles = $_POST['section_title'];
    $contents = $_POST['section_content'] ?? [];
    $enabledMap = $_POST['section_enabled'] ?? [];
    foreach ($titles as $idx => $title) {
        $t = trim((string)$title);
        $c = trim((string)($contents[$idx] ?? ''));
        if ($t === '' && $c === '') continue;
        $sections[] = [
            'title' => mb_substr($t, 0, 200),
            'content' => mb_substr($c, 0, 10000),
            'is_enabled' => !empty($enabledMap[$idx]) ? 1 : 0,
        ];
    }
    $settings['contract_custom_sections'] = $sections;
}

// Review link for invoices
if (isset($_POST['review_link'])) {
    $rl = trim((string)$_POST['review_link']);
    $settings['review_link'] = $rl !== '' ? $rl : null;
}

if ($isBillingTab || isset($_POST['processor_import_standalone_income']) || isset($_POST['processor_import_auto_create_clients'])) {
    $settings['processor_import_standalone_income'] = !empty($_POST['processor_import_standalone_income']) ? 1 : 0;
    $settings['processor_import_auto_create_clients'] = !empty($_POST['processor_import_auto_create_clients']) ? 1 : 0;
}

// Stripe settings — save encrypted to app_config DB table (NOT to .env file)
// This allows users to enter Stripe keys via the UI without needing to edit .env
// The .env file is mounted read-only in Docker, so we can't write to it
$stripeConfigKeys = [];
if (isset($_POST['stripe_publishable_key'])) {
    $val = trim((string)$_POST['stripe_publishable_key']);
    // Always save publishable key (it's public, can be blank to clear)
    $stripeConfigKeys['stripe_publishable_key'] = $val;
}
if (isset($_POST['stripe_secret_key'])) {
    $sk = trim((string)$_POST['stripe_secret_key']);
    if ($sk !== '') {
        // Only update if a new key was entered (don't blank out existing)
        require_once __DIR__ . '/../utils/crypto.php';
        $enc = crypto_encrypt($sk);
        if ($enc) {
            $stripeConfigKeys['stripe_secret_key_enc'] = $enc;
        }
    }
}
if (isset($_POST['stripe_webhook_secret'])) {
    $ws = trim((string)$_POST['stripe_webhook_secret']);
    if ($ws !== '') {
        // Only update if a new secret was entered (don't blank out existing)
        require_once __DIR__ . '/../utils/crypto.php';
        $enc = crypto_encrypt($ws);
        if ($enc) {
            $stripeConfigKeys['stripe_webhook_secret_enc'] = $enc;
        }
    }
}

// Surcharge settings
$surchargeKeys = ['stripe_surcharge_type', 'stripe_surcharge_percent', 'stripe_surcharge_fixed', 'stripe_surcharge_split_percent', 'stripe_surcharge_message'];
foreach ($surchargeKeys as $sk) {
    if (isset($_POST[$sk])) {
        $val = trim((string)$_POST[$sk]);
        if ($sk === 'stripe_surcharge_percent' || $sk === 'stripe_surcharge_fixed' || $sk === 'stripe_surcharge_split_percent') {
            $val = (float)$val;
        }
        // Save to settings.json (like other billing settings)
        $settings[$sk] = $val;
        // Also save to app_config DB
        try {
            $pdo->prepare('INSERT INTO app_config (organization_id, config_key, config_value) VALUES (0, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)')
                ->execute([$sk, (string)$val]);
        } catch (Throwable $e) {
            // DB might not have app_config on fresh install
        }
    }
}

// Write Stripe keys to app_config table (encrypted where needed)
if (!empty($stripeConfigKeys)) {
    $stmtConfig = $pdo->prepare(
        'INSERT INTO app_config (organization_id, config_key, config_value)
         VALUES (0, ?, ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    );
    foreach ($stripeConfigKeys as $key => $val) {
        $stmtConfig->execute([$key, $val]);
    }

    // Also set in current request so they're available immediately
    foreach ($stripeConfigKeys as $key => $val) {
        putenv("$key=$val");
        $_ENV[$key] = $val;
        $appConfig[$key] = $val;
    }
}

// Also remove any stripe keys from settings so they don't get written to JSON
foreach (['stripe_publishable_key','stripe_secret_key_enc','stripe_webhook_secret_enc'] as $k) {
    unset($settings[$k]);
    unset($existing[$k]);
}

// Persist the final computed settings to app_config after all tab-specific handlers have run.
// This keeps the database-backed config in sync with settings.json across deployments.
if (isset($settings['contract_custom_sections'])) {
    $settings['contract_custom_sections_json'] = json_encode($settings['contract_custom_sections']);
    if (!in_array('contract_custom_sections_json', $generalConfigKeys, true)) {
        $generalConfigKeys[] = 'contract_custom_sections_json';
    }
}
if (!empty($generalConfigKeys)) {
    require_once __DIR__ . '/../config/db.php';
    try {
        $stmtGenFinal = $pdo->prepare(
            'INSERT INTO app_config (organization_id, config_key, config_value)
             VALUES (0, ?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        foreach ($generalConfigKeys as $key) {
            if (array_key_exists($key, $settings)) {
                $val = $settings[$key];
                if (is_array($val)) { $val = json_encode($val); }
                if ($val === null) { $val = ''; }
                $stmtGenFinal->execute([$key, (string)$val]);
            }
        }
    } catch (Throwable $e) {
        @error_log('[settings] final app_config DB write failed: ' . $e->getMessage());
    }
}

// Merge with existing file on target before writing to avoid overwriting unrelated fields
$target = $settingsFile;
$existing = [];
if (is_readable($target)) {
    $existing = json_decode(@file_get_contents($target), true) ?: [];
}

if (!pa_workforce_enabled()) {
    foreach (array_keys($settings) as $key) {
        if (str_starts_with($key, 'workforce_')) { unset($settings[$key]); }
    }
}
$merged = array_merge($existing, $settings);
// Never persist a plaintext encryption key in settings.json (env-var only).
unset($merged['encryption_key']);
$payload = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$ok = @file_put_contents($target, $payload);

// Audit the settings change
try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../utils/audit.php';
    audit_log($pdo, 'settings.update', 'settings', null, ['tab' => (string)($_POST['tab'] ?? ''), 'keys_changed' => count($settings)]);
} catch (Throwable $e) { /* never block settings save */ }
if ($ok === false) {
    // attempt permission fix (best-effort)
    if (is_dir(dirname($settingsFile))) {
        @chmod(dirname($settingsFile), 0775);
    }
    // Try fallback to repo config (src/config/settings.json) and merge similarly
    $fallback = __DIR__ . '/../config/settings.json';
    $existingFb = [];
    if (is_readable($fallback)) {
        $existingFb = json_decode(@file_get_contents($fallback), true) ?: [];
    }
    $mergedFb = array_merge($existingFb, $settings);
    $fbPayload = json_encode($mergedFb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $fbOk = @file_put_contents($fallback, $fbPayload);
    if ($fbOk !== false) {
        // Preserve tab when redirecting
        $tab = $_POST['tab'] ?? '';
        $docTab = $_POST['doc_tab'] ?? $_GET['doc_tab'] ?? '';
        $redirect = '/?page=settings&saved=1&fallback=1';
        if ($tab !== '') {
            $redirect .= '&tab=' . rawurlencode($tab);
        }
        if ($docTab !== '') {
            $redirect .= '&doc_tab=' . rawurlencode($docTab);
        }
        header('Location: ' . $redirect);
        exit;
    }

    // Redirect back with error flag
    $err = rawurlencode('failed-to-write-settings');
    header('Location: /?page=settings&saved=0&error=' . $err);
    exit;
}

$logoErr = $settings['_logo_upload_error'] ?? null;
if ($logoErr) {
    $tab = $_POST['tab'] ?? $_GET['tab'] ?? 'system';
    header('Location: /?page=settings&tab=' . rawurlencode($tab) . '&error=' . rawurlencode('Logo upload failed: ' . $logoErr));
    exit;
}

// Preserve tab and subtab when redirecting
$tab = $_POST['tab'] ?? '';
$docTab = $_POST['doc_tab'] ?? $_GET['doc_tab'] ?? '';
$redirect = '/?page=settings&saved=1';
if ($tab !== '') {
    $redirect .= '&tab=' . rawurlencode($tab);
}
if ($docTab !== '') {
    $redirect .= '&doc_tab=' . rawurlencode($docTab);
}
header('Location: ' . $redirect);
exit;
