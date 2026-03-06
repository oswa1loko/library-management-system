(function () {
  var generatedAt = document.getElementById('printGeneratedAt');
  var printNowButton = document.getElementById('printNowButton');

  function updateGeneratedAt() {
    if (!generatedAt) {
      return;
    }

    var now = new Date();
    generatedAt.textContent = now.toLocaleString(undefined, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    });
  }

  if (printNowButton) {
    printNowButton.addEventListener('click', function () {
      updateGeneratedAt();
      window.print();
    });
  }

  updateGeneratedAt();
  window.addEventListener('beforeprint', updateGeneratedAt);
  window.addEventListener('load', function () {
    updateGeneratedAt();
    window.print();
  });
})();

