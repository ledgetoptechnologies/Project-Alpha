<?php
require_once __DIR__ . '/../../utils/check_reports.php';
require_once __DIR__ . '/../../utils/check_report_staff.php';
require_once __DIR__ . '/../../utils/check_report_public.php';
$pendingChecks = [];
try {
    // Numeric report IDs are historical audit snapshots. Restore can allocate
    // new IDs, so find the current document and owner by their stable identity.
    $checkRows = $pdo->query('SELECT r.document_type,
            CASE WHEN r.document_type="invoice" THEN i.id ELSE pi.id END AS document_id
        FROM mailed_check_reports r
        LEFT JOIN invoices i ON r.document_type="invoice" AND i.public_id=r.document_public_id
        LEFT JOIN project_invoices pi ON r.document_type="project_invoice" AND pi.public_id=r.document_public_id
        JOIN clients c ON c.id=CASE WHEN r.document_type="invoice" THEN i.client_id ELSE pi.primary_client_id END
            AND c.public_id=r.client_public_id AND c.archived=0
        WHERE r.status="pending" AND (i.id IS NOT NULL OR pi.id IS NOT NULL)
        ORDER BY r.expected_by,r.id LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($checkRows as $checkRow) {
        if (!pa_check_report_staff_access($pdo, (string)$checkRow['document_type'], (int)$checkRow['document_id'], (int)($_SESSION['user']['id'] ?? 0))) continue;
        $check = pa_check_report_lookup($pdo, (string)$checkRow['document_type'], (int)$checkRow['document_id']);
        if ($check) $pendingChecks[] = $check;
    }
} catch (Throwable $error) { /* The migration must precede enabling check reporting. */ }
?>
<?php if ($pendingChecks): ?>
<section style="margin:16px 0;padding:14px;border:1px solid #eab308;border-radius:8px;background:#fffbeb">
  <h3 style="margin:0 0 8px">Mailed checks awaiting receipt</h3><p>These reports are not payments or income. Open the invoice to confirm receipt or review a missing check. Automatic collection is held until reviewed.</p>
  <div style="display:grid;gap:8px">
  <?php foreach ($pendingChecks as $pendingCheck): $checkPath = $pendingCheck['document_type'] === 'invoice' ? 'invoice/invoice-details' : 'project/project-invoice-details'; ?>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;padding:8px;background:white;border-radius:6px;overflow-wrap:anywhere">
      <span><?= htmlspecialchars($pendingCheck['document_type'] === 'invoice' ? 'Invoice' : 'Project invoice', ENT_QUOTES, 'UTF-8') ?> #<?= (int)$pendingCheck['document_id'] ?> · Check <?= htmlspecialchars((string)$pendingCheck['check_number'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(pa_check_report_money((int)$pendingCheck['amount_cents'], (string)$pendingCheck['currency']), ENT_QUOTES, 'UTF-8') ?><br><?= !empty($pendingCheck['overdue']) ? 'Overdue review' : 'Awaiting receipt' ?> · Expected by <?= htmlspecialchars(pa_check_report_display_date((string)$pendingCheck['expected_by'], $appConfig), ENT_QUOTES, 'UTF-8') ?></span>
      <a href="/?page=<?= $checkPath ?>&amp;id=<?= (int)$pendingCheck['document_id'] ?>">Review report</a>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
