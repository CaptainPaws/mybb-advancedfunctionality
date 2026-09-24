(function () {
  'use strict';

  var modal = null;

  function escapeHtml(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-active');
    modal.setAttribute('aria-hidden', 'true');
  }

  function ensureModal() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'af-wanted-modal-backdrop';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = '<section class="af-wanted-modal" role="dialog" aria-modal="true" aria-labelledby="af-wanted-modal-title">' +
      '<header class="af-wanted-modal-header"><h3 id="af-wanted-modal-title"></h3>' +
      '<button type="button" class="af-wanted-modal-close" aria-label="Закрыть">&times;</button></header>' +
      '<div class="af-wanted-modal-body"></div></section>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (event) {
      if (event.target === modal || event.target.closest('.af-wanted-modal-close')) closeModal();
    });
    return modal;
  }

  function render(data) {
    var shell = ensureModal();
    var entry = data && data.entry;
    if (!entry) return;
    var title = shell.querySelector('h3');
    var body = shell.querySelector('.af-wanted-modal-body');
    title.textContent = entry.title || entry.key || 'Wanted';
    var image = entry.banner_url ? '<img class="af-wanted-modal-image" src="' + escapeHtml(entry.banner_url) + '" alt="" loading="lazy">' : '';
    var sections = '';
    (Array.isArray(entry.sections_html) ? entry.sections_html : []).forEach(function (section) {
      if (!section) return;
      sections += '<section class="af-wanted-modal-section"><h4>' + escapeHtml(section.label || '') + '</h4><div>' + (section.html || '') + '</div></section>';
    });
    body.innerHTML = image + (entry.body_html || '') + sections;
    shell.classList.add('is-active');
    shell.setAttribute('aria-hidden', 'false');
    shell.querySelector('.af-wanted-modal-close').focus();
  }

  function openChip(chip) {
    var id = chip.getAttribute('data-wanted-id');
    if (!id) return;
    fetch('wanted.php?action=modal&ajax=1&id=' + encodeURIComponent(id), {
      credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    }).then(render).catch(function () { closeModal(); });
  }

  document.addEventListener('click', function (event) {
    var chip = event.target.closest && event.target.closest('.af-wanted-post-chip');
    if (!chip || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    openChip(chip);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { closeModal(); return; }
    if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') return;
    var chip = event.target.closest && event.target.closest('.af-wanted-post-chip');
    if (!chip) return;
    event.preventDefault();
    openChip(chip);
  });
}());
