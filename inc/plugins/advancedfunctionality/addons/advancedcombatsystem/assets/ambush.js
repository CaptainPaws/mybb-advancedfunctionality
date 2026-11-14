(() => {
    'use strict';
    const labels = {preparation: 'Подготовка', ambush_ready: 'Готовность засады', encounter_start: 'Запуск', ambush_resolution: 'Разрешение', active: 'Активный первый раунд', start_confirmed: 'Старт подтверждён', recovery_required: 'Требуется восстановление'};
    document.querySelectorAll('[data-acs-ambush]').forEach(root => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const config = JSON.parse(root.querySelector('[data-acs-ambush-config]').textContent);
        let dto = config.context, busy = false, checked = false, pending = null;
        const part = name => root.querySelector(`[data-part="${name}"]`);
        const button = name => root.querySelector(`[data-op="${name}"]`);
        const storageKey = `acs-ambush:${dto.viewer_uid}:${dto.session_id}:${dto.encounter_id}`;
        let storageOK = true;
        try { pending = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch (_) { storageOK = false; }
        const notice = text => { part('notice').textContent = text; };
        const persist = value => {
            // Write before sending; a failed write must prevent an untrackable mutation.
            sessionStorage.setItem(storageKey, JSON.stringify(value)); pending = value;
        };
        const render = () => {
            part('summary').textContent = `${labels[dto.phase] || 'Состояние недоступно'}. Разрешённых подготовок: ${dto.prepared_count}. Участники: ${dto.participants_ready === null ? 'первый раунд создан' : (dto.participants_ready ? 'готовы' : 'есть блокировки')}. Неподдержанных подготовок: ${dto.unsupported_count}.`;
            const table = document.createElement('table'), header = table.insertRow();
            ['Группа', 'Бюджет AP', 'Оплачено до раунда', 'AP на старте первого раунда'].forEach(text => { const th = document.createElement('th'); th.textContent = text; header.append(th); });
            dto.groups.forEach(group => { const row = table.insertRow(); [group.group_id, group.budget, group.commitment, group.remaining_first_round].forEach(value => { row.insertCell().textContent = String(value); }); });
            part('groups').replaceChildren(table);
            button('ready').disabled = busy || !storageOK || !!pending || !dto.can_ready;
            button('start').disabled = busy || !storageOK || !!pending || !dto.can_start;
            button('status').disabled = busy;
            const sameStart = pending && pending.op === 'ambush_start' && dto.start_operation_id === `ambush:${dto.session_id}:${pending.input.operation_key}`;
            button('resume').hidden = !(checked && pending && (dto.start_operation_id === null || (sameStart && dto.operation_status === 'prepared')));
            button('resume').disabled = busy;
            root.querySelector('[data-field="reason"]').disabled = busy || !!pending;
        };
        const apply = data => {
            dto = data; checked = true;
            if ((pending?.rejected && dto.start_operation_id === null) || dto.mechanics_confirmed || (pending && pending.op === 'ambush_ready' && dto.phase === 'ambush_ready')) {
                try { sessionStorage.removeItem(storageKey); pending = null; } catch (_) { storageOK = false; }
            }
            if (dto.mechanics_confirmed) notice(`Запуск подтверждён. Раунд #${dto.round_id}. Исполнено подготовок: ${dto.executed_count}. Новый литературный пост не создавался.`);
            else if (dto.operation_status === 'recovery_required') notice('Исход требует восстановления. Ключ сохранён; новый запуск и слепой повтор запрещены. Обратитесь к ответственному за восстановление ACS.');
            else if (pending || dto.start_operation_id) notice('Успех запуска не подтверждён. Используйте прежний ключ и проверку результата. Продолжение возможно только для сохранённого server intent.');
            else if (dto.unsupported_count || dto.blocker_count) notice('Есть неразрешённые операции или неподдержанные подготовки. Исправьте условия до запуска.');
            else notice('Состояние проверено сервером.');
        };
        const request = async (op, input) => {
            const url = new URL(config.endpoint, window.location.href), options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
            if (op === 'ambush_status') { url.searchParams.set('op', op); Object.entries(input).forEach(([k, v]) => url.searchParams.set(k, v)); }
            else { options.method = 'POST'; options.body = new URLSearchParams({...input, op, my_post_key: config.post_key}); }
            const response = await fetch(url, options), result = await response.json();
            if (!result.ok) { const error = new Error(result.error?.message || 'Результат не подтверждён.'); error.code = result.error?.code; throw error; }
            return result.data;
        };
        root.addEventListener('click', async event => {
            const target = event.target.closest('button[data-op]'); if (!target || busy || target.disabled) return;
            const op = target.dataset.op; busy = true; render();
            try {
                if (op === 'status') apply(await request('ambush_status', {session_id: dto.session_id, encounter_id: dto.encounter_id}));
                else {
                    if (op !== 'resume') {
                        if (pending || !storageOK) throw new Error('Сначала проверьте прежний результат.');
                        const reason = root.querySelector('[data-field="reason"]').value.trim();
                        if (!reason) throw new Error('Укажите причину.');
                        const bytes = crypto.getRandomValues(new Uint8Array(16)), key = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
                        persist({op: `ambush_${op}`, input: {session_id: dto.session_id, encounter_id: dto.encounter_id, expected_version: dto.expected_version, encounter_version: dto.encounter_version, operation_key: key, reason}});
                    }
                    apply(await request(pending.op, pending.input));
                }
            } catch (error) { checked = false; if (pending && ['ACS_VERSION_CONFLICT', 'ACS_CSRF_REQUIRED', 'ACS_POST_REQUIRED', 'ACS_AMBUSH_INPUT_INVALID', 'ACS_PREPARED_UNSUPPORTED'].includes(error.code)) { try { persist({...pending, rejected: true}); } catch (_) { storageOK = false; } } notice(`${error.message} Проверьте результат запуска; прежний ключ сохранён. Не отправляйте новую операцию вслепую.`); }
            finally { busy = false; render(); }
        });
        render();
        if (!storageOK) notice('Хранилище вкладки недоступно. Управление заблокировано, проверка результата доступна.');
        else if (pending || dto.start_operation_id) { notice('Сохранён прежний запуск. Проверьте результат до дальнейших действий.'); }
        else apply(dto);
    });
})();
