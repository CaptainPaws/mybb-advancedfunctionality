(function () {
    'use strict';

    // защита от многократного подключения
    if (window.afAamInitialized) {
        return;
    }
    window.afAamInitialized = true;

    // включаем отладку:
    //   до загрузки:   window.afAamDebug = true;
    //   после загрузки: window.afAamSetDebug(true);
    var afAamDebug = !!window.afAamDebug;

    window.afAamSetDebug = function (flag) {
        afAamDebug = !!flag;
        window.afAamDebug = afAamDebug;
        console.log('[AAM] debug =', afAamDebug);
    };


    var currentUnread = 0;
    var afAamBaseTitle = document.title;
    var lastSeenAlertId = 0;

    // хранить "последний увиденный id" в рамках вкладки
    var afAamLastSeenStorageKey = 'af_aam_last_seen_alert_id';

    (function restoreLastSeen() {
        try {
            var v = sessionStorage.getItem(afAamLastSeenStorageKey);
            var n = parseInt(v || '0', 10) || 0;
            if (n > 0) lastSeenAlertId = n;
        } catch (e) {}
    })();

    function saveLastSeen(id) {
        lastSeenAlertId = parseInt(id || '0', 10) || 0;
        try {
            if (lastSeenAlertId > 0) sessionStorage.setItem(afAamLastSeenStorageKey, String(lastSeenAlertId));
        } catch (e) {}
    }

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
    // тосты: настройка пользователя (localStorage)
    var afAamToastsStorageKey = 'af_aam_toasts_disabled';
    var afAamUserToastsEnabled = true;

    try {
        var tVal = localStorage.getItem(afAamToastsStorageKey);
        if (tVal === '1') {
            afAamUserToastsEnabled = false;
        }
    } catch (e) {}


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
    function ajax(url, data, callback, timeoutMs) {
        var xhr = new XMLHttpRequest();

        data = data || {};

        // анти-кэш (некоторые прокси/браузеры умудряются “переиспользовать” xmlhttp ответы)
        if (typeof data._ts === 'undefined') {
            data._ts = Date.now();
        }

        // сериализация данных вида {key: value}
        var params = [];
        for (var key in data) {
            if (!data.hasOwnProperty(key)) continue;

            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (v) {
                    params.push(encodeURIComponent(key + '[]') + '=' + encodeURIComponent(v));
                });
            } else {
                params.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            }
        }
        var body = params.join('&');

        if (afAamDebug) {
            console.log('[AAM ajax →]', url, data);
        }

        xhr.open('POST', url, true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (typeof timeoutMs === 'number' && timeoutMs > 0) {
            xhr.timeout = timeoutMs;
        }

        xhr.onerror = function () {
            if (afAamDebug) console.log('[AAM ajax] network error');
            if (typeof callback === 'function') callback({ ok: 0, error: 'network_error' });
        };

        xhr.ontimeout = function () {
            if (afAamDebug) console.log('[AAM ajax] timeout');
            if (typeof callback === 'function') callback({ ok: 0, error: 'timeout' });
        };

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            if (afAamDebug) {
                console.log('[AAM ajax ←]', 'status=' + xhr.status, 'resp=', xhr.responseText);
            }

            // status=0 бывает при сворачивании/переключении/обрыве — не считаем это “валидным JSON”
            if (xhr.status === 0 && (!xhr.responseText || !xhr.responseText.trim())) {
                if (typeof callback === 'function') callback({ ok: 0, error: 'status_0' });
                return;
            }

            var resp = null;
            try {
                resp = JSON.parse(xhr.responseText);
            } catch (e) {
                if (afAamDebug) console.log('[AAM ajax] JSON parse error:', e);
                resp = { ok: 0, error: 'bad_json', raw: xhr.responseText };
            }

            if (typeof callback === 'function') callback(resp);
        };

        xhr.send(body);
    }

    // GET-обёртка (для mention suggest через misc.php)
    function ajaxGet(url, data, callback, timeoutMs) {
        var xhr = new XMLHttpRequest();
        data = data || {};
        if (typeof data._ts === 'undefined') data._ts = Date.now();

        var params = [];
        for (var key in data) {
            if (!data.hasOwnProperty(key)) continue;
            params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }

        var glue = (url.indexOf('?') >= 0) ? '&' : '?';
        var fullUrl = url + glue + params.join('&');

        if (afAamDebug) console.log('[AAM ajaxGet →]', fullUrl);

        xhr.open('GET', fullUrl, true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (typeof timeoutMs === 'number' && timeoutMs > 0) xhr.timeout = timeoutMs;

        xhr.onerror = function () {
            if (afAamDebug) console.log('[AAM ajaxGet] network error');
            if (typeof callback === 'function') callback({ ok: 0, error: 'network_error' });
        };

        xhr.ontimeout = function () {
            if (afAamDebug) console.log('[AAM ajaxGet] timeout');
            if (typeof callback === 'function') callback({ ok: 0, error: 'timeout' });
        };

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            if (afAamDebug) console.log('[AAM ajaxGet ←]', 'status=' + xhr.status, 'resp=', xhr.responseText);

            var resp = null;
            try {
                resp = JSON.parse(xhr.responseText);
            } catch (e) {
                resp = { ok: 0, error: 'bad_json', raw: xhr.responseText };
            }
            if (typeof callback === 'function') callback(resp);
        };

        xhr.send(null);
    }




    function qs(sel) { return document.querySelector(sel); }
    function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
    function afAamSendBeacon(payload) {
        try {
            if (!navigator.sendBeacon) return false;

            var params = [];
            for (var k in payload) {
                if (!payload.hasOwnProperty(k)) continue;
                params.push(encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]));
            }

            var blob = new Blob([params.join('&')], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
            return navigator.sendBeacon('xmlhttp.php', blob);
        } catch (e) {
            return false;
        }
    }


    // ===== вспомогательный хелпер: достать ID алерта из элемента/строки =====
    function getAlertIdFromElement(el) {
        if (!el) {
            return 0;
        }

        // 1) если у самого элемента есть data-alert-id
        if (el.getAttribute) {
            var rawData = el.getAttribute('data-alert-id');
            if (rawData) {
                var n1 = parseInt(String(rawData).replace(/[^0-9]/g, ''), 10);
                if (!isNaN(n1) && n1 > 0) {
                    return n1;
                }
            }
        }

        // 2) если есть id вида read_123 / unread_123 / alert_123 и т.п.
        if (el.id) {
            var n2 = parseInt(String(el.id).replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n2) && n2 > 0) {
                return n2;
            }
        }

        // 3) поднимаемся наверх по DOM и ищем любой родитель с data-alert-id
        var node = el.closest ? el.closest('[data-alert-id]') : null;
        if (node && node.getAttribute) {
            var raw = node.getAttribute('data-alert-id') || '';
            var n3 = parseInt(String(raw).replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n3) && n3 > 0) {
                return n3;
            }
        }

        return 0;
    }


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

    function syncFromResponse(resp, deltaIfUnknown) {
        if (afAamDebug) {
            console.log('[AAM sync]', resp, 'deltaIfUnknown =', deltaIfUnknown);
        }

        // если вообще ничего не прилетело
        if (!resp || typeof resp !== 'object') {
            // если знаем, что счётчик должен сдвинуться на ±1 / ±N — двигаем локально
            if (typeof deltaIfUnknown === 'number' && deltaIfUnknown !== 0) {
                updateVisibleCounts(Math.max(0, currentUnread + deltaIfUnknown));
            } else {
                // иначе — переспрашиваем сервер через list
                pollUnreadAlerts();
            }
            return;
        }

        // сервер мог прислать блок debug
        if (afAamDebug && resp.debug) {
            console.log('[AAM debug (server)]', resp.debug);
        }

        var hasDelta = (typeof deltaIfUnknown === 'number' && deltaIfUnknown !== 0);

        // Пытаемся вытащить число непрочитанных
        var cnt = extractUnreadCount(resp);

        if (typeof cnt === 'number' && !isNaN(cnt)) {
            // сервер честно прислал счётчик — верим ему
            updateVisibleCounts(cnt);
        } else if (hasDelta) {
            // сервер молчит про счётчик, но мы знаем, что он изменился относительно currentUnread
            updateVisibleCounts(Math.max(0, currentUnread + deltaIfUnknown));
        } else {
            // ни числа, ни дельты — спрашиваем list
            pollUnreadAlerts();
        }

        // Если пришёл шаблон — обновляем тело модалки
        if (resp.template) {
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


    function afAamHasNewUnseenUnread(alerts) {
        if (!Array.isArray(alerts) || !alerts.length) {
            return false;
        }

        for (var i = 0; i < alerts.length; i++) {
            var a = alerts[i];
            if (!a) continue;

            var id = parseInt(a.id, 10) || 0;

            // только непрочитанные
            if (a.is_read && parseInt(a.is_read, 10) === 1) {
                continue;
            }

            // если это "старьё" (мы уже видели такие id) — не считаем новым
            if (id && lastSeenAlertId > 0 && id <= lastSeenAlertId) {
                continue;
            }

            // если уже показывали/обрабатывали именно этот id — тоже не новое
            if (id && afAamShownToastIds[id]) {
                continue;
            }

            // дошли сюда — это новое непрочитанное
            return true;
        }

        return false;
    }

    function afAamMaxAlertId(alerts) {
        if (!Array.isArray(alerts) || !alerts.length) {
            return 0;
        }

        var maxId = 0;
        for (var i = 0; i < alerts.length; i++) {
            var a = alerts[i];
            if (!a) continue;

            var id = parseInt(a.id, 10) || 0;
            if (id > maxId) {
                maxId = id;
            }
        }

        return maxId;
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
                        // если браузер упёрся — не критично
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

                        // выставляем тост-тумблер по localStorage
                        try {
                            var toastCb0 = prefsBox.querySelector('#af_aam_pref_toasts');
                            if (toastCb0) {
                                toastCb0.checked = afAamUserToastsEnabled;
                            }
                        } catch (e) {}

                        prefsBox.setAttribute('data-loaded', '1');
                        prefsBox.style.display = 'block';

                        var form = prefsBox.querySelector('#af_aam_prefs_form');
                        if (form) {
                            form.addEventListener('submit', function (ev) {
                                ev.preventDefault();

                                var checkboxes = prefsBox.querySelectorAll('.af-aam-pref-checkbox');
                                var types = [];
                                checkboxes.forEach(function (cb) {
                                    if (cb.checked) types.push(cb.value);
                                });

                                // тосты: сохраняем локально
                                var toastCb = prefsBox.querySelector('#af_aam_pref_toasts');
                                var toastsEnabled = toastCb ? !!toastCb.checked : true;

                                afAamUserToastsEnabled = toastsEnabled;
                                try {
                                    if (!toastsEnabled) localStorage.setItem(afAamToastsStorageKey, '1');
                                    else localStorage.removeItem(afAamToastsStorageKey);
                                } catch (e) {}

                                ajax('xmlhttp.php', {
                                    action: 'af_aam_api',
                                    op: 'list',
                                    unreadOnly: 1
                                }, function (resp2) {

                                    // на первом запуске — просто “запоминаем верхний id”, без тостов
                                    if (!lastSeenAlertId && resp2 && Array.isArray(resp2.items) && resp2.items.length) {
                                        var maxId = 0;
                                        resp2.items.forEach(function (a) {
                                            var id = parseInt(a.id, 10) || 0;
                                            if (id > maxId) maxId = id;
                                        });
                                        lastSeenAlertId = maxId;
                                        syncFromResponse(resp2 || {});
                                        return;
                                    }

                                    // ВАЖНО: вычисляем "есть ли новые" ДО queueToasts(), потому что queueToasts двигает lastSeenAlertId
                                    var hasNewUnseen = (resp2 && Array.isArray(resp2.items))
                                        ? afAamHasNewUnseenUnread(resp2.items)
                                        : false;

                                    syncFromResponse(resp2 || {});

                                    // тосты только для новых непрочитанных (queueToasts это фильтрует)
                                    if (resp2 && Array.isArray(resp2.items)) {
                                        queueToasts(resp2.items);
                                    }

                                    // звук — только если реально пришли НОВЫЕ уведомления, а не просто "в списке есть непрочитанные"
                                    if (hasNewUnseen) {
                                        playAlertSound();
                                    }
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

                var row = target.closest('[data-alert-id]');
                var id  = row ? getAlertIdFromElement(row) : getAlertIdFromElement(target);
                var wasUnread = false;

                if (row) {
                    wasUnread = row.classList.contains('alert--unread');
                    row.classList.remove('alert--unread');
                    row.classList.add('alert--read');

                    var btnRead   = row.querySelector('.markReadAlertButton');
                    var btnUnread = row.querySelector('.markUnreadAlertButton');

                    if (btnRead) btnRead.classList.add('hidden');
                    if (btnUnread) btnUnread.classList.remove('hidden');
                }

                if (id) {
                    ajax('xmlhttp.php', {
                        action:    'af_aam_api',
                        op:        'mark_read',
                        id:        id,
                        alert_id:  id,
                        'alert_id[]': id,
                        my_post_key: window.my_post_key || ''
                    }, function (resp3) {
                        syncFromResponse(resp3 || {}, wasUnread ? -1 : 0);
                    });
                } else if (afAamDebug) {
                    console.log('[AAM] mark_read: не удалось определить id алерта');
                }

                return;
            }

            // 2) Непрочитано
            if (target.classList && target.classList.contains('markUnreadAlertButton')) {
                e.preventDefault();

                var row2 = target.closest('[data-alert-id]');
                var id2  = row2 ? getAlertIdFromElement(row2) : getAlertIdFromElement(target);
                var wasRead = false;

                if (row2) {
                    wasRead = row2.classList.contains('alert--read');
                    row2.classList.remove('alert--read');
                    row2.classList.add('alert--unread');

                    var btnRead2   = row2.querySelector('.markReadAlertButton');
                    var btnUnread2 = row2.querySelector('.markUnreadAlertButton');

                    if (btnRead2) btnRead2.classList.remove('hidden');
                    if (btnUnread2) btnUnread2.classList.add('hidden');
                }

                if (id2) {
                    ajax('xmlhttp.php', {
                        action:    'af_aam_api',
                        op:        'mark_unread',
                        id:        id2,
                        alert_id:  id2,
                        'alert_id[]': id2,
                        my_post_key: window.my_post_key || ''
                    }, function (resp4) {
                        syncFromResponse(resp4 || {}, wasRead ? +1 : 0);
                    });
                } else if (afAamDebug) {
                    console.log('[AAM] mark_unread: не удалось определить id алерта');
                }

                return;
            }

            // 3) Удалить
            if (target.classList && target.classList.contains('deleteAlertButton')) {
                e.preventDefault();

                var row3 = target.closest('[data-alert-id]');
                var id3  = row3 ? getAlertIdFromElement(row3) : getAlertIdFromElement(target);

                var wasUnread3 = false;
                if (row3) {
                    wasUnread3 = row3.classList.contains('alert--unread');
                    row3.parentNode.removeChild(row3);
                }

                if (id3) {
                    ajax('xmlhttp.php', {
                        action:    'af_aam_api',
                        op:        'delete',
                        id:        id3,
                        alert_id:  id3,
                        'alert_id[]': id3,
                        my_post_key: window.my_post_key || ''
                    }, function (resp5) {
                        syncFromResponse(resp5 || {}, wasUnread3 ? -1 : 0);
                    });
                } else if (afAamDebug) {
                    console.log('[AAM] delete: не удалось определить id алерта');
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

                        var tr2 = node.closest('[data-alert-id]');
                        var alertId = 0;
                        var wasUnreadLink = false;

                        if (tr2) {
                            alertId = getAlertIdFromElement(tr2);
                            wasUnreadLink = tr2.classList.contains('alert--unread');
                        }

                        if (tr2) {
                            tr2.classList.remove('alert--unread');
                            tr2.classList.add('alert--read');
                        }

                        if (alertId) {
                            ajax('xmlhttp.php', {
                                action:      'af_aam_api',
                                op:          'mark_read',
                                id:          alertId,
                                alert_id:    alertId,
                                'alert_id[]': alertId,
                                my_post_key: window.my_post_key || ''
                            }, function (resp6) {
                                syncFromResponse(resp6 || {}, wasUnreadLink ? -1 : 0);
                                window.location.href = node.href;
                            });
                        } else {
                            if (afAamDebug) {
                                console.log('[AAM] click-link: alertId=0, идём без ajax');
                            }
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
            var target = e.target;

            // 1) ✔ на странице списка (без перехода)
            if (target && target.classList && target.classList.contains('markReadAlertButton')) {
                var rowBtn = target.closest('.af-aam-list-row');
                if (rowBtn) {
                    e.preventDefault();

                    var idBtn = getAlertIdFromElement(rowBtn) || getAlertIdFromElement(target);
                    var wasUnreadBtn = rowBtn.classList.contains('af-aam-row-unread');

                    // UI локально
                    rowBtn.classList.remove('af-aam-row-unread');
                    rowBtn.classList.add('af-aam-row-read');

                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'mark_read',
                        id: idBtn,
                        my_post_key: window.my_post_key || ''
                    }, function (resp) {
                        syncFromResponse(resp || {}, wasUnreadBtn ? -1 : 0);
                    });

                    return;
                }
            }

            // 2) ✕ на странице списка (удалить)
            if (target && target.classList && target.classList.contains('deleteAlertButton')) {
                var rowDel = target.closest('.af-aam-list-row');
                if (rowDel) {
                    e.preventDefault();

                    var idDel = getAlertIdFromElement(rowDel) || getAlertIdFromElement(target);
                    var wasUnreadDel = rowDel.classList.contains('af-aam-row-unread');

                    // UI локально
                    rowDel.parentNode.removeChild(rowDel);

                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'delete',
                        id: idDel,
                        my_post_key: window.my_post_key || ''
                    }, function (resp) {
                        syncFromResponse(resp || {}, wasUnreadDel ? -1 : 0);
                    });

                    return;
                }
            }

            // 3) Клик по уведомлению на странице списка — пометить и перейти
            var node = target;
            while (node && node !== document) {
                if (node.classList && node.classList.contains('af-aam-list-link')) {
                    if (node.href && e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
                        var row = node.closest('.af-aam-list-row');
                        var alertId = getAlertIdFromElement(node) || (row ? getAlertIdFromElement(row) : 0);

                        var wasUnread = false;
                        if (row) {
                            wasUnread = row.classList.contains('af-aam-row-unread');
                            row.classList.remove('af-aam-row-unread');
                            row.classList.add('af-aam-row-read');
                        }

                        // бейдж сразу (оптимистично)
                        if (wasUnread) updateVisibleCounts(Math.max(0, currentUnread - 1));

                        // НЕ блочим навигацию: beacon → идеально, иначе ajax с быстрым fallback
                        var ok = false;
                        if (alertId) {
                            ok = afAamSendBeacon({
                                action: 'af_aam_api',
                                op: 'mark_read',
                                id: alertId,
                                my_post_key: window.my_post_key || ''
                            });
                        }

                        // если beacon есть — даём браузеру перейти как обычно
                        if (ok) return;

                        // fallback: короткий ajax, но не держим пользователя “в заложниках”
                        e.preventDefault();
                        var jumped = false;

                        if (alertId) {
                            ajax('xmlhttp.php', {
                                action: 'af_aam_api',
                                op: 'mark_read',
                                id: alertId,
                                my_post_key: window.my_post_key || ''
                            }, function (resp) {
                                if (!jumped) {
                                    syncFromResponse(resp || {}, 0);
                                    jumped = true;
                                    window.location.href = node.href;
                                }
                            }, 1500);

                            setTimeout(function () {
                                if (!jumped) {
                                    jumped = true;
                                    window.location.href = node.href;
                                }
                            }, 200);
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
            ajax('xmlhttp.php', {
                action:    'af_aam_api',
                op:        'mark_all_read',
                my_post_key: window.my_post_key || ''
            }, function (resp) {
                // если сервер не прислал явный unread_count, просто зануляем счётчик
                syncFromResponse(resp || {}, -currentUnread);
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
    function insertMention(username, uid) {
        var u = String(username || '')
            .replace(/[\r\n]+/g, ' ')
            .replace(/"/g, '')
            .trim();

        if (!u) return;

        var mentionUid = parseInt(uid || '0', 10) || 0;
        var text = (mentionUid > 0)
            ? '[mention=' + mentionUid + ']' + u + '[/mention] '
            : '[mention]' + u + '[/mention] ';

        // SCEditor (стандартный редактор MyBB)
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
            var $ta = window.jQuery('textarea[name="message"]');
            if ($ta.length) {
                var editor = $ta.sceditor('instance');
                if (editor) {
                    if (typeof editor.insertText === 'function') {
                        editor.insertText(text);
                        return;
                    }
                    if (typeof editor.insert === 'function') {
                        editor.insert(text);
                        return;
                    }
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
        if (!textarea) return;

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

            while (node && node !== document) {
                // твой основной класс
                var isAam = node.classList && node.classList.contains('af-aam-mention-button');

                // частые варианты разметки (если шаблон/постбит поменялся)
                var isAlt =
                    (node.classList && node.classList.contains('mention_user')) ||
                    (node.classList && node.classList.contains('aam-mention')) ||
                    (node.getAttribute && node.getAttribute('data-mention') === '1');

                if (isAam || isAlt) {
                    e.preventDefault();

                    var username =
                        (node.getAttribute && (node.getAttribute('data-username') || node.getAttribute('data-mention-username'))) ||
                        '';
                    var uid = node.getAttribute ? (parseInt(node.getAttribute('data-uid'), 10) || 0) : 0;

                    if (username) insertMention(username, uid);
                    return;
                }

                node = node.parentNode;
            }
        }, true);
    }


    function initMentionAutocomplete() {
        var mentionSuggestUrl = (typeof window.af_aam_mention_suggest_url === 'string' && window.af_aam_mention_suggest_url)
            ? window.af_aam_mention_suggest_url
            : 'misc.php?action=af_mention_suggest';

        var textarea = document.querySelector('textarea[name="message"]');

        // SCEditor instance (если есть)
        var sceditor = null;
        var scBody = null;

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
            var $ta = window.jQuery('textarea[name="message"]');
            if ($ta.length) {
                sceditor = $ta.sceditor('instance') || null;
                if (sceditor && typeof sceditor.getBody === 'function') {
                    try { scBody = sceditor.getBody(); } catch (e) { scBody = null; }
                }
            }
        }

        // если нет ни textarea, ни sceditor — выходим
        if (!textarea && !scBody) return;

        var box = document.createElement('div');
        box.className = 'af-aam-suggest-box';
        box.style.display = 'none';
        document.body.appendChild(box);

        var state = {
            mode: null,          // 'textarea' | 'sceditor'
            prefix: '',
            lastAtIndex: -1,
            lastCaret: 0,
            lastTextNode: null,
            lastTextNodeAtOffset: 0
        };

        function hideBox() {
            box.style.display = 'none';
            box.innerHTML = '';
            state.prefix = '';
            state.lastAtIndex = -1;
        }

        function placeBoxByRect(rect) {
            if (!rect) return;
            var x = (rect.left + window.scrollX);
            var y = (rect.bottom + window.scrollY + 6);
            box.style.left = Math.max(8, x) + 'px';
            box.style.top  = Math.max(8, y) + 'px';
        }

        function showBox(items, rect) {
            box.innerHTML = '';
            if (!items || !items.length) {
                hideBox();
                return;
            }

            items.forEach(function (it) {
                var item = document.createElement('div');
                item.className = 'af-aam-suggest-item';
                item.textContent = it.username;

                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    applySuggestion(it);
                });

                box.appendChild(item);
            });

            placeBoxByRect(rect);
            box.style.display = 'block';
        }

        function requestSuggest(prefix, rect) {
            // 1) основной путь — misc.php suggest
            ajaxGet(mentionSuggestUrl, { q: prefix }, function (resp) {
                if (resp && Array.isArray(resp.items)) {
                    showBox(resp.items, rect);
                    return;
                }

                // 2) фолбэк — старый путь через af_aam_api suggest (если у тебя так было заведено)
                ajax('xmlhttp.php', { action: 'af_aam_api', op: 'suggest', q: prefix }, function (resp2) {
                    if (resp2 && Array.isArray(resp2.items)) {
                        showBox(resp2.items, rect);
                    } else {
                        hideBox();
                    }
                }, 3000);
            }, 4000);
        }

        function applySuggestion(item) {
            var username = item && item.username ? item.username : item;
            var uid = item && item.uid ? parseInt(item.uid, 10) || 0 : 0;

            var u = String(username || '').replace(/"/g, '').trim();
            if (!u) return;

            var mentionText = (uid > 0)
                ? '[mention=' + uid + ']' + u + '[/mention] '
                : '[mention]' + u + '[/mention] ';

            // textarea режим — точная замена
            if (state.mode === 'textarea' && textarea) {
                var value = textarea.value;
                var caret = textarea.selectionStart || state.lastCaret || value.length;
                var atPos = state.lastAtIndex;

                if (atPos < 0 || atPos >= caret) {
                    // просто вставим
                    insertMention(u, uid);
                    hideBox();
                    return;
                }

                var before = value.substring(0, atPos);
                var after  = value.substring(caret);
                textarea.value = before + mentionText + after;

                var newCaret = before.length + mentionText.length;
                textarea.focus();
                textarea.selectionStart = textarea.selectionEnd = newCaret;

                hideBox();
                return;
            }

            // SCEditor WYSIWYG — заменяем в текущем текст-ноде участок "@prefix"
            if (state.mode === 'sceditor' && sceditor && scBody) {
                try {
                    sceditor.focus();

                    var sel = window.getSelection ? window.getSelection() : null;
                    if (!sel || sel.rangeCount === 0) {
                        sceditor.insertText(mentionText);
                        hideBox();
                        return;
                    }

                    var r = sel.getRangeAt(0);
                    var endNode = r.endContainer;
                    var endOffset = r.endOffset;

                    // используем сохранённую ноду (более стабильно)
                    if (state.lastTextNode && state.lastTextNode.nodeType === 3) {
                        endNode = state.lastTextNode;
                        endOffset = state.lastCaret;
                    }

                    if (!endNode || endNode.nodeType !== 3) {
                        sceditor.insertText(mentionText);
                        hideBox();
                        return;
                    }

                    var text = endNode.nodeValue || '';
                    var startOffset = state.lastTextNodeAtOffset;

                    // страховка
                    if (startOffset < 0 || startOffset > endOffset) {
                        sceditor.insertText(mentionText);
                        hideBox();
                        return;
                    }

                    var rr = document.createRange();
                    rr.setStart(endNode, startOffset);
                    rr.setEnd(endNode, endOffset);
                    rr.deleteContents();

                    var tn = document.createTextNode(mentionText);
                    rr.insertNode(tn);

                    // курсор после вставки
                    rr.setStartAfter(tn);
                    rr.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(rr);

                    hideBox();
                    return;
                } catch (e) {
                    // фолбэк
                    try { sceditor.insertText(mentionText); } catch (e2) {}
                    hideBox();
                    return;
                }
            }

            // совсем фолбэк
            insertMention(u, uid);
            hideBox();
        }

        function parseTextareaContext() {
            var pos = textarea.selectionStart || 0;
            var value = textarea.value || '';
            var slice = value.substring(0, pos);
            var atPos = slice.lastIndexOf('@');
            if (atPos === -1) return null;

            var prefix = slice.substring(atPos + 1);
            if (!prefix || /\s/.test(prefix)) return null;
            if (prefix.length < 2) return null;

            state.mode = 'textarea';
            state.prefix = prefix;
            state.lastAtIndex = atPos;
            state.lastCaret = pos;

            // позицию сильно “не высчитываем”: ставим рядом с textarea
            var rect = textarea.getBoundingClientRect();
            return { prefix: prefix, rect: rect };
        }

        function parseSceditorContext() {
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || sel.rangeCount === 0) return null;

            var r = sel.getRangeAt(0);

            // если курсор не внутри тела редактора — выходим
            if (!scBody.contains(r.endContainer)) return null;

            var endNode = r.endContainer;
            var endOffset = r.endOffset;

            // нормализуем к текст-ноде
            if (endNode && endNode.nodeType === 1) {
                // попытка найти текстовую ноду рядом
                var child = endNode.childNodes[endOffset - 1] || endNode.childNodes[endOffset] || null;
                if (child && child.nodeType === 3) {
                    endNode = child;
                    endOffset = child.nodeValue ? child.nodeValue.length : 0;
                }
            }

            if (!endNode || endNode.nodeType !== 3) return null;

            var t = endNode.nodeValue || '';
            var i = endOffset - 1;

            // ищем '@' назад до пробела
            while (i >= 0) {
                var ch = t.charAt(i);
                if (ch === '@') break;
                if (/\s/.test(ch)) return null;
                i--;
            }
            if (i < 0 || t.charAt(i) !== '@') return null;

            var prefix = t.substring(i + 1, endOffset);
            if (!prefix || prefix.length < 2) return null;

            state.mode = 'sceditor';
            state.prefix = prefix;
            state.lastAtIndex = i; // локальный индекс, но нам важнее offsets в ноде
            state.lastTextNode = endNode;
            state.lastTextNodeAtOffset = i;
            state.lastCaret = endOffset;

            // координаты — по range rect
            var rect = null;
            try {
                rect = r.getBoundingClientRect && r.getBoundingClientRect();
            } catch (e) { rect = null; }
            if (!rect || (!rect.left && !rect.top)) {
                // фолбэк к контейнеру редактора
                try { rect = scBody.getBoundingClientRect(); } catch (e2) {}
            }

            return { prefix: prefix, rect: rect };
        }

        function onKeyUp() {
            var ctx = null;

            // приоритет SCEditor (если активен)
            if (scBody) {
                ctx = parseSceditorContext();
            }

            // иначе textarea
            if (!ctx && textarea) {
                ctx = parseTextareaContext();
            }

            if (!ctx) {
                hideBox();
                return;
            }

            requestSuggest(ctx.prefix, ctx.rect);
        }

        // textarea
        if (textarea) {
            textarea.addEventListener('keyup', function () {
                onKeyUp();
            });
            textarea.addEventListener('blur', function () {
                setTimeout(hideBox, 200);
            });
        }

        // sceditor body
        if (scBody) {
            scBody.addEventListener('keyup', function () {
                onKeyUp();
            });
            // клики/фокус вне — прячем
            scBody.addEventListener('blur', function () {
                setTimeout(hideBox, 200);
            }, true);
        }

        // клик вне бокса — прячем
        document.addEventListener('mousedown', function (e) {
            if (box.style.display === 'block' && e.target !== box && !box.contains(e.target)) {
                hideBox();
            }
        });

        window.addEventListener('scroll', function () {
            if (box.style.display === 'block') hideBox();
        }, true);
    }


    function initSound() {
        // глобально выключено — всё, выходим
        if (!afAamGlobalSoundEnabled) {
            if (afAamDebug) console.log('[AAM] sound globally disabled');
            return;
        }

        // 0) если есть <audio id="af_aam_sound"> — используем его
        var domAudio = document.getElementById('af_aam_sound');
        if (domAudio && typeof domAudio.play === 'function') {
            afAamSound = domAudio;

            // если src пуст — пробуем подставить файл из asset_base
            if ((!afAamSound.src || afAamSound.src === window.location.href) && typeof window.af_aam_asset_base === 'string') {
                var base = window.af_aam_asset_base;
                if (base[base.length - 1] !== '/') base += '/';

                // сначала notification.mp3, если нет — ping.mp3
                afAamSound.src = base + 'notification.mp3';
                afAamSound.addEventListener('error', function () {
                    // fallback
                    afAamSound.src = base + 'ping.mp3';
                }, { once: true });
            }

            afAamSound.muted = false;
            afAamSound.volume = 1.0;

            if (afAamDebug) console.log('[AAM] initSound: using existing <audio>');
            return;
        }

        // 1) fallback: создаём Audio вручную
        if (typeof window.af_aam_asset_base !== 'string') {
            if (afAamDebug) console.log('[AAM] initSound: no af_aam_asset_base, skip');
            return;
        }

        var base2 = window.af_aam_asset_base;
        if (base2[base2.length - 1] !== '/') base2 += '/';

        // пробуем notification.mp3, если не прогрузится — ping.mp3
        try {
            afAamSound = new Audio(base2 + 'notification.mp3');
            afAamSound.preload = 'auto';
            afAamSound.volume = 1.0;
            afAamSound.muted = false;

            afAamSound.addEventListener('error', function () {
                try {
                    afAamSound = new Audio(base2 + 'ping.mp3');
                    afAamSound.preload = 'auto';
                    afAamSound.volume = 1.0;
                    afAamSound.muted = false;
                } catch (e2) {
                    afAamSound = null;
                }
            }, { once: true });
        } catch (e) {
            afAamSound = null;
        }

        if (afAamDebug) console.log('[AAM] initSound ok=', !!afAamSound);
    }


    function playAlertSound() {
        if (!afAamGlobalSoundEnabled) return;
        if (!afAamUserSoundEnabled) return;
        if (!afAamSound) return;

        try {
            // если звук ещё не "разбужен" жестом — пробуем тихо. Если нельзя — молча.
            var p = afAamSound.play();
            if (p && typeof p.then === 'function') {
                p.then(function () {
                    // ок
                }).catch(function () {
                    // браузер запретил автоплей — не критично
                });
            }
        } catch (e) {}
    }

    function initToasts() {
        if (afAamToastContainer) {
            return;
        }
        var div = document.createElement('div');
        div.className = 'af-aam-toast-container';
        document.body.appendChild(div);
        afAamToastContainer = div;

        // гарантируем “дренаж” очереди, даже если снаружи никто не определил функцию
        window.afAamDrainToasts = function () {
            processToastQueue();
        };
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

    function afAamGetDefaultAvatarUrl() {
        // пробуем разные возможные глобалы (в темах/плагинах по-разному)
        if (typeof window.af_aam_default_avatar === 'string' && window.af_aam_default_avatar) {
            return window.af_aam_default_avatar;
        }
        if (typeof window.default_avatar === 'string' && window.default_avatar) {
            return window.default_avatar;
        }
        if (typeof window.mybb_default_avatar === 'string' && window.mybb_default_avatar) {
            return window.mybb_default_avatar;
        }

        // совсем фолбэк: если у тебя в assets лежит дефолт (необязательно)
        if (typeof window.af_aam_asset_base === 'string' && window.af_aam_asset_base) {
            var base = window.af_aam_asset_base;
            if (base[base.length - 1] !== '/') base += '/';
            return base + 'default_avatar.png';
        }

        return '';
    }

    function afAamCssUrl(url) {
        // безопасно для url('...') — убираем кавычки/переводы строк
        return String(url || '').replace(/['"\n\r\\]/g, '');
    }


    function markToastAlertRead(alert) {
        if (!alert) return;

        var id = parseInt(alert.id, 10) || 0;
        var wasUnread = !(alert.is_read && parseInt(alert.is_read, 10) === 1);

        if (wasUnread) {
            updateVisibleCounts(Math.max(0, currentUnread - 1));
            alert.is_read = 1;
        }

        if (!id) return;

        var payload = {
            action: 'af_aam_api',
            op: 'mark_read',
            id: id,
            alert_id: id,
            'alert_id[]': id,
            my_post_key: window.my_post_key || ''
        };

        var ok = afAamSendBeacon(payload);
        if (ok) return;

        ajax('xmlhttp.php', payload, function (resp) {
            syncFromResponse(resp || {}, wasUnread ? -1 : 0);
        }, 1500);
    }


    function spawnToast(alert) {
        if (!afAamUserToastsEnabled) return;

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

        // аватар слева
        var avatarWrap = document.createElement('span');
        avatarWrap.className = 'af-aam-toast-avatar';

        var img = document.createElement('img');

        var avatarUrl = (alert && alert.avatar && alert.avatar.url) ? String(alert.avatar.url) : '';
        if (!avatarUrl) {
            avatarUrl = afAamGetDefaultAvatarUrl();
        }

        // Ставим фон INLINE + !important: это убивает “перекрытие дефолтом” CSS’ом
        if (avatarUrl) {
            var safe = afAamCssUrl(avatarUrl);
            avatarWrap.style.setProperty('background-image', "url('" + safe + "')", 'important');
            avatarWrap.style.setProperty('background-size', 'cover', 'important');
            avatarWrap.style.setProperty('background-position', 'center', 'important');
            avatarWrap.style.setProperty('background-repeat', 'no-repeat', 'important');
        }

        // Сам img оставляем (если CSS его не скрывает — отлично; если скрывает — фон уже верный)
        if (avatarUrl) {
            img.src = avatarUrl;
        }
        img.width  = (alert && alert.avatar && alert.avatar.width)  ? (parseInt(alert.avatar.width, 10) || 32)  : 32;
        img.height = (alert && alert.avatar && alert.avatar.height) ? (parseInt(alert.avatar.height, 10) || 32) : 32;
        img.alt    = (alert && alert.avatar && alert.avatar.username) ? String(alert.avatar.username) : '';
        img.decoding = 'async';
        img.loading = 'lazy';
        img.draggable = false;

        // Если вдруг реальный аватар не грузится — упадём в дефолт (и тоже обновим фон)
        img.addEventListener('error', function () {
            var d = afAamGetDefaultAvatarUrl();
            if (d && img.src !== d) {
                img.src = d;

                var safeD = afAamCssUrl(d);
                avatarWrap.style.setProperty('background-image', "url('" + safeD + "')", 'important');
            }
        }, { once: true });

        avatarWrap.appendChild(img);

        var title = document.createElement('div');
        title.className = 'af-aam-toast-title';
        title.textContent = 'Новое уведомление';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'af-aam-toast-close';
        close.textContent = '×';

        header.appendChild(avatarWrap);
        header.appendChild(title);
        header.appendChild(close);

        var text = document.createElement('div');
        text.className = 'af-aam-toast-text';
        text.textContent = (alert && alert.text) ? String(alert.text) : '';

        div.appendChild(header);
        div.appendChild(text);

        var autoHide = setTimeout(function () {
            dismissToast(div);
        }, afAamToastDuration);

        div.addEventListener('click', function () {
            if (alert) {
                markToastAlertRead(alert);
                if (alert.id) {
                    saveLastSeen(alert.id);
                }
                if (alert.url) {
                    window.location.href = alert.url;
                }
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
        if (!Array.isArray(alerts) || !alerts.length) return;

        // тосты выключены пользователем
        if (!afAamUserToastsEnabled) return;

        for (var i = 0; i < alerts.length; i++) {
            var a = alerts[i];
            if (!a) continue;

            var id = parseInt(a.id, 10) || 0;

            // только непрочитанные
            if (a.is_read && parseInt(a.is_read, 10) === 1) continue;

            // уже видели/показывали
            if (id && lastSeenAlertId > 0 && id <= lastSeenAlertId) continue;
            if (id && afAamShownToastIds[id]) continue;

            afAamShownToastIds[id] = 1;
            afAamToastQueue.push(a);
        }

        // запуск показа, если у тебя дальше в файле есть drain/renderer — оставляем как было
        if (typeof window.afAamDrainToasts === 'function') {
            window.afAamDrainToasts();
        }
    }

    var afAamPollInFlight = false;
    var afAamInitialSnapshotDone = false;

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
        if (afAamPollInFlight) return;
        afAamPollInFlight = true;

        ajax('xmlhttp.php', {
            action: 'af_aam_api',
            op: 'list',
            unreadOnly: 0
        }, function (resp) {
            afAamPollInFlight = false;

            if (!resp || !resp.ok) {
                if (afAamDebug) console.log('[AAM] poll failed', resp);
                return;
            }

            // синк счётчика/шаблона
            syncFromResponse(resp || {});

            var items = Array.isArray(resp.items) ? resp.items : [];
            if (!items.length) {
                afAamInitialSnapshotDone = true;
                return;
            }

            // На первом нормальном ответе: делаем "слепок" и НЕ считаем это новыми уведомлениями
            // (решает твой кейс “кликнула лого — звук”)
            if (!afAamInitialSnapshotDone) {
                var maxId0 = 0;
                items.forEach(function (a) {
                    var id = parseInt(a.id, 10) || 0;
                    if (id > maxId0) maxId0 = id;
                });

                if (lastSeenAlertId <= 0 && maxId0 > 0) {
                    saveLastSeen(maxId0);
                }

                afAamInitialSnapshotDone = true;
                return;
            }

            // Есть ли реально новые (непр.) относительно lastSeenAlertId
            var hasNewUnseen = afAamHasNewUnseenUnread(items);
            var maxId = afAamMaxAlertId(items);

            // Тосты — только новые (queueToasts сама фильтрует)
            queueToasts(items);

            // Звук — ТОЛЬКО если реально пришло новое
            if (hasNewUnseen) {
                playAlertSound();
            }

            if (maxId > lastSeenAlertId) {
                saveLastSeen(maxId);
            }
        });
    }

    var afAamLongPollRunning = false;
    var afAamPollBackoff = 0;

    function afAamComputeNextDelay() {
        // в фоне чуть медленнее, но без “умирания”
        var hidden = document.hidden;
        var base = hidden ? 1200 : 150;
        if (afAamPollBackoff <= 0) return base;
        return Math.min(10000, base + afAamPollBackoff);
    }

    function afAamOnPollFail() {
        afAamPollBackoff = afAamPollBackoff ? Math.min(15000, afAamPollBackoff * 2) : 800;
    }

    function afAamOnPollOk() {
        afAamPollBackoff = 0;
    }

    function afAamLongPollLoop() {
        if (afAamLongPollRunning) return;
        afAamLongPollRunning = true;

        function tick() {
            var timeoutSec = document.hidden ? 30 : 25;
            var timeoutMs  = (timeoutSec + 5) * 1000;

            ajax('xmlhttp.php', {
                action: 'af_aam_api',
                op: 'poll',
                since_id: lastSeenAlertId || 0,
                since_unread: currentUnread || 0,
                timeout: timeoutSec
            }, function (resp) {
                if (!resp || resp.ok === 0) {
                    if (afAamDebug) console.log('[AAM] longpoll fail:', resp);
                    afAamOnPollFail();
                    return setTimeout(tick, afAamComputeNextDelay());
                }

                afAamOnPollOk();

                afAamHandleIncoming(resp);

                // следующий тик почти сразу, но с учётом backoff/visibility
                setTimeout(tick, afAamComputeNextDelay());
            }, timeoutMs);
        }

        // при возвращении на вкладку — ускоряемся
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                afAamPollBackoff = 0;
            }
        });

        tick();
    }

    function afAamHandleIncoming(resp) {
        if (!resp || typeof resp !== 'object') return;

        if (resp.error && !resp.ok) {
            if (afAamDebug) console.log('[AAM] server error:', resp);
            return;
        }

        var oldCount = currentUnread || 0;
        var newCount = extractUnreadCount(resp);
        if (newCount === null) newCount = oldCount;

        if (resp.template) {
            var tbody = qs('#alerts_content');
            if (tbody) tbody.innerHTML = resp.template;
        }

        // вычислим items заранее
        var items = Array.isArray(resp.items) ? resp.items : [];

        // обновляем newest id (сервер может прислать server_newest_id)
        var newest = 0;
        if (typeof resp.server_newest_id !== 'undefined') {
            newest = parseInt(resp.server_newest_id, 10) || 0;
        } else if (items.length) {
            newest = parseInt(items[0].id, 10) || 0;
        }

        updateVisibleCounts(newCount);

        // “новые” — это НЕ просто changed=1, а реально новые непрочитанные выше lastSeenAlertId
        var hasNewUnseen = false;
        if (items.length) {
            hasNewUnseen = afAamHasNewUnseenUnread(items);
        } else {
            // если items не дали, но счётчик вырос — тоже считаем событием (на случай урезанного ответа)
            hasNewUnseen = (newCount > oldCount);
        }

        if (hasNewUnseen) {
            playAlertSound();
            if (items.length) {
                showToastsFromAlerts(items);
            }
        }

        var maxCandidate = afAamMaxAlertId(items);
        if (newest > maxCandidate) {
            maxCandidate = newest;
        }

        if (maxCandidate > lastSeenAlertId) {
            saveLastSeen(maxCandidate);
        }
    }





    // ======== ИНИЦИАЛИЗАЦИЯ ==========================

    function removeSoundPrimers() {
        document.removeEventListener('click', primeSoundOnce, true);
        document.removeEventListener('keydown', primeSoundOnce, true);
        document.removeEventListener('pointerdown', primeSoundOnce, true);
    }

    function primeSoundOnce() {
        if (!afAamSound || afAamSoundPrimed || !afAamUserSoundEnabled) {
            removeSoundPrimers();
            return;
        }

        try {
            var p = afAamSound.play();
            if (p && typeof p.then === 'function') {
                p.then(function () {
                    afAamSound.pause();
                    afAamSound.currentTime = 0;
                    afAamSoundPrimed = true;
                    removeSoundPrimers();
                }).catch(function () {
                    // Firefox может отклонить первый автоплей — пробуем ещё раз на следующем жесте
                });
            } else {
                afAamSoundPrimed = true;
                removeSoundPrimers();
            }
        } catch (e) {
            // ждём следующего пользовательского жеста
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
        document.addEventListener('keydown', primeSoundOnce, true);
        document.addEventListener('pointerdown', primeSoundOnce, true);

        // сразу один раз дернем, чтобы синхронизировать бейдж без ожидания интервала
        pollUnreadAlerts();
        // long-poll realtime (работает лучше setInterval в фоне)
        afAamLongPollLoop();

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
            console.log('[AAM] interval fallback каждые', refreshInterval, 'сек (на случай, если long-poll упадёт)');
        }
        setInterval(function () {
            // лёгкий фолбэк: просто сверим счётчик
            pollUnreadAlerts();
        }, refreshInterval * 1000);
    }


    // запуск init после загрузки DOM
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

})();
