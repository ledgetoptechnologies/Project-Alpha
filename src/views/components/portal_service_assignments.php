<?php

declare(strict_types=1);

require_once __DIR__ . '/../../services/PortalServiceAssignmentManager.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/escaper.php';

use App\Services\PortalServiceAssignmentManager;

$assignmentSubjectType = (string)($portalServiceAssignmentSubjectType ?? '');
$assignmentSubjectId = (int)($portalServiceAssignmentSubjectId ?? 0);
$assignmentEntityPermission = (string)($portalServiceAssignmentEntityPermission ?? '');
$assignmentActorId = (int)($_SESSION['user']['id'] ?? 0);
$assignmentCanManage = $assignmentActorId > 0
    && user_can($pdo, $assignmentActorId, 'portal_service_assignments.manage', 0)
    && $assignmentEntityPermission !== ''
    && user_can($pdo, $assignmentActorId, $assignmentEntityPermission, 0);
$assignmentRows = [];
$assignmentServices = [];
$assignmentLoadError = '';

try {
    $assignmentRows = (new PortalServiceAssignmentManager())->listForSubject(
        $pdo,
        $assignmentSubjectType,
        $assignmentSubjectId
    );
    $serviceStatement = $pdo->query(
        "SELECT id,item_name,portal_category FROM item_library
         WHERE portal_requestable=1 AND is_active=1 AND entry_type='service' AND portal_public_id IS NOT NULL
         ORDER BY COALESCE(portal_category,''),item_name,id"
    );
    $assignmentServices = $serviceStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    $assignmentLoadError = 'Assigned services are temporarily unavailable.';
    error_log('[portal-service-assignments-view] ' . $error->getMessage());
}

$assignmentPanelId = 'assigned-services-' . preg_replace('/[^a-z0-9_-]+/i', '-', $assignmentSubjectType)
    . '-' . $assignmentSubjectId;
$assignmentMessageMatches = (string)($_GET['assignment_subject_type'] ?? '') === $assignmentSubjectType
    && (int)($_GET['assignment_subject_id'] ?? 0) === $assignmentSubjectId;
$assignmentDateValue = static fn(mixed $value): string => trim((string)$value) === ''
    ? ''
    : str_replace(' ', 'T', substr((string)$value, 0, 16));
$assignmentDateLabel = static function (mixed $value): string {
    if (trim((string)$value) === '') return 'No limit';
    try {
        return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('M j, Y g:i A') . ' UTC';
    } catch (Throwable) {
        return 'Invalid date';
    }
};

if (!isset($GLOBALS['portal_service_assignment_styles_rendered'])):
    $GLOBALS['portal_service_assignment_styles_rendered'] = true;
?>
<style>
  .service-assignments{display:grid;gap:12px}.service-assignments__intro{color:var(--muted);font-size:13px;line-height:1.5}.service-assignments__list{display:grid;gap:9px}.service-assignment{border:1px solid #e1e6ec;border-radius:8px;background:#fafbfc;padding:11px}.service-assignment__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.service-assignment__meta{color:var(--muted);font-size:12px;margin-top:4px}.service-assignment__badge{display:inline-flex;padding:4px 7px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11px;font-weight:700}.service-assignment__badge--inactive{background:#f1f5f9;color:#475569}.service-assignment__actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}.service-assignment__actions form{margin:0}.service-assignment__edit{margin-top:9px}.service-assignment__edit summary{cursor:pointer;color:var(--nav-accent);font-size:13px;font-weight:700}.service-assignment__form{display:grid;grid-template-columns:minmax(180px,1.5fr) repeat(2,minmax(160px,1fr)) auto;gap:9px;align-items:end;margin-top:10px}.service-assignment__field{display:grid;gap:5px}.service-assignment__field span{font-size:12px;font-weight:650}.service-assignment__field select,.service-assignment__field input{width:100%;padding:8px 9px;border:1px solid #cfd5dc;border-radius:6px;background:#fff}.service-assignment__empty{padding:12px;border:1px dashed #cbd5e1;border-radius:8px;color:var(--muted);font-size:13px}.service-assignment__notice{padding:9px 11px;border-radius:7px;font-size:13px}.service-assignment__notice--ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.service-assignment__notice--error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}@media(max-width:800px){.service-assignment__form{grid-template-columns:1fr}.service-assignment__form .btn{width:100%}}
</style>
<?php endif; ?>

<section class="service-assignments" id="<?php echo e($assignmentPanelId); ?>" aria-labelledby="<?php echo e($assignmentPanelId); ?>-title">
  <div>
    <h3 id="<?php echo e($assignmentPanelId); ?>-title" style="margin:0 0 4px">Assigned services</h3>
    <div class="service-assignments__intro">Controls which catalog services this business may request through an authorized client portal. It does not grant portal access, membership, files, billing, or notifications.</div>
  </div>

  <?php if ($assignmentMessageMatches && !empty($_GET['assignment_saved'])): ?>
    <div class="service-assignment__notice service-assignment__notice--ok" role="status">Service assignment saved.</div>
  <?php endif; ?>
  <?php if ($assignmentMessageMatches && !empty($_GET['assignment_error'])): ?>
    <div class="service-assignment__notice service-assignment__notice--error" role="alert"><?php echo e((string)$_GET['assignment_error']); ?></div>
  <?php endif; ?>
  <?php if ($assignmentLoadError !== ''): ?>
    <div class="service-assignment__notice service-assignment__notice--error" role="alert"><?php echo e($assignmentLoadError); ?></div>
  <?php elseif ($assignmentRows): ?>
    <div class="service-assignments__list">
      <?php foreach ($assignmentRows as $assignment): ?>
        <?php
          $assignmentActive = !empty($assignment['active']);
          $assignmentServiceAvailable = !empty($assignment['service_available']);
        ?>
        <article class="service-assignment">
          <div class="service-assignment__head">
            <div>
              <strong><?php echo e((string)($assignment['service_name'] ?: 'Unavailable catalog service')); ?></strong>
              <div class="service-assignment__meta">From <?php echo e($assignmentDateLabel($assignment['effective_from'] ?? null)); ?> · Until <?php echo e($assignmentDateLabel($assignment['effective_until'] ?? null)); ?></div>
            </div>
            <span class="service-assignment__badge<?php echo $assignmentActive ? '' : ' service-assignment__badge--inactive'; ?>"><?php echo $assignmentActive ? 'Active' : 'Inactive'; ?></span>
          </div>
          <?php if ($assignmentCanManage): ?>
            <div class="service-assignment__actions">
              <form method="post" action="/?page=portal/service-assignments-handler">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="<?php echo $assignmentActive ? 'deactivate' : 'reactivate'; ?>">
                <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['id']; ?>">
                <button class="btn btn-sm" type="submit"><?php echo $assignmentActive ? 'Deactivate' : 'Reactivate'; ?></button>
              </form>
              <form method="post" action="/?page=portal/service-assignments-handler" onsubmit="return confirm('Remove this assigned service? This does not delete the catalog service.');">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['id']; ?>">
                <button class="btn btn-sm" type="submit">Remove</button>
              </form>
            </div>
            <details class="service-assignment__edit">
              <summary>Edit service or effective dates</summary>
              <form class="service-assignment__form" method="post" action="/?page=portal/service-assignments-handler">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['id']; ?>">
                <label class="service-assignment__field"><span>Service</span><select name="item_library_id" required>
                  <?php if (!$assignmentServiceAvailable): ?>
                    <option value="" selected disabled>Unavailable: <?php echo e((string)($assignment['service_name'] ?: $assignment['service_public_id'])); ?></option>
                  <?php endif; ?>
                  <?php foreach ($assignmentServices as $service): ?><option value="<?php echo (int)$service['id']; ?>" <?php echo (int)$service['id'] === (int)($assignment['item_library_id'] ?? 0) ? 'selected' : ''; ?>><?php echo e((string)$service['item_name']); ?></option><?php endforeach; ?>
                </select></label>
                <label class="service-assignment__field"><span>Effective from (UTC)</span><input type="datetime-local" name="effective_from" value="<?php echo e($assignmentDateValue($assignment['effective_from'] ?? null)); ?>"></label>
                <label class="service-assignment__field"><span>Effective until (UTC)</span><input type="datetime-local" name="effective_until" value="<?php echo e($assignmentDateValue($assignment['effective_until'] ?? null)); ?>"></label>
                <button class="btn btn-primary" type="submit">Save</button>
              </form>
            </details>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="service-assignment__empty">No services are assigned to this record.</div>
  <?php endif; ?>

  <?php if ($assignmentCanManage && $assignmentLoadError === ''): ?>
    <?php if ($assignmentServices): ?>
      <form class="service-assignment__form" method="post" action="/?page=portal/service-assignments-handler">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="subject_type" value="<?php echo e($assignmentSubjectType); ?>">
        <input type="hidden" name="subject_id" value="<?php echo $assignmentSubjectId; ?>">
        <label class="service-assignment__field"><span>Add service</span><select name="item_library_id" required><option value="">Choose a service</option>
          <?php foreach ($assignmentServices as $service): ?><option value="<?php echo (int)$service['id']; ?>"><?php echo e((string)$service['item_name']); ?><?php echo trim((string)($service['portal_category'] ?? '')) !== '' ? ' · ' . e((string)$service['portal_category']) : ''; ?></option><?php endforeach; ?>
        </select></label>
        <label class="service-assignment__field"><span>Effective from (UTC)</span><input type="datetime-local" name="effective_from"></label>
        <label class="service-assignment__field"><span>Effective until (UTC)</span><input type="datetime-local" name="effective_until"></label>
        <button class="btn btn-primary" type="submit">Assign service</button>
      </form>
    <?php else: ?>
      <div class="service-assignment__empty">Publish at least one active, requestable service in the Service Library before assigning services.</div>
    <?php endif; ?>
  <?php endif; ?>
</section>
