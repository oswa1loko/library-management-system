(function () {
  var printAction = document.getElementById('printBooksAction');
  var printShell = document.querySelector('.manage-users-print-shell');
  var runPrintAction = document.getElementById('runBooksPrintAction');
  var filterForm = document.querySelector('.js-auto-submit-filters');
  var searchInput = document.getElementById('search');
  var catalogFilter = document.getElementById('catalog_filter');
  var autoSubmitTimer = null;

  if (!printAction || !printShell || !runPrintAction) {
    return;
  }

  function submitFilters() {
    if (!filterForm) {
      return;
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
    printShell.classList.toggle('is-selected', !!printAction.value);
  }

  function buildPrintParams(action) {
    var params = new URLSearchParams();
    params.set('print', '1');

    var searchValue = searchInput ? searchInput.value.trim() : '';
    var catalogValue = catalogFilter ? catalogFilter.value : '';

    if (searchValue) {
      params.set('search', searchValue);
    }

    if (action.indexOf('catalog:') === 0) {
      params.set('catalog', action.split(':')[1]);
      params.set('print_scope', 'catalog');
      return params;
    }

    if (catalogValue && action !== 'all') {
      params.set('catalog', catalogValue);
    }

    params.set('print_scope', action);
    return params;
  }

  printAction.addEventListener('change', syncPrintSelectState);
  syncPrintSelectState();

  runPrintAction.addEventListener('click', function () {
    var action = printAction.value;

    if (!action) {
      window.alert('Select a print option first.');
      return;
    }

    var params = buildPrintParams(action);
    window.location.href = 'manage_books.php?' + params.toString();
  });

  if (searchInput) {
    searchInput.addEventListener('input', queueFilterSubmit);
  }

  if (catalogFilter) {
    catalogFilter.addEventListener('change', submitFilters);
  }
})();
