(function () {
  'use strict';

  var MAX_RECENT = 20;
  var state = { loaded: false, data: null, removeMode: false };

  function payload() {
    return (window.afAePayload || window.afAdvancedEditorPayload || {}).stikers || null;
  }

  function isAuth() {
    var p = payload();
    return !!(p && Number(p.isAuth) === 1);
  }

  function req(url, method, body, cb) {
    var p = payload() || {};
    var fd = body instanceof FormData ? body : new FormData();
    if (!fd.get('my_post_key')) fd.append('my_post_key', (window.afAePayload || {}).postKey || '');
    fetch(url, { method: method || 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { cb(null, j); })
      .catch(function (e) { cb(e); });
  }

  function recentKey() {
    var uid = isAuth() ? 'u1' : 'g';
    return 'af_ae_stikers_recent_' + uid;
  }

  function readLocalRecent() {
    try { return JSON.parse(localStorage.getItem(recentKey()) || '[]') || []; } catch (e) { return []; }
  }

  function pushLocalRecent(item) {
    var list = readLocalRecent().filter(function (x) { return x && x.url !== item.url; });
    list.unshift(item);
    if (list.length > MAX_RECENT) list = list.slice(0, MAX_RECENT);
    localStorage.setItem(recentKey(), JSON.stringify(list));
  }

  function htmlEscape(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }

  function renderGrid(items, removable) {
    items = Array.isArray(items) ? items : [];
    if (!items.length) return '<div class="af-ae-stikers-empty">Пусто</div>';
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
        if (typeof ed.insertText === 'function') ed.insertText('[img]' + url + '[/img]');
        else if (typeof ed.insert === 'function') ed.insert('[img]' + url + '[/img]', '');
      } else if (typeof ed.insert === 'function') {
        ed.insert('[img]' + url + '[/img]', '');
      }
    } catch (e) {}
  }

  function openModal(ed) {
    var p = payload();
    if (!p) return;

    var old = document.getElementById('af-ae-stikers-modal');
    if (old) old.remove();

    var wrap = document.createElement('div');
    wrap.id = 'af-ae-stikers-modal';
    wrap.innerHTML = '<div class="af-ae-stikers-backdrop"></div><div class="af-ae-stikers-modal"><div class="af-ae-stikers-head"><strong>Стикеры</strong><button type="button" class="af-ae-stikers-close">×</button></div><div class="af-ae-stikers-tabs"></div><div class="af-ae-stikers-body"></div></div>';
    document.body.appendChild(wrap);

    function close() { wrap.remove(); }
    wrap.querySelector('.af-ae-stikers-close').addEventListener('click', close);
    wrap.querySelector('.af-ae-stikers-backdrop').addEventListener('click', close);

    function paint(data) {
      var tabs = [
        { key: 'recent', title: 'Недавние стикеры', list: (data.recent && data.recent.length ? data.recent : readLocalRecent()) },
        { key: 'mine', title: 'Свои стикеры', list: data.user_stickers || [] }
      ];
      (data.categories || []).forEach(function (c) { tabs.push({ key: 'cat_' + c.id, title: c.title, list: c.stickers || [] }); });

      var tabsEl = wrap.querySelector('.af-ae-stikers-tabs');
      var bodyEl = wrap.querySelector('.af-ae-stikers-body');
      tabsEl.innerHTML = tabs.map(function (t, i) { return '<button class="af-ae-stikers-tab' + (i===0?' is-active':'') + '" data-tab="' + t.key + '">' + htmlEscape(t.title) + '</button>'; }).join('');

      function renderTab(key) {
        var t = tabs.filter(function (x) { return x.key === key; })[0] || tabs[0];
        var extra = '';
        if (t.key === 'mine' && isAuth()) {
          extra = '<div class="af-ae-stikers-tools"><label class="button"><input type="file" id="af-ae-stiker-upload" accept=".webp,.gif,.png,.jpg,.jpeg" style="display:none">Добавить стикер</label><button type="button" id="af-ae-stiker-toggle">⚙</button></div>';
        }
        bodyEl.innerHTML = extra + renderGrid(t.list, t.key === 'mine' && state.removeMode);

        var upload = bodyEl.querySelector('#af-ae-stiker-upload');
        if (upload) upload.addEventListener('change', function () {
          var f = upload.files && upload.files[0];
          if (!f) return;
          var fd = new FormData(); fd.append('sticker', f);
          req(p.uploadUrl, 'POST', fd, function (err, res) {
            if (err || !res || !res.success) return alert((res && res.message) || 'Ошибка загрузки');
            loadAndPaint();
          });
        });
        var toggle = bodyEl.querySelector('#af-ae-stiker-toggle');
        if (toggle) toggle.addEventListener('click', function () { state.removeMode = !state.removeMode; renderTab('mine'); });

        bodyEl.querySelectorAll('.af-ae-stiker').forEach(function (btn) {
          btn.addEventListener('click', function (ev) {
            var del = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-del') === '1';
            var id = Number(btn.getAttribute('data-id') || 0);
            var url = btn.getAttribute('data-url') || '';
            if (del && t.key === 'mine') {
              var fd = new FormData(); fd.append('id', String(id));
              req(p.deleteUrl, 'POST', fd, function (err, res) {
                if (err || !res || !res.success) return alert((res && res.message) || 'Ошибка удаления');
                loadAndPaint();
              });
              return;
            }
            insertSticker(ed, url);
            pushLocalRecent({ id: id, url: url, title: '' });
            var fd2 = new FormData(); fd2.append('sticker_id', String(id)); fd2.append('sticker_url', url);
            req(p.recentUrl, 'POST', fd2, function () {});
            close();
          });
        });
      }

      tabsEl.querySelectorAll('.af-ae-stikers-tab').forEach(function (b) {
        b.addEventListener('click', function () {
          tabsEl.querySelectorAll('.af-ae-stikers-tab').forEach(function (x) { x.classList.remove('is-active'); });
          b.classList.add('is-active');
          renderTab(b.getAttribute('data-tab'));
        });
      });
      renderTab('recent');
    }

    function loadAndPaint() {
      req(p.listUrl, 'POST', new FormData(), function (err, res) {
        if (err || !res || !res.success) { alert((res && res.message) || 'Ошибка загрузки стикеров'); return; }
        state.data = res.data || {};
        paint(state.data);
      });
    }
    loadAndPaint();
  }

  window.af_ae_stikers_exec = function (ed) { openModal(ed); };
})();
