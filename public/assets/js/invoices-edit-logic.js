// Extra charges management
var extraChargeCounter = 0;
function money(n) { return '$' + (Number(n) || 0).toFixed(2) }
function addExtraCharge(parentItemId = null) {
  try {
    var container = document.getElementById('extraChargesContainer');
    var anchorBtn = document.getElementById('addExtraChargeBtn');

    // If container doesn't exist, create it and insert it right before the button
    if (!container) {
      container = document.createElement('div');
      container.id = 'extraChargesContainer';
      container.style.display = 'grid';
      container.style.gap = '8px';
      // Insert before the button in the DOM
      if (anchorBtn && anchorBtn.parentNode) {
        anchorBtn.parentNode.insertBefore(container, anchorBtn);
      } else {
        console.error('Could not find button or its parent');
        return;
      }
    }

    // Create a new row for the extra charge with item + description
    var itemId = 'extraItem_' + (extraChargeCounter++);
    var descId = 'extraDesc_' + extraChargeCounter;
    var priceId = 'extraPrice_' + extraChargeCounter;

    var wrap = document.createElement('div');
    wrap.style.display = 'grid';
    wrap.style.gridTemplateColumns = '1.2fr 2.5fr 2.5fr 1fr 1fr auto';
    wrap.style.gap = '8px';
    wrap.style.padding = '8px';
    wrap.style.background = '#fff';
    wrap.style.borderRadius = '4px';
    wrap.style.border = '1px solid #fcd34d';
    wrap.innerHTML = `
    <input type="hidden" name="extra_billing_unit[]" value="each">
    <select name="extra_adjustment_type[]" style="padding:8px;border-radius:4px;border:1px solid #ddd" onchange="recalcInv()"><option value="charge">Charge</option><option value="credit">Credit</option></select>
    <input id="${itemId}" type="text" name="extra_item[]" placeholder="Item name..." style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    <textarea id="${descId}" name="extra_desc[]" placeholder="Description (optional)" style="padding:8px;border-radius:4px;border:1px solid #ddd;resize:vertical;min-height:34px" oninput="recalcInv()"></textarea>
    <input type="number" step="0.01" min="0" name="extra_qty[]" value="1" class="qty-input" style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()">
    <input id="${priceId}" type="number" step="0.01" min="0" name="extra_price[]" value="0" style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()">
    <input type="hidden" name="extra_id[]" value="">
    <button type="button" onclick="this.parentElement.remove();recalcInv()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:4px;padding:8px 10px;cursor:pointer">Remove</button>
  `;
    const parentSelect = document.createElement('select');
    parentSelect.name = 'extra_parent_item_id[]';
    parentSelect.setAttribute('aria-label', 'Show as sub-item of');
    parentSelect.style.cssText = 'grid-column:1/-1;justify-self:start;max-width:100%';
    parentSelect.appendChild(document.getElementById('invoiceAdjustmentParentOptions').content.cloneNode(true));
    parentSelect.value = parentItemId ? String(parentItemId) : '';
    wrap.prepend(parentSelect);
    container.appendChild(wrap);
    if (parentItemId) document.getElementById(itemId).focus();

    // Initialize autocomplete for the new item input
    if (window.ItemAutocomplete) {
      const input = document.getElementById(itemId);
      const descField = document.getElementById(descId);
      const priceField = document.getElementById(priceId);
      if (input && descField && priceField) {
        new ItemAutocomplete(input, {
          descriptionField: descField,
          priceField: priceField
        });
      }
    }

    recalcInv();
  } catch (e) {
    console.error('Error in addExtraCharge():', e.message, e.stack);
  }
}

function recalcInv() {
  var totalsEl = document.getElementById('totalsInv');
  var baseSubtotal = totalsEl ? (parseFloat(totalsEl.dataset.baseSubtotal) || 0) : 0;
  // Recalculate totals from extra charges if visible
  var extraQtys = Array.from(document.querySelectorAll('[name="extra_qty[]"]')).map(e => parseFloat(e.value) || 0);
  var extraPrices = Array.from(document.querySelectorAll('[name="extra_price[]"]')).map(e => parseFloat(e.value) || 0);
  var extraTypes = Array.from(document.querySelectorAll('[name="extra_adjustment_type[]"]')).map(e => e.value);
  var extraSubtotal = 0;

  for (var i = 0; i < extraQtys.length; i++) {
    extraSubtotal += extraQtys[i] * extraPrices[i] * (extraTypes[i] === 'credit' ? -1 : 1);
  }

  //Update totals
  var dtype = document.getElementById('discountTypeInv').value;
  var dval = parseFloat(document.getElementById('discountValueInv').value) || 0;
  var taxp = parseFloat(document.getElementById('taxPercentInv').value) || 0;

  var subtotal = baseSubtotal + extraSubtotal;
  var discount = 0;
  if (dtype === 'percent') {
    discount = Math.max(0, Math.min(100, dval)) * subtotal / 100;
  } else if (dtype === 'fixed') {
    discount = Math.max(0, dval);
  }

  var taxable = Math.max(0, subtotal - discount);
  var tax = Math.max(0, taxp) * taxable / 100;
  var total = Math.max(0, taxable + tax);

  document.getElementById('subtotalValInv').textContent = money(subtotal);
  document.getElementById('discountValInv').textContent = money(discount);
  document.getElementById('taxValInv').textContent = money(tax);
  document.getElementById('totalValInv').textContent = money(total);
}


// Initialize invoice edit page event listeners
function initInvoiceEdit() {
  // Client typeahead for invoice edit
  var ciI = document.getElementById('clientInputInv');
  if (!ciI) return; // Not on invoice edit page
  if (ciI.dataset.invoiceEditReady === '1') return;
  ciI.dataset.invoiceEditReady = '1';

  var cidI = document.getElementById('clientIdInv');
  var sugI = document.getElementById('clientSuggestInv');

  ciI.addEventListener('input', function () {
    cidI.value = '';
    var t = this.value.trim();
    if (!t) { sugI.style.display = 'none'; sugI.innerHTML = ''; return; }
    fetch('/?page=clients-search&term=' + encodeURIComponent(t))
      .then(r => r.json())
      .then(list => {
        if (!Array.isArray(list) || list.length === 0) { sugI.style.display = 'none'; sugI.innerHTML = ''; return; }
        sugI.innerHTML = list.map((x, index) => `<div data-client-index="${index}" style="padding:8px 10px;cursor:pointer"><strong>${escapeInvoiceEditRecipient(x.name)}</strong>${x.org_name ? `<small style="display:block;color:#6b7280">${escapeInvoiceEditRecipient(x.org_name)}</small>` : ''}</div>`).join('');
        Array.from(sugI.children).forEach(el => {
          el.addEventListener('click', function () {
            var client = list[Number(this.dataset.clientIndex)];
            if (!client) return;
            ciI.value = client.name; cidI.value = client.id;
            cidI.dataset.organizationId = client.organization_id || '';
            cidI.dataset.organizationName = client.org_name || '';
            cidI.dispatchEvent(new Event('change', { bubbles: true }));
            sugI.style.display = 'none';
          });
        });
        sugI.style.display = 'block';
      }).catch(() => { sugI.style.display = 'none' });
  });
  document.addEventListener('click', function (e) { if (!sugI.contains(e.target) && e.target !== ciI) { sugI.style.display = 'none'; } });

  var form = document.getElementById('invEditForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!cidI.value) {
        e.preventDefault();
        alert('Please select a client from suggestions.');
      }
    });
  }

  // Listen for discount/tax changes
  ['discountTypeInv', 'discountValueInv', 'taxPercentInv'].forEach(id => {
    var elem = document.getElementById(id);
    if (elem) elem.addEventListener('input', recalcInv);
  });
}

function escapeInvoiceEditRecipient(value) {
  var element = document.createElement('div');
  element.textContent = value == null ? '' : String(value);
  return element.innerHTML;
}
initInvoiceEdit.pageInitializerId = 'invoice-edit';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
  window.ProjectAlpha.registerPage('invoice/invoices-edit', initInvoiceEdit);
} else if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initInvoiceEdit, { once: true });
} else {
  initInvoiceEdit();
}

var items = document.querySelectorAll("#item");
var descriptions = document.querySelectorAll("#description");
var quantities = document.querySelectorAll('[name="extra_qty[]"]');
var prices = document.querySelectorAll("#price");

var confirmButton = document.getElementById("confirm");
if (confirmButton) {
  confirmButton.addEventListener("click", function () {
    if (confirm('Remove this extra charge?')) {
      this.parentElement.remove();
      recalcInv()
    }
  });
}

items.forEach(item => {
  item.addEventListener('input', recalcInv);
});

descriptions.forEach(description => {
  description.addEventListener('input', recalcInv);
});

quantities.forEach(quantity => {
  quantity.addEventListener('input', recalcInv);
});

prices.forEach(price => {
  price.addEventListener('input', recalcInv);
});
