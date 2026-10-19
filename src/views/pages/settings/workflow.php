<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Quote Conversion</legend>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="quote_auto_create_contract" value="1" <?php echo !empty($appConfig['quote_auto_create_contract']) ? 'checked' : ''; ?>>
    <span><strong>Create a contract when a quote is approved</strong><br><small>The contract starts pending and follows its normal signing workflow.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="quote_auto_create_invoice" value="1" <?php echo !empty($appConfig['quote_auto_create_invoice']) ? 'checked' : ''; ?>>
    <span><strong>Create a draft invoice when a regular quote is approved</strong><br><small>The draft remains private and cannot be paid until the contract is completed.</small></span>
  </label>
</fieldset>

<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Locations &amp; Route Assistance</legend>
  <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Manual addresses and mileage always remain available. Google only suggests address details and one-way driving distance.</p>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0"><input type="checkbox" name="address_route_assistance_enabled" value="1" <?php echo !empty($appConfig['address_route_assistance_enabled'])?'checked':''; ?>><span><strong>Enable address and route assistance</strong><br><small>Requires restricted Google keys in System settings. Default is off.</small></span></label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0"><input type="checkbox" name="job_project_locations_enabled" value="1" <?php echo !empty($appConfig['job_project_locations_enabled'])?'checked':''; ?>><span><strong>Enable Job and Project location controls</strong><br><small>Jobs may have one default service location; Projects may have several. Existing manual document addresses are preserved.</small></span></label>
</fieldset>

<?php if (pa_workforce_enabled()): ?>
<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Time Access &amp; Approval</legend>
  <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Workers can access their own permitted time. Team-wide access requires both the matching ACL permission and a worker capability scope under People &amp; Business Units.</p>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="workforce_allow_non_admin_time_management" value="1" <?php echo !empty($appConfig['workforce_allow_non_admin_time_management']) ? 'checked' : ''; ?>>
    <span><strong>Allow non-admin time managers to view and edit team time</strong><br><small>Requires <code>timekeeping.manage</code> in both ACL and the worker's scoped capabilities.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="workforce_allow_non_admin_time_approval" value="1" <?php echo !empty($appConfig['workforce_allow_non_admin_time_approval']) ? 'checked' : ''; ?>>
    <span><strong>Allow non-admin reviewers to review submitted time</strong><br><small>Requires <code>approvals.review</code> in both ACL and the worker's scoped capabilities.</small></span>
  </label>
  <div style="padding:10px 12px;border-radius:8px;background:#f8fafc;border:1px solid #cbd5e1;color:#334155;font-size:13px"><strong>Owner time is self-confirmed.</strong> A worker profile explicitly classified as Owner never enters its own review queue and never creates payroll compensation. Administrator access alone does not make a worker an owner.</div>
</fieldset>

<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Time &amp; Workforce</legend>
  <p style="margin:0 0 14px;color:var(--muted);font-size:13px">Time records what happened. Client billing and worker compensation are separate outcomes with separate rules. Business name and timezone remain under Business &amp; Branding.</p>
  <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
    <label>
      <div>Currency</div>
      <input name="workforce_currency" maxlength="3" value="<?php echo htmlspecialchars((string)($appConfig['workforce_currency'] ?? 'USD')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="USD">
    </label>
    <label>
      <div>Default time capture mode</div>
      <?php $captureMode = (string)($appConfig['workforce_default_capture_mode'] ?? 'duration'); ?>
      <select name="workforce_default_capture_mode" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><option value="duration" <?php echo $captureMode === 'duration' ? 'selected' : ''; ?>>Duration</option><option value="timer" <?php echo $captureMode === 'timer' ? 'selected' : ''; ?>>Timer</option><option value="exact" <?php echo $captureMode === 'exact' ? 'selected' : ''; ?>>Exact start and end</option></select>
    </label>
    <label>
      <div>Fallback worker hourly pay rate</div>
      <input name="workforce_default_hourly_rate" inputmode="decimal" value="<?php echo htmlspecialchars((string)($appConfig['workforce_default_hourly_rate'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional">
      <small style="display:block;margin-top:5px;color:var(--muted)">Used only when an eligible worker has no assignment, worker, or Work Type rule.</small>
    </label>
    <label>
      <div>Fallback client hourly billing rate</div>
      <input name="workforce_default_billing_rate" inputmode="decimal" value="<?php echo htmlspecialchars((string)($appConfig['workforce_default_billing_rate'] ?? '')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional">
      <small style="display:block;margin-top:5px;color:var(--muted)">Used only when time is explicitly prepared for hourly client billing.</small>
    </label>
    <label>
      <div>Default client billing treatment</div>
      <?php $billingTreatment = (string)($appConfig['workforce_default_billing_treatment'] ?? 'undecided'); ?>
      <select name="workforce_default_billing_treatment" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><option value="undecided" <?php echo $billingTreatment === 'undecided' ? 'selected' : ''; ?>>Decide later</option><option value="nonbillable" <?php echo $billingTreatment === 'nonbillable' ? 'selected' : ''; ?>>Internal / nonbillable</option><option value="included_fixed" <?php echo $billingTreatment === 'included_fixed' ? 'selected' : ''; ?>>Included in fixed price</option><option value="ready" <?php echo $billingTreatment === 'ready' ? 'selected' : ''; ?>>Ready for hourly billing</option></select>
    </label>
  </div>
  <div style="display:grid;gap:8px;margin-top:14px">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
      <input type="checkbox" name="workforce_require_project" value="1" <?php echo !empty($appConfig['workforce_require_project']) ? 'checked' : ''; ?>>
      <span>Require workers to select a Job or assigned Project</span>
    </label>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
      <input type="checkbox" name="workforce_require_work_type" value="1" <?php echo !empty($appConfig['workforce_require_work_type']) ? 'checked' : ''; ?>>
      <span>Require workers to classify entries with a Work Type</span>
    </label>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
      <input type="checkbox" name="workforce_require_description" value="1" <?php echo !empty($appConfig['workforce_require_description']) ? 'checked' : ''; ?>>
      <span>Require workers to enter a description</span>
    </label>
  </div>
</fieldset>
<?php endif; ?>

<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Mileage</legend>
  <label>
    <div><strong>Default client mileage rate</strong></div>
    <input type="number" name="default_mileage_rate" step="0.001" min="0" required value="<?php echo htmlspecialchars(number_format((float)($appConfig['default_mileage_rate'] ?? 0.670), 3, '.', '')); ?>" style="width:100%;max-width:220px;margin-top:6px;padding:10px;border-radius:8px;border:1px solid #ddd">
    <small style="display:block;margin-top:5px;color:var(--muted)">Used only for new client travel charges. Physical trip mileage has no monetary value by itself.</small>
  </label>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:14px">
    <label><div><strong>Included miles before client charges begin</strong></div><input type="number" name="default_mileage_included_miles" step="0.001" min="0" required value="<?php echo htmlspecialchars(number_format((float)($appConfig['default_mileage_included_miles'] ?? 0),3,'.','')); ?>" style="width:100%;margin-top:6px;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div><strong>Default client travel-charge method</strong></div><select name="default_mileage_charge_method" style="width:100%;margin-top:6px;padding:10px;border-radius:8px;border:1px solid #ddd"><?php $travelMethod=(string)($appConfig['default_mileage_charge_method']??'actual_trip'); ?><option value="actual_trip" <?php echo $travelMethod==='actual_trip'?'selected':''; ?>>Actual trip distance</option><option value="origin_distance" <?php echo $travelMethod==='origin_distance'?'selected':''; ?>>Billing origin to service location</option><option value="fixed_fee" <?php echo $travelMethod==='fixed_fee'?'selected':''; ?>>Fixed travel fee</option><option value="none" <?php echo $travelMethod==='none'?'selected':''; ?>>No client charge</option></select></label>
  </div>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:14px 0 10px">
    <input type="checkbox" name="default_mileage_include_return_trip" value="1" <?php echo !array_key_exists('default_mileage_include_return_trip', $appConfig) || !empty($appConfig['default_mileage_include_return_trip']) ? 'checked' : ''; ?>>
    <span><strong>Include return miles in new simple trip logs</strong><br><small>The entered distance is one way; total or multi-stop trips always use the full distance entered.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="default_mileage_bill_return_trip" value="1" <?php echo !empty($appConfig['default_mileage_bill_return_trip']) ? 'checked' : ''; ?>>
    <span><strong>Charge return travel by default</strong><br><small>This affects client pricing only. Return miles may still be included in the physical trip log when this is off.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0"><input type="checkbox" name="mileage_tracking_enabled" value="1" <?php echo !empty($appConfig['mileage_tracking_enabled'])?'checked':''; ?>><span><strong>Enable foreground GPS mileage tracking beta</strong><br><small>Allows signed-in users to track a trip while the PA page remains visible on their phone.</small></span></label>
</fieldset>

<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Invoice Delivery</legend>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="invoice_auto_email_on_contract_complete" value="1" <?php echo !empty($appConfig['invoice_auto_email_on_contract_complete']) ? 'checked' : ''; ?>>
    <span><strong>Email a finalized regular invoice when its contract is completed</strong><br><small>Monthly project child invoices remain inside the project statement.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="invoice_auto_email_on_generate" value="1" <?php echo !empty($appConfig['invoice_auto_email_on_generate']) ? 'checked' : ''; ?>>
    <span><strong>Email scheduled long-term invoices when generated</strong><br><small>This generates and emails an invoice. It never charges a saved card.</small></span>
  </label>
</fieldset>

<div style="padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:8px">
  On-demand and manual invoices always ask whether to save a draft or finalize and send.
</div>
