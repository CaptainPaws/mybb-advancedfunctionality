(function () {
    var cache = {
        types: null,
        lists: {}
    };

    function getLang(key, fallback) {
        if (window.afKbLang && window.afKbLang[key]) {
            return window.afKbLang[key];
        }
        return fallback;
    }

    function fetchTypes() {
        if (cache.types) {
            return Promise.resolve(cache.types);
        }
        return fetch('misc.php?action=kb_types')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                cache.types = data.items || [];
                return cache.types;
            })
            .catch(function () { return []; });
    }

    function fetchList(type, query) {
        var cacheKey = type + ':' + (query || '');
        if (cache.lists[cacheKey]) {
            return Promise.resolve(cache.lists[cacheKey]);
        }
        var url = 'misc.php?action=kb_list&type=' + encodeURIComponent(type);
        if (query) {
            url += '&q=' + encodeURIComponent(query);
        }
        return fetch(url)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                cache.lists[cacheKey] = data.items || [];
                return cache.lists[cacheKey];
            })
            .catch(function () { return []; });
    }

    function insertAtCursor(textarea, text) {
        if (!textarea) {
            return;
        }
        var start = textarea.selectionStart || 0;
        var end = textarea.selectionEnd || 0;
        var value = textarea.value || '';
        textarea.value = value.substring(0, start) + text + value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + text.length;
        textarea.focus();
    }

    function insertIntoTarget(target, text) {
        if (!target) {
            return;
        }
        if (target && typeof target.insertText === 'function') {
            target.insertText(text);
            return;
        }
        if (target && typeof target.insert === 'function') {
            target.insert(text);
            return;
        }
        if (target instanceof HTMLTextAreaElement) {
            insertAtCursor(target, text);
        }
    }

    function buildModal() {
        var backdrop = document.querySelector('.af-kb-modal-backdrop.af-kb-insert');
        if (backdrop) {
            return backdrop;
        }
        backdrop = document.createElement('div');
        backdrop.className = 'af-kb-modal-backdrop af-kb-insert';
        backdrop.innerHTML = '<div class="af-kb-modal">' +
            '<div class="af-kb-modal-header">' +
            '<h3>' + getLang('kbInsertTitle', 'Insert KB') + '</h3>' +
            '<button type="button" class="af-kb-modal-close">&times;</button>' +
            '</div>' +
            '<div class="af-kb-insert-controls">' +
            '<input type="text" class="af-kb-insert-search" placeholder="' + getLang('kbInsertSearch', 'Search...') + '" />' +
            '<select class="af-kb-insert-select"></select>' +
            '</div>' +
            '<div class="af-kb-insert-hint"></div>' +
            '<div class="af-kb-insert-list"></div>' +
            '</div>';
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop || event.target.classList.contains('af-kb-modal-close')) {
                backdrop.classList.remove('is-active');
            }
        });
        return backdrop;
    }

    function renderTypeOptions(select, types, currentType) {
        select.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = getLang('kbInsertSelect', 'Select category');
        select.appendChild(placeholder);
        types.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.type;
            option.textContent = item.title || item.type;
            if (item.type === currentType) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function renderHint(container, text) {
        container.textContent = text || '';
        container.style.display = text ? 'block' : 'none';
    }

    function renderList(container, items, insertCallback) {
        container.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'af-kb-insert-empty';
            empty.textContent = getLang('kbInsertEmpty', 'Nothing found');
            container.appendChild(empty);
            return;
        }
        items.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'af-kb-insert-item';
            var title = document.createElement('div');
            title.className = 'af-kb-insert-item-title';
            if (item.icon_url) {
                var img = document.createElement('img');
                img.className = 'af-kb-icon-img';
                img.src = item.icon_url;
                img.alt = '';
                title.appendChild(img);
            } else if (item.icon_class) {
                var icon = document.createElement('i');
                icon.className = item.icon_class;
                title.appendChild(icon);
            }
            var span = document.createElement('span');
            span.textContent = item.title || item.key;
            title.appendChild(span);
            row.appendChild(title);
            if (item.tech) {
                var meta = document.createElement('div');
                meta.className = 'af-kb-insert-item-meta';
                meta.textContent = item.tech;
                row.appendChild(meta);
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'af-kb-btn af-kb-btn--edit';
            btn.textContent = getLang('kbInsertButton', 'Insert');
            btn.addEventListener('click', function () {
                insertCallback(item);
            });
            row.appendChild(btn);
            container.appendChild(row);
        });
    }

    function openInsertModal(target) {
        var backdrop = buildModal();
        var searchInput = backdrop.querySelector('.af-kb-insert-search');
        var typeSelect = backdrop.querySelector('.af-kb-insert-select');
        var hint = backdrop.querySelector('.af-kb-insert-hint');
        var list = backdrop.querySelector('.af-kb-insert-list');
        var state = { type: '', query: '' };

        function insertItem(item) {
            var resolvedType = item.type || state.type;
            insertIntoTarget(target, '[kb=' + resolvedType + ':' + item.key + ']');
            backdrop.classList.remove('is-active');
        }

        function loadList() {
            if (!state.type) {
                list.innerHTML = '';
                renderHint(hint, getLang('kbInsertHint', 'Select category or continue search'));
                return;
            }
            renderHint(hint, '');
            fetchList(state.type, state.query).then(function (items) {
                renderList(list, items, insertItem);
            });
        }

        fetchTypes().then(function (types) {
            renderTypeOptions(typeSelect, types, state.type);
            loadList();
        });

        typeSelect.onchange = function () {
            state.type = typeSelect.value;
            loadList();
        };

        searchInput.oninput = function () {
            state.query = searchInput.value.trim();
            loadList();
        };

        searchInput.value = '';
        typeSelect.value = state.type;
        backdrop.classList.add('is-active');
        searchInput.focus();
    }

    function ensureCommandRegistered() {
        var $ = window.jQuery;
        if (!$ || !$.sceditor || !$.sceditor.command) {
            return false;
        }

        if ($.sceditor.command.get && $.sceditor.command.get('af_kb_insert')) {
            return true;
        }

        $.sceditor.command.set('af_kb_insert', {
            tooltip: getLang('kbInsertLabel', 'KB'),
            exec: function () {
                openInsertModal(this);
            },
            txtExec: function () {
                openInsertModal(this);
            }
        });
        return true;
    }

    function patchToolbarString(toolbar) {
        if (!toolbar || toolbar.indexOf('af_kb_insert') !== -1) {
            return toolbar;
        }
        return toolbar + '|af_kb_insert';
    }

    function hookSceditorCreate() {
        var $ = window.jQuery;
        if (!$ || !$.fn || typeof $.fn.sceditor !== 'function') {
            return false;
        }

        if (window.sceditor_options && typeof window.sceditor_options === 'object' && typeof window.sceditor_options.toolbar === 'string') {
            window.sceditor_options.toolbar = patchToolbarString(window.sceditor_options.toolbar);
        }

        if ($.fn.sceditor.__afKbInsertWrapped) {
            return true;
        }

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

        wrapped.__afKbInsertWrapped = true;
        $.fn.sceditor = wrapped;
        $.fn.sceditor.__afKbInsertWrapped = true;
        return true;
    }

    function attachPlainTextareaButtons() {
        var targets = document.querySelectorAll('textarea[name="message"], textarea#message');
        targets.forEach(function (textarea) {
            if (textarea.dataset.afKbInsertReady === '1') {
                return;
            }
            textarea.dataset.afKbInsertReady = '1';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'af-kb-btn af-kb-btn--edit';
            button.textContent = getLang('kbInsertLabel', 'KB');
            button.addEventListener('click', function () {
                openInsertModal(textarea);
            });
            textarea.parentNode.insertBefore(button, textarea);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        ensureCommandRegistered();
        hookSceditorCreate();
        attachPlainTextareaButtons();
    });
})();
