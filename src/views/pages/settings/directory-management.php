<?php
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryStatus = api_v2_directory_management_status($pdo);
$directoryPolicy = api_v2_directory_management_schema_ready($pdo)
    ? $pdo->query('SELECT * FROM api_v2_directory_management_policy WHERE singleton=1')->fetch(PDO::FETCH_ASSOC) : [];
$directoryApplications = [];
try { $directoryApplications = $pdo->query('SELECT id,name,application_id FROM api_v2_applications ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$reasonLabels = [
  'ready'=>'All safety checks pass.', 'not_configured'=>'The policy is not configured.', 'schema_unavailable'=>'The policy schema is unavailable.',
  'identity_changed'=>'The configured source, application, history epoch, or authorization state changed.', 'route_disabled'=>'One or more required default-off API routes are disabled.',
  'authorized_key_unavailable'=>'No live application-bound key has every explicit required scope.', 'attestation_stale'=>'The writer, schema, or backfill attestation is missing or stale.',
  'replacement_routes_unavailable'=>'Required lifecycle and relationship API routes are not implemented in this release.',
  'health_unavailable'=>'A health check could not be completed; local changes remain available.',
  'managed_degraded'=>'External ownership remains active, but a live safety check is degraded. Local directory writes remain blocked until explicit takeover.',
];
?>
<div class="settings-card">
  <h3>External directory policy</h3>
  <p>This policy is separate from API-key possession. It becomes effective only while the selected application, explicit scopes, default-off routes, identity state, and current release-safety evidence all pass.</p>
  <p><span class="api-key-badge"><?=$directoryStatus['effective']?'Effective read-only':'Local changes available'?></span>
    <?=$h($reasonLabels[$directoryStatus['reason']] ?? 'A required safety check is not ready.')?></p>
  <?php if($directoryStatus['configured']&&!$directoryStatus['effective']):?><div class="settings-alert settings-alert-warning" role="status">External directory management remains configured but is inactive. Local administrator changes are available to prevent lockout.</div><?php endif;?>
  <form method="post" action="/?page=settings/directory-management-handler" onsubmit="return confirm('<?=$directoryStatus['effective']?'Save this policy? To take local control, clear the configured checkbox; this explicitly ends external ownership.':'Save this external directory policy? It will only become read-only after a separate confirmed activation.'?>')" style="display:grid;gap:14px;max-width:760px">
    <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>">
    <label class="field"><span class="label">Authorized application</span><select class="input" name="application_pk"><option value="">Choose an application</option><?php foreach($directoryApplications as $application):?><option value="<?=(int)$application['id']?>" <?=((int)($directoryPolicy['application_pk']??0)===(int)$application['id'])?'selected':''?>><?=$h($application['name'])?> · <?=$h($application['application_id'])?></option><?php endforeach;?></select></label>
    <label class="check-row"><input type="checkbox" name="configured_enabled" value="1" <?=!empty($directoryPolicy['configured_enabled'])?'checked':''?>> Configure external directory management</label>
    <label class="check-row"><input type="checkbox" name="confirm_policy" value="1" required> <?=$directoryStatus['effective']?'I confirm any change, including an explicit administrator takeover if I clear the checkbox.':'I understand configuration alone does not transfer directory ownership.'?></label>
    <button class="btn btn-primary"><?=$directoryStatus['effective']?'Save policy / take local control':'Save directory policy'?></button>
  </form>
  <?php if($directoryStatus['configured']&&!$directoryStatus['effective']):?><form method="post" action="/?page=settings/directory-management-handler" onsubmit="return confirm('Activate external directory ownership? Local topology changes will be blocked until an explicit administrator takeover.')" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="activate"><label class="check-row"><input type="checkbox" name="confirm_policy" value="1" required> I confirm the authorized application has taken ownership of directory changes.</label><button class="btn">Activate ownership</button></form><?php endif;?>
</div>
<div class="settings-card"><h3>Required explicit capabilities</h3><p>Legacy full-access keys are rejected. A single live application-bound key must contain every capability below.</p><code style="white-space:pre-wrap"><?=$h(implode("\n",api_v2_directory_management_required_scopes()))?></code></div>
