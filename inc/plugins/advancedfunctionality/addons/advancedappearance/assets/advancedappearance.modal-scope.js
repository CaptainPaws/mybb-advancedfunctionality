(function (window, document) {
  'use strict';

  if (window.__afAaModalScopeLoaded) return;
  window.__afAaModalScopeLoaded = true;

  var ALLOWED_SURFACES = {
    sheet: true
  };

  var OBSERVER_KEY = '__afAaScopeObserver';

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function trim(value) {
    return asText(value).trim();
  }

  function safeLower(value) {
    return trim(value).toLowerCase();
  }

  function getAttr(el, name) {
    if (!el || !name) return '';
    var v = el.getAttribute(name);
    return v == null ? '' : trim(v);
  }

  function setAttr(el, name, value) {
    if (!el || !name) return;
    el.setAttribute(name, asText(value));
  }

  function removeAttr(el, name) {
    if (!el || !name) return;
    el.removeAttribute(name);
  }

  function hasClass(el, className) {
    return !!(el && el.classList && className && el.classList.contains(className));
  }

  function parseUrl(url) {
    url = trim(url);
    if (!url) return null;

    try {
      return new URL(url, window.location.href);
    } catch (e) {
      return null;
    }
  }

  function getQueryParam(url, key) {
    var u = parseUrl(url);
    if (!u) return '';
    return trim(u.searchParams.get(key) || '');
  }

  function getQueryInt(url, key) {
    var v = getQueryParam(url, key);
    var n = parseInt(v, 10);
    return isFinite(n) ? n : 0;
  }

  function normalizeSurface(surface) {
    surface = safeLower(surface);
    if (surface === 'achivments') surface = 'achievements';
    return ALLOWED_SURFACES[surface] ? surface : '';
  }

  function getModalUrlCandidate(opener) {
    var url =
      getAttr(opener, 'data-af-apui-modal-url') ||
      getAttr(opener, 'data-af-apui-fetch-url') ||
      '';

    if (!url && opener && opener.tagName === 'A') {
      url = getAttr(opener, 'href');
    }

    if (!url) {
      url = getAttr(opener, 'data-afcs-sheet');
    }

    return url;
  }

  function resolveSurface(opener) {
    var s = normalizeSurface(getAttr(opener, 'data-af-apui-surface'));
    if (s) return s;

    s = normalizeSurface(getAttr(opener, 'data-af-apui-modal-kind'));
    if (s) return s;

    s = normalizeSurface(getAttr(opener, 'data-af-modal-kind'));
    if (s) return s;

    var url = getModalUrlCandidate(opener);
    s = normalizeSurface(getQueryParam(url, 'af_apui_surface'));
    if (s) return s;

    return '';
  }

  function resolveUid(opener) {
    var uid = parseInt(getAttr(opener, 'data-af-apui-owner-uid'), 10);
    if (isFinite(uid) && uid > 0) return uid;

    var url = getModalUrlCandidate(opener);

    uid = getQueryInt(url, 'af_apui_owner_uid');
    if (uid > 0) return uid;

    uid = getQueryInt(url, 'uid');
    if (uid > 0) return uid;

    uid = parseInt(getAttr(opener, 'data-uid'), 10);
    if (isFinite(uid) && uid > 0) return uid;

    return 0;
  }

  function findOpenModal() {
    return document.querySelector('.af-cs-modal.is-open');
  }

  function removeSurfaceUserClasses(node) {
    if (!node || !node.classList) return;

    var toRemove = [];
    node.classList.forEach(function (cls) {
      if (cls && cls.indexOf('af-aa-surface-user-') === 0) {
        toRemove.push(cls);
      }
    });

    toRemove.forEach(function (cls) {
      node.classList.remove(cls);
    });
  }

  function cleanupScopedNode(node) {
    if (!node || node.nodeType !== 1) return;

    removeSurfaceUserClasses(node);
    removeAttr(node, 'data-af-apui-owner-uid');
    removeAttr(node, 'data-uid');
    removeAttr(node, 'data-af-apui-surface');
  }

  function applyScopedNode(node, uid, surface) {
    if (!node || node.nodeType !== 1 || uid <= 0 || !surface) return;

    if (node.classList) {
      node.classList.add('af-aa-surface-user-' + uid);
    }

    setAttr(node, 'data-af-apui-owner-uid', String(uid));
    setAttr(node, 'data-uid', String(uid));
    setAttr(node, 'data-af-apui-surface', surface);
  }

  function nodeMatchesSurface(node, surface) {
    if (!node || node.nodeType !== 1) return false;

    if (
      hasClass(node, 'af-cs-modal__body') ||
      hasClass(node, 'af-cs-modal__content') ||
      hasClass(node, 'af-cs-modal__frame')
    ) {
      return true;
    }

    if (normalizeSurface(getAttr(node, 'data-af-apui-surface')) === surface) {
      return true;
    }

    if (surface === 'sheet') {
      return hasClass(node, 'af-apui-sheet-fragment') || hasClass(node, 'af-cs-page');
    }

    return false;
  }

  function collectScopedNodes(modal) {
    var nodes = [];

    function push(node) {
      if (!node || node.nodeType !== 1) return;
      if (nodes.indexOf(node) === -1) {
        nodes.push(node);
      }
    }

    push(modal);

    var selector = [
      '.af-cs-modal__body',
      '.af-cs-modal__content',
      '.af-cs-modal__frame',
      '.af-apui-sheet-fragment',
      '.af-apui-surface-page',
      '.af-apui-surface-body',
      '.af-apui-surface-content',
      '.af-cs-page'
    ].join(',');

    modal.querySelectorAll(selector).forEach(push);

    return nodes;
  }

  function applySurfaceScope(modal, uid, surface) {
    if (!modal || uid <= 0 || !surface) return;

    collectScopedNodes(modal).forEach(function (node) {
      cleanupScopedNode(node);

      if (node === modal || nodeMatchesSurface(node, surface)) {
        applyScopedNode(node, uid, surface);
      }
    });

  }

  function installObserver(modal, uid, surface) {
    if (!modal || !window.MutationObserver || uid <= 0 || !surface) return;

    if (modal[OBSERVER_KEY] && typeof modal[OBSERVER_KEY].disconnect === 'function') {
      modal[OBSERVER_KEY].disconnect();
    }

    var target = modal.querySelector('.af-cs-modal__body') || modal;

    var observer = new MutationObserver(function () {
      applySurfaceScope(modal, uid, surface);
    });

    observer.observe(target, {
      childList: true,
      subtree: true
    });

    modal[OBSERVER_KEY] = observer;
  }

  function waitAndApply(uid, surface) {
    if (uid <= 0 || !surface) return;

    var maxAttempts = 20;
    var intervalMs = 40;
    var attempt = 0;

    function tick() {
      attempt += 1;

      var modal = findOpenModal();
      if (modal) {
        applySurfaceScope(modal, uid, surface);
        installObserver(modal, uid, surface);
        return;
      }

      if (attempt < maxAttempts) {
        window.setTimeout(tick, intervalMs);
      }
    }

    window.setTimeout(tick, 0);
  }

  function getOpenerFromEvent(event) {
    if (!event) return null;

    var target = event.target;
    if (!target || typeof target.closest !== 'function') return null;

    return target.closest('[data-af-apui-modal-url],[data-afcs-open],[data-afcs-sheet]');
  }

  document.addEventListener(
    'click',
    function (event) {
      var opener = getOpenerFromEvent(event);
      if (!opener) return;

      var uid = resolveUid(opener);
      var surface = resolveSurface(opener);

      if (uid <= 0 || !surface) return;

      waitAndApply(uid, surface);
    },
    true
  );
})(window, document);
