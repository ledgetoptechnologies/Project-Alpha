(function () {
    'use strict';
    function init() {
        document.querySelectorAll('[data-invoice-autopay-preview]').forEach(function (panel) {
            var form = panel.closest('form');
            if (!form || panel.dataset.ready) return;
            panel.dataset.ready = '1';
            var button = form.querySelector('#finalizeAndSendInvoice');
            var text = panel.querySelector('[data-autopay-preview-text]');
            var sequence = 0;
            function update() {
                var request = ++sequence;
                var data = new FormData();
                ['csrf', 'client_id', 'project_id', 'recipient_presentation_mode'].forEach(function (name) {
                    var field = form.elements.namedItem(name);
                    if (field) data.set(name, field.value);
                });
                data.set('invoice_id', panel.dataset.invoiceId || '0');
                text.textContent = 'Checking AutoPay authorization… Drafts never charge a payment method.';
                fetch('/?page=invoice-autopay-preview', { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (response) { if (!response.ok) throw new Error('preview'); return response.json(); })
                    .then(function (result) {
                        if (request !== sequence) return;
                        if (!result.success) throw new Error('preview');
                        if (button) button.textContent = result.eligible ? 'Save & review AutoPay' : (result.project_billing ? 'Finalize for Project Billing' : 'Save & Send');
                        if (!result.enabled) { panel.hidden = true; return; }
                        panel.hidden = false;
                        if (result.eligible) text.textContent = 'AutoPay authorized: ' + result.method + '. Scope: ' + result.scope + '. Saving this draft does not charge. Review the saved total and payment method before finalizing.';
                        else if (result.state === 'separate_collection') text.textContent = 'This invoice uses a separate collection workflow. AutoPay will not collect this invoice directly. Saving changes does not charge.';
                        else if (result.state === 'select_client') text.textContent = 'Choose a client to check AutoPay. Saving a draft never charges.';
                        else text.textContent = 'No eligible AutoPay authorization for this invoice. Saving a draft never charges.';
                    })
                    .catch(function () {
                        if (request !== sequence) return;
                        if (button) button.textContent = 'Save & review payment';
                        text.textContent = 'AutoPay preview is unavailable. Saving a draft never charges; authorization is checked again before finalization.';
                    });
            }
            form.addEventListener('change', function (event) {
                if (['client_id', 'project_id', 'recipient_presentation_mode'].indexOf(event.target.name) !== -1) update();
            });
            update();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
