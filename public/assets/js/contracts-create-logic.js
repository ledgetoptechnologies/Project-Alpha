var itemCounterCo = 0;

function money(n) { return '$' + (Number(n) || 0).toFixed(2) }

function addItemCo(item = '', desc = '', qty = 1, price = 0, billingUnit = 'each') {
    var wrap = document.createElement('div');
    var itemId = 'itemCo_' + (itemCounterCo++);
    var descId = 'descCo_' + itemCounterCo;
    var priceId = 'priceCo_' + itemCounterCo;
    wrap.style.display = 'grid'; wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr 1fr auto'; wrap.style.gap = '8px';
    var selectedUnit = ['each', 'hour', 'mile'].includes(billingUnit) ? billingUnit : 'each';
    wrap.innerHTML = `
    <input id="${itemId}" required placeholder="Item name..." name="item[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${item}" oninput="recalcCo()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    <textarea id="${descId}" placeholder="Description (optional)" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px" oninput="recalcCo()">${desc}</textarea>
    <input required type="number" step="0.01" min="0" name="item_qty[]" class="qty-input" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalcCo()">
    <input id="${priceId}" required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalcCo()">
    <select name="item_billing_unit[]" aria-label="Billing unit" style="padding:10px;border-radius:8px;border:1px solid #ddd">
      <option value="each" ${selectedUnit === 'each' ? 'selected' : ''}>Each</option>
      <option value="hour" ${selectedUnit === 'hour' ? 'selected' : ''}>Hours</option>
      <option value="mile" ${selectedUnit === 'mile' ? 'selected' : ''}>Miles</option>
    </select>
    <button type="button" onclick="this.parentElement.remove();recalcCo()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
    document.getElementById('itemsCo').appendChild(wrap);

    // Re-initialize autocomplete for the new item input
    if (window.ItemAutocomplete) {
        const input = document.getElementById(itemId);
        const descField = document.getElementById(descId);
        const priceField = document.getElementById(priceId);
        new ItemAutocomplete(input, {
            descriptionField: descField,
            priceField: priceField
        });
    }

    if (typeof updateHourlyModeUICo === 'function') updateHourlyModeUICo();
    recalcCo();
}
// Calculate number of billing periods between two dates
function calcInvoiceCountFromDatesCo(startDate, endDate, intervalCount, intervalUnit) {
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

function recalcCo() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');
    // Get pricing type from radio (long-term) or default for on-demand
    var pricingTypeEl = document.querySelector('input[name="pricing_type"]:checked');
    var pricingType = isLongTerm ? (pricingTypeEl?.value || null) : (isOnDemand ? 'on_demand' : null);
    var endDateEl = document.getElementById('endDateTypeCo');
    var isOngoing = isLongTerm && endDateEl && endDateEl.value === 'ongoing';
    var hasFixedEnd = endDateEl && endDateEl.value === 'fixed';

    // Auto-calculate invoice count when Fixed End Date + Fixed Total
    if (isLongTerm && pricingType === 'fixed_total' && hasFixedEnd) {
        var startVal = document.getElementById('startDateFieldCo').value;
        var endVal = document.querySelector('input[name="end_date"]').value;
        var intCount = parseInt(document.getElementById('billingIntervalCount').value) || 1;
        var intUnit = document.getElementById('billingIntervalUnit').value;
        var autoCount = calcInvoiceCountFromDatesCo(startVal, endVal, intCount, intUnit);
        var invoiceInput = document.getElementById('invoiceCountInputCo');
        if (invoiceInput) {
            invoiceInput.value = autoCount;
            invoiceInput.readOnly = true;
            invoiceInput.style.backgroundColor = '#f3f4f6';
        }
    } else {
        var invoiceInput = document.getElementById('invoiceCountInputCo');
        if (invoiceInput) {
            invoiceInput.readOnly = false;
            invoiceInput.style.backgroundColor = '';
        }
    }

    var subtotal = 0;

    // Calculate subtotal based on pricing type
    if (isLongTerm && (pricingType === 'per_invoice' || pricingType === 'on_demand')) {
        // Use price per invoice
        subtotal = parseFloat(document.getElementById('pricePerInvoiceInput').value) || 0;
    } else if (isOnDemand) {
        var odMode = document.querySelector('input[name="od_pricing_mode"]:checked');
        if (odMode && odMode.value === 'flat') {
            subtotal = parseFloat(document.getElementById('onDemandAmountInputCo').value) || 0;
        } else {
            var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
            var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
            for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
        }
    } else {
        // Use line items
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    // For fixed_total pricing, recalculate based on items
    if (isLongTerm && pricingType === 'fixed_total') {
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        subtotal = 0;
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    var dtype = document.getElementById('discountTypeCo').value;
    var dval = parseFloat(document.getElementById('discountValueCo').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercentCo').value) || 0;
    var discount = 0;
    if (dtype === 'percent') { discount = Math.max(0, Math.min(100, dval)) * subtotal / 100; }
    else if (dtype === 'fixed') { discount = Math.max(0, dval); }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);

    // Calculate deposit
    var depositTypeEl = document.getElementById('depositTypeCo');
    var depositValueEl = document.getElementById('depositValueCo');
    var depType = depositTypeEl ? depositTypeEl.value : 'none';
    var depVal = depositValueEl ? parseFloat(depositValueEl.value) || 0 : 0;
    var deposit = 0;
    if (depType === 'percent') { deposit = Math.max(0, Math.min(100, depVal)) * total / 100; }
    else if (depType === 'fixed') { deposit = Math.max(0, depVal); }

    document.getElementById('subtotalValCo').textContent = money(subtotal);
    document.getElementById('discountValCo').textContent = money(discount);
    document.getElementById('taxValCo').textContent = money(tax);

    // For ongoing contracts, don't show total (unknown)
    if (isOngoing) {
        document.getElementById('totalValCo').parentElement.style.display = 'none';
    } else {
        document.getElementById('totalValCo').parentElement.style.display = 'flex';
        document.getElementById('totalValCo').textContent = money(total);
    }

    // Show calculated price per invoice for fixed_total
    if (isLongTerm && pricingType === 'fixed_total') {
        var invoiceCount = parseInt(document.getElementById('invoiceCountInputCo').value) || 1;
        var pricePerInv = total / invoiceCount;
        document.getElementById('calcPriceValCo').textContent = money(pricePerInv);
        document.getElementById('invoiceAmountRow').style.display = 'none';
    } else if (isLongTerm) {
        document.getElementById('invoiceAmountRow').style.display = 'block';
        document.getElementById('invoiceAmountVal').textContent = money(total);
    } else {
        document.getElementById('invoiceAmountRow').style.display = 'none';
    }

    // Show/hide deposit row
    var depositRowEl = document.getElementById('depositRowCo');
    var depositValEl = document.getElementById('depositValCo');
    if (depositRowEl) {
        if (depType !== 'none' && deposit > 0) {
            depositRowEl.style.display = 'block';
            if (depositValEl) depositValEl.textContent = money(deposit);
        } else {
            depositRowEl.style.display = 'none';
        }
    }

    // Update discount warning
    updateDiscountWarning();
}

function updateHourlyModeUICo() {
    var hourly = !!document.getElementById('billingModeHourlyCo')?.checked;
    var hint = document.getElementById('hourlyBillingHintCo');
    if (hint) hint.style.display = hourly ? 'block' : 'none';
    document.querySelectorAll('#itemsCo select[name="item_billing_unit[]"]').forEach(function (el) {
        if (hourly) el.value = 'hour';
        else if (el.value === 'hour') el.value = 'each';
    });
    document.querySelectorAll('#itemsCo input[name="item_qty[]"]').forEach(function (el) {
        el.placeholder = hourly ? 'Est. hours' : 'Qty';
    });
}
// Safely add event listeners only if elements exist
['discountTypeCo', 'discountValueCo', 'taxPercentCo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalcCo);
});
['depositTypeCo', 'depositValueCo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalcCo);
});
var discountTypeElCo = document.getElementById('discountTypeCo');
if (discountTypeElCo) discountTypeElCo.addEventListener('change', updateDiscountWarning);
var hourlyModeElCo = document.getElementById('billingModeHourlyCo');
if (hourlyModeElCo) hourlyModeElCo.addEventListener('change', updateHourlyModeUICo);

// No need for DOMContentLoaded start date setting - now handled in toggleDocTypeFields

function toggleDocTypeFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');

    // Show/hide the appropriate settings section
    document.getElementById('longTermFields').style.display = isLongTerm ? 'block' : 'none';
    var onDemandSection = document.getElementById('onDemandFieldsCo');
    if (onDemandSection) onDemandSection.style.display = isOnDemand ? 'block' : 'none';

    if (isLongTerm) {
        // Set start date to today when first enabling long-term
        var startField = document.getElementById('startDateFieldCo');
        if (startField && !startField.value) {
            startField.value = new Date().toISOString().split('T')[0];
        }
        toggleEndDate();
        togglePricingFields();
        updateDiscountWarning();
    } else if (isOnDemand) {
        var onDemandStartField = document.getElementById('onDemandStartDateCo');
        if (onDemandStartField && !onDemandStartField.value) {
            onDemandStartField.value = new Date().toISOString().split('T')[0];
        }
        toggleOnDemandEndDateCo();
        toggleOnDemandPricingModeCo();
        document.getElementById('invoiceAmountRow').style.display = 'none';
    } else {
        // Regular contract - always show items
        document.getElementById('itemsCo').parentElement.style.display = 'block';
        document.getElementById('invoiceAmountRow').style.display = 'none';
        var flatAmount = document.getElementById('onDemandFlatAmountCo');
        if (flatAmount) flatAmount.style.display = 'none';
        setItemsRequiredCo(true);
        // Show custom fields for regular contracts (if they exist)
        ['depositTypeLabelCo', 'depositValueLabelCo', 'fulfillmentDateLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
    }
    recalcCo();
}

function toggleOnDemandEndDateCo() {
    var typeEl = document.getElementById('onDemandEndDateTypeCo');
    var field = document.getElementById('onDemandEndDateFieldCo');
    if (!typeEl || !field) return;

    field.style.display = typeEl.value === 'fixed' ? 'block' : 'none';
}

function toggleEndDate() {
    var type = document.getElementById('endDateTypeCo').value;
    var isOngoing = (type === 'ongoing');

    document.getElementById('endDateFieldCo').style.display = isOngoing ? 'none' : 'block';

    // Hide fulfillment date when ongoing
    var fulfillmentLabel = document.getElementById('fulfillmentDateLabelCo');
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    if (docType === 'long_term' && fulfillmentLabel) {
        fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
    }

    // Show/hide fixed total option based on contract duration
    var fixedTotalOption = document.getElementById('fixedTotalOption');
    if (isOngoing) {
        fixedTotalOption.style.display = 'none';
        // Force per_invoice if ongoing
        document.getElementById('recurringPerInvoice').checked = true;
    } else {
        fixedTotalOption.style.display = 'flex';
    }

    togglePricingFields();
    updateDiscountWarning();
    recalcCo();
}

// Recalc when date/interval inputs change
['startDateFieldCo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', recalcCo);
});
document.querySelectorAll('input[name="end_date"]').forEach(el => el.addEventListener('change', recalcCo));
['billingIntervalCount', 'billingIntervalUnit'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', recalcCo);
});

function togglePricingFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');

    if (docType === 'regular') {
        // Regular contract - show custom fields and items
        ['depositTypeLabelCo', 'depositValueLabelCo', 'fulfillmentDateLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        document.getElementById('itemsCo').parentElement.style.display = 'block';
        setItemsRequiredCo(true);
        recalcCo();
        return;
    }

    if (isOnDemand) {
        toggleOnDemandPricingModeCo();
        return;
    }

    // Long-term contract - check pricing type
    var pricingTypeEl = document.querySelector('input[name="pricing_type"]:checked');
    var pricingType = pricingTypeEl ? pricingTypeEl.value : 'per_invoice';

    if (pricingType === 'per_invoice') {
        // Recurring amount - hide deposit and fulfillment, hide items
        ['depositTypeLabelCo', 'depositValueLabelCo', 'fulfillmentDateLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        document.getElementById('perInvoiceField').style.display = 'block';
        document.getElementById('fixedTotalFieldsCo').style.display = 'none';
        document.getElementById('itemsCo').parentElement.style.display = 'none';
        // Disable required attributes on hidden items so form can submit
        setItemsRequiredCo(false);
    } else {
        // Fixed total - show deposit and fulfillment, show items
        ['depositTypeLabelCo', 'depositValueLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        var isOngoing = document.getElementById('endDateTypeCo').value === 'ongoing';
        const fulfillmentLabel = document.getElementById('fulfillmentDateLabelCo');
        if (fulfillmentLabel) fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
        document.getElementById('perInvoiceField').style.display = 'none';
        document.getElementById('fixedTotalFieldsCo').style.display = 'block';
        document.getElementById('itemsCo').parentElement.style.display = 'block';
        // Re-enable required attributes on items
        setItemsRequiredCo(true);
    }
    recalcCo();
}

function toggleOnDemandPricingModeCo() {
    var docType = document.querySelector('input[name="doc_type"]:checked')?.value || 'regular';
    var mode = document.querySelector('input[name="od_pricing_mode"]:checked');
    var modeVal = mode ? mode.value : 'items';
    var flatSection = document.getElementById('onDemandFlatAmountCo');
    var flatInput = document.getElementById('onDemandAmountInputCo');
    var itemsWrap = document.getElementById('itemsCo')?.parentElement;
    var useFlat = docType === 'on_demand' && modeVal === 'flat';

    if (useFlat) {
        if (flatSection) flatSection.style.display = 'block';
        if (itemsWrap) itemsWrap.style.display = 'none';
    } else {
        if (flatSection) flatSection.style.display = 'none';
        if (itemsWrap) itemsWrap.style.display = 'block';
    }

    if (flatInput) {
        if (useFlat) {
            flatInput.setAttribute('required', '');
        } else {
            flatInput.removeAttribute('required');
        }
    }

    setItemsRequiredCo(!useFlat);
    recalcCo();
}

// Helper to enable/disable required attribute on contract item inputs
function setItemsRequiredCo(required) {
    document.querySelectorAll('#itemsCo input[name="item[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
    document.querySelectorAll('#itemsCo input[name="item_qty[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
    document.querySelectorAll('#itemsCo input[name="item_price[]"]').forEach(el => {
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
    var isOngoing = document.getElementById('endDateTypeCo').value === 'ongoing';
    var discountType = document.getElementById('discountTypeCo').value;

    var warning = document.getElementById('discountWarning');
    if (isLongTerm && isOngoing && discountType !== 'none') {
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
}

addItemCo();
updateHourlyModeUICo();

// Client typeahead
function initContractClientDropdown() {
    var ci = document.getElementById('clientInputCo');
    var cid = document.getElementById('clientIdCo');
    var sug = document.getElementById('clientSuggestCo');
    var taxBanner = document.getElementById('taxExemptBannerCo');
    
    if (!ci || !cid || !sug) {
        console.log('Contract client dropdown elements not found');
        return;
    }

    // Guard against duplicate initialization (SPA navigation re-fires pageLoaded)
    if (ci._contractDropdownReady) return;
    ci._contractDropdownReady = true;
    
    ci.addEventListener('input', function () {
        cid.value = '';
        var t = this.value.trim();
        if (!t) { sug.style.display = 'none'; sug.innerHTML = ''; if(taxBanner) taxBanner.style.display = 'none'; return; }
        fetch('/?page=clients-search&term=' + encodeURIComponent(t))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) { sug.style.display = 'none'; sug.innerHTML = ''; return; }
                sug.innerHTML = list.map((x, index) => `<div data-client-index="${index}" style="padding:8px 10px;cursor:pointer"><strong>${escapeDocumentRecipientCo(x.name)}</strong>${x.org_name ? `<small style="display:block;color:#6b7280">${escapeDocumentRecipientCo(x.org_name)}</small>` : ''}</div>`).join('');
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
                        loadProjectsForClientCo(client.id);
                        sug.style.display = 'none';
                    });
                });
                sug.style.display = 'block';
            }).catch(() => { sug.style.display = 'none' });
    });
}
function escapeDocumentRecipientCo(value) {
    var element = document.createElement('div');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
}
initContractClientDropdown.pageInitializerId = 'contract-create-client-dropdown';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('contract/contracts-create', initContractClientDropdown);
} else {
    initContractClientDropdown();
}

function loadProjectsForClientCo(clientId) {
    const projectSection = document.getElementById('projectSectionCo');
    const projectSelect = document.getElementById('projectSelectCo');
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

document.getElementById('createProjectBtnCo').addEventListener('click', function () {
    const clientId = document.getElementById('clientIdCo').value;
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
                loadProjectsForClientCo(clientId);
                // Select the new project
                setTimeout(() => {
                    const projectSelect = document.getElementById('projectSelectCo');
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

document.getElementById('coCreateForm').addEventListener('submit', function (e) {
    var cid = document.getElementById('clientIdCo');
    if (!cid || !cid.value) {
        e.preventDefault();
        alert('Please select a client from suggestions.');
    }
    // Form action stays as contracts-create - the controller routes based on doc_type
});

// Signature Management
var signatureCount = 0;
var MAX_SIGNATURES = 5;

function addSignature(title = 'Client Signature', isRequired = true) {
    if (signatureCount >= MAX_SIGNATURES) {
        alert('Maximum of ' + MAX_SIGNATURES + ' signatures allowed');
        return;
    }

    signatureCount++;
    const sigId = 'sig_' + Date.now() + '_' + signatureCount;

    const wrap = document.createElement('div');
    wrap.className = 'signature-item';
    wrap.dataset.sigId = sigId;
    wrap.style.cssText = 'display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff';

    wrap.innerHTML = `
    <div style="display:grid;gap:8px">
      <input type="text" name="signature_titles[]" value="${title}" required placeholder="Signature Title (e.g., Project Manager, Owner)"
             style="padding:8px;border-radius:6px;border:1px solid #ddd;font-weight:600">
      <div style="font-size:12px;color:var(--muted)">Order: ${signatureCount}</div>
    </div>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
      <input type="checkbox" name="signature_required[]" value="${sigId}" ${isRequired ? 'checked' : ''}>
      Required
    </label>
    <button type="button" onclick="removeSignature('${sigId}')" ${signatureCount === 1 ? 'disabled style="opacity:0.5;cursor:not-allowed"' : ''}
            style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:13px">
      Remove
    </button>
    <input type="hidden" name="signature_orders[]" value="${signatureCount}">
  `;

    document.getElementById('signaturesList').appendChild(wrap);

    if (signatureCount >= MAX_SIGNATURES) {
        document.getElementById('addSigBtn').disabled = true;
        document.getElementById('addSigBtn').style.opacity = '0.5';
        document.getElementById('addSigBtn').style.cursor = 'not-allowed';
    }
}

function removeSignature(sigId) {
    const item = document.querySelector(`[data-sig-id="${sigId}"]`);
    if (item) {
        item.remove();
        signatureCount--;

        // Re-enable add button if under max
        if (signatureCount < MAX_SIGNATURES) {
            document.getElementById('addSigBtn').disabled = false;
            document.getElementById('addSigBtn').style.opacity = '1';
            document.getElementById('addSigBtn').style.cursor = 'pointer';
        }

        // Update order numbers
        const items = document.querySelectorAll('.signature-item');
        items.forEach((el, idx) => {
            const orderDiv = el.querySelector('[style*="Order:"]');
            if (orderDiv) orderDiv.textContent = 'Order: ' + (idx + 1);
            const orderInput = el.querySelector('input[name="signature_orders[]"]');
            if (orderInput) orderInput.value = idx + 1;
        });

        // Disable remove button on first signature if it's the only one
        if (signatureCount === 1) {
            const firstRemoveBtn = items[0]?.querySelector('button[onclick^="removeSignature"]');
            if (firstRemoveBtn) {
                firstRemoveBtn.disabled = true;
                firstRemoveBtn.style.opacity = '0.5';
                firstRemoveBtn.style.cursor = 'not-allowed';
            }
        }
    }
}

// Add default signature on page load
addSignature();
