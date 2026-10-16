<?php
// src/views/pages/settings/system.php
$emailConnections = [];
try {
  require_once __DIR__ . '/../../../services/EmailService.php';
  require_once __DIR__ . '/../../../services/EmailProviderManager.php';
  $emailConnections = (new EmailProviderManager($pdo, $appConfig))->connections();
} catch (Throwable $emailStatusError) {
  @error_log('[settings/system] Unable to load email providers: ' . $emailStatusError->getMessage());
}
$googleEmailSetupReady = trim((string)($appConfig['google_oauth_client_id'] ?? '')) !== ''
  && trim((string)($appConfig['google_oauth_client_secret_enc'] ?? '')) !== '';
$googleCallbackBase = trim((string)($appConfig['app_host'] ?? ''));
if ($googleCallbackBase !== '' && !preg_match('#^https?://#i', $googleCallbackBase)) {
  $googleCallbackBase = 'https://' . $googleCallbackBase;
}
if ($googleCallbackBase === '') {
  require_once __DIR__ . '/../../../utils/request_security.php';
  $googleCallbackScheme = request_is_https() ? 'https' : 'http';
  $googleCallbackBase = $googleCallbackScheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$googleEmailCallback = rtrim($googleCallbackBase, '/') . '/?page=settings/gmail-oauth&action=callback';
?>
<fieldset>
  <legend>Company financial reporting</legend>
  <label>
    <div>Expense reporting currency</div>
    <input type="text" name="financial_base_currency" maxlength="3" pattern="[A-Z]{3}|" value="<?php echo htmlspecialchars((string)($appConfig['financial_base_currency'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Not configured" autocomplete="off">
  </label>
  <p>Enter an uppercase three-letter currency code only after confirming the currency of existing expenses. This declares their reporting currency; it does not change amounts, convert currencies, or rewrite historical records. Leave blank to keep their currency unknown in company summaries. Income retains linked document currencies; payments without a currency remain unknown.</p>
</fieldset>

<fieldset>
  <legend>Domain &amp; Public Access</legend>
  
  <label style="display:block;margin-bottom:12px">
    <div style="margin-bottom:4px;font-weight:500">Application Domain <span style="color:#666;font-weight:normal">(Optional)</span></div>
    <input type="text" name="app_host" value="<?php echo htmlspecialchars($appConfig['app_host'] ?? ''); ?>" placeholder="invoices.example.com" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="font-size:0.85em;color:#666;margin-top:4px">Enter the public address people open in their browser, not the proxy's internal address. Public links, email callbacks, and passkeys use this value; a reverse-proxy path is allowed.</div>
  </label>
  
  <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:8px">
    <input type="checkbox" name="public_links_in_email" value="1" <?php echo !empty($appConfig['public_links_in_email']) ? 'checked' : ''; ?> style="margin-top:3px;width:18px;height:18px">
    <div>
      <div style="font-weight:500">Include public payment links in emails</div>
      <div style="font-size:0.85em;color:#666">When enabled, invoice emails will include a secure link for clients to view and pay online. Only works when a valid domain is configured above.</div>
    </div>
  </label>
  
  <?php if (empty($appConfig['app_host'])): ?>
  <div style="margin-top:12px;padding:12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:13px;color:#92400e">
    <strong>⚠️ Domain Required:</strong> Public links are disabled until you configure a domain. Set up a domain pointing to this server, then enter it above.
  </div>
  <?php endif; ?>
</fieldset>

<fieldset>
  <legend>Brand</legend>
  <label>
    <div>Brand Name</div>
    <input type="text" name="brand_name" value="<?php echo htmlspecialchars(($appConfig['brand_name'] ?? 'Project Alpha')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>

  <div>
    <div>Logo (PNG, JPG, WEBP)</div>
    <?php 
    $logoPreview = $appConfig['logo_path'] ?? '';
    if (empty($logoPreview)) {
      $logoPreview = '/assets/default-logo.png';
    }
    ?>
    <div style="margin:8px 0">
      <img alt="Current logo" src="<?php echo htmlspecialchars($logoPreview); ?>" style="max-width:240px;max-height:120px;object-fit:contain;border-radius:6px;background:#fff;padding:8px">
      <?php if (empty($appConfig['logo_path'])): ?>
        <div style="font-size:0.8rem;color:#6c757d;margin-top:4px;">Default logo shown. Upload a custom logo to replace it.</div>
      <?php endif; ?>
    </div>
    <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
  </div>
</fieldset>

<fieldset>
  <legend>Business Info</legend>
  <label>
    <div>Business / Organization Name</div>
    <input name="from_company" value="<?php echo htmlspecialchars($appConfig['from_company'] ?? ($appConfig['brand_name'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="font-size:0.85em;color:#666;margin-top:4px">Used as the email sender name unless an SMTP override is set.</div>
  </label>
  <label>
    <div>Contact Name</div><input name="from_name" value="<?php echo htmlspecialchars($appConfig['from_name'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <label>
    <div>Address line 1</div><input name="from_address_line1" value="<?php echo htmlspecialchars($appConfig['from_address_line1'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <label>
    <div>Apartment / Suite</div><input name="from_address_line2" value="<?php echo htmlspecialchars($appConfig['from_address_line2'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
    <label>
      <div>City</div><input name="from_city" value="<?php echo htmlspecialchars($appConfig['from_city'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>State</div><input name="from_state" value="<?php echo htmlspecialchars($appConfig['from_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Postal</div><input name="from_postal" value="<?php echo htmlspecialchars($appConfig['from_postal'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
  </div>
  <div style="margin-top:12px">
    <label>
      <div>Primary State (default for new clients)</div>
      <input name="primary_state" value="<?php echo htmlspecialchars($appConfig['primary_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="WI">
    </label>
  </div>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
    <label>
      <div>Email</div><input name="from_email" value="<?php echo htmlspecialchars($appConfig['from_email'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Phone</div><input type="tel" name="from_phone" autocomplete="tel" value="<?php echo htmlspecialchars($appConfig['from_phone'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="(123) 456-7890">
    </label>
  </div>
</fieldset>

<fieldset>
  <legend>Timezone</legend>
  <?php $tzCurrent = $appConfig['timezone'] ?? date_default_timezone_get();
  $zones = function_exists('timezone_identifiers_list') ? timezone_identifiers_list() : []; ?>
  <label>
    <div>Select Timezone</div>
    <select name="timezone" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      <?php foreach ($zones as $z): ?>
        <option value="<?php echo htmlspecialchars($z); ?>" <?php echo ($tzCurrent === $z) ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</fieldset>
<fieldset>
  <legend>Google Address &amp; Route Configuration</legend>
  <p style="margin:0 0 10px;color:var(--muted);font-size:13px">Installation administrators configure a browser key restricted by HTTP referrer and a server key restricted to the Routes API. Enable the workflow separately.</p>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
    <label><div>Places browser key</div><input type="password" name="google_maps_browser_key" value="<?php echo htmlspecialchars((string)($appConfig['google_maps_browser_key'] ?? '')); ?>" autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>Routes server key</div><input type="password" name="google_routes_api_key" placeholder="Enter to update encrypted key" autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
  </div>
</fieldset>

<?php if (!empty($_GET['email_test']) && $_GET['email_test'] === '1'): ?>
  <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Test email sent.</div>
<?php elseif (!empty($_GET['email_err'])): ?>
  <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Test email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
<?php endif; ?>
<fieldset>
  <legend>Outgoing Email</legend>
  <p style="margin:0 0 12px;color:var(--muted)">Configure SMTP, Google, or both. Exactly one provider is active; Project Alpha never silently switches providers.</p>
  <?php if (!empty($_GET['email_connected'])): ?>
    <div style="margin-bottom:12px;padding:10px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:8px">Google Gmail connected and activated.</div>
  <?php endif; ?>
  <?php if ($emailConnections): ?>
    <div style="display:grid;gap:8px;margin-bottom:16px">
      <?php foreach ($emailConnections as $connection): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px;border:1px solid #e5e7eb;border-radius:8px">
          <div>
            <strong><?php echo htmlspecialchars(strtoupper((string)$connection['provider'])); ?></strong>
            <?php echo !empty($connection['is_active']) ? '<span style="color:#047857"> · Active</span>' : ''; ?>
            <div style="font-size:12px;color:var(--muted)"><?php echo htmlspecialchars((string)($connection['sender_email'] ?: $connection['status'])); ?></div>
          </div>
          <div style="display:flex;gap:6px">
            <?php if (empty($connection['is_active']) && in_array($connection['status'], ['configured','connected'], true)): ?>
              <button type="button" class="email-provider-action" data-action="activate" data-id="<?php echo (int)$connection['id']; ?>">Make active</button>
            <?php endif; ?>
            <button type="button" class="email-provider-action" data-action="disconnect" data-id="<?php echo (int)$connection['id']; ?>">Disconnect</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:14px">
    <strong>Sign in with Google to send email</strong>
    <p style="margin:4px 0 10px;color:var(--muted);font-size:13px">Once the installation is configured, the normal experience is one click: sign in to the business Google account, approve sending email, and return to Project Alpha automatically. This affects outgoing email only—not PA login.</p>
    <?php if ($googleEmailSetupReady): ?>
      <button class="btn btn-primary" type="submit" formmethod="post" formaction="/?page=settings/gmail-oauth&amp;action=connect">Sign in with Google</button>
    <?php else: ?>
      <button class="btn" type="button" disabled title="Complete the one-time installation setup below">Sign in with Google</button>
      <span style="margin-left:8px;color:#92400e;font-size:12px">One-time installation setup required</span>
    <?php endif; ?>
    <details style="margin-top:12px" <?php echo $googleEmailSetupReady ? '' : 'open'; ?>>
      <summary style="cursor:pointer;font-weight:600">Advanced: one-time Google app setup</summary>
      <p style="font-size:12px;color:var(--muted)">Google requires every self-hosted installation to identify the app before it can request Gmail permission. An installation administrator does this once; people connecting the sender account do not manage these values.</p>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
        <label><div>Google client ID</div><input name="google_oauth_client_id" value="<?php echo htmlspecialchars((string)($appConfig['google_oauth_client_id'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <label><div>Google client secret</div><input type="password" name="google_oauth_client_secret" placeholder="Enter to update" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      </div>
      <label style="display:block;margin-top:8px"><div>Authorized return URL</div><input readonly value="<?php echo htmlspecialchars($googleEmailCallback); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff"></label>
      <p style="font-size:12px;color:var(--muted);margin-bottom:0">Register that return URL in the Google app, save Settings once, then use <strong>Sign in with Google</strong>. Project Alpha handles the return automatically.</p>
    </details>
  </div>
  <strong>SMTP configuration</strong>
  <p style="margin:4px 0 8px;color:var(--muted)">Use any compatible mail server, including a Google App Password if preferred.</p>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
    <label>
      <div>SMTP Host</div><input name="smtp_host" value="<?php echo htmlspecialchars($appConfig['smtp_host'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="smtp.gmail.com">
    </label>
    <label>
      <div>Port</div><input type="number" name="smtp_port" value="<?php echo htmlspecialchars((string)($appConfig['smtp_port'] ?? 587)); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Security</div>
      <select name="smtp_secure" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <?php $sec = strtolower((string)($appConfig['smtp_secure'] ?? 'tls')); ?>
        <option value="tls" <?php echo $sec === 'tls' ? 'selected' : ''; ?>>TLS</option>
        <option value="ssl" <?php echo $sec === 'ssl' ? 'selected' : ''; ?>>SSL</option>
        <option value="none" <?php echo $sec === 'none' ? 'selected' : ''; ?>>None</option>
      </select>
    </label>
  </div>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
    <label>
      <div>From Email (override)</div><input name="smtp_from_email" value="<?php echo htmlspecialchars($appConfig['smtp_from_email'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="leave empty to use User Info Email">
    </label>
    <label>
      <div>From Name (override)</div><input name="smtp_from_name" value="<?php echo htmlspecialchars($appConfig['smtp_from_name'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="leave empty to use Business / Organization Name">
    </label>
  </div>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
    <label>
      <div>Username (email)</div><input name="smtp_username" value="<?php echo htmlspecialchars($appConfig['smtp_username'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="you@gmail.com">
    </label>
    <label>
      <div>App Password</div><input type="password" name="smtp_password" value="" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter to update (leave blank to keep)">
    </label>
  </div>
  <p style="margin:6px 0 0;color:var(--muted);font-size:12px">For Gmail: host smtp.gmail.com, port 587 (TLS) or 465 (SSL); use an App Password (not your normal password).</p>
  <div style="margin-top:12px">
    <button type="button" id="btnEmailTest" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">Test active provider</button>
    <div id="emailTestResult" style="margin-top:8px;font-size:13px"></div>
  </div>
</fieldset>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/settings-system.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
