(function () {
    'use strict';
    if (!window.HTMLDialogElement?.prototype.showModal || window.AFACSModalLauncher) return;
    window.AFACSModalLauncher = true;

    const script = new URL(document.currentScript.src);
    const base = new URL('./', script);
    const forum = new URL('showthread.php', document.baseURI);
    const assets = new Map();
    const scripts = new Set(['runtime.js', 'builder.js', 'ambush.js']);
    const styles = new Set(['modal-gm.css', 'builder.css']);
    let dialog, content, feedback, closeButton, retryButton, refreshButton, opener, current, activeTool, flight, pending, rejectedForm;

    function nativeUrl(href) {
        const url = new URL(href, forum);
        return url.origin === forum.origin && url.pathname === forum.pathname ? url : null;
    }

    function fragmentUrl(url, tool) {
        const endpoint = new URL('misc.php', forum);
        endpoint.search = url.search;
        endpoint.searchParams.set('action', 'acs_modal');
        endpoint.searchParams.set('acs_surface', tool);
        return endpoint;
    }

    function parse(html) {
        const template = document.createElement('template');
        template.innerHTML = html || '';
        return template.content;
    }

    function loadAsset(url, stylesheet) {
        if (assets.has(url.href)) return assets.get(url.href);
        const existing = Array.from(document.querySelectorAll(stylesheet ? 'link[rel="stylesheet"]' : 'script[src]'))
            .find(node => (stylesheet ? node.href : node.src) === url.href);
        if (existing && (stylesheet || (url.pathname.endsWith('/runtime.js') && window.AFACSRuntime)
            || (url.pathname.endsWith('/builder.js') && window.AFACSBuilder)
            || (url.pathname.endsWith('/ambush.js') && window.AFACSAmbush))) return Promise.resolve();
        const element = document.createElement(stylesheet ? 'link' : 'script');
        if (stylesheet) { element.rel = 'stylesheet'; element.href = url.href; }
        else { element.src = url.href; }
        const ready = new Promise((resolve, reject) => {
            element.addEventListener('load', resolve, {once: true});
            element.addEventListener('error', () => {
                assets.delete(url.href);
                element.remove();
                reject(new Error('Ресурс ACS недоступен. Откройте инструмент по прямой ссылке.'));
            }, {once: true});
            document.head.append(element);
        });
        assets.set(url.href, ready);
        return ready;
    }

    async function prepare(fragment) {
        for (const element of fragment.querySelectorAll('script:not([type="application/json"]), link')) {
            const stylesheet = element.matches('link[rel="stylesheet"]');
            const raw = element.getAttribute(stylesheet ? 'href' : 'src');
            element.remove();
            if (!raw) continue;
            const url = new URL(raw, forum);
            const filename = url.pathname.slice(base.pathname.length);
            if (url.origin === base.origin && url.pathname.startsWith(base.pathname)
                && (stylesheet ? styles : scripts).has(filename)) {
                await loadAsset(url, stylesheet);
            }
        }
    }

    function setBar(html) {
        const old = document.querySelector('#acs-thread-panel .af-acs-combat-bar');
        const fresh = parse(html).querySelector('.af-acs-combat-bar');
        if (!old || !fresh) return;
        old.replaceWith(fresh);
        const newAction = fresh.querySelector('[data-acs-modal]');
        const toolbar = document.querySelector('.atf-thread__toolbar [data-acs-modal]');
        if (toolbar && newAction) {
            toolbar.href = newAction.href;
            toolbar.dataset.acsModal = newAction.dataset.acsModal;
        }
    }

    function disposeContent() {
        content.querySelectorAll('[data-acs-builder]').forEach(root => window.AFACSBuilder?.unmount(root));
        content.replaceChildren();
    }

    function showError(message, canRetry) {
        feedback.textContent = message;
        retryButton.hidden = !canRetry;
        refreshButton.hidden = !rejectedForm || canRetry;
    }

    async function visit(url, tool, body = null, focus = true, restore = null) {
        if (flight || !dialog?.open) return;
        if (pending && pending.body !== body) {
            showError('Сначала проверьте исход прежней команды. Повтор отправляет тот же запрос с тем же ключом.', true);
            return;
        }
        const sourcePreview = body?.get('acs_encounter_op') === 'entity_request' && body.get('op') === 'entity_validate';
        const controller = new AbortController();
        flight = {controller, post: !!body};
        const timeout = setTimeout(() => controller.abort(), 25000);
        content.setAttribute('aria-busy', 'true');
        closeButton.disabled = !!body;
        retryButton.disabled = true;
        refreshButton.disabled = true;
        showError(sourcePreview ? 'Проверка источника…' : (body ? 'Сохранение…' : 'Загрузка…'), false);
        const scroll = content.scrollTop;
        const previousColumns = Array.from(content.querySelectorAll('.af-acs-gm__column-body'), column => column.scrollTop);
        const sameRoster = tool === 'gm' && current?.searchParams.get('gm_stage') === 'roster'
            && url.searchParams.get('gm_stage') === 'roster'
            && current.searchParams.get('acs_encounter') === url.searchParams.get('acs_encounter');
        let confirmed = false;
        try {
            const response = await fetch(fragmentUrl(url, tool), {
                method: body ? 'POST' : 'GET',
                body,
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers: {Accept: 'application/json'},
                signal: controller.signal
            });
            const result = await response.json();
            if (!result.ok) {
                const details = Array.from(parse(result.html).querySelectorAll('[role="alert"], .error'));
                const message = result.message || details.map(node => node.textContent.trim()).join(' ')
                    || 'Команда недоступна. Проверьте данные и обновите шаг.';
                pending = body && !sourcePreview && result.uncertain ? {url, tool, body} : null;
                showError(message, !!pending);
                return;
            }
            confirmed = true;
            pending = null;
            rejectedForm = null;
            if (result.bar) setBar(result.bar);
            if (result.session_id) {
                const sessionRefresh = document.querySelector('[data-acs-session-refresh]');
                if (sessionRefresh && sessionRefresh.dataset.acsSessionRefresh === result.session_id) sessionRefresh.hidden = false;
            }
            if (result.session_id && !url.searchParams.get('acs_session')) {
                url.searchParams.set('acs_session', result.session_id);
                url.searchParams.set('gm_stage', 'settings');
            }
            if (result.encounter_id) url.searchParams.set('acs_encounter', result.encounter_id);
            const fragment = parse(result.html);
            const resume = tool === 'gm' ? fragment.querySelector('[data-acs-gm-resume]')?.dataset.acsGmResume : null;
            if (resume) url = nativeUrl(resume) ?? url;
            await prepare(fragment);
            if (!dialog?.open || controller.signal.aborted) return;
            if (sourcePreview && sameRoster) {
                const card = content.querySelector('[data-acs-source-card]');
                const replacement = fragment.querySelector('[data-acs-source-card]');
                if (card && replacement && card.dataset.acsSourceCard === replacement.dataset.acsSourceCard) {
                    card.replaceWith(replacement);
                    current = url;
                    dialog.querySelector('[data-fallback]').href = url.href;
                    showError('', false);
                    if (focus) replacement.querySelector('[data-acs-source-result]')?.focus();
                    return;
                }
            }
            const keepScroll = body || !focus || restore;
            disposeContent();
            content.append(fragment);
            if (restore) restoreFields(restore);
            window.AFACSBuilder?.init(content);
            window.AFACSAmbush?.init(content);
            content.scrollTop = keepScroll ? scroll : 0;
            setDialogMode(tool);
            const columns = Array.from(content.querySelectorAll('.af-acs-gm__column-body'));
            if (sameRoster) {
                columns.slice(0, body ? 3 : 2).forEach((column, index) => { column.scrollTop = previousColumns[index] ?? 0; });
            }
            const picker = content.querySelector('.af-acs-gm__picker .af-acs-gm__column-body');
            const sourceResult = body ? picker?.querySelector('[data-acs-source-result]') : null;
            if (sourceResult) {
                const offset = sourceResult.getBoundingClientRect().top - picker.getBoundingClientRect().top;
                if (offset < 0 || offset > picker.clientHeight - 48) picker.scrollTop += offset;
            }
            const sideSettings = columns[0]?.querySelector('[data-acs-side-settings]');
            if (sideSettings) columns[0].scrollTop += sideSettings.getBoundingClientRect().top - columns[0].getBoundingClientRect().top;
            current = url;
            activeTool = tool;
            dialog.querySelector('[data-fallback]').href = url.href;
            showError('', false);
            if (focus) (sourceResult ?? sideSettings ?? content).focus({preventScroll: true});
        } catch (_) {
            if (!dialog?.open) return;
            if (sourcePreview) {
                showError('Не удалось получить результат проверки источника. Повторите проверку.', false);
            } else if (body && !confirmed) {
                pending = {url, tool, body};
                showError('Ответ не подтверждён. Можно явно повторить прежний запрос с тем же ключом.', true);
            } else {
                showError(confirmed && body ? 'Команда подтверждена. Откройте текущий шаг заново.'
                    : 'Инструмент ACS недоступен. Используйте прямую ссылку.', false);
                if (confirmed && body) {
                    disposeContent();
                    current = url;
                    dialog.querySelector('[data-fallback]').href = url.href;
                    rejectedForm = {url};
                    refreshButton.hidden = false;
                }
            }
        } finally {
            clearTimeout(timeout);
            flight = null;
            if (dialog) {
                content.removeAttribute('aria-busy');
                closeButton.disabled = false;
                retryButton.disabled = false;
                refreshButton.disabled = false;
            }
        }
    }

    function formIdentity(form) {
        const values = Array.from(form.querySelectorAll('input[type="hidden"]'))
            .filter(field => !field.name.includes('version') && !['my_post_key', 'operation_key'].includes(field.name))
            .map(field => [field.name, field.value]);
        return JSON.stringify([form.getAttribute('action'), values]);
    }

    function editableFields(form) {
        return Array.from(form.elements).filter(field => field.matches('input:not([type="hidden"]):not([type="submit"]):not([type="file"]), textarea, select')
            && !['acs_confirm', 'confirm', 'confirm_full'].includes(field.name));
    }

    function restoreFields(saved) {
        const form = Array.from(content.querySelectorAll('form')).find(node => formIdentity(node) === saved.identity);
        if (!form) return;
        editableFields(form).forEach((field, index) => {
            const old = saved.fields[index];
            if (!old || old.name !== field.name || old.type !== field.type) return;
            if (field.matches('select')) {
                for (const option of field.options) {
                    if (field.multiple || old.values.includes(option.value)) option.selected = old.values.includes(option.value);
                }
            } else if (['checkbox', 'radio'].includes(field.type)) {
                field.checked = old.checked;
            } else if (!field.disabled) field.value = old.value;
        });
    }

    function canLeave() {
        if (flight?.post || content.querySelector('[data-acs-busy]')) return false;
        const state = content.querySelector('[data-acs-builder]')?.acsBuilder?.state();
        if (state?.busy) return false;
        return !state?.dirty || window.confirm('Действия ещё не сохранены на сервере. Закрыть конструктор?');
    }

    function setDialogMode(tool) {
        const gm = tool === 'gm';
        dialog.classList.toggle('af-acs-modal--gm', gm);
        dialog.querySelectorAll('[data-gm-header]').forEach(part => { part.hidden = !gm; });
        dialog.querySelector('[data-default-close]').hidden = gm;
    }

    function makeDialog(link, url, tool) {
        const host = window.AFModalHost?.get();
        if (!host) return false;
        dialog = document.createElement('dialog');
        dialog.className = 'af-acs-modal' + (tool === 'gm' ? ' af-acs-modal--gm' : '');
        dialog.setAttribute('aria-labelledby', 'af-acs-modal-title');
        dialog.innerHTML = '<header class="af-acs-modal__header"><span class="af-acs-modal__emblem" aria-hidden="true" data-gm-header hidden><i class="fa-solid fa-khanda"></i></span>'
            + '<div><h2 id="af-acs-modal-title">Панель ACS</h2><p class="af-acs-modal__subtitle" data-gm-header hidden>Управление боевой сессией</p></div>'
            + '<button type="button" class="atf-button atf-button--secondary atf-modal-close" aria-label="Закрыть панель ACS" data-close><i class="fa-solid fa-xmark" aria-hidden="true" data-gm-header hidden></i><span data-default-close>Закрыть</span></button></header>'
            + '<div class="af-acs-modal__feedback"><p role="status"></p>'
            + '<button type="button" class="atf-button atf-button--secondary" data-retry hidden>Повторить прежний запрос</button>'
            + '<button type="button" class="atf-button atf-button--secondary" data-refresh hidden>Обновить шаг</button>'
            + ' <a data-fallback>Открыть на странице</a></div><div class="af-acs-modal__content" tabindex="-1"></div>';
        content = dialog.querySelector('.af-acs-modal__content');
        feedback = dialog.querySelector('[role="status"]');
        closeButton = dialog.querySelector('[data-close]');
        retryButton = dialog.querySelector('[data-retry]');
        refreshButton = dialog.querySelector('[data-refresh]');
        dialog.querySelector('[data-fallback]').href = url.href;
        opener = link;
        setDialogMode(tool);
        host.append(dialog);
        dialog.showModal();
        closeButton.addEventListener('click', () => { if (canLeave()) dialog.close(); });
        retryButton.addEventListener('click', () => { if (pending) visit(pending.url, pending.tool, pending.body); });
        refreshButton.addEventListener('click', () => {
            if (!pending && rejectedForm) visit(rejectedForm.url, 'gm', null, true, rejectedForm.fields ? rejectedForm : null);
        });
        dialog.addEventListener('cancel', event => { if (!canLeave()) event.preventDefault(); });
        dialog.addEventListener('close', () => {
            flight?.controller.abort();
            disposeContent();
            dialog.remove();
            dialog = null;
            current = null;
            rejectedForm = null;
            (opener.isConnected ? opener : document.getElementById('acs-thread-panel'))?.focus({preventScroll: true});
            opener = null;
        }, {once: true});
        if (pending) showError('У прежней команды неизвестный исход. Можно повторить тот же запрос.', true);
        else visit(url, tool);
        return true;
    }

    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const url = nativeUrl(link.href);
        if (!url) return;
        const inside = dialog?.contains(link);
        if (inside && link.hasAttribute('data-fallback')) {
            if (pending || !canLeave()) event.preventDefault();
            return;
        }
        if (inside && url.searchParams.has('acs_panel_session')) {
            event.preventDefault();
            if (canLeave()) dialog.close();
            return;
        }
        let tool = link.dataset.acsModal;
        if (inside && !tool) {
            if (url.searchParams.has('acs_builder')) tool = 'builder';
            else if (url.searchParams.has('acs_session') || url.searchParams.has('acs_setup')) tool = 'gm';
        }
        if (!tool) return;
        if (dialog?.open) {
            event.preventDefault();
            if (canLeave()) visit(url, tool);
        } else if (flight || makeDialog(link, url, tool)) event.preventDefault();
    });

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!dialog?.contains(form) || activeTool !== 'gm' || event.defaultPrevented) return;
        const url = nativeUrl(form.getAttribute('action') || current.href);
        if (!url) return;
        event.preventDefault();
        if (flight || pending) return;
        const values = new URLSearchParams(new FormData(form, event.submitter));
        if (form.method === 'post') {
            rejectedForm = {
                url: current,
                identity: formIdentity(form),
                fields: editableFields(form).map(field => ({name: field.name, type: field.type,
                    value: field.value, checked: field.checked,
                    values: field.matches('select') ? Array.from(field.selectedOptions, option => option.value) : []}))
            };
            visit(url, 'gm', values);
        } else {
            for (const [key, value] of values) url.searchParams.set(key, value);
            visit(url, 'gm');
        }
    });

    document.addEventListener('acs:committed', async event => {
        if (!dialog?.contains(event.target)) return;
        const {tid, sid} = event.detail;
        if (activeTool === 'gm' && current) visit(current, 'gm', null, false);
        const url = new URL(forum);
        url.search = new URLSearchParams({tid, acs_panel_session: sid});
        try {
            const response = await fetch(fragmentUrl(url, 'bar'), {credentials: 'same-origin', cache: 'no-store'});
            const result = await response.json();
            if (result.ok) setBar(result.html);
        } catch (_) { /* The command was confirmed by its original endpoint. */ }
    });
})();
