<?php
// src/views/pages/quotes-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$status = $_GET['status'] ?? 'all'; // all|approved|rejected|pending
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
// Detect optional columns
$hasDoc = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='doc_number'")->fetchColumn();
$hasProj = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='project_code'")->fetchColumn();
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;
$where=['(q.quote_type IS NULL OR q.quote_type = "regular")'];$p=[];
if($client_id>0){$where[]='q.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='(c.name LIKE ? OR o.name LIKE ?)'; $p[]='%'.$client_name.'%'; $p[]='%'.$client_name.'%'; }
if($start!==''){$where[]='q.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='q.created_at<=?';$p[]=$end.' 23:59:59';}
if(in_array($status,['approved','rejected','pending'],true)){ $where[]='q.status=?'; $p[]=$status; }
if($hasProj && $project_code!==''){ $where[]='q.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($hasDoc && $doc_no>0){ $where[]='q.doc_number=?'; $p[] = $doc_no; }
if($min_price !== null){ $where[]='q.total>=?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='q.total<=?'; $p[] = $max_price; }

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'q', (int)$_SESSION['user']['id']);
if ($scopeWhere) {
    $where[] = trim($scopeWhere);
    $p = array_merge($p, $scopeParams);
}

$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$documentJoins = ' JOIN clients c ON c.id=q.client_id' . pa_document_effective_organization_joins('q', 'c');
$sqlCount = 'SELECT COUNT(*) FROM quotes q'.$documentJoins.($where? ' WHERE '.implode(' AND ', $where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$select = 'q.id, q.status, q.total, q.created_at, c.name AS client_name, c.id AS client_id, o.name AS organization_name';
$select = ($hasDoc ? 'q.doc_number, ' : 'q.id AS doc_number, ') . $select;
$select = ($hasProj ? 'q.project_code, ' : "'' AS project_code, ") . $select;
$sql = "SELECT $select FROM quotes q{$documentJoins}";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} $sql.=" ORDER BY q.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>Quotes</h2>
  <?php if (!empty($_GET['emailed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Email sent.</div>
  <?php elseif (!empty($_GET['email_err'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
  <?php endif; ?>
  <?php
  $flash = $_SESSION['flash_quote_approve'] ?? null;
  unset($_SESSION['flash_quote_approve']);
  ?>
  <?php if ($flash): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1"><?php echo htmlspecialchars($flash['message']); ?></div>
  <?php endif; ?>
  
  <?php
  // Render the filter using Twig template instead of PHP include
  $statusOptions = [
      ['value' => 'all', 'label' => 'All'],
      ['value' => 'approved', 'label' => 'Approved'],
      ['value' => 'rejected', 'label' => 'Denied'],
      ['value' => 'pending', 'label' => 'Pending']
  ];
  
  $filterConfig = [
      'page' => 'quote/quotes-list',
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
              'options' => $statusOptions
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
  
  // Render the filter component using Twig
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px"><?php echo $hasDoc ? 'No.' : 'ID'; ?></th>
          <?php if ($hasProj): ?><th style="padding:10px">Project</th><?php endif; ?>
          <th style="padding:10px">Customer</th>
          <th style="padding:10px">Contact</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px;text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $rowStyle = $r['status']==='approved' ? 'background:#ecfdf5;' : ($r['status']==='pending' ? 'background:#fffbeb;' : ($r['status']==='rejected' ? 'background:#fef2f2;' : '')); ?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px">Q-<?php echo (int)$r['doc_number']; ?></td>
            <?php if ($hasProj): ?><td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td><?php endif; ?>
            <td style="padding:10px"><?php echo htmlspecialchars($r['organization_name'] ?: $r['client_name']); ?></td>
            <td style="padding:10px"><?php if (!empty($r['organization_name'])): ?><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client_name']); ?></a><?php endif; ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td style="padding:10px"><?php echo $r['created_at'] ? date('m/d/Y', strtotime($r['created_at'])) : ''; ?></td>
            <td style="padding:10px">
              <div style="display:flex;gap:6px;justify-content:flex-end">
              <a href="/?page=quote/quote-details&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">View</a>
              <?php if (in_array($r['status'], ['draft','pending'], true)): ?>
                <a href="/?page=quote/quotes-edit&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Edit</a>
              <?php endif; ?>
              <?php if (strtolower((string)$r['status']) !== 'rejected'): ?>
              <form method="post" action="/?page=quote/email-send" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="type" value="quote">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Email</button>
              </form>
              <?php endif; ?>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=quote/quote-approve" onsubmit="return confirm('Approve this quote and generate contract + invoice?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff; font-size: small;">Approve</button>
                </form>
                <form method="post" action="/?page=quote/quote-reject" onsubmit="return confirm('Deny this quote?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff; font-size: small;">Deny</button>
              </form>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'quote/quotes-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="quote/quotes-list">
        <label>Per page
          <select name="per_page" onchange="this.form.submit()" style="padding:6px;border-radius:8px;border:1px solid #ddd">
            <option value="50" <?php echo $per===50?'selected':''; ?>>50</option>
            <option value="100" <?php echo $per===100?'selected':''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div style="display:flex;gap:8px">
      <?php if($pageN>1): ?><a href="<?php echo htmlspecialchars($base.'&p='.($pageN-1), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Prev</a><?php endif; ?>
      <div style="padding:6px 10px;color:var(--muted)">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if($pageN<$last): ?><a href="<?php echo htmlspecialchars($base.'&p='.($pageN+1), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Next</a><?php endif; ?>
    </div>
  </div>
</section>
