(function () {
  'use strict';
  function init() {
  document.querySelectorAll('[data-invoice-billing-recipients]').forEach(function (panel) {
    var form = panel.closest('form');
    if (!form || panel.dataset.billingRecipientsReady) return;
    panel.dataset.billingRecipientsReady = '1';
    var mode = panel.querySelector('[name="billing_recipient_mode"]');
    var custom = panel.querySelector('[data-billing-custom]');
    var candidates = panel.querySelector('[data-billing-candidates]');
    var summary = panel.querySelector('[data-billing-summary]');
    var selected = new Set(JSON.parse(panel.dataset.selectedIds || '[]').map(String));
    var sequence = 0;
    var timer;
    var lastContext = '';
    var previewReady = false;
    function field(name) { return form.querySelector('[name="' + name + '"]'); }
    function requestPreview() {
      var general = field('recipient_presentation_mode');
      var hidden = general && general.value === 'general';
      panel.hidden = Boolean(hidden);
      panel.disabled = Boolean(hidden);
      if (hidden) { sequence++; previewReady = true; return; }
      previewReady = false;
      custom.hidden = mode.value !== 'custom';
      var client = field('client_id');
      var project = field('project_id');
      var context = (client ? client.value : '') + ':' + (project ? project.value : panel.dataset.projectId);
      if (lastContext && context !== lastContext) selected.clear();
      lastContext = context;
      if (!client || !client.value) { summary.textContent = 'Select a working contact to preview billing delivery.'; candidates.replaceChildren(); return; }
      var body = new FormData();
      body.set('csrf', field('csrf').value);
      body.set('invoice_id', panel.dataset.invoiceId || '0');
      body.set('client_id', client.value);
      body.set('project_id', project ? project.value : panel.dataset.projectId || '');
      body.set('billing_recipient_mode', mode.value);
      selected.forEach(function (id) { body.append('billing_recipient_ids[]', id); });
      if (panel.querySelector('[name="billing_copy_working_contact"]').checked) body.set('billing_copy_working_contact', '1');
      var current = ++sequence;
      summary.textContent = 'Checking billing recipients…';
      fetch('/?page=invoice-billing-preview', {method:'POST', body:body, credentials:'same-origin', headers:{'Accept':'application/json'}})
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (current !== sequence) return;
          if (!result.success) throw new Error(result.message || 'Billing preview unavailable.');
          previewReady = Boolean((result.recipients || []).length);
          candidates.replaceChildren();
          (result.candidates || []).forEach(function (contact) {
            var label = document.createElement('label'); label.style.cssText = 'display:flex;gap:8px;align-items:flex-start;overflow-wrap:anywhere';
            var input = document.createElement('input'); input.type = 'checkbox'; input.name = 'billing_recipient_ids[]'; input.value = String(contact.id); input.checked = selected.has(String(contact.id));
            input.disabled = !contact.email;
            input.addEventListener('change', function () { if (input.checked) selected.add(input.value); else selected.delete(input.value); schedule(); });
            var text = document.createElement('span'); text.textContent = contact.name + (contact.email ? ' — ' + contact.email : ' (no email)');
            label.append(input, text); candidates.append(label);
          });
          summary.replaceChildren();
          var heading = document.createElement('strong'); heading.textContent = 'Working contact: ' + (result.working_contact || '') + (result.organization_name ? ' · Bill to: ' + result.organization_name : '');
          var source = document.createElement('div');
          var labels = {invoice:'This invoice', project:'Project recipients', organization:'Organization billing default', legacy:'Existing working-contact delivery'};
          source.textContent = 'Delivery from: ' + (labels[result.source] || result.source || 'default');
          var recipients = document.createElement('div'); recipients.textContent = 'Sends to: ' + ((result.recipients || []).map(function (row) { return row.name + ' <' + row.email + '>'; }).join('; ') || 'No deliverable recipients');
          summary.append(heading, source, recipients);
          if (result.reason) { var warning = document.createElement('p'); warning.textContent = result.reason; warning.style.color = '#92400e'; summary.append(warning); }
        }).catch(function (error) { if (current === sequence) summary.textContent = error.message || 'Billing preview unavailable. Reload before sending.'; });
    }
    function schedule() { sequence++; previewReady = false; clearTimeout(timer); timer = setTimeout(requestPreview, 100); }
    form.addEventListener('change', schedule);
    form.addEventListener('submit', function (event) {
      if (event.submitter && event.submitter.id === 'finalizeAndSendInvoice' && !previewReady) {
        event.preventDefault();
        summary.textContent = 'Resolve the billing recipient preview before finalizing and sending. You can still save a draft.';
      }
    });
    form.addEventListener('input', function (event) { if (event.target.matches('[data-document-client-search]')) schedule(); });
    requestPreview();
  });
  }
  init.pageInitializerId = 'invoice-billing-recipients';
  if (typeof window !== 'undefined' && window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage(['invoice/invoices-create','invoices-create','invoice/invoices-edit','invoices-edit'], init);
  } else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
