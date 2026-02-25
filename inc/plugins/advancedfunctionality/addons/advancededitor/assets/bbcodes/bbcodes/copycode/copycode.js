(function () {
  'use strict';

  // one-shot
  if (window.__afCopyCodeLoaded) return;
  window.__afCopyCodeLoaded = true;

  function asText(x) { return String(x == null ? '' : x); }

  // --- copy helpers ---
  function copyToClipboard(text) {
    text = asText(text);

    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
      }
    } catch (e0) {}

    return new Promise(function (resolve) {
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.left = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, ta.value.length);

        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e1) { ok = false; }

        try { ta.remove(); } catch (e2) { if (ta.parentNode) ta.parentNode.removeChild(ta); }
        resolve(!!ok);
      } catch (e3) {
        resolve(false);
      }
    });
  }

  function makeButton(isFallback) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'af-cc-btn ' + (isFallback ? 'af-cc-fallback' : 'af-cc-in-title');
    btn.setAttribute('aria-label', 'Копировать код');
    btn.setAttribute('title', 'Копировать');

    // ВАЖНО: на случай “агрессивных” тем (в т.ч. гостевых)
    btn.style.display = 'inline-flex';
    btn.style.visibility = 'visible';
    btn.style.pointerEvents = 'auto';
    btn.style.zIndex = '9999';

    // НЕ задаём top отрицательным — это и убивало видимость у гостей
    // позиционирование оставляем CSS-классам

    var ico = document.createElement('span');
    ico.className = 'af-cc-ico';
    ico.setAttribute('aria-hidden', 'true');

    var tx = document.createElement('span');
    tx.className = 'af-cc-txt';
    tx.textContent = 'Копировать';

    btn.appendChild(ico);
    btn.appendChild(tx);

    return btn;
  }

  function setButtonState(btn, state) {
    var t = btn.querySelector('.af-cc-txt');
    if (!t) return;

    if (state === 'busy') {
      btn.disabled = true;
      t.textContent = '...';
      return;
    }

    btn.disabled = false;

    if (state === 'ok')  { t.textContent = 'Скопировано'; return; }
    if (state === 'fail'){ t.textContent = 'Не вышло';   return; }

    t.textContent = 'Копировать';
  }

  function resetLater(btn) {
    setTimeout(function () { setButtonState(btn, 'idle'); }, 1200);
  }

  // --- extract code text from MyBB codeblock (разные темы) ---
  function extractCodeTextFromCodeblock(codeblock) {
    if (!codeblock || codeblock.nodeType !== 1) return '';

    var body = codeblock.querySelector('.body') || codeblock;

    var pre = body.querySelector('pre');
    if (pre) return asText(pre.textContent || '').replace(/\r\n/g, '\n').trim();

    var code = body.querySelector('code');
    if (code) return asText(code.textContent || '').replace(/\r\n/g, '\n').trim();

    var ol = body.querySelector('ol');
    if (ol) {
      var items = ol.querySelectorAll('li');
      if (items && items.length) {
        var lines = [];
        for (var i = 0; i < items.length; i++) {
          lines.push(asText(items[i].textContent || '').replace(/\r\n/g, '\n'));
        }
        return lines.join('\n').trim();
      }
    }

    var txt = asText(body.textContent || '').replace(/\r\n/g, '\n');
    txt = txt.replace(/^\s*Код:\s*/i, '');
    return txt.trim();
  }

  function findTitleBar(codeblock) {
    if (!codeblock || codeblock.nodeType !== 1) return null;

    var t = codeblock.querySelector('.title') || codeblock.querySelector('.header') || null;
    if (t && t.nodeType === 1) return t;

    var firstDiv = codeblock.firstElementChild;
    if (firstDiv && firstDiv.nodeType === 1) {
      var tx = asText(firstDiv.textContent || '').trim().toLowerCase();
      if (tx === 'код:' || tx === 'code:' || tx.indexOf('код') === 0) return firstDiv;
    }

    return null;
  }

  function alreadyPatched(codeblock) {
    return !!(codeblock && codeblock.querySelector && codeblock.querySelector('.af-cc-btn'));
  }

  function forceVisibleBox(el) {
    if (!el || el.nodeType !== 1) return;
    try {
      var cs = window.getComputedStyle(el);
      if (!cs) return;

      if (cs.position === 'static') el.style.position = 'relative';

      // самый частый виновник “у гостей не видно”
      if (cs.overflow === 'hidden' || cs.overflowX === 'hidden' || cs.overflowY === 'hidden') {
        el.style.overflow = 'visible';
      }
    } catch (e0) {
      el.style.position = 'relative';
      el.style.overflow = 'visible';
    }
  }

  function patchOneCodeblock(codeblock) {
    if (!codeblock || codeblock.nodeType !== 1) return;
    if (alreadyPatched(codeblock)) return;

    // scope для fallback-режима
    if (!codeblock.classList.contains('af-cc-scope')) codeblock.classList.add('af-cc-scope');

    // снимаем клиппинг с самого codeblock (часто именно он режет кнопку у гостей)
    forceVisibleBox(codeblock);

    var title = findTitleBar(codeblock);
    var body = codeblock.querySelector('.body') || null;

    if (title) forceVisibleBox(title);

    var useFallback = !title;
    var btn = makeButton(useFallback);

    btn.addEventListener('click', function (ev) {
      ev.preventDefault();

      var text = extractCodeTextFromCodeblock(codeblock);
      if (!text) {
        setButtonState(btn, 'fail');
        resetLater(btn);
        return;
      }

      setButtonState(btn, 'busy');
      copyToClipboard(text).then(function (ok) {
        setButtonState(btn, ok ? 'ok' : 'fail');
        resetLater(btn);
      });
    }, false);

    if (title) {
      // вставляем в шапку — самое логичное место
      title.appendChild(btn);

      // если вдруг внешний контейнер всё равно клипает (в теме гостей) —
      // продублируем fallback в сам codeblock
      // (но без дубля: только если кнопка реально “не влезла” по высоте)
      setTimeout(function () {
        try {
          var r = btn.getBoundingClientRect();
          if (!r || r.width <= 0 || r.height <= 0) return;

          // если кнопка вне видимой области codeblock — пересадим в fallback
          var cb = codeblock.getBoundingClientRect();
          if (r.top < cb.top || r.right > cb.right + 2) {
            if (btn.parentNode) btn.parentNode.removeChild(btn);

            var btn2 = makeButton(true);
            btn2.addEventListener('click', function (ev2) {
              ev2.preventDefault();
              var text2 = extractCodeTextFromCodeblock(codeblock);
              if (!text2) { setButtonState(btn2, 'fail'); resetLater(btn2); return; }
              setButtonState(btn2, 'busy');
              copyToClipboard(text2).then(function (ok2) {
                setButtonState(btn2, ok2 ? 'ok' : 'fail');
                resetLater(btn2);
              });
            }, false);

            codeblock.classList.add('af-cc-has-fallback');
            codeblock.appendChild(btn2);

            // если есть .body — добавим верхний паддинг, чтобы кнопка не наезжала
            if (body) body.style.paddingTop = '26px';
          }
        } catch (e0) {}
      }, 0);

      return;
    }

    // fallback: прямо на контейнер
    codeblock.classList.add('af-cc-has-fallback');
    codeblock.appendChild(btn);

    if (body) body.style.paddingTop = '26px';
  }

  function patchAll(root) {
    root = root || document;

    var blocks = root.querySelectorAll ? root.querySelectorAll('.codeblock') : [];
    for (var i = 0; i < blocks.length; i++) patchOneCodeblock(blocks[i]);

    var alt = root.querySelectorAll ? root.querySelectorAll('.mycode_code, .code_block') : [];
    for (var j = 0; j < alt.length; j++) patchOneCodeblock(alt[j]);
  }

  // init
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { patchAll(document); });
  } else {
    patchAll(document);
  }

  // динамика: quickedit/подгрузки
  try {
    var mo = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (!m || !m.addedNodes) continue;

        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (!n || n.nodeType !== 1) continue;

          if (n.matches && (n.matches('.codeblock') || n.matches('.mycode_code') || n.matches('.code_block'))) {
            patchOneCodeblock(n);
          } else {
            patchAll(n);
          }
        }
      }
    });

    mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
  } catch (e1) {}
})();
