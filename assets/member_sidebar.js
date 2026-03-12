(function () {
  var CLOSE_DURATION_MS = 220;

  function isMobileViewport() {
    return !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  }

  function setMobileOpen(shell, open) {
    var closeTimer = shell._memberMobileCloseTimer;
    if (closeTimer) {
      clearTimeout(closeTimer);
      shell._memberMobileCloseTimer = null;
    }

    if (open) {
      shell.classList.remove('is-mobile-nav-closing');
      shell.classList.add('is-mobile-nav-open');
      document.body.classList.add('member-mobile-nav-active');
    } else {
      shell.classList.remove('is-mobile-nav-open');
      shell.classList.add('is-mobile-nav-closing');
      shell._memberMobileCloseTimer = window.setTimeout(function () {
        shell.classList.remove('is-mobile-nav-closing');
        document.body.classList.remove('member-mobile-nav-active');
        shell._memberMobileCloseTimer = null;
      }, CLOSE_DURATION_MS);
    }

    shell.querySelectorAll('.js-mobile-sidebar-toggle').forEach(function (button) {
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
    });
  }

  function ensureMobileControls(shell) {
    if (shell.querySelector('.js-mobile-sidebar-toggle')) {
      return;
    }

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'member-mobile-nav-toggle js-mobile-sidebar-toggle';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open navigation menu');
    toggle.innerHTML = '<span class="member-mobile-nav-toggle-bars" aria-hidden="true"></span>';

    shell.appendChild(toggle);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var shells = document.querySelectorAll('.js-member-sidebar');
    shells.forEach(function (shell) {
      var mobileOpen = false;

      ensureMobileControls(shell);
      shell.querySelectorAll('.js-sidebar-toggle').forEach(function (button) {
        button.setAttribute('aria-expanded', 'true');
        button.setAttribute('aria-label', 'Main menu');
      });

      shell.querySelectorAll('.js-mobile-sidebar-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
          if (!isMobileViewport()) {
            return;
          }

          mobileOpen = !mobileOpen;
          setMobileOpen(shell, mobileOpen);
        });
      });

      shell.querySelectorAll('.member-sidebar-link').forEach(function (link) {
        link.addEventListener('click', function () {
          if (!isMobileViewport()) {
            return;
          }

          mobileOpen = false;
          setMobileOpen(shell, false);
        });
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && mobileOpen) {
          mobileOpen = false;
          setMobileOpen(shell, false);
        }
      });

      if (window.matchMedia) {
        var mediaQuery = window.matchMedia('(max-width: 768px)');
        var handleViewportChange = function (event) {
          if (!event.matches) {
            mobileOpen = false;
            setMobileOpen(shell, false);
          }
        };

        if (typeof mediaQuery.addEventListener === 'function') {
          mediaQuery.addEventListener('change', handleViewportChange);
        } else if (typeof mediaQuery.addListener === 'function') {
          mediaQuery.addListener(handleViewportChange);
        }
      }
    });
  });
})();
