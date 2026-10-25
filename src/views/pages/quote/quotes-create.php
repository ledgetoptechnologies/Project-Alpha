<?php
// src/views/pages/quotes-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../components/tax_lookup_control.php';
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
$selectedProject = null;
$selectedProjectId = (int)($_GET['project_id'] ?? 0);
if ($selectedProjectId > 0) {
  $projectStmt = $pdo->prepare('
    SELECT p.id, p.client_id, p.organization_id, p.name, c.name AS client_name, o.name AS organization_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN organizations o ON o.id = p.organization_id
    WHERE p.id = ? AND p.status IN ("active","not_started")
  ');
  $projectStmt->execute([$selectedProjectId]);
  $selectedProject = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>

<section>
  <h2>Create Quote</h2>
  <div id="taxExemptBanner" style="display:none;margin:12px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#78350f">
    <strong>ℹ️ Tax Exempt Organization:</strong> The selected client's organization has a tax-exempt form on file. You can still choose whether to charge taxes.
  </div>
  <form id="quoteForm" method="post" action="/?page=quote/quotes-create" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <?php $issuerChoiceRow = []; require __DIR__ . '/../../components/document_issuer_choice.php'; ?>
    <?php if ($selectedProject): ?>
      <input type="hidden" name="return_to_project" value="<?php echo (int)$selectedProject['id']; ?>">
    <?php endif; ?>
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">

      <!-- Client input -->
      <div data-document-recipient-picker style="grid-column:1/2">
      <label style="position:relative;display:block">
        <div>Client</div>
        <input id="clientInput" data-document-client-search type="text" placeholder="Type client name..." autocomplete="off" value="<?php echo htmlspecialchars((string)($selectedProject['client_name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientId" data-document-client-id type="hidden" name="client_id" value="<?php echo (int)($selectedProject['client_id'] ?? 0) ?: ''; ?>" data-organization-id="<?php echo (int)($selectedProject['organization_id'] ?? 0) ?: ''; ?>" data-organization-name="<?php echo htmlspecialchars((string)($selectedProject['organization_name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <div id="clientSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label data-document-contact-option hidden style="display:flex;align-items:flex-start;gap:8px;margin-top:8px;font-size:13px">
        <input data-document-contact-checkbox type="checkbox" name="show_contact_on_document" value="1">
        <span>Include contact on document <small data-document-organization-name style="display:block;color:var(--muted)"></small></span>
      </label>
      </div>

      <div style="grid-column:2/3">
        <?php echo render_tax_lookup_control('taxPercent', 'tax_percent', 0.0); ?>
      </div>

      <label>
        <div>Discount Type</div>
        <select id="discountType" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValue" type="number" step="0.01" name="discount_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>


    </div>

    <div id="projectSection" style="<?php echo $selectedProject ? 'display:block' : 'display:none'; ?>;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;margin:12px 0">
      <h3 style="margin:0 0 12px 0;color:#374151">Project Association</h3>
      <div style="display:grid;gap:12px">
        <label>
          <div>Add to Existing Project</div>
          <select id="projectSelect" name="project_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="">-- Select Project --</option>
            <?php if ($selectedProject): ?>
              <option value="<?php echo (int)$selectedProject['id']; ?>" selected>
                <?php echo htmlspecialchars((string)$selectedProject['name'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>
                <?php if (!empty($selectedProject['organization_name'])): ?>
                  (<?php echo htmlspecialchars((string)$selectedProject['organization_name'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>)
                <?php endif; ?>
              </option>
            <?php endif; ?>
          </select>
        </label>
        <div style="text-align:center;color:#6b7280;font-size:13px">or</div>
        <div>
          <button type="button" id="createProjectBtn" style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;width:100%">Create New Project</button>
        </div>
      </div>
    </div>

    <?php $documentServiceLocationId = 0; require __DIR__ . '/../../components/document_service_location_fields.php'; ?>

    <div id="customFieldsContainer">
    <?php
    // Dynamically render custom fields for regular documents by default
    // JavaScript will update this when document type changes
    echo renderDocumentCustomFields($pdo, 'regular', []);
    ?>
    </div>

    <?php require __DIR__ . '/../../components/document_agreement_choice.php'; ?>

    <div style="margin:12px 0;padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Billing Mode</div>
      <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
        <input type="checkbox" name="billing_mode" value="hourly" id="billingModeHourly" style="margin-top:3px">
        <div>
          <div style="font-weight:600;color:#1f2937">Hourly billing</div>
          <div style="font-size:13px;color:#4b5563">Use line items as estimated hours and hourly rates. Actual invoice billing can come from tracked time.</div>
        </div>
      </label>
    </div>

    <!-- Recurring billing -->
    <div id="longTermFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 12px 0;color:#374151">Recurring Billing Settings</h3>

      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
        <label>
          <div>Start Date *</div>
          <input id="startDateField" type="date" name="lt_start_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Contract Duration *</div>
          <select id="endDateType" name="lt_end_date_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" onchange="toggleEndDate()">
            <option value="ongoing">Ongoing (Until Terminated)</option>
            <option value="fixed">Fixed End Date</option>
          </select>
        </label>
      </div>

      <div id="endDateField" style="display:none;margin-top:12px">
        <label>
          <div>End Date *</div>
          <input type="date" name="lt_end_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>

      <div id="billingIntervalFields" style="display:grid;gap:12px;grid-template-columns:1fr 1fr;margin-top:12px">
        <label>
          <div>Bill Every *</div>
          <select id="billingIntervalCount" name="lt_billing_interval_count" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="6">6</option>
            <option value="12">12</option>
          </select>
        </label>
        <label>
          <div>Period *</div>
          <select id="billingIntervalUnit" name="lt_billing_interval_unit" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="day">Day(s)</option>
            <option value="week">Week(s)</option>
            <option value="month" selected>Month(s)</option>
            <option value="year">Year(s)</option>
          </select>
        </label>
      </div>

      <div style="margin-top:16px;padding:12px;background:#fef3c7;border-radius:8px;border:1px solid #fbbf24">
        <div style="font-weight:600;margin-bottom:8px;color:#92400e">How should the client be billed?</div>
        <label style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
          <input type="radio" id="recurringPerInvoice" name="lt_pricing_type" value="per_invoice" checked onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Recurring Amount</div>
            <div style="font-size:13px;color:#6b7280">Client pays the same amount on each invoice (e.g., $20/month)</div>
          </div>
        </label>
        <label id="fixedTotalOption" style="display:flex;align-items:start;gap:8px;cursor:pointer">
          <input type="radio" id="recurringFixedTotal" name="lt_pricing_type" value="fixed_total" onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Fixed Total (Billed Over Time)</div>
            <div style="font-size:13px;color:#6b7280">Total quote amount is divided across invoices until paid in full</div>
          </div>
        </label>
      </div>

      <div id="perInvoiceField" style="display:grid;gap:12px;margin-top:12px">
        <?php $rateChoiceRow = []; $rateChoiceAmountId = 'pricePerInvoiceInput'; $rateChoiceCountId = 'billingIntervalCount'; $rateChoiceUnitId = 'billingIntervalUnit'; require __DIR__ . '/../../components/document_monthly_rate.php'; ?>
        <label>
          <div>Amount Per Invoice * <span style="font-size:13px;color:#6b7280;font-weight:normal">(before tax & discount)</span></div>
          <input id="pricePerInvoiceInput" type="number" step="0.01" name="lt_price_per_invoice" placeholder="20.00" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalc()">
        </label>
        <label>
          <div>Service Description</div>
          <textarea name="scope" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="e.g. Website hosting, Google Ads management"></textarea>
        </label>
      </div>

      <div id="fixedTotalFields" style="display:none;margin-top:12px">
        <label>
          <div>Number of Invoices * <span style="font-size:13px;color:#6b7280;font-weight:normal">(how many invoices to divide the total across)</span></div>
          <input id="invoiceCountInput" type="number" step="1" min="1" name="invoice_count" placeholder="4" value="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalc()">
        </label>
        <div id="calculatedPricePerInvoice" style="margin-top:8px;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:600;color:#065f46">Price Per Invoice:</div>
            <div id="calcPriceVal" style="font-size:16px;font-weight:700;color:#065f46">$0.00</div>
          </div>
        </div>
      </div>

      <div id="discountWarning" style="display:none;margin-top:12px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:13px">
        <strong>Note:</strong> For ongoing quotes, discounts apply to each invoice, not the total contract value.
      </div>
    </div>

    <div id="onDemandFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 12px 0;color:#374151">On-Demand Quote Settings</h3>

      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
        <label>
          <div>Start Date</div>
          <input id="onDemandStartDate" type="date" name="od_start_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Contract Duration</div>
          <select id="onDemandEndDateType" name="od_end_date_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" onchange="toggleOnDemandEndDate()">
            <option value="ongoing">Ongoing (Until Terminated)</option>
            <option value="fixed">Fixed End Date</option>
          </select>
        </label>
      </div>

      <div id="onDemandEndDateField" style="display:none;margin-top:12px">
        <label>
          <div>End Date</div>
          <input type="date" name="od_end_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>

      <div style="margin-top:16px;padding:12px;background:#e0f2fe;border-radius:8px;border:1px solid #7dd3fc">
        <div style="font-weight:600;margin-bottom:8px;color:#0369a1">How do you want to specify pricing?</div>
        <label style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
          <input type="radio" name="od_pricing_mode" value="items" checked onchange="toggleOnDemandPricingMode()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Use Line Items</div>
            <div style="font-size:13px;color:#6b7280">Add individual items with quantities and prices</div>
          </div>
        </label>
        <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
          <input type="radio" name="od_pricing_mode" value="flat" onchange="toggleOnDemandPricingMode()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Flat Amount</div>
            <div style="font-size:13px;color:#6b7280">Enter a single amount without itemized details</div>
          </div>
        </label>
      </div>

      <div id="onDemandFlatAmount" style="display:none;margin-top:12px">
        <label>
          <div>Quote Amount * <span style="font-size:13px;color:#6b7280;font-weight:normal">(before tax & discount)</span></div>
          <input id="onDemandAmountInput" type="number" step="0.01" name="od_flat_amount" placeholder="0.00" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalc()">
        </label>
        <label style="margin-top:12px">
          <div>Service Description</div>
          <textarea name="scope" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="e.g. Website hosting, Google Ads management"></textarea>
        </label>
      </div>

      <div style="margin-top:12px;padding:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:13px">
        <strong>Note:</strong> On-demand quotes allow you to generate invoices manually as needed. No recurring billing schedule is set.
      </div>
    </div>
    <?php $travelRuleScope='quote'; require __DIR__.'/../../components/travel_billing_fields.php'; ?>
    <!-- Items input -->
    <div>
      <div style="font-weight:600;margin-bottom:8px">Items / Rates</div>
      <div id="items" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItem()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <!-- Job Notes (shared across related docs) -->
    <label>
      <div>Job Notes (shared across related docs)</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Notes visible to you (not the client PDF)"></textarea>
    </label>

    <!-- Invoice preview (hidden by default) -->

    <div id="invoiceAmountRow" style="display:none;margin-top:8px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div style="font-weight:600;color:#065f46">Amount Per Invoice:</div>
        <div id="invoiceAmountVal" style="font-size:18px;font-weight:700;color:#065f46">$0.00</div>
      </div>
    </div>

    <!-- Totals -->
    <div id="totals" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div>
        <div id="subtotalVal" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div>
        <div id="discountVal" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div>
        <div id="taxVal" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700">
        <div style="min-width:140px;text-align:right">Total</div>
        <div id="totalVal" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div id="depositRow" style="display:flex;border-top:1px solid #e5e7eb;padding-top:6px;margin-top:6px">
        <div style="display:flex;gap:16px;justify-content:flex-end">
          <div style="min-width:140px;text-align:right;color:#059669;font-weight:700;font-size:15px">Deposit Due</div>
          <div id="depositVal" style="min-width:120px;text-align:right;color:#059669;font-weight:700;font-size:15px">$0.00</div>
        </div>
      </div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Quote</button>
    </div>
  </form>
</section>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/quotes-create-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/document-recipient-presentation.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/tax-lookup-control.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/client-selection-dropdown-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/document-billing-choice.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
