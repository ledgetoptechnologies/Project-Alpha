const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/document-billing-choice.js'), 'utf8');
function input(value = '') {
  return { value, dataset: {}, listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; }, setCustomValidity(message) { this.validationMessage = message; } };
}

test('monthly rate preview switches annual/monthly exactly and rejects unsupported frequency', () => {
  const basis = input('monthly');
  const monthly = input('19.99');
  const amount = input('1.00');
  const count = input('1');
  const unit = input('year');
  const field = {};
  const preview = {};
  const form = input();
  form.querySelector = selector => selector.includes('doc_type') ? { value: 'long_term' } : { value: 'per_invoice' };
  const nodes = { '[data-rate-basis]': basis, '[data-monthly-rate-input]': monthly, '[data-monthly-rate-field]': field, '[data-monthly-rate-preview]': preview };
  const root = { dataset: { amountId: 'amount', countId: 'count', unitId: 'unit' }, closest: () => form, querySelector: selector => nodes[selector] };
  const controls = { amount, count, unit };
  const document = { querySelectorAll: selector => selector === '[data-monthly-rate]' ? [root] : [], getElementById: id => controls[id] };
  vm.runInNewContext(source, { document });
  assert.equal(amount.value, '239.88');
  assert.equal(amount.readOnly, true);
  assert.equal(monthly.required, true);
  assert.match(preview.textContent, /12 months/);
  unit.value = 'month'; unit.listeners.input();
  assert.equal(amount.value, '19.99');
  count.value = '2'; count.listeners.input();
  assert.equal(amount.value, '');
  assert.match(monthly.validationMessage, /1 month or 1 year/);
  basis.value = 'per_period'; basis.listeners.input();
  amount.value = '300.00'; unit.value = 'year'; unit.listeners.input();
  assert.equal(amount.value, '300.00');
  assert.equal(amount.readOnly, false);
  assert.equal(monthly.disabled, true);
  assert.equal(monthly.validationMessage, '');
});
