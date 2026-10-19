var itemCounterCo = 0;
var itemData = getItemData();

//Add loaded data
itemData.forEach(item => {
    addItemCo(item.item, item.description, Number(item.quantity), Number(item.unit_price), item.billing_unit || 'each', item.item_library_id || '');
});

function escapeHtmlCo(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function initContractEditClientSearch() {
    var form = document.getElementById('coEditForm');
    var input = document.getElementById('contractEditClientSearch');
    var hidden = document.getElementById('contractEditClientId');
    var suggestions = document.getElementById('contractEditClientSuggest');
    var projectSelect = document.getElementById('contractEditProjectSelect');
    if (!form || !input || !hidden || !suggestions) return;
    if (input.dataset.contractEditClientReady === '1') return;
    input.dataset.contractEditClientReady = '1';
    var originalClientId = hidden.value || '';
    var originalProjectId = projectSelect ? (projectSelect.getAttribute('data-selected-project-id') || '') : '';

    function hideSuggestions() {
        suggestions.style.display = 'none';
        suggestions.innerHTML = '';
    }

    function setValidity() {
        if (hidden.value) {
            input.setCustomValidity('');
            return;
        }
        input.setCustomValidity('Choose a client from the search results.');
    }

    function renderProjects(projects, selectedProjectId) {
        if (!projectSelect) return;
        var selected = selectedProjectId || '';
        projectSelect.innerHTML = '<option value="">No project</option>';
        if (!Array.isArray(projects)) return;
        projects.forEach(function (project) {
            var option = document.createElement('option');
            option.value = project.id;
            option.textContent = project.name + ' - ' + String(project.status || '').replace('_', ' ');
            if (String(project.id) === String(selected)) option.selected = true;
            projectSelect.appendChild(option);
        });
    }

    function loadProjects(clientId, selectedProjectId) {
        if (!projectSelect) return;
        if (!clientId) {
            renderProjects([], '');
            return;
        }
        fetch('/?page=projects-search&client_id=' + encodeURIComponent(clientId))
            .then(function (response) { return response.json(); })
            .then(function (projects) { renderProjects(projects, selectedProjectId || ''); })
            .catch(function () { renderProjects([], ''); });
    }

    function renderSuggestions(list) {
        if (!Array.isArray(list) || list.length === 0) {
            hideSuggestions();
            return;
        }
        suggestions.innerHTML = list.map(function (client) {
            var meta = [];
            if (client.email) meta.push(client.email);
            if (client.org_name) meta.push(client.org_name);
            return '<button type="button" data-client-id="' + escapeHtmlCo(client.id) + '" data-client-name="' + escapeHtmlCo(client.name) + '" data-organization-id="' + escapeHtmlCo(client.organization_id || '') + '" data-organization-name="' + escapeHtmlCo(client.org_name || '') + '" style="display:block;width:100%;text-align:left;padding:9px 10px;border:0;border-bottom:1px solid #eef2f7;background:#fff;cursor:pointer">' +
                '<strong>' + escapeHtmlCo(client.name) + '</strong>' +
                (meta.length ? '<span style="display:block;margin-top:2px;color:#6b7280;font-size:12px">' + escapeHtmlCo(meta.join(' - ')) + '</span>' : '') +
                '</button>';
        }).join('');
        suggestions.style.display = 'block';
    }

    var searchTimer = null;
    input.addEventListener('input', function () {
        hidden.value = '';
        setValidity();
        var term = input.value.trim();
        clearTimeout(searchTimer);
        if (!term) {
            hideSuggestions();
            loadProjects('', '');
            return;
        }
        searchTimer = setTimeout(function () {
            fetch('/?page=clients-search&term=' + encodeURIComponent(term))
                .then(function (response) { return response.json(); })
                .then(renderSuggestions)
                .catch(hideSuggestions);
        }, 160);
    });

    suggestions.addEventListener('click', function (event) {
        var option = event.target.closest('[data-client-id]');
        if (!option) return;
        hidden.value = option.getAttribute('data-client-id') || '';
        hidden.dataset.organizationId = option.getAttribute('data-organization-id') || '';
        hidden.dataset.organizationName = option.getAttribute('data-organization-name') || '';
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        input.value = option.getAttribute('data-client-name') || '';
        input.setCustomValidity('');
        hideSuggestions();
        loadProjects(hidden.value, String(hidden.value) === String(originalClientId) ? originalProjectId : '');
    });

    document.addEventListener('click', function (event) {
        if (!suggestions.contains(event.target) && event.target !== input) {
            hideSuggestions();
        }
    });

    form.addEventListener('submit', function (event) {
        setValidity();
        if (!input.checkValidity()) {
            event.preventDefault();
            input.reportValidity();
        }
    });
}

function getItemData() {
    const element = document.getElementById("contract-items-data");
    if (!element) {
        return [];
    }

    try {
        return JSON.parse(element.textContent);
    } catch (e) {
        console.log(e);
        return [];
    }
}

function money(n) {
    return '$' + (Number(n) || 0).toFixed(2)
}

function addItemCo(item = '', desc = '', qty = 1, price = 0, billingUnit = 'each', itemLibraryId = '') {
    var wrap = document.createElement('div');
    var itemId = 'itemCo_' + (itemCounterCo++);
    var descId = 'descCo_' + itemCounterCo;
    var priceId = 'priceCo_' + itemCounterCo;
    wrap.style.display = 'grid'; wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr 1fr auto'; wrap.style.gap = '8px';

    var itemInput = document.createElement('input');
    itemInput.id = itemId;
    itemInput.required = true;
    itemInput.placeholder = 'Item name...';
    itemInput.name = 'item[]';
    itemInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
    itemInput.value = item;
    itemInput.oninput = recalcCo;
    itemInput.setAttribute('data-item-autocomplete', '');
    itemInput.setAttribute('data-description-field', descId);
    itemInput.setAttribute('data-price-field', priceId);
    itemInput.setAttribute('data-item-library-id', itemLibraryId);

    var descTextarea = document.createElement('textarea');
    descTextarea.id = descId;
    descTextarea.placeholder = 'Description (optional)';
    descTextarea.name = 'item_desc[]';
    descTextarea.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px';
    descTextarea.value = desc;
    descTextarea.oninput = recalcCo;

    var qtyInput = document.createElement('input');
    qtyInput.required = true;
    qtyInput.type = 'number';
    qtyInput.step = '0.01';
    qtyInput.min = '0';
    qtyInput.name = 'item_qty[]';
    qtyInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
    qtyInput.value = qty;
    qtyInput.oninput = recalcCo;

    var priceInput = document.createElement('input');
    priceInput.id = priceId;
    priceInput.required = true;
    priceInput.type = 'number';
    priceInput.step = '0.01';
    priceInput.min = '0';
    priceInput.name = 'item_price[]';
    priceInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
    priceInput.value = price;
    priceInput.oninput = recalcCo;

    var unitInput = document.createElement('select');
    unitInput.name = 'item_billing_unit[]';
    ['each', 'hour', 'day', 'mile', 'project'].forEach(function (value) {
        var option = document.createElement('option');
        option.value = value;
        option.textContent = value.charAt(0).toUpperCase() + value.slice(1);
        unitInput.appendChild(option);
    });
    unitInput.value = ['each', 'hour', 'day', 'mile', 'project'].includes(billingUnit) ? billingUnit : 'each';
    unitInput.setAttribute('aria-label', 'Billing unit');
    unitInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.textContent = 'Remove';
    removeBtn.style.cssText = 'border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px';
    removeBtn.onclick = function () { wrap.remove(); recalcCo(); };

    wrap.appendChild(unitInput);
    wrap.appendChild(itemInput);
    wrap.appendChild(descTextarea);
    wrap.appendChild(qtyInput);
    wrap.appendChild(priceInput);
    wrap.appendChild(removeBtn);
    document.getElementById('itemsCo').appendChild(wrap);

    // Initialize autocomplete for the new item input
    if (window.ItemAutocomplete) {
        new ItemAutocomplete(itemInput, {
            descriptionField: descTextarea,
            priceField: priceInput
        });
    }

    recalcCo();
}

function recalcCo() {
    var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
    var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
    var subtotal = 0; for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    var pricingType = document.querySelector('[name="pricing_type"]:checked');
    var pricePerInvoice = document.getElementById('pricePerInvoiceEditCo');
    if (pricingType && pricingType.value === 'per_invoice' && pricePerInvoice) {
        subtotal = parseFloat(pricePerInvoice.value) || 0;
    }
    var dtype = document.getElementById('discountTypeCo').value;
    var dval = parseFloat(document.getElementById('discountValueCo').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercentCo').value) || 0;
    var discount = 0; if (dtype === 'percent') { discount = Math.max(0, Math.min(100, dval)) * subtotal / 100; } else if (dtype === 'fixed') { discount = Math.max(0, dval); }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);
    document.getElementById('subtotalValCo').textContent = money(subtotal);
    document.getElementById('discountValCo').textContent = money(discount);
    document.getElementById('taxValCo').textContent = money(tax);
    document.getElementById('totalValCo').textContent = money(total);
}

function initLongTermEditFieldsCo() {
    var longTermFields = document.getElementById('longTermEditFields');
    if (!longTermFields) return;
    var endDateType = document.getElementById('editEndDateTypeCo');
    var endDateField = document.getElementById('editEndDateFieldCo');
    var perInvoiceField = document.getElementById('perInvoiceEditFieldCo');
    var fixedTotalField = document.getElementById('fixedTotalEditFieldCo');

    function updateEndDate() {
        if (!endDateType || !endDateField) return;
        endDateField.style.display = endDateType.value === 'fixed' ? '' : 'none';
    }

    function updatePricing() {
        var selected = document.querySelector('[name="pricing_type"]:checked');
        var isPerInvoice = !selected || selected.value === 'per_invoice';
        if (perInvoiceField) perInvoiceField.style.display = isPerInvoice ? '' : 'none';
        if (fixedTotalField) fixedTotalField.style.display = isPerInvoice ? 'none' : '';
        recalcCo();
    }

    if (endDateType) endDateType.addEventListener('change', updateEndDate);
    document.querySelectorAll('[name="pricing_type"]').forEach(function (input) {
        input.addEventListener('change', updatePricing);
    });
    ['pricePerInvoiceEditCo', 'billingIntervalCountEditCo', 'billingIntervalUnitEditCo'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', recalcCo);
        if (el) el.addEventListener('change', recalcCo);
    });
    updateEndDate();
    updatePricing();
}

var addItemBtn = document.getElementById("addItemBtn");
if (addItemBtn) addItemBtn.addEventListener('click', function(e) {addItemCo();});

['discountTypeCo', 'discountValueCo', 'taxPercentCo'].forEach(id => {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', recalcCo);
});

initContractEditClientSearch();
initLongTermEditFieldsCo();
