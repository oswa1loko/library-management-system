document.addEventListener('DOMContentLoaded', () => {
  initBorrowSelection();
  initReturnBatchSelection();
});

function initBorrowSelection() {
  const searchInput = document.querySelector('[data-book-search]');
  if (!searchInput) {
    return;
  }

  const searchSuggestions = document.querySelector('[data-book-search-suggestions]');
  const searchOptions = Array.isArray(window.memberBookSearchOptions) ? window.memberBookSearchOptions : [];
  const initialFilterState = window.memberBookInitialFilter && typeof window.memberBookInitialFilter === 'object'
    ? window.memberBookInitialFilter
    : {};
  const options = Array.from(document.querySelectorAll('[data-book-option]'));
  const emptyState = document.querySelector('[data-book-empty]');
  const categorySelect = document.querySelector('[data-book-category]');
  const groups = Array.from(document.querySelectorAll('[data-book-group]'));
  const resultsStatus = document.querySelector('[data-book-results-status]');
  const modal = document.querySelector('[data-book-modal]');
  const modalTriggers = Array.from(document.querySelectorAll('[data-book-trigger]'));
  const modalCloseButtons = Array.from(document.querySelectorAll('[data-book-modal-close]'));
  const modalIdInput = document.querySelector('[data-book-modal-id]');
  const modalTitle = document.querySelector('[data-book-modal-title]');
  const modalBookTitle = document.querySelector('[data-book-modal-book-title]');
  const modalBookMeta = document.querySelector('[data-book-modal-book-meta]');
  const modalBookDescription = document.querySelector('[data-book-modal-description]');
  const modalAvailable = document.querySelector('[data-book-modal-available]');
  const modalCover = document.querySelector('[data-book-modal-cover]');
  const modalCoverPlaceholder = document.querySelector('[data-book-modal-cover-placeholder]');
  const modalQty = document.querySelector('[data-book-modal-qty]');
  const resultsPanel = document.querySelector('[data-book-results-panel]');
  let activeSuggestionIndex = -1;
  let syncUrlTimer = null;
  let exactBookId = String(initialFilterState.bookId || '').trim();

  const normalizeSearchValue = (value) => String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  const normalizeCategoryValue = (value) => String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const buildFilterUrl = (nextExactBookId = '') => {
    const url = new URL(window.location.href);
    const query = searchInput.value.trim();
    const selectedCategory = categorySelect ? normalizeCategoryValue(categorySelect.value) : '';
    const hasFixedCategoryScope = categorySelect && categorySelect.hasAttribute('data-book-fixed-category');
    const exactBookValue = String(nextExactBookId || '').trim();

    if (query !== '') {
      url.searchParams.set('search', query);
    } else {
      url.searchParams.delete('search');
    }

    if (hasFixedCategoryScope) {
      url.searchParams.delete('catalog');
    }

    if (selectedCategory !== '') {
      url.searchParams.set('category', selectedCategory);
    } else {
      url.searchParams.delete('category');
    }

    if (exactBookValue !== '') {
      url.searchParams.set('book', exactBookValue);
    } else {
      url.searchParams.delete('book');
    }

    return `${url.pathname}${url.search}${url.hash}`;
  };

  const syncFiltersInUrl = () => {
    const nextUrl = buildFilterUrl(exactBookId);
    const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;

    if (nextUrl !== currentUrl && window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState({}, '', nextUrl);
    }
  };

  const queueFilterUrlSync = () => {
    window.clearTimeout(syncUrlTimer);
    syncUrlTimer = window.setTimeout(syncFiltersInUrl, 120);
  };

  const focusResultsPanel = () => {
    if (!resultsPanel) {
      return;
    }

    const panelTop = resultsPanel.getBoundingClientRect().top + window.scrollY - 24;
    window.scrollTo({ top: Math.max(0, panelTop), behavior: 'auto' });
  };

  const focusFirstVisibleBook = () => {
    const firstVisibleOption = options.find((option) => !option.hidden);
    if (!firstVisibleOption) {
      focusResultsPanel();
      return;
    }

    const firstOptionTop = firstVisibleOption.getBoundingClientRect().top + window.scrollY - 24;
    window.scrollTo({ top: Math.max(0, firstOptionTop), behavior: 'auto' });
  };

  const updateResultsStatus = (query, selectedCategory) => {
    if (!resultsStatus) {
      return;
    }

    const chips = [];
    if (selectedCategory && categorySelect) {
      const selectedOption = categorySelect.options[categorySelect.selectedIndex];
      const categoryLabel = selectedOption ? selectedOption.textContent.trim() : '';
      if (categoryLabel !== '') {
        chips.push(`<span class="chip">Catalog: ${escapeHtml(categoryLabel)}</span>`);
      }
    }
    if (query !== '') {
      chips.push(`<span class="chip">Search: ${escapeHtml(searchInput.value.trim())}</span>`);
    }

    resultsStatus.innerHTML = chips.join('');
    resultsStatus.hidden = chips.length === 0;
  };

  const hideSearchSuggestions = () => {
    if (!searchSuggestions) {
      return;
    }

    searchSuggestions.hidden = true;
    searchSuggestions.innerHTML = '';
    activeSuggestionIndex = -1;
  };

  const getSuggestionButtons = () => searchSuggestions
    ? Array.from(searchSuggestions.querySelectorAll('.member-book-search-suggestion'))
    : [];

  const findBestMatchingBookOption = (value) => {
    const normalizedValue = normalizeSearchValue(value);
    if (!normalizedValue) {
      return null;
    }

    const rankedOptions = options
      .map((option) => {
        const title = normalizeSearchValue(option.getAttribute('data-book-title') || '');
        const author = normalizeSearchValue(option.getAttribute('data-book-author') || '');
        const searchText = normalizeSearchValue(option.getAttribute('data-book-search-text') || '');

        let score = -1;
        if (title === normalizedValue) {
          score = 0;
        } else if (title.indexOf(normalizedValue) === 0) {
          score = 1;
        } else if (author === normalizedValue) {
          score = 2;
        } else if (author.indexOf(normalizedValue) === 0) {
          score = 3;
        } else if (searchText.indexOf(normalizedValue) !== -1) {
          score = 4;
        }

        if (score === -1) {
          return null;
        }

        return { option, score, titleLength: title.length || searchText.length };
      })
      .filter(Boolean)
      .sort((left, right) => {
        if (left.score !== right.score) {
          return left.score - right.score;
        }

        return left.titleLength - right.titleLength;
      });

    return rankedOptions.length ? rankedOptions[0].option : null;
  };

  const highlightSuggestion = (index) => {
    const buttons = getSuggestionButtons();
    activeSuggestionIndex = index;

    buttons.forEach((button, buttonIndex) => {
      const isActive = buttonIndex === activeSuggestionIndex;
      button.classList.toggle('is-active', isActive);
      if (isActive) {
        button.scrollIntoView({ block: 'nearest' });
      }
    });
  };

  const getSearchMatches = (query) => {
    const normalizedQuery = normalizeSearchValue(query);
    if (!normalizedQuery) {
      return [];
    }

    return searchOptions
      .map((option) => ({
        label: option,
        normalized: normalizeSearchValue(option),
      }))
      .filter((option) => option.normalized.indexOf(normalizedQuery) === 0)
      .sort((left, right) => {
        if (left.label.length !== right.label.length) {
          return left.label.length - right.label.length;
        }

        return left.label.localeCompare(right.label);
      })
      .slice(0, 6);
  };

  const renderSearchSuggestions = () => {
    if (!searchSuggestions) {
      return;
    }

    const matches = getSearchMatches(searchInput.value);
    if (!matches.length) {
      hideSearchSuggestions();
      return;
    }

    searchSuggestions.innerHTML = matches
      .map((match) => `<button type="button" class="member-book-search-suggestion" data-book-search-value="${escapeHtml(match.label)}">${escapeHtml(match.label)}</button>`)
      .join('');
    searchSuggestions.hidden = false;
    activeSuggestionIndex = -1;
  };

  const submitSearchSelection = (value) => {
    searchInput.value = value;
    const matchedOption = findBestMatchingBookOption(value);
    if (matchedOption && categorySelect) {
      categorySelect.value = normalizeCategoryValue(matchedOption.getAttribute('data-book-category-value') || '');
    }
    exactBookId = matchedOption ? String(matchedOption.getAttribute('data-book-id') || '').trim() : '';
    applyFilter();
    hideSearchSuggestions();
    if (matchedOption) {
      window.location.assign(buildFilterUrl(exactBookId));
      return;
    }

    syncFiltersInUrl();
  };

  const applyFilter = () => {
    const query = normalizeSearchValue(searchInput.value);
    const selectedCategory = categorySelect ? normalizeCategoryValue(categorySelect.value) : '';
    const exactBookValue = String(exactBookId || '').trim();
    let visibleCount = 0;

    options.forEach((option) => {
      const haystack = (option.getAttribute('data-book-search-text') || '').toLowerCase();
      const categoryValue = (option.getAttribute('data-book-category-value') || '').toLowerCase();
      const optionBookId = String(option.getAttribute('data-book-id') || '').trim();
      const matchesQuery = query === '' || haystack.includes(query);
      const matchesCategory = selectedCategory === '' || categoryValue === selectedCategory;
      const matchesExactBook = exactBookValue === '' || optionBookId === exactBookValue;
      const isVisible = matchesQuery && matchesCategory && matchesExactBook;
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

    updateResultsStatus(query, selectedCategory);
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
    const description = trigger.getAttribute('data-book-description') || '';
    const coverPath = trigger.getAttribute('data-book-cover') || '';
    const availableCopies = Math.max(1, parseInt(trigger.getAttribute('data-book-available') || '1', 10));
    const maxQty = Math.max(1, parseInt(trigger.getAttribute('data-book-max-qty') || '1', 10));

    modalIdInput.value = bookId;
    modalTitle.textContent = `Request ${title}`;
    modalBookTitle.textContent = title;
    modalBookMeta.textContent = [author, category].filter(Boolean).join(' - ');
    if (modalBookDescription) {
      modalBookDescription.textContent = description !== '' ? description : 'No description available yet.';
      modalBookDescription.hidden = false;
    }
    if (modalAvailable) {
      modalAvailable.textContent = `${availableCopies} available cop${availableCopies === 1 ? 'y' : 'ies'}`;
    }
    populateQtyOptions(maxQty);

    if (modalCover && modalCoverPlaceholder) {
      if (coverPath !== '') {
        modalCover.src = coverPath;
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
    exactBookId = '';
    if (searchInput.value.trim() === '' && categorySelect) {
      categorySelect.value = '';
    }
    applyFilter();
    renderSearchSuggestions();
    queueFilterUrlSync();
  });

  searchInput.addEventListener('keydown', (event) => {
    const suggestionButtons = getSuggestionButtons();

    if (event.key === 'ArrowDown') {
      if (!suggestionButtons.length) {
        return;
      }

      event.preventDefault();
      highlightSuggestion((activeSuggestionIndex + 1) % suggestionButtons.length);
      return;
    }

    if (event.key === 'ArrowUp') {
      if (!suggestionButtons.length) {
        return;
      }

      event.preventDefault();
      highlightSuggestion(activeSuggestionIndex <= 0 ? suggestionButtons.length - 1 : activeSuggestionIndex - 1);
      return;
    }

    if (event.key === 'Escape') {
      hideSearchSuggestions();
      return;
    }

    if (event.key !== 'Enter') {
      return;
    }

    if (activeSuggestionIndex >= 0 && suggestionButtons[activeSuggestionIndex]) {
      event.preventDefault();
      submitSearchSelection(suggestionButtons[activeSuggestionIndex].getAttribute('data-book-search-value') || '');
      return;
    }

    const exactMatch = searchOptions.find((option) => normalizeSearchValue(option) === normalizeSearchValue(searchInput.value));
    if (exactMatch) {
      event.preventDefault();
      submitSearchSelection(exactMatch);
      return;
    }

    const matchedOption = findBestMatchingBookOption(searchInput.value);
    if (!matchedOption) {
      return;
    }

    event.preventDefault();
    submitSearchSelection(matchedOption.getAttribute('data-book-title') || searchInput.value);
  });

  if (searchSuggestions) {
    searchSuggestions.addEventListener('click', (event) => {
      const suggestion = event.target.closest('[data-book-search-value]');
      if (!suggestion) {
        return;
      }

      submitSearchSelection(suggestion.getAttribute('data-book-search-value') || '');
    });
  }

  document.addEventListener('click', (event) => {
    if (event.target === searchInput || (searchSuggestions && searchSuggestions.contains(event.target))) {
      return;
    }

    hideSearchSuggestions();
  });

  if (categorySelect) {
    categorySelect.addEventListener('change', () => {
      exactBookId = '';
      hideSearchSuggestions();
      window.location.assign(buildFilterUrl(''));
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

  if ((categorySelect && categorySelect.value.trim() !== '') || searchInput.value.trim() !== '') {
    window.requestAnimationFrame(focusFirstVisibleBook);
  }
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
