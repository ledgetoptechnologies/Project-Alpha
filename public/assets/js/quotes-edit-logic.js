var itemData = getItemData();
var itemCounter = 0;

itemData.forEach(item => {
    addItem(item.item ?? '', item.description ?? '', Number(item.quantity), Number(item.unit_price), item.billing_unit || 'each', item.item_library_id || '');
});

function money(n) {
    return '$' + (Number(n) || 0).toFixed(2)
}

function getItemData() {
    const element = document.getElementById("quote-items-data");
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

function addItem(item = '', desc = '', qty = 1, price = 0, billingUnit = 'each', itemLibraryId = '') {
    var wrap = document.createElement('div');
    var itemId = 'item_' + (itemCounter++);
    var descId = 'desc_' + itemCounter;
    var priceId = 'price_' + itemCounter;
    wrap.style.display = 'grid';
    wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr auto';
    wrap.style.gap = '8px';

    var itemInput = document.createElement('input');
    itemInput.id = itemId;
    itemInput.required = true;
    itemInput.placeholder = 'Item name...';
    itemInput.name = 'item[]';
    itemInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
    itemInput.value = item ;
    itemInput.oninput = recalc;
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
    descTextarea.oninput = recalc;

    var qtyInput = document.createElement('input');
    qtyInput.required = true;
    qtyInput.type = 'number';
    qtyInput.step = '0.01';
    qtyInput.min = '0';
    qtyInput.name = 'item_qty[]';
    qtyInput.className = 'qty-input';
    qtyInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
    qtyInput.value = qty;
    qtyInput.oninput = recalc;

    var priceInput = document.createElement('input');
    priceInput.id = priceId;
    priceInput.required = true;
    priceInput.type = 'number';
    priceInput.step = '0.01';
    priceInput.min = '0';
    priceInput.name = 'item_price[]';
    priceInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
    priceInput.value = price;
    priceInput.oninput = recalc;

    var unitInput = document.createElement('input');
    unitInput.type = 'hidden';
    unitInput.name = 'item_billing_unit[]';
    unitInput.value = ['each','hour','day','mile','project'].includes(billingUnit) ? billingUnit : 'each';

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.textContent = 'Remove';
    removeBtn.style.cssText = 'border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px';
    removeBtn.onclick = function () {
        wrap.remove();
        recalc();
    };

    wrap.appendChild(unitInput);
    wrap.appendChild(itemInput);
    wrap.appendChild(descTextarea);
    wrap.appendChild(qtyInput);
    wrap.appendChild(priceInput);
    wrap.appendChild(removeBtn);
    document.getElementById('items').appendChild(wrap);

    // Initialize autocomplete for the new item input
    if (window.ItemAutocomplete) {
        new ItemAutocomplete(itemInput, {
            descriptionField: descTextarea,
            priceField: priceInput
        });
    }

    recalc();
}

function recalc() {
    var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
    var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
    var subtotal = 0;
    for (var i = 0; i < qtys.length; i++) {
        subtotal += qtys[i] * prices[i];
    }
    const recurringAmount = document.getElementById('pricePerInvoiceEditQ');
    if (recurringAmount) subtotal = Number(recurringAmount.value) || 0;
    var dtype = document.getElementById('discountType').value;
    var dval = parseFloat(document.getElementById('discountValue').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercent').value) || 0;
    var discount = 0;
    if (dtype === 'percent') {
        discount = Math.max(0, Math.min(100, dval)) * subtotal / 100;
    } else if (dtype === 'fixed') {
        discount = Math.max(0, dval);
    }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);
    document.getElementById('subtotalVal').textContent = money(subtotal);
    document.getElementById('discountVal').textContent = money(discount);
    document.getElementById('taxVal').textContent = money(tax);
    document.getElementById('totalVal').textContent = money(total);
}

['discountType', 'discountValue', 'taxPercent'].forEach(id => document.getElementById(id).addEventListener('input', recalc));

document.getElementById("addItemBtn")?.addEventListener("click", (e) => addItem('','',1,0));

document.getElementById('pricePerInvoiceEditQ')?.addEventListener('input', recalc);

if (document.getElementById('pricePerInvoiceEditQ')) {
  document.querySelectorAll('#items input, #items select, #items textarea').forEach(input => { input.disabled = true; });
}
