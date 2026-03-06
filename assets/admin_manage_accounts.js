(function () {
  var selectAll = document.getElementById('selectAllUsers');
  var printAction = document.getElementById('printAction');
  var printShell = document.querySelector('.manage-users-print-shell');
  var runPrintAction = document.getElementById('runPrintAction');
  var searchInput = document.getElementById('search');
  var checks = Array.prototype.slice.call(document.querySelectorAll('.user-print-check'));
  var deleteForms = Array.prototype.slice.call(document.querySelectorAll('.js-confirm-delete-user'));
  var currentSearch = searchInput ? searchInput.value.trim() : '';

  if (!selectAll || !printAction || !printShell || !runPrintAction) {
    return;
  }

  function syncPrintSelectState() {
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

  selectAll.addEventListener('change', function () {
    checks.forEach(function (check) {
      check.checked = selectAll.checked;
    });
  });

  checks.forEach(function (check) {
    check.addEventListener('change', function () {
      selectAll.checked = checks.every(function (item) {
        return item.checked;
      });
    });
  });

  printAction.addEventListener('change', syncPrintSelectState);
  syncPrintSelectState();

  runPrintAction.addEventListener('click', function () {
    var action = printAction.value;

    if (!action) {
      window.alert('Select a print option first.');
      return;
    }

    var params = buildPrintParams(action);
    if (!params) {
      return;
    }

    window.location.href = 'manage_accounts.php?' + params.toString();
  });

  deleteForms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Delete this user?')) {
        event.preventDefault();
      }
    });
  });
})();
