(function () {
  function initAddBookModal() {
    var addBookModal = document.querySelector('[data-book-add-modal]');
    var openAddBookModalButtons = Array.prototype.slice.call(document.querySelectorAll('[data-open-book-add-modal]'));
    var closeAddBookModalButtons = Array.prototype.slice.call(document.querySelectorAll('[data-close-book-add-modal]'));

    var setAddBookModalState = function (isOpen) {
      if (!addBookModal) {
        return;
      }

      addBookModal.hidden = !isOpen;
      document.body.classList.toggle('modal-open', isOpen);
    };

    openAddBookModalButtons.forEach(function (button) {
      if (button.dataset.booksModalBound === '1') {
        return;
      }

      button.dataset.booksModalBound = '1';
      button.addEventListener('click', function () {
        setAddBookModalState(true);
      });
    });

    closeAddBookModalButtons.forEach(function (button) {
      if (button.dataset.booksModalBound === '1') {
        return;
      }

      button.dataset.booksModalBound = '1';
      button.addEventListener('click', function () {
        setAddBookModalState(false);
      });
    });

    if (addBookModal && !addBookModal.hidden) {
      document.body.classList.add('modal-open');
    }
  }

  function initAddBookFormTools() {
    var addQtyInput = document.getElementById('qty');
    if (addQtyInput && addQtyInput.dataset.booksQtyBound !== '1') {
      addQtyInput.dataset.booksQtyBound = '1';
      addQtyInput.addEventListener('input', function () {
        if (parseInt(addQtyInput.value || '0', 10) < 1) {
          addQtyInput.value = 1;
        }
      });
    }

    var coverInputs = Array.prototype.slice.call(document.querySelectorAll('input[type="file"][name="cover"]'));
    coverInputs.forEach(function (input) {
      if (input.dataset.booksCoverBound === '1') {
        return;
      }

      input.dataset.booksCoverBound = '1';
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

    var coverInput = document.getElementById('cover');
    var coverPreview = document.getElementById('add-cover-preview');
    if (coverInput && coverPreview && coverInput.dataset.booksPreviewBound !== '1') {
      coverInput.dataset.booksPreviewBound = '1';
      coverInput.addEventListener('change', function () {
        var file = coverInput.files && coverInput.files[0] ? coverInput.files[0] : null;
        if (!file) {
          coverPreview.removeAttribute('src');
          coverPreview.hidden = true;
          return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
          coverPreview.src = event.target && event.target.result ? event.target.result : '';
          coverPreview.hidden = !coverPreview.src;
        };
        reader.readAsDataURL(file);
      });
    }
  }

  function initManageBooksSearch(root) {
    var scope = root || document;
    var filterForm = scope.querySelector('[data-ajax-filter-form]');
    var searchInput = scope.querySelector('#search');
    var catalogFilter = scope.querySelector('#catalog_filter');
    var bookFilter = scope.querySelector('[data-librarian-book-filter]');
    var rows = Array.prototype.slice.call(scope.querySelectorAll('[data-book-row]'));

    if (!filterForm || !searchInput || !catalogFilter || !bookFilter || rows.length === 0 || searchInput.dataset.booksSearchBound === '1') {
      return;
    }

    var normalizeValue = function (value) {
      return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    };

    var buildUrl = function () {
      var formData = new FormData(filterForm);
      var params = new URLSearchParams();

      formData.forEach(function (value, key) {
        if (String(value).trim() !== '') {
          params.set(key, String(value));
        }
      });

      return 'manage_books.php' + (params.toString() ? '?' + params.toString() : '');
    };

    var findExactMatches = function (value) {
      var normalizedValue = normalizeValue(value);
      if (!normalizedValue) {
        return [];
      }

      return rows.filter(function (row) {
        var title = normalizeValue(row.getAttribute('data-title') || '');
        var author = normalizeValue(row.getAttribute('data-author') || '');
        return title === normalizedValue || author === normalizedValue;
      });
    };

    searchInput.dataset.booksSearchBound = '1';
    searchInput.addEventListener('input', function () {
      var term = searchInput.value.trim();

      if (term === '') {
        bookFilter.value = '';
        catalogFilter.value = '';
        window.location.assign(buildUrl());
        return;
      }

      var exactMatches = findExactMatches(term);
      if (exactMatches.length !== 1) {
        bookFilter.value = '';
        return;
      }

      var matchedRow = exactMatches[0];
      bookFilter.value = String(matchedRow.getAttribute('data-book-id') || '').trim();
      catalogFilter.value = String(matchedRow.getAttribute('data-book-catalog-id') || '').trim();
      window.location.assign(buildUrl());
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initAddBookModal();
    initAddBookFormTools();
    initManageBooksSearch(document);
  });

  document.addEventListener('adminAjaxPanel:updated', function (event) {
    if (!event.detail || event.detail.shellKey !== 'librarian-books-records-panel') {
      return;
    }

    initManageBooksSearch(event.detail.shell);
  });
})();
