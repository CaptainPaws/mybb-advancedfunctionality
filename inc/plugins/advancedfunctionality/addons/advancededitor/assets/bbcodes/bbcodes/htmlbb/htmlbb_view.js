(function(window, document) {
  "use strict";
  function asText(x) { return String(x == null ? "" : x); }
  // ===== Frontend render placeholders =====
  function decodeB64(s) {
    s = asText(s);
    try { return decodeURIComponent(escape(window.atob(s))); } catch (e) {}
    try { return window.atob(s); } catch (e2) {}
    return '';
  }

  function renderOne(el) {
    if (!el || el.__afHtmlbbRendered) return;
    el.__afHtmlbbRendered = true;

    var b64 = el.getAttribute('data-af-html-b64') || '';
    var html = decodeB64(b64);

    if (!html) return;

    // вставляем HTML внутрь контейнера
    el.innerHTML = '';
    var box = document.createElement('div');
    box.className = 'af-htmlbb-box';
    box.innerHTML = html;
    el.appendChild(box);
  }

  function renderAll(root) {
    root = root || document;
    var list = root.querySelectorAll ? root.querySelectorAll('.af-htmlbb[data-af-html-b64]') : [];
    for (var i = 0; i < list.length; i++) renderOne(list[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { renderAll(document); });
  } else {
    renderAll(document);
  }

  if (window.MutationObserver) {
    try {
      var mo = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var m = mutations[i];
          if (!m || !m.addedNodes) continue;

          for (var j = 0; j < m.addedNodes.length; j++) {
            var n = m.addedNodes[j];
            if (n && n.nodeType === 1) {
              if (n.matches && n.matches('.af-htmlbb[data-af-html-b64]')) renderOne(n);
              else renderAll(n);
            }
          }
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

})(window, document);
