(function () {
  'use strict';

  function initProjectSettingsContactManager() {
    document.querySelectorAll('[data-project-billing-transition]').forEach(function (form) {
      if (form.dataset.billingTransitionReady === '1') return;
      form.dataset.billingTransitionReady = '1';
      var period = form.querySelector('[data-project-billing-period]');
      var transitionPanel = form.querySelector('[data-billing-transition-panel]');
      var autoEmail = form.querySelector('[data-monthly-auto-email]');
      var autoEmailInput = autoEmail ? autoEmail.querySelector('input[name="project_invoice_auto_email"]') : null;
      var monthlyConfirmation = form.querySelector('[data-monthly-auto-email-confirmed]');
      if (!period) return;

      function syncBillingTransition() {
        var original = String(period.dataset.originalBillingPeriod || 'per_invoice');
        var selected = String(period.value || 'per_invoice');
        var hasUnresolved = transitionPanel && transitionPanel.dataset.hasUnresolved === '1';
        var needsTransition = selected === 'per_invoice' && (original === 'monthly' || hasUnresolved);
        if (transitionPanel) transitionPanel.hidden = !needsTransition;
        if (autoEmail) autoEmail.hidden = selected !== 'monthly';
        if (autoEmailInput) autoEmailInput.disabled = selected !== 'monthly';
      }

      period.addEventListener('change', syncBillingTransition);
      form.addEventListener('submit', function (event) {
        var original = String(period.dataset.originalBillingPeriod || 'per_invoice');
        if (original === 'per_invoice' && period.value === 'monthly') {
          var autoEmailChoice = autoEmailInput && autoEmailInput.checked ? 'enabled' : 'disabled';
          if (!window.confirm('Switch this Project to monthly billing with automatic Project Invoice email ' + autoEmailChoice + '? Existing direct invoices and links remain unchanged.')) {
            event.preventDefault();
            return;
          }
          if (monthlyConfirmation) monthlyConfirmation.value = '1';
        }
        var unresolved = transitionPanel && transitionPanel.dataset.hasUnresolved === '1';
        if (period.value !== 'per_invoice' || (String(period.dataset.originalBillingPeriod || '') !== 'monthly' && !unresolved)) return;
        var strategy = form.querySelector('input[name="billing_transition_strategy"]:checked');
        var delivery = form.querySelector('input[name="delivery_action"]:checked');
        var strategyLabel = strategy && strategy.value === 'convert_to_direct'
          ? 'convert all unassigned monthly invoices to individual billing'
          : 'create one final Project Invoice';
        var deliveryLabel = delivery && delivery.value === 'send_all'
          ? (strategy && strategy.value === 'convert_to_direct' ? ' and send them after the transition' : ' and send it after the transition')
          : ' without sending email';
        if (!window.confirm('Switch this Project to per-invoice billing, ' + strategyLabel + deliveryLabel + '? Existing public links will remain unchanged.')) {
          event.preventDefault();
        }
      });
      syncBillingTransition();
    });

    var manager = document.getElementById('projectManagerSelect');
    var businessUnit = document.getElementById('projectBusinessUnitSelect');
    var touched = document.getElementById('projectBusinessUnitTouched');
    var suggestion = document.getElementById('projectManagerUnitSuggestion');
    if (manager && businessUnit && touched && manager.dataset.unitDefaultReady !== '1') {
      manager.dataset.unitDefaultReady = '1';
      businessUnit.addEventListener('change', function () { touched.value = '1'; });
      manager.addEventListener('change', function () {
        var option = manager.options[manager.selectedIndex];
        var suggestedUnit = option ? String(option.dataset.primaryBusinessUnit || '') : '';
        var suggestedOption = Array.from(businessUnit.options).find(function (unit) { return unit.value === suggestedUnit; });
        if (suggestion) {
          suggestion.textContent = suggestedOption ? 'Manager primary unit: ' + suggestedOption.textContent + '. You may keep the current Project unit.' : 'This manager has no primary Business Unit.';
        }
        if (String(businessUnit.dataset.projectCurrentUnit || '') === '0' && touched.value !== '1' && suggestedOption) {
          businessUnit.value = suggestedUnit;
        }
      });
    }
    document.querySelectorAll('[data-project-settings-contact-manager]').forEach(function (root) {
      if (!root || root.dataset.ready === '1') return;
      root.dataset.ready = '1';

      var dataNode = root.querySelector('[data-project-settings-clients]');
      var clients = [];
      try {
        clients = JSON.parse(dataNode ? dataNode.textContent : '[]');
      } catch (e) {
        clients = [];
      }
      clients = clients.map(function (client) {
        client.id = String(client.id);
        client.email = client.email || '';
        return client;
      });

      var selected = {
        project: new Set(clients.filter(function (client) { return Number(client.is_selected || 0) === 1; }).map(function (client) { return client.id; })),
        invoice: new Set(clients.filter(function (client) { return Number(client.is_invoice_recipient || 0) === 1; }).map(function (client) { return client.id; })),
        links: new Set(clients.filter(function (client) { return Number(client.can_view_links || 0) === 1; }).map(function (client) { return client.id; }))
      };
      var primaryId = (clients.find(function (client) { return Number(client.is_primary || 0) === 1; }) || {}).id || '';

      function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
      }

      function picker(type) {
        var pickerRoot = root.querySelector('[data-project-settings-picker="' + type + '"]');
        if (!pickerRoot) return null;
        return {
          root: pickerRoot,
          selected: pickerRoot.querySelector('[data-picker-selected]'),
          search: pickerRoot.querySelector('[data-picker-search]'),
          suggestions: pickerRoot.querySelector('[data-picker-suggestions]'),
          hidden: pickerRoot.querySelector('[data-picker-hidden]')
        };
      }

      function clientById(id) {
        id = String(id);
        return clients.find(function (client) { return client.id === id; }) || null;
      }

      function inputName(type) {
        if (type === 'invoice') return 'project_invoice_email_client_ids[]';
        if (type === 'links') return 'project_invoice_link_client_ids[]';
        return 'project_client_ids[]';
      }

      function renderPrimarySelect() {
        var select = root.querySelector('[data-project-primary-select]');
        if (!select) return;
        var ids = Array.from(selected.project).filter(function (id) { return clientById(id); });
        if (primaryId && ids.indexOf(primaryId) === -1) {
          primaryId = ids[0] || '';
        }
        select.innerHTML = '';
        if (!ids.length) {
          var empty = document.createElement('option');
          empty.value = '';
          empty.textContent = 'No project contacts selected';
          select.appendChild(empty);
          return;
        }
        ids.forEach(function (id) {
          var client = clientById(id);
          var option = document.createElement('option');
          option.value = id;
          option.textContent = client.name + (client.email ? ' - ' + client.email : '');
          if (id === primaryId) option.selected = true;
          select.appendChild(option);
        });
      }

      function render(type) {
        var p = picker(type);
        if (!p) return;
        var ids = Array.from(selected[type]).filter(function (id) { return clientById(id); });
        selected[type] = new Set(ids);
        p.selected.innerHTML = '';
        p.hidden.innerHTML = '';
        if (!ids.length) {
          p.selected.innerHTML = '<div class="project-settings-picker__empty">' + escapeHtml(p.root.dataset.emptyText || 'No clients selected.') + '</div>';
        }
        ids.forEach(function (id) {
          var client = clientById(id);
          var row = document.createElement('div');
          row.className = 'project-settings-picker__item';
          row.innerHTML =
            '<span><span class="project-settings-picker__name">' + escapeHtml(client.name) + '</span>' +
            (Number(client.is_department_contact || 0) === 1 ? '<span class="project-pill" style="margin-left:6px">Department</span>' : '') +
            (Number(client.is_primary_department_contact || 0) === 1 ? '<span class="project-pill project-pill--primary" style="margin-left:6px">Dept Primary</span>' : '') +
            (client.email ? '<span class="project-settings-picker__meta">' + escapeHtml(client.email) + '</span>' : '<span class="project-settings-picker__meta">No email address</span>') +
            '</span><button type="button" class="project-settings-picker__remove" data-remove-id="' + escapeHtml(id) + '" aria-label="Remove ' + escapeHtml(client.name) + '">x</button>';
          p.selected.appendChild(row);

          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = inputName(type);
          hidden.value = id;
          p.hidden.appendChild(hidden);
        });
        p.selected.querySelectorAll('[data-remove-id]').forEach(function (button) {
          button.addEventListener('click', function () {
            var id = String(button.getAttribute('data-remove-id') || '');
            selected[type].delete(id);
            if (type === 'project') {
              selected.links.delete(id);
              if (primaryId === id) primaryId = '';
            }
            renderAll();
          });
        });
      }

      function renderAll() {
        render('project');
        render('invoice');
        render('links');
        renderPrimarySelect();
      }

      function renderSuggestions(type) {
        var p = picker(type);
        if (!p) return;
        var query = (p.search.value || '').trim().toLowerCase();
        p.suggestions.innerHTML = '';
        if (!query) {
          p.suggestions.style.display = 'none';
          return;
        }
        var matches = clients.filter(function (client) {
          if (selected[type].has(client.id)) return false;
          if (type === 'invoice' && !client.email) return false;
          return (client.name + ' ' + client.email).toLowerCase().indexOf(query) !== -1;
        }).slice(0, 12);
        if (!matches.length) {
          p.suggestions.innerHTML = '<div class="project-settings-picker__suggestion" style="color:var(--muted)">No matching clients</div>';
          p.suggestions.style.display = 'block';
          return;
        }
        matches.forEach(function (client) {
          var option = document.createElement('div');
          option.className = 'project-settings-picker__suggestion';
          option.setAttribute('data-client-id', client.id);
          option.innerHTML =
            '<strong>' + escapeHtml(client.name) + '</strong>' +
            (Number(client.is_department_contact || 0) === 1 ? '<span class="project-pill" style="margin-left:6px">Department</span>' : '') +
            (Number(client.is_primary_department_contact || 0) === 1 ? '<span class="project-pill project-pill--primary" style="margin-left:6px">Dept Primary</span>' : '') +
            '<span class="project-settings-picker__meta">' + escapeHtml(client.email || 'No email address') + '</span>';
          p.suggestions.appendChild(option);
        });
        p.suggestions.style.display = 'block';
      }

      function add(type, id) {
        id = String(id);
        if (!clientById(id)) return;
        selected[type].add(id);
        if (type === 'links') {
          selected.project.add(id);
        }
        if (!primaryId && selected.project.has(id)) {
          primaryId = id;
          var primaryClient = clientById(primaryId);
          if (primaryClient && primaryClient.email) selected.invoice.add(primaryId);
        }
        renderAll();
      }

      ['project', 'invoice', 'links'].forEach(function (type) {
        var p = picker(type);
        if (!p) return;
        p.search.addEventListener('input', function () {
          renderSuggestions(type);
        });
        p.search.addEventListener('focus', function () { renderSuggestions(type); });
        p.suggestions.addEventListener('click', function (event) {
          var option = event.target.closest('[data-client-id]');
          if (!option) return;
          add(type, option.getAttribute('data-client-id'));
          p.search.value = '';
          p.suggestions.style.display = 'none';
        });
      });

      var primarySelect = root.querySelector('[data-project-primary-select]');
      if (primarySelect) {
        primarySelect.addEventListener('change', function () {
          primaryId = primarySelect.value || '';
          var client = clientById(primaryId);
          if (client && client.email) {
            selected.invoice.add(primaryId);
            render('invoice');
          }
        });
      }

      if (!window.__projectAlphaProjectSettingsOutsideClickReady) {
        window.__projectAlphaProjectSettingsOutsideClickReady = true;
        document.addEventListener('click', function (event) {
          document.querySelectorAll('[data-project-settings-picker]').forEach(function (pickerRoot) {
            if (!pickerRoot.contains(event.target)) {
              var suggestions = pickerRoot.querySelector('[data-picker-suggestions]');
              if (suggestions) suggestions.style.display = 'none';
            }
          });
        });
      }

      renderAll();
    });
  }
  initProjectSettingsContactManager.pageInitializerId = 'project-settings-contact-manager';

  if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('project/projects-edit', initProjectSettingsContactManager);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initProjectSettingsContactManager, { once: true });
  } else {
    initProjectSettingsContactManager();
  }
})();
