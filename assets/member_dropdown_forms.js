(function () {
  function initIncidentForm() {
    var borrowSelect = document.getElementById('borrow_id');
    var incidentTypeSelect = document.getElementById('incident_type');
    var summary = document.querySelector('[data-incident-selection-summary]');
    var borrowValue = document.querySelector('[data-incident-selected-borrow]');
    var incidentTypeValue = document.querySelector('[data-incident-selected-type]');
    var submitButton = document.querySelector('[data-incident-submit]');

    if (!borrowSelect || !incidentTypeSelect || !summary) {
      return;
    }

    function syncIncidentSummary() {
      var borrowOption = borrowSelect.options[borrowSelect.selectedIndex];
      var incidentOption = incidentTypeSelect.options[incidentTypeSelect.selectedIndex];
      var hasBorrow = !!(borrowSelect.value && borrowOption);
      var hasIncidentType = !!(incidentTypeSelect.value && incidentOption);

      borrowValue.textContent = hasBorrow
        ? (borrowOption.getAttribute('data-borrow-display') || borrowOption.textContent || 'Selected borrow')
        : 'No borrowed book selected yet.';
      incidentTypeValue.textContent = hasIncidentType
        ? (incidentOption.textContent || 'Selected incident type')
        : 'No incident type selected yet.';

      summary.hidden = !(hasBorrow || hasIncidentType);
      if (submitButton) {
        submitButton.disabled = !(hasBorrow && hasIncidentType);
      }
    }

    borrowSelect.addEventListener('change', syncIncidentSummary);
    incidentTypeSelect.addEventListener('change', syncIncidentSummary);
    syncIncidentSummary();
  }

  function initPaymentForm() {
    var penaltySelect = document.getElementById('payment_group_book_id');
    var penaltyAmount = document.getElementById('penalty_amount');
    var penaltySummary = document.querySelector('[data-penalty-selection-summary]');
    var penaltySummaryValue = document.querySelector('[data-penalty-selection-value]');
    var incidentSelect = document.getElementById('payment_incident_id');
    var incidentAmount = document.getElementById('incident_amount');
    var incidentSummary = document.querySelector('[data-incident-payment-summary]');
    var incidentSummaryValue = document.querySelector('[data-incident-payment-value]');

    function syncAmount(select, amountInput, summary, summaryValue, emptyText) {
      if (!select || !amountInput) {
        return;
      }

      var option = select.options[select.selectedIndex];
      var amount = option ? option.getAttribute('data-payment-amount') || '' : '';
      var label = option ? option.getAttribute('data-payment-label') || option.textContent || '' : '';

      amountInput.value = amount;
      if (summary && summaryValue) {
        summaryValue.textContent = label || emptyText;
        summary.hidden = !label;
      }
    }

    if (penaltySelect && penaltyAmount) {
      penaltySelect.addEventListener('change', function () {
        syncAmount(
          penaltySelect,
          penaltyAmount,
          penaltySummary,
          penaltySummaryValue,
          'No grouped penalty selected yet.'
        );
      });

      syncAmount(
        penaltySelect,
        penaltyAmount,
        penaltySummary,
        penaltySummaryValue,
        'No grouped penalty selected yet.'
      );
    }

    if (incidentSelect && incidentAmount) {
      incidentSelect.addEventListener('change', function () {
        syncAmount(
          incidentSelect,
          incidentAmount,
          incidentSummary,
          incidentSummaryValue,
          'No incident fee selected yet.'
        );
      });

      syncAmount(
        incidentSelect,
        incidentAmount,
        incidentSummary,
        incidentSummaryValue,
        'No incident fee selected yet.'
      );
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initIncidentForm();
    initPaymentForm();
  });
})();
