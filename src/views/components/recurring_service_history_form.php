<?php // Authenticated, explicitly confirmed reconciliation of payments already received. ?>
<details style="margin-top:14px">
  <summary>Record paid monthly periods</summary>
  <p class="service-help">Use this for a check already received. Preview each monthly period, then record paid invoices and the payment reference. This does not send emails, collect money, or activate billing.</p>
  <form method="post" action="/?page=long-term-recurring-history" data-recurring-history>
    <input type="hidden" name="csrf" value="<?= $serviceEscape(csrf_token()) ?>">
    <input type="hidden" name="contract_id" value="<?= (int)$id ?>">
    <input type="hidden" name="service_id" value="<?= $serviceId ?>">
    <input type="hidden" name="generation_key" value="<?= bin2hex(random_bytes(16)) ?>">
    <fieldset data-history-inputs style="border:0;padding:0;margin:0">
      <div class="service-grid">
        <label>First paid period starts<input required type="date" name="first_period_start"></label>
        <label>Number of monthly periods<input required type="number" name="months" min="1" max="24" step="1" value="1"></label>
        <label>Payment received on<input required type="date" name="payment_date" value="<?= $serviceEscape(date('Y-m-d')) ?>" max="<?= $serviceEscape(date('Y-m-d')) ?>"></label>
        <label>Check / payment reference<input required type="text" name="reference_number" maxlength="255" autocomplete="off"></label>
        <label>Total payment received ($)<input required type="number" name="paid_amount" min="0.01" step="0.01" inputmode="decimal"></label>
      </div>
    </fieldset>
    <div class="service-actions"><button type="submit" class="btn btn-sm" data-history-preview-button>Preview paid periods</button></div>
    <p class="service-help" role="status" aria-live="polite" data-history-status></p>
    <div data-history-preview hidden>
      <p class="service-help">Coverage includes the start date and stops before the end date. The end date is the next period's start.</p>
      <div data-history-periods></div>
      <p data-history-total></p>
      <p data-history-next></p>
      <label style="display:flex;align-items:flex-start;gap:8px"><input type="checkbox" data-history-confirm> I confirm these periods and this payment were already received and are not recorded elsewhere.</label>
      <div class="service-actions"><button type="button" class="btn btn-sm" data-history-record disabled>Record paid invoices</button></div>
    </div>
    <noscript><p>Enable JavaScript to preview and confirm paid periods before recording them.</p></noscript>
  </form>
</details>
