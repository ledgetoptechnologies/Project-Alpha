const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/invoices-create-logic.js'), 'utf8');

function extractFunction(sourceCode, name) {
  const start = sourceCode.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `Expected ${name} in production source`);
  const open = sourceCode.indexOf('{', start);
  let depth = 0;
  let quote = null;
  let escaped = false;

  for (let index = open; index < sourceCode.length; index += 1) {
    const character = sourceCode[index];
    if (quote) {
      if (escaped) escaped = false;
      else if (character === '\\') escaped = true;
      else if (character === quote) quote = null;
      continue;
    }
    if (character === '"' || character === "'" || character === '`') {
      quote = character;
      continue;
    }
    if (character === '{') depth += 1;
    if (character === '}' && --depth === 0) return sourceCode.slice(start, index + 1);
  }

  throw new Error(`Could not extract ${name}`);
}

function bootstrapSource() {
  const start = source.indexOf('initInvoiceClientDropdown.pageInitializerId');
  const end = source.indexOf('\nfunction loadProjectsForClientInv', start);
  assert.notEqual(start, -1, 'Expected invoice dropdown initializer registration');
  assert.notEqual(end, -1, 'Expected invoice project loader after initializer registration');
  return source.slice(start, end);
}

function stringDataset(initial = {}) {
  return new Proxy({ ...initial }, {
    set(target, property, value) {
      target[property] = String(value);
      return true;
    },
  });
}

function control(value = '') {
  const listeners = new Map();
  let currentValue = String(value);
  return {
    dataset: stringDataset(),
    style: {},
    get value() { return currentValue; },
    set value(next) { currentValue = String(next); },
    addEventListener(type, callback) {
      const callbacks = listeners.get(type) || [];
      callbacks.push(callback);
      listeners.set(type, callbacks);
    },
    dispatch(type, event = { type }) {
      for (const callback of listeners.get(type) || []) callback.call(this, event);
    },
    dispatchEvent(event) {
      this.lastEvent = event;
      this.dispatch(event.type, event);
      return true;
    },
    listenerCount(type) { return (listeners.get(type) || []).length; },
  };
}

function suggestionContainer() {
  const container = { style: {}, children: [], _innerHTML: '' };
  Object.defineProperty(container, 'innerHTML', {
    get() { return this._innerHTML; },
    set(value) {
      this._innerHTML = String(value);
      this.children = Array.from(this._innerHTML.matchAll(/data-client-index="(\d+)"/g), match => {
        const item = control();
        item.dataset.clientIndex = match[1];
        return item;
      });
    },
  });
  return container;
}

function htmlTextElement() {
  let textContent = '';
  return {
    set textContent(value) { textContent = String(value); },
    get textContent() { return textContent; },
    get innerHTML() {
      return textContent
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    },
  };
}

function createHarness(mode) {
  const search = control('Alice');
  const clientId = control('existing');
  const suggestions = suggestionContainer();
  const taxBanner = { style: { display: 'none' } };
  const elements = {
    clientInputInv: search,
    clientIdInv: clientId,
    clientSuggestInv: suggestions,
    taxExemptBannerInv: taxBanner,
  };
  const clients = [{
    id: 17,
    name: 'Alice <script>alert(1)</script>',
    organization_id: 9,
    org_name: 'Acme & Sons',
    tax_exempt_file: 'certificate.pdf',
  }];
  let registeredPage = null;
  let registeredInitializer = null;
  let requestedUrl = null;
  let loadedProjectClient = null;
  const window = mode === 'soft'
    ? {
        ProjectAlpha: {
          registerPage(page, initializer) {
            registeredPage = page;
            registeredInitializer = initializer;
          },
        },
      }
    : {};
  const document = {
    getElementById(id) { return elements[id] || null; },
    createElement() { return htmlTextElement(); },
  };
  class TestEvent {
    constructor(type, options = {}) {
      this.type = type;
      this.bubbles = Boolean(options.bubbles);
    }
  }
  const context = vm.createContext({
    Array,
    Event: TestEvent,
    Number,
    String,
    document,
    encodeURIComponent,
    fetch(url) {
      requestedUrl = url;
      return Promise.resolve({ json: () => Promise.resolve(clients) });
    },
    loadProjectsForClientInv(client) { loadedProjectClient = client; },
    window,
  });

  vm.runInContext(`${extractFunction(source, 'initInvoiceClientDropdown')}\n${bootstrapSource()}`, context);
  if (mode === 'soft') {
    assert.equal(registeredPage, 'document/new');
    assert.equal(typeof registeredInitializer, 'function');
    registeredInitializer();
    registeredInitializer();
  }

  return {
    clientId,
    get loadedProjectClient() { return loadedProjectClient; },
    get requestedUrl() { return requestedUrl; },
    search,
    suggestions,
    taxBanner,
  };
}

for (const mode of ['direct', 'soft']) {
  test(`invoice client search renders and selects results after ${mode} initialization`, async () => {
    const harness = createHarness(mode);
    assert.equal(harness.search.listenerCount('input'), 1);

    harness.search.dispatch('input');
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(harness.requestedUrl, '/?page=clients-search&term=Alice');
    assert.equal(harness.suggestions.style.display, 'block');
    assert.match(harness.suggestions.innerHTML, /Alice &lt;script&gt;alert\(1\)&lt;\/script&gt;/);
    assert.match(harness.suggestions.innerHTML, /Acme &amp; Sons/);
    assert.doesNotMatch(harness.suggestions.innerHTML, /<script>/);
    assert.equal(harness.suggestions.children.length, 1);

    let propagationStopped = false;
    harness.suggestions.children[0].dispatch('click', {
      stopPropagation() { propagationStopped = true; },
    });

    assert.equal(propagationStopped, true);
    assert.equal(harness.search.value, 'Alice <script>alert(1)</script>');
    assert.equal(harness.clientId.value, '17');
    assert.equal(harness.clientId.dataset.organizationId, '9');
    assert.equal(harness.clientId.dataset.organizationName, 'Acme & Sons');
    assert.equal(harness.clientId.lastEvent.type, 'change');
    assert.equal(harness.clientId.lastEvent.bubbles, true);
    assert.equal(harness.loadedProjectClient, 17);
    assert.equal(harness.taxBanner.style.display, 'block');
    assert.equal(harness.suggestions.style.display, 'none');
  });
}
