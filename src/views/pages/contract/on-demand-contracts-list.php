<?php
// src/views/pages/contract/on-demand-contracts-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/csrf.php';



$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$status = $_GET['status'] ?? '';
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;

$where=['odc.contract_type="on_demand"'];$p=[];
if($client_id>0){$where[]='odc.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='(c.name LIKE ? OR o.name LIKE ?)'; $p[]='%'.$client_name.'%'; $p[]='%'.$client_name.'%'; }
if($status!==''){ $where[]='odc.status=?'; $p[] = $status; }
if($start!==''){$where[]='odc.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='odc.created_at<=?';$p[]=$end.' 23:59:59';}
if($project_code!==''){ $where[]='odc.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($doc_no>0){ $where[]='odc.doc_number=?'; $p[] = $doc_no; }
if($min_price !== null){ $where[]='odc.price_per_invoice>=?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='odc.price_per_invoice<=?'; $p[] = $max_price; }

require_once __DIR__ . '/../../../utils/acl.php';
[$scopeWhere, $scopeParams] = scope_clause($pdo, 'odc', (int)$_SESSION['user']['id']);
if ($scopeWhere !== '') {
    $where[] = ltrim($scopeWhere, ' AND');
    $p = array_merge($p, $scopeParams);
}

$per = (int)($_GET['per_page'] ?? 50); 
if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$documentJoins = ' LEFT JOIN clients c ON c.id=odc.client_id' . pa_document_effective_organization_joins('odc', 'c');
$sqlCount = 'SELECT COUNT(*) FROM contracts odc'.$documentJoins.($where?' WHERE '.implode(' AND ',$where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql="SELECT odc.id, odc.doc_number, odc.project_id, odc.project_code, odc.status, odc.start_date, odc.end_date, odc.billing_interval_count, odc.billing_interval_unit, odc.price_per_invoice, odc.subtotal, odc.total_invoiced, odc.invoice_count, odc.last_invoice_date, odc.signed_at, odc.signed_pdf_path, c.name client, c.id AS client_id, o.name organization_name, p.invoice_billing_period AS project_invoice_billing_period FROM contracts odc{$documentJoins} LEFT JOIN projects p ON p.id=odc.project_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} 
$sql.=" ORDER BY odc.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();

$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>On-Demand Contracts</h2>
  
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">On-demand contract created successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['emailed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Email sent.</div>
  <?php elseif (!empty($_GET['email_err'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['invoice_generated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Invoice generated successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['activated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Contract activated.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['signed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Signed PDF uploaded. Review the contract, then activate it when ready.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <?php
  $filterConfig = [
      'page' => 'contract/on-demand-contracts-list',
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
              'value' => $status,
              'options' => [
                  ['value' => '', 'label' => 'All'],
                  ['value' => 'pending', 'label' => 'Pending'],
                  ['value' => 'active', 'label' => 'Active'],
                  ['value' => 'paused', 'label' => 'Paused'],
                  ['value' => 'completed', 'label' => 'Completed'],
                  ['value' => 'cancelled', 'label' => 'Cancelled']
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
              'label' => 'Project ID',
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
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Billing</th>
          <th style="padding:10px">Price/Invoice</th>
          <th style="padding:10px">Invoices</th>
          <th style="padding:10px">Last Invoice</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
<?php 
  $rowStyle = ($r['status']==='active') ? 'background:#fffbeb;' : (($r['status']==='completed') ? 'background:#ecfdf5;' : ((in_array($r['status'], ['cancelled','paused'], true)) ? 'background:#fef2f2;' : ''));
  
  $billingText = 'Manual invoices';
?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px">ODC-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['organization_name'] ?: $r['client']); ?></td>
            <td style="padding:10px"><?php if (!empty($r['organization_name'])): ?><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a><?php endif; ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($billingText); ?></td>
            <?php $displayPrice = (float)($r['price_per_invoice'] ?? 0) > 0 ? (float)$r['price_per_invoice'] : (float)($r['subtotal'] ?? 0); ?>
            <td style="padding:10px">$<?php echo number_format($displayPrice, 2); ?></td>
            <td style="padding:10px"><?php echo (int)$r['invoice_count']; ?> ($<?php echo number_format((float)$r['total_invoiced'], 2); ?>)</td>
            <td style="padding:10px"><?php echo $r['last_invoice_date'] ? date('M j, Y', strtotime($r['last_invoice_date'])) : '—'; ?></td>
            <td style="padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
              <a href="/?page=contract/contract-details&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">View</a>
              <?php $cst = strtolower((string)$r['status']); if (!in_array($cst, ['denied','cancelled','void'], true)): ?>
                <form method="post" action="/?page=contract/email-send" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="type" value="contract">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="generation_key" value="<?php echo bin2hex(random_bytes(16)); ?>">
                  <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                  <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Email</button>
                </form>
              <?php endif; ?>
              <a href="/?page=contract/on-demand-invoices-list&contract_id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Invoices</a>
              <?php if (in_array($r['status'], ['draft','pending'], true) && empty($r['signed_at']) && empty($r['signed_pdf_path'])): ?>
                <form method="post" action="/?page=contract/contract-sign" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input id="upl-od-<?php echo (int)$r['id']; ?>" type="file" name="signed_pdf" accept="application/pdf,.pdf" style="display:none" data-submit-on-file required>
                  <button type="button" data-file-picker-target="upl-od-<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Upload</button>
                </form>
              <?php endif; ?>
              <?php if (!empty($r['signed_pdf_path'])): ?>
                <a href="<?php echo htmlspecialchars($r['signed_pdf_path']); ?>" target="_blank" rel="noopener" style="padding:6px 10px;border:1px solid #10b981;border-radius:8px;background:#ecfdf5;color:#065f46; font-size: small;">Signed PDF</a>
              <?php endif; ?>
              <?php if ($r['status'] === 'pending'): ?>
                <?php if (empty($r['signed_pdf_path'])): ?>
                  <button type="button" disabled title="Upload signed contract first" style="padding:6px 10px;border:0;border-radius:8px;background:#9ca3af;color:#fff;font-size:small;cursor:not-allowed">Activate</button>
                <?php else: ?>
                  <form method="post" action="/?page=on-demand-contract-activate" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;">Activate</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($r['status'] === 'active'): ?>
                <?php 
                  // Check if contract has ended
                  $canGenerate = true;
                  if (!empty($r['end_date']) && $r['end_date'] < date('Y-m-d')) {
                    $canGenerate = false;
                  }
                ?>
                <?php if ($canGenerate): ?>
                <?php $usesMonthlyProjectBilling = !empty($r['project_id']) && ($r['project_invoice_billing_period'] ?? '') === 'monthly'; ?>
                <form method="post" action="/?page=on-demand-invoice-generate" class="od-generate-form" data-monthly-project-billing="<?php echo $usesMonthlyProjectBilling ? '1' : '0'; ?>" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="generation_key" value="<?php echo bin2hex(random_bytes(16)); ?>">
                  <input type="hidden" name="send_email" value="0">
                  <button type="button" onclick="openOdGenerateModal(this.form)" style="padding:6px 10px;border:0;border-radius:8px;background:#3b82f6;color:#fff; font-size: small;">Generate Invoice</button>
                </form>
                <?php endif; ?>
                <form method="post" action="/?page=on-demand-contract-pause" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#f59e0b;color:#fff; font-size: small;">Pause</button>
                </form>
              <?php elseif ($r['status'] === 'paused'): ?>
                <form method="post" action="/?page=on-demand-contract-resume" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;">Resume</button>
                </form>
              <?php endif; ?>
              <?php if (in_array($r['status'], ['draft','pending'], true) && empty($r['signed_pdf_path'])): ?>
                <form method="post" action="/?page=on-demand-contract-terminate" style="display:inline" onsubmit="return confirm('Terminate this on-demand contract?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#dc2626;color:#fff; font-size: small;">Terminate</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div id="odGenerateModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);z-index:1000;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:8px;max-width:440px;width:100%;box-shadow:0 24px 70px rgba(15,23,42,0.25);border:1px solid #e5e7eb">
      <div style="padding:18px 20px;border-bottom:1px solid #e5e7eb">
        <h3 style="margin:0;font-size:18px">Generate On-Demand Invoice</h3>
        <p id="odGenerateDescription" style="margin:6px 0 0;color:#6b7280;font-size:14px">Choose whether to send the invoice now or generate it for review and edits first.</p>
      </div>
      <div style="padding:18px 20px;display:grid;gap:10px">
        <button id="odGeneratePrimary" type="button" onclick="submitOdGenerate(true)" style="width:100%;padding:11px 14px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">Generate and Send Email</button>
        <button id="odGenerateDraft" type="button" onclick="submitOdGenerate(false)" style="width:100%;padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-weight:600;cursor:pointer">Generate Only</button>
        <button type="button" onclick="closeOdGenerateModal()" style="width:100%;padding:11px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;color:#6b7280;font-weight:600;cursor:pointer">Cancel</button>
      </div>
    </div>
  </div>

  <script>
    var odGenerateForm = null;
    function openOdGenerateModal(form) {
      odGenerateForm = form;
      var monthly = form.dataset.monthlyProjectBilling === '1';
      var description = document.getElementById('odGenerateDescription');
      var primary = document.getElementById('odGeneratePrimary');
      var draft = document.getElementById('odGenerateDraft');
      if (description) description.textContent = monthly
        ? 'Finalize this charge for the monthly project statement, or save a draft for review. Monthly charges are not emailed separately.'
        : 'Choose whether to send the invoice now or generate it for review and edits first.';
      if (primary) primary.textContent = monthly ? 'Finalize for Project Billing' : 'Generate and Send Email';
      if (draft) draft.textContent = monthly ? 'Save Draft for Review' : 'Generate Only';
      var modal = document.getElementById('odGenerateModal');
      if (modal) modal.style.display = 'flex';
    }
    function closeOdGenerateModal() {
      odGenerateForm = null;
      var modal = document.getElementById('odGenerateModal');
      if (modal) modal.style.display = 'none';
    }
    function submitOdGenerate(sendEmail) {
      if (!odGenerateForm) return;
      var input = odGenerateForm.querySelector('input[name="send_email"]');
      if (input) input.value = sendEmail ? '1' : '0';
      odGenerateForm.submit();
    }
    document.getElementById('odGenerateModal')?.addEventListener('click', function(e) {
      if (e.target === this) closeOdGenerateModal();
    });
  </script>

  <?php
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'contract/on-demand-contracts-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="contract/on-demand-contracts-list">
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
