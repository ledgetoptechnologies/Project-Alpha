const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');

function extractFunction(source, name) {
  const start = source.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `Expected ${name} in production source`);

  const open = source.indexOf('{', start);
  let depth = 0;
  let quote = null;
  let escaped = false;
  let lineComment = false;
  let blockComment = false;

  for (let i = open; i < source.length; i += 1) {
    const char = source[i];
    const next = source[i + 1];
    if (lineComment) {
      if (char === '\n') lineComment = false;
      continue;
    }
    if (blockComment) {
      if (char === '*' && next === '/') {
        blockComment = false;
        i += 1;
      }
      continue;
    }
    if (quote) {
      if (escaped) escaped = false;
      else if (char === '\\') escaped = true;
      else if (char === quote) quote = null;
      continue;
    }
    if (char === '/' && next === '/') {
      lineComment = true;
      i += 1;
      continue;
    }
    if (char === '/' && next === '*') {
      blockComment = true;
      i += 1;
      continue;
    }
    if (char === '"' || char === "'" || char === '`') {
      quote = char;
      continue;
    }
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(start, i + 1);
    }
  }

  throw new Error(`Could not extract ${name}`);
}

class TestEvent {
  constructor(type, options = {}) {
    this.type = type;
    this.bubbles = Boolean(options.bubbles);
  }
}

function loadAutocomplete() {
  const document = {
    readyState: 'complete',
    addEventListener() {},
    querySelectorAll() { return []; },
    createElement() {
      return {
        style: {},
        appendChild() {},
        addEventListener() {},
        querySelectorAll() { return []; },
        set textContent(value) { this._textContent = value; },
        get innerHTML() { return this._textContent || ''; },
      };
    },
  };
  const window = { document };
  window.window = window;
  const context = vm.createContext({ console, document, Event: TestEvent, setTimeout, clearTimeout, window });
  vm.runInContext(fs.readFileSync(path.join(root, 'public/assets/item-autocomplete.js'), 'utf8'), context);
  return window.ItemAutocomplete;
}

function calculatorHarness(script, recalcName, ids) {
  const quantityFields = [{ value: '1' }, { value: '1' }];
  const priceFields = [{ value: '0', listeners: [] }, { value: '0', listeners: [] }];
  const elements = new Map();
  const element = (id, value = '') => {
    if (!elements.has(id)) elements.set(id, { value, textContent: '', style: {}, parentElement: { style: {} } });
    return elements.get(id);
  };

  element(ids.discountType, 'none');
  element(ids.discountValue, '0');
  element(ids.taxPercent, '5.5');
  element(ids.subtotal);
  element(ids.discount);
  element(ids.tax);
  element(ids.total);
  element(ids.invoiceAmountRow);

  const document = {
    querySelector(selector) {
      if (selector === 'input[name="doc_type"]:checked') return { value: 'regular' };
      return null;
    },
    querySelectorAll(selector) {
      if (selector === '[name="item_qty[]"]') return quantityFields;
      if (selector === '[name="item_price[]"]') return priceFields;
      return [];
    },
    getElementById(id) { return elements.get(id) || null; },
  };

  const source = fs.readFileSync(path.join(root, script), 'utf8');
  const context = vm.createContext({
    Array,
    Math,
    Number,
    document,
    parseFloat,
    parseInt,
    updateDiscountWarning() {},
  });
  vm.runInContext(`${extractFunction(source, 'money')}\n${extractFunction(source, recalcName)}`, context);

  for (const priceField of priceFields) {
    priceField.addEventListener = (type, listener) => {
      if (type === 'input') priceField.listeners.push(listener);
    };
    priceField.dispatchEvent = (event) => {
      priceField.lastEvent = event;
      for (const listener of priceField.listeners) listener(event);
      return true;
    };
    priceField.addEventListener('input', context[recalcName]);
  }

  return { element, priceFields };
}

function autocompleteFor(ItemAutocomplete, priceField, libraryIdField) {
  const quantity = { placeholder: 'Qty' };
  const billingUnit = { value: 'each' };
  const row = {
    querySelector(selector) {
      if (selector === '.qty-input') return quantity;
      if (selector === '[name="item_billing_unit[]"]') return billingUnit;
      return null;
    },
  };
  const input = {
    value: '',
    nextElementSibling: null,
    closest() { return row; },
    parentElement: row,
  };
  const autocomplete = Object.create(ItemAutocomplete.prototype);
  Object.assign(autocomplete, {
    input,
    descriptionField: { value: '' },
    priceField,
    libraryIdField,
    onSelect: null,
    dropdown: { style: {} },
    selectedIndex: -1,
  });
  return autocomplete;
}

const cases = [
  {
    name: 'Invoice',
    script: 'public/assets/js/invoices-create-logic.js',
    recalc: 'recalcInv',
    ids: {
      discountType: 'discountTypeInv', discountValue: 'discountValueInv', taxPercent: 'taxPercentInv',
      subtotal: 'subtotalValInv', discount: 'discountValInv', tax: 'taxValInv', total: 'totalValInv',
      invoiceAmountRow: 'invoiceAmountRowInv',
    },
  },
  {
    name: 'Quote and contract',
    script: 'public/assets/js/document-new.js',
    recalc: 'recalc',
    ids: {
      discountType: 'discountType', discountValue: 'discountValue', taxPercent: 'taxPercent',
      subtotal: 'subtotalVal', discount: 'discountVal', tax: 'taxVal', total: 'totalVal',
      invoiceAmountRow: 'invoiceAmountRow',
    },
  },
];

for (const scenario of cases) {
  test(`${scenario.name} item-library selections refresh visible totals and preserve IDs`, () => {
    const ItemAutocomplete = loadAutocomplete();
    const harness = calculatorHarness(scenario.script, scenario.recalc, scenario.ids);
    const libraryIds = [{ value: '' }, { value: '' }];
    const first = autocompleteFor(ItemAutocomplete, harness.priceFields[0], libraryIds[0]);
    const second = autocompleteFor(ItemAutocomplete, harness.priceFields[1], libraryIds[1]);

    first.selectItem({ id: 101, item_name: 'Premium Promotional Video', unit_price: '350', billing_unit: 'each' });
    assert.equal(harness.element(scenario.ids.subtotal).textContent, '$350.00');
    assert.equal(harness.element(scenario.ids.tax).textContent, '$19.25');
    assert.equal(harness.element(scenario.ids.total).textContent, '$369.25');

    second.selectItem({ id: 202, item_name: 'Basic Photo Shoot', unit_price: '150', billing_unit: 'each' });
    assert.equal(harness.element(scenario.ids.subtotal).textContent, '$500.00');
    assert.equal(harness.element(scenario.ids.tax).textContent, '$27.50');
    assert.equal(harness.element(scenario.ids.total).textContent, '$527.50');
    assert.deepEqual(libraryIds.map((field) => field.value), ['101', '202']);
    assert.equal(harness.priceFields[1].lastEvent.type, 'input');
    assert.equal(harness.priceFields[1].lastEvent.bubbles, true);
  });
}

test('invoice catalog units are applied to the selected sub-item instead of the form', () => {
  const ItemAutocomplete = loadAutocomplete();
  const firstUnit = { value: 'each' };
  const selectedUnit = { value: 'each' };
  const selectedQuantity = { placeholder: '' };
  const selection = autocompleteFor(ItemAutocomplete, null, { value: '' });
  selection.input.closest = selector => {
    if (selector === '.invoice-item-editor__row') {
      return { querySelector: field => field === '.qty-input' ? selectedQuantity : selectedUnit };
    }
    if (selector === '[style*="grid"]') {
      return { querySelector: () => firstUnit };
    }
    return null;
  };

  selection.selectItem({ id: 42, item_name: 'Additional survey time', billing_unit: 'hour' });
  assert.equal(selectedUnit.value, 'hour');
  assert.equal(selectedQuantity.placeholder, 'Hours');
  assert.equal(firstUnit.value, 'each');

  selection.selectItem({ id: 43, item_name: 'Travel', billing_unit: 'mile' });
  assert.equal(selectedUnit.value, 'mile');
  assert.equal(firstUnit.value, 'each');
});
