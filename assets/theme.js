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
    var panel = document.querySelector('.student-notification-panel');
    var shouldKeepNotificationPanelOpen = !!(panel && panel.dataset.panelOpen === 'true');
    setTheme(nextTheme);
    if (!shouldKeepNotificationPanelOpen) {
      return;
    }

    window.setTimeout(function () {
      var activePanel = ensureStudentNotificationPanel();
      setStudentNotificationPanelOpen(activePanel, true);
      window.requestAnimationFrame(function () {
        setStudentNotificationPanelOpen(activePanel, true);
      });
    }, 0);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function withNoCache(url) {
    var separator = url.indexOf('?') === -1 ? '?' : '&';
    return url + separator + '_=' + Date.now();
  }

  var memberNotificationLoadSequence = 0;

  function getMemberNotificationConfig() {
    if (window.location.pathname.indexOf('/admin/') !== -1) {
      return {
        feedUrl: '/librarymanage/admin/notifications_feed.php',
        openUrl: '/librarymanage/admin/notification_open.php'
      };
    }

    if (window.location.pathname.indexOf('/librarian/') !== -1) {
      return {
        feedUrl: '/librarymanage/librarian/notifications_feed.php',
        openUrl: '/librarymanage/librarian/notification_open.php'
      };
    }

    if (window.location.pathname.indexOf('/student/') !== -1) {
      return {
        feedUrl: '/librarymanage/student/notifications_feed.php',
        openUrl: '/librarymanage/student/notification_open.php'
      };
    }

    if (window.location.pathname.indexOf('/faculty/') !== -1) {
      return {
        feedUrl: '/librarymanage/faculty/notifications_feed.php',
        openUrl: '/librarymanage/faculty/notification_open.php'
      };
    }

    return null;
  }

  function isFullMemberNotificationsPage() {
    return (
      window.location.pathname.indexOf('/student/notifications.php') !== -1 ||
      window.location.pathname.indexOf('/faculty/notifications.php') !== -1 ||
      window.location.pathname.indexOf('/librarian/notifications.php') !== -1
    );
  }

  function buildMemberNotificationOpenUrl(baseUrl, destinationUrl, notificationId, borrowId) {
    var url = new window.URL(baseUrl, window.location.origin);
    url.searchParams.set('redirect', destinationUrl);
    if (Number(notificationId || 0) > 0) {
      url.searchParams.set('notification_id', String(Number(notificationId || 0)));
    }
    if (Number(borrowId || 0) > 0) {
      url.searchParams.set('borrow_id', String(Number(borrowId || 0)));
    }
    return url.toString();
  }

  function ensureStudentNotificationPanel() {
    var panel = document.querySelector('.student-notification-panel');
    if (panel) {
      return panel;
    }

    panel = document.createElement('div');
    panel.className = 'student-notification-panel';
    panel.hidden = true;
    panel.dataset.panelOpen = 'false';
    panel.dataset.notificationsLoaded = 'false';
    panel.dataset.notificationsPrefetched = 'false';
    panel.innerHTML =
      '<div class="student-notification-panel-head">' +
        '<strong>Notifications</strong>' +
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
      var themeToggle = event.target.closest('.theme-toggle');
      if (panel.contains(event.target) || shortcut || themeToggle) {
        return;
      }

      setStudentNotificationPanelOpen(panel, false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        setStudentNotificationPanelOpen(panel, false);
      }
    });

    panel.addEventListener('click', function (event) {
      var notificationItem = event.target.closest('.student-notification-item[data-destination-url]');
      if (!notificationItem) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      openStudentNotificationDestination(panel, notificationItem);
    });

    return panel;
  }

  function setStudentNotificationPanelOpen(panel, open) {
    if (!panel) {
      return;
    }

    panel.hidden = !open;
    panel.dataset.panelOpen = open ? 'true' : 'false';
  }

  function isRecentNotificationItem(item) {
    if (!item) {
      return false;
    }

    if (String(item.kind || '') !== 'notification') {
      return true;
    }

    var createdAtRaw = String(item.created_at_raw || '');
    if (!createdAtRaw) {
      return false;
    }

    var createdAt = new Date(createdAtRaw);
    if (window.Number.isNaN(createdAt.getTime())) {
      return false;
    }

    return (Date.now() - createdAt.getTime()) < 86400000;
  }

  function renderStudentNotificationItem(item) {
    var severityClass = item.severity === 'critical'
      ? 'unpaid'
      : (item.severity === 'warning' ? 'due' : 'approved');
    var destinationUrl = String(item.destination_url || '');
    var destinationLabel = String(item.destination_label || '');
    var categoryLabel = String(item.category_label || 'Update');
    var relativeTime = String(item.relative_time || item.created_at || '-');
    var isLinked = destinationUrl !== '';
    var unreadChip = item.is_read
      ? '<span class="chip student-notification-read">Read</span>'
      : '<span class="chip">Unread</span>';

    return (
      '<div class="student-notification-item' + (isLinked ? ' is-linked' : '') + '"' +
        (isLinked ? ' data-destination-url="' + escapeHtml(destinationUrl) + '"' : '') +
        (Number(item.id || 0) > 0 ? ' data-notification-id="' + Number(item.id || 0) + '"' : '') +
        (Number(item.borrow_id || 0) > 0 ? ' data-notification-borrow-id="' + Number(item.borrow_id || 0) + '"' : '') +
        ' data-notification-unread="' + (item.is_read ? 'false' : 'true') + '">' +
        '<div class="student-notification-kicker">' +
          '<span class="chip student-notification-category-chip">' + escapeHtml(categoryLabel) + '</span>' +
          '<span class="student-notification-relative-time">' + escapeHtml(relativeTime) + '</span>' +
        '</div>' +
        '<div class="student-notification-title">' +
          '<span class="status-dot ' + severityClass + '"></span>' +
          '<strong>' + escapeHtml(item.title || 'Notification') + '</strong>' +
          unreadChip +
        '</div>' +
        '<div class="student-notification-copy">' + escapeHtml(item.body || '') + '</div>' +
        '<div class="student-notification-meta">' +
          '<span>' + escapeHtml(item.created_at || '-') + '</span>' +
          (isLinked ? '<span class="student-notification-link-hint">' + escapeHtml(destinationLabel || 'Open item') + '</span>' : '') +
        '</div>' +
      '</div>'
    );
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

    var grouped = {
      recent: [],
      earlier: []
    };

    items.forEach(function (item) {
      if (isRecentNotificationItem(item)) {
        grouped.recent.push(item);
      } else {
        grouped.earlier.push(item);
      }
    });

    var sections = [];
    if (grouped.recent.length > 0) {
      sections.push(
        '<section class="student-notification-section">' +
          '<div class="student-notification-section-label">New</div>' +
          grouped.recent.map(renderStudentNotificationItem).join('') +
        '</section>'
      );
    }
    if (grouped.earlier.length > 0) {
      sections.push(
        '<section class="student-notification-section">' +
          '<div class="student-notification-section-label">Earlier</div>' +
          grouped.earlier.map(renderStudentNotificationItem).join('') +
        '</section>'
      );
    }

    body.innerHTML = sections.join('');
  }

  function setNotificationItemReadState(notificationItem) {
    if (!notificationItem) {
      return;
    }

    notificationItem.setAttribute('data-notification-unread', 'false');
    var chip = notificationItem.querySelector('.student-notification-title .chip');
    if (chip) {
      chip.textContent = 'Read';
      chip.classList.add('student-notification-read');
    }
  }

  function openStudentNotificationDestination(panel, notificationItem) {
    if (!notificationItem) {
      return;
    }

    var destinationUrl = notificationItem.getAttribute('data-destination-url') || '';
    if (!destinationUrl) {
      return;
    }

    var notificationId = Number(notificationItem.getAttribute('data-notification-id') || 0);
    var borrowId = Number(notificationItem.getAttribute('data-notification-borrow-id') || 0);
    var isUnread = notificationItem.getAttribute('data-notification-unread') === 'true';
    var config = getMemberNotificationConfig();
    var navigate = function () {
      window.location.assign(destinationUrl);
    };

    if (isUnread && config && config.openUrl && (notificationId > 0 || borrowId > 0)) {
      setNotificationItemReadState(notificationItem);
      window.location.assign(buildMemberNotificationOpenUrl(config.openUrl, destinationUrl, notificationId, borrowId));
      return;
    }

    if (isUnread && notificationId > 0) {
      setNotificationItemReadState(notificationItem);
      markStudentNotificationRead(panel, notificationId, { reload: false })
        .catch(function () {
          // Navigate even if the read update fails so the click still feels reliable.
        })
        .finally(navigate);
      return;
    }

    navigate();
  }

  function updateStudentNotificationBadges(unreadCount) {
    var count = Number(unreadCount || 0);
    document.querySelectorAll('.student-header-notification-badge').forEach(function (badge) {
      if (count <= 0) {
        badge.textContent = '';
        badge.hidden = true;
        return;
      }

      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = false;
    });
  }

  function loadStudentNotifications(panel) {
    var options = arguments.length > 1 && arguments[1] ? arguments[1] : {};
    var config = getMemberNotificationConfig();
    var body = panel.querySelector('.student-notification-panel-body');
    var shouldForce = options.force === true;
    var isBackgroundLoad = options.background === true;

    if (!shouldForce && panel.dataset.notificationsLoaded === 'true') {
      return Promise.resolve();
    }

    if (panel._notificationLoadPromise) {
      return panel._notificationLoadPromise;
    }

    var requestId = String(++memberNotificationLoadSequence);
    panel.dataset.notificationRequestId = requestId;
    if (body && !isBackgroundLoad) {
      body.innerHTML = '<div class="student-notification-empty">Loading notifications...</div>';
    }

    if (!config) {
      if (body) {
        body.innerHTML = '<div class="student-notification-empty">Notifications are not available here.</div>';
      }
      return Promise.resolve();
    }

    panel._notificationLoadPromise = window.fetch(withNoCache(config.feedUrl), {
      cache: 'no-store',
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

        if (panel.dataset.notificationRequestId !== requestId) {
          return;
        }

        panel.dataset.notificationsLoaded = 'true';
        renderStudentNotifications(panel, payload);
        updateStudentNotificationBadges(payload.unread_count || 0);
      })
      .catch(function () {
        if (panel.dataset.notificationRequestId !== requestId) {
          return;
        }

        if (body) {
          body.innerHTML = '<div class="student-notification-empty">Unable to load notifications right now.</div>';
        }
      })
      .finally(function () {
        panel._notificationLoadPromise = null;
      });

    return panel._notificationLoadPromise;
  }

  function markStudentNotificationRead(panel, notificationId, options) {
    var config = getMemberNotificationConfig();
    var formData = new window.URLSearchParams();
    var shouldReload = !options || options.reload !== false;
    formData.set('action', 'mark_read');
    formData.set('id', String(Number(notificationId || 0)));

    if (!config) {
      return Promise.reject(new Error('Notifications are not available here.'));
    }

    return window.fetch(config.feedUrl, {
      method: 'POST',
      keepalive: true,
      cache: 'no-store',
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
        if (shouldReload) {
          panel.dataset.notificationsLoaded = 'false';
          loadStudentNotifications(panel, { force: true });
        }
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
      setStudentNotificationPanelOpen(panel, shouldOpen);
      if (shouldOpen) {
        loadStudentNotifications(panel);
      }
    });
    shortcut.dataset.notificationBound = 'true';
    shortcut.setAttribute('data-notification-role', (getMemberNotificationConfig() || {}).role || 'member');
    if (panel.dataset.notificationsPrefetched !== 'true') {
      panel.dataset.notificationsPrefetched = 'true';
      loadStudentNotifications(panel, { background: true });
    }
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

    notificationLink.classList.toggle('is-active', isFullMemberNotificationsPage());
    if (isFullMemberNotificationsPage()) {
      notificationLink.setAttribute('aria-current', 'page');
      notificationLink.setAttribute('data-tooltip', 'Notifications');
    } else {
      notificationLink.removeAttribute('aria-current');
    }

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

    notificationButton.classList.toggle('is-active', isFullMemberNotificationsPage());
    if (isFullMemberNotificationsPage()) {
      notificationButton.setAttribute('aria-current', 'page');
    } else {
      notificationButton.removeAttribute('aria-current');
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
          var homeLink = topbarNav.querySelector('a[href*="/librarymanage/index.php"], a[href$="index.php"], a[href="/librarymanage/index.php"]');
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

