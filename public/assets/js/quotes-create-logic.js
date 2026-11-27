function money(n) {
    return '$' + (Number(n) || 0).toFixed(2)
}

var itemCounter = 0;

function addItem(item = '', desc = '', qty = 1, price = 0) {
    var wrap = document.createElement('div');
    var itemId = 'item_' + (itemCounter++);
    var descId = 'desc_' + itemCounter;
    var priceId = 'price_' + itemCounter;
    wrap.style.display = 'grid'; wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr auto'; wrap.style.gap = '8px';
    wrap.innerHTML = `
    <input type="hidden" name="item_billing_unit[]" value="each">
    <input id="${itemId}" required placeholder="Item name..." name="item[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${item}" oninput="recalc()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    <textarea id="${descId}" placeholder="Description (optional)" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px" oninput="recalc()">${desc}</textarea>
    <input required type="number" step="0.01" min="0" name="item_qty[]" class="qty-input" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalc()">
    <input id="${priceId}" required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalc()">
    <button type="button" onclick="this.parentElement.remove();recalc()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
    document.getElementById('items').appendChild(wrap);

    // Re-initialize autocomplete for the new item input
    function initAutocomplete() {
        if (window.ItemAutocomplete) {
            const input = document.getElementById(itemId);
            if (input && !input._itemAutocomplete) {
                const descField = document.getElementById(descId);
                const priceField = document.getElementById(priceId);
                const instance = new ItemAutocomplete(input, {
                    descriptionField: descField,
                    priceField: priceField
                });
                // Mark as initialized
                input._itemAutocomplete = instance;
            }
        } else {
            // Retry after a short delay if ItemAutocomplete not loaded yet
            setTimeout(initAutocomplete, 100);
        }
    }

    initAutocomplete();

    if (typeof updateHourlyModeUI === 'function') updateHourlyModeUI();
    recalc();
}

// Calculate number of billing periods between two dates
function calcInvoiceCountFromDates(startDate, endDate, intervalCount, intervalUnit) {
    if (!startDate || !endDate) return 0;
    var start = new Date(startDate);
    var end = new Date(endDate);
    if (isNaN(start) || isNaN(end) || end <= start) return 0;
    var periods = 0;
    var cursor = new Date(start);
    while (cursor < end) {
        if (intervalUnit === 'day') cursor.setDate(cursor.getDate() + intervalCount);
        else if (intervalUnit === 'week') cursor.setDate(cursor.getDate() + 7 * intervalCount);
        else if (intervalUnit === 'month') cursor.setMonth(cursor.getMonth() + intervalCount);
        else if (intervalUnit === 'year') cursor.setFullYear(cursor.getFullYear() + intervalCount);
        if (cursor <= end) periods++;
    }
    return Math.max(1, periods);
}

function recalc() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');
    // Get pricing type from radio (long-term) or hidden field (on-demand)
    var pricingTypeEl = document.querySelector('input[name="lt_pricing_type"]:checked') || document.querySelector('input[name="lt_pricing_type"][type="hidden"]');
    var pricingType = (isLongTerm || isOnDemand) ? (pricingTypeEl?.value || null) : null;
    var onDemandPricingModeEl = document.querySelector('input[name="od_pricing_mode"]:checked');
    var onDemandPricingMode = onDemandPricingModeEl ? onDemandPricingModeEl.value : 'items';
    var endDateEl = document.getElementById('endDateType');
    var isOngoing = isLongTerm && endDateEl && endDateEl.value === 'ongoing';
    var hasFixedEnd = endDateEl && endDateEl.value === 'fixed';

    // Auto-calculate invoice count when Fixed End Date + Fixed Total
    if (isLongTerm && pricingType === 'fixed_total' && hasFixedEnd) {
        var startVal = document.getElementById('startDateField').value;
        var endVal = document.querySelector('input[name="lt_end_date"]').value;
        var intCount = parseInt(document.getElementById('billingIntervalCount').value) || 1;
        var intUnit = document.getElementById('billingIntervalUnit').value;
        var autoCount = calcInvoiceCountFromDates(startVal, endVal, intCount, intUnit);
        var invoiceInput = document.getElementById('invoiceCountInput');
        if (invoiceInput) {
            invoiceInput.value = autoCount;
            invoiceInput.readOnly = true;
            invoiceInput.style.backgroundColor = '#f3f4f6';
        }
    } else {
        var invoiceInput = document.getElementById('invoiceCountInput');
        if (invoiceInput) {
            invoiceInput.readOnly = false;
            invoiceInput.style.backgroundColor = '';
        }
    }

    var subtotal = 0;

    // Calculate subtotal based on pricing type
    if (isLongTerm && (pricingType === 'per_invoice' || pricingType === 'on_demand')) {
        subtotal = parseFloat(document.getElementById('pricePerInvoiceInput').value) || 0;
    } else if (isOnDemand && onDemandPricingMode === 'flat') {
        var onDemandAmountInput = document.getElementById('onDemandAmountInput');
        subtotal = onDemandAmountInput ? (parseFloat(onDemandAmountInput.value) || 0) : 0;
    } else {
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    // For fixed_total pricing, calculate price per invoice
    if (isLongTerm && pricingType === 'fixed_total') {
        var invoiceCount = parseInt(document.getElementById('invoiceCountInput').value) || 1;
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        subtotal = 0;
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    var dtype = document.getElementById('discountType').value;
    var dval = parseFloat(document.getElementById('discountValue').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercent').value) || 0;
    var discount = 0;
    if (dtype === 'percent') { discount = Math.max(0, Math.min(100, dval)) * subtotal / 100; }
    else if (dtype === 'fixed') { discount = Math.max(0, dval); }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);

    // Calculate deposit
    var depositTypeEl = document.getElementById('depositType');
    var depositValueEl = document.getElementById('depositValue');
    var depType = depositTypeEl ? depositTypeEl.value : 'none';
    var depVal = depositValueEl ? parseFloat(depositValueEl.value) || 0 : 0;
    var deposit = 0;
    if (depType === 'percent') { deposit = Math.max(0, Math.min(100, depVal)) * total / 100; }
    else if (depType === 'fixed') { deposit = Math.max(0, depVal); }

    document.getElementById('subtotalVal').textContent = money(subtotal);
    document.getElementById('discountVal').textContent = money(discount);
    document.getElementById('taxVal').textContent = money(tax);

    // For ongoing quotes, don't show total (unknown)
    if (isOngoing) {
        document.getElementById('totalVal').parentElement.style.display = 'none';
    } else {
        document.getElementById('totalVal').parentElement.style.display = 'flex';
        document.getElementById('totalVal').textContent = money(total);
    }

    // Show calculated price per invoice for fixed_total
    if (isLongTerm && pricingType === 'fixed_total') {
        var invoiceCount = parseInt(document.getElementById('invoiceCountInput').value) || 1;
        var pricePerInv = total / invoiceCount;
        document.getElementById('calcPriceVal').textContent = money(pricePerInv);
        document.getElementById('invoiceAmountRow').style.display = 'none';
    } else if (isLongTerm) {
        document.getElementById('invoiceAmountRow').style.display = 'block';
        document.getElementById('invoiceAmountVal').textContent = money(total);
    } else {
        document.getElementById('invoiceAmountRow').style.display = 'none';
    }

    // Show/hide deposit row
    var depositRowEl = document.getElementById('depositRow');
    var depositValEl = document.getElementById('depositVal');
    if (depositRowEl) {
        if (depType !== 'none' && deposit > 0) {
            depositRowEl.style.display = 'block';
            if (depositValEl) depositValEl.textContent = money(deposit);
        } else {
            depositRowEl.style.display = 'none';
        }
    }

    updateDiscountWarning();
}

function updateHourlyModeUI() {
    var hourly = !!document.getElementById('billingModeHourly')?.checked;
    var hint = document.getElementById('hourlyBillingHint');
    if (hint) hint.style.display = hourly ? 'block' : 'none';
    document.querySelectorAll('#items input[name="item_billing_unit[]"]').forEach(function (el) {
        el.value = hourly ? 'hour' : 'each';
    });
    document.querySelectorAll('#items input[name="item_qty[]"]').forEach(function (el) {
        el.placeholder = hourly ? 'Est. hours' : 'Qty';
    });
}

// Safely add event listeners only if elements exist
['discountType', 'discountValue', 'taxPercent'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalc);
});

['depositType', 'depositValue'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalc);
});

var hourlyModeEl = document.getElementById('billingModeHourly');
if (hourlyModeEl) hourlyModeEl.addEventListener('change', updateHourlyModeUI);

var discountTypeEl = document.getElementById('discountType');
if (discountTypeEl) discountTypeEl.addEventListener('change', updateDiscountWarning);

// No need for DOMContentLoaded start date setting - now handled in toggleDocTypeFields
function toggleDocTypeFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');

    // Show/hide the appropriate settings sections
    document.getElementById('longTermFields').style.display = isLongTerm ? 'block' : 'none';
    var onDemandSection = document.getElementById('onDemandFields');
    if (onDemandSection) onDemandSection.style.display = isOnDemand ? 'block' : 'none';

    // Always show items section (all quote types use items, except long-term per_invoice)
    document.getElementById('items').parentElement.style.display = 'block';
    document.getElementById('invoiceAmountRow').style.display = 'none';

    if (isLongTerm) {
        // Set start date to today when first enabling long-term
        var startField = document.getElementById('startDateField');
        if (startField && !startField.value) {
            startField.value = new Date().toISOString().split('T')[0];
        }
        toggleEndDate();
        togglePricingFields();
        updateDiscountWarning();
    } else if (isOnDemand) {
        var onDemandStartField = document.getElementById('onDemandStartDate');
        if (onDemandStartField && !onDemandStartField.value) {
            onDemandStartField.value = new Date().toISOString().split('T')[0];
        }
        toggleOnDemandEndDate();
        toggleOnDemandPricingMode();
    } else {
        // Regular or on-demand quote - show custom fields if they exist
        ['depositTypeLabel', 'depositValueLabel', 'fulfillmentDateLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        var flatAmount = document.getElementById('onDemandFlatAmount');
        if (flatAmount) flatAmount.style.display = 'none';
        document.getElementById('items').parentElement.style.display = 'block';
        setItemsRequired(true);
    }
    recalc();
}

function toggleOnDemandEndDate() {
    var typeEl = document.getElementById('onDemandEndDateType');
    var field = document.getElementById('onDemandEndDateField');
    if (!typeEl || !field) return;

    field.style.display = typeEl.value === 'fixed' ? 'block' : 'none';
}

function toggleEndDate() {
    var type = document.getElementById('endDateType').value;
    var isOngoing = (type === 'ongoing');

    document.getElementById('endDateField').style.display = isOngoing ? 'none' : 'block';

    // Hide fulfillment date when ongoing
    var fulfillmentLabel = document.getElementById('fulfillmentDateLabel');
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    if (docType === 'long_term' && fulfillmentLabel) {
        fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
    }

    var fixedTotalOption = document.getElementById('fixedTotalOption');
    if (isOngoing) {
        fixedTotalOption.style.display = 'none';
        document.getElementById('recurringPerInvoice').checked = true;
    } else {
        fixedTotalOption.style.display = 'flex';
    }

    togglePricingFields();
    updateDiscountWarning();
    recalc();
}

// Recalc when date/interval inputs change
['startDateField'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', recalc);
});
document.querySelectorAll('input[name="lt_end_date"]').forEach(el => el.addEventListener('change', recalc));
['billingIntervalCount', 'billingIntervalUnit'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', recalc);
});

function togglePricingFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;

    if (docType !== 'long_term') {
        if (docType === 'on_demand') {
            toggleOnDemandPricingMode();
            return;
        }

        // Regular quote - show custom fields, items always visible
        ['depositTypeLabel', 'depositValueLabel', 'fulfillmentDateLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        document.getElementById('items').parentElement.style.display = 'block';
        // Re-enable required attributes on items
        setItemsRequired(true);
        return;
    }

    // Long-term quote - check pricing type
    var pricingTypeEl = document.querySelector('input[name="lt_pricing_type"]:checked');
    var pricingType = pricingTypeEl ? pricingTypeEl.value : 'per_invoice';

    if (pricingType === 'per_invoice') {
        // Recurring amount - hide deposit and fulfillment, hide items
        ['depositTypeLabel', 'depositValueLabel', 'fulfillmentDateLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        document.getElementById('perInvoiceField').style.display = 'block';
        document.getElementById('fixedTotalFields').style.display = 'none';
        document.getElementById('items').parentElement.style.display = 'none';
        document.getElementById('billingIntervalFields').style.display = 'grid';
        // Disable required attributes on hidden items so form can submit
        setItemsRequired(false);
    } else {
        // Fixed total - show deposit and fulfillment, show items
        ['depositTypeLabel', 'depositValueLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        var isOngoing = document.getElementById('endDateType').value === 'ongoing';
        const fulfillmentLabel = document.getElementById('fulfillmentDateLabel');
        if (fulfillmentLabel) fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
        document.getElementById('perInvoiceField').style.display = 'none';
        document.getElementById('fixedTotalFields').style.display = 'block';
        document.getElementById('items').parentElement.style.display = 'block';
        document.getElementById('billingIntervalFields').style.display = 'grid';
        // Re-enable required attributes on items
        setItemsRequired(true);
    }
    recalc();
}

function toggleOnDemandPricingMode() {
    var docType = document.querySelector('input[name="doc_type"]:checked')?.value || 'regular';
    var selectedMode = document.querySelector('input[name="od_pricing_mode"]:checked')?.value || 'items';
    var itemsWrap = document.getElementById('items')?.parentElement;
    var flatAmount = document.getElementById('onDemandFlatAmount');
    var flatInput = document.getElementById('onDemandAmountInput');
    var useFlat = docType === 'on_demand' && selectedMode === 'flat';

    if (itemsWrap) {
        itemsWrap.style.display = useFlat ? 'none' : 'block';
    }
    if (flatAmount) {
        flatAmount.style.display = useFlat ? 'block' : 'none';
    }
    if (flatInput) {
        if (useFlat) {
            flatInput.setAttribute('required', '');
        } else {
            flatInput.removeAttribute('required');
        }
    }

    setItemsRequired(!useFlat);
    recalc();
}

// Helper to enable/disable required attribute on item inputs
function setItemsRequired(required) {
    document.querySelectorAll('#items input[name="item[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
    document.querySelectorAll('#items input[name="item_qty[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
    document.querySelectorAll('#items input[name="item_price[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
}

function updateDiscountWarning() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOngoing = document.getElementById('endDateType').value === 'ongoing';
    var discountType = document.getElementById('discountType').value;

    var warning = document.getElementById('discountWarning');
    if (isLongTerm && isOngoing && discountType !== 'none') {
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
}

addItem();
updateHourlyModeUI();

function loadProjectsForClient(clientId) {
    const projectSection = document.getElementById('projectSection');
    const projectSelect = document.getElementById('projectSelect');
    if (!clientId) {
        if (projectSelect) projectSelect.innerHTML = '<option value="">-- Select Project --</option>';
        if (projectSection) projectSection.style.display = 'none';
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
                    option.textContent = project.name + ' (' + project.status.replace('_', ' ') + ')';
                    projectSelect.appendChild(option);
                });
                projectSection.style.display = 'block';
            } else {
                projectSelect.value = '';
                projectSection.style.display = 'none';
            }
        })
        .catch(() => {
            projectSection.style.display = 'none';
        });
}

document.getElementById('createProjectBtn').addEventListener('click', function () {
    const clientId = document.getElementById('clientId').value;
    if (!clientId) {
        alert('Please select a client first.');
        return;
    }

    const projectName = prompt('Enter project name:');
    if (!projectName || !projectName.trim()) return;

    fetch('/?page=project/projects-create', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'csrf=' + encodeURIComponent(document.querySelector('input[name="csrf"]').value) +
            '&name=' + encodeURIComponent(projectName.trim()) +
            '&client_id=' + encodeURIComponent(clientId) + '&quick=1'
    })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                // Reload projects for this client
                loadProjectsForClient(clientId);
                // Select the new project
                setTimeout(() => {
                    const projectSelect = document.getElementById('projectSelect');
                    for (let i = 0; i < projectSelect.options.length; i++) {
                        if (projectSelect.options[i].value == result.project_id) {
                            projectSelect.selectedIndex = i;
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

function initQuoteClientDropdown() {
    var ci = document.getElementById('clientInput');
    var cid = document.getElementById('clientId');
    var sug = document.getElementById('clientSuggest');
    var taxBanner = document.getElementById('taxExemptBanner');
    
    if (!ci || !cid || !sug) {
        console.log('Quote client dropdown elements not found');
        return;
    }

    // Guard against duplicate initialization (SPA navigation re-fires pageLoaded)
    if (ci._quoteDropdownReady) return;
    ci._quoteDropdownReady = true;
    
    ci.addEventListener('input', function () {
        cid.value = '';
        var t = this.value.trim();
        if (!t) { sug.style.display = 'none'; sug.innerHTML = ''; if(taxBanner) taxBanner.style.display = 'none'; return; }
        fetch('/?page=clients-search&term=' + encodeURIComponent(t))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) { sug.style.display = 'none'; sug.innerHTML = ''; return; }
                sug.innerHTML = list.map((x, index) => `<div data-client-index="${index}" style="padding:8px 10px;cursor:pointer"><strong>${escapeDocumentRecipient(x.name)}</strong>${x.org_name ? `<small style="display:block;color:#6b7280">${escapeDocumentRecipient(x.org_name)}</small>` : ''}</div>`).join('');
                Array.from(sug.children).forEach(el => {
                    el.addEventListener('click', function (e) {
                        e.stopPropagation();
                        const client = list[Number(this.dataset.clientIndex)];
                        if (!client) return;
                        ci.value = client.name; cid.value = client.id;
                        cid.dataset.organizationId = client.organization_id || '';
                        cid.dataset.organizationName = client.org_name || '';
                        cid.dispatchEvent(new Event('change', { bubbles: true }));
                        if (client.tax_exempt_file && taxBanner) { taxBanner.style.display = 'block'; } else if(taxBanner) { taxBanner.style.display = 'none'; }
                        loadProjectsForClient(client.id);
                        sug.style.display = 'none';
                    });
                });
                sug.style.display = 'block';
            }).catch(() => { sug.style.display = 'none' });
    });
}
function escapeDocumentRecipient(value) {
    var element = document.createElement('div');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
}
initQuoteClientDropdown.pageInitializerId = 'quote-create-client-dropdown';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('quote/quotes-create', initQuoteClientDropdown);
} else {
    initQuoteClientDropdown();
}

document.getElementById('quoteForm').addEventListener('submit', function (e) {
    var cid = document.getElementById('clientId');
    if (!cid || !cid.value) {
        e.preventDefault();
        alert('Please select a client from suggestions.');
    }
});
