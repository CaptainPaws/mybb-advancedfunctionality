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
    function afAamGetReplyEditorHandles() {
        var ta =
            document.querySelector('textarea[name="message"]') ||
            document.getElementById('message') ||
            document.getElementById('message_new') ||
            document.querySelector('textarea[id^="message"]') ||
            null;

        var host =
            document.getElementById('quickreply_e') ||
            document.getElementById('quickreply') ||
            (ta ? (ta.closest ? ta.closest('form') : null) : null) ||
            null;

        var inst = null;
        if (ta && window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
            try { inst = window.jQuery(ta).sceditor('instance') || null; } catch (e) { inst = null; }
        }

        var body = null;
        try { body = inst && typeof inst.getBody === 'function' ? inst.getBody() : null; } catch (e2) { body = null; }

        return {
            textarea: ta || null,
            host: host || null,
            sceditor: inst || null,
            scBody: body || null
        };
    }

    function afAamJumpToReplyAndFocus() {
        var h = afAamGetReplyEditorHandles();
        var host = h.host;

        // попытка раскрыть quickreply, если он скрыт темой/скриптом
        if (host) {
            try {
                var cs = window.getComputedStyle(host);
                var hidden = (cs && (cs.display === 'none' || cs.visibility === 'hidden')) || host.hidden;
                if (hidden) {
                    // популярные тогглеры в темах MyBB
                    var toggler =
                        document.getElementById('quickreplylink') ||
                        document.querySelector('[data-toggle="quickreply"]') ||
                        document.querySelector('.quickreply_toggle, .quickreply-link, a.quickreply, a#quick_reply_button') ||
                        null;

                    if (toggler && typeof toggler.click === 'function') {
                        toggler.click();
                    } else {
                        host.style.display = 'block';
                        host.hidden = false;
                    }
                }
            } catch (e) {}
        }

        // прокрутка
        var scrollTarget = host || h.textarea || null;
        if (scrollTarget && scrollTarget.scrollIntoView) {
            try { scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e2) {
                try { scrollTarget.scrollIntoView(true); } catch (e3) {}
            }
        }

        // фокус (чуть позже, чтобы после scrollIntoView/раскрытия не слетел)
        setTimeout(function () {
            try {
                if (h.sceditor) {
                    h.sceditor.focus();
                    // иногда нужно дополнительно дать фокус body iframe
                    if (h.scBody && h.scBody.focus) h.scBody.focus();
                    return;
                }
            } catch (e) {}

            try {
                if (h.textarea && h.textarea.focus) {
                    h.textarea.focus();
                }
            } catch (e2) {}
        }, 60);

        return h;
    }

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

    function afAamEscapeHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function afAamNormalizeMentionLinks(root) {
        try {
            root = root || document;

            // поддержка “старых”/альтернативных классов (если где-то уже рендерится иначе)
            var links = root.querySelectorAll('a.af-aam-mention-link, a.mention, a.mybb-mention, a.mention_user');
            if (!links || !links.length) return;

            Array.prototype.forEach.call(links, function (a) {
                if (!a || !a.textContent) return;

                // приводим к нашему основному классу
                if (!a.classList.contains('af-aam-mention-link')) {
                    a.classList.add('af-aam-mention-link');
                }

                var t = (a.textContent || '').trim();
                if (!t) return;

                // если нет '@' — добавим, чтобы @ был того же цвета (внутри ссылки)
                if (t.charAt(0) !== '@') {
                    a.textContent = '@' + t;
                    t = a.textContent.trim();
                }

                // @all — отдельный класс (для тонкой стилизации)
                if (t.toLowerCase() === '@all') {
                    a.classList.add('af-aam-mention-all');
                }
            });
        } catch (e) {}
    }

    function afAamRenderMentionsInContainer(root) {
        try {
            if (!root) return;

            var html0 = root.innerHTML || '';

            // быстрый выход
            if (html0.indexOf('[mention') === -1) return;

            var html = html0;

            // [mention=123]Name[/mention] -> <a ...>@Name</a>
            html = html.replace(/\[mention=([0-9]+)\]([\s\S]*?)\[\/mention\]/g, function (_, uid, name) {
                var cleanName = String(name || '').trim();
                var safeName = afAamEscapeHtml(cleanName);
                var safeUid = String(uid).replace(/[^0-9]/g, '');

                var isAll = (cleanName.toLowerCase() === 'all');

                return '<a class="af-aam-mention-link' + (isAll ? ' af-aam-mention-all' : '') + '" ' +
                    'href="member.php?action=profile&uid=' + safeUid + '">' +
                    '@' + safeName +
                    '</a>';
            });

            // [mention]Name[/mention] -> <a ...>@Name</a>
            html = html.replace(/\[mention\]([\s\S]*?)\[\/mention\]/g, function (_, name) {
                var clean = String(name || '').trim();
                var safeName2 = afAamEscapeHtml(clean);
                var enc = encodeURIComponent(clean);

                var isAll2 = (clean.toLowerCase() === 'all');

                return '<a class="af-aam-mention-link' + (isAll2 ? ' af-aam-mention-all' : '') + '" ' +
                    'href="member.php?action=profile&username=' + enc + '">' +
                    '@' + safeName2 +
                    '</a>';
            });

            root.innerHTML = html;

            // На всякий случай: нормализуем уже готовые ссылки, чтобы @ точно был
            afAamNormalizeMentionLinks(root);

        } catch (e) {
            // молча
        }
    }



    function syncFromResponse(resp, deltaIfUnknown) {
        if (afAamDebug) {
            console.log('[AAM sync]', resp, 'deltaIfUnknown =', deltaIfUnknown);
        }

        if (!resp || typeof resp !== 'object') {
            if (typeof deltaIfUnknown === 'number' && deltaIfUnknown !== 0) {
                updateVisibleCounts(Math.max(0, currentUnread + deltaIfUnknown));
            } else {
                pollUnreadAlerts();
            }
            return;
        }

        if (afAamDebug && resp.debug) {
            console.log('[AAM debug (server)]', resp.debug);
        }

        var hasDelta = (typeof deltaIfUnknown === 'number' && deltaIfUnknown !== 0);
        var cnt = extractUnreadCount(resp);

        if (typeof cnt === 'number' && !isNaN(cnt)) {
            updateVisibleCounts(cnt);
        } else if (hasDelta) {
            updateVisibleCounts(Math.max(0, currentUnread + deltaIfUnknown));
        } else {
            pollUnreadAlerts();
        }

        if (resp.template) {
            var tbody = qs('#alerts_content');
            if (tbody) {
                tbody.innerHTML = resp.template;

                // ФИКС №2: превращаем [mention] в жирную ссылку на профиль
                afAamRenderMentionsInContainer(tbody);
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
                                    op: 'prefs_save',
                                    types: types,
                                    toasts: toastsEnabled ? 1 : 0,
                                    my_post_key: window.my_post_key || ''
                                }, function () {
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
            afAamSound.crossOrigin = 'anonymous';

            afAamSound.addEventListener('error', function () {
                try {
                    afAamSound = new Audio(base2 + 'ping.mp3');
                    afAamSound.preload = 'auto';
                    afAamSound.volume = 1.0;
                    afAamSound.muted = false;
                    afAamSound.crossOrigin = 'anonymous';
                    afAamSound.load();
                } catch (e2) {
                    afAamSound = null;
                }
            }, { once: true });
            afAamSound.load();
        } catch (e) {
            afAamSound = null;
        }

        if (afAamSound && document.body && !afAamSound.parentNode) {
            // Firefox не всегда играет звук для "висячих" объектов Audio
            afAamSound.style.display = 'none';
            document.body.appendChild(afAamSound);
        }

        if (afAamDebug) console.log('[AAM] initSound ok=', !!afAamSound);
    }


    function playAlertSound() {
        if (!afAamGlobalSoundEnabled) return;
        if (!afAamUserSoundEnabled) return;
        if (!afAamSound) return;

        try {
            afAamSound.pause();
            afAamSound.currentTime = 0;
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
            if (afAamPollInFlight) {
                return setTimeout(tick, afAamComputeNextDelay());
            }

            afAamPollInFlight = true;
            var timeoutSec = document.hidden ? 30 : 25;
            var timeoutMs  = (timeoutSec + 5) * 1000;

            ajax('xmlhttp.php', {
                action: 'af_aam_api',
                op: 'poll',
                since_id: lastSeenAlertId || 0,
                since_unread: currentUnread || 0,
                timeout: timeoutSec
            }, function (resp) {
                afAamPollInFlight = false;
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

    function initMentionButtons() {
        // Делегированный клик по кнопкам "Упомянуть" (постбит и любые места)
        // Поддерживаем разные варианты разметки:
        //  - .af-aam-mention-btn / .af-aam-mention-button
        //  - [data-af-aam-mention], [data-mention-username], [data-mention-uid]
        // Вставляем ТОЛЬКО BBCode:
        //  - [mention=uid]username[/mention] или [mention]username[/mention]
        // Никаких "@username" — чтобы не ломать твой текущий парсер.

        function closest(el, selector) {
            if (!el) return null;
            if (el.closest) return el.closest(selector);
            // fallback для старых браузеров
            while (el) {
                if (el.matches && el.matches(selector)) return el;
                el = el.parentNode;
            }
            return null;
        }

        function sanitizeName(s) {
            s = String(s || '').trim();
            if (!s) return '';
            if (s.charAt(0) === '@') s = s.slice(1).trim();
            // убираем переносы/табы
            s = s.replace(/[\r\n\t]+/g, ' ').trim();
            // схлопнем двойные пробелы
            s = s.replace(/\s{2,}/g, ' ');
            return s;
        }

        function parseUidFromHref(href) {
            href = String(href || '');
            // member.php?action=profile&uid=123
            var m = href.match(/[?&]uid=([0-9]+)/i);
            if (m && m[1]) return parseInt(m[1], 10) || 0;
            return 0;
        }

        function parseUsernameFromHref(href) {
            href = String(href || '');
            // member.php?action=profile&username=Name
            var m = href.match(/[?&]username=([^&#]+)/i);
            if (m && m[1]) {
                try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
            }
            return '';
        }

        function extractUserFromElement(btn) {
            // 1) data- атрибуты на самой кнопке или родителе
            var holder = btn;

            var host = closest(holder, '[data-mention-username], [data-mention-uid], [data-username], [data-uid], [data-af-aam-username], [data-af-aam-uid]') || holder;

            var uid = 0;
            var username = '';

            function readAttr(el, name) {
                try { return el && el.getAttribute ? el.getAttribute(name) : ''; } catch (e) { return ''; }
            }

            var rawUid =
                readAttr(host, 'data-mention-uid') ||
                readAttr(host, 'data-af-aam-uid') ||
                readAttr(host, 'data-uid') ||
                '';

            if (rawUid) uid = parseInt(String(rawUid).replace(/[^0-9]/g, ''), 10) || 0;

            var rawName =
                readAttr(host, 'data-mention-username') ||
                readAttr(host, 'data-af-aam-username') ||
                readAttr(host, 'data-username') ||
                '';

            if (rawName) username = sanitizeName(rawName);

            // 2) если кнопка рядом с ссылкой на профиль — попробуем вытащить из неё
            if (!username || !uid) {
                var link =
                    closest(holder, 'tr, li, .post, .postbit') ?
                        (closest(holder, 'tr, li, .post, .postbit').querySelector('a[href*="member.php?action=profile"]') || null)
                        : null;

                if (link) {
                    if (!uid) uid = parseUidFromHref(link.href);
                    if (!username) username = sanitizeName(parseUsernameFromHref(link.href) || link.textContent);
                }
            }

            // 3) крайний фолбэк — текст самой кнопки (если вдруг там ник)
            if (!username) username = sanitizeName(btn.textContent);

            return { uid: uid || 0, username: username || '' };
        }

        function buildMentionTag(user) {
            var name = sanitizeName(user && user.username ? user.username : '');
            var uid = parseInt(user && user.uid ? user.uid : 0, 10) || 0;

            if (!name) return '';

            // @all — разрешим как [mention]all[/mention]
            if (name.toLowerCase() === 'all') {
                return '[mention]all[/mention] ';
            }

            if (uid > 0) {
                return '[mention=' + uid + ']' + name + '[/mention] ';
            }
            return '[mention]' + name + '[/mention] ';
        }

        function insertIntoEditor(text) {
            if (!text) return false;

            // используем твою функцию — она уже умеет раскрывать quickreply и фокусить
            var h = (typeof afAamJumpToReplyAndFocus === 'function') ? afAamJumpToReplyAndFocus() : null;
            if (!h) return false;

            // SCEditor
            if (h.sceditor && typeof h.sceditor.insertText === 'function') {
                try {
                    h.sceditor.insertText(text, '');
                    return true;
                } catch (e) {}
            }

            // textarea
            if (h.textarea) {
                try {
                    var ta = h.textarea;
                    var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
                    var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : ta.value.length;

                    var before = ta.value.slice(0, start);
                    var after = ta.value.slice(end);

                    ta.value = before + text + after;

                    var pos = (before + text).length;
                    if (ta.setSelectionRange) ta.setSelectionRange(pos, pos);
                    return true;
                } catch (e2) {
                    // фолбэк: допишем в конец
                    try { h.textarea.value += text; return true; } catch (e3) {}
                }
            }

            return false;
        }

        // Чтобы не навесить дважды, ставим флаг
        if (window.afAamMentionButtonsInited) return;
        window.afAamMentionButtonsInited = true;

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t) return;

            // Ищем кнопку/ссылку "упомянуть"
            var btn = closest(t,
                '.af-aam-mention-btn, .af-aam-mention-button, .af-aam-mention, ' +
                '[data-af-aam-mention="1"], [data-af-aam-mention], ' +
                '[data-mention-username], [data-mention-uid]'
            );

            if (!btn) return;

            // ЛКМ без модификаторов — вставляем mention. Остальное оставляем браузеру (открытие в новой вкладке и т.п.)
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

            e.preventDefault();

            var u = extractUserFromElement(btn);
            if (!u.username && !u.uid) {
                if (typeof window.afAamDebug !== 'undefined' && window.afAamDebug) {
                    console.log('[AAM] mention button: не смогли вытащить user из элемента', btn);
                }
                return;
            }

            var tag = buildMentionTag(u);
            if (!tag) return;

            insertIntoEditor(tag);
        });
    }

    function initPostAuthorNicknameClicks() {
        // Клики по нику автора поста → вставка [mention]...[/mention]
        // Безопасный режим:
        //  - ALT+ЛКМ по нику автора: вставить mention и прыгнуть к ответу
        //  - Обычный ЛКМ: оставить переход в профиль (НЕ ломаем UX)
        // Опционально:
        //  - если у ссылки ника есть класс .af-aam-mention-onclick или атрибут data-af-aam-mention-onclick="1"
        //    тогда mention вставится и по обычному ЛКМ.

        function closest(el, selector) {
            if (!el) return null;
            if (el.closest) return el.closest(selector);
            while (el) {
                if (el.matches && el.matches(selector)) return el;
                el = el.parentNode;
            }
            return null;
        }

        function sanitizeName(s) {
            s = String(s || '').trim();
            if (!s) return '';
            if (s.charAt(0) === '@') s = s.slice(1).trim();
            s = s.replace(/[\r\n\t]+/g, ' ').trim().replace(/\s{2,}/g, ' ');
            return s;
        }

        function parseUidFromHref(href) {
            href = String(href || '');
            var m = href.match(/[?&]uid=([0-9]+)/i);
            if (m && m[1]) return parseInt(m[1], 10) || 0;
            return 0;
        }

        function parseUsernameFromHref(href) {
            href = String(href || '');
            var m = href.match(/[?&]username=([^&#]+)/i);
            if (m && m[1]) {
                try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
            }
            return '';
        }

        function buildMention(uid, username) {
            username = sanitizeName(username);
            uid = parseInt(uid || 0, 10) || 0;
            if (!username) return '';

            if (username.toLowerCase() === 'all') {
                return '[mention]all[/mention] ';
            }

            if (uid > 0) return '[mention=' + uid + ']' + username + '[/mention] ';
            return '[mention]' + username + '[/mention] ';
        }

        function insertMention(text) {
            if (!text) return false;

            var h = (typeof afAamJumpToReplyAndFocus === 'function') ? afAamJumpToReplyAndFocus() : null;
            if (!h) return false;

            if (h.sceditor && typeof h.sceditor.insertText === 'function') {
                try { h.sceditor.insertText(text, ''); return true; } catch (e) {}
            }

            if (h.textarea) {
                try {
                    var ta = h.textarea;
                    var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
                    var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : ta.value.length;
                    ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
                    var pos = start + text.length;
                    if (ta.setSelectionRange) ta.setSelectionRange(pos, pos);
                    return true;
                } catch (e2) {
                    try { h.textarea.value += text; return true; } catch (e3) {}
                }
            }

            return false;
        }

        function isAuthorLink(a) {
            if (!a || !a.tagName || a.tagName.toLowerCase() !== 'a') return false;

            var href = String(a.getAttribute('href') || '');
            if (href.indexOf('member.php') === -1 || href.indexOf('action=profile') === -1) {
                return false;
            }

            // Должно быть в контексте поста/постбита, чтобы не цеплять любые профили на странице
            var inPost =
                !!closest(a, '.post, .postbit, .postclassic, .post_author, .author_information, [id^="post_"], tr[id^="post_"], div[id^="post_"]');

            return inPost;
        }

        function wantsMentionOnPlainClick(a) {
            try {
                if (a.classList && a.classList.contains('af-aam-mention-onclick')) return true;
                var v = a.getAttribute('data-af-aam-mention-onclick');
                if (v === '1' || v === 'true') return true;
            } catch (e) {}
            return false;
        }

        if (window.afAamPostAuthorClicksInited) return;
        window.afAamPostAuthorClicksInited = true;

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t) return;

            // ловим клик по ссылке ника или по вложенным элементам внутри неё
            var a = closest(t, 'a');
            if (!a) return;
            if (!isAuthorLink(a)) return;

            // Правило:
            //  - ALT+ЛКМ → mention
            //  - обычный ЛКМ → mention ТОЛЬКО если явно разрешено (класс/атрибут)
            var plainMention = wantsMentionOnPlainClick(a);

            var isLmb = (e.button === 0);
            var hasModifiers = (e.ctrlKey || e.metaKey || e.shiftKey); // alt отдельно, он наш триггер

            if (!isLmb) return;
            if (hasModifiers) return; // Ctrl/Shift — оставим браузеру (новая вкладка, выделение и т.д.)

            var doMention = false;
            if (e.altKey) doMention = true;
            else if (plainMention) doMention = true;

            if (!doMention) return;

            e.preventDefault();

            var uid = parseUidFromHref(a.href);
            var username = sanitizeName(parseUsernameFromHref(a.href) || a.textContent);

            // иногда темы вставляют ник в span внутри ссылки
            if (!username) {
                username = sanitizeName(a.textContent || '');
            }

            var tag = buildMention(uid, username);
            if (!tag) return;

            insertMention(tag);
        });
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
        initListClicks();
        initPostAuthorNicknameClicks();
        afAamNormalizeMentionLinks(document);

        // автокомплит живёт в aam_mentions.js — тут только безопасный вызов, чтобы main не падал
        (function safeInitMentionAutocomplete() {
            try {
                // 1) самый очевидный вариант: функция экспортирована в window
                if (typeof window.initMentionAutocomplete === 'function') {
                    window.initMentionAutocomplete();
                    return;
                }

                // 2) запасной: если ты в mentions-файле назвала иначе
                if (typeof window.afAamInitMentionAutocomplete === 'function') {
                    window.afAamInitMentionAutocomplete();
                    return;
                }

                // 3) если mentions.js грузится позже — попробуем пару раз догнать (без вечного интервала)
                var tries = 0;
                (function retry() {
                    tries++;
                    if (typeof window.initMentionAutocomplete === 'function') {
                        window.initMentionAutocomplete();
                        return;
                    }
                    if (typeof window.afAamInitMentionAutocomplete === 'function') {
                        window.afAamInitMentionAutocomplete();
                        return;
                    }
                    if (tries < 12) {
                        setTimeout(retry, 250);
                    } else if (afAamDebug) {
                        console.log('[AAM] mention autocomplete not available (aam_mentions.js?)');
                    }
                })();
            } catch (e) {
                if (afAamDebug) console.log('[AAM] safeInitMentionAutocomplete error', e);
            }
        })();


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
