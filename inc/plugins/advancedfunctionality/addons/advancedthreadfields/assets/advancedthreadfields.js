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

    getKbEndpoint() {
      const meta = document.querySelector('meta[name="af-atf-kb-endpoint"]');
      if (meta && meta.content) return meta.content;
      return "/misc.php?action=af_kb_get";
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

    initKbSelects() {
      const blocks = AF_ATF.qsa(".af-atf-kb-select");
      if (!blocks.length) return;

      blocks.forEach((wrap) => {
        const select = AF_ATF.qs(".af-atf-kb-select-input", wrap);
        const preview = AF_ATF.qs(".af-atf-kb-preview", wrap);
        if (!select || !preview) return;

        const kbType = wrap.getAttribute("data-kb-type") || "";

        function renderPreview() {
          const key = String(select.value || "").trim();
          if (!key) {
            preview.innerHTML = "";
            return;
          }
          const label = select.options[select.selectedIndex]
            ? select.options[select.selectedIndex].textContent
            : key;
          const chip = document.createElement("span");
          chip.className = "af_kb_chip";
          chip.setAttribute("data-kb-type", kbType);
          chip.setAttribute("data-kb-key", key);
          chip.textContent = label;
          preview.innerHTML = "";
          preview.appendChild(chip);
        }

        select.addEventListener("change", renderPreview);
        renderPreview();
      });
    },

    initKbChips() {
      if (AF_ATF.__kbChipsInited) return;
      AF_ATF.__kbChipsInited = true;

      const cache = new Map();
      const endpoint = AF_ATF.getKbEndpoint();
      let modalState = window.__afAtfKbModal || null;
      let requestCounter = 0;
      let activeRequestId = 0;

      function destroyExistingModal() {
        AF_ATF.qsa(".af-atf-kb-modal").forEach((node) => node.remove());
        AF_ATF.qsa(".af-atf-kb-modal-backdrop").forEach((node) => node.remove());
        document.body.classList.remove("modal-open");
        if (modalState) {
          modalState.closeBtn.removeEventListener("click", modalState.onClose);
          modalState.backdrop.removeEventListener("click", modalState.onBackdrop);
          document.removeEventListener("keydown", modalState.onKeydown);
        }
        modalState = null;
        window.__afAtfKbModal = null;
      }

      function buildModal() {
        destroyExistingModal();

        const backdrop = document.createElement("div");
        backdrop.className = "af-atf-kb-modal-backdrop";
        backdrop.hidden = true;

        const modal = document.createElement("div");
        modal.className = "af-atf-kb-modal";

        const header = document.createElement("div");
        header.style.display = "flex";
        header.style.gap = "10px";
        header.style.alignItems = "center";

        const title = document.createElement("div");
        title.className = "af-atf-kb-modal-title";

        const close = document.createElement("button");
        close.type = "button";
        close.className = "af-atf-kb-modal-close";
        close.textContent = "×";

        header.appendChild(title);
        header.appendChild(close);

        const body = document.createElement("div");
        body.className = "af-atf-kb-modal-body";

        modal.appendChild(header);
        modal.appendChild(body);
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        const onClose = (event) => {
          if (event) event.preventDefault();
          destroyExistingModal();
        };

        const onBackdrop = (event) => {
          if (event.target === backdrop) onClose(event);
        };

        const onKeydown = (event) => {
          if (event.key === "Escape" && !backdrop.hidden) {
            onClose(event);
          }
        };

        close.addEventListener("click", onClose);
        backdrop.addEventListener("click", onBackdrop);
        document.addEventListener("keydown", onKeydown);

        modalState = {
          backdrop,
          title,
          body,
          closeBtn: close,
          onClose,
          onBackdrop,
          onKeydown,
          show(entry) {
            title.textContent = entry.title || "";
            body.innerHTML = "";

            if (entry.short_html) {
              const shortBlock = document.createElement("div");
              shortBlock.innerHTML = entry.short_html;
              body.appendChild(shortBlock);
            }
            if (entry.body_html) {
              const bodyBlock = document.createElement("div");
              bodyBlock.innerHTML = entry.body_html;
              body.appendChild(bodyBlock);
            }

            if (Array.isArray(entry.blocks)) {
              entry.blocks.forEach((block) => {
                if (!block) return;
                const blockTitle = String(block.title || "");
                const blockHtml = String(block.body_html || block.content_html || "");
                if (!blockTitle && !blockHtml) return;

                const section = document.createElement("section");
                if (blockTitle) {
                  const h4 = document.createElement("h4");
                  h4.textContent = blockTitle;
                  section.appendChild(h4);
                }
                if (blockHtml) {
                  const bodyWrap = document.createElement("div");
                  bodyWrap.innerHTML = blockHtml;
                  section.appendChild(bodyWrap);
                }
                body.appendChild(section);
              });
            }

            backdrop.hidden = false;
          }
        };

        window.__afAtfKbModal = modalState;
        return modalState;
      }

      async function fetchEntry(type, key) {
        const cacheKey = `${type}:${key}`;
        if (cache.has(cacheKey)) {
          return cache.get(cacheKey);
        }

        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set("type", type);
        url.searchParams.set("key", key);

        const resp = await fetch(url.toString(), {
          method: "GET",
          credentials: "same-origin"
        });
        const data = await resp.json();
        if (!data || data.ok !== 1 || !data.entry) {
          return null;
        }
        cache.set(cacheKey, data.entry);
        return data.entry;
      }

      document.addEventListener("click", async (e) => {
        const chip = e.target.closest(".af_kb_chip");
        if (!chip) return;
        e.preventDefault();

        const type = chip.getAttribute("data-kb-type") || "";
        const key = chip.getAttribute("data-kb-key") || "";
        if (!type || !key) return;

        const requestId = ++requestCounter;
        activeRequestId = requestId;
        destroyExistingModal();

        try {
          const entry = await fetchEntry(type, key);
          if (requestId !== activeRequestId || !entry) return;
          const modal = modalState || buildModal();
          if (requestId !== activeRequestId) return;
          modal.show(entry);
        } catch (err) {
          // ignore fetch errors
        }
      });
    },

    initKbCatalogCtaModal() {
      if (AF_ATF.__kbCatalogCtaInited) return;
      AF_ATF.__kbCatalogCtaInited = true;

      let modalState = null;
      const KB_IFRAME_MODAL_STYLE_ID = "af-atf-kb-catalog-modal-frame-style";
      const DEFAULT_MODAL_TITLE = "Персонажи";
      const LOADER_HTML =
        '<div style="width:min(90%,1170px);height:34px;border-radius:999px;background:rgba(255,255,255,.12);box-shadow:inset 0 0 0 1px rgba(255,255,255,.05);"></div>';

      function normalizeTarget(target) {
        if (!target) return null;
        if (target.nodeType === 3) {
          return target.parentElement || null;
        }
        return target instanceof Element ? target : null;
      }

      function stashStyle(node) {
        if (!node || !node.style) return;
        if (node.hasAttribute("data-af-atf-kb-style-stash")) return;
        node.setAttribute("data-af-atf-kb-style-stash", node.getAttribute("style") || "");
      }

      function applyStyle(node, property, value) {
        if (!node || !node.style) return;
        stashStyle(node);
        node.style.setProperty(property, value, "important");
      }

      function resetIframePresentation(frameDoc) {
        if (!frameDoc || !frameDoc.body) return;

        const nodes = frameDoc.querySelectorAll("[data-af-atf-kb-style-stash]");
        nodes.forEach((node) => {
          const oldStyle = node.getAttribute("data-af-atf-kb-style-stash");
          if (oldStyle) {
            node.setAttribute("style", oldStyle);
          } else {
            node.removeAttribute("style");
          }
          node.removeAttribute("data-af-atf-kb-style-stash");
        });

        frameDoc.documentElement.classList.remove("af-atf-kb-modal-frame");
        frameDoc.body.classList.remove("af-atf-kb-modal-frame");
      }

      function ensureIframeModalStyle(frameDoc) {
        if (!frameDoc || !frameDoc.head) return;
        if (frameDoc.getElementById(KB_IFRAME_MODAL_STYLE_ID)) return;

        const style = frameDoc.createElement("style");
        style.id = KB_IFRAME_MODAL_STYLE_ID;
        style.textContent =
          "html.af-atf-kb-modal-frame, body.af-atf-kb-modal-frame{" +
            "margin:0!important;" +
            "padding:0!important;" +
            "min-height:100%!important;" +
            "background:#0d1118!important;" +
            "color:inherit!important;" +
          "}" +
          "body.af-atf-kb-modal-frame{" +
            "overflow:auto!important;" +
          "}" +

          /* главный контейнер KB — на всю ширину iframe */
          "body.af-atf-kb-modal-frame .af-kb-page{" +
            "width:100%!important;" +
            "max-width:none!important;" +
            "margin:0!important;" +
            "padding:20px 24px!important;" +
            "box-sizing:border-box!important;" +
            "background:transparent!important;" +
          "}" +

          /* внутренние layout-обёртки KB тоже не должны зажиматься */
          "body.af-atf-kb-modal-frame .af-kb-layout," +
          "body.af-atf-kb-modal-frame .af-kb-main," +
          "body.af-atf-kb-modal-frame .af-kb-content," +
          "body.af-atf-kb-modal-frame .af-kb-entries," +
          "body.af-atf-kb-modal-frame .af-kb-topcats," +
          "body.af-atf-kb-modal-frame .af-kb-sidebar{" +
            "width:100%!important;" +
            "max-width:none!important;" +
            "box-sizing:border-box!important;" +
          "}" +

          /* на случай если внутри KB есть wrapper темы */
          "body.af-atf-kb-modal-frame .wrapper," +
          "body.af-atf-kb-modal-frame #content," +
          "body.af-atf-kb-modal-frame main{" +
            "width:100%!important;" +
            "max-width:none!important;" +
            "margin:0!important;" +
            "padding:0!important;" +
            "box-sizing:border-box!important;" +
          "}";

        frameDoc.head.appendChild(style);
      }

      function resolveFrameRoot(frameDoc) {
        if (!frameDoc || !frameDoc.body) return null;

        return (
          frameDoc.querySelector(".af-kb-page") ||
          frameDoc.querySelector("[data-af-kb-page]") ||
          frameDoc.querySelector("main .af-kb-page") ||
          frameDoc.querySelector("#content .af-kb-page") ||
          frameDoc.querySelector("main") ||
          frameDoc.querySelector("#content") ||
          frameDoc.querySelector(".wrapper") ||
          frameDoc.body.firstElementChild ||
          frameDoc.body
        );
      }

      function hideSiblingsAlongPath(node, frameDoc) {
        let current = node;
        while (current && current !== frameDoc.body) {
          const parent = current.parentElement;
          if (!parent) break;

          Array.prototype.forEach.call(parent.children, (sibling) => {
            if (sibling !== current) {
              applyStyle(sibling, "display", "none");
            }
          });

          current = parent;
        }
      }

      function applyIframeModalPresentation(frameDoc) {
        if (!frameDoc || !frameDoc.body) {
          return false;
        }

        resetIframePresentation(frameDoc);
        ensureIframeModalStyle(frameDoc);

        const root = resolveFrameRoot(frameDoc);

        frameDoc.documentElement.classList.add("af-atf-kb-modal-frame");
        frameDoc.body.classList.add("af-atf-kb-modal-frame");

        applyStyle(frameDoc.documentElement, "background", "#0d1118");
        applyStyle(frameDoc.body, "background", "#0d1118");
        applyStyle(frameDoc.body, "margin", "0");
        applyStyle(frameDoc.body, "padding", "0");
        applyStyle(frameDoc.body, "overflow", "auto");

        if (root && root !== frameDoc.body && root !== frameDoc.documentElement) {
          hideSiblingsAlongPath(root, frameDoc);
          applyStyle(root, "display", "block");
          applyStyle(root, "visibility", "visible");
          applyStyle(root, "margin-left", "auto");
          applyStyle(root, "margin-right", "auto");
        }

        return true;
      }

      function applyCriticalModalStyles(backdrop, modal, header, body, iframe, closeBtn, loader) {
        backdrop.style.position = "fixed";
        backdrop.style.inset = "0";
        backdrop.style.zIndex = "999999";
        backdrop.style.display = "none";
        backdrop.style.alignItems = "center";
        backdrop.style.justifyContent = "center";
        backdrop.style.background = "rgba(0,0,0,.72)";
        backdrop.style.padding = "24px";
        backdrop.style.boxSizing = "border-box";

        modal.style.position = "relative";
        modal.style.display = "flex";
        modal.style.flexDirection = "column";
        modal.style.width = "min(1400px, 96vw)";
        modal.style.height = "min(900px, 92vh)";
        modal.style.background = "#0d1118";
        modal.style.border = "1px solid rgba(255,255,255,.12)";
        modal.style.borderRadius = "14px";
        modal.style.overflow = "hidden";
        modal.style.boxShadow = "0 20px 60px rgba(0,0,0,.45)";

        header.style.display = "flex";
        header.style.alignItems = "center";
        header.style.justifyContent = "space-between";
        header.style.gap = "16px";
        header.style.padding = "14px 18px";
        header.style.flex = "0 0 auto";
        header.style.background = "#05070b";

        body.style.position = "relative";
        body.style.flex = "1 1 auto";
        body.style.minHeight = "0";
        body.style.background = "#0d1118";

        iframe.style.display = "block";
        iframe.style.width = "100%";
        iframe.style.height = "100%";
        iframe.style.border = "0";
        iframe.style.background = "transparent";
        iframe.style.visibility = "hidden";
        iframe.style.opacity = "0";
        iframe.style.transition = "opacity .15s ease";

        loader.style.position = "absolute";
        loader.style.inset = "0";
        loader.style.display = "flex";
        loader.style.alignItems = "center";
        loader.style.justifyContent = "center";
        loader.style.padding = "24px";
        loader.style.boxSizing = "border-box";
        loader.style.background = "#0d1118";
        loader.style.zIndex = "2";
        loader.style.color = "#fff";

        closeBtn.style.cursor = "pointer";
        closeBtn.style.fontSize = "28px";
        closeBtn.style.lineHeight = "1";
        closeBtn.style.border = "0";
        closeBtn.style.background = "transparent";
        closeBtn.style.color = "#fff";
        closeBtn.style.padding = "0 4px";
      }

      function resetLoader(state) {
        if (!state) return;
        state.loader.innerHTML = LOADER_HTML;
      }

      function setLoadingState(state, loading) {
        if (!state) return;

        state.loader.style.display = loading ? "flex" : "none";
        state.frame.style.visibility = loading ? "hidden" : "visible";
        state.frame.style.opacity = loading ? "0" : "1";
      }

      function setFallbackMessage(state, message) {
        if (!state) return;

        state.loader.innerHTML =
          '<div style="max-width:780px;padding:16px 20px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#fff;text-align:center;line-height:1.55;">' +
          AF_ATF.escapeAttr(message) +
          "</div>";

        state.loader.style.display = "flex";
        state.frame.style.visibility = "hidden";
        state.frame.style.opacity = "0";
      }

      function destroyModal() {
        AF_ATF.qsa(".af-atf-kb-catalog-modal-backdrop").forEach((node) => node.remove());
        document.body.classList.remove("af-atf-kb-catalog-modal-open");

        if (modalState) {
          modalState.closeBtn.removeEventListener("click", modalState.onClose);
          modalState.backdrop.removeEventListener("click", modalState.onBackdrop);
          document.removeEventListener("keydown", modalState.onKeydown);

          if (modalState.frame && modalState.onFrameLoad) {
            modalState.frame.removeEventListener("load", modalState.onFrameLoad);
          }
        }

        modalState = null;
      }

      function buildModal() {
        destroyModal();

        const backdrop = document.createElement("div");
        backdrop.className = "af-atf-kb-catalog-modal-backdrop";

        const modal = document.createElement("div");
        modal.className = "af-atf-kb-catalog-modal";
        modal.setAttribute("role", "dialog");
        modal.setAttribute("aria-modal", "true");

        const header = document.createElement("div");
        header.className = "af-atf-kb-catalog-modal-head";

        const title = document.createElement("div");
        title.className = "af-atf-kb-catalog-modal-title";

        const close = document.createElement("button");
        close.type = "button";
        close.className = "af-atf-kb-catalog-modal-close";
        close.setAttribute("aria-label", "Закрыть");
        close.textContent = "×";

        const body = document.createElement("div");
        body.className = "af-atf-kb-catalog-modal-body";

        const loader = document.createElement("div");
        loader.className = "af-atf-kb-catalog-modal-loader";
        loader.innerHTML = LOADER_HTML;

        const iframe = document.createElement("iframe");
        iframe.className = "af-atf-kb-catalog-modal-frame";
        iframe.setAttribute("loading", "lazy");
        iframe.setAttribute("referrerpolicy", "same-origin");
        iframe.setAttribute("src", "about:blank");

        applyCriticalModalStyles(backdrop, modal, header, body, iframe, close, loader);

        body.appendChild(loader);
        body.appendChild(iframe);
        header.appendChild(title);
        header.appendChild(close);
        modal.appendChild(header);
        modal.appendChild(body);
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        const onClose = (event) => {
          if (event) event.preventDefault();

          backdrop.style.display = "none";
          document.body.classList.remove("af-atf-kb-catalog-modal-open");

          try {
            iframe.setAttribute("src", "about:blank");
          } catch (e) {
            // ignore
          }

          destroyModal();
        };

        const onBackdrop = (event) => {
          if (event.target === backdrop) {
            onClose(event);
          }
        };

        const onKeydown = (event) => {
          if (event.key === "Escape") {
            onClose(event);
          }
        };

        const onFrameLoad = () => {
          const currentSrc = String(iframe.getAttribute("src") || "").trim();
          if (!currentSrc || currentSrc === "about:blank") {
            return;
          }

          let doc = null;
          let hasMeaningfulContent = false;

          try {
            doc = iframe.contentDocument;
            applyIframeModalPresentation(doc);
            hasMeaningfulContent = !!(
              doc &&
              doc.body &&
              String(doc.body.textContent || "").replace(/\s+/g, "").length
            );
          } catch (err) {
            doc = null;
          }

          window.requestAnimationFrame(() => {
            if (!doc) {
              setFallbackMessage(
                modalState,
                "Не удалось прочитать содержимое KB внутри iframe."
              );
              return;
            }

            if (!hasMeaningfulContent) {
              setFallbackMessage(
                modalState,
                "Контент сейчас не отрисовался. Проверь права просмотра KB и публичность категории."
              );
              return;
            }

            setLoadingState(modalState, false);
          });
        };

        close.addEventListener("click", onClose);
        backdrop.addEventListener("click", onBackdrop);
        document.addEventListener("keydown", onKeydown);
        iframe.addEventListener("load", onFrameLoad);

        modalState = {
          backdrop,
          modal,
          frame: iframe,
          loader,
          closeBtn: close,
          onClose,
          onBackdrop,
          onKeydown,
          onFrameLoad,
          open(src, modalTitle) {
            const cleanSrc = String(src || "").trim();
            if (!cleanSrc) return;

            title.textContent = String(modalTitle || DEFAULT_MODAL_TITLE).trim() || DEFAULT_MODAL_TITLE;

            backdrop.style.display = "flex";
            document.body.classList.add("af-atf-kb-catalog-modal-open");

            resetLoader(modalState);
            setLoadingState(modalState, true);

            iframe.setAttribute("src", "about:blank");
            window.setTimeout(() => {
              iframe.setAttribute("src", cleanSrc);
            }, 0);
          }
        };

        return modalState;
      }

      function openFromOpener(opener) {
        if (!opener) return;

        const href = String(opener.getAttribute("href") || "").trim();
        if (!href || href === "#" || href.toLowerCase().indexOf("javascript:") === 0) {
          return;
        }

        const modalTitle = String(
          opener.getAttribute("data-af-atf-modal-title") ||
          opener.textContent ||
          DEFAULT_MODAL_TITLE
        ).trim();

        const modal = modalState || buildModal();
        modal.open(href, modalTitle);
      }

      function onCatalogCtaClick(event) {
        const target = normalizeTarget(event.target);
        if (!target) return;

        const opener = target.closest("[data-af-atf-kb-catalog-cta]");
        if (!opener) return;

        const href = String(opener.getAttribute("href") || "").trim();
        if (!href) return;

        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === "function") {
          event.stopImmediatePropagation();
        }

        openFromOpener(opener);
        return false;
      }

      document.addEventListener("click", onCatalogCtaClick, true);

      AF_ATF.qsa("[data-af-atf-kb-catalog-cta]").forEach((opener) => {
        if (opener.__afAtfKbCatalogBound) return;
        opener.__afAtfKbCatalogBound = true;

        opener.addEventListener("click", function (event) {
          event.preventDefault();
          event.stopPropagation();
          if (typeof event.stopImmediatePropagation === "function") {
            event.stopImmediatePropagation();
          }

          openFromOpener(this);
          return false;
        }, true);
      });
    },

    initPointBuyAll() {
      const blocks = AF_ATF.qsa(".af-atf-pointbuy");
      if (!blocks.length) return;
      blocks.forEach((wrap) => AF_ATF.initPointBuyOne(wrap));
    },

    initPointBuyOne(wrap) {
      if (!wrap || wrap.__af_atf_pointbuy) return;
      wrap.__af_atf_pointbuy = true;

      const hidden = AF_ATF.qs(".af-atf-pointbuy-hidden", wrap);
      const inputs = AF_ATF.qsa(".af-atf-pointbuy-input", wrap);
      if (!hidden || !inputs.length) return;

      const total = parseInt(wrap.getAttribute("data-total") || "0", 10);
      const min = parseInt(wrap.getAttribute("data-min") || "0", 10);
      const max = parseInt(wrap.getAttribute("data-max") || "0", 10);
      const base = parseInt(wrap.getAttribute("data-base") || "0", 10);
      const allowNegative = wrap.getAttribute("data-allow-negative") === "1";
      const requireExact = wrap.getAttribute("data-require-exact") === "1";
      const errOver = wrap.getAttribute("data-err-overbudget") || "Over budget";
      const errRange = wrap.getAttribute("data-err-out-of-range") || "Out of range";
      const errExact = wrap.getAttribute("data-err-not-exact") || "Not exact";
      let curve = {};
      try {
        curve = JSON.parse(wrap.getAttribute("data-cost-curve") || "{}");
      } catch (e) {
        curve = {};
      }

      const spentEl = AF_ATF.qs(".af-atf-pointbuy-spent", wrap);
      const remainingEl = AF_ATF.qs(".af-atf-pointbuy-remaining", wrap);
      const errorEl = AF_ATF.qs(".af-atf-pointbuy-errors", wrap);

      function stepCost(from, to) {
        if (!curve || !curve.costs) return null;
        const key = `${from}->${to}`;
        if (Object.prototype.hasOwnProperty.call(curve.costs, key)) {
          return parseInt(curve.costs[key], 10);
        }
        return null;
      }

      function calcCost(value) {
        if (value === base) return { cost: 0, invalid: false };
        let cost = 0;
        if (value > base) {
          for (let i = base; i < value; i += 1) {
            const step = stepCost(i, i + 1);
            if (step === null) return { cost: 0, invalid: true };
            cost += step;
          }
          return { cost, invalid: false };
        }
        for (let i = base; i > value; i -= 1) {
          const step = stepCost(i - 1, i);
          if (step === null) return { cost: 0, invalid: true };
          cost -= step;
        }
        return { cost, invalid: false };
      }

      function collectValues() {
        const values = {};
        inputs.forEach((input) => {
          const code = input.getAttribute("data-attr");
          if (!code) return;
          values[code] = parseInt(input.value || "0", 10);
        });
        return values;
      }

      function update() {
        const values = collectValues();
        let spent = 0;
        let hasRangeError = false;
        let hasCostError = false;

        Object.keys(values).forEach((code) => {
          const val = values[code];
          if (Number.isNaN(val) || val < min || val > max) {
            hasRangeError = true;
            return;
          }
          const result = calcCost(val);
          if (result.invalid) {
            hasCostError = true;
            return;
          }
          spent += result.cost;
        });

        const remaining = total - spent;
        if (spentEl) spentEl.textContent = String(spent);
        if (remainingEl) remainingEl.textContent = String(remaining);

        const errors = [];
        if (hasRangeError || hasCostError) errors.push(errRange);
        if (!allowNegative && spent > total) errors.push(errOver);
        if (requireExact && spent !== total) errors.push(errExact);

        if (errorEl) {
          errorEl.textContent = errors.join(" · ");
        }

        hidden.value = JSON.stringify(values);
      }

      inputs.forEach((input) => {
        input.addEventListener("input", update);
        input.addEventListener("change", update);
      });

      update();
    },

    initDynamicKbPreviews() {
      AF_ATF.qsa(".af-atf-kb-dynamic").forEach((wrap) => {
        const preview = AF_ATF.qs(".af-atf-kb-dynamic-preview[data-preview-role='element']", wrap);
        const select = AF_ATF.qs("select", wrap);
        if (!preview || !select) return;

        function render() {
          const option = select.options[select.selectedIndex] || null;
          const key = option ? String(option.value || "").trim() : "";
          if (!key) {
            preview.innerHTML = "";
            return;
          }

          const label = option ? String(option.textContent || key) : key;
          const iconUrl = option ? String(option.getAttribute("data-icon-url") || "").trim() : "";
          const iconClass = option ? String(option.getAttribute("data-icon-class") || "").trim() : "";
          const tooltip = option ? String(option.getAttribute("data-tooltip") || "").trim() : "";

          const chip = document.createElement("span");
          chip.className = "af_kb_chip af-atf-element-chip";
          if (tooltip) chip.title = tooltip;

          if (iconUrl) {
            const img = document.createElement("img");
            img.className = "af-atf-element-icon";
            img.src = iconUrl;
            img.alt = "";
            chip.appendChild(img);
          } else if (iconClass) {
            const icon = document.createElement("i");
            icon.className = `af-atf-element-icon ${iconClass}`;
            chip.appendChild(icon);
          }

          const text = document.createElement("span");
          text.className = "af-atf-element-label";
          text.textContent = label;
          chip.appendChild(text);

          preview.innerHTML = "";
          preview.appendChild(chip);
        }

        select.addEventListener("change", render);
        render();
      });
    },

    initCharacterMechanic() {
      const switcher = AF_ATF.qs(".af-atf-character-mechanic");
      if (!switcher) return;

      function parseOptions(raw) {
        try {
          const parsed = JSON.parse(raw || "{}");
          return parsed && typeof parsed === "object" ? parsed : {};
        } catch (e) {
          return {};
        }
      }

      function renderMechanicSelects(mode) {
        AF_ATF.qsa(".af-atf-kb-mechanic").forEach((wrap) => {
          const select = AF_ATF.qs("select", wrap);
          if (!select) return;
          const optionsByMode = parseOptions(wrap.getAttribute("data-options"));
          const options = Array.isArray(optionsByMode[mode]) ? optionsByMode[mode] : [];
          const current = String(select.value || "");

          let html = '<option value=""></option>';
          let hasCurrent = false;
          options.forEach((item) => {
            const key = item && item.key ? String(item.key) : "";
            if (!key) return;
            if (key === current) hasCurrent = true;
            const title = item && item.title ? String(item.title) : key;
            html += `<option value="${AF_ATF.escapeAttr(key)}">${AF_ATF.escapeAttr(title)}</option>`;
          });
          if (current && !hasCurrent) {
            html += `<option value="${AF_ATF.escapeAttr(current)}">${AF_ATF.escapeAttr(current)}</option>`;
          }

          select.innerHTML = html;
          if (current) select.value = current;
        });
      }

      function applyMode(mode) {
        const activeMode = mode === "arpg" ? "arpg" : "dnd";

        AF_ATF.qsa(".af-atf-kb-dynamic").forEach((wrap) => {
          const scope = String(wrap.getAttribute("data-mechanic-scope") || "").trim();
          const row = wrap.closest("tr");
          const shouldShow = !scope || scope === activeMode;
          if (row) {
            row.style.display = shouldShow ? "" : "none";
          }
          if (!shouldShow) {
            const select = AF_ATF.qs("select", wrap);
            if (select) select.value = "";
          }
        });

        renderMechanicSelects(activeMode);
        AF_ATF.initDynamicKbPreviews();
      }

      switcher.addEventListener("change", () => applyMode(String(switcher.value || "dnd")));
      applyMode(String(switcher.value || "dnd"));
    },

    initCharacterAbilities() {
      const blocks = AF_ATF.qsa(".af-atf-abilities");
      if (!blocks.length) return;

      const createAbility = (seed) => ({
        ability_name: String((seed && seed.ability_name) || ""),
        ability_type: (seed && seed.ability_type) === "passive" ? "passive" : "active",
        ability_description: String((seed && seed.ability_description) || ""),
        ability_kb_key: String((seed && seed.ability_kb_key) || ""),
        sortorder: Number.isFinite(Number(seed && seed.sortorder)) ? Number(seed.sortorder) : 0
      });

      blocks.forEach((wrap) => {
        if (wrap.__afAbilitiesInited) return;
        wrap.__afAbilitiesInited = true;

        const hidden = AF_ATF.qs(".af-atf-abilities-hidden", wrap);
        const list = AF_ATF.qs(".af-atf-abilities-list", wrap);
        const addBtn = AF_ATF.qs(".af-atf-abilities-add", wrap);
        const maxItems = parseInt(wrap.getAttribute("data-max-items") || "8", 10) || 8;
        if (!hidden || !list || !addBtn) return;

        let state = [];
        try {
          const parsed = JSON.parse(String(hidden.value || "[]"));
          if (Array.isArray(parsed)) {
            state = parsed.map((row) => createAbility(row)).slice(0, maxItems);
          }
        } catch (e) {
          state = [];
        }

        function sync() {
          hidden.value = JSON.stringify(state.slice(0, maxItems));
          addBtn.disabled = state.length >= maxItems;
        }

        function render() {
          list.innerHTML = "";
          state.forEach((ability, index) => {
            const row = document.createElement("div");
            row.className = "af-atf-ability-item";
            row.innerHTML = `
              <div><strong>Ability #${index + 1}</strong></div>
              <input type="text" class="text_input af-atf-ability-name" placeholder="ability_name" value="${AF_ATF.escapeAttr(ability.ability_name)}" />
              <select class="select af-atf-ability-type">
                <option value="active"${ability.ability_type === "active" ? " selected" : ""}>active</option>
                <option value="passive"${ability.ability_type === "passive" ? " selected" : ""}>passive</option>
              </select>
              <textarea class="textarea af-atf-ability-description" rows="3" placeholder="ability_description">${AF_ATF.escapeAttr(ability.ability_description)}</textarea>
              <input type="text" class="text_input af-atf-ability-kb-key" placeholder="ability_kb_key (optional)" value="${AF_ATF.escapeAttr(ability.ability_kb_key)}" />
              <input type="number" class="text_input af-atf-ability-sortorder" placeholder="sortorder" value="${AF_ATF.escapeAttr(ability.sortorder)}" />
              <button type="button" class="button af-atf-ability-remove">Удалить</button>
            `;

            const setValue = () => {
              state[index] = createAbility({
                ability_name: AF_ATF.qs(".af-atf-ability-name", row).value,
                ability_type: AF_ATF.qs(".af-atf-ability-type", row).value,
                ability_description: AF_ATF.qs(".af-atf-ability-description", row).value,
                ability_kb_key: AF_ATF.qs(".af-atf-ability-kb-key", row).value,
                sortorder: AF_ATF.qs(".af-atf-ability-sortorder", row).value
              });
              sync();
            };

            AF_ATF.qsa("input,select,textarea", row).forEach((el) => {
              el.addEventListener("input", setValue);
              el.addEventListener("change", setValue);
            });

            AF_ATF.qs(".af-atf-ability-remove", row).addEventListener("click", () => {
              state.splice(index, 1);
              render();
              sync();
            });

            list.appendChild(row);
          });
          sync();
        }

        addBtn.addEventListener("click", () => {
          if (state.length >= maxItems) return;
          state.push(createAbility({ sortorder: state.length + 1 }));
          render();
          sync();
        });

        if (!state.length) {
          state.push(createAbility({ sortorder: 1 }));
        }
        render();
      });
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
    AF_ATF.initKbSelects();
    AF_ATF.initKbChips();
    AF_ATF.initKbCatalogCtaModal();
    AF_ATF.initPointBuyAll();
    AF_ATF.initCharacterMechanic();
    AF_ATF.initDynamicKbPreviews();
    AF_ATF.initCharacterAbilities();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  // на всякий случай: если страница частично перерисовывается скриптами темы
  window.AF_ATF = window.AF_ATF || AF_ATF;
})();
