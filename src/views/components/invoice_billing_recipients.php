<?php
require_once __DIR__ . '/../../utils/billing_recipients.php';
$billingInvoiceId = (int)($inv['id'] ?? 0);
$billingPreference = $billingInvoiceId ? pa_billing_invoice_preferences($pdo, $billingInvoiceId) : null;
$billingSelectedIds = array_map('intval', $billingPreference['recipient_ids'] ?? []);
?>
<fieldset data-invoice-billing-recipients data-invoice-id="<?= $billingInvoiceId ?>" data-project-id="<?= (int)($inv['project_id'] ?? 0) ?>" data-selected-ids="<?= htmlspecialchars(json_encode($billingSelectedIds), ENT_QUOTES, 'UTF-8') ?>" style="min-width:0;padding:14px;border:1px solid #cbd5e1;border-radius:10px">
  <legend style="font-weight:600">Invoice email recipients</legend>
  <input type="hidden" name="billing_recipients_present" value="1">
  <p style="margin:0 0 10px;font-size:13px;color:#475569">The working contact and contract signer stay separate from billing delivery. These recipients also receive invoice reminders and receipts.</p>
  <label style="display:block">Delivery policy
    <select name="billing_recipient_mode" style="display:block;width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px">
      <option value="inherit" <?= !$billingPreference ? 'selected' : '' ?>>Use project / organization default</option>
      <option value="custom" <?= $billingPreference ? 'selected' : '' ?>>Choose recipients for this invoice</option>
    </select>
  </label>
  <div data-billing-custom hidden style="margin-top:12px">
    <div data-billing-candidates style="display:grid;gap:8px;max-height:220px;overflow:auto"></div>
    <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px"><input name="billing_copy_working_contact" type="checkbox" value="1" <?= !empty($billingPreference['copy_working_contact']) ? 'checked' : '' ?>><span>Also include the working contact</span></label>
  </div>
  <div data-billing-summary aria-live="polite" role="status" style="margin-top:12px;padding:10px;background:#f8fafc;border-radius:6px;overflow-wrap:anywhere">Select a working contact to preview billing delivery.</div>
</fieldset>
<script src="<?= htmlspecialchars(asset_url('/assets/js/invoice-billing-recipients.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
