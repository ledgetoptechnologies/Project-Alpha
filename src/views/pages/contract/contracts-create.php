<?php
// src/views/pages/contracts-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../components/tax_lookup_control.php';
$csrf = csrf_sf_token('contracts-create');
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
// TODO: We need to update the logic for the long-term contracts as follows:
//When selecting the start and end date, we should only be able to select the days that are relative to the billing period. For example:
//If the billing period is every week, and the start date is a friday, the user should only be able to select the end date which is a friday. Same goes for billing every month, and year.
// Start date should auto fill to todays date.
// If end date is specified, and the billing is Recurring Amount, then we should be able to calculate the total based on the price per invoice and the duration of the contract with billing frequency.
// If long-term contract is selected, the deposit option should only be available if there is a specific end date, AND the billing is a Fixed Total. The invoices should be evenly divided price of total-deposit. So invoices + deposit = total.
// Fulfillment should not be available on Non-Long-Term contracts.
?>
<section>
  <h2>Create Contract</h2>
  <div id="taxExemptBannerCo" style="display:none;margin:12px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#78350f">
    <strong>ℹ️ Tax Exempt Organization:</strong> The selected client's organization has a tax-exempt form on file. You can still choose whether to charge taxes.
  </div>
  <form id="coCreateForm" method="post" action="/?page=contract/contracts-create" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <?php $issuerChoiceRow = []; require __DIR__ . '/../../components/document_issuer_choice.php'; ?>
    <?php if ($selectedProject): ?>
      <input type="hidden" name="return_to_project" value="<?php echo (int)$selectedProject['id']; ?>">
    <?php endif; ?>
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <div data-document-recipient-picker>
      <label style="position:relative;display:block">
        <div>Client</div>
        <input id="clientInputCo" data-document-client-search name="client" type="text" placeholder="Type client name..." autocomplete="off" value="<?php echo htmlspecialchars((string)($selectedProject['client_name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientIdCo" data-document-client-id type="hidden" name="client_id" value="<?php echo (int)($selectedProject['client_id'] ?? 0) ?: ''; ?>" data-organization-id="<?php echo (int)($selectedProject['organization_id'] ?? 0) ?: ''; ?>" data-organization-name="<?php echo htmlspecialchars((string)($selectedProject['organization_name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <div id="clientSuggestCo" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label data-document-contact-option hidden style="display:flex;align-items:flex-start;gap:8px;margin-top:8px;font-size:13px">
        <input data-document-contact-checkbox type="checkbox" name="show_contact_on_document" value="1">
        <span>Include contact on document <small data-document-organization-name style="display:block;color:var(--muted)"></small></span>
      </label>
      </div>
      <div>
        <?php echo render_tax_lookup_control('taxPercentCo', 'tax_percent', 0.0); ?>
      </div>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeCo" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueCo" type="number" step="0.01" name="discount_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div id="projectSectionCo" style="<?php echo $selectedProject ? 'display:block' : 'display:none'; ?>;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;margin:12px 0">
      <h3 style="margin:0 0 12px 0;color:#374151">Project Association</h3>
      <div style="display:grid;gap:12px">
        <label>
          <div>Add to Existing Project</div>
          <select id="projectSelectCo" name="project_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
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
          <button type="button" id="createProjectBtnCo" style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;width:100%">Create New Project</button>
        </div>
      </div>
    </div>

    <?php $documentServiceLocationId = 0; require __DIR__ . '/../../components/document_service_location_fields.php'; ?>

    <div id="customFieldsContainerCo">
    <?php
    // Dynamically render custom fields for regular contracts by default
    // JavaScript will update this when document type changes
    echo renderDocumentCustomFields($pdo, 'regular', [], 'Co');
    ?>
    </div>

    <?php require __DIR__ . '/../../components/document_agreement_choice.php'; ?>

    <div style="margin:12px 0;padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Billing Mode</div>
      <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
        <input type="checkbox" name="billing_mode" value="hourly" id="billingModeHourlyCo" style="margin-top:3px">
        <div>
          <div style="font-weight:600;color:#1f2937">Hourly billing</div>
          <div style="font-size:13px;color:#4b5563">Use line items as estimated hours and hourly rates. Actual invoice billing can come from tracked time.</div>
        </div>
      </label>
    </div>

    <div id="longTermFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 12px 0;color:#374151">Recurring Billing Settings</h3>

      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
        <label>
          <div>Start Date *</div>
          <input id="startDateFieldCo" type="date" name="start_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Contract Duration *</div>
          <select id="endDateTypeCo" name="end_date_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" onchange="toggleEndDate()">
            <option value="ongoing">Ongoing (Until Terminated)</option>
            <option value="fixed">Fixed End Date</option>
          </select>
        </label>
      </div>

      <div style="margin-top:12px;padding:12px;background:#eff6ff;border-radius:8px;border:1px solid #bfdbfe">
        <div style="font-weight:600;margin-bottom:8px;color:#1e3a8a">Billing Start</div>
        <label style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
          <input type="radio" name="billing_start_mode" value="on_upload" checked style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Start when signed contract is uploaded</div>
            <div style="font-size:13px;color:#6b7280">Default. The first long-term invoice becomes due when the signed contract is received.</div>
          </div>
        </label>
        <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
          <input type="radio" name="billing_start_mode" value="manual" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Manual start</div>
            <div style="font-size:13px;color:#6b7280">Keep recurring invoices paused until you explicitly start billing.</div>
          </div>
        </label>
      </div>

      <div id="endDateFieldCo" style="display:none;margin-top:12px">
        <label>
          <div>End Date *</div>
          <input type="date" name="end_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>

      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr;margin-top:12px">
        <label>
          <div>Bill Every *</div>
          <select id="billingIntervalCount" name="billing_interval_count" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="6">6</option>
            <option value="12">12</option>
          </select>
        </label>
        <label>
          <div>Period *</div>
          <select id="billingIntervalUnit" name="billing_interval_unit" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
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
          <input type="radio" id="recurringPerInvoice" name="pricing_type" value="per_invoice" checked onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Recurring Amount</div>
            <div style="font-size:13px;color:#6b7280">Client pays the same amount on each invoice (e.g., $20/month)</div>
          </div>
        </label>
        <label id="fixedTotalOption" style="display:flex;align-items:start;gap:8px;cursor:pointer">
          <input type="radio" id="recurringFixedTotal" name="pricing_type" value="fixed_total" onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Fixed Total (Billed Over Time)</div>
            <div style="font-size:13px;color:#6b7280">Total contract amount is divided across invoices until paid in full</div>
          </div>
        </label>
      </div>

      <div id="perInvoiceField" style="margin-top:12px">
        <?php $rateChoiceRow = []; $rateChoiceAmountId = 'pricePerInvoiceInput'; $rateChoiceCountId = 'billingIntervalCount'; $rateChoiceUnitId = 'billingIntervalUnit'; require __DIR__ . '/../../components/document_monthly_rate.php'; ?>
        <label>
          <div>Amount Per Invoice * <span style="font-size:13px;color:#6b7280;font-weight:normal">(before tax & discount)</span></div>
          <input id="pricePerInvoiceInput" type="number" step="0.01" name="price_per_invoice" placeholder="20.00" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalcCo()">
        </label>
      </div>

      <div id="fixedTotalFieldsCo" style="display:none;margin-top:12px">
        <label>
          <div>Number of Invoices * <span style="font-size:13px;color:#6b7280;font-weight:normal">(how many invoices to divide the total across)</span></div>
          <input id="invoiceCountInputCo" type="number" step="1" min="1" name="invoice_count" placeholder="4" value="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalcCo()">
        </label>
        <div id="calculatedPricePerInvoiceCo" style="margin-top:8px;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:600;color:#065f46">Price Per Invoice:</div>
            <div id="calcPriceValCo" style="font-size:16px;font-weight:700;color:#065f46">$0.00</div>
          </div>
        </div>
      </div>

      <div id="discountWarning" style="display:none;margin-top:12px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:13px">
        <strong>Note:</strong> For ongoing contracts, discounts apply to each invoice, not the total contract value.
      </div>
    </div>

    <div id="onDemandFieldsCo" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 12px 0;color:#374151">On-Demand Contract Settings</h3>
      
      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
        <label>
          <div>Start Date</div>
          <input id="onDemandStartDateCo" type="date" name="od_start_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Contract Duration</div>
          <select id="onDemandEndDateTypeCo" name="od_end_date_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" onchange="toggleOnDemandEndDateCo()">
            <option value="ongoing">Ongoing (Until Terminated)</option>
            <option value="fixed">Fixed End Date</option>
          </select>
        </label>
      </div>
      
      <div id="onDemandEndDateFieldCo" style="display:none;margin-top:12px">
        <label>
          <div>End Date</div>
          <input type="date" name="od_end_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>

      <div style="margin-top:16px;padding:12px;background:#e0f2fe;border-radius:8px;border:1px solid #7dd3fc">
        <div style="font-weight:600;margin-bottom:8px;color:#0369a1">How do you want to specify pricing?</div>
        <label style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
          <input type="radio" name="od_pricing_mode" value="items" checked onchange="toggleOnDemandPricingModeCo()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Use Line Items</div>
            <div style="font-size:13px;color:#6b7280">Add individual items with quantities and prices</div>
          </div>
        </label>
        <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
          <input type="radio" name="od_pricing_mode" value="flat" onchange="toggleOnDemandPricingModeCo()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Flat Amount</div>
            <div style="font-size:13px;color:#6b7280">Enter a single amount without itemized details</div>
          </div>
        </label>
      </div>

      <div id="onDemandFlatAmountCo" style="display:none;margin-top:12px">
        <label>
          <div>Contract Amount * <span style="font-size:13px;color:#6b7280;font-weight:normal">(before tax & discount)</span></div>
          <input id="onDemandAmountInputCo" type="number" step="0.01" name="od_flat_amount" placeholder="0.00" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalcCo()">
        </label>
      </div>

      <div style="margin-top:12px;padding:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:13px">
        <strong>ℹ️ Note:</strong> On-demand contracts allow you to generate invoices manually as needed. No recurring billing schedule is set.
      </div>
    </div>

    <?php $travelRuleScope='contract'; require __DIR__.'/../../components/travel_billing_fields.php'; ?>
    <div>
      <div style="font-weight:600;margin-bottom:8px">Items / Rates</div>
      <div id="itemsCo" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItemCo()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <?php if (!isset($appConfig['contract_scope_enabled']) || !empty($appConfig['contract_scope_enabled'])): ?>
      <label>
        <div>Scope of Work</div>
        <textarea name="scope" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional: Describe the scope of work and deliverables for this contract..."></textarea>
      </label>
    <?php endif; ?>

    <label>
      <div>Job Notes (shared across related docs)</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Notes visible to you (not the client PDF)"></textarea>
    </label>

    <div id="invoiceAmountRow" style="display:none;margin-top:8px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div style="font-weight:600;color:#065f46">Amount Per Invoice:</div>
        <div id="invoiceAmountVal" style="font-size:18px;font-weight:700;color:#065f46">$0.00</div>
      </div>
    </div>

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
      <div id="depositRowCo" style="display:none;border-top:1px solid #e5e7eb;padding-top:6px;margin-top:6px">
        <div style="display:flex;gap:16px;justify-content:flex-end">
          <div style="min-width:140px;text-align:right;color:#059669;font-weight:700;font-size:15px">Deposit Due</div>
          <div id="depositValCo" style="min-width:120px;text-align:right;color:#059669;font-weight:700;font-size:15px">$0.00</div>
        </div>
      </div>
    </div>

    <!-- Signatures Section -->
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 8px 0;font-size:15px">Contract Signatures</h3>
      <p style="margin:0 0 12px 0;font-size:13px;color:var(--muted)">Add up to 5 signatures for this contract</p>

      <div id="signaturesList" style="display:grid;gap:12px"></div>

      <button type="button" onclick="addSignature()" id="addSigBtn"
        style="margin-top:12px;padding:8px 14px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px">
        + Add Signature
      </button>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Contract</button>
    </div>
  </form>
</section>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/contracts-create-logic.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/document-recipient-presentation.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/tax-lookup-control.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/document-billing-choice.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
