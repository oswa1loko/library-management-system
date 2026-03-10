(function () {
  function readPref(key) {
    try {
      return localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function writePref(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (error) {
      // Ignore persistence errors.
    }
  }

  function applyState(shell, collapsed) {
    shell.classList.toggle('is-collapsed', collapsed);
    shell.querySelectorAll('.js-sidebar-toggle').forEach(function (button) {
      button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var shells = document.querySelectorAll('.js-member-sidebar');
    shells.forEach(function (shell) {
      var rawKey = shell.getAttribute('data-sidebar-key') || 'member';
      var groupKey = rawKey.split('-')[0] || rawKey;
      var key = 'librarymanage.sidebar.' + groupKey;
      var saved = readPref(key);
      var defaultMode = (shell.getAttribute('data-sidebar-default') || '').toLowerCase();
      var lockedMode = (shell.getAttribute('data-sidebar-lock') || '').toLowerCase();
      var prefersCompact = window.matchMedia && window.matchMedia('(max-width: 560px)').matches;
      var defaultCollapsed = defaultMode === 'expanded' ? false : prefersCompact;
      var collapsed = lockedMode === 'expanded' ? false : (saved === null ? defaultCollapsed : saved === '1');

      applyState(shell, collapsed);

      shell.querySelectorAll('.js-sidebar-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
          if (lockedMode === 'expanded') {
            return;
          }

          collapsed = !collapsed;
          applyState(shell, collapsed);
          writePref(key, collapsed ? '1' : '0');
        });
      });
    });
  });
})();
