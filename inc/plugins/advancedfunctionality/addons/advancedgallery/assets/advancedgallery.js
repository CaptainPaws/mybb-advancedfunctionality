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

  function syncProviderForm(form) {
    var selector = $one('[data-ag-storage-provider]', form);
    if (!selector) return;
    var external = selector.value !== 'local', options = $one('[data-ag-external-options]', form);
    var source = $one('[data-ag-external-source]', form), url = $one('[name="external_url"]', form);
    var allowed = selector.options[selector.selectedIndex].getAttribute('data-url-enabled') === '1';
    var urlOption = $one('[data-ag-external-url-option]', form);
    urlOption.disabled = !allowed;
    if (!allowed) source.value = 'file';
    var useUrl = external && source.value === 'url', file = $one('[name="ag_file"]', form);
    options.hidden = !external;
    $one('[data-ag-external-url]', form).hidden = !useUrl;
    url.disabled = !useUrl; url.required = useUrl;
    source.disabled = !external;
    file.disabled = useUrl; file.required = !useUrl;
    if (file.closest('.ag-field')) file.closest('.ag-field').hidden = useUrl;
    var album = $one('select[name="album_id"]', form);
    if (album) album.disabled = external;
  }
  function initProviderForms(root) {
    $all('[data-ag-storage-provider]', root).forEach(function (select) { syncProviderForm(select.form); });
  }
  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-ag-storage-provider], [data-ag-external-source]')) syncProviderForm(event.target.form);
  });
  document.addEventListener('DOMContentLoaded', function () {initProviderForms(document);});
  if (document.readyState !== 'loading') initProviderForms(document);

  function showUploadedCard(data, form) {
    if (!data.id || !data.url_full) return;
    var result = $one('[data-ag-upload-result]', form.parentElement);
    if (!result) { result = document.createElement('div'); result.setAttribute('data-ag-upload-result',''); form.after(result); }
    var card = document.createElement('article'); card.className='ag-tile';
    var link=document.createElement('a');link.href='gallery.php?action=view&id='+Number(data.id);
    var image=document.createElement('img');image.className='ag-thumb';image.src=data.thumb || data.url_full;image.alt=data.title || 'Загруженное изображение';link.appendChild(image);
    var title=document.createElement('p');title.textContent=data.title || 'Изображение загружено';
    var copy=document.createElement('button');copy.type='button';copy.className='button ag-copy-bbcode';copy.textContent='Copy BBCode';copy.setAttribute('data-bbcode',data.bbcode || '[img]'+data.url_full+'[/img]');
    card.append(link,title,copy);result.prepend(card);
  }

  function renderStorage(storage) {
    if (!storage) return;
    var mb = function (n) { return (Number(n) / 1048576).toFixed(2); };
    $all('[data-ag-storage]').forEach(function (root) {
      $one('[data-ag-storage-used]', root).textContent = 'Использовано: ' + mb(storage.used_bytes) + ' МБ' +
        (storage.limit_bytes > 0 ? ' из ' + mb(storage.limit_bytes) + ' МБ' : ' · Без ограничений');
      var progress = $one('[data-ag-storage-progress]', root), available = $one('[data-ag-storage-available]', root);
      progress.hidden = available.hidden = !(storage.limit_bytes > 0);
      progress.value = storage.percent || 0;
      available.textContent = 'Доступно: ' + mb(storage.available_bytes) + ' МБ';
      $one('[data-ag-storage-warning]', root).hidden = !storage.full;
    });
  }

  function updateStorage(storage) {
    if (!$one('[data-ag-storage]')) return;
    if (storage) { renderStorage(storage); return; }
    fetch('gallery.php?action=storage_usage', {credentials:'same-origin', cache:'no-store'})
      .then(function (r) { return r.json(); }).then(function (data) { if (data.ok) renderStorage(data.storage); })
      .catch(function () {});
  }

  // Page forms retain their regular POST fallback when JavaScript is unavailable.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || !$one('[data-ag-storage]') || form.closest('.ag-picker')) return;
    var url = new URL(form.getAttribute('action') || location.href, location.href);
    var action = url.searchParams.get('action');
    if (url.origin !== location.origin || !/\/gallery\.php$/.test(url.pathname) ||
        ['upload_do','delete','bulk_do'].indexOf(action) === -1) return;
    if (action === 'bulk_do' && new FormData(form).get('bulk_action') !== 'delete') return;
    event.preventDefault();
    if (form.getAttribute('data-ag-busy') === '1') return;
    form.setAttribute('data-ag-busy', '1');
    var fd = new FormData(form); fd.set('ajax', '1');
    var status = $one('[data-ag-storage-result]');
    status.textContent = 'Выполняется…';
    fetch(url.href, {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function (r) { return r.text().then(function (body) {
        var data;
        try { data = JSON.parse(body); } catch (e) { throw new Error('Сервер не подтвердил операцию. Проверьте ограничения файла и обновите страницу перед повторной попыткой.'); }
        if (data.storage) renderStorage(data.storage);
        if (!r.ok || !data.ok) throw new Error(data.error || 'Операция не выполнена.');
        return data;
      }); })
      .then(function (data) {
        updateStorage(data.storage);
        status.textContent = data.message || (action === 'upload_do' ? 'Изображение загружено.' : 'Удаление завершено.');
        if (action === 'upload_do') { showUploadedCard(data,form); form.reset(); syncProviderForm(form); }
        if (action === 'delete') {
          var view = form.closest('.ag-view');
          if (view) view.remove(); else form.remove();
        }
        if (action === 'bulk_do') {
          // Retained files can be skipped by the server; do not guess which rows were removed.
          status.textContent = 'Удалено файлов: ' + data.affected + '. Обновите список для просмотра оставшихся изображений.';
        }
      }).catch(function (error) { status.textContent = error.message; })
      .finally(function () { form.removeAttribute('data-ag-busy'); });
  });

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
    albumId: -1,
    purpose: '',
    openToken: 0,
    dataToken: 0,
    keyBound: false
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
    card.setAttribute('data-media-id', String(item.media_id || item.id));

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

    if (pickerState.purpose) {
      var select = document.createElement('button');
      select.type = 'button'; select.className = 'button'; select.textContent = 'Выбрать изображение';
      select.addEventListener('click', function () { selectAppearanceMedia(item, select); });
      actions.appendChild(select);
      body.appendChild(actions); card.appendChild(body);
      return card;
    }

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
    var token = ++pickerState.dataToken;
    if (pickerState.purpose) params.push('purpose=' + encodeURIComponent(pickerState.purpose));

    if (pickerState.page > 1) params.push('page=' + pickerState.page);
    if (pickerState.q) params.push('q=' + encodeURIComponent(pickerState.q));

    if (typeof pickerState.albumId === 'number') {
      params.push('album_id=' + encodeURIComponent(String(pickerState.albumId)));
    }

    if (params.length) url += (url.indexOf('?') >= 0 ? '&' : '?') + params.join('&');

    if (pickerState.modal) setGridLoading(pickerState.modal, true);

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) { if (!res.ok) throw new Error('Picker failed'); return res.json(); })
      .then(function (data) { if (token === pickerState.dataToken) renderPickerItems(data); })
      .catch(function (error) { if (token === pickerState.dataToken) { renderPickerItems([]); if (pickerState.onError) pickerState.onError(new Error('Не удалось загрузить изображения галереи. ' + error.message)); } })
      .finally(function () {
        if (token === pickerState.dataToken && pickerState.modal) setGridLoading(pickerState.modal, false);
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
    var token = pickerState.openToken;

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
        if (token !== pickerState.openToken) return;
        var ok = !!(data && (data.ok || data.success || data.status === 'ok' || data.status === true));
        if (!ok) {
            var msg = (data && (data.error || data.message)) || 'Ошибка загрузки';
            alert(msg);
            return;
        }

        updateStorage(data.storage);
        if ((pickerState.purpose || data.provider) && data.album_id && ['account_avatar','character_avatar'].indexOf(pickerState.purpose)===-1) {
          pickerState.albumId = Number(data.album_id);
          pickerState.modal.setAttribute('data-initial-album', String(data.album_id));
          var button = $one('[data-album-id="-2"]', pickerState.modal);
          if (button) button.setAttribute('data-album-id', String(data.album_id));
          var existingAlbum=$one('[data-album-id="'+data.album_id+'"]',pickerState.modal);
          if (!existingAlbum && data.provider) {
            var albums=$one('.ag-picker-albums',pickerState.modal);
            if(albums){var added=document.createElement('button');added.type='button';added.className='ag-picker-album';added.setAttribute('data-album-id',data.album_id);added.textContent=data.provider;albums.appendChild(added);}
          }
          setActiveAlbum(pickerState.modal, pickerState.albumId);
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
    initProviderForms(modal);

    if (!pickerState.keyBound) document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        if (pickerState.modal && pickerState.modal.classList.contains('is-open')) {
          closePicker();
        }
      }
    });

    pickerState.keyBound = true;

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
        if (!isNaN(id) && id >= -3) {
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

  function openPicker(insertFn, options) {
    options = options || {};
    if (insertFn && typeof insertFn === 'object') { options = insertFn; insertFn = options.onSelect; }
    var purpose = options.purpose || '';
    var allowedPurposes=getPickerConfig().purposes || ['character_avatar', 'account_avatar', 'signature_background', 'signature_images', 'appearance_avatars', 'appearance_postbit_bg', 'appearance_profile_bg', 'appearance_sheet_bg', 'appearance_theme_bg'];
    if (purpose && allowedPurposes.indexOf(purpose) === -1) throw new Error('Unknown Gallery purpose: ' + purpose);
    if (purpose && typeof insertFn !== 'function') throw new Error('Appearance picker requires onSelect');
    if (purpose !== pickerState.purpose && pickerState.modal) { closePicker(); pickerState.modal.remove(); pickerState.modal = null; }
    var token = ++pickerState.openToken;
    ++pickerState.dataToken;
    pickerState.onError = typeof options.onError === 'function' ? options.onError : function (error) { alert(error.message); };
    pickerState.purpose = purpose;
    pickerState.insertFn = (typeof insertFn === 'function') ? insertFn : defaultInsert;
    var cfg = getPickerConfig();

    var pickerUrl = cfg.pickerUrl + (cfg.pickerUrl.indexOf('?') >= 0 ? '&' : '?') + 'purpose=' + encodeURIComponent(purpose);
    var ensureModal = pickerState.modal
      ? Promise.resolve(pickerState.modal)
      : fetch(pickerUrl, { credentials: 'same-origin' })
          .then(function (res) { if (!res.ok) throw new Error('Сервер ответил HTTP ' + res.status + '. Проверьте доступ к Gallery.'); return res.text(); })
          .then(function (html) {
            if (token !== pickerState.openToken) return null;
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;

            var modal = wrapper.querySelector('#af-gallery-picker') || wrapper.querySelector('.ag-picker');
            if (!modal) return null;

            document.body.appendChild(modal);
            pickerState.modal = modal;
            bindPickerModal(modal);
            return modal;
          });

    return ensureModal.then(function (modal) {
      if (token !== pickerState.openToken) return;
      if (!modal) throw new Error('Галерея не вернула окно выбора. Проверьте доступ и активность Advanced Gallery.');

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      lockBodyScroll(true);

      pickerState.albumId = purpose ? parseInt(modal.getAttribute('data-initial-album') || '-2', 10) : -1;
      pickerState.page = 1;
      pickerState.pages = 1;
      pickerState.q = '';

      var search = $one('[data-ag-picker-search="1"]', modal);
      if (search) search.value = '';

      setActiveAlbum(modal, pickerState.albumId);
      focusDialog(modal);

      loadPickerData();
    }).catch(function (error) { if (token === pickerState.openToken) pickerState.onError(new Error('Не удалось открыть галерею. ' + error.message)); });
  }

  function selectAppearanceMedia(item, button) {
    var token = pickerState.openToken, callback = pickerState.insertFn;
    var form = $one('form', pickerState.modal);
    var key = form && $one('[name="my_post_key"]', form);
    button.disabled = true;
    selectGalleryMedia(item.media_id || item.id, {purpose:pickerState.purpose, myPostKey:key ? key.value : ''})
      .then(function (data) {
        if (token !== pickerState.openToken) return;
        closePicker();
        callback({ media_id: Number(data.media_id), url_full: data.url_full, mime: data.mime, filename: data.filename, thumb: item.thumb, title: item.title });
      }).catch(function (error) { if (token === pickerState.openToken) pickerState.onError(error); })
      .finally(function () { button.disabled = false; });
  }

  function closePicker() {
    ++pickerState.openToken;
    ++pickerState.dataToken;
    if (pickerState.modal) {
      pickerState.modal.classList.remove('is-open');
      pickerState.modal.setAttribute('aria-hidden', 'true');
    }
    lockBodyScroll(false);
  }

  // Shared selection boundary for the modal and compact consumer thumbnail strips.
  function selectGalleryMedia(mediaId, options) {
    var fd = new FormData(), cfg = getPickerConfig();
    fd.append('purpose', options.purpose); fd.append('media_id', mediaId); fd.append('my_post_key', options.myPostKey || '');
    var url = new URL(cfg.pickerUrl, location.href); url.searchParams.set('action', 'picker_select');
    return fetch(url.href, {method:'POST',body:fd,credentials:'same-origin'})
      .then(function(res) { return res.json().then(function(data) { if(!res.ok || !data.ok) throw new Error(data.error || 'Изображение недоступно'); return data; }); });
  }

  window.AF_GalleryPicker = {
    supportsPurpose: function(purpose) { return (getPickerConfig().purposes || ['account_avatar','character_avatar','signature_background','signature_images','appearance_avatars','appearance_postbit_bg','appearance_profile_bg','appearance_sheet_bg','appearance_theme_bg']).indexOf(purpose)!==-1; },
    open: function (insertFn, options) { return openPicker(insertFn, options); },
    reload: function () { loadPickerData(); },
    select: function (mediaId, options) { return selectGalleryMedia(mediaId, options || {}); },
    recent: function (options) {
      var url=new URL(getPickerConfig().dataUrl, location.href);
      url.searchParams.set('purpose', options.purpose);
      // Avatar cards use the same server-selected album as the modal.
      if (['account_avatar','character_avatar'].indexOf(options.purpose)!==-1) url.searchParams.delete('album_id');
      else url.searchParams.set('album_id','-1');
      url.searchParams.set('page','1');
      return fetch(url.href,{credentials:'same-origin'}).then(function(res) { if(!res.ok) throw new Error('Не удалось получить изображения галереи'); return res.json(); })
        .then(function(items) { if(!Array.isArray(items)) throw new Error('Неверный ответ Gallery'); return items.slice(0,6); });
    }
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
