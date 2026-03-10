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
  const selectedCount = document.querySelector('[data-book-selected-count]');
  const selectionNote = document.querySelector('[data-book-selection-note]');
  const clearButton = document.querySelector('[data-book-clear]');
  const submitButton = document.querySelector('[data-book-submit]');
  const categorySelect = document.querySelector('[data-book-category]');
  const limitSelect = document.querySelector('[data-book-limit]');
  const groups = Array.from(document.querySelectorAll('[data-book-group]'));
  const getCheckbox = (option) => option.querySelector('input[type="checkbox"]');
  const getQuantitySelect = (option) => option.querySelector('[data-book-quantity]');

  const getSelectedCopies = () => options.reduce((total, option) => {
    const checkbox = getCheckbox(option);
    const quantitySelect = getQuantitySelect(option);
    if (!checkbox || !checkbox.checked) {
      return total;
    }

    const quantity = quantitySelect ? Math.max(1, parseInt(quantitySelect.value || '1', 10)) : 1;
    return total + quantity;
  }, 0);

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
      const hasVisibleOption = groupOptions.some((option) => !option.hidden);
      group.hidden = !hasVisibleOption;
    });
  };

  const updateSelectionCount = () => {
    const selectedLimit = limitSelect ? Math.max(1, parseInt(limitSelect.value || '1', 10)) : options.length;
    let totalCopies = 0;

    options.forEach((option) => {
      const checkbox = getCheckbox(option);
      const quantitySelect = getQuantitySelect(option);
      if (!checkbox || !quantitySelect || !checkbox.checked) {
        return;
      }

      const availableCopies = Math.max(1, parseInt(quantitySelect.getAttribute('data-book-available') || '1', 10));
      const remainingAllowance = Math.max(1, selectedLimit - totalCopies);
      const maxAllowed = Math.max(1, Math.min(availableCopies, selectedLimit, remainingAllowance));
      const requestedQuantity = Math.max(1, parseInt(quantitySelect.value || '1', 10));

      if (requestedQuantity > maxAllowed) {
        quantitySelect.value = String(maxAllowed);
      }

      totalCopies += Math.max(1, parseInt(quantitySelect.value || '1', 10));
    });

    if (selectedCount) {
      const limitLabel = selectedLimit === 1 ? '1 book copy max' : `${selectedLimit} book copies max`;
      selectedCount.textContent = `${totalCopies} selected - ${limitLabel}`;
    }

    if (clearButton) {
      clearButton.disabled = totalCopies === 0;
    }

    if (submitButton) {
      submitButton.disabled = totalCopies === 0;
    }

    if (selectionNote) {
      if (totalCopies === 0) {
        selectionNote.textContent = 'Select one or more titles and set the quantity per title.';
      } else if (totalCopies >= selectedLimit) {
        selectionNote.textContent = 'Selection limit reached. Uncheck a title or lower a quantity to pick another book.';
      } else {
        const remainingCopies = selectedLimit - totalCopies;
        const remainingLabel = remainingCopies === 1 ? '1 more copy' : `${remainingCopies} more copies`;
        selectionNote.textContent = `You can still add ${remainingLabel} in this submission.`;
      }
    }

    options.forEach((option) => {
      const checkbox = getCheckbox(option);
      const quantitySelect = getQuantitySelect(option);
      if (!checkbox) {
        return;
      }

      option.classList.toggle('is-selected', Boolean(checkbox.checked));

      if (option.classList.contains('is-unavailable')) {
        checkbox.disabled = true;
        if (quantitySelect) {
          quantitySelect.disabled = true;
        }
        option.classList.remove('is-limit-locked');
        return;
      }

      if (quantitySelect) {
        const availableCopies = Math.max(1, parseInt(quantitySelect.getAttribute('data-book-available') || '1', 10));
        const otherSelectedCopies = Math.max(0, getSelectedCopies() - (checkbox.checked ? Math.max(1, parseInt(quantitySelect.value || '1', 10)) : 0));
        const remainingAllowance = Math.max(1, selectedLimit - otherSelectedCopies);
        const maxAllowed = Math.max(1, Math.min(availableCopies, selectedLimit, remainingAllowance));

        if (parseInt(quantitySelect.value || '1', 10) > maxAllowed) {
          quantitySelect.value = String(maxAllowed);
        }

        quantitySelect.disabled = !checkbox.checked;
        quantitySelect.querySelectorAll('option').forEach((optionNode) => {
          const optionValue = parseInt(optionNode.value || '1', 10);
          optionNode.hidden = optionValue > maxAllowed;
          optionNode.disabled = optionValue > maxAllowed;
        });
      }

      if (!checkbox.checked && getSelectedCopies() >= selectedLimit) {
        checkbox.disabled = true;
        option.classList.add('is-limit-locked');
      } else {
        checkbox.disabled = false;
        option.classList.remove('is-limit-locked');
      }
    });
  };

  const clearSelected = () => {
    options.forEach((option) => {
      const checkbox = getCheckbox(option);
      const quantitySelect = getQuantitySelect(option);
      if (checkbox && !option.classList.contains('is-unavailable')) {
        checkbox.checked = false;
      }
      if (quantitySelect) {
        quantitySelect.value = '1';
        quantitySelect.disabled = true;
      }
    });
    updateSelectionCount();
  };

  searchInput.addEventListener('input', applyFilter);

  if (categorySelect) {
    categorySelect.addEventListener('change', applyFilter);
  }

  if (limitSelect) {
    limitSelect.addEventListener('change', updateSelectionCount);
  }

  if (clearButton) {
    clearButton.addEventListener('click', clearSelected);
  }

  options.forEach((option) => {
    const checkbox = getCheckbox(option);
    const quantitySelect = getQuantitySelect(option);
    if (checkbox) {
      checkbox.addEventListener('change', updateSelectionCount);
    }
    if (quantitySelect) {
      quantitySelect.addEventListener('change', updateSelectionCount);
    }
  });

  applyFilter();
  updateSelectionCount();
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
