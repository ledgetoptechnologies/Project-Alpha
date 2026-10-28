<?php
// src/controllers/auth/auth_handler.php
// Handles login and first-admin registration with CSRF verification

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/crypto.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';
require_once __DIR__ . '/../../utils/password_reset_tokens.php';
require_once __DIR__ . '/../../utils/auth_gate_checks.php';

// Verbose error toggle: set APP_VERBOSE_ERRORS=true or AUTH_VERBOSE_ERRORS=true (or APP_DEBUG=true)
$VERBOSE_AUTH = filter_var(getenv('APP_VERBOSE_ERRORS') ?: getenv('AUTH_VERBOSE_ERRORS') ?: getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

// CSRF check (prefer Symfony token, fallback to legacy)
require_once __DIR__ . '/../../utils/csrf_sf.php';
$submitted = $_POST['_token'] ?? ($_POST['csrf'] ?? '');
if (!csrf_sf_is_valid('auth', is_string($submitted) ? $submitted : '')) {
    header('Location: /?page=login&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

$action = $_POST['action'] ?? '';
$emailOrUsername = trim((string)($_POST['email'] ?? ''));
$registrationUsername = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
require_once __DIR__ . '/../../utils/client_ip.php';
$ip = get_client_ip();

function valid_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

try {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    $count = 0;
}

// Throttle login if too many attempts from this IP in a short window
if ($action === 'login') {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND attempted_at >= NOW() - INTERVAL 10 MINUTE");
        $st->execute([$ip]);
        $attempts = (int)$st->fetchColumn();
        if ($attempts >= 15) {
            header('Location: /?page=login&error=' . urlencode('Too many attempts. Try again later.'));
            exit;
        }
    } catch (Throwable $e) { /* ignore throttle errors */ }

    // Per-account lockout: 5 failed attempts for this identifier in 15 minutes
    if ($emailOrUsername !== '') {
        try {
            $stA = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email=? AND attempted_at >= NOW() - INTERVAL 15 MINUTE");
            $stA->execute([$emailOrUsername]);
            if ((int)$stA->fetchColumn() >= 5) {
                audit_log($pdo, 'auth.account_locked', null, null, ['identifier' => $emailOrUsername, 'ip' => $ip]);
                header('Location: /?page=login&error=' . urlencode('Account temporarily locked. Try again in 15 minutes.'));
                exit;
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

if ($action === 'register_first') {
    // Only allow if there are no users yet
    if ($count > 0) {
        header('Location: /?page=login&error=' . urlencode('Setup already completed'));
        exit;
    }
    if (!valid_email($emailOrUsername)) {
        header('Location: /?page=login&error=' . urlencode('Enter a valid email'));
        exit;
    }
    if ($registrationUsername === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $registrationUsername)) {
        header('Location: /?page=login&error=' . urlencode('Choose a username using 3-50 letters, numbers, dots, dashes, or underscores'));
        exit;
    }
    $pwdErr = password_policy_error($password);
    if ($pwdErr !== null) {
        header('Location: /?page=login&error=' . urlencode($pwdErr));
        exit;
    }
    $password2 = (string)($_POST['password2'] ?? '');
    if ($password !== $password2) {
        header('Location: /?page=login&error=' . urlencode('Passwords do not match'));
        exit;
    }
    $setupLockAcquired = false;
    $setupAlreadyCompleted = false;
    $setupError = null;
    try {
        $lock = $pdo->query("SELECT GET_LOCK('project_alpha_first_admin_setup', 10)");
        $setupLockAcquired = (int)$lock->fetchColumn() === 1;
        if (!$setupLockAcquired) {
            throw new RuntimeException('Could not acquire the first-time setup lock.');
        }

        $pdo->beginTransaction();
        $existing = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($existing !== false) {
            $setupAlreadyCompleted = true;
            $pdo->rollBack();
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') {
                throw new RuntimeException('Could not hash the administrator password.');
            }
            $st = $pdo->prepare('INSERT INTO users (email, username, password_hash, role) VALUES (?,?,?,?)');
            $st->execute([$emailOrUsername, $registrationUsername, $hash, 'admin']);
            $newAdminId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'user.first_admin_created', 'user', $newAdminId, ['email' => $emailOrUsername], $newAdminId);
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $setupError = $e;
    } finally {
        if ($setupLockAcquired) {
            try { $pdo->query("SELECT RELEASE_LOCK('project_alpha_first_admin_setup')"); } catch (Throwable $ignored) {}
        }
    }

    if ($setupAlreadyCompleted) {
        header('Location: /?page=login&error=' . urlencode('Setup already completed'));
        exit;
    }
    if ($setupError instanceof Throwable) {
        try { app_log('auth', 'register_first failed', ['ex' => $setupError->getMessage()]); } catch (Throwable $_e) { /* ignore logging failure */ }
        $msg = 'Failed to create admin';
        if ($VERBOSE_AUTH) { $msg .= ': ' . $setupError->getMessage(); }
        header('Location: /?page=login&error=' . urlencode($msg));
        exit;
    }

    // Do not auto-login the new admin; require explicit sign-in.
    header('Location: /?page=login&created=1');
    exit;
}

if ($action === 'login') {
    if (empty($emailOrUsername)) {
        // record attempt
        try { $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?,?)')->execute([$ip, null]); } catch (Throwable $e) {}
        header('Location: /?page=login&error=' . urlencode('Invalid credentials'));
        exit;
    }
    try {
        $isEmail = valid_email($emailOrUsername);
        if ($isEmail) {
            $st = $pdo->prepare('SELECT id, email, password_hash, role, is_disabled, auth_version FROM users WHERE email=? AND deleted_at IS NULL');
            $st->execute([$emailOrUsername]);
        } else {
            // Accept either username or email when the input isn't a valid email
            $st = $pdo->prepare('SELECT id, email, password_hash, role, is_disabled, auth_version FROM users WHERE (username=? OR email=?) AND deleted_at IS NULL');
            $st->execute([$emailOrUsername, $emailOrUsername]);
        }
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            try { $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?,?)')->execute([$ip, $emailOrUsername]); } catch (Throwable $e) {}
            audit_log($pdo, 'auth.login_failed', 'user', $u ? (int)$u['id'] : null, ['ip' => $ip, 'input' => $emailOrUsername]);
            header('Location: /?page=login&error=' . urlencode('Invalid credentials'));
            exit;
        }
        // Check if account is disabled
        if (!empty($u['is_disabled'])) {
            app_log('auth', 'login denied - account disabled', ['ip'=>$ip, 'uid'=>(int)$u['id']]);
            header('Location: /?page=login&error=' . urlencode('Account disabled. Contact an administrator.'));
            exit;
        }
        // Check if user has 2FA enabled
        try {
            $twofa_enabled = pa_auth_two_factor_enabled($pdo, (int)$u['id']);
        } catch (Throwable $e) {
            error_log('[security] 2FA enrollment lookup failed: ' . get_class($e));
            header('Location: /?page=login&error=' . urlencode('Authentication temporarily unavailable'));
            exit;
        }
        $trustedDevice = false;
        if ($twofa_enabled) {
            $deviceToken = (string)($_COOKIE['device_trust'] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $deviceToken)) {
                try {
                    $agentHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                    $trusted = $pdo->prepare(
                        'SELECT id FROM trusted_devices
                         WHERE user_id=? AND device_token=? AND ip_address=? AND user_agent_hash=? AND expires_at>NOW()
                         LIMIT 1'
                    );
                    $trusted->execute([(int)$u['id'], $deviceToken, $ip, $agentHash]);
                    $trustedId = (int)$trusted->fetchColumn();
                    if ($trustedId > 0) {
                        $trustedDevice = true;
                        $pdo->prepare('UPDATE trusted_devices SET last_verified_at=NOW() WHERE id=?')->execute([$trustedId]);
                    }
                } catch (Throwable $e) {
                    $trustedDevice = false;
                }
            }
        }
        
        // Build the full session user data before storing or redirecting
        require_once __DIR__ . '/../../utils/acl.php';
        $userSession = [
            'id'               => (int)$u['id'],
            'email'            => $u['email'],
            'role'             => $u['role'],
            'app_role'         => $u['role'],
            'auth_version'     => (int)$u['auth_version'],
            'permissions_hash' => compute_permissions_hash($pdo, (int)$u['id'], 0),
        ];

        if ($twofa_enabled && !$trustedDevice) {
            // User has 2FA enabled, redirect to 2FA verification
            session_regenerate_id(true);
            $_SESSION['2fa_pending'] = [
                'user_id' => (int)$u['id'],
                'user_data' => $userSession
            ];
            app_log('auth', 'login requires 2FA', ['user_id'=>(int)$u['id'], 'ip'=>$ip]);
            header('Location: /?page=2fa-verify');
            exit;
        }

        // on success (no 2FA), regenerate session and optionally clear attempts
        session_regenerate_id(true);
        $_SESSION['user'] = $userSession;
        App\Security\SessionPolicy::completeAuthentication($trustedDevice ? 'password_trusted_device' : 'password');
        password_reset_revoke_for_user($pdo, (int)$u['id']);
        // Clear old login attempts on success
        try {
            $pdo->prepare('DELETE FROM login_attempts WHERE ip=? AND attempted_at < NOW() - INTERVAL 1 DAY')->execute([$ip]);
            $pdo->prepare('DELETE FROM login_attempts WHERE email=? AND attempted_at < NOW() - INTERVAL 1 DAY')->execute([$u['email']]);
        } catch (Throwable $e) {}
        audit_log($pdo, 'auth.login_success', 'user', (int)$u['id'], ['ip' => $ip]);
        // Remember-me flow is intentionally disabled. Below is the implementation
        // kept as a comment for future use.
        /*
        if (!empty($_POST['remember'])) {
            $cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https')
                || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string)$_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
                || (!empty($_SERVER['HTTP_X_SCHEME']) && strtolower((string)$_SERVER['HTTP_X_SCHEME']) === 'https');
            $uid = (int)$u['id'];
            $exp = time() + 60*60*24*30;
            $key = crypto_get_key();
            if ($key !== '') {
                $data = $uid . '|' . $exp;
                $hmac = base64_encode(hash_hmac('sha256', $data, $key, true));
                $val = $data . '|' . $hmac;
                setcookie('remember', $val, [
                    'expires' => $exp,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $cookieSecure,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            }
        }
        */
        
        // Check if force password reset is required
        $forceReset = false;
        try {
            $st = $pdo->prepare('SELECT force_password_reset FROM users WHERE id = ?');
            $st->execute([(int)$u['id']]);
            $forceReset = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
        
        if ($forceReset) {
            header('Location: /?page=account&force=1');
            exit;
        }
        
        // Check if ToS has been accepted; if not, require acceptance before proceeding
        try {
            $tosStmt = $pdo->prepare('SELECT tos_accepted_at FROM users WHERE id = ?');
            $tosStmt->execute([(int)$u['id']]);
            $tosAccepted = $tosStmt->fetchColumn();
            if ($tosAccepted === null || $tosAccepted === false) {
                header('Location: /?page=legal/tos-accept');
                exit;
            }
        } catch (Throwable $e) { /* if column missing, skip ToS gate */ }
        
        app_log('auth', 'login success', ['uid'=>(int)$u['id'], 'ip'=>$ip]);

        // Route user to appropriate landing page based on permissions
        require_once __DIR__ . '/../../utils/acl.php';
        if (user_can($pdo, (int)$u['id'], 'financial.view', 0)) {
            header('Location: /');
        } else {
            header('Location: /?page=user-dashboard');
        }
        exit;
    } catch (Throwable $e) {
        header('Location: /?page=login&error=' . urlencode('Login failed'));
        exit;
    }
}

header('Location: /?page=login');
exit;
