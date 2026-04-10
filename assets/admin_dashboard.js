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

  root.querySelectorAll('[data-donut-chart]').forEach(function (chart) {
    if (chart.dataset.analyticsDonutBound === '1') {
      return;
    }

    chart.dataset.analyticsDonutBound = '1';

    var panel = chart.closest('.analytics-donut-panel');
    if (!panel) {
      return;
    }

    var centerLabel = panel.querySelector('[data-donut-center-label]');
    var centerTitle = panel.querySelector('[data-donut-center-title]');
    var centerMeta = panel.querySelector('[data-donut-center-meta]');
    var insight = panel.querySelector('[data-donut-insight]');
    var segments = Array.prototype.slice.call(panel.querySelectorAll('[data-donut-segment]'));

    if (!segments.length) {
      return;
    }

    function applySegmentState(segment, useSelectionLabel) {
      if (!segment) {
        return;
      }

      var label = segment.getAttribute('data-segment-label') || 'Selected title';
      var count = segment.getAttribute('data-segment-count') || '0';
      var percent = segment.getAttribute('data-segment-percent') || '0';
      var rank = segment.getAttribute('data-segment-rank') || '';
      var summary = segment.getAttribute('data-segment-insight') || '';
      var heading = useSelectionLabel
        ? (segment.getAttribute('data-segment-default-label') || 'Selected title')
        : 'Leading title';

      segments.forEach(function (item) {
        var isActive = item === segment;
        item.classList.toggle('is-active', isActive);
        item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });

      if (centerLabel) {
        centerLabel.textContent = heading;
      }

      if (centerTitle) {
        centerTitle.textContent = label;
        centerTitle.setAttribute('title', label);
      }

      if (centerMeta) {
        centerMeta.textContent = count + ' borrow' + (count === '1' ? '' : 's') + ' • ' + percent + '% of monthly total' + (rank ? ' • Rank #' + rank : '');
      }

      if (insight) {
        insight.textContent = summary;
      }
    }

    segments.forEach(function (segment) {
      segment.addEventListener('mouseenter', function () {
        applySegmentState(segment, true);
      });

      segment.addEventListener('focus', function () {
        applySegmentState(segment, true);
      });

      segment.addEventListener('click', function () {
        applySegmentState(segment, true);
      });
    });

    panel.addEventListener('mouseleave', function () {
      var active = panel.querySelector('[data-donut-segment].is-active') || segments[0];
      applySegmentState(active, false);
    });

    var defaultSegment = panel.querySelector('[data-donut-segment].is-active') || segments[0];
    applySegmentState(defaultSegment, false);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  initAdminAnalytics(document);
});

document.addEventListener('adminAjaxPanel:updated', function (event) {
  if (!event.detail || !event.detail.shell) {
    initAdminAnalytics(document);
    return;
  }

  initAdminAnalytics(event.detail.shell);
});
