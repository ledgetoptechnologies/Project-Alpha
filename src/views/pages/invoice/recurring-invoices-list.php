<?php
// src/views/pages/invoice/recurring-invoices-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/document_organization.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';



$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$status = $_GET['status'] ?? 'active';
$invoice_status = strtolower(trim((string)($_GET['invoice_status'] ?? 'all')));
$allowedInvoiceStatuses = ['all', 'draft', 'sent', 'unpaid', 'partial', 'paid', 'overdue', 'cancelled', 'void'];
if (!in_array($invoice_status, $allowedInvoiceStatuses, true)) {
    $invoice_status = 'all';
}
$history_contract_id = max(0, (int)($_GET['contract_id'] ?? 0));
$history_page = max(1, (int)($_GET['history_page'] ?? 1));
$history_per_page = 25;
$project_code = trim($_GET['project_code'] ?? '');
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;

$where = ['ltc.contract_type="long_term"'];
$p = [];

if($client_id>0){$where[]='ltc.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='(c.name LIKE ? OR o.name LIKE ?)'; $p[]='%'.$client_name.'%'; $p[]='%'.$client_name.'%'; }
if ($status !== '' && $status !== 'all') {
    $where[] = 'ltc.status=?';
    $p[] = $status;
}
if($project_code!==''){ $where[]='ltc.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($min_price !== null){ $where[]='ltc.price_per_invoice >= ?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='ltc.price_per_invoice <= ?'; $p[] = $max_price; }
if($history_contract_id > 0){ $where[]='ltc.id=?'; $p[] = $history_contract_id; }

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'ltc', (int)$_SESSION['user']['id']);
if ($scopeWhere !== '') {
    $where[] = trim($scopeWhere);
    $p = array_merge($p, $scopeParams);
}

$sql = "SELECT ltc.id, ltc.doc_number, ltc.project_code, ltc.status, ltc.billing_interval_count, ltc.billing_interval_unit, ltc.pricing_type, ltc.price_per_invoice, ltc.total, ltc.total_invoiced, ltc.next_invoice_date, ltc.last_invoice_date, ltc.start_date, ltc.end_date, c.name client_name, c.id AS client_id, o.name AS organization_name,
               (SELECT COUNT(*) FROM invoices ih WHERE ih.contract_id=ltc.id AND ih.invoice_type=\"long_term\") AS invoice_history_count,
               (SELECT COUNT(*) FROM contract_recurring_services rs WHERE rs.contract_id=ltc.id AND rs.status<>\"ended\") AS recurring_service_count,
               (SELECT COALESCE(SUM(rs.amount),0) FROM contract_recurring_services rs WHERE rs.contract_id=ltc.id AND rs.status IN (\"active\",\"paused\") AND rs.approval_status=\"approved\" AND rs.next_invoice_date=ltc.next_invoice_date) AS next_service_amount
        FROM contracts ltc
        LEFT JOIN clients c ON c.id=ltc.client_id" . pa_document_effective_organization_joins('ltc', 'c');

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY ltc.next_invoice_date ASC, ltc.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Invoice history is intentionally independent of the contract-status filter:
// paid invoices remain visible after a schedule is paused or completed.
$historyWhere = ['i.invoice_type="long_term"'];
$historyParams = [];
if ($client_id > 0) { $historyWhere[] = 'i.client_id=?'; $historyParams[] = $client_id; }
elseif ($client_name !== '') { $historyWhere[] = '(c.name LIKE ? OR o.name LIKE ?)'; $historyParams[] = '%' . $client_name . '%'; $historyParams[] = '%' . $client_name . '%'; }
if ($project_code !== '') { $historyWhere[] = 'i.project_code LIKE ?'; $historyParams[] = $project_code . '%'; }
if ($history_contract_id > 0) { $historyWhere[] = 'i.contract_id=?'; $historyParams[] = $history_contract_id; }
if ($invoice_status !== 'all') { $historyWhere[] = 'i.status=?'; $historyParams[] = $invoice_status; }

[$historyScopeWhere, $historyScopeParams] = scope_clause($pdo, 'i', (int)$_SESSION['user']['id']);
if ($historyScopeWhere !== '') {
    $historyWhere[] = trim($historyScopeWhere);
    $historyParams = array_merge($historyParams, $historyScopeParams);
}

$historyFrom = ' FROM invoices i LEFT JOIN clients c ON c.id=i.client_id LEFT JOIN contracts ltc ON ltc.id=i.contract_id LEFT JOIN projects document_project ON document_project.id=i.project_id LEFT JOIN organizations o ON o.id=COALESCE(document_project.organization_id,i.organization_id,ltc.organization_id,c.organization_id)';
$historyWhereSql = ' WHERE ' . implode(' AND ', $historyWhere);
$historyCountStmt = $pdo->prepare('SELECT COUNT(*)' . $historyFrom . $historyWhereSql);
$historyCountStmt->execute($historyParams);
$historyTotal = (int)$historyCountStmt->fetchColumn();
$historyLastPage = max(1, (int)ceil($historyTotal / $history_per_page));
$history_page = min($history_page, $historyLastPage);
$historyOffset = ($history_page - 1) * $history_per_page;

$historySql = 'SELECT i.id,i.doc_number,i.contract_id,i.project_code,i.status,i.total,i.amount_paid,i.balance_due,i.due_date,i.sent_at,i.paid_at,i.created_at,
                      c.name AS client_name,c.id AS client_id,o.name AS organization_name,ltc.doc_number AS contract_doc_number'
    . $historyFrom . $historyWhereSql
    . ' ORDER BY i.created_at DESC,i.id DESC LIMIT ? OFFSET ?';
$historyStmt = $pdo->prepare($historySql);
$historyBindIndex = 1;
foreach ($historyParams as $historyParam) {
    $historyStmt->bindValue($historyBindIndex++, $historyParam);
}
$historyStmt->bindValue($historyBindIndex++, $history_per_page, PDO::PARAM_INT);
$historyStmt->bindValue($historyBindIndex, $historyOffset, PDO::PARAM_INT);
$historyStmt->execute();
$invoiceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section>
  <h2>Recurring Billing</h2>
  
  <!-- <div style="margin:16px 0;padding:12px;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px">
    <div style="font-weight:600;margin-bottom:4px;color:#92400e">Automatic Invoice Generation</div>
    <div style="font-size:14px;color:#78350f">Invoices are automatically generated based on the billing schedule below. Active contracts will create new invoices on their next invoice date.</div>
  </div> -->

  <?php
  $filterConfig = [
      'page' => 'invoice/recurring-invoices-list',
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
                  ['value' => 'all', 'label' => 'All'],
                  ['value' => 'active', 'label' => 'Active'],
                  ['value' => 'pending', 'label' => 'Pending'],
                  ['value' => 'paused', 'label' => 'Paused'],
                  ['value' => 'completed', 'label' => 'Completed'],
                  ['value' => 'cancelled', 'label' => 'Cancelled']
              ]
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
          ]
      ]
  ];
  
  // Render the filter using Twig template
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>

  <h3 style="margin:20px 0 10px;font-size:18px">Billing Schedules</h3>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Contract</th>
          <th style="padding:10px">Customer</th>
          <th style="padding:10px">Contact</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Billing</th>
          <th style="padding:10px">Amount/Invoice</th>
          <th style="padding:10px">Last Invoice</th>
          <th style="padding:10px">Next Invoice</th>
          <th style="padding:10px">Progress</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($contracts)): ?>
          <tr>
            <td colspan="10" style="padding:20px;text-align:center;color:#6b7280">No recurring billing contracts found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($contracts as $ltc): ?>
            <?php
              $billingInterval = $ltc['billing_interval_count'] . ' ' . ucfirst($ltc['billing_interval_unit']);
              if ($ltc['billing_interval_count'] > 1) $billingInterval .= 's';
              
              $amountText = '';
              if ($ltc['pricing_type'] === 'per_invoice') {
                $nextAmount = (float)($ltc['next_service_amount'] ?? 0);
                $amountText = '$' . number_format($nextAmount > 0 ? $nextAmount : (float)$ltc['price_per_invoice'], 2) . ' next';
              } else {
                $amountText = '$' . number_format((float)$ltc['total'], 2) . ' total';
              }
              
              $isOngoing = empty($ltc['end_date']);
              if ((int)($ltc['recurring_service_count'] ?? 0) > 1) {
                $billingInterval = (int)$ltc['recurring_service_count'] . ' service schedules';
              }
              $nextDate = $ltc['next_invoice_date'] ? date('M j, Y', strtotime($ltc['next_invoice_date'])) : '—';
              $lastDate = $ltc['last_invoice_date'] ? date('M j, Y', strtotime($ltc['last_invoice_date'])) : 'Not yet';
              
              // Calculate progress for fixed_total contracts
              $progressText = '—';
              if ($ltc['pricing_type'] === 'fixed_total' && !$isOngoing) {
                $total = (float)$ltc['total'];
                $invoiced = (float)$ltc['total_invoiced'];
                if ($total > 0) {
                  $percent = min(100, ($invoiced / $total) * 100);
                  $progressText = number_format($percent, 1) . '%';
                }
              }
              
              $rowStyle = '';
              if ($ltc['status'] === 'active') {
                $rowStyle = 'background:#fffbeb;';
              } elseif ($ltc['status'] === 'paused') {
                $rowStyle = 'background:#fef2f2;';
              } elseif ($ltc['status'] === 'completed') {
                $rowStyle = 'background:#ecfdf5;';
              }
            ?>
            <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
              <td style="padding:10px">
                <a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$ltc['id']; ?>" style="text-decoration:none;color:inherit;font-weight:600">
                  LTC-<?php echo (int)($ltc['doc_number'] ?? $ltc['id']); ?>
                </a>
                <div style="font-size:13px;color:#6b7280"><?php echo htmlspecialchars($ltc['project_code'] ?? ''); ?></div>
                <?php if (in_array((string)$ltc['status'], ['pending', 'active', 'paused'], true)): ?>
                  <a href="/?page=contract/contracts-edit&id=<?php echo (int)$ltc['id']; ?>" style="display:inline-block;margin-top:4px;font-size:12px;color:#2563eb;text-decoration:none">Edit billing</a>
                <?php endif; ?>
              </td>
              <td style="padding:10px">
                <?php echo htmlspecialchars((string)($ltc['organization_name'] ?: $ltc['client_name'])); ?>
              </td>
              <td style="padding:10px"><?php if (!empty($ltc['organization_name'])): ?><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$ltc['client_id']; ?>"><?php echo htmlspecialchars((string)$ltc['client_name']); ?></a><?php endif; ?></td>
              <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($ltc['status']); ?></td>
              <td style="padding:10px"><?php echo htmlspecialchars($billingInterval); ?></td>
              <td style="padding:10px;font-weight:600"><?php echo htmlspecialchars($amountText); ?></td>
              <td style="padding:10px"><?php echo $lastDate; ?></td>
              <td style="padding:10px">
                <?php if ($ltc['status'] === 'active' && $ltc['next_invoice_date']): ?>
                  <span style="font-weight:600;color:#059669"><?php echo $nextDate; ?></span>
                <?php else: ?>
                  <span style="color:#6b7280"><?php echo $nextDate; ?></span>
                <?php endif; ?>
              </td>
              <td style="padding:10px"><?php echo htmlspecialchars($progressText); ?></td>
              <td style="padding:10px;white-space:nowrap">
                <a href="/?page=invoice/recurring-invoices-list&status=all&contract_id=<?php echo (int)$ltc['id']; ?>&history_page=1#invoice-history" style="display:inline-block;padding:6px 9px;border:1px solid #bfdbfe;border-radius:7px;background:#eff6ff;font-size:13px;color:#1d4ed8;text-decoration:none;font-weight:600">
                  View history (<?php echo (int)$ltc['invoice_history_count']; ?>)
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div id="invoice-history" style="margin-top:28px;scroll-margin-top:20px">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:10px">
      <div>
        <h3 style="margin:0 0 4px;font-size:18px">Recurring Invoice History</h3>
        <div style="font-size:13px;color:#6b7280">
          <?php echo number_format($historyTotal); ?> invoice<?php echo $historyTotal === 1 ? '' : 's'; ?>, including paid and completed billing periods.
          <?php if ($history_contract_id > 0): ?> Filtered to LTC-<?php echo (int)($contracts[0]['doc_number'] ?? $history_contract_id); ?>.<?php endif; ?>
        </div>
        <?php if ($history_contract_id > 0 && !empty($contracts[0])): ?>
          <div style="margin-top:8px;padding:8px 10px;border:1px solid #bfdbfe;border-radius:7px;background:#eff6ff;color:#1e3a8a;font-size:13px">
            Showing invoices for <strong>LTC-<?php echo (int)($contracts[0]['doc_number'] ?? $contracts[0]['id']); ?></strong> — <?php echo htmlspecialchars((string)($contracts[0]['organization_name'] ?: $contracts[0]['client_name'])); ?>.
          </div>
        <?php endif; ?>
      </div>
      <form method="get" action="/" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="invoice/recurring-invoices-list">
        <?php if ($client_id > 0): ?><input type="hidden" name="client_id" value="<?php echo $client_id; ?>"><?php endif; ?>
        <?php if ($client_name !== ''): ?><input type="hidden" name="client" value="<?php echo htmlspecialchars($client_name); ?>"><?php endif; ?>
        <?php if ($project_code !== ''): ?><input type="hidden" name="project_code" value="<?php echo htmlspecialchars($project_code); ?>"><?php endif; ?>
        <?php if ($history_contract_id > 0): ?><input type="hidden" name="contract_id" value="<?php echo $history_contract_id; ?>"><?php endif; ?>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <label style="display:grid;gap:4px;font-size:12px;font-weight:600;color:#374151">
          Invoice status
          <select name="invoice_status" style="padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;background:#fff" onchange="this.form.submit()">
            <?php foreach ($allowedInvoiceStatuses as $invoiceStatusOption): ?>
              <option value="<?php echo htmlspecialchars($invoiceStatusOption); ?>" <?php echo $invoice_status === $invoiceStatusOption ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($invoiceStatusOption)); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($history_contract_id > 0): ?>
          <a href="/?page=invoice/recurring-invoices-list&status=<?php echo urlencode($status); ?>&invoice_status=<?php echo urlencode($invoice_status); ?>#invoice-history" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;background:#fff;text-decoration:none;color:#374151;font-size:13px">All contracts</a>
        <?php endif; ?>
      </form>
    </div>

    <div style="overflow:auto">
      <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #eee">
            <th style="padding:10px">Invoice</th>
            <th style="padding:10px">Contract</th>
            <th style="padding:10px">Customer</th>
            <th style="padding:10px">Contact</th>
            <th style="padding:10px">Issued</th>
            <th style="padding:10px">Due</th>
            <th style="padding:10px">Status</th>
            <th style="padding:10px;text-align:right">Total</th>
            <th style="padding:10px;text-align:right">Paid</th>
            <th style="padding:10px;text-align:right">Balance</th>
            <th style="padding:10px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$invoiceHistory): ?>
            <tr><td colspan="11" style="padding:20px;text-align:center;color:#6b7280">No recurring invoices match these filters.</td></tr>
          <?php else: ?>
            <?php foreach ($invoiceHistory as $historyInvoice): ?>
              <?php
                $historyStatus = strtolower((string)$historyInvoice['status']);
                $historyPaid = (float)$historyInvoice['amount_paid'];
                $historyBalance = in_array($historyStatus, ['paid', 'cancelled', 'void'], true)
                  ? max(0.0, (float)$historyInvoice['balance_due'])
                  : max(0.0, (float)$historyInvoice['total'] - $historyPaid);
                $historyRowStyle = $historyStatus === 'paid' ? 'background:#f0fdf4;' : (in_array($historyStatus, ['unpaid', 'partial', 'overdue', 'sent'], true) ? 'background:#fffbeb;' : '');
              ?>
              <tr style="border-top:1px solid #f3f4f6;<?php echo $historyRowStyle; ?>">
                <td style="padding:10px;font-weight:600"><a href="/?page=invoice/invoice-details&id=<?php echo (int)$historyInvoice['id']; ?>" style="color:#2563eb;text-decoration:none"><?php echo htmlspecialchars(pa_invoice_label($historyInvoice['doc_number'] ?? null, 'long_term', $historyInvoice['id'])); ?></a></td>
                <td style="padding:10px"><a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$historyInvoice['contract_id']; ?>" style="color:inherit;text-decoration:none">LTC-<?php echo (int)($historyInvoice['contract_doc_number'] ?? $historyInvoice['contract_id']); ?></a></td>
                <td style="padding:10px"><?php echo htmlspecialchars((string)($historyInvoice['organization_name'] ?: $historyInvoice['client_name'])); ?></td>
                <td style="padding:10px"><?php if (!empty($historyInvoice['organization_name'])): ?><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$historyInvoice['client_id']; ?>"><?php echo htmlspecialchars((string)$historyInvoice['client_name']); ?></a><?php endif; ?></td>
                <td style="padding:10px;white-space:nowrap"><?php echo !empty($historyInvoice['created_at']) ? date('M j, Y', strtotime((string)$historyInvoice['created_at'])) : '—'; ?></td>
                <td style="padding:10px;white-space:nowrap"><?php echo !empty($historyInvoice['due_date']) ? date('M j, Y', strtotime((string)$historyInvoice['due_date'])) : '—'; ?></td>
                <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($historyStatus); ?></td>
                <td style="padding:10px;text-align:right">$<?php echo number_format((float)$historyInvoice['total'], 2); ?></td>
                <td style="padding:10px;text-align:right">$<?php echo number_format($historyPaid, 2); ?></td>
                <td style="padding:10px;text-align:right;font-weight:600">$<?php echo number_format($historyBalance, 2); ?></td>
                <td style="padding:10px"><a href="/?page=invoice/invoice-details&id=<?php echo (int)$historyInvoice['id']; ?>" style="display:inline-block;padding:6px 9px;border:1px solid #d1d5db;border-radius:7px;background:#fff;color:#374151;text-decoration:none;font-size:13px">View / Actions</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($historyLastPage > 1): ?>
      <?php
        $historyQuery = $_GET;
        $historyQuery['page'] = 'invoice/recurring-invoices-list';
        unset($historyQuery['history_page']);
        $historyBase = '/?' . http_build_query($historyQuery);
      ?>
      <div style="display:flex;justify-content:flex-end;align-items:center;gap:8px;margin-top:12px">
        <?php if ($history_page > 1): ?><a href="<?php echo htmlspecialchars($historyBase . '&history_page=' . ($history_page - 1)); ?>#invoice-history" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:7px;background:#fff;text-decoration:none">Previous</a><?php endif; ?>
        <span style="font-size:13px;color:#6b7280">Page <?php echo $history_page; ?> of <?php echo $historyLastPage; ?></span>
        <?php if ($history_page < $historyLastPage): ?><a href="<?php echo htmlspecialchars($historyBase . '&history_page=' . ($history_page + 1)); ?>#invoice-history" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:7px;background:#fff;text-decoration:none">Next</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="margin-top:20px;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
    <h3 style="margin:0 0 12px 0;font-size:16px">About Recurring Billing</h3>
    <ul style="margin:0;padding-left:20px;color:#374151;line-height:1.8">
      <li><strong>Active contracts</strong> automatically generate invoices on their scheduled date</li>
      <li><strong>Pending contracts</strong> must be activated before invoicing begins</li>
      <li><strong>Paused contracts</strong> skip billing until resumed</li>
      <li><strong>Completed contracts</strong> have reached an end date, finished a fixed-total schedule, or were explicitly terminated</li>
      <li>Paying a recurring invoice does not complete its long-term contract</li>
      <li>Invoices are generated daily by the system at 2:00 AM</li>
      <li>Fixed-total contracts complete automatically when fully invoiced</li>
    </ul>
  </div>
</section>
