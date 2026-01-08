(function () {
  'use strict';

  if (window.afAdvancedGiphyInit) return;
  window.afAdvancedGiphyInit = true;

  function getCfg() {
    return (window.afAdvancedGiphyConfig && typeof window.afAdvancedGiphyConfig === 'object')
      ? window.afAdvancedGiphyConfig
      : {};
  }

  function getApiKey() {
    var c = getCfg();
    return String(c.key || '').trim();
  }

  function getLimit() {
    var c = getCfg();
    var n = parseInt(c.limit, 10);
    if (!isFinite(n) || n < 5) n = 25;
    if (n > 100) n = 100;
    return n;
  }

  function getRating() {
    var c = getCfg();
    return String(c.rating || 'g').trim();
  }

  function getPoweredImg() {
    var c = getCfg();
    return String(c.poweredImg || '').trim();
  }

  function patchToolbarString(toolbar) {
    if (typeof toolbar !== 'string' || !toolbar) return toolbar;
    if (toolbar.indexOf('advancedgiphy') !== -1) return toolbar;

    if (toolbar.indexOf('horizontalrule') !== -1) {
      return toolbar.replace('horizontalrule', 'horizontalrule,advancedgiphy');
    }
    if (toolbar.indexOf('image') !== -1) {
      return toolbar.replace('image', 'image,advancedgiphy');
    }
    return toolbar.replace(/\s+$/, '') + ',advancedgiphy';
  }

  function giphyUrl(params) {
    var base = 'https://api.giphy.com/v1/gifs/search';
    var parts = [];
    for (var k in params) {
      if (!Object.prototype.hasOwnProperty.call(params, k)) continue;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(params[k])));
    }
    return base + '?' + parts.join('&');
  }

  function getPreviewCandidates(it) {
    var id = (it && it.id) ? String(it.id) : '';
    var urls = [];

    // Превью: лучше начинать с i.giphy.com — чаще проходит и стабильнее.
    if (id) urls.push('https://i.giphy.com/media/' + id + '/200w.gif');
    if (id) urls.push('https://media.giphy.com/media/' + id + '/200w.gif');

    var apiPreview =
      it && it.images && it.images.fixed_width && it.images.fixed_width.url
        ? String(it.images.fixed_width.url)
        : '';
    if (apiPreview) urls.push(apiPreview);

    if (id) urls.push('https://media0.giphy.com/media/' + id + '/200w.gif');

    // убрать дубли
    var seen = {};
    var out = [];
    for (var i = 0; i < urls.length; i++) {
      var u = urls[i];
      if (!u || seen[u]) continue;
      seen[u] = true;
      out.push(u);
    }
    return out;
  }

  // Главное изменение: держим ДВА URL
  function getUrls(it) {
    var id = (it && it.id) ? String(it.id) : '';
    if (!id) return { lite: '', hq: '' };

    // lite — то, что вставляем по умолчанию (быстро грузится)
    var lite = 'https://i.giphy.com/media/' + id + '/200w.gif';

    // hq — по Shift+клик (для красоты/качества)
    var hq = 'https://i.giphy.com/media/' + id + '/giphy.gif';

    return { lite: lite, hq: hq };
  }

  function setImgWithFallback(imgEl, candidates) {
    if (!imgEl || !candidates || !candidates.length) return;

    var idx = 0;

    function next() {
      if (idx >= candidates.length) {
        imgEl.removeAttribute('src');
        imgEl.setAttribute('data-af-giphy-broken', '1');
        return;
      }
      imgEl.src = candidates[idx++];
    }

    imgEl.onerror = function () {
      next();
    };

    next();
  }

  /* ------------------------------------------------------------
   *  Реанимация только для HQ-гифок (giphy.gif).
   *  Для 200w.gif (lite) это обычно не нужно и только плодит запросы.
   * ------------------------------------------------------------ */

  function extractGiphyIdFromUrl(url) {
    url = String(url || '');

    var m = url.match(/\/media\/([a-zA-Z0-9]+)\/giphy\.gif/i);
    if (m && m[1]) return m[1];

    m = url.match(/\/media\/([a-zA-Z0-9]+)\/200w\.gif/i);
    if (m && m[1]) return m[1];

    m = url.match(/giphy\.com\/media\/([a-zA-Z0-9]+)\//i);
    if (m && m[1]) return m[1];

    return '';
  }

  function hqCandidatesById(id) {
    var cb = 'afcb=' + Date.now() + '_' + Math.random().toString(16).slice(2);
    return [
      'https://i.giphy.com/media/' + id + '/giphy.gif',
      'https://media.giphy.com/media/' + id + '/giphy.gif',
      'https://media0.giphy.com/media/' + id + '/giphy.gif',
      'https://i.giphy.com/media/' + id + '/giphy.gif?' + cb
    ];
  }

  function armGiphyImg(img) {
    try {
      if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') return;

      var srcAttr = img.getAttribute('src') || '';
      if (srcAttr.indexOf('giphy.com') === -1) return;

      // ВАЖНО: реанимируем только тяжёлые HQ (giphy.gif). Лёгкие 200w — не трогаем.
      if (!/\/giphy\.gif(\?|$)/i.test(srcAttr)) return;

      if (img.getAttribute('data-af-giphy-armed') === '1') return;
      img.setAttribute('data-af-giphy-armed', '1');

      if (img.complete && img.naturalWidth > 0) return;

      var id = extractGiphyIdFromUrl(srcAttr);
      if (!id) return;

      var tries = parseInt(img.getAttribute('data-af-giphy-tries') || '0', 10);
      if (!isFinite(tries)) tries = 0;

      function retry() {
        tries++;
        img.setAttribute('data-af-giphy-tries', String(tries));
        if (tries > 3) return;

        var cands = hqCandidatesById(id);
        var shifted = cands.slice(tries - 1).concat(cands.slice(0, tries - 1));

        for (var i = 0; i < shifted.length; i++) {
          if (img.src !== shifted[i]) {
            img.src = shifted[i];
            break;
          }
        }
      }

      img.addEventListener('error', function () {
        retry();
      });

      window.setTimeout(function () {
        if (!img.isConnected) return;
        if (img.complete && img.naturalWidth > 0) return;
        retry();
      }, 900);

    } catch (e) {}
  }

  function scanForGiphyImgs(root) {
    try {
      var scope = root && root.querySelectorAll ? root : document;
      var imgs = scope.querySelectorAll('img');
      for (var i = 0; i < imgs.length; i++) armGiphyImg(imgs[i]);
    } catch (e) {}
  }

  function installGiphyImgObserver() {
    if (window.afGiphyImgObserverInstalled) return;
    window.afGiphyImgObserverInstalled = true;

    scanForGiphyImgs(document);

    if (!window.MutationObserver) return;

    var obs = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (!m.addedNodes || !m.addedNodes.length) continue;

        for (var j = 0; j < m.addedNodes.length; j++) {
          var node = m.addedNodes[j];
          if (!node || node.nodeType !== 1) continue;

          if (node.tagName === 'IMG') {
            armGiphyImg(node);
          } else {
            scanForGiphyImgs(node);
          }
        }
      }
    });

    obs.observe(document.documentElement || document.body, { childList: true, subtree: true });
  }

  /* -------------------- Dropdown UI -------------------- */

  function buildDropdown(editor, onPick) {
    var $ = window.jQuery;
    var poweredImg = getPoweredImg();

    var $wrap = $('<div class="af-giphy-dd"></div>');
    var $searchRow = $('<div class="af-giphy-search"></div>');
    var $input = $('<input type="text" placeholder="GIPHY… (введи запрос)"/>');
    var $results = $('<div class="af-giphy-results"></div>');
    var $footer = $('<div class="af-giphy-footer"></div>');

    $searchRow.append($input);
    $wrap.append($searchRow);
    $wrap.append($results);

    if (poweredImg) {
      $footer.append($('<img alt="Powered by GIPHY" />').attr('src', poweredImg));
    }
    // Подсказка юзеру прямо в интерфейсе
    $footer.append($('<div class="af-giphy-hint"></div>').text('Клик — вставить лёгкую (200w). Shift+клик — вставить HQ (giphy.gif).'));
    $wrap.append($footer);

    var state = { q: '', offset: 0, loading: false, ended: false };

    function showMsg(msg) {
      $results.empty().append($('<div class="af-giphy-msg"></div>').text(msg));
    }

    function fetchMore(reset) {
      if (state.loading || state.ended) return;

      var apiKey = getApiKey();
      var limit = getLimit();
      var rating = getRating();

      if (!apiKey) {
        showMsg('Не задан GIPHY API key (ACP → Settings → AdvancedGiphy).');
        return;
      }
      if (!state.q) {
        showMsg('Начни печатать запрос выше 🙂');
        return;
      }

      state.loading = true;

      if (reset) {
        state.offset = 0;
        state.ended = false;
        $results.empty();
      }

      var url = giphyUrl({
        api_key: apiKey,
        q: state.q,
        limit: limit,
        rating: rating,
        offset: state.offset
      });

      $.getJSON(url)
        .done(function (data) {
          var items = (data && data.data) ? data.data : [];

          if (!items.length && state.offset === 0) {
            showMsg('Ничего не найдено. Попробуй другой запрос.');
            state.ended = true;
            return;
          }
          if (!items.length) {
            state.ended = true;
            return;
          }

          for (var i = 0; i < items.length; i++) {
            var it = items[i];

            var urls = getUrls(it);
            if (!urls.lite || !urls.hq) continue;

            var $a = $('<a class="af-giphy-item" href="#"></a>');

            var img = document.createElement('img');
            img.loading = 'lazy';
            img.referrerPolicy = 'no-referrer';
            img.alt = 'giphy';

            var candidates = getPreviewCandidates(it);
            setImgWithFallback(img, candidates);

            $a.append(img);

            (function (liteUrl, hqUrl) {
              $a.on('click', function (e) {
                e.preventDefault();

                // По умолчанию — lite. Shift — HQ.
                var chosen = (e && e.shiftKey) ? hqUrl : liteUrl;

                try { onPick(chosen); } catch (err) {}

                try { editor.closeDropDown(true); }
                catch (e1) {
                  try { editor.closeDropDown(); } catch (e2) {}
                }

                // Быстрый скан на случай, если страница сразу вставила img через ajax
                setTimeout(function () { scanForGiphyImgs(document); }, 150);
              });
            })(urls.lite, urls.hq);

            $results.append($a);
          }

          state.offset += items.length;
          if (items.length < getLimit()) state.ended = true;
        })
        .fail(function (xhr) {
          var st = xhr && xhr.status ? xhr.status : 0;
          if (st === 401 || st === 403) {
            showMsg('GIPHY отверг ключ API (401/403). Проверь ключ и ограничения в кабинете GIPHY.');
          } else if (state.offset === 0) {
            showMsg('Ошибка запроса к GIPHY. Проверь интернет/доступ/блокировки.');
          }
        })
        .always(function () { state.loading = false; });
    }

    var t = 0;
    $input.on('input', function () {
      var v = String($input.val() || '').trim();
      window.clearTimeout(t);
      t = window.setTimeout(function () {
        state.q = v;
        state.offset = 0;
        state.ended = false;
        fetchMore(true);
      }, 250);
    });

    $input.on('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        state.q = String($input.val() || '').trim();
        fetchMore(true);
      }
    });

    $results.on('scroll', function () {
      var el = $results.get(0);
      if (!el) return;
      if (el.scrollTop + el.clientHeight >= el.scrollHeight - 120) fetchMore(false);
    });

    showMsg(getApiKey() ? 'Начни печатать запрос выше 🙂' : 'Не задан GIPHY API key (ACP → Settings → AdvancedGiphy).');
    setTimeout(function () { $input.trigger('focus'); }, 0);

    return $wrap.get(0);
  }

  function ensureCommandRegistered() {
    var $ = window.jQuery;
    if (!$ || !$.sceditor || !$.sceditor.command) return false;

    if ($.sceditor.command.get && $.sceditor.command.get('advancedgiphy')) {
      return true;
    }

    $.sceditor.command.set('advancedgiphy', {
      tooltip: 'GIPHY',

      exec: function (caller) {
        var editor = this;
        var dd = buildDropdown(editor, function (gifUrl) {
          if (typeof editor.insertText === 'function') editor.insertText('[img]' + gifUrl + '[/img]');
          else if (typeof editor.insert === 'function') editor.insert('[img]' + gifUrl + '[/img]');
        });
        editor.createDropDown(caller, 'advancedgiphy', dd);
      },

      txtExec: function (caller) {
        var editor = this;
        var dd = buildDropdown(editor, function (gifUrl) {
          editor.insertText('[img]' + gifUrl + '[/img]');
        });
        editor.createDropDown(caller, 'advancedgiphy', dd);
      }
    });

    return true;
  }

  function hookSceditorCreate() {
    var $ = window.jQuery;
    if (!$ || !$.fn || typeof $.fn.sceditor !== 'function') return false;

    if (window.sceditor_options && typeof window.sceditor_options === 'object' && typeof window.sceditor_options.toolbar === 'string') {
      window.sceditor_options.toolbar = patchToolbarString(window.sceditor_options.toolbar);
    }

    if ($.fn.sceditor.__afAdvancedGiphyWrapped) return true;

    var orig = $.fn.sceditor;

    var wrapped = function (options) {
      ensureCommandRegistered();
      try {
        if (options && typeof options === 'object' && typeof options.toolbar === 'string') {
          options.toolbar = patchToolbarString(options.toolbar);
        }
      } catch (e) {}
      return orig.apply(this, arguments);
    };

    wrapped.__afAdvancedGiphyWrapped = true;
    $.fn.sceditor = wrapped;
    $.fn.sceditor.__afAdvancedGiphyWrapped = true;

    return true;
  }

  function boot() {
    var okHook = hookSceditorCreate();
    var okCmd = ensureCommandRegistered();
    return okHook && okCmd;
  }

  // Реаниматор оставляем, но он теперь трогает только тяжёлые giphy.gif (если ты вставишь их через Shift+клик)
  installGiphyImgObserver();

  if (boot()) return;

  var tries = 0;
  var timer = setInterval(function () {
    tries++;
    if (boot() || tries >= 120) clearInterval(timer);
  }, 50);
})();
