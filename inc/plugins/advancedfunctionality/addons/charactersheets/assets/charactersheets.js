(function () {
  var modal = document.querySelector('[data-afcs-modal]');
  var frame = modal ? modal.querySelector('[data-afcs-frame]') : null;

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.classList.remove('is-open');
    if (frame) {
      frame.removeAttribute('src');
    }
  }

  function openModal(url) {
    if (!modal || !frame) {
      window.open(url, '_blank');
      return;
    }
    var loadUrl = url;
    if (loadUrl.indexOf('ajax=1') === -1) {
      loadUrl += (loadUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
    }
    frame.setAttribute('src', loadUrl);
    modal.classList.add('is-open');
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-afcs-sheet]');
    if (trigger) {
      event.preventDefault();
      var url = trigger.getAttribute('data-afcs-sheet');
      if (url) {
        openModal(url);
      }
      return;
    }

    if (event.target.closest('[data-afcs-close]')) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
})();
