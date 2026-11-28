(function () {
    'use strict';

    function initDocumentRecipientPresentation() {
        document.querySelectorAll('[data-document-recipient-picker]').forEach(function (picker) {
            if (picker.dataset.recipientPresentationReady === '1') return;

            var clientId = picker.querySelector('[data-document-client-id]');
            var clientSearch = picker.querySelector('[data-document-client-search]');
            var contactOption = picker.querySelector('[data-document-contact-option]');
            var contactCheckbox = picker.querySelector('[data-document-contact-checkbox]');
            var organizationName = picker.querySelector('[data-document-organization-name]');
            if (!clientId || !contactOption || !contactCheckbox) return;

            picker.dataset.recipientPresentationReady = '1';

            function copySelectedOrganization() {
                if (clientId.tagName !== 'SELECT') return;
                var selected = clientId.options[clientId.selectedIndex];
                clientId.dataset.organizationId = selected ? (selected.dataset.organizationId || '') : '';
                clientId.dataset.organizationName = selected ? (selected.dataset.organizationName || '') : '';
            }

            function sync(resetContact) {
                var orgId = String(clientId.dataset.organizationId || '').trim();
                var orgName = String(clientId.dataset.organizationName || '').trim();
                var hasOrganization = orgId !== '' && orgId !== '0';

                contactOption.hidden = !hasOrganization;
                contactCheckbox.disabled = !hasOrganization;
                if (!hasOrganization || resetContact) contactCheckbox.checked = false;
                if (organizationName) organizationName.textContent = orgName;
            }

            clientId.addEventListener('change', function () {
                copySelectedOrganization();
                sync(true);
            });
            if (clientSearch) {
                clientSearch.addEventListener('input', function () {
                    if (!clientId.value) {
                        clientId.dataset.organizationId = '';
                        clientId.dataset.organizationName = '';
                        sync(true);
                    }
                });
            }

            copySelectedOrganization();
            sync(false);
        });
    }

    initDocumentRecipientPresentation.pageInitializerId = 'document-recipient-presentation';

    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage([
            'document/new',
            'invoice/invoices-edit',
            'quote/quotes-edit',
            'contract/contracts-edit'
        ], initDocumentRecipientPresentation);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDocumentRecipientPresentation, { once: true });
    } else {
        initDocumentRecipientPresentation();
    }
})();
