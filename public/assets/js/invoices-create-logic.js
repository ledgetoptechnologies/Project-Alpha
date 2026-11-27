function money(n) { return '$' + (Number(n) || 0).toFixed(2) }
var itemCounterInv = 0;
function escapeInvoiceItem(value) {
    return String(value).replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
}
function addItemInv(item = '', desc = '', qty = 1, price = 0, timeEntryId = null, billingUnit = 'each', mileageAllocationId = null, parentRow = null) {
    var wrap = document.createElement('div');
    var rowIndex = document.querySelectorAll('#itemsInv > div').length;
    var itemId = 'itemInv_' + (itemCounterInv++);
    wrap.className = 'invoice-item-editor__row';
    wrap.dataset.itemKey = itemId;
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', parentRow ? 'Invoice sub-item' : 'Invoice item');
    if (parentRow) {
        wrap.dataset.parentKey = parentRow.dataset.itemKey;
    }
    var descId = 'descInv_' + itemCounterInv;
    var priceId = 'priceInv_' + itemCounterInv;
    const lineTotalId = 'lineTotalInv_' + itemCounterInv;
    var teIds = Array.isArray(timeEntryId) ? timeEntryId : (timeEntryId ? [timeEntryId] : []);
    var mileageIds = Array.isArray(mileageAllocationId) ? mileageAllocationId : (mileageAllocationId ? [mileageAllocationId] : []);
    var teInput = teIds.map(id => `<input type="hidden" name="time_entry_ids[${rowIndex}][]" value="${id}">`).join('');
    var mileageInput = mileageIds.map(id => `<input type="hidden" name="mileage_allocation_ids[${rowIndex}][]" value="${id}">`).join('');
    var selectedUnit = ['each', 'hour', 'mile'].includes(billingUnit) ? billingUnit : 'each';
    wrap.innerHTML = teInput + mileageInput + `
    <input type="hidden" name="item_key[]" value="${itemId}">
    <input type="hidden" name="item_parent_key[]" value="${wrap.dataset.parentKey || ''}">
    <div class="invoice-item-editor__field invoice-item-editor__name">
      <label for="${itemId}">${parentRow ? 'Sub-item' : 'Item'}</label>
      <input id="${itemId}" required placeholder="${parentRow ? 'e.g. Additional acres' : 'e.g. 2D Map'}" name="item[]" value="${escapeInvoiceItem(item)}" oninput="recalcInv()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    </div>
    <label class="invoice-item-editor__field">
      <span>Quantity</span>
      <input required type="number" step="0.01" ${teIds.length === 0 && mileageIds.length === 0 ? 'min="0"' : ''} name="item_qty[]" class="qty-input" value="${escapeInvoiceItem(qty)}" oninput="recalcInv()">
    </label>
    <label class="invoice-item-editor__field">
      <span>Unit price</span>
      <input id="${priceId}" required type="number" step="0.01" min="0" name="item_price[]" value="${escapeInvoiceItem(price)}" oninput="recalcInv()">
    </label>
    <label class="invoice-item-editor__field">
      <span>Billing unit</span>
      <select name="item_billing_unit[]">
        <option value="each" ${selectedUnit === 'each' ? 'selected' : ''}>Each</option>
        <option value="hour" ${selectedUnit === 'hour' ? 'selected' : ''}>Hours</option>
        <option value="mile" ${selectedUnit === 'mile' ? 'selected' : ''}>Miles</option>
      </select>
    </label>
    <div class="invoice-item-editor__field invoice-item-editor__total">
      <span>Line total</span>
      <output id="${lineTotalId}" aria-label="Line total">${money(Number(qty) * Number(price))}</output>
    </div>
    <label class="invoice-item-editor__field invoice-item-editor__description">
      <span>Description <span class="invoice-item-editor__optional">(optional)</span></span>
      <textarea id="${descId}" rows="2" name="item_desc[]">${escapeInvoiceItem(desc)}</textarea>
    </label>
    <div class="invoice-item-editor__actions">
      ${parentRow ? '' : '<button type="button" data-add-sub-item class="btn btn-sm">+ Add sub-item</button>'}
      <button type="button" data-remove-item class="btn btn-sm invoice-item-editor__remove" aria-label="${parentRow ? 'Remove sub-item' : 'Remove item'}">Remove</button>
    </div>
  `;
    const container = document.getElementById('itemsInv');
    let followingRow = parentRow ? parentRow.nextElementSibling : null;
    while (followingRow && followingRow.dataset.parentKey === parentRow.dataset.itemKey) {
        followingRow = followingRow.nextElementSibling;
    }
    container.insertBefore(wrap, followingRow);
    wrap.querySelector('[data-add-sub-item]')?.addEventListener('click', () => {
        addItemInv('', '', 1, 0, null, 'each', null, wrap).focus();
    });
    wrap.querySelector('[data-remove-item]').addEventListener('click', () => {
        const children = Array.from(container.children).filter(row => row.dataset.parentKey === itemId);
        if (children.length && !window.confirm('Remove this item and its sub-items?')) return;
        children.forEach(row => row.remove());
        wrap.remove();
        recalcInv();
    });

    // Re-initialize autocomplete for the new item input
    if (window.ItemAutocomplete) {
        const input = document.getElementById(itemId);
        const descField = document.getElementById(descId);
        const priceField = document.getElementById(priceId);
        input._itemAutocomplete = new ItemAutocomplete(input, {
            descriptionField: descField,
            priceField: priceField
        });
    }

    recalcInv();
    return document.getElementById(itemId);
}

function recalcInv() {
    var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
    var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
    let subtotal = 0;
    const rows = document.querySelectorAll('#itemsInv > div');
    for (let index = 0; index < qtys.length; index++) {
        const lineTotal = qtys[index] * prices[index];
        subtotal += lineTotal;
        const output = rows[index]?.querySelector('output');
        if (output) output.textContent = money(lineTotal);
    }
    var dtype = document.getElementById('discountTypeInv').value;
    var dval = parseFloat(document.getElementById('discountValueInv').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercentInv').value) || 0;
    var discount = 0; if (dtype === 'percent') { discount = Math.max(0, Math.min(100, dval)) * subtotal / 100; } else if (dtype === 'fixed') { discount = Math.max(0, dval); }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);
    document.getElementById('subtotalValInv').textContent = money(subtotal);
    document.getElementById('discountValInv').textContent = money(discount);
    document.getElementById('taxValInv').textContent = money(tax);
    document.getElementById('totalValInv').textContent = money(total);
}

['discountTypeInv', 'discountValueInv', 'taxPercentInv'].forEach(id => document.getElementById(id).addEventListener('input', recalcInv));
addItemInv();

// Client typeahead for invoice
function initInvoiceClientDropdown() {
    var ciI = document.getElementById('clientInputInv');
    var cidI = document.getElementById('clientIdInv');
    var sugI = document.getElementById('clientSuggestInv');
    var taxBannerInv = document.getElementById('taxExemptBannerInv');
    
    if (!ciI || !cidI || !sugI) return;
    if (ciI._clientDropdownInitialized) return;
    ciI._clientDropdownInitialized = true;

    function escapeInvoiceRecipient(value) {
        var node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    }
    
    ciI.addEventListener('input', function () {
        cidI.value = '';
        var t = this.value.trim();
        if (!t) { sugI.style.display = 'none'; sugI.innerHTML = ''; if(taxBannerInv) taxBannerInv.style.display = 'none'; return; }
        fetch('/?page=clients-search&term=' + encodeURIComponent(t))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) { sugI.style.display = 'none'; sugI.innerHTML = ''; return; }
                sugI.innerHTML = list.map((x, index) => `<div data-client-index="${index}" style=\"padding:8px 10px;cursor:pointer\"><strong>${escapeInvoiceRecipient(x.name)}</strong>${x.org_name ? `<small style="display:block;color:#6b7280">${escapeInvoiceRecipient(x.org_name)}</small>` : ''}</div>`).join('');
                Array.from(sugI.children).forEach(el => {
                    el.addEventListener('click', function (e) {
                        e.stopPropagation();
                        const client = list[Number(this.dataset.clientIndex)];
                        if (!client) return;
                        ciI.value = client.name; cidI.value = client.id;
                        cidI.dataset.organizationId = client.organization_id || '';
                        cidI.dataset.organizationName = client.org_name || '';
                        cidI.dispatchEvent(new Event('change', { bubbles: true }));
                        if (client.tax_exempt_file && taxBannerInv) { taxBannerInv.style.display = 'block'; } else if(taxBannerInv) { taxBannerInv.style.display = 'none'; }
                        loadProjectsForClientInv(client.id);
                        sugI.style.display = 'none';
                    });
                });
                sugI.style.display = 'block';
            }).catch(() => { sugI.style.display = 'none' });
    });
}
initInvoiceClientDropdown.pageInitializerId = 'invoice-create-client-dropdown';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('invoice/invoices-create', initInvoiceClientDropdown);
} else {
    initInvoiceClientDropdown();
}

function loadProjectsForClientInv(clientId) {
    const projectSection = document.getElementById('projectSectionInv');
    const projectSelect = document.getElementById('projectSelectInv');
    if (!clientId) {
        if (projectSelect) projectSelect.innerHTML = '<option value="">-- Select Project --</option>';
        if (projectSection) projectSection.style.display = 'none';
        syncInvoiceProjectBillingPresentation();
        return;
    }

    fetch('/?page=projects-search&client_id=' + encodeURIComponent(clientId))
        .then(r => r.json())
        .then(projects => {
            projectSelect.innerHTML = '<option value="">-- Select Project --</option>';

            if (projects && projects.length > 0) {
                projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project.id;
                    option.dataset.invoiceBillingPeriod = project.invoice_billing_period || 'per_invoice';
                    option.textContent = project.name + ' (' + project.status.replace('_', ' ') + ')';
                    projectSelect.appendChild(option);
                });
                projectSection.style.display = 'block';
            } else {
                projectSelect.value = '';
                projectSection.style.display = 'none';
            }
            syncInvoiceProjectBillingPresentation();
        })
        .catch(() => {
            projectSection.style.display = 'none';
            syncInvoiceProjectBillingPresentation();
        });
}

function syncInvoiceProjectBillingPresentation() {
    const projectSelect = document.getElementById('projectSelectInv');
    const actionButton = document.getElementById('finalizeAndSendInvoice');
    const help = document.getElementById('invoiceProjectBillingHelp');
    if (!projectSelect || !actionButton || !help) return;
    const selected = projectSelect.options[projectSelect.selectedIndex];
    const isMonthly = !!selected && selected.dataset.invoiceBillingPeriod === 'monthly';
    actionButton.value = isMonthly ? 'finalize' : 'finalize_send';
    actionButton.textContent = isMonthly ? 'Finalize for Project Billing' : 'Save & Send';
    help.hidden = !isMonthly;
}

const invoiceProjectSelect = document.getElementById('projectSelectInv');
if (invoiceProjectSelect) {
    invoiceProjectSelect.addEventListener('change', syncInvoiceProjectBillingPresentation);
    syncInvoiceProjectBillingPresentation();
}

document.getElementById('createProjectBtnInv').addEventListener('click', function () {
    const clientId = document.getElementById('clientIdInv').value;
    if (!clientId) {
        alert('Please select a client first.');
        return;
    }

    const projectName = prompt('Enter project name:');
    if (!projectName || !projectName.trim()) return;

    fetch('/?page=project/projects-create-quick', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'csrf=' + encodeURIComponent(document.querySelector('input[name="csrf"]').value) +
            '&name=' + encodeURIComponent(projectName.trim()) +
            '&client_id=' + encodeURIComponent(clientId)
    })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                // Reload projects for this client
                loadProjectsForClientInv(clientId);
                // Select the new project
                setTimeout(() => {
                    const projectSelect = document.getElementById('projectSelectInv');
                    for (let i = 0; i < projectSelect.options.length; i++) {
                        if (projectSelect.options[i].value == result.project_id) {
                            projectSelect.selectedIndex = i;
                            projectSelect.dispatchEvent(new Event('change', { bubbles: true }));
                            break;
                        }
                    }
                }, 100);
            } else {
                alert('Failed to create project: ' + (result.error || 'Unknown error'));
            }
        })
        .catch(() => {
            alert('Failed to create project.');
        });
});

document.getElementById('invoiceForm').addEventListener('submit', function (e) {
    document.querySelectorAll('#itemsInv > div').forEach(function (row, index) {
        row.querySelectorAll('input[name^="time_entry_ids["]').forEach(function (input) {
            input.name = `time_entry_ids[${index}][]`;
        });
        row.querySelectorAll('input[name^="mileage_allocation_ids["]').forEach(function (input) {
            input.name = `mileage_allocation_ids[${index}][]`;
        });
    });
    const clientId = document.getElementById('clientIdInv');
    if (!clientId || !clientId.value) {
        e.preventDefault();
        alert('Please select a client from suggestions.');
    }
});

// Tracked time integration
(function () {
    const modal = document.getElementById('trackedTimeModal');
    const openBtn = document.getElementById('btnAddFromTrackedTime');
    const closeBtn = document.getElementById('closeTrackedTimeModal');
    const loading = document.getElementById('trackedTimeLoading');
    const empty = document.getElementById('trackedTimeEmpty');
    const form = document.getElementById('trackedTimeForm');
    const tbody = document.getElementById('trackedTimeTbody');
    const selectAll = document.getElementById('selectAllTrackedTime');
    const addSelected = document.getElementById('btnAddSelectedTrackedTime');
    const clientIdInput = document.getElementById('clientIdInv');

    if (!openBtn || !modal) return;

    function renderTrackedTime(entries) {
        tbody.innerHTML = '';
        if (!Array.isArray(entries) || entries.length === 0) {
            loading.style.display = 'none';
            form.style.display = 'none';
            empty.style.display = 'block';
            return;
        }
        entries.forEach(function (entry) {
            const tr = document.createElement('tr');
            const serviceName = entry.service_name || 'Tracked Time';
            const detail = entry.detail || [entry.started_at || '', entry.ended_at || '', entry.description || ''].filter(Boolean).join(' ');
            tr.innerHTML = `
                <td><input type="checkbox" class="tt-checkbox" data-id="${entry.id}" data-group="${escapeAttr(entry.adjustment_group_id || '')}" data-service="${escapeAttr(serviceName)}" data-desc="${escapeAttr(entry.description || '')}" data-detail="${escapeAttr(detail)}" data-hours="${entry.hours}" data-rate="${entry.rate}"></td>
                <td>${(entry.started_at || '').substr(0, 10)}</td>
                <td>${escapeHtml(serviceName)}${entry.description ? '<div style="font-size:12px;color:var(--muted)">' + escapeHtml(entry.description) + '</div>' : ''}</td>
                <td>${Number(entry.hours).toFixed(2)}</td>
                <td>$${Number(entry.rate).toFixed(2)}</td>
                <td>$${(Number(entry.hours) * Number(entry.rate)).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        });
        document.querySelectorAll('.tt-checkbox').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (!this.dataset.group) return;
                document.querySelectorAll('.tt-checkbox').forEach(function (candidate) {
                    if (candidate.dataset.group === checkbox.dataset.group) candidate.checked = checkbox.checked;
                });
            });
        });
        loading.style.display = 'none';
        empty.style.display = 'none';
        form.style.display = 'block';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    function loadTrackedTime() {
        const clientId = clientIdInput ? clientIdInput.value : '';
        loading.style.display = 'block';
        form.style.display = 'none';
        empty.style.display = 'none';
        modal.style.display = 'flex';
        fetch('/?page=time-tracking/unbilled' + (clientId ? '&client_id=' + encodeURIComponent(clientId) : ''))
            .then(function (r) { return r.json(); })
            .then(renderTrackedTime)
            .catch(function () {
                loading.style.display = 'none';
                empty.style.display = 'block';
            });
    }

    openBtn.addEventListener('click', function () {
        if (!clientIdInput || !clientIdInput.value) {
            alert('Please select a client first.');
            return;
        }
        loadTrackedTime();
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () { modal.style.display = 'none'; });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            const checked = this.checked;
            document.querySelectorAll('.tt-checkbox').forEach(function (cb) { cb.checked = checked; });
        });
    }

    if (addSelected) {
        addSelected.addEventListener('click', function () {
            const checked = Array.from(document.querySelectorAll('.tt-checkbox:checked'));
            if (checked.length === 0) {
                alert('Please select at least one time entry.');
                return;
            }
            const itemWrap = document.getElementById('itemsInv');
            if (itemWrap && itemWrap.children.length === 1) {
                const firstItem = itemWrap.querySelector('[name="item[]"]');
                if (firstItem && !firstItem.value.trim()) {
                    itemWrap.innerHTML = '';
                }
            }
            const groups = new Map();
            checked.forEach(function (cb) {
                const rate = parseFloat(cb.dataset.rate) || 0;
                const service = cb.dataset.service || 'Tracked Time';
                const key = service + '|' + rate.toFixed(2);
                if (!groups.has(key)) {
                    groups.set(key, { item: service, rate, hours: 0, ids: [], descriptions: [] });
                }
                const group = groups.get(key);
                const hours = parseFloat(cb.dataset.hours) || 0;
                group.hours += hours;
                group.ids.push(cb.dataset.id);
                group.descriptions.push(cb.dataset.detail || cb.dataset.desc || '');
            });
            for (const group of groups.values()) {
                if (!(group.rate > 0)) {
                    const enteredRate = window.prompt('Enter the hourly billing rate for this tracked time:');
                    const parsedRate = Number(enteredRate);
                    if (!(parsedRate > 0)) {
                        window.alert('A positive hourly billing rate is required before tracked time can be invoiced.');
                        return;
                    }
                    group.rate = parsedRate;
                }
            }
            for (const group of groups.values()) {
                const detail = group.descriptions.filter(Boolean).join('\n');
                addItemInv(group.item, detail, group.hours.toFixed(2), group.rate.toFixed(2), group.ids, 'hour');
            }
            modal.style.display = 'none';
            recalcInv();
        });
    }
})();

// Billable mileage integration
(function () {
    const modal = document.getElementById('billableMileageModal');
    const openBtn = document.getElementById('btnAddFromMileage');
    const closeBtn = document.getElementById('closeBillableMileageModal');
    const loading = document.getElementById('billableMileageLoading');
    const empty = document.getElementById('billableMileageEmpty');
    const content = document.getElementById('billableMileageContent');
    const tbody = document.getElementById('billableMileageTbody');
    const selectAll = document.getElementById('selectAllBillableMileage');
    const addSelected = document.getElementById('btnAddSelectedMileage');
    const clientIdInput = document.getElementById('clientIdInv');
    if (!modal || !openBtn) return;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function render(entries) {
        tbody.innerHTML = '';
        loading.style.display = 'none';
        if (!Array.isArray(entries) || entries.length === 0) {
            empty.style.display = 'block';
            content.style.display = 'none';
            return;
        }
        entries.forEach(function (entry) {
            const route = [entry.start_location, entry.end_location].filter(Boolean).join(' â†’ ');
            const loggedMiles = Number(entry.logged_miles);
            const billableMiles = Number(entry.billable_miles);
            const billingNote = entry.charge_method === 'fixed_fee' ? 'Fixed travel fee' : `${loggedMiles.toFixed(3)} miles logged; ${billableMiles.toFixed(3)} miles billed`;
            const detail = [entry.trip_date, route, entry.project_name, entry.description, billingNote].filter(Boolean).join(' | ');
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td><input type="checkbox" class="mileage-checkbox" data-id="${Number(entry.id)}" data-quantity="${Number(entry.quantity)}" data-rate="${Number(entry.unit_price)}" data-unit="${escapeHtml(entry.billing_unit)}" data-detail="${escapeHtml(detail).replace(/"/g, '&quot;')}"></td>
              <td>${escapeHtml(entry.trip_date)}</td>
              <td>${escapeHtml(route || entry.description || 'Mileage')}</td>
              <td>${entry.charge_method === 'fixed_fee' ? 'Fixed' : loggedMiles.toFixed(3)+' / '+billableMiles.toFixed(3)}</td>
              <td>$${Number(entry.unit_price).toFixed(entry.billing_unit === 'mile' ? 4 : 2)}</td>
              <td>$${Number(entry.client_charge).toFixed(2)}</td>`;
            tbody.appendChild(tr);
        });
        empty.style.display = 'none';
        content.style.display = 'block';
    }

    openBtn.addEventListener('click', function () {
        const clientId = clientIdInput ? clientIdInput.value : '';
        if (!clientId) {
            alert('Please select a client first.');
            return;
        }
        loading.style.display = 'block';
        empty.style.display = 'none';
        content.style.display = 'none';
        modal.style.display = 'flex';
        fetch('/?page=financial/mileage-unbilled&client_id=' + encodeURIComponent(clientId))
            .then(function (response) { return response.json(); })
            .then(function (result) { render(Array.isArray(result) ? result : (Array.isArray(result.data) ? result.data : [])); })
            .catch(function () { render([]); });
    });

    closeBtn.addEventListener('click', function () { modal.style.display = 'none'; });
    selectAll.addEventListener('change', function () {
        document.querySelectorAll('.mileage-checkbox').forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
    });
    addSelected.addEventListener('click', function () {
        const checked = Array.from(document.querySelectorAll('.mileage-checkbox:checked'));
        if (!checked.length) {
            alert('Please select at least one mileage entry.');
            return;
        }
        const itemWrap = document.getElementById('itemsInv');
        if (itemWrap && itemWrap.children.length === 1) {
            const firstItem = itemWrap.querySelector('[name="item[]"]');
            if (firstItem && !firstItem.value.trim()) itemWrap.innerHTML = '';
        }
        const groups = new Map();
        checked.forEach(function (checkbox) {
            const rate = Number(checkbox.dataset.rate) || 0;
            const unit = checkbox.dataset.unit || 'mile';
            const key = unit + ':' + rate.toFixed(4);
            if (!groups.has(key)) groups.set(key, { rate, unit, quantity: 0, ids: [], details: [] });
            const group = groups.get(key);
            group.quantity += Number(checkbox.dataset.quantity) || 0;
            group.ids.push(Number(checkbox.dataset.id));
            if (checkbox.dataset.detail) group.details.push(checkbox.dataset.detail);
        });
        groups.forEach(function (group) {
            addItemInv(group.unit === 'mile' ? 'Client travel mileage' : 'Client travel fee', group.details.join('\n'), group.quantity.toFixed(group.unit === 'mile' ? 3 : 0), group.rate.toFixed(group.unit === 'mile' ? 4 : 2), null, group.unit, group.ids);
        });
        modal.style.display = 'none';
        recalcInv();
    });
})();
