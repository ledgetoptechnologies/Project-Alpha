function isDepartmentModalOpen(modal) {
    return Boolean(modal && modal.style.display === 'flex');
}

// Notes editing
function toggleNotesEdit() {
    const display = document.getElementById('notesDisplay');
    const form = document.getElementById('notesForm');
    if (form.style.display === 'none') {
        display.style.display = 'none';
        form.style.display = 'block';
        document.getElementById('notesTextarea').focus();
    } else {
        display.style.display = 'block';
        form.style.display = 'none';
    }
}

function openDepartmentModal(trigger) {
    const modal = document.getElementById('departmentModal');
    if (!modal) return;
    window.__projectAlphaOrganizationViewDepartmentModalReturnFocus = document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;
    const title = document.getElementById('departmentModalTitle');
    const idInput = document.getElementById('departmentIdInput');
    const nameInput = document.getElementById('departmentNameInput');
    const folderInput = document.getElementById('departmentFolderInput');
    const resolverInput = document.getElementById('departmentResolverInput');
    const aliasesInput = document.getElementById('departmentAliasesInput');
    const notesInput = document.getElementById('departmentNotesInput');

    let data = null;
    if (trigger && trigger.dataset && trigger.dataset.department) {
        try {
            data = JSON.parse(trigger.dataset.department);
        } catch (error) {
            data = null;
        }
    }

    if (title) title.textContent = data ? 'Edit Department' : 'Add Department';
    if (idInput) idInput.value = data ? String(data.id || '') : '';
    if (nameInput) nameInput.value = data ? String(data.name || '') : '';
    if (folderInput) folderInput.value = data ? String(data.folder_name || '') : '';
    if (resolverInput) resolverInput.value = data ? String(data.resolver_mode || 'auto_attach') : 'auto_attach';
    if (aliasesInput) aliasesInput.value = data ? String(data.folder_aliases || '') : '';
    if (notesInput) notesInput.value = data ? String(data.notes || '') : '';

    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    const firstInput = nameInput || modal.querySelector('input[name="name"]');
    if (firstInput) firstInput.focus();
}

function closeDepartmentModal() {
    const modal = document.getElementById('departmentModal');
    if (!modal) return;
    if (!isDepartmentModalOpen(modal)) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');

    const returnFocus = window.__projectAlphaOrganizationViewDepartmentModalReturnFocus;
    window.__projectAlphaOrganizationViewDepartmentModalReturnFocus = null;
    if (returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function') {
        returnFocus.focus();
    }
}

if (!window.__projectAlphaOrganizationViewKeydownReady) {
    window.__projectAlphaOrganizationViewKeydownReady = true;
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        const modal = document.getElementById('departmentModal');
        if (!isDepartmentModalOpen(modal)) return;
        closeDepartmentModal();
    });
}

if (!window.__projectAlphaOrganizationViewClickReady) {
    window.__projectAlphaOrganizationViewClickReady = true;
    document.addEventListener('click', function (event) {
        const modal = document.getElementById('departmentModal');
        if (!isDepartmentModalOpen(modal)) return;
        if (event.target === modal) closeDepartmentModal();
    });
}

// Client search
function initOrganizationClientSearch() {
    const searchInput = document.getElementById('clientSearchInput');
    const searchResults = document.getElementById('clientSearchResults');
    const dataElement = document.getElementById('organizationViewData');
    if (!searchInput || !searchResults || !dataElement || searchInput.dataset.initialized === '1') return;

    const orgId = Number(dataElement.dataset.orgId || 0);
    let availableClients = [];
    try {
        availableClients = JSON.parse(dataElement.dataset.availableClients || '[]');
    } catch (error) {
        availableClients = [];
    }

    if (!orgId || !Array.isArray(availableClients)) return;
    searchInput.dataset.initialized = '1';
    
    function debounce(fn, ms) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function clearResults() {
        searchResults.style.display = 'none';
        searchResults.innerHTML = '';
    }

    function searchClients(query) {
        if (!query || query.trim().length === 0) {
            clearResults();
            return;
        }

        const q = query.toLowerCase();
        const matches = availableClients.filter(c =>
            c.name.toLowerCase().includes(q) ||
            (c.email && c.email.toLowerCase().includes(q))
        );

        if (matches.length === 0) {
            clearResults();
            return;
        }

        searchResults.innerHTML = '';
        matches.forEach(client => {
            const div = document.createElement('div');
            div.style.cssText = 'padding:10px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:background 0.2s';
            div.innerHTML = `<div style="font-weight:500">${escapeHtml(client.name)}</div>${client.email ? `<div style="font-size:small;color:var(--muted)">${escapeHtml(client.email)}</div>` : ''}`;

            div.addEventListener('mouseenter', () => { div.style.background = '#f9fafb'; });
            div.addEventListener('mouseleave', () => { div.style.background = '#fff'; });
            div.addEventListener('click', () => {
                addClientToOrg(client.id, client.name);
            });

            searchResults.appendChild(div);
        });
        searchResults.style.display = 'block';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function addClientToOrg(clientId, clientName) {
        if (!confirm(`Add ${clientName} to this organization?`)) return;

        const formData = new FormData();
        formData.append('csrf', dataElement.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '');
        formData.append('organization_id', orgId);
        formData.append('client_id', clientId);

        try {
            const res = await fetch('/?page=organization/organization-add-client', {
                method: 'POST',
                body: formData
            });

            if (res.ok) {
                window.location.href = '/?page=organization/organization-view&id=' + orgId + '&client_added=1';
            } else {
                alert('Failed to add client to organization');
            }
        } catch (e) {
            alert('Failed to add client to organization');
        }
    }

    const debouncedSearch = debounce(searchClients, 200);
    searchInput.addEventListener('input', (e) => debouncedSearch(e.target.value));

    document.addEventListener('click', (e) => {
        if (!searchResults.contains(e.target) && e.target !== searchInput) {
            clearResults();
        }
    });
}
initOrganizationClientSearch.pageInitializerId = 'organization-view-client-search';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('organization/organization-view', initOrganizationClientSearch);
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOrganizationClientSearch, { once: true });
} else {
    initOrganizationClientSearch();
}
