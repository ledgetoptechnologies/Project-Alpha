<?php
// src/views/pages/jobs-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_notes_access.php';
// TODo: Change the Details Button in the project list view to "Preview" and then add button called "Details" That will open up a new page with all the details of the project.
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$prefix = trim($_GET['project_prefix'] ?? '');
$selected = trim($_GET['selected_project_code'] ?? '');
$where = ['j.archived=0'];
$params = [];
if ($client_id > 0) { $where[]='j.client_id=?'; $params[]=$client_id; }
if ($prefix !== '') { $where[]='j.job_code LIKE ?'; $params[]=$prefix.'%'; }
[$scopeWhere,$scopeParams]=scope_clause($pdo,'j',(int)$_SESSION['user']['id']);
if ($scopeWhere !== '') { $where[]=$scopeWhere; $params=array_merge($params,$scopeParams); }
$sql='SELECT j.id job_id,j.job_code project_code,j.client_id,j.project_id,j.created_at,c.name client_name,
             COALESCE(p.notes,pm.notes) notes,
             (SELECT COUNT(*) FROM quotes q WHERE q.job_id=j.id) quotes_count,
             (SELECT COUNT(*) FROM contracts co WHERE co.job_id=j.id) contracts_count,
             (SELECT COUNT(*) FROM invoices i WHERE i.job_id=j.id) invoices_count
      FROM jobs j JOIN clients c ON c.id=j.client_id
      LEFT JOIN projects p ON p.id=j.project_id
      LEFT JOIN project_meta pm ON pm.project_code=j.job_code AND pm.client_id=j.client_id
        AND NOT EXISTS (SELECT 1 FROM jobs other_job WHERE other_job.job_code=j.job_code AND other_job.id<>j.id)
      WHERE '.implode(' AND ',$where).'
      ORDER BY j.created_at DESC,j.id DESC';
$rows=$pdo->prepare($sql);
$rows->execute($params);
$projects = $rows->fetchAll();
$clientName = '';
if ($client_id > 0) {
  $clientStmt = $pdo->prepare('SELECT name FROM clients WHERE id=?');
  $clientStmt->execute([$client_id]);
  $clientName = (string)($clientStmt->fetchColumn() ?: '');
}

$selectedRow = null;
if ($selected !== '') {
  foreach ($projects as $pr) {
    if ($pr['project_code'] === $selected) {
      $selectedRow = $pr;
      break;
    }
  }
}
?>
<section>
  <h2>Jobs</h2>
  <?php
  $filterConfig = [
      'page' => 'jobs/jobs-list',
      'filters' => [
          'client' => [
              'type' => 'client_autocomplete',
              'label' => 'Client',
              'value' => $clientName,
              'id_value' => $client_id,
              'placeholder' => 'Type client name...'
          ],
          'project_prefix' => [
              'type' => 'text',
              'label' => 'Job ID Prefix',
              'value' => $prefix,
              'placeholder' => 'PA-JD'
          ]
      ],
      'columns' => 4
  ];
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>

  <?php if (!$projects): ?>
    <div style="color:var(--muted)">No jobs yet.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;align-items:start">
      <div style="display:grid;gap:12px">
        <?php foreach ($projects as $p): ?>
          <div style="border:1px solid #eee;border-radius:8px;background:#fff;overflow:hidden">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid #eee">
              <div>
                <strong>Job ID <?php echo htmlspecialchars($p['project_code']); ?></strong>
                <?php if (!empty($p['created_at'])): ?><span style="color:var(--muted);font-size:12px"> · Created <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$p['created_at']))); ?></span><?php endif; ?>
                <span style="color:var(--muted)"> · <?php echo htmlspecialchars($p['client_name']); ?></span>
              </div>
              <div style="display:flex;gap:12px;align-items:center">
                <?php
                $notes = isset($p['notes']) ? (string)$p['notes'] : '';
                $preview = '';
                if ($notes !== null && trim($notes) !== '') {
                  $oneLine = preg_replace('/\s+/', ' ', trim($notes));
                  if (function_exists('mb_substr')) {
                    $preview = mb_substr($oneLine, 0, 80, 'UTF-8');
                    if (mb_strlen($oneLine, 'UTF-8') > 80) {
                      $preview .= '...';
                    }
                  } else {
                    $preview = substr($oneLine, 0, 80) . (strlen($oneLine) > 80 ? '...' : '');
                  }
                }
                ?>
                <div title="<?php echo htmlspecialchars($notes ?? ''); ?>" style="color:var(--muted);max-width:520px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <?php echo htmlspecialchars($preview); ?>
                </div>
                <a href="/?page=jobs/job-details&amp;selected_project_code=<?php echo urlencode($p['project_code']); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff">View Details</a>
                <a href="/?page=jobs/jobs-list&amp;selected_project_code=<?php echo urlencode($p['project_code']); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff">Quick View</a>
                <div style="color:var(--muted)">Q: <?php echo (int)$p['quotes_count']; ?> · C: <?php echo (int)$p['contracts_count']; ?> · I: <?php echo (int)$p['invoices_count']; ?></div>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;padding:12px">
              <?php
              $pc = $p['project_code'];
              $cid = (int)$p['client_id'];
              $q = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM quotes WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              $q->execute([$cid, $pc]);
              $quotes = $q->fetchAll();
              // signed_pdf_path column may be absent on older databases; select it conditionally
              $has_signed = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='signed_pdf_path'")->fetchColumn();
              if ($has_signed) {
                $co = $pdo->prepare('SELECT id, doc_number, status, created_at, signed_pdf_path FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              } else {
                $co = $pdo->prepare('SELECT id, doc_number, status, created_at, NULL AS signed_pdf_path FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              }
              $co->execute([$cid, $pc]);
              $contracts = $co->fetchAll();
              $i = $pdo->prepare('SELECT id, doc_number, invoice_type, total, status, created_at FROM invoices WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              $i->execute([$cid, $pc]);
              $invoices = $i->fetchAll();
              ?>
              <div>
                <div style="font-weight:600;margin-bottom:6px">Quotes</div>
                <?php if ($quotes): ?>
                  <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                    <?php foreach ($quotes as $row): ?>
                      <li><a href="/?page=quote/quote-details&amp;id=<?php echo (int)$row['id']; ?>">Q-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'], 2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <div style="color:var(--muted)">None</div>
                <?php endif; ?>
              </div>
              <div>
                <div style="font-weight:600;margin-bottom:6px">Contracts</div>
                <?php if ($contracts): ?>
                  <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                    <?php foreach ($contracts as $row): ?>
                      <li>
                        <div><a href="/?page=contract/contract-details&amp;id=<?php echo (int)$row['id']; ?>">C-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · <?php echo htmlspecialchars($row['status']); ?></div>
                        <?php if (!empty($row['signed_pdf_path'])): ?>
                          <?php $u2 = (string)$row['signed_pdf_path'];
                          $dl2 = $u2 . (strpos($u2, '?') !== false ? '&download=1' : ''); ?>
                          <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">
                            <a href="<?php echo htmlspecialchars($u2); ?>" target="_blank" style="padding:2px 6px;border-radius:6px;background:#3b82f6;color:#fff;text-decoration:none">View PDF</a>
                            <a href="<?php echo htmlspecialchars($dl2); ?>" style="padding:2px 6px;border-radius:6px;background:#6366f1;color:#fff;text-decoration:none">Download</a>
                          </div>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <div style="color:var(--muted)">None</div>
                <?php endif; ?>
              </div>
              <div>
                <div style="font-weight:600;margin-bottom:6px">Invoices</div>
                <?php if ($invoices): ?>
                  <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                    <?php foreach ($invoices as $row): ?>
                      <li><a href="/?page=invoice/invoice-details&amp;id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars(pa_invoice_label_from_row($row)); ?></a> · $<?php echo number_format((float)$row['total'], 2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <div style="color:var(--muted)">None</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div>
        <?php if ($selected !== '' && $selectedRow): ?>
          <?php
          $selNotes = '';
          if (project_notes_authorized_job($pdo, $selected, (int)$_SESSION['user']['id'], (int)$selectedRow['client_id'])) {
            $pn2 = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=? AND client_id=?');
            $pn2->execute([$selected, (int)$selectedRow['client_id']]);
            $selNotes = (string)$pn2->fetchColumn();
          }
          $signedContracts = [];
          $has_signed = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='signed_pdf_path'")->fetchColumn();
          if ($has_signed) {
            $signed = $pdo->prepare("SELECT id, doc_number, signed_pdf_path, status FROM contracts WHERE project_code=? AND client_id=? AND signed_pdf_path IS NOT NULL ORDER BY created_at DESC");
            $signed->execute([$selected, (int)$selectedRow['client_id']]);
            $signedContracts = $signed->fetchAll();
          }
          ?>
          <div style="position:sticky;top:12px;border:1px solid #eee;border-radius:8px;background:#fff;padding:12px;display:grid;gap:12px">
            <div style="font-weight:700">Job ID <?php echo htmlspecialchars($selected); ?> · <?php echo htmlspecialchars($selectedRow['client_name']); ?></div>
            <?php if(!empty($appConfig['job_project_locations_enabled'])): $jobLocations=$pdo->query('SELECT id,name,city,state FROM service_locations WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);$jobCurrent=$pdo->prepare('SELECT default_service_location_id,status,notes FROM jobs WHERE id=?');$jobCurrent->execute([(int)$selectedRow['job_id']]);$jobSettings=$jobCurrent->fetch(PDO::FETCH_ASSOC)?:[]; ?>
            <form method="post" action="/?page=jobs/job-settings-handler" style="display:grid;gap:8px"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="job_id" value="<?php echo (int)$selectedRow['job_id']; ?>"><input type="hidden" name="job_code" value="<?php echo htmlspecialchars($selected); ?>"><label><div>Default service location</div><select name="default_service_location_id" class="input"><option value="">No default</option><?php foreach($jobLocations as $location): ?><option value="<?php echo (int)$location['id']; ?>" <?php echo (int)($jobSettings['default_service_location_id']??0)===(int)$location['id']?'selected':''; ?>><?php echo htmlspecialchars($location['name'].($location['city']?' — '.$location['city'].', '.$location['state']:'')); ?></option><?php endforeach; ?></select></label><label><div>Scheduling status</div><select name="status" class="input"><?php foreach(['not_started'=>'Not started','active'=>'Active','completed'=>'Completed','cancelled'=>'Cancelled'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo ($jobSettings['status']??'not_started')===$value?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label><label><div>Internal Job notes</div><textarea name="notes" class="input" rows="3"><?php echo htmlspecialchars((string)($jobSettings['notes']??'')); ?></textarea></label><button class="btn">Save Job details</button></form>
            <?php endif; ?>
            <form method="post" action="/?page=project-notes-update" style="display:grid;gap:8px">
              <input type="hidden" name="project_code" value="<?php echo htmlspecialchars($selected); ?>">
              <input type="hidden" name="client_id" value="<?php echo (int)$selectedRow['client_id']; ?>">
              <input type="hidden" name="redirect" value="<?php echo htmlspecialchars('/?page=jobs/jobs-list&selected_project_code=' . urlencode($selected)); ?>">
              <label>
                <div>Notes</div>
                <textarea name="notes" rows="10" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Project notes visible only to you"><?php echo htmlspecialchars($selNotes ?? ''); ?></textarea>
              </label>
              <div style="display:flex;gap:8px">
                <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Save</button>
                <a href="/?page=jobs/jobs-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Close</a>
              </div>
            </form>
            <div>
              <div style="font-weight:600;margin-bottom:6px">Signed Contracts</div>
              <?php if ($signedContracts): ?>
                <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                  <?php foreach ($signedContracts as $sc): ?>
                    <li style="border:1px solid #eee;border-radius:8px;padding:8px">
                      <div>C-<?php echo (int)($sc['doc_number'] ?? $sc['id']); ?> · <?php echo htmlspecialchars($sc['status']); ?></div>
                      <?php if (!empty($sc['signed_pdf_path'])): ?>
                        <?php $u3 = (string)$sc['signed_pdf_path'];
                        $dl3 = $u3 . (strpos($u3, '?') !== false ? '&download=1' : ''); ?>
                        <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
                          <a href="<?php echo htmlspecialchars($u3); ?>" target="_blank" style="padding:2px 6px;border-radius:6px;background:#3b82f6;color:#fff;text-decoration:none">View PDF</a>
                          <a href="<?php echo htmlspecialchars($dl3); ?>" style="padding:2px 6px;border-radius:6px;background:#6366f1;color:#fff;text-decoration:none">Download</a>
                        </div>
                        <div style="margin-top:6px">
                          <iframe src="<?php echo htmlspecialchars($u3); ?>" style="width:100%;height:220px;border:1px solid #eee;border-radius:6px"></iframe>
                        </div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div style="color:var(--muted)">No signed contracts yet.</div>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div style="position:sticky;top:12px;color:var(--muted);border:1px dashed #e5e7eb;border-radius:8px;padding:12px;background:#fafafa">Select a job to view and edit notes.</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
