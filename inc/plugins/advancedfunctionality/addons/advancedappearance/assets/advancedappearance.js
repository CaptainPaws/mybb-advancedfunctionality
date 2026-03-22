(function (window, document) {
  'use strict';

  if (window.__afAaUiLoaded) return;
  window.__afAaUiLoaded = true;

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function trim(value) {
    return asText(value).trim();
  }

  function parseJson(value, fallback) {
    try {
      var parsed = JSON.parse(asText(value));
      return parsed && typeof parsed === 'object' ? parsed : (fallback || {});
    } catch (e) {
      return fallback || {};
    }
  }

  function escCssUrl(url) {
    return asText(url).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\r|\n/g, '');
  }

  function toCssUrl(url) {
    url = trim(url);
    if (!url) return 'none';
    return 'url("' + escCssUrl(url) + '")';
  }

  function findPreviewRoot() {
    return document.querySelector('[data-aa-preview-root]');
  }

  function findPreviewPanel() {
    return document.querySelector('[data-aa-preview-panel]');
  }

  function setVar(root, name, value) {
    if (!root) return;
    root.style.setProperty(name, asText(value));
  }

  function collectFormSettings(form) {
    var settings = {};
    if (!form) return settings;

    var fields = form.querySelectorAll('[data-aa-setting]');
    fields.forEach(function (field) {
      var key = trim(field.getAttribute('data-aa-setting'));
      if (!key) return;
      settings[key] = asText(field.value || '');
    });

    return settings;
  }

  function collectFormMeta(form) {
    if (!form) {
      return {
        title: 'Превью',
        description: ''
      };
    }

    var titleNode = form.querySelector('[name="title"]');
    var descNode = form.querySelector('[name="description"]');

    return {
      title: trim(titleNode ? titleNode.value : '') || 'Новый пресет',
      description: trim(descNode ? descNode.value : '')
    };
  }

  function prefixSimpleCss(css, scopeSelector) {
    if (!css || css.indexOf('@') !== -1) return css;

    return css.replace(/(^|})\s*([^{@}][^{]*)\{/gm, function (full, lead, selectorList) {
      var clean = trim(selectorList);
      if (!clean) return full;

      var parts = clean.split(',');
      parts = parts.map(function (part) {
        part = trim(part);
        if (!part) return part;
        if (part.indexOf(scopeSelector) === 0) return part;
        return scopeSelector + ' ' + part;
      });

      return lead + ' ' + parts.join(', ') + ' {';
    });
  }

  function renderCustomCss(root, css) {
    if (!root) return;

    var styleNode = root.querySelector('[data-aa-preview-custom-css]');
    if (!styleNode) return;

    css = trim(css);
    if (!css) {
      styleNode.textContent = '';
      return;
    }

    var containsPlaceholder = css.indexOf('{{selector}}') !== -1 || css.indexOf('{{body_selector}}') !== -1;

    css = css.replace(/\{\{selector\}\}/g, '.af-aa-preview-root');
    css = css.replace(/\{\{body_selector\}\}/g, '.af-aa-preview-root .af-aa-preview-profile-page');

    if (!containsPlaceholder) {
      css = prefixSimpleCss(css, '.af-aa-preview-root');
    }

    styleNode.textContent = css;
  }

  function updatePreviewMeta(root, meta) {
    var panel = root ? root.closest('[data-aa-preview-panel]') : null;
    if (!panel) return;

    var titleNode = panel.querySelector('[data-aa-preview-title]');
    var descNode = panel.querySelector('[data-aa-preview-description]');

    if (titleNode) {
      titleNode.textContent = meta && meta.title ? meta.title : 'Превью';
    }

    if (descNode) {
      descNode.textContent = meta && meta.description ? meta.description : '';
    }
  }

  function updatePlaqueIcon(root, settings) {
    if (!root) return;

    var iconNode = root.querySelector('.af-apui-postbit-plaque__icon');
    if (!iconNode) return;

    var iconUrl = trim(settings.postbit_plaque_icon_url || '');
    var glyph = trim(settings.postbit_plaque_icon_glyph || '★') || '★';

    if (iconUrl) {
      iconNode.innerHTML = '<img class="af-apui-postbit-plaque__icon-image" src="' + iconUrl.replace(/"/g, '&quot;') + '" alt="">';
      return;
    }

    iconNode.innerHTML = '<span class="af-apui-postbit-plaque__icon-glyph" aria-hidden="true"></span>';
    var glyphNode = iconNode.querySelector('.af-apui-postbit-plaque__icon-glyph');
    if (glyphNode) {
      glyphNode.textContent = glyph;
    }
  }

  function applySettings(root, settings, meta) {
    if (!root || !settings) return;

    var mode = trim(settings.member_profile_body_bg_mode || 'cover');
    if (mode !== 'tile') mode = 'cover';

    var bodyCover = trim(settings.member_profile_body_cover_url || '');
    var bodyTile = trim(settings.member_profile_body_tile_url || '');
    var bodyImage = mode === 'tile' ? bodyTile : bodyCover;

    if (!bodyImage) {
      bodyImage = mode === 'tile' ? bodyCover : bodyTile;
      if (bodyImage) mode = mode === 'tile' ? 'cover' : 'tile';
    }

    setVar(root, '--af-aa-preview-body-image', toCssUrl(bodyImage));
    setVar(root, '--af-aa-preview-body-overlay', trim(settings.member_profile_body_overlay || 'linear-gradient(180deg, rgba(8,12,20,.28), rgba(6,8,14,.72))'));
    setVar(root, '--af-aa-preview-body-repeat', mode === 'tile' ? 'repeat' : 'no-repeat');
    setVar(root, '--af-aa-preview-body-position', mode === 'tile' ? 'left top' : 'center center');
    setVar(root, '--af-aa-preview-body-size', mode === 'tile' ? 'auto' : 'cover');

    setVar(root, '--af-aa-preview-banner-image', toCssUrl(settings.profile_banner_url || ''));
    setVar(root, '--af-aa-preview-banner-overlay', trim(settings.profile_banner_overlay || 'linear-gradient(180deg, rgba(7,10,16,.06), rgba(7,10,16,.78))'));

    setVar(root, '--af-aa-preview-postbit-author-image', toCssUrl(settings.postbit_author_bg_url || ''));
    setVar(root, '--af-aa-preview-postbit-author-overlay', trim(settings.postbit_author_overlay || 'linear-gradient(180deg, rgba(7,10,16,.08), rgba(7,10,16,.72))'));

    setVar(root, '--af-aa-preview-postbit-name-image', toCssUrl(settings.postbit_name_bg_url || ''));
    setVar(root, '--af-aa-preview-postbit-name-overlay', trim(settings.postbit_name_overlay || 'linear-gradient(180deg, rgba(20,24,38,.64), rgba(14,18,30,.92))'));

    setVar(root, '--af-aa-preview-postbit-plaque-image', toCssUrl(settings.postbit_plaque_bg_url || ''));
    setVar(root, '--af-aa-preview-postbit-plaque-overlay', trim(settings.postbit_plaque_overlay || 'linear-gradient(180deg, rgba(55,66,122,.30), rgba(31,38,76,.48))'));
    setVar(root, '--af-aa-preview-postbit-plaque-icon-bg', trim(settings.postbit_plaque_icon_bg || 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))'));
    setVar(root, '--af-aa-preview-postbit-plaque-icon-overlay', trim(settings.postbit_plaque_icon_overlay || 'none'));
    setVar(root, '--af-aa-preview-postbit-plaque-icon-border', trim(settings.postbit_plaque_icon_border || 'rgba(255,255,255,.18)'));
    setVar(root, '--af-aa-preview-postbit-plaque-icon-color', trim(settings.postbit_plaque_icon_color || '#f6f1cf'));
    setVar(root, '--af-aa-preview-postbit-plaque-icon-size', trim(settings.postbit_plaque_icon_size || '26px'));
    updatePlaqueIcon(root, settings);

    renderCustomCss(root, settings.custom_css || '');
    updatePreviewMeta(root, meta || {});
  }

  function setActiveCard(card) {
    document.querySelectorAll('[data-aa-card].is-active').forEach(function (node) {
      node.classList.remove('is-active');
    });

    if (card) {
      card.classList.add('is-active');
    }
  }

  function openPreviewPanel() {
    var panel = findPreviewPanel();
    if (!panel) return;

    panel.hidden = false;
    panel.removeAttribute('hidden');

    if (typeof panel.scrollIntoView === 'function') {
      panel.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  }

  function closePreviewPanel() {
    var panel = findPreviewPanel();
    if (!panel) return;

    panel.hidden = true;
    panel.setAttribute('hidden', 'hidden');
  }

  document.addEventListener('click', function (event) {
    var closeBtn = event.target.closest('[data-aa-preview-close]');
    if (closeBtn) {
      event.preventDefault();
      closePreviewPanel();
      return;
    }

    var formBtn = event.target.closest('[data-aa-preview-from-form]');
    if (formBtn) {
      var form = formBtn.closest('[data-aa-form]');
      var root = findPreviewRoot();
      if (!form || !root) return;

      event.preventDefault();

      applySettings(root, collectFormSettings(form), collectFormMeta(form));
      setActiveCard(null);
      openPreviewPanel();
      return;
    }

    var cardBtn = event.target.closest('[data-aa-preview-from-card]');
    if (cardBtn) {
      var card = cardBtn.closest('[data-aa-card]');
      var previewRoot = findPreviewRoot();
      if (!card || !previewRoot) return;

      event.preventDefault();

      var settings = parseJson(card.getAttribute('data-aa-settings'), {});
      applySettings(previewRoot, settings, {
        title: trim(card.getAttribute('data-aa-title') || 'Preset'),
        description: trim(card.getAttribute('data-aa-description') || '')
      });

      setActiveCard(card);
      openPreviewPanel();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closePreviewPanel();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var panel = findPreviewPanel();
    if (panel) {
      panel.hidden = true;
      panel.setAttribute('hidden', 'hidden');
    }
  });
})(window, document);
