<?php
$rateChoiceRow = $rateChoiceRow ?? [];
$rateChoiceBasis = (string)($rateChoiceRow['rate_basis'] ?? 'per_period');
?>
<div data-monthly-rate data-amount-id="<?php echo htmlspecialchars($rateChoiceAmountId, ENT_QUOTES, 'UTF-8'); ?>" data-count-id="<?php echo htmlspecialchars($rateChoiceCountId, ENT_QUOTES, 'UTF-8'); ?>" data-unit-id="<?php echo htmlspecialchars($rateChoiceUnitId, ENT_QUOTES, 'UTF-8'); ?>" style="display:grid;gap:8px">
  <label class="field"><span class="label">Rate basis</span>
    <select class="input" name="rate_basis" data-rate-basis>
      <option value="per_period" <?php echo $rateChoiceBasis === 'per_period' ? 'selected' : ''; ?>>Amount per invoice</option>
      <option value="monthly" <?php echo $rateChoiceBasis === 'monthly' ? 'selected' : ''; ?>>Monthly service rate</option>
    </select>
  </label>
  <label class="field" data-monthly-rate-field><span class="label">Monthly service rate</span>
    <input class="input" name="monthly_rate" data-monthly-rate-input type="number" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)($rateChoiceRow['monthly_rate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
  </label>
  <small data-monthly-rate-preview aria-live="polite">For a monthly service rate, select billing every 1 month or 1 year. Annual invoices cover 12 months at the monthly rate, before tax and discounts.</small>
  <small>Each full billing period must fit within the service end date. Shorter periods need a separately agreed charge; changing frequency does not automatically prorate or refund payments.</small>
</div>
<?php unset($rateChoiceRow, $rateChoiceBasis, $rateChoiceAmountId, $rateChoiceCountId, $rateChoiceUnitId); ?>
