function initAdminAnalytics(root) {
  root.querySelectorAll('[data-preserve-scroll]').forEach(function (link) {
    if (link.dataset.ajaxBound === '1') {
      return;
    }

    link.dataset.ajaxBound = '1';
    link.addEventListener('click', function (event) {
      event.preventDefault();

      var href = link.getAttribute('href');
      if (!href) {
        return;
      }

      fetch(href, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.text();
        })
        .then(function (html) {
          var parser = new DOMParser();
          var doc = parser.parseFromString(html, 'text/html');
          var nextStack = doc.querySelector('.member-main .stack');
          var currentStack = document.querySelector('.member-main .stack');

          if (!nextStack || !currentStack) {
            window.location.href = href;
            return;
          }

          currentStack.innerHTML = nextStack.innerHTML;
          if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState({}, '', href);
          }
          initAdminAnalytics(document);
        })
        .catch(function () {
          window.location.href = href;
        });
    });
  });

  root.querySelectorAll('[data-weekly-chart]').forEach(function (chart) {
    if (chart.dataset.analyticsBound === '1') {
      return;
    }

    chart.dataset.analyticsBound = '1';
    var bars = Array.prototype.slice.call(chart.querySelectorAll('[data-week-bar]'));
    if (!bars.length) {
      return;
    }

    bars.forEach(function (bar, index) {
      var value = bar.querySelector('.analytics-week-bar-value');
      var targetHeight = parseFloat(bar.getAttribute('data-target-height') || '18');

      bar.style.height = '18px';
      bar.style.opacity = '0.22';
      bar.style.transform = 'translateY(8px)';

      if (value) {
        value.style.opacity = '0';
        value.style.transform = 'translateX(50%) translateY(8px)';
      }

      window.setTimeout(function () {
        bar.style.height = targetHeight + 'px';
        bar.style.opacity = '1';
        bar.style.transform = 'translateY(0)';

        if (value) {
          value.style.opacity = '1';
          value.style.transform = 'translateX(50%) translateY(0)';
        }
      }, 140 + (index * 110));
    });
  });
}

document.addEventListener('DOMContentLoaded', function () {
  initAdminAnalytics(document);
});
