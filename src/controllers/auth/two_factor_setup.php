<?php
// src/controllers/auth/two_factor_setup.php
// Handles 2FA setup: enable, disable, verify setup

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/two_factor_auth.php';
require_once __DIR__ . '/../../utils/two_factor_policy.php';
require_once __DIR__ . '/../../utils/two_factor_enrollment.php';

use App\Utils\TwoFactorAuth;

// Require authenticated user
if (!isset($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

$userId = (int)$_SESSION['user']['id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Two-factor setup actions require POST.');
}
$action = $_POST['action'] ?? '';

// Every setup mutation is protected by the form-specific CSRF token.
require_once __DIR__ . '/../../utils/csrf_sf.php';
$submitted = $_POST['_token'] ?? '';
if (!csrf_sf_is_valid('2fa_setup', is_string($submitted) ? $submitted : '')) {
    header('Location: /?page=2fa-setup&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

try {
    // Get current 2FA status
    $st = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ?');
    $st->execute([$userId]);
    $twofa = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($action === 'enable_init') {
        if (!totp_enrollment_can_start($twofa ?: null)) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Disable the current authenticator with your password before enrolling a new one.'));
            exit;
        }
        // Generate the secret only. Recovery codes were retired in schema 46;
        // users can enroll another passkey or use audited administrator recovery.
        $secret = TwoFactorAuth::generateSecret();
        
        // Store in database (not enabled yet, waiting for verification)
        if ($twofa) {
            $st = $pdo->prepare('UPDATE user_2fa SET secret = ?, enabled = 0, enabled_at = NULL WHERE user_id = ? AND enabled = 0');
            $st->execute([$secret, $userId]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('An enabled authenticator cannot be replaced by setup.');
            }
        } else {
            $st = $pdo->prepare('INSERT INTO user_2fa (user_id, secret, enabled) VALUES (?, ?, 0)');
            $st->execute([$userId, $secret]);
        }
        
        $_SESSION['totp_pending_enrollment'] = ['user_id' => $userId, 'expires_at' => time() + 600];
        header('Location: /?page=2fa-setup&step=verify');
        exit;
    }
    
    if ($action === 'enable_verify') {
        $pending = $_SESSION['totp_pending_enrollment'] ?? null;
        if (!totp_enrollment_is_pending($pending, $userId, $twofa ?: null, time())) {
            unset($_SESSION['totp_pending_enrollment']);
            header('Location: /?page=2fa-setup&error=' . urlencode('Authenticator setup expired. Start again.'));
            exit;
        }
        // Verify the code before enabling
        $code = trim($_POST['code'] ?? '');
        
        $pdo->beginTransaction();
        // Match recovery's lock order: user first, then the TOTP row. This
        // prevents an in-flight setup from clearing a newer recovery flag.
        $userLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $userLock->execute([$userId]);
        $totpLock = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ? FOR UPDATE');
        $totpLock->execute([$userId]);
        $lockedTwofa = $totpLock->fetch(PDO::FETCH_ASSOC);

        if (!$lockedTwofa || !empty($lockedTwofa['enabled'])) {
            $pdo->rollBack();
            header('Location: /?page=2fa-setup&error=' . urlencode('2FA setup not initialized'));
            exit;
        }
        
        if (TwoFactorAuth::verifyCode($code, $lockedTwofa['secret'])) {
            $st = $pdo->prepare('UPDATE user_2fa SET enabled = 1, enabled_at = NOW() WHERE user_id = ?');
            $st->execute([$userId]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('TOTP enrollment changed during verification.');
            }
            $pdo->prepare('UPDATE users SET totp_reenroll_required = 0 WHERE id = ?')->execute([$userId]);
            $pdo->commit();
            unset($_SESSION['totp_pending_enrollment']);
            App\Security\SessionPolicy::rotateAuthenticatedId();
            $_SESSION['authn']['reauthenticated_at'] = time();

            app_log('2fa', '2FA enabled', ['user_id' => $userId]);
            
            header('Location: /?page=2fa-setup&success=enabled');
            exit;
        } else {
            $pdo->rollBack();
            header('Location: /?page=2fa-setup&step=verify&error=' . urlencode('Invalid code. Please try again.'));
            exit;
        }
    }
    
    if ($action === 'disable') {
        // Verify password before disabling
        $password = $_POST['password'] ?? '';
        
        if (empty($password)) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Password required to disable 2FA'));
            exit;
        }
        
        // Verify user's password
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Invalid password'));
            exit;
        }
        
        // Disable 2FA
        if ($twofa) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$userId]);
            $version = $pdo->prepare('SELECT auth_version FROM users WHERE id=?');
            $version->execute([$userId]);
            $_SESSION['user']['auth_version'] = (int)$version->fetchColumn();
            $pdo->commit();
            App\Security\SessionRevocation::revokeUserSessions($pdo, $userId, session_id());
            App\Security\SessionPolicy::rotateAuthenticatedId();
            app_log('2fa', '2FA disabled', ['user_id' => $userId]);
        }
        unset($_SESSION['totp_pending_enrollment']);
        
        header('Location: /?page=2fa-setup&success=disabled');
        exit;
    }
    
    // Revoke a trusted device
    if ($action === 'revoke_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        
        try {
            // Only allow revoking own devices
            $st = $pdo->prepare('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?');
            $st->execute([$deviceId, $userId]);
            
            app_log('2fa', 'Trusted device revoked', ['user_id' => $userId, 'device_id' => $deviceId]);
            header('Location: /?page=2fa-setup&success=device_revoked');
            exit;
        } catch (Throwable $e) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Failed to revoke device'));
            exit;
        }
    }
    
    // Add trusted IP (admin only)
    if ($action === 'add_trusted_ip') {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            header('Location: /?page=2fa-setup&error=' . urlencode('Admin access required'));
            exit;
        }
        
        $ipAddress = trim($_POST['ip_address'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($ipAddress) || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Invalid IP address'));
            exit;
        }
        
        try {
            $st = $pdo->prepare('INSERT INTO trusted_ips (ip_address, description, created_by) VALUES (?, ?, ?)');
            $st->execute([$ipAddress, $description, $userId]);
            
            app_log('2fa', 'Trusted IP added', ['user_id' => $userId, 'ip' => $ipAddress]);
            header('Location: /?page=2fa-setup&success=ip_added');
            exit;
        } catch (Throwable $e) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Failed to add trusted IP'));
            exit;
        }
    }
    
    // Remove trusted IP (admin only)
    if ($action === 'remove_trusted_ip') {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            header('Location: /?page=2fa-setup&error=' . urlencode('Admin access required'));
            exit;
        }
        
        $ipId = (int)($_POST['ip_id'] ?? 0);
        
        try {
            $st = $pdo->prepare('DELETE FROM trusted_ips WHERE id = ?');
            $st->execute([$ipId]);
            
            app_log('2fa', 'Trusted IP removed', ['user_id' => $userId, 'ip_id' => $ipId]);
            header('Location: /?page=2fa-setup&success=ip_removed');
            exit;
        } catch (Throwable $e) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Failed to remove trusted IP'));
            exit;
        }
    }
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_log('2fa', 'Setup error', ['error' => $e->getMessage(), 'user_id' => $userId]);
    header('Location: /?page=2fa-setup&error=' . urlencode('An error occurred'));
    exit;
}

header('Location: /?page=2fa-setup');
exit;
