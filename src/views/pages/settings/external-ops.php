<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../utils/external_ops.php';

$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$config = pa_external_ops_delivery_config($pdo);
$label = (string)$config['label'];
$applicationKey = (string)$config['application_key'];
$grantAccessLabel = 'Grant ' . $label . ' access';
$revokeAccessLabel = 'Revoke ' . $label . ' access';
$search = trim((string)($_GET['access_search'] ?? ''));
$pageNumber = max(1, (int)($_GET['access_page'] ?? 1));
$pageSize = 20;
$offset = ($pageNumber - 1) * $pageSize;
$detailUserId = max(0, (int)($_GET['access_user_id'] ?? 0));
$accessCount = 0;
$accessUsers = [];
$eligibleUsers = [];
$accessDetail = null;
$projectSources = [];
$status = [];
$directoryError = false;
$portalStatus = ['configured'=>false,'ready'=>false,'profile'=>null,'counts'=>['active_roots'=>0,'revoked_roots'=>0,'eligible'=>0,'review_required'=>0,'revoked'=>0,'active_workspaces'=>0,'historical_remaining'=>0,'failed_backfill'=>0,'pending'=>0,'retrying'=>0,'failed'=>0,'failed_portal'=>0,'failed_revocations'=>0,'recoveries_pending'=>0],'delivery_diagnostics'=>['oldest_pending_age_seconds'=>null,'retries'=>[],'terminal'=>[]],'scheduler'=>['reconciliation'=>['state'=>'unknown','last_run'=>null],'delivery'=>['state'=>'unknown','last_run'=>null]],'preflight'=>['ready'=>false,'operations_delivery_ready'=>false,'checks'=>[],'issues'=>[],'receiver_verification'=>'']];
$portalStatusError = false;

try {
    $portalProvisioning = new \App\Services\PortalClientProvisioningService();
    $portalStatus = $portalProvisioning->status($pdo, $applicationKey);
} catch (Throwable $error) {
    $portalStatusError = true;
    error_log('[custom_integration_settings] Failed to load connected-workspace synchronization status: ' . $error->getMessage());
}

if (!empty($config['enabled'])) {
    try {
        $where = 'e.application_key=? AND e.enabled=1 AND e.manual_enabled=1';
        $params = [$applicationKey];
        if ($search !== '') {
            $where .= ' AND (wp.display_name LIKE ? OR tm.display_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM application_entitlements e JOIN users u ON u.id=e.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE {$where}");
        $countStmt->execute($params);
        $accessCount = (int)$countStmt->fetchColumn();
        $accessStmt = $pdo->prepare("SELECT e.*,COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) display_name,u.username,u.email,u.role,u.is_disabled,u.deleted_at,wp.status worker_status,ep.employment_status FROM application_entitlements e JOIN users u ON u.id=e.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE {$where} ORDER BY display_name LIMIT {$pageSize} OFFSET {$offset}");
        $accessStmt->execute($params);
        $accessUsers = $accessStmt->fetchAll(PDO::FETCH_ASSOC);
        $eligibleStmt = $pdo->prepare("SELECT u.id,COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) name,u.email,u.is_disabled,wp.status worker_status,ep.employment_status FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE u.deleted_at IS NULL AND COALESCE(wp.status,'active')<>'terminated' AND COALESCE(ep.employment_status,'active')<>'terminated' AND NOT EXISTS (SELECT 1 FROM application_entitlements selected WHERE selected.application_key=? AND selected.user_id=u.id AND selected.enabled=1 AND selected.manual_enabled=1) ORDER BY name LIMIT 250");
        $eligibleStmt->execute([$applicationKey]);
        $eligibleUsers = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($detailUserId > 0) {
            $detailStmt = $pdo->prepare("SELECT e.*,COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) display_name,u.username,u.email,u.role,u.is_disabled,u.deleted_at,wp.status worker_status,ep.employment_status FROM application_entitlements e JOIN users u ON u.id=e.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE e.application_key=? AND e.user_id=?");
            $detailStmt->execute([$applicationKey, $detailUserId]);
            $accessDetail = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $sourceStmt = $pdo->prepare('SELECT DISTINCT p.id,p.name FROM projects p LEFT JOIN project_assignments pa ON pa.project_id=p.id AND pa.user_id=? AND (pa.ends_at IS NULL OR pa.ends_at>UTC_TIMESTAMP(6)) WHERE pa.id IS NOT NULL OR p.manager_user_id=? ORDER BY p.name');
            $sourceStmt->execute([$detailUserId, $detailUserId]);
            $projectSources = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $statusStmt = $pdo->prepare('SELECT SUM(CASE WHEN delivered_at IS NULL THEN 1 ELSE 0 END) pending,SUM(CASE WHEN delivered_at IS NULL AND attempts>0 AND last_error IS NOT NULL THEN 1 ELSE 0 END) failed,MAX(delivered_at) last_delivered_at FROM integration_outbox WHERE integration_key=?');
        $statusStmt->execute([$applicationKey]);
        $status = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) {
        $directoryError = true;
        error_log('[custom_integration_settings] Failed to load access directory: ' . $error->getMessage());
    }
}
?>
<div class="settings-alert settings-alert-warning">
  <strong>Optional custom integration.</strong>
  Project Alpha remains the source of truth. The configured application receives signed, read-only updates and reconciliation snapshots.
</div>

<div class="settings-card">
  <h3>External application connection</h3>
  <form method="post" action="/?page=settings/external-ops-handler">
    <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>">
    <input type="hidden" name="action" value="save-config">
    <div class="settings-form-grid">
      <label class="check-row" style="grid-column:1/-1"><input type="checkbox" name="enabled" value="1" <?=!empty($config['configured_enabled'])?'checked':''?>> Enable this custom integration</label>
      <label class="field"><span class="label">Display label</span><input class="input" name="label" maxlength="100" required value="<?=$h($label)?>"><small>Choose the name administrators use for the connected application.</small></label>
      <label class="field"><span class="label">Application key</span><input class="input" name="application_key" maxlength="64" minlength="2" pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,63}" required value="<?=$h($applicationKey)?>" placeholder="external_application"><small>Must match the receiver's configured application key.</small></label>
      <label class="field" style="grid-column:1/-1"><span class="label">Signed event URL</span><input class="input" type="url" name="webhook_url" value="<?=$h($config['webhook_url'])?>" placeholder="https://application.example/api/integration/events"></label>
      <label class="field"><span class="label">Service authentication ID</span><input class="input" type="password" name="access_client_id" autocomplete="new-password" placeholder="<?=!empty($config['access_client_id'])?'Configured - leave blank to keep':'Authentication ID'?>"></label>
      <label class="field"><span class="label">Service authentication secret</span><input class="input" type="password" name="access_client_secret" autocomplete="new-password" placeholder="<?=!empty($config['access_client_secret'])?'Configured - leave blank to keep':'Authentication secret'?>"></label>
      <label class="field" style="grid-column:1/-1"><span class="label">HMAC secret</span><input class="input" type="password" name="hmac_secret" minlength="32" autocomplete="new-password" placeholder="<?=!empty($config['hmac_secret'])?'Configured - leave blank to keep':'Same 32+ character secret as the receiver'?>"></label>
      <label class="field"><span class="label">Timeout seconds</span><input class="input" type="number" name="timeout_seconds" min="2" max="30" value="<?=(int)$config['timeout_seconds']?>"></label>
      <label class="field"><span class="label">Maximum attempts</span><input class="input" type="number" name="max_attempts" min="1" max="100" value="<?=(int)$config['max_attempts']?>"></label>
      <label class="check-row" style="grid-column:1/-1"><input type="checkbox" name="service_assignment_projection_enabled" value="1" <?=!empty($portalStatus['profile']['service_assignment_projection_enabled'])?'checked':''?>> Publish assigned services to the connected application</label>
      <small style="grid-column:1/-1">Default off. This publishes explicit service availability through the same signed connection. It never grants sign-in, workspace membership, file access, billing access, or notifications.</small>
      <label class="check-row" style="grid-column:1/-1"><input type="checkbox" name="contact_assignment_projection_enabled" value="1" <?=!empty($portalStatus['profile']['contact_assignment_projection_enabled'])?'checked':''?>> Publish scoped contact roles to the connected application</label>
      <small style="grid-column:1/-1">Default off. This publishes explicit department and project contact roles as informational metadata. It never creates a sign-in, membership, entitlement, file grant, billing authority, or notification recipient.</small>
    </div>
    <button class="btn btn-primary">Save integration</button>
  </form>
</div>

<?php $signingKeys=(array)($config['signing_keys']??[]);$activeSigningMode=(string)($config['signing_mode']??'hmac-sha256'); ?>
<div class="settings-card" id="outbound-signing">
  <div class="settings-section-heading"><h3>Outbound signing</h3><p>This is the signing method for the same External Operations connection above. It does not create another endpoint, application profile, or client-portal connection.</p></div>
  <div class="settings-form-grid">
    <div><span class="label">Active method</span><strong><?=$h($activeSigningMode==='ed25519'?'Ed25519':'HMAC-SHA256')?></strong></div>
    <div><span class="label">Active key ID</span><strong><?=$h((string)($config['signing_key_id']??'external_ops_hmac_v1'))?></strong></div>
    <?php if($activeSigningMode==='ed25519'):?><div style="grid-column:1/-1"><span class="label">Active public key</span><code style="display:block;overflow-wrap:anywhere;white-space:normal"><?=$h((string)($config['signing_public_key']??''))?></code></div><?php endif;?>
  </div>
  <p style="color:var(--muted);font-size:13px">Private signing keys are encrypted and never displayed or exported. Generate a staged key, register its public key with the existing receiver, then explicitly activate it after all pending deliveries have been resolved.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
    <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Generate a new staged Ed25519 key? Project Alpha will display only its public key.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="generate-ed25519-signing-key"><button class="btn">Generate staged Ed25519 key</button></form>
    <?php if($activeSigningMode==='ed25519'):?><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Switch back to the configured HMAC key? This is blocked while the current signing contract has pending delivery.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="activate-hmac-signing"><label class="check-row"><input type="checkbox" name="confirm_hmac_fallback" value="1" required> Receiver still accepts HMAC</label><button class="btn">Use HMAC-SHA256</button></form><?php endif;?>
  </div>
  <?php foreach($signingKeys as $signingKey): if(($signingKey['state']??'')!=='staged')continue; ?>
    <div class="settings-alert settings-alert-info" style="margin-top:10px"><strong>Staged Ed25519 key: <?=$h((string)$signingKey['key_id'])?></strong><br><span class="label">Public key</span><code style="display:block;overflow-wrap:anywhere;white-space:normal"><?=$h((string)$signingKey['public_key_b64'])?></code>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
        <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Activate this Ed25519 key on the existing connection? This is blocked while any signed delivery remains unresolved.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="activate-ed25519-signing-key"><input type="hidden" name="signing_key_id" value="<?=$h((string)$signingKey['key_id'])?>"><label class="check-row"><input type="checkbox" name="receiver_key_registered" value="1" required> Receiver has registered this exact public key</label><button class="btn btn-primary">Activate Ed25519</button></form>
        <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Retire this unused staged key? Its private material will be removed permanently.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="retire-ed25519-signing-key"><input type="hidden" name="signing_key_id" value="<?=$h((string)$signingKey['key_id'])?>"><button class="btn">Retire staged key</button></form>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="settings-card" id="connected-workspace-synchronization">
  <div class="settings-section-heading"><h3>Connected workspace synchronization</h3><p>Project Alpha publishes eligible client identities and organization structure as signed events through this external application connection. The connected application can use those records to provision its own client-facing workspaces. Sign-in and resource access remain controlled by that application.</p></div>
  <?php if($portalStatusError):?>
    <div class="settings-alert settings-alert-danger" role="alert">Connected workspace synchronization status could not be loaded. Apply the current database migrations, then reload this page.</div>
  <?php else:?>
    <?php $portalCounts=(array)$portalStatus['counts'];$portalDiagnostics=(array)($portalStatus['delivery_diagnostics']??[]);$portalRetries=(array)($portalDiagnostics['retries']??[]);$portalTerminal=(array)($portalDiagnostics['terminal']??[]);$portalScheduler=(array)($portalStatus['scheduler']??[]);$portalPreflight=(array)($portalStatus['preflight']??[]);$portalIssues=(array)($portalPreflight['issues']??[]);$oldestQueuedAge=isset($portalDiagnostics['oldest_pending_age_seconds'])&&$portalDiagnostics['oldest_pending_age_seconds']!==null?(int)$portalDiagnostics['oldest_pending_age_seconds']:null;$formatQueuedAge=static function(?int$seconds):string{if($seconds===null)return'None';if($seconds<60)return'Under a minute';if($seconds<3600)return floor($seconds/60).' minutes';if($seconds<86400)return floor($seconds/3600).' hours';return floor($seconds/86400).' days';};$schedulerLabel=static function(string$state):string{return match($state){'ran'=>'Observed','preflight_not_ready'=>'Preflight not ready','running'=>'Running','failed'=>'Failed',default=>'Not yet observed'};};?>
    <?php if(!empty($portalStatus['transition_message'])):?><div class="settings-alert settings-alert-warning" role="status"><?=$h($portalStatus['transition_message'])?></div><?php endif;?>
    <div class="settings-form-grid">
      <div><span class="label">External application connection</span><strong><?=!empty($portalPreflight['operations_delivery_ready'])?'Ready':'Paused'?></strong></div>
      <div><span class="label">Workspace event routing</span><strong><?=!empty($portalStatus['ready'])?'Ready':'Paused'?></strong></div>
      <div><span class="label">Active workspaces</span><strong><?=(int)($portalCounts['active_workspaces']??0)?></strong></div>
      <div><span class="label">Historical roots remaining</span><strong><?=(int)($portalCounts['historical_remaining']??0)?></strong></div>
      <div><span class="label">Historical roots requiring retry</span><strong><?=(int)($portalCounts['failed_backfill']??0)?></strong></div>
      <div><span class="label">Eligible contacts</span><strong><?=(int)($portalCounts['eligible']??0)?></strong></div>
      <div><span class="label">Needs review</span><strong><?=(int)($portalCounts['review_required']??0)?></strong></div>
      <div><span class="label">Revoked</span><strong><?=(int)($portalCounts['revoked']??0)?></strong></div>
      <div><span class="label">Total pending / retrying (subset) / terminal failed</span><strong><?=(int)($portalCounts['pending']??0)?> / <?=(int)($portalCounts['retrying']??0)?> / <?=(int)($portalCounts['failed']??0)?></strong></div>
      <div><span class="label">Replacement snapshots pending</span><strong><?=(int)($portalCounts['recoveries_pending']??0)?></strong></div>
      <div><span class="label">Oldest queued age</span><strong><?=$h($formatQueuedAge($oldestQueuedAge))?></strong></div>
      <div><span class="label">Scheduled reconciliation</span><strong><?=$h($schedulerLabel((string)($portalScheduler['reconciliation']['state']??'unknown')))?></strong><small><?=$h((string)($portalScheduler['reconciliation']['last_run']??'No run recorded'))?></small></div>
      <div><span class="label">Scheduled delivery</span><strong><?=$h($schedulerLabel((string)($portalScheduler['delivery']['state']??'unknown')))?></strong><small><?=$h((string)($portalScheduler['delivery']['last_run']??'No run recorded'))?></small></div>
    </div>
    <?php if($portalRetries):?><div class="settings-alert settings-alert-warning" role="status"><strong>Workspace-event retries:</strong><div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Safe code</th><th>HTTP status</th><th>Highest attempt</th><th>Records</th></tr></thead><tbody><?php foreach($portalRetries as$retry):?><tr><td><code><?=$h((string)($retry['code']??'delivery_retry'))?></code></td><td><?=$h(isset($retry['http_status'])&&$retry['http_status']!==null?(string)$retry['http_status']:'None')?></td><td><?=(int)($retry['attempts']??0)?></td><td><?=(int)($retry['count']??0)?></td></tr><?php endforeach;?></tbody></table></div><small>Only fixed sender codes and numeric statuses are shown; receiver bodies, addresses, delivery IDs, workspace identifiers, and credentials are never displayed.</small></div><?php endif;?>
    <?php if($portalTerminal):?><div class="settings-alert settings-alert-danger" role="alert"><strong>Terminal workspace-event failures require investigation:</strong><div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Projection</th><th>Safe code</th><th>HTTP status</th><th>Highest attempt</th><th>Records</th><th>Workspaces</th></tr></thead><tbody><?php foreach($portalTerminal as$failure):?><tr><td><?=$h((string)($failure['route']??'unknown'))?></td><td><code><?=$h((string)($failure['code']??'terminal_failure'))?></code></td><td><?=$h(isset($failure['http_status'])&&$failure['http_status']!==null?(string)$failure['http_status']:'None')?></td><td><?=(int)($failure['attempts']??0)?></td><td><?=(int)($failure['count']??0)?></td><td><?=(int)($failure['workspaces']??0)?></td></tr><?php endforeach;?></tbody></table></div><small>Repair and verify the receiver before queuing replacement snapshots. Original failed records remain preserved for audit.</small></div><?php endif;?>
    <p style="color:var(--muted);font-size:13px">Retrying is included in total pending. Scheduled reconciliation and delivery are evidence recorded by the cron process, separate from this page's web-process readiness check.</p>
    <p><strong>One outbound connection.</strong> Workspace records and ordinary integration updates use the signed event URL and credentials configured above. API-key pull reconciliation remains a receiver-driven recovery path.</p>
    <?php if($portalIssues):?>
      <div class="settings-alert settings-alert-warning" role="status"><strong>Workspace publisher still needs:</strong> <?=$h(implode(', ',$portalIssues))?>.</div>
    <?php endif;?>
    <p style="color:var(--muted);font-size:13px"><?=$h((string)($portalPreflight['receiver_verification']??''))?> Project Alpha reports only its own producer prerequisites and never displays credential values.</p>
    <?php if(!empty($portalStatus['configured'])):?>
      <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Reconcile active organizations and standalone clients now? Invalid, missing, or duplicate emails will require review and no files will be granted.')">
        <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="reconcile-client-portal">
        <button class="btn btn-primary" <?=empty($portalStatus['ready'])?'disabled aria-disabled="true" title="Complete the workspace publisher preflight first"':''?>>Synchronize next workspace batch</button>
      </form>
      <?php if((int)($portalCounts['failed_revocations']??0)>0):?>
        <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Retry failed workspace revocations against the unchanged retired receiver? The replacement connection will remain blocked until every revocation is acknowledged.')">
          <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="retry-client-portal-revocations">
          <button class="btn">Retry failed revocations (<?=(int)$portalCounts['failed_revocations']?>)</button>
        </form>
        <?php if((int)($portalCounts['failed_portal']??0)>0):?>
          <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Has the current External Operations receiver been repaired and verified? This queues fresh complete snapshots for up to 25 affected active workspaces. It does not replay or delete failed payloads.')">
            <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="recover-client-portal-deliveries">
            <button class="btn" <?=empty($portalStatus['ready'])?'disabled aria-disabled="true" title="Repair and verify the current receiver first"':''?>>Queue replacement snapshots</button>
          </form>
        <?php endif;?>
        <?php if((int)($portalCounts['failed_backfill']??0)>0):?>
          <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Retry up to 25 terminal historical roots now? Existing portal revocations and administrator access choices will be preserved.')">
            <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="retry-client-portal-backfill">
            <button class="btn">Retry failed historical roots (<?=(int)$portalCounts['failed_backfill']?>)</button>
          </form>
        <?php endif;?>
      <?php endif;?>
    <?php endif;?>
  <?php endif;?>
</div>

<?php if (!empty($config['configured_enabled']) && empty($config['delivery_ready'])): ?>
  <div class="settings-alert settings-alert-warning" role="alert"><strong>Outbound signed-event delivery is paused.</strong> Complete or replace the following settings: <?=$h(implode(', ', (array)$config['delivery_issues']))?>. Project Alpha continues recording authoritative events for later delivery, and existing API-key pull synchronization and access administration remain available.</div>
<?php endif; ?>

<?php if (empty($config['configured_enabled'])): ?>
  <div class="settings-alert settings-alert-info">This custom integration is disabled. Existing API-key pull synchronization is separate and remains available. Enable the connection after its receiver settings and contract are ready.</div>
  <div class="settings-card">
    <h3>Custom-integration access</h3>
    <p>Enable the external application connection before selecting which Project Alpha accounts may use it. Project, Operation, and Task assignments never grant application access automatically.</p>
  </div>
<?php elseif ($directoryError): ?>
  <div class="settings-alert settings-alert-danger" role="alert"><strong>Integration settings could not be loaded.</strong> Verify that all database migrations have completed, then reload this page.</div>
<?php else: ?>
  <div class="settings-card">
    <div class="settings-section-heading"><h3>Custom-integration access</h3><p>Only explicitly selected accounts can sign in to the connected application. Project Alpha administrators receive the global external role; everyone else receives an assigned-work role and sees only assigned Projects, Operations, and Tasks.</p></div>
    <form method="get" class="settings-form-grid"><input type="hidden" name="page" value="settings"><input type="hidden" name="tab" value="external-ops"><label class="field"><span class="label">Search selected accounts</span><input class="input" name="access_search" value="<?=$h($search)?>" placeholder="Name or email"></label><div><button class="btn">Search</button></div></form>
    <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Account</th><th>Selection</th><th>External role</th><th>Account state</th><th></th></tr></thead><tbody>
    <?php foreach ($accessUsers as $user): $userEffective=!empty($user['enabled'])&&!empty($user['manual_enabled'])&&empty($user['is_disabled'])&&empty($user['deleted_at'])&&!in_array((string)($user['worker_status']??''),['inactive','terminated'],true)&&!in_array((string)($user['employment_status']??''),['inactive','terminated'],true); ?>
      <tr><td><strong><?=$h($user['display_name'])?></strong><small><?=$h(strtolower((string)$user['email']))?></small></td><td><strong style="color:<?=$userEffective?'#047857':'#92400e'?>"><?=$userEffective?'Access granted':'Not granted'?></strong></td><td><?=$user['role']==='admin'?'Global external administrator':'Assigned-work operator'?></td><td><?=$userEffective?'Active':'Inactive / inconsistent'?></td><td><a class="btn btn-sm" data-external-ops-detail-link href="/?page=settings&amp;tab=external-ops&amp;access_user_id=<?=(int)$user['user_id']?>#integration-access-detail">Details</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$accessUsers): ?><tr><td colspan="5">No selected accounts match this search.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div style="display:flex;justify-content:space-between;margin-top:12px"><span><?=$accessCount?> enabled access records</span><span><?php if($pageNumber>1):?><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;access_page=<?=$pageNumber-1?>&amp;access_search=<?=rawurlencode($search)?>">Previous</a><?php endif;?> <?php if($offset+$pageSize<$accessCount):?><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;access_page=<?=$pageNumber+1?>&amp;access_search=<?=rawurlencode($search)?>">Next</a><?php endif;?></span></div>
  </div>

  <div data-external-ops-detail-region aria-live="polite">
    <?php if ($accessDetail): $effectiveAccess=!empty($accessDetail['enabled'])&&!empty($accessDetail['manual_enabled'])&&empty($accessDetail['is_disabled'])&&empty($accessDetail['deleted_at'])&&!in_array((string)($accessDetail['worker_status']??''),['inactive','terminated'],true)&&!in_array((string)($accessDetail['employment_status']??''),['inactive','terminated'],true); ?>
      <div class="settings-card" id="integration-access-detail" tabindex="-1"><h3><?=$h($accessDetail['display_name'])?> access details</h3><p><strong>Assigned Projects:</strong> <?=$projectSources?$h(implode(', ',array_column($projectSources,'name'))):'None'?></p><p><strong>External role:</strong> <?=$accessDetail['role']==='admin'?'Global external administrator':'Assigned-work operator'?></p><p><strong>Effective access:</strong> <?=$effectiveAccess?'Access granted':'Not granted'?></p><?php if($effectiveAccess):?><div style="display:flex;gap:8px;flex-wrap:wrap"><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Resend the current custom-integration access state? This does not change the grant or expand visibility.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="resend-access"><input type="hidden" name="user_id" value="<?=(int)$accessDetail['user_id']?>"><button class="btn">Resend <?=$h($grantAccessLabel)?> access</button></form><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Revoke custom-integration access? The Project Alpha account will remain active.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="revoke-access"><input type="hidden" name="user_id" value="<?=(int)$accessDetail['user_id']?>"><button class="btn"><?=$h($revokeAccessLabel)?></button></form></div><?php elseif(!empty($accessDetail['enabled'])):?><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Revoke custom-integration access? The Project Alpha account will remain active.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="revoke-access"><input type="hidden" name="user_id" value="<?=(int)$accessDetail['user_id']?>"><button class="btn"><?=$h($revokeAccessLabel)?></button></form><?php endif;?></div>
    <?php endif; ?>
  </div>

  <details class="settings-card" data-external-ops-access><summary><strong><?=$h($grantAccessLabel)?></strong></summary><p>Choose the exact Project Alpha account to provision. An inactive account is reactivated after confirmation. Work assignments determine what non-administrators can see.</p><form method="post" action="/?page=settings/external-ops-handler" data-external-ops-grant-form><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="grant-access"><input type="hidden" name="user_id" value="" data-external-ops-user-id><div class="settings-form-grid"><label class="field"><span class="label">Account</span><input class="input" type="search" list="external-ops-eligible-users" autocomplete="off" required data-external-ops-user-search placeholder="Type a name or email"><datalist id="external-ops-eligible-users"><?php foreach($eligibleUsers as $user):$eligibleLabel=$user['name'].' - '.$user['email'].(!empty($user['is_disabled'])?' - inactive account':'');?><option value="<?=$h($eligibleLabel)?>" data-user-id="<?=(int)$user['id']?>"></option><?php endforeach;?></datalist><small>Choose the exact account from the matching suggestions.</small></label></div><button class="btn btn-primary"><?=$h($grantAccessLabel)?></button></form></details>

  <script>
  (function(){
  function initExternalAccessDirectory(context){
    var root=context&&context.root?context.root:document;
    root.querySelectorAll('[data-external-ops-grant-form]').forEach(function(form){
      if(form.dataset.directoryReady==='1')return;
      form.dataset.directoryReady='1';
      var search=form.querySelector('[data-external-ops-user-search]');
      var userId=form.querySelector('[data-external-ops-user-id]');
      var list=search?root.querySelector('#'+search.getAttribute('list')):null;
      var options=list?Array.from(list.querySelectorAll('option')):[];
      function syncSelectedUser(){
        var value=search.value.trim().toLowerCase();
        var match=options.find(function(option){return option.value.trim().toLowerCase()===value;});
        userId.value=match?match.dataset.userId:'';
        search.setCustomValidity(value!==''&&!match?'Choose an account from the suggestions.':'');
        return !!match;
      }
      search.addEventListener('input',syncSelectedUser);
      search.addEventListener('change',syncSelectedUser);
      form.addEventListener('submit',function(event){
        if(!syncSelectedUser()){event.preventDefault();search.reportValidity();return;}
        if(!window.confirm('Grant custom-integration access? If this Project Alpha account is inactive, it will also be reactivated. Project and Task visibility will not be expanded.'))event.preventDefault();
      });
    });

    var detailRegion=root.querySelector('[data-external-ops-detail-region]');
    root.querySelectorAll('[data-external-ops-detail-link]').forEach(function(link){
      if(link.dataset.detailReady==='1')return;
      link.dataset.detailReady='1';
      link.addEventListener('click',async function(event){
        if(!detailRegion||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
        event.preventDefault();
        event.stopPropagation();
        detailRegion.setAttribute('aria-busy','true');
        try{
          var response=await fetch(link.href,{headers:{'Accept':'text/html','X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
          if(!response.ok)throw new Error('Unable to load access details.');
          var parsed=new DOMParser().parseFromString(await response.text(),'text/html');
          var loadedRegion=parsed.querySelector('[data-external-ops-detail-region]');
          if(!loadedRegion||!loadedRegion.querySelector('#integration-access-detail'))throw new Error('Access details were not returned.');
          detailRegion.innerHTML=loadedRegion.innerHTML;
          var detail=detailRegion.querySelector('#integration-access-detail');
          if(detail){detail.focus({preventScroll:true});detail.scrollIntoView({block:'nearest',behavior:'smooth'});}
        }catch(error){
          detailRegion.innerHTML='<div class="settings-alert settings-alert-danger" role="alert">Access details could not be loaded. Please try again.</div>';
        }finally{
          detailRegion.removeAttribute('aria-busy');
        }
      });
    });
  }
  initExternalAccessDirectory.pageInitializerId='external-operations-access-directory';
  if(window.ProjectAlpha&&typeof window.ProjectAlpha.registerPage==='function'){window.ProjectAlpha.registerPage('settings',initExternalAccessDirectory);}else{initExternalAccessDirectory();}
  })();
  </script>
<?php endif; ?>

<div class="settings-card"><h3>Synchronization status</h3><div class="settings-form-grid"><div><span class="label">Connection</span><strong><?=empty($config['configured_enabled'])?'Disabled':(!empty($config['delivery_ready'])?'Ready':'Paused')?></strong></div><div><span class="label">Pending ordinary events</span><strong><?=(int)($status['pending']??0)?></strong></div><div><span class="label">Ordinary retry errors</span><strong><?=(int)($status['failed']??0)?></strong></div><div><span class="label">Pending portal events</span><strong><?=(int)(($portalStatus['counts']['pending']??0))?></strong></div><div><span class="label">Last ordinary event delivered</span><strong><?=$h($status['last_delivered_at']??'Never')?></strong></div></div><p>A manual sync activates portal routing from this one connection, reconciles a bounded historical batch, and sends due ordinary and portal events within the request deadline. Repeat it while historical roots remain; scheduled jobs continue safely in the background.</p><form method="post" action="/?page=settings/external-ops-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="send-now"><button class="btn" <?=empty($config['delivery_ready'])?'disabled aria-disabled="true" title="Outbound delivery is paused"':''?>>Sync now</button></form></div>
