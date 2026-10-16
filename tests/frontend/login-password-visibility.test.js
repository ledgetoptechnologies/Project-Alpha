const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/password-visibility.js'), 'utf8');
const login = fs.readFileSync(path.join(root, 'src/views/pages/auth/login.php'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'public/assets/auth-login.css'), 'utf8');

function element(tagName, attributes = {}) {
  const listeners = {};
  const attrs = { ...attributes };
  return {
    tagName,
    attrs,
    dataset: {},
    hidden: true,
    title: '',
    type: attrs.type,
    form: null,
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null; },
    setAttribute(name, value) { attrs[name] = String(value); },
    addEventListener(name, listener) { (listeners[name] ||= []).push(listener); },
    dispatch(name) { for (const listener of listeners[name] || []) listener({ type: name }); },
  };
}

function loadWithField() {
  const form = element('FORM');
  const input = element('INPUT', { id: 'login-password', type: 'password' });
  input.form = form;
  const button = element('BUTTON', { type: 'button', 'aria-controls': 'login-password', 'aria-pressed': 'false', 'aria-label': 'Show password' });
  button.ownerDocument = {
    getElementById(id) { return id === 'login-password' ? input : null; },
  };
  const document = {
    readyState: 'complete',
    querySelectorAll(selector) { return selector === '[data-password-toggle]' ? [button] : []; },
    addEventListener() {},
  };
  const window = { document };
  vm.runInContext(script, vm.createContext({ window }));
  return { window, form, input, button };
}

test('login password fields carry a hidden-by-default, labelled toggle button', () => {
  assert.match(login, /type="button" class="login-password-toggle" data-password-toggle/);
  assert.match(login, /aria-pressed="false" aria-label="Show password" title="Show password" hidden>/);
  assert.match(login, /\$passwordToggle\('login-password'\)/);
  assert.match(login, /\$passwordToggle\('login-password-confirm'\)/);
  assert.match(login, /<label for="login-password">Password<\/label>/);
  assert.match(login, /id="login-password" minlength="8" type="password" name="password" autocomplete="<\?php echo \$noUsers \? 'new-password' : 'current-password'; \?>"/);
  assert.match(login, /asset_url\('\/assets\/js\/password-visibility\.js'\)\); \?>" defer><\/script>/);
  // The toggle is outside every <label>, so clicking it never focuses or submits through the label.
  assert.doesNotMatch(login, /<label[^>]*>(?:(?!<\/label>)[\s\S])*data-password-toggle/);
  // Passkey sign-in and the username autocomplete hint are untouched.
  assert.match(login, /autocomplete="<\?php echo \$noUsers \? 'username' : 'username webauthn'; \?>"/);
  assert.match(login, /data-passkey-login/);
});

test('toggle styles keep one visible control and a focus ring', () => {
  assert.match(styles, /\.login-password-toggle:focus-visible \{ outline: 3px solid/);
  assert.match(styles, /::-ms-reveal/);
  assert.match(styles, /\.login-password-toggle\[hidden\] \{ display: none; \}/);
});

test('the toggle starts hidden-value, then switches type, pressed state and label', () => {
  const { input, button } = loadWithField();
  assert.equal(button.hidden, false, 'the script reveals the enhancement');
  assert.equal(input.type, 'password');
  assert.equal(button.getAttribute('aria-pressed'), 'false');
  assert.equal(button.getAttribute('aria-label'), 'Show password');

  button.dispatch('click');
  assert.equal(input.type, 'text');
  assert.equal(button.getAttribute('aria-pressed'), 'true');
  assert.equal(button.getAttribute('aria-label'), 'Hide password');
  assert.equal(button.title, 'Hide password');

  button.dispatch('click');
  assert.equal(input.type, 'password');
  assert.equal(button.getAttribute('aria-pressed'), 'false');
  assert.equal(button.getAttribute('aria-label'), 'Show password');
});

test('submitting the form masks a revealed password again', () => {
  const { form, input, button } = loadWithField();
  button.dispatch('click');
  assert.equal(input.type, 'text');
  form.dispatch('submit');
  assert.equal(input.type, 'password');
  assert.equal(button.getAttribute('aria-pressed'), 'false');
});

test('the script never reads or reports the password value', () => {
  assert.doesNotMatch(script, /\.value\b/);
  assert.doesNotMatch(script, /console\.|fetch\(|localStorage|sessionStorage|sendBeacon/);
});
