(function () {
  var storageKey = 'librarymanage-theme';
  var root = document.documentElement;
  var sunIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.8"></circle><path d="M12 3.8v2M12 18.2v2M5.8 5.8l1.4 1.4M16.8 16.8l1.4 1.4M3.8 12h2M18.2 12h2M5.8 18.2l1.4-1.4M16.8 7.2l1.4-1.4"></path></svg>';
  var moonIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20.1 14.6a7.9 7.9 0 1 1-10.7-10.7a6.5 6.5 0 1 0 10.7 10.7z"></path></svg>';

  function getStoredTheme() {
    try {
      return localStorage.getItem(storageKey);
    } catch (error) {
      return null;
    }
  }

  function storeTheme(theme) {
    try {
      localStorage.setItem(storageKey, theme);
    } catch (error) {
      // Ignore storage failures and keep the current session state only.
    }
  }

  function applyThemeToToggle(toggle, theme) {
    var isLight = theme === 'light';
    var label = isLight ? 'Light' : 'Dark';
    var nextLabel = isLight ? 'Switch to dark mode' : 'Switch to light mode';

    toggle.setAttribute('aria-pressed', isLight ? 'true' : 'false');
    toggle.setAttribute('aria-label', nextLabel);
    toggle.title = nextLabel;

    var text = toggle.querySelector('.theme-toggle-text');
    if (text) {
      text.textContent = label;
    }

    var icon = toggle.querySelector('.theme-toggle-icon');
    if (icon) {
      icon.innerHTML = isLight ? sunIcon : moonIcon;
    }
  }

  function ensureDesktopFooterToggle() {
      if (document.body && document.body.dataset.skipDesktopFooter === 'true') {
        return null;
      }

      var main = document.querySelector('.site-shell .member-main');
    if (!main) {
      return null;
    }

    var footer = main.querySelector('.site-footer-bar');
    if (!footer) {
      footer = document.createElement('div');
      footer.className = 'panel site-footer-bar';
      footer.innerHTML =
        '<a class="site-footer-brand" href="/librarymanage/index.php">' +
          '<img class="site-footer-brand-mark" src="/librarymanage/assets/images/RMLOGO.jfif" alt="Regis Marie College logo">' +
          '<span class="site-footer-copy">' +
            '<strong>Regis Marie College</strong>' +
            '<span>Library Management System</span>' +
          '</span>' +
        '</a>' +
        '<nav class="site-footer-nav" aria-label="Campus links">' +
          '<a href="/librarymanage/index.php">Home</a>' +
          '<a href="/librarymanage/index.php#services">Services</a>' +
          '<a href="/librarymanage/index.php#access">Access</a>' +
          '<a href="/librarymanage/index.php#contact">Contact</a>' +
        '</nav>' +
        '<div class="site-footer-actions">' +
          '<a class="button site-footer-home-link" href="/librarymanage/index.php">Home</a>' +
        '</div>' +
        '<div class="site-footer-actions site-footer-actions-theme">' +
        '</div>';
      main.appendChild(footer);
    }

    var actions = footer.querySelector('.site-footer-actions-theme');
    if (!actions) {
      return null;
    }

    var toggle = actions.querySelector('.theme-toggle');
    if (!toggle) {
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'theme-toggle site-footer-theme-toggle';
      toggle.innerHTML = '<span class="theme-toggle-icon" aria-hidden="true"></span><span class="theme-toggle-text"></span>';
      actions.appendChild(toggle);
    }

    return toggle;
  }

    function ensureDesktopHeaderToggle() {
      if (document.body && document.body.dataset.skipDesktopHeader === 'true') {
        return null;
      }

      var actions = document.querySelector('.site-desktop-header-theme');
    if (!actions) {
      var shell = document.querySelector('.site-shell');
      if (!shell || !document.body) {
        return null;
      }

      var header = document.createElement('div');
      header.className = 'site-desktop-header member-mobile-hide';
      header.innerHTML =
        '<a class="site-footer-brand" href="/librarymanage/index.php">' +
          '<img class="site-footer-brand-mark" src="/librarymanage/assets/images/RMLOGO.jfif" alt="Regis Marie College logo">' +
          '<span class="site-footer-copy">' +
            '<strong>Regis Marie College</strong>' +
            '<span>Library Management System</span>' +
          '</span>' +
        '</a>' +
        '<div class="site-desktop-header-theme"></div>';
      document.body.insertBefore(header, shell);
      actions = header.querySelector('.site-desktop-header-theme');
    }

    if (!actions) {
      return null;
    }

    var toggle = actions.querySelector('.theme-toggle');
    if (!toggle) {
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'theme-toggle site-desktop-header-toggle';
      toggle.innerHTML = '<span class="theme-toggle-icon" aria-hidden="true"></span><span class="theme-toggle-text"></span>';
      actions.appendChild(toggle);
    }

    return toggle;
  }

  function setTheme(theme) {
    root.setAttribute('data-theme', theme);
    storeTheme(theme);

    document.querySelectorAll('.theme-toggle').forEach(function (toggle) {
      applyThemeToToggle(toggle, theme);
    });
  }

  function toggleTheme() {
    var currentTheme = root.getAttribute('data-theme') || 'dark';
    var nextTheme = currentTheme === 'light' ? 'dark' : 'light';
    setTheme(nextTheme);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getMemberNotificationConfig() {
    if (window.location.pathname.indexOf('/admin/') !== -1) {
      return {
        feedUrl: '/librarymanage/admin/notifications_feed.php'
      };
    }

    if (window.location.pathname.indexOf('/librarian/') !== -1) {
      return {
        feedUrl: '/librarymanage/librarian/notifications_feed.php'
      };
    }

    if (window.location.pathname.indexOf('/student/') !== -1) {
      return {
        feedUrl: '/librarymanage/student/notifications_feed.php'
      };
    }

    if (window.location.pathname.indexOf('/faculty/') !== -1) {
      return {
        feedUrl: '/librarymanage/faculty/notifications_feed.php'
      };
    }

    return null;
  }

  function ensureStudentNotificationPanel() {
    var panel = document.querySelector('.student-notification-panel');
    if (panel) {
      return panel;
    }

    panel = document.createElement('div');
    panel.className = 'student-notification-panel';
    panel.hidden = true;
    panel.innerHTML =
      '<div class="student-notification-panel-head">' +
        '<strong>Notifications</strong>' +
        '<button type="button" class="button secondary student-notification-mark-all">Mark as read</button>' +
      '</div>' +
      '<div class="student-notification-panel-body">' +
        '<div class="student-notification-empty">Loading notifications...</div>' +
      '</div>';
    document.body.appendChild(panel);

    document.addEventListener('click', function (event) {
      if (panel.hidden) {
        return;
      }

      var shortcut = event.target.closest('.student-header-notification-shortcut');
      if (panel.contains(event.target) || shortcut) {
        return;
      }

      panel.hidden = true;
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        panel.hidden = true;
      }
    });

    panel.addEventListener('click', function (event) {
      var markAllButton = event.target.closest('.student-notification-mark-all');
      if (markAllButton) {
        event.preventDefault();
        event.stopPropagation();
        markAllStudentNotificationsRead(panel)
          .catch(function () {
            var body = panel.querySelector('.student-notification-panel-body');
            if (body) {
              body.insertAdjacentHTML('afterbegin', '<div class="student-notification-empty">Unable to mark notifications as read right now.</div>');
            }
          });
        return;
      }

      var readChip = event.target.closest('.student-notification-unread');
      if (!readChip) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      markStudentNotificationRead(panel, readChip.getAttribute('data-notification-id'))
        .catch(function () {
          var body = panel.querySelector('.student-notification-panel-body');
          if (body) {
            body.insertAdjacentHTML('afterbegin', '<div class="student-notification-empty">Unable to mark notification as read right now.</div>');
          }
        });
    });

    return panel;
  }

  function renderStudentNotifications(panel, payload) {
    var body = panel.querySelector('.student-notification-panel-body');
    if (!body) {
      return;
    }

    var items = Array.isArray(payload.items) ? payload.items : [];
    if (items.length === 0) {
      body.innerHTML = '<div class="student-notification-empty">No notifications right now.</div>';
      return;
    }

    body.innerHTML = items.map(function (item) {
      var severityClass = item.severity === 'critical'
        ? 'unpaid'
        : (item.severity === 'warning' ? 'due' : 'approved');
      var unreadChip = item.is_read
        ? '<span class="chip student-notification-read">Read</span>'
        : (
          item.kind === 'notification' && Number(item.id || 0) > 0
            ? '<button type="button" class="chip student-notification-unread" data-notification-id="' + Number(item.id) + '">Mark as read</button>'
            : '<span class="chip">Unread</span>'
        );
      return (
        '<div class="student-notification-item">' +
          '<div class="student-notification-title">' +
            '<span class="status-dot ' + severityClass + '"></span>' +
            '<strong>' + escapeHtml(item.title || 'Notification') + '</strong>' +
            unreadChip +
          '</div>' +
          '<div class="student-notification-copy">' + escapeHtml(item.body || '') + '</div>' +
          '<div class="student-notification-meta">' + escapeHtml(item.created_at || '-') + '</div>' +
        '</div>'
      );
    }).join('');
  }

  function updateStudentNotificationBadges(unreadCount) {
    var count = Number(unreadCount || 0);
    document.querySelectorAll('.student-header-notification-badge').forEach(function (badge) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = count <= 0;
    });

    document.querySelectorAll('.student-notification-mark-all').forEach(function (button) {
      button.disabled = count <= 0;
    });
  }

  function loadStudentNotifications(panel) {
    var config = getMemberNotificationConfig();
    var body = panel.querySelector('.student-notification-panel-body');
    if (body) {
      body.innerHTML = '<div class="student-notification-empty">Loading notifications...</div>';
    }

    if (!config) {
      if (body) {
        body.innerHTML = '<div class="student-notification-empty">Notifications are not available here.</div>';
      }
      return;
    }

    window.fetch(config.feedUrl, {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          throw new Error('Unable to load notifications.');
        }

        renderStudentNotifications(panel, payload);
        updateStudentNotificationBadges(payload.unread_count || 0);
      })
      .catch(function () {
        if (body) {
          body.innerHTML = '<div class="student-notification-empty">Unable to load notifications right now.</div>';
        }
      });
  }

  function markStudentNotificationRead(panel, notificationId) {
    var config = getMemberNotificationConfig();
    var formData = new window.URLSearchParams();
    formData.set('action', 'mark_read');
    formData.set('id', String(Number(notificationId || 0)));

    if (!config) {
      return Promise.reject(new Error('Notifications are not available here.'));
    }

    return window.fetch(config.feedUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: formData.toString()
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          throw new Error('Unable to mark notification as read.');
        }

        updateStudentNotificationBadges(payload.unread_count || 0);
        loadStudentNotifications(panel);
      });
  }

  function markAllStudentNotificationsRead(panel) {
    var config = getMemberNotificationConfig();
    var formData = new window.URLSearchParams();
    formData.set('action', 'mark_all_read');

    if (!config) {
      return Promise.reject(new Error('Notifications are not available here.'));
    }

    return window.fetch(config.feedUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: formData.toString()
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          throw new Error('Unable to mark notifications as read.');
        }

        updateStudentNotificationBadges(payload.unread_count || 0);
        loadStudentNotifications(panel);
      });
  }

  function attachStudentNotificationShortcut(shortcut) {
    if (!shortcut || shortcut.dataset.notificationBound === 'true') {
      return;
    }

    var panel = ensureStudentNotificationPanel();
    shortcut.addEventListener('click', function (event) {
      event.preventDefault();
      var shouldOpen = panel.hidden;
      panel.hidden = !panel.hidden;
      if (shouldOpen) {
        loadStudentNotifications(panel);
      }
    });
    shortcut.dataset.notificationBound = 'true';
    loadStudentNotifications(panel);
  }

  function attachExistingNotificationShortcuts() {
    document.querySelectorAll('.student-header-notification-shortcut').forEach(function (shortcut) {
      attachStudentNotificationShortcut(shortcut);
    });
  }

  function ensureStudentHeaderNotificationShortcut() {
    var config = getMemberNotificationConfig();
    if (!config) {
      return;
    }

    var actions = document.querySelector('.site-desktop-header-theme');
    if (!actions) {
      return;
    }

    var themeToggle = actions.querySelector('.theme-toggle');
    if (!themeToggle) {
      return;
    }

    var notificationLink = actions.querySelector('.student-header-notification-shortcut');
    if (!notificationLink) {
      notificationLink = document.createElement('button');
      notificationLink.type = 'button';
      notificationLink.className = 'student-header-notification-shortcut';
      notificationLink.setAttribute('data-tooltip', 'Notifications');
      notificationLink.setAttribute('aria-label', 'Notifications');
      notificationLink.innerHTML =
        '<span class="dashboard-icon icon-notes" aria-hidden="true"></span>' +
        '<span class="student-header-notification-badge" hidden>0</span>';
    }

    notificationLink.classList.remove('is-active');

    if (!actions.contains(notificationLink)) {
      actions.insertBefore(notificationLink, themeToggle);
    }
    attachStudentNotificationShortcut(notificationLink);
  }

  function ensureStudentMobileNotificationShortcut() {
    var config = getMemberNotificationConfig();
    if (!config) {
      return;
    }

    var actions = document.querySelector('.member-mobile-nav-actions');
    if (!actions) {
      return;
    }

    var themeToggle = actions.querySelector('.member-mobile-theme-toggle');
    if (!themeToggle) {
      return;
    }

    var notificationButton = actions.querySelector('.student-mobile-notification-shortcut');
    if (!notificationButton) {
      notificationButton = document.createElement('button');
      notificationButton.type = 'button';
      notificationButton.className = 'student-header-notification-shortcut student-mobile-notification-shortcut';
      notificationButton.setAttribute('aria-label', 'Notifications');
      notificationButton.innerHTML =
        '<span class="dashboard-icon icon-notes" aria-hidden="true"></span>' +
        '<span class="student-header-notification-badge" hidden>0</span>';
    }

    if (!actions.contains(notificationButton)) {
      actions.insertBefore(notificationButton, themeToggle);
    }

    attachStudentNotificationShortcut(notificationButton);
  }

  var initialTheme = getStoredTheme() || 'dark';
  setTheme(initialTheme);
  window.libraryManageSyncThemeToggles = function () {
    setTheme(root.getAttribute('data-theme') || initialTheme);
  };
  window.libraryManageEnsureStudentNotifications = function () {
    ensureStudentHeaderNotificationShortcut();
    ensureStudentMobileNotificationShortcut();
    attachExistingNotificationShortcuts();
  };

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body) {
      setTheme(root.getAttribute('data-theme') || initialTheme);
      return;
    }

    if (document.body.dataset.skipThemeToggle === 'true') {
      document.querySelectorAll('.theme-toggle').forEach(function (toggle) {
        if (toggle && toggle.parentNode) {
          toggle.parentNode.removeChild(toggle);
        }
      });
      setTheme(root.getAttribute('data-theme') || initialTheme);
      window.libraryManageEnsureStudentNotifications();
      return;
    }

    var toggle = ensureDesktopHeaderToggle() || ensureDesktopFooterToggle();
    if (!toggle) {
      toggle = document.querySelector('.theme-toggle');
      if (!toggle) {
        toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'theme-toggle';
        toggle.innerHTML = '<span class="theme-toggle-icon" aria-hidden="true"></span><span class="theme-toggle-text"></span>';

        var topbarNav = document.querySelector('.topbar-nav');
        if (topbarNav) {
          var homeLink = topbarNav.querySelector('a[href*="/index.php"], a[href$="index.php"], a[href="/librarymanage/index.php"]');
          var logoutLink = topbarNav.querySelector('a[href*="logout.php"]');
          if (homeLink && homeLink.parentNode === topbarNav) {
            homeLink.insertAdjacentElement('afterend', toggle);
          } else if (logoutLink) {
            topbarNav.insertBefore(toggle, logoutLink);
          } else {
            topbarNav.appendChild(toggle);
          }
        } else {
          document.body.appendChild(toggle);
        }
      }
    }

    setTheme(root.getAttribute('data-theme') || initialTheme);
    window.libraryManageEnsureStudentNotifications();
  });

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.theme-toggle');
    if (!button) {
      return;
    }

    event.preventDefault();
    toggleTheme();
  });
})();
