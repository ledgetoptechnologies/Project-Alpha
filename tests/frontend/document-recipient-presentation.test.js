const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/document-recipient-presentation.js'), 'utf8');

function control(properties = {}) {
  const listeners = {};
  return {
    dataset: {},
    addEventListener(type, callback) { (listeners[type] ||= []).push(callback); },
    dispatch(type) { for (const callback of listeners[type] || []) callback.call(this); },
    listenerCount(type) { return (listeners[type] || []).length; },
    ...properties,
  };
}

test('organization contact option is idempotent and defaults off after client changes', () => {
  const clientId = control({
    tagName: 'INPUT',
    value: '7',
    dataset: { organizationId: '3', organizationName: 'Company ABC' },
  });
  const search = control({ value: 'Kevin Smith' });
  const option = { hidden: true };
  const checkbox = { checked: true, disabled: false };
  const organizationName = { textContent: '' };
  const picker = {
    dataset: {},
    querySelector(selector) {
      return {
        '[data-document-client-id]': clientId,
        '[data-document-client-search]': search,
        '[data-document-contact-option]': option,
        '[data-document-contact-checkbox]': checkbox,
        '[data-document-organization-name]': organizationName,
      }[selector] || null;
    },
  };

  let initializer;
  const context = vm.createContext({
    document: { querySelectorAll: () => [picker], readyState: 'complete' },
    window: { ProjectAlpha: { registerPage(pages, callback) { initializer = callback; } } },
  });
  vm.runInContext(script, context);
  initializer();
  initializer();

  assert.equal(clientId.listenerCount('change'), 1);
  assert.equal(search.listenerCount('input'), 1);
  assert.equal(option.hidden, false);
  assert.equal(checkbox.checked, true, 'saved edit state is retained during initialization');
  assert.equal(organizationName.textContent, 'Company ABC');

  checkbox.checked = true;
  clientId.dataset.organizationId = '4';
  clientId.dataset.organizationName = 'Company XYZ';
  clientId.dispatch('change');
  assert.equal(option.hidden, false);
  assert.equal(checkbox.checked, false, 'a newly selected organization defaults contact presentation off');

  clientId.dataset.organizationId = '';
  clientId.dataset.organizationName = '';
  clientId.dispatch('change');
  assert.equal(option.hidden, true);
  assert.equal(checkbox.disabled, true);
});

test('all document create and edit forms submit the shared presentation field', () => {
  const views = [
    'src/views/pages/document/new.php',
    'src/views/pages/invoice/invoices-edit.php',
    'src/views/pages/quote/quotes-edit.php',
    'src/views/pages/contract/contracts-edit.php',
  ];
  for (const view of views) {
    const source = fs.readFileSync(path.join(root, view), 'utf8');
    assert.match(source, /name="show_contact_on_document"/u, view);
    assert.match(source, /document-recipient-presentation\.js/u, view);
  }
});
