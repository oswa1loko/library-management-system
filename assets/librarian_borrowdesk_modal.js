(function () {
  var modal = document.querySelector('[data-desk-modal]');
  if (!modal) {
    return;
  }

  document.body.style.overflow = 'hidden';

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }

    var closeLink = modal.querySelector('.desk-modal-backdrop');
    if (closeLink instanceof HTMLAnchorElement) {
      window.location.href = closeLink.href;
    }
  });

  window.addEventListener('beforeunload', function () {
    document.body.style.overflow = '';
  });
})();
