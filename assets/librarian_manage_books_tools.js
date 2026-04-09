(function () {
  function initManageBooksTools(root) {
    var scope = root || document;
    var printAction = scope.querySelector('#printBooksAction');
    var printShell = scope.querySelector('.manage-users-print-shell');
    var searchInput = scope.querySelector('#search');
    var catalogFilter = scope.querySelector('#catalog_filter');

    if (!printAction || !printShell || printAction.dataset.booksToolsBound === '1') {
      return;
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

    printAction.dataset.booksToolsBound = '1';
    printAction.addEventListener('change', function () {
      syncPrintSelectState();

      var action = printAction.value;
      if (!action) {
        return;
      }

      var params = buildPrintParams(action);
      window.location.href = 'manage_books.php?' + params.toString();
    });

    syncPrintSelectState();
  }

  document.addEventListener('DOMContentLoaded', function () {
    initManageBooksTools(document);
  });

  document.addEventListener('adminAjaxPanel:updated', function (event) {
    if (!event.detail || event.detail.shellKey !== 'librarian-books-records-panel') {
      return;
    }

    initManageBooksTools(event.detail.shell);
  });
})();
