<?php
$editingService = !empty($serviceFormRow['id']);
$serviceFormDate = $editingService ? ($serviceFormRow['next_invoice_date'] ?: date('Y-m-d')) : date('Y-m-d');
$serviceFormBasis = $serviceFormRow['rate_basis'] ?? 'monthly';
?>
<form class="service-grid" data-service-rate-form method="post" action="/?page=long-term-recurring-service-save" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $serviceEscape(csrf_token()) ?>"><input type="hidden" name="contract_id" value="<?= (int)$id ?>"><input type="hidden" name="service_id" value="<?= (int)($serviceFormRow['id'] ?? 0) ?>"><input type="hidden" name="generation_key" value="<?= bin2hex(random_bytes(16)) ?>">
  <label>Service name<input name="name" required maxlength="190" value="<?= $serviceEscape($serviceFormRow['name'] ?? '') ?>"></label>
  <label>Rate basis<select name="rate_basis"><option value="monthly" <?= $serviceFormBasis === 'monthly' ? 'selected' : '' ?>>Monthly rate</option><option value="per_period" <?= $serviceFormBasis !== 'monthly' ? 'selected' : '' ?>>Amount per invoice</option></select></label>
  <label>Monthly rate ($)<input name="monthly_rate" type="number" min="0.01" step="0.01" value="<?= $serviceEscape($serviceFormRow['monthly_rate'] ?? '') ?>"></label>
  <label>Amount per invoice ($)<input name="amount" type="number" min="0.01" step="0.01" value="<?= $serviceEscape($serviceFormRow['amount'] ?? '') ?>"></label>
  <label>Bill every<input name="billing_interval_count" type="number" min="1" max="120" value="<?= (int)($serviceFormRow['billing_interval_count'] ?? 1) ?>" required></label>
  <label>Billing period<select name="billing_interval_unit"><?php foreach (['month', 'year', 'week', 'day'] as $unit): ?><option value="<?= $unit ?>" <?= ($serviceFormRow['billing_interval_unit'] ?? 'month') === $unit ? 'selected' : '' ?>><?= ucfirst($unit) ?></option><?php endforeach; ?></select></label>
  <?php if ($editingService): ?>
    <input type="hidden" name="effective_from" value="<?= $serviceEscape($serviceFormDate) ?>"><input type="hidden" name="next_invoice_date" value="<?= $serviceEscape($serviceFormDate) ?>">
    <div class="service-wide service-preview">Requested start: <strong><?= $serviceEscape($serviceDate($serviceFormDate)) ?></strong>, at renewal. The current billed period remains unchanged.</div>
  <?php else: ?>
    <label>Service starts<input type="date" name="effective_from" value="<?= date('Y-m-d') ?>" required></label>
    <label>First invoice<input type="date" name="next_invoice_date" value="<?= date('Y-m-d') ?>" required></label>
  <?php endif; ?>
  <label>Service ends (optional)<input type="date" name="effective_until" value="<?= $serviceEscape($serviceFormRow['effective_until'] ?? '') ?>"></label>
  <label>Signed addendum (optional PDF)<input type="file" name="signed_addendum" accept="application/pdf,.pdf"></label>
  <label class="service-wide">Description<input name="description" value="<?= $serviceEscape($serviceFormRow['description'] ?? '') ?>"></label>
  <label class="service-wide" style="display:flex;align-items:center"><input type="checkbox" name="client_approved" value="1"> Client approved these specific terms</label>
  <?php if (!$editingService): ?>
    <details class="service-wide"><summary>Optional initial partial-period charge</summary>
      <p class="service-help">Use only for work before the first full billing period. This adds a separate invoice and requires client approval.</p>
      <div class="service-grid">
        <label>Partial-period subtotal ($)<input type="number" name="proration_amount" min="0" step="0.01" placeholder="0.00"></label>
        <label>Charge description<input name="proration_description" placeholder="Work before the first full month"></label>
        <label style="display:flex;align-items:center"><input type="checkbox" name="send_proration" value="1"> Email this invoice now</label>
      </div>
    </details>
  <?php endif; ?>
  <output class="service-wide service-preview" aria-live="polite" data-service-preview>Monthly rates are multiplied by 12 for annual billing. Taxes and discounts are applied separately.</output>
  <p class="service-wide service-help">Without approval, these terms remain pending. Monthly rates support monthly or annual billing. Amount per invoice preserves custom schedules.</p>
  <div class="service-wide"><button type="submit" class="btn btn-sm"><?= $editingService ? 'Save change for renewal' : 'Add service' ?></button></div>
</form>
<script>
(() => {
  const form = document.currentScript.previousElementSibling;
  const field = name => form.elements.namedItem(name);
  function preview() {
    const monthly = field('rate_basis').value === 'monthly';
    field('monthly_rate').required = monthly;
    field('amount').required = !monthly;
    field('amount').readOnly = monthly;
    const unit = field('billing_interval_unit').value;
    const count = Number(field('billing_interval_count').value);
    const raw = field('monthly_rate').value;
    const valid = /^\d+(\.\d{1,2})?$/.test(raw) && Number(raw) > 0 && count === 1 && ['month', 'year'].includes(unit);
    const output = form.querySelector('[data-service-preview]');
    if (monthly && valid) {
      const parts = raw.split('.');
      const cents = Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
      const amount = cents * (unit === 'year' ? 12 : 1);
      field('amount').value = (amount / 100).toFixed(2);
      output.textContent = '$' + (cents / 100).toFixed(2) + '/month × ' + (unit === 'year' ? 12 : 1) + ' month(s) = $' + (amount / 100).toFixed(2) + ' per invoice, before tax and discount.';
    } else {
      output.textContent = monthly ? 'Enter a monthly rate and choose every 1 month or every 1 year.' : 'The entered amount is charged once per selected billing period, before tax and discount.';
    }
  }
  form.addEventListener('input', preview);
  form.addEventListener('change', preview);
  preview();
})();
</script>
