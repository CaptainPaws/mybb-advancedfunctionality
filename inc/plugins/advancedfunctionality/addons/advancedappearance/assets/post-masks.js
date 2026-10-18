'use strict';
(() => {
 const seedNode=document.getElementById('af-aa-mask-seed');if(!seedNode||window.afAppearanceMasks)return;
 const seed=JSON.parse(seedNode.textContent),states=new WeakMap(),quickStates=new Map(),quickRetries=new Map(),refreshed=new WeakSet(),pendingRefresh=new Map();
 function number(value){return /^\d+$/.test(String(value||''))?Number(value):0;}
 function context(ta){const form=ta.form,q=new URLSearchParams(location.search),find=name=>number(form?.querySelector('[name="'+name+'"]')?.value);
  const quick=String(ta.id||'').match(/^quickedit_(\d+)$/),pid=quick?Number(quick[1]):find('pid')||(/editpost\.php$/.test(location.pathname)?number(q.get('pid')):0);
  return {pid,tid:find('tid')||number(q.get('tid')),fid:find('fid')||number(q.get('fid')),quick:!!quick};}
 function draftKey(c){return 'af-aa-mask:v1:'+seed.uid+':'+(c.pid?'post:'+c.pid:c.tid?'reply:'+c.tid:'thread:'+c.fid);}
 function attach(ta){if(!ta?.form||(!/^quickedit_\d+$/.test(ta.id||'')&&ta.name!=='message'))return null;if(states.has(ta))return states.get(ta);
  const ctx=context(ta);let input=ta.form.querySelector('input[name="af_aa_mask"]');if(!input){input=document.createElement('input');input.type='hidden';input.name='af_aa_mask';ta.form.append(input);}
  const state={ta,input,ctx,key:draftKey(ctx)};states.set(ta,state);if(ctx.quick){quickStates.set(ctx.pid,state);const retry=quickRetries.get(ctx.pid);if(retry&&Date.now()-retry.at<300000){input.value=retry.value;state.retry=true;}else quickRetries.delete(ctx.pid);}
  // Posted data wins over a local reserve. Quick Edit cancellation never leaves a reserve.
  if(!ctx.quick&&!input.value){input.value=seed.pending||'';if(!input.value)try{input.value=localStorage.getItem(state.key)||'';}catch(_){}}
  if(!ctx.quick)ta.form.addEventListener('submit',()=>{try{if(input.value){localStorage.setItem(state.key,input.value);sessionStorage.setItem(state.key+':pending',location.href);}}catch(_){}});
  return state;}
 async function api(operation,c,values={}){const response=await fetch(seed.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({operation,my_post_key:seed.csrf,pid:c.pid,tid:c.tid,fid:c.fid,...values})});const value=await response.json();if(!response.ok||!value.ok)throw new Error(value.error||'Маска недоступна');return value.data;}
 function choose(state,value){state.input.value=JSON.stringify(value);if(!state.ctx.quick)try{localStorage.setItem(state.key,state.input.value);}catch(_){}state.ta.form.dispatchEvent(new CustomEvent('af:mask-changed',{bubbles:true,detail:{pid:state.ctx.pid}}));}
 function clearReserve(state){state.input.value='';try{localStorage.removeItem(state.key);sessionStorage.removeItem(state.key+':pending');}catch(_){}}
 function notice(root,message){let el=root.querySelector('.af-aa-mask-notice');if(!el){el=document.createElement('p');el.className='af-aa-mask-notice';el.setAttribute('role','status');root.append(el);}el.textContent=message;}
 async function refresh(pid){if(pendingRefresh.has(pid))return pendingRefresh.get(pid);const task=(async()=>{const root=document.getElementById('post_'+pid);if(!root||number(root.dataset.authorUid)!==seed.uid)return;const data=await api('runtime',{pid,tid:0,fid:0});if(!root.isConnected)return;
   root.classList.remove('af-aa-postbit-user-'+seed.uid,'af-aa-postbit-mask-'+pid);root.classList.add(data.masked?'af-aa-postbit-mask-'+pid:'af-aa-postbit-user-'+seed.uid);
   const slot=root.querySelector('.atf-post__slot--before');if(!slot)return;
   root.querySelectorAll('[data-af-aa-mask-css],[data-af-appearance-post-effects-config]').forEach(n=>n.remove());
   const style=document.createElement('style');style.dataset.afAaMaskCss=String(pid);style.textContent=data.css;slot.append(style);
   const config=document.createElement('script');config.type='application/json';config.setAttribute('data-af-appearance-post-effects-config','');config.textContent=JSON.stringify(data.canvas);slot.append(config);
   // Keep unchanged avatar nodes (and their listeners); never rebuild the rest of the sidebar.
   for(const [selector,html] of [['.atf-post__primary-avatar-media',data.topbar_html],['.atf-post__secondary-avatar',data.sidebar_html]]){
    const avatar=root.querySelector(selector);if(!avatar||typeof html!=='string')continue;
    const template=document.createElement('template');template.innerHTML=html;
    if(avatar.innerHTML!==template.innerHTML)avatar.replaceChildren(template.content);
   }
   window.afElementEffects?.refresh();
  })().finally(()=>pendingRefresh.delete(pid));pendingRefresh.set(pid,task);return task;}
 function transport(){const jq=window.jQuery;if(!jq?.ajaxPrefilter||jq.__afAppearanceMasks)return;jq.__afAppearanceMasks=true;
  jq.ajaxPrefilter((options,original,xhr)=>{let url;try{url=new URL(options.url,location.href);}catch(_){return;}
   if(url.origin!==location.origin||String(options.type||'GET').toUpperCase()!=='POST')return;
   const quickEdit=/\/xmlhttp\.php$/.test(url.pathname)&&url.searchParams.get('action')==='edit_post'&&url.searchParams.get('do')==='update_post';
   const quickReply=/\/newreply\.php$/.test(url.pathname)&&url.searchParams.get('ajax')==='1';if(!quickEdit&&!quickReply)return;
   const pid=quickEdit?number(url.searchParams.get('pid')):0,ta=quickEdit?document.getElementById('quickedit_'+pid):document.querySelector('#quick_reply_form textarea[name="message"]'),state=attach(ta)||(quickEdit?quickStates.get(pid):null);
   if(quickEdit&&state)state.submitting=true;
   const selected=state?.input.value||'';
   if(quickEdit&&state?.input.value){if(typeof options.data==='string')options.data+='&af_aa_mask='+encodeURIComponent(state.input.value);else options.data={...options.data,af_aa_mask:state.input.value};}
   xhr.done(data=>{let body=data;try{if(typeof body==='string')body=JSON.parse(body);}catch(_){return;}if(!body)return;if(body.errors||body.error){if(quickEdit&&selected)quickRetries.set(pid,{value:selected,at:Date.now()});return;}if(body.moderation_post||body.moderation_thread)return;if(quickEdit)quickRetries.delete(pid);
    if(quickEdit&&typeof body.message==='string')setTimeout(()=>{refresh(pid).catch(e=>{const root=document.getElementById('post_'+pid);if(root)notice(root,'Сообщение сохранено. Не удалось обновить маску: '+e.message);});},0);
    if(quickReply&&typeof body.data==='string'&&state)setTimeout(()=>{if(!state.ta.value.trim())clearReserve(state);},0);
   });
   xhr.fail(()=>{if(quickEdit&&selected)quickRetries.set(pid,{value:selected,at:Date.now()});});
  });}
 function added(root){if(!(root instanceof Element))return;const posts=root.matches('.atf-post')?[root]:Array.from(root.querySelectorAll('.atf-post'));
  posts.forEach(post=>{if(refreshed.has(post))return;refreshed.add(post);const pid=number(post.id.replace(/^post_/,''));if(pid&&number(post.dataset.authorUid)===seed.uid&&!post.querySelector('[data-af-aa-mask-css]'))refresh(pid).catch(()=>{});});}
 document.addEventListener('af:editor-ready',event=>attach(event.detail?.textarea));
 document.addEventListener('af:editor-destroyed',event=>{const ta=event.detail?.textarea,state=states.get(ta);if(state?.ctx.quick){if(state.retry&&!state.submitting)quickRetries.delete(state.ctx.pid);state.input.remove();states.delete(ta);if(quickStates.get(state.ctx.pid)===state)quickStates.delete(state.ctx.pid);}});
 function boot(){document.querySelectorAll('.af-ae-shell').forEach(shell=>attach(shell.__afAeTextarea));document.querySelectorAll('.atf-post').forEach(p=>refreshed.add(p));transport();
  // The Editor only clears a draft after a confirmed redirect; use the same confirmation for its mask reserve.
  if(/showthread\.php$/.test(location.pathname)&&/^(#pid_?\d+|#post\d+)$/i.test(location.hash))document.querySelectorAll('textarea[name="message"]').forEach(ta=>{const state=attach(ta);try{if(state&&!ta.value.trim()&&sessionStorage.getItem(state.key+':pending')&&sessionStorage.getItem(state.key+':pending')!==location.href&&!document.querySelector('.error,.error_message,.alert--error'))clearReserve(state);}catch(_){}});
  new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(added))).observe(document.body,{subtree:true,childList:true});}
 window.afAppearanceMasks={attach,api,choose,refresh,seed,notice};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
