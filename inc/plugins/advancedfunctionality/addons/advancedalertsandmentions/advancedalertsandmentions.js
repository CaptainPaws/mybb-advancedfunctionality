(function () {
    'use strict';

    // защита от многократного подключения
    if (window.afAamInitialized) {
        return;
    }
    window.afAamInitialized = true;

    // ======== простая AJAX-обёртка =========
    function ajax(url, data, cb) {
        var xhr = new XMLHttpRequest();
        var method = data ? 'POST' : 'GET';

        if (method === 'GET' && data) {
            var qs = [];
            for (var k in data) {
                if (Object.prototype.hasOwnProperty.call(data, k)) {
                    qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
                }
            }
            if (qs.length) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + qs.join('&');
            }
        }

        xhr.open(method, url, true);

        if (method === 'POST') {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        }

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                var resp = null;
                try {
                    resp = JSON.parse(xhr.responseText);
                } catch (e) {}
                cb(resp || {});
            }
        };

        if (method === 'POST' && data) {
            var body = [];
            for (var k2 in data) {
                if (Object.prototype.hasOwnProperty.call(data, k2)) {
                    body.push(encodeURIComponent(k2) + '=' + encodeURIComponent(data[k2]));
                }
            }
            xhr.send(body.join('&'));
        } else {
            xhr.send(null);
        }
    }

    function qs(sel) { return document.querySelector(sel); }

    function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    // ======== ЛОГИКА MyAlerts (адаптация) =========

    function updateVisibleCounts(unreadCount) {
        var badge = qs('#af_aam_badge');
        if (badge) {
            badge.textContent = unreadCount;
        }

        var title = document.title;
        var idx = title.lastIndexOf('(');
        if (idx !== -1 && title.endsWith(')')) {
            title = title.substring(0, idx).trimEnd();
        }
        if (unreadCount > 0) {
            document.title = title + ' (' + unreadCount + ')';
        } else {
            document.title = title;
        }

        var alertsEl = document.querySelector('.alerts a');
        if (alertsEl) {
            var baseText = alertsEl.textContent.replace(/\(.*\)$/,'').trimEnd();
            alertsEl.textContent = baseText + ' (' + unreadCount + ')';
            var parent = alertsEl.closest('.alerts');
            if (parent) {
                parent.classList.toggle('alerts--new', unreadCount > 0);
            }
        }
    }

    // ======== ALERTS: HEADER ICON + МОДАЛКА =========

    function initAlertsUI() {
        var link   = qs('#af_aam_header_link');
        var modal = qs('#af_aam_modal');
        var modalClose = modal ? modal.querySelector('.af-aam-modal-close') : null;
        var alertsTable = qs('#alerts_content');
        var bell = qs('#af_aam_bell') || qs('.af-aam-bell');

        if (bell && !bell.textContent) {
            bell.textContent = '🔔';
        }

        if (!link || !modal || !alertsTable) {
            return;
        }

        function renderModal(unreadOnly) {
            ajax('xmlhttp.php', {
                action: 'getLatestAlerts',
                unreadOnly: unreadOnly ? 1 : 0
            }, function (resp) {
                if (resp && resp.template) {
                    alertsTable.innerHTML = resp.template;
                }
                if (resp && typeof resp.unread_count === 'number') {
                    updateVisibleCounts(resp.unread_count);
                }
            });
        }

        function openModal() {
            renderModal();
            modal.classList.add('af-aam-modal-open');
            modal.style.display = 'block';
        }

        function closeModal() {
            modal.classList.remove('af-aam-modal-open');
            modal.style.display = 'none';
        }

        link.addEventListener('click', function (e) {
            e.preventDefault();
            openModal();
        });

        if (modalClose) {
            modalClose.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        modal.addEventListener('click', function (e) {
            var target = e.target;
            if (target.classList.contains('markReadAlertButton')) {
                e.preventDefault();
                var id = parseInt((target.id || '').replace(/[^0-9]/g, ''), 10);
                if (id) {
                    ajax('xmlhttp.php', { action: 'myalerts_mark_read', id: id, my_post_key: window.my_post_key || '' }, function (resp) {
                        if (resp && typeof resp.unread_count === 'number') {
                            updateVisibleCounts(resp.unread_count);
                        }
                        renderModal();
                    });
                }
            }
            if (target.classList.contains('markUnreadAlertButton')) {
                e.preventDefault();
                var id2 = parseInt((target.id || '').replace(/[^0-9]/g, ''), 10);
                if (id2) {
                    ajax('xmlhttp.php', { action: 'myalerts_mark_unread', id: id2, my_post_key: window.my_post_key || '' }, function (resp) {
                        if (resp && typeof resp.unread_count === 'number') {
                            updateVisibleCounts(resp.unread_count);
                        }
                        renderModal();
                    });
                }
            }
        });

        window.afAamRefreshModal = renderModal;
    }

    function initMyAlertsCompat() {
        var latestBtn = qs('#getLatestAlerts');
        var latestContainer = qs('#latestAlertsListing');
        var markAllButtons = qsa('.markAllReadButton');

        function renderLatest() {
            ajax('xmlhttp.php', { action: 'getLatestAlerts' }, function (resp) {
                if (resp && resp.template && latestContainer) {
                    latestContainer.innerHTML = resp.template;
                }
                if (resp && typeof resp.unread_count === 'number') {
                    updateVisibleCounts(resp.unread_count);
                }
            });
        }

        if (latestBtn && latestContainer) {
            latestBtn.addEventListener('click', function (e) {
                e.preventDefault();
                renderLatest();
            });
        }

        markAllButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                ajax('xmlhttp.php', { action: 'markAllRead', my_post_key: window.my_post_key || '' }, function (resp) {
                    if (resp && resp.success) {
                        renderLatest();
                        if (typeof window.afAamRefreshModal === 'function') {
                            window.afAamRefreshModal();
                        }
                    }
                });
            });
        });

        if (typeof window.myalerts_autorefresh !== 'undefined' && window.myalerts_autorefresh > 0) {
            setInterval(renderLatest, window.myalerts_autorefresh * 1000);
        }
    }

    // ======== MENTIONS: КНОПКА + АВТОДОПОЛНЕНИЕ =============

    function insertMention(username) {
        var text = '@"' + username + '" ';

        var textarea = document.querySelector('textarea[name="message"]');
        if (!textarea) {
            return;
        }

        textarea.focus();

        if (typeof textarea.selectionStart === 'number') {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var before = textarea.value.substring(0, start);
            var after  = textarea.value.substring(end);
            textarea.value = before + text + after;
            var pos = before.length + text.length;
            textarea.selectionStart = textarea.selectionEnd = pos;
        } else {
            textarea.value += text;
        }
    }

    function initMentionButtons() {
        document.addEventListener('click', function (e) {
            var node = e.target;

            // поднимаемся вверх по DOM, пока не найдём .af-aam-mention-button
            while (node && node !== document) {
                if (node.classList && node.classList.contains('af-aam-mention-button')) {
                    e.preventDefault();
                    var username = node.getAttribute('data-username') || '';
                    if (username) {
                        insertMention(username);
                    }
                    break;
                }
                node = node.parentNode;
            }
        });
    }

    function initMentionAutocomplete() {
        var textarea = document.querySelector('textarea[name="message"]');
        if (!textarea) {
            return;
        }

        var box = document.createElement('div');
        box.className = 'af-aam-suggest-box';
        box.style.display = 'none';
        document.body.appendChild(box);

        var currentPrefix = '';
        var lastPos = 0;

        function hideBox() {
            box.style.display = 'none';
            box.innerHTML = '';
            currentPrefix = '';
        }

        function showBox(items, x, y) {
            box.innerHTML = '';
            if (!items.length) {
                hideBox();
                return;
            }
            items.forEach(function (it) {
                var item = document.createElement('div');
                item.className = 'af-aam-suggest-item';
                item.textContent = it.username;
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    applySuggestion(it.username);
                });
                box.appendChild(item);
            });
            box.style.left = x + 'px';
            box.style.top  = y + 'px';
            box.style.display = 'block';
        }

        function applySuggestion(username) {
            if (!currentPrefix) {
                return;
            }
            var value = textarea.value;
            var pos = textarea.selectionStart;

            var start = lastPos;
            var before = value.substring(0, start);
            var after  = value.substring(pos);
            var text = '@"' + username + '" ';
            textarea.value = before + text + after;
            var caret = before.length + text.length;
            textarea.selectionStart = textarea.selectionEnd = caret;

            hideBox();
        }

        textarea.addEventListener('keyup', function () {
            var pos = textarea.selectionStart;
            var value = textarea.value;

            var slice = value.substring(0, pos);
            var atPos = slice.lastIndexOf('@');
            if (atPos === -1) {
                hideBox();
                return;
            }

            var prefix = slice.substring(atPos + 1);
            if (/\s/.test(prefix)) {
                hideBox();
                return;
            }

            if (prefix.length < 2) {
                hideBox();
                return;
            }

            currentPrefix = prefix;
            lastPos = atPos;

            var rect = textarea.getBoundingClientRect();
            var lineHeight = 18;
            var x = rect.left + 20;
            var y = rect.top + textarea.scrollTop + lineHeight * (value.substring(0, pos).split('\n').length);

            ajax('xmlhttp.php', {
                action: 'af_aam_api',
                op: 'suggest',
                q: prefix
            }, function (resp) {
                if (!resp || !Array.isArray(resp.items)) {
                    hideBox();
                    return;
                }
                showBox(resp.items, x, y);
            });
        });

        textarea.addEventListener('blur', function () {
            setTimeout(hideBox, 200);
        });
    }

    // ======== ИНИЦИАЛИЗАЦИЯ ==========================

    function init() {
        initAlertsUI();
        initMyAlertsCompat();
        initMentionButtons();
        initMentionAutocomplete();
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})();