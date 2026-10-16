<?php
// src/views/pages/auth/login.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/password_reset_tokens.php';

$csrf = csrf_sf_token('auth');
$passkeyCsrf = csrf_sf_token('passkey_login');
$noUsers = false;
try {
    $noUsers = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) === 0;
} catch (Throwable $e) {
    $noUsers = true;
}

$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$resetAvailable = password_reset_email_is_configured($appConfig);
$brandName = (string)($appConfig['brand_name'] ?? 'Project Alpha');
$brandLogo = trim((string)($appConfig['logo_path'] ?? '')) ?: '/assets/auth-logo.png';
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
// Hidden until password-visibility.js runs, so no-JS sign-in keeps a plain field.
$passwordToggle = static fn(string $inputId): string => '<button type="button" class="login-password-toggle" data-password-toggle'
    . ' aria-controls="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" aria-pressed="false" aria-label="Show password" title="Show password" hidden>'
    . '<svg class="login-password-icon login-password-icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>'
    . '<svg class="login-password-icon login-password-icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>'
    . '</button>';
?>

<link rel="stylesheet" href="<?php echo $h(asset_url('/assets/auth-login.css')); ?>">

<main class="login-page">
  <div class="login-shell">
    <section class="login-story" aria-label="<?php echo $h($brandName); ?>">
      <div class="login-brand">
        <img src="<?php echo $h($brandLogo); ?>" alt="" loading="eager" fetchpriority="high">
        <span><?php echo $h($brandName); ?></span>
      </div>
      <div class="login-story__copy">
        <p class="login-kicker">Business operations, together</p>
        <h1>One place to run the work.</h1>
        <p>Clients, projects, billing, workforce time, and approvals in the self-hosted system your team controls.</p>
      </div>
      <div class="login-proof" aria-hidden="true"><span>Self-hosted</span><span>Role-based access</span><span>Unified time &amp; billing</span></div>
    </section>

    <section class="login-panel">
      <div class="login-form-wrap">
        <h2><?php echo $noUsers ? 'Create your admin' : 'Welcome back'; ?></h2>
        <p class="login-intro"><?php echo $noUsers ? 'Set up the first Project Alpha administrator to get started.' : 'Sign in to continue to your workspace.'; ?></p>

        <?php if ($error): ?><div class="login-alert login-alert--error" role="alert"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (!$noUsers && !empty($_GET['created'])): ?><div class="login-alert login-alert--success">Account created. Please sign in.</div><?php endif; ?>
        <?php if (!$noUsers && !empty($_GET['pwd_reset'])): ?><div class="login-alert login-alert--success">Password updated. Please sign in.</div><?php endif; ?>

        <form method="post" action="/?page=auth" class="login-form">
          <input type="hidden" name="_token" value="<?php echo $h($csrf); ?>">
          <input type="hidden" name="action" value="<?php echo $noUsers ? 'register_first' : 'login'; ?>">
          <label class="login-field">
            <span><?php echo $noUsers ? 'Administrator email' : 'Email or username'; ?></span>
            <input required class="login-input" type="<?php echo $noUsers ? 'email' : 'text'; ?>" name="email" autocomplete="<?php echo $noUsers ? 'username' : 'username webauthn'; ?>" autofocus>
          </label>
          <?php if ($noUsers): ?>
            <label class="login-field">
              <span>Administrator username</span>
              <input required class="login-input" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" type="text" name="username" autocomplete="username">
            </label>
          <?php endif; ?>
          <div class="login-field">
            <label for="login-password">Password</label>
            <div class="login-password">
              <input required class="login-input" id="login-password" minlength="8" type="password" name="password" autocomplete="<?php echo $noUsers ? 'new-password' : 'current-password'; ?>">
              <?php echo $passwordToggle('login-password'); ?>
            </div>
          </div>
          <?php if ($noUsers): ?>
            <div class="login-field">
              <label for="login-password-confirm">Confirm password</label>
              <div class="login-password">
                <input required class="login-input" id="login-password-confirm" minlength="8" type="password" name="password2" autocomplete="new-password">
                <?php echo $passwordToggle('login-password-confirm'); ?>
              </div>
            </div>
            <p class="login-help">This creates PA's first administrator. No default or Compose-managed login exists.</p>
          <?php endif; ?>
          <div class="login-actions">
            <button type="submit" class="login-submit"><?php echo $noUsers ? 'Create administrator' : 'Sign in'; ?></button>
            <?php if (!$noUsers && $resetAvailable): ?><a href="/?page=reset-password" class="login-reset">Forgot your password?</a><?php endif; ?>
          </div>
        </form>
        <script src="<?php echo $h(asset_url('/assets/js/password-visibility.js')); ?>" defer></script>
        <?php if (!$noUsers): ?>
          <div class="login-divider"><span>or</span></div>
          <button type="button" id="passkey-login-button" class="login-passkey" data-passkey-login data-csrf="<?php echo $h($passkeyCsrf); ?>" data-options-url="/?page=passkey-options" data-complete-url="/?page=passkey-complete" data-status-id="passkey-login-status">Sign in with a passkey</button>
          <p id="passkey-login-status" class="passkey-login-status" hidden aria-live="polite"></p>
          <script src="/assets/passkeys.js" defer></script>
        <?php endif; ?>
        <p class="login-footnote">Protected access to <?php echo $h($brandName); ?></p>
      </div>
    </section>
  </div>
</main>

</body>
</html>
