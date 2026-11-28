// Client typeahead
function initClientDropdown() {
    var ci = document.getElementById('clientInput');
    var cid = document.getElementById('clientId');
    var sug = document.getElementById('clientSuggest');

    // Only initialize if elements exist
    if (!ci || !cid || !sug) return;

    // Guard against duplicate initialization (SPA navigation re-fires pageLoaded)
    if (ci._clientDropdownReady) return;
    ci._clientDropdownReady = true;

    // Remove any existing input listener to avoid duplicates
    ci.removeEventListener('input', ci._clientDropdownHandler);
    ci._clientDropdownHandler = function () {
        cid.value = '';
        var t = this.value.trim();

        if (!t) {
            if (sug) { sug.style.display = 'none'; sug.innerHTML = ''; }
            return;
        }

        fetch('/?page=clients-search&term=' + encodeURIComponent(t))
            .then(r => r.json())
            .then(list => {
                if (!sug) return;

                if (!Array.isArray(list) || list.length === 0) {
                    sug.style.display = 'none';
                    sug.innerHTML = '';
                    return;
                }

                sug.innerHTML = list.map(function (x, index) {
                    return `<div data-client-index="${index}" style="padding:8px 10px;cursor:pointer"><strong>${escapeClientDropdownValue(x.name)}</strong>${x.org_name ? `<small style="display:block;color:#6b7280">${escapeClientDropdownValue(x.org_name)}</small>` : ''}</div>`;
                }).join('');

                Array.from(sug.children).forEach(el => {
                    el.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var client = list[Number(this.dataset.clientIndex)];
                        if (!client) return;
                        ci.value = client.name;
                        cid.value = client.id;
                        cid.dataset.organizationId = client.organization_id || '';
                        cid.dataset.organizationName = client.org_name || '';
                        cid.dispatchEvent(new Event('change', { bubbles: true }));

                        // Dispatch change event for other listeners
                        ci.dispatchEvent(new Event('change', { bubbles: true }));

                        if (sug) sug.style.display = 'none';
                    });
                });
                sug.style.display = 'block';
            }).catch(() => {
                if (sug) sug.style.display = 'none';
            });
    };
    ci.addEventListener('input', ci._clientDropdownHandler);

    // Close dropdown on outside click — use named handler so it can be cleaned up
    if (!document._clientOutsideClickHandler) {
        document._clientOutsideClickHandler = function (e) {
            // Find the active suggestion div — check all known IDs
            var allSug = ['clientSuggest', 'clientSuggestCo', 'clientSuggestInv', 'clientSuggestPL', 'clientSuggestODI'];
            allSug.forEach(function (sid) {
                var s = document.getElementById(sid);
                var inputId = sid.replace('clientSuggest', 'clientInput').replace('Suggest', '');
                // Map suggestion div to its input
                var inputMap = { 'clientSuggest': 'clientInput', 'clientSuggestCo': 'clientInputCo', 'clientSuggestInv': 'clientInputInv', 'clientSuggestPL': 'clientInputPL', 'clientSuggestODI': 'clientInputODI' };
                var inp = document.getElementById(inputMap[sid] || inputId);
                if (s && !s.contains(e.target) && e.target !== inp) {
                    s.style.display = 'none';
                }
            });
        };
        document.addEventListener('click', document._clientOutsideClickHandler);
    }
}
function escapeClientDropdownValue(value) {
    var element = document.createElement('div');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
}
initClientDropdown.pageInitializerId = 'client-selection-dropdown';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage(['project/projects-create'], initClientDropdown);
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initClientDropdown, { once: true });
} else {
    initClientDropdown();
}
