(function () {
  'use strict';
  const clone = x => JSON.parse(JSON.stringify(x));
  const text = x => String(x || '').replace(/\r\n?/g, '\n');
  class PublicationModel {
    constructor() { this.submission = ''; this.status = ''; this.pendingSave = null; this.pendingPrepare = null; }
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
    const write = value => { const editor=existingEditor(ta);if(editor?.val)editor.val(value);ta.value=value; };
    const quick = /quick.?reply/i.test(form.id || '') || !!form.querySelector('#quick_reply_submit');
    const root=node('fieldset');root.className='af-acs-publication';root.append(node('legend','Режим сообщения'));
    const mode=node('select');mode.name='acs_mode';
    [['narrative','Литературный пост'],['combat','Боевой пост']].forEach(([v,t])=>{const o=node('option',t);o.value=v;mode.append(o);});mode.value='combat';
    const label=node('label','Режим ');label.append(mode);root.append(label);
    const explanation=node('p');root.append(explanation);
    const autosave=node('p');autosave.setAttribute('role','status');autosave.setAttribute('aria-live','polite');root.append(autosave);
    const combat=node('div'), details=node('details');details.open=true;details.append(node('summary','Конструктор действий'));
    const summary=node('div');summary.className='acs-publication-summary';
    const status=node('p','Загрузка черновика…');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const recovery=node('section');recovery.hidden=true;recovery.setAttribute('aria-label','Состояние восстановления публикации');recovery.tabIndex=-1;
    recovery.append(node('h4','Отправка требует проверки'),node('p','Неизвестный исход не означает отказ. Используется прежняя операция; новый пост и новые броски не создаются.'));
    const identity=node('p');recovery.append(identity);
    const report=message=>{status.textContent=message;};
    const button=(value,fn)=>{const b=node('button',value);b.type='button';b.addEventListener('click',()=>Promise.resolve().then(fn).catch(e=>report(e.message)));return b;};
    const localButton=button('Восстановить локальный литературный текст (до 24 часов)',()=>bridge?.restoreLocal());root.append(localButton);
    const prepareButton=button('Подготовить отправку подтверждённого хода',prepare);
    const statusButton=button('Проверить прежнюю операцию',checkStatus);recovery.append(statusButton);
    const retryPrepare=button('Повторить прежнюю подготовку',prepare);
    const returnDraft=button('Вернуться к черновику после подтверждённого отказа',()=>{if(!rejectedVerified || busy)return;const sid=bridge?.state().session_id || draft?.session_id;if(!sid)return;if(!window.confirm('Вернуться к серверному черновику? Запечатанные броски сохранятся. Новая отправка потребует отдельной подготовки и подтверждения.'))return;try{sessionStorage.removeItem(key());}catch(_){}window.location.assign('showthread.php?tid='+encodeURIComponent(config.tid)+'&acs_builder='+encodeURIComponent(sid));});
    const ack=node('input');ack.type='checkbox';const ackLabel=node('label',' Подтверждаю отправку этого текста с запечатанной сводкой');ackLabel.prepend(ack);
    combat.append(details,status,summary,prepareButton,retryPrepare,ackLabel,recovery,returnDraft);root.append(combat);
    // Reparent existing nodes, never create or destroy an editor or form. Collapse only hides the same Builder.
    const container=existingEditor(ta)?.getContainer?.();
    const host=container?.jquery?container[0]:container;
    const shell=ta.__afAeShell || (host?.nodeType===1 && form.contains(host) && host!==form?host:ta);
    shell.before(root);
    let builder=document.querySelector('[data-acs-builder]'), bridge=null, model=new PublicationModel(), busy=false, ready=false, connected=false, syncing=false, draft=null,rejectedVerified=false;
    let draftId=String(config.draft_id || ''), frozenProse='', boundEditor=null, scope='';
    const lifetime=24*60*60*1000;
    const key=()=>['acs-publication-v2',config.author_uid,config.tid,draftId].join(':');
    const persist=()=>{if(!draftId)return;try{sessionStorage.setItem(key(),JSON.stringify({submission:model.submission,status:model.status,pendingPrepare:model.pendingPrepare,frozenProse,expires:Date.now()+lifetime}));}catch(_){};};
    const restoreJournal=()=>{if(!draftId)return;try{const v=JSON.parse(sessionStorage.getItem(key()));if(v && v.expires>Date.now() && v.expires<=Date.now()+lifetime){if(typeof v.submission==='string' && /^[a-f0-9]{32}$/.test(v.submission))model.submission=v.submission;if(typeof v.status==='string')model.status=v.status;if(v.pendingPrepare && String(v.pendingPrepare.acs_draft_id)===draftId)model.pendingPrepare=v.pendingPrepare;frozenProse=typeof v.frozenProse==='string'?v.frozenProse:'';}else sessionStorage.removeItem(key());sessionStorage.removeItem(['acs-publication',config.author_uid,config.tid,draftId].join(':'));}catch(_){};};
    const hidden=(name,value)=>{let e=form.querySelector('input[name="'+name+'"]');if(!e){e=node('input');e.type='hidden';e.name=name;form.append(e);}e.value=value;return e;};
    const originalAction=form.elements.namedItem('action')?.value || 'do_newreply';
    const actionField=hidden('action',originalAction),opField=hidden('acs_operation',''),idField=hidden('acs_submission_id','');
    const uncertain=()=>!!model.pendingPrepare || ['UNKNOWN','POSTING','COMMITTING','RECOVERY_REQUIRED'].includes(model.status);
    function refresh() {
      const state=bridge?.state();
      const nextScope=state?[state.session_id,state.turn_id].join(':'):'';
      if(scope && nextScope && scope!==nextScope && !model.submission && !model.pendingPrepare){model=new PublicationModel();draft=null;draftId=state.id||'';ready=false;ack.checked=false;summary.replaceChildren();}
      if(nextScope)scope=nextScope;
      if(state?.id && state.id!==draftId && !model.submission && !model.pendingPrepare){draftId=state.id;restoreJournal();}
      const saveLabels={dirty:'Есть несохранённые изменения',saving:'Сохранение…',saved:'Сохранено',error:'Ошибка сохранения',conflict:'Конфликт другой вкладки'};
      const saveText=state?saveLabels[state.saveState] || 'Черновик недоступен':'Загрузка черновика…';if(autosave.textContent!==saveText)autosave.textContent=saveText;
      localButton.hidden=!state?.backupAvailable;localButton.disabled=busy || !!model.submission || !!model.pendingPrepare;
      const frozen=busy || !!model.submission || !!model.pendingPrepare;
      if(bridge)bridge.freeze(frozen);
      const combatMode=mode.value==='combat';combat.hidden=!combatMode;
      explanation.textContent=combatMode ? (config.can_publish?'Действия и литературный текст войдут в одно сообщение. До COMMITTED расходы не применены.':'Конструктор и черновик доступны. Боевая публикация заблокирована действующими capability gates.') : 'Обычный AdvancedEditor и правила MyBB. ACS-механика и состояние хода не изменяются. Боевой черновик сохранён отдельно.';
      prepareButton.disabled=busy || !config.can_publish || !state?.ready || state.pending || ['error','conflict'].includes(state.saveState) || !state.confirmed || state.blocked || !!model.submission || !!model.pendingPrepare;
      returnDraft.hidden=!rejectedVerified;returnDraft.disabled=busy;
      retryPrepare.hidden=!model.pendingPrepare;retryPrepare.disabled=busy || !config.can_publish;
      statusButton.disabled=busy || !config.can_publish || (!model.submission && !draftId);
      ack.disabled=busy || !ready || !model.canSend() || !config.can_publish;
      mode.disabled=frozen;
      idField.value=model.submission;opField.value=combatMode?'publish':'';actionField.value=combatMode?'acs_publication':originalAction;
      // Frozen only after issue/unknown issue; seal alone never freezes literary editing.
      ta.readOnly=frozen;try{existingEditor(ta)?.readOnly?.(frozen);}catch(_){}
      recovery.hidden=!model.submission && !model.pendingPrepare;
      identity.textContent=model.submission?'Операция: '+model.submission:'Идентификатор ещё не подтверждён. Проверка ищет операцию этого черновика.';
      recovery.classList.toggle('acs-recovery-required',uncertain());
    }
    function showSummary(data) {
      summary.replaceChildren();if(!data)return;
      summary.append(node('h4','Серверная запечатанная сводка — до COMMITTED ещё не применена'));
      const list=node('ol');(data.actions||[]).forEach((a,i)=>{
        const li=node('li'),action=draft?.actions?.[i] || {actor_id:a.actor_id,ability_key:a.ability_key,targets:a.target_id?[a.target_id]:[]};
        const description=bridge?.describe(action),actorName=description?.actor || 'Исполнитель';
        const names=description?.targets || ['Разрешённые сервером цели'];
        li.append(node('p',(i+1)+'. '+actorName+' → '+names.join(', ')+' · '+(description?.ability || 'Действие')+' · '+({hit:'попадание',crit:'критическое попадание',miss:'промах'}[a.outcome] || 'серверный результат')));
        li.append(node('p','Расходы: '+(a.costs||[]).map(c=>c.amount+' '+(c.kind==='ap'?'AP':c.resource||'ресурса')).join(', ')+(a.damage===undefined?'':' · предварительный урон: '+a.damage)));
        if(a.rolls?.hit)li.append(node('p','Запечатанные значения: попадание '+a.rolls.hit.u+'; крит '+(a.rolls.crit?.u ?? '—')+'.'));
        list.append(li);
      });summary.append(list,node('p','Сводка подтверждается явно. Изменение зависимостей требует повторной проверки на тех же бросках.'));
    }
    const states={COMMITTED:'Результат подтверждён сервером. Сообщение и боевая история сохранены.',MODERATION_PENDING:'Сообщение ожидает модерации. HP/AP ещё не применены.',RECOVERY_REQUIRED:'Требуется восстановление мастером. Только проверка прежней операции; новый POST не выполняется.',REJECTED:'Публикация отклонена. Текст и запечатанные действия сохранены. Вернитесь к черновику для новой явной подготовки.',REGISTERED:'Отправка зарегистрирована. Подтвердите текст со сводкой и отправьте существующую форму.',VALIDATING:'Публикация проверяется.',POST_CONFIRMED:'Пост подтверждён. Боевая фиксация ещё не подтверждена; повтор использует прежний пост.',POSTING:'Исход создания поста уточняется. Только проверка статуса.',COMMITTING:'Исход фиксации уточняется. Только проверка статуса.'};
    function operation(data) {
      if(!data || !/^[a-f0-9]{32}$/.test(data.submission_id || ''))throw new Error('Сервер не подтвердил идентификатор операции.');
      if(data.status==='COMMITTED' && data.mechanics_confirmed!==true)throw new Error('Сервер не подтвердил боевую фиксацию. Проверяйте прежнюю операцию.');
      rejectedVerified=data.status==='REJECTED';model.prepared(data);persist();report(states[data.status] || 'Публикация обрабатывается. Проверяйте прежнюю операцию.');refresh();
      if(data.pid && ['COMMITTED','MODERATION_PENDING'].includes(data.status)){const a=node('a',' Открыть сообщение');a.href='showthread.php?tid='+encodeURIComponent(config.tid)+'&pid='+encodeURIComponent(data.pid)+'#pid'+encodeURIComponent(data.pid);status.append(a);}
      if(data.status==='RECOVERY_REQUIRED'){recovery.hidden=false;recovery.focus({preventScroll:true});}
    }
    async function request(op,values={},method='POST') {
      const body=new URLSearchParams(values);body.set('acs_mode','combat');body.set('acs_operation',op);body.set('tid',config.tid);if(method==='POST')body.set('my_post_key',config.post_key);
      const response=await fetch(config.endpoint+(method==='GET'?'?'+body:''),{method,credentials:'same-origin',cache:'no-store',...(method==='POST'?{body}:{})});
      let result;try{result=await response.json();}catch(_){throw new Error('Ответ сервера не подтверждён. Выполните проверку прежней операции.');}
      if(!result.ok){const e=new Error((result.error?.message || 'Запрос не подтверждён.')+(result.error?.error_id?' · error_id='+result.error.error_id:''));e.code=result.error?.code;throw e;}return result.data;
    }
    function noAttachments() {
      for(const input of form.querySelectorAll('input[type="file"]'))if(input.files?.length)throw new Error('Вложения в боевом режиме пока не поддерживаются. Уберите файлы; текст и действия сохранятся.');
      for(const name of ['attachmentaid','attachmentact'])if(form.elements.namedItem(name)?.value)throw new Error('Операции с вложениями не поддерживаются в боевом режиме.');
    }
    async function prepare() {
      if(busy || model.submission || !bridge || !config.can_publish)return;noAttachments();
      // Set the lock synchronously before the first await: repeated clicks cannot register a second request.
      busy=true;ack.checked=false;report('Сохранение текста и проверка подтверждённой сводки…');
      try {
        if(!model.pendingPrepare){
          bridge.prose(read());await bridge.save();draftId=bridge.state().id;refresh();
          draft=await request('compose',{acs_draft_id:draftId},'GET');
          if(draft.operation){operation(draft.operation);return;}
          if(!draft.confirmed)throw new Error('Подтвердите актуальную сводку в конструкторе. Новые броски не выполнялись.');
          if(read()!==text(draft.prose))throw new Error('Текст изменился во время сохранения. Сохраните актуальный текст перед подготовкой.');
          frozenProse=text(draft.prose);model.pendingPrepare={acs_draft_id:draftId,acs_draft_version:draft.draft_version,acs_confirmation_hash:draft.confirmation_hash,message:frozenProse};persist();
        }
        refresh();report('Регистрация отправки. Сохраняется прежний запрос…');
        const data=await request('prepare',model.pendingPrepare);ready=!!data.summary;showSummary(data.summary);operation(data.operation);
      } catch(e){report(e.message+(model.pendingPrepare?' Исход подготовки неизвестен: проверьте прежнюю операцию или повторите именно прежнюю подготовку.':''));}
      finally{busy=false;refresh();}
    }
    async function checkStatus() {
      if(busy || !config.can_publish || !draftId)return;busy=true;refresh();report('Проверяется прежняя операция — без публикационного POST…');
      try {
        if(model.submission)operation(await request('status',{acs_submission_id:model.submission},'GET'));
        else {const data=await request('compose',{acs_draft_id:draftId},'GET');draft=data;ready=!!data.confirmed;showSummary(data.summary);if(data.operation){if(!frozenProse)frozenProse=text(data.prose);operation(data.operation);}else report('Операция пока не найдена. При неизвестной подготовке разрешён только повтор прежнего запроса, без нового ключа.');}
      }finally{busy=false;refresh();}
    }
    async function send() {
      if(busy)return;
      if(!config.can_publish || !model.canSend() || !ack.checked || !ready)throw new Error('Подготовьте и явно подтвердите отправку. При неизвестном исходе проверяйте прежнюю операцию.');
      if(read()!==frozenProse)throw new Error('Текст зарегистрированной отправки отличается. Сохраните новый текст отдельно и проверьте прежнюю операцию; новый пост не отправлен.');
      noAttachments();busy=true;persist();refresh();report('Отправка выполняется… Ожидается подтверждение сервером.');
      try {
        existingEditor(ta)?.updateOriginal?.();const body=new URLSearchParams();for(const [name,value] of new FormData(form))if(typeof value==='string')body.append(name,value);
        body.set('action','acs_publication');body.set('acs_submission_id',model.submission);body.set('message',frozenProse);if(quick)body.set('method','quickreply');
        operation(await request('publish',Object.fromEntries(body)));
      }catch(e){model.status='UNKNOWN';persist();report(e.message+' Исход не подтверждён. Только проверка этой отправки; новый submission_id не создаётся.');}
      finally{busy=false;refresh();}
    }
    function changed(){if(syncing || !connected || model.submission || model.pendingPrepare)return;const value=read();if(value!==bridge.state().prose)bridge.prose(value);}
    function bindEditor(){
      const editor=existingEditor(ta);if(!editor || editor===boundEditor)return;boundEditor=editor;
      try{editor.bind?.('valuechanged',changed);
        for(let surface of [editor.getBody?.(),editor.getSourceEditor?.()]){
          if(surface?.jquery)surface=surface[0];if(!surface || surface===ta || !surface.addEventListener)continue;
          surface.addEventListener('input',changed);
          surface.addEventListener('compositionstart',()=>builder?.dispatchEvent(new Event('acs:compositionstart')));
          surface.addEventListener('compositionend',()=>{builder?.dispatchEvent(new Event('acs:compositionend'));changed();});
        }
      }catch(_){}
    }
    function connect(){
      if(connected || !builder?.acsBuilder?.state().ready)return;
      connected=true;bridge=builder.acsBuilder;
      const current=read();bridge.attach(value=>{syncing=true;try{write(value);}finally{syncing=false;}});
      if(current)bridge.prose(current);else{syncing=true;try{write(bridge.state().prose);}finally{syncing=false;}}
      draftId=bridge.state().id || draftId;restoreJournal();bindEditor();refresh();
      if(config.can_publish && draftId)loadCompose();else report('Черновик сохраняется с контролем версии. Публикация остаётся заблокированной.');
    }
    async function loadCompose(){
      const requestedId=draftId,requestedScope=scope;
      try{const data=await request('compose',{acs_draft_id:requestedId},'GET');if(draftId!==requestedId || scope!==requestedScope)return;draft=data;ready=!!draft.confirmed;showSummary(draft.summary);
        if(draft.operation){if(!frozenProse)frozenProse=text(draft.prose);operation(draft.operation);}else if(model.submission)await checkStatus();else report(ready?'Сводка подтверждена. Текст остаётся редактируемым до регистрации отправки.':'Соберите действия, сохраните, запечатайте и явно подтвердите сводку.');
      }catch(e){report(e.message);}finally{refresh();}
    }
    if(builder){details.append(builder);builder.addEventListener('acs:builder-ready',connect);builder.addEventListener('acs:builder-state',()=>{if(connected){ack.checked=false;refresh();}});connect();}
    else{details.hidden=true;restoreJournal();if(config.can_publish && draftId)loadCompose();else{mode.value='narrative';report('Конструктор недоступен в текущем состоянии. Сохраните текст и вернитесь к теме.');}}
    document.addEventListener('af:editor-ready',event=>{if(event.detail?.textarea===ta){bindEditor();refresh();}});
    ta.addEventListener('input',changed);ta.addEventListener('compositionstart',()=>{if(builder)builder.dispatchEvent(new Event('acs:compositionstart'));});ta.addEventListener('compositionend',()=>{if(builder)builder.dispatchEvent(new Event('acs:compositionend'));changed();});
    // Fallback for an editor initialized by another deferred script; no polling, init or remount.
    form.addEventListener('focusin',bindEditor);
    mode.addEventListener('change',()=>{changed();refresh();});
    const sendButton=b=>!b || b.name==='submit' || b.id==='quick_reply_submit';
    document.addEventListener('submit',e=>{if(e.target!==form || mode.value!=='combat')return;e.preventDefault();e.stopImmediatePropagation();if(!sendButton(e.submitter)){report('Предпросмотр и черновики MyBB не отправляют боевой пост.');return;}send().catch(e=>report(e.message));},true);
    document.addEventListener('click',e=>{const b=e.target.closest('button,input[type="submit"]');if(!b || (b.form||b.closest('form'))!==form || mode.value!=='combat' || (b.type!=='submit' && b.id!=='quick_reply_submit'))return;e.preventDefault();e.stopImmediatePropagation();if(!sendButton(b)){report('Эта команда не отправляет боевой пост. Текст и действия сохранены.');return;}send().catch(e=>report(e.message));},true);
    window.addEventListener('beforeunload',()=>{changed();persist();});
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden'){changed();persist();}});
    refresh();
  }
  function init(){for(const tag of document.querySelectorAll('[data-acs-publication-config]')){try{mount(JSON.parse(tag.textContent));}catch(_){/* Native narrative form remains available; no editor is replaced. */}}}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
