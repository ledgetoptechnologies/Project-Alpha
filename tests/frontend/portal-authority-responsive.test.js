const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');

test('custom integrations exposes one generic connection and hides technical portal controls', () => {
  const page = fs.readFileSync(path.join(root, 'src/views/pages/settings/external-ops.php'), 'utf8');
  const registry = fs.readFileSync(path.join(root, 'src/views/pages/settings/registry.php'), 'utf8');
  const clientAlias = fs.readFileSync(path.join(root, 'src/views/pages/settings/client-portal-access.php'), 'utf8');
  const advancedAlias = fs.readFileSync(path.join(root, 'src/views/pages/settings/integration-advanced.php'), 'utf8');
  assert.match(page, /External application connection/);
  assert.match(page, /Connected workspace synchronization/);
  assert.match(page, /Custom-integration access/);
  assert.match(page, /Synchronization status/);
  assert.match(page, /data-external-ops-grant-form/);
  assert.doesNotMatch(page, /Portal projection runtime|Advanced integration profiles|Workspaces and portal principals|Scoped client access|Profile workspace allowlist|Manager appointment|Projection recovery|viewer\.share\.create/i);
  assert.doesNotMatch(page, /Client portal provisioning|Operations routes/i);
  assert.doesNotMatch(registry, /'tab'\s*=>\s*'(?:client-portal-access|integration-advanced)'/);
  assert.match(clientAlias, /require __DIR__ \. '\/external-ops\.php'/);
  assert.match(advancedAlias, /require __DIR__ \. '\/external-ops\.php'/);
  assert.doesNotMatch(clientAlias + advancedAlias, /integrationSurface/);
  assert.match(page, /role="alert"/);
});

test('open-source integration settings keep deployment branding out of runtime UI', () => {
  const settingsDir = path.join(root, 'src/views/pages/settings');
  const runtime = ['external-ops.php','client-portal-access.php','integration-advanced.php','links.php','registry.php']
    .map(file => fs.readFileSync(path.join(settingsDir, file), 'utf8')).join('\n');
  const deliveryRuntime = [
    fs.readFileSync(path.join(root, 'public/assets/js/settings-links.js'), 'utf8'),
    fs.readFileSync(path.join(root, 'src/controllers/settings/managed_delivery_test.php'), 'utf8'),
  ].join('\n');
  const runtimeCopy = (runtime + '\n' + deliveryRuntime).replace(/external-ops/gi, '');
  assert.doesNotMatch(runtimeCopy, /LTDS|Ledge Top Drone Services|Portal v2|model-viewer|\bOps\b/i);
  assert.match(runtime, /Managed Delivery Service/);
  assert.match(runtime, /External application connection/);
  assert.match(runtime, /Custom-integration access/);
  assert.doesNotMatch(runtime, /Portal projection runtime|Advanced integration profiles|Profile workspace allowlist|Public delivery-link authority/i);
});

test('user-visible PHP views do not hard-code deployment-specific branding', () => {
  const viewsRoot = path.join(root, 'src/views');
  const phpViews = [];
  const visit = directory => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const target = path.join(directory, entry.name);
      if (entry.isDirectory()) visit(target);
      else if (entry.isFile() && entry.name.endsWith('.php')) phpViews.push(target);
    }
  };
  visit(viewsRoot);
  const runtimeViews = phpViews.map(file => fs.readFileSync(file, 'utf8')).join('\n');
  assert.doesNotMatch(runtimeViews, /LTDS|Ledge Top Drone Services|ledgetopdroneservices\.com/i);

  const logout = fs.readFileSync(path.join(viewsRoot, 'pages/auth/logout.php'), 'utf8');
  assert.match(logout, /\$appConfig\['brand_name'\]/);
  assert.match(logout, /Project Alpha/);
});

test('portal catalog UI exposes only explicit client-safe publication fields', () => {
  const page = fs.readFileSync(path.join(root, 'src/views/pages/settings/item-library.php'), 'utf8');
  const script = fs.readFileSync(path.join(root, 'public/assets/js/item-library.js'), 'utf8');
  for (const field of ['portal_requestable','portal_category','portal_display_order','portal_geometry_requirement','portal_summary','portal_questions_json']) {
    assert.match(page, new RegExp(`name="${field}"`));
  }
  assert.match(page, /Internal pricing policy, fulfillment notes, compensation, raw files, and processing details are never included/);
  assert.match(script, /portal_category/);
});
