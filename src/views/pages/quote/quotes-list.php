<?php
// src/views/pages/quote/quotes-list.php
// One list for every quote kind (one-time, recurring, as-needed).
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../components/document_list.php';

$page = 'quote/quotes-list';
$kind = doc_list_kind();
$statuses = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Denied'];
$status = (string)($_GET['status'] ?? 'all');
if (!isset($statuses[$status])) {
    $status = 'all';
}

$where = [];
$params = [];
if ($cond = doc_list_kind_condition($kind, 'q.quote_type')) {
    $where[] = $cond;
}
if ($status !== 'all') {
    $where[] = 'q.status = ?';
    $params[] = $status;
}
doc_list_common_filters('q', $where, $params);
doc_list_amount_filter("(CASE WHEN q.quote_type = 'on_demand' THEN q.price_per_invoice ELSE q.total END)", $where, $params);
[$scopeWhere, $scopeParams] = scope_clause($pdo, 'q', (int)$_SESSION['user']['id']);
if ($scopeWhere !== '') {
    $where[] = $scopeWhere;
    $params = array_merge($params, $scopeParams);
}
[$per, $pageN, $offset] = doc_list_paging();

$from = ' FROM quotes q JOIN clients c ON c.id = q.client_id' . pa_document_effective_organization_joins('q', 'c')
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$count = $pdo->prepare('SELECT COUNT(*)' . $from);
$count->execute($params);
$total = (int)$count->fetchColumn();

$st = $pdo->prepare("SELECT q.id, q.doc_number, q.project_code, q.quote_type, q.status, q.total, q.price_per_invoice,
        q.billing_interval_count, q.billing_interval_unit, q.start_date, q.created_at,
        c.id AS client_id, c.name AS client_name, o.name AS organization_name"
    . $from . " ORDER BY q.created_at DESC LIMIT {$per} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$messages = [];
$flash = $_SESSION['flash_quote_approve'] ?? null;
unset($_SESSION['flash_quote_approve']);
if ($flash) {
    $messages[] = ['info', (string)$flash['message']];
}
if (!empty($_GET['created'])) {
    $messages[] = ['ok', 'Quote created.'];
}
if (!empty($_GET['emailed'])) {
    $messages[] = ['ok', 'Email sent.'];
}
if (!empty($_GET['email_err'])) {
    $messages[] = ['bad', 'Email failed: ' . $_GET['email_err']];
}
if (!empty($_GET['error'])) {
    $messages[] = ['bad', (string)$_GET['error']];
}

$userId = (int)$_SESSION['user']['id'];
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$canCreate = $isAdmin || user_can($pdo, $userId, 'quotes.create', 0);

$prefix = ['one_time' => 'Q', 'recurring' => 'LQ', 'as_needed' => 'ODQ'];
$approveConfirm = [
    'one_time' => 'Approve this quote and generate contract + invoice?',
    'recurring' => 'Approve this recurring quote and generate its contract?',
    'as_needed' => 'Approve this as-needed quote and generate its contract?',
];
?>
<section class="doc-list">
  <?php doc_list_render_top([
      'title' => 'Quotes',
      'page' => $page,
      'new_url' => $canCreate ? '/?page=quote/quotes-create' : null,
      'new_label' => '+ New quote',
      'statuses' => $statuses,
      'status' => $status,
      'messages' => $messages,
  ]); ?>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr><th>No.</th><th>Client</th><th>Kind</th><th>Status</th><th class="doc-amount">Amount</th><th>Created</th><th><span class="sr-only">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
            $k = doc_list_kind_of($r['quote_type']);
            $id = (int)$r['id'];
            $rowStatus = (string)$r['status'];
            $number = $prefix[$k] . '-' . (int)($r['doc_number'] ?: $r['id']);
            if ($k === 'as_needed') {
                $amount = '$' . number_format((float)$r['price_per_invoice'], 2) . ' / invoice';
            } elseif ($k === 'recurring' && (int)$r['billing_interval_count'] > 0) {
                $n = (int)$r['billing_interval_count'];
                $unit = (string)$r['billing_interval_unit'];
                $amount = '$' . number_format((float)$r['total'], 2) . '<span class="doc-sub">every ' . ($n > 1 ? $n . ' ' . doc_list_e($unit) . 's' : doc_list_e($unit)) . '</span>';
            } else {
                $amount = '$' . number_format((float)$r['total'], 2);
            }
        ?>
          <tr>
            <td><a href="/?page=quote/quote-details&amp;id=<?php echo $id; ?>"><strong><?php echo doc_list_e($number); ?></strong></a></td>
            <td><?php echo doc_list_customer_cell($r); ?></td>
            <td class="doc-kind"><?php echo doc_list_e(DOC_LIST_KINDS[$k]); ?><?php if ($k !== 'one_time' && !empty($r['start_date'])): ?><span class="doc-sub">Starts <?php echo doc_list_e(date('M j, Y', strtotime((string)$r['start_date']))); ?></span><?php endif; ?></td>
            <td><?php echo doc_list_status_pill($rowStatus, $statuses[$rowStatus] ?? null); ?></td>
            <td class="doc-amount"><?php echo $amount; ?></td>
            <td><?php echo $r['created_at'] ? doc_list_e(date('M j, Y', strtotime((string)$r['created_at']))) : ''; ?></td>
            <td>
              <div class="doc-actions">
                <?php if ($k === 'recurring'): ?>
                  <a class="btn btn-sm" href="/?page=quote/long-term-quote-pdf&amp;id=<?php echo $id; ?>" target="_blank" rel="noopener">PDF</a>
                <?php endif; ?>
                <?php if (in_array($rowStatus, ['draft', 'pending'], true)): ?>
                  <a class="btn btn-sm" href="/?page=quote/quotes-edit&amp;id=<?php echo $id; ?>">Edit</a>
                <?php endif; ?>
                <?php if ($rowStatus !== 'rejected') echo doc_list_email_button('quote', $id); ?>
                <?php if ($rowStatus === 'pending'): ?>
                  <?php echo doc_list_post_button('quote/quote-approve', 'Approve', ['id' => $id], $approveConfirm[$k], 'btn btn-sm btn-success'); ?>
                  <?php echo doc_list_post_button('quote/quote-reject', 'Deny', ['id' => $id], 'Deny this quote?', 'btn btn-sm btn-danger'); ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="doc-empty">No quotes match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php doc_list_render_pager($page, $total, $per, $pageN); ?>
</section>
