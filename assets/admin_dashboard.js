document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-weekly-chart]').forEach(function (chart) {
    var bars = Array.prototype.slice.call(chart.querySelectorAll('[data-week-bar]'));
    if (!bars.length) {
      return;
    }

    bars.forEach(function (bar, index) {
      var value = parseInt(bar.getAttribute('data-value') || '0', 10);
      var max = parseInt(bar.getAttribute('data-max') || '1', 10);
      var height = max > 0 ? Math.max(18, Math.round((value / max) * 150)) : 18;

      bar.style.height = '18px';
      bar.style.opacity = '0.28';

      window.setTimeout(function () {
        bar.style.height = height + 'px';
        bar.style.opacity = '1';
      }, 140 + (index * 110));
    });
  });
});
