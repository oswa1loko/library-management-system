document.addEventListener('DOMContentLoaded', () => {
  initBorrowSelection();
  initReturnBatchSelection();
});

function initBorrowSelection() {
  const searchInput = document.querySelector('[data-book-search]');
  if (!searchInput) {
    return;
  }

  const options = Array.from(document.querySelectorAll('[data-book-option]'));
  const emptyState = document.querySelector('[data-book-empty]');
  const categorySelect = document.querySelector('[data-book-category]');
  const groups = Array.from(document.querySelectorAll('[data-book-group]'));
  const modal = document.querySelector('[data-book-modal]');
  const modalTriggers = Array.from(document.querySelectorAll('[data-book-trigger]'));
  const modalCloseButtons = Array.from(document.querySelectorAll('[data-book-modal-close]'));
  const modalIdInput = document.querySelector('[data-book-modal-id]');
  const modalTitle = document.querySelector('[data-book-modal-title]');
  const modalBookTitle = document.querySelector('[data-book-modal-book-title]');
  const modalBookMeta = document.querySelector('[data-book-modal-book-meta]');
  const modalAvailable = document.querySelector('[data-book-modal-available]');
  const modalCover = document.querySelector('[data-book-modal-cover]');
  const modalCoverPlaceholder = document.querySelector('[data-book-modal-cover-placeholder]');
  const modalQty = document.querySelector('[data-book-modal-qty]');
  let navigationTimer = null;

  const buildFilterUrl = () => {
    const url = new URL(window.location.href);
    const query = searchInput.value.trim();
    const selectedCategory = categorySelect ? categorySelect.value.trim() : '';

    if (query !== '') {
      url.searchParams.set('search', query);
    } else {
      url.searchParams.delete('search');
    }

    if (selectedCategory !== '') {
      url.searchParams.set('category', selectedCategory);
    } else {
      url.searchParams.delete('category');
    }

    return `${url.pathname}${url.search}${url.hash}`;
  };

  const navigateWithFilters = () => {
    const nextUrl = buildFilterUrl();
    const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;

    if (nextUrl !== currentUrl) {
      window.location.assign(nextUrl);
    }
  };

  const queueFilterNavigation = () => {
    window.clearTimeout(navigationTimer);
    navigationTimer = window.setTimeout(navigateWithFilters, 450);
  };

  const applyFilter = () => {
    const query = searchInput.value.trim().toLowerCase();
    const selectedCategory = categorySelect ? categorySelect.value.trim().toLowerCase() : '';
    let visibleCount = 0;

    options.forEach((option) => {
      const haystack = (option.getAttribute('data-book-search-text') || '').toLowerCase();
      const categoryValue = (option.getAttribute('data-book-category-value') || '').toLowerCase();
      const matchesQuery = query === '' || haystack.includes(query);
      const matchesCategory = selectedCategory === '' || categoryValue === selectedCategory;
      const isVisible = matchesQuery && matchesCategory;
      option.hidden = !isVisible;

      if (isVisible) {
        visibleCount += 1;
      }
    });

    if (emptyState) {
      emptyState.hidden = visibleCount > 0;
    }

    groups.forEach((group) => {
      const groupOptions = Array.from(group.querySelectorAll('[data-book-option]'));
      group.hidden = !groupOptions.some((option) => !option.hidden);
    });
  };

  const closeModal = () => {
    if (!modal) {
      return;
    }

    modal.hidden = true;
    document.body.classList.remove('modal-open');
  };

  const populateQtyOptions = (maxQty) => {
    if (!modalQty) {
      return;
    }

    modalQty.innerHTML = '';
    for (let qty = 1; qty <= maxQty; qty += 1) {
      const option = document.createElement('option');
      option.value = String(qty);
      option.textContent = `${qty} cop${qty === 1 ? 'y' : 'ies'}`;
      modalQty.appendChild(option);
    }
  };

  const openModal = (trigger) => {
    if (!modal || !modalIdInput || !modalTitle || !modalBookTitle || !modalBookMeta || !modalQty) {
      return;
    }

    const bookId = trigger.getAttribute('data-book-id') || '';
    const title = trigger.getAttribute('data-book-title') || 'Book';
    const author = trigger.getAttribute('data-book-author') || '';
    const category = trigger.getAttribute('data-book-category-label') || '';
    const coverPath = trigger.getAttribute('data-book-cover') || '';
    const availableCopies = Math.max(1, parseInt(trigger.getAttribute('data-book-available') || '1', 10));
    const maxQty = Math.max(1, parseInt(trigger.getAttribute('data-book-max-qty') || '1', 10));

    modalIdInput.value = bookId;
    modalTitle.textContent = `Request ${title}`;
    modalBookTitle.textContent = title;
    modalBookMeta.textContent = [author, category].filter(Boolean).join(' - ');
    if (modalAvailable) {
      modalAvailable.textContent = `${availableCopies} available cop${availableCopies === 1 ? 'y' : 'ies'}`;
    }
    populateQtyOptions(maxQty);

    if (modalCover && modalCoverPlaceholder) {
      if (coverPath !== '') {
        modalCover.src = `/librarymanage/${coverPath}`;
        modalCover.alt = title;
        modalCover.hidden = false;
        modalCoverPlaceholder.hidden = true;
      } else {
        modalCover.src = '';
        modalCover.alt = '';
        modalCover.hidden = true;
        modalCoverPlaceholder.hidden = false;
      }
    }

    modal.hidden = false;
    document.body.classList.add('modal-open');
  };

  searchInput.addEventListener('input', () => {
    applyFilter();
    queueFilterNavigation();
  });

  if (categorySelect) {
    categorySelect.addEventListener('change', () => {
      applyFilter();
      navigateWithFilters();
    });
  }

  modalTriggers.forEach((trigger) => {
    trigger.addEventListener('click', () => openModal(trigger));
  });

  modalCloseButtons.forEach((node) => {
    node.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeModal();
    }
  });

  applyFilter();
}

function initReturnBatchSelection() {
  const batchForms = Array.from(document.querySelectorAll('[data-return-batch-form]'));
  if (batchForms.length === 0) {
    return;
  }

  batchForms.forEach((form) => {
    const checkboxes = Array.from(form.querySelectorAll('[data-return-batch-checkbox]'));
    const submitButton = form.querySelector('[data-return-batch-submit]');
    const note = form.querySelector('[data-return-batch-note]');
    const isSingleReadyBatch = form.hasAttribute('data-return-batch-single');

    const updateBatchState = () => {
      const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
      const effectiveSelectedCount = isSingleReadyBatch ? 1 : selectedCount;

      if (submitButton) {
        submitButton.disabled = effectiveSelectedCount === 0;
        submitButton.textContent = isSingleReadyBatch ? 'Request Return' : 'Request Return for Selected';
      }

      if (note) {
        if (checkboxes.length === 0) {
          note.textContent = isSingleReadyBatch
            ? 'This batch has only one returnable item, so the return request is ready to send.'
            : 'All items in this batch are already waiting for librarian confirmation.';
        } else if (selectedCount === 0) {
          note.textContent = 'Select at least one borrowed item in this batch to send a return request.';
        } else if (selectedCount === 1) {
          note.textContent = '1 item ready for return request. The librarian will confirm when the physical book is received.';
        } else {
          note.textContent = `${selectedCount} items ready for return request. The librarian will confirm only the books physically handed over.`;
        }
      }
    };

    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', updateBatchState);
    });

    updateBatchState();
  });
}
