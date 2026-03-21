(function () {
  var root = document.querySelector('[data-desk-tabs]');
  if (!root) {
    return;
  }

  var triggers = Array.prototype.slice.call(root.querySelectorAll('[data-desk-tab-trigger]'));
  var panels = Array.prototype.slice.call(root.querySelectorAll('[data-desk-tab-panel]'));
  if (!triggers.length || !panels.length) {
    return;
  }

  function activateTab(tabName) {
    triggers.forEach(function (trigger) {
      var isActive = trigger.getAttribute('data-desk-tab-trigger') === tabName;
      trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
      trigger.tabIndex = isActive ? 0 : -1;
    });

    panels.forEach(function (panel) {
      var isActive = panel.getAttribute('data-desk-tab-panel') === tabName;
      panel.hidden = !isActive;
      panel.classList.toggle('is-active', isActive);
    });
  }

  triggers.forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      activateTab(trigger.getAttribute('data-desk-tab-trigger'));
    });

    trigger.addEventListener('keydown', function (event) {
      var currentIndex = triggers.indexOf(trigger);
      if (currentIndex === -1) {
        return;
      }

      if (event.key === 'ArrowRight') {
        event.preventDefault();
        triggers[(currentIndex + 1) % triggers.length].focus();
      } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        triggers[(currentIndex - 1 + triggers.length) % triggers.length].focus();
      }
    });
  });

  activateTab(root.getAttribute('data-default-tab') || triggers[0].getAttribute('data-desk-tab-trigger'));
})();
