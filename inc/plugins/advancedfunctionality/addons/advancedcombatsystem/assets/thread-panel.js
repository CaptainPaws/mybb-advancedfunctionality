(function () {
    'use strict';
    document.querySelectorAll('[data-acs-panel]').forEach(function (panel) {
        if (panel.dataset.ready) return;
        panel.dataset.ready = '1';
        var details = panel.querySelector('details');
        var side = panel.querySelector('[data-acs-side]');
        var group = panel.querySelector('[data-acs-group]');
        var notice = document.createElement('p'); notice.setAttribute('role','status');
        var watcher = panel.dataset.acsSession && window.AFACSRuntime?.watch(panel, function () {
            return {session_id:panel.dataset.acsSession,tid:panel.dataset.acsTid};
        }, function () {
            notice.replaceChildren(document.createTextNode('Состояние боя изменилось. Показанные сведения требуют обновления. '));
            var link=document.createElement('a');link.href=window.location.href;link.textContent='Обновить страницу после сохранения текста';notice.append(link);
        }, function (error) {notice.textContent=error.message || 'Обновление недоступно. Сохраните текст перед обновлением страницы.';});
        if(panel.dataset.acsSession)details.append(notice);

        var filters = panel.querySelector('.af-acs-panel__filters');
        if (filters) filters.hidden = false;
        details.addEventListener('toggle', function () {
            if (details.open) watcher?.check('panel-open').catch(function () {});
        });
        if (watcher) watcher.check('panel-open').catch(function () {});
        else notice.textContent = 'Проверка обновлений недоступна. Сохраните текст перед обновлением страницы.';
        function filter() {
            if (!side || !group) return;
            var any = false;
            panel.querySelectorAll('[data-group]').forEach(function (node) {
                node.hidden = !!((side.value && node.dataset.side !== side.value) || (group.value && node.dataset.group !== group.value));
                any = any || !node.hidden;
            });
            panel.querySelector('[data-acs-empty]').hidden = any;
        }
        if (side) side.addEventListener('change', filter);
        if (group) group.addEventListener('change', filter);
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
    });
}());
