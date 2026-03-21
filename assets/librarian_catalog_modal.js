(function () {
  var modal = document.querySelector('[data-catalog-modal]');
  if (!modal) {
    return;
  }

  var closeLink = modal.querySelector('.catalog-modal-backdrop');
  var originalBodyOverflow = document.body.style.overflow;

  document.body.style.overflow = 'hidden';

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }

    if (closeLink instanceof HTMLAnchorElement) {
      window.location.href = closeLink.href;
    }
  });

  window.addEventListener('beforeunload', function () {
    document.body.style.overflow = originalBodyOverflow;
  });
})();
