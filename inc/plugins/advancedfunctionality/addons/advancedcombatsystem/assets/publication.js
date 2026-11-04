(function () {
  'use strict';
  const clone = x => JSON.parse(JSON.stringify(x));
  const text = x => String(x || '').replace(/\r\n?/g, '\n');
  class PublicationModel {
    constructor() { this.submission = ''; this.status = ''; this.pendingSave = null; this.pendingPrepare = null; this.localProse = ''; }
    saveRequest(draft, prose, key) {
      if (this.submission) throw new Error('Отправка уже зарегистрирована. Проверьте её статус.');
      if (!this.pendingSave) this.pendingSave = {session_id:draft.session_id,encounter_id:draft.encounter_id,turn_id:draft.turn_id,draft_id:draft.draft_id,expected_draft_version:draft.draft_version,operation_key:key,prose:text(prose),actions:JSON.stringify(draft.actions)};
      return clone(this.pendingSave);
    }
    prepared(operation) {
      if (this.submission && this.submission !== operation.submission_id) throw new Error('Нельзя заменить идентификатор незавершённой отправки.');
      this.submission = operation.submission_id; this.status = operation.status; this.pendingPrepare = null;
    }
    canSend() { return !!this.submission && ['REGISTERED','VALIDATING','POST_CONFIRMED','MODERATION_PENDING'].includes(this.status); }
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = {PublicationModel, text};
  if (typeof document === 'undefined') return;
  const node = (tag, value) => { const n = document.createElement(tag); if (value !== undefined) n.textContent = value; return n; };
  function existingEditor(ta) {
    try { if (window.jQuery?.fn?.sceditor) {const editor=window.jQuery(ta).sceditor('instance');if(editor)return editor;} } catch (_) {}
    return ta.__afAeAdapter || null;
  }
  function mount(config) {
    const ta = [...document.querySelectorAll('textarea[name="message"]')].find(t => t.form && new URL(t.form.action, location.href).pathname.endsWith('/newreply.php'));
    if (!ta || ta.form.dataset.acsPublication) return;
    const form = ta.form; form.dataset.acsPublication = '1';
    const read = () => text(existingEditor(ta)?.val?.() ?? ta.value);
    const write = value => { const editor = existingEditor(ta); if (editor?.val) editor.val(value); ta.value = value; };
    const quick = /quick.?reply/i.test(form.id || '') || !!form.querySelector('#quick_reply_submit');
    const root = node('fieldset'); root.className = 'af-acs-publication'; root.append(node('legend', 'Публикация ACS — тестовый доступ'));
    const mode = node('select'); mode.name = 'acs_mode';
    [['combat','Боевой пост'],['narrative','Обычный литературный пост']].forEach(([v,t]) => {const o=node('option',t);o.value=v;mode.append(o);});
    const label = node('label','Режим '); label.append(mode);root.append(label);
    root.append(node('p','В боевом режиме вложения пока не поддерживаются. Текст редактируется в существующем редакторе; действия и броски берутся из серверного черновика.'));
    const summary = node('div'); summary.className = 'acs-publication-summary';
    const status = node('p','Загружается серверный черновик…');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const button = (value, fn) => {const b=node('button',value);b.type='button';b.addEventListener('click',() => Promise.resolve().then(fn).catch(e=>report(e.message)));return b;};
    const load = button('Загрузить сохранённый текст', () => {if (draft && window.confirm('Заменить текст редактора сохранённым литературным черновиком?')) {write(draft.prose);persist();}});
    const prepareButton = button('Сохранить текст и подготовить отправку', prepare);
    const statusButton = button('Проверить статус отправки', checkStatus);
    const ack = node('input');ack.type='checkbox';const ackLabel=node('label',' Подтверждаю публикацию текста с этой запечатанной сводкой');ackLabel.prepend(ack);
    const back=node('a','Вернуться к подготовке хода');back.href='showthread.php?tid='+encodeURIComponent(config.tid)+'&acs_builder=';
    root.append(status,load,summary,prepareButton,ackLabel,statusButton,back);form.append(root);
    const hidden = (name,value) => {let e=form.querySelector('input[name="'+name+'"]');if(!e){e=node('input');e.type='hidden';e.name=name;form.append(e);}e.value=value;return e;};
    const actionField=hidden('action','acs_publication');
    const opField=hidden('acs_operation','publish');const idField=hidden('acs_submission_id','');
    let model = new PublicationModel(), draft = null, busy = false, key = config.author_uid ? ['acs-publication',config.author_uid,config.tid,config.draft_id].join(':') : '', ready = false;
    const report = message => {status.textContent=message;};
    const persist = () => {model.localProse=read();if(key)try{sessionStorage.setItem(key,JSON.stringify(model));}catch(_){};};
    function refresh() {
      prepareButton.disabled = busy || !draft || !ready || !!model.submission;
      statusButton.disabled = busy || !model.submission;
      ack.disabled = busy || !model.submission || !model.canSend() || !ready;
      mode.disabled = !!model.submission || busy;
      idField.value=model.submission;
      if (model.submission) {mode.value='combat';opField.value='publish';actionField.value='acs_publication';ta.readOnly=true;try{existingEditor(ta)?.readOnly?.(true);}catch(_){};}
    }
    function showSummary(data) {
      summary.replaceChildren(node('h4','Запечатанный результат — до COMMITTED ещё не применён'));
      if (!data) {summary.append(node('p','Подтверждённая сводка недоступна. Обновите сведения в конструкторе; новые броски не выполняются.'));return;}
      const list=node('ol');(data.actions||[]).forEach((a,i)=>{const outcome={hit:'попадание',crit:'критическое попадание',miss:'промах'}[a.outcome]||'исход недоступен';const rolls=a.rolls||{};list.append(node('li','Действие '+(i+1)+': '+outcome+'; стоимость '+(a.costs||[]).map(c=>c.amount+' '+(c.kind==='ap'?'AP':'ресурса')).join(', ')+'; урон '+a.damage+'. Бросок попадания: '+(rolls.hit?.u??'—')+'; критического попадания: '+(rolls.crit?.u??'—')+(rolls.crit_used?'':' (резерв).')));});summary.append(list);
    }
    const states = {COMMITTED:'Результат подтверждён. Сообщение и боевая история сохранены.',MODERATION_PENDING:'Сообщение ожидает модерации или подтверждения после одобрения. HP/AP пока не применены.',RECOVERY_REQUIRED:'Требуется восстановление мастером. Не отправляйте новый пост вместо этой операции.',REJECTED:'Публикация отклонена. Текст и запечатанные действия сохранены. Вернитесь к черновику для новой подготовки.',REGISTERED:'Отправка подготовлена. Проверьте сводку и подтвердите отправку формы.',VALIDATING:'Публикация проверяется.',POST_CONFIRMED:'Сообщение подтверждено; боевой результат ещё не зафиксирован. Повтор использует тот же пост.',POSTING:'Результат отправки пока неизвестен. Проверяйте статус; новый INSERT не выполняется.',COMMITTING:'Результат фиксации уточняется. Проверяйте прежнюю отправку.'};
    function operation(data) {
      model.prepared(data);persist();report(states[data.status] || 'Публикация обрабатывается.');refresh();
      if(data.pid && ['COMMITTED','MODERATION_PENDING'].includes(data.status)) {const a=node('a',' Открыть сообщение');a.href='showthread.php?tid='+encodeURIComponent(config.tid)+'&pid='+encodeURIComponent(data.pid)+'#pid'+encodeURIComponent(data.pid);status.append(a);}
    }
    async function request(op, values = {}, method = 'POST') {
      const body=new URLSearchParams(values);body.set('acs_mode','combat');body.set('acs_operation',op);body.set('tid',config.tid);
      if(method==='POST')body.set('my_post_key',config.post_key);
      const url=config.endpoint+(method==='GET'?'?'+body:'');
      const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',...(method==='POST'?{body}:{})});
      const result=await response.json();if(!result.ok)throw new Error(result.error?.message || 'Запрос не подтверждён. Сохраните текст и проверьте прежний submission_id.');return result.data;
    }
    function noAttachments() {
      for(const input of form.querySelectorAll('input[type="file"]'))if(input.files?.length)throw new Error('Вложения в боевом режиме пока не поддерживаются. Уберите файлы; текст и действия сохранятся.');
      for(const name of ['attachmentaid','attachmentact'])if(form.elements.namedItem(name)?.value)throw new Error('Операции с вложениями не поддерживаются в боевом режиме.');
    }
    async function prepare() {
      if(busy || model.submission || !draft) return;noAttachments();busy=true;persist();refresh();
      try {
        if(!model.pendingPrepare) {
          const prose=read();const save=model.saveRequest(draft,prose,crypto.randomUUID().replace(/-/g,''));persist();
          const body=new URLSearchParams(save);body.set('my_post_key',config.post_key);
          const response=await fetch(config.draft_endpoint,{method:'POST',body,credentials:'same-origin',cache:'no-store'});const result=await response.json();
          if(!result.ok)throw new Error(result.error?.message || 'Сохранение не подтверждено. Повторите подготовку с прежним ключом.');
          draft.draft_version=result.data.draft_version;draft.prose=save.prose;model.pendingSave=null;persist();
          if(read()!==save.prose)throw new Error('Сохранённый запрос подтверждён, но локальный текст изменился. Подготовьте его ещё раз.');
          model.pendingPrepare={acs_draft_id:draft.draft_id,acs_draft_version:draft.draft_version,acs_confirmation_hash:draft.confirmation_hash,message:save.prose};persist();
        }
        const data=await request('prepare',model.pendingPrepare);if(data.summary){ready=true;showSummary(data.summary);}operation(data.operation);
      } finally {busy=false;refresh();}
    }
    async function checkStatus() {if(busy || !model.submission)return;busy=true;refresh();try{operation(await request('status',{acs_submission_id:model.submission},'GET'));}finally{busy=false;refresh();}}
    async function send() {
      if(busy)return;
      if(!model.canSend() || !ack.checked || !ready)throw new Error('Сначала подготовьте отправку и подтвердите запечатанную сводку. Для незавершённой операции проверяйте прежний статус.');
      noAttachments();busy=true;persist();refresh();report('Отправка выполняется… Текст и прежний submission_id сохранены.');
      try {
        existingEditor(ta)?.updateOriginal?.();const body=new URLSearchParams();
        for(const [name,value] of new FormData(form))if(typeof value==='string')body.append(name,value);
        body.set('action','acs_publication');body.set('acs_submission_id',model.submission);body.set('message',read());if(quick)body.set('method','quickreply');
        operation(await request('publish',Object.fromEntries(body)));
      } catch(e) {model.status='UNKNOWN';persist();report(e.message+' Исход не подтверждён: проверьте статус этой отправки. Новый submission_id не создаётся.');}
      finally {busy=false;refresh();}
    }
    mode.addEventListener('change',()=>{opField.value=mode.value==='combat'?'publish':'';actionField.value=mode.value==='combat'?'acs_publication':'do_newreply';});
    const sendButton=b=>!b || b.name==='submit' || b.id==='quick_reply_submit';
    document.addEventListener('submit',e=>{if(e.target!==form || mode.value!=='combat')return;e.preventDefault();e.stopImmediatePropagation();if(!sendButton(e.submitter)){report('Предпросмотр, сохранение MyBB-черновика и вложения не отправляют боевой пост. Используйте кнопку отправки ответа.');return;}send().catch(e=>report(e.message));},true);
    document.addEventListener('click',e=>{const b=e.target.closest('button,input[type="submit"]');if(!b || (b.form||b.closest('form'))!==form || mode.value!=='combat' || (b.type!=='submit' && b.id!=='quick_reply_submit'))return;e.preventDefault();e.stopImmediatePropagation();if(!sendButton(b)){report('Эта команда недоступна в боевом режиме. Текст и действия сохранены.');return;}send().catch(e=>report(e.message));},true);
    ta.addEventListener('input',persist);window.addEventListener('beforeunload',persist);document.addEventListener('visibilitychange',persist);
    if(key)try{const cached=JSON.parse(sessionStorage.getItem(key));if(cached){Object.assign(model,cached);if(!read()&&cached.localProse)write(cached.localProse);}}catch(_){}
    refresh();
    (async()=>{try{
      draft=await request('compose',{acs_draft_id:config.draft_id},'GET');key=['acs-publication',draft.author_uid,config.tid,config.draft_id].join(':');
      let saved=null;try{saved=JSON.parse(sessionStorage.getItem(key));}catch(_){}
      if(saved && typeof saved==='object')Object.assign(model,saved);
      if(!read())write(saved?.localProse || draft.prose);else if(saved?.localProse && saved.localProse!==read())report('В редакторе есть текст. Локальная копия сохранена отдельно; автоматическая замена не выполнена.');
      back.href+=encodeURIComponent(draft.session_id);ready=!!draft.confirmed;showSummary(ready?draft.summary:null);
      if(draft.operation)operation(draft.operation);else if(model.submission)await checkStatus();else report(ready?'Текст можно отредактировать в этом редакторе, затем сохранить и подготовить отправку.':'Сначала подтвердите сводку в конструкторе.');
      try{existingEditor(ta)?.bind?.('valuechanged',persist);}catch(_){}
    }catch(e){report(e.message);}finally{refresh();}})();
  }
  function init(){for(const tag of document.querySelectorAll('[data-acs-publication-config]')){try{mount(JSON.parse(tag.textContent));}catch(_){/* Narrative form remains native. */}}}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
