(() => {
  document.querySelectorAll('[data-agreement-choice]').forEach(root => {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const kind = root.querySelector('[data-agreement-kind]');
    const billing = root.querySelector('[data-agreement-billing]');
    const syncFromRadio = () => {
      const selected = root.querySelector('[name="doc_type"]:checked').value;
      kind.value = selected === 'regular' ? 'one_time' : 'ongoing';
      if (selected !== 'regular') billing.value = selected;
      root.querySelector('[data-agreement-billing-field]').hidden = selected === 'regular';
    };
    const sync = () => {
      const selected = kind.value === 'one_time' ? 'regular' : billing.value;
      root.querySelector(`[name="doc_type"][value="${selected}"]`).checked = true;
      root.querySelector('[data-agreement-billing-field]').hidden = selected === 'regular';
      if (typeof toggleDocTypeFields === 'function') toggleDocTypeFields();
    };
    kind.addEventListener('change', sync);
    billing.addEventListener('change', sync);
    syncFromRadio();
    root.querySelector('[data-agreement-controls]').hidden = false;
    root.querySelector('[data-agreement-fallback]').hidden = true;
  });

  document.querySelectorAll('[data-monthly-rate]').forEach(root => {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const form = root.closest('form');
    const basis = root.querySelector('[data-rate-basis]');
    const monthly = root.querySelector('[data-monthly-rate-input]');
    const amount = document.getElementById(root.dataset.amountId);
    const count = document.getElementById(root.dataset.countId);
    const unit = document.getElementById(root.dataset.unitId);
    const update = () => {
      const docType = form.querySelector('[name="doc_type"]:checked');
      const pricing = form.querySelector('[name="pricing_type"]:checked,[name="lt_pricing_type"]:checked');
      const active = (!docType || docType.value === 'long_term') && (!pricing || pricing.value === 'per_invoice');
      const enabled = basis.value === 'monthly';
      root.querySelector('[data-monthly-rate-field]').hidden = !enabled;
      monthly.required = enabled && active;
      monthly.disabled = !active || !enabled;
      amount.readOnly = enabled && active;
      const validCadence = count.value === '1' && ['month', 'year'].includes(unit.value);
      monthly.setCustomValidity(enabled && active && !validCadence ? 'Monthly service rates can be billed every 1 month or 1 year.' : '');
      let preview = 'The amount per invoice stays unchanged when you change frequency. Choose Monthly service rate to calculate annual charges automatically.';
      if (enabled) {
        const rate = Number(monthly.value);
        if (validCadence && rate > 0) {
          const months = unit.value === 'year' ? 12 : 1;
          const minor = Math.round(rate * 100) * months;
          amount.value = (minor / 100).toFixed(2);
          preview = `${rate.toFixed(2)} per month × ${months} ${months === 1 ? 'month' : 'months'} = ${amount.value} per invoice, before tax and discounts.`;
        } else {
          amount.value = '';
          preview = 'Enter a monthly rate and select billing every 1 month or 1 year.';
        }
      }
      root.querySelector('[data-monthly-rate-preview]').textContent = preview;
      if (typeof recalcCo === 'function') recalcCo();
      else if (typeof recalc === 'function') recalc();
    };
    [basis, monthly, count, unit].forEach(input => input.addEventListener('input', update));
    form.addEventListener('change', update);
    update();
  });
})();
