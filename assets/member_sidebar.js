(function () {
  var CLOSE_DURATION_MS = 220;
  var MOBILE_TRACKING_LABEL = 'Tracking';

  function isMobileViewport() {
    return !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  }

  function syncMobileLabels(shell) {
    shell.querySelectorAll('.member-sidebar-link[data-tooltip="Records Tracking"]').forEach(function (link) {
      var label = link.querySelector('.member-sidebar-label');
      if (!label) {
        return;
      }

      if (!label.dataset.desktopLabel) {
        label.dataset.desktopLabel = label.textContent.trim();
      }

      label.textContent = isMobileViewport() ? MOBILE_TRACKING_LABEL : label.dataset.desktopLabel;
    });
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
    var header = shell.querySelector('.member-mobile-header');
    if (!header) {
      header = document.createElement('div');
      header.className = 'member-mobile-header';
      header.innerHTML =
        '<div class="member-mobile-brand">' +
          '<img class="member-mobile-brand-mark" src="/librarymanage/assets/images/RMLOGO.jfif" alt="Regis Marie College logo">' +
          '<span class="member-mobile-brand-copy">' +
            '<strong>Regis Marie College</strong>' +
            '<span>Library Management System</span>' +
          '</span>' +
        '</div>' +
        '<div class="member-mobile-nav-actions"></div>';
      shell.insertBefore(header, shell.firstChild);
    }

    var actions = header.querySelector('.member-mobile-nav-actions');
    if (!actions) {
      return;
    }

    if (!actions.querySelector('.member-mobile-theme-toggle')) {
      var themeToggle = document.createElement('button');
      themeToggle.type = 'button';
      themeToggle.className = 'theme-toggle member-mobile-theme-toggle';
      themeToggle.setAttribute('aria-label', 'Toggle theme');
      themeToggle.innerHTML = '<span class="theme-toggle-icon" aria-hidden="true"></span><span class="theme-toggle-text"></span>';
      actions.appendChild(themeToggle);
    }

    if (!actions.querySelector('.js-mobile-sidebar-toggle')) {
      var toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'member-mobile-nav-toggle js-mobile-sidebar-toggle';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open navigation menu');
      toggle.innerHTML = '<span class="member-mobile-nav-toggle-bars" aria-hidden="true"></span>';
      actions.appendChild(toggle);
    }

    if (typeof window.libraryManageSyncThemeToggles === 'function') {
      window.libraryManageSyncThemeToggles();
    }

    if (typeof window.libraryManageEnsureStudentNotifications === 'function') {
      window.libraryManageEnsureStudentNotifications();
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var shells = document.querySelectorAll('.js-member-sidebar');
    shells.forEach(function (shell) {
      var mobileOpen = false;

      ensureMobileControls(shell);
      syncMobileLabels(shell);
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

      document.addEventListener('click', function (event) {
        if (!isMobileViewport() || !mobileOpen) {
          return;
        }

        var sidebar = shell.querySelector('.member-sidebar');
        var mobileToggle = shell.querySelector('.js-mobile-sidebar-toggle');
        var target = event.target;
        if (!sidebar || !target) {
          return;
        }

        if (sidebar.contains(target) || (mobileToggle && mobileToggle.contains(target))) {
          return;
        }

        mobileOpen = false;
        setMobileOpen(shell, false);
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
          syncMobileLabels(shell);
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

