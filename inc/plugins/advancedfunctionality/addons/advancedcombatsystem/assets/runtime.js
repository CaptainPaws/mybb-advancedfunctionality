(function () {
  'use strict';
  if (window.AFACSRuntime) return;
  const endpoint = 'xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem';
  function error(dto) {
    const e = new Error((dto?.message || 'Ответ не подтверждён. Текст и действия сохранены.') + (dto?.error_id ? ' · error_id=' + dto.error_id : ''));
    e.code = dto?.code; e.category = dto?.category; e.error_id = dto?.error_id; return e;
  }
  function watch(root, scope, changed, failed) {
    const lifetime = new AbortController();
    const options = {signal: lifetime.signal};
    let controller;
    let baseline = null, baselineScope = null, inflight = null, last = 0;
    async function check(reason) {
      if (inflight) return inflight;
      const current = scope();
      if (!current.session_id || !current.tid) throw error({message:'Контекст ACS недоступен. Обновите разрешённую панель.'});
      const scopeKey=JSON.stringify(current);
      if(baselineScope!==scopeKey){baseline=null;baselineScope=scopeKey;}
      inflight = (async function () {
        const query = new URLSearchParams({...current, op:'versions'});
        controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
          const response = await fetch(endpoint + '&' + query, {credentials:'same-origin',cache:'no-store',signal:controller.signal});
          const result = await response.json(); if (!result.ok) throw error(result.error);
          if(lifetime.signal.aborted || JSON.stringify(scope())!==scopeKey)return {changed:false,data:result.data};
          const stamp = JSON.stringify([result.data.encounter_id,result.data.versions]);
          const update = baseline !== null && baseline !== stamp; baseline = stamp; last = Date.now();
          if (update) changed(result.data, reason);
          return {changed:update,data:result.data};
        } catch (e) { if (!lifetime.signal.aborted) failed?.(e); throw e; }
        finally { clearTimeout(timeout); inflight = null; }
      })(); return inflight;
    }
    function passive(reason) {
      if (!root.isConnected || document.visibilityState === 'hidden' || Date.now() - last < 500) return;
      check(reason).catch(() => {});
    }
    document.addEventListener('visibilitychange', () => {if(document.visibilityState==='visible')passive('tab-return');}, options);
    window.addEventListener('focus', () => passive('focus-return'), options);
    root.addEventListener('acs:open', () => passive('open'), options);
    root.addEventListener('toggle', event => {if(event.target.open)passive('open');}, {capture:true, ...options});
    const container=root.closest('details,dialog');
    if(container && container!==root)container.addEventListener('toggle',()=>{if(container.open)passive('open');}, options);
    return {check, reset:() => {baseline=null;}, dispose:() => { lifetime.abort(); controller?.abort(); }};
  }
  window.AFACSRuntime = {watch,error};
})();
