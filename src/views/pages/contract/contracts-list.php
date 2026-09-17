<?php
// src/views/pages/contracts-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;
$status = $_GET['status'] ?? '';
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
$where=['(co.contract_type IS NULL OR co.contract_type = "regular")'];$p=[];
if($client_id>0){$where[]='co.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='(c.name LIKE ? OR o.name LIKE ?)'; $p[]='%'.$client_name.'%'; $p[]='%'.$client_name.'%'; }
if($start!==''){$where[]='co.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='co.created_at<=?';$p[]=$end.' 23:59:59';}
if($status!==''){ $where[]='co.status=?'; $p[] = $status; }
if($project_code!==''){ $where[]='co.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($doc_no>0){ $where[]='co.doc_number=?'; $p[] = $doc_no; }
if($min_price !== null){ $where[]='co.total>=?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='co.total<=?'; $p[] = $max_price; }

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'co', (int)$_SESSION['user']['id']);
if ($scopeWhere) {
    $where[] = trim($scopeWhere);
    $p = array_merge($p, $scopeParams);
}

$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$documentJoins = ' JOIN clients c ON c.id=co.client_id' . pa_document_effective_organization_joins('co', 'c');
$sqlCount = 'SELECT COUNT(*) FROM contracts co'.$documentJoins.($where?' WHERE '.implode(' AND ',$where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$has_signed = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='signed_pdf_path'")->fetchColumn();
$select_signed = $has_signed ? 'co.signed_pdf_path' : 'NULL AS signed_pdf_path';
$sql="SELECT co.id, co.doc_number, co.project_code, co.status, co.total, co.deposit_type, co.deposit_amount, co.deposit_paid, co.signed_at, {$select_signed}, c.name client, c.id AS client_id, o.name organization_name FROM contracts co{$documentJoins}";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} $sql.=" ORDER BY co.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>Contracts</h2>
  <?php if (!empty($_GET['emailed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Email sent.</div>
  <?php elseif (!empty($_GET['email_err'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['signed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Signed PDF uploaded.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['deposit_received'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Deposit marked as received.</div>
  <?php endif; ?>
  <?php
  $filterConfig = [
      'page' => 'contract/contracts-list',
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
                  ['value' => 'completed', 'label' => 'Completed'],
                  ['value' => 'cancelled', 'label' => 'Cancelled'],
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
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Actions</th>
          <th style="padding:10px">Edit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
<?php 
  // active = yellow, completed = green, denied/cancelled = red, draft = light yellow
  $rowStyle = ($r['status']==='active') ? 'background:#fffbeb;' : (($r['status']==='completed') ? 'background:#ecfdf5;' : ((in_array($r['status'], ['cancelled','denied'], true)) ? 'background:#fef2f2;' : (($r['status']==='draft') ? 'background:#fdfdea;' : '')));
?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px"><a href="/?page=contract/contract-details&id=<?php echo (int)$r['id']; ?>" style="text-decoration:none;color:inherit">C-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></a></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['organization_name'] ?: $r['client']); ?></td>
            <td style="padding:10px"><?php if (!empty($r['organization_name'])): ?><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a><?php endif; ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)($r['total'] ?? 0),2); ?></td>
            <td style="padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
              <a href="/?page=contract/contract-details&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">View</a>
              <?php $cst = strtolower((string)$r['status']); if (!in_array($cst, ['denied','cancelled','void'], true)): ?>
              <form method="post" action="/?page=contract/email-send" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="type" value="contract">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Email</button>
              </form>
              <?php endif; ?>
              <?php if (in_array($r['status'], ['draft','pending'], true) && empty($r['signed_at']) && empty($r['signed_pdf_path'])): ?>
              <form method="post" action="/?page=contract/contract-sign" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <input id="upload-<?php echo (int)$r['id']; ?>" type="file" name="signed_pdf" accept="application/pdf,.pdf" style="display:none" data-submit-on-file required>
                <button type="button" data-file-picker-target="upload-<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Upload</button>
              </form>
              <?php endif; ?>
              <?php if (!empty($r['signed_pdf_path'])): ?>
                <a href="<?php echo htmlspecialchars($r['signed_pdf_path']); ?>" target="_blank" rel="noopener" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Signed PDF</a>
              <?php endif; ?>
              <?php 
                // Check if deposit is required and not yet received
                // Note: deposit_amount already stores the calculated dollar amount (not percentage)
                $depositType = $r['deposit_type'] ?? 'none';
                $depositCalc = (float)($r['deposit_amount'] ?? 0);
                $depositPaid = (float)($r['deposit_paid'] ?? 0);
                $needsDeposit = $depositType !== 'none' && $depositCalc > 0 && $depositPaid < $depositCalc;
              ?>
              <?php if ($needsDeposit && $r['status'] === 'pending'): ?>
                <form method="post" action="/?page=contract/contract-deposit-received" style="display:inline" onsubmit="return confirm('Mark deposit as received ($<?php echo number_format($depositCalc, 2); ?>)?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46; font-size: small;">Deposit Received</button>
                </form>
              <?php endif; ?>
              <?php if ($r['status']==='active'): ?>
                <form method="post" action="/?page=contract/contract-complete" style="display:inline" onsubmit="return confirm('Complete this contract, finalize its invoice, and send it when automatic delivery is enabled?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;">Complete</button>
                </form>
              <?php endif; ?>
              <?php if ($r['status'] !== 'cancelled' && $r['status'] !== 'completed'): ?>
              <form method="post" action="/?page=contract/contract-void" onsubmit="return confirm('Void this contract and linked invoices?')" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#6b7280;color:#fff; font-size: small;">Void</button>
              </form>
              <?php endif; ?>
            </td>
            <td style="padding:10px">
              <?php if (in_array($r['status'], ['draft','pending'], true) && empty($r['signed_pdf_path'])): ?>
                <a href="/?page=contract/contracts-edit&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Edit</a>
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
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'contract/contracts-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="contract/contracts-list">
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
