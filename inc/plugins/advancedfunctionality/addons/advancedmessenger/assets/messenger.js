(function () {
    'use strict';
    function boot() {
        const root = document.querySelector('[data-am-root]');
        if (!root || root.dataset.amBooted) return;
        root.dataset.amBooted = '1';
        const pane = document.getElementById('am-pane');
        const status = document.getElementById('am-status');
        const dialog = document.getElementById('am-panel-dialog');
        const tool = document.getElementById('am-tool-frame');
        const layout = root.querySelector('.am-layout');
        function fitLayout() {
            if (!layout) return;
            const viewport = window.visualViewport;
            const bottom = (viewport?.height || window.innerHeight) + (viewport?.offsetTop || 0);
            layout.style.height = Math.max(240, Math.floor(bottom - layout.getBoundingClientRect().top - 12)) + 'px';
            fitComposer();
        }
        function fitComposer() {
            const frame = document.getElementById('am-composer');
            if (!frame?.dataset.amHeight || !layout) return;
            // Expanded address/options remain reachable on short screens. In
            // the normal collapsed state the frame fits its content exactly.
            const cap = Math.max(120, Math.floor(layout.clientHeight * .6));
            frame.style.height = Math.min(Number(frame.dataset.amHeight), cap) + 'px';
        }
        window.addEventListener('resize', fitLayout);
        window.addEventListener('load', fitLayout);
        window.visualViewport?.addEventListener('resize', fitLayout);
        fitLayout();
        let generation = 0;
        let touching = false;
        let touchQueue = [];
        let observer;
        const touched = new Set();
        let refreshing = false;
        let refreshAgain = false;
        const label = key => root.getAttribute('data-am-' + key) || key;
        const notice = text => { status.textContent = text; };
        function formatHistory() {
            root.querySelectorAll('.am-bubble__body blockquote blockquote blockquote').forEach(quote => {
                if (quote.closest('.am-quote-fold')) return;
                const disclosure = document.createElement('details');
                disclosure.className = 'am-quote-fold';
                const summary = document.createElement('summary');
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-quote-right';
                icon.setAttribute('aria-hidden', 'true');
                summary.append(icon, document.createTextNode(label('quoted-history')));
                disclosure.append(summary);
                quote.replaceWith(disclosure);
                disclosure.append(quote);
            });
        }
        function closeMenus(except = null) {
            root.querySelectorAll('[data-am-menu][open]').forEach(details => {
                if (details !== except) details.open = false;
            });
        }
        function positionMenu(details) {
            const menu = details.querySelector('.am-dropdown');
            if (!menu || !details.open) return;
            const anchor = details.querySelector('summary').getBoundingClientRect();
            const region = (details.closest('.am-history') || layout).getBoundingClientRect();
            const bounds = { left: Math.max(0, region.left), right: Math.min(innerWidth, region.right),
                top: Math.max(0, region.top), bottom: Math.min(innerHeight, region.bottom) };
            menu.style.maxHeight = Math.max(80, bounds.bottom - bounds.top - 16) + 'px';
            const box = menu.getBoundingClientRect();
            menu.style.left = Math.max(bounds.left + 4, Math.min(anchor.right - box.width, bounds.right - box.width - 4)) + 'px';
            const below = anchor.bottom + 4;
            const above = anchor.top - box.height - 4;
            menu.style.top = Math.max(bounds.top + 4, Math.min(below + box.height <= bounds.bottom ? below : above, bounds.bottom - box.height - 4)) + 'px';
        }
        root.addEventListener('toggle', event => {
            const details = event.target;
            if (!details.matches('[data-am-menu]')) return;
            const menu = details.querySelector('.am-dropdown');
            if (details.open) {
                closeMenus(details);
                if (typeof menu.showPopover === 'function') { if (!menu.matches(':popover-open')) menu.showPopover(); }
                else menu.classList.add('am-dropdown-fallback');
                positionMenu(details);
                if (!menu.dataset.amObserved && 'ResizeObserver' in window) {
                    menu.dataset.amObserved = '1';
                    new ResizeObserver(() => positionMenu(details)).observe(menu);
                }
            } else {
                if (typeof menu.hidePopover === 'function' && menu.matches(':popover-open')) menu.hidePopover();
                menu.classList.remove('am-dropdown-fallback');
            }
        }, true);
        document.addEventListener('click', event => {
            if (!event.target.closest('[data-am-menu]')) closeMenus();
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            const opened = root.querySelector('[data-am-menu][open]');
            if (opened) { closeMenus(); opened.querySelector('summary').focus(); event.preventDefault(); }
        });
        document.getElementById('am-history')?.addEventListener('scroll', () => closeMenus(), { passive: true });
        window.addEventListener('resize', () => closeMenus());
        // Each page starts collapsed. refreshSidebar preserves the current
        // DOM disclosure state, without importing a previous page's preference.
        formatHistory();

        function saveEditor() {
            for (const frame of [document.getElementById('am-composer'), tool]) {
                try { frame?.contentWindow?.AMComposer?.save(); } catch (_) { /* Native error document. */ }
            }
        }
        function nativeURL(href) {
            const url = new URL(href, location.href);
            if (url.origin !== location.origin || !url.pathname.endsWith('/private.php')) throw new Error(label('error'));
            return url;
        }
        async function request(href, values) {
            const url = nativeURL(href);
            const body = new URLSearchParams(values);
            body.set('my_post_key', root.dataset.amToken);
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);
            try {
                const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, signal: controller.signal });
                if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error(label('response'));
                const data = await response.json();
                if (!response.ok || data.error) throw new Error(data.error || label('error'));
                return data;
            } finally { clearTimeout(timeout); }
        }
        function updateUnread(count) {
            if (!Number.isInteger(count)) return;
            root.querySelectorAll('[data-am-unread-count]').forEach(el => { el.textContent = String(count); });
            document.dispatchEvent(new CustomEvent('advancedmessenger:unread', { detail: { unread: count } }));
        }
        async function refreshSidebar(quotaOnly = false) {
            if (refreshing) { refreshAgain = true; return; }
            refreshing = true;
            try {
                const data = await request('private.php', { am_refresh: '1', am_folder: root.dataset.amFolder,
                    am_quota: quotaOnly ? '1' : '', am_name: root.dataset.amName, am_page: root.dataset.amPage });
                const sidebar = document.getElementById('am-sidebar');
                if (quotaOnly) { sidebar.querySelector('.am-quota').innerHTML = data.quota; updateUnread(data.unread); return; }
                const opened = Array.from(sidebar.querySelectorAll('details[open]')).map(el => el.className);
                const query = sidebar.querySelector('#am-search')?.value || '';
                sidebar.innerHTML = data.sidebar + '<div class="am-quota">' + data.quota + '</div>';
                sidebar.querySelectorAll('details').forEach(el => { el.open = opened.includes(el.className); });
                sidebar.querySelector('#am-search').value = query;
                sidebar.querySelectorAll('[data-am-dialog]').forEach(el => {
                    const active = el.dataset.amKey === root.dataset.amKey;
                    el.classList.toggle('is-active', active);
                    if (active) el.setAttribute('aria-current', 'page');
                });
                updateUnread(data.unread);
            } catch (error) { notice(error.message || label('error')); }
            finally { refreshing = false; if (refreshAgain) { refreshAgain = false; refreshSidebar(); } }
        }
        async function touchNext() {
            if (touching || !touchQueue.length) return;
            touching = true;
            const item = touchQueue.shift();
            try {
                const data = await request('private.php?action=read&pmid=' + item.id, { am_touch: '1' });
                touched.add(item.id);
                item.element.dataset.amUnread = '0';
                const state = item.element.querySelector('[data-am-state]');
                if (state) state.textContent = label('read');
                updateUnread(data.unread);
                // Reloading the sidebar is intentionally left to native navigation;
                // do not invent a second unread counter or polling loop.
            } catch (error) { notice(error.message || label('error')); }
            finally { touching = false; if (touchQueue.length) touchNext(); else refreshSidebar(); }
        }
        function mergeDates() {
            let previous = '';
            document.querySelectorAll('#posts > .am-date').forEach(date => {
                if (date.dataset.amDate === previous) date.remove();
                else previous = date.dataset.amDate;
            });
        }
        function watchUnread() {
            observer?.disconnect();
            if (!('IntersectionObserver' in window)) return; // Core still marks the opened PM.
            observer = new IntersectionObserver(entries => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) continue;
                    const id = entry.target.dataset.amPmid;
                    observer.unobserve(entry.target);
                    if (touched.has(id) || touchQueue.some(item => item.id === id)) continue;
                    touchQueue.push({ id, element: entry.target });
                }
                touchNext();
            }, { root: document.getElementById('am-history'), threshold: 0.01 });
            root.querySelectorAll('.am-bubble[data-am-unread="1"]').forEach(el => observer.observe(el));
        }
        async function select(href, push) {
            // The initial empty mailbox has not loaded postbit/BBCode runtimes.
            // A native load establishes that lifecycle before fragment navigation.
            if (!document.getElementById('posts')) { location.assign(href); return; }
            saveEditor();
            const mine = ++generation;
            notice(label('loading'));
            pane.setAttribute('aria-busy', 'true');
            try {
                const data = await request(href, { am_fragment: '1' });
                if (mine !== generation) return;
                // Reject incomplete responses before replacing a working view.
                if (!['html', 'older', 'header', 'composer', 'title', 'key'].every(key => typeof data[key] === 'string') || !Number.isInteger(data.pmid)) throw new Error(label('response'));
                const posts = document.getElementById('posts');
                closeMenus();
                posts.innerHTML = data.html;
                formatHistory();
                document.getElementById('am-history-more').innerHTML = data.older;
                document.getElementById('am-conversation-head').innerHTML = data.header;
                document.getElementById('am-composer-host').innerHTML = data.composer;
                root.dataset.amPmid = data.pmid;
                root.dataset.amKey = data.key;
                document.getElementById('am-latest').href = 'private.php?action=read&pmid=' + data.pmid + '&am_latest=1&am_folder=' + root.dataset.amFolder;
                root.querySelectorAll('[data-am-dialog]').forEach(el => {
                    const active = el.dataset.amKey === data.key;
                    el.classList.toggle('is-active', active);
                    if (active) el.setAttribute('aria-current', 'page'); else el.removeAttribute('aria-current');
                });
                if (push) history.pushState({ am: true }, '', href);
                document.title = data.title;
                root.classList.add('am-show-conversation');
                const scroll = document.getElementById('am-history');
                scroll.scrollTop = scroll.scrollHeight;
                document.getElementById('am-title').setAttribute('tabindex', '-1');
                document.getElementById('am-title').focus({ preventScroll: true });
                touchQueue = [];
                updateUnread(data.unread);
                watchUnread();
                refreshSidebar();
                notice('');
            } catch (error) {
                if (mine === generation) {
                    notice(error.message || label('error'));
                    const retry = document.createElement('button');
                    retry.type = 'button';
                    retry.textContent = label('retry');
                    retry.addEventListener('click', () => select(href, push), { once: true });
                    status.append(' ', retry);
                    const fallback = document.createElement('a');
                    fallback.href = href;
                    fallback.textContent = label('open');
                    status.append(' ', fallback);
                }
            } finally { if (mine === generation) pane.removeAttribute('aria-busy'); }
        }
        function openPanel(href) {
            saveEditor();
            const url = nativeURL(href);
            url.searchParams.set('am_panel', '1');
            const inline = document.getElementById('am-composer');
            if (inline && url.searchParams.get('action') === 'send') {
                if (['reply', 'replyall'].includes(url.searchParams.get('do')) && url.searchParams.has('pmid')) {
                    inline.src = url.href;
                    inline.focus();
                    return;
                }
                // Keep exactly one editing document active while the new-message
                // or forward panel is open. Its draft was saved above.
                inline.dataset.amPausedSrc = inline.src;
                inline.src = 'about:blank';
            }
            document.getElementById('am-panel-title').textContent = label('loading');
            tool.src = url.href;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else location.assign(href);
        }
        root.addEventListener('click', async event => {
            const confirm = event.target.closest('[data-am-confirm]');
            if (confirm && !window.confirm(confirm.dataset.amConfirm)) { event.preventDefault(); return; }
            const close = event.target.closest('.am-panel-close');
            if (close) { saveEditor(); dialog.close(); return; }
            if (event.target.closest('.am-back')) { root.classList.remove('am-show-conversation'); document.getElementById('am-search')?.focus(); return; }
            const older = event.target.closest('#am-older');
            if (older) {
                event.preventDefault();
                older.disabled = true;
                const mine = generation;
                const scroll = document.getElementById('am-history');
                const height = scroll.scrollHeight;
                const top = scroll.scrollTop;
                try {
                    const data = await request('private.php?action=am_history', { pmid: root.dataset.amPmid,
                        before_time: older.dataset.time, before_id: older.dataset.id });
                    if (mine !== generation) return;
                    document.getElementById('posts').insertAdjacentHTML('afterbegin', data.html);
                    if (data.cursor) { older.dataset.time = data.cursor[0]; older.dataset.id = data.cursor[1]; }
                    else older.remove();
                    formatHistory();
                    mergeDates();
                    scroll.scrollTop = top + scroll.scrollHeight - height;
                    watchUnread();
                    notice('');
                } catch (error) { notice(error.message || label('error')); }
                finally { older.disabled = false; }
                return;
            }
            const link = event.target.closest('a[href]');
            if (link?.closest('.am-dropdown')) closeMenus();
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (link.matches('[data-am-panel]')) { event.preventDefault(); openPanel(link.href); }
            else if (link.matches('[data-am-dialog]')) { event.preventDefault(); select(link.href, true); }
        });
        dialog.addEventListener('cancel', saveEditor);
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const box = dialog.getBoundingClientRect();
            const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
            if (!outside) return;
            try { if (tool.contentWindow?.AMPanelDirty) return; } catch (_) { return; }
            saveEditor(); dialog.close();
        });

        dialog.addEventListener('close', () => {
            saveEditor();
            tool.src = 'about:blank';
            const inline = document.getElementById('am-composer');
            if (inline?.dataset.amPausedSrc) {
                inline.src = inline.dataset.amPausedSrc;
                delete inline.dataset.amPausedSrc;
            }
        });
        root.addEventListener('submit', saveEditor);
        window.addEventListener('beforeunload', saveEditor);
        window.addEventListener('popstate', () => {
            if (new URL(location.href).searchParams.get('action') === 'read') select(location.href, false);
            else location.reload();
        });
        window.addEventListener('message', event => {
            if (event.origin !== location.origin || !event.data) return;
            const composer = document.getElementById('am-composer');
            if (event.data.type === 'advancedmessenger:composer-size') {
                if (event.source !== composer?.contentWindow || !Number.isFinite(event.data.height) || event.data.height < 1) return;
                composer.dataset.amHeight = String(event.data.height);
                fitComposer();
                return;
            }
            if (event.data.type === 'advancedmessenger:close' && event.source === tool.contentWindow) { saveEditor(); dialog.close(); return; }
            if (event.data.type !== 'advancedmessenger:complete') return;
            if (event.source !== composer?.contentWindow && event.source !== tool.contentWindow) return;
            // Native send has committed. Refresh once to rebuild latest activity,
            // quotas and every header counter using MyBB's canonical state.
            const latest = new URL(location.href);
            latest.searchParams.set('am_latest', '1');
            location.assign(latest.href);
        });
        tool.addEventListener('load', () => {
            try {
                const title = tool.contentDocument?.querySelector('h1, .am-pm__title')?.textContent?.trim();
                if (title) { document.getElementById('am-panel-title').textContent = title; tool.title = title; }
                // Folder edits/native mass actions redirect to the mailbox.
                if (tool.contentDocument?.querySelector('[data-am-root]')) location.reload();
                else if (tool.contentDocument?.querySelector('[data-am-panel-page]')) refreshSidebar();
            } catch (_) { notice(label('error')); }
        });
        if (root.dataset.amPmid !== '0') {
            root.classList.add('am-show-conversation');
            const scroll = document.getElementById('am-history');
            if (scroll) scroll.scrollTop = scroll.scrollHeight;
            watchUnread();
            refreshSidebar(true);
        }
        if (!pane.querySelector('.am-welcome')) root.classList.add('am-show-conversation');
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
