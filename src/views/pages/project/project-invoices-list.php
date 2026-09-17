<?php
// src/views/pages/project/project-invoices-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_invoice_billing.php';

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId > 0) {
    require_record_ownership($pdo, 'projects', $projectId);
}

$where = [];
$params = [];
if ($projectId > 0) {
    $where[] = 'pi.project_id = ?';
    $params[] = $projectId;
}
[$scopeWhere, $scopeParams] = scope_clause($pdo, 'pi', (int)($_SESSION['user']['id'] ?? 0));
if ($scopeWhere !== '') {
    $where[] = $scopeWhere;
    $params = array_merge($params, $scopeParams);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT pi.*, p.name AS project_name, c.name AS client_name,
           COUNT(pii.id) AS child_count
    FROM project_invoices pi
    JOIN projects p ON p.id = pi.project_id
    LEFT JOIN clients c ON c.id = pi.primary_client_id
    LEFT JOIN project_invoice_items pii ON pii.project_invoice_id = pi.id
    {$whereSql}
    GROUP BY pi.id
    ORDER BY pi.billing_period_end DESC, pi.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section>
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:16px">
    <div>
      <h2 style="margin:0">Project Invoices</h2>
      <?php if ($projectId > 0): ?>
        <div style="color:var(--muted);font-size:13px;margin-top:4px">Monthly aggregate invoices for this project.</div>
      <?php endif; ?>
    </div>
    <?php if ($projectId > 0): ?>
      <a class="btn btn-sm" href="/?page=project/projects-details&id=<?php echo $projectId; ?>">Back to Project</a>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div style="padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;color:var(--muted)">No project invoices found.</div>
  <?php else: ?>
    <div style="display:grid;gap:10px">
      <?php foreach ($rows as $r): ?>
        <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px">
          <div>
            <div style="font-weight:700">PI-<?php echo htmlspecialchars((string)($r['doc_number'] ?: $r['id'])); ?> - <?php echo htmlspecialchars($r['project_name']); ?></div>
            <div style="font-size:13px;color:var(--muted)">
              <?php echo htmlspecialchars(project_invoice_period_label($r)); ?>
              · <?php echo (int)$r['child_count']; ?> invoice(s)
              · <?php echo htmlspecialchars(ucfirst($r['status'])); ?>
            </div>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <div style="font-weight:700;white-space:nowrap">$<?php echo number_format((float)$r['balance_due'], 2); ?> due</div>
            <a class="btn btn-sm" href="/?page=project/project-invoice-details&id=<?php echo (int)$r['id']; ?>">View</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
