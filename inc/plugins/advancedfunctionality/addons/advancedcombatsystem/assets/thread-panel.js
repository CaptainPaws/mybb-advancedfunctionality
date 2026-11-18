(function () {
    'use strict';
    document.querySelectorAll('[data-acs-panel]').forEach(function (panel) {
        if (panel.dataset.ready) return;
        panel.dataset.ready = '1';
        var details = panel.querySelector('details');
        var compact = panel.querySelector('.af-acs-panel__compact');
        var anchor = panel.querySelector('.af-acs-panel__anchor');
        var open = panel.querySelector('[data-acs-open]');
        var close = panel.querySelector('[data-acs-close]');
        var mobile = window.matchMedia('(max-width: 48rem)');
        var side = panel.querySelector('[data-acs-side]');
        var group = panel.querySelector('[data-acs-group]');
        var frame = 0;
        var notice = document.createElement('p'); notice.setAttribute('role','status');
        var watcher = panel.dataset.acsSession && window.AFACSRuntime?.watch(panel, function () {
            return {session_id:panel.dataset.acsSession,tid:panel.dataset.acsTid};
        }, function () {
            notice.replaceChildren(document.createTextNode('Состояние боя изменилось. Показанные сведения требуют обновления. '));
            var link=document.createElement('a');link.href=window.location.href;link.textContent='Обновить страницу после сохранения текста';notice.append(link);
        }, function (error) {notice.textContent=error.message || 'Обновление недоступно. Сохраните текст перед обновлением страницы.';});
        if(panel.dataset.acsSession)details.append(notice);

        // The same details DOM keeps filter values, focus targets and participant state.
        details.open = !mobile.matches;
        compact.hidden = false;
        close.hidden = false;
        var filters = panel.querySelector('.af-acs-panel__filters');
        if (filters) filters.hidden = false;
        panel.classList.add('af-acs-panel--enhanced');
        function geometry() {
            frame = 0;
            var top = 0;
            var nav = document.querySelector('.af-am-navigation');
            if (nav) top = Math.max(0, nav.getBoundingClientRect().bottom);
            // Respect the existing ATF surfaces without writing their styles or offsets.
            document.querySelectorAll('.atf-post__topbar, .atf-post__meta-line').forEach(function (node) {
                var r = node.getBoundingClientRect();
                if (r.top <= top && r.bottom > top) top = r.bottom;
            });
            top = Math.ceil(top);
            panel.style.setProperty('--acs-panel-top', top + 'px');
            var past = anchor.getBoundingClientRect().top < top;
            panel.classList.toggle('af-acs-panel--floating', past);
            panel.classList.toggle('af-acs-panel--mobile', mobile.matches);
            open.setAttribute('aria-expanded', String(details.open));
        }
        function schedule() {
            if (!frame) frame = window.requestAnimationFrame(geometry);
        }
        function expand() {
            watcher?.check('panel-open').catch(function () {});
            details.open = true;
            if (!mobile.matches) {
                panel.scrollIntoView({block: 'start'});
            }
            close.focus({preventScroll: true});
            schedule();
        }
        open.addEventListener('click', expand);
        close.addEventListener('click', function () { details.open = false; open.focus({preventScroll: true}); schedule(); });
        panel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && details.open) { details.open = false; open.focus({preventScroll: true}); schedule(); }
        });
        details.addEventListener('toggle', function () {schedule();if(details.open)watcher?.check('panel-open').catch(function () {});});
        if(panel.dataset.acsSession){if(watcher)watcher.check('panel-open').catch(function () {});else notice.textContent='Проверка обновлений недоступна. Сохраните текст перед обновлением страницы.';}
        function filter() {
            if (!side || !group) return;
            var any = false;
            panel.querySelectorAll('[data-group]').forEach(function (node) {
                node.hidden = !!((side.value && node.dataset.side !== side.value) || (group.value && node.dataset.group !== group.value));
                any = any || !node.hidden;
            });
            panel.querySelector('[data-acs-empty]').hidden = any;
            schedule();
        }
        if (side) side.addEventListener('change', filter);
        if (group) group.addEventListener('change', filter);
        window.addEventListener('scroll', schedule, {passive: true});
        window.addEventListener('resize', schedule);
        mobile.addEventListener('change', schedule);
        if (window.ResizeObserver) {
            var observer = new ResizeObserver(schedule);
            observer.observe(panel);
            var navigation = document.querySelector('.af-am-navigation');
            if (navigation) observer.observe(navigation);
        }
        var start = Date.now();
        var clocks = Array.from(panel.querySelectorAll('[data-acs-seconds]'));
        function tick() {
            clocks.forEach(function (node) {
                var seconds = Math.max(0, Number(node.dataset.acsSeconds) - (node.dataset.paused === '1' ? 0 : Math.floor((Date.now() - start) / 1000)));
                node.textContent = Math.floor(seconds / 60) + ' мин ' + (seconds % 60) + ' с';
            });
        }
        if (clocks.length) window.setInterval(tick, 1000);
        filter();
        tick();
        geometry();
    });
}());
