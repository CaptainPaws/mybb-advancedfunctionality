(function () {
  'use strict';

  if (window.__afPostbitFaIconsInit) return;
  window.__afPostbitFaIconsInit = true;

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function hasFA() {
    var i = document.createElement('i');
    i.className = 'fa-solid fa-link';
    i.style.position = 'absolute';
    i.style.left = '-9999px';
    document.body.appendChild(i);
    var cs = window.getComputedStyle(i);
    var ok = cs && cs.fontFamily && /Font Awesome/i.test(cs.fontFamily);
    document.body.removeChild(i);
    return ok;
  }

  function ensureFaLinkOnce() {
    if (document.querySelector('link[data-af-fa="1"]')) return;
    if (hasFA()) return;

    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
    link.setAttribute('data-af-fa', '1');
    document.head.appendChild(link);
  }

  function addClockToPostDate(root) {
    var nodes = (root || document).querySelectorAll('.post_date');
    nodes.forEach(function (pd) {
      if (pd.querySelector('.af-pbfa-clock')) return;

      var ico = document.createElement('i');
      ico.className = 'fa-regular fa-clock af-pbfa-ico af-pbfa-clock';
      ico.setAttribute('aria-hidden', 'true');

      pd.insertBefore(ico, pd.firstChild);
    });
  }

  function addLinkToPostNumber(root) {
    var links = (root || document).querySelectorAll('.float_right a[href*="showthread.php"][href*="pid="][href*="#pid"]');
    links.forEach(function (a) {
      var txt = (a.textContent || '').trim();
      if (!/^#\d+$/i.test(txt)) return;

      if (a.querySelector('.af-pbfa-linkinside')) return;

      var ico = document.createElement('i');
      ico.className = 'fa-solid fa-link af-pbfa-ico af-pbfa-linkinside';
      ico.setAttribute('aria-hidden', 'true');

      a.insertBefore(ico, a.firstChild);
    });
  }

  function enhance(root) {
    addClockToPostDate(root);
    addLinkToPostNumber(root);
  }

  function observe() {
    var mo = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (!m.addedNodes || !m.addedNodes.length) continue;
        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (n.nodeType !== 1) continue;
          enhance(n);
        }
      }
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }

  onReady(function () {
    ensureFaLinkOnce();
    enhance(document);
    observe();
  });

})();
