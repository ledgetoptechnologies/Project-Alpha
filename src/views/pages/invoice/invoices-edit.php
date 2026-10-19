<?php
// src/views/pages/invoices-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../../utils/workforce_availability.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/general_recipient_invoices.php';
require_once __DIR__ . '/../../../services/DocumentPolicy.php';
require_once __DIR__ . '/../../../services/WorkTimeInvoiceEligibilityService.php';
require_once __DIR__ . '/../../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../components/tax_lookup_control.php';
$id = (int)($_GET['id'] ?? 0);
require_record_ownership($pdo, 'invoices', $id);
$iv = $pdo->prepare('SELECT * FROM invoices WHERE id=?');
$iv->execute([$id]);
$inv = $iv->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
  echo '<p>Invoice not found</p>';
  return;
}
try{$inv=DocumentPolicy::assertMutable($pdo,'invoice',$id,'monetary_adjustment');}catch(DocumentLockedException $locked){http_response_code(409);echo '<div class="alert alert-warning">'.htmlspecialchars($locked->getMessage()).' <a href="/?page=invoice/invoice-details&id='.$id.'">Return to invoice</a></div>';return;}
$isGeneralRecipientInvoice = pa_invoice_is_general_recipient($inv);
$items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=?');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT c.id, c.name, c.organization_id, o.name AS organization_name FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id ORDER BY c.name ASC")->fetchAll();
$clientName = '';
$clientOrganizationId = 0;
$clientOrganizationName = '';
foreach ($clients as $c) {
  if ((int)$c['id'] === (int)$inv['client_id']) {
    $clientName = $c['name'];
    $clientOrganizationId = (int)($c['organization_id'] ?? 0);
    $clientOrganizationName = (string)($c['organization_name'] ?? '');
    break;
  }
}
$invoiceActorId = (int)($_SESSION['user']['id'] ?? 0);
$availableTimeEntries = [];
$pendingTimeEntries = [];
$attachedTimeEntries = [];
$invoiceTimeBlockingReason = null;
$invoiceActorIsVerifiedOwner = false;
$invoiceActorCanAdministrativelySelfConfirm = false;
if (!$isGeneralRecipientInvoice) {
  $invoiceTimeEligibility = (new \App\Services\WorkTimeInvoiceEligibilityService($pdo))
    ->forInvoice($id, $invoiceActorId);
  $availableTimeEntries = $invoiceTimeEligibility['ready'];
  $pendingTimeEntries = $invoiceTimeEligibility['pending'];
  $attachedTimeEntries = $invoiceTimeEligibility['attached'];
  $invoiceTimeBlockingReason = $invoiceTimeEligibility['blocking_reason'];
  $invoiceActorIsVerifiedOwner = $invoiceTimeEligibility['actor_is_verified_owner'];
  $invoiceActorCanAdministrativelySelfConfirm = $invoiceTimeEligibility['actor_can_administratively_self_confirm'];
}
$contractEstimateLines = [];
if (!empty($inv['contract_id'])) {
  $estimateStmt = $pdo->prepare(
    "SELECT item,description,quantity,unit_price,line_total,billing_unit
     FROM contract_items WHERE contract_id=? AND pricing_status='estimate'
     ORDER BY id"
  );
  $estimateStmt->execute([(int)$inv['contract_id']]);
  $contractEstimateLines = $estimateStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<section>
  <h2>Edit Invoice <?php echo htmlspecialchars(pa_invoice_label_from_row($inv)); ?><?php if (!empty($inv['project_code'])) echo ' (Job ' . htmlspecialchars($inv['project_code']) . ')'; ?></h2>
  <?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success" role="status"><?php echo htmlspecialchars((string)$_GET['success']); ?></div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>
  <?php if ($isGeneralRecipientInvoice): ?>
    <div class="alert alert-info" role="status">This public invoice is presented as <strong>General Recipient</strong>. Its internal accounting client remains private and cannot be reassigned here.</div>
  <?php endif; ?>
  <?php if ($contractEstimateLines): ?>
  <div style="max-width:900px;margin:0 0 18px;padding:14px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc">
    <strong>Contract estimate — reference only</strong>
    <p style="margin:4px 0 10px;color:#64748b;font-size:13px">These estimated hours are not collectible invoice charges. Confirmed time below creates the actual hourly lines.</p>
    <div style="display:grid;gap:6px"><?php foreach ($contractEstimateLines as $estimateLine): ?><div style="display:flex;justify-content:space-between;gap:16px"><span><?= htmlspecialchars((string)$estimateLine['item']) ?> · <?= number_format((float)$estimateLine['quantity'],2) ?> hr × $<?= number_format((float)$estimateLine['unit_price'],2) ?></span><strong>$<?= number_format((float)$estimateLine['line_total'],2) ?> estimated</strong></div><?php endforeach; ?></div>
  </div>
  <?php endif; ?>
  <?php if (pa_workforce_enabled() && !$isGeneralRecipientInvoice): ?>
  <div style="display:grid;gap:10px;max-width:900px;margin:0 0 18px;padding:14px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff">
    <div>
      <div style="font-weight:700;color:#1e3a8a">Add confirmed time to this draft</div>
      <div style="font-size:13px;color:#475569">Confirmed, billable time for this client stays available until it is attached. Finalizing the invoice locks this workflow.</div>
    </div>
    <?php if ($invoiceTimeBlockingReason): ?>
      <p style="margin:0;color:#92400e"><?php echo htmlspecialchars($invoiceTimeBlockingReason); ?></p>
    <?php endif; ?>
    <?php if (!$availableTimeEntries): ?>
      <p style="margin:0;color:#64748b"><?php echo $pendingTimeEntries
        ? 'No time is ready to add yet. Pending entries and their next action are shown below.'
        : 'No additional confirmed time is currently available for this client.'; ?></p>
    <?php else: ?>
      <div style="display:grid;gap:8px">
        <?php foreach ($availableTimeEntries as $timeEntry): ?>
          <form method="post" action="/?page=workforce/action" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:10px;background:#fff;border:1px solid #dbeafe;border-radius:8px">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="link-invoice">
            <input type="hidden" name="entry_id" value="<?php echo htmlspecialchars((string)$timeEntry['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="entry_user_id" value="<?php echo (int)$timeEntry['user_id']; ?>">
            <input type="hidden" name="invoice_id" value="<?php echo $id; ?>">
            <input type="hidden" name="return_to" value="invoice-edit">
            <div>
              <strong><?php echo htmlspecialchars($timeEntry['work_type_name'] ?: ($timeEntry['job_code'] ?: ($timeEntry['project_name'] ?: 'Unclassified work'))); ?></strong>
              <small style="display:block;color:#64748b"><?php echo htmlspecialchars($timeEntry['worker_name']); ?> · <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$timeEntry['start_time']))); ?> · <?php echo number_format(((int)$timeEntry['duration_seconds']) / 3600, 2); ?> h<?php if ($timeEntry['description']): ?> · <?php echo htmlspecialchars($timeEntry['description']); ?><?php endif; ?></small>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
              <?php if ((float)($timeEntry['billing_rate'] ?? 0) <= 0): ?><input type="number" name="billing_rate" min="0.01" step="0.01" required placeholder="Hourly rate" aria-label="Hourly billing rate" style="width:120px;padding:8px;border:1px solid #cbd5e1;border-radius:6px"><?php endif; ?>
              <button class="btn btn-primary btn-sm" type="submit">Add time</button>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($pendingTimeEntries): ?>
      <div style="display:grid;gap:8px;padding-top:10px;border-top:1px solid #bfdbfe">
        <div>
          <strong style="color:#92400e">Time waiting for confirmation</strong>
          <small style="display:block;color:#64748b">Entries selected for this invoice or matching its Job cannot become invoice charges until their time review is complete.</small>
        </div>
        <?php foreach ($pendingTimeEntries as $timeEntry):
          $pendingState = match ((string)$timeEntry['workflow_status']) {
            'submitted' => 'Awaiting time review',
            'returned' => 'Returned for changes',
            default => 'Not submitted for review',
          };
          $canConfirmAndAdd = (bool)($timeEntry['can_confirm_and_add'] ?? false);
        ?>
          <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px">
            <div>
              <strong><?php echo htmlspecialchars($timeEntry['work_type_name'] ?: ($timeEntry['job_code'] ?: ($timeEntry['project_name'] ?: 'Unclassified work'))); ?></strong>
              <small style="display:block;color:#64748b"><?php echo htmlspecialchars($timeEntry['worker_name']); ?> · <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$timeEntry['start_time']))); ?> · <?php echo number_format(((int)$timeEntry['duration_seconds']) / 3600, 2); ?> h · <?php echo htmlspecialchars($pendingState); ?></small>
            </div>
            <?php if ($canConfirmAndAdd): ?>
              <form method="post" action="/?page=workforce/action">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="link-invoice">
                <input type="hidden" name="entry_id" value="<?php echo htmlspecialchars((string)$timeEntry['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="entry_user_id" value="<?php echo (int)$timeEntry['user_id']; ?>">
                <input type="hidden" name="invoice_id" value="<?php echo $id; ?>">
                <input type="hidden" name="return_to" value="invoice-edit">
                <div style="display:flex;gap:8px;align-items:center">
                  <input type="number" name="billing_rate" min="0.01" step="0.01" placeholder="Optional hourly rate" aria-label="Optional hourly billing rate" style="width:140px;padding:8px;border:1px solid #d97706;border-radius:6px">
                  <button class="btn btn-primary btn-sm" type="submit">Confirm and add</button>
                </div>
              </form>
            <?php elseif ((string)$timeEntry['workflow_status'] === 'submitted' && (int)$timeEntry['user_id'] !== $invoiceActorId): ?>
              <a class="btn btn-sm" href="/?page=workforce/approvals">Review time</a>
            <?php elseif ((string)$timeEntry['workflow_status'] === 'submitted'): ?>
              <span style="color:#92400e;font-size:13px;font-weight:600">Another reviewer is required</span>
            <?php else: ?>
              <a class="btn btn-sm" href="/?page=workforce/time&amp;user=<?php echo (int)$timeEntry['user_id']; ?>">Open time entry</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$invoiceActorCanAdministrativelySelfConfirm && !$invoiceActorIsVerifiedOwner && array_filter($pendingTimeEntries, static fn(array $entry): bool => (int)$entry['user_id'] === $invoiceActorId)): ?>
          <p style="margin:0;color:#92400e;font-size:13px">Your own submitted time needs another reviewer. If this account represents the business owner, confirm its <a href="/?page=settings&amp;tab=business-units">Owner relationship</a> to use self-confirmation and nonpayable owner time.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <form id="invEditForm" method="post" action="/?page=invoices-update" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <?php $issuerChoiceRow = $inv; require __DIR__ . '/../../components/document_issuer_choice.php'; ?>
    <input type="hidden" name="id" value="<?php echo (int)$inv['id']; ?>">
    <?php echo !$isGeneralRecipientInvoice?pricing_adjustment_override_controls($pdo,(int)($inv['organization_id']??$clientOrganizationId),'invoice',$id):''; ?>
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr">
      <div data-document-recipient-picker>
      <label style="position:relative;display:block">
        <div><?php echo $isGeneralRecipientInvoice ? 'Internal accounting client' : 'Client'; ?></div>
        <input id="clientInputInv" data-document-client-search type="text" value="<?php echo htmlspecialchars($clientName); ?>" placeholder="Type client name..." autocomplete="off" <?php echo $isGeneralRecipientInvoice ? 'readonly' : ''; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientIdInv" data-document-client-id type="hidden" name="client_id" value="<?php echo (int)$inv['client_id']; ?>" data-organization-id="<?php echo $clientOrganizationId ?: ''; ?>" data-organization-name="<?php echo htmlspecialchars($clientOrganizationName, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <div id="clientSuggestInv" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <?php if (!$isGeneralRecipientInvoice): ?>
      <label data-document-contact-option hidden style="display:flex;align-items:flex-start;gap:8px;margin-top:8px;font-size:13px">
        <input data-document-contact-checkbox type="checkbox" name="show_contact_on_document" value="1" <?php echo !empty($inv['show_contact_on_document']) ? 'checked' : ''; ?>>
        <span>Include contact on document <small data-document-organization-name style="display:block;color:var(--muted)"></small></span>
      </label>
      <?php endif; ?>
      </div>
      <label>
        <div>Due Date</div>
        <input type="date" name="due_date" value="<?php echo htmlspecialchars($inv['due_date'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div>
        <?php echo render_tax_lookup_control('taxPercentInv', 'tax_percent', (float)($inv['tax_percent'] ?? 0)); ?>
      </div>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeInv" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo $inv['discount_type'] === 'none' ? 'selected' : ''; ?>>None</option>
          <option value="percent" <?php echo $inv['discount_type'] === 'percent' ? 'selected' : ''; ?>>Percent</option>
          <option value="fixed" <?php echo $inv['discount_type'] === 'fixed' ? 'selected' : ''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueInv" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($inv['discount_value']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Fulfillment Date</div>
        <input type="date" name="fulfillment_date" value="<?php echo htmlspecialchars($inv['fulfillment_date'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <?php
    // Render custom fields for invoices (use 'regular' type)
    $documentType = 'regular';

    // Get existing custom field values
    $existingCustomFields = !empty($inv['custom_fields']) ? json_decode($inv['custom_fields'], true) : [];
    if (!is_array($existingCustomFields)) $existingCustomFields = [];

    // Fetch non-builtin custom fields for this document type
    $customFieldsStmt = $pdo->prepare('
        SELECT * FROM document_custom_fields 
        WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0
        ORDER BY display_order, id
    ');
    $customFieldsStmt->execute([$documentType]);
    $customFields = $customFieldsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($customFields)):
    ?>
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
        <div style="font-weight:600;margin-bottom:12px;color:#374151">Custom Fields</div>
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr))">
          <?php foreach ($customFields as $field):
            $fieldKey = $field['field_key'];
            $fieldValue = $existingCustomFields[$fieldKey] ?? '';
          ?>
            <label>
              <div><?php echo htmlspecialchars($field['field_label']); ?><?php if ($field['is_required']): ?> <span style="color:#dc2626">*</span><?php endif; ?></div>
              <?php if ($field['field_type'] === 'date'): ?>
                <input type="date" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php elseif ($field['field_type'] === 'number'): ?>
                <input type="number" step="0.01" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php elseif ($field['field_type'] === 'textarea'): ?>
                <textarea name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" rows="3" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($fieldValue); ?></textarea>
              <?php elseif ($field['field_type'] === 'select'): ?>
                <?php $options = json_decode($field['field_options'] ?? '[]', true); ?>
                <select name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                  <option value="">-- Select --</option>
                  <?php foreach ($options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $fieldValue === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="text" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$isGeneralRecipientInvoice): ?>
      <?php $documentServiceLocationId = (int)($inv['service_location_id'] ?? 0); require __DIR__ . '/../../components/document_service_location_fields.php'; ?>
    <?php endif; ?>

    <div style="margin:12px 0;padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Billing Mode</div>
      <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
        <input type="checkbox" name="billing_mode" value="hourly" <?php echo ($inv['billing_mode'] ?? 'fixed') === 'hourly' ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600;color:#1f2937">Hourly billing</div>
          <div style="font-size:13px;color:#4b5563">Use tracked time or hourly rows for this invoice.</div>
        </div>
      </label>
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items (from contract - read only)</div>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px">
        <?php
        $contractItems = [];
        $extraCharges = [];
        foreach ($items as $it) {
          if ((int)($it['is_extra_charge'] ?? 0) === 1) {
            $extraCharges[] = $it;
          } else {
            $contractItems[] = $it;
          }
        }
        ?>
        <?php if (empty($contractItems)): ?>
          <p style="color:#6b7280;margin:0">No items</p>
        <?php else: ?>
          <table style="width:100%;border-collapse:collapse">
            <thead>
              <tr style="border-bottom:2px solid #e5e7eb">
                <th style="text-align:left;padding:8px;color:#6b7280;font-weight:600">Item</th>
                <th style="text-align:left;padding:8px;color:#6b7280;font-weight:600">Description</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:100px">Quantity</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:120px">Unit Price</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:120px">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($contractItems as $it): ?>
                <tr style="border-bottom:1px solid #e5e7eb">
                  <td style="padding:8px;font-weight:600"><?php echo htmlspecialchars($it['item'] ?? ''); ?></td>
                  <td style="padding:8px;color:#6b7280;font-size:13px"><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
                  <td style="padding:8px;text-align:right"><?php echo htmlspecialchars(number_format((float)$it['quantity'], 2)); ?></td>
                  <td style="padding:8px;text-align:right">$<?php echo htmlspecialchars(number_format((float)$it['unit_price'], 2)); ?></td>
                  <td style="padding:8px;text-align:right">$<?php echo htmlspecialchars(number_format((float)$it['quantity'] * (float)$it['unit_price'], 2)); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <p style="color:#6b7280;font-size:0.875rem;margin-top:8px">To change items, modify the contract and apply a discount if needed.</p>
    </div>

    <!-- Extra Charges Section -->
    <div>
      <div style="font-weight:600;margin-bottom:8px">Invoice Adjustments (editable)</div>
      <div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:12px;margin-bottom:8px">
        <?php if (empty($extraCharges)): ?>
          <p style="color:#92400e;margin:0">No extra charges added yet. Use the form below to add additional line items.</p>
        <?php else: ?>
          <div id="extraChargesContainer" style="display:grid;gap:8px">
            <?php foreach ($extraCharges as $idx => $it): ?>
              <div style="display:grid;grid-template-columns:1.2fr 2.5fr 2.5fr 1fr 1fr auto;gap:8px;padding:8px;background:#fff;border-radius:4px;border:1px solid #fcd34d">
                <input type="hidden" name="extra_billing_unit[]" value="<?php echo htmlspecialchars($it['billing_unit'] ?? 'each'); ?>">
                <select name="extra_adjustment_type[]" style="padding:8px;border-radius:4px;border:1px solid #ddd" onchange="recalcInv()"><option value="charge" <?php echo (float)$it['unit_price'] >= 0 ? 'selected' : ''; ?>>Charge</option><option value="credit" <?php echo (float)$it['unit_price'] < 0 ? 'selected' : ''; ?>>Credit</option></select>
                <input id="item" type="text" name="extra_item[]" value="<?php echo htmlspecialchars($it['item'] ?? ''); ?>" placeholder="Item name..." style="padding:8px;border-radius:4px;border:1px solid #ddd" data-item-autocomplete data-item-library-id="<?php echo (int)($it['item_library_id'] ?? 0); ?>" data-description-field="extra_desc_<?php echo $idx; ?>" data-price-field="extra_price_<?php echo $idx; ?>">
                <textarea id="description" name="extra_desc[]" placeholder="Description (optional)" style="padding:8px;border-radius:4px;border:1px solid #ddd;resize:vertical;min-height:34px"><?php echo htmlspecialchars($it['description'] ?? ''); ?></textarea>
                <input id="quantity" type="number" step="0.01" min="0" name="extra_qty[]" value="<?php echo htmlspecialchars($it['quantity']); ?>" placeholder="Qty" style="padding:8px;border-radius:4px;border:1px solid #ddd">
                <input id="price" type="number" step="0.01" min="0" name="extra_price[]" value="<?php echo htmlspecialchars((string)abs((float)$it['unit_price'])); ?>" placeholder="Amount" style="padding:8px;border-radius:4px;border:1px solid #ddd">
                <input type="hidden" name="extra_id[]" value="<?php echo htmlspecialchars($it['id']); ?>">
                <button type="button" onclick="if(confirm('Remove this extra charge?')){this.parentElement.remove();recalcInv();}" style="border:0;background:#fee2e2;color:#991b1b;border-radius:4px;padding:8px 10px;cursor:pointer">Remove</button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <button id="addExtraChargeBtn" type="button" onclick="addExtraCharge()" style="margin-bottom:12px;padding:8px 12px;border-radius:8px;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;cursor:pointer;font-weight:600">+ Add Charge or Credit</button>
    </div>

    <?php $pn = null;
    if (!empty($inv['project_code'])) {
      $pm = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
      $pm->execute([$inv['project_code']]);
      $pn = (string)$pm->fetchColumn();
    } ?>
    <label>
      <div>Job Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>

    <?php if ((!isset($appConfig['contract_scope_enabled']) || !empty($appConfig['contract_scope_enabled'])) && !empty($inv['scope'])): ?>
      <div>
        <div style="font-weight:600;margin-bottom:8px">Scope of Work (from contract - read only)</div>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;white-space:pre-wrap;color:#374151"><?php echo htmlspecialchars($inv['scope'] ?? ''); ?></div>
        <p style="color:#6b7280;font-size:0.875rem;margin-top:8px">To change scope, modify the contract.</p>
      </div>
    <?php endif; ?>

    <?php
    // Calculate totals from database items (contract + extra charges)
    $subtotal = 0;
    $contractSubtotalForTotals = 0;
    $extraSubtotalForTotals = 0;
    foreach ($items as $it) {
      $lineTotal = (float)$it['quantity'] * (float)$it['unit_price'];
      $subtotal += $lineTotal;
      if ((int)($it['is_extra_charge'] ?? 0) === 1) {
        $extraSubtotalForTotals += $lineTotal;
      } else {
        $contractSubtotalForTotals += $lineTotal;
      }
    }
    $dtype = $inv['discount_type'] ?? 'none';
    $dval = (float)($inv['discount_value'] ?? 0);
    $discount = 0;
    if ($dtype === 'percent') {
      $discount = max(0, min(100, $dval)) * $subtotal / 100;
    } elseif ($dtype === 'fixed') {
      $discount = max(0, $dval);
    }
    $taxable = max(0, $subtotal - $discount);
    $taxpct = (float)($inv['tax_percent'] ?? 0);
    $tax = max(0, $taxpct) * $taxable / 100;
    $total = max(0, $taxable + $tax);
    ?>
    <div id="totalsInv" data-base-subtotal="<?php echo htmlspecialchars(number_format($contractSubtotalForTotals, 2, '.', '')); ?>" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div>
        <div id="subtotalValInv" style="min-width:120px;text-align:right">$<?php echo number_format($subtotal, 2); ?></div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div>
        <div id="discountValInv" style="min-width:120px;text-align:right">$<?php echo number_format($discount, 2); ?></div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div>
        <div id="taxValInv" style="min-width:120px;text-align:right">$<?php echo number_format($tax, 2); ?></div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700">
        <div style="min-width:140px;text-align:right">Total</div>
        <div id="totalValInv" style="min-width:120px;text-align:right">$<?php echo number_format($total, 2); ?></div>
      </div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Invoice</button>
    </div>
  </form>
</section>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/invoices-edit-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/document-recipient-presentation.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/tax-lookup-control.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/pricing-adjustments.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
