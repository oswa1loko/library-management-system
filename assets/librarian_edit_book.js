(function () {
  var totalInput = document.getElementById('qty_total');
  var availableInput = document.getElementById('qty_available');
  if (totalInput && availableInput) {
    var syncAvailableBounds = function () {
      var total = parseInt(totalInput.value || '0', 10);
      var available = parseInt(availableInput.value || '0', 10);

      if (total < 0) {
        total = 0;
        totalInput.value = 0;
      }

      availableInput.max = String(total);

      if (available > total) {
        availableInput.value = total;
      }

      if (available < 0 || Number.isNaN(available)) {
        availableInput.value = 0;
      }
    };

    totalInput.addEventListener('input', syncAvailableBounds);
    availableInput.addEventListener('input', syncAvailableBounds);
    syncAvailableBounds();
  }

  var coverInput = document.getElementById('cover');
  var preview = document.getElementById('edit-cover-preview');
  if (!coverInput || !preview) {
    return;
  }

  coverInput.addEventListener('change', function () {
    var file = coverInput.files && coverInput.files[0] ? coverInput.files[0] : null;
    if (!file) {
      preview.removeAttribute('src');
      preview.hidden = true;
      return;
    }

    var reader = new FileReader();
    reader.onload = function (event) {
      preview.src = event.target && event.target.result ? event.target.result : '';
      preview.hidden = !preview.src;
    };
    reader.readAsDataURL(file);
  });
})();
