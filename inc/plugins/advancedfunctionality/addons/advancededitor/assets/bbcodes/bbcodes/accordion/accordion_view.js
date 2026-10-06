(function(window, document) {
  "use strict";
  function setPanelState(toggle, panel, isOpen) {
    var item;

    if (!toggle || !panel) return;

    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    panel.hidden = false;

    if (isOpen) {
      panel.classList.add('is-open');
      panel.classList.remove('is-closing');
      panel.style.maxHeight = (panel.scrollHeight + 20) + 'px';
    } else {
      panel.classList.remove('is-open');
      panel.classList.add('is-closing');
      panel.style.maxHeight = '0px';
    }

    item = toggle.closest('.af-accordion-item');
    if (item) {
      if (isOpen) item.classList.add('is-open');
      else item.classList.remove('is-open');
    }

    if (!isOpen) {
      window.setTimeout(function () {
        if (toggle.getAttribute('aria-expanded') !== 'true') {
          panel.hidden = true;
        }
      }, 230);
    }
  }

  function togglePanel(toggle) {
    var expanded;
    var panelId;
    var panel;

    if (!toggle || toggle.nodeType !== 1) return;

    expanded = toggle.getAttribute('aria-expanded') === 'true';
    panelId = toggle.getAttribute('aria-controls') || '';
    panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) return;

    setPanelState(toggle, panel, !expanded);
  }

  function bindAccordion(root) {
    if (!root || root.nodeType !== 1 || root.getAttribute('data-af-accordion-bound') === '1') {
      return;
    }

    root.setAttribute('data-af-accordion-bound', '1');

    root.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;
      var toggle = target.closest('[data-af-acc-toggle="1"]');
      if (!toggle || !root.contains(toggle)) return;

      event.preventDefault();
      togglePanel(toggle);
    }, false);
  }

  function initAccordions(scope) {
    var root = scope && scope.querySelectorAll ? scope : document;
    var list = root.querySelectorAll('[data-af-accordion="1"]');

    for (var i = 0; i < list.length; i++) {
      bindAccordion(list[i]);
    }
  }

  function bindDynamicInit() {
    if (window.__afAeAccordionDynamicInitBound) return;
    window.__afAeAccordionDynamicInitBound = true;

    if (window.MutationObserver && document.body) {
      var observer = new MutationObserver(function (records) {
        for (var i = 0; i < records.length; i++) {
          var rec = records[i];
          if (!rec || !rec.addedNodes || !rec.addedNodes.length) continue;

          for (var j = 0; j < rec.addedNodes.length; j++) {
            var node = rec.addedNodes[j];
            if (!node || node.nodeType !== 1) continue;

            if (node.matches && node.matches('[data-af-accordion="1"]')) {
              bindAccordion(node);
            }

            if (node.querySelectorAll) {
              initAccordions(node);
            }
          }
        }
      });

      observer.observe(document.body, { childList: true, subtree: true });
    }

    document.addEventListener('af:preview-updated', function (event) {
      initAccordions(event && event.detail && event.detail.root ? event.detail.root : document);
    });
  }

  function boot() { initAccordions(document); bindDynamicInit(); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot); else boot();

})(window, document);
