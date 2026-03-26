(function () {
  var form = document.getElementById('catalogSearchForm');
  var input = document.getElementById('search');
  var suggestions = document.getElementById('catalogSearchSuggestions');
  var options = Array.isArray(window.catalogSearchOptions) ? window.catalogSearchOptions : [];
  var activeSuggestionIndex = -1;

  if (!form || !input || !suggestions || !options.length) {
    return;
  }

  function normalize(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/\s+/g, ' ')
      .trim();
  }

  function getMatches(query) {
    var normalizedQuery = normalize(query);
    if (!normalizedQuery) {
      return [];
    }

    return options
      .map(function (option) {
        var normalizedOption = normalize(option);
        if (normalizedOption.indexOf(normalizedQuery) !== 0) {
          return null;
        }

        return {
          label: option,
          normalized: normalizedOption,
          score: 0,
        };
      })
      .filter(Boolean)
      .sort(function (left, right) {
        if (left.label.length !== right.label.length) {
          return left.label.length - right.label.length;
        }

        return left.label.localeCompare(right.label);
      })
      .slice(0, 6);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function hideSuggestions() {
    suggestions.hidden = true;
    suggestions.innerHTML = '';
    activeSuggestionIndex = -1;
  }

  function submitSearch(value) {
    input.value = value;
    if (form.requestSubmit) {
      form.requestSubmit();
      return;
    }

    form.submit();
  }

  function getSuggestionButtons() {
    return Array.prototype.slice.call(suggestions.querySelectorAll('.catalog-search-suggestion'));
  }

  function highlightSuggestion(index) {
    var buttons = getSuggestionButtons();
    activeSuggestionIndex = index;

    buttons.forEach(function (button, buttonIndex) {
      var isActive = buttonIndex === activeSuggestionIndex;
      button.classList.toggle('is-active', isActive);
      if (isActive) {
        button.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  function renderSuggestions() {
    var matches = getMatches(input.value);
    if (!matches.length) {
      hideSuggestions();
      return;
    }

    suggestions.innerHTML = matches
      .map(function (match) {
        return '<button type="button" class="catalog-search-suggestion" data-value="' +
          escapeHtml(match.label) +
          '">' + escapeHtml(match.label) + '</button>';
      })
      .join('');
    suggestions.hidden = false;
    activeSuggestionIndex = -1;
  }

  input.addEventListener('input', renderSuggestions);

  input.addEventListener('keydown', function (event) {
    var buttons = getSuggestionButtons();

    if (event.key === 'ArrowDown') {
      if (!buttons.length) {
        return;
      }

      event.preventDefault();
      highlightSuggestion((activeSuggestionIndex + 1) % buttons.length);
      return;
    }

    if (event.key === 'ArrowUp') {
      if (!buttons.length) {
        return;
      }

      event.preventDefault();
      highlightSuggestion(activeSuggestionIndex <= 0 ? buttons.length - 1 : activeSuggestionIndex - 1);
      return;
    }

    if (event.key === 'Escape') {
      hideSuggestions();
      return;
    }

    if (event.key !== 'Enter') {
      return;
    }

    if (activeSuggestionIndex >= 0 && buttons[activeSuggestionIndex]) {
      event.preventDefault();
      submitSearch(buttons[activeSuggestionIndex].getAttribute('data-value') || '');
      return;
    }

    var exactMatch = options.find(function (option) {
      return normalize(option) === normalize(input.value);
    });

    if (!exactMatch) {
      return;
    }

    event.preventDefault();
    hideSuggestions();
    submitSearch(exactMatch);
  });

  suggestions.addEventListener('click', function (event) {
    var suggestion = event.target.closest('[data-value]');
    if (!suggestion) {
      return;
    }

    submitSearch(suggestion.getAttribute('data-value') || '');
  });

  document.addEventListener('click', function (event) {
    if (event.target === input || suggestions.contains(event.target)) {
      return;
    }

    hideSuggestions();
  });
})();
