(function () {
  'use strict';

  if (window.__afQuoteAvatarsInit) return;
  window.__afQuoteAvatarsInit = true;

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function normalizeName(s) {
    return String(s || '')
      .replace(/\u00A0/g, ' ')
      .replace(/[\u200B-\u200D\uFEFF]/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function uidFromHref(href) {
    if (!href) return 0;
    var m = String(href).match(/[?&]uid=(\d+)/i);
    return m ? (parseInt(m[1], 10) || 0) : 0;
  }

  // MyBB цитата: "Имя Написал:" / "Имя wrote:" / "Имя schrieb:" и т.п.
  function extractAuthorName(text) {
    var t = normalizeName(text);

    // чаще всего: "Hanna Sinclair Написал:"
    var m = t.match(/^(.*?)(?:\s+(?:написал|написала|писал|писала|wrote|posted|schrieb))\s*:\s*$/iu);
    if (m && m[1]) return normalizeName(m[1]);

    // иногда: "Имя:"
    m = t.match(/^(.*?):\s*$/u);
    if (m && m[1]) return normalizeName(m[1]);

    // иногда текст без двоеточия, но с "написал"
    m = t.match(/^(.*?)(?:\s+(?:написал|написала|писал|писала|wrote|posted|schrieb))\s*$/iu);
    if (m && m[1]) return normalizeName(m[1]);

    return '';
  }

  function findQuoteHeader(bq) {
    if (!bq) return null;

    // классика MyBB: <blockquote class="mycode_quote"><cite>...</cite>...</blockquote>
    var h = bq.querySelector('cite');
    if (h) return h;

    // некоторые темы: .quote_header
    h = bq.querySelector('.quote_header');
    if (h) return h;

    // запасной: первый элемент внутри blockquote, если он похож на заголовок
    var first = bq.firstElementChild;
    if (first && (first.tagName === 'CITE' || /quote/i.test(first.className || ''))) return first;

    return null;
  }

  function findProfileLinkIn(node) {
    if (!node) return null;
    return node.querySelector('a[href*="member.php"][href*="uid="]') || null;
  }

  // Выбираем "похожий на аватар" img рядом с пользователем
  function pickBestAvatarImg(context) {
    if (!context) return null;

    var imgs = Array.prototype.slice.call(context.querySelectorAll('img'));
    if (!imgs.length) return null;

    function score(img) {
      var src = String(img.getAttribute('src') || '');
      var cls = String(img.getAttribute('class') || '').toLowerCase();
      var alt = String(img.getAttribute('alt') || '').toLowerCase();

      // размеры (часто аватар >= 24)
      var w = img.naturalWidth || img.width || 0;
      var h = img.naturalHeight || img.height || 0;
      var s = Math.max(w, h);

      // жёсткие подсказки по src
      if (/uploads\/avatars\//i.test(src)) s += 200;
      if (/avatar_/i.test(src)) s += 120;
      if (/avatar/i.test(src + ' ' + cls + ' ' + alt)) s += 60;

      // штрафы за смайлы/иконки/ранги
      if (/smil|emoji|icon|badge|rank|star|award|flag|sprite/i.test(src + ' ' + cls + ' ' + alt)) s -= 150;

      return s;
    }

    imgs.sort(function (a, b) { return score(b) - score(a); });
    return imgs[0] || null;
  }

  // Собираем карту аватаров с текущей страницы:
  // uid -> src, username(lower) -> src
  function buildAvatarIndex() {
    var byUid = new Map();
    var byName = new Map();

    // Берём все ссылки на member.php?uid=... внутри постов/таблиц
    var links = Array.prototype.slice.call(document.querySelectorAll('a[href*="member.php"][href*="uid="]'));

    links.forEach(function (a) {
      var uid = uidFromHref(a.getAttribute('href'));
      if (!uid) return;

      var username = normalizeName(a.textContent || '');
      var unameKey = username.toLowerCase();

      // Контекст для поиска аватара: сначала "пост", если есть, иначе строка таблицы
      var ctx = a.closest('.post, .postbit, .postrow, .post_author, .post_author_info, tr, td, table') || a.parentElement;
      if (!ctx) return;

      var img = pickBestAvatarImg(ctx);
      if (!img) return;

      var src = String(img.getAttribute('src') || '');
      if (!src) return;

      // фикс на темы где avatar src с ?dateline=... — это нормально, оставляем как есть (это "реальный путь")
      if (!byUid.has(uid)) byUid.set(uid, src);
      if (username && !byName.has(unameKey)) byName.set(unameKey, src);
    });

    return { byUid: byUid, byName: byName };
  }

  // fallback: пытаемся добыть аватар с профиля
  function fetchAvatarFromProfile(uid) {
    // относительный путь — это важно, чтобы не зависеть от bburl
    var url = 'member.php?uid=' + encodeURIComponent(String(uid));

    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        // Быстрый хак: если в HTML прямо встречается uploads/avatars/... — вытащим первую ссылку.
        // Это часто работает лучше, чем угадывать DOM.
        var m = html.match(/(https?:\/\/[^"' ]+uploads\/avatars\/[^"' ]+|\/uploads\/avatars\/[^"' ]+)/i);
        if (m && m[1]) return m[1];

        // Если нет — парсим DOM и пробуем найти подходящий img
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var img = pickBestAvatarImg(doc);
        if (!img) return '';
        return String(img.getAttribute('src') || '');
      })
      .catch(function () { return ''; });
  }

  function insertAvatarIntoHeader(headerEl, src) {
    if (!headerEl || !src) return;

    // анти-дубль
    if (headerEl.querySelector('img.af-qa-avatar')) return;

    headerEl.classList.add('af-qa-cite');

    var img = document.createElement('img');
    img.className = 'af-qa-avatar';
    img.alt = 'avatar';
    img.loading = 'lazy';
    img.src = src;

    // Вставляем максимально безопасно:
    // если есть ссылка на профиль — вставим перед ней, иначе в начало заголовка.
    var link = findProfileLinkIn(headerEl);
    if (link && link.parentNode === headerEl) {
      headerEl.insertBefore(img, link);
    } else {
      headerEl.insertBefore(img, headerEl.firstChild);
    }
  }

  function enhanceQuotes(root, index) {
    root = root || document;

    // На практике лучше не ловить "любой blockquote", а только те, что реально цитаты.
    var quotes = root.querySelectorAll('blockquote.mycode_quote, blockquote.quote, blockquote[class*="quote"]');
    if (!quotes.length) return;

    quotes.forEach(function (bq) {
      var header = findQuoteHeader(bq);
      if (!header) return;

      // анти-дубль
      if (header.getAttribute('data-af-qa') === '1') return;

      var link = findProfileLinkIn(header);
      var uid = link ? uidFromHref(link.getAttribute('href')) : 0;

      var name = '';
      if (link) name = normalizeName(link.textContent || '');
      if (!name) name = extractAuthorName(header.textContent || '');

      var src = '';

      if (uid && index.byUid.has(uid)) {
        src = index.byUid.get(uid);
      } else if (name) {
        var key = name.toLowerCase();
        if (index.byName.has(key)) src = index.byName.get(key);
      }

      // помечаем, чтобы не обрабатывать бесконечно
      header.setAttribute('data-af-qa', '1');

      if (src) {
        insertAvatarIntoHeader(header, src);
        return;
      }

      // fallback: если есть uid, пробуем профиль
      if (uid) {
        fetchAvatarFromProfile(uid).then(function (remoteSrc) {
          if (!remoteSrc) return;
          // кешируем
          index.byUid.set(uid, remoteSrc);
          if (name) index.byName.set(name.toLowerCase(), remoteSrc);
          insertAvatarIntoHeader(header, remoteSrc);
        });
      }
    });
  }

  function observeQuotes(index) {
    var mo = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (!m.addedNodes || !m.addedNodes.length) continue;
        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (n.nodeType !== 1) continue;
          enhanceQuotes(n, index);
        }
      }
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }

  onReady(function () {
    var index = buildAvatarIndex();
    enhanceQuotes(document, index);
    observeQuotes(index);
  });

})();
