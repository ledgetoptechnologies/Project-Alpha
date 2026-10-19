<?php
// src/views/pages/contracts-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../services/DocumentPolicy.php';
require_once __DIR__ . '/../../../utils/project_selection.php';
require_once __DIR__ . '/../../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../components/tax_lookup_control.php';
$id = (int)($_GET['id'] ?? 0);
require_record_ownership($pdo, 'contracts', $id);
$co = $pdo->prepare('SELECT co.* FROM contracts co WHERE co.id=?');
$co->execute([$id]);
$contract = $co->fetch(PDO::FETCH_ASSOC);
if (!$contract) {
  echo '<p>Contract not found</p>';
  return;
}
try{$contract=DocumentPolicy::assertMutable($pdo,'contract',$id,'commercial');}catch(DocumentLockedException $locked){http_response_code(409);echo '<div class="alert alert-warning">'.htmlspecialchars($locked->getMessage()).' <a href="/?page=contract/contract-details&id='.$id.'">Return to contract</a></div>';return;}
// Resolve display fields from the policy-checked row's current recipient.
// Never replace its authoritative document fields with an earlier joined row.
$recipient = $pdo->prepare('SELECT c.name AS client_name, c.organization_id AS client_organization_id, o.name AS client_organization_name FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id WHERE c.id=?');
$recipient->execute([(int)($contract['client_id'] ?? 0)]);
$contract += $recipient->fetch(PDO::FETCH_ASSOC) ?: [];
$isLongTermContract = ($contract['contract_type'] ?? 'regular') === 'long_term';
$baseRecurringService = null;
if ($isLongTermContract) {
  try {
    $baseServiceStmt = $pdo->prepare('SELECT * FROM contract_recurring_services WHERE contract_id=? AND is_base=1 ORDER BY id LIMIT 1');
    $baseServiceStmt->execute([$id]);
    $baseRecurringService = $baseServiceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    $baseRecurringService = null;
  }
}
$items = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=? AND is_travel=0');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$projectOptions = [];
if (!empty($contract['client_id'])) {
  try {
    [$projectWhere, $projectParams] = pa_active_project_filter_for_client($pdo, (int)$contract['client_id']);
    if (function_exists('scope_clause')) {
      [$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', (int)($_SESSION['user']['id'] ?? 0));
      if ($scopeWhere !== '') {
        $projectWhere[] = trim($scopeWhere);
        $projectParams = array_merge($projectParams, $scopeParams);
      }
    }
    $projectStmt = $pdo->prepare('
      SELECT p.id, p.name, p.status, o.name AS organization_name
      FROM projects p
      LEFT JOIN organizations o ON o.id = p.organization_id
      WHERE ' . implode(' AND ', $projectWhere) . '
      ORDER BY p.name
    ');
    $projectStmt->execute($projectParams);
    $projectOptions = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $projectOptions = [];
  }
}

// Fetch existing signatures
$existingSignatures = [];
try {
  $sigStmt = $pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id = ? ORDER BY display_order, id');
  $sigStmt->execute([$id]);
  $existingSignatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  try {
    $sigStmt = $pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id = ? ORDER BY id');
    $sigStmt->execute([$id]);
    $existingSignatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $ignored) {
    $existingSignatures = [];
  }
}
?>
<section>
  <h2>Edit Contract C-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?><?php if (!empty($contract['project_code'])) echo ' (Job ' . htmlspecialchars($contract['project_code']) . ')'; ?></h2>
  <?php echo pricing_adjustment_assignment_controls($pdo,(int)($contract['organization_id']??$contract['client_organization_id']??0),'contract',$id,'/?page=contract/contracts-edit&id='.$id,csrf_token()); ?>
  <form id="coEditForm" method="post" action="/?page=contracts-update" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <?php $issuerChoiceRow = $contract; require __DIR__ . '/../../components/document_issuer_choice.php'; ?>
    <input type="hidden" name="id" value="<?php echo (int)$contract['id']; ?>">
    <?php echo pricing_adjustment_override_controls($pdo,(int)($contract['organization_id']??$contract['client_organization_id']??0),'contract',$id); ?>
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <div data-document-recipient-picker>
      <label style="display:block">
        <div>Client</div>
        <div style="position:relative">
          <input type="hidden" data-document-client-id name="client_id" id="contractEditClientId" value="<?php echo (int)($contract['client_id'] ?? 0); ?>" data-organization-id="<?php echo (int)($contract['client_organization_id'] ?? 0) ?: ''; ?>" data-organization-name="<?php echo htmlspecialchars((string)($contract['client_organization_name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <input type="text" data-document-client-search id="contractEditClientSearch" value="<?php echo htmlspecialchars($contract['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Type a client name or email..." autocomplete="off" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <div id="contractEditClientSuggest" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:40;max-height:220px;overflow:auto;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 12px 24px rgba(15,23,42,.12)"></div>
        </div>
        <small style="display:block;margin-top:6px;color:var(--muted)">Start typing, then choose the matching client.</small>
      </label>
      <label data-document-contact-option hidden style="display:flex;align-items:flex-start;gap:8px;margin-top:8px;font-size:13px">
        <input data-document-contact-checkbox type="checkbox" name="show_contact_on_document" value="1" <?php echo !empty($contract['show_contact_on_document']) ? 'checked' : ''; ?>>
        <span>Include contact on document <small data-document-organization-name style="display:block;color:var(--muted)"></small></span>
      </label>
      </div>
      <div>
        <?php echo render_tax_lookup_control('taxPercentCo', 'tax_percent', (float)($contract['tax_percent'] ?? 0)); ?>
      </div>
      <label>
        <div>Project</div>
        <select name="project_id" id="contractEditProjectSelect" data-selected-project-id="<?php echo (int)($contract['project_id'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="">No project</option>
          <?php foreach ($projectOptions as $projectOption): ?>
            <option value="<?php echo (int)$projectOption['id']; ?>" <?php echo (int)($contract['project_id'] ?? 0) === (int)$projectOption['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars((string)$projectOption['name']); ?>
              <?php if (!empty($projectOption['organization_name'])): ?>
                (<?php echo htmlspecialchars((string)$projectOption['organization_name']); ?>)
              <?php endif; ?>
              - <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$projectOption['status']))); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeCo" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo ($contract['discount_type'] ?? 'none') === 'none' ? 'selected' : ''; ?>>None</option>
          <option value="percent" <?php echo ($contract['discount_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>Percent</option>
          <option value="fixed" <?php echo ($contract['discount_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueCo" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($contract['discount_value'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Deposit Type</div>
        <select id="depositTypeCo" name="deposit_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo ($contract['deposit_type'] ?? 'none') === 'none' ? 'selected' : ''; ?>>None</option>
          <option value="percent" <?php echo ($contract['deposit_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>Percent</option>
          <option value="fixed" <?php echo ($contract['deposit_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Deposit Value <small>(percent or dollars, based on type)</small></div>
        <?php
          $depositEditValue=(float)($contract['deposit_amount']??0);
          if(($contract['deposit_type']??'none')==='percent'){
            $contractTotal=(float)($contract['total']??0);
            $depositEditValue=$contractTotal>0?round(max(0,min(100,$depositEditValue/$contractTotal*100)),4):0;
          }
        ?>
        <input id="depositValueCo" type="number" step="0.0001" name="deposit_value" value="<?php echo htmlspecialchars((string)$depositEditValue); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Deposit Paid</div>
        <input id="depositPaidCo" type="number" step="0.01" name="deposit_paid" value="<?php echo htmlspecialchars($contract['deposit_paid'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Fulfillment Date</div>
        <input type="date" name="fulfillment_date" value="<?php echo htmlspecialchars($contract['fulfillment_date'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <?php
    // Determine document type and render custom fields for contracts (use 'regular' type)
    $documentType = 'regular';

    // Get existing custom field values
    $existingCustomFields = !empty($contract['custom_fields']) ? json_decode($contract['custom_fields'], true) : [];
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

    <div style="margin:12px 0;padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Billing Mode</div>
      <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
        <input type="checkbox" name="billing_mode" value="hourly" <?php echo ($contract['billing_mode'] ?? 'fixed') === 'hourly' ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600;color:#1f2937">Hourly billing</div>
          <div style="font-size:13px;color:#4b5563">Use line items as estimated hours and hourly rates.</div>
        </div>
      </label>
    </div>

    <?php if ($isLongTermContract): ?>
      <?php
        $intervalCount = max(1, (int)($baseRecurringService['billing_interval_count'] ?? $contract['billing_interval_count'] ?? 1));
        $intervalCountOptions = array_values(array_unique(array_merge([1, 2, 3, 6, 12], [$intervalCount])));
        sort($intervalCountOptions);
        $intervalUnit = in_array((string)($baseRecurringService['billing_interval_unit'] ?? $contract['billing_interval_unit'] ?? 'month'), ['day', 'week', 'month', 'year'], true)
          ? (string)($baseRecurringService['billing_interval_unit'] ?? $contract['billing_interval_unit'])
          : 'month';
        $pricingType = in_array((string)($contract['pricing_type'] ?? 'per_invoice'), ['per_invoice', 'fixed_total'], true)
          ? (string)$contract['pricing_type']
          : 'per_invoice';
        $billingStartMode = in_array((string)($contract['billing_start_mode'] ?? 'on_upload'), ['on_upload', 'manual'], true)
          ? (string)$contract['billing_start_mode']
          : 'on_upload';
        $hasEndDate = !empty($contract['end_date']);
      ?>
      <div id="longTermEditFields" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
        <h3 style="margin:0 0 8px;color:#374151;font-size:16px">Recurring Billing Settings</h3>
        <p style="margin:0 0 12px;color:#6b7280;font-size:13px;line-height:1.45">
          Changes here apply to the base recurring service and future invoices. Manage additional services and their independent schedules from the contract details page. Existing invoices and payments are left as-is.
        </p>

        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(210px,1fr))">
          <label>
            <div>Start Date</div>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars((string)($contract['start_date'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          </label>
          <label>
            <div>Contract Duration</div>
            <select id="editEndDateTypeCo" name="end_date_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <option value="ongoing" <?php echo !$hasEndDate ? 'selected' : ''; ?>>Ongoing</option>
              <option value="fixed" <?php echo $hasEndDate ? 'selected' : ''; ?>>Fixed End Date</option>
            </select>
          </label>
          <label id="editEndDateFieldCo" style="<?php echo $hasEndDate ? '' : 'display:none'; ?>">
            <div>End Date</div>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars((string)($contract['end_date'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          </label>
          <label>
            <div>Next Invoice Date</div>
            <input type="date" name="next_invoice_date" value="<?php echo htmlspecialchars((string)($baseRecurringService['next_invoice_date'] ?? $contract['next_invoice_date'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          </label>
        </div>

        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));margin-top:12px">
          <label>
            <div>Bill Every</div>
            <select id="billingIntervalCountEditCo" name="billing_interval_count" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php foreach ($intervalCountOptions as $countOption): ?>
                <option value="<?php echo (int)$countOption; ?>" <?php echo $intervalCount === (int)$countOption ? 'selected' : ''; ?>><?php echo (int)$countOption; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <div>Period</div>
            <select id="billingIntervalUnitEditCo" name="billing_interval_unit" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php foreach (['day' => 'Day(s)', 'week' => 'Week(s)', 'month' => 'Month(s)', 'year' => 'Year(s)'] as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $intervalUnit === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div style="margin-top:12px;padding:12px;background:#eff6ff;border-radius:8px;border:1px solid #bfdbfe">
          <div style="font-weight:600;margin-bottom:8px;color:#1e3a8a">Billing Start</div>
          <label style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
            <input type="radio" name="billing_start_mode" value="on_upload" <?php echo $billingStartMode === 'on_upload' ? 'checked' : ''; ?> style="margin-top:3px">
            <span>
              <strong style="display:block;color:#374151">Start when signed contract is uploaded</strong>
              <span style="display:block;font-size:13px;color:#6b7280">The first long-term invoice becomes due when the signed contract is received.</span>
            </span>
          </label>
          <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
            <input type="radio" name="billing_start_mode" value="manual" <?php echo $billingStartMode === 'manual' ? 'checked' : ''; ?> style="margin-top:3px">
            <span>
              <strong style="display:block;color:#374151">Manual start</strong>
              <span style="display:block;font-size:13px;color:#6b7280">Keep recurring invoices paused until billing is explicitly started.</span>
            </span>
          </label>
        </div>

        <div style="margin-top:12px;padding:12px;background:#fef3c7;border-radius:8px;border:1px solid #fbbf24">
          <div style="font-weight:600;margin-bottom:8px;color:#92400e">Pricing</div>
          <label style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
            <input type="radio" name="pricing_type" value="per_invoice" <?php echo $pricingType === 'per_invoice' ? 'checked' : ''; ?> style="margin-top:3px">
            <span>
              <strong style="display:block;color:#374151">Recurring Amount</strong>
              <span style="display:block;font-size:13px;color:#6b7280">Client pays the same amount on each invoice.</span>
            </span>
          </label>
          <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
            <input type="radio" name="pricing_type" value="fixed_total" <?php echo $pricingType === 'fixed_total' ? 'checked' : ''; ?> style="margin-top:3px">
            <span>
              <strong style="display:block;color:#374151">Fixed Total</strong>
              <span style="display:block;font-size:13px;color:#6b7280">Total contract amount is divided across a set number of invoices.</span>
            </span>
          </label>
        </div>

        <div id="perInvoiceEditFieldCo" style="<?php echo $pricingType === 'per_invoice' ? '' : 'display:none'; ?>;margin-top:12px">
          <label>
            <div>Amount Per Invoice</div>
            <input id="pricePerInvoiceEditCo" type="number" step="0.01" min="0" name="price_per_invoice" value="<?php echo htmlspecialchars((string)($baseRecurringService['amount'] ?? $contract['price_per_invoice'] ?? $contract['subtotal'] ?? 0)); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          </label>
        </div>

        <div id="fixedTotalEditFieldCo" style="<?php echo $pricingType === 'fixed_total' ? '' : 'display:none'; ?>;margin-top:12px">
          <label>
            <div>Number of Invoices</div>
            <input type="number" step="1" min="<?php echo max(1, (int)($contract['invoices_generated'] ?? 0)); ?>" name="invoice_count" value="<?php echo htmlspecialchars((string)max(1, (int)($contract['invoice_count'] ?? 1))); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          </label>
          <?php if ((int)($contract['invoices_generated'] ?? 0) > 0): ?>
            <small style="display:block;margin-top:6px;color:#6b7280">Already generated invoices: <?php echo (int)$contract['invoices_generated']; ?>.</small>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php $documentServiceLocationId = (int)($contract['service_location_id'] ?? 0); require __DIR__ . '/../../components/document_service_location_fields.php'; ?>
    <?php $travelRuleScope='contract'; $travelDocumentId=(int)$contract['id']; require __DIR__.'/../../components/travel_billing_fields.php'; ?>
    <div>
      <div style="font-weight:600;margin-bottom:8px"><?php echo $isLongTermContract ? 'Items / Fixed Total Rates' : 'Items / Rates'; ?></div>
      <?php if ($isLongTermContract): ?>
        <div style="font-size:13px;color:#6b7280;margin-bottom:8px">For recurring amount contracts, items are optional. For fixed total contracts, items define the contract total.</div>
      <?php endif; ?>

      <div id="itemsCo" style="display:grid;gap:8px"></div>
      <button id="addItemBtn" type="button" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <?php
    $pn = null;
    $pt = null;
    if (!empty($contract['project_code'])) {
      try {
        $pm = $pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
        $pm->execute([$contract['project_code']]);
        $row = $pm->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $pn = (string)($row['notes'] ?? '');
          $pt = (string)($row['terms'] ?? '');
        }
      } catch (Throwable $e) {
        // Older schema may lack 'terms' column; fallback to reading notes only
        try {
          $pm = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
          $pm->execute([$contract['project_code']]);
          $row = $pm->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $pn = (string)($row['notes'] ?? '');
          }
        } catch (Throwable $e2) { /* ignore */
        }
      }
    }
    ?>
    <!-- Job Notes (shared across related docs) -->
    <label>
      <div>Job Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>
    <label>
      <div>Job Terms (override default terms for this job)</div>
      <textarea name="project_terms" rows="6" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="If set, used for all quotes/contracts under this project"><?php echo htmlspecialchars($pt ?? ''); ?></textarea>
    </label>

    <!-- Service Description -->
    <label>
      <div>Service Description</div>
      <textarea name="scope" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="e.g. Website hosting, Google Ads management"><?php echo htmlspecialchars($contract['scope'] ?? ''); ?></textarea>
    </label>

    <div id="totalsCo" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div>
        <div id="subtotalValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div>
        <div id="discountValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div>
        <div id="taxValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700">
        <div style="min-width:140px;text-align:right">Total</div>
        <div id="totalValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Contract</button>
    </div>
  </form>
</section>

<div id="contract-items-data" type="application/json" style="display:none">
  <?php echo json_encode($items) ?>
</div>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/contracts-edit-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/document-recipient-presentation.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/tax-lookup-control.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/pricing-adjustments.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
