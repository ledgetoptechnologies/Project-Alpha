(() => {
  document.querySelectorAll('[data-recurring-history]').forEach(form => {
    if (form.dataset.ready) return;
    form.dataset.ready = '1';
    const node = name => form.querySelector(`[data-history-${name}]`);
    const inputs = node('inputs'), preview = node('preview'), confirm = node('confirm');
    const record = node('record'), previewButton = node('preview-button'), status = node('status');
    let previewHash = '', busy = false, completed = false, revision = 0;
    const invalidate = () => {
      revision++;
      previewHash = '';
      preview.hidden = true;
      confirm.checked = false;
      record.disabled = true;
      status.textContent = 'Preview again after changing payment or coverage details.';
    };
    inputs.addEventListener('input', invalidate);
    inputs.addEventListener('change', invalidate);
    confirm.addEventListener('change', () => { record.disabled = busy || !previewHash || !confirm.checked; });
    const request = async action => {
      if (busy || completed || !form.reportValidity() || (action === 'record' && (!previewHash || !confirm.checked))) return;
      const data = new FormData(form);
      data.set('history_action', action);
      if (action === 'record') {
        data.set('preview_hash', previewHash);
        data.set('history_confirmed', '1');
      }
      const requestedRevision = revision;
      busy = true;
      inputs.disabled = previewButton.disabled = record.disabled = confirm.disabled = true;
      status.textContent = action === 'record' ? 'Recording paid invoices…' : 'Checking monthly periods…';
      try {
        const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: data });
        const result = await response.json();
        if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to process these paid periods. Please review the details.');
        if (revision !== requestedRevision) return;
        if (action === 'record') {
          completed = true;
          status.textContent = 'Paid invoices recorded. Opening payment history…';
          record.disabled = true;
          if (typeof result.redirect !== 'string' || !result.redirect) throw new Error('Recorded successfully. Reload this page to view payment history.');
          const destination = new URL(result.redirect, window.location.href);
          if (destination.origin !== window.location.origin) throw new Error('Recorded successfully. Reload this page to view payment history.');
          window.location.assign(destination.href);
          return;
        }
        if (typeof result.preview_hash !== 'string' || !result.preview_hash || !Array.isArray(result.periods) || !result.periods.length || typeof result.total !== 'string' || typeof result.next_invoice_date !== 'string') throw new Error('The preview was incomplete. Please try again.');
        node('periods').replaceChildren();
        result.periods.forEach(period => {
          const row = document.createElement('p');
          row.className = 'service-help';
          row.textContent = `${period.start} to ${period.end} (end excluded): subtotal $${period.subtotal}, discount $${period.discount}, tax $${period.tax}, total $${period.total}`;
          node('periods').appendChild(row);
        });
        node('total').textContent = `Total payment: $${result.total} across ${result.months} monthly period(s).`;
        node('next').textContent = `Next invoice date after recording: ${result.next_invoice_date}. Billing activation stays unchanged.`;
        previewHash = result.preview_hash;
        confirm.checked = false;
        preview.hidden = false;
        status.textContent = 'Review the calculated amounts and coverage before confirming.';
      } catch (error) {
        status.textContent = error instanceof Error ? error.message : 'Unable to process the request. Please try again.';
        if (action === 'preview') { previewHash = ''; preview.hidden = true; }
      } finally {
        busy = false;
        inputs.disabled = previewButton.disabled = confirm.disabled = completed;
        record.disabled = completed || !previewHash || !confirm.checked;
      }
    };
    form.addEventListener('submit', event => { event.preventDefault(); request('preview'); });
    record.addEventListener('click', () => request('record'));
  });
})();
