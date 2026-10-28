<?php
// src/controllers/auth/reset_request.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/EmailService.php';
require_once __DIR__ . '/../../utils/password_reset_tokens.php';

// CSRF check (Symfony-backed)
require_once __DIR__ . '/../../utils/csrf_sf.php';
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('reset_request', $submitted)) {
  header('Location: /?page=reset-password&error=' . urlencode('Invalid request'));
  exit;
}

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  header('Location: /?page=reset-password&error=' . urlencode('Enter a valid email'));
  exit;
}

if (!password_reset_email_is_configured($appConfig)) {
  header('Location: /?page=reset-password&error=' . urlencode('Password reset email is not configured. Contact an administrator or use the Docker recovery command.'));
  exit;
}
$resetOrigin = password_reset_canonical_origin($appConfig);
if ($resetOrigin === null) {
  header('Location: /?page=reset-password&error=' . urlencode('A canonical HTTPS app host must be configured before reset emails can be sent.'));
  exit;
}

// Always respond with generic message, but only generate token if user exists
$uid = 0;
try {
  $st = $pdo->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL AND is_disabled=0');
  $st->execute([$email]);
  $uid = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) { $uid = 0; }

if ($uid > 0) {
  try {
    $recent = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= NOW() - INTERVAL 15 MINUTE');
    $recent->execute([$uid]);
    if ((int)$recent->fetchColumn() >= 3) {
      header('Location: /?page=reset-verify&email=' . urlencode($email) . '&sent=1');
      exit;
    }

    // Create table if missing (best-effort, idempotent)
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    // Ensure attempts column exists in case of older installs
    try { $pdo->exec("ALTER TABLE password_resets ADD COLUMN attempts TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ignore exists */ }
  } catch (Throwable $e) { /* ignore */ }

  try {
    // Invalidate any old tokens for this user
    $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id=? AND used=0')->execute([$uid]);

    // Generate 6-digit numeric code
    $token = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp = date('Y-m-d H:i:s', time() + 5*60);
    $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)')->execute([$uid, hash('sha256', $token), $exp]);
    // Log masked token creation for debugging (do not log full token in production)
    $masked = substr($token, 0, 2) . '****' . substr($token, -2);
    if (function_exists('app_log')) {
      app_log('auth', 'reset token created', ['user_id'=>$uid, 'token_mask'=>$masked]);
    }
    // Debug logging is intentionally masked; never write full reset codes to disk.
    try {
      $dbg = getenv('APP_DEBUG') ?: getenv('DEBUG') ?: '';
      if ($dbg) {
        $dbgDir = __DIR__ . '/../../config/logs/system';
        if (!is_dir($dbgDir)) { @mkdir($dbgDir, 0775, true); }
        $dbgFile = realpath($dbgDir) ? realpath($dbgDir) . DIRECTORY_SEPARATOR . 'reset_debug.log' : $dbgDir . DIRECTORY_SEPARATOR . 'reset_debug.log';
        $line = sprintf("[%s] create uid=%s token=%s expires=%s\n", date('c'), $uid, $masked, $exp);
        @file_put_contents($dbgFile, $line, FILE_APPEND | LOCK_EX);
      }
    } catch (Throwable $e) { /* ignore debug logging failures */ }

    // Compose email
    $brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');

    $link = $resetOrigin . '/?page=reset-verify&email=' . rawurlencode($email);

    $subject = $brand . ' password reset';
    $html = '<p>Here is your one-time reset code (valid for 5 minutes):</p>'
          . '<p style="font-size:22px;font-weight:800;letter-spacing:3px">' . htmlspecialchars($token) . '</p>'
          . '<p>Go to the code entry page below and enter the 6-digit code:</p>'
          . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';

    [$ok, $err] = EmailService::sendEmail($email, $subject, $html);
    if (!$ok) {
      @error_log('[reset_request] Password reset email failed for user_id=' . $uid . ': ' . $err);
    }
  } catch (Throwable $e) {
    // do not reveal errors to user
  }
}

header('Location: /?page=reset-verify&email=' . urlencode($email) . '&sent=1');
exit;
