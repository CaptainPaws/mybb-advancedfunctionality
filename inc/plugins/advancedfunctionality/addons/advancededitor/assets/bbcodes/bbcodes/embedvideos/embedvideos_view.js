(function(window, document) {
  "use strict";
  function asText(x) { return String(x == null ? '' : x); }
  function ensureTelegramWidgetScript() {
    if (document.getElementById('af-ev-telegram-widget')) return;
    var s = document.createElement('script');
    s.id = 'af-ev-telegram-widget';
    s.async = true;
    s.src = 'https://telegram.org/js/telegram-widget.js?22';
    document.head.appendChild(s);
  }

  function renderOneEmbed(el) {
    try {
      if (!el || el.__afEvRendered) return;
      el.__afEvRendered = true;

      var type  = (el.getAttribute('data-af-ev-type') || '').toLowerCase();
      var src   = el.getAttribute('data-af-ev-src') || '';
      var id    = el.getAttribute('data-af-ev-id') || '';
      var allow = el.getAttribute('data-af-ev-allow') || '';
      var afull = el.getAttribute('data-af-ev-allowfullscreen') || '';

      var fallbackHtml = el.innerHTML;

      function makeResponsiveIframe(url, opts) {
        opts = opts || {};
        if (!url) return false;

        el.style.position = 'relative';
        el.style.width = '100%';
        el.style.maxWidth = '100%';
        el.style.paddingTop = '56.25%'; // 16:9
        el.style.margin = '8px 0';

        var iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.loading = 'lazy';
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');


        // allow/allowfullscreen — для “Другой”
        if (opts.allow) iframe.setAttribute('allow', opts.allow);
        if (opts.allowfullscreen) iframe.allowFullscreen = true;

        // базовый allow для нормальных хостингов
        if (!opts.allow) {
          iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
          iframe.allowFullscreen = true;
        }

        iframe.style.position = 'absolute';
        iframe.style.left = '0';
        iframe.style.top = '0';
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = '0';

        el.innerHTML = '';
        el.appendChild(iframe);
        return true;
      }

      // ---- Telegram: виджет ----
      if (type === 'telegram' && id) {
        el.style.position = 'relative';
        el.style.width = '100%';
        el.style.maxWidth = '100%';
        el.style.margin = '8px 0';

        el.innerHTML = '';

        var bq = document.createElement('blockquote');
        bq.className = 'telegram-post';
        bq.setAttribute('data-telegram-post', id);
        bq.setAttribute('data-width', '100%');
        el.appendChild(bq);

        ensureTelegramWidgetScript();
        return;
      }

      // ---- Telegram iframe fallback ----
      if (type === 'telegram_iframe' && src) {
        if (makeResponsiveIframe(src)) return;
      }

      // ---- “Другой”: сырой iframe -> src уже готов ----
      if (type === 'iframe_raw' && src) {
        if (makeResponsiveIframe(src, {
          allow: allow,
          allowfullscreen: (afull === '1' || afull === 'true')
        })) return;
      }

      // ---- Kodik ----
      if (type === 'kodik' && src) {
        if (makeResponsiveIframe(src)) return;
      }

      // ---- Остальные iframe-типы ----
      if (src && (type === 'youtube' || type === 'rutube' || type === 'coub')) {
        if (makeResponsiveIframe(src)) return;
      }

      // fallback
      el.__afEvRendered = true;
      el.innerHTML = fallbackHtml;

    } catch (e) {
      try { el.__afEvRendered = false; } catch (_) {}
    }
  }

  function renderAllEmbeds(root) {
    root = root || document;
    var list = root.querySelectorAll ? root.querySelectorAll('.af-ev-embed[data-af-ev-type]') : [];
    for (var i = 0; i < (list ? list.length : 0); i++) renderOneEmbed(list[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { renderAllEmbeds(document); });
  } else {
    renderAllEmbeds(document);
  }

  if (window.MutationObserver) {
    try {
      var mo = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var m = mutations[i];
          if (m && m.addedNodes) {
            for (var j = 0; j < m.addedNodes.length; j++) {
              var n = m.addedNodes[j];
              if (n && n.nodeType === 1) {
                if (n.matches && n.matches('.af-ev-embed[data-af-ev-type]')) renderOneEmbed(n);
                else renderAllEmbeds(n);
              }
            }
          }
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }


})(window, document);
