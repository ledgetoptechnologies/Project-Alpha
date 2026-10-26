const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/recurring-service-history.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
function setup() {
  const nodes = {};
  for (const name of ['inputs','preview','confirm','record','preview-button','status','periods','total','next']) nodes[name] = {
    listeners: {}, children: [], checked: false, disabled: false, hidden: true,
    addEventListener(event, fn) { this.listeners[event] = fn; },
    replaceChildren() { this.children = []; }, appendChild(child) { this.children.push(child); }
  };
  const form = { dataset: {}, action: 'http://localhost/?page=long-term-recurring-history', listeners: {}, reportValidity: () => true,
    querySelector: selector => nodes[selector.slice(14,-1)], addEventListener(event, fn) { this.listeners[event] = fn; } };
  const calls = [], responses = [], redirects = [];
  class FakeFormData extends Map { constructor() { super(); assert.equal(nodes.inputs.disabled, false); this.set('generation_key', 'a'.repeat(32)); this.set('csrf', 'synthetic'); } }
  vm.runInNewContext(source, { document: { querySelectorAll: () => [form], createElement: () => ({}) }, FormData: FakeFormData, URL,
    window: { location: { href: 'http://localhost/?page=contract', origin: 'http://localhost', assign: value => redirects.push(value) } },
    fetch: async (url, options) => { calls.push(options); return responses.shift(); } });
  const submit = () => form.listeners.submit({ preventDefault() {} });
  const response = data => responses.push({ ok: true, json: async () => data });
  const preview = () => response({ success: true, preview_hash: 'server-hash', total: '220.00', months: 2, next_invoice_date: '2026-03-01', periods: [
    { start:'2026-01-01',end:'2026-02-01',subtotal:'100.00',discount:'0.00',tax:'10.00',total:'110.00' },
    { start:'2026-02-01',end:'2026-03-01',subtotal:'100.00',discount:'0.00',tax:'10.00',total:'110.00' }
  ] });
  return { nodes, calls, responses, redirects, submit, response, preview };
}
test('server amounts are rendered and changes invalidate confirmation', async () => {
  const x = setup(); x.preview(); x.submit(); await settle();
  assert.equal(x.calls[0].headers.Accept, 'application/json');
  assert.equal(x.nodes.periods.children.length, 2);
  assert.match(x.nodes.periods.children[0].textContent, /tax \$10.00, total \$110.00/);
  assert.match(x.nodes.total.textContent, /220.00/);
  assert.equal(x.nodes.record.disabled, true);
  x.nodes.confirm.checked = true; x.nodes.confirm.listeners.change();
  assert.equal(x.nodes.record.disabled, false);
  x.nodes.inputs.listeners.input();
  assert.equal(x.nodes.preview.hidden, true);
  assert.equal(x.nodes.confirm.checked, false);
  x.nodes.record.listeners.click(); await settle(); assert.equal(x.calls.length, 1);
});
test('record requires confirmation and ignores double clicks through completion', async () => {
  const x = setup(); x.preview(); x.submit(); x.submit(); await settle(); assert.equal(x.calls.length, 1);
  x.nodes.record.listeners.click(); await settle(); assert.equal(x.calls.length, 1);
  x.nodes.confirm.checked = true; x.nodes.confirm.listeners.change();
  x.response({ success:true, redirect:'/?page=invoices' });
  x.nodes.record.listeners.click(); x.nodes.record.listeners.click(); await settle();
  assert.equal(x.calls.length, 2);
  assert.equal(x.calls[1].body.get('preview_hash'), 'server-hash');
  assert.equal(x.calls[1].body.get('history_confirmed'), '1');
  assert.equal(x.calls[1].body.get('generation_key'), x.calls[0].body.get('generation_key'));
  assert.deepEqual(x.redirects, ['http://localhost/?page=invoices']);
  assert.equal(x.nodes.record.disabled, true);
  x.nodes.record.listeners.click(); await settle(); assert.equal(x.calls.length, 2);
});
test('errors stay plain text and failed record can retry with same key', async () => {
  const x = setup(); x.preview(); x.submit(); await settle();
  x.nodes.confirm.checked = true; x.nodes.confirm.listeners.change();
  x.responses.push({ ok:false, json:async () => ({ success:false,message:'<img src=x onerror=alert(1)>' }) });
  x.nodes.record.listeners.click(); await settle();
  assert.equal(x.nodes.status.textContent, '<img src=x onerror=alert(1)>');
  assert.equal(x.nodes.status.innerHTML, undefined);
  assert.equal(x.nodes.record.disabled, false);
  x.response({success:true,redirect:'https://external.example/'}); x.nodes.record.listeners.click(); await settle();
  assert.equal(x.redirects.length, 0);
  assert.equal(x.nodes.record.disabled, true);
});
