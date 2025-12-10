(function () {
    'use strict';

    // защита от многократного подключения
    if (window.afAamInitialized) {
        return;
    }
    window.afAamInitialized = true;

    // включаем отладку, если нужно: window.afAamDebug = true в консоли
    var afAamDebug = !!window.afAamDebug;

    var currentUnread = 0;
    var afAamBaseTitle = document.title;
    var lastSeenAlertId = 0;
    if (typeof window.unreadAlerts !== 'undefined') {
        currentUnread = parseInt(window.unreadAlerts, 10) || 0;
    }

    // лимит тостов
    var afAamToastLimit = parseInt(window.af_aam_toast_limit || '0', 10) || 0;
    var afAamToastQueue = [];
    var afAamActiveToasts = 0;
    var afAamToastDuration = 10000;
    var afAamShownToastIds = {};

    // глобальная настройка из PHP (0/1, число или строка)
    var rawSoundFlag = (typeof window.af_aam_sound_enabled !== 'undefined')
        ? window.af_aam_sound_enabled
        : 1;

    // приводим к булю: 1 / "1" / true -> включено
    var afAamGlobalSoundEnabled =
        (parseInt(rawSoundFlag, 10) === 1) || (rawSoundFlag === true || rawSoundFlag === 'true');

    var afAamSound          = null;
    var afAamToastContainer = null;
    var afAamSoundPrimed    = false; // звук "разбужен" жестом пользователя

    // ключ для localStorage и реальный флаг для пользователя
    var afAamSoundStorageKey  = 'af_aam_sound_muted';
    var afAamUserSoundEnabled = afAamGlobalSoundEnabled;

    // если в localStorage стоит "заглушено" — переопределяем
    try {
        var lsVal = localStorage.getItem(afAamSoundStorageKey);
        if (lsVal === '1') {
            afAamUserSoundEnabled = false;
        }
    } catch (e) {
        // если localStorage недоступен — просто игнорируем
    }


    // ======== простая AJAX-обёртка =========
    function ajax(url, data, cb) {
        var xhr = new XMLHttpRequest();
        var method = data ? 'POST' : 'GET';

        if (method === 'GET' && data) {
            var qsArr = [];
            for (var k in data) {
                if (Object.prototype.hasOwnProperty.call(data, k)) {
                    if (Array.isArray(data[k])) {
                        data[k].forEach(function (val) {
                            qsArr.push(encodeURIComponent(k) + '=' + encodeURIComponent(val));
                        });
                    } else {
                        qsArr.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
                    }
                }
            }
            if (qsArr.length) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + qsArr.join('&');
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
                } catch (e) {
                    if (afAamDebug) {
                        console.log('[AAM] ajax JSON parse error:', e, 'raw:', xhr.responseText);
                    }
                }
                cb(resp || {});
            }
        };

        if (method === 'POST' && data) {
            var body = [];
            for (var k2 in data) {
                if (Object.prototype.hasOwnProperty.call(data, k2)) {
                    if (Array.isArray(data[k2])) {
                        data[k2].forEach(function (val) {
                            body.push(encodeURIComponent(k2) + '=' + encodeURIComponent(val));
                        });
                    } else {
                        body.push(encodeURIComponent(k2) + '=' + encodeURIComponent(data[k2]));
                    }
                }
            }
            xhr.send(body.join('&'));
        } else {
            xhr.send(null);
        }
    }

    function qs(sel) { return document.querySelector(sel); }
    function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    // ======== вспомогательная: вытащить число непрочитанных =========
    function extractUnreadCount(resp) {
        if (!resp || typeof resp !== 'object') {
            return null;
        }

        var cand = null;

        if (typeof resp.unread_count === 'number') cand = resp.unread_count;
        else if (typeof resp.unread === 'number') cand = resp.unread;
        else if (typeof resp.badge === 'number') cand = resp.badge;

        if (cand === null) {
            if (typeof resp.unread_count === 'string') {
                var n1 = parseInt(resp.unread_count, 10);
                if (!isNaN(n1)) cand = n1;
            } else if (typeof resp.unread === 'string') {
                var n2 = parseInt(resp.unread, 10);
                if (!isNaN(n2)) cand = n2;
            } else if (typeof resp.badge === 'string') {
                var n3 = parseInt(resp.badge, 10);
                if (!isNaN(n3)) cand = n3;
            }
        }

        if (typeof cand === 'number' && !isNaN(cand)) {
            return cand;
        }

        return null;
    }

    function syncFromResponse(resp) {
        if (!resp || typeof resp !== 'object') {
            pollUnreadAlerts();
            return;
        }

        var cnt = extractUnreadCount(resp);
        if (cnt !== null) {
            updateVisibleCounts(cnt);
        } else {
            pollUnreadAlerts();
        }

        if (resp && resp.template) {
            var tbody = qs('#alerts_content');
            if (tbody) {
                tbody.innerHTML = resp.template;
            }
        }
    }

    // ======== ЛОГИКА MyAlerts (адаптация) =========
    function updateVisibleCounts(unreadCount) {
        currentUnread = Math.max(0, unreadCount || 0);

        var badge = qs('#af_aam_badge');
        if (badge) {
            badge.textContent = currentUnread;
        }

        // Обновляем заголовок страницы (как у MyAlerts)
        if (currentUnread > 0) {
            document.title = '(' + currentUnread + ') ' + afAamBaseTitle;
        } else {
            document.title = afAamBaseTitle;
        }

        // Только добавляем/убираем класс alerts--new, но НЕ трогаем текст ссылки
        var alertsContainer = document.querySelector('.af-aam-alerts');
        if (alertsContainer) {
            alertsContainer.classList.toggle('alerts--new', currentUnread > 0);
        }

        if (afAamDebug) {
            console.log('[AAM] unread updated:', currentUnread);
        }
    }


    // ======== ALERTS: HEADER ICON + МОДАЛКА =========

    function initAlertsUI() {
        var link   = qs('#af_aam_header_link');
        var modal  = qs('#af_aam_modal');
        var alertsTable = qs('#alerts_content');
        var bell   = qs('#af_aam_bell') || qs('.af-aam-bell');
        var modalClose = modal ? modal.querySelector('.af-aam-modal-close') : null;
        var backdrop   = modal ? modal.querySelector('.af-aam-modal-backdrop') : null;
        // Тумблер звука в модалке
        var soundToggle = qs('#af_aam_sound_toggle');

        if (soundToggle) {
            // начальное состояние — с учётом глобальной настройки и localStorage
            soundToggle.checked = afAamUserSoundEnabled;

            soundToggle.addEventListener('change', function () {
                afAamUserSoundEnabled = !!soundToggle.checked;
                try {
                    if (!afAamUserSoundEnabled) {
                        // запоминаем, что пользователь заглушил звук
                        localStorage.setItem(afAamSoundStorageKey, '1');
                    } else {
                        // убираем "мьют" и даём тестовый пинг
                        localStorage.removeItem(afAamSoundStorageKey);
                        playAlertSound();
                    }
                } catch (e) {
                    // если localStorage отвалился — просто игнорируем
                }
            });
        }

        if (bell && !bell.textContent) {
            bell.textContent = '🔔';
        }

        if (!link || !modal || !alertsTable) {
            return;
        }

        function renderModal(unreadOnly) {
            ajax('xmlhttp.php', {
                action: 'af_aam_api',
                op: 'list',
                unreadOnly: unreadOnly ? 1 : 0
            }, function (resp) {
                syncFromResponse(resp || {});
            });
        }

        function openModal() {
            renderModal();
            modal.classList.add('af-aam-modal-open');
            modal.style.display = 'block';

            // "пробуждаем" звук от первого клика по колокольчику
            if (afAamSound && afAamUserSoundEnabled && !afAamSoundPrimed) {
                try {
                    afAamSound.play().then(function () {
                        afAamSound.pause();
                        afAamSound.currentTime = 0;
                        afAamSoundPrimed = true;
                    }).catch(function () {
                        // если браузер упёрся — не критично, всё равно будем пробовать позже
                    });
                } catch (e) {}
            }
        }

        function closeModal() {
            modal.classList.remove('af-aam-modal-open');
            modal.style.display = 'none';
        }

        // Клик по колокольчику — просто открываем модалку
        link.addEventListener('click', function (e) {
            // ЛКМ без модификаторов — модалка; Ctrl/колёсико оставляем как есть (открыть ссылку)
            if (e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
                e.preventDefault();
                openModal();
            }
        });

        // Крестик
        if (modalClose) {
            modalClose.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        // Клик по тёмному фону (если появится)
        if (backdrop) {
            backdrop.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        // Настройки
        var prefsToggle = qs('#af_aam_prefs_toggle');
        var prefsBox    = qs('#af_aam_prefs_inline');

        if (prefsToggle && prefsBox) {
            prefsToggle.addEventListener('click', function (e) {
                e.preventDefault();
                if (prefsBox.getAttribute('data-loaded') === '1') {
                    // просто показать/скрыть
                    prefsBox.style.display = (prefsBox.style.display === 'none' || !prefsBox.style.display) ? 'block' : 'none';
                    return;
                }

                ajax('xmlhttp.php', { action: 'af_aam_api', op: 'prefs_form' }, function (resp) {
                    if (resp && resp.ok && resp.html) {
                        prefsBox.innerHTML = resp.html;
                        prefsBox.setAttribute('data-loaded', '1');
                        prefsBox.style.display = 'block';

                        var form = prefsBox.querySelector('#af_aam_prefs_form');
                        if (form) {
                            form.addEventListener('submit', function (ev) {
                                ev.preventDefault();
                                var checkboxes = prefsBox.querySelectorAll('.af-aam-pref-checkbox');
                                var types = [];
                                checkboxes.forEach(function (cb) {
                                    if (cb.checked) {
                                        types.push(cb.value);
                                    }
                                });

                                ajax('xmlhttp.php', {
                                    action: 'af_aam_api',
                                    op: 'prefs_save',
                                    my_post_key: window.my_post_key || '',
                                    'types': types
                                }, function (resp2) {
                                    // можно показать "сохранено"
                                });
                            });
                        }
                    }
                });
            });
        }

        // Делегирование кликов внутри модалки
        modal.addEventListener('click', function (e) {
            var target = e.target;

            // 1) Прочитано
            if (target.classList && target.classList.contains('markReadAlertButton')) {
                e.preventDefault();

                var id = parseInt((target.id || '').replace(/[^0-9]/g, ''), 10);
                var row = target.closest('tr.alert');

                // ЛОКАЛЬНО ОБНОВЛЯЕМ UI
                if (row) {
                    row.classList.remove('alert--unread');
                    row.classList.add('alert--read');

                    var btnRead   = row.querySelector('.markReadAlertButton');
                    var btnUnread = row.querySelector('.markUnreadAlertButton');

                    if (btnRead) {
                        btnRead.classList.add('hidden');
                    }
                    if (btnUnread) {
                        btnUnread.classList.remove('hidden');
                    }
                }

                if (id) {
                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'mark_read',
                        id: id,
                        my_post_key: window.my_post_key || ''
                    }, function (resp) {
                        // сервер вернёт актуальное количество непрочитанных
                        syncFromResponse(resp || {});
                    });
                }

                return;
            }

            // 2) Непрочитано
            if (target.classList && target.classList.contains('markUnreadAlertButton')) {
                e.preventDefault();

                var id2 = parseInt((target.id || '').replace(/[^0-9]/g, ''), 10);
                var row2 = target.closest('tr.alert');

                if (row2) {
                    row2.classList.remove('alert--read');
                    row2.classList.add('alert--unread');

                    var btnRead2   = row2.querySelector('.markReadAlertButton');
                    var btnUnread2 = row2.querySelector('.markUnreadAlertButton');

                    if (btnRead2) {
                        btnRead2.classList.remove('hidden');
                    }
                    if (btnUnread2) {
                        btnUnread2.classList.add('hidden');
                    }
                }

                if (id2) {
                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'mark_unread',
                        id: id2,
                        my_post_key: window.my_post_key || ''
                    }, function (resp) {
                        syncFromResponse(resp || {});
                    });
                }

                return;
            }

            // 3) Удалить — этот блок уже ходит в af_aam_api: op=delete, оставляем как есть
            if (target.classList && target.classList.contains('deleteAlertButton')) {
                e.preventDefault();

                var id3 = parseInt((target.id || '').replace(/[^0-9]/g, ''), 10);
                var row3 = target.closest('tr.alert');

                var wasUnread = false;
                if (row3) {
                    wasUnread = row3.classList.contains('alert--unread');
                    row3.parentNode.removeChild(row3);
                }

                if (!id3 && row3) {
                    id3 = parseInt(row3.getAttribute('data-alert-id') || '0', 10);
                }

                if (id3) {
                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'delete',
                        id: id3,
                        my_post_key: window.my_post_key || ''
                    }, function (resp) {
                        if (wasUnread && (!resp || extractUnreadCount(resp) === null)) {
                            pollUnreadAlerts();
                            return;
                        }
                        syncFromResponse(resp || {});
                    });
                }

                return;
            }

            // 4) клик по самому уведомлению — пометить прочитанным и перейти
            var node = target;
            while (node && node !== modal) {
                if (node.tagName && node.tagName.toLowerCase() === 'a' &&
                    node.classList && node.classList.contains('af-aam-alert-link')) {

                    if (node.href && e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
                        e.preventDefault();

                        // найдём id алерта
                        var tr2 = node;
                        while (tr2 && tr2 !== modal && (!tr2.tagName || tr2.tagName.toLowerCase() !== 'tr')) {
                            tr2 = tr2.parentNode;
                        }
                        var alertId = 0;
                        if (tr2 && tr2.getAttribute) {
                            alertId = parseInt(tr2.getAttribute('data-alert-id') || '0', 10);
                        }

                        if (alertId) {
                            ajax('xmlhttp.php', {
                                action: 'af_aam_api',
                                op: 'mark_read',
                                id: alertId,
                                my_post_key: window.my_post_key || ''
                            }, function (resp) {
                                syncFromResponse(resp || {});
                                window.location.href = node.href;
                            });
                        } else {
                            window.location.href = node.href;
                        }

                    }
                    return;
                }
                node = node.parentNode;
            }

        });



        window.afAamRefreshModal = renderModal;
    }


    function initListClicks() {
        document.addEventListener('click', function (e) {
            var node = e.target;

            while (node && node !== document) {
                if (node.classList && node.classList.contains('af-aam-list-link')) {
                    if (node.href && e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
                        e.preventDefault();

                        var row = node.closest('.af-aam-list-row');
                        var alertId = parseInt((node.getAttribute('data-alert-id') || (row && row.getAttribute('data-alert-id')) || '0'), 10);

                        // локально отметим прочитанным и скорректируем счётчик, чтобы UCP сразу реагировал
                        var wasUnread = false;
                        if (row) {
                            wasUnread = row.classList.contains('af-aam-row-unread');
                            row.classList.remove('af-aam-row-unread');
                            row.classList.add('af-aam-row-read');
                        }

                        if (wasUnread) {
                            var badgeVal = currentUnread > 0 ? currentUnread - 1 : 0;
                            updateVisibleCounts(badgeVal);
                        }

                        if (alertId) {
                            ajax('xmlhttp.php', {
                                action: 'af_aam_api',
                                op: 'mark_read',
                                id: alertId,
                                my_post_key: window.my_post_key || ''
                            }, function (resp) {
                                syncFromResponse(resp || {});
                                window.location.href = node.href;
                            });
                        } else {
                            window.location.href = node.href;
                        }
                    }
                    return;
                }
                node = node.parentNode;
            }
        });
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
                var cnt = extractUnreadCount(resp);
                if (cnt !== null) {
                    updateVisibleCounts(cnt);
                }
            });
        }

        function markAllHandler(e) {
            e.preventDefault();
            ajax('xmlhttp.php', { action: 'af_aam_api', op: 'mark_all', my_post_key: window.my_post_key || '' }, function (resp) {
                syncFromResponse(resp || {});
                renderLatest();
            });
        }

        if (latestBtn && latestContainer) {
            latestBtn.addEventListener('click', function (e) {
                e.preventDefault();
                renderLatest();
            });
        }

        markAllButtons.forEach(function (btn) {
            btn.addEventListener('click', markAllHandler);
        });

        document.addEventListener('click', function (e) {
            var target = e.target.closest('.markAllReadButton');
            if (target) {
                markAllHandler(e);
            }
        });

        if (typeof window.myalerts_autorefresh !== 'undefined' && window.myalerts_autorefresh > 0) {
            setInterval(renderLatest, window.myalerts_autorefresh * 1000);
        }
    }

    // ======== MENTIONS: КНОПКА + АВТОДОПОЛНЕНИЕ =============

    function insertMention(username) {
        var text = '@"' + username + '" ';

        // SCEditor (стандартный редактор MyBB)
        if (window.jQuery && jQuery.fn && jQuery.fn.sceditor) {
            var $ta = jQuery('textarea[name="message"]');
            if ($ta.length) {
                var editor = $ta.sceditor('instance');
                if (editor && typeof editor.insert === 'function') {
                    editor.insert(text);
                    return;
                }
            }
        }

        // Возможный глобальный MyBBEditor
        if (typeof window.MyBBEditor !== 'undefined' &&
            window.MyBBEditor &&
            typeof window.MyBBEditor.insert === 'function') {
            window.MyBBEditor.insert(text);
            return;
        }

        // Фолбэк: обычное textarea
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

    function initSound() {
        // 0) Если есть <audio>, но без src — попробуем подставить ping.mp3
        var domAudio = document.getElementById('af_aam_sound');
        if (domAudio && !domAudio.src && typeof window.af_aam_asset_base === 'string') {
            domAudio.src = window.af_aam_asset_base + 'ping.mp3';
        }

        // 1) Пробуем использовать <audio id="af_aam_sound"> из шаблона
        if (domAudio && typeof domAudio.play === 'function') {
            afAamSound = domAudio;
            afAamSound.muted  = false;
            afAamSound.volume = 1.0;
            if (afAamDebug) {
                console.log('[AAM] initSound: using existing <audio> element');
            }
            return;
        }

        // 2) Фолбэк — создаём Audio вручную
        if (typeof window.af_aam_asset_base !== 'string') {
            if (afAamDebug) {
                console.log('[AAM] no af_aam_asset_base, sound disabled');
            }
            return;
        }
        try {
            afAamSound = new Audio(window.af_aam_asset_base + 'ping.mp3');
            afAamSound.preload = 'auto';
            afAamSound.volume  = 1.0;
            afAamSound.muted   = false;
        } catch (e) {
            afAamSound = null;
        }
        if (afAamDebug) {
            console.log('[AAM] initSound, asset:', window.af_aam_asset_base + 'ping.mp3', 'ok=', !!afAamSound);
        }
    }


    function playAlertSound() {
        // если пользователь или глобальная настройка отключили звук — выходим
        if (!afAamUserSoundEnabled) {
            if (afAamDebug) {
                console.log('[AAM] sound disabled by user/global');
            }
            return;
        }
        if (!afAamSound) {
            if (afAamDebug) {
                console.log('[AAM] no Audio object to play');
            }
            return;
        }
        try {
            afAamSound.currentTime = 0;
            var p = afAamSound.play();
            if (p && typeof p.then === 'function') {
                p.catch(function (e) {
                    if (afAamDebug) {
                        console.log('[AAM] audio play() blocked:', e);
                    }
                });
            }
        } catch (e) {
            if (afAamDebug) {
                console.log('[AAM] playAlertSound error:', e);
            }
        }
    }


    function initToasts() {
        if (afAamToastContainer) {
            return;
        }
        var div = document.createElement('div');
        div.className = 'af-aam-toast-container';
        document.body.appendChild(div);
        afAamToastContainer = div;
    }

    function dismissToast(div, timeoutId) {
        if (!div) {
            return;
        }

        if (timeoutId) {
            clearTimeout(timeoutId);
        }

        div.classList.remove('af-aam-toast-show');
        setTimeout(function () {
            if (div.parentNode === afAamToastContainer) {
                afAamToastContainer.removeChild(div);
            }
            if (afAamActiveToasts > 0) {
                afAamActiveToasts--;
            }
            processToastQueue();
        }, 200);
    }

    function spawnToast(alert) {
        if (!afAamToastContainer) {
            initToasts();
        }
        if (!afAamToastContainer) {
            return;
        }

        afAamActiveToasts++;

        var div = document.createElement('div');
        div.className = 'af-aam-toast';

        var header = document.createElement('div');
        header.className = 'af-aam-toast-header';

        var title = document.createElement('div');
        title.className = 'af-aam-toast-title';
        title.textContent = 'Новое уведомление';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'af-aam-toast-close';
        close.textContent = '×';

        header.appendChild(title);
        header.appendChild(close);

        var text = document.createElement('div');
        text.className = 'af-aam-toast-text';
        text.textContent = alert.text || '';

        if (alert.avatar && alert.avatar.url) {
            var avatarWrap = document.createElement('span');
            avatarWrap.className = 'af-aam-toast-avatar';
            var img = document.createElement('img');
            img.src = alert.avatar.url;
            img.width = alert.avatar.width || 32;
            img.height = alert.avatar.height || 32;
            img.alt = alert.avatar.username || '';
            avatarWrap.appendChild(img);
            div.appendChild(avatarWrap);
        }

        div.appendChild(header);
        div.appendChild(text);

        var autoHide = setTimeout(function () {
            dismissToast(div);
        }, afAamToastDuration);

        // клик — перейти по ссылке, если есть
        div.addEventListener('click', function () {
            if (alert.url) {
                window.location.href = alert.url;
            }
            dismissToast(div, autoHide);
        });

        close.addEventListener('click', function (ev) {
            ev.stopPropagation();
            dismissToast(div, autoHide);
        });

        afAamToastContainer.appendChild(div);

        setTimeout(function () {
            div.classList.add('af-aam-toast-show');
        }, 10);
    }

    function processToastQueue() {
        if (!afAamToastContainer || afAamToastLimit <= 0) {
            return;
        }

        while (afAamActiveToasts < afAamToastLimit && afAamToastQueue.length) {
            spawnToast(afAamToastQueue.shift());
        }
    }

    function queueToasts(alerts) {
        if (!Array.isArray(alerts) || !alerts.length) {
            return;
        }

        alerts.forEach(function (alert) {
            var id = parseInt(alert.id, 10) || null;
            if (id && afAamShownToastIds[id]) {
                return;
            }
            if (id) {
                afAamShownToastIds[id] = true;
            }
            afAamToastQueue.push(alert);
        });

        processToastQueue();
    }

    // звук больше НЕ зависит от лимита тостов
    function showToastsFromAlerts(alerts) {
        if (!alerts || !alerts.length || afAamToastLimit <= 0) {
            if (afAamDebug) {
                console.log('[AAM] showToastsFromAlerts: пусто или лимит 0');
            }
            return;
        }

        initToasts();
        queueToasts(alerts);

        if (afAamDebug) {
            console.log('[AAM] showToastsFromAlerts: тосты поставлены в очередь');
        }
    }

    // ======== ПОЛЛИНГ НЕПРОЧИТАННЫХ (ТЕПЕРЬ ЧЕРЕЗ af_aam_api&op=list) =========
    function pollUnreadAlerts() {
        ajax('xmlhttp.php', {
            action: 'af_aam_api',
            op: 'list'
        }, function (resp) {
            if (!resp || typeof resp !== 'object') {
                if (afAamDebug) {
                    console.log('[AAM] pollUnreadAlerts: пустой или некорректный ответ', resp);
                }
                return;
            }

            if (resp.ok !== 1) {
                if (afAamDebug) {
                    console.log('[AAM] pollUnreadAlerts: ok!=1', resp);
                }
                return;
            }

            var oldCount = currentUnread || 0;
            var newCount = extractUnreadCount(resp);
            var newestId = 0;

            // на всякий случай дублируем через badge
            if (newCount === null && typeof resp.badge !== 'undefined') {
                var tmp = parseInt(resp.badge, 10);
                if (!isNaN(tmp)) {
                    newCount = tmp;
                }
            }

            if (newCount === null) {
                if (afAamDebug) {
                    console.log('[AAM] pollUnreadAlerts: не смогли вытащить счётчик из', resp);
                }
                return;
            }

            if (newCount < 0) {
                newCount = 0;
            }

            // обновляем содержимое модалки, если шаблон пришёл
            if (resp.template) {
                var tbody = qs('#alerts_content');
                if (tbody) {
                    tbody.innerHTML = resp.template;
                }
            }

            if (Array.isArray(resp.items) && resp.items.length) {
                newestId = parseInt(resp.items[0].id, 10) || 0;
            }

            if (afAamDebug) {
                console.log('[AAM] pollUnreadAlerts: old=', oldCount, 'new=', newCount);
            }

            updateVisibleCounts(newCount);

            var hasNewAlerts = (newCount > oldCount);
            if (!hasNewAlerts && newestId && newestId > lastSeenAlertId) {
                hasNewAlerts = true;
            }

            if (hasNewAlerts) {
                if (afAamDebug) {
                    console.log('[AAM] pollUnreadAlerts: обнаружены новые, играем звук и тосты');
                }
                playAlertSound();

                if (Array.isArray(resp.items) && resp.items.length) {
                    showToastsFromAlerts(resp.items);
                }
            }

            if (newestId && newestId > lastSeenAlertId) {
                lastSeenAlertId = newestId;
            }
        });
    }


    // ======== ИНИЦИАЛИЗАЦИЯ ==========================

    function primeSoundOnce() {
        if (!afAamSound || afAamSoundPrimed || !afAamUserSoundEnabled) {
            document.removeEventListener('click', primeSoundOnce, true);
            return;
        }

        try {
            var p = afAamSound.play();
            if (p && typeof p.then === 'function') {
                p.then(function () {
                    afAamSound.pause();
                    afAamSound.currentTime = 0;
                    afAamSoundPrimed = true;
                    document.removeEventListener('click', primeSoundOnce, true);
                }).catch(function () {
                    afAamSoundPrimed = true;
                    document.removeEventListener('click', primeSoundOnce, true);
                });
            } else {
                afAamSoundPrimed = true;
                document.removeEventListener('click', primeSoundOnce, true);
            }
        } catch (e) {
            afAamSoundPrimed = true;
            document.removeEventListener('click', primeSoundOnce, true);
        }
    }

    function init() {
        initSound();
        initAlertsUI();
        initMyAlertsCompat();
        initMentionButtons();
        initMentionAutocomplete();
        initListClicks();

        // глобальный клик для "разбуживания" звука (один раз)
        document.addEventListener('click', primeSoundOnce, true);

        // сразу один раз дернем, чтобы синхронизировать бейдж без ожидания интервала
        pollUnreadAlerts();
    }

    // выбираем интервал: сначала af_aam_autorefresh, потом myalerts, иначе дефолт 5 сек
    var refreshInterval = 0;
    var minRefreshInterval = 5; // секунды, чтобы обновление было "почти realtime"

    if (typeof window.af_aam_autorefresh !== 'undefined' &&
        !isNaN(parseInt(window.af_aam_autorefresh, 10)) &&
        parseInt(window.af_aam_autorefresh, 10) > 0) {

        refreshInterval = parseInt(window.af_aam_autorefresh, 10);

    } else if (typeof window.myalerts_autorefresh !== 'undefined' &&
               !isNaN(parseInt(window.myalerts_autorefresh, 10)) &&
               parseInt(window.myalerts_autorefresh, 10) > 0) {

        refreshInterval = parseInt(window.myalerts_autorefresh, 10);

    } else {
        // если всё выключено/ноль — всё равно опрашиваем раз в 5 сек
        refreshInterval = 5;
    }

    if (refreshInterval > 0 && refreshInterval < minRefreshInterval) {
        refreshInterval = minRefreshInterval;
    }

    if (refreshInterval > 0) {
        if (afAamDebug) {
            console.log('[AAM] автообновление каждые', refreshInterval, 'секунд');
        }
        setInterval(pollUnreadAlerts, refreshInterval * 1000);
    }

    // запуск init после загрузки DOM
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

})();
