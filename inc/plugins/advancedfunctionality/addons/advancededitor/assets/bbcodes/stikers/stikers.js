(function () {
  'use strict';

  var MAX_RECENT = 20;
  var state = {
    loaded: false,
    loading: false,
    data: null,
    removeMode: false,
    activeTab: 'recent',
    uploadSource: 'file'
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
      return '<div class="af-ae-stikers-loading" role="status">Загрузка стикеров…</div>';
    }

    if (!items.length) {
      return '<div class="af-ae-stikers-empty"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h8m0 0 7-7h-5a2 2 0 0 0-2 2v5ZM8 8h.01M15 8h.01M8 12q3 3 6 0"/></svg><strong>Здесь пока нет стикеров</strong><span>Выберите другую категорию или добавьте свой стикер.</span></div>';
    }

    return '<div class="af-ae-stikers-grid">' + items.map(function (s) {
      return '<button type="button" class="af-ae-stiker" data-id="' + Number(s.id || 0) + '" data-external="'+(s.source==='url'?'1':'0')+'" data-url="' + htmlEscape(s.url || '') + '" title="' + htmlEscape(s.title || '') + '">' +
        '<img src="' + htmlEscape(s.url || '') + '" alt="' + htmlEscape(s.title || '') + '">' +
        (s.source==='url' ? '<span class="af-ae-stiker-source" title="Внешний стикер">URL</span>' : '') +
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
    if (old) {
      if (old.afAeClose) old.afAeClose();
      else old.remove();
    }

    var previousFocus = document.activeElement;
    var wrap = document.createElement('div');
    wrap.id = 'af-ae-stikers-modal';
    wrap.innerHTML =
      '<div class="af-ae-stikers-backdrop"></div>' +
      '<div class="af-ae-stikers-modal" role="dialog" aria-modal="true" aria-labelledby="af-ae-stikers-title">' +
        '<div class="af-ae-stikers-head">' +
          '<strong id="af-ae-stikers-title">Стикеры</strong>' +
          '<button type="button" class="af-ae-stikers-close" aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>' +
        '</div>' +
        '<div class="af-ae-stikers-tabs"></div>' +
        '<div class="af-ae-stikers-controls"></div>' +
        '<div class="af-ae-stikers-body"></div>' +
      '</div>';

    wrap.addEventListener('error',function(event){
      var image=event.target, card=image.closest && image.closest('.af-ae-stiker[data-external="1"]');
      if(!card || image.tagName!=='IMG') return;
      image.style.display='none';
      if(card.querySelector('.af-ae-stiker-unavailable')) return;
      var fallback=document.createElement('span');fallback.className='af-ae-stiker-unavailable';fallback.textContent='Недоступен';fallback.setAttribute('role','img');fallback.setAttribute('aria-label','Внешний стикер недоступен');card.appendChild(fallback);
    },true);
    document.body.appendChild(wrap);
    if (window.afAtfDecorateSurface) window.afAtfDecorateSurface(wrap.querySelector('.af-ae-stikers-modal'));
    function onKeydown(event) {
      if (event.key === 'Escape') { event.preventDefault(); close(); return; }
      if (event.key !== 'Tab') return;
      var nodes = Array.from(wrap.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),[tabindex="0"]')).filter(function (node) { return node.getClientRects().length && !node.closest('[hidden]'); });
      var first = nodes[0], last = nodes[nodes.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    wrap.addEventListener('keydown', onKeydown);
    wrap.querySelector('.af-ae-stikers-close').focus();

    function close() {
      document.removeEventListener('af:editor-destroyed', onDestroy);
      wrap.remove();
      if (previousFocus && previousFocus.isConnected) previousFocus.focus();
    }
    function onDestroy(event) { if (event.detail && event.detail.textarea === ed.textarea) close(); }
    wrap.afAeClose = close;
    document.addEventListener('af:editor-destroyed', onDestroy);

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
      var controlsEl = wrap.querySelector('.af-ae-stikers-controls');

      tabsEl.innerHTML = tabs.map(function (t, i) {
        return '<button type="button" class="af-ae-stikers-tab' + (i === 0 ? ' is-active' : '') + '" data-tab="' + t.key + '">' + htmlEscape(t.title) + '</button>';
      }).join('');

      function renderTab(key) {
        var t = tabs.filter(function (x) { return x.key === key; })[0] || tabs[0];
        state.activeTab=t.key;
        tabsEl.querySelectorAll('.af-ae-stikers-tab').forEach(function(b){b.classList.toggle('is-active',b.getAttribute('data-tab')===t.key);});
        var extra = '';

        if (t.key === 'mine' && isAuth()) {
          var storage = data.storage;
          var limits = data.limits || p.limits || {};
          var mb = function (bytes) { return (Number(bytes) / 1048576).toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
          var percent = storage && storage.percent != null ? Number(storage.percent) : null;
          var usage = storage ? 'Использовано: ' + mb(storage.used_bytes) + (storage.limit_bytes > 0 ? ' / ' + mb(storage.limit_bytes) + ' МБ · Осталось: ' + mb(storage.available_bytes) + ' МБ' : ' МБ · Без ограничений') : 'Загрузка данных о стикерах…';
          var external = data.sizes && Number(data.sizes.allow_external) === 1;
          var formats = Array.isArray(limits.ext) ? limits.ext : [];
          var accept = formats.map(function (ext) { return '.' + ext; }).join(',');
          extra = '<div class="af-ae-stikers-storage" role="status"><span>' + htmlEscape(usage) + '</span>' +
            (percent !== null && storage.limit_bytes > 0 ? '<span class="af-ae-stikers-percent">' + Math.round(percent) + '%</span><progress max="100" value="' + percent + '" aria-label="Заполнение квоты стикеров"></progress>' : '') + '</div>' +
            (storage && storage.full ? '<p class="af-ae-stikers-warning">Квота исчерпана. Удалите локальные стикеры или добавьте внешний URL, если он разрешён.</p>' : '') +
            (external ? '<div class="af-ae-stikers-source"><select data-sticker-source hidden aria-hidden="true" tabindex="-1"><option value="file">С устройства</option><option value="url">По внешнему URL</option></select><button type="button" data-source="file">С устройства</button><button type="button" data-source="url">По внешнему URL</button></div>' : '') +
            '<div class="af-ae-stikers-entry"><div class="af-ae-stikers-tools">' +
              '<label class="af-ae-stikers-upload-btn" title="' + htmlEscape(formats.join(', ').toUpperCase() + (limits.max_size ? ' · до ' + mb(limits.max_size) + ' МБ' : '')) + '">' +
                '<input type="file" id="af-ae-stiker-upload" accept="' + htmlEscape(accept) + '">' +
                '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 16v4h16v-4M12 16V3m-5 5 5-5 5 5"/></svg>' +
                '<span>Выбрать файл</span>' +
              '</label>' +
              '<button type="button" class="af-ae-stikers-btn af-ae-stikers-gear-btn" id="af-ae-stiker-toggle" title="' + (state.removeMode ? 'Режим удаления включён: нажмите крестик на стикере' : 'Включить режим удаления') + '" aria-label="Режим удаления" aria-pressed="' + state.removeMode + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 3-1 3-3 1-2 4 2 2v4l4 2 3-1 3 1 4-2v-4l2-2-2-4-3-1-1-3z"/><circle cx="12" cy="12" r="3"/></svg></button>' +
            '</div>' +
            (external ? '<form class="af-ae-stikers-url" title="Сохраняется только ссылка. Локальная квота не расходуется."><label for="af-ae-sticker-url">URL изображения</label><input id="af-ae-sticker-url" type="url" name="sticker_url" placeholder="https://…" required><input aria-label="Название стикера" type="text" name="sticker_title" maxlength="60" placeholder="Название (необязательно)"><button class="af-ae-stikers-btn" type="submit">Добавить</button></form>' : '') + '</div>';
        }

        controlsEl.innerHTML = extra;
        controlsEl.hidden = !extra;
        bodyEl.innerHTML = renderGrid(
          t.list,
          t.key === 'mine' && state.removeMode,
          state.loading && !state.loaded && !state.data
        );

        var urlForm=controlsEl.querySelector('.af-ae-stikers-url');
        if(urlForm) urlForm.addEventListener('submit',function(event){
          event.preventDefault();
          var fd=new FormData(urlForm), button=urlForm.querySelector('button');button.disabled=true;
          req(p.uploadUrl,'POST',fd,function(err,res){button.disabled=false;if(err || !res || !res.success){alert((res && res.message) || 'Не удалось добавить ссылку.');return;}loadAndPaint();});
        });
        var sourceSelect=controlsEl.querySelector('[data-sticker-source]');
        var fileLabel=controlsEl.querySelector('.af-ae-stikers-upload-btn');
        function syncUploadSource() {
          var byUrl=!!sourceSelect && sourceSelect.value==='url';
          if(sourceSelect) state.uploadSource=sourceSelect.value;
          if(fileLabel) fileLabel.hidden=byUrl;
          if(urlForm) urlForm.hidden=!byUrl;
          controlsEl.querySelectorAll('[data-source]').forEach(function (button) { button.setAttribute('aria-pressed', String(button.dataset.source === state.uploadSource)); });
        }
        if(sourceSelect) {sourceSelect.value=state.uploadSource;sourceSelect.addEventListener('change',syncUploadSource);}
        controlsEl.querySelectorAll('[data-source]').forEach(function (button) {
          button.addEventListener('click', function () { sourceSelect.value=button.dataset.source; sourceSelect.dispatchEvent(new Event('change')); });
        });
        syncUploadSource();
        var upload = controlsEl.querySelector('#af-ae-stiker-upload');
        if (upload) {
          upload.disabled=!!(data.storage && data.storage.full);
          fileLabel.classList.toggle('is-disabled', upload.disabled);
          upload.addEventListener('change', function () {
            var f = upload.files && upload.files[0];
            if (!f) return;

            var fd = new FormData();
            fd.append('sticker', f);
            upload.disabled=true; fileLabel.setAttribute('aria-busy','true');

            req(p.uploadUrl, 'POST', fd, function (err, res) {
              upload.disabled=false; fileLabel.removeAttribute('aria-busy');
              if (err || !res || !res.success) {
                alert((res && res.message) || (err && err.message) || 'Ошибка загрузки');
                return;
              }
              loadAndPaint();
            });
          });
        }

        var toggle = controlsEl.querySelector('#af-ae-stiker-toggle');
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
            pushLocalRecent({ id: id, url: url, title: '', source: btn.getAttribute('data-external')==='1'?'url':'local' });

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

      renderTab(state.activeTab || 'recent');
    }

    function loadAndPaint() {
      if (state.loading) {state.reloadPending=true;return;}

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
        if(state.reloadPending){state.reloadPending=false;loadAndPaint();}
      });
    }

    paint(buildInitialData());
    window.requestAnimationFrame(loadAndPaint);
  }

  window.af_ae_stikers_exec = function (ed) {
    openModal(ed);
  };
})();
