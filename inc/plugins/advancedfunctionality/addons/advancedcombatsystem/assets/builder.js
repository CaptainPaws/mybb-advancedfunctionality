(function () {
  'use strict';
  const copy = value => JSON.parse(JSON.stringify(value));
  class DraftModel {
    constructor() { this.actions = []; this.prose = ''; this.id = ''; this.version = '0'; this.dirty = false; this.pending = null; this.sealPending = null; this.sealedCount = 0; this.revision = 0; }
    changed() { if (this.pending || this.sealPending) throw new Error('Сначала завершите или восстановите предыдущее сохранение.'); this.dirty = true; this.revision++; }
    add(action) { if (this.actions.length >= 100) throw new Error('Достигнут технический предел черновика: 100 действий.'); this.changed(); this.actions.push(copy(action)); }
    update(index, action) { if (index < this.sealedCount) throw new Error('Запечатанное действие неизменно.'); if (!this.actions[index]) throw new Error('Действие не найдено.'); this.changed(); this.actions[index] = copy(action); }
    remove(index) { if (index < this.sealedCount) throw new Error('Отмена seal разрешена только отдельной кнопкой с конца.'); if (!this.actions[index]) return; this.changed(); this.actions.splice(index, 1); }
    move(index, delta) { const to = index + delta; if (index < this.sealedCount || to < this.sealedCount) throw new Error('Запечатанный порядок неизменен.'); if (!this.actions[index] || to < 0 || to >= this.actions.length) return; this.changed(); [this.actions[index], this.actions[to]] = [this.actions[to], this.actions[index]]; }
    text(value) { if (this.prose !== value) { this.dirty = true; this.revision++; this.prose = value; } }
    begin(scope, key) {
      if (this.sealPending) throw new Error('Сначала восстановите исход seal.');
      if (this.pending) return copy(this.pending);
      this.pending = { session_id: scope.session_id, encounter_id: scope.encounter_id, turn_id: scope.turn.id, draft_id: this.id, expected_draft_version: this.version, operation_key: key, prose: this.prose, actions: JSON.stringify(this.actions) };
      return copy(this.pending);
    }
    ack(receipt) { this.id = String(receipt.draft_id); this.version = String(receipt.draft_version); const saved = this.pending; this.pending = null; this.dirty = !saved || this.prose !== saved.prose || JSON.stringify(this.actions) !== saved.actions; }
    restore(dto) { this.actions = copy(dto.actions); this.prose = dto.prose; this.id = String(dto.draft_id); this.version = String(dto.draft_version); this.pending = null; this.sealPending = null; this.sealedCount = (dto.sealed_prefix || []).length; this.dirty = false; this.revision++; }
    preview(scope, scenario) { return { session_id: scope.session_id, encounter_id: scope.encounter_id, turn_id: scope.turn.id, scope_token: scope.scope_token, scenario, actions: JSON.stringify(this.actions) }; }
  }
  const planning = context => context.mode === 'pre_round' && context.encounter_status === 'preparation';
  const canEdit = context => planning(context) || context.turn.status === 'pending';
  const availableAP = (context, actorId) => planning(context) ? (context.actors.find(a => a.id === actorId)?.available_ap ?? context.turn.ap) : context.turn.ap;
  if (typeof module !== 'undefined' && module.exports) module.exports = { DraftModel, planning, canEdit, availableAP };
  if (typeof document === 'undefined') return;

  if (window.AFACSBuilder) return;
  const mounts = new WeakMap();
  let builderInstance = 0;
  function mount(root) {
    if (root.dataset.acsMounted) return;
    root.dataset.acsMounted = '1';
    const lifecycle = new AbortController();
    const listener = {signal: lifecycle.signal};
    const targetGroup = 'acs-builder-target-' + (++builderInstance);
    const config = JSON.parse(root.querySelector('[data-acs-builder-config]').textContent);
    let ctx = config.context, model = new DraftModel(), editing = null, loading = false, saving = false, previewBusy = false, epoch = 0, sealView = null, editable = canEdit(ctx);
    let autosaveTimer = null, saveState = 'saved', autosaveBlocked = false, initialized = false, frozen = false, composing = false, serverProse = '', externalProse = null, recoveredSave = false, observedVersion = '';
    let step = 1, pickedTargets = [], lastFormActor = '', lastFormChoice = '', validation = null, validationTimer = null, validationSerial = 0;
    const field = key => root.querySelector('[data-field="' + key + '"]');
    const part = key => root.querySelector('[data-part="' + key + '"]');
    const command = key => root.querySelector('[data-command="' + key + '"]');
    const node = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = String(text); return n; };
    const status = (text, error) => { part('status').textContent = text; part('status').classList.toggle('acs-error', !!error); };
    const statuses = { pending: 'Ожидает действий', resolved: 'Завершён', passed: 'Пропущен', forfeited: 'Исключён' };
    let versionsDirty=false,versionBusy=false;
    const versions = window.AFACSRuntime?.watch(root, () => ({session_id:ctx.session_id,encounter_id:ctx.encounter_id,tid:ctx.tid,...(model.id?{draft_id:model.id}:{})}), data => {
      versionsDirty=true; invalidate();
      if(sealView)sealView.confirmed=false;
      if(model.id && data.versions.draft_version!==null && data.versions.draft_version!==model.version){autosaveBlocked=true;saveState='conflict';}
      root.dispatchEvent(new CustomEvent('acs:version-changed',{bubbles:true}));
      saveStatus();status('Состояние боя изменилось. Текст и действия сохранены. Перед следующей операцией будут обновлены разрешённые сведения.',true);overview();
    }, e => status(e.message || 'Обновление недоступно. Текст и действия сохранены.',true));
    mounts.set(root, () => {
      localSave();
      lifecycle.abort();
      versions?.dispose();
      clearTimeout(autosaveTimer);
      clearTimeout(validationTimer);
      delete root.acsBuilder;
      delete root.dataset.acsMounted;
    });
    function notifyCommit() {
      root.dispatchEvent(new CustomEvent('acs:committed', {bubbles: true, detail: {tid: ctx.tid, sid: ctx.session_id}}));
    }
    async function ensureCurrent(reason) {
      if(versionBusy)throw new Error('Проверка состояния уже выполняется. Дождитесь её ответа; текст и действия сохранены.');
      if(!versions)throw new Error('Компонент обновления ACS недоступен. Текст и действия сохранены; проверьте разрешение assets.');
      versionBusy=true;try{
      await versions.check(reason);
      if(versionsDirty){await refresh();versionsDirty=false;invalidate();
        throw new Error('Состояние боя изменилось. Проверьте исполнителей, цели и расходы и подтвердите результат заново. Текст и последовательность сохранены.');}
      }finally{versionBusy=false;}
    }
    const storageKey = () => ['acs-draft', ctx.author_uid, ctx.session_id, ctx.encounter_id, ctx.turn.id].join(':');
    const backupKey = () => storageKey() + ':prose-v2';
    const journalKey = () => storageKey() + ':requests-v2';
    const savedKey = () => storageKey() + ':saved-v2';
    const lifetime = 24 * 60 * 60 * 1000;
    try {for(let i=localStorage.length-1;i>=0;i--){const key=localStorage.key(i);if(key?.startsWith('acs-draft:'+ctx.author_uid+':') && key.endsWith(':prose-v2')){const value=JSON.parse(localStorage.getItem(key));if(!value || value.expires<=Date.now() || value.expires>Date.now()+lifetime)localStorage.removeItem(key);}}}catch(_){}
    function backup() {
      try { const value = JSON.parse(localStorage.getItem(backupKey())); if (value && typeof value.prose === 'string' && value.expires > Date.now() && value.expires <= Date.now() + lifetime) return value; localStorage.removeItem(backupKey()); } catch (_) {}
      return null;
    }
    function saveStatus() {
      const labels = {dirty:'Есть несохранённые изменения',saving:'Сохранение…',saved:'Сохранено',error:'Ошибка сохранения',conflict:'Конфликт другой вкладки'};
      if(part('save-status').textContent!==labels[saveState])part('save-status').textContent = labels[saveState];
      part('save-status').classList.toggle('acs-error', ['error','conflict'].includes(saveState));
      const local=backup();command('restore-local').hidden = !local || local.prose === model.prose;
      root.dispatchEvent(new CustomEvent('acs:builder-state', {bubbles:true}));
    }
    function localSave() {
      // Only prose is a local backup. Exact outstanding request envelopes are retry journals, never tape/results.
      if (initialized && model.prose !== serverProse) try { localStorage.setItem(backupKey(),JSON.stringify({prose:model.prose,expires:Date.now()+lifetime})); } catch (_) {}
      try {
        sessionStorage.removeItem(storageKey()); // retire the old automatic whole-model restore
        if(model.pending || model.sealPending) sessionStorage.setItem(journalKey(),JSON.stringify({id:model.id,version:model.version,pending:model.pending,sealPending:model.sealPending,expires:Date.now()+lifetime}));
        else sessionStorage.removeItem(journalKey());
      } catch (_) {}
    }
    function scheduleSave() {
      clearTimeout(autosaveTimer);
      if (lifecycle.signal.aborted || !initialized || composing || frozen || autosaveBlocked || !editable || model.pending || model.sealPending || !model.dirty) return;
      saveState = 'dirty'; saveStatus();
      autosaveTimer = setTimeout(() => { if (!loading && !saving && editing === null && !frozen) save().catch(e => {saveState='error';autosaveBlocked=true;saveStatus();status(e.message,true);}); else scheduleSave(); }, 1200);
    }
    function setProse(value) {
      model.text(String(value).replace(/\r\n?/g,'\n')); field('prose').value=model.prose; localSave();
      if(model.dirty && !['saving','conflict','error'].includes(saveState))saveState='dirty';
      saveStatus(); scheduleSave();
    }
    const localAvailable=()=>{const value=backup();return !!value && value.prose!==model.prose;};
    root.acsBuilder = {
      state: () => ({ready:initialized,dirty:model.dirty,busy:loading||saving,pending:!!(model.pending||model.sealPending),id:model.id,version:model.version,confirmed:!!sealView?.confirmed && model.actions.length===model.sealedCount && !model.dirty,blocked:!!sealView?.blocked,prose:model.prose,saveState,backupAvailable:localAvailable(),session_id:ctx.session_id,turn_id:ctx.turn.id}),
      describe:action=>({actor:actor(action.actor_id)?.name || 'Исполнитель',ability:ability(action)?.label || 'Действие',targets:(action.targets || []).map(id=>ctx.targets.find(t=>t.id===id)?.name || 'Разрешённая цель')}),
      prose:setProse,
      restoreLocal:()=>{if(frozen || loading)return;const value=backup();if(value && window.confirm('Заменить литературный текст локальной копией? Действия и броски не восстанавливаются из браузера.')){setProse(value.prose);if(externalProse)externalProse(value.prose);overview();}},
      attach: writer => {externalProse=writer;field('prose').closest('label').hidden=true;},
      revalidate:async () => {await ensureCurrent('publication');return root.acsBuilder.state();},
      save: async () => {if(!initialized || loading || saving || model.sealPending || autosaveBlocked)throw new Error('Сохранение заблокировано. Проверьте прежний запрос или конфликт версий.');if(model.dirty || model.pending)await save();if(model.dirty || model.pending)throw new Error('Черновик не подтверждён сервером.');return root.acsBuilder.state();},
      freeze: value => {if(frozen===!!value)return;frozen=!!value;clearTimeout(autosaveTimer);overview();if(!frozen)scheduleSave();},
      restoreText: value => {setProse(value);if(externalProse)externalProse(model.prose);}
    };
    function invalidate(checkSequence = false) { if(checkSequence && sealView)sealView.confirmed=false;part('preview').replaceChildren(); epoch++; localSave(); if (model.dirty && !['saving','error','conflict'].includes(saveState))saveState='dirty';saveStatus();scheduleSave(); if (checkSequence) validateSequence(); }
    window.addEventListener('storage', event => {
      if(event.key===savedKey() && model.id)try {const other=JSON.parse(event.newValue);if(other.id===model.id && other.version!==model.version){observedVersion=other.version;autosaveBlocked=true;clearTimeout(autosaveTimer);saveState='conflict';saveStatus();status('Другая вкладка сохранила новую версию. Ваш текст сохранён отдельно; восстановите серверный черновик явно.',true);}}catch(_){}
    }, listener);
    document.addEventListener('visibilitychange',localSave,listener);
    root.dispatchEvent(new CustomEvent('acs:open'));

    root.addEventListener('acs:compositionstart',()=>{composing=true;clearTimeout(autosaveTimer);});
    root.addEventListener('acs:compositionend',()=>{composing=false;scheduleSave();});
    field('prose').addEventListener('compositionstart',()=>{composing=true;clearTimeout(autosaveTimer);});
    field('prose').addEventListener('compositionend',()=>{composing=false;scheduleSave();});
    function options(select, rows, chosen, placeholder) {
      select.replaceChildren(); const empty = node('option', placeholder || 'Выберите'); empty.value = ''; select.append(empty);
      rows.forEach(row => { const o = node('option', row.label || row.name); o.value = row.id || row.key; o.disabled = row.supported === false; if (o.disabled && row.reason) o.textContent += ' — ' + row.reason; o.selected = o.value === chosen; if(['objects','cooperation','spatial','cover'].includes(row.category)) { let group = select.querySelector('optgroup[data-category="' + row.category + '"]'); if (!group) { group = node('optgroup'); group.label = row.category === 'cover' ? 'Укрытия' : row.category === 'spatial' ? 'Перемещение и контакт' : (row.category === 'cooperation' ? 'Совместные действия' : 'Взаимодействия с объектами'); group.dataset.category = row.category; select.append(group); } group.append(o); } else select.append(o); });
    }
    const budget = () => availableAP(ctx, model.actions[0]?.actor_id || field('actor').value);
    const actor = id => ctx.actors.find(a => a.id === id);
    const ability = action => (actor(action.actor_id)?.choices || []).find(a => a.key === action.ability_key);
    function knowledge() {
      const container = part('knowledge'); if (!container) return;
      container.replaceChildren();
      const labels = {suspected:'Есть признаки', detected:'Обнаружено', identified:'Опознано'};
      (ctx.cover || []).filter(c => c.status === 'active').forEach(c => container.append(node('p', 'Известное укрытие: ' + c.category)));
      const zoneNames = new Map((ctx.spatial?.zones || []).map(z => [z.id,z.name])); (ctx.spatial?.positions || []).forEach(p => container.append(node('p', (ctx.actors.find(a => a.id === p.entity_id)?.name || ctx.targets.find(t => t.id === p.entity_id)?.name || 'Известный участник') + ': ' + (zoneNames.get(p.zone_id) || 'Позиция не назначена'))));
      const slots = {primary_form:'Основная форма', form_modifier:'Модификатор формы', cosmetic_form:'Косметическая форма'};
      (ctx.actors || []).forEach(a => {
        const state = a.advanced_state || {};
        (state.forms || []).forEach(f => container.append(node('p', a.name + ': ' + (slots[f.slot] || 'Форма') + ' · ' + f.mode + ' · срок ' + f.remaining + ' ' + f.unit)));
        (state.granted_abilities || []).forEach(g => container.append(node('p', a.name + ': временная способность · применений ' + g.remaining_uses + ' · срок ' + g.remaining + ' ' + g.unit)));
        (state.effects || []).forEach(e => container.append(node('p', a.name + ': ' + (e.kind === 'reflect_effect' ? 'Отражение' : 'Поглощение') + ' · срок ' + e.remaining + ' ' + e.unit)));
      });
      (ctx.objectives || []).forEach(o => container.append(node('p', o.label + ': ' + o.progress + ' / ' + o.cap + (o.status === 'completed' ? ' · завершена' : ''))));
      (ctx.known_subjects || []).forEach(entry => {
        const details = entry.details || {};
        container.append(node('p', (labels[entry.level] || 'Известный объект') + ': ' + (details.name || details.hint || 'Доступные сведения ограничены.')));
        if (ctx.can_manage && entry.gm_only) {
          const block = node('details'); block.append(node('summary', 'Осведомлённость · GM'));
          (entry.awareness || []).forEach(row => block.append(node('p', row.audience_kind + ' #' + row.audience_id + ': ' + row.level + ' · v' + row.awareness_version)));
          container.append(block);
        }
      });
    }
    const selectedChoice = () => (actor(field('actor').value)?.choices || []).find(c => c.key === field('ability').value);
    const categoryLabels = {attack:'Атака',abilities:'Способности',items:'Предметы',equipment:'Экипировка',tactics:'Тактика',special:'Особые'};
    const costText = costs => (costs || []).map(c => c.amount + ' ' + (c.kind === 'ap' ? 'AP' : (c.resource || (c.kind === 'item' ? 'шт.' : 'ресурса')))).join(', ') || 'Затраты не определены';
    function targetsValid() {
      const c = selectedChoice(), count = c?.target_count || {min:1,max:1};
      return !!c?.supported && pickedTargets.length >= count.min && pickedTargets.length <= count.max && new Set(pickedTargets).size === pickedTargets.length && pickedTargets.every(id => (c.target_ids || []).includes(id));
    }
    function renderActor() {
      const box = part('actor-details'), performer = actor(field('actor').value); box.replaceChildren();
      if (!performer) { box.append(node('p','Выберите разрешённого исполнителя своей группы.')); return; }
      const kinds = {character:'Персонаж',pet:'Питомец',summon:'Призыв',npc:'NPC',monster:'Монстр'};
      box.append(node('p',performer.name + ' · ' + (kinds[performer.kind] || 'Исполнитель') + ' · общий AP группы: ' + availableAP(ctx,performer.id)));
      const state = performer.state || {};
      box.append(node('p',state.hp ? 'HP: ' + state.hp.current + ' / ' + state.hp.maximum : 'Сведения о здоровье и ресурсах ограничены сервером.'));
      Object.entries(state.resources || {}).forEach(([key,value]) => box.append(node('p',key + ': ' + value.current + ' / ' + value.maximum)));
      (state.statuses || []).forEach(s => box.append(node('p','Статус: ' + s.label + ' × ' + s.stacks + ' · осталось ' + s.remaining + ' ' + ({round:'раундов',own_turn:'собственных ходов',target_turn:'ходов цели'}[s.unit] || 'единиц времени'))));
      if (state.notice) box.append(node('p',state.notice));
      if (performer.supported !== false) box.append(node('p',planning(ctx) ? 'Доступна предраундовая подготовка по серверному контракту.' : 'Исполнитель доступен для подготовки действий. Допустимость всей последовательности проверит сервер.'));
      if (performer.reason) box.append(node('p',performer.reason));
      if (performer.catalog_notice) box.append(node('p',performer.catalog_notice));
    }
    function renderCatalog() {
      const box = part('catalog'), active = document.activeElement;
      const focus = box.contains(active) ? [active.dataset.choiceActor,active.dataset.choice] : null;
      box.replaceChildren();
      const search = field('search').value.trim().toLocaleLowerCase(), category = field('category').value, filterActor = field('catalog-actor').value;
      let shown = false;
      ctx.actors.filter(a => !filterActor || a.id === filterActor).forEach(performer => (performer.choices || []).filter(c => !c.sealed_only).forEach(c => {
        const supported = c.supported && performer.supported !== false;
        if (category && c.catalog_category !== category || field('available-only').checked && !supported || search && !(c.label + ' ' + (c.description || '') + ' ' + performer.name).toLocaleLowerCase().includes(search)) return;
        shown = true; const card = node('article'); card.className = 'acs-action-card';
        const chosen = field('actor').value === performer.id && field('ability').value === c.key;
        card.classList.toggle('is-selected',chosen);
        card.append(node('h5',c.label),node('p',performer.name + ' · ' + (categoryLabels[c.catalog_category] || 'Способности')));
        card.append(node('p',c.description || 'Описание не задано.'),node('p',costText(c.costs)),node('p',c.cooldown_text || 'Сведения о кулдауне не предоставлены.'));
        (c.prepared_item_costs || []).forEach(cost => card.append(node('p','Дополнительно: ' + cost.qty + ' шт. · ' + cost.key + ' · ' + cost.timing)));
        if ((c.requirements || []).length) c.requirements.forEach(text => card.append(node('p',text))); else card.append(node('p','Условия действия и последовательности проверит сервер.'));
        if (!supported) card.append(node('p',performer.supported === false ? performer.reason : c.reason || 'Действие недоступно по текущему серверному контракту.'));
        const button = node('button',chosen ? 'Выбрано' : 'Выбрать действие'); button.type = 'button'; button.dataset.command = 'choose'; button.dataset.choice = c.key; button.dataset.choiceActor = performer.id;
        button.setAttribute('aria-pressed',String(chosen)); button.disabled = !supported || !editable || loading || saving || !!model.pending || !!model.sealPending; card.append(button); box.append(card);
        if (focus && focus[0] === performer.id && focus[1] === c.key) button.focus({preventScroll:true});
      }));
      if (!shown) box.append(node('p','Нет известных действий по выбранным фильтрам. Измените фильтры или исполнителя.'));
    }
    function renderTargets() {
      const box = part('target-options'), order = part('target-order'), c = selectedChoice(), active = document.activeElement;
      const focus = box.contains(active) ? active.dataset.targetId : null, orderFocus = order.contains(active) ? [active.dataset.command,active.dataset.index] : null;
      box.replaceChildren(); order.replaceChildren(); part('target-help').textContent = c?.target_help || 'Сначала выберите действие.';
      const rows = ctx.targets.filter(t => (c?.target_ids || []).includes(t.id));
      const locked = loading || saving || !editable || !!model.pending || !!model.sealPending || !c?.supported;
      rows.forEach(t => {
        const label = node('label'), input = node('input'); input.type = c.target_mode === 'multiple' ? 'checkbox' : 'radio'; input.name = targetGroup; input.dataset.targetId = t.id;
        input.checked = pickedTargets.includes(t.id); input.disabled = locked; label.append(input,node('span',t.name)); box.append(label);
        if (focus === t.id) input.focus({preventScroll:true});
      });
      if (!rows.length) box.append(node('p','Для этого действия нет разрешённых целей в текущем контексте.'));
      const stale = pickedTargets.some(id => !(c?.target_ids || []).includes(id));
      if (stale) order.append(node('li','Выбор целей больше не соответствует текущим серверным сведениям. Сбросьте выбор и назначьте цели заново.'));
      else pickedTargets.forEach((id,index) => {
        const target = rows.find(t => t.id === id); if (!target) return;
        const li = node('li',target.name);
        if (c.target_mode === 'multiple') [['target-up','Выше'],['target-down','Ниже'],['target-remove','Убрать']].forEach(([cmd,text]) => {
          const button = node('button',text); button.type = 'button'; button.dataset.command = cmd; button.dataset.index = String(index);
          button.disabled = locked || cmd === 'target-up' && index === 0 || cmd === 'target-down' && index === pickedTargets.length - 1; button.setAttribute('aria-label',text + ': ' + target.name); li.append(button);
        }); order.append(li);
      });
      if (orderFocus) { const button = Array.from(order.querySelectorAll('button')).find(b => b.dataset.command === orderFocus[0] && b.dataset.index === orderFocus[1] && !b.disabled); if (button) button.focus({preventScroll:true}); }
      command('clear-targets').disabled = locked || !pickedTargets.length;
    }
    function renderWizard() {
      root.querySelectorAll('[data-step]').forEach(panel => { panel.hidden = Number(panel.dataset.step) !== step; });
      root.querySelectorAll('[data-step-go]').forEach(button => { if (Number(button.dataset.stepGo) === step) button.setAttribute('aria-current','step'); else button.removeAttribute('aria-current'); });
      part('step-status').textContent = 'Шаг ' + step + ' из 4';
      command('step-back').disabled = step === 1;
      const next = command('step-next'); next.hidden = step === 4; next.textContent = step === 3 ? (editing === null ? 'Добавить и далее' : 'Применить и далее') : 'Далее';
      next.disabled = step === 1 ? !actor(field('actor').value) : step === 2 ? !selectedChoice()?.supported || actor(field('actor').value)?.supported === false : command('add').disabled;
    }
    function goStep(value,focus = true) {
      step = Math.max(1,Math.min(4,Number(value) || 1)); renderWizard();
      if (focus) root.querySelector('[data-step="' + step + '"] [data-step-heading]').focus();
    }
    function validationStamp() { return JSON.stringify([ctx.session_id,ctx.encounter_id,ctx.turn.id,ctx.scope_token,field('scenario').value,model.sealedCount,model.actions]); }
    function currentValidation() { return validation?.stamp === validationStamp() ? validation : null; }
    function renderValidation() {
      const box = part('sequence-validation'); box.replaceChildren(); const current = currentValidation();
      let text = !model.actions.length ? 'Добавьте действия в последовательность.' : planning(ctx) ? 'До первого раунда доступна отдельная подготовка одного действия. Обычная проверка последовательности недоступна.' : model.sealedCount ? 'Запечатанный префикс фиксирован. Следующее запечатывание проверит допустимость всей последовательности на сервере.' : current?.state === 'pending' || current?.state === 'waiting' ? 'Сервер проверяет порядок, цели и виртуальные затраты…' : current?.state === 'valid' ? current.data.label : current?.message || 'Последовательность ещё не проверена сервером.';
      box.append(node('p',text)); box.classList.toggle('acs-error',current?.state === 'invalid');
      if (current?.state === 'valid' && current.data.friendly_fire?.required) box.append(node('p','Предупреждение сервера: возможен дружественный огонь. Перед seal потребуется отдельное подтверждение.'));
      command('validate').disabled = !editable || ctx.encounter_status !== 'active' || planning(ctx) || !!model.sealedCount || !model.actions.length || loading || saving || !!model.pending || !!model.sealPending || current?.state === 'pending';
    }
    function validateSequence(immediate = false) {
      if (validationTimer) clearTimeout(validationTimer);
      const ticket = ++validationSerial, stamp = validationStamp(); validation = null;
      if (!model.actions.length || planning(ctx) || !editable || ctx.encounter_status !== 'active' || model.sealedCount || loading || saving || model.pending || model.sealPending) { renderValidation(); return; }
      validation = {stamp,state:'waiting'}; renderValidation();
      const run = async () => {
        if (ticket !== validationSerial || stamp !== validationStamp()) return;
        validation = {stamp,state:'pending'}; overview();
        try { const data = await api('builder_validate',model.preview(ctx,field('scenario').value),true); if (ticket === validationSerial && stamp === validationStamp()) { if (!data.valid || !Array.isArray(data.actions) || data.actions.length !== model.actions.length || data.scope_token !== ctx.scope_token) throw new Error('Серверная проверка не подтверждена. Обновите сведения и повторите проверку.'); validation = {stamp,state:'valid',data}; } }
        catch (error) { if (ticket === validationSerial && stamp === validationStamp()) validation = {stamp,state:'invalid',message:error.message}; }
        if (ticket === validationSerial) overview();
      };
      if (immediate) return run();
      validationTimer = window.setTimeout(run,350);
    }
    function actionForm(chosen) {
      knowledge();
      const requestedActor = chosen?.actor_id ?? field('actor').value, requestedChoice = chosen?.ability_key ?? field('ability').value;
      options(field('actor'),ctx.actors.map(a => ({...a,supported:true})),requestedActor);
      const performer = actor(field('actor').value);
      options(field('ability'),(performer?.choices || []).filter(c => !c.sealed_only),requestedChoice,'Выберите действие');
      const changed = lastFormActor !== field('actor').value || lastFormChoice !== field('ability').value;
      if (chosen?.targets) pickedTargets = copy(chosen.targets); else if (changed) pickedTargets = [];
      lastFormActor = field('actor').value; lastFormChoice = field('ability').value;
      const c = selectedChoice();
      if (c?.target_mode === 'self' && c.supported && !pickedTargets.length && c.target_ids.length === 1) pickedTargets = [...c.target_ids];
      options(field('target'),ctx.targets.filter(t => (c?.target_ids || []).includes(t.id)),pickedTargets[0] || '','Выберите цель');
      options(field('catalog-actor'),ctx.actors.map(a => ({...a,supported:true})),field('catalog-actor').value,'Все разрешённые исполнители');
      info();
      if (chosen?.parameters?.prepared_items) chosen.parameters.prepared_items.forEach(s => { const select = root.querySelector('[data-prepared-cost="' + s.cost_index + '"]'); if (select) select.value = s.inventory_item_id; });
    }
    function info() {
      const a = selectedChoice(), performer = actor(field('actor').value), choices = performer?.choices || [];
      part('action-info').textContent = performer?.supported === false ? performer.reason : a ? (a.label + ' · ' + (a.supported ? costText(a.costs) : a.reason || 'Действие недоступно.')) : !choices.length ? (performer?.catalog_notice || 'Каталог исполнителя недоступен. Базовая атака без закреплённого определения не подставляется.') : 'Выберите карточку действия.';
      const params = part('parameters'), focusedCost = params.contains(document.activeElement) ? document.activeElement.dataset.preparedCost : null, previous = {}; params.querySelectorAll('[data-prepared-cost]').forEach(s => { previous[s.dataset.preparedCost] = s.value; }); params.replaceChildren();
      (a?.prepared_item_costs || []).forEach(c => { const label = node('label',c.key + ' · ' + c.qty + ' шт. · ' + c.timing), select = node('select'); select.dataset.preparedCost = String(c.cost_index); options(select,c.options.map(o => ({id:o.inventory_item_id,label:o.label})),previous[c.cost_index] || '','Выберите конкретный предмет'); select.disabled = !editable || loading || !!model.pending || !!model.sealPending; label.append(select); params.append(label); });
      if (focusedCost !== null) { const input = Array.from(params.querySelectorAll('[data-prepared-cost]')).find(s => s.dataset.preparedCost === focusedCost); if (input) input.focus({preventScroll:true}); }
      if (!(a?.prepared_item_costs || []).length) params.textContent = 'Параметры действия закреплены сервером.';
      if (a?.prepared_action) part('action-info').textContent += (a.prepared_action.start_execution ? ' Подготовка с запуском из засады.' : ' Подготовка: исполнитель выбран целью действия.') + ' Срок — до следующего собственного хода.';
      command('add').disabled = loading || saving || !!model.pending || !!model.sealPending || !editable || planning(ctx) && model.actions.length > 0 && editing === null || performer?.supported === false || !targetsValid();
      renderActor(); renderCatalog(); renderTargets(); renderWizard();
    }
    function commitAction() {
      if (!targetsValid() || actor(field('actor').value)?.supported === false) throw new Error('Выберите исполнителя, действие и цели из текущего серверного списка.');
      if (editing === null && model.actions.length >= (ctx.sequence_limit || 20)) throw new Error('Достигнут серверный предел действий для этой последовательности.');
      const a = {actor_id:field('actor').value,ability_key:field('ability').value,targets:copy(pickedTargets),parameters:editing === null ? [] : copy(model.actions[editing].parameters || [])};
      if ((ability(a)?.prepared_item_costs || []).length) { a.parameters = {prepared_items:Array.from(part('parameters').querySelectorAll('[data-prepared-cost]')).map(s => ({cost_index:Number(s.dataset.preparedCost),inventory_item_id:s.value}))}; if (a.parameters.prepared_items.some(s => !s.inventory_item_id)) throw new Error('Выберите предмет для каждой стоимости.'); }
      if (editing === null) model.add(a); else model.update(editing,a);
      editing = null; invalidate(true); overview(); goStep(4); status('Последовательность изменена. Сервер проверит порядок и виртуальные затраты; затем сохраните черновик.',false);
    }
    function queue() {
      const active = document.activeElement, focus = part('queue').contains(active) ? [active.dataset.command,active.dataset.index] : null;
      part('queue').replaceChildren(); let sum = 0, known = true;
      model.actions.forEach((a, i) => {
        const li = node('li'), ab = ability(a), targetNames = a.targets.map(id => ctx.targets.find(t => t.id === id)?.name || 'Цель недоступна'), checkedCosts = currentValidation()?.state === 'valid' ? currentValidation().data.actions[i]?.costs : null;
        li.append(node('p', (actor(a.actor_id)?.name || 'Недоступное существо') + ' → ' + (ab?.label || 'Недоступная способность') + ' → ' + targetNames.join(' → ')));
        li.append(node('p', checkedCosts ? 'Проверено сервером: ' + costText(checkedCosts) : ab?.supported ? 'Заявленные затраты: ' + costText(ab.costs) : 'Источник действия недоступен. Обновите сведения и проверьте последовательность.'));
        if (checkedCosts) sum += checkedCosts.filter(c => c.kind === 'ap').reduce((n,c) => n + c.amount,0); else if (ab?.supported && Number.isFinite(ab.ap_cost)) sum += ab.ap_cost; else known = false;
        const itemParameters = a.parameters?.prepared_items;
        if (itemParameters) itemParameters.forEach(s => li.append(node('p', 'Стоимость #' + s.cost_index + ' · предмет #' + s.inventory_item_id)));
        const params = a.parameters && Object.keys(a.parameters).length && !itemParameters;
        if (i < model.sealedCount) li.append(node('p', 'Запечатано: способность, цель и порядок неизменны.'));
        if (params) li.append(node('p', 'Сохранены параметры, для которых пока нет интерфейса. Они не удалены; предпросмотр заблокирован.'));
        [['edit', 'Изменить'], ['up', 'Выше'], ['down', 'Ниже'], ['remove', 'Удалить'], ...(params ? [['clear-parameters', 'Сбросить неподдерживаемые параметры']] : [])].forEach(([cmd, label]) => {
          const b = node('button', label); b.type = 'button'; b.dataset.command = cmd; b.dataset.index = String(i); b.disabled = i < model.sealedCount || (cmd === 'up' && i === model.sealedCount) || !editable || (!!model.pending || !!model.sealPending) || loading || (cmd === 'up' && i === 0) || (cmd === 'down' && i === model.actions.length - 1); li.append(b);
        }); part('queue').append(li);
      });
      if (!model.actions.length) part('queue').append(node('li', 'Пока нет подготовленных действий.'));
      const total = node('p', known ? (currentValidation()?.state === 'valid' ? 'Проверенная сервером стоимость: ' : 'Заявленная стоимость: ') + sum + ' / ' + budget() + ' доступных AP группы. AP пока не списываются.' : 'Общая стоимость не определена: проверьте источники действий.');
      if (known && sum > budget()) total.className = 'acs-error'; part('queue-total').replaceChildren(total);
      if (focus) { const button = Array.from(part('queue').querySelectorAll('button')).find(b => b.dataset.command === focus[0] && b.dataset.index === focus[1] && !b.disabled); if (button) button.focus({preventScroll:true}); }
      renderValidation();
      command('preview').disabled = planning(ctx) || loading || previewBusy || !model.actions.length || !known || !editable || (!planning(ctx) && ctx.encounter_status !== 'active') || !ctx.targets.length || model.sealedCount > 0;
    }
    function overview() {
      part('overview').replaceChildren(); const line = node('div'); line.className = 'acs-summary';
      if (planning(ctx)) { line.append(node('span', 'Подготовка до первого раунда · один исполнитель и одна фиксированная цель на подготовку.')); line.append(node('span', 'Доступно выбранной группе: ' + budget() + ' AP. Подтверждение запишет commitment первого раунда.')); }
      else ['Раунд: ' + ctx.round, 'Группа: ' + ctx.turn.group_id, 'AP: ' + ctx.turn.ap + ' / ' + ctx.turn.maximum_ap, 'Ход: ' + (statuses[ctx.turn.status] || 'Недоступен'), ctx.encounter_status === 'paused' ? 'Столкновение на паузе' : 'Столкновение активно', 'Черновик: ' + (model.id ? '#' + model.id + ', версия ' + model.version : 'новый') + (model.dirty ? ' — не сохранён' : '')].forEach(t => line.append(node('span', t)));
      part('overview').append(line);
      if (editing !== null) part('overview').append(node('p','Редактируется действие ' + (editing + 1) + '. Примените изменение или отмените редактирование на шаге «Цели».'));
      if (!planning(ctx)) part('overview').append(node('p', 'Участники группы: ' + (ctx.turn.members || []).map(m => m.name).join(', ')));
      if (planning(ctx)) ctx.actors.forEach(a => part('overview').append(node('p', a.name + ' · группа #' + a.group_id + ' · оплачено ' + a.pre_round_ap_commitment + ' AP · остаток ' + a.available_ap)));
      const details = part('group-details');
      if (details) {
        details.replaceChildren();
        const controls = {assignment:'назначение',source:'права источника',delegation:'делегирование',temporary_control:'временный контроль',unavailable:'нет актуального разрешения'};
        ctx.actors.forEach(a => details.append(node('p', a.name + ' · ' + (controls[a.control?.via] || 'управление') + (a.supported === false ? ' · ' + a.reason : ''))));
        const units = {round:'границ раунда',own_turn:'собственных ходов',target_turn:'ходов цели'};
        (ctx.summons || []).forEach(s => details.append(node('p', 'Призыв: ' + s.name + ' · остаток: ' + s.remaining + ' ' + (units[s.duration_unit] || '') + (s.status === 'pending_independent' ? ' · переход к независимому управлению в следующем раунде' : s.admission === 'next_round' ? ' · обычные действия со следующего roster' : ' · в текущем roster группы'))));
        (ctx.prepared_actions || []).forEach(p => {
          details.append(node('p', 'Подготовка #' + p.prepared_action_id + ' · ' + p.status + ' · ' + p.cost_state + ' · до следующего собственного хода'));
          if (p.can_release || model.sealPending?.command === 'release-prepared' && model.sealPending.body.prepared_action_id === p.prepared_action_id) { const button = node('button','Проверить и освободить неиспользованный резерв'); button.type = 'button'; button.dataset.command = 'release-prepared'; button.dataset.preparedId = p.prepared_action_id; button.disabled = loading || saving || !!model.pending || !!model.sealPending && (model.sealPending.command !== 'release-prepared' || model.sealPending.body.prepared_action_id !== p.prepared_action_id); details.append(button); }
        });
        if (ctx.can_manage) { const link = node('a','Управление столкновением'); link.href = 'showthread.php?tid=' + encodeURIComponent(ctx.tid) + '&acs_session=' + encodeURIComponent(ctx.session_id) + '&acs_encounter=' + encodeURIComponent(ctx.encounter_id) + '&acs_tab=management'; details.append(link); }
      }
      options(field('group'), ctx.turns.map(t => ({ id: t.id, label: 'Группа ' + t.group_id + ' — ' + t.members.map(m => m.name).join(', ') + ' (' + (statuses[t.status] || t.status) + ')' })), ctx.turn.id, 'Выберите группу');
      field('group').closest('label').hidden = planning(ctx);
      command('upkeep-load').hidden = planning(ctx);field('scenario').closest('label').hidden = planning(ctx);
      field('group').disabled = frozen || saving || planning(ctx) || loading || (!!model.pending || !!model.sealPending);
      field('prose').disabled = frozen || !editable;
      field('actor').disabled = field('ability').disabled = field('target').disabled = loading || (!!model.pending || !!model.sealPending) || !editable;
      command('save').disabled = frozen || loading || saving || !!model.sealPending || !editable || editing !== null || autosaveBlocked && !model.pending;
      command('refresh').disabled = frozen || loading || saving;
      command('save').textContent = model.pending ? 'Повторить тот же запрос сохранения' : 'Сохранить черновик';
      command('restore').disabled = frozen || loading || saving;
      command('cancel-edit').hidden = editing === null;
      command('add').textContent = editing === null ? 'Добавить действие' : 'Применить изменение';
      info(); queue();
      if(frozen) root.querySelectorAll('button[data-command],select,input').forEach(e => {e.disabled=true;});
      const locked = frozen || autosaveBlocked || loading || saving || !!model.pending || !editable || (!planning(ctx) && ctx.encounter_status !== 'active') || model.dirty || editing !== null;
      const remaining = planning(ctx) ? (sealView ? Math.min(budget(), ctx.turn.maximum_ap - sealView.quota_used) : budget()) : (sealView ? sealView.quota_budget - sealView.quota_used : ctx.turn.ap);
      const newCost = model.actions.slice(model.sealedCount).reduce((n, a) => n + (ability(a)?.ap_cost || 0), 0);
      command('seal').disabled = locked || !ctx.targets.length || model.actions.length <= model.sealedCount || newCost > remaining || (!planning(ctx) && model.sealedCount === 0 && currentValidation()?.state !== 'valid');
      command('cancel-seal').disabled = locked || !model.sealedCount;
      command('publish-prepared').disabled = locked || !sealView?.confirmed || !sealView?.summary?.actions?.[0]?.prepared_action;
      command('confirm-seal').disabled = locked || model.actions.length !== model.sealedCount || !sealView?.confirmable || sealView?.blocked || sealView?.confirmed;
      command('seal-refresh').disabled = frozen || loading || saving || !model.id;
      ['compose','compose-quick'].forEach(c => { const b = command(c); if (b) b.disabled = planning(ctx) || !config.test_publication_allowed || locked || !sealView?.confirmed; });
      command('restore-local').disabled=frozen || loading;
      if (model.sealPending) ['seal','cancel-seal','confirm-seal','publish-prepared'].forEach(c => { command(c).disabled = c !== model.sealPending.command || frozen || loading || saving; });
    }
    async function loadUpkeep() {
      const data = await api('clock_sustained', { session_id: ctx.session_id, encounter_id: ctx.encounter_id }, false);
      const container = part('upkeep'); container.replaceChildren();
      data.sources.forEach(source => {
        container.append(node('h4', 'Поддержание источника #' + source.source_entity_id));
        const order = source.effects.filter(e => e.status === 'active').map(e => e.id);
        source.effects.forEach(e => container.append(node('p', '#' + e.id + ' ' + e.effect_key + ' · ' + e.costs.map(c => c.amount + ' ' + (c.resource || c.kind.toUpperCase())).join(', ') + ' · ' + (e.priority === null ? 'FIFO' : 'приоритет ' + e.priority) + ' · ' + (e.termination_reason ? ({source_inactive:'Прекращён: источник выведен из строя',kb_control:'Прекращён по условию KB',effects_cost_unaffordable:'Прекращён: недостаточно ресурсов',effects_resource_invalid:'Прекращён: ресурс недоступен'}[e.termination_reason] || 'Прекращён') : e.status !== 'active' ? 'Завершён' : e.last_payment ? (e.last_payment.paid ? 'Последняя оплата выполнена' : 'Оплата отклонена') : 'Оплата ещё не выполнялась'))));
        if (!order.length || !source.turn || !source.turn.editable) return;
        if (source.turn.applies_next) container.append(node('p', 'Начало уже обработано. Новый порядок применяется со следующего начала хода источника.'));
        const label = node('label', 'Порядок ID активных эффектов через запятую '), input = node('input'); input.value = order.join(','); input.maxLength = 2800; label.append(input);
        const save = node('button', 'Сохранить порядок'); save.type = 'button';
        save.addEventListener('click', async () => {
          save.disabled = true;
          try {
            await api('clock_upkeep_order', { session_id: ctx.session_id, encounter_id: ctx.encounter_id, entity_turn_id: source.turn.id, expected_version: data.session_version, encounter_version: data.encounter_version, effect_order: input.value, operation_key: crypto.randomUUID().replace(/-/g, ''), reason: 'Выбор порядка поддержания' }, true);
            await loadUpkeep(); status('Порядок поддержания сохранён. Обновите контекст действий перед подготовкой.', false);
          } catch (e) { status(e.message, true); save.disabled = false; }
        }); container.append(label, save);
      });
      if (!data.sources.length) container.append(node('p', 'Доступных поддерживаемых эффектов нет.'));
    }
    command('upkeep-load').addEventListener('click', () => loadUpkeep().catch(e => status(e.message, true)));
    async function api(op, body, post) {
      const values = new URLSearchParams(body);
      if (post) values.set('my_post_key', config.post_key);
      const url = config.endpoint + '&op=' + encodeURIComponent(op);
      const response = await fetch(post ? url : url + '&' + values, { method: post ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: lifecycle.signal, ...(post ? { body: values } : {}) });
      let result;
      try { result = await response.json(); } catch (_) { throw new Error('Сервер не подтвердил ответ. Локальные действия и текст сохранены; для неизвестной операции повторяйте прежний запрос.'); }
      if (!result.ok) { const e = new Error((result.error?.message || 'Запрос не выполнен.')+(result.error?.error_id?' · error_id='+result.error.error_id:'')); e.code = result.error?.code;e.category=result.error?.category;e.error_id=result.error?.error_id; throw e; }
      return result.data;
    }
    async function refresh(turnId) {
      const data = await api('builder_context', { session_id: ctx.session_id, encounter_id: ctx.encounter_id, turn_id: turnId || ctx.turn.id }, false);
      ctx = data; editable = canEdit(data); epoch++; actionForm(); overview();
    }
    async function restore(automatic,preserveProse=false) {
      clearTimeout(autosaveTimer);
      if (!automatic && (model.dirty || model.pending || model.sealPending) && !window.confirm('Восстановить серверную версию? Локальные несохранённые изменения будут заменены.')) return;
      loading = true; overview();
      try {
      await refresh();
      const id = ctx.draft_id || model.id;
      if (!id) { status('Серверный черновик для этой группы пока не найден. Локальные данные сохранены.', false); return; }
      const dto = await api('draft_restore', { draft_id: id }, false);
      const keptProse=preserveProse && model.prose!==serverProse?model.prose:null;
      const proseConflict=keptProse!==null && dto.prose!==serverProse && dto.prose!==keptProse;
      localSave(); model.restore(dto); serverProse=model.prose;if(keptProse!==null)model.text(keptProse);recoveredSave=false;observedVersion='';autosaveBlocked=proseConflict;saveState=proseConflict?'conflict':model.dirty?'dirty':'saved';if(externalProse)externalProse(model.prose); editable = !!dto.editable; field('prose').value = model.prose; editing = null; invalidate(); actionForm(); overview();
      status(dto.dependency_changed ? 'Черновик восстановлен. Зависимости изменились — нужен новый условный предпросмотр.' : 'Черновик восстановлен с сервера.', false);
      await refreshSeal();
      } finally { loading = false; overview(); saveStatus();validateSequence();scheduleSave(); }
    }
    async function refreshSeal() {
      if (!model.id) return;
      sealView = await api('seal_status', { draft_id: model.id }, false);
      const prefix = sealView.history.filter(h => h.status === 'sealed').map(h => h.action);
      if (JSON.stringify(model.actions.slice(0, prefix.length)) === JSON.stringify(prefix)) model.sealedCount = prefix.length;
      else { editable = false; status('Локальная копия расходится с запечатанным префиксом. Сохраните свой текст отдельно и восстановите серверный черновик.', true); }
      const box = part('seal'); box.replaceChildren(node('p', 'Квота подготовки: ' + sealView.quota_used + ' / ' + (sealView.quota_budget ?? 'недоступно') + ' AP. Отмена не возвращает квоту.'));
      if (sealView.changed) box.append(node('p', 'Зависимости изменились. Сводка пересчитана на прежних значениях; требуется новое подтверждение.'));
      if (sealView.blocked) box.append(node('p', sealView.reason || 'Сводка заблокирована. Броски сохранены.'));
      sealView.history.forEach(h => {
        if (h.rolls.deferred) { box.append(node('p', 'Подготовка #' + h.id + ': ' + h.status + ', ' + h.ap_cost + ' AP. Будущие проверки ещё не выполнялись.')); return; }
        box.append(node('p', 'Попытка #' + h.id + ': ' + (h.status === 'cancelled' ? 'отменена' : 'запечатана') + ', квота ' + h.ap_cost + ' AP; hit u=' + h.rolls.hit.u + ', crit u=' + h.rolls.crit.u + ' (резерв, если проверка не использована).'));
      });
      if (sealView.summary) {
        box.append(node('h4', sealView.summary.label));
        sealView.summary.actions.forEach((a,i) => { const planned=model.actions[i];box.append(node('p',(i+1)+'. '+(actor(a.actor_id)?.name || 'Исполнитель')+' → '+(planned?.targets || []).map(id=>ctx.targets.find(t=>t.id===id)?.name || 'Разрешённая цель').join(', ')+' · '+costText(a.costs))); if (a.prepared_action) { box.append(node('p', (a.prepared_action.start_execution ? 'При запуске засады · ' : 'При выборе исполнителя целью действия · ') + a.prepared_action.ap_cost + ' AP · до начала следующего собственного хода.')); return; } if (a.cover) { box.append(node('p', a.cover.operation === 'enter' ? 'Занятие укрытия после публикации.' : 'Выход из укрытия после публикации.')); return; } if (a.spatial) { box.append(node('p', ({move:'Перемещение',engage:'Вступление в контакт',leave:'Выход из контакта',forced_move:'Принудительное перемещение',teleport:'Телепортация'}[a.spatial.operation] || 'Пространственное действие') + '. Позиция изменится после боевой публикации.')); return; } if (a.cooperation) { box.append(node('p', (a.cooperation.operation === 'assist' ? 'Помощь применена' : 'Подтверждённый вклад') + ': ' + a.cooperation.progress + ' / ' + a.cooperation.cap + (a.cooperation.completed ? ' · общая задача завершена' : '') + '. До публикации состояние не изменено.')); return; } if (a.interaction) { box.append(node('p', 'Взаимодействие: ' + ({success:'успех',critical_success:'критический успех',partial_success:'частичный успех',failure:'провал'}[a.interaction.outcome] || 'ожидает проверки') + (a.interaction.progress === null ? '' : ', прогресс ' + a.interaction.progress) + '. Изменения применятся после подтверждённой публикации.')); return; } if (['search','share','environment'].includes(a.outcome)) { box.append(node('p', (actor(a.actor_id)?.name || 'Существо') + ': ' + (a.outcome === 'search' ? 'Поиск' : (a.outcome === 'share' ? 'Передача сведений' : 'Воздействие на окружение')) + '. Сведения изменятся после подтверждённой публикации.')); return; } box.append(node('p', a.item ? ('Предмет #' + a.item.item_id + ' · ' + (a.item.operation === 'consume' ? 'использование' : 'смена оружия') + ' · 1 AP, ' + a.item.qty + ' шт.') : ((actor(a.actor_id)?.name || 'Существо') + ': ' + ({hit:'попадание',crit:'критическое попадание',miss:'промах',used:'действие с предметом'}[a.outcome] || a.outcome) + ', урон ' + a.damage + ', P_hit=' + a.probabilities.P_hit + '%, P_crit=' + a.probabilities.P_crit + '%.'))); });
        box.append(node('p', sealView.confirmed ? 'Сводка подтверждена. Общая публикация выключена; тестовая отправка доступна только разрешённому пользователю.' : 'Сводка требует явного подтверждения. Реальные HP/AP не изменены.'));
      }
      if (sealView?.friendly_fire?.required) box.append(node('p','Предупреждение: выбрана атака союзника. Урон не уменьшается автоматически.'));
      overview();saveStatus();
    }
    async function sealCommand(commandName, preparedRecord) {
      if(!model.sealPending)await ensureCurrent('before-seal');
      const routes = {'release-prepared':'prepared_release','publish-prepared':'prepared_publish',seal:'seal','cancel-seal':'seal_cancel_tail','confirm-seal':'seal_confirm'};
      if (commandName !== 'release-prepared' && (!model.id || model.dirty)) throw new Error('Сначала сохраните черновик.');
      if(autosaveBlocked && !model.sealPending)throw new Error('Сначала восстановите серверную версию. Новые броски при конфликте запрещены.');
      if (!model.sealPending) {
        if (commandName === 'cancel-seal' && !window.confirm('Отменить последнее запечатанное действие? История и броски сохранятся, квота не вернётся.')) return;
        const body = commandName === 'release-prepared' ? {session_id:ctx.session_id,encounter_id:ctx.encounter_id,entity_id:preparedRecord.entity_id,prepared_action_id:preparedRecord.prepared_action_id,prepared_version:preparedRecord.prepared_version,expected_version:ctx.session_version,operation_key:crypto.randomUUID().replace(/-/g,'')} : {draft_id:model.id,expected_draft_version:model.version,operation_key:crypto.randomUUID().replace(/-/g,'')};
        if (['seal','confirm-seal'].includes(commandName) && sealView?.friendly_fire?.required && !sealView.friendly_fire.acknowledged) { if (!window.confirm('Выбраны союзники для нанесения урона. Подтвердить дружественный огонь без снижения урона?')) return; body.friendly_fire_confirmation = sealView.friendly_fire.confirmation_hash; }
        if (commandName === 'publish-prepared') body.expected_version = ctx.session_version;
        if (commandName === 'confirm-seal') body.confirmation_hash = sealView.confirmation_hash;
        model.sealPending = {command:commandName,body};
      }
      const pending = copy(model.sealPending); saving = true; localSave(); overview();
      try {
        const operationResult = await api(routes[pending.command], pending.body, true); versions?.reset();
        if (['release-prepared','publish-prepared'].includes(pending.command)) notifyCommit();
        if (pending.command === 'release-prepared') { model.sealPending = null; localSave(); await refresh(); status(operationResult.cost_state === 'settled' ? 'Неиспользованный резерв освобождён. AP и оплаченные стоимости сохранены.' : 'Резерв сохраняется: release не подтверждён, требуется восстановление прав или Inventory.', operationResult.cost_state !== 'settled'); return; }
        if (pending.command === 'publish-prepared') { model.sealPending = null; model.dirty = false; localSave(); const preRound = planning(ctx); if (preRound) { model = new DraftModel(); sealView = null; field('prose').value = ''; part('seal').replaceChildren(); } await refresh(); status(preRound ? 'Подготовка оплачена. AP закреплены в бюджете первого раунда; новый пост не создан.' : 'Подготовка оплачена и зарегистрирована. Ход группы закрыт; новый пост не создан.', false); return; }
        model.sealPending = null; localSave(); saving = false;
        await restore(true,true); status('Операция seal сохранена. Реальное боевое состояние не изменено.',false);
      } catch(e) {
        if (['ACS_PREPARED_UNSUPPORTED','ACS_BEHAVIOR_UNSUPPORTED','ACS_BEHAVIOR_ACTION_BLOCKED','ACS_BEHAVIOR_TARGET_BLOCKED','ACS_BEHAVIOR_NO_LEGAL_TARGET','ACS_FRIENDLY_FIRE_CONFIRMATION_REQUIRED','ACS_SEAL_UNSUPPORTED','ACS_SEAL_IMMUTABLE','ACS_SEAL_SUMMARY_CHANGED','ACS_SEAL_QUOTA_EXHAUSTED','ACS_DRAFT_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_SEAL_TURN_UNAVAILABLE'].includes(e.code)) {
          if(['ACS_DRAFT_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT'].includes(e.code)){autosaveBlocked=true;saveState='conflict';saveStatus();}
          model.sealPending = null; localSave(); if (['publish-prepared','release-prepared'].includes(pending.command)) await refresh(); await refreshSeal();
        }
        status(e.message + (model.sealPending ? ' Повторите ту же кнопку: ключ и запрос сохранены.' : ' Обновите черновик и проверьте сводку.'),true);
      } finally { saving = false; overview();saveStatus();scheduleSave(); }
    }
    async function save() {
      if(saving || loading || frozen)return;
      if(autosaveBlocked && !model.pending)throw new Error('Конфликт другой вкладки. Сначала явно восстановите серверную версию; локальный текст остаётся отдельно.');
      clearTimeout(autosaveTimer);
      // Technical request bounds only; costs and execution permission remain server-owned.
      if (new TextEncoder().encode(model.prose).length > 65536 || new TextEncoder().encode(JSON.stringify(model.actions)).length > 65536) throw new Error('Черновик превышает технический предел 64 КиБ для текста или действий.');
      const key = model.pending ? model.pending.operation_key : crypto.randomUUID().replace(/-/g, '');
      const request = model.begin(ctx, key); saving = true;saveState='saving';saveStatus(); localSave(); overview();
      try { const receipt = await api('draft_save', request, true); model.ack(receipt);versions?.reset();serverProse=request.prose;autosaveBlocked=recoveredSave || !!observedVersion && observedVersion!==model.version;saveState=recoveredSave?'error':autosaveBlocked?'conflict':model.dirty?'dirty':'saved';try{localStorage.setItem(savedKey(),JSON.stringify({id:model.id,version:model.version}));}catch(_){} ctx.draft_id = model.id; localSave(); overview(); status(recoveredSave?'Прежний запрос подтверждён. Явно восстановите серверный черновик перед продолжением; локальный текст остаётся отдельно.':'Черновик сохранён. Версия ' + model.version + '.', false); }
      catch (e) {
        const conflicts = ['ACS_DRAFT_VERSION_CONFLICT', 'ACS_DRAFT_ALREADY_EXISTS', 'ACS_OPERATION_KEY_CONFLICT'];
        autosaveBlocked=true;saveState=conflicts.includes(e.code)?'conflict':'error';
        if (conflicts.includes(e.code)) { model.pending = null; localSave(); overview(); status('Конфликт сохранения. Локальный текст оставлен. Восстановите серверную версию отдельной кнопкой или сохраните свой текст перед заменой.', true); }
        else { overview(); status(e.message + ' Исход запроса не подтверждён. Повторите тот же запрос или явно восстановите серверную версию.', true); }
      } finally { saving = false; overview();saveStatus();scheduleSave(); }
    }
    function renderPreview(dto) {
      part('preview').replaceChildren(node('p', dto.label + '. Сценарий: ' + ({ hit: 'попадания без крита', crit: 'критические попадания', miss: 'промахи' }[dto.scenario])));
      const list = node('ol'); dto.actions.forEach((a, i) => {
        const li = node('li'), p = a.probabilities; if (a.cover) li.append(node('p', 'Назначение укрытия проверено; реальное состояние не изменено.')); else if (a.spatial) li.append(node('p', 'Пространственное действие проверено по последовательному виртуальному состоянию; реальные позиции не изменены.')); else if (a.cooperation) li.append(node('p', 'Совместное действие: стоимость проверена. Помощь и вклад станут действительными после боевой публикации.')); else if (a.interaction) li.append(node('p', 'Взаимодействие: стоимость проверена. Бросок и исход будут определены при seal по закреплённой формуле KB.')); else if (a.operation) li.append(node('p', a.operation === 'search' ? 'Поиск: вероятность обнаружения ' + p.P_hit + '%. Бросок будет запечатан отдельно.' : (a.operation === 'share' ? 'Передача известных сведений разрешённой аудитории.' : 'Воздействие на окружение по правилам действия.'))); else if (a.item) li.append(node('p', 'Предмет #' + a.item.item_id + ': ' + (a.item.operation === 'consume' ? 'использовать' : 'сменить оружие') + ', ' + a.item.qty + ' шт., 1 AP. Эффект не зависит от сценария попадания.')); else li.append(node('p', 'Действие ' + (i + 1) + ': P попадания ' + p.P_hit + '%, P крита при попадании ' + p.P_crit + '%. Условный урон: ' + a.damage + '.'));
        a.components.forEach(c => li.append(node('p', 'Компонент ' + (c.source_index + 1) + ': ' + ({ physical: 'физический', elemental: 'стихийный', true: 'истинный' }[c.damage_type] || 'урон') + ', защита ' + c.effective_defense + ', после защиты ' + c.post_defense + ', распределено ' + c.allocated + '.')));
        li.append(node('p', 'Стоимость: ' + a.costs.map(c => c.amount + ' ' + (c.kind === 'ap' ? 'AP' : 'ресурса')).join(', '))); list.append(li);
      }); part('preview').append(list, node('h4', 'Виртуальное состояние — не сохранено'));
      dto.virtual_entities.forEach(e => part('preview').append(node('p', (ctx.actors.find(a => a.id === e.entity_id)?.name || ctx.targets.find(t => t.id === e.entity_id)?.name || 'Участник') + ': HP ' + e.hp + ' / ' + e.max_hp)));
      Object.entries(dto.virtual_groups).forEach(([id, g]) => part('preview').append(node('p', 'Группа ' + id + ': виртуальные AP ' + g.ap.current + ' / ' + g.ap.maximum)));
    }
    async function preview() {
      await ensureCurrent('before-preview');
      const revision = model.revision, snapshotEpoch = epoch; previewBusy = true; overview(); status('Рассчитывается условная последовательность без бросков…', false);
      try { const dto = await api('builder_preview', model.preview(ctx, field('scenario').value), true); if (revision === model.revision && snapshotEpoch === epoch) { renderPreview(dto); status('Условный расчёт готов. Реальные HP/AP не изменены.', false); } }
      catch (e) { if (revision === model.revision && snapshotEpoch === epoch) { part('preview').replaceChildren(node('p', e.message)); status(e.message, true); } }
      finally { previewBusy = false; overview(); }
    }
    root.addEventListener('change', async event => {
      try {
        if (event.target === field('actor')) { field('catalog-actor').value = field('actor').value; actionForm({ actor_id: field('actor').value, ability_key: '', targets: [] }); overview(); }
        else if (event.target === field('ability')) actionForm();
        else if (event.target.matches('[data-target-id]')) { const id = event.target.dataset.targetId; if (selectedChoice()?.target_mode === 'multiple') pickedTargets = event.target.checked ? [...pickedTargets.filter(t => t !== id),id] : pickedTargets.filter(t => t !== id); else pickedTargets = [id]; field('target').value = pickedTargets[0] || ''; info(); }
        else if ([field('category'),field('catalog-actor'),field('available-only')].includes(event.target)) renderCatalog();
        else if (event.target === field('scenario')) { invalidate(true); overview(); }
        else if (event.target === field('group')) {
          const turn = field('group').value;
          if (!turn || (model.dirty && !window.confirm('Перейти к другой группе и оставить несохранённые изменения? Сохраните черновик перед переходом, если он нужен.'))) { field('group').value = ctx.turn.id; return; }
          localSave();initialized=false;loading = true; overview(); await refresh(turn); model = new DraftModel();serverProse='';saveState='saved';autosaveBlocked=false;recoveredSave=false;observedVersion=''; editing = null; field('prose').value = ''; loading = false; invalidate(); actionForm(); overview(); await restore(true);if(!model.id && externalProse)externalProse('');initialized=true;localSave();saveStatus();scheduleSave();
        }
      } catch (e) { loading = false;initialized=true; status(e.message, true); overview(); }
    });
    field('search').addEventListener('input',renderCatalog);
    field('prose').addEventListener('input', () => { try { setProse(field('prose').value); overview(); } catch (e) { field('prose').value = model.prose; status(e.message, true); } });
    root.addEventListener('click', async event => {
      const button = event.target.closest('button[data-command]'); if (!button || !root.contains(button) || button.disabled) return;
      const cmd = button.dataset.command, index = Number(button.dataset.index);
      try {
        if(frozen)throw new Error('Отправка зарегистрирована. Проверяйте прежнюю операцию.');
        if (cmd === 'restore-local') root.acsBuilder.restoreLocal();
        else if (cmd === 'step') goStep(button.dataset.stepGo);
        else if (cmd === 'step-back') goStep(step - 1);
        else if (cmd === 'step-next') { if (step === 3) commitAction(); else goStep(step + 1); }
        else if (cmd === 'choose') { actionForm({actor_id:button.dataset.choiceActor,ability_key:button.dataset.choice,targets:[]}); overview(); }
        else if (cmd === 'clear-targets') { pickedTargets = []; field('target').value = ''; info(); }
        else if (cmd === 'target-up' || cmd === 'target-down' || cmd === 'target-remove') {
          let nextIndex = index;
          if (cmd === 'target-remove') { pickedTargets.splice(index,1); nextIndex = Math.min(index,pickedTargets.length - 1); }
          else { const to = index + (cmd === 'target-up' ? -1 : 1); if (to >= 0 && to < pickedTargets.length) { [pickedTargets[index],pickedTargets[to]] = [pickedTargets[to],pickedTargets[index]]; nextIndex = to; } }
          field('target').value = pickedTargets[0] || ''; info();
          const nextButton = Array.from(part('target-order').querySelectorAll('button')).find(b => b.dataset.command === 'target-remove' && Number(b.dataset.index) === nextIndex && !b.disabled);
          if (nextButton) nextButton.focus({preventScroll:true}); else root.querySelector('[data-step="3"] [data-step-heading]').focus({preventScroll:true});
        }
        else if (cmd === 'add') commitAction();
        else if (cmd === 'add-another') { editing = null; overview(); goStep(1); }
        else if (cmd === 'validate') await validateSequence(true);
        else if (cmd === 'remove' || cmd === 'up' || cmd === 'down') {
          if (cmd === 'remove') model.remove(index); else model.move(index,cmd === 'up' ? -1 : 1);
          editing = null; invalidate(true); overview();
          const nextIndex = cmd === 'remove' ? Math.min(index,model.actions.length - 1) : index + (cmd === 'up' ? -1 : 1);
          const nextButton = Array.from(part('queue').querySelectorAll('button')).find(b => b.dataset.command === 'edit' && Number(b.dataset.index) === nextIndex && !b.disabled);
          if (nextButton) nextButton.focus({preventScroll:true}); else root.querySelector('[data-step="4"] [data-step-heading]').focus({preventScroll:true});
        }
        else if (cmd === 'edit') { editing = index; actionForm(model.actions[index]); overview(); goStep(1); }
        else if (cmd === 'cancel-edit') { editing = null; overview(); goStep(4); }
        else if (cmd === 'clear-parameters') { if (window.confirm('Удалить сохранённые неподдерживаемые параметры этого действия?')) { model.update(index,{...model.actions[index],parameters:[]}); editing = null; invalidate(true); overview(); } }
        else if (cmd === 'save') await save();
        else if (cmd === 'restore') await restore(false);
        else if (cmd === 'refresh') { await refresh(); invalidate(true); status('Сведения обновлены. Локальные действия и текст сохранены.', false); }
        else if (cmd === 'preview') await preview();
        else if (cmd === 'release-prepared') await sealCommand(cmd, (ctx.prepared_actions || []).find(p => p.prepared_action_id === button.dataset.preparedId));
        else if (['seal','cancel-seal','confirm-seal','publish-prepared'].includes(cmd)) await sealCommand(cmd);
        else if (cmd === 'seal-refresh') await refreshSeal();
        else if (cmd === 'compose' || cmd === 'compose-quick') {
          if (planning(ctx) || !config.test_publication_allowed || model.dirty || model.pending || model.sealPending || !sealView?.confirmed) throw new Error('Сначала сохраните и подтвердите сводку.');
          window.location.assign((cmd === 'compose' ? 'newreply.php' : 'showthread.php') + '?tid=' + encodeURIComponent(ctx.tid) + '&acs_draft=' + encodeURIComponent(model.id));
        }
      } catch (e) { status(e.message, true); }
    });
    window.addEventListener('beforeunload', event => { if (model.dirty || model.pending || model.sealPending) { localSave(); event.preventDefault(); event.returnValue = ''; } }, listener);
    (async () => {
      try {
        // The server is the only source of restored mechanics and sealed history.
        let journal=null;try{journal=JSON.parse(sessionStorage.getItem(journalKey()));}catch(_){}
        actionForm(); overview(); if (ctx.draft_id) await restore(true);
        if(journal && journal.expires>Date.now() && journal.expires<=Date.now()+lifetime){
          model.pending=journal.pending||null;model.sealPending=journal.sealPending||null;recoveredSave=!!model.pending;
          if(model.pending || model.sealPending){autosaveBlocked=true;saveState='error';status('Есть запрос с неизвестным исходом. Повторите прежнее сохранение или seal; новый ключ не создаётся. Локальный текст восстанавливается отдельной кнопкой.',true);}
        }
        initialized=true;localSave();overview();saveStatus();root.dispatchEvent(new CustomEvent('acs:builder-ready',{bubbles:true}));
        if (model.actions.length) goStep(4,false); validateSequence();
      } catch (e) { status(e.message, true);saveState='error';autosaveBlocked=true;saveStatus(); }
    })();
  }
  function init(container = document) { container.querySelectorAll('[data-acs-builder]').forEach(root => { try { mount(root); } catch (_) { const s = root.querySelector('[data-part="status"]'); if (s) s.textContent = 'Не удалось открыть конструктор. Обновите страницу.'; } }); }
  function unmount(root) {
    mounts.get(root)?.();
    mounts.delete(root);
  }
  window.AFACSBuilder = {init, unmount};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();
