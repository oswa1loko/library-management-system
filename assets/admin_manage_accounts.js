(function () {
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
  var filterPanel = document.querySelector('[data-filter-panel]');
  var autoSubmitTimer = null;
  var scrollStorageKey = 'admin-manage-accounts-filter-scroll';

  try {
    if (window.sessionStorage.getItem(scrollStorageKey) === 'filter-panel' && filterPanel) {
      window.requestAnimationFrame(function () {
        var top = filterPanel.getBoundingClientRect().top + window.scrollY - 24;
        window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
        document.documentElement.removeAttribute('data-pending-filter-scroll');
      });
      window.sessionStorage.removeItem(scrollStorageKey);
    } else {
      document.documentElement.removeAttribute('data-pending-filter-scroll');
    }
  } catch (error) {
    // Ignore session storage failures.
    document.documentElement.removeAttribute('data-pending-filter-scroll');
  }

  function submitFilters() {
    if (!filterForm) {
      return;
    }

    try {
      window.sessionStorage.setItem(scrollStorageKey, 'filter-panel');
    } catch (error) {
      // Ignore session storage failures and continue with submit.
    }

    filterForm.requestSubmit ? filterForm.requestSubmit() : filterForm.submit();
  }

  function queueFilterSubmit() {
    if (!filterForm) {
      return;
    }

    window.clearTimeout(autoSubmitTimer);
    autoSubmitTimer = window.setTimeout(submitFilters, 320);
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

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checks.forEach(function (check) {
        check.checked = selectAll.checked;
      });
    });
  }

  checks.forEach(function (check) {
    check.addEventListener('change', function () {
      if (selectAll) {
        selectAll.checked = checks.every(function (item) {
          return item.checked;
        });
      }
    });
  });

  if (printAction) {
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

  if (searchInput) {
    searchInput.addEventListener('input', queueFilterSubmit);
  }

  if (roleFilter) {
    roleFilter.addEventListener('change', submitFilters);
  }

  deleteForms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Delete this user?')) {
        event.preventDefault();
      }
    });
  });

  if (createForm) {
    var roleSelect = createForm.querySelector('select[name="role"]');
    var courseSelect = createForm.querySelector('select[name="course"]');
    var courseField = courseSelect ? courseSelect.closest('.manage-users-create-field') : null;
    var courseHelp = createForm.querySelector('.manage-users-create-help');

    if (roleSelect && courseSelect) {
      var syncProgramFieldState = function () {
        var requiresProgram = roleSelect.value === 'student';

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

      roleSelect.addEventListener('change', syncProgramFieldState);
      syncProgramFieldState();
    }
  }

  if (provisioningShell) {
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
})();
