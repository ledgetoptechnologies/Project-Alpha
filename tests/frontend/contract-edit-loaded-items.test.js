const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('whole contract editor hydrates two items then adds a unique third without losing data', () => {
  const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/contracts-edit-logic.js'), 'utf8');
  const controls = new Map();
  function element(tagName) {
    return {
      tagName, style: {}, children: [], attributes: {}, listeners: {}, value: '',
      appendChild(child) { this.children.push(child); child.parent = this; },
      setAttribute(name, value) { this.attributes[name] = String(value); },
      addEventListener(name, callback) { this.listeners[name] = callback; },
      remove() { this.parent.children.splice(this.parent.children.indexOf(this), 1); },
    };
  }
  const items = element('div');
  controls.set('itemsCo', items);
  const loaded = [
    { item: 'Mapping', description: 'First description', quantity: '2', unit_price: '3.50', billing_unit: 'hour', item_library_id: '10' },
    { item: 'Model', description: 'Second description', quantity: '4', unit_price: '8.75', billing_unit: 'day', item_library_id: '11' },
  ];
  controls.set('contract-items-data', { textContent: JSON.stringify(loaded) });
  for (const id of ['discountTypeCo', 'discountValueCo', 'taxPercentCo', 'subtotalValCo', 'discountValCo', 'taxValCo', 'totalValCo', 'addItemBtn']) {
    controls.set(id, element('input'));
  }
  function descendants(node) { return node.children.flatMap(child => [child, ...descendants(child)]); }
  const document = {
    createElement: element,
    getElementById(id) { return controls.get(id) || descendants(items).find(child => child.id === id) || null; },
    querySelector() { return null; },
    querySelectorAll(selector) {
      const name = selector.includes('item_qty[]') ? 'item_qty[]' : selector.includes('item_price[]') ? 'item_price[]' : null;
      return name ? descendants(items).filter(child => child.name === name) : [];
    },
  };
  const context = vm.createContext({ document, window: {}, console });
  vm.runInContext(source, context); // Includes real top-level hydration/counter initialization order.
  assert.equal(items.children.length, 2);
  controls.get('addItemBtn').listeners.click({});
  assert.equal(items.children.length, 3);
  const ids = descendants(items).filter(child => child.id).map(child => child.id);
  assert.equal(ids.length, 9);
  assert.equal(new Set(ids).size, 9);
  assert.ok(ids.every(id => /^(item|desc|price)Co_\d+$/.test(id)));
  items.children.forEach((row, index) => {
    const field = name => row.children.find(child => child.name === name);
    const input = field('item[]');
    assert.equal(input.attributes['data-description-field'], field('item_desc[]').id);
    assert.equal(input.attributes['data-price-field'], field('item_price[]').id);
    if (index < 2) {
      assert.equal(input.value, loaded[index].item);
      assert.equal(field('item_desc[]').value, loaded[index].description);
      assert.equal(Number(field('item_qty[]').value), Number(loaded[index].quantity));
      assert.equal(Number(field('item_price[]').value), Number(loaded[index].unit_price));
      assert.equal(field('item_billing_unit[]').value, loaded[index].billing_unit);
      assert.equal(input.attributes['data-item-library-id'], loaded[index].item_library_id);
    } else {
      assert.equal(input.value, '');
      assert.equal(field('item_qty[]').value, 1);
      assert.equal(field('item_price[]').value, 0);
    }
  });
  assert.equal(controls.get('totalValCo').textContent, '$42.00');
});
