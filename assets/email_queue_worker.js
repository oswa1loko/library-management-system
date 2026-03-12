(function () {
  if (!window.fetch) {
    return;
  }

  function processQueue() {
    window.fetch('/librarymanage/api/v1/system/process_email_queue.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).catch(function () {
      return null;
    });
  }

  window.setTimeout(processQueue, 150);
})();
