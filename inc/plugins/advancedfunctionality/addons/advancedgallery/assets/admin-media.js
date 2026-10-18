(() => {
  'use strict';
  const root = document.querySelector('[data-ag-admin]');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  const feedback = root.querySelector('[data-ag-feedback]');
  let busy = false;
  const say = message => { feedback.textContent = message; };
  const setBusy = value => {
    busy = value;
    root.setAttribute('aria-busy', String(value));
    root.querySelectorAll('button').forEach(button => { button.disabled = value && !button.matches('[data-ag-stop]'); });
  };
  const request = async (op, values = {}) => {
    const body = new URLSearchParams({ ag_op: op, my_post_key: root.dataset.postKey });
    for (const [key, value] of Object.entries(values)) {
      if (Array.isArray(value)) value.forEach(item => body.append(`${key}[]`, String(item)));
      else body.set(key, String(value));
    }
    const response = await fetch(root.dataset.endpoint, { method: 'POST', body, credentials: 'same-origin' });
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    const envelope = doc.querySelector('[data-ag-admin-result]');
    if (!response.ok || !envelope) throw new Error('Ответ ACP недоступен. Проверьте сессию и обновите страницу.');
    const data = JSON.parse(envelope.textContent);
    if (!data.ok) throw new Error(data.error || 'Не удалось выполнить действие.');
    return data;
  };
  const run = async callback => {
    if (busy) return;
    setBusy(true);
    try { await callback(); } catch (error) { say(error.message || 'Ошибка запроса.'); }
    finally { setBusy(false); }
  };
  const reload = message => {
    try { sessionStorage.setItem('ag-admin-feedback', message); } catch (_) { /* Storage may be disabled. */ }
    location.reload();
  };
  try {
    const prior = sessionStorage.getItem('ag-admin-feedback');
    if (prior) { say(prior); sessionStorage.removeItem('ag-admin-feedback'); }
  } catch (_) { /* Storage may be disabled. */ }
  const cards = () => [...root.querySelectorAll('[data-media-id]')];
  const count = () => {
    const output = root.querySelector('[data-ag-selected]');
    if (output) output.textContent = `Выбрано: ${root.querySelectorAll('[data-ag-choice]:checked').length}`;
  };
  root.addEventListener('change', event => { if (event.target.matches('[data-ag-choice]')) count(); });
  root.querySelectorAll('[data-ag-days]').forEach(button => button.addEventListener('click', () => {
    const form = button.closest('form');
    form.elements.inactive_days.value = button.dataset.agDays;
    form.requestSubmit();
  }));
  root.querySelectorAll('[data-ag-select]').forEach(button => button.addEventListener('click', () => {
    for (const card of cards()) {
      const safe = card.dataset.protected !== '1' && card.dataset.usage !== 'used';
      card.querySelector('[data-ag-choice]').checked = safe && (button.dataset.agSelect === 'all' || (button.dataset.agSelect === 'unlinked' && card.dataset.usage === 'not_found'));
    }
    count();
  }));
  let auditPaused = false;
  const stop = root.querySelector('[data-ag-stop]');
  const resume = root.querySelector('[data-ag-resume]');
  stop?.addEventListener('click', () => { auditPaused = true; });
  const audit = token => run(async () => {
    auditPaused = false;
    stop.hidden = false;
    try {
      if (!token) { say('Подготовка проверки источников…'); token = (await request('audit_start')).token; }
      resume.dataset.agResume = token;
      resume.hidden = false;
      let result;
      do {
        result = await request('audit_step', { token });
        say(`Проверка связей: ${result.source || ''} · источников ${result.sources_done || 0} из ${result.sources_total || 0}. Можно уйти со страницы и продолжить позже.`);
        if (!result.done) await new Promise(resolve => setTimeout(resolve, 150));
      } while (!result.done && !auditPaused);
      if (auditPaused && !result.done) { say('Проверка приостановлена. Курсор сохранён; нажмите «Продолжить проверку».'); return; }
      reload(result.state === 'complete' ? 'Проверка связей завершена. Состояние отражает проверенные источники на момент проверки.' : 'Проверка завершена частично: для изображений без найденных связей сохранён статус «Не проверено».');
    } finally { stop.hidden = true; }
  });
  root.querySelector('[data-ag-audit]')?.addEventListener('click', () => audit(''));
  root.querySelector('[data-ag-resume]')?.addEventListener('click', event => audit(event.currentTarget.dataset.agResume));
  const dialog = root.querySelector('[data-ag-confirm]');
  let deletionToken = '';
  let deletionItems = [];
  const estimate = () => {
    const allowed = deletionItems.filter(item => (!item.linked || dialog.querySelector('[name=allow_linked]').checked) && (!item.protected || dialog.querySelector('[name=allow_protected]').checked) && (!item.unchecked || dialog.querySelector('[name=allow_unknown]').checked));
    const output = dialog.querySelector('[data-ag-estimate]');
    if (output) output.textContent = `С текущими разрешениями: до ${allowed.length} записей и ${(allowed.reduce((bytes, item) => bytes + item.bytes, 0) / 1048576).toFixed(2)} МиБ локальных оригиналов. Реальный объём зависит от наличия файлов и общих путей.`;
  };
  dialog?.addEventListener('change', estimate);
  const prepareDelete = ids => run(async () => {
    if (!ids.length) { say('Сначала выберите изображения.'); return; }
    say('Проверяем владельца и актуальные связи выбранных изображений…');
    const data = await request('prepare_delete', { ids });
    deletionToken = data.token;
    deletionItems = data.items;
    const summary = dialog.querySelector('[data-ag-summary]');
    summary.replaceChildren();
    const labels = { count: 'Выбрано изображений', linked: 'С подтверждёнными связями', protected: 'Защищённых / ранее закреплённых', unchecked: 'Использование не проверено', external: 'Внешних записей' };
    for (const [key, label] of Object.entries(labels)) {
      const line = document.createElement('p');
      line.textContent = `${label}: ${data.summary[key]}`;
      summary.append(line);
    }
    const size = document.createElement('p');
    size.textContent = `Локальные оригиналы выбранных записей: ${(data.summary.bytes / 1048576).toFixed(2)} МиБ. Фактически освобождённый объём будет показан после удаления.`;
    summary.append(size);
    const estimated = document.createElement('p');
    estimated.dataset.agEstimate = '';
    summary.append(estimated);
    dialog.querySelectorAll('input[type=checkbox]').forEach(input => { input.checked = false; });
    estimate();
    dialog.showModal();
    say('Проверьте сводку удаления. Без отдельных разрешений связанные, защищённые и непроверенные изображения будут пропущены.');
  });
  root.querySelector('[data-ag-delete]')?.addEventListener('click', () => prepareDelete([...root.querySelectorAll('[data-ag-choice]:checked')].map(input => input.value)));
  dialog?.querySelector('[data-ag-cancel]').addEventListener('click', () => { deletionToken = ''; dialog.close(); });
  dialog?.querySelector('[data-ag-confirm-delete]').addEventListener('click', () => run(async () => {
    if (!deletionToken) return;
    const values = { token: deletionToken };
    dialog.querySelectorAll('input[type=checkbox]').forEach(input => { values[input.name] = input.checked ? '1' : '0'; });
    dialog.close();
    let data;
    do {
      data = await request('delete_batch', values);
      say(`Обработано: ${data.processed} из ${data.total}. Удалено: ${data.result.deleted}. Пропущено: ${data.result.skipped}.`);
    } while (!data.done);
    const r = data.result;
    reload(`Удалено из Gallery: ${r.deleted}; внешних записей: ${r.external}; пропущено: ${r.skipped}. Освобождено локальных оригиналов: ${(r.bytes / 1048576).toFixed(2)} МиБ. ${r.errors.join(' ')}`);
  }));
  for (const card of cards()) {
    const ids = [card.dataset.mediaId];
    card.querySelector('[data-ag-delete-one]').addEventListener('click', () => prepareDelete(ids));
    card.querySelector('[data-ag-protect]').addEventListener('click', () => run(async () => {
      const data = await request('protect', { ids, value: card.dataset.manualProtected === '1' ? '0' : '1' });
      reload(data.protected ? 'Изображение защищено от очистки.' : 'Ручная отметка защиты снята. Закрепление оформления и подтверждённые связи продолжают защищать изображение.');
    }));
    card.querySelector('[data-ag-move]').addEventListener('click', () => run(async () => {
      const album = card.querySelector('[name=target_album]').value;
      if (!album) throw new Error('Выберите целевой альбом.');
      await request('move', { ids, album_id: album });
      reload('Изображение перемещено в альбом владельца.');
    }));
    card.querySelector('[data-ag-moderate]').addEventListener('click', () => run(async () => {
      await request('moderate', { ids, status: card.querySelector('[name=target_status]').value });
      reload('Статус модерации обновлён.');
    }));
    card.querySelectorAll('[data-ag-copy]').forEach(button => button.addEventListener('click', () => run(async () => {
      const url = card.dataset.url;
      const text = button.dataset.agCopy === 'bbcode' ? `[img]${url}[/img]` : url;
      if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(text);
      else {
        const input = document.createElement('textarea');
        input.value = text; document.body.append(input); input.select();
        const copied = document.execCommand('copy'); input.remove();
        if (!copied) throw new Error('Браузер запретил копирование. Откройте оригинал и скопируйте адрес.');
      }
      say(button.dataset.agCopy === 'bbcode' ? 'BBCode скопирован.' : 'URL скопирован.');
    })));
    card.querySelector('img').addEventListener('error', event => {
      const placeholder = document.createElement('span');
      placeholder.className = 'ag-admin-thumb'; placeholder.textContent = 'Миниатюра недоступна';
      event.target.replaceWith(placeholder);
    }, { once: true });
  }
})();
