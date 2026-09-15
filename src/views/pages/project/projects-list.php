<?php
// src/views/pages/project/projects-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../services/ProjectReceivablesSummaryService.php';

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$clientId = trim((string)($_GET['client_id'] ?? ''));
$orgId = trim((string)($_GET['org_id'] ?? ''));

$showArchived = $status === 'archived';
$where = [$showArchived ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = 'p.name LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($status === 'archived') {
    // The archive predicate above is the complete lifecycle filter.
} elseif ($status === 'overdue') {
    $where[] = "p.status IN ('not_started','active') AND p.estimated_end IS NOT NULL AND p.estimated_end < CURRENT_DATE";
} elseif ($status !== '') {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($clientId !== '') {
    $where[] = 'p.client_id = ?';
    $params[] = $clientId;
}
if ($orgId !== '') {
    $where[] = 'p.organization_id = ?';
    $params[] = $orgId;
}

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', (int)$_SESSION['user']['id']);
if ($scopeWhere !== '') {
    $where[] = ltrim($scopeWhere, ' AND');
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT p.*, c.name AS client_name, o.name AS organization_name,
           (SELECT COUNT(*) FROM quotes q WHERE q.project_id = p.id) AS quote_count,
           (SELECT COUNT(*) FROM contracts co WHERE co.project_id = p.id) AS contract_count,
           (SELECT COUNT(*) FROM invoices i WHERE i.project_id = p.id) AS invoice_count,
           (SELECT COUNT(*) FROM project_clients pc WHERE pc.project_id = p.id) AS client_count
      FROM projects p
 LEFT JOIN clients c ON c.id = p.client_id
 LEFT JOIN organizations o ON o.id = p.organization_id
    {$whereClause}
  ORDER BY CASE WHEN p.status IN ('not_started','active') AND p.estimated_end IS NOT NULL AND p.estimated_end < CURRENT_DATE THEN 0 ELSE 1 END,
           FIELD(p.status, 'active', 'not_started', 'completed', 'cancelled'),
           p.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, $scopeParams));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$receivablesService = new App\Services\ProjectReceivablesSummaryService($pdo);
$receivablesByProject = $receivablesService->summarizeProjects(array_map('intval', array_column($rows, 'id')));
foreach ($rows as &$row) {
    $summary = $receivablesByProject[(int)$row['id']];
    $row['open_balance'] = $summary['total_minor'] / 100;
}
unset($row);

$organizations = $pdo->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$statusStyles = [
    'active' => ['label' => 'Active', 'class' => 'active'],
    'overdue' => ['label' => 'Overdue', 'class' => 'overdue'],
    'not_started' => ['label' => 'Not Started', 'class' => 'not-started'],
    'completed' => ['label' => 'Completed', 'class' => 'completed'],
    'cancelled' => ['label' => 'Cancelled', 'class' => 'cancelled'],
    'archived' => ['label' => 'Archived', 'class' => 'cancelled'],
];

$visibleActive = count(array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') === 'active'));
$hasFilters = $q !== '' || $status !== '' || $clientId !== '' || $orgId !== '';
$formatDate = static function ($value): string {
    if (empty($value)) {
        return 'Not set';
    }
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('M j, Y', $timestamp) : (string)$value;
};
?>

<style>
.projects-page{max-width:1440px;margin:0 auto;padding:24px}.projects-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:20px}.projects-header h1{font-size:28px;line-height:1.2;margin:0 0 5px}.projects-header-copy{color:var(--muted);font-size:14px}.projects-create{white-space:nowrap}.projects-filters{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:16px;margin-bottom:18px}.projects-filter-grid{display:grid;grid-template-columns:minmax(220px,1.35fr) repeat(3,minmax(170px,1fr));gap:12px;align-items:end}.projects-field{display:grid;gap:5px;position:relative}.projects-field>span{font-size:12px;font-weight:700;color:#374151}.projects-field input,.projects-field select{width:100%;height:38px;padding:8px 10px;border:1px solid #cfd5dc;border-radius:6px;background:#fff}.projects-field input:focus,.projects-field select:focus{outline:2px solid color-mix(in srgb,var(--nav-accent) 22%,transparent);border-color:var(--nav-accent)}.projects-filter-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}.projects-filter-buttons{display:flex;gap:8px}.projects-result-note{font-size:13px;color:var(--muted)}.projects-list{display:grid;gap:10px}.project-list-row{--status-color:#94a3b8;position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:17px 18px 17px 22px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.035);cursor:pointer}.project-list-row:before{content:"";position:absolute;inset:0 auto 0 0;width:5px;background:var(--status-color)}.project-list-row--active{--status-color:#16a34a}.project-list-row--overdue{--status-color:#dc2626}.project-list-row--not-started{--status-color:#d97706}.project-list-row--completed{--status-color:#2563eb}.project-list-row--cancelled{--status-color:#6b7280}.project-list-row:hover{border-color:#bdc5cf;box-shadow:0 3px 10px rgba(15,23,42,.06)}.project-row-main{min-width:0}.project-row-heading{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:5px}.project-row-heading h2{font-size:17px;line-height:1.3;margin:0;overflow-wrap:anywhere}.project-row-link{color:inherit;text-decoration:none}.project-row-link::after{content:"";position:absolute;inset:0;z-index:1}.project-row-link:focus-visible{outline:none}.project-row-link:focus-visible::after{outline:3px solid var(--nav-accent);outline-offset:-3px;border-radius:8px}.project-status{display:inline-flex;align-items:center;padding:4px 8px;border-radius:6px;font-size:12px;font-weight:750;background:#f1f5f9;color:#475569}.project-status--active{background:#dcfce7;color:#166534}.project-status--overdue{background:#fee2e2;color:#991b1b}.project-status--not-started{background:#fef3c7;color:#92400e}.project-status--completed{background:#dbeafe;color:#1e40af}.project-status--cancelled{background:#f3f4f6;color:#4b5563}.project-context{display:flex;gap:7px;flex-wrap:wrap;color:#5f6b7a;font-size:13px;margin-bottom:13px}.project-context-item:not(:last-child):after{content:"/";margin-left:7px;color:#c0c7d0}.project-row-data{display:flex;align-items:stretch;gap:0;flex-wrap:wrap}.project-data-item{min-width:92px;padding:0 15px;border-left:1px solid #e5e7eb}.project-data-item:first-child{padding-left:0;border-left:0}.project-data-label{font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700}.project-data-value{font-size:14px;font-weight:650;margin-top:2px}.project-row-actions{display:flex;align-items:center;gap:8px;align-self:center}.project-row-actions>a,.project-row-actions>form{position:relative;z-index:2}.project-row-actions form{margin:0}.project-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:8px;padding:44px 24px;text-align:center}.project-empty-title{font-weight:700;margin-bottom:5px}.project-empty-copy{font-size:14px;color:var(--muted)}.project-suggestions{display:none;position:absolute;z-index:100;left:0;right:0;top:100%;background:#fff;border:1px solid #cfd5dc;border-radius:6px;max-height:220px;overflow-y:auto;margin-top:3px;box-shadow:0 8px 20px rgba(15,23,42,.12)}@media(max-width:1080px){.projects-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.project-list-row{grid-template-columns:1fr}.project-row-actions{justify-content:flex-end;border-top:1px solid #edf0f3;padding-top:12px}}@media(max-width:680px){.site-shell{display:block!important}.main-content{width:100%!important;min-width:0!important}.projects-page{padding:16px}.projects-header{align-items:stretch}.projects-header h1{font-size:24px}.projects-create{align-self:flex-start}.projects-filter-grid{grid-template-columns:1fr}.projects-filter-actions{align-items:flex-start;flex-direction:column}.project-list-row{padding:16px 14px 16px 19px}.project-row-data{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.project-data-item,.project-data-item:first-child{padding:0;border:0}.project-row-actions{justify-content:stretch}.project-row-actions .btn,.project-row-actions form{flex:1}.project-row-actions form .btn{width:100%}}@media(max-width:440px){.projects-header{display:grid}.projects-create{width:100%;text-align:center}.projects-filter-buttons{width:100%}.projects-filter-buttons .btn{flex:1}.project-row-actions{display:grid;grid-template-columns:1fr 1fr}.project-context-item{width:100%}.project-context-item:after{display:none}}
</style>

<section class="projects-page">
  <header class="projects-header">
    <div>
      <h1>Projects</h1>
      <div class="projects-header-copy">
        <?php echo count($rows); ?> project<?php echo count($rows) === 1 ? '' : 's'; ?> shown
        <?php if (!$hasFilters && $visibleActive > 0): ?>, <?php echo $visibleActive; ?> active<?php endif; ?>
      </div>
    </div>
    <a class="btn btn-primary projects-create" href="/?page=project/projects-create">Create Project</a>
  </header>

  <form class="projects-filters" method="get" action="/">
    <input type="hidden" name="page" value="project/projects-list">
    <div class="projects-filter-grid">
      <label class="projects-field">
        <span>Project Name</span>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search projects">
      </label>

      <label class="projects-field">
        <span>Status</span>
        <select name="status">
          <option value="">All statuses</option>
          <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="not_started" <?php echo $status === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
          <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
          <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
          <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
      </label>

      <label class="projects-field">
        <span>Client</span>
        <input type="text" id="clientSearchInput" placeholder="Search clients" autocomplete="off">
        <input type="hidden" name="client_id" id="clientIdInput" value="<?php echo htmlspecialchars($clientId); ?>">
        <div id="clientSuggestions" class="project-suggestions"></div>
      </label>

      <label class="projects-field">
        <span>Organization</span>
        <input type="text" id="orgSearchInput" placeholder="Search organizations" autocomplete="off">
        <input type="hidden" name="org_id" id="orgIdInput" value="<?php echo htmlspecialchars($orgId); ?>">
        <div id="orgSuggestions" class="project-suggestions"></div>
      </label>
    </div>
    <div class="projects-filter-actions">
      <div class="projects-filter-buttons">
        <button class="btn btn-primary" type="submit">Apply Filters</button>
        <?php if ($hasFilters): ?><a class="btn" href="/?page=project/projects-list">Clear</a><?php endif; ?>
      </div>
      <div class="projects-result-note">Active projects appear first.</div>
    </div>
  </form>

  <?php if (!$rows): ?>
    <div class="project-empty">
      <div class="project-empty-title">No projects found</div>
      <div class="project-empty-copy"><?php echo $hasFilters ? 'Try changing or clearing the current filters.' : 'Create a project to start organizing related work and billing.'; ?></div>
    </div>
  <?php else: ?>
    <div class="projects-list">
      <?php foreach ($rows as $project): ?>
        <?php
          $projectStatus = !empty($project['archived_at']) ? 'archived' : (in_array((string)($project['status'] ?? ''), ['not_started','active'], true)
              && !empty($project['estimated_end']) && (string)$project['estimated_end'] < date('Y-m-d')
              ? 'overdue' : (string)($project['status'] ?? 'not_started'));
          $statusStyle = $statusStyles[$projectStatus] ?? ['label' => ucwords(str_replace('_', ' ', $projectStatus)), 'class' => 'cancelled'];
          $billingLabel = ($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'Monthly' : 'Per Invoice';
        ?>
        <article class="project-list-row project-list-row--<?php echo htmlspecialchars($statusStyle['class']); ?>">
          <div class="project-row-main">
            <div class="project-row-heading">
              <h2><a class="project-row-link" href="/?page=project/projects-details&amp;id=<?php echo (int)$project['id']; ?>"><?php echo htmlspecialchars((string)$project['name']); ?></a></h2>
              <span class="project-status project-status--<?php echo htmlspecialchars($statusStyle['class']); ?>"><?php echo htmlspecialchars($statusStyle['label']); ?></span>
            </div>

            <div class="project-context">
              <?php if (!empty($project['client_name'])): ?><span class="project-context-item"><?php echo htmlspecialchars((string)$project['client_name']); ?><?php if ((int)$project['client_count'] > 1): ?> +<?php echo (int)$project['client_count'] - 1; ?><?php endif; ?></span><?php endif; ?>
              <?php if (!empty($project['organization_name'])): ?><span class="project-context-item"><?php echo htmlspecialchars((string)$project['organization_name']); ?></span><?php endif; ?>
              <span class="project-context-item">Created <?php echo htmlspecialchars($formatDate($project['created_at'] ?? null)); ?></span>
            </div>

            <div class="project-row-data">
              <div class="project-data-item"><div class="project-data-label">Quotes</div><div class="project-data-value"><?php echo (int)$project['quote_count']; ?></div></div>
              <div class="project-data-item"><div class="project-data-label">Contracts</div><div class="project-data-value"><?php echo (int)$project['contract_count']; ?></div></div>
              <div class="project-data-item"><div class="project-data-label">Invoices</div><div class="project-data-value"><?php echo (int)$project['invoice_count']; ?></div></div>
              <div class="project-data-item"><div class="project-data-label">Open Balance</div><div class="project-data-value">$<?php echo number_format((float)$project['open_balance'], 2); ?></div></div>
              <div class="project-data-item"><div class="project-data-label">Billing</div><div class="project-data-value"><?php echo htmlspecialchars($billingLabel); ?></div></div>
              <div class="project-data-item"><div class="project-data-label">Timeline</div><div class="project-data-value"><?php echo htmlspecialchars($formatDate($project['estimated_start'] ?? null)); ?> - <?php echo htmlspecialchars($formatDate($project['estimated_end'] ?? null)); ?></div></div>
            </div>
          </div>

          <div class="project-row-actions">
            <a class="btn btn-sm" href="/?page=project/projects-details&amp;id=<?php echo (int)$project['id']; ?>">View Project</a>
            <form method="post" action="/?page=project/projects-delete" onsubmit="return confirm('<?php echo $showArchived ? 'Restore this project?' : 'Archive this project? Its documents, billing, files, and history will be retained.'; ?>');">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="id" value="<?php echo (int)$project['id']; ?>">
              <input type="hidden" name="lifecycle_action" value="<?php echo $showArchived ? 'restore' : 'archive'; ?>">
              <input type="hidden" name="redirect" value="/?page=project/projects-list">
              <button class="btn btn-sm <?php echo $showArchived ? '' : 'btn-danger'; ?>" type="submit"><?php echo $showArchived ? 'Restore' : 'Archive'; ?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<script>
  const clientData = <?php echo json_encode($clients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const orgData = <?php echo json_encode($organizations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/projects-list-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
