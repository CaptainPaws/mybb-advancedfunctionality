(() => {
  'use strict';
  const form = document.querySelector('[data-af-asb-builder]');
  if (!form || form.dataset.initialized) return;
  const seed = JSON.parse(form.querySelector('[data-af-sig-seed]').textContent);
  const model = structuredClone(seed.model), thumbs = seed.media_urls || [];
  const status = form.querySelector('[data-af-sig-status]');
  const preview = form.querySelector('[data-af-sig-preview]');
  let timer, sequence = 0, dirty = false;
  const serialize = () => { form.querySelector('[data-af-sig-model]').value = JSON.stringify(model); };
  const showError = error => { status.textContent = error.message || 'Не удалось выполнить действие.'; };
  async function request(operation) {
    const seq = ++sequence, submitted = JSON.stringify(model), data = new FormData();
    data.set('my_post_key', seed.csrf); data.set('af_signature_model', submitted);
    const response = await fetch('usercp.php?action=editsig&af_asb_api=' + operation, {
      method: 'POST', body: data, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}
    });
    const result = await response.json();
    if (seq !== sequence && operation === 'preview') return;
    if (!response.ok || !result.ok) throw new Error(result.error || 'Ошибка сервера.');
    // HTML/CSS comes exclusively from the shared, validating server renderer.
    if (JSON.stringify(model) === submitted) {
      form.querySelector('[data-af-sig-preview-style]').textContent = result.data.css;
      preview.innerHTML = result.data.html;
      if (operation === 'draft') dirty = false;
    }
    status.textContent = result.data.message;
  }
  function changed() {
    dirty = true; serialize(); clearTimeout(timer);
    timer = setTimeout(() => request('preview').catch(showError), 300);
  }
  form.querySelectorAll('[data-asb-text]').forEach(input => {
    const key = input.dataset.asbText, count = form.querySelector('[data-asb-count="' + key + '"]');
    input.addEventListener('input', () => {
      model[key] = input.value; count.textContent = Array.from(input.value).length + '/' + seed.limits[key]; changed();
    });
  });
  form.querySelectorAll('[data-asb-show]').forEach(input => input.addEventListener('change', () => {
    model['show_' + input.dataset.asbShow] = input.checked; changed();
  }));
  form.querySelectorAll('[name="af_asb_template"]').forEach(input => input.addEventListener('change', () => {
    model.template = input.value;
    form.querySelectorAll('.af-asb__template').forEach(card => card.classList.toggle('is-active', card.contains(input)));
    changed();
  }));
  form.querySelectorAll('[data-asb-image]').forEach(box => {
    const index = Number(box.dataset.asbImage), image = index === 0 ? model.background : model.images[index - 1];
    const url = box.querySelector('[data-asb-image-url]'), thumb = box.querySelector('[data-asb-image-thumb]');
    function refreshThumb() {
      const source = image.media_id ? thumbs[index] : image.url;
      let safe = '';
      try { const parsed = new URL(source); if (['http:', 'https:'].includes(parsed.protocol) && /\.(png|jpe?g|webp|gif)$/i.test(parsed.pathname)) safe = source; } catch (_) {}
      thumb.hidden = !safe; if (safe) thumb.src = safe; else thumb.removeAttribute('src');
    }
    box.querySelector('[data-asb-image-enabled]').addEventListener('change', event => { image.enabled = event.target.checked; changed(); });
    url.addEventListener('input', () => { image.url = url.value; image.media_id = null; refreshThumb(); changed(); });
    box.querySelector('[data-asb-image-pick]').addEventListener('click', () => {
      if (!window.AF_GalleryPicker) { showError(new Error('Gallery недоступна. Укажите URL на разрешённом домене.')); return; }
      window.AF_GalleryPicker.open(media => {
        image.media_id = Number(media.media_id); image.url = ''; thumbs[index] = media.url_full;
        url.value = ''; refreshThumb(); changed();
      }, {purpose: 'appearance_postbit_bg'});
    });
    box.querySelector('[data-asb-image-clear]').addEventListener('click', () => {
      image.url = ''; image.media_id = null; url.value = ''; refreshThumb(); changed();
    });
    refreshThumb();
  });
  const links = form.querySelector('[data-asb-links]'), add = form.querySelector('[data-asb-add-link]');
  function element(tag, text) { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; }
  function action(text, title, callback) { const node = element('button', text); node.type = 'button'; node.setAttribute('aria-label', title); node.addEventListener('click', callback); return node; }
  function renderLinks(focusIndex = -1) {
    links.replaceChildren();
    model.buttons.forEach((link, index) => {
      const row = element('div'); row.className = 'af-asb__link';
      const name = element('input'), url = element('input');
      name.type = 'text'; name.maxLength = 60; name.placeholder = 'Название'; name.value = link.label; name.setAttribute('aria-label', 'Название ссылки ' + (index + 1));
      url.type = 'url'; url.maxLength = 2048; url.placeholder = 'https://'; url.value = link.url; url.setAttribute('aria-label', 'Адрес ссылки ' + (index + 1));
      name.addEventListener('input', () => { link.label = name.value; changed(); }); url.addEventListener('input', () => { link.url = url.value; changed(); });
      const up = action('↑', 'Переместить вверх', () => { [model.buttons[index - 1], model.buttons[index]] = [link, model.buttons[index - 1]]; renderLinks(index - 1); changed(); });
      const down = action('↓', 'Переместить вниз', () => { [model.buttons[index + 1], model.buttons[index]] = [link, model.buttons[index + 1]]; renderLinks(index + 1); changed(); });
      up.disabled = index === 0; down.disabled = index === model.buttons.length - 1;
      row.append(name, url, up, down, action('×', 'Удалить ссылку', () => { model.buttons.splice(index, 1); renderLinks(Math.min(index, model.buttons.length - 1)); changed(); }));
      links.append(row); if (focusIndex === index) name.focus();
    });
    add.disabled = model.buttons.length >= 6;
  }
  add.addEventListener('click', () => { if (model.buttons.length >= 6) return; model.buttons.push({label: '', url: ''}); renderLinks(model.buttons.length - 1); changed(); });
  form.querySelector('[data-af-sig-draft]').addEventListener('click', () => { clearTimeout(timer); request('draft').catch(showError); });
  form.querySelector('[data-af-sig-preview-button]').addEventListener('click', () => { clearTimeout(timer); request('preview').catch(showError); });
  preview.addEventListener('click', event => { if (event.target.closest('a,summary')) event.preventDefault(); });
  form.addEventListener('change', event => { if (event.target.name === 'updateposts' || event.target.name === 'af_sig_confirm_replace') dirty = true; });
  form.addEventListener('submit', () => { serialize(); dirty = false; });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('focus', () => request('preview').catch(showError));
  renderLinks(); serialize(); form.dataset.initialized = '1'; form.querySelector('button[type="submit"]').disabled = false;
})();
