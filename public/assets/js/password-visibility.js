// Show/hide toggle for password fields marked with [data-password-toggle].
// Progressive enhancement: the button stays hidden until this script runs, so
// a page without JavaScript keeps a plain password field. The toggle never
// reads, stores or logs the value; it only switches the input type, and every
// field is masked again before its form submits so browser password managers
// see an ordinary password submission.
(function (window) {
  'use strict';

  var SHOW = 'Show password';
  var HIDE = 'Hide password';

  function inputFor(button) {
    var id = button.getAttribute('aria-controls');
    var input = id ? button.ownerDocument.getElementById(id) : null;
    return input && input.tagName === 'INPUT' ? input : null;
  }

  function setVisible(button, input, visible) {
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', visible ? 'true' : 'false');
    button.setAttribute('aria-label', visible ? HIDE : SHOW);
    button.title = visible ? HIDE : SHOW;
  }

  function bind(button) {
    var input = inputFor(button);
    if (!input || button.dataset.passwordToggleBound === 'true') return;
    button.dataset.passwordToggleBound = 'true';
    setVisible(button, input, false);
    button.hidden = false;
    // type="button" keeps Enter/Space on the toggle from submitting the form;
    // the native button already handles both keys and keeps focus on itself.
    button.addEventListener('click', function () {
      setVisible(button, input, input.type === 'password');
    });
    if (input.form) {
      input.form.addEventListener('submit', function () { setVisible(button, input, false); });
    }
  }

  function init(root) {
    var scope = root || window.document;
    var buttons = scope.querySelectorAll('[data-password-toggle]');
    for (var i = 0; i < buttons.length; i += 1) bind(buttons[i]);
  }

  window.ProjectAlpha = window.ProjectAlpha || {};
  window.ProjectAlpha.passwordVisibility = { init: init, labels: { show: SHOW, hide: HIDE } };

  if (window.document.readyState === 'loading') {
    window.document.addEventListener('DOMContentLoaded', function () { init(); });
  } else {
    init();
  }
})(window);
