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
  <?php endif;?>
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
