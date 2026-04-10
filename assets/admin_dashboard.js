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

  root.querySelectorAll('[data-analytics-print-open]').forEach(function (button) {
    if (button.dataset.analyticsPrintBound === '1') {
      return;
    }

    button.dataset.analyticsPrintBound = '1';
    button.addEventListener('click', function () {
      var modal = document.querySelector('[data-analytics-print-modal]');
      var previewHost = modal ? modal.querySelector('[data-analytics-print-preview]') : null;
      var panel = document.querySelector('[data-filter-panel][data-ajax-panel-shell="admin-analytics-year"]');

      if (!modal || !previewHost || !panel) {
        window.print();
        return;
      }

      var previewClone = panel.cloneNode(true);
      var periodLabel = panel.querySelector('.analytics-print-head .code-pill');
      var generatedAt = new Date().toLocaleString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
      });
      var reportHead = document.createElement('div');

      reportHead.className = 'analytics-print-report-head';
      reportHead.innerHTML =
        '<div>' +
          '<p class="analytics-print-report-kicker">Library Analytics Report</p>' +
          '<h4 class="analytics-print-report-title">Borrowing Performance Summary</h4>' +
        '</div>' +
        '<div class="analytics-print-report-meta">' +
          '<span><strong>Period:</strong> ' + (periodLabel ? periodLabel.textContent.trim() : 'Current selection') + '</span>' +
          '<span><strong>Generated:</strong> ' + generatedAt + '</span>' +
        '</div>';

      previewClone.classList.add('analytics-print-report-body');
      previewClone.removeAttribute('data-filter-panel');
      previewClone.removeAttribute('data-ajax-panel-shell');
      previewClone.querySelectorAll('[data-ajax-filter-form], .analytics-print-actions').forEach(function (element) {
        element.remove();
      });
      previewClone.querySelectorAll('[data-ajax-filter-link], [data-preserve-scroll]').forEach(function (link) {
        link.removeAttribute('href');
      });

      previewHost.innerHTML = '';
      previewHost.appendChild(reportHead);
      previewHost.appendChild(previewClone);
      modal.hidden = false;
      document.body.classList.add('modal-open');
    });
  });
}

function closeAnalyticsPrintModal() {
  var modal = document.querySelector('[data-analytics-print-modal]');
  var previewHost = modal ? modal.querySelector('[data-analytics-print-preview]') : null;

  if (!modal) {
    return;
  }

  modal.hidden = true;
  if (previewHost) {
    previewHost.innerHTML = '';
  }
  document.body.classList.remove('modal-open');
}

document.addEventListener('DOMContentLoaded', function () {
  initAdminAnalytics(document);

  document.querySelectorAll('[data-analytics-print-close]').forEach(function (button) {
    if (button.dataset.analyticsPrintBound === '1') {
      return;
    }

    button.dataset.analyticsPrintBound = '1';
    button.addEventListener('click', closeAnalyticsPrintModal);
  });

  var printNowButton = document.querySelector('[data-analytics-print-now]');
  if (printNowButton && printNowButton.dataset.analyticsPrintBound !== '1') {
    printNowButton.dataset.analyticsPrintBound = '1';
    printNowButton.addEventListener('click', function () {
      closeAnalyticsPrintModal();
      window.print();
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeAnalyticsPrintModal();
    }
  });
});
