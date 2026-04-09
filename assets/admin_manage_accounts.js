(function () {
  function initManageAccountsUi() {
    var selectAll = document.getElementById('selectAllUsers');
    var printAction = document.getElementById('printAction');
    var printShell = document.querySelector('.manage-users-print-shell');
    var filterForm = document.querySelector('.js-auto-submit-filters');
    var searchInput = document.getElementById('search');
    var roleFilter = document.getElementById('role_filter');
    var checks = Array.prototype.slice.call(document.querySelectorAll('.user-print-check'));
    var deleteForms = Array.prototype.slice.call(document.querySelectorAll('.js-confirm-delete-user'));
    var createForm = document.querySelector('.manage-users-create-form');
    var provisioningShell = document.querySelector('.js-manage-users-provisioning');
    var filterPanel = document.querySelector('[data-manage-accounts-shell]');
    var autoSubmitTimer = null;
    var activeRequest = null;

    function setPanelLoading(isLoading) {
      if (!filterPanel) {
        return;
      }

      filterPanel.style.opacity = isLoading ? '0.72' : '';
      if (filterForm) {
        filterForm.querySelectorAll('select, input, button').forEach(function (element) {
          element.disabled = isLoading;
        });
      }

      filterPanel.querySelectorAll('[data-manage-accounts-reset]').forEach(function (link) {
        link.style.pointerEvents = isLoading ? 'none' : '';
        link.style.opacity = isLoading ? '0.72' : '';
      });
    }

    function syncPrintSelectState() {
      if (!printShell || !printAction) {
        return;
      }

      printShell.classList.toggle('is-selected', !!printAction.value);
    }

    function selectedUserIds() {
      return checks.filter(function (check) {
        return check.checked;
      }).map(function (check) {
        return check.value;
      });
    }

    function buildPrintParams(action) {
      var params = new URLSearchParams();
      params.set('print', '1');
      var currentSearch = searchInput ? searchInput.value.trim() : '';

      if (currentSearch) {
        params.set('search', currentSearch);
      }

      if (action === 'selected') {
        var ids = selectedUserIds();

        if (ids.length === 0) {
          window.alert('Select at least one user to print.');
          return null;
        }

        params.set('user_ids', ids.join(','));
        return params;
      }

      if (action !== 'all') {
        params.set('role', action);
      }

      return params;
    }

    function buildFilterUrl() {
      if (!filterForm) {
        return 'manage_accounts.php';
      }

      var formData = new FormData(filterForm);
      var params = new URLSearchParams();
      formData.forEach(function (value, key) {
        if (String(value).trim() !== '') {
          params.set(key, String(value));
        }
      });

      var query = params.toString();
      return 'manage_accounts.php' + (query ? '?' + query : '');
    }

    function loadPanel(url) {
      if (!filterPanel) {
        window.location.href = url;
        return;
      }

      if (activeRequest && typeof activeRequest.abort === 'function') {
        activeRequest.abort();
      }

      var controller = new AbortController();
      activeRequest = controller;
      setPanelLoading(true);

      fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        signal: controller.signal
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Request failed');
          }

          return response.text();
        })
        .then(function (html) {
          var parser = new DOMParser();
          var nextDoc = parser.parseFromString(html, 'text/html');
          var nextPanel = nextDoc.querySelector('[data-manage-accounts-shell]');

          if (!nextPanel) {
            throw new Error('Missing manage accounts shell');
          }

          filterPanel.replaceWith(nextPanel);
          if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState({}, '', url);
          }

          var top = nextPanel.getBoundingClientRect().top + window.scrollY - 24;
          window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
          initManageAccountsUi();
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') {
            return;
          }

          window.location.href = url;
        })
        .finally(function () {
          activeRequest = null;
          var currentPanel = document.querySelector('[data-manage-accounts-shell]');
          if (currentPanel) {
            currentPanel.style.opacity = '';
          }
        });
    }

    function submitFilters() {
      loadPanel(buildFilterUrl());
    }

    function queueFilterSubmit() {
      if (!filterForm) {
        return;
      }

      window.clearTimeout(autoSubmitTimer);
      autoSubmitTimer = window.setTimeout(submitFilters, 320);
    }

    if (selectAll && selectAll.dataset.bound !== '1') {
      selectAll.dataset.bound = '1';
      selectAll.addEventListener('change', function () {
        checks.forEach(function (check) {
          check.checked = selectAll.checked;
        });
      });
    }

    checks.forEach(function (check) {
      if (check.dataset.bound === '1') {
        return;
      }

      check.dataset.bound = '1';
      check.addEventListener('change', function () {
        if (selectAll) {
          selectAll.checked = checks.every(function (item) {
            return item.checked;
          });
        }
      });
    });

    if (printAction && printAction.dataset.bound !== '1') {
      printAction.dataset.bound = '1';
      printAction.addEventListener('change', function () {
        syncPrintSelectState();

        var action = printAction.value;
        if (!action) {
          return;
        }

        var params = buildPrintParams(action);
        if (!params) {
          printAction.value = '';
          syncPrintSelectState();
          return;
        }

        window.location.href = 'manage_accounts.php?' + params.toString();
      });
      syncPrintSelectState();
    }

    if (searchInput && searchInput.dataset.bound !== '1') {
      searchInput.dataset.bound = '1';
      searchInput.addEventListener('input', queueFilterSubmit);
    }

    if (roleFilter && roleFilter.dataset.bound !== '1') {
      roleFilter.dataset.bound = '1';
      roleFilter.addEventListener('change', submitFilters);
    }

    if (filterForm && filterForm.dataset.bound !== '1') {
      filterForm.dataset.bound = '1';
      filterForm.addEventListener('submit', function (event) {
        event.preventDefault();
        submitFilters();
      });
    }

    document.querySelectorAll('[data-manage-accounts-reset]').forEach(function (link) {
      if (link.dataset.bound === '1') {
        return;
      }

      link.dataset.bound = '1';
      link.addEventListener('click', function (event) {
        event.preventDefault();
        loadPanel(link.href);
      });
    });

    deleteForms.forEach(function (form) {
      if (form.dataset.bound === '1') {
        return;
      }

      form.dataset.bound = '1';
      form.addEventListener('submit', function (event) {
        if (!window.confirm('Delete this user?')) {
          event.preventDefault();
        }
      });
    });

    if (createForm && createForm.dataset.bound !== '1') {
      createForm.dataset.bound = '1';
      var createRoleSelect = createForm.querySelector('select[name="role"]');
      var courseSelect = createForm.querySelector('select[name="course"]');
      var courseField = courseSelect ? courseSelect.closest('.manage-users-create-field') : null;
      var courseHelp = createForm.querySelector('.manage-users-create-help');

      if (createRoleSelect && courseSelect) {
        var syncProgramFieldState = function () {
          var requiresProgram = createRoleSelect.value === 'student';

          courseSelect.disabled = !requiresProgram;
          courseSelect.required = requiresProgram;

          if (!requiresProgram) {
            courseSelect.value = '';
          }

          if (courseField) {
            courseField.classList.toggle('is-disabled', !requiresProgram);
            courseField.hidden = !requiresProgram;
          }

          if (courseHelp) {
            courseHelp.textContent = requiresProgram
              ? 'Required for student accounts.'
              : 'Only needed when the selected role is Student.';
          }
        };

        createRoleSelect.addEventListener('change', syncProgramFieldState);
        syncProgramFieldState();
      }
    }

    if (provisioningShell && provisioningShell.dataset.bound !== '1') {
      provisioningShell.dataset.bound = '1';
      var tabButtons = Array.prototype.slice.call(provisioningShell.querySelectorAll('[data-tab-trigger]'));
      var tabPanels = Array.prototype.slice.call(provisioningShell.querySelectorAll('[data-tab-panel]'));

      var setActiveProvisioningTab = function (tabId) {
        var activeId = tabId === 'bulk' ? 'bulk' : 'single';

        tabButtons.forEach(function (button) {
          var isActive = button.getAttribute('data-tab-trigger') === activeId;
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-selected', isActive ? 'true' : 'false');
          button.tabIndex = isActive ? 0 : -1;
        });

        tabPanels.forEach(function (panel) {
          var isActive = panel.getAttribute('data-tab-panel') === activeId;
          panel.classList.toggle('is-active', isActive);
          panel.hidden = !isActive;
        });

        provisioningShell.setAttribute('data-active-tab', activeId);
      };

      tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
          setActiveProvisioningTab(button.getAttribute('data-tab-trigger'));
        });
      });

      setActiveProvisioningTab(provisioningShell.getAttribute('data-active-tab') || 'single');
    }
  }

  initManageAccountsUi();
})();
