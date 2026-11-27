<?php
// src/views/pages/invoice/invoices-list.php
// One list for every invoice kind (one-time, recurring, as-needed).
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../components/document_list.php';

$page = 'invoice/invoices-list';
$kind = doc_list_kind();
$statuses = ['all' => 'All', 'draft' => 'Draft', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void'];
$status = (string)($_GET['status'] ?? 'all');
if (!isset($statuses[$status])) {
    $status = 'all';
}
$contractId = max(0, (int)($_GET['contract_id'] ?? 0));

$where = [];
$params = [];
if ($cond = doc_list_kind_condition($kind, 'i.invoice_type')) {
    $where[] = $cond;
}
// Drafts are never receivables. Project-aggregate children age only through
// their project statement and never enter the direct-invoice overdue view.
$statusConditions = [
    'draft' => "i.status='draft'",
    'unpaid' => "i.status IN ('sent','unpaid','partial')",
    'overdue' => "i.status IN ('sent','unpaid','partial','overdue') AND i.collection_mode='direct' AND i.due_date IS NOT NULL AND i.due_date < CURDATE()",
    'paid' => "i.status='paid'",
    'void' => "i.status IN ('void','cancelled')",
];
if (isset($statusConditions[$status])) {
    $where[] = '(' . $statusConditions[$status] . ')';
}
if ($contractId > 0) {
    $where[] = 'i.contract_id = ?';
    $params[] = $contractId;
}
doc_list_common_filters('i', $where, $params);
doc_list_amount_filter('i.total', $where, $params);
[$scopeWhere, $scopeParams] = scope_clause($pdo, 'i', (int)$_SESSION['user']['id']);
if (trim($scopeWhere) !== '') {
    $where[] = trim($scopeWhere);
    $params = array_merge($params, $scopeParams);
}
[$per, $pageN, $offset] = doc_list_paging();

$from = ' FROM invoices i LEFT JOIN clients c ON c.id = i.client_id' . pa_document_effective_organization_joins('i', 'c')
    . ' LEFT JOIN contracts ct ON ct.id = i.contract_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$count = $pdo->prepare('SELECT COUNT(*)' . $from);
$count->execute($params);
$total = (int)$count->fetchColumn();

$st = $pdo->prepare("SELECT i.id, i.doc_number, i.invoice_type, i.project_code, i.status, i.collection_mode, i.total,
        i.amount_paid, i.balance_due, i.due_date, i.created_at, i.contract_id,
        ct.doc_number AS contract_doc_number, ct.contract_type,
        c.id AS client_id, c.name AS client_name, o.name AS organization_name"
    . $from . " ORDER BY i.created_at DESC, i.id DESC LIMIT {$per} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Contract number and details page for a contract id and type.
$contractLinkFor = static function (int $id, ?string $type, $docNumber): array {
    $prefix = match ($type) {
        'long_term' => 'LTC',
        'on_demand' => 'ODC',
        default => 'C',
    };
    $detailsPage = $type === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
    return [$prefix . '-' . (int)($docNumber ?: $id), '/?page=' . $detailsPage . '&id=' . $id];
};

$contractBanner = null;
if ($contractId > 0) {
    $contractStmt = $pdo->prepare('SELECT doc_number, contract_type FROM contracts WHERE id = ?');
    $contractStmt->execute([$contractId]);
    $contractRow = $contractStmt->fetch(PDO::FETCH_ASSOC);
    if ($contractRow) {
        $contractBanner = $contractLinkFor($contractId, $contractRow['contract_type'], $contractRow['doc_number']);
    }
}

$messages = [];
if (!empty($_GET['created'])) {
    $messages[] = ['ok', 'Invoice created.'];
}
if (!empty($_GET['invoice_generated'])) {
    $messages[] = ['ok', !empty($_GET['idempotent']) ? 'This invoice was already generated; no duplicate was created.' : 'Invoice generated.'];
}
if (!empty($_GET['email_requested'])) {
    $messages[] = ['info', 'Invoice email requested. Failed deliveries are retried automatically.'];
}
if (!empty($_GET['project_billing'])) {
    $messages[] = ['ok', 'Invoice finalized for monthly project billing. It will be included in the next eligible Project Invoice and will not be emailed separately.'];
}
if (!empty($_GET['emailed'])) {
    $messages[] = ['ok', 'Email sent.'];
}
if (!empty($_GET['email_err'])) {
    $messages[] = ['bad', 'Email failed: ' . $_GET['email_err']];
}
if (!empty($_GET['voided'])) {
    $messages[] = ['info', 'Invoice voided. It remains available under the Void filter for audit history.'];
}
if (!empty($_GET['error'])) {
    $messages[] = ['bad', (string)$_GET['error']];
}

$userId = (int)$_SESSION['user']['id'];
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$canCreate = $isAdmin || user_can($pdo, $userId, 'invoices.create', 0);
$returnTo = (string)($_SERVER['REQUEST_URI'] ?? '/?page=invoice/invoices-list');
?>
<section class="doc-list">
  <?php doc_list_render_top([
      'title' => 'Invoices',
      'page' => $page,
      'new_url' => $canCreate ? '/?page=invoice/invoices-create' : null,
      'new_label' => '+ New invoice',
      'statuses' => $statuses,
      'status' => $status,
      'messages' => $messages,
  ]); ?>
  <?php if ($contractId > 0): ?>
    <div class="doc-flash doc-flash--info" role="status">
      Showing invoices for contract <?php if ($contractBanner): ?><a href="<?php echo doc_list_e($contractBanner[1]); ?>"><strong><?php echo doc_list_e($contractBanner[0]); ?></strong></a><?php else: ?>#<?php echo $contractId; ?><?php endif; ?>.
      <a href="<?php echo doc_list_e(doc_list_url($page, ['contract_id' => null, 'p' => null])); ?>">Show all</a>
    </div>
  <?php endif; ?>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr><th>No.</th><th>Client</th><th>Kind</th><th>Status</th><th class="doc-amount">Amount</th><th>Due</th><th><span class="sr-only">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
            $k = doc_list_kind_of($r['invoice_type']);
            $id = (int)$r['id'];
            $rowStatus = strtolower((string)$r['status']);
            $label = pa_invoice_label_from_row($r);
            $collectible = in_array($rowStatus, ['sent', 'unpaid', 'partial', 'overdue'], true) && ($r['collection_mode'] ?? 'direct') === 'direct';
            $contractLink = (int)$r['contract_id'] > 0 && $r['contract_type'] !== null
                ? $contractLinkFor((int)$r['contract_id'], $r['contract_type'], $r['contract_doc_number'])
                : null;
            $amount = '$' . number_format((float)$r['total'], 2);
            if ($k === 'recurring') {
                $paid = (float)$r['amount_paid'];
                $balance = in_array($rowStatus, ['paid', 'cancelled', 'void'], true)
                    ? max(0.0, (float)$r['balance_due'])
                    : max(0.0, (float)$r['total'] - $paid);
                $amount .= '<span class="doc-sub">Paid $' . number_format($paid, 2) . ' · Balance $' . number_format($balance, 2) . '</span>';
            }
            $voidDialogId = 'voidInvoiceDialog' . $id;
        ?>
          <tr>
            <td><a href="/?page=invoice/invoice-details&amp;id=<?php echo $id; ?>"><strong><?php echo doc_list_e($label); ?></strong></a></td>
            <td><?php echo doc_list_customer_cell($r); ?></td>
            <td class="doc-kind"><?php echo doc_list_e(DOC_LIST_KINDS[$k]); ?><?php if ($contractLink): ?><span class="doc-sub">from <a href="<?php echo doc_list_e($contractLink[1]); ?>"><?php echo doc_list_e($contractLink[0]); ?></a></span><?php endif; ?></td>
            <td><?php echo invoice_is_past_due($r) ? doc_list_status_pill('overdue', 'Overdue') : doc_list_status_pill($rowStatus); ?></td>
            <td class="doc-amount"><?php echo $amount; ?></td>
            <td><?php echo !empty($r['due_date']) ? doc_list_e(date('M j, Y', strtotime((string)$r['due_date']))) : ''; ?></td>
            <td>
              <div class="doc-actions">
                <a class="btn btn-sm" href="/?page=invoice/invoice-details&amp;id=<?php echo $id; ?>">View</a>
                <?php if (!in_array($rowStatus, ['void', 'cancelled'], true)): ?>
                  <a class="btn btn-sm" href="/?page=invoice/invoices-edit&amp;id=<?php echo $id; ?>">Edit</a>
                <?php endif; ?>
                <?php if ($rowStatus === 'draft' && ($r['collection_mode'] ?? 'direct') === 'project_aggregate'): ?>
                  <?php echo doc_list_post_button('invoice/invoice-finalize', 'Finalize for Project Billing', ['id' => $id], 'Finalize this invoice for the monthly project statement? It will not be emailed separately.', 'btn btn-sm btn-success'); ?>
                <?php endif; ?>
                <?php if ($collectible): ?>
                  <?php echo doc_list_email_button('invoice', $id); ?>
                  <?php echo doc_list_post_button('invoice/invoices-mark-paid', 'Paid', ['id' => $id], 'Mark invoice paid?', 'btn btn-sm btn-success'); ?>
                <?php endif; ?>
                <?php if (in_array($rowStatus, ['draft', 'sent', 'unpaid', 'overdue'], true)): ?>
                  <button type="button" class="btn btn-sm btn-danger" onclick="document.getElementById('<?php echo $voidDialogId; ?>').showModal()">Void</button>
                  <dialog id="<?php echo $voidDialogId; ?>" class="invoice-void-dialog">
                    <form method="post" action="/?page=invoice/invoice-void" onsubmit="return confirm(<?php echo doc_list_e(json_encode('Void invoice ' . $label . '? It will remain in audit history and cannot be paid.')); ?>)">
                      <input type="hidden" name="csrf" value="<?php echo doc_list_e(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo $id; ?>">
                      <input type="hidden" name="redirect_to" value="<?php echo doc_list_e($returnTo); ?>">
                      <div><h3>Void invoice <?php echo doc_list_e($label); ?></h3><p>The invoice stays in audit history and all payment links are revoked.</p></div>
                      <label>Reason<textarea name="reason" maxlength="500" required rows="4" placeholder="Example: Accidental duplicate invoice"></textarea></label>
                      <p>Pending or economically active payments must be resolved first. Fully refunded zero-balance history will not block voiding.</p>
                      <div class="doc-actions"><button type="button" class="btn btn-sm" onclick="document.getElementById('<?php echo $voidDialogId; ?>').close()">Cancel</button><button type="submit" class="btn btn-sm btn-danger">Confirm Void Invoice</button></div>
                    </form>
                  </dialog>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="doc-empty">No invoices match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php doc_list_render_pager($page, $total, $per, $pageN); ?>
</section>
