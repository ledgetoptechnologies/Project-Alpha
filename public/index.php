<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/config/db.php';
require_once __DIR__ . '/../src/utils/request_security.php';
// Secure session cookies and start session
$isSecure = request_is_https();
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_save_handler(new App\Security\DatabaseSessionHandler($pdo), true);
    session_start();
}
ob_start();

// Security headers
require_once __DIR__ . '/../src/utils/security_headers.php';
send_security_headers();

// Resolve clean module routes before falling back to PA's legacy ?page router.
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$syncContractV2Enabled = filter_var(
    getenv('APP_SYNC_CONTRACT_V2_ENABLED') !== false ? getenv('APP_SYNC_CONTRACT_V2_ENABLED') : 'false',
    FILTER_VALIDATE_BOOLEAN
);
if ($requestPath === '/api/v1/ops/snapshot/') {
    $requestPath = '/api/v1/ops/snapshot';
}
if ($requestPath === '/api/v2/ops/snapshot/') {
    $requestPath = '/api/v2/ops/snapshot';
}
require_once __DIR__ . '/../src/utils/project_management_clean_route.php';
$projectManagementAlias = project_management_clean_route($requestPath, $_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET);
if ($projectManagementAlias === 405) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$moduleRoutes = [
    '/health/ready' => 'health/ready',
    '/time' => 'workforce/time',
    '/time/action' => 'workforce/action',
    '/workforce' => 'workforce/overview',
    '/workforce/action' => 'workforce/action',
    '/approvals' => 'workforce/approvals',
    '/approvals/action' => 'workforce/action',
    '/pay' => 'workforce/pay',
    '/pay/action' => 'workforce/action',
    '/api/v1/workforce' => 'api/workforce-v1',
    '/api/v1/catalog' => 'api/catalog-v1',
    '/api/v1/ops/snapshot' => 'api-ops-snapshot',
];
require_once __DIR__ . '/../src/utils/portal_integration_security.php';
$portalIntegrationMatch = [];
if (preg_match('#^/api/v2/integrations/([a-z0-9][a-z0-9_-]{1,63})/(pricing-hints|draft-quotes)/?$#D', $requestPath, $portalIntegrationMatch) === 1) {
    $isPricingIntegration = $portalIntegrationMatch[2] === 'pricing-hints';
    $integrationFlag = $isPricingIntegration
        ? 'APP_PORTAL_PRICING_PREVIEW_ENABLED'
        : 'APP_PORTAL_DRAFT_QUOTES_ENABLED';
    if (!portal_integration_flag_enabled($integrationFlag)) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['code' => 'NOT_FOUND']);
        exit;
    }
    $_GET['_integration_key'] = $portalIntegrationMatch[1];
    $moduleRoutes[$requestPath] = $isPricingIntegration
        ? 'api-integration-pricing-hints'
        : 'api-integration-draft-quotes';
}
if ($syncContractV2Enabled) {
    $moduleRoutes['/api/v2/ops/snapshot'] = 'api-ops-snapshot-v2';
}
if (preg_match('#^/quotes/([a-f0-9]{32})/edit/?$#D', $requestPath, $quotePublicRoute) === 1) {
    $_GET['_quote_public_id'] = $quotePublicRoute[1];
    $moduleRoutes[$requestPath] = 'quote/quotes-edit-public';
}
if ($requestPath === '/api/v1/ops/snapshot') {
    // The legacy front controller reserves `page` for routing, while this
    // versioned endpoint exposes `page` as its public pagination parameter.
    // Preserve the query value before selecting the clean-path route.
    $opsSnapshotPage = $_GET['page'] ?? null;
    $_GET['page'] = $moduleRoutes[$requestPath];
    if ($opsSnapshotPage !== null) {
        $_GET['_ops_snapshot_page'] = $opsSnapshotPage;
    } else {
        unset($_GET['_ops_snapshot_page']);
    }
} elseif (!isset($_GET['page']) && isset($moduleRoutes[$requestPath])) {
    $_GET['page'] = $moduleRoutes[$requestPath];
}
if (!isset($_GET['page']) && $requestPath === '/time-tracking') {
    header('Location: /time', true, 302);
    exit;
}

// Resolve requested page (allow letters, numbers, dashes, and slashes)
// Be defensive: some clients may accidentally URL-encode the entire query
// into the `page` parameter (e.g. page=contract%2Fcontracts-edit%26id%3D3).
// Split on any stray '&' and recover additional params into $_GET so
// the router sees the intended `page` and other GET values like `id`.
$pageRaw = isset($_GET['page']) ? (string)$_GET['page'] : 'home';
$isAjaxEarly = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest';
if (strpos($pageRaw, '&') !== false) {
    [$pagePart, $rest] = explode('&', $pageRaw, 2);
    // Merge any parsed params into $_GET if they're not already present
    parse_str($rest, $parsedExtra);
    foreach ($parsedExtra as $k => $v) {
        if (!isset($_GET[$k])) {
            $_GET[$k] = $v;
        }
    }
    $page = preg_replace('#[^a-z0-9/\-]#i', '', $pagePart);
} else {
    $page = preg_replace('#[^a-z0-9/\-]#i', '', $pageRaw);
}

$pageAliases = [
    'public_doc' => 'public-doc',
    'public_redirect' => 'public-redirect',
];
$page = $pageAliases[$page] ?? $page;

if ($page === 'api-ops-snapshot-v2' && !$syncContractV2Enabled) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// The former PA time tracker and every PA<->AL connection endpoint are retired,
// not compatibility-shimmed. Existing billing rows remain readable elsewhere.
if ($page === 'time-tracking' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: /time', true, 302);
    exit;
}
$retiredAlphaLedgerPages = [
    'settings/alphaledger-handler', 'settings/alphaledger-time-admin',
    'time-tracking/create', 'time-tracking/update', 'time-tracking/delete',
    'time-tracking/start-timer', 'time-tracking/stop-timer', 'time-tracking/alphaledger-command',
    'financial/ledger',
];
if (in_array($page, $retiredAlphaLedgerPages, true)) {
    http_response_code(410);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This standalone AlphaLedger integration endpoint has been retired. Use /time, /workforce, /approvals, or /pay.';
    exit;
}

if ($page === 'health/ready') {
    require_once __DIR__ . '/../src/controllers/health/ready.php';
    exit;
}

// Helper: Resolve view path with case-insensitive subfolder checks
function resolve_view_path(string $page): string
{
    $base = __DIR__ . '/../src/views/pages/';
    $candidates = [];

    // Special case: accounts is in auth folder
    if ($page === 'accounts') {
        $candidates[] = $base . 'auth/accounts.php';
    }
    if ($page === 'account') {
        $candidates[] = $base . 'auth/account.php';
    }
    if ($page === 'account-edit') {
        $candidates[] = $base . 'auth/account-edit.php';
    }
    if ($page === 'passkeys') {
        $candidates[] = $base . 'auth/passkeys.php';
    }
    // GDPR/CCPA account pages are in account/ subdirectory
    if ($page === 'account-deleted') {
        $candidates[] = $base . 'account/account-deleted.php';
    }

    // As-provided
    $candidates[] = $base . $page . '.php';

    // Try ucfirst for first segment (e.g., jobs -> Jobs)
    $parts = explode('/', $page);
    if (count($parts) >= 2) {
        $parts_ucfirst = $parts;
        $parts_ucfirst[0] = ucfirst($parts_ucfirst[0]);
        $candidates[] = $base . implode('/', $parts_ucfirst) . '.php';

        // Try uppercasing all segments (maybe folders/filenames are TitleCase)
        $parts_uc = array_map(function ($p) {
            return ucfirst($p);
        }, $parts);
        $candidates[] = $base . implode('/', $parts_uc) . '.php';
    }

    // Try basename only
    $candidates[] = $base . basename($page) . '.php';

    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return $base . 'home.php';
}

// Error logging — NEVER display errors to end users in production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Log to a file OUTSIDE the public web root
$errorLogDir = '/var/www/config/logs/system';
if (!is_dir($errorLogDir)) {
    $fallbackLogDir = __DIR__ . '/../config/logs/system';
    $errorLogDir = $fallbackLogDir;
}
if (!is_dir($errorLogDir)) { @mkdir($errorLogDir, 0750, true); }
ini_set('error_log', $errorLogDir . '/error_log.txt');

// Temporary debug logging: record incoming page parsing to server error log
// (remove or narrow this later once the issue is fixed)
// error_log('DEBUG incoming pageRaw=' . $pageRaw . ' parsed_page=' . $page . ' GET=' . json_encode($_GET));
// Whitelist of allowed pages
// $allowedPages = [
//     'home',
//     'projects-list',
//     'settings',
//     'financial/financial-dashboard',
//     'financial/audit',

// ];

// // If not in whitelist, force to home (or show error)
// if (!in_array($page, $allowedPages, true)) {
//     $page = 'home';
// }

// CSRF setup
require_once __DIR__ . '/../src/utils/csrf.php';
csrf_init();

// First, bootstrap database structures required for auth
require_once __DIR__ . '/../src/config/bootstrap.php';

// CORS for API endpoints. Note: stateless API routes use the 'api-' prefix
// (e.g. api-clients-search); 'api-keys' is a UI page, not an API endpoint.
// Slash-prefixed 'settings/' routes are AJAX/JSON handlers.
$isServerOnlyIntegration = in_array($page, ['api-integration-pricing-hints','api-integration-draft-quotes'], true);
if ($isServerOnlyIntegration && trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')) !== '') {
    require_once __DIR__.'/../src/utils/api_response.php';
    try{(new App\Services\PortalIntegrationAuditService())->recordCommand($pdo,(string)($_GET['_integration_key']??''),0,$page==='api-integration-pricing-hints'?App\Services\PortalIntegrationContract::PRICING_SCOPE:App\Services\PortalIntegrationContract::DRAFT_SCOPE,'denied',api_request_id(),'BROWSER_ORIGIN_DENIED');}
    catch(Throwable$error){error_log('[PortalIntegrationOrigin]['.api_request_id().'] audit='.get_class($error));header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');http_response_code(503);echo json_encode(['code'=>'AUDIT_UNAVAILABLE']);exit;}
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(403);
    echo json_encode(['code' => 'BROWSER_ORIGIN_DENIED']);
    exit;
}
$isApiEndpoint = !$isServerOnlyIntegration && (str_starts_with($page, 'api-') && !str_starts_with($page, 'api-keys'))
    || str_starts_with($page, 'settings/');
if ($isApiEndpoint) {
    $allowedOrigins = getenv('ALLOWED_ORIGINS') ? explode(',', getenv('ALLOWED_ORIGINS')) : [];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-CSRF-Token, Idempotency-Key');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// API routing (stateless, header auth)
$apiEnabled = filter_var(getenv('APP_API_ENABLED') !== false ? getenv('APP_API_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN);
if ($apiEnabled && substr($page, 0, 4) === 'api-' && !str_starts_with($page, 'api-keys')) { // exclude UI page 'api-keys'
    require_once __DIR__ . '/../src/utils/api_auth.php';
    $apiEndpointScopes = api_scope_endpoint_map();
    $requiredApiScope = $apiEndpointScopes[$page] ?? null;
    if ($requiredApiScope === null) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Unknown API endpoint']);
        exit;
    }
    $apiKey = $isServerOnlyIntegration
        ? api_require_key([$requiredApiScope],false,['application_key'=>(string)($_GET['_integration_key']??''),'capability'=>(string)$requiredApiScope])
        : api_require_key([$requiredApiScope]);

    // Map API endpoints
    $dashboardPages = ['api-dashboard-summary', 'api-financial-summary', 'api-invoices', 'api-quotes', 'api-projects', 'api-clients', 'api-ops-snapshot', 'api-ops-snapshot-v2', 'api-integration-pricing-hints', 'api-integration-draft-quotes'];
    if (in_array($page, $dashboardPages, true)) {
        $map = [
            'api-dashboard-summary'   => __DIR__ . '/../src/controllers/api/dashboard_summary.php',
            'api-financial-summary'   => __DIR__ . '/../src/controllers/api/financial_summary.php',
            'api-invoices'              => __DIR__ . '/../src/controllers/api/invoices_list.php',
            'api-quotes'                => __DIR__ . '/../src/controllers/api/quotes_list.php',
            'api-projects'              => __DIR__ . '/../src/controllers/api/projects_list.php',
            'api-clients'               => __DIR__ . '/../src/controllers/api/clients_list.php',
            'api-ops-snapshot'           => __DIR__ . '/../src/controllers/api/ops_snapshot.php',
            'api-ops-snapshot-v2'        => __DIR__ . '/../src/controllers/api/ops_snapshot_v2.php',
            'api-integration-pricing-hints' => __DIR__ . '/../src/controllers/api/integration_pricing_hints.php',
            'api-integration-draft-quotes' => __DIR__ . '/../src/controllers/api/integration_draft_quotes.php',
        ];
        require_once $map[$page];
        exit;
    }

    if ($page === 'api-clients-search') {
        require_once __DIR__ . '/../src/controllers/client/clients_search.php';
        exit;
    }

    // Unknown API endpoint
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Handle logout early
if ($page === 'logout') {
    // SameSite=Lax sends cookies on cross-site top-level GETs, so logout must
    // remain a same-origin, CSRF-protected mutation.
    $logoutToken = (string)($_POST['csrf'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
        || empty($_SESSION['csrf'])
        || !hash_equals((string)$_SESSION['csrf'], $logoutToken)) {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Logout requires a same-origin POST request.');
    }
    
    // Audit the logout before clearing session
    if (!empty($_SESSION['user']['id'])) {
        try {
            require_once __DIR__ . '/../src/config/db.php';
            require_once __DIR__ . '/../src/utils/audit.php';
            audit_log($pdo, 'auth.logout', 'user', (int)$_SESSION['user']['id']);
        } catch (Throwable $e) { /* never block logout */ }
    }
    
    // Clear session data
    $_SESSION = [];
    
    // Delete session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    
    // Clear remember-me cookie
    setcookie('remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    
    // Destroy the session
    session_destroy();
    
    // Redirect to logout confirmation (which will start a fresh session)
    header('Location: /?page=logout-confirm');
    exit;
}

// Allow unauthenticated access only to explicit public pages
// NOTE: serve-upload enforces granular access itself (public images/logos only; PDFs & subdirs require auth)
$publicPages = ['login', 'session-status', 'serve-upload', 'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update', '2fa-verify', '2fa-verify-action', 'passkey-options', 'passkey-complete', 'public-doc', 'public-doc-pdf', 'public-redirect', 'public-project', 'public-project-upload', 'public-project-file', 'payment-receipt', 'client-onboarding', 'client-onboarding-submit', 'public-quote-action', 'public-contract-sign', 'stripe-checkout', 'stripe-success', 'stripe-webhook', 'stripe-webhook-legacy', 'legal/terms-of-service', 'legal/privacy-policy', 'legal/acceptable-use-policy', 'legal/dmca-policy', 'legal/data-retention-policy', 'account-deleted'];

// Toggle to disable auth checks in development/testing
$authDisabled = filter_var(getenv('AUTH_DISABLED') ?: getenv('APP_AUTH_DISABLED') ?: '', FILTER_VALIDATE_BOOLEAN);
$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
$authBypassAllowed = in_array($appEnv, ['development', 'dev', 'local', 'test', 'testing'], true);
if ($authDisabled && !$authBypassAllowed) {
    error_log('[security] AUTH_DISABLED ignored because APP_ENV is production or not explicitly development/test');
    $authDisabled = false;
}

// Allow POST to auth handler without prior login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'auth') {
    require_once __DIR__ . '/../src/controllers/auth/auth_handler.php';
    exit;
}

// Attempt remember-me auto login before enforcing auth (temporarily disabled)
if (false && empty($_SESSION['user']) && isset($_COOKIE['remember'])) {
    require_once __DIR__ . '/../src/utils/crypto.php';
    require_once __DIR__ . '/../src/config/db.php';
    $raw = (string)$_COOKIE['remember'];
    $parts = explode('|', $raw);
    if (count($parts) === 3) {
        [$uidStr, $expStr, $hmacB64] = $parts;
        $uid = (int)$uidStr;
        $exp = (int)$expStr;
        $key = crypto_get_key();
        if ($uid > 0 && $exp > time() && $key !== '') {
            $data = $uid . '|' . $exp;
            $calc = base64_encode(hash_hmac('sha256', $data, $key, true));
            if (hash_equals($calc, $hmacB64)) {
                try {
                    $st = $pdo->prepare('SELECT id, email, role FROM users WHERE id=?');
                    $st->execute([$uid]);
                    $u = $st->fetch(PDO::FETCH_ASSOC);
                    if ($u) {
                        $_SESSION['user'] = ['id' => (int)$u['id'], 'email' => $u['email'], 'role' => $u['role']];
                    }
                } catch (Throwable $e) { /* ignore */
                }
            }
        }
    }
}

// Development/test auth bypass: create a real session for the first active admin
// so the application behaves normally without weakening production deployments.
if ($authDisabled && empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    try {
        require_once __DIR__ . '/../src/config/db.php';
        $stmt = $pdo->query('
            SELECT id, email, role, auth_version
            FROM users
            WHERE COALESCE(is_disabled, 0) = 0
            ORDER BY (role = "admin") DESC, id ASC
            LIMIT 1
        ');
        $bypassUser = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($bypassUser) {
            $activeOrgId = 0;
            try {
                $orgStmt = $pdo->prepare('SELECT organization_id FROM user_organizations WHERE user_id = ? ORDER BY id ASC LIMIT 1');
                $orgStmt->execute([(int)$bypassUser['id']]);
                $activeOrgId = (int)($orgStmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $activeOrgId = 0;
            }
            if ($activeOrgId <= 0) {
                try {
                    $activeOrgId = (int)($pdo->query('SELECT id FROM organizations ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                    $activeOrgId = 0;
                }
            }
            App\Security\SessionPolicy::rotateAuthenticatedId();
            $_SESSION['user'] = [
                'id' => (int)$bypassUser['id'],
                'email' => (string)$bypassUser['email'],
                'role' => (string)$bypassUser['role'],
                'auth_version' => (int)$bypassUser['auth_version'],
                'active_org_id' => $activeOrgId,
                'auth_bypass' => true,
            ];
            App\Security\SessionPolicy::completeAuthentication('development_bypass');
            if (empty($_SESSION['auth_bypass_logged'])) {
                error_log('[security] Development auth bypass signed in user id ' . (int)$bypassUser['id']);
                $_SESSION['auth_bypass_logged'] = 1;
            }
        }
    } catch (Throwable $e) {
        error_log('[security] AUTH_DISABLED could not create a development session: ' . $e->getMessage());
    }
}

// Enforce authentication for everything else (unless disabled)
if (!$authDisabled && empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    header('Location: /?page=login');
    exit;
}

// Privileged and workforce sessions use a 15-minute idle timeout. The
// database session handler separately enforces a seven-day absolute maximum.
if (!empty($_SESSION['user'])) {
    try {
        $sessionUser = $pdo->prepare('SELECT role, is_disabled, deleted_at, auth_version FROM users WHERE id = ? LIMIT 1');
        $sessionUser->execute([(int)$_SESSION['user']['id']]);
        $currentUser = $sessionUser->fetch(PDO::FETCH_ASSOC);
        $sessionVersion = (int)($_SESSION['user']['auth_version'] ?? 0);
        if (!$currentUser || (int)$currentUser['is_disabled'] !== 0 || !empty($currentUser['deleted_at'])
            || $sessionVersion < 1 || $sessionVersion !== (int)$currentUser['auth_version']) {
            $_SESSION = [];
            session_destroy();
            if ($page === 'session-status') {
                require_once __DIR__ . '/../src/controllers/auth/session_status.php';
                exit;
            }
            header('Location: /?page=login&error=' . urlencode('Your session was revoked. Please sign in again.'));
            exit;
        }
        $_SESSION['user']['role'] = (string)$currentUser['role'];
        $_SESSION['user']['app_role'] = (string)$currentUser['role'];
    } catch (Throwable $e) {
        error_log('[security] session revocation check failed: ' . $e->getMessage());
        $_SESSION = [];
        session_destroy();
        if ($page === 'session-status') {
            require_once __DIR__ . '/../src/controllers/auth/session_status.php';
            exit;
        }
        header('Location: /?page=login&error=' . urlencode('Unable to validate your session. Please sign in again.'));
        exit;
    }

    $sessionTimeout = App\Security\SessionPolicy::IDLE_SECONDS;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $sessionTimeout) {
        $_SESSION = [];
        session_destroy();
        if ($page === 'session-status') {
            require_once __DIR__ . '/../src/controllers/auth/session_status.php';
            exit;
        }
        header('Location: /?page=login&error=' . urlencode('Session expired. Please log in again.'));
        exit;
    }
    // Only explicitly classified user activity refreshes the idle deadline.
    if (App\Security\SessionPolicy::isIntentionalActivity($page, $_GET)) {
        $_SESSION['last_activity'] = time();
    }
}

// A recovery that explicitly reset TOTP must finish enrollment after the
// temporary password has been replaced and before any other application use.
if (!empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    $allowedForTotpReenroll = ['2fa-setup', '2fa-setup-action', 'logout', 'logout-confirm', 'session-status'];
    if (!in_array($page, $allowedForTotpReenroll, true)) {
        try {
            $totpStmt = $pdo->prepare('SELECT force_password_reset, totp_reenroll_required FROM users WHERE id = ?');
            $totpStmt->execute([(int)$_SESSION['user']['id']]);
            $recoveryState = $totpStmt->fetch(PDO::FETCH_ASSOC);
            if ((int)($recoveryState['force_password_reset'] ?? 0) === 0
                && (int)($recoveryState['totp_reenroll_required'] ?? 0) === 1) {
                header('Location: /?page=2fa-setup&required=1&recovery=1');
                exit;
            }
        } catch (Throwable $e) {
            error_log('[security] TOTP reenrollment gate failed: ' . $e->getMessage());
        }
    }
}

// Enforce force-password-reset: lock user to account page until they change it
if (!empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    $allowedForForceReset = ['account', 'account-update', 'logout'];
    if (!in_array($page, $allowedForForceReset, true)) {
        try {
            require_once __DIR__ . '/../src/config/db.php';
            $fpStmt = $pdo->prepare('SELECT force_password_reset FROM users WHERE id = ?');
            $fpStmt->execute([(int)$_SESSION['user']['id']]);
            if ((int)$fpStmt->fetchColumn() === 1) {
                header('Location: /?page=account&force=1');
                exit;
            }
        } catch (Throwable $e) { /* allow through if check fails */ }
    }
}

// Encourage administrators and privileged operators to enroll TOTP. This is
// intentionally non-blocking: the layout offers setup or dismissal instead of
// redirecting users away from their work.
if (!empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    try {
        require_once __DIR__ . '/../src/config/db.php';
        require_once __DIR__ . '/../src/utils/two_factor_policy.php';
        $_SESSION['two_factor_warning_required'] = two_factor_warning_needed($pdo, $page) ? 1 : 0;
    } catch (Throwable $e) {
        // Do not lock users out if the policy check cannot be evaluated during
        // installation/recovery. The production readiness check warns loudly
        // when schema/configuration is incomplete.
        error_log('[security] 2FA policy check failed: ' . $e->getMessage());
    }
}

// Global error/exception/shutdown handlers: route PHP errors to Monolog with error_log() fallback
require_once __DIR__ . '/../src/utils/logger.php';
$errLogger = app_logger('error');
set_error_handler(function ($severity, $message, $file, $line) use ($errLogger) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    try {
        $errLogger->error('PHP error', [
            'severity' => $severity,
            'message'  => $message,
            'file'     => $file,
            'line'     => $line,
        ]);
    } catch (Throwable $e) {
        error_log(sprintf('[PHP error] severity=%d message=%s file=%s line=%d', $severity, $message, $file, $line));
    }
    return true;
});
set_exception_handler(function ($e) use ($errLogger) {
    try {
        $errLogger->error('Uncaught exception', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);
    } catch (Throwable $e2) {
        error_log('[Uncaught exception] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
});
register_shutdown_function(function () use ($errLogger) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        try {
            $errLogger->error('Fatal shutdown', [
                'type'    => $e['type'],
                'message' => $e['message'],
                'file'    => $e['file'],
                'line'    => $e['line'],
            ]);
        } catch (Throwable $_e) {
            error_log(sprintf('[Fatal shutdown] type=%d message=%s file=%s line=%d', $e['type'], $e['message'], $e['file'], $e['line']));
        }
    }
});

// Audit middleware: guarantees baseline audit rows for sensitive actions
// (payments, 2FA changes, API keys, exports, deletes) even when the routed
// controller doesn't call audit_log() itself. See src/utils/audit_middleware.php.
try {
    require_once __DIR__ . '/../src/config/db.php';
    require_once __DIR__ . '/../src/utils/audit_middleware.php';
    audit_middleware($pdo, $page);
} catch (Throwable $e) {
    @error_log('[audit] middleware init failed: ' . $e->getMessage());
}

// ACL middleware — permission check after login, before controller dispatch
try {
    require_once __DIR__ . '/../src/utils/acl_middleware.php';
    if (!empty($_SESSION['user']) && $page === 'home') {
        $dashboardUserId = (int)($_SESSION['user']['id'] ?? 0);
        if ($dashboardUserId > 0 && ($_SESSION['user']['role'] ?? '') !== 'admin'
            && !user_can($pdo, $dashboardUserId, 'financial.view', get_active_org_id())) {
            $page = 'user-dashboard';
        }
    }
    acl_middleware($pdo, $page);
} catch (Throwable $e) {
    @error_log('[acl] middleware failed: ' . $e->getMessage());
}

if ($page === 'settings/dropbox-oauth') {
    require_once __DIR__ . '/../src/controllers/settings/dropbox_oauth.php';
    exit;
}
if ($page === 'settings/gmail-oauth') {
    require_once __DIR__ . '/../src/controllers/settings/gmail_oauth.php';
    exit;
}
// These endpoints validate their own JSON or form CSRF tokens and return
// responses without the application layout.
$directControllers = [
    'passkey-options' => 'auth/passkey_options.php',
    'passkey-complete' => 'auth/passkey_complete.php',
    'passkey-register-options' => 'auth/passkey_register_options.php',
    'passkey-register-complete' => 'auth/passkey_register_complete.php',
    'passkey-manage' => 'auth/passkey_manage.php',
    'passkey-admin-reset' => 'auth/admin_passkey_reset.php',
    'api/workforce-v1' => 'api/workforce_v1.php',
    'api/catalog-v1' => 'api/catalog_v1.php',
    'settings/workforce-catalog-handler' => 'settings/workforce_catalog_handler.php',
    'settings/external-ops-handler' => 'settings/external_ops_handler.php',
    'workforce/contractor-invoice' => 'workforce/contractor_invoice.php',
    'workforce/contractor-invoice-download' => 'workforce/contractor_invoice_download.php',
];
if (isset($directControllers[$page])) {
    require_once __DIR__ . '/../src/controllers/' . $directControllers[$page];
    exit;
}

// API/GET endpoints that should bypass layout (still require auth by default)
if ($page === 'clients-search') {
    require_once __DIR__ . '/../src/controllers/client/clients_search.php';
    exit;
}
if ($page === 'projects-search-autocomplete') {
    require_once __DIR__ . '/../src/controllers/project/projects_search_autocomplete.php';
    exit;
}
if ($page === 'projects-search') {
    require_once __DIR__ . '/../src/controllers/project/projects_search.php';
    exit;
}
// Organization search for client creation (AJAX)
if ($page === 'org-search' || $page === 'organization/org-search') {
    require_once __DIR__ . '/../src/controllers/organization/org_search.php';
    exit;
}
if ($page === 'organization/organization-departments-options') {
    require_once __DIR__ . '/../src/controllers/organization/organization_departments_options.php';
    exit;
}
if ($page === 'project/client-options') {
    require_once __DIR__ . '/../src/controllers/project/project_client_options.php';
    exit;
}
if ($page === 'time-tracking/unbilled') {
    require_once __DIR__ . '/../src/controllers/time-tracking/time_entries_unbilled.php';
    exit;
}
if ($page === 'financial/mileage-unbilled') {
    require_once __DIR__ . '/../src/controllers/financial/mileage_unbilled.php';
    exit;
}
if ($page === 'financial/mileage-tracking-api') {
    require_once __DIR__ . '/../src/controllers/financial/mileage_tracking_api.php';
    exit;
}
if ($page === 'financial/route-estimate') {
    require_once __DIR__ . '/../src/controllers/financial/route_estimate.php';
    exit;
}
if ($page === 'time-tracking/options') {
    require_once __DIR__ . '/../src/controllers/time-tracking/time_entry_options.php';
    exit;
}
if ($page === 'financial/financial-api') {
    require_once __DIR__ . '/../src/controllers/financial/financial_api.php';
    exit;
}
if ($page === 'settings/item-library-search') {
    require_once __DIR__ . '/../src/controllers/settings/item_library_search.php';
    exit;
}
if ($page === 'tax-lookup') {
    require_once __DIR__ . '/../src/controllers/tax_lookup.php';
    exit;
}
if ($page === 'settings/logs') {
    require_once __DIR__ . '/../src/views/pages/settings/logs.php';
    exit;
}
if ($page === 'settings/logs-handler' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../src/controllers/settings/logs_handler.php';
    exit;
}
if ($page === 'settings/permissions') {
    require_once __DIR__ . '/../src/views/pages/settings/permissions.php';
    exit;
}
if ($page === 'settings/permissions-handler') {
    require_once __DIR__ . '/../src/controllers/settings/permissions_handler.php';
    exit;
}
if ($page === 'custom-fields-ajax') {
    require_once __DIR__ . '/../src/controllers/api/custom_fields_ajax.php';
    exit;
}
// Document custom fields handler (GET for fetching field data)
if ($page === 'settings/document-custom-fields-handler' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../src/controllers/settings/document-custom-fields-handler.php';
    exit;
}
if ($isAjaxEarly && $page === 'project-notes') {
    require_once __DIR__ . '/../src/controllers/project_notes.php';
    exit;
}
if ($isAjaxEarly && $page === 'project/projects-list') {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly && $page === 'project/projects-create') {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly && ($page === 'jobs/jobs-list' || $page === 'jobs-list')) {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly && $page === 'jobs/job-details') {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly) {
    if ($page === 'quote/quotes-edit' || $page === 'quotes-edit') {
        require_once __DIR__ . '/../src/views/pages/quote/quotes-edit.php';
        exit;
    }
    if ($page === 'contract/contracts-edit' || $page === 'contracts-edit') {
        require_once __DIR__ . '/../src/views/pages/contract/contracts-edit.php';
        exit;
    }
    if ($page === 'invoice/invoices-edit' || $page === 'invoices-edit') {
        require_once __DIR__ . '/../src/views/pages/invoice/invoices-edit.php';
        exit;
    }
}
// If someone lands on email-test via GET (e.g., CSRF redirect), send them back to Settings -> System (email section)
if ($page === 'email-test' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $suffix = '';
    if (!empty($_GET['error'])) {
        $suffix = '&email_err=' . rawurlencode((string)$_GET['error']);
    }
    header('Location: /?page=settings&tab=system' . $suffix);
    exit;
}
if ($page === 'serveupload' || $page === 'serve-upload') {
    require_once __DIR__ . '/../src/controllers/serve_upload.php';
    exit;
}
// Handle PDF generation routes - only -pdf pages, not -print pages
if (in_array($page, ['contract/contract-pdf', 'contract-pdf'])) {
    require_once __DIR__ . '/../src/controllers/contract/contract_pdf.php';
    exit;
}
if (in_array($page, ['quote/quote-pdf', 'quote-pdf'])) {
    require_once __DIR__ . '/../src/controllers/quote/quote_pdf.php';
    exit;
}
if (in_array($page, ['invoice/invoice-pdf', 'invoice-pdf'])) {
    require_once __DIR__ . '/../src/controllers/invoice/invoice_pdf.php';
    exit;
}
if ($page === 'project/project-invoice-pdf') {
    require_once __DIR__ . '/../src/controllers/project/project_invoice_pdf.php';
    exit;
}
if ($page === 'project/project-file-download') {
    require_once __DIR__ . '/../src/controllers/project/project_file_download.php';
    exit;
}
if (in_array($page, ['worker-document-download', 'employee-document-download'], true)) {
    require_once __DIR__ . '/../src/controllers/accounts/worker_document_download.php';
    exit;
}
if (in_array($page, ['quote/long-term-quote-pdf', 'long-term-quote-pdf'])) {
    require_once __DIR__ . '/../src/controllers/quote/quote_pdf.php';
    exit;
}
if (in_array($page, ['contract/long-term-contract-pdf', 'long-term-contract-pdf'])) {
    require_once __DIR__ . '/../src/controllers/contract/contract_pdf.php';
    exit;
}
if ($page === 'settings/dropbox-oauth') {
    require_once __DIR__ . '/../src/controllers/settings/dropbox_oauth.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add a global per-IP rate-limit gate before routing. Endpoint-specific limits
    // (e.g. public links) may use tighter checks in their own controllers.
    require_once __DIR__ . '/../src/config/db.php';
    require_once __DIR__ . '/../src/utils/client_ip.php';

    // Global POST rate limiter: block only per-IP, not per-page, and skip for
    // legitimate authenticated actions that naturally chain POSTs.
    $skipGlobalRateLimitFor = [
        // Settings & system
        'settings',
        'settings-backup',
        'settings/backup-download',
        'settings/tax-rates-handler',
        'settings/pricing-adjustments-handler',
        'settings/tax-import-handler',
        'settings/tax-import-chunk',
        'settings/links-handler',
        'settings/permissions-handler',
        'settings/custom-fields-handler',
        'settings/item-library-handler',
        'settings/document-customization-save',
        'settings/document-custom-fields-handler',
        'settings/link-test-connection',
        'settings/managed-delivery-test',
        'settings/managed-delivery-send',
        'settings/managed-delivery-revoke',
        'settings/managed-delivery-retry',
        'settings/stripe-net-backfill',
        'settings/stripe-import-payments',
        'settings/dropbox-oauth',
        'settings/email-provider-handler',
        'settings/link-resolver-run',

        // User accounts / auth management
        'auth/account-edit',
        'account-update',
        'account-notification-prefs',
        'account-revoke-device',
        'account/delete',
        'accounts-create',
        'accounts-update',
        'worker-documents',
        'employee-documents',
        'accounts-delete',
        'accounts-reset-password',
        '2fa-setup-action',
        '2fa-verify-action',
        '2fa-admin-disable',
        '2fa-warning-dismiss',

        // API keys
        'api-keys-create',
        'api-keys-update',
        'api-keys-revoke',

        // Clients
        'client/client-create',
        'client/clients-create',
        'clients-create',
        'client/client-update',
        'client/clients-update',
        'clients-update',
        'client/clients-delete',
        'clients-delete',
        'client/clients-restore',
        'clients-restore',
        'client/clients-purge',
        'clients-purge',

        // Projects
        'project/project-create',
        'project/projects-create',
        'project/project-update',
        'project/projects-update',
        'project/projects-delete',
        'project/project-add-document',
        'project/project-remove-document',
        'project/project-files',
        'project/project-invoice-generate',
        'project/project-invoice-email',
        'project/project-invoice-payment',
        'project/projects-update-status',
        'project/project-work-handler',
        'project-notes-update',

        // Quotes
        'quote/quotes-create',
        'quotes-create',
        'quote/quotes-update',
        'quotes-update',
        'quote/quote-approve',
        'quote/quote-decline',
        'quote/quote-reject',
        'quote-reject',
        'quote/email-send',

        // Contracts
        'contract/contract-action',
        'contract/contract-create',
        'contract/contracts-create',
        'contracts-create',
        'contract/contracts-update',
        'contracts-update',
        'contract/contract-sign',
        'contract/contract-complete',
        'contract/contract-void',
        'contract/contract-deposit-received',
        'contract/contract-deny',
        'contract/email-send',
        'long-term-contracts-create',
        'contract/long-term-contracts-create',
        'long-term-contract-activate',
        'long-term-contract-pause',
        'long-term-contract-resume',
        'long-term-contract-terminate',
        'on-demand-contract-activate',
        'on-demand-contract-pause',
        'on-demand-contract-resume',
        'on-demand-contract-terminate',
        'on-demand-invoice-generate',

        // Invoices / payments
        'invoice/invoice-create',
        'invoice/invoice-action',
        'invoice/invoices-create',
        'invoices-create',
        'invoice/invoices-update',
        'invoices-update',
        'invoice/invoices-mark-paid',
        'invoice/invoice-finalize',
        'invoice/invoice-reopen',
        'invoice/invoice-void',
        'invoice/invoice-reenable',
        'invoice/email-send',
        'payments/payments-create',

        // Documents / forms / receipts
        'document-reenable',
        'document-date-update',
        'receipts-handler',
        'forms-handler',

        // Organizations
        'organization/org-create',
        'organization/organizations-create',
        'organization/organizations-update',
        'organization/organizations-delete',
        'organization/organization-add-client',
        'organization/organization-update-notes',
        'organization-update-notes',
        'organization/organization-remove-client',
        'organization/organization-departments',
        'organization/organizations_upload',
        'organization/organizations-upload',

        // Links / public links
        'public-link-create',
        'public-link-revoke',
        'links/link-management',
        'links/manual-link-handler',

        // Financial
        'financial/audit-export',
        'financial/audit-schedule-handler',
        'financial/mileage-handler',
        'financial/vendor-handler',
        'financial/category-handler',
        'financial/asset-handler',
        'financial/expense-handler',
        'financial/expense_handler',
        'financial/recurring-expense-handler',
        'financial/csv-import',

        // Email / legal / other
        'email-send',
        'email-test',
        'legal/tos-accept',
        'stripe-charge',
    ];
    if (!in_array($page, $skipGlobalRateLimitFor, true)) {
        $clientIp = get_client_ip();
        $globalPostKey = 'global_post_' . md5($clientIp);
        if (!rate_limit_check($pdo, $globalPostKey, 300, 60, false)) {
            error_log('Rate limit exceeded: global_post for IP ' . $clientIp . ' page=' . $page);
            http_response_code(429);
            header('Content-Type: text/plain');
            echo 'Too many requests. Please slow down.';
            exit;
        }
    }

    // Enforce CSRF on most POST endpoints, but allow controllers with their own CSRF/validation.
    // Reasons for bypasses:
    //   auth                         - controller validates CSRF (csrf_sf_is_valid 'auth')
    //   reset-request                - controller validates CSRF (csrf_sf_is_valid 'reset_request')
    //   reset-verify                 - controller validates CSRF (csrf_sf_is_valid 'reset_verify')
    //   reset-update                 - controller validates CSRF (csrf_sf_is_valid 'reset_update')
    //   public-quote-action          - controller validates CSRF (csrf_sf_is_valid 'public_quote_action')
    //   public-contract-sign         - controller validates CSRF (csrf_sf_is_valid 'public_contract_sign')
    //   public-contract-action       - controller validates CSRF (csrf_sf_is_valid 'public_contract_action')
    //   public-project-upload        - controller validates CSRF and public project ACLs
    //   organization/org-create      - controller validates CSRF (legacy session hash_equals)
    //   organization/organization-update-notes - controller validates CSRF (csrf_validate)
    //   stripe-webhook               - tokenless: Stripe webhook uses signature verification (HMAC + replay protection)
    //   stripe-webhook-legacy        - tokenless: legacy Stripe webhook uses signature verification (HMAC + replay protection)
    //   settings/link-test-connection - controller validates CSRF (csrf_validate)
    //   settings/link-resolver-run    - controller validates CSRF (csrf_validate)
    //   legal/tos-accept             - controller validates CSRF (csrf_sf_verify_or_redirect 'auth')
    // This controller resolves an entity-specific return path before validating
    // its own CSRF token and authorization contract.
    $skipCsrfFor = ['auth', 'reset-request', 'reset-verify', 'reset-update', '2fa-setup-action', '2fa-verify-action', 'public-quote-action', 'public-contract-sign', 'public-contract-action', 'public-project-upload', 'organization/org-create', 'organization/organization-update-notes', 'stripe-webhook', 'stripe-webhook-legacy', 'settings/link-test-connection', 'settings/link-resolver-run', 'settings/managed-delivery-test', 'settings/managed-delivery-send', 'settings/managed-delivery-revoke', 'settings/managed-delivery-retry', 'legal/tos-accept', 'portal/service-assignments-handler'];
    if (!in_array($page, $skipCsrfFor, true)) {
        csrf_verify_post_or_redirect($page);
    }

    if ($page === 'settings') {
        require_once __DIR__ . '/../src/controllers/settings_handler.php';
        exit;
    }
    if ($page === 'jobs/job-settings-handler') {
        require_once __DIR__ . '/../src/controllers/jobs/job_settings_handler.php';
        exit;
    }
    if ($page === 'quote/quote-clone') {
        require_once __DIR__ . '/../src/controllers/quote/quote_clone.php';
        exit;
    }
    if ($page === 'settings/email-provider-handler') {
        require_once __DIR__ . '/../src/controllers/settings/email_provider_handler.php';
        exit;
    }
    if ($page === 'workforce/action') {
        require_once __DIR__ . '/../src/controllers/workforce/action.php';
        exit;
    }
    if ($page === 'settings-backup') {
        require_once __DIR__ . '/../src/controllers/backup_handler.php';
        exit;
    }
    if ($page === 'settings/tax-rates-handler') {
        require_once __DIR__ . '/../src/controllers/settings/tax-rates-handler.php';
        exit;
    }
    if ($page === 'settings/pricing-adjustments-handler') {
        require_once __DIR__ . '/../src/controllers/settings/pricing_adjustments_handler.php';
        exit;
    }
    if ($page === 'settings/tax-import-handler') {
        require_once __DIR__ . '/../src/controllers/settings/tax-import-handler.php';
        exit;
    }
    if ($page === 'settings/tax-import-chunk') {
        require_once __DIR__ . '/../src/controllers/settings/tax_import_chunk.php';
        exit;
    }
    if ($page === 'settings/links-handler') {
        require_once __DIR__ . '/../src/controllers/settings/links_handler.php';
        exit;
    }
    if ($page === 'settings/permissions-handler') {
        require_once __DIR__ . '/../src/controllers/settings/permissions_handler.php';
        exit;
    }
    if ($page === 'settings/logs-handler') {
        require_once __DIR__ . '/../src/controllers/settings/logs_handler.php';
        exit;
    }
    if ($page === 'settings/stripe-net-backfill') {
        require_once __DIR__ . '/../src/controllers/settings/stripe_net_backfill.php';
        exit;
    }
    if ($page === 'settings/stripe-import-payments') {
        require_once __DIR__ . '/../src/controllers/settings/stripe_import_payments.php';
        exit;
    }
    if ($page === 'settings/custom-fields-handler') {
        require_once __DIR__ . '/../src/controllers/settings/custom_fields_handler.php';
        exit;
    }
    if ($page === 'accounts-create') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_create.php';
        exit;
    }
    if ($page === 'accounts-update') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_update.php';
        exit;
    }
    if (in_array($page, ['worker-documents', 'employee-documents'], true)) {
        require_once __DIR__ . '/../src/controllers/accounts/worker_documents.php';
        exit;
    }
    if ($page === 'accounts-delete') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_delete.php';
        exit;
    }
    if ($page === 'accounts-reset-password') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_reset_password.php';
        exit;
    }
    if ($page === '2fa-setup-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_setup.php';
        exit;
    }
    if ($page === '2fa-verify-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_verify.php';
        exit;
    }
    if ($page === '2fa-admin-disable') {
        require_once __DIR__ . '/../src/controllers/auth/admin_2fa_disable.php';
        exit;
    }
    if ($page === '2fa-warning-dismiss') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_warning_dismiss.php';
        exit;
    }
    if ($page === 'reset-request') {
        require_once __DIR__ . '/../src/controllers/auth/reset_request.php';
        exit;
    }
    if ($page === 'reset-verify') {
        require_once __DIR__ . '/../src/controllers/auth/reset_verify.php';
        exit;
    }
    if ($page === 'reset-update') {
        require_once __DIR__ . '/../src/controllers/auth/reset_update.php';
        exit;
    }
    if ($page === 'public-quote-action') {
        require_once __DIR__ . '/../src/controllers/public_view/public_quote_action.php';
        exit;
    }
    if ($page === 'public-contract-sign') {
        require_once __DIR__ . '/../src/controllers/public_view/public_contract_sign.php';
        exit;
    }
    if ($page === 'public-project-upload') {
        require_once __DIR__ . '/../src/controllers/public_view/public_project_upload.php';
        exit;
    }
    if ($page === 'api-keys-create') {
        require_once __DIR__ . '/../src/controllers/api_keys_create.php';
        exit;
    }
    if ($page === 'api-keys-update') {
        require_once __DIR__ . '/../src/controllers/api_keys_update.php';
        exit;
    }
    if ($page === 'api-keys-revoke') {
        require_once __DIR__ . '/../src/controllers/api_keys_revoke.php';
        exit;
    }
    if ($page === 'client/clients-create' || $page === 'clients-create') {
        require_once __DIR__ . '/../src/controllers/client/clients_create.php';
        exit;
    }
    if ($page === 'portal/service-assignments-handler') {
        require_once __DIR__ . '/../src/controllers/portal/service_assignments_handler.php';
        exit;
    }
    if ($page === 'client/onboarding-invite') {
        require_once __DIR__ . '/../src/controllers/client/client_onboarding_invite.php';
        exit;
    }
    if ($page === 'client/onboarding-review') {
        require_once __DIR__ . '/../src/controllers/client/client_onboarding_review.php';
        exit;
    }
    if ($page === 'client-onboarding-submit') {
        require_once __DIR__ . '/../src/controllers/public_view/client_onboarding_submit.php';
        exit;
    }
    if ($page === 'project/projects-create') {
        require_once __DIR__ . '/../src/controllers/project/projects_create.php';
        exit;
    }
    if ($page === 'project/projects-update') {
        require_once __DIR__ . '/../src/controllers/project/projects_update.php';
        exit;
    }
    if ($page === 'project/project-work-handler') {
        require_once __DIR__ . '/../src/controllers/project/project_work_handler.php';
        exit;
    }
    if ($page === 'project/projects-delete') {
        require_once __DIR__ . '/../src/controllers/project/projects_delete.php';
        exit;
    }
    if ($page === 'project/project-add-document') {
        require_once __DIR__ . '/../src/controllers/project/project_add_document.php';
        exit;
    }
    if ($page === 'project/project-remove-document') {
        require_once __DIR__ . '/../src/controllers/project/project_remove_document.php';
        exit;
    }
    if ($page === 'project/project-files') {
        require_once __DIR__ . '/../src/controllers/project/project_files_handler.php';
        exit;
    }
    if ($page === 'settings/backup-download') {
        require_once __DIR__ . '/../src/controllers/settings/backup_download.php';
        exit;
    }
    if ($page === 'project/project-invoice-generate') {
        require_once __DIR__ . '/../src/controllers/project/project_invoice_generate.php';
        exit;
    }
    if ($page === 'project/project-invoice-email') {
        require_once __DIR__ . '/../src/controllers/project/project_invoice_email.php';
        exit;
    }
    if ($page === 'project/project-invoice-payment') {
        require_once __DIR__ . '/../src/controllers/project/project_invoice_payment.php';
        exit;
    }
    if ($page === 'project/projects-update-status') {
        require_once __DIR__ . '/../src/controllers/project/projects_update_status.php';
        exit;
    }
    if ($page === 'quote/quotes-create' || $page === 'quotes-create') {
        require_once __DIR__ . '/../src/controllers/quote/quotes_create.php';
        exit;
    }
    if ($page === 'quote/quote-approve') {
        require_once __DIR__ . '/../src/controllers/quote/quote_approve.php';
        exit;
    }
    if ($page === 'contract/contract-sign') {
        require_once __DIR__ . '/../src/controllers/contract/contract_sign.php';
        exit;
    }
    if ($page === 'contract/contract-complete') {
        require_once __DIR__ . '/../src/controllers/contract/contract_complete.php';
        exit;
    }
    if ($page === 'contract/contract-void') {
        require_once __DIR__ . '/../src/controllers/contract/contract_void.php';
        exit;
    }
    if ($page === 'contract/contract-deposit-received') {
        require_once __DIR__ . '/../src/controllers/contract/contract_deposit_received.php';
        exit;
    }
    if ($page === 'long-term-contract-activate') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_activate.php';
        exit;
    }
    if ($page === 'long-term-contract-start-billing') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_start_billing.php';
        exit;
    }
    if ($page === 'on-demand-contract-activate') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_activate.php';
        exit;
    }
    if ($page === 'on-demand-invoice-generate') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_invoice_generate.php';
        exit;
    }
    if ($page === 'on-demand-contract-pause') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_pause.php';
        exit;
    }
    if ($page === 'on-demand-contract-resume') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_resume.php';
        exit;
    }
    if ($page === 'on-demand-contract-terminate') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_terminate.php';
        exit;
    }
    if ($page === 'long-term-contract-pause') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_pause.php';
        exit;
    }
    if ($page === 'long-term-contract-resume') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_resume.php';
        exit;
    }
    if ($page === 'long-term-contract-terminate') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_terminate.php';
        exit;
    }
    if ($page === 'long-term-recurring-service-save') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_recurring_service_save.php';
        exit;
    }
    if ($page === 'long-term-recurring-service-action') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_recurring_service_action.php';
        exit;
    }
    if ($page === 'document-reenable') {
        require_once __DIR__ . '/../src/controllers/document_reenable_handler.php';
        exit;
    }
    if ($page === 'document-date-update') {
        require_once __DIR__ . '/../src/controllers/document_date_update_handler.php';
        exit;
    }
    if ($page === 'contract/contract-deny') { // legacy
        require_once __DIR__ . '/../src/controllers/contract/contract_deny.php';
        exit;
    }
    if ($page === 'invoice/invoices-mark-paid') {
        require_once __DIR__ . '/../src/controllers/invoice/invoices_mark_paid.php';
        exit;
    }
    if ($page === 'invoice/invoice-finalize') {
        require_once __DIR__ . '/../src/controllers/invoice/invoice_finalize.php';
        exit;
    }
    if ($page === 'invoice/invoice-reopen') {
        require_once __DIR__ . '/../src/controllers/invoice/invoice_reopen.php';
        exit;
    }
    if ($page === 'invoice/invoice-void') {
        require_once __DIR__ . '/../src/controllers/invoice/invoice_void.php';
        exit;
    }
    if ($page === 'invoice/invoice-reenable') {
        require_once __DIR__ . '/../src/controllers/invoice/invoice_reenable.php';
        exit;
    }
    if ($page === 'payments/payments-create') {
        require_once __DIR__ . '/../src/controllers/payments_create.php';
        exit;
    }
    if ($page === 'payments/payment-refund') {
        require_once __DIR__ . '/../src/controllers/payments_refund.php';
        exit;
    }
    if ($page === 'payments/payment-reverse') {
        require_once __DIR__ . '/../src/controllers/payments_reverse.php';
        exit;
    }
    if ($page === 'payments/payment-correct') {
        require_once __DIR__ . '/../src/controllers/payments_correct.php';
        exit;
    }
    if ($page === 'quote/quotes-update' || $page === 'quotes-update') {
        require_once __DIR__ . '/../src/controllers/quote/quotes_update.php';
        exit;
    }
    if ($page === 'client/clients-update' || $page === 'clients-update') {
        require_once __DIR__ . '/../src/controllers/client/clients_update.php';
        exit;
    }
    if ($page === 'client/clients-delete' || $page === 'clients-delete') {
        require_once __DIR__ . '/../src/controllers/client/clients_delete.php';
        exit;
    }
    if ($page === 'client/clients-restore' || $page === 'clients-restore') {
        require_once __DIR__ . '/../src/controllers/client/clients_restore.php';
        exit;
    }
    if ($page === 'client/clients-purge' || $page === 'clients-purge') {
        require_once __DIR__ . '/../src/controllers/client/clients_purge.php';
        exit;
    }
    if ($page === 'contract/contracts-create' || $page === 'contracts-create') {
        require_once __DIR__ . '/../src/controllers/contract/contracts_create.php';
        exit;
    }
    if ($page === 'long-term-contracts-create' || $page === 'contract/long-term-contracts-create') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contracts_create.php';
        exit;
    }
    if ($page === 'contract/contracts-update' || $page === 'contracts-update') {
        require_once __DIR__ . '/../src/controllers/contract/contracts_update.php';
        exit;
    }
    if ($page === 'invoice/invoices-create' || $page === 'invoices-create') {
        require_once __DIR__ . '/../src/controllers/invoice/invoices_create.php';
        exit;
    }
    if ($page === 'invoice/invoices-update' || $page === 'invoices-update') {
        require_once __DIR__ . '/../src/controllers/invoice/invoices_update.php';
        exit;
    }
    if ($page === 'quote/quote-reject' || $page === 'quote-reject') {
        require_once __DIR__ . '/../src/controllers/quote/quote_reject.php';
        exit;
    }
    if ($page === 'quote/email-send' || $page === 'contract/email-send' || $page === 'invoice/email-send' || $page === 'email-send') {
        require_once __DIR__ . '/../src/controllers/email_send.php';
        exit;
    }
    if ($page === 'email-test') {
        require_once __DIR__ . '/../src/controllers/email_test.php';
        exit;
    }
    if ($page === 'project-notes-update') {
        require_once __DIR__ . '/../src/controllers/project_notes_update.php';
        exit;
    }
    if ($page === 'account-update') {
        require_once __DIR__ . '/../src/controllers/auth/account_update.php';
        exit;
    }
    if ($page === 'account-notification-prefs') {
        require_once __DIR__ . '/../src/controllers/auth/account_notification_prefs.php';
        exit;
    }
    if ($page === 'account-revoke-device') {
        require_once __DIR__ . '/../src/controllers/account_revoke_device.php';
        exit;
    }
    if ($page === 'account/delete') {
        require_once __DIR__ . '/../src/controllers/account/account_delete.php';
        exit;
    }
    if ($page === 'financial/audit-export') {
        require_once __DIR__ . '/../src/controllers/financial/audit_export.php';
        exit;
    }
    if ($page === 'financial/audit-schedule-handler') {
        require_once __DIR__ . '/../src/controllers/financial/audit_schedule_handler.php';
        exit;
    }
    if ($page === 'organization/org-create') {
        require_once __DIR__ . '/../src/controllers/organization/org_create.php';
        exit;
    }
    if ($page === 'organization/organizations-create') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_create.php';
        exit;
    }
    if ($page === 'organization/organizations-update') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_update.php';
        exit;
    }
    if ($page === 'organization/organizations-delete') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_delete.php';
        exit;
    }
    if ($page === 'organization/organization-add-client') {
        require_once __DIR__ . '/../src/controllers/organization/organization_add_client.php';
        exit;
    }
    if ($page === 'organization/organization-update-notes' || $page === 'organization-update-notes') {
        require_once __DIR__ . '/../src/controllers/organization/organization-update-notes.php';
        exit;
    }
    if ($page === 'organization/organization-remove-client') {
        require_once __DIR__ . '/../src/controllers/organization/organization_remove_client.php';
        exit;
    }
    if ($page === 'organization/organization-departments') {
        require_once __DIR__ . '/../src/controllers/organization/organization_departments.php';
        exit;
    }
    if ($page === 'organization/organizations_upload' || $page === 'organization/organizations-upload') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_upload.php';
        exit;
    }
    if ($page === 'settings/item-library-handler') {
        require_once __DIR__ . '/../src/controllers/settings/item_library_handler.php';
        exit;
    }
    if ($page === 'settings/document-customization-save') {
        require_once __DIR__ . '/../src/controllers/settings/document-customization-save.php';
        exit;
    }
    if ($page === 'settings/document-custom-fields-handler') {
        require_once __DIR__ . '/../src/controllers/settings/document-custom-fields-handler.php';
        exit;
    }
    if ($page === 'settings/link-test-connection') {
        require_once __DIR__ . '/../src/controllers/settings/link_test_connection.php';
        exit;
    }
    if ($page === 'settings/managed-delivery-test') {
        require_once __DIR__ . '/../src/controllers/settings/managed_delivery_test.php';
        exit;
    }
    if ($page === 'settings/managed-delivery-send') {
        require_once __DIR__ . '/../src/controllers/settings/managed_delivery_send.php';
        exit;
    }
    if ($page === 'settings/managed-delivery-revoke') {
        require_once __DIR__ . '/../src/controllers/settings/managed_delivery_revoke.php';
        exit;
    }
    if ($page === 'settings/managed-delivery-retry') {
        require_once __DIR__ . '/../src/controllers/settings/managed_delivery_retry.php';
        exit;
    }
    if ($page === 'settings/link-resolver-run') {
        require_once __DIR__ . '/../src/controllers/settings/link_resolver_run.php';
        exit;
    }
    if ($page === 'links/link-management') {
        require_once __DIR__ . '/../src/controllers/links/link_management.php';
        exit;
    }
    if ($page === 'links/manual-link-handler') {
        require_once __DIR__ . '/../src/controllers/links/manual_link_handler.php';
        exit;
    }
    if ($page === 'settings/dropbox-oauth') {
        require_once __DIR__ . '/../src/controllers/settings/dropbox_oauth.php';
        exit;
    }
    if ($page === '2fa-setup-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_setup.php';
        exit;
    }
    if ($page === '2fa-verify-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_verify.php';
        exit;
    }
    if ($page === '2fa-warning-dismiss') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_warning_dismiss.php';
        exit;
    }
    if ($page === 'receipts-handler') {
        require_once __DIR__ . '/../src/controllers/receipts_handler.php';
        exit;
    }
    if ($page === 'forms-handler') {
        require_once __DIR__ . '/../src/controllers/forms_handler.php';
        exit;
    }
    if ($page === 'financial/mileage-handler') {
        require_once __DIR__ . '/../src/controllers/financial/mileage_handler.php';
        exit;
    }
    if ($page === 'financial/mileage-profile-handler') {
        require_once __DIR__ . '/../src/controllers/financial/mileage_profile_handler.php';
        exit;
    }
    if ($page === 'financial/vendor-handler') {
        require_once __DIR__ . '/../src/controllers/financial/vendor_handler.php';
        exit;
    }
    if ($page === 'financial/category-handler') {
        require_once __DIR__ . '/../src/controllers/financial/category_handler.php';
        exit;
    }
    if ($page === 'financial/asset-handler') {
        require_once __DIR__ . '/../src/controllers/financial/asset_handler.php';
        exit;
    }
    if ($page === 'financial/expense-handler' || $page === 'financial/expense_handler') {
        require_once __DIR__ . '/../src/controllers/financial/expense_handler.php';
        exit;
    }
    if ($page === 'financial/recurring-expense-handler') {
        require_once __DIR__ . '/../src/controllers/financial/recurring_expense_handler.php';
        exit;
    }
    if ($page === 'financial/csv-import') {
        require_once __DIR__ . '/../src/controllers/financial/csv_import.php';
        exit;
    }
    if ($page === 'public-link-create') {
        require_once __DIR__ . '/../src/controllers/public_link_create.php';
        exit;
    }
    if ($page === 'public-link-revoke') {
        require_once __DIR__ . '/../src/controllers/public_link_revoke.php';
        exit;
    }
    if ($page === 'stripe-webhook') {
        // Route to new future-proof webhook handler
        require_once __DIR__ . '/../src/controllers/webhook/stripe_webhooks.php';
        exit;
    }
    // Legacy webhook endpoint (kept for backward compatibility)
    if ($page === 'stripe-webhook-legacy') {
        require_once __DIR__ . '/../src/controllers/webhook/stripe_webhooks.php';
        exit;
    }
}

if ($page === 'settings/backup-download') {
    require_once __DIR__ . '/../src/controllers/settings/backup_download.php';
    exit;
}

// Stripe charge endpoint (supports both GET and POST)
if ($page === 'stripe-charge') {
    require_once __DIR__ . '/../src/controllers/stripe/stripe_charge.php';
    exit;
}

if ($page === 'session-status') {
    require_once __DIR__ . '/../src/controllers/auth/session_status.php';
    exit;
}

// Standalone login and reset pages use a minimal top header
if ($page === 'login') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/login.php';
    exit;
}
if ($page === 'reset-password') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/reset-password.php';
    exit;
}
if ($page === 'reset-verify' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/reset-verify.php';
    exit;
}
if ($page === 'reset-new') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/reset-new.php';
    exit;
}
if ($page === 'logout-confirm') {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/logout.php';
    exit;
}
if ($page === 'public-doc') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_view/public_doc.php';
    exit;
}
if ($page === 'public-doc-pdf') {
    require_once __DIR__ . '/../src/controllers/public_view/public_doc_pdf.php';
    exit;
}
if ($page === 'payment-receipt') {
    require_once __DIR__ . '/../src/controllers/public_view/payment_receipt.php';
    exit;
}
if ($page === 'client-onboarding') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_view/client_onboarding.php';
    exit;
}
if ($page === 'public-redirect') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_view/public_redirect.php';
    exit;
}
if ($page === 'public-project') {
    require_once __DIR__ . '/../src/controllers/public_view/public_project.php';
    exit;
}
if ($page === 'public-project-file') {
    require_once __DIR__ . '/../src/controllers/public_view/public_project_file.php';
    exit;
}
if ($page === 'legal/tos-accept') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/../src/controllers/legal/tos_accept.php';
    } else {
        require_once __DIR__ . '/../src/views/partials/header.php';
        require_once __DIR__ . '/../src/views/pages/legal/tos-accept.php';
        require_once __DIR__ . '/../src/views/partials/footer.php';
    }
    exit;
}
if (str_starts_with($page, 'legal/')) {
    $legalView = resolve_view_path($page);
    if (is_file($legalView)) {
        require_once $legalView;
        exit;
    }
}
if ($page === 'stripe-checkout') {
    require_once __DIR__ . '/../src/controllers/stripe/stripe_checkout.php';
    exit;
}
if ($page === 'stripe-success') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/stripe/stripe_success.php';
    exit;
}
if ($page === '2fa-verify') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/two_factor_verify.php';
    exit;
}
if ($page === '2fa-setup') {
    require_once __DIR__ . '/../src/views/partials/header.php';
    require_once __DIR__ . '/../src/views/pages/auth/two_factor_setup.php';
    require_once __DIR__ . '/../src/views/partials/footer.php';
    exit;
}

// Check if this is an AJAX request
// OUTDATED $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

//Load header
require_once __DIR__ . '/../src/views/partials/header.php';

// The database session handler serializes requests for one authenticated
// browser session. Common auth/activity mutations are complete at this point,
// so ordinary read-only views should not retain that lock while running their
// page queries and rendering HTML. One-time flash consumers deliberately keep
// the session open via SessionPolicy::canReleaseBeforeViewRendering().
if (App\Security\SessionPolicy::canReleaseBeforeViewRendering(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $page,
    $_SESSION
) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
    define('PA_SESSION_READ_ONLY', true);
}

//Load page content
$view = resolve_view_path($page);
require $view;

//Load footer
require_once __DIR__ . '/../src/views/partials/footer.php';
