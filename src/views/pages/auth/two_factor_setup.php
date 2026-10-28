<?php
// Authenticator-app enrollment and management.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/two_factor_auth.php';
require_once __DIR__ . '/../../../utils/two_factor_policy.php';
require_once __DIR__ . '/../../../utils/two_factor_enrollment.php';

use App\Utils\TwoFactorAuth;

$userId = (int)$_SESSION['user']['id'];
$userEmail = (string)($_SESSION['user']['email'] ?? '');
$csrf = csrf_sf_token('2fa_setup');
$twofa = null;
try {
    $st = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ?');
    $st->execute([$userId]);
    $twofa = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

$isEnabled = $twofa && !empty($twofa['enabled']);
$isRecommended = two_factor_recommended_for_user($pdo, $userId);
$step = (string)($_GET['step'] ?? 'main');
$error = (string)($_GET['error'] ?? '');
$success = (string)($_GET['success'] ?? '');
$isRecoveryEnrollment = !empty($_GET['recovery']);
$qrDataUri = null;
$qrError = null;
$pending = $_SESSION['totp_pending_enrollment'] ?? null;
$hasPendingEnrollment = totp_enrollment_is_pending($pending, $userId, $twofa, time());
if ($step === 'verify' && !$hasPendingEnrollment) {
    $step = 'main';
    $error = 'Authenticator setup expired. Start again.';
}

if ($step === 'verify' && $hasPendingEnrollment) {
    try {
        $otpUri = TwoFactorAuth::getOtpAuthUri(
            (string)$twofa['secret'],
            $userEmail,
            (string)($appConfig['brand_name'] ?? 'Project Alpha')
        );
        $qrDataUri = TwoFactorAuth::getQrCodeDataUri($otpUri);
    } catch (Throwable $e) {
        $qrError = 'The QR code could not be rendered. Use the manual setup key below.';
    }
}
?>

<style>
  .settings-container { max-width:700px; margin:0 auto; padding:20px; }
  .status-badge { display:inline-block; padding:6px 12px; border-radius:6px; font-weight:600; font-size:14px; }
  .status-enabled { background:#d1fae5; color:#065f46; }
  .status-disabled { background:#fee2e2; color:#991b1b; }
  .qr-container { background:#fff; padding:20px; border-radius:12px; text-align:center; margin:20px 0; border:2px solid #e5e7eb; }
  .totp-qr { display:block; width:min(256px,100%); height:auto; margin:0 auto 16px; }
  .manual-key summary { cursor:pointer; color:#374151; font-weight:600; }
  .secret-display { background:#f9fafb; padding:16px; border-radius:8px; margin:12px 0 0; border:1px solid #e5e7eb; }
  .secret-code { font-family:monospace; font-size:18px; font-weight:600; letter-spacing:2px; padding:12px; background:#fff; border-radius:6px; word-break:break-all; }
  .alert { padding:12px 16px; border-radius:8px; margin:16px 0; }
  .alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
  .alert-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
  .alert-warning { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
  .btn { display:inline-block; padding:10px 18px; border-radius:8px; border:0; font-weight:600; cursor:pointer; text-decoration:none; }
  .btn-primary { background:var(--nav-accent,#2563eb); color:#fff; }
  .btn-danger { background:#dc2626; color:#fff; }
  .btn-secondary { background:#6b7280; color:#fff; }
  .form-group { margin-bottom:16px; }
  .form-group label { display:block; margin-bottom:6px; font-weight:600; }
  .form-group input { width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:16px; }
</style>

<section class="settings-container">
  <h1>Authenticator App</h1>
  <?php if ($isRecoveryEnrollment): ?>
    <div class="alert alert-warning">TOTP was reset by the Docker recovery command. Enroll an authenticator to finish account recovery.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <?php if ($success === 'enabled'): ?><div class="alert alert-success">Authenticator verification has been enabled.</div><?php endif; ?>
  <?php if ($success === 'disabled'): ?><div class="alert alert-success">Authenticator verification has been disabled.</div><?php endif; ?>

  <?php if ($isRecommended): ?>
    <div class="alert alert-warning">An authenticator app or passkey is strongly recommended for accounts with privileged access.</div>
  <?php endif; ?>

  <?php if ($step === 'main'): ?>
    <div style="display:flex;align-items:center;gap:12px;margin:20px 0">
      <span>Status:</span>
      <span class="status-badge <?php echo $isEnabled ? 'status-enabled' : 'status-disabled'; ?>"><?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?></span>
    </div>
    <p style="color:#6b7280;margin:20px 0">Use a six-digit code from an authenticator app after signing in with your password. A passkey is also available as a passwordless sign-in method.</p>

    <?php if (!$isEnabled): ?>
      <form method="post" action="/?page=2fa-setup-action" style="margin-top:24px">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="enable_init">
        <button type="submit" class="btn btn-primary">Set Up Authenticator App</button>
      </form>
    <?php else: ?>
      <div style="border-top:1px solid #e5e7eb;padding-top:24px;margin-top:24px">
        <h3>Disable Authenticator Verification</h3>
        <p style="color:#6b7280">Enter your password to remove this sign-in method.</p>
        <form method="post" action="/?page=2fa-setup-action" style="margin-top:16px">
          <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="disable">
          <div class="form-group"><label for="totp-disable-password">Current password</label><input id="totp-disable-password" type="password" name="password" required autocomplete="current-password"></div>
          <button type="submit" class="btn btn-danger">Disable Authenticator</button>
        </form>
      </div>
      <div class="alert alert-warning"><strong>Recovery:</strong> PA no longer uses backup codes. Add a second passkey from My Account so you have another secure way to sign in.</div>
    <?php endif; ?>
  <?php elseif ($step === 'verify'): ?>
    <h2>Step 1: Scan the QR Code</h2>
    <p style="color:#6b7280">Open your authenticator app, add an account, and scan this code. PA generates it locally and does not send it to an external QR service.</p>
    <?php if ($twofa): ?>
      <div class="qr-container">
        <?php if ($qrDataUri): ?><img class="totp-qr" src="<?php echo htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8'); ?>" alt="QR code for adding this account to an authenticator app"><?php endif; ?>
        <?php if ($qrError): ?><p class="alert alert-warning"><?php echo htmlspecialchars($qrError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <details class="manual-key">
          <summary>Can't scan? Enter a setup key</summary>
          <div class="secret-display"><p style="margin:0 0 8px;color:#6b7280;font-size:14px">Manual setup key:</p><div class="secret-code"><?php echo htmlspecialchars((string)$twofa['secret'], ENT_QUOTES, 'UTF-8'); ?></div></div>
        </details>
      </div>
    <?php endif; ?>
    <h2>Step 2: Verify the Code</h2>
    <p style="color:#6b7280">Enter the current six-digit code to finish setup.</p>
    <form method="post" action="/?page=2fa-setup-action" style="margin-top:16px">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="enable_verify">
      <div class="form-group"><label for="totp-code">Verification code</label><input id="totp-code" type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" autofocus></div>
      <button type="submit" class="btn btn-primary">Complete Setup</button>
      <a href="/?page=2fa-setup" class="btn btn-secondary" style="margin-left:8px">Cancel</a>
    </form>
  <?php endif; ?>
</section>
