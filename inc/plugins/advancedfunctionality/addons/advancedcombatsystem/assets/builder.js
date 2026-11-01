(function () {
  'use strict';
  const copy = value => JSON.parse(JSON.stringify(value));
  class DraftModel {
    constructor() { this.actions = []; this.prose = ''; this.id = ''; this.version = '0'; this.dirty = false; this.pending = null; this.revision = 0; }
    changed() { if (this.pending) throw new Error('Сначала завершите или восстановите предыдущее сохранение.'); this.dirty = true; this.revision++; }
    add(action) { if (this.actions.length >= 100) throw new Error('Достигнут технический предел черновика: 100 действий.'); this.changed(); this.actions.push(copy(action)); }
    update(index, action) { if (!this.actions[index]) throw new Error('Действие не найдено.'); this.changed(); this.actions[index] = copy(action); }
    remove(index) { if (!this.actions[index]) return; this.changed(); this.actions.splice(index, 1); }
    move(index, delta) { const to = index + delta; if (!this.actions[index] || to < 0 || to >= this.actions.length) return; this.changed(); [this.actions[index], this.actions[to]] = [this.actions[to], this.actions[index]]; }
    text(value) { if (this.prose !== value) { this.changed(); this.prose = value; } }
    begin(scope, key) {
      if (this.pending) return copy(this.pending);
      this.pending = { session_id: scope.session_id, encounter_id: scope.encounter_id, turn_id: scope.turn.id, draft_id: this.id, expected_draft_version: this.version, operation_key: key, prose: this.prose, actions: JSON.stringify(this.actions) };
      return copy(this.pending);
    }
    ack(receipt) { this.id = String(receipt.draft_id); this.version = String(receipt.draft_version); this.pending = null; this.dirty = false; }
    restore(dto) { this.actions = copy(dto.actions); this.prose = dto.prose; this.id = String(dto.draft_id); this.version = String(dto.draft_version); this.pending = null; this.dirty = false; this.revision++; }
    preview(scope, scenario) { return { session_id: scope.session_id, encounter_id: scope.encounter_id, turn_id: scope.turn.id, scope_token: scope.scope_token, scenario, actions: JSON.stringify(this.actions) }; }
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = { DraftModel };
  if (typeof document === 'undefined') return;

  function mount(root) {
    if (root.dataset.acsMounted) return;
    root.dataset.acsMounted = '1';
    const config = JSON.parse(root.querySelector('[data-acs-builder-config]').textContent);
    let ctx = config.context, model = new DraftModel(), editing = null, loading = false, saving = false, previewBusy = false, epoch = 0, editable = ctx.turn.status === 'pending';
    const field = key => root.querySelector('[data-field="' + key + '"]');
    const part = key => root.querySelector('[data-part="' + key + '"]');
    const command = key => root.querySelector('[data-command="' + key + '"]');
    const node = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = String(text); return n; };
    const status = (text, error) => { part('status').textContent = text; part('status').classList.toggle('acs-error', !!error); };
    const statuses = { pending: 'Ожидает действий', resolved: 'Завершён', passed: 'Пропущен', forfeited: 'Исключён' };
    const storageKey = () => ['acs-draft', ctx.author_uid, ctx.session_id, ctx.encounter_id, ctx.turn.id].join(':');
    function localSave() { try { if (model.dirty || model.pending) sessionStorage.setItem(storageKey(), JSON.stringify(model)); else sessionStorage.removeItem(storageKey()); } catch (_) { /* Server save remains usable when browser storage is unavailable. */ } }
    function invalidate() { part('preview').replaceChildren(); epoch++; localSave(); }
    function options(select, rows, chosen, placeholder) {
      select.replaceChildren(); const empty = node('option', placeholder || 'Выберите'); empty.value = ''; select.append(empty);
      rows.forEach(row => { const o = node('option', row.label || row.name); o.value = row.id || row.key; o.disabled = row.supported === false; if (o.disabled && row.reason) o.textContent += ' — ' + row.reason; o.selected = o.value === chosen; select.append(o); });
    }
    const actor = id => ctx.actors.find(a => a.id === id);
    const ability = action => (actor(action.actor_id)?.choices || []).find(a => a.key === action.ability_key);
    function actionForm(chosen) {
      const a = chosen?.actor_id || field('actor').value;
      options(field('actor'), ctx.actors, a);
      options(field('ability'), actor(a)?.choices || [], chosen?.ability_key || field('ability').value, 'Выберите способность');
      options(field('target'), ctx.targets.filter(t => t.actors.includes(a)), chosen?.targets?.[0] || field('target').value, 'Выберите разрешённую цель');
      info();
    }
    function info() {
      const choices = actor(field('actor').value)?.choices || [];
      const a = choices.find(x => x.key === field('ability').value);
      part('action-info').textContent = a?.supported ? 'Стоимость: ' + a.ap_cost + ' AP из общего бюджета группы.' : (!choices.length ? (actor(field('actor').value)?.catalog_notice || 'Нет поддержанных закреплённых способностей. Обычная атака без утверждённого определения не подставляется.') : 'Выберите поддержанную способность. Недоступные определения показаны с причиной.');
      if (!ctx.targets.length) part('action-info').textContent += ' Нет разрешённых целей в текущей области видимости. Мастерские данные не открываются игроку автоматически.';
      command('add').disabled = loading || !!model.pending || !editable || !a?.supported || !field('target').value;
    }
    function queue() {
      part('queue').replaceChildren(); let sum = 0, known = true;
      model.actions.forEach((a, i) => {
        const li = node('li'), ab = ability(a), target = ctx.targets.find(t => t.id === a.targets[0]);
        li.append(node('p', (actor(a.actor_id)?.name || 'Недоступное существо') + ' → ' + (ab?.label || 'Недоступная способность') + ' → ' + (target?.name || 'Цель недоступна')));
        li.append(node('p', ab?.supported ? 'Стоимость: ' + ab.ap_cost + ' AP' : 'Предпросмотр заблокирован: источник способности недоступен.'));
        if (ab?.supported) sum += ab.ap_cost; else known = false;
        const params = a.parameters && Object.keys(a.parameters).length;
        if (params) li.append(node('p', 'Сохранены параметры, для которых пока нет интерфейса. Они не удалены; предпросмотр заблокирован.'));
        [['edit', 'Изменить'], ['up', 'Выше'], ['down', 'Ниже'], ['remove', 'Удалить'], ...(params ? [['clear-parameters', 'Сбросить неподдерживаемые параметры']] : [])].forEach(([cmd, label]) => {
          const b = node('button', label); b.type = 'button'; b.dataset.command = cmd; b.dataset.index = String(i); b.disabled = !editable || !!model.pending || loading || (cmd === 'up' && i === 0) || (cmd === 'down' && i === model.actions.length - 1); li.append(b);
        }); part('queue').append(li);
      });
      if (!model.actions.length) part('queue').append(node('li', 'Пока нет подготовленных действий.'));
      const total = node('p', known ? 'Планируемая стоимость: ' + sum + ' / ' + ctx.turn.ap + ' доступных AP группы. AP пока не списываются.' : 'Общая стоимость не определена: проверьте источники действий.');
      if (known && sum > ctx.turn.ap) total.className = 'acs-error'; part('queue-total').replaceChildren(total);
      command('preview').disabled = loading || previewBusy || !model.actions.length || !known || !editable || ctx.encounter_status !== 'active' || !ctx.targets.length;
    }
    function overview() {
      part('overview').replaceChildren(); const line = node('div'); line.className = 'acs-summary';
      ['Раунд: ' + ctx.round, 'Группа: ' + ctx.turn.group_id, 'AP: ' + ctx.turn.ap + ' / ' + ctx.turn.maximum_ap, 'Ход: ' + (statuses[ctx.turn.status] || 'Недоступен'), ctx.encounter_status === 'paused' ? 'Столкновение на паузе' : 'Столкновение активно', 'Черновик: ' + (model.id ? '#' + model.id + ', версия ' + model.version : 'новый') + (model.dirty ? ' — не сохранён' : '')].forEach(t => line.append(node('span', t)));
      part('overview').append(line, node('p', 'Участники группы: ' + ctx.turn.members.map(m => m.name).join(', ')));
      options(field('group'), ctx.turns.map(t => ({ id: t.id, label: 'Группа ' + t.group_id + ' — ' + t.members.map(m => m.name).join(', ') + ' (' + (statuses[t.status] || t.status) + ')' })), ctx.turn.id, 'Выберите группу');
      field('group').disabled = loading || !!model.pending;
      field('prose').disabled = loading || !!model.pending || !editable;
      field('actor').disabled = field('ability').disabled = field('target').disabled = loading || !!model.pending || !editable;
      command('save').disabled = loading || saving || !editable;
      command('refresh').disabled = loading;
      command('save').textContent = model.pending ? 'Повторить тот же запрос сохранения' : 'Сохранить черновик';
      command('restore').disabled = loading;
      command('cancel-edit').hidden = editing === null;
      command('add').textContent = editing === null ? 'Добавить действие' : 'Применить изменение';
      info(); queue();
    }
    async function api(op, body, post) {
      const values = new URLSearchParams(body);
      if (post) values.set('my_post_key', config.post_key);
      const url = config.endpoint + '&op=' + encodeURIComponent(op);
      const response = await fetch(post ? url : url + '&' + values, { method: post ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', ...(post ? { body: values } : {}) });
      const result = await response.json();
      if (!result.ok) { const e = new Error(result.error?.message || 'Запрос не выполнен.'); e.code = result.error?.code; throw e; }
      return result.data;
    }
    async function refresh(turnId) {
      const data = await api('builder_context', { session_id: ctx.session_id, encounter_id: ctx.encounter_id, turn_id: turnId || ctx.turn.id }, false);
      ctx = data; editable = data.turn.status === 'pending'; epoch++; actionForm(); overview();
    }
    async function restore(automatic) {
      if (!automatic && (model.dirty || model.pending) && !window.confirm('Восстановить серверную версию? Локальные несохранённые изменения будут заменены.')) return;
      loading = true; overview();
      try {
      await refresh();
      const id = ctx.draft_id || model.id;
      if (!id) { status('Серверный черновик для этой группы пока не найден. Локальные данные сохранены.', false); return; }
      const dto = await api('draft_restore', { draft_id: id }, false);
      model.restore(dto); editable = !!dto.editable; field('prose').value = model.prose; editing = null; invalidate(); actionForm(); overview();
      status(dto.dependency_changed ? 'Черновик восстановлен. Зависимости изменились — нужен новый условный предпросмотр.' : 'Черновик восстановлен с сервера.', false);
      } finally { loading = false; overview(); }
    }
    async function save() {
      // Technical request bounds only; costs and execution permission remain server-owned.
      if (new TextEncoder().encode(model.prose).length > 65536 || new TextEncoder().encode(JSON.stringify(model.actions)).length > 65536) throw new Error('Черновик превышает технический предел 64 КиБ для текста или действий.');
      const key = model.pending ? model.pending.operation_key : crypto.randomUUID().replace(/-/g, '');
      const request = model.begin(ctx, key); saving = true; localSave(); overview();
      try { const receipt = await api('draft_save', request, true); model.ack(receipt); ctx.draft_id = model.id; localSave(); overview(); status('Черновик сохранён. Версия ' + model.version + '.', false); }
      catch (e) {
        const conflicts = ['ACS_DRAFT_VERSION_CONFLICT', 'ACS_DRAFT_ALREADY_EXISTS', 'ACS_OPERATION_KEY_CONFLICT'];
        if (conflicts.includes(e.code)) { model.pending = null; localSave(); overview(); status('Конфликт сохранения. Локальный текст оставлен. Восстановите серверную версию отдельной кнопкой или сохраните свой текст перед заменой.', true); }
        else { overview(); status(e.message + ' Исход запроса не подтверждён. Повторите тот же запрос или явно восстановите серверную версию.', true); }
      } finally { saving = false; overview(); }
    }
    function renderPreview(dto) {
      part('preview').replaceChildren(node('p', dto.label + '. Сценарий: ' + ({ hit: 'попадания без крита', crit: 'критические попадания', miss: 'промахи' }[dto.scenario])));
      const list = node('ol'); dto.actions.forEach((a, i) => {
        const li = node('li'), p = a.probabilities; li.append(node('p', 'Действие ' + (i + 1) + ': P попадания ' + p.P_hit + '%, P крита при попадании ' + p.P_crit + '%. Условный урон: ' + a.damage + '.'));
        a.components.forEach(c => li.append(node('p', 'Компонент ' + (c.source_index + 1) + ': ' + ({ physical: 'физический', elemental: 'стихийный', true: 'истинный' }[c.damage_type] || 'урон') + ', защита ' + c.effective_defense + ', после защиты ' + c.post_defense + ', распределено ' + c.allocated + '.')));
        li.append(node('p', 'Стоимость: ' + a.costs.map(c => c.amount + ' ' + (c.kind === 'ap' ? 'AP' : 'ресурса')).join(', '))); list.append(li);
      }); part('preview').append(list, node('h4', 'Виртуальное состояние — не сохранено'));
      dto.virtual_entities.forEach(e => part('preview').append(node('p', (ctx.actors.find(a => a.id === e.entity_id)?.name || ctx.targets.find(t => t.id === e.entity_id)?.name || 'Участник') + ': HP ' + e.hp + ' / ' + e.max_hp)));
      Object.entries(dto.virtual_groups).forEach(([id, g]) => part('preview').append(node('p', 'Группа ' + id + ': виртуальные AP ' + g.ap.current + ' / ' + g.ap.maximum)));
    }
    async function preview() {
      const revision = model.revision, snapshotEpoch = epoch; previewBusy = true; overview(); status('Рассчитывается условная последовательность без бросков…', false);
      try { const dto = await api('builder_preview', model.preview(ctx, field('scenario').value), true); if (revision === model.revision && snapshotEpoch === epoch) { renderPreview(dto); status('Условный расчёт готов. Реальные HP/AP не изменены.', false); } }
      catch (e) { if (revision === model.revision && snapshotEpoch === epoch) { part('preview').replaceChildren(node('p', e.message)); status(e.message, true); } }
      finally { previewBusy = false; overview(); }
    }
    root.addEventListener('change', async event => {
      try {
        if (event.target === field('actor')) actionForm({ actor_id: field('actor').value, ability_key: '', targets: [] });
        else if (event.target === field('ability') || event.target === field('target')) info();
        else if (event.target === field('scenario')) { invalidate(); }
        else if (event.target === field('group')) {
          const turn = field('group').value;
          if (!turn || (model.dirty && !window.confirm('Перейти к другой группе и оставить несохранённые изменения? Сохраните черновик перед переходом, если он нужен.'))) { field('group').value = ctx.turn.id; return; }
          loading = true; overview(); await refresh(turn); model = new DraftModel(); editing = null; field('prose').value = ''; loading = false; invalidate(); actionForm(); overview(); await restore(true);
        }
      } catch (e) { loading = false; status(e.message, true); overview(); }
    });
    field('prose').addEventListener('input', () => { try { model.text(field('prose').value); invalidate(); overview(); } catch (e) { field('prose').value = model.prose; status(e.message, true); } });
    root.addEventListener('click', async event => {
      const button = event.target.closest('button[data-command]'); if (!button || !root.contains(button) || button.disabled) return;
      const cmd = button.dataset.command, index = Number(button.dataset.index);
      try {
        if (cmd === 'add') {
          const a = { actor_id: field('actor').value, ability_key: field('ability').value, targets: [field('target').value], parameters: editing === null ? [] : copy(model.actions[editing].parameters || []) };
          if (!ability(a)?.supported || !ctx.targets.some(t => t.id === a.targets[0] && t.actors.includes(a.actor_id))) throw new Error('Выберите разрешённые существо, способность и цель.');
          if (editing === null) model.add(a); else model.update(editing, a); editing = null; invalidate(); overview(); status('Последовательность изменена. Сохраните черновик.', false);
        } else if (cmd === 'remove' || cmd === 'up' || cmd === 'down') { if (cmd === 'remove') model.remove(index); else model.move(index, cmd === 'up' ? -1 : 1); editing = null; invalidate(); overview(); }
        else if (cmd === 'edit') { editing = index; actionForm(model.actions[index]); overview(); field('actor').focus(); }
        else if (cmd === 'cancel-edit') { editing = null; overview(); }
        else if (cmd === 'clear-parameters') { if (window.confirm('Удалить сохранённые неподдерживаемые параметры этого действия?')) { model.update(index, { ...model.actions[index], parameters: [] }); invalidate(); overview(); } }
        else if (cmd === 'save') await save();
        else if (cmd === 'restore') await restore(false);
        else if (cmd === 'refresh') { await refresh(); invalidate(); status('Сведения обновлены. Локальные действия и текст сохранены.', false); }
        else if (cmd === 'preview') await preview();
      } catch (e) { status(e.message, true); }
    });
    window.addEventListener('beforeunload', event => { if (model.dirty || model.pending) { localSave(); event.preventDefault(); event.returnValue = ''; } });
    (async () => {
      try {
        let local = null; try { local = JSON.parse(sessionStorage.getItem(storageKey())); } catch (_) {}
        if (local && Array.isArray(local.actions) && typeof local.prose === 'string') {
          Object.assign(model, local); field('prose').value = model.prose; actionForm(); overview(); status('Восстановлена локальная несохранённая копия этой вкладки. При неизвестном результате повторите сохранение тем же запросом.', false);
        } else { actionForm(); overview(); if (ctx.draft_id) await restore(true); }
      } catch (e) { status(e.message, true); }
    })();
  }
  function init() { document.querySelectorAll('[data-acs-builder]').forEach(root => { try { mount(root); } catch (_) { const s = root.querySelector('[data-part="status"]'); if (s) s.textContent = 'Не удалось открыть конструктор. Обновите страницу.'; } }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
})();
