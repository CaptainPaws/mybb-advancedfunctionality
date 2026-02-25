(function () {
  'use strict';

  if (window.__afHeaderWelcomeAvatarJsInit) return;
  window.__afHeaderWelcomeAvatarJsInit = true;

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function closest(el, sel) { return el && el.closest ? el.closest(sel) : null; }

  function normalizeName(s) {
    return String(s || '')
      .replace(/\u00A0/g, ' ')
      .replace(/[\u200B-\u200D\uFEFF]/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function isGuestWelcome(welcomeEl) {
    if (!welcomeEl) return false;
    var login = welcomeEl.querySelector('a[href*="member.php"][href*="action=login"]');
    var reg = welcomeEl.querySelector('a[href*="member.php"][href*="action=register"]');
    return !!(login || reg);
  }

  function getProfileLink(welcomeEl) {
    if (!welcomeEl) return null;
    var a = welcomeEl.querySelector('strong a[href*="member.php"]');
    if (a) return a;
    return welcomeEl.querySelector('a[href*="member.php"][href*="uid="], a[href*="member.php"][href*="action=profile"]');
  }

  function makeBtn(a, styleClass, faClass) {
    if (!a) return;
    a.classList.add('af-hw-btn');
    if (styleClass) a.classList.add(styleClass);
    a.style.backgroundImage = 'none';

    if (faClass && !a.querySelector('.af-hw-ico')) {
      var ico = document.createElement('i');
      ico.className = faClass + ' af-hw-ico';
      a.insertBefore(ico, a.firstChild);
    }
  }

  function restructureWelcomeDom(welcomeEl, mode) {
    if (!welcomeEl) return;
    if (welcomeEl.querySelector('.af-hw-line1')) return;

    var login = welcomeEl.querySelector('a[href*="member.php"][href*="action=login"]');
    var reg = welcomeEl.querySelector('a[href*="member.php"][href*="action=register"]');
    var logout = welcomeEl.querySelector('a.logout, a[href*="member.php"][href*="action=logout"]');

    if (login) makeBtn(login, 'af-hw-btn--login', 'fa-solid fa-right-to-bracket');
    if (reg) makeBtn(reg, 'af-hw-btn--reg', 'fa-solid fa-user-plus');
    if (logout) makeBtn(logout, 'af-hw-btn--logout', 'fa-solid fa-right-from-bracket');

    var line1 = document.createElement('div');
    line1.className = 'af-hw-line1';

    var line2 = document.createElement('div');
    line2.className = 'af-hw-line2';

    var actions = document.createElement('div');
    actions.className = 'af-hw-actions';

    var nodes = Array.prototype.slice.call(welcomeEl.childNodes);

    if (mode === 'user') {
      var strong = welcomeEl.querySelector('strong');

      if (strong) {
        var next = strong.nextSibling;
        while (next && next.nodeType === 3 && !String(next.nodeValue || '').replace(/[\s\u00A0]/g, '')) {
          next = next.nextSibling;
        }
        if (next && next.nodeType === 3) {
          var t = String(next.nodeValue || '');
          var re = /^[\s\u00A0]*\.[\s\u00A0]*/;
          if (re.test(t)) {
            next.nodeValue = t.replace(re, '');
            if (!String(next.nodeValue || '').replace(/[\s\u00A0]/g, '')) {
              if (next.parentNode) next.parentNode.removeChild(next);
            }
            line1.__needDotAfterStrong = true;
          }
        }
      }

      if (strong) {
        line1.appendChild(strong);
        if (!line1.__needDotAfterStrong) line1.__needDotAfterStrong = true;
        if (line1.__needDotAfterStrong) line1.appendChild(document.createTextNode('. '));
      }

      nodes = Array.prototype.slice.call(nodes);
      nodes.forEach(function (n) {
        if (n === line1 || n === line2 || n === actions) return;
        if (strong && n === strong) return;
        if (logout && n === logout) return;
        if (n.nodeType === 3 && !String(n.nodeValue || '').replace(/[\s\u00A0]/g, '')) return;
        line2.appendChild(n);
      });

      if (logout) actions.appendChild(logout);
    } else {
      nodes.forEach(function (n) {
        if (login && n === login) return;
        if (reg && n === reg) return;
        if (n.nodeType === 3 && !String(n.nodeValue || '').replace(/[\s\u00A0]/g, '')) return;
        line1.appendChild(n);
      });

      if (login) actions.appendChild(login);
      if (reg) actions.appendChild(reg);
    }

    welcomeEl.textContent = '';
    welcomeEl.appendChild(line1);

    if (mode === 'user' && line2.childNodes.length) welcomeEl.appendChild(line2);
    if (actions.childNodes.length) welcomeEl.appendChild(actions);

    if (!line1.textContent.trim()) {
      line1.textContent = (mode === 'guest') ? 'Здравствуйте!' : 'С возвращением!';
    }
  }

  function applyOne(welcomeEl) {
    if (!welcomeEl) return;

    var wrap = closest(welcomeEl, '.af-hw-wrap');
    if (!wrap) return;

    welcomeEl.classList.add('af-hw-welcome');

    var avatarLink = wrap.querySelector('.af-hw-avatarlink');
    var avatarImg = wrap.querySelector('.af-hw-avatar');

    var guest = isGuestWelcome(welcomeEl);

    if (guest) {
      if (avatarLink) avatarLink.href = 'member.php?action=login';
      if (avatarImg) avatarImg.alt = 'guest';
      restructureWelcomeDom(welcomeEl, 'guest');
      wrap.classList.add('af-hw--guest');
      return;
    }

    var profileA = getProfileLink(welcomeEl);
    var href = profileA ? (profileA.getAttribute('href') || '') : '';
    if (avatarLink) avatarLink.href = href || 'member.php?action=profile';
    if (avatarImg) avatarImg.alt = 'avatar';

    restructureWelcomeDom(welcomeEl, 'user');
  }

  function findWelcomeInHeader() {
    return (
      qs('#panel span.welcome') ||
      qs('.panel span.welcome') ||
      qs('#header span.welcome') ||
      qs('header span.welcome') ||
      qs('span.welcome') ||
      null
    );
  }

  function observe() {
    var mo = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (!m.addedNodes || !m.addedNodes.length) continue;

        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (!n || n.nodeType !== 1) continue;

          var w = (n.matches && n.matches('span.welcome')) ? n : (n.querySelector ? n.querySelector('span.welcome') : null);
          if (!w) continue;

          var inHeader = closest(w, '#panel') || closest(w, '.panel') || closest(w, '#header') || closest(w, 'header');
          if (!inHeader) continue;

          if (closest(w, '.af-hw-wrap')) applyOne(w);
        }
      }
    });

    mo.observe(document.documentElement, { childList: true, subtree: true });
  }

  onReady(function () {
    var welcome = findWelcomeInHeader();
    if (welcome && closest(welcome, '.af-hw-wrap')) applyOne(welcome);
    observe();
  });

})();
