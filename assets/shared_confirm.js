(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    var message = form.getAttribute('data-confirm');
    if (!message) {
      return;
    }

    var ok = window.confirm(message);
    if (!ok) {
      event.preventDefault();
    }
  });
})();
