// New quote or contract (document/new): kind of work, schedule, line items and totals.
// Invoices use invoices-create-logic.js; both share the kind cards handled here.

var itemCounter = 0;
var signatureCount = 0;
var MAX_SIGNATURES = 5;
var DOC_NEW_KIND_KEYS = { regular: 'one_time', long_term: 'recurring', on_demand: 'as_needed' };

function money(n) { return '$' + (Number(n) || 0).toFixed(2); }

function docNewEscape(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function docNewType() {
    var checked = document.querySelector('input[name="doc_type"]:checked');
    return checked ? checked.value : 'regular';
}

function addItem(item, desc, qty, price, billingUnit) {
    var list = document.getElementById('items');
    if (!list) return;
    var n = itemCounter++;
    var unit = ['each', 'hour', 'mile'].indexOf(billingUnit) >= 0 ? billingUnit : 'each';
    var row = document.createElement('div');
    row.className = 'doc-new-item';
    row.innerHTML =
        '<input id="item_' + n + '" class="input" required placeholder="Item name..." name="item[]" value="' + docNewEscape(item || '') + '" data-item-autocomplete data-description-field="desc_' + n + '" data-price-field="price_' + n + '" aria-label="Item">' +
        '<textarea id="desc_' + n + '" class="input" placeholder="Description (optional)" name="item_desc[]" rows="1" aria-label="Description">' + docNewEscape(desc || '') + '</textarea>' +
        '<input class="input qty-input" required type="number" step="0.01" min="0" name="item_qty[]" value="' + docNewEscape(qty == null ? 1 : qty) + '" aria-label="Quantity">' +
        '<input id="price_' + n + '" class="input" required type="number" step="0.01" min="0" name="item_price[]" value="' + docNewEscape(price == null ? 0 : price) + '" aria-label="Unit price">' +
        '<select class="input" name="item_billing_unit[]" aria-label="Billing unit">' +
        ['each', 'hour', 'mile'].map(function (u) {
            return '<option value="' + u + '"' + (u === unit ? ' selected' : '') + '>' + { each: 'Each', hour: 'Hours', mile: 'Miles' }[u] + '</option>';
        }).join('') +
        '</select>' +
        '<button type="button" class="btn btn-sm" data-remove-item>Remove</button>';
    list.appendChild(row);
    if (window.ItemAutocomplete) {
        new ItemAutocomplete(document.getElementById('item_' + n), {
            descriptionField: document.getElementById('desc_' + n),
            priceField: document.getElementById('price_' + n)
        });
    }
    setItemsRequired(!(document.getElementById('itemsCard') || {}).hidden);
    updateHourlyModeUI();
    recalc();
}

// Billing periods between two dates; mirrors long_term_contracts_create.php.
function calcInvoiceCountFromDates(startDate, endDate, intervalCount, intervalUnit) {
    if (!startDate || !endDate) return 0;
    var start = new Date(startDate + 'T00:00:00');
    var end = new Date(endDate + 'T00:00:00');
    if (isNaN(start) || isNaN(end) || end <= start) return 0;
    var periods = 0;
    var cursor = new Date(start);
    while (cursor < end) {
        if (intervalUnit === 'day') cursor.setDate(cursor.getDate() + intervalCount);
        else if (intervalUnit === 'week') cursor.setDate(cursor.getDate() + 7 * intervalCount);
        else if (intervalUnit === 'month') cursor.setMonth(cursor.getMonth() + intervalCount);
        else cursor.setFullYear(cursor.getFullYear() + intervalCount);
        if (cursor <= end) periods++;
    }
    return Math.max(1, periods);
}

function recalc() {
    var checked = document.querySelector('input[name="doc_type"]:checked');
    var docType = checked ? checked.value : 'regular';
    var isLongTerm = docType === 'long_term';
    var isOnDemand = docType === 'on_demand';
    var pricingEl = document.querySelector('input[name="lt_pricing_type"]:checked');
    var pricingType = isLongTerm ? (pricingEl ? pricingEl.value : 'per_invoice') : null;
    var endTypeEl = document.getElementById('endDateType');
    var hasFixedEnd = !!endTypeEl && endTypeEl.value === 'fixed';
    var isOngoing = isLongTerm && !hasFixedEnd;

    var invoiceInput = document.getElementById('invoiceCountInput');
    if (invoiceInput) {
        var autoCount = isLongTerm && pricingType === 'fixed_total' && hasFixedEnd;
        if (autoCount) {
            invoiceInput.value = calcInvoiceCountFromDates(
                document.getElementById('startDateField').value,
                document.getElementById('endDateInput').value,
                parseInt(document.getElementById('billingIntervalCount').value, 10) || 1,
                document.getElementById('billingIntervalUnit').value
            );
        }
        invoiceInput.readOnly = autoCount;
    }

    var subtotal = 0;
    var qtys = Array.from(document.querySelectorAll('[name="item_qty[]"]'));
    var prices = Array.from(document.querySelectorAll('[name="item_price[]"]'));
    for (var i = 0; i < qtys.length; i++) {
        subtotal += (parseFloat(qtys[i].value) || 0) * (parseFloat(prices[i] && prices[i].value) || 0);
    }
    if (isLongTerm && pricingType === 'per_invoice') {
        subtotal = parseFloat(document.getElementById('pricePerInvoiceInput').value) || 0;
    } else if (isOnDemand) {
        var odMode = document.querySelector('input[name="od_pricing_mode"]:checked');
        if (odMode && odMode.value === 'flat') {
            subtotal = parseFloat(document.getElementById('onDemandAmountInput').value) || 0;
        }
    }

    var dtype = document.getElementById('discountType').value;
    var dval = parseFloat(document.getElementById('discountValue').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercent').value) || 0;
    var discount = 0;
    if (dtype === 'percent') discount = Math.max(0, Math.min(100, dval)) * subtotal / 100;
    else if (dtype === 'fixed') discount = Math.max(0, dval);
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);

    var depositTypeEl = document.getElementById('depositType');
    var depositValueEl = document.getElementById('depositValue');
    var depType = depositTypeEl ? depositTypeEl.value : 'none';
    var depVal = depositValueEl ? parseFloat(depositValueEl.value) || 0 : 0;
    var deposit = 0;
    if (depType === 'percent') deposit = Math.max(0, Math.min(100, depVal)) * total / 100;
    else if (depType === 'fixed') deposit = Math.max(0, depVal);

    document.getElementById('subtotalVal').textContent = money(subtotal);
    document.getElementById('discountVal').textContent = money(discount);
    document.getElementById('taxVal').textContent = money(tax);
    var totalEl = document.getElementById('totalVal');
    totalEl.textContent = money(total);
    totalEl.parentElement.style.display = isOngoing ? 'none' : '';

    var amountRow = document.getElementById('invoiceAmountRow');
    var amountVal = document.getElementById('invoiceAmountVal');
    var calcPrice = document.getElementById('calcPriceVal');
    if (isLongTerm && pricingType === 'fixed_total') {
        if (calcPrice) calcPrice.textContent = money(total / (parseInt(invoiceInput && invoiceInput.value, 10) || 1));
        amountRow.style.display = 'none';
    } else if (isLongTerm) {
        amountRow.style.display = '';
        if (amountVal) amountVal.textContent = money(total);
    } else {
        amountRow.style.display = 'none';
    }

    var depositRow = document.getElementById('depositRow');
    if (depositRow) {
        depositRow.style.display = depType !== 'none' && deposit > 0 ? '' : 'none';
        document.getElementById('depositVal').textContent = money(deposit);
    }

    updateDiscountWarning();
}

function setItemsRequired(required) {
    document.querySelectorAll('#items input[name="item[]"], #items input[name="item_qty[]"], #items input[name="item_price[]"]').forEach(function (el) {
        el.required = required;
    });
}

function showFields(ids, visible) {
    ids.forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = visible ? '' : 'none';
    });
}

function updateDiscountWarning() {
    var warning = document.getElementById('discountWarning');
    if (!warning) return;
    var ongoing = document.getElementById('endDateType').value === 'ongoing';
    warning.hidden = !(docNewType() === 'long_term' && ongoing && document.getElementById('discountType').value !== 'none');
}

function updateHourlyModeUI() {
    var hourly = !!(document.getElementById('billingModeHourly') || {}).checked;
    document.querySelectorAll('#items select[name="item_billing_unit[]"]').forEach(function (el) {
        if (hourly) el.value = 'hour';
        else if (el.value === 'hour') el.value = 'each';
    });
    document.querySelectorAll('#items input[name="item_qty[]"]').forEach(function (el) {
        el.placeholder = hourly ? 'Est. hours' : 'Qty';
    });
}

// Show the panels for the selected kind of work and re-apply its pricing rules.
function toggleDocTypeFields() {
    var docType = docNewType();
    var today = new Date().toISOString().split('T')[0];
    document.querySelectorAll('[data-kind-only]').forEach(function (el) {
        el.hidden = el.getAttribute('data-kind-only').split(' ').indexOf(docType) < 0;
    });
    document.querySelectorAll('[data-doc-new-as]').forEach(function (link) {
        var url = new URL(link.getAttribute('href'), window.location.href);
        url.searchParams.set('kind', DOC_NEW_KIND_KEYS[docType]);
        link.setAttribute('href', url.pathname + url.search);
    });
    var startField = document.getElementById(docType === 'on_demand' ? 'onDemandStartDate' : 'startDateField');
    if (docType !== 'regular' && startField && !startField.value) startField.value = today;
    togglePricingFields();
}

function toggleEndDate() {
    var ongoing = document.getElementById('endDateType').value === 'ongoing';
    document.getElementById('endDateField').hidden = ongoing;
    document.getElementById('fixedTotalOption').hidden = ongoing;
    if (ongoing) document.getElementById('recurringPerInvoice').checked = true;
    togglePricingFields();
}

function togglePricingFields() {
    var docType = docNewType();
    var itemsCard = document.getElementById('itemsCard');
    var itemsShown = true;
    var depositShown = true;
    var fulfillmentShown = true;
    if (docType === 'long_term') {
        var fixedTotal = (document.querySelector('input[name="lt_pricing_type"]:checked') || {}).value === 'fixed_total';
        var ongoing = document.getElementById('endDateType').value === 'ongoing';
        document.getElementById('perInvoiceField').hidden = fixedTotal;
        document.getElementById('fixedTotalFields').hidden = !fixedTotal;
        itemsShown = fixedTotal;
        depositShown = fixedTotal;
        fulfillmentShown = fixedTotal && !ongoing;
    }
    var flat = docType === 'on_demand' && (document.querySelector('input[name="od_pricing_mode"]:checked') || {}).value === 'flat';
    document.getElementById('onDemandFlatAmount').hidden = !flat;
    document.getElementById('onDemandAmountInput').required = flat;
    if (flat) itemsShown = false;
    itemsCard.hidden = !itemsShown;
    setItemsRequired(itemsShown);
    showFields(['depositTypeLabel', 'depositValueLabel'], depositShown);
    showFields(['fulfillment_dateLabel', 'fulfillmentDateLabel'], fulfillmentShown);
    var terms = document.getElementById('termsCard');
    if (terms) {
        terms.hidden = !Array.from(terms.querySelectorAll('#customFieldsRow > label')).some(function (label) { return label.style.display !== 'none'; });
    }
    recalc();
}

function loadProjectsForClient(clientId) {
    var section = document.getElementById('projectSection');
    var select = document.getElementById('projectSelect');
    select.innerHTML = '<option value="">-- Select Project --</option>';
    if (!clientId) {
        section.style.display = 'none';
        return Promise.resolve();
    }
    return fetch('/?page=projects-search&client_id=' + encodeURIComponent(clientId))
        .then(function (r) { return r.json(); })
        .then(function (projects) {
            (projects || []).forEach(function (project) {
                var option = document.createElement('option');
                option.value = project.id;
                option.textContent = project.name + ' (' + String(project.status).replace('_', ' ') + ')';
                select.appendChild(option);
            });
        })
        .catch(function () {})
        .then(function () { section.style.display = ''; });
}

function createProjectForClient() {
    var clientId = document.getElementById('clientId').value;
    if (!clientId) { alert('Please select a client first.'); return; }
    var name = prompt('Enter project name:');
    if (!name || !name.trim()) return;
    fetch('/?page=project/projects-create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf=' + encodeURIComponent(document.querySelector('#docNewForm input[name="csrf"]').value) +
            '&name=' + encodeURIComponent(name.trim()) + '&client_id=' + encodeURIComponent(clientId) + '&quick=1'
    })
        .then(function (r) { return r.json(); })
        .then(function (result) {
            if (!result.success) { alert('Failed to create project: ' + (result.error || 'Unknown error')); return; }
            loadProjectsForClient(clientId).then(function () {
                document.getElementById('projectSelect').value = String(result.project_id);
            });
        })
        .catch(function () { alert('Failed to create project.'); });
}

function initClientSearch() {
    var input = document.getElementById('clientInput');
    var id = document.getElementById('clientId');
    var suggest = document.getElementById('clientSuggest');
    var banner = document.getElementById('taxExemptBanner');
    input.addEventListener('input', function () {
        id.value = '';
        var term = input.value.trim();
        if (!term) { suggest.style.display = 'none'; suggest.innerHTML = ''; banner.style.display = 'none'; return; }
        fetch('/?page=clients-search&term=' + encodeURIComponent(term))
            .then(function (r) { return r.json(); })
            .then(function (list) {
                if (!Array.isArray(list) || list.length === 0) { suggest.style.display = 'none'; suggest.innerHTML = ''; return; }
                suggest.innerHTML = list.map(function (x, index) {
                    return '<div data-client-index="' + index + '"><strong>' + docNewEscape(x.name) + '</strong>' + (x.org_name ? '<small>' + docNewEscape(x.org_name) + '</small>' : '') + '</div>';
                }).join('');
                Array.from(suggest.children).forEach(function (el) {
                    el.addEventListener('click', function (event) {
                        event.stopPropagation();
                        var client = list[Number(el.dataset.clientIndex)];
                        if (!client) return;
                        input.value = client.name;
                        id.value = client.id;
                        id.dataset.organizationId = client.organization_id || '';
                        id.dataset.organizationName = client.org_name || '';
                        id.dispatchEvent(new Event('change', { bubbles: true }));
                        banner.style.display = client.tax_exempt_file ? '' : 'none';
                        loadProjectsForClient(client.id);
                        suggest.style.display = 'none';
                    });
                });
                suggest.style.display = 'block';
            })
            .catch(function () { suggest.style.display = 'none'; });
    });
}

function addSignature(title, isRequired) {
    var list = document.getElementById('signaturesList');
    if (!list || signatureCount >= MAX_SIGNATURES) return;
    signatureCount++;
    var sigId = 'sig_' + Date.now() + '_' + signatureCount;
    var row = document.createElement('div');
    row.className = 'doc-new-signature';
    row.dataset.sigId = sigId;
    row.innerHTML =
        '<input class="input" type="text" name="signature_titles[]" value="' + docNewEscape(title || 'Client Signature') + '" required placeholder="Signature title (e.g. Project Manager)" aria-label="Signature title">' +
        '<label><input type="checkbox" name="signature_required[]" value="' + sigId + '"' + (isRequired === false ? '' : ' checked') + '> Required</label>' +
        '<button type="button" class="btn btn-sm" data-remove-signature>Remove</button>' +
        '<input type="hidden" name="signature_orders[]" value="' + signatureCount + '">';
    list.appendChild(row);
    renumberSignatures();
}

function renumberSignatures() {
    var rows = document.querySelectorAll('#signaturesList .doc-new-signature');
    signatureCount = rows.length;
    rows.forEach(function (row, index) {
        row.querySelector('input[name="signature_orders[]"]').value = index + 1;
        row.querySelector('[data-remove-signature]').disabled = rows.length === 1;
    });
    document.getElementById('addSigBtn').disabled = signatureCount >= MAX_SIGNATURES;
}

(function initDocNewForm() {
    var form = document.getElementById('docNewForm');
    if (!form || form.dataset.ready) return;
    form.dataset.ready = '1';
    itemCounter = 0;
    signatureCount = 0;

    form.addEventListener('change', function (event) {
        var name = event.target.name;
        if (name === 'doc_type') toggleDocTypeFields();
        else if (event.target.id === 'endDateType') toggleEndDate();
        else if (event.target.id === 'onDemandEndDateType') document.getElementById('onDemandEndDateField').hidden = event.target.value !== 'fixed';
        else if (name === 'lt_pricing_type' || name === 'od_pricing_mode') togglePricingFields();
        else if (event.target.id === 'billingModeHourly') updateHourlyModeUI();
        recalc();
    });
    form.addEventListener('input', recalc);
    form.addEventListener('click', function (event) {
        var target = event.target;
        if (target.closest('[data-remove-item]')) { target.closest('.doc-new-item').remove(); recalc(); }
        if (target.closest('[data-remove-signature]')) { target.closest('.doc-new-signature').remove(); renumberSignatures(); }
    });
    form.addEventListener('submit', function (event) {
        if (!document.getElementById('clientId').value) {
            event.preventDefault();
            alert('Please select a client from suggestions.');
        }
    });
    document.getElementById('addItemBtn').addEventListener('click', function () { addItem(); });
    document.getElementById('createProjectBtn').addEventListener('click', createProjectForClient);
    var addSig = document.getElementById('addSigBtn');
    if (addSig) addSig.addEventListener('click', function () { addSignature(); });

    initClientSearch();
    addItem();
    addSignature();
    toggleDocTypeFields();
})();
