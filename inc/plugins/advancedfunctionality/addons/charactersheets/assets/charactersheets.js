(function () {
  var tabRoots = document.querySelectorAll('[data-afcs-tabs]');
  tabRoots.forEach(function (root) {
    root.addEventListener('click', function (event) {
      var target = event.target.closest('[data-tab]');
      if (!target) {
        return;
      }
      var tab = target.getAttribute('data-tab');
      if (!tab) {
        return;
      }
      root.querySelectorAll('.af-cs-tab').forEach(function (node) {
        node.classList.toggle('is-active', node === target);
      });
      document.querySelectorAll('.af-cs-panel').forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-panel') === tab);
      });
    });
  });

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
    frame.setAttribute('src', url);
    modal.classList.add('is-open');
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-af-cs-sheet-url]');
    if (trigger) {
      event.preventDefault();
      var url = trigger.getAttribute('data-af-cs-sheet-url');
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
