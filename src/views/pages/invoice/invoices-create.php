<?php
// src/views/pages/invoice/invoices-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../components/tax_lookup_control.php';
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
$netDays = (int)($appConfig['net_terms_days'] ?? 30); if ($netDays < 0) { $netDays = 0; }
$defaultDue = date('Y-m-d', strtotime('+' . $netDays . ' days'));
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
?>
<section>
  <h2>Create Invoice</h2>
  <p style="margin:-4px 0 16px;color:#6b7280">Create an invoice for a client or their organization.</p>
  <div id="taxExemptBannerInv" style="display:none;margin:12px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#78350f">
    <strong>ℹ️ Tax Exempt Organization:</strong> The selected client's organization has a tax-exempt form on file. You can still choose whether to charge taxes.
  </div>
  <form id="invoiceForm" method="post" action="/?page=invoices-create" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <?php $issuerChoiceRow = []; require __DIR__ . '/../../components/document_issuer_choice.php'; ?>
    <?php if ($selectedProject): ?>
      <input type="hidden" name="return_to_project" value="<?php echo (int)$selectedProject['id']; ?>">
    <?php endif; ?>
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr">
      <div data-document-recipient-picker>
      <label style="position:relative;display:block">
        <div>Client</div>
        <input id="clientInputInv" data-document-client-search type="text" placeholder="Type client name..." autocomplete="off" value="<?php echo htmlspecialchars((string)($selectedProject['client_name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientIdInv" data-document-client-id type="hidden" name="client_id" value="<?php echo (int)($selectedProject['client_id'] ?? 0) ?: ''; ?>" data-organization-id="<?php echo (int)($selectedProject['organization_id'] ?? 0) ?: ''; ?>" data-organization-name="<?php echo htmlspecialchars((string)($selectedProject['organization_name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <div id="clientSuggestInv" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label data-document-contact-option hidden style="display:flex;align-items:flex-start;gap:8px;margin-top:8px;font-size:13px">
        <input data-document-contact-checkbox type="checkbox" name="show_contact_on_document" value="1">
        <span>Include contact on document <small data-document-organization-name style="display:block;color:var(--muted)"></small></span>
      </label>
      </div>
      <label>
        <div>Due Date</div>
        <input type="date" name="due_date" value="<?php echo htmlspecialchars($defaultDue); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div>
        <?php echo render_tax_lookup_control('taxPercentInv', 'tax_percent', 0.0); ?>
      </div>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeInv" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueInv" type="number" step="0.01" name="discount_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div id="generalRecipientServiceLocation">
      <?php $documentServiceLocationId = 0; require __DIR__ . '/../../components/document_service_location_fields.php'; ?>
    </div>

    <div id="customFieldsContainerInv">
    <?php
    // Render custom fields for regular invoices
    echo renderDocumentCustomFields($pdo, 'regular', [], 'Inv');
    ?>
    </div>

    <div style="margin:12px 0;padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Billing Mode</div>
      <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
        <input type="checkbox" name="billing_mode" value="hourly" id="billingModeHourlyInv" style="margin-top:3px">
        <div>
          <div style="font-weight:600;color:#1f2937">Hourly billing</div>
          <div style="font-size:13px;color:#4b5563">Use tracked time or hourly item rows. Fixed-price invoices can leave this off.</div>
        </div>
      </label>
    </div>

    <div id="projectSectionInv" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;margin:12px 0">
      <h3 style="margin:0 0 12px 0;color:#374151">Project Association</h3>
      <div style="display:grid;gap:12px">
        <label>
          <div>Add to Existing Project</div>
          <select id="projectSelectInv" name="project_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="">-- Select Project --</option>
            <?php if ($selectedProject): ?>
              <option value="<?php echo (int)$selectedProject['id']; ?>" data-invoice-billing-period="<?php echo htmlspecialchars((string)($selectedProject['invoice_billing_period'] ?? 'per_invoice'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" selected>
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
          <button type="button" id="createProjectBtnInv" style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;width:100%">Create New Project</button>
        </div>
      </div>
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items / Time</div>
      <div id="itemsInv" style="display:grid;gap:8px"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:8px">
        <button type="button" onclick="addItemInv()" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
        <button type="button" id="btnAddFromTrackedTime" style="padding:8px 12px;border-radius:8px;border:1px solid #2ea3d6;background:#eff6ff;color:#0b4a6a;font-weight:600">+ Add from Tracked Time</button>
        <button type="button" id="btnAddFromMileage" style="padding:8px 12px;border-radius:8px;border:1px solid #2ea3d6;background:#eff6ff;color:#0b4a6a;font-weight:600">+ Add Billable Mileage</button>
      </div>
    </div>

    <label>
      <div>Job Notes (shared across related docs)</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Notes visible to you (not the client PDF)"></textarea>
    </label>

    <div id="totalsInv" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValInv" style="min-width:120px;text-align:right">$0.00</div></div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="submit" name="invoice_action" value="save" class="btn">Save Invoice</button>
      <button type="submit" name="invoice_action" id="finalizeAndSendInvoice" style="" value="finalize_send" class="btn btn-primary">Save &amp; Send</button>
    </div>
    <p id="invoiceProjectBillingHelp" hidden style="margin:-4px 0 0;color:#4b5563;font-size:13px">This invoice will be finalized for the monthly project statement and will not be emailed separately.</p>
  </form>
</section>

<!-- Tracked Time Modal -->
<div id="trackedTimeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="width:100%;max-width:800px;max-height:90vh;overflow:auto">
    <div class="card-head">
      <h3 class="card-title">Add from Tracked Time</h3>
      <button type="button" id="closeTrackedTimeModal" class="btn btn-sm">Close</button>
    </div>
    <div id="trackedTimeLoading" style="padding:30px;text-align:center;color:var(--muted)">Loading unbilled time entries…</div>
    <div id="trackedTimeEmpty" style="display:none;padding:30px;text-align:center;color:var(--muted)">No unbilled billable time entries available.</div>
    <form id="trackedTimeForm" style="display:none">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <div class="expense-table-wrap">
        <table class="pa-table expense-table">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="selectAllTrackedTime"></th>
              <th>Date</th>
              <th>Description</th>
              <th>Hours</th>
              <th>Rate</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody id="trackedTimeTbody"></tbody>
        </table>
      </div>
      <div class="expense-filter-actions" style="margin-top:16px">
        <button type="button" id="btnAddSelectedTrackedTime" class="btn btn-primary">Add Selected to Invoice</button>
      </div>
    </form>
  </div>
</div>

<!-- Billable Mileage Modal -->
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

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/invoices-create-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/document-recipient-presentation.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/tax-lookup-control.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
