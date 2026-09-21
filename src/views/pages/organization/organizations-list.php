<?php
// src/views/pages/organization/organizations-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus = api_v2_directory_management_status($pdo);
// TODO: We need to add a way for the user to upload a tax exempt form for an org. This will be a file upload, and we will keep up to two files per org. This way if they update theirs, we will also have the old one to reference.
// Fetch all organizations with client counts
$search = trim($_GET['search'] ?? '');
$where = [];
$params = [];
if ($search !== '') {
  $where[] = 'o.name LIKE ?';
  $params[] = '%'.$search.'%';
}

$whereClause = '';
if (!empty($where)) {
  $whereClause = 'WHERE ' . implode(' AND ', $where);
}

$sql = "SELECT o.id, o.name, o.notes, o.created_at, COUNT(c.id) as client_count 
        FROM organizations o
        LEFT JOIN clients c ON c.organization_id = o.id AND c.archived = 0
        $whereClause
        GROUP BY o.id
        ORDER BY o.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$organizations = $stmt->fetchAll();
?>
<section>
  <h2>Organizations</h2>
  <?php if($directoryManagementStatus['configured']):?><div style="margin:10px 0;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;font-size:13px"><?php echo htmlspecialchars(api_v2_directory_management_warning($directoryManagementStatus)); ?></div><?php endif;?>
  <div style="margin:8px 0">
    <?php if(!$directoryManagementStatus['effective']):?>
    <a href="/?page=organization/organizations-create" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none">Create Organization</a>
    <?php endif;?>
  </div>
  
  <?php
  $filterConfig = [
      'page' => 'organization/organizations-list',
      'filters' => [
          'search' => [
              'type' => 'text',
              'label' => 'Search Organizations',
              'value' => $search,
              'placeholder' => 'Type to search...'
          ]
      ],
      'primary_count' => 1,
      'live_filter_id' => 'organization-list',
      'live_filter_fields' => ['search'],
      'live_filter_debounce' => 300,
      'live_filter_help' => 'Results update automatically as you type.'
  ];
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>
  
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Organization created.</div>
  <?php elseif (!empty($_GET['updated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Organization updated.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div class="alert alert-danger">Organization deleted.</div>
  <?php endif; ?>
  
  <div style="overflow:auto;margin-top:16px">
    <table class="pa-table">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Name</th>
          <th style="padding:10px">Clients</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($organizations)): ?>
          <tr>
            <td colspan="4" style="padding:20px;text-align:center;color:var(--muted)">No organizations found. Create one to get started.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($organizations as $org): ?>
            <tr style="border-top:1px solid #f3f4f6">
              <td style="padding:10px">
                <a href="/?page=organization/organization-view&id=<?php echo (int)$org['id']; ?>" style="text-decoration:none;color:inherit;font-weight:600">
                  <?php echo htmlspecialchars($org['name']); ?>
                </a>
              </td>
              <td style="padding:10px"><?php echo (int)$org['client_count']; ?> client<?php echo $org['client_count'] != 1 ? 's' : ''; ?></td>
              <td style="padding:10px"><?php echo htmlspecialchars(date('M j, Y', strtotime($org['created_at']))); ?></td>
              <td style="padding:10px">
                <a href="/?page=organization/organization-view&id=<?php echo (int)$org['id']; ?>" class="btn btn-sm">View</a>
                <?php if(!$directoryManagementStatus['effective']):?><a href="/?page=organization/organizations-edit&id=<?php echo (int)$org['id']; ?>" class="btn btn-sm">Edit</a><?php endif;?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/client-list-live-filter.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
