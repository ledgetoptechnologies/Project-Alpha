const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/invoices-create-logic.js'), 'utf8');
const itemEditor = source.slice(0, source.indexOf("['discountTypeInv',"));

test('sub-items stay with their parent and contribute their own quantity and price', () => {
    const rows = [];
    const fields = new Map([
        ['discountTypeInv', { value: 'none' }],
        ['discountValueInv', { value: '0' }],
        ['taxPercentInv', { value: '0' }],
        ...['subtotal', 'discount', 'tax', 'total'].map(name => [name + 'ValInv', {}]),
    ]);
    const container = {
        children: rows,
        insertBefore(row, next) { rows.splice(next ? rows.indexOf(next) : rows.length, 0, row); },
    };
    const document = {
        createElement() {
            return {
                dataset: {},
                style: {},
                get nextElementSibling() { return rows[rows.indexOf(this) + 1] ?? null; },
                set innerHTML(html) {
                    this.html = html;
                    const key = html.match(/name="item_key\[\]" value="([^"]+)"/)[1];
                    fields.set(key, { focus() {} });
                },
                querySelector(selector) {
                    return {
                        addEventListener: (event, callback) => { this[selector] = callback; },
                    };
                },
                remove() { rows.splice(rows.indexOf(this), 1); },
            };
        },
        getElementById(id) { return id === 'itemsInv' ? container : fields.get(id); },
        querySelectorAll(selector) {
            if (selector === '#itemsInv > div') return rows;
            const name = selector.match(/"([^"]+)"/)[1].replace('[','\\[').replace(']','\\]');
            const pattern = new RegExp('name="' + name + '"[^>]*value="([^"]+)"');
            return rows.map(row => ({ value: row.html.match(pattern)[1] }));
        },
    };
    const context = vm.createContext({ document, window: { confirm: () => true } });
    vm.runInContext(itemEditor, context);
    context.addItemInv('2D Map', '', 1, 300);
    context.addItemInv('Standard Photo package', '', 1, 150);
    context.addItemInv('Mileage', '', 32, 1);
    const parent = rows[0];
    context.addItemInv('Additional acres', '', 30, 10, null, 'each', null, parent);
    context.addItemInv('Extra export', '', 1, 25, null, 'each', null, parent);

    assert.equal(rows.length, 5);
    assert.match(rows[1].html, /Additional acres/);
    assert.match(rows[2].html, /Extra export/);
    assert.match(rows[3].html, /Standard Photo package/);
    assert.equal(rows[1].dataset.parentKey, parent.dataset.itemKey);
    assert.equal(fields.get('totalValInv').textContent, '$807.00');

    parent['[data-remove-item]']();
    assert.equal(rows.length, 2);
    assert.equal(fields.get('totalValInv').textContent, '$182.00');
});
