<?php
// New quote, contract or invoice: ?as=quote|contract|invoice, ?kind=one_time|recurring|as_needed.
// The kind of work comes first; quotes and contracts post doc_type to their create
// controllers, invoices are always one-time (recurring and as-needed invoices come
// from a contract).
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../../utils/document_new.php';
require_once __DIR__ . '/../../components/tax_lookup_control.php';

$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$userId = (int)($_SESSION['user']['id'] ?? 0);
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$allowedTypes = array_keys(array_filter(DOC_NEW_TYPES, static fn (string $perm): bool => $isAdmin || user_can($pdo, $userId, $perm, 0)));

$as = document_new_as($_GET['as'] ?? null);
$isInvoice = $as === 'invoice';
$listKind = is_string($_GET['kind'] ?? null) && isset(DOC_NEW_KIND_TYPES[$_GET['kind']]) ? $_GET['kind'] : '';
$kind = $listKind;
if ($isInvoice || $kind === '') {
    $kind = 'one_time';
}
$docType = DOC_NEW_KIND_TYPES[$kind];
$sfx = $isInvoice ? 'Inv' : '';

$selectedProject = null;
$selectedProjectId = (int)($_GET['project_id'] ?? 0);
if ($selectedProjectId > 0) {
    $projectStmt = $pdo->prepare('
        SELECT p.id, p.client_id, p.organization_id, p.name, p.invoice_billing_period, c.name AS client_name, o.name AS organization_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN organizations o ON o.id = p.organization_id
        WHERE p.id = ? AND p.status IN ("active","not_started")
    ');
    $projectStmt->execute([$selectedProjectId]);
    $selectedProject = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$projectQuery = $selectedProject ? ['project_id' => (int)$selectedProject['id']] : [];

$kinds = [
    'one_time' => ['One-time job', 'A single deliverable, billed once.'],
    'recurring' => ['Recurring service', 'Billed on a schedule until it ends.'],
    'as_needed' => ['As-needed', 'Agreed rates; you bill each visit.'],
];
$flows = [
    'quote' => [
        'regular' => 'Client approves the quote → a contract and its one invoice are created.',
        'long_term' => 'Client approves → a contract starts and invoices go out on the schedule below.',
        'on_demand' => 'Client approves → an open contract; you create an invoice each time you do the work.',
    ],
    'contract' => [
        'regular' => 'The contract’s one invoice is created with it, for the balance after any deposit.',
        'long_term' => 'Client signs → invoices go out on the schedule below.',
        'on_demand' => 'Client signs → an open contract; you create an invoice each time you do the work.',
    ],
    'invoice' => [
        'regular' => 'A standalone invoice with no contract.',
    ],
];

// Deposit sits under Pricing options; the other custom fields under Dates and terms.
$depositEnabled = !$isInvoice && (bool)array_filter(getCustomFields($pdo, 'regular'), static fn (array $f): bool => !empty($f['is_builtin']) && $f['field_key'] === 'deposit');
$customFieldsHtml = renderDocumentCustomFields($pdo, 'regular', [], $sfx, ['deposit']);
$scopeEnabled = !isset($appConfig['contract_scope_enabled']) || !empty($appConfig['contract_scope_enabled']);
$listUrl = ['quote' => '/?page=quote/quotes-list', 'contract' => '/?page=contract/contracts-list', 'invoice' => '/?page=invoice/invoices-list'][$as];
if ($listKind !== '') {
    $listUrl .= '&kind=' . $listKind;
}
$formAction = '/?' . http_build_query(['page' => 'document/new', 'as' => $as]);
if ($isInvoice) {
    require_once __DIR__ . '/../../../utils/workforce_availability.php';
    require_once __DIR__ . '/../../../utils/autopay_beta.php';
    $netDays = max(0, (int)($appConfig['net_terms_days'] ?? 30));
    $defaultDue = date('Y-m-d', strtotime('+' . $netDays . ' days'));
}
?>
<section class="doc-new">
  <div class="page-head">
    <h2>New <?php echo $e($as); ?></h2>
    <a class="btn" href="<?php echo $e($listUrl); ?>">Cancel</a>
  </div>
  <?php if (is_string($_GET['error'] ?? null) && $_GET['error'] !== ''): ?>
    <div class="doc-flash doc-flash--bad" role="alert"><?php echo $e($_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (count($allowedTypes) > 1): ?>
  <nav class="doc-tabs" aria-label="Document type">
    <?php foreach ($allowedTypes as $type): ?>
      <a href="<?php echo $e(document_new_url($type, $kind, $projectQuery)); ?>" data-doc-new-as class="<?php echo $type === $as ? 'active' : ''; ?>"<?php echo $type === $as ? ' aria-current="page"' : ''; ?>><?php echo $e(ucfirst($type)); ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <div id="taxExemptBanner<?php echo $sfx; ?>" class="doc-flash doc-flash--info" style="display:none">
    <strong>Tax-exempt organization:</strong> the selected client's organization has a tax-exempt form on file. You can still choose whether to charge tax.
  </div>

  <form id="<?php echo $isInvoice ? 'invoiceForm' : 'docNewForm'; ?>" class="doc-new-form" method="post" action="<?php echo $e($formAction); ?>">
    <input type="hidden" name="csrf" value="<?php echo $e(csrf_token()); ?>">
    <?php if ($selectedProject): ?>
      <input type="hidden" name="return_to_project" value="<?php echo (int)$selectedProject['id']; ?>">
    <?php endif; ?>

    <div class="card">
      <h3 class="card-title">What kind of work is this?</h3>
      <div class="doc-new-kinds">
        <?php foreach ($kinds as $key => [$title, $text]): ?>
          <?php if ($isInvoice && $key !== 'one_time'): ?>
            <a class="doc-new-kind" href="<?php echo $e(document_new_url('contract', $key, $projectQuery)); ?>"><strong><?php echo $e($title); ?></strong><span>Comes from a contract. Switch to Contract.</span></a>
          <?php else: ?>
            <label class="doc-new-kind"><input type="radio" name="doc_type" value="<?php echo $e(DOC_NEW_KIND_TYPES[$key]); ?>" <?php echo $key === $kind ? 'checked' : ''; ?>><strong><?php echo $e($title); ?></strong><span><?php echo $e($text); ?></span></label>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php foreach ($flows[$as] as $type => $text): ?>
        <p class="doc-new-flow" data-kind-only="<?php echo $e($type); ?>"<?php echo $type === $docType ? '' : ' hidden'; ?>><?php echo $e($text); ?></p>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h3 class="card-title">Client and project</h3>
      <div class="doc-new-grid">
        <div data-document-recipient-picker>
          <label class="field" style="position:relative">
            <span class="label">Client</span>
            <input id="clientInput<?php echo $sfx; ?>" class="input" data-document-client-search <?php echo $as === 'contract' ? 'name="client"' : ''; ?> type="text" placeholder="Type client name..." autocomplete="off" value="<?php echo $e($selectedProject['client_name'] ?? ''); ?>">
            <input id="clientId<?php echo $sfx; ?>" data-document-client-id type="hidden" name="client_id" value="<?php echo (int)($selectedProject['client_id'] ?? 0) ?: ''; ?>" data-organization-id="<?php echo (int)($selectedProject['organization_id'] ?? 0) ?: ''; ?>" data-organization-name="<?php echo $e($selectedProject['organization_name'] ?? ''); ?>">
            <div id="clientSuggest<?php echo $sfx; ?>" class="doc-new-suggest" style="display:none"></div>
          </label>
          <label data-document-contact-option hidden style="display:flex;align-items:flex-start;gap:8px;font-size:13px">
            <input data-document-contact-checkbox type="checkbox" name="show_contact_on_document" value="1">
            <span>Include contact on document <small data-document-organization-name style="display:block;color:var(--muted)"></small></span>
          </label>
        </div>
        <div<?php echo $isInvoice ? ' id="generalRecipientServiceLocation"' : ''; ?>>
          <?php $documentServiceLocationId = 0; require __DIR__ . '/../../components/document_service_location_fields.php'; ?>
        </div>
      </div>
      <div id="projectSection<?php echo $sfx; ?>" class="doc-new-grid" style="<?php echo $selectedProject ? '' : 'display:none'; ?>">
        <label class="field">
          <span class="label">Project</span>
          <select id="projectSelect<?php echo $sfx; ?>" class="input" name="project_id">
            <option value="">-- Select Project --</option>
            <?php if ($selectedProject): ?>
              <option value="<?php echo (int)$selectedProject['id']; ?>" data-invoice-billing-period="<?php echo $e($selectedProject['invoice_billing_period'] ?? 'per_invoice'); ?>" selected>
                <?php echo $e($selectedProject['name']); ?><?php echo !empty($selectedProject['organization_name']) ? ' (' . $e($selectedProject['organization_name']) . ')' : ''; ?>
              </option>
            <?php endif; ?>
          </select>
        </label>
        <div class="field doc-new-end">
          <button type="button" id="createProjectBtn<?php echo $sfx; ?>" class="btn">+ New project for this client</button>
        </div>
      </div>
      <?php $issuerChoiceRow = []; require __DIR__ . '/../../components/document_issuer_choice.php'; ?>
    </div>

    <?php if (!$isInvoice): ?>
    <div class="card" data-kind-only="long_term"<?php echo $docType === 'long_term' ? '' : ' hidden'; ?>>
      <h3 class="card-title">Schedule</h3>
      <div class="doc-new-grid">
        <label class="field"><span class="label">Bill every *</span>
          <select id="billingIntervalCount" class="input" name="lt_billing_interval_count">
            <?php foreach ([1, 2, 3, 6, 12] as $n): ?><option value="<?php echo $n; ?>"><?php echo $n; ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span class="label">Period *</span>
          <select id="billingIntervalUnit" class="input" name="lt_billing_interval_unit">
            <option value="day">Day(s)</option>
            <option value="week">Week(s)</option>
            <option value="month" selected>Month(s)</option>
            <option value="year">Year(s)</option>
          </select>
        </label>
        <label class="field"><span class="label">Start date *</span>
          <input id="startDateField" class="input" type="date" name="lt_start_date">
        </label>
        <label class="field"><span class="label">Term *</span>
          <select id="endDateType" class="input" name="lt_end_date_type">
            <option value="ongoing">Ongoing (until ended)</option>
            <option value="fixed">Fixed end date</option>
          </select>
        </label>
        <label class="field" id="endDateField" hidden><span class="label">End date *</span>
          <input id="endDateInput" class="input" type="date" name="lt_end_date">
        </label>
        <?php if ($as === 'contract'): ?>
        <label class="field"><span class="label">Billing starts</span>
          <select class="input" name="billing_start_mode">
            <option value="on_upload">When the signed contract is uploaded</option>
            <option value="manual">When I start it</option>
          </select>
        </label>
        <?php endif; ?>
      </div>
      <fieldset class="doc-new-choice">
        <legend class="label">How is the client billed?</legend>
        <label><input type="radio" id="recurringPerInvoice" name="lt_pricing_type" value="per_invoice" checked> The same amount on each invoice</label>
        <label id="fixedTotalOption" hidden><input type="radio" id="recurringFixedTotal" name="lt_pricing_type" value="fixed_total"> A fixed total split across invoices</label>
      </fieldset>
      <div id="perInvoiceField" class="doc-new-grid">
        <?php $rateChoiceRow = []; $rateChoiceAmountId = 'pricePerInvoiceInput'; $rateChoiceCountId = 'billingIntervalCount'; $rateChoiceUnitId = 'billingIntervalUnit'; require __DIR__ . '/../../components/document_monthly_rate.php'; ?>
        <label class="field"><span class="label">Amount per invoice * <small>(before tax and discount)</small></span>
          <input id="pricePerInvoiceInput" class="input" type="number" step="0.01" name="lt_price_per_invoice" placeholder="20.00">
        </label>
      </div>
      <div id="fixedTotalFields" class="doc-new-grid" hidden>
        <label class="field"><span class="label">Number of invoices *</span>
          <input id="invoiceCountInput" class="input" type="number" step="1" min="1" name="invoice_count" value="4">
        </label>
        <div class="field"><span class="label">Price per invoice</span><strong id="calcPriceVal">$0.00</strong></div>
      </div>
      <div id="discountWarning" class="doc-flash doc-flash--info" hidden>For ongoing agreements, discounts apply to each invoice, not the total.</div>
    </div>

    <div class="card" data-kind-only="on_demand"<?php echo $docType === 'on_demand' ? '' : ' hidden'; ?>>
      <h3 class="card-title">Rates</h3>
      <div class="doc-new-grid">
        <label class="field"><span class="label">Start date</span>
          <input id="onDemandStartDate" class="input" type="date" name="od_start_date">
        </label>
        <label class="field"><span class="label">Term</span>
          <select id="onDemandEndDateType" class="input" name="od_end_date_type">
            <option value="ongoing">Ongoing (until ended)</option>
            <option value="fixed">Fixed end date</option>
          </select>
        </label>
        <label class="field" id="onDemandEndDateField" hidden><span class="label">End date</span>
          <input class="input" type="date" name="od_end_date">
        </label>
      </div>
      <fieldset class="doc-new-choice">
        <legend class="label">Pricing</legend>
        <label><input type="radio" name="od_pricing_mode" value="items" checked> Line items with rates</label>
        <label><input type="radio" name="od_pricing_mode" value="flat"> One flat amount</label>
      </fieldset>
      <div id="onDemandFlatAmount" class="doc-new-grid" hidden>
        <label class="field"><span class="label">Amount * <small>(before tax and discount)</small></span>
          <input id="onDemandAmountInput" class="input" type="number" step="0.01" name="od_flat_amount" placeholder="0.00">
        </label>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isInvoice || $customFieldsHtml !== ''): ?>
    <div class="card">
      <h3 class="card-title">Dates and terms</h3>
      <?php if ($isInvoice): ?>
        <div class="doc-new-grid">
          <label class="field"><span class="label">Due date</span>
            <input class="input" type="date" name="due_date" value="<?php echo $e($defaultDue); ?>">
          </label>
        </div>
      <?php endif; ?>
      <div id="customFieldsContainer<?php echo $sfx; ?>"><?php echo $customFieldsHtml; ?></div>
    </div>
    <?php endif; ?>

    <?php if ($isInvoice): ?>
    <section class="card invoice-item-editor" aria-labelledby="invoiceItemsHeading">
      <h3 class="card-title" id="invoiceItemsHeading">Line items</h3>
      <div id="itemsInv" class="invoice-item-editor__rows"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:8px">
        <button type="button" onclick="addItemInv().focus()" class="btn btn-sm">+ Add item</button>
        <?php if (pa_workforce_enabled()): ?>
        <button type="button" id="btnAddFromTrackedTime" class="btn btn-sm btn-info">+ Add from tracked time</button>
        <button type="button" id="btnAddFromMileage" class="btn btn-sm btn-info">+ Add billable mileage</button>
        <?php endif; ?>
      </div>
    </section>
    <?php else: ?>
    <div class="card" id="itemsCard">
      <h3 class="card-title">Line items</h3>
      <div id="items" style="display:grid;gap:8px"></div>
      <button type="button" id="addItemBtn" class="btn btn-sm" style="margin-top:8px">+ Add item</button>
    </div>
    <?php $travelRuleScope = $as; require __DIR__ . '/../../components/travel_billing_fields.php'; ?>
    <?php if ($scopeEnabled || $as === 'quote'): ?>
    <label class="field"<?php echo $as === 'quote' ? ' data-kind-only="long_term on_demand"' . ($docType === 'regular' ? ' hidden' : '') : ''; ?>>
      <span class="label">Scope of work</span>
      <textarea class="input" name="scope" rows="3" placeholder="e.g. Website hosting, monthly site survey"></textarea>
    </label>
    <?php endif; ?>
    <?php endif; ?>

    <div class="card">
      <details class="doc-new-more">
        <summary><?php echo $isInvoice ? 'Pricing options: tax, discount' : 'Pricing options: tax, discount, deposit'; ?></summary>
        <div class="doc-new-grid">
          <div><?php echo render_tax_lookup_control('taxPercent' . $sfx, 'tax_percent', 0.0); ?></div>
          <label class="field"><span class="label">Discount type</span>
            <select id="discountType<?php echo $sfx; ?>" class="input" name="discount_type">
              <option value="none">None</option>
              <option value="percent">Percent</option>
              <option value="fixed">Fixed $</option>
            </select>
          </label>
          <label class="field"><span class="label">Discount value</span>
            <input id="discountValue<?php echo $sfx; ?>" class="input" type="number" step="0.01" name="discount_value" value="0">
          </label>
          <?php if ($depositEnabled) echo renderDepositField([]); ?>
          <label class="field doc-new-check"><input type="checkbox" name="billing_mode" value="hourly" id="billingModeHourly<?php echo $sfx; ?>"> Hourly billing (line items are hours × hourly rate)</label>
        </div>
      </details>
    </div>

    <label class="field"><span class="label">Job notes (internal, shared across related documents)</span>
      <textarea class="input" name="project_notes" rows="3" placeholder="Not shown on the client PDF"></textarea>
    </label>

    <?php if (!$isInvoice): ?>
    <div id="invoiceAmountRow" class="doc-new-total-row" style="display:none"><span>Amount per invoice</span><strong id="invoiceAmountVal">$0.00</strong></div>
    <?php endif; ?>
    <div class="doc-new-totals">
      <div><span>Subtotal</span><span id="subtotalVal<?php echo $sfx; ?>">$0.00</span></div>
      <div><span>Discount</span><span id="discountVal<?php echo $sfx; ?>">$0.00</span></div>
      <div><span>Tax</span><span id="taxVal<?php echo $sfx; ?>">$0.00</span></div>
      <div class="doc-new-grand"><span>Total</span><span id="totalVal<?php echo $sfx; ?>">$0.00</span></div>
      <?php if (!$isInvoice): ?>
      <div id="depositRow" style="display:none" class="doc-new-deposit"><span>Deposit due</span><span id="depositVal">$0.00</span></div>
      <?php endif; ?>
    </div>

    <?php if ($as === 'contract'): ?>
    <div class="card">
      <h3 class="card-title">Signatures</h3>
      <div id="signaturesList" style="display:grid;gap:12px"></div>
      <button type="button" id="addSigBtn" class="btn btn-sm" style="margin-top:12px">+ Add signature</button>
    </div>
    <?php endif; ?>

    <?php if ($isInvoice && pa_autopay_enabled($appConfig)): ?>
    <aside data-invoice-autopay-preview data-invoice-id="0" aria-live="polite" style="padding:12px;border:1px solid #cbd5e1;border-radius:8px"><strong>AutoPay — test mode</strong><p data-autopay-preview-text>Choose a client to check authorization. Saving a draft never charges.</p></aside>
    <?php endif; ?>

    <div class="doc-new-actions">
      <?php if ($isInvoice): ?>
        <button type="submit" name="invoice_action" value="save" class="btn">Save Invoice</button>
        <button type="submit" name="invoice_action" id="finalizeAndSendInvoice" value="finalize_send" class="btn btn-primary">Save &amp; Send</button>
      <?php else: ?>
        <button type="submit" class="btn btn-primary">Create <?php echo $e($as); ?></button>
      <?php endif; ?>
    </div>
    <?php if ($isInvoice): ?>
    <p id="invoiceProjectBillingHelp" hidden style="margin:0;color:#4b5563;font-size:13px">This invoice will be finalized for the monthly project statement and will not be emailed separately.</p>
    <?php endif; ?>
  </form>
</section>

<?php if ($isInvoice): ?>
<?php if (pa_workforce_enabled()): ?>
<div id="trackedTimeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="width:100%;max-width:800px;max-height:90vh;overflow:auto">
    <div class="card-head">
      <h3 class="card-title">Add from Tracked Time</h3>
      <button type="button" id="closeTrackedTimeModal" class="btn btn-sm">Close</button>
    </div>
    <div id="trackedTimeLoading" style="padding:30px;text-align:center;color:var(--muted)">Loading unbilled time entries…</div>
    <div id="trackedTimeEmpty" style="display:none;padding:30px;text-align:center;color:var(--muted)">No unbilled billable time entries available.</div>
    <form id="trackedTimeForm" style="display:none">
      <input type="hidden" name="csrf" value="<?php echo $e(csrf_token()); ?>">
      <div class="expense-table-wrap">
        <table class="pa-table expense-table">
          <thead><tr><th style="width:40px"><input type="checkbox" id="selectAllTrackedTime"></th><th>Date</th><th>Description</th><th>Hours</th><th>Rate</th><th>Amount</th></tr></thead>
          <tbody id="trackedTimeTbody"></tbody>
        </table>
      </div>
      <div class="expense-filter-actions" style="margin-top:16px">
        <button type="button" id="btnAddSelectedTrackedTime" class="btn btn-primary">Add Selected to Invoice</button>
      </div>
    </form>
  </div>
</div>

<div id="billableMileageModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="width:100%;max-width:850px;max-height:90vh;overflow:auto">
    <div class="card-head">
      <h3 class="card-title">Add Billable Mileage</h3>
      <button type="button" id="closeBillableMileageModal" class="btn btn-sm">Close</button>
    </div>
    <div id="billableMileageLoading" style="padding:30px;text-align:center;color:var(--muted)">Loading unbilled mileage entries…</div>
    <div id="billableMileageEmpty" style="display:none;padding:30px;text-align:center;color:var(--muted)">No unbilled mileage is marked billable for this client.</div>
    <div id="billableMileageContent" style="display:none">
      <div class="expense-table-wrap">
        <table class="pa-table expense-table">
          <thead><tr><th style="width:40px"><input type="checkbox" id="selectAllBillableMileage"></th><th>Date</th><th>Trip</th><th>Logged / Billable Miles</th><th>Rate</th><th>Amount</th></tr></thead>
          <tbody id="billableMileageTbody"></tbody>
        </table>
      </div>
      <div class="expense-filter-actions" style="margin-top:16px">
        <button type="button" id="btnAddSelectedMileage" class="btn btn-primary">Add Selected to Invoice</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<script src="<?php echo $e(asset_url('/assets/js/invoices-create-logic.js')); ?>" defer></script>
<?php if (pa_autopay_enabled($appConfig)): ?><script src="<?php echo $e(asset_url('/assets/js/invoice-autopay-preview.js')); ?>" defer></script><?php endif; ?>
<?php else: ?>
<script src="<?php echo $e(asset_url('/assets/js/document-new.js')); ?>" defer></script>
<script src="<?php echo $e(asset_url('/assets/js/document-billing-choice.js')); ?>" defer></script>
<?php endif; ?>
<script src="<?php echo $e(asset_url('/assets/js/document-recipient-presentation.js')); ?>" defer></script>
<script src="<?php echo $e(asset_url('/assets/js/tax-lookup-control.js')); ?>" defer></script>
