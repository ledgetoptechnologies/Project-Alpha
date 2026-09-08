<?php
// src/views/pages/client/client-details.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/external_ops.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<p>Client not found.</p>';
    return;
}

require_record_ownership($pdo, 'clients', $id);
$stmt = $pdo->prepare('
    SELECT c.*, o.name AS organization_name
    FROM clients c
    LEFT JOIN organizations o ON o.id = c.organization_id
    WHERE c.id = ?
    LIMIT 1
');
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    echo '<p>Client not found.</p>';
    return;
}

$portalLogin = null;
$portalLoginConfigured = false;
$canManagePortalLogin = user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'users.manage', 0)
    && user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), 'settings.manage', 0);
try {
    $portalConfig = pa_external_ops_delivery_config($pdo);
    $portalState = (new \App\Services\PortalClientProvisioningService())->status($pdo, (string)$portalConfig['application_key']);
    $portalLoginConfigured = !empty($portalState['configured']);
    $portalStmt = $pdo->prepare('SELECT * FROM portal_client_login_eligibility WHERE client_id=?');
    $portalStmt->execute([$id]);
    $portalLogin = $portalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $error) {
    error_log('[client_details] Client portal status unavailable: ' . $error->getMessage());
}

$projectStmt = $pdo->prepare('
    SELECT p.id, p.name, p.status
    FROM projects p
    LEFT JOIN project_clients pc ON pc.project_id = p.id
    WHERE p.client_id = ? OR pc.client_id = ?
    GROUP BY p.id, p.name, p.status
    ORDER BY p.updated_at DESC, p.id DESC
');
$projectStmt->execute([$id, $id]);
$projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);

$documentCounts = [];
foreach (['quotes' => 'Quotes', 'contracts' => 'Contracts', 'invoices' => 'Invoices'] as $table => $label) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE client_id = ?");
    $countStmt->execute([$id]);
    $documentCounts[$label] = (int)$countStmt->fetchColumn();
}

$addressLines = array_values(array_filter([
    trim((string)($client['address_line1'] ?? '')),
    trim((string)($client['address_line2'] ?? '')),
    trim(implode(', ', array_filter([
        trim((string)($client['city'] ?? '')),
        trim((string)($client['state'] ?? '')),
        trim((string)($client['postal_code'] ?? '')),
    ]))),
], static fn(string $value): bool => $value !== ''));
?>
<style>
  .client-view { max-width: 1280px; margin: 0 auto; padding-bottom: 32px; }
  .client-view__header { display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:18px; }
  .client-view__title { margin:0;font-size:30px;line-height:1.15;overflow-wrap:anywhere; }
  .client-view__meta { margin-top:6px;color:var(--muted);font-size:13px; }
  .client-view__actions { display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end; }
  .client-view__button { padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:inherit;text-decoration:none;font-size:13px; }
  .client-view__button--primary { background:var(--nav-accent);border-color:var(--nav-accent);color:#fff; }
  .client-view__layout { display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:18px;align-items:start; }
  .client-view__sidebar,.client-view__main { display:grid;gap:18px;min-width:0; }
  .client-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,.05); }
  .client-card h3 { margin:0 0 14px;font-size:17px; }
  .client-detail-list { display:grid;gap:12px;margin:0; }
  .client-detail-list dt { color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em; }
  .client-detail-list dd { margin:4px 0 0;line-height:1.5;overflow-wrap:anywhere; }
  .client-stats { display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px; }
  .client-stat { padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc; }
  .client-stat span { display:block;color:var(--muted);font-size:12px; }
  .client-stat strong { display:block;margin-top:5px;font-size:22px; }
  .client-projects { display:grid;gap:10px; }
  .client-project { display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;min-width:0; }
  .client-project__name { min-width:0;overflow-wrap:anywhere; }
  .client-project__status { flex:0 1 auto;overflow-wrap:anywhere;text-align:right; }
  .client-empty { padding:20px;text-align:center;color:var(--muted);border:1px dashed #d1d5db;border-radius:8px;background:#f9fafb; }
  .client-links-card > div { margin-top:0 !important;padding-top:0 !important;border-top:0 !important; }
  .client-danger-zone { border-color:#fecaca;background:#fffafa; }
  .client-danger-zone__head { display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap; }
  .client-danger-zone__description { color:var(--muted);font-size:13px; }
  .client-danger-zone .client-view__button--danger { border-color:#fca5a5;color:#b91c1c; }
  @media (max-width:900px) { .client-view__layout { grid-template-columns:1fr; } .client-view__header { display:grid; } .client-view__actions { justify-content:flex-start; } }
  @media (max-width:560px) {
    .client-view__header { gap:12px; }
    .client-stats { grid-template-columns:1fr; }
    .client-project { flex-direction:column;gap:4px; }
    .client-project__status { text-align:left; }
  }
</style>

<section class="client-view">
  <?php if (!empty($_GET['updated'])): ?>
    <div style="margin:0 0 14px;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Client updated.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div role="alert" style="margin:0 0 14px;padding:10px 12px;border-radius:8px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca"><?php echo e((string)$_GET['error']); ?></div>
  <?php endif; ?>
  <div class="client-view__header">
    <div>
      <h2 class="client-view__title"><?php echo e((string)$client['name']); ?></h2>
      <div class="client-view__meta">
        <?php echo !empty($client['organization_id']) ? 'Organization contact' : 'Standalone client'; ?>
      </div>
    </div>
    <div class="client-view__actions">
      <a class="client-view__button client-view__button--primary" href="/?page=client/clients-edit&id=<?php echo $id; ?>">Edit Client</a>
      <?php if (!empty($client['organization_id'])): ?>
        <a class="client-view__button" href="/?page=organization/organization-view&id=<?php echo (int)$client['organization_id']; ?>">View Organization</a>
      <?php endif; ?>
      <a class="client-view__button" href="/?page=client/clients-list">Back to List</a>
    </div>
  </div>

  <div class="client-view__layout">
    <aside class="client-view__sidebar">
      <div class="client-card">
        <h3>Client Information</h3>
        <dl class="client-detail-list">
          <div><dt>Email</dt><dd><?php echo !empty($client['email']) ? '<a href="mailto:' . e((string)$client['email']) . '">' . e((string)$client['email']) . '</a>' : '<span style="color:var(--muted)">None</span>'; ?></dd></div>
          <div><dt>Phone</dt><dd><?php echo e(format_phone((string)($client['phone'] ?? '')) ?: 'None'); ?></dd></div>
          <div><dt>Organization</dt><dd><?php echo !empty($client['organization_name']) ? e((string)$client['organization_name']) : '<span style="color:var(--muted)">None</span>'; ?></dd></div>
          <div><dt>Address</dt><dd><?php echo $addressLines ? implode('<br>', array_map('e', $addressLines)) : '<span style="color:var(--muted)">None</span>'; ?></dd></div>
          <?php if (trim((string)($client['notes'] ?? '')) !== ''): ?>
            <div><dt>Notes</dt><dd><?php echo nl2br(e((string)$client['notes'])); ?></dd></div>
          <?php endif; ?>
        </dl>
      </div>

      <div class="client-card">
        <h3>Documents</h3>
        <div class="client-stats">
          <?php foreach ($documentCounts as $label => $count): ?>
            <div class="client-stat"><span><?php echo e($label); ?></span><strong><?php echo $count; ?></strong></div>
          <?php endforeach; ?>
        </div>
      </div>

    </aside>

    <main class="client-view__main">
      <div class="client-card" id="assigned-services">
        <?php
        $portalServiceAssignmentSubjectType = empty($client['organization_id']) ? 'standalone_client' : 'client';
        $portalServiceAssignmentSubjectId = $id;
        $portalServiceAssignmentEntityPermission = 'clients.edit';
        include __DIR__ . '/../../components/portal_service_assignments.php';
        ?>
      </div>

      <div class="client-card client-links-card">
        <?php
        $entityType = 'client';
        $entityId = $id;
        include __DIR__ . '/../../components/links_section.php';
        ?>
      </div>

      <div class="client-card">
        <h3>Projects</h3>
        <?php if ($projects): ?>
          <div class="client-projects">
            <?php foreach ($projects as $project): ?>
              <a class="client-project" href="/?page=project/projects-details&id=<?php echo (int)$project['id']; ?>">
                <span class="client-project__name"><strong><?php echo e((string)($project['name'] ?: 'Project')); ?></strong></span>
                <span class="client-project__status"><?php echo e(ucwords(str_replace('_', ' ', (string)($project['status'] ?? '')))); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="client-empty">No projects for this client yet.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <?php if($portalLoginConfigured):?>
    <?php
      $portalLoginStatus=(string)($portalLogin['eligibility_status']??'review_required');
      $portalLoginReason=(string)($portalLogin['review_reason']??'not_reconciled');
      $portalLoginActive=$portalLoginStatus==='eligible'&&(string)($portalLogin['manual_state']??'automatic')!=='revoked';
    ?>
    <section class="client-card client-danger-zone" aria-labelledby="clientPortalAccessTitle">
      <div class="client-danger-zone__head">
        <div>
          <h3 id="clientPortalAccessTitle">Portal access</h3>
          <p><strong><?=e(ucwords(str_replace('_',' ',$portalLoginStatus)))?></strong></p>
          <p class="client-danger-zone__description"><?=e($portalLoginReason==='none'?'Revoke portal login only when this person should no longer be eligible. The first verified sign-in still binds the external identity.':ucwords(str_replace('_',' ',$portalLoginReason)))?></p>
        </div>
        <?php if($canManagePortalLogin):?>
          <form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('<?=$portalLoginActive?'Revoke this person’s client portal login eligibility? Existing Project Alpha records are preserved.':'Restore automatic client portal eligibility? The contact must still pass email and identity verification.'?>')">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="set-client-portal-client"><input type="hidden" name="return_to" value="client-details"><input type="hidden" name="client_id" value="<?=$id?>"><input type="hidden" name="access_state" value="<?=$portalLoginActive?'revoked':'active'?>">
            <button class="client-view__button client-view__button--danger"><?=$portalLoginActive?'Revoke portal login':'Restore portal login'?></button>
          </form>
        <?php endif;?>
      </div>
    </section>
  <?php endif;?>
</section>
