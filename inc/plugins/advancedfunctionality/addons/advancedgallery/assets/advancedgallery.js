(function () {
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

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.classList.contains('ag-copy-bbcode')) {
            event.preventDefault();
            var bbcode = target.getAttribute('data-bbcode') || '';
            copyText(bbcode).then(function () {
                target.textContent = 'Copied';
                setTimeout(function () {
                    target.textContent = 'Copy BBCode';
                }, 1500);
            });
        }
    });

    function toggleAllowedGroups() {
        var select = document.getElementById('ag_visibility');
        var wrapper = document.getElementById('ag_allowed_groups_wrap');
        if (!select || !wrapper) {
            return;
        }
        if (select.value === 'groups') {
            wrapper.style.display = 'flex';
        } else {
            wrapper.style.display = 'none';
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target && event.target.id === 'ag_visibility') {
            toggleAllowedGroups();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        toggleAllowedGroups();
    });

    var pickerState = {
        modal: null,
        insertFn: null,
        page: 1,
        q: '',
        albumId: 0,
        tab: 'media'
    };

    function isImageUrl(url) {
        return /\.(jpe?g|png|gif|webp)(\?|#|$)/i.test(url || '');
    }

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
        var ta = document.querySelector('textarea');
        if (ta) {
            return insertIntoTextarea(ta, text);
        }
        return false;
    }

    function buildCard(item, labels) {
        var card = document.createElement('div');
        card.className = 'ag-picker-card';

        var img = document.createElement('img');
        img.src = item.thumb || '';
        img.alt = item.title || '';
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
                if (pickerState.insertFn) {
                    pickerState.insertFn('[img]' + item.url_full + '[/img]');
                }
                closePicker();
            });
            actions.appendChild(btnImg);
        }

        var btnLink = document.createElement('button');
        btnLink.type = 'button';
        btnLink.className = 'button';
        btnLink.textContent = labels.insertLink;
        btnLink.addEventListener('click', function () {
            if (pickerState.insertFn) {
                pickerState.insertFn('[url=' + item.url_full + ']' + item.url_full + '[/url]');
            }
            closePicker();
        });
        actions.appendChild(btnLink);

        body.appendChild(actions);
        card.appendChild(body);
        return card;
    }

    function renderPickerItems(items) {
        if (!pickerState.modal) return;
        var grid = pickerState.modal.querySelector('[data-picker-grid="1"]');
        if (!grid) return;
        grid.innerHTML = '';

        var labels = {
            insertImg: pickerState.modal.getAttribute('data-insert-img-label') || 'Insert IMG',
            insertLink: pickerState.modal.getAttribute('data-insert-link-label') || 'Insert link'
        };

        if (!items || !items.length) {
            var empty = document.createElement('div');
            empty.className = 'ag-empty';
            empty.textContent = pickerState.modal.getAttribute('data-empty-label') || 'No media.';
            grid.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            grid.appendChild(buildCard(item, labels));
        });
    }

    function loadPickerData() {
        var cfg = getPickerConfig();
        var url = cfg.dataUrl;
        var params = [];
        if (pickerState.page > 1) params.push('page=' + pickerState.page);
        if (pickerState.q) params.push('q=' + encodeURIComponent(pickerState.q));
        if (pickerState.albumId) params.push('album_id=' + pickerState.albumId);
        if (params.length) url += (url.indexOf('?') >= 0 ? '&' : '?') + params.join('&');

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) { renderPickerItems(data); })
            .catch(function () { renderPickerItems([]); });
    }

    function openPicker(insertFn) {
        pickerState.insertFn = typeof insertFn === 'function' ? insertFn : defaultInsert;
        var cfg = getPickerConfig();

        var ensureModal = pickerState.modal
            ? Promise.resolve(pickerState.modal)
            : fetch(cfg.pickerUrl, { credentials: 'same-origin' })
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    var modal = wrapper.querySelector('#af-gallery-picker');
                    if (!modal) return null;
                    document.body.appendChild(modal);
                    pickerState.modal = modal;
                    bindPickerModal(modal);
                    return modal;
                });

        ensureModal.then(function (modal) {
            if (!modal) return;
            modal.classList.add('is-open');
            setPickerTab('media');
            loadPickerData();
        });
    }

    function closePicker() {
        if (pickerState.modal) {
            pickerState.modal.classList.remove('is-open');
        }
    }

    function setPickerTab(tab) {
        if (!pickerState.modal) return;
        pickerState.tab = tab;
        pickerState.modal.classList.toggle('is-albums', tab === 'albums');
        var tabs = pickerState.modal.querySelectorAll('.ag-picker-tab');
        tabs.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tab);
        });
        if (tab === 'media') {
            pickerState.albumId = 0;
            loadPickerData();
        }
    }

    function bindPickerModal(modal) {
        modal.addEventListener('click', function (event) {
            var target = event.target;
            if (!target) return;
            if (target.getAttribute('data-picker-close') === '1') {
                closePicker();
            }
        });

        var search = modal.querySelector('.ag-picker-search');
        if (search) {
            var timer;
            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    pickerState.q = search.value.trim();
                    loadPickerData();
                }, 300);
            });
        }

        var tabs = modal.querySelectorAll('.ag-picker-tab');
        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setPickerTab(btn.getAttribute('data-tab'));
            });
        });

        var albumWrap = modal.querySelector('[data-picker-albums="1"]');
        if (albumWrap) {
            albumWrap.addEventListener('click', function (event) {
                var target = event.target;
                if (!target || !target.classList.contains('ag-picker-album')) return;
                var id = parseInt(target.getAttribute('data-album-id') || '0', 10);
                if (id > 0) {
                    pickerState.albumId = id;
                    setPickerTab('media');
                }
            });
        }
    }

    window.AF_GalleryPicker = {
        open: function (insertFn) {
            openPicker(insertFn);
        }
    };

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
            exec: function (caller) {
                var editor = this;
                openPicker(function (bbcode) {
                    if (typeof editor.insertText === 'function') editor.insertText(bbcode);
                    else if (typeof editor.insert === 'function') editor.insert(bbcode);
                });
            },
            txtExec: function (caller) {
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
})();
