(function (window, document) {
  'use strict';

  if (window.__afAeResizeimgPackLoaded) return;
  window.__afAeResizeimgPackLoaded = true;

  var MIN_SIZE = 24;
  var MAX_SIZE = 4000;
  var IFRAME_STYLE_ID = 'af-ae-resizeimg-style';
  var HOST_STYLE_ID = 'af-ae-resizeimg-host-style';

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function trim(value) {
    return asText(value).trim();
  }

  function escHtml(value) {
    return asText(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function clampResizeSize(value) {
    var n = parseInt(value, 10);
    if (isNaN(n)) return 0;
    if (n < MIN_SIZE) return MIN_SIZE;
    if (n > MAX_SIZE) return MAX_SIZE;
    return n;
  }

  function parseDimension(value) {
    value = trim(value).toLowerCase();
    if (!value) return 0;

    var m = value.match(/^(\d+)(?:px)?$/i);
    if (!m) return 0;

    var n = parseInt(m[1], 10);
    if (isNaN(n) || n <= 0) return 0;
    if (n > MAX_SIZE) return MAX_SIZE;

    return n;
  }

  function normalizeUrl(url) {
    return trim(url);
  }

  function getSceditorRoot() {
    if (window.sceditor) return window.sceditor;
    if (window.jQuery && window.jQuery.sceditor) return window.jQuery.sceditor;
    return null;
  }

  function isSourceMode(editor) {
    try {
      if (!editor) return false;
      if (typeof editor.inSourceMode === 'function') return !!editor.inSourceMode();
      if (typeof editor.sourceMode === 'function') return !!editor.sourceMode();
    } catch (e) {}
    return false;
  }

  function getEditorDom(editor) {
    if (!editor || typeof editor.getBody !== 'function') return null;

    var body = editor.getBody();
    if (!body || !body.ownerDocument) return null;

    var doc = body.ownerDocument;
    var win = doc.defaultView || window;
    var sel = win.getSelection ? win.getSelection() : null;

    return {
      body: body,
      doc: doc,
      win: win,
      sel: sel
    };
  }

  function getEditorFrame(editor) {
    try {
      var dom = getEditorDom(editor);
      if (!dom || !dom.win) return null;
      return dom.win.frameElement || null;
    } catch (e) {}
    return null;
  }

  function syncEditor(editor) {
    if (!editor) return;

    try {
      if (typeof editor.updateOriginal === 'function') {
        editor.updateOriginal();
      }
    } catch (e) {}

    try {
      if (typeof editor.focus === 'function') {
        editor.focus();
      }
    } catch (e2) {}
  }

  function ensureImageDataset(img) {
    if (!img || img.nodeType !== 1) return;

    var src = trim(img.getAttribute('data-af-src') || img.getAttribute('src') || '');
    if (src && !img.getAttribute('data-af-src')) {
      img.setAttribute('data-af-src', src);
    }

    if (!img.getAttribute('data-af-img')) {
      img.setAttribute('data-af-img', '1');
    }

    if (!img.classList.contains('af-resizeimg')) {
      img.classList.add('af-resizeimg');
    }
  }

  function getImageUrl(img) {
    if (!img || img.nodeType !== 1) return '';

    ensureImageDataset(img);

    return normalizeUrl(
      img.getAttribute('data-af-src') ||
      img.getAttribute('src') ||
      ''
    );
  }

  function getExplicitImageWidth(img) {
    if (!img || img.nodeType !== 1) return 0;
    return parseDimension(img.getAttribute('data-af-img-width') || '');
  }

  function getExplicitImageHeight(img) {
    if (!img || img.nodeType !== 1) return 0;
    return parseDimension(img.getAttribute('data-af-img-height') || '');
  }

  function getNaturalImageWidth(img) {
    if (!img || img.nodeType !== 1) return 0;

    var naturalW = parseInt(img.naturalWidth || 0, 10);
    if (isNaN(naturalW) || naturalW <= 0) return 0;

    return naturalW;
  }

  function getNaturalImageHeight(img) {
    if (!img || img.nodeType !== 1) return 0;

    var naturalH = parseInt(img.naturalHeight || 0, 10);
    if (isNaN(naturalH) || naturalH <= 0) return 0;

    return naturalH;
  }

  function getImageWidth(img) {
    if (!img || img.nodeType !== 1) return 0;

    var width = getExplicitImageWidth(img);
    if (width > 0) {
      return width;
    }

    width = getNaturalImageWidth(img);
    if (width > 0) {
      return width;
    }

    try {
      var rect = img.getBoundingClientRect();
      if (rect && rect.width) {
        return Math.round(rect.width);
      }
    } catch (e) {}

    return 0;
  }

  function getImageHeight(img) {
    if (!img || img.nodeType !== 1) return 0;

    var height = getExplicitImageHeight(img);
    if (height > 0) {
      return height;
    }

    height = getNaturalImageHeight(img);
    if (height > 0) {
      return height;
    }

    try {
      var rect = img.getBoundingClientRect();
      if (rect && rect.height) {
        return Math.round(rect.height);
      }
    } catch (e) {}

    return 0;
  }

  function getImageRatio(img) {
    if (!img || img.nodeType !== 1) return 1;

    var naturalW = getNaturalImageWidth(img);
    var naturalH = getNaturalImageHeight(img);

    if (naturalW > 0 && naturalH > 0) {
      return naturalW / naturalH;
    }

    var w = getImageWidth(img);
    var h = getImageHeight(img);

    if (w > 0 && h > 0) {
      return w / h;
    }

    return 1;
  }

  function applyNaturalImageDisplay(img) {
    if (!img || img.nodeType !== 1) return;

    ensureImageDataset(img);

    if (getExplicitImageWidth(img) > 0 || getExplicitImageHeight(img) > 0) {
      return;
    }

    var naturalW = getNaturalImageWidth(img);
    var naturalH = getNaturalImageHeight(img);

    img.removeAttribute('width');
    img.removeAttribute('height');
    img.removeAttribute('data-af-natural-width');
    img.removeAttribute('data-af-natural-height');

    img.style.maxWidth = 'none';

    if (naturalW > 0 && naturalH > 0) {
      img.setAttribute('data-af-natural-width', String(naturalW));
      img.setAttribute('data-af-natural-height', String(naturalH));
      img.style.width = naturalW + 'px';
      img.style.height = naturalH + 'px';
      return;
    }

    img.style.width = '';
    img.style.height = '';

    if (!img.__afAeResizeimgNaturalBound) {
      img.__afAeResizeimgNaturalBound = true;
      img.addEventListener('load', function () {
        if (!img.isConnected) return;
        if (getExplicitImageWidth(img) > 0 || getExplicitImageHeight(img) > 0) return;
        applyNaturalImageDisplay(img);
      });
    }
  }

  function normalizeRenderedImage(img) {
    if (!img || img.nodeType !== 1) return;

    ensureImageDataset(img);

    var explicitW = getExplicitImageWidth(img);
    var explicitH = getExplicitImageHeight(img);

    if (explicitW > 0 || explicitH > 0) {
      img.style.maxWidth = 'none';

      if (explicitW > 0) {
        img.setAttribute('width', String(explicitW));
        img.style.width = explicitW + 'px';
      } else {
        img.removeAttribute('width');
        img.style.width = '';
      }

      if (explicitH > 0) {
        img.setAttribute('height', String(explicitH));
        img.style.height = explicitH + 'px';
      } else {
        img.removeAttribute('height');
        img.style.height = explicitW > 0 ? 'auto' : '';
      }

      img.removeAttribute('data-af-natural-width');
      img.removeAttribute('data-af-natural-height');
      return;
    }

    applyNaturalImageDisplay(img);
  }

  function normalizeAllEditorImages(editor) {
    var dom = getEditorDom(editor);
    if (!dom || !dom.body) return;

    var images = dom.body.querySelectorAll('img[data-af-img="1"], img.af-resizeimg');
    for (var i = 0; i < images.length; i += 1) {
      normalizeRenderedImage(images[i]);
    }
  }

  function markImageResized(img, width, height) {
    if (!img || img.nodeType !== 1) return;

    width = clampResizeSize(width);
    height = clampResizeSize(height);

    if (width <= 0 || height <= 0) return;

    ensureImageDataset(img);

    img.setAttribute('data-af-img-width', String(width));
    img.setAttribute('data-af-img-height', String(height));

    img.setAttribute('width', String(width));
    img.setAttribute('height', String(height));

    img.removeAttribute('data-af-natural-width');
    img.removeAttribute('data-af-natural-height');

    img.style.width = width + 'px';
    img.style.height = height + 'px';
    img.style.maxWidth = 'none';
  }

  function clearImageSize(img) {
    if (!img || img.nodeType !== 1) return;

    img.removeAttribute('data-af-img-width');
    img.removeAttribute('data-af-img-height');
    img.removeAttribute('width');
    img.removeAttribute('height');

    img.style.width = '';
    img.style.height = '';
    img.style.maxWidth = 'none';

    applyNaturalImageDisplay(img);
  }

  function buildImageHtml(url, width, height) {
    url = normalizeUrl(url);
    if (!url) return '';

    width = parseDimension(width);
    height = parseDimension(height);

    var parts = [
      'class="af-resizeimg"',
      'data-af-img="1"',
      'data-af-src="' + escHtml(url) + '"',
      'src="' + escHtml(url) + '"',
      'alt=""'
    ];

    var styles = ['max-width:none'];

    if (width > 0) {
      parts.push('data-af-img-width="' + width + '"');
      parts.push('width="' + width + '"');
      styles.push('width:' + width + 'px');
    }

    if (height > 0) {
      parts.push('data-af-img-height="' + height + '"');
      parts.push('height="' + height + '"');
      styles.push('height:' + height + 'px');
    }

    if (width > 0 && !height) {
      styles.push('height:auto');
    }

    if (styles.length) {
      parts.push('style="' + escHtml(styles.join(';')) + ';"');
    }

    return '<img ' + parts.join(' ') + ' />';
  }

  function formatImageElement(el) {
    if (!el || el.nodeType !== 1 || !el.tagName || el.tagName.toLowerCase() !== 'img') {
      return '';
    }

    var url = getImageUrl(el);
    if (!url) return '';

    var width = getExplicitImageWidth(el);
    var height = getExplicitImageHeight(el);

    if (width > 0 || height > 0) {
      var attrs = [];
      if (width > 0) attrs.push('width=' + width);
      if (height > 0) attrs.push('height=' + height);

      return '[img ' + attrs.join(' ') + ']' + url + '[/img]';
    }

    return '[img]' + url + '[/img]';
  }

  function registerBbcodeFormats() {
    var sc = getSceditorRoot();

    if (!sc || !sc.formats || !sc.formats.bbcode || typeof sc.formats.bbcode.set !== 'function') {
      return false;
    }

    sc.formats.bbcode.set('img', {
      tags: {
        img: {
          src: null
        }
      },
      isInline: true,
      format: function (element) {
        return formatImageElement(element);
      },
      html: function (token, attrs, content) {
        attrs = attrs || {};

        var url = normalizeUrl(
          content ||
          attrs.src ||
          attrs.defaultattr ||
          ''
        );

        var width = parseDimension(attrs.width || attrs.w || '');
        var height = parseDimension(attrs.height || attrs.h || '');

        return buildImageHtml(url, width, height);
      }
    });

    return true;
  }

  function getIframeCssText() {
    return '' +
      '.af-resizeimg{max-width:none !important;height:auto;vertical-align:bottom;}' +
      'img.af-ae-resizeimg-selected{outline:2px solid rgba(79,141,255,.95);outline-offset:2px;}';
  }

  function getHostCssText() {
    return '' +
      '.af-ae-resizeimg-menu{' +
        'position:fixed;' +
        'display:none;' +
        'width:260px;' +
        'max-width:calc(100vw - 16px);' +
        'z-index:2147483000;' +
        'box-sizing:border-box;' +
        'padding:10px;' +
        'border:1px solid rgba(79,141,255,.35);' +
        'border-radius:12px;' +
        'background:rgba(24,28,36,.98);' +
        'box-shadow:0 14px 28px rgba(0,0,0,.38);' +
        'color:#fff;' +
      '}' +
      '.af-ae-resizeimg-menu.is-visible{display:block;}' +
      '.af-ae-resizeimg-menu__title{' +
        'margin:0 0 8px 0;' +
        'font-size:13px;' +
        'font-weight:700;' +
        'line-height:1.2;' +
      '}' +
      '.af-ae-resizeimg-menu__grid{' +
        'display:grid;' +
        'grid-template-columns:1fr 1fr;' +
        'gap:8px;' +
        'margin-bottom:8px;' +
      '}' +
      '.af-ae-resizeimg-menu__field{' +
        'display:flex;' +
        'flex-direction:column;' +
        'gap:4px;' +
        'min-width:0;' +
      '}' +
      '.af-ae-resizeimg-menu__field span{' +
        'font-size:11px;' +
        'color:rgba(255,255,255,.72);' +
      '}' +
      '.af-ae-resizeimg-menu__field input{' +
        'width:100%;' +
        'min-height:32px;' +
        'box-sizing:border-box;' +
        'padding:6px 8px;' +
        'border:1px solid rgba(255,255,255,.14);' +
        'border-radius:8px;' +
        'background:rgba(255,255,255,.06);' +
        'color:#fff;' +
        'outline:none;' +
        'font-size:12px;' +
      '}' +
      '.af-ae-resizeimg-menu__field input:focus{' +
        'border-color:rgba(79,141,255,.72);' +
        'box-shadow:0 0 0 3px rgba(79,141,255,.16);' +
      '}' +
      '.af-ae-resizeimg-menu__lock{' +
        'display:flex;' +
        'align-items:center;' +
        'gap:6px;' +
        'margin:0 0 10px 0;' +
        'font-size:12px;' +
        'color:rgba(255,255,255,.82);' +
      '}' +
      '.af-ae-resizeimg-menu__actions{' +
        'display:flex;' +
        'gap:6px;' +
        'justify-content:flex-end;' +
      '}' +
      '.af-ae-resizeimg-menu__btn{' +
        'min-height:32px;' +
        'padding:6px 10px;' +
        'border:1px solid rgba(255,255,255,.14);' +
        'border-radius:8px;' +
        'background:rgba(255,255,255,.06);' +
        'color:#fff;' +
        'cursor:pointer;' +
        'font-size:12px;' +
      '}' +
      '.af-ae-resizeimg-menu__btn:hover{' +
        'background:rgba(255,255,255,.10);' +
      '}' +
      '.af-ae-resizeimg-menu__btn--primary{' +
        'background:#4f8dff;' +
        'border-color:#4f8dff;' +
      '}' +
      '.af-ae-resizeimg-menu__btn--primary:hover{' +
        'background:#6aa0ff;' +
      '}';
  }

  function ensureIframeCss(editor) {
    var dom = getEditorDom(editor);
    if (!dom || !dom.doc || !dom.doc.head) return;

    if (dom.doc.getElementById(IFRAME_STYLE_ID)) return;

    var style = dom.doc.createElement('style');
    style.id = IFRAME_STYLE_ID;
    style.type = 'text/css';
    style.appendChild(dom.doc.createTextNode(getIframeCssText()));
    dom.doc.head.appendChild(style);
  }

  function ensureHostCss() {
    if (document.getElementById(HOST_STYLE_ID)) return;

    var style = document.createElement('style');
    style.id = HOST_STYLE_ID;
    style.type = 'text/css';
    style.appendChild(document.createTextNode(getHostCssText()));
    document.head.appendChild(style);
  }

  function isResizableImage(node) {
    return !!(
      node &&
      node.nodeType === 1 &&
      node.tagName &&
      node.tagName.toLowerCase() === 'img'
    );
  }

  function getEditorState(editor) {
    if (!editor) return null;

    if (editor.__afAeResizeimgState) {
      return editor.__afAeResizeimgState;
    }

    var dom = getEditorDom(editor);
    if (!dom || !dom.body || !dom.doc) return null;

    ensureIframeCss(editor);
    ensureHostCss();

    var menu = document.createElement('div');
    menu.className = 'af-ae-resizeimg-menu';
    menu.innerHTML = [
      '<div class="af-ae-resizeimg-menu__title">Размер изображения</div>',
      '<div class="af-ae-resizeimg-menu__grid">',
      '  <label class="af-ae-resizeimg-menu__field">',
      '    <span>Ширина</span>',
      '    <input type="number" min="' + MIN_SIZE + '" max="' + MAX_SIZE + '" step="1" data-af-field="width" placeholder="auto">',
      '  </label>',
      '  <label class="af-ae-resizeimg-menu__field">',
      '    <span>Высота</span>',
      '    <input type="number" min="' + MIN_SIZE + '" max="' + MAX_SIZE + '" step="1" data-af-field="height" placeholder="auto">',
      '  </label>',
      '</div>',
      '<label class="af-ae-resizeimg-menu__lock">',
      '  <input type="checkbox" data-af-field="lock" checked>',
      '  <span>Сохранять пропорции</span>',
      '</label>',
      '<div class="af-ae-resizeimg-menu__actions">',
      '  <button type="button" class="af-ae-resizeimg-menu__btn" data-af-action="reset">Сбросить</button>',
      '  <button type="button" class="af-ae-resizeimg-menu__btn" data-af-action="close">Закрыть</button>',
      '  <button type="button" class="af-ae-resizeimg-menu__btn af-ae-resizeimg-menu__btn--primary" data-af-action="apply">Применить</button>',
      '</div>'
    ].join('');

    document.body.appendChild(menu);

    var widthInput = menu.querySelector('[data-af-field="width"]');
    var heightInput = menu.querySelector('[data-af-field="height"]');
    var lockInput = menu.querySelector('[data-af-field="lock"]');

    var state = {
      editor: editor,
      dom: dom,
      menu: menu,
      widthInput: widthInput,
      heightInput: heightInput,
      lockInput: lockInput,
      img: null
    };

    function hideMenu() {
      menu.classList.remove('is-visible');
    }

    function showMenu() {
      menu.classList.add('is-visible');
    }

    function clearSelection() {
      if (state.img && state.img.classList) {
        state.img.classList.remove('af-ae-resizeimg-selected');
      }

      state.img = null;
      hideMenu();
    }

    function populateFields() {
      if (!state.img) {
        widthInput.value = '';
        heightInput.value = '';
        return;
      }

      var w = getImageWidth(state.img);
      var h = getImageHeight(state.img);

      widthInput.value = w > 0 ? String(w) : '';
      heightInput.value = h > 0 ? String(h) : '';
    }

    function getGlobalImageRect() {
      if (!state.img || !state.img.isConnected) return null;

      var imgRect;
      var frameRect;
      var frame = getEditorFrame(editor);

      try {
        imgRect = state.img.getBoundingClientRect();
      } catch (e0) {
        return null;
      }

      if (frame && frame.getBoundingClientRect) {
        frameRect = frame.getBoundingClientRect();
        return {
          left: frameRect.left + imgRect.left,
          top: frameRect.top + imgRect.top,
          right: frameRect.left + imgRect.right,
          bottom: frameRect.top + imgRect.bottom,
          width: imgRect.width,
          height: imgRect.height
        };
      }

      return imgRect;
    }

    function positionMenu() {
      if (!state.img || !state.img.isConnected || isSourceMode(editor)) {
        clearSelection();
        return;
      }

      var rect = getGlobalImageRect();
      if (!rect || rect.width < 4 || rect.height < 4) {
        hideMenu();
        return;
      }

      menu.style.visibility = 'hidden';
      showMenu();

      var menuRect = menu.getBoundingClientRect();
      var left = rect.left;
      var top = rect.bottom + 10;
      var maxLeft = Math.max(8, window.innerWidth - menuRect.width - 8);

      if (left > maxLeft) {
        left = maxLeft;
      }
      if (left < 8) {
        left = 8;
      }

      if (top + menuRect.height > window.innerHeight - 8) {
        top = rect.top - menuRect.height - 10;
      }
      if (top < 8) {
        top = 8;
      }

      menu.style.left = Math.round(left) + 'px';
      menu.style.top = Math.round(top) + 'px';
      menu.style.visibility = '';
    }

    function applyFromFields() {
      if (!state.img) return;

      var ratio = getImageRatio(state.img);
      var width = parseDimension(widthInput.value);
      var height = parseDimension(heightInput.value);
      var lock = !!lockInput.checked;

      if (!width && !height) {
        clearImageSize(state.img);
        syncEditor(editor);
        populateFields();
        positionMenu();
        return;
      }

      if (lock) {
        if (width && !height) {
          height = clampResizeSize(Math.round(width / ratio));
          heightInput.value = String(height);
        } else if (height && !width) {
          width = clampResizeSize(Math.round(height * ratio));
          widthInput.value = String(width);
        }
      }

      if (!width && height) {
        width = clampResizeSize(Math.round(height * ratio));
      }

      if (!height && width) {
        height = clampResizeSize(Math.round(width / ratio));
      }

      if (!width || !height) {
        return;
      }

      markImageResized(state.img, width, height);
      syncEditor(editor);
      populateFields();
      positionMenu();
    }

    function resetSize() {
      if (!state.img) return;

      clearImageSize(state.img);
      syncEditor(editor);
      populateFields();
      positionMenu();
    }

    function selectImage(img) {
      if (!isResizableImage(img)) {
        clearSelection();
        return;
      }

      normalizeRenderedImage(img);

      if (state.img && state.img !== img && state.img.classList) {
        state.img.classList.remove('af-ae-resizeimg-selected');
      }

      state.img = img;
      state.img.classList.add('af-ae-resizeimg-selected');

      populateFields();
      positionMenu();
      showMenu();
    }

    function syncHeightFromWidth() {
      if (!state.img || !lockInput.checked) return;

      var width = parseDimension(widthInput.value);
      if (!width) return;

      var ratio = getImageRatio(state.img);
      var height = clampResizeSize(Math.round(width / ratio));
      if (height > 0) {
        heightInput.value = String(height);
      }
    }

    function syncWidthFromHeight() {
      if (!state.img || !lockInput.checked) return;

      var height = parseDimension(heightInput.value);
      if (!height) return;

      var ratio = getImageRatio(state.img);
      var width = clampResizeSize(Math.round(height * ratio));
      if (width > 0) {
        widthInput.value = String(width);
      }
    }

    widthInput.addEventListener('input', syncHeightFromWidth);
    heightInput.addEventListener('input', syncWidthFromHeight);

    menu.addEventListener('click', function (event) {
      var actionEl = event.target && event.target.closest('[data-af-action]');
      if (!actionEl) return;

      event.preventDefault();
      event.stopPropagation();

      var action = actionEl.getAttribute('data-af-action');

      if (action === 'apply') {
        applyFromFields();
        return;
      }

      if (action === 'reset') {
        resetSize();
        return;
      }

      if (action === 'close') {
        clearSelection();
      }
    }, true);

    menu.addEventListener('keydown', function (event) {
      if (!event) return;

      if (event.key === 'Enter') {
        event.preventDefault();
        applyFromFields();
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        clearSelection();
      }
    }, true);

    dom.body.addEventListener('load', function (event) {
      var target = event.target;
      if (!isResizableImage(target)) return;

      normalizeRenderedImage(target);

      if (state.img === target) {
        populateFields();
        positionMenu();
      }
    }, true);

    dom.body.addEventListener('mousedown', function (event) {
      var target = event.target;

      if (isResizableImage(target)) {
        selectImage(target);
        return;
      }

      clearSelection();
    }, true);

    document.addEventListener('mousedown', function (event) {
      var target = event.target;

      if (menu.contains(target)) {
        return;
      }

      var frame = getEditorFrame(editor);
      if (frame && target === frame) {
        return;
      }

      clearSelection();
    }, true);

    dom.body.addEventListener('keyup', function () {
      if (state.img && state.img.isConnected) {
        positionMenu();
      } else {
        clearSelection();
      }
    }, true);

    dom.body.addEventListener('mouseup', function () {
      if (state.img && state.img.isConnected) {
        positionMenu();
      }
    }, true);

    dom.win.addEventListener('scroll', function () {
      if (state.img) positionMenu();
    }, true);

    dom.win.addEventListener('resize', function () {
      if (state.img) positionMenu();
    }, true);

    window.addEventListener('scroll', function () {
      if (state.img) positionMenu();
    }, true);

    window.addEventListener('resize', function () {
      if (state.img) positionMenu();
    }, true);

    if (typeof editor.bind === 'function') {
      editor.bind('valuechanged nodechanged selectionchanged', function () {
        if (isSourceMode(editor)) {
          clearSelection();
          return;
        }

        normalizeAllEditorImages(editor);

        if (state.img && state.img.isConnected) {
          positionMenu();
        } else {
          hideMenu();
        }
      });
    }

    normalizeAllEditorImages(editor);

    editor.__afAeResizeimgState = state;
    return state;
  }

  function enhanceEditor(editor) {
    if (!editor || editor.__afAeResizeimgEnhanced) return false;

    var state = getEditorState(editor);
    if (!state) return false;

    normalizeAllEditorImages(editor);

    editor.__afAeResizeimgEnhanced = true;
    return true;
  }

  function enhanceAllEditors() {
    var sc = getSceditorRoot();
    if (!sc || typeof sc.instance !== 'function') return;

    var textareas = document.querySelectorAll('textarea');
    for (var i = 0; i < textareas.length; i += 1) {
      try {
        var editor = sc.instance(textareas[i]);
        if (editor) {
          enhanceEditor(editor);
        }
      } catch (e) {}
    }
  }

  function waitAnd(fn, maxTries) {
    var tries = 0;

    (function tick() {
      tries += 1;

      if (fn()) return;
      if (tries > (maxTries || 150)) return;

      setTimeout(tick, 100);
    })();
  }

  waitAnd(registerBbcodeFormats, 150);
  waitAnd(function () {
    enhanceAllEditors();
    return false;
  }, 20);

  for (var i = 1; i <= 20; i += 1) {
    setTimeout(enhanceAllEditors, i * 300);
  }
})(window, document);
