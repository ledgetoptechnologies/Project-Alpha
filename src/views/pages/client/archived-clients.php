<?php
// src/views/pages/archived-clients.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus=api_v2_directory_management_status($pdo);

$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, [50, 100], true)) $per = 50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;
$q = trim($_GET['q'] ?? '');
$params=[]; $where='';
if ($q !== '') { $where = 'WHERE ac.name LIKE ?'; $params[] = '%'.$q.'%'; }

$stc = $pdo->prepare('SELECT COUNT(*) FROM archived_clients ac '.($where));
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "SELECT ac.id, ac.client_id, ac.name, ac.email, ac.phone, org.name AS organization, ac.archived_at FROM archived_clients ac LEFT JOIN organizations org ON ac.organization_id = org.id ".($where)." ORDER BY ac.archived_at DESC LIMIT $per OFFSET $offset";
$params = [];
$where = '';

if ($q !== '') {
  $where = 'WHERE name LIKE ?';
  $params[] = '%' . $q . '%';
}

$stc = $pdo->prepare('SELECT COUNT(*) FROM archived_clients ' . ($where));
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "SELECT ac.id, ac.client_id, ac.name, ac.email, ac.phone, org.name AS organization, ac.archived_at
        FROM archived_clients ac
        LEFT JOIN organizations org ON ac.organization_id = org.id
        " . ($where ? str_replace('WHERE name LIKE ?', 'WHERE ac.name LIKE ?', $where) : '') . "
        ORDER BY ac.archived_at DESC LIMIT $per OFFSET $offset";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function archived_client_email_html(?string $email): string
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
  <h2>Archived Clients</h2>
  <form method="get" action="/" style="display:flex;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="client/archived-clients">
    <label>
      <div>Search by name</div>
      <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="e.g., Acme" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=client/archived-clients" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Reset</a>
  </form>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Client ID</th>
          <th style="padding:10px">Name</th>
          <th style="padding:10px">Email</th>
          <th style="padding:10px">Phone</th>
          <th style="padding:10px">Organization</th>
          <th style="padding:10px">Archived</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px">#<?php echo (int)$r['client_id']; ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['name']); ?></td>
            <td style="padding:10px"><?php echo archived_client_email_html($r['email'] ?? null); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['phone'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['organization'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['archived_at']); ?></td>
          </tr>
          <tr>
            <td colspan="6" style="padding:10px">
              <?php if(!$directoryManagementStatus['effective']):?><form method="post" action="/?page=client/clients-restore" onsubmit="return confirm('Restore client <?php echo e($r['name']); ?> to active list?');" style="display:inline-block">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Restore</button>
              </form>
              <?php else:?><span class="api-key-badge">Managed externally</span><?php endif;?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php $last = (int)ceil(max(1, $total) / $per);
  $qs = $_GET;
  unset($qs['p']);
  $base = '/?' . http_build_query($qs + ['page' => 'client/archived-clients', 'per_page' => $per]); ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach ($_GET as $k => $v) {
          if ($k === 'per_page' || $k === 'p' || $k === 'page') continue;
          echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }
        ?>
        <input type="hidden" name="page" value="client/archived-clients">
        <label>Per page
          <select name="per_page" onchange="this.form.submit()" style="padding:6px;border-radius:8px;border:1px solid #ddd">
            <option value="50" <?php echo $per === 50 ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo $per === 100 ? 'selected' : ''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div style="display:flex;gap:8px">
      <?php if ($pageN > 1): ?><a href="<?php echo $base . '&p=' . ($pageN - 1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Prev</a><?php endif; ?>
      <div style="padding:6px 10px;color:var(--muted)">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if ($pageN < $last): ?><a href="<?php echo $base . '&p=' . ($pageN + 1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Next</a><?php endif; ?>
    </div>
  </div>
</section>
