<?php
// src/views/pages/api-keys.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/api_keys_schema.php';

if (empty($_SESSION['user'])) { echo '<p>Unauthorized</p>'; return; }
$isAdmin = (($_SESSION['user']['role'] ?? 'user') === 'admin');
if (!$isAdmin) { echo '<p>Only admins can manage API keys.</p>'; return; }

try {
  pa_ensure_api_keys_schema($pdo);
  $keys = $pdo->query("SELECT id, name, key_prefix, scopes, allowed_ips, created_at, last_used_at, revoked_at FROM api_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  @error_log('[API Keys Page] Failed to load API keys: ' . $e->getMessage());
  $keys = [];
  $loadError = 'API key storage needs repair. Run the latest database migrations, then reload this page.';
}
$flash = $_SESSION['flash_api_key'] ?? null; unset($_SESSION['flash_api_key']);
?>
<section>
  <!-- Beta Banner -->
  <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700;text-transform:uppercase">Beta</span>
    <span style="color:#92400e;font-size:14px">API keys management is currently in beta. Features may change or be removed.</span>
  </div>

  <h2>API Keys</h2>
  <?php if (!empty($loadError)): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($loadError); ?></div>
  <?php endif; ?>
  <?php if ($flash): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfeff;color:#164e63;border:1px solid #a5f3fc">
      New key generated. Copy it now — it will not be shown again:<br>
      <code style="word-break:break-all;display:block;margin-top:6px"><?php echo htmlspecialchars($flash); ?></code>
    </div>
  <?php endif; ?>

  <form method="post" action="/?page=api-keys-create" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:12px 0">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <label><div>Key name/label</div>
      <input type="text" name="name" required placeholder="CI pipeline, Zapier, etc." style="padding:8px 10px;border:1px solid #ddd;border-radius:8px">
    </label>
    <label><div>Allowed IPs (optional, comma or space separated)</div>
      <input type="text" name="allowed_ips" placeholder="1.2.3.4, 5.6.7.8" style="padding:8px 10px;border:1px solid #ddd;border-radius:8px;min-width:320px">
    </label>
    <button type="submit" style="padding:8px 12px;border:0;border-radius:8px;background:var(--nav-accent);color:#fff;font-weight:600">Create API Key</button>
  </form>

  <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #eee;border-radius:8px;overflow:hidden">
    <thead style="background:#f8fafc">
      <tr><th style="text-align:left;padding:8px 10px">Name</th><th style="text-align:left;padding:8px 10px">Prefix</th><th style="text-align:left;padding:8px 10px">Scopes</th><th style="text-align:left;padding:8px 10px">Allowed IPs</th><th style="text-align:left;padding:8px 10px">Last Used</th><th style="padding:8px 10px">Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($keys as $k): ?>
        <tr>
          <td style="padding:8px 10px"><?php echo htmlspecialchars($k['name']); ?></td>
          <td style="padding:8px 10px"><code><?php echo htmlspecialchars($k['key_prefix']); ?></code></td>
          <td style="padding:8px 10px"><?php echo htmlspecialchars($k['scopes'] ?: 'full'); ?></td>
          <td style="padding:8px 10px"><?php echo htmlspecialchars($k['allowed_ips'] ?: ''); ?></td>
          <td style="padding:8px 10px"><?php echo htmlspecialchars($k['last_used_at'] ?: '—'); ?></td>
          <td style="padding:8px 10px;text-align:center">
            <?php if (empty($k['revoked_at'])): ?>
            <form method="post" action="/?page=api-keys-revoke" onsubmit="return confirm('Revoke this key?');" style="display:inline">
              <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="id" value="<?php echo (int)$k['id']; ?>">
              <button type="submit" style="padding:6px 10px;border:0;border-radius:6px;background:#fee2e2;color:#991b1b">Revoke</button>
            </form>
            <?php else: ?>
              <span style="color:#991b1b">Revoked</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
