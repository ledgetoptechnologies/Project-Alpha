<?php
// src/views/pages/invoices-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../../config/app.php';
$netDays = (int)($appConfig['net_terms_days'] ?? 30);
if ($netDays < 0) $netDays = 0;

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$statusFilter = $_GET['status'] ?? 'all'; // all|paid|unpaid|overdue
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;

$where = ['(i.invoice_type IS NULL OR i.invoice_type = "regular")'];
$params = [];
if ($client_id > 0) {
  $where[] = 'i.client_id=?';
  $params[] = $client_id;
} elseif ($client_name !== '') {
  $where[] = '(c.name LIKE ? OR o.name LIKE ?)';
  $params[] = '%'.$client_name.'%';
  $params[] = '%'.$client_name.'%';
}
if ($start !== '') {
  $where[] = 'i.created_at>=?';
  $params[] = $start.' 00:00:00';
}
if ($end !== '') {
  $where[] = 'i.created_at<=?';
  $params[] = $end.' 23:59:59';
}
if ($min_price !== null) {
  $where[] = 'i.total>=?';
  $params[] = $min_price;
}
if ($max_price !== null) {
  $where[] = 'i.total<=?';
  $params[] = $max_price;
}
if ($statusFilter === 'paid') {
  $where[] = "i.status='paid'";
} elseif ($statusFilter === 'unpaid') {
  $where[] = "i.status IN ('unpaid','partial')";
} elseif ($statusFilter === 'overdue') {
  // Drafts are never receivables. Project-aggregate children age only through
  // their project statement and never enter the direct-invoice overdue view.
  $where[] = "i.status IN ('sent','unpaid','partial','overdue')
    AND i.collection_mode='direct'
    AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
} elseif ($statusFilter === 'void') {
  $where[] = "i.status='void'";
}
if ($project_code !== '') {
  $where[] = 'i.project_code LIKE ?';
  $params[] = $project_code.'%';
}
if ($doc_no > 0) {
  $where[] = 'i.doc_number=?';
  $params[] = $doc_no;
}

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'i', (int)$_SESSION['user']['id']);
if ($scopeWhere) {
  $where[] = trim($scopeWhere);
  $params = array_merge($params, $scopeParams);
}

$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$documentJoins = ' JOIN clients c ON c.id=i.client_id' . pa_document_effective_organization_joins('i', 'c');
$sqlCount = 'SELECT COUNT(*) FROM invoices i'.$documentJoins.' WHERE '.implode(' AND ', $where);
$stc = $pdo->prepare($sqlCount);
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = 'SELECT i.id,i.doc_number,i.invoice_type,i.project_code,i.total,i.status,i.collection_mode,i.created_at,i.due_date,c.name client,c.id AS client_id,o.name organization_name FROM invoices i'.$documentJoins;
if ($where) {
  $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY i.created_at DESC LIMIT $per OFFSET $offset";

$rows = $pdo->prepare($sql);
$rows->execute($params);
$rows = $rows->fetchAll();
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients = $pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <style>.invoice-void-dialog::backdrop{background:rgba(15,23,42,.48)}</style>
  <h2>Invoices</h2>
  <?php if (!empty($_GET['emailed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Email sent.</div>
  <?php elseif (!empty($_GET['email_err'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['voided'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db">Invoice voided. It remains available under the Void filter for audit history.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <?php
  $filterConfig = [
      'page' => 'invoice/invoices-list',
      'filters' => [
          'client' => [
              'type' => 'client_autocomplete',
              'label' => 'Client',
              'value' => $client_name,
              'id_value' => $client_id
          ],
          'status' => [
              'type' => 'select',
              'label' => 'Status',
              'value' => $statusFilter,
              'options' => [
                  ['value' => 'all', 'label' => 'All'],
                  ['value' => 'paid', 'label' => 'Paid'],
                  ['value' => 'unpaid', 'label' => 'Unpaid/Partial'],
                  ['value' => 'overdue', 'label' => 'Overdue'],
                  ['value' => 'void', 'label' => 'Void']
              ]
          ],
          'start' => [
              'type' => 'date',
              'label' => 'Start',
              'value' => $start
          ],
          'end' => [
              'type' => 'date',
              'label' => 'End',
              'value' => $end
          ],
          'min_price' => [
              'type' => 'number',
              'label' => 'Min ($)',
              'value' => $min_price ?? '',
              'step' => '0.01'
          ],
          'max_price' => [
              'type' => 'number',
              'label' => 'Max ($)',
              'value' => $max_price ?? '',
              'step' => '0.01'
          ],
          'project_code' => [
              'type' => 'text',
              'label' => 'Project',
              'value' => $project_code,
              'placeholder' => 'PA-2025'
          ],
          'doc_number' => [
              'type' => 'number',
              'label' => 'Doc #',
              'value' => $doc_no
          ]
      ]
  ];
  
  // Render the filter using Twig template
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>

  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">No.</th>
          <th style="padding:10px">Project</th>
          <th style="padding:10px">Customer</th>
          <th style="padding:10px">Contact</th>
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Due</th>
          <th style="padding:10px">Actions</th>
          <th style="padding:10px">Edit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
          $rowStyle = '';
          $status = $r['status'];
          if ($status === 'paid') {
            $rowStyle = 'background:#ecfdf5;';
          } elseif ($status === 'void') {
            $rowStyle = 'background:#f3f4f6;'; // gray for void
          } elseif (invoice_is_collectible_status((string)$status)) {
            if (invoice_is_past_due($r)) {
              $rowStyle = 'background:#fef2f2;'; // red overdue
            } else {
              $rowStyle = 'background:#fffbeb;'; // issued, not yet overdue
            }
          }
          ?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px"><?php echo htmlspecialchars(pa_invoice_label_from_row($r)); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['organization_name'] ?: $r['client']); ?></td>
            <td style="padding:10px"><?php if (!empty($r['organization_name'])): ?><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a><?php endif; ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo $r['created_at'] ? date('m/d/Y', strtotime($r['created_at'])) : ''; ?></td>
            <td style="padding:10px"><?php echo (!empty($r['due_date'])) ? date('m/d/Y', strtotime($r['due_date'])) : ''; ?></td>
            <td style="padding:10px">
              <a href="/?page=invoice/invoice-details&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;margin-right:6px; font-size: small;">View</a>
              <?php if (in_array(strtolower((string)$r['status']), ['sent','unpaid','partial','overdue'], true) && ($r['collection_mode'] ?? 'direct') === 'direct'): ?>
              <form method="post" action="/?page=invoice/email-send" style="display:inline;margin-right:6px">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="type" value="invoice">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Email</button>
              </form>
              <?php endif; ?>
              <?php if (in_array(strtolower((string)$r['status']), ['sent','unpaid','partial','overdue'], true) && ($r['collection_mode'] ?? 'direct') === 'direct'): ?>
                <form method="post" action="/?page=invoice/invoices-mark-paid" onsubmit="return confirm('Mark invoice paid?')" style="display:inline">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46; font-size: small;">Paid</button>
                </form>
              <?php endif; ?>
              <?php if (in_array(strtolower((string)$r['status']), ['draft','sent','unpaid','overdue'], true)): ?>
                <?php $voidDialogId = 'voidInvoiceDialog' . (int)$r['id']; ?>
                <button type="button" onclick="document.getElementById('<?php echo $voidDialogId; ?>').showModal()" style="padding:6px 10px;border:1px solid #fda4af;border-radius:8px;background:#fff1f2;color:#9f1239;font-size:small;cursor:pointer;margin-left:6px">Void</button>
                <dialog id="<?php echo $voidDialogId; ?>" class="invoice-void-dialog" style="width:min(420px,calc(100vw - 32px));box-sizing:border-box;padding:0;border:1px solid #fecdd3;border-radius:12px;box-shadow:0 24px 60px rgba(15,23,42,.28)">
                  <form method="post" action="/?page=invoice/invoice-void" style="display:grid;gap:12px;padding:18px;margin:0" onsubmit="return confirm('Void invoice <?php echo htmlspecialchars(pa_invoice_label_from_row($r)); ?>? It will remain in audit history and cannot be paid.');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/?page=invoice/invoices-list')); ?>">
                    <div><div style="font-size:18px;font-weight:700;color:#111827">Void invoice <?php echo htmlspecialchars(pa_invoice_label_from_row($r)); ?></div><div style="margin-top:4px;font-size:13px;color:#6b7280">The invoice stays in audit history and all payment links are revoked.</div></div>
                    <label style="font-size:13px;font-weight:600;color:#374151">Reason<textarea name="reason" maxlength="500" required rows="4" placeholder="Example: Accidental duplicate invoice" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:9px;border:1px solid #d1d5db;border-radius:7px;resize:vertical"></textarea></label>
                    <div style="font-size:12px;color:#6b7280">Pending or economically active payments must be resolved first. Fully refunded zero-balance history will not block voiding.</div>
                    <div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" onclick="document.getElementById('<?php echo $voidDialogId; ?>').close()" style="padding:8px 11px;border:1px solid #d1d5db;border-radius:7px;background:#fff">Cancel</button><button type="submit" style="padding:8px 11px;border:1px solid #be123c;border-radius:7px;background:#be123c;color:#fff">Confirm Void Invoice</button></div>
                  </form>
                </dialog>
              <?php endif; ?>
            </td>
            <td style="padding:10px">
              <?php if (!in_array(strtolower((string)$r['status']), ['void','cancelled'], true)): ?>
                <a href="/?page=invoice/invoices-edit&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Edit</a>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last = (int)ceil(max(1,$total)/$per);
    $qs = $_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'invoice/invoices-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach ($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="invoice/invoices-list">
        <label>Per page
          <select name="per_page" onchange="this.form.submit()" style="padding:6px;border-radius:8px;border:1px solid #ddd">
            <option value="50" <?php echo $per===50?'selected':''; ?>>50</option>
            <option value="100" <?php echo $per===100?'selected':''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div style="display:flex;gap:8px">
      <?php if($pageN>1): ?><a href="<?php echo $base.'&p='.($pageN-1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Prev</a><?php endif; ?>
      <div style="padding:6px 10px;color:var(--muted)">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if($pageN<$last): ?><a href="<?php echo $base.'&p='.($pageN+1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Next</a><?php endif; ?>
    </div>
  </div>
</section>
