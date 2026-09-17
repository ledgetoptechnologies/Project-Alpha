<?php
// src/views/pages/clients-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus = api_v2_directory_management_status($pdo);
$per = null; // show all clients
$pageN = 1;
$offset = 0;
$q = trim($_GET['q'] ?? '');
$org = trim($_GET['org'] ?? '');
$where = [];
$params = [];
if ($q !== '') { $where[] = 'c.name LIKE ?'; $params[] = '%'.$q.'%'; }
if ($org !== '') { $where[] = 'o.name LIKE ?'; $params[] = '%'.$org.'%'; }

// Guard for older DBs without 'archived' column
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
// The organization join may also expose an `archived` column. Qualify this
// predicate so the normal client list remains valid after either table gains
// lifecycle support.
$activeFilter = $hasArchived ? 'c.archived=0' : '1=1';

// Build WHERE clause
$whereClause = 'WHERE '.$activeFilter;
if (!empty($where)) {
  $whereClause .= ' AND ('.implode(' AND ', $where).')';
}

// fetch all clients without pagination
$sql = "SELECT c.id, c.name, c.email, c.phone, c.created_at, o.name as organization_name 
        FROM clients c 
        LEFT JOIN organizations o ON c.organization_id = o.id 
        $whereClause
        ORDER BY c.name ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$clients = $st->fetchAll();

function client_list_email_html(?string $email): string
{
    $email = trim((string)$email);
    $placeholderEmails = ['[email protected]', 'email@example.com', 'noemail@example.com', 'n/a', 'na'];
    if ($email === '' || in_array(strtolower($email), $placeholderEmails, true)) {
        return '<span style="color:var(--muted)">None</span>';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $safe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        return '<a href="mailto:' . $safe . '">' . $safe . '</a>';
    }
    return htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
}
?>
<section>
  <h2>Clients</h2>
  <?php if($directoryManagementStatus['configured']):?><div style="margin:10px 0;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;font-size:13px"><?php echo e(api_v2_directory_management_warning($directoryManagementStatus)); ?></div><?php endif;?>
  <div style="margin:8px 0">
    <a href="/?page=client/archived-clients" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">View Archived</a>
  </div>
  <?php if (!empty($_GET['archived'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-size:small;">Client archived.</div>
  <?php elseif (!empty($_GET['restored'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-size:small;">Client restored.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5;font-size:small;">Client permanently deleted.</div>
  <?php endif; ?>
  <?php $selected = isset($_GET['selected_client_id']) ? (int)$_GET['selected_client_id'] : 0; ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
  <div>
  <?php
  $filterConfig = [
      'page' => 'client/clients-list',
      'filters' => [
          'q' => [
              'type' => 'text',
              'label' => 'Search by Name',
              'value' => $q,
              'placeholder' => 'Type to search...'
          ],
          'org' => [
              'type' => 'text',
              'label' => 'Search by Organization',
              'value' => $org,
              'placeholder' => 'Type to search...'
          ]
      ],
      'columns' => 4,
      'live_filter_id' => 'client-list',
      'live_filter_fields' => ['q', 'org'],
      'live_filter_debounce' => 300,
      'live_filter_help' => 'Results update automatically as you type.'
  ];
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Client created.</div>
  <?php endif; ?>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Name</th>
          <th style="padding:10px">Email</th>
          <th style="padding:10px">Phone</th>
          <th style="padding:10px">Organization</th>
          <!-- <th style="padding:10px">Created</th> -->
          <?php if(!$directoryManagementStatus['effective']):?><th style="padding:10px">Edit</th><th style="padding:10px">Actions</th><?php endif;?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><a href="/?page=client/client-details&id=<?php echo (int)$c['id']; ?>" style="text-decoration:none;color:inherit;font-weight:600"><?php echo htmlspecialchars($c['name']); ?></a></td>
            <td style="padding:10px"><?php echo client_list_email_html($c['email'] ?? null); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars(format_phone($c['phone'] ?? '')); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['organization_name'] ?? ''); ?></td>
            <!-- <td style="padding:10px"><?php echo htmlspecialchars($c['created_at']); ?></td> -->
            <?php if(!$directoryManagementStatus['effective']):?><td style="padding:10px"><a href="/?page=client/clients-edit&id=<?php echo (int)$c['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Edit</a></td>
            <td style="padding:10px">
              <!-- TODO: archive button does not work on the edit view of a client -->
              <form method="post" action="/?page=client/clients-delete" onsubmit="return confirm('Archive client <?php echo e($c['name']); ?>? This moves the client to Archived Clients.');" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c">Archive</button>
              </form>
            </td>
            <?php endif;?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <!-- Pagination removed: showing all clients -->
  </div>
  <div>
    <h3 style="margin:0 0 8px">Related Projects</h3>
    <?php if ($selected>0): ?>
      <?php
      // Gather distinct project codes for this client
      $proj = $pdo->prepare("SELECT project_code FROM (
        SELECT project_code FROM quotes WHERE client_id=? AND project_code IS NOT NULL
        UNION DISTINCT SELECT project_code FROM contracts WHERE client_id=? AND project_code IS NOT NULL
        UNION DISTINCT SELECT project_code FROM invoices WHERE client_id=? AND project_code IS NOT NULL
      ) t ORDER BY project_code DESC");
      $proj->execute([$selected,$selected,$selected]);
      $projects = $proj->fetchAll(PDO::FETCH_COLUMN);
      ?>
      <?php if ($projects): ?>
        <div style="display:grid;gap:12px">
          <?php foreach ($projects as $pc): ?>
            <div style="border:1px solid #eee;border-radius:8px;background:#fff;overflow:hidden">
              <div style="padding:10px 12px;border-bottom:1px solid #eee;font-weight:600">Project <?php echo e($pc); ?></div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;padding:12px">
                <?php
                  $q = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM quotes WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $q->execute([$selected, $pc]); $quotes = $q->fetchAll();
                  $co = $pdo->prepare('SELECT id, doc_number, status, created_at FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $co->execute([$selected, $pc]); $contracts = $co->fetchAll();
                  $i = $pdo->prepare('SELECT id, doc_number, invoice_type, total, status, created_at FROM invoices WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $i->execute([$selected, $pc]); $invoices = $i->fetchAll();
                ?>
                <div>
                  <div style="font-weight:600;margin-bottom:6px">Quotes</div>
                  <?php if ($quotes): ?>
                    <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                      <?php foreach ($quotes as $row): ?>
                        <li><a href="/?page=quote/quote-details&id=<?php echo (int)$row['id']; ?>">Q-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
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
                        <li><a href="/?page=contract/contract-details&id=<?php echo (int)$row['id']; ?>">C-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · <?php echo htmlspecialchars($row['status']); ?></li>
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
                        <li><a href="/?page=invoice/invoice-details&id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars(pa_invoice_label_from_row($row)); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
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
      <?php else: ?>
        <div style="color:var(--muted)">No projects for this client yet.</div>
      <?php endif; ?>
    <?php else: ?>
      <div style="color:var(--muted)">Select a client on the left to view related projects and documents.</div>
    <?php endif; ?>
  </div>
</section>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/client-list-live-filter.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
