(function () {
  var addQtyInput = document.getElementById('qty');
  if (addQtyInput) {
    addQtyInput.addEventListener('input', function () {
      if (parseInt(addQtyInput.value || '0', 10) < 1) {
        addQtyInput.value = 1;
      }
    });
  }

  var coverInputs = Array.prototype.slice.call(document.querySelectorAll('input[type="file"][name="cover"]'));
  coverInputs.forEach(function (input) {
    input.addEventListener('change', function () {
      var fileName = input.files && input.files[0] ? input.files[0].name : '';
      if (!fileName) {
        return;
      }

      var existingNote = input.parentElement.querySelector('.js-file-note');
      if (!existingNote) {
        existingNote = document.createElement('div');
        existingNote.className = 'muted js-file-note meta-top';
        input.parentElement.appendChild(existingNote);
      }

      existingNote.textContent = 'Selected file: ' + fileName;
    });
  });

  var bindPreview = function (inputId, previewId) {
    var input = document.getElementById(inputId);
    var preview = document.getElementById(previewId);
    if (!input || !preview) {
      return;
    }

    input.addEventListener('change', function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
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
  };

  bindPreview('cover', 'add-cover-preview');

  var searchInput = document.getElementById('search');
  var categoryFilter = document.getElementById('category_filter');
  var rows = Array.prototype.slice.call(document.querySelectorAll('[data-book-row]'));
  var emptyState = document.getElementById('client-filter-empty');
  if (!searchInput || rows.length === 0) {
    return;
  }

  var applyClientFilter = function () {
    var term = (searchInput.value || '').trim().toLowerCase();
    var category = categoryFilter ? (categoryFilter.value || '').trim().toLowerCase() : '';
    var visibleCount = 0;

    rows.forEach(function (row) {
      var title = row.getAttribute('data-title') || '';
      var author = row.getAttribute('data-author') || '';
      var rowCategory = row.getAttribute('data-category') || '';
      var matchesSearch = term === '' || title.indexOf(term) !== -1 || author.indexOf(term) !== -1;
      var matchesCategory = category === '' || rowCategory === category;
      var isVisible = matchesSearch && matchesCategory;

      row.style.display = isVisible ? '' : 'none';
      if (isVisible) {
        visibleCount += 1;
      }
    });

    if (emptyState) {
      emptyState.classList.toggle('hidden', visibleCount !== 0);
    }
  };

  searchInput.addEventListener('input', applyClientFilter);
  if (categoryFilter) {
    categoryFilter.addEventListener('change', applyClientFilter);
  }
  applyClientFilter();
})();
