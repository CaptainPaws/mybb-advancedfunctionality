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
  const imageGroup = form.querySelector('[data-asb-images-group]'), imageError = form.querySelector('[data-asb-images-error]');
  const galleryEmpty = () => model.template === 'gallery' && !model.images.some(image => image.enabled && (Number(image.media_id) > 0 || image.url.trim() !== ''));
  function focusImages(message) {
    imageError.textContent = message; imageError.hidden = false; imageGroup.classList.add('has-error');
    imageGroup.setAttribute('aria-invalid', 'true'); imageGroup.scrollIntoView({behavior:'smooth', block:'center'}); imageGroup.focus({preventScroll:true});
  }
  const showError = error => { status.dataset.error = '1'; status.textContent = error.message || 'Не удалось выполнить действие.'; };
  async function request(operation, announce = false) {
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
    if (announce || operation === 'draft') { status.textContent = result.data.message; delete status.dataset.error; }
    else if (status.dataset.error) { status.textContent = ''; delete status.dataset.error; }
  }
  function changed() {
    if (!galleryEmpty()) { imageError.hidden = true; imageError.textContent = ''; imageGroup.classList.remove('has-error'); imageGroup.removeAttribute('aria-invalid'); }
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
    const index = Number(box.dataset.asbImage), image = model.images[index - 1];
    const url = box.querySelector('[data-asb-image-url]'), thumb = box.querySelector('[data-asb-image-thumb]');
    function refreshThumb() {
      const source = image.media_id ? thumbs[index - 1] : image.url;
      let safe = '';
      try { const parsed = new URL(source); if (['http:', 'https:'].includes(parsed.protocol) && /\.(png|jpe?g|webp|gif)$/i.test(parsed.pathname)) safe = source; } catch (_) {}
      box.classList.toggle('has-image', Boolean(safe));
      box.querySelector('[data-asb-image-pick]').textContent = safe ? 'Заменить' : 'Выбрать файл';
      box.querySelector('[data-asb-image-clear]').disabled = !image.media_id && !image.url;
      thumb.hidden = !safe; if (safe) thumb.src = safe; else thumb.removeAttribute('src');
    }
    box.querySelector('[data-asb-image-enabled]').addEventListener('change', event => { image.enabled = event.target.checked; changed(); });
    url.addEventListener('input', () => { image.url = url.value; image.media_id = null; refreshThumb(); changed(); });
    box.querySelector('[data-asb-image-pick]').addEventListener('click', async event => {
      const button = event.currentTarget;
      // Gallery is delivered by its owner manifest; opening waits for the real modal request.
      if (!window.AF_GalleryPicker || typeof window.AF_GalleryPicker.open !== 'function') {
        showError(new Error('Модуль выбора Advanced Gallery не загрузился. Обновите страницу; если ошибка повторяется, проверьте активность Gallery и доставку его ассетов в UserCP.')); return;
      }
      button.disabled = true;
      try {
        await window.AF_GalleryPicker.open(media => {
          if (!Number.isInteger(Number(media.media_id)) || Number(media.media_id) < 1) { showError(new Error('Галерея не вернула ID изображения.')); return; }
          image.media_id = Number(media.media_id); image.url = ''; image.enabled = true; thumbs[index - 1] = media.url_full;
          box.querySelector('[data-asb-image-enabled]').checked = true;
          url.value = ''; refreshThumb(); changed();
        }, {purpose: 'signature_images', onError: showError});
      } catch (error) { showError(error); }
      finally { button.disabled = !seed.image_allowed; }
    });
    box.querySelector('[data-asb-image-clear]').addEventListener('click', () => {
      image.url = ''; image.media_id = null; url.value = ''; refreshThumb(); changed();
    });
    refreshThumb();
  });
  const links = form.querySelector('[data-asb-links]'), add = form.querySelector('[data-asb-add-link]');
  function element(tag, text) { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; }
  function action(icon, title, callback) { const node = element('button'); node.append(form.querySelector('[data-asb-icon="' + icon + '"]').content.cloneNode(true)); node.type = 'button'; node.setAttribute('aria-label', title); node.addEventListener('click', callback); return node; }
  function renderLinks(focusIndex = -1) {
    links.replaceChildren();
    model.buttons.forEach((link, index) => {
      const row = element('div'); row.className = 'af-asb__link';
      const name = element('input'), url = element('input');
      name.type = 'text'; name.maxLength = 60; name.placeholder = 'Название'; name.value = link.label; name.setAttribute('aria-label', 'Название ссылки ' + (index + 1));
      url.type = 'url'; url.maxLength = 2048; url.placeholder = 'https://'; url.value = link.url; url.setAttribute('aria-label', 'Адрес ссылки ' + (index + 1));
      name.addEventListener('input', () => { link.label = name.value; changed(); }); url.addEventListener('input', () => { link.url = url.value; changed(); });
      const up = action('up', 'Переместить вверх', () => { [model.buttons[index - 1], model.buttons[index]] = [link, model.buttons[index - 1]]; renderLinks(index - 1); changed(); });
      const down = action('down', 'Переместить вниз', () => { [model.buttons[index + 1], model.buttons[index]] = [link, model.buttons[index + 1]]; renderLinks(index + 1); changed(); });
      up.disabled = index === 0; down.disabled = index === model.buttons.length - 1;
      const reorder = element('div'); reorder.className = 'af-asb__link-reorder'; reorder.append(up, down);
      const remove = action('trash', 'Удалить ссылку', () => { model.buttons.splice(index, 1); renderLinks(Math.min(index, model.buttons.length - 1)); changed(); }); remove.className = 'af-asb__link-delete';
      row.append(name, url, remove, reorder);
      links.append(row); if (focusIndex === index) name.focus();
    });
    add.disabled = model.buttons.length >= 6;
  }
  add.addEventListener('click', () => { if (model.buttons.length >= 6) return; model.buttons.push({label: '', url: ''}); renderLinks(model.buttons.length - 1); changed(); });
  form.querySelector('[data-af-sig-draft]').addEventListener('click', () => { clearTimeout(timer); request('draft').catch(showError); });
  form.querySelector('[data-af-sig-preview-button]').addEventListener('click', () => { clearTimeout(timer); request('preview', true).catch(showError); });
  preview.addEventListener('click', event => { if (event.target.closest('a,summary')) event.preventDefault(); });
  form.addEventListener('change', event => { if (event.target.name === 'updateposts' || event.target.name === 'af_sig_confirm_replace') dirty = true; });
  form.addEventListener('submit', event => {
    serialize();
    if (galleryEmpty()) { event.preventDefault(); focusImages('Добавьте хотя бы одно изображение в галерею.'); return; }
    dirty = false;
  });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('focus', () => request('preview').catch(showError));
  if (seed.field_error && seed.field_error.field === 'images') focusImages(seed.field_error.message);
  renderLinks(); serialize(); form.dataset.initialized = '1'; form.querySelector('button[type="submit"]').disabled = false;
})();
