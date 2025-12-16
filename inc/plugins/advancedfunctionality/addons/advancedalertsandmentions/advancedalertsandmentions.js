(function () {
    'use strict';

    // защита от многократного подключения
    if (window.afAamAlertsInitialized) return;
    window.afAamAlertsInitialized = true;

    // debug:
    //   до загрузки:    window.afAamDebug = true;
    //   после загрузки: window.afAamSetDebug(true);
    var afAamDebug = !!window.afAamDebug;

    window.afAamSetDebug = function (flag) {
        afAamDebug = !!flag;
        window.afAamDebug = afAamDebug;
        console.log('[AAM] debug =', afAamDebug);
    };

    function qs(sel) { return document.querySelector(sel); }
    function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    // ====== счетчик / заголовок / lastSeen ======
    var currentUnread = 0;
    if (typeof window.unreadAlerts !== 'undefined') {
        currentUnread = parseInt(window.unreadAlerts, 10) || 0;
    }

    var afAamBaseTitle = document.title;
    var lastSeenAlertId = 0;

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

    // ====== тосты ======
    var afAamToastLimit = parseInt(window.af_aam_toast_limit || '0', 10) || 0;
    var afAamToastQueue = [];
    var afAamActiveToasts = 0;
    var afAamToastDuration = 10000;
    var afAamShownToastIds = Object.create(null);
    var afAamToastContainer = null;

    // ====== звук (глобал + пользовательский) ======
    var rawSoundFlag = (typeof window.af_aam_sound_enabled !== 'undefined') ? window.af_aam_sound_enabled : 1;
    var afAamGlobalSoundEnabled =
        (parseInt(rawSoundFlag, 10) === 1) || (rawSoundFlag === true || rawSoundFlag === 'true');

    var afAamSound = null;
    var afAamSoundPrimed = false;

    var afAamSoundStorageKey = 'af_aam_sound_muted';
    var afAamUserSoundEnabled = afAamGlobalSoundEnabled;

    try {
        var lsVal = localStorage.getItem(afAamSoundStorageKey);
        if (lsVal === '1') afAamUserSoundEnabled = false;
    } catch (e) {}

    // тосты: настройка пользователя (localStorage)
    var afAamToastsStorageKey = 'af_aam_toasts_disabled';
    var afAamUserToastsEnabled = true;
    try {
        var tVal = localStorage.getItem(afAamToastsStorageKey);
        if (tVal === '1') afAamUserToastsEnabled = false;
    } catch (e) {}

    // ======== AJAX POST =========
    function ajax(url, data, callback, timeoutMs) {
        var xhr = new XMLHttpRequest();
        data = data || {};

        if (typeof data._ts === 'undefined') data._ts = Date.now();

        var params = [];
        for (var key in data) {
            if (!Object.prototype.hasOwnProperty.call(data, key)) continue;

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

        if (afAamDebug) console.log('[AAM ajax →]', url, data);

        xhr.open('POST', url, true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (typeof timeoutMs === 'number' && timeoutMs > 0) xhr.timeout = timeoutMs;

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

            if (afAamDebug) console.log('[AAM ajax ←]', 'status=' + xhr.status, 'resp=', xhr.responseText);

            if (xhr.status === 0 && (!xhr.responseText || !xhr.responseText.trim())) {
                if (typeof callback === 'function') callback({ ok: 0, error: 'status_0' });
                return;
            }

            var resp = null;
            try { resp = JSON.parse(xhr.responseText); }
            catch (e) { resp = { ok: 0, error: 'bad_json', raw: xhr.responseText }; }

            if (typeof callback === 'function') callback(resp);
        };

        xhr.send(body);
    }

    // ======== sendBeacon =========
    function afAamSendBeacon(payload) {
        try {
            if (!navigator.sendBeacon) return false;

            var params = [];
            for (var k in payload) {
                if (!Object.prototype.hasOwnProperty.call(payload, k)) continue;
                params.push(encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]));
            }

            var blob = new Blob([params.join('&')], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
            return navigator.sendBeacon('xmlhttp.php', blob);
        } catch (e) {
            return false;
        }
    }

    // экспортируем в window (иногда полезно для других файлов)
    window.afAamAjaxPost = ajax;
    window.afAamSendBeacon = afAamSendBeacon;

    // ===== ID алерта =====
    function getAlertIdFromElement(el) {
        if (!el) return 0;

        if (el.getAttribute) {
            var rawData = el.getAttribute('data-alert-id');
            if (rawData) {
                var n1 = parseInt(String(rawData).replace(/[^0-9]/g, ''), 10);
                if (!isNaN(n1) && n1 > 0) return n1;
            }
        }

        if (el.id) {
            var n2 = parseInt(String(el.id).replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n2) && n2 > 0) return n2;
        }

        var node = el.closest ? el.closest('[data-alert-id]') : null;
        if (node && node.getAttribute) {
            var raw = node.getAttribute('data-alert-id') || '';
            var n3 = parseInt(String(raw).replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n3) && n3 > 0) return n3;
        }

        return 0;
    }

    // ===== unread count parsing =====
    function extractUnreadCount(resp) {
        if (!resp || typeof resp !== 'object') return null;

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

        return (typeof cand === 'number' && !isNaN(cand)) ? cand : null;
    }

    function updateVisibleCounts(unreadCount) {
        currentUnread = Math.max(0, unreadCount || 0);

        var badge = qs('#af_aam_badge');
        if (badge) badge.textContent = currentUnread;

        if (currentUnread > 0) document.title = '(' + currentUnread + ') ' + afAamBaseTitle;
        else document.title = afAamBaseTitle;

        var alertsContainer = document.querySelector('.af-aam-alerts');
        if (alertsContainer) alertsContainer.classList.toggle('alerts--new', currentUnread > 0);

        if (afAamDebug) console.log('[AAM] unread updated:', currentUnread);
    }

    function syncFromResponse(resp, deltaIfUnknown) {
        if (afAamDebug) console.log('[AAM sync]', resp, 'deltaIfUnknown =', deltaIfUnknown);

        if (!resp || typeof resp !== 'object') {
            if (typeof deltaIfUnknown === 'number' && deltaIfUnknown !== 0) {
                updateVisibleCounts(Math.max(0, currentUnread + deltaIfUnknown));
            } else {
                pollUnreadAlerts();
            }
            return;
        }

        if (afAamDebug && resp.debug) console.log('[AAM debug (server)]', resp.debug);

        var cnt = extractUnreadCount(resp);
        var hasDelta = (typeof deltaIfUnknown === 'number' && deltaIfUnknown !== 0);

        if (typeof cnt === 'number' && !isNaN(cnt)) updateVisibleCounts(cnt);
        else if (hasDelta) updateVisibleCounts(Math.max(0, currentUnread + deltaIfUnknown));
        else pollUnreadAlerts();

        if (resp.template) {
            var tbody = qs('#alerts_content');
            if (tbody) tbody.innerHTML = resp.template;
        }
    }

    // ===== helpers: новые непрочитанные =====
    function afAamHasNewUnseenUnread(alerts) {
        if (!Array.isArray(alerts) || !alerts.length) return false;

        for (var i = 0; i < alerts.length; i++) {
            var a = alerts[i];
            if (!a) continue;

            var id = parseInt(a.id, 10) || 0;

            if (a.is_read && parseInt(a.is_read, 10) === 1) continue;
            if (id && lastSeenAlertId > 0 && id <= lastSeenAlertId) continue;
            if (id && afAamShownToastIds[id]) continue;

            return true;
        }
        return false;
    }

    function afAamMaxAlertId(alerts) {
        if (!Array.isArray(alerts) || !alerts.length) return 0;
        var maxId = 0;
        for (var i = 0; i < alerts.length; i++) {
            var a = alerts[i];
            if (!a) continue;
            var id = parseInt(a.id, 10) || 0;
            if (id > maxId) maxId = id;
        }
        return maxId;
    }

    // ===== Sound =====
    function initSound() {
        if (!afAamGlobalSoundEnabled) {
            if (afAamDebug) console.log('[AAM] sound globally disabled');
            return;
        }

        var domAudio = document.getElementById('af_aam_sound');
        if (domAudio && typeof domAudio.play === 'function') {
            afAamSound = domAudio;

            if ((!afAamSound.src || afAamSound.src === window.location.href) && typeof window.af_aam_asset_base === 'string') {
                var base = window.af_aam_asset_base;
                if (base[base.length - 1] !== '/') base += '/';

                afAamSound.src = base + 'notification.mp3';
                afAamSound.addEventListener('error', function () {
                    afAamSound.src = base + 'ping.mp3';
                }, { once: true });
            }

            afAamSound.muted = false;
            afAamSound.volume = 1.0;

            if (afAamDebug) console.log('[AAM] initSound: using existing <audio>');
            return;
        }

        if (typeof window.af_aam_asset_base !== 'string') {
            if (afAamDebug) console.log('[AAM] initSound: no af_aam_asset_base, skip');
            return;
        }

        var base2 = window.af_aam_asset_base;
        if (base2[base2.length - 1] !== '/') base2 += '/';

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
            try {
                afAamSound.style.display = 'none';
                document.body.appendChild(afAamSound);
            } catch (e3) {}
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

            var p = afAamSound.play();
            if (p && typeof p.then === 'function') {
                p.catch(function () { /* autoplay block -> ignore */ });
            }
        } catch (e) {}
    }

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
                }).catch(function () { /* wait next gesture */ });
            } else {
                afAamSoundPrimed = true;
                removeSoundPrimers();
            }
        } catch (e) {}
    }

    // ===== Toasts =====
    function initToasts() {
        if (afAamToastContainer) return;

        var div = document.createElement('div');
        div.className = 'af-aam-toast-container';
        document.body.appendChild(div);
        afAamToastContainer = div;

        window.afAamDrainToasts = function () {
            processToastQueue();
        };
    }

    function dismissToast(div, timeoutId) {
        if (!div) return;

        if (timeoutId) clearTimeout(timeoutId);

        div.classList.remove('af-aam-toast-show');
        setTimeout(function () {
            if (div.parentNode === afAamToastContainer) afAamToastContainer.removeChild(div);
            if (afAamActiveToasts > 0) afAamActiveToasts--;
            processToastQueue();
        }, 200);
    }

    function afAamGetDefaultAvatarUrl() {
        if (typeof window.af_aam_default_avatar === 'string' && window.af_aam_default_avatar) return window.af_aam_default_avatar;
        if (typeof window.default_avatar === 'string' && window.default_avatar) return window.default_avatar;
        if (typeof window.mybb_default_avatar === 'string' && window.mybb_default_avatar) return window.mybb_default_avatar;

        if (typeof window.af_aam_asset_base === 'string' && window.af_aam_asset_base) {
            var base = window.af_aam_asset_base;
            if (base[base.length - 1] !== '/') base += '/';
            return base + 'default_avatar.png';
        }

        return '';
    }

    function afAamCssUrl(url) {
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

        if (!afAamToastContainer) initToasts();
        if (!afAamToastContainer) return;

        afAamActiveToasts++;

        var div = document.createElement('div');
        div.className = 'af-aam-toast';

        var header = document.createElement('div');
        header.className = 'af-aam-toast-header';

        var avatarWrap = document.createElement('span');
        avatarWrap.className = 'af-aam-toast-avatar';

        var img = document.createElement('img');

        var avatarUrl = (alert && alert.avatar && alert.avatar.url) ? String(alert.avatar.url) : '';
        if (!avatarUrl) avatarUrl = afAamGetDefaultAvatarUrl();

        if (avatarUrl) {
            var safe = afAamCssUrl(avatarUrl);
            avatarWrap.style.setProperty('background-image', "url('" + safe + "')", 'important');
            avatarWrap.style.setProperty('background-size', 'cover', 'important');
            avatarWrap.style.setProperty('background-position', 'center', 'important');
            avatarWrap.style.setProperty('background-repeat', 'no-repeat', 'important');
        }

        if (avatarUrl) img.src = avatarUrl;
        img.width = (alert && alert.avatar && alert.avatar.width) ? (parseInt(alert.avatar.width, 10) || 32) : 32;
        img.height = (alert && alert.avatar && alert.avatar.height) ? (parseInt(alert.avatar.height, 10) || 32) : 32;
        img.alt = (alert && alert.avatar && alert.avatar.username) ? String(alert.avatar.username) : '';
        img.decoding = 'async';
        img.loading = 'lazy';
        img.draggable = false;

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
                if (alert.id) saveLastSeen(alert.id);
                if (alert.url) window.location.href = alert.url;
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
        if (!afAamToastContainer || afAamToastLimit <= 0) return;

        while (afAamActiveToasts < afAamToastLimit && afAamToastQueue.length) {
            spawnToast(afAamToastQueue.shift());
        }
    }

    function queueToasts(alerts) {
        if (!Array.isArray(alerts) || !alerts.length) return;
        if (!afAamUserToastsEnabled) return;

        for (var i = 0; i < alerts.length; i++) {
            var a = alerts[i];
            if (!a) continue;

            var id = parseInt(a.id, 10) || 0;

            if (a.is_read && parseInt(a.is_read, 10) === 1) continue;
            if (id && lastSeenAlertId > 0 && id <= lastSeenAlertId) continue;
            if (id && afAamShownToastIds[id]) continue;

            afAamShownToastIds[id] = 1;
            afAamToastQueue.push(a);
        }

        if (typeof window.afAamDrainToasts === 'function') window.afAamDrainToasts();
    }

    function showToastsFromAlerts(alerts) {
        if (!alerts || !alerts.length || afAamToastLimit <= 0) {
            if (afAamDebug) console.log('[AAM] showToastsFromAlerts: пусто или лимит 0');
            return;
        }
        initToasts();
        queueToasts(alerts);
        if (afAamDebug) console.log('[AAM] showToastsFromAlerts: тосты поставлены в очередь');
    }

    // ===== UI: колокольчик + модалка =====
    function initAlertsUI() {
        var link = qs('#af_aam_header_link');
        var modal = qs('#af_aam_modal');
        var alertsTable = qs('#alerts_content');
        var bell = qs('#af_aam_bell') || qs('.af-aam-bell');
        var modalClose = modal ? modal.querySelector('.af-aam-modal-close') : null;
        var backdrop = modal ? modal.querySelector('.af-aam-modal-backdrop') : null;

        // Тумблер звука в модалке (если есть)
        var soundToggle = qs('#af_aam_sound_toggle');
        if (soundToggle) {
            soundToggle.checked = afAamUserSoundEnabled;

            soundToggle.addEventListener('change', function () {
                afAamUserSoundEnabled = !!soundToggle.checked;
                try {
                    if (!afAamUserSoundEnabled) localStorage.setItem(afAamSoundStorageKey, '1');
                    else {
                        localStorage.removeItem(afAamSoundStorageKey);
                        playAlertSound();
                    }
                } catch (e) {}
            });
        }

        if (bell && !bell.textContent) bell.textContent = '🔔';

        if (!link || !modal || !alertsTable) return;

        function renderModal(unreadOnly) {
            ajax('xmlhttp.php', { action: 'af_aam_api', op: 'list', unreadOnly: unreadOnly ? 1 : 0 }, function (resp) {
                syncFromResponse(resp || {});
            });
        }

        function openModal() {
            renderModal();
            modal.classList.add('af-aam-modal-open');
            modal.style.display = 'block';

            // попытка “разбудить” звук от клика по колоколу
            if (afAamSound && afAamUserSoundEnabled && !afAamSoundPrimed) {
                try {
                    afAamSound.play().then(function () {
                        afAamSound.pause();
                        afAamSound.currentTime = 0;
                        afAamSoundPrimed = true;
                    }).catch(function () {});
                } catch (e) {}
            }
        }

        function closeModal() {
            modal.classList.remove('af-aam-modal-open');
            modal.style.display = 'none';
        }

        link.addEventListener('click', function (e) {
            if (e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
                e.preventDefault();
                openModal();
            }
        });

        if (modalClose) {
            modalClose.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        // Preferences block (если есть)
        var prefsToggle = qs('#af_aam_prefs_toggle');
        var prefsBox = qs('#af_aam_prefs_inline');

        if (prefsToggle && prefsBox) {
            prefsToggle.addEventListener('click', function (e) {
                e.preventDefault();

                if (prefsBox.getAttribute('data-loaded') === '1') {
                    prefsBox.style.display = (prefsBox.style.display === 'none' || !prefsBox.style.display) ? 'block' : 'none';
                    return;
                }

                ajax('xmlhttp.php', { action: 'af_aam_api', op: 'prefs_form' }, function (resp) {
                    if (resp && resp.ok && resp.html) {
                        prefsBox.innerHTML = resp.html;

                        try {
                            var toastCb0 = prefsBox.querySelector('#af_aam_pref_toasts');
                            if (toastCb0) toastCb0.checked = afAamUserToastsEnabled;
                        } catch (e2) {}

                        prefsBox.setAttribute('data-loaded', '1');
                        prefsBox.style.display = 'block';

                        var form = prefsBox.querySelector('#af_aam_prefs_form');
                        if (form) {
                            form.addEventListener('submit', function (ev) {
                                ev.preventDefault();

                                // тосты: локальная настройка
                                var toastCb = prefsBox.querySelector('#af_aam_pref_toasts');
                                var toastsEnabled = toastCb ? !!toastCb.checked : true;

                                afAamUserToastsEnabled = toastsEnabled;
                                try {
                                    if (!toastsEnabled) localStorage.setItem(afAamToastsStorageKey, '1');
                                    else localStorage.removeItem(afAamToastsStorageKey);
                                } catch (e3) {}

                                // обновим список непрочитанных, чтобы UI был синхронен
                                ajax('xmlhttp.php', { action: 'af_aam_api', op: 'list', unreadOnly: 1 }, function (resp2) {
                                    // первый запуск: запомнить текущий максимум как “уже видели”
                                    if (!lastSeenAlertId && resp2 && Array.isArray(resp2.items) && resp2.items.length) {
                                        var maxId = 0;
                                        resp2.items.forEach(function (a) {
                                            var id = parseInt(a.id, 10) || 0;
                                            if (id > maxId) maxId = id;
                                        });
                                        if (maxId > 0) saveLastSeen(maxId);
                                        syncFromResponse(resp2 || {});
                                        return;
                                    }

                                    var hasNewUnseen = (resp2 && Array.isArray(resp2.items))
                                        ? afAamHasNewUnseenUnread(resp2.items)
                                        : false;

                                    syncFromResponse(resp2 || {});
                                    if (resp2 && Array.isArray(resp2.items)) queueToasts(resp2.items);
                                    if (hasNewUnseen) playAlertSound();
                                });
                            });
                        }
                    }
                });
            });
        }

        // клики внутри модалки: read/unread/delete/link
        modal.addEventListener('click', function (e) {
            var target = e.target;

            // 1) прочитано
            if (target.classList && target.classList.contains('markReadAlertButton')) {
                e.preventDefault();

                var row = target.closest('[data-alert-id]');
                var id = row ? getAlertIdFromElement(row) : getAlertIdFromElement(target);
                var wasUnread = false;

                if (row) {
                    wasUnread = row.classList.contains('alert--unread');
                    row.classList.remove('alert--unread');
                    row.classList.add('alert--read');

                    var btnRead = row.querySelector('.markReadAlertButton');
                    var btnUnread = row.querySelector('.markUnreadAlertButton');
                    if (btnRead) btnRead.classList.add('hidden');
                    if (btnUnread) btnUnread.classList.remove('hidden');
                }

                if (id) {
                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'mark_read',
                        id: id,
                        alert_id: id,
                        'alert_id[]': id,
                        my_post_key: window.my_post_key || ''
                    }, function (resp3) {
                        syncFromResponse(resp3 || {}, wasUnread ? -1 : 0);
                    });
                } else if (afAamDebug) {
                    console.log('[AAM] mark_read: не удалось определить id');
                }
                return;
            }

            // 2) непрочитано
            if (target.classList && target.classList.contains('markUnreadAlertButton')) {
                e.preventDefault();

                var row2 = target.closest('[data-alert-id]');
                var id2 = row2 ? getAlertIdFromElement(row2) : getAlertIdFromElement(target);
                var wasRead = false;

                if (row2) {
                    wasRead = row2.classList.contains('alert--read');
                    row2.classList.remove('alert--read');
                    row2.classList.add('alert--unread');

                    var btnRead2 = row2.querySelector('.markReadAlertButton');
                    var btnUnread2 = row2.querySelector('.markUnreadAlertButton');
                    if (btnRead2) btnRead2.classList.remove('hidden');
                    if (btnUnread2) btnUnread2.classList.add('hidden');
                }

                if (id2) {
                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'mark_unread',
                        id: id2,
                        alert_id: id2,
                        'alert_id[]': id2,
                        my_post_key: window.my_post_key || ''
                    }, function (resp4) {
                        syncFromResponse(resp4 || {}, wasRead ? +1 : 0);
                    });
                } else if (afAamDebug) {
                    console.log('[AAM] mark_unread: не удалось определить id');
                }
                return;
            }

            // 3) удалить
            if (target.classList && target.classList.contains('deleteAlertButton')) {
                e.preventDefault();

                var row3 = target.closest('[data-alert-id]');
                var id3 = row3 ? getAlertIdFromElement(row3) : getAlertIdFromElement(target);
                var wasUnread3 = false;

                if (row3) {
                    wasUnread3 = row3.classList.contains('alert--unread');
                    row3.parentNode.removeChild(row3);
                }

                if (id3) {
                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'delete',
                        id: id3,
                        alert_id: id3,
                        'alert_id[]': id3,
                        my_post_key: window.my_post_key || ''
                    }, function (resp5) {
                        syncFromResponse(resp5 || {}, wasUnread3 ? -1 : 0);
                    });
                } else if (afAamDebug) {
                    console.log('[AAM] delete: не удалось определить id');
                }
                return;
            }

            // 4) клик по уведомлению (ссылка) — пометить и перейти
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
                            tr2.classList.remove('alert--unread');
                            tr2.classList.add('alert--read');
                        }

                        if (alertId) {
                            ajax('xmlhttp.php', {
                                action: 'af_aam_api',
                                op: 'mark_read',
                                id: alertId,
                                alert_id: alertId,
                                'alert_id[]': alertId,
                                my_post_key: window.my_post_key || ''
                            }, function (resp6) {
                                syncFromResponse(resp6 || {}, wasUnreadLink ? -1 : 0);
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

    // ===== список уведомлений (отдельная страница) =====
    function initListClicks() {
        document.addEventListener('click', function (e) {
            var target = e.target;

            // ✔
            if (target && target.classList && target.classList.contains('markReadAlertButton')) {
                var rowBtn = target.closest('.af-aam-list-row');
                if (rowBtn) {
                    e.preventDefault();

                    var idBtn = getAlertIdFromElement(rowBtn) || getAlertIdFromElement(target);
                    var wasUnreadBtn = rowBtn.classList.contains('af-aam-row-unread');

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

            // ✕
            if (target && target.classList && target.classList.contains('deleteAlertButton')) {
                var rowDel = target.closest('.af-aam-list-row');
                if (rowDel) {
                    e.preventDefault();

                    var idDel = getAlertIdFromElement(rowDel) || getAlertIdFromElement(target);
                    var wasUnreadDel = rowDel.classList.contains('af-aam-row-unread');

                    rowDel.parentNode.removeChild(rowDel);

                    ajax('xmlhttp.php', {
                        action: 'af_aam_api',
                        op: 'delete',
                        id: idDel,
                        my_post_key: window.my_post_key || ''
                    }, function (resp2) {
                        syncFromResponse(resp2 || {}, wasUnreadDel ? -1 : 0);
                    });

                    return;
                }
            }

            // клик по уведомлению -> beacon/mark_read и переход
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

                        if (wasUnread) updateVisibleCounts(Math.max(0, currentUnread - 1));

                        var ok = false;
                        if (alertId) {
                            ok = afAamSendBeacon({
                                action: 'af_aam_api',
                                op: 'mark_read',
                                id: alertId,
                                my_post_key: window.my_post_key || ''
                            });
                        }

                        if (ok) return;

                        e.preventDefault();
                        var jumped = false;

                        if (alertId) {
                            ajax('xmlhttp.php', {
                                action: 'af_aam_api',
                                op: 'mark_read',
                                id: alertId,
                                my_post_key: window.my_post_key || ''
                            }, function (resp3) {
                                if (!jumped) {
                                    syncFromResponse(resp3 || {}, 0);
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

    // ===== MyAlerts совместимость (latest/mark all) =====
    function initMyAlertsCompat() {
        var latestBtn = qs('#getLatestAlerts');
        var latestContainer = qs('#latestAlertsListing');
        var markAllButtons = qsa('.markAllReadButton');

        function renderLatest() {
            ajax('xmlhttp.php', { action: 'getLatestAlerts' }, function (resp) {
                if (resp && resp.template && latestContainer) latestContainer.innerHTML = resp.template;
                var cnt = extractUnreadCount(resp);
                if (cnt !== null) updateVisibleCounts(cnt);
            });
        }

        function markAllHandler(e) {
            e.preventDefault();
            ajax('xmlhttp.php', {
                action: 'af_aam_api',
                op: 'mark_all_read',
                my_post_key: window.my_post_key || ''
            }, function (resp) {
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
            var t = e.target && e.target.closest ? e.target.closest('.markAllReadButton') : null;
            if (t) markAllHandler(e);
        });

        if (typeof window.myalerts_autorefresh !== 'undefined' && window.myalerts_autorefresh > 0) {
            setInterval(renderLatest, window.myalerts_autorefresh * 1000);
        }
    }

    // ===== fallback poll list (быстрый опрос) =====
    var afAamPollInFlight = false;
    var afAamInitialSnapshotDone = false;

    function pollUnreadAlerts() {
        if (afAamPollInFlight) return;
        afAamPollInFlight = true;

        ajax('xmlhttp.php', { action: 'af_aam_api', op: 'list', unreadOnly: 0 }, function (resp) {
            afAamPollInFlight = false;

            if (!resp || !resp.ok) {
                if (afAamDebug) console.log('[AAM] poll failed', resp);
                return;
            }

            syncFromResponse(resp || {});

            var items = Array.isArray(resp.items) ? resp.items : [];
            if (!items.length) {
                afAamInitialSnapshotDone = true;
                return;
            }

            if (!afAamInitialSnapshotDone) {
                var maxId0 = 0;
                items.forEach(function (a) {
                    var id = parseInt(a.id, 10) || 0;
                    if (id > maxId0) maxId0 = id;
                });

                if (lastSeenAlertId <= 0 && maxId0 > 0) saveLastSeen(maxId0);
                afAamInitialSnapshotDone = true;
                return;
            }

            var hasNewUnseen = afAamHasNewUnseenUnread(items);
            var maxId = afAamMaxAlertId(items);

            queueToasts(items);

            if (hasNewUnseen) playAlertSound();

            if (maxId > lastSeenAlertId) saveLastSeen(maxId);
        });
    }

    // ===== long-poll loop =====
    var afAamLongPollRunning = false;
    var afAamPollBackoff = 0;

    function afAamComputeNextDelay() {
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

        var items = Array.isArray(resp.items) ? resp.items : [];

        var newest = 0;
        if (typeof resp.server_newest_id !== 'undefined') newest = parseInt(resp.server_newest_id, 10) || 0;
        else if (items.length) newest = parseInt(items[0].id, 10) || 0;

        updateVisibleCounts(newCount);

        var hasNewUnseen = false;
        if (items.length) hasNewUnseen = afAamHasNewUnseenUnread(items);
        else hasNewUnseen = (newCount > oldCount);

        if (hasNewUnseen) {
            playAlertSound();
            if (items.length) showToastsFromAlerts(items);
        }

        var maxCandidate = afAamMaxAlertId(items);
        if (newest > maxCandidate) maxCandidate = newest;

        if (maxCandidate > lastSeenAlertId) saveLastSeen(maxCandidate);
    }

    function afAamLongPollLoop() {
        if (afAamLongPollRunning) return;
        afAamLongPollRunning = true;

        function tick() {
            var timeoutSec = document.hidden ? 30 : 25;
            var timeoutMs = (timeoutSec + 5) * 1000;

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

                setTimeout(tick, afAamComputeNextDelay());
            }, timeoutMs);
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) afAamPollBackoff = 0;
        });

        tick();
    }

    // ===== init =====
    function init() {
        initSound();
        initAlertsUI();
        initMyAlertsCompat();
        initListClicks();

        document.addEventListener('click', primeSoundOnce, true);
        document.addEventListener('keydown', primeSoundOnce, true);
        document.addEventListener('pointerdown', primeSoundOnce, true);

        // стартовое сравнение (и слепок)
        pollUnreadAlerts();

        // основная realtime-петля
        afAamLongPollLoop();
    }

    // интервальный fallback (на случай если long-poll умрёт)
    var refreshInterval = 0;
    var minRefreshInterval = 20;

    if (typeof window.af_aam_autorefresh !== 'undefined' &&
        !isNaN(parseInt(window.af_aam_autorefresh, 10)) &&
        parseInt(window.af_aam_autorefresh, 10) > 0) {

        refreshInterval = parseInt(window.af_aam_autorefresh, 10);

    } else if (typeof window.myalerts_autorefresh !== 'undefined' &&
        !isNaN(parseInt(window.myalerts_autorefresh, 10)) &&
        parseInt(window.myalerts_autorefresh, 10) > 0) {

        refreshInterval = parseInt(window.myalerts_autorefresh, 10);

    } else {
        refreshInterval = 25;
    }

    if (refreshInterval > 0 && refreshInterval < minRefreshInterval) refreshInterval = minRefreshInterval;

    if (refreshInterval > 0) {
        if (afAamDebug) console.log('[AAM] interval fallback каждые', refreshInterval, 'сек');
        setInterval(function () {
            pollUnreadAlerts();
        }, refreshInterval * 1000);
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') init();
    else document.addEventListener('DOMContentLoaded', init);

})();
