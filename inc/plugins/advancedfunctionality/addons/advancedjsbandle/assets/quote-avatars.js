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
    if (/^(?:написал|написала|писал|писала|wrote|posted|schrieb)\s*:?$/iu.test(t)) return '';

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

  function insertAvatarIntoHeader(headerEl, src) {
    if (!headerEl || !src) return;

    // Server-rendered quotes may already contain the avatar. Apply the same
    // layout class before the duplicate check so those avatars get aligned
    // and styled just like client-inserted ones.
    headerEl.classList.add('af-qa-cite');

    // анти-дубль
    if (headerEl.querySelector('img.af-qa-avatar')) return;

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
    var selector = 'blockquote.mycode_quote, blockquote.quote, blockquote[class*="quote"]';
    var quotes = Array.from(root.querySelectorAll(selector));
    if (root.matches && root.matches(selector)) quotes.unshift(root);
    if (!quotes.length) return;

    quotes.forEach(function (bq) {
      var header = findQuoteHeader(bq);
      if (!header) return;

      // Keep server-rendered avatars and already-enhanced quotes consistent.
      // A quick-edit response can replace quote contents while retaining the
      // cite node, so the marker must not hide a missing avatar on re-render.
      var existingAvatar = header.querySelector('img.af-qa-avatar');
      if (existingAvatar) {
        header.classList.add('af-qa-cite');
        header.setAttribute('data-af-qa', '1');
        return;
      }
      if (header.getAttribute('data-af-qa') === '1') header.removeAttribute('data-af-qa');

      var link = findProfileLinkIn(header);
      var postLink = header.querySelector('a[href*="pid="]');
      var pidMatch = postLink && postLink.getAttribute('href').match(/[?&]pid=(\d+)/);
      var pid = bq.getAttribute('data-pid') || (pidMatch && pidMatch[1]);
      var post = pid && document.getElementById('post_' + pid);
      var authorLink = post && post.querySelector('.atf-post__name a[href*="uid="], .author_information a[href*="uid="], .post_author a[href*="uid="]');
      if (!link && authorLink) {
        var headerText = header.cloneNode(true);
        headerText.querySelectorAll('a[href*="pid="]').forEach(function (node) { node.remove(); });
        var existingName = extractAuthorName(headerText.textContent || '');
        if (!existingName) { link = authorLink.cloneNode(true); header.insertBefore(link, header.firstChild); }
        else link = authorLink;
      }
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
      if (src) header.setAttribute('data-af-qa', '1');

      if (src) {
        insertAvatarIntoHeader(header, src);
        return;
      }

      // Missing local data is deliberately harmless. Profile HTML is never
      // fetched from a thread; a server-provided map can extend this index.
    });
  }

  function observeQuotes() {
    var posts = document.getElementById('posts') || document.querySelector('.atf-posts, .posts');
    if (!posts) return;

    var pendingRoots = new Set();
    var refreshTimer = 0;

    function addAffectedRoot(node) {
      var el = node && (node.nodeType === 1 ? node : node.parentElement);
      if (!el || !posts.contains(el)) return;
      var quote = el.closest('blockquote.mycode_quote, blockquote.quote, blockquote[class*="quote"]');
      var message = el.closest('.atf-post__message, .post_body');
      var root = quote || message;
      if (!root && el !== posts && el.querySelector) {
        root = el.matches && el.matches('.atf-post__message, .post_body')
          ? el
          : el.querySelector('.atf-post__message, .post_body');
      }
      if (!root && el === posts) root = posts;
      if (!root) return;
      // If a containing message is already queued, a nested quote adds no
      // work. Conversely, replace queued descendants with their new parent.
      var covered = false;
      pendingRoots.forEach(function (queued) {
        if (queued === root || queued.contains(root)) covered = true;
        else if (root.contains(queued)) pendingRoots.delete(queued);
      });
      if (!covered) pendingRoots.add(root);
    }

    function scheduleRefresh() {
      if (refreshTimer) return;
      refreshTimer = window.setTimeout(function () {
        refreshTimer = 0;
        var index = buildAvatarIndex();
        pendingRoots.forEach(function (root) {
          if (root.isConnected) enhanceQuotes(root, index);
        });
        pendingRoots.clear();
      }, 0);
    }

    var mo = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (m.type === 'characterData') addAffectedRoot(m.target);
        if (m.addedNodes && m.addedNodes.length) Array.prototype.forEach.call(m.addedNodes, addAffectedRoot);
        if (m.removedNodes && m.removedNodes.length) addAffectedRoot(m.target);
      }
      if (pendingRoots.size) scheduleRefresh();
    });
    mo.observe(posts, { childList: true, characterData: true, subtree: true });
  }

  onReady(function () {
    var index = buildAvatarIndex();
    enhanceQuotes(document, index);
    observeQuotes();
    document.addEventListener('af:preview-updated', function(e) {
      enhanceQuotes(e.detail && e.detail.root || document, buildAvatarIndex());
    });
  });

})();
