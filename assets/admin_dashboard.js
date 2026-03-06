document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-chart-bar]').forEach(function (bar, index) {
    var value = parseInt(bar.getAttribute('data-value') || '0', 10);
    var max = parseInt(bar.getAttribute('data-max') || '1', 10);
    var height = max > 0 ? Math.max(12, Math.round((value / max) * 190)) : 12;

    bar.style.height = '12px';
    bar.style.opacity = '0.3';

    window.setTimeout(function () {
      bar.style.height = height + 'px';
      bar.style.opacity = '1';
    }, 120 + (index * 90));
  });
});

