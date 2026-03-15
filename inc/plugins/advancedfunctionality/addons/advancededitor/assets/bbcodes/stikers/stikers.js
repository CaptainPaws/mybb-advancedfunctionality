(function () {
  'use strict';

  var MAX_RECENT = 20;
  var state = {
    loaded: false,
    loading: false,
    data: null,
    removeMode: false
  };

  function payload() {
    return (window.afAePayload || window.afAdvancedEditorPayload || {}).stikers || null;
  }

  function isAuth() {
    var p = payload();
    return !!(p && Number(p.isAuth) === 1);
  }

  function getSizes(source) {
    source = source || {};
    var sizes = source.sizes || {};
    return {
      thumb: Number(sizes.thumb_size || 50) || 50,
      post: Number(sizes.post_size || 120) || 120
    };
  }

  function getStickerPathFragment() {
    return '/inc/plugins/advancedfunctionality/addons/advancededitor/assets/stikers/';
  }

  function isStickerSrc(src) {
    return String(src || '').indexOf(getStickerPathFragment()) !== -1;
  }

  function applyStickerSizeToImage(img, size, skipModalCheck) {
    if (!img) return;

    if (!skipModalCheck && typeof img.closest === 'function' && img.closest('#af-ae-stikers-modal')) {
      return;
    }

    var src = String(img.getAttribute('src') || img.src || '');
    if (!isStickerSrc(src)) return;

    img.style.width = size + 'px';
    img.style.height = size + 'px';
    img.style.maxWidth = 'none';
    img.style.maxHeight = 'none';
    img.style.objectFit = 'contain';
  }

  function applyStickerPostSizeToDocument(doc, size, skipModalCheck) {
    if (!doc || !doc.querySelectorAll) return;

    var imgs = doc.querySelectorAll('img');
    for (var i = 0; i < imgs.length; i++) {
      applyStickerSizeToImage(imgs[i], size, !!skipModalCheck);
    }
  }

  function ensureStickerIframeCss(doc, size) {
    if (!doc || !doc.head || !doc.createElement) return;

    var id = 'af-ae-stikers-iframe-css';
    var el = doc.getElementById(id);

    if (!el) {
      el = doc.createElement('style');
      el.id = id;
      doc.head.appendChild(el);
    }

    el.textContent =
      'img[src*="' + getStickerPathFragment() + '"]{' +
      'width:' + size + 'px !important;' +
      'height:' + size + 'px !important;' +
      'max-width:none !important;' +
      'max-height:none !important;' +
      'object-fit:contain;' +
      '}';
  }

  function applyStickerPostSizeEverywhere(size) {
    applyStickerPostSizeToDocument(document, size, false);

    var iframes = document.querySelectorAll('iframe');
    for (var i = 0; i < iframes.length; i++) {
      try {
        var doc = iframes[i].contentDocument || (iframes[i].contentWindow && iframes[i].contentWindow.document) || null;
        if (!doc) continue;

        ensureStickerIframeCss(doc, size);
        applyStickerPostSizeToDocument(doc, size, true);
      } catch (e) {}
    }
  }

  function ensureStickerRuntimeObserver(source) {
    var size = getSizes(source).post;

    if (window.__afAeStickerRuntimeObserver) {
      window.__afAeStickerRuntimeObserver.size = size;
      applyStickerPostSizeEverywhere(size);
      return;
    }

    var state = {
      timer: 0,
      size: size
    };

    function schedule() {
      if (state.timer) {
        clearTimeout(state.timer);
      }

      state.timer = window.setTimeout(function () {
        state.timer = 0;
        applyStickerPostSizeEverywhere(state.size);
      }, 30);
    }

    if (document.body && window.MutationObserver) {
      var mo = new MutationObserver(schedule);
      mo.observe(document.body, { childList: true, subtree: true });
      state.observer = mo;
    }

    window.__afAeStickerRuntimeObserver = state;

    schedule();
    window.setTimeout(schedule, 100);
    window.setTimeout(schedule, 250);
  }

  function ensureStickerRuntimeCss(source) {
    var sizes = getSizes(source);
    var id = 'af-ae-stikers-runtime-css';
    var el = document.getElementById(id);
    var stickerPath = getStickerPathFragment();

    if (!el) {
      el = document.createElement('style');
      el.id = id;
      document.head.appendChild(el);
    }

    el.textContent =
      ':root{' +
      '--af-ae-stiker-thumb-size:' + sizes.thumb + 'px;' +
      '--af-ae-stiker-post-size:' + sizes.post + 'px;' +
      '}' +
      '.post img[src*="' + stickerPath + '"],' +
      '.post_body img[src*="' + stickerPath + '"],' +
      '.post_content img[src*="' + stickerPath + '"],' +
      '.scaleimages img[src*="' + stickerPath + '"],' +
      '.editor_content img[src*="' + stickerPath + '"],' +
      '.preview img[src*="' + stickerPath + '"],' +
      '.af-ae-previewparsed img[src*="' + stickerPath + '"]{' +
      'width:' + sizes.post + 'px !important;' +
      'height:' + sizes.post + 'px !important;' +
      'max-width:none !important;' +
      'max-height:none !important;' +
      'object-fit:contain;' +
      '}';

    applyStickerPostSizeEverywhere(sizes.post);
    ensureStickerRuntimeObserver(source);
  }
  function req(url, method, body, cb) {
    var root = window.afAePayload || window.afAdvancedEditorPayload || {};
    var fd = body instanceof FormData ? body : new FormData();

    var postKey = String(
      root.postKey ||
      root.post_key ||
      root.my_post_key ||
      ''
    );

    if (!fd.get('my_post_key') && postKey) {
      fd.append('my_post_key', postKey);
    }

    fetch(url, {
      method: method || 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) {
        return r.text().then(function (text) {
          return {
            ok: r.ok,
            status: r.status,
            text: text
          };
        });
      })
      .then(function (resp) {
        var json = null;

        try {
          json = JSON.parse(resp.text);
        } catch (e) {
          json = null;
        }

        if (!json) {
          cb(new Error('Non-JSON response [' + resp.status + ']: ' + resp.text.slice(0, 500)));
          return;
        }

        if (!resp.ok) {
          cb(new Error(json.message || ('HTTP ' + resp.status)), json);
          return;
        }

        cb(null, json);
      })
      .catch(function (e) {
        cb(e);
      });
  }

  function recentKey() {
    var uid = isAuth() ? 'u1' : 'g';
    return 'af_ae_stikers_recent_' + uid;
  }

  function readLocalRecent() {
    try {
      return JSON.parse(localStorage.getItem(recentKey()) || '[]') || [];
    } catch (e) {
      return [];
    }
  }

  function writeLocalRecent(list) {
    list = Array.isArray(list) ? list : [];

    list = list.filter(function (x) {
      return x && String(x.url || '') !== '';
    });

    if (list.length > MAX_RECENT) {
      list = list.slice(0, MAX_RECENT);
    }

    try {
      localStorage.setItem(recentKey(), JSON.stringify(list));
    } catch (e) {}
  }

  function removeLocalRecent(stickerId, stickerUrl) {
    var sid = Number(stickerId || 0);
    var surl = String(stickerUrl || '');

    var list = readLocalRecent().filter(function (x) {
      if (!x) return false;

      var xid = Number(x.id || 0);
      var xurl = String(x.url || '');

      if (sid > 0 && xid === sid) {
        return false;
      }

      if (surl !== '' && xurl === surl) {
        return false;
      }

      return xurl !== '';
    });

    writeLocalRecent(list);
  }  

  function pushLocalRecent(item) {
    var list = readLocalRecent().filter(function (x) {
      return x && x.url !== item.url;
    });

    list.unshift(item);
    writeLocalRecent(list);
  }
  function htmlEscape(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function renderGrid(items, removable, loading) {
    items = Array.isArray(items) ? items : [];

    items = items.filter(function (s) {
      return s && String(s.url || '') !== '';
    });

    if (loading) {
      return '<div class="af-ae-stikers-loading">Загрузка...</div>';
    }

    if (!items.length) {
      return '<div class="af-ae-stikers-empty">Пусто</div>';
    }

    return '<div class="af-ae-stikers-grid">' + items.map(function (s) {
      return '<button type="button" class="af-ae-stiker" data-id="' + Number(s.id || 0) + '" data-url="' + htmlEscape(s.url || '') + '" title="' + htmlEscape(s.title || '') + '">' +
        '<img src="' + htmlEscape(s.url || '') + '" alt="' + htmlEscape(s.title || '') + '">' +
        (removable ? '<span class="af-ae-stiker-del" data-del="1">×</span>' : '') +
        '</button>';
    }).join('') + '</div>';
  }

  function insertSticker(ed, url) {
    if (!ed || !url) return;

    try {
      if (typeof window.afAeIsSourceMode === 'function' && window.afAeIsSourceMode(ed)) {
        if (typeof ed.insertText === 'function') {
          ed.insertText('[img]' + url + '[/img]');
        } else if (typeof ed.insert === 'function') {
          ed.insert('[img]' + url + '[/img]', '');
        }
      } else if (typeof ed.insert === 'function') {
        ed.insert('[img]' + url + '[/img]', '');
      }
    } catch (e) {}

    var postSize = getSizes(payload()).post;

    window.setTimeout(function () {
      applyStickerPostSizeEverywhere(postSize);
    }, 0);

    window.setTimeout(function () {
      applyStickerPostSizeEverywhere(postSize);
    }, 60);

    window.setTimeout(function () {
      applyStickerPostSizeEverywhere(postSize);
    }, 180);
  }

  function openModal(ed) {
    var p = payload();
    if (!p) return;

    ensureStickerRuntimeCss(p);

    var old = document.getElementById('af-ae-stikers-modal');
    if (old) old.remove();

    var wrap = document.createElement('div');
    wrap.id = 'af-ae-stikers-modal';
    wrap.innerHTML =
      '<div class="af-ae-stikers-backdrop"></div>' +
      '<div class="af-ae-stikers-modal">' +
        '<div class="af-ae-stikers-head">' +
          '<strong>Стикеры</strong>' +
          '<button type="button" class="af-ae-stikers-close">×</button>' +
        '</div>' +
        '<div class="af-ae-stikers-tabs"></div>' +
        '<div class="af-ae-stikers-body"></div>' +
      '</div>';

    document.body.appendChild(wrap);

    function close() {
      wrap.remove();
    }

    wrap.querySelector('.af-ae-stikers-close').addEventListener('click', close);
    wrap.querySelector('.af-ae-stikers-backdrop').addEventListener('click', close);

    function buildInitialData() {
      if (state.data) {
        return state.data;
      }

      return {
        recent: readLocalRecent(),
        user_stickers: [],
        categories: [],
        sizes: (p && p.sizes) || {}
      };
    }

    function normalizeRecentList(data) {
      var recent = Array.isArray(data && data.recent) ? data.recent : [];

      recent = recent.filter(function (item) {
        return item && String(item.url || '') !== '';
      });

      // Пока сервер ещё не ответил — можно показать локальный recent как временный плейсхолдер.
      // После ответа сервера localStorage больше НЕ подмешиваем.
      if (!state.loaded && !recent.length) {
        recent = readLocalRecent().filter(function (item) {
          return item && String(item.url || '') !== '';
        });
      }

      return recent;
    }

    function paint(data) {
      data = data || buildInitialData();
      ensureStickerRuntimeCss(data);

      var recentList = normalizeRecentList(data);

      var tabs = [
        {
          key: 'recent',
          title: 'Недавние стикеры',
          list: recentList
        },
        {
          key: 'mine',
          title: 'Свои стикеры',
          list: data.user_stickers || []
        }
      ];

      (data.categories || []).forEach(function (c) {
        tabs.push({
          key: 'cat_' + c.id,
          title: c.title,
          list: c.stickers || []
        });
      });

      var tabsEl = wrap.querySelector('.af-ae-stikers-tabs');
      var bodyEl = wrap.querySelector('.af-ae-stikers-body');

      tabsEl.innerHTML = tabs.map(function (t, i) {
        return '<button type="button" class="af-ae-stikers-tab' + (i === 0 ? ' is-active' : '') + '" data-tab="' + t.key + '">' + htmlEscape(t.title) + '</button>';
      }).join('');

      function renderTab(key) {
        var t = tabs.filter(function (x) { return x.key === key; })[0] || tabs[0];
        var extra = '';

        if (t.key === 'mine' && isAuth()) {
          extra =
            '<div class="af-ae-stikers-tools">' +
              '<label class="af-ae-stikers-btn af-ae-stikers-upload-btn">' +
                '<input type="file" id="af-ae-stiker-upload" accept=".webp,.gif,.png,.jpg,.jpeg">' +
                '<span>Добавить стикер</span>' +
              '</label>' +
              '<button type="button" class="af-ae-stikers-btn af-ae-stikers-gear-btn" id="af-ae-stiker-toggle" title="Режим удаления">⚙</button>' +
            '</div>';
        }

        bodyEl.innerHTML = extra + renderGrid(
          t.list,
          t.key === 'mine' && state.removeMode,
          state.loading && !state.loaded && !state.data
        );

        var upload = bodyEl.querySelector('#af-ae-stiker-upload');
        if (upload) {
          upload.addEventListener('change', function () {
            var f = upload.files && upload.files[0];
            if (!f) return;

            var fd = new FormData();
            fd.append('sticker', f);

            req(p.uploadUrl, 'POST', fd, function (err, res) {
              if (err || !res || !res.success) {
                alert((res && res.message) || (err && err.message) || 'Ошибка загрузки');
                return;
              }
              loadAndPaint();
            });
          });
        }

        var toggle = bodyEl.querySelector('#af-ae-stiker-toggle');
        if (toggle) {
          toggle.addEventListener('click', function () {
            state.removeMode = !state.removeMode;
            renderTab('mine');
          });
        }

        bodyEl.querySelectorAll('.af-ae-stiker').forEach(function (btn) {
          btn.addEventListener('click', function (ev) {
            var del = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-del') === '1';
            var id = Number(btn.getAttribute('data-id') || 0);
            var url = btn.getAttribute('data-url') || '';

            if (del && t.key === 'mine') {
              var fd = new FormData();
              fd.append('id', String(id));

              req(p.deleteUrl, 'POST', fd, function (err, res) {
                if (err || !res || !res.success) {
                  alert((res && res.message) || (err && err.message) || 'Ошибка удаления');
                  return;
                }

                removeLocalRecent(id, url);

                if (state.data && Array.isArray(state.data.recent)) {
                  state.data.recent = state.data.recent.filter(function (item) {
                    if (!item) return false;
                    if (Number(item.id || 0) === id) return false;
                    if (String(item.url || '') === url) return false;
                    return true;
                  });
                }

                loadAndPaint();
              });
              return;
            }

            insertSticker(ed, url);
            pushLocalRecent({ id: id, url: url, title: '' });

            var fd2 = new FormData();
            fd2.append('sticker_id', String(id));
            fd2.append('sticker_url', url);

            req(p.recentUrl, 'POST', fd2, function () {});
            close();
          });
        });
      }

      tabsEl.querySelectorAll('.af-ae-stikers-tab').forEach(function (b) {
        b.addEventListener('click', function () {
          tabsEl.querySelectorAll('.af-ae-stikers-tab').forEach(function (x) {
            x.classList.remove('is-active');
          });
          b.classList.add('is-active');
          renderTab(b.getAttribute('data-tab'));
        });
      });

      renderTab('recent');
    }

    function loadAndPaint() {
      if (state.loading) return;

      state.loading = true;

      req(p.listUrl, 'POST', new FormData(), function (err, res) {
        state.loading = false;

        if (err || !res || !res.success) {
          alert((res && res.message) || (err && err.message) || 'Ошибка загрузки стикеров');
          return;
        }

        state.loaded = true;
        state.data = res.data || {};

        if (isAuth()) {
          writeLocalRecent(Array.isArray(state.data.recent) ? state.data.recent : []);
        }

        ensureStickerRuntimeCss(state.data);
        paint(state.data);
      });
    }

    paint(buildInitialData());
    window.requestAnimationFrame(loadAndPaint);
  }

  window.af_ae_stikers_exec = function (ed) {
    openModal(ed);
  };
})();
