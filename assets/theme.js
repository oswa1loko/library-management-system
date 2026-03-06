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

  function setTheme(theme) {
    root.setAttribute('data-theme', theme);
    storeTheme(theme);

    var toggle = document.querySelector('.theme-toggle');
    if (!toggle) {
      return;
    }

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

  var initialTheme = getStoredTheme() || 'dark';
  setTheme(initialTheme);

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body || document.querySelector('.theme-toggle')) {
      setTheme(root.getAttribute('data-theme') || initialTheme);
      return;
    }

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'theme-toggle';
    toggle.innerHTML = '<span class="theme-toggle-icon" aria-hidden="true"></span><span class="theme-toggle-text"></span>';
    toggle.addEventListener('click', function () {
      var currentTheme = root.getAttribute('data-theme') || 'dark';
      var nextTheme = currentTheme === 'light' ? 'dark' : 'light';
      setTheme(nextTheme);
    });

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

    setTheme(root.getAttribute('data-theme') || initialTheme);
  });
})();
