(function () {
  'use strict';

  /* -------------------- small helpers -------------------- */

  function $one(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
    return Promise.resolve();
  }

  function isImageUrl(url) {
    return /\.(jpe?g|png|gif|webp)(\?|#|$)/i.test(url || '');
  }

  function debounce(fn, wait) {
    var t = null;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  }

  function lockBodyScroll(lock) {
    var cls = 'ag-modal-open';
    if (lock) document.documentElement.classList.add(cls);
    else document.documentElement.classList.remove(cls);
  }

  /* -------------------- 1) Copy BBCode button -------------------- */

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (target && target.classList && target.classList.contains('ag-copy-bbcode')) {
      event.preventDefault();
      var bbcode = target.getAttribute('data-bbcode') || '';

      var labelDefault = target.getAttribute('data-label-default') || target.textContent || 'Copy BBCode';
      var labelCopied = target.getAttribute('data-label-copied') || 'Copied';

      copyText(bbcode).then(function () {
        target.textContent = labelCopied;
        setTimeout(function () {
          target.textContent = labelDefault;
        }, 1500);
      });
    }
  });

  /* -------------------- 2) Album form visibility helper -------------------- */

  function toggleAllowedGroups() {
    var select = document.getElementById('ag_visibility');
    var wrapper = document.getElementById('ag_allowed_groups_wrap');
    if (!select || !wrapper) return;
    wrapper.style.display = (select.value === 'groups') ? '' : 'none';
  }

  document.addEventListener('change', function (event) {
    if (event.target && event.target.id === 'ag_visibility') toggleAllowedGroups();
  });

  document.addEventListener('DOMContentLoaded', function () {
    toggleAllowedGroups();
  });

  /* -------------------- 3) UPLOAD PAGE TABS (gallery.php?action=upload) -------------------- */

  function setUploadPageTab(root, tab) {
    if (!root) return;

    var tabsRoot = $one('.ag-upload-tabs', root);
    if (!tabsRoot) return;

    var remoteEnabled = tabsRoot.getAttribute('data-remote-enabled');
    if (tab === 'remote' && remoteEnabled === '0') tab = 'local';

    $all('.ag-upload-tabs .ag-tab', root).forEach(function (btn) {
      btn.classList.toggle('ag-tab-active', (btn.getAttribute('data-tab') === tab));
    });

    $all('.ag-upload-pane', root).forEach(function (pane) {
      pane.style.display = (pane.getAttribute('data-pane') === tab) ? '' : 'none';
    });
  }

  function initUploadPageTabs() {
    var root = $one('.ag-upload');
    if (!root) return;

    var activeBtn = $one('.ag-upload-tabs .ag-tab.ag-tab-active', root);
    var startTab = activeBtn ? (activeBtn.getAttribute('data-tab') || 'local') : 'local';

    try {
      var u = new URL(window.location.href);
      var t = u.searchParams.get('tab');
      if (t === 'remote' || t === 'local') startTab = t;
    } catch (e) {}

    setUploadPageTab(root, startTab);

    root.addEventListener('click', function (event) {
      var btn = event.target && event.target.closest ? event.target.closest('.ag-upload-tabs .ag-tab') : null;
      if (!btn) return;

      event.preventDefault();
      var tab = btn.getAttribute('data-tab') || 'local';
      setUploadPageTab(root, tab);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initUploadPageTabs();
  });

  /* -------------------- 4) Picker modal (editor gallery button) -------------------- */

  var pickerState = {
    modal: null,
    dialog: null,
    insertFn: null,
    page: 1,
    pages: 1,
    q: '',
    albumId: -1
  };

  function getPickerConfig() {
    return window.AF_GalleryPickerConfig || {
      pickerUrl: 'gallery.php?action=picker',
      dataUrl: 'gallery.php?action=picker_data'
    };
  }

  function insertIntoTextarea(textarea, text) {
    if (!textarea) return false;
    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var value = textarea.value || '';
    textarea.value = value.slice(0, start) + text + value.slice(end);
    textarea.focus();
    var pos = start + text.length;
    textarea.setSelectionRange(pos, pos);
    return true;
  }

  function defaultInsert(text) {
    var active = document.activeElement;
    if (active && active.tagName === 'TEXTAREA') {
      return insertIntoTextarea(active, text);
    }
    var ta = $one('textarea');
    if (ta) return insertIntoTextarea(ta, text);
    return false;
  }

  function buildCard(item, labels) {
    var card = document.createElement('div');
    card.className = 'ag-picker-card';

    var img = document.createElement('img');
    img.src = item.thumb || '';
    img.alt = item.title || '';
    img.loading = 'lazy';
    img.decoding = 'async';
    card.appendChild(img);

    var body = document.createElement('div');
    body.className = 'ag-picker-card-body';

    var title = document.createElement('div');
    title.className = 'ag-picker-card-title';
    title.textContent = item.title || ('#' + item.id);
    body.appendChild(title);

    var actions = document.createElement('div');
    actions.className = 'ag-picker-card-actions';

    if (isImageUrl(item.url_full) || item.type === 'local') {
      var btnImg = document.createElement('button');
      btnImg.type = 'button';
      btnImg.className = 'button';
      btnImg.textContent = labels.insertImg;
      btnImg.addEventListener('click', function () {
        if (pickerState.insertFn) pickerState.insertFn('[img]' + item.url_full + '[/img]');
        closePicker();
      });
      actions.appendChild(btnImg);
    }

    var btnLink = document.createElement('button');
    btnLink.type = 'button';
    btnLink.className = 'button';
    btnLink.textContent = labels.insertLink;
    btnLink.addEventListener('click', function () {
      if (pickerState.insertFn) pickerState.insertFn('[url=' + item.url_full + ']' + item.url_full + '[/url]');
      closePicker();
    });
    actions.appendChild(btnLink);

    body.appendChild(actions);
    card.appendChild(body);

    return card;
  }

  function getPickerGrid(modal) {
    return $one('[data-ag-picker-grid="1"]', modal) || $one('[data-picker-grid="1"]', modal);
  }

  function setGridLoading(modal, isLoading) {
    var grid = getPickerGrid(modal);
    if (!grid) return;
    grid.classList.toggle('is-loading', !!isLoading);
  }

  function setActiveAlbum(modal, albumId) {
    $all('.ag-picker-albums [data-album-id]', modal).forEach(function (node) {
      var id = parseInt(node.getAttribute('data-album-id') || '-9999', 10);
      node.classList.toggle('is-active', id === albumId);
    });
  }

  function renderPager(modal) {
    var pager = $one('[data-ag-picker-pager="1"]', modal);
    if (!pager) return;

    var pageEl = $one('[data-ag-picker-page="1"]', pager);
    var prevBtn = $one('[data-ag-picker-prev="1"]', pager);
    var nextBtn = $one('[data-ag-picker-next="1"]', pager);

    var pages = Math.max(1, pickerState.pages || 1);
    var page = Math.min(Math.max(1, pickerState.page || 1), pages);

    if (pageEl) pageEl.textContent = page + ' / ' + pages;

    var show = pages > 1;
    pager.style.display = show ? '' : 'none';

    if (prevBtn) prevBtn.disabled = (page <= 1);
    if (nextBtn) nextBtn.disabled = (page >= pages);
  }

  function renderPickerItems(payload) {
    if (!pickerState.modal) return;

    var modal = pickerState.modal;
    var grid = getPickerGrid(modal);
    if (!grid) return;

    grid.innerHTML = '';

    var items = Array.isArray(payload) ? payload : (payload && payload.items ? payload.items : []);
    var meta = (!Array.isArray(payload) && payload && payload.meta) ? payload.meta : null;

    if (meta) {
      if (typeof meta.pages === 'number') pickerState.pages = Math.max(1, meta.pages);
      if (typeof meta.page === 'number') pickerState.page = Math.max(1, meta.page);
    } else {
      pickerState.pages = Math.max(1, pickerState.pages || 1);
      pickerState.page = Math.max(1, pickerState.page || 1);
    }

    var labels = {
      insertImg: modal.getAttribute('data-insert-img-label') || 'Insert IMG',
      insertLink: modal.getAttribute('data-insert-link-label') || 'Insert link'
    };

    if (!items || !items.length) {
      var empty = document.createElement('div');
      empty.className = 'ag-empty';
      empty.textContent = modal.getAttribute('data-empty-label') || 'No media.';
      grid.appendChild(empty);
      renderPager(modal);
      return;
    }

    items.forEach(function (item) {
      grid.appendChild(buildCard(item, labels));
    });

    renderPager(modal);
  }

  function loadPickerData() {
    var cfg = getPickerConfig();
    var url = cfg.dataUrl;
    var params = [];

    if (pickerState.page > 1) params.push('page=' + pickerState.page);
    if (pickerState.q) params.push('q=' + encodeURIComponent(pickerState.q));

    if (typeof pickerState.albumId === 'number' && pickerState.albumId !== -1) {
      params.push('album_id=' + encodeURIComponent(String(pickerState.albumId)));
    }

    if (params.length) url += (url.indexOf('?') >= 0 ? '&' : '?') + params.join('&');

    if (pickerState.modal) setGridLoading(pickerState.modal, true);

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) { renderPickerItems(data); })
      .catch(function () { renderPickerItems([]); })
      .finally(function () {
        if (pickerState.modal) setGridLoading(pickerState.modal, false);
      });
  }

  function setModalUploadTab(modal, tab) {
    if (!modal) return;

    var uploadRoot = $one('.ag-picker-upload', modal) || modal;
    var remoteEnabled = uploadRoot.getAttribute('data-remote-enabled');

    if (tab === 'remote' && remoteEnabled === '0') tab = 'local';

    $all('.ag-upload-tabs .ag-tab', modal).forEach(function (b) {
      b.classList.toggle('ag-tab-active', (b.getAttribute('data-tab') === tab));
    });

    $all('.ag-upload-pane', uploadRoot).forEach(function (p) {
      p.style.display = (p.getAttribute('data-pane') === tab) ? '' : 'none';
    });
  }

    function ajaxSubmitPickerUpload(form) {
    if (!form) return;

    var fd = new FormData(form);

    if (!fd.has('ajax')) fd.append('ajax', '1');
    if (!fd.has('ag_title')) fd.append('ag_title', '');
    if (!fd.has('ag_description')) fd.append('ag_description', '');

    fetch(form.action, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
        var ok = !!(data && (data.ok || data.success || data.status === 'ok' || data.status === true));
        if (!ok) {
            var msg = (data && (data.error || data.message)) || 'Ошибка загрузки';
            alert(msg);
            return;
        }

        pickerState.page = 1;
        loadPickerData();
        })
        .catch(function () {
        alert('Ошибка сети при загрузке');
        });
    }

  function focusDialog(modal) {
    var dialog = $one('.ag-picker-dialog', modal) || modal;
    pickerState.dialog = dialog;
    try { dialog.focus(); } catch (e) {}
  }

  function bindPickerModal(modal) {

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        if (pickerState.modal && pickerState.modal.classList.contains('is-open')) {
          closePicker();
        }
      }
    });

    modal.addEventListener('click', function (event) {
      var target = event.target;
      if (!target) return;

      if (target.getAttribute('data-ag-picker-close') === '1' || target.getAttribute('data-picker-close') === '1') {
        closePicker();
        return;
      }

      if (target.getAttribute('data-ag-picker-prev') === '1') {
        if (pickerState.page > 1) {
          pickerState.page -= 1;
          loadPickerData();
        }
        return;
      }
      if (target.getAttribute('data-ag-picker-next') === '1') {
        if (pickerState.page < (pickerState.pages || 1)) {
          pickerState.page += 1;
          loadPickerData();
        }
        return;
      }

      var uploadTabBtn = target.closest ? target.closest('.ag-upload-tabs .ag-tab') : null;
      if (uploadTabBtn) {
        var upTab = uploadTabBtn.getAttribute('data-tab') || 'local';
        setModalUploadTab(modal, upTab);
        return;
      }

      var albumNode = target.closest ? target.closest('[data-album-id]') : null;
      if (albumNode && albumNode.closest && albumNode.closest('.ag-picker-albums')) {
        var id = parseInt(albumNode.getAttribute('data-album-id') || '-1', 10);
        if (!isNaN(id) && id >= -1) {
          pickerState.albumId = id;
          pickerState.page = 1;
          setActiveAlbum(modal, id);
          loadPickerData();
        }
        return;
      }
    });

    var search = $one('[data-ag-picker-search="1"]', modal);
    if (search) {
      search.addEventListener('input', debounce(function () {
        pickerState.q = (search.value || '').trim();
        pickerState.page = 1;
        loadPickerData();
      }, 250));
    }

    modal.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form) return;

      if (form.matches && form.matches('form[data-ag-ajax="1"]')) {
        event.preventDefault();
        ajaxSubmitPickerUpload(form);
      }
    });

    setModalUploadTab(modal, 'local');
  }

  function openPicker(insertFn) {
    pickerState.insertFn = (typeof insertFn === 'function') ? insertFn : defaultInsert;
    var cfg = getPickerConfig();

    var ensureModal = pickerState.modal
      ? Promise.resolve(pickerState.modal)
      : fetch(cfg.pickerUrl, { credentials: 'same-origin' })
          .then(function (res) { return res.text(); })
          .then(function (html) {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;

            var modal = wrapper.querySelector('#af-gallery-picker') || wrapper.querySelector('.ag-picker');
            if (!modal) return null;

            document.body.appendChild(modal);
            pickerState.modal = modal;
            bindPickerModal(modal);
            return modal;
          });

    ensureModal.then(function (modal) {
      if (!modal) return;

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      lockBodyScroll(true);

      pickerState.albumId = -1;
      pickerState.page = 1;
      pickerState.pages = 1;
      pickerState.q = '';

      var search = $one('[data-ag-picker-search="1"]', modal);
      if (search) search.value = '';

      setActiveAlbum(modal, pickerState.albumId);
      focusDialog(modal);

      loadPickerData();
    });
  }

  function closePicker() {
    if (pickerState.modal) {
      pickerState.modal.classList.remove('is-open');
      pickerState.modal.setAttribute('aria-hidden', 'true');
    }
    lockBodyScroll(false);
  }

  window.AF_GalleryPicker = {
    open: function (insertFn) { openPicker(insertFn); },
    reload: function () { loadPickerData(); }
  };

  /* -------------------- 5) SCEditor button integration -------------------- */

  function patchToolbarString(toolbar) {
    if (!toolbar || toolbar.indexOf('af_gallery_picker') !== -1) return toolbar;
    var rows = toolbar.split('|');
    var inserted = false;
    rows = rows.map(function (row) {
      if (inserted) return row;
      if (/(^|,)image(,|$)/.test(row)) {
        inserted = true;
        return row.replace(/(^|,)image(,|$)/, '$1image,af_gallery_picker$2');
      }
      return row;
    });
    if (!inserted) rows.push('af_gallery_picker');
    return rows.join('|');
  }

  function ensureCommandRegistered() {
    var $ = window.jQuery;
    if (!$ || !$.sceditor || !$.sceditor.command) return false;
    if ($.sceditor.command.get && $.sceditor.command.get('af_gallery_picker')) return true;

    $.sceditor.command.set('af_gallery_picker', {
      tooltip: 'Gallery',
      exec: function () {
        var editor = this;
        openPicker(function (bbcode) {
          if (typeof editor.insertText === 'function') editor.insertText(bbcode);
          else if (typeof editor.insert === 'function') editor.insert(bbcode);
        });
      },
      txtExec: function () {
        var editor = this;
        openPicker(function (bbcode) {
          if (typeof editor.insertText === 'function') editor.insertText(bbcode);
        });
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

    if ($.fn.sceditor.__afGalleryPickerWrapped) return true;

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

    wrapped.__afGalleryPickerWrapped = true;
    $.fn.sceditor = wrapped;
    $.fn.sceditor.__afGalleryPickerWrapped = true;
    return true;
  }

  function bootPicker() {
    var okHook = hookSceditorCreate();
    var okCmd = ensureCommandRegistered();
    return okHook || okCmd;
  }

  if (bootPicker()) return;

  var tries = 0;
  var timer = setInterval(function () {
    tries++;
    if (bootPicker() || tries >= 120) clearInterval(timer);
  }, 50);

    document.addEventListener('keydown', function (e) {
    var wrap = document.querySelector('.ag-view-media-inner');
    if (!wrap) return;

    var ae = document.activeElement;
    if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA')) return;

    var prev = wrap.getAttribute('data-ag-prev') || '';
    var next = wrap.getAttribute('data-ag-next') || '';

    if ((e.key === 'ArrowLeft' || e.keyCode === 37) && prev) {
        window.location.href = prev;
    }
    if ((e.key === 'ArrowRight' || e.keyCode === 39) && next) {
        window.location.href = next;
    }
    });

    (function(){
    'use strict';

    function findTile(el){
        var cur = el;
        for (var i=0; i<8 && cur; i++){
        if (cur.classList && (cur.classList.contains('ag-tile') || cur.classList.contains('ag-card') || cur.classList.contains('ag-item'))) {
            return cur;
        }

        if (cur.parentElement && cur.parentElement.classList && cur.parentElement.classList.contains('ag-grid')) {
            return cur;
        }
        cur = cur.parentElement;
        }
        return null;
    }

    function syncOne(chk){
        var tile = findTile(chk);
        if (!tile) return;
        if (chk.checked) tile.classList.add('ag-selected');
        else tile.classList.remove('ag-selected');
    }

    document.addEventListener('change', function(e){
        var t = e.target;
        if (!t || t.tagName !== 'INPUT') return;
        if (t.type !== 'checkbox') return;
        if (t.name !== 'media_ids[]' && t.name !== 'media_id[]') return;
        syncOne(t);
    });

    document.addEventListener('DOMContentLoaded', function(){
        var list = document.querySelectorAll('input[type="checkbox"][name="media_ids[]"], input[type="checkbox"][name="media_id[]"]');
        for (var i=0; i<list.length; i++) syncOne(list[i]);
    });
    })();

})();
