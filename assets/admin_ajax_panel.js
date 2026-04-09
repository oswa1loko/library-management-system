(function () {
  function setShellLoading(shell, isLoading) {
    if (!shell) {
      return;
    }

    shell.classList.toggle('is-ajax-loading', isLoading);
    shell.style.opacity = isLoading ? '0.72' : '';

    var filterForm = shell.querySelector('[data-ajax-filter-form]');
    if (filterForm) {
      filterForm.querySelectorAll('select, input, button').forEach(function (element) {
        element.disabled = isLoading;
      });
    }

    shell.querySelectorAll('[data-ajax-filter-link]').forEach(function (link) {
      link.style.pointerEvents = isLoading ? 'none' : '';
      link.style.opacity = isLoading ? '0.72' : '';
    });
  }

  function restoreShellPosition(shell) {
    var filterPanel = shell.querySelector('[data-filter-panel]');
    if (!filterPanel) {
      return;
    }

    var top = filterPanel.getBoundingClientRect().top + window.scrollY - 24;
    window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
  }

  function buildFormUrl(form) {
    var formData = new FormData(form);
    var params = new URLSearchParams();

    formData.forEach(function (value, key) {
      if (String(value).trim() !== '') {
        params.set(key, String(value));
      }
    });

    var query = params.toString();
    var action = form.getAttribute('action') || window.location.pathname.split('/').pop() || '';
    return action + (query ? '?' + query : '');
  }

  function bindShell(shell) {
    if (!shell || shell.dataset.ajaxPanelBound === '1') {
      return;
    }

    var shellKey = shell.getAttribute('data-ajax-panel-shell');
    var filterForm = shell.querySelector('[data-ajax-filter-form]');
    var activeRequest = null;
    var searchTimer = null;

    if (!shellKey || !filterForm) {
      return;
    }

    function loadShell(url) {
      if (activeRequest && typeof activeRequest.abort === 'function') {
        activeRequest.abort();
      }

      var controller = new AbortController();
      activeRequest = controller;
      setShellLoading(shell, true);

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
          var nextShell = nextDoc.querySelector('[data-ajax-panel-shell="' + shellKey + '"]');

          if (!nextShell) {
            throw new Error('Missing shell');
          }

          shell.replaceWith(nextShell);
          if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState({}, '', url);
          }

          restoreShellPosition(nextShell);
          bindShell(nextShell);
          document.dispatchEvent(new CustomEvent('adminAjaxPanel:updated', {
            detail: {
              shellKey: shellKey,
              shell: nextShell
            }
          }));
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') {
            return;
          }

          window.location.href = url;
        })
        .finally(function () {
          activeRequest = null;
          var currentShell = document.querySelector('[data-ajax-panel-shell="' + shellKey + '"]');
          setShellLoading(currentShell, false);
        });
    }

    function submitFilters() {
      loadShell(buildFormUrl(filterForm));
    }

    shell.dataset.ajaxPanelBound = '1';

    filterForm.addEventListener('submit', function (event) {
      event.preventDefault();
      submitFilters();
    });

    filterForm.querySelectorAll('[data-ajax-filter-control]').forEach(function (control) {
      if (control.dataset.ajaxFilterBound === '1') {
        return;
      }

      control.dataset.ajaxFilterBound = '1';
      control.addEventListener('change', submitFilters);
    });

    filterForm.querySelectorAll('[data-ajax-filter-search]').forEach(function (input) {
      if (input.dataset.ajaxFilterBound === '1') {
        return;
      }

      input.dataset.ajaxFilterBound = '1';
      input.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(submitFilters, 320);
      });
    });

    shell.querySelectorAll('[data-ajax-filter-link]').forEach(function (link) {
      if (link.dataset.ajaxFilterBound === '1' || !link.getAttribute('href')) {
        return;
      }

      link.dataset.ajaxFilterBound = '1';
      link.addEventListener('click', function (event) {
        event.preventDefault();
        loadShell(link.href);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.documentElement.removeAttribute('data-pending-filter-scroll');
    document.querySelectorAll('[data-ajax-panel-shell]').forEach(bindShell);
  });
})();
