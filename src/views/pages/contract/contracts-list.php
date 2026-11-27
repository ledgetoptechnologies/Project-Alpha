<?php
// src/views/pages/contract/contracts-list.php
// One list for every contract kind (one-time, recurring, as-needed).
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/contract_billing_start.php';
require_once __DIR__ . '/../../components/document_list.php';

$page = 'contract/contracts-list';
$kind = doc_list_kind();
$statuses = ['all' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'void' => 'Void'];
$status = (string)($_GET['status'] ?? 'all');
if (!isset($statuses[$status]) && !in_array($status, ['draft', 'closing', 'denied'], true)) {
    $status = 'all';
}

$where = [];
$params = [];
if ($cond = doc_list_kind_condition($kind, 'co.contract_type')) {
    $where[] = $cond;
}
if ($status !== 'all') {
    $where[] = 'co.status = ?';
    $params[] = $status;
}
doc_list_common_filters('co', $where, $params);
doc_list_amount_filter("(CASE WHEN co.contract_type = 'on_demand' THEN co.price_per_invoice ELSE co.total END)", $where, $params);
[$scopeWhere, $scopeParams] = scope_clause($pdo, 'co', (int)$_SESSION['user']['id']);
if ($scopeWhere !== '') {
    $where[] = $scopeWhere;
    $params = array_merge($params, $scopeParams);
}
[$per, $pageN, $offset] = doc_list_paging();

$from = ' FROM contracts co LEFT JOIN clients c ON c.id=co.client_id' . pa_document_effective_organization_joins('co', 'c')
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$count = $pdo->prepare('SELECT COUNT(*)' . $from);
$count->execute($params);
$total = (int)$count->fetchColumn();

// Recurring-only lookups sit inside CASE so other kinds skip the subqueries.
$latestInvoice = 'FROM invoices i WHERE i.contract_id=co.id AND i.invoice_type="long_term" ORDER BY i.created_at DESC, i.id DESC LIMIT 1';
$st = $pdo->prepare("SELECT co.id, co.doc_number, co.project_id, co.project_code, co.contract_type, co.status, co.total, co.subtotal,
        co.deposit_type, co.deposit_amount, co.deposit_paid, co.start_date, co.end_date, co.billing_interval_count, co.billing_interval_unit,
        co.pricing_type, co.price_per_invoice, co.total_invoiced, co.next_invoice_date, co.last_invoice_date, co.invoices_generated,
        co.invoice_count, co.signed_at, co.signed_pdf_path, co.created_at,
        c.id AS client_id, c.name AS client_name, o.name AS organization_name,
        document_project.invoice_billing_period AS project_invoice_billing_period,
        CASE WHEN co.contract_type='long_term' THEN (SELECT i.id {$latestInvoice}) END AS latest_invoice_id,
        CASE WHEN co.contract_type='long_term' THEN (SELECT i.sent_at {$latestInvoice}) END AS latest_invoice_sent_at,
        CASE WHEN co.contract_type='long_term' THEN (SELECT COUNT(*) FROM contract_recurring_services rs WHERE rs.contract_id=co.id AND rs.status<>\"ended\") END AS recurring_service_count,
        CASE WHEN co.contract_type='long_term' THEN (SELECT COALESCE(SUM(rs.amount),0) FROM contract_recurring_services rs WHERE rs.contract_id=co.id AND rs.status IN (\"active\",\"paused\") AND rs.approval_status=\"approved\" AND rs.next_invoice_date=co.next_invoice_date) END AS next_service_amount"
    . $from . " ORDER BY co.created_at DESC LIMIT {$per} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$messages = [];
$banners = [
    'created' => ['ok', 'Contract created.'],
    'signed' => ['ok', $kind === 'as_needed' ? 'Signed PDF uploaded. Review the contract, then activate it when ready.' : 'Signed PDF uploaded.'],
    'activated' => ['ok', 'Contract activated.'],
    'billing_started' => ['ok', 'Billing started for the recurring contract.'],
    'deposit_received' => ['ok', 'Deposit marked as received.'],
    'completed' => ['ok', 'Contract completed.'],
    'paused' => ['info', 'Contract paused.'],
    'resumed' => ['ok', 'Contract resumed.'],
    'terminated' => ['info', 'Contract terminated. Future invoicing has stopped.'],
    'denied' => ['info', 'Contract denied.'],
    'emailed' => ['ok', 'Email sent.'],
];
foreach ($banners as $key => $banner) {
    if (!empty($_GET[$key])) {
        $messages[] = $banner;
    }
}
if (!empty($_GET['email_err'])) {
    $messages[] = ['bad', 'Email failed: ' . $_GET['email_err']];
}
if (!empty($_GET['error'])) {
    $messages[] = ['bad', (string)$_GET['error']];
}

$userId = (int)$_SESSION['user']['id'];
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$canCreate = $isAdmin || user_can($pdo, $userId, 'contracts.create', 0);

$prefix = ['one_time' => 'C', 'recurring' => 'LTC', 'as_needed' => 'ODC'];
$today = date('Y-m-d');
$hasAsNeeded = false;
?>
<section class="doc-list">
  <?php doc_list_render_top([
      'title' => 'Contracts',
      'page' => $page,
      'new_url' => $canCreate ? '/?page=contract/contracts-create' : null,
      'new_label' => '+ New contract',
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
            $k = doc_list_kind_of($r['contract_type']);
            $id = (int)$r['id'];
            $rowStatus = (string)$r['status'];
            $number = $prefix[$k] . '-' . (int)($r['doc_number'] ?: $r['id']);
            $detailsPage = $k === 'recurring' ? 'contract/long-term-contract-details' : 'contract/contract-details';
            $hasSignedPdf = !empty($r['signed_pdf_path']);
            $editable = in_array($rowStatus, ['draft', 'pending'], true) && !$hasSignedPdf;
            $route = $k === 'recurring' ? 'long-term-contract' : 'on-demand-contract';
            $kindSub = [];
            if ($k === 'recurring') {
                $n = (int)$r['billing_interval_count'];
                $unit = strtolower((string)$r['billing_interval_unit']);
                $kindSub[] = (int)($r['recurring_service_count'] ?? 0) > 1
                    ? (int)$r['recurring_service_count'] . ' service schedules'
                    : 'every ' . ($n > 1 ? $n . ' ' . $unit . 's' : $unit);
                if (!empty($r['next_invoice_date'])) {
                    $kindSub[] = 'Next ' . date('M j, Y', strtotime((string)$r['next_invoice_date']));
                } elseif ($rowStatus === 'active') {
                    $kindSub[] = 'Manual start';
                }
                if ($r['pricing_type'] === 'per_invoice') {
                    $nextAmount = (float)($r['next_service_amount'] ?? 0);
                    $amount = '$' . number_format($nextAmount > 0 ? $nextAmount : (float)$r['price_per_invoice'], 2) . ' next';
                } else {
                    $amount = '$' . number_format((float)$r['total'], 2) . ' total';
                }
                $latestInvoiceId = (int)($r['latest_invoice_id'] ?? 0);
            } elseif ($k === 'as_needed') {
                $hasAsNeeded = true;
                $kindSub[] = 'Manual invoices';
                $invoiceCount = (int)$r['invoice_count'];
                $kindSub[] = $invoiceCount . ($invoiceCount === 1 ? ' invoice' : ' invoices') . ' ($' . number_format((float)$r['total_invoiced'], 2) . ')'
                    . (!empty($r['last_invoice_date']) ? ', last ' . date('M j, Y', strtotime((string)$r['last_invoice_date'])) : '');
                $price = (float)($r['price_per_invoice'] ?? 0) > 0 ? (float)$r['price_per_invoice'] : (float)($r['subtotal'] ?? 0);
                $amount = '$' . number_format($price, 2) . ' / invoice';
                $canGenerate = $rowStatus === 'active' && (empty($r['end_date']) || (string)$r['end_date'] >= $today);
                $monthlyProjectBilling = !empty($r['project_id']) && ($r['project_invoice_billing_period'] ?? '') === 'monthly';
            } else {
                $amount = '$' . number_format((float)$r['total'], 2);
                $depositDue = (float)($r['deposit_amount'] ?? 0);
                $needsDeposit = ($r['deposit_type'] ?? 'none') !== 'none' && $depositDue > 0 && (float)($r['deposit_paid'] ?? 0) < $depositDue;
            }
        ?>
          <tr>
            <td><a href="/?page=<?php echo $detailsPage; ?>&amp;id=<?php echo $id; ?>"><strong><?php echo doc_list_e($number); ?></strong></a></td>
            <td><?php echo doc_list_customer_cell($r); ?></td>
            <td class="doc-kind"><?php echo doc_list_e(DOC_LIST_KINDS[$k]); ?><?php foreach ($kindSub as $line): ?><span class="doc-sub"><?php echo doc_list_e($line); ?></span><?php endforeach; ?></td>
            <td><?php echo doc_list_status_pill($rowStatus, $statuses[$rowStatus] ?? null); ?></td>
            <td class="doc-amount"><?php echo doc_list_e($amount); ?></td>
            <td><?php echo $r['created_at'] ? doc_list_e(date('M j, Y', strtotime((string)$r['created_at']))) : ''; ?></td>
            <td>
              <div class="doc-actions">
                <?php if ($editable && $k !== 'as_needed'): ?>
                  <a class="btn btn-sm" href="/?page=contract/contracts-edit&amp;id=<?php echo $id; ?>"><?php echo $k === 'recurring' ? 'Edit Billing' : 'Edit'; ?></a>
                <?php endif; ?>
                <?php if (!in_array($rowStatus, ['denied', 'cancelled', 'void'], true)) echo doc_list_email_button('contract', $id, $k === 'as_needed' ? ['generation_key' => bin2hex(random_bytes(16))] : []); ?>
                <?php if ($k !== 'one_time'): ?>
                  <a class="btn btn-sm" href="/?page=invoice/invoices-list&amp;kind=<?php echo $k; ?>&amp;contract_id=<?php echo $id; ?>">Invoices</a>
                <?php endif; ?>
                <?php if (in_array($rowStatus, ['draft', 'pending'], true) && empty($r['signed_at']) && empty($r['signed_pdf_path'])): ?>
                  <form method="post" action="/?page=contract/contract-sign" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo doc_list_e(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input id="upload-<?php echo $id; ?>" type="file" name="signed_pdf" accept="application/pdf,.pdf" hidden data-submit-on-file required>
                    <button type="button" class="btn btn-sm" data-file-picker-target="upload-<?php echo $id; ?>">Upload</button>
                  </form>
                <?php endif; ?>
                <?php if ($hasSignedPdf): ?>
                  <a class="btn btn-sm" href="<?php echo doc_list_e((string)$r['signed_pdf_path']); ?>" target="_blank" rel="noopener">Signed PDF</a>
                <?php endif; ?>
                <?php if ($k === 'one_time'): ?>
                  <?php if ($needsDeposit && $rowStatus === 'pending') echo doc_list_post_button('contract/contract-deposit-received', 'Deposit Received', ['id' => $id], 'Mark deposit as received ($' . number_format($depositDue, 2) . ')?', 'btn btn-sm btn-success'); ?>
                  <?php if ($rowStatus === 'active') echo doc_list_post_button('contract/contract-complete', 'Complete', ['id' => $id], 'Complete this contract, finalize its invoice, and send it when automatic delivery is enabled?', 'btn btn-sm btn-success'); ?>
                  <?php if (!in_array($rowStatus, ['cancelled', 'completed', 'void'], true)) echo doc_list_post_button('contract/contract-void', 'Void', ['id' => $id], 'Void this contract and linked invoices?', 'btn btn-sm btn-muted'); ?>
                <?php else: ?>
                  <?php if ($rowStatus === 'pending' && $hasSignedPdf): ?>
                    <?php echo doc_list_post_button($route . '-activate', 'Activate', ['id' => $id], null, 'btn btn-sm btn-success'); ?>
                  <?php elseif ($rowStatus === 'pending'): ?>
                    <button type="button" class="btn btn-sm" disabled title="Upload signed contract first">Activate</button>
                  <?php endif; ?>
                  <?php if ($k === 'recurring'): ?>
                    <?php if ($rowStatus !== 'pending' && pa_long_term_can_start_billing($pdo, $r)) echo doc_list_post_button('long-term-contract-start-billing', 'Start Billing', ['id' => $id], null, 'btn btn-sm btn-primary'); ?>
                    <?php if ($latestInvoiceId > 0 && empty($r['latest_invoice_sent_at'])): ?>
                      <?php echo doc_list_post_button('invoice/email-send', 'Email Latest Invoice', ['type' => 'invoice', 'id' => $latestInvoiceId, 'redirect_to' => '/?page=invoice/invoice-details&id=' . $latestInvoiceId]); ?>
                    <?php elseif ($latestInvoiceId > 0): ?>
                      <a class="btn btn-sm" href="/?page=invoice/invoice-details&amp;id=<?php echo $latestInvoiceId; ?>">Latest Invoice</a>
                    <?php endif; ?>
                  <?php elseif ($canGenerate): ?>
                    <form method="post" action="/?page=on-demand-invoice-generate" class="od-generate-form" data-monthly-project-billing="<?php echo $monthlyProjectBilling ? '1' : '0'; ?>">
                      <input type="hidden" name="csrf" value="<?php echo doc_list_e(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo $id; ?>">
                      <input type="hidden" name="generation_key" value="<?php echo bin2hex(random_bytes(16)); ?>">
                      <input type="hidden" name="send_email" value="0">
                      <button type="button" class="btn btn-sm btn-primary" onclick="openOdGenerateModal(this.form)">Generate Invoice</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($rowStatus === 'active') echo doc_list_post_button($route . '-pause', 'Pause', ['id' => $id], null, 'btn btn-sm btn-warning'); ?>
                  <?php if ($rowStatus === 'paused') echo doc_list_post_button($route . '-resume', 'Resume', ['id' => $id], null, 'btn btn-sm btn-success'); ?>
                  <?php if ($k === 'recurring' && in_array($rowStatus, ['pending', 'active', 'paused'], true)) echo doc_list_post_button('long-term-contract-terminate', 'Terminate', ['id' => $id], 'Terminate this long-term contract? This will stop future invoicing.', 'btn btn-sm btn-danger'); ?>
                  <?php if ($k === 'as_needed' && $editable) echo doc_list_post_button('on-demand-contract-terminate', 'Terminate', ['id' => $id], 'Terminate this on-demand contract?', 'btn btn-sm btn-danger'); ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="doc-empty">No contracts match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php doc_list_render_pager($page, $total, $per, $pageN); ?>
  <?php if ($hasAsNeeded): ?>
    <dialog id="odGenerateModal" aria-labelledby="odGenerateTitle">
      <h3 id="odGenerateTitle">Generate On-Demand Invoice</h3>
      <p id="odGenerateDescription" class="doc-sub">Choose whether to send the invoice now or generate it for review and edits first.</p>
      <div class="doc-actions">
        <button type="button" class="btn btn-muted" onclick="closeOdGenerateModal()">Cancel</button>
        <button id="odGenerateDraft" type="button" class="btn" onclick="submitOdGenerate(false)">Generate Only</button>
        <button id="odGeneratePrimary" type="button" class="btn btn-primary" onclick="submitOdGenerate(true)">Generate and Send Email</button>
      </div>
    </dialog>
    <script>
      var odGenerateForm = null;
      function openOdGenerateModal(form) {
        odGenerateForm = form;
        var monthly = form.dataset.monthlyProjectBilling === '1';
        document.getElementById('odGenerateDescription').textContent = monthly
          ? 'Finalize this charge for the monthly project statement, or save a draft for review. Monthly charges are not emailed separately.'
          : 'Choose whether to send the invoice now or generate it for review and edits first.';
        document.getElementById('odGeneratePrimary').textContent = monthly ? 'Finalize for Project Billing' : 'Generate and Send Email';
        document.getElementById('odGenerateDraft').textContent = monthly ? 'Save Draft for Review' : 'Generate Only';
        document.getElementById('odGenerateModal').showModal();
      }
      function closeOdGenerateModal() {
        odGenerateForm = null;
        document.getElementById('odGenerateModal').close();
      }
      function submitOdGenerate(sendEmail) {
        if (!odGenerateForm) return;
        odGenerateForm.querySelector('input[name="send_email"]').value = sendEmail ? '1' : '0';
        odGenerateForm.submit();
      }
      document.getElementById('odGenerateModal').addEventListener('click', function (e) {
        if (e.target === this) closeOdGenerateModal();
      });
    </script>
  <?php endif; ?>
</section>
