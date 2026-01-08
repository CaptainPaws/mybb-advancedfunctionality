/* AdvancedThreadFields (AF_ATF) */
(function () {
  "use strict";

  const AF_ATF = {
    debounce(fn, wait) {
      let t = null;
      return function (...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), wait);
      };
    },

    qs(sel, root) {
      return (root || document).querySelector(sel);
    },

    qsa(sel, root) {
      return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    },

    escapeAttr(s) {
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/"/g, "&quot;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
    },

    // --- 1) hide editor if meta flag present (НЕ трогаем остальное) ---
    applyHideEditor() {
      const meta = document.querySelector('meta[name="af-atf-hide-editor"][content="1"]');
      if (!meta) return;

      const ta = document.querySelector('textarea[name="message"]');
      if (!ta) return;

      // MyBB: чаще всего textarea лежит в <tr> таблицы
      const tr = ta.closest("tr");
      if (tr) {
        tr.style.display = "none";
      } else {
        // запасной вариант
        ta.style.display = "none";
      }

      // иногда рядом есть “toolbar”/контейнеры — прячем ближайшее окружение аккуратно
      const editorWrap = ta.closest(".sceditor-container") || ta.closest(".editor") || ta.closest(".message") || null;
      if (editorWrap) {
        editorWrap.style.display = "none";
      }
    },

    // --- 2) userchips init ---
    initUserChipsAll() {
      const blocks = AF_ATF.qsa(".af-atf-userchips");
      if (!blocks.length) return;

      blocks.forEach((wrap) => AF_ATF.initUserChipsOne(wrap));
    },

    initUserChipsOne(wrap) {
      if (!wrap || wrap.__af_atf_inited) return;
      wrap.__af_atf_inited = true;

      const hidden = AF_ATF.qs(".af-atf-userchips-hidden", wrap);
      const chipsBox = AF_ATF.qs(".af-atf-userchips-chips", wrap);
      const input = AF_ATF.qs(".af-atf-userchips-input", wrap);
      const dd = AF_ATF.qs(".af-atf-userchips-dd", wrap);

      if (!hidden || !chipsBox || !input || !dd) return;

      const suggestUrl = wrap.getAttribute("data-suggest") || "";
      const resolveUrl = wrap.getAttribute("data-resolve") || "";
      const max = parseInt(wrap.getAttribute("data-max") || "0", 10) || 0;

      const state = {
        items: [],            // текущие подсказки dropdown
        activeIndex: -1,      // навигация клавиатурой
        selected: new Map(),  // uid -> username
        suggestUrl,
        resolveUrl,
        max
      };

      // ---- helpers: selected -> hidden csv ----
      function syncHidden() {
        const uids = Array.from(state.selected.keys());
        hidden.value = uids.join(",");
      }

      function canAddMore() {
        if (!state.max || state.max <= 0) return true;
        return state.selected.size < state.max;
      }

      function renderChips() {
        chipsBox.innerHTML = "";
        for (const [uid, username] of state.selected.entries()) {
          const chip = document.createElement("span");
          chip.className = "af-atf-userchip";
          chip.setAttribute("data-uid", String(uid));

          const name = document.createElement("span");
          name.className = "af-atf-userchip-name";
          name.textContent = username;

          const btn = document.createElement("button");
          btn.type = "button";
          btn.className = "af-atf-userchip-x";
          btn.textContent = "×";
          btn.title = "Убрать";

          btn.addEventListener("click", () => {
            state.selected.delete(uid);
            syncHidden();
            renderChips();
          });

          chip.appendChild(name);
          chip.appendChild(btn);
          chipsBox.appendChild(chip);
        }
      }

      function closeDropdown() {
        dd.hidden = true;
        dd.innerHTML = "";
        state.items = [];
        state.activeIndex = -1;
      }

      function openDropdown(items) {
        state.items = Array.isArray(items) ? items : [];
        state.activeIndex = -1;

        dd.innerHTML = "";

        if (!state.items.length) {
          closeDropdown();
          return;
        }

        const ul = document.createElement("div");
        ul.className = "af-atf-userchips-dd-list";

        state.items.forEach((it, idx) => {
          const row = document.createElement("div");
          row.className = "af-atf-userchips-dd-item";
          row.setAttribute("data-index", String(idx));

          const uname = (it && it.username) ? String(it.username) : "";
          const uid = (it && it.uid) ? parseInt(it.uid, 10) : 0;

          row.textContent = uname;

          row.addEventListener("mousedown", (e) => {
            // mousedown, чтобы не потерять фокус до клика
            e.preventDefault();
            if (!uid || !uname) return;
            addSelected(uid, uname);
          });

          ul.appendChild(row);
        });

        dd.appendChild(ul);
        dd.hidden = false;
      }

      function setActive(index) {
        state.activeIndex = index;
        const rows = dd.querySelectorAll(".af-atf-userchips-dd-item");
        rows.forEach((el) => el.classList.remove("is-active"));
        if (index >= 0 && index < rows.length) {
          rows[index].classList.add("is-active");
          // ensure visible
          rows[index].scrollIntoView({ block: "nearest" });
        }
      }

      function addSelected(uid, username) {
        uid = parseInt(uid, 10);
        username = String(username || "").trim();
        if (!uid || !username) return;

        if (state.selected.has(uid)) {
          input.value = "";
          closeDropdown();
          return;
        }

        if (!canAddMore()) {
          // лимит — просто закрываем и не добавляем
          input.value = "";
          closeDropdown();
          return;
        }

        state.selected.set(uid, username);
        syncHidden();
        renderChips();

        input.value = "";
        closeDropdown();
      }

      // ---- network ----
      async function fetchSuggest(query) {
        if (!state.suggestUrl) return [];
        const url = new URL(state.suggestUrl, window.location.origin);
        url.searchParams.set("query", query);

        const r = await fetch(url.toString(), {
          method: "GET",
          credentials: "same-origin"
        });

        // если сервер вернул HTML, тут будет не json -> упадём в catch
        const data = await r.json();
        if (!data || data.ok !== 1 || !Array.isArray(data.items)) return [];
        return data.items;
      }

      async function fetchResolve(uidsCsv) {
        if (!state.resolveUrl) return [];
        const url = new URL(state.resolveUrl, window.location.origin);
        url.searchParams.set("uids", uidsCsv);

        const r = await fetch(url.toString(), {
          method: "GET",
          credentials: "same-origin"
        });

        const data = await r.json();
        if (!data || data.ok !== 1 || !Array.isArray(data.items)) return [];
        return data.items;
      }

      // ---- input listeners ----
      const doSuggest = AF_ATF.debounce(async () => {
        const q = String(input.value || "").trim();
        if (!q) {
          closeDropdown();
          return;
        }

        // если уже лимит — подсказки не нужны
        if (!canAddMore()) {
          closeDropdown();
          return;
        }

        try {
          const items = await fetchSuggest(q);

          // фильтр: убираем уже выбранных
          const filtered = items.filter((it) => {
            const uid = it && it.uid ? parseInt(it.uid, 10) : 0;
            return uid > 0 && !state.selected.has(uid);
          });

          openDropdown(filtered);
        } catch (e) {
          // молча закрываем, чтобы не ломать страницу
          closeDropdown();
        }
      }, 200);

      input.addEventListener("input", () => {
        doSuggest();
      });

      input.addEventListener("keydown", (e) => {
        if (dd.hidden) return;

        const maxIndex = state.items.length - 1;
        if (e.key === "ArrowDown") {
          e.preventDefault();
          const next = state.activeIndex < maxIndex ? state.activeIndex + 1 : 0;
          setActive(next);
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          const prev = state.activeIndex > 0 ? state.activeIndex - 1 : maxIndex;
          setActive(prev);
        } else if (e.key === "Enter") {
          if (state.activeIndex >= 0 && state.activeIndex < state.items.length) {
            e.preventDefault();
            const it = state.items[state.activeIndex];
            if (it && it.uid && it.username) {
              addSelected(it.uid, it.username);
            }
          }
        } else if (e.key === "Escape") {
          e.preventDefault();
          closeDropdown();
        }
      });

      // blur: закрываем dropdown (но даём mousedown на item сработать)
      input.addEventListener("blur", () => {
        setTimeout(() => closeDropdown(), 150);
      });

      // click outside wrap -> close
      document.addEventListener("mousedown", (e) => {
        if (!wrap.contains(e.target)) {
          closeDropdown();
        }
      });

      // ---- init from existing hidden value ----
      (async function initFromHidden() {
        const csv = String(hidden.value || "").trim();
        if (!csv) {
          renderChips();
          return;
        }

        try {
          const items = await fetchResolve(csv);
          items.forEach((it) => {
            const uid = it && it.uid ? parseInt(it.uid, 10) : 0;
            const username = it && it.username ? String(it.username) : "";
            if (uid > 0 && username) {
              state.selected.set(uid, username);
            }
          });

          // respect max
          if (state.max > 0 && state.selected.size > state.max) {
            const trimmed = new Map();
            let i = 0;
            for (const [uid, username] of state.selected.entries()) {
              trimmed.set(uid, username);
              i++;
              if (i >= state.max) break;
            }
            state.selected = trimmed;
          }

          syncHidden();
          renderChips();
        } catch (e) {
          // если resolve упал — оставим как есть, но не ломаем форму
          renderChips();
        }
      })();
    }
  };

  function boot() {
    AF_ATF.applyHideEditor();
    AF_ATF.initUserChipsAll();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  // на всякий случай: если страница частично перерисовывается скриптами темы
  window.AF_ATF = window.AF_ATF || AF_ATF;
})();
