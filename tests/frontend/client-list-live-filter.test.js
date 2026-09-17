const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/client-list-live-filter.js'), 'utf8');
const clientList = fs.readFileSync(path.join(root, 'src/views/pages/client/clients-list.php'), 'utf8');
const organizationList = fs.readFileSync(path.join(root, 'src/views/pages/organization/organizations-list.php'), 'utf8');
const filterTemplate = fs.readFileSync(path.join(root, 'src/views/templates/components/document-filter.html.twig'), 'utf8');

function loadLiveFilter() {
  const context = vm.createContext({
    window: { ProjectAlpha: {}, location: { origin: 'https://pa.example.test' } },
    document: { querySelectorAll() { return []; } },
    URL,
    URLSearchParams,
  });
  context.window.window = context.window;
  context.window.document = context.document;
  vm.runInContext(script, context);
  return context.window.ProjectAlpha.clientListLiveFilter;
}

test('client name and organization fields opt into an accessible debounce', () => {
  assert.match(clientList, /'live_filter_fields'\s*=>\s*\['q',\s*'org'\]/);
  assert.match(clientList, /'live_filter_debounce'\s*=>\s*300/);
  assert.match(clientList, /client-list-live-filter\.js/);
  assert.match(filterTemplate, /data-live-filter-fields=/);
  assert.match(filterTemplate, /role="status"/);
  assert.match(filterTemplate, /aria-live="polite"/);
});

test('organization name filtering uses the same partial live-filter contract', () => {
  assert.match(organizationList, /o\.name LIKE \?/);
  assert.match(organizationList, /'%'\.\$search\.'%'/);
  assert.match(organizationList, /'live_filter_id'\s*=>\s*'organization-list'/);
  assert.match(organizationList, /'live_filter_fields'\s*=>\s*\['search'\]/);
  assert.match(organizationList, /'live_filter_debounce'\s*=>\s*300/);
  assert.match(organizationList, /client-list-live-filter\.js/);
});

test('active client lifecycle filtering is qualified against the joined organization table', () => {
  assert.match(clientList, /\$activeFilter\s*=\s*\$hasArchived\s*\?\s*'c\.archived=0'\s*:\s*'1=1'/);
  assert.match(clientList, /FROM clients c[\s\S]*LEFT JOIN organizations o ON c\.organization_id = o\.id[\s\S]*\$whereClause/);
});

test('live filtering serializes partial name and organization values together', () => {
  class FormDataMock {
    constructor() {
      this.values = new Map([
        ['page', 'client/clients-list'],
        ['q', 'Kev'],
        ['org', 'Comp'],
        ['unused', ''],
      ]);
    }
    get(name) { return this.values.get(name); }
    delete(name) { this.values.delete(name); }
    forEach(callback) { this.values.forEach((value, name) => callback(value, name)); }
  }
  const context = vm.createContext({
    window: { ProjectAlpha: {}, location: { origin: 'https://pa.example.test' } },
    document: { querySelectorAll() { return []; } },
    FormData: FormDataMock,
    URL,
    URLSearchParams,
  });
  vm.runInContext(script, context);
  const liveFilter = context.window.ProjectAlpha.clientListLiveFilter;
  assert.equal(liveFilter.buildPageString({}), 'client/clients-list&q=Kev&org=Comp');
});

test('fragment re-execution reuses one idempotent live-filter engine', () => {
  const liveFilter = loadLiveFilter();
  assert.equal(typeof liveFilter.initialize, 'function');
  assert.match(script, /projectAlpha\.clientListLiveFilter \|\| createClientListLiveFilter\(\)/);
  assert.match(script, /form\.dataset\.liveFilterBound === 'true'/);
  assert.match(script, /registerPage\(\[[\s\S]*'client\/clients-list',[\s\S]*'organization\/organizations-list'[\s\S]*\]/);
  assert.match(script, /root\.querySelectorAll\('form\[data-live-filter-fields\]'\)/);
  assert.match(script, /window\.navigateToPage\(state\.page, false\)/);
});

test('manual Filter submission remains as a no-JavaScript fallback', () => {
  assert.match(filterTemplate, /<form[\s\S]*method="get"[\s\S]*action="\/"/);
  assert.match(filterTemplate, /<button[\s\S]*type="submit"[\s\S]*>\s*Filter\s*<\/button>/);
  assert.match(script, /fallbackForm\.requestSubmit\(\)/);
});
