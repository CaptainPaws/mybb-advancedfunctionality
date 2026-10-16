(() => {
  'use strict';
  if (window.afApuiSignatureEditorReady) return;
  window.afApuiSignatureEditorReady = true;
  document.querySelectorAll('[data-af-apui-signature-editor]').forEach(block => {
    const frame = block.querySelector('iframe[data-af-signature-src]');
    if (!frame) return;
    let observer, draft = null, restore = false, recovery;
    function recover() {
      if (draft === null || recovery) return;
      recovery = document.createElement('button');
      recovery.type = 'button';
      recovery.textContent = 'Вернуться к редактированию — введённый текст сохранён';
      recovery.addEventListener('click', () => {
        restore = true;
        frame.src = frame.dataset.afSignatureSrc;
      });
      block.append(recovery);
    }
    block.addEventListener('toggle', () => {
      if (block.open && !frame.hasAttribute('src')) frame.src = frame.dataset.afSignatureSrc;
    });
    frame.addEventListener('load', () => {
      observer?.disconnect();
      try {
        const doc = frame.contentDocument;
        const content = doc?.querySelector('.af-apui-signature-embed__main');
        const textarea = content?.querySelector('textarea[name="signature"]');
        if (!content || !textarea) {
          if (!doc?.querySelector('meta[http-equiv="refresh"]')) recover();
          return;
        }
        recovery?.remove(); recovery = null;
        if (content.querySelector('[data-af-signature-saved]')) draft = null;
        if (restore && draft !== null) {
          textarea.value = draft;
          const jq = frame.contentWindow.jQuery;
          try { jq?.(textarea).sceditor('instance')?.val(draft); } catch (_) { /* source-only form */ }
          restore = false;
        }
        // Native validation errors already redisplay the posted BBCode. Keep an
        // in-memory backup for native error/login/network documents without a form.
        textarea.form?.addEventListener('submit', () => { draft = textarea.value; });
        const resize = () => { frame.style.height = Math.max(360, Math.ceil(content.getBoundingClientRect().height) + 20) + 'px'; };
        resize();
        if (window.ResizeObserver) { observer = new ResizeObserver(resize); observer.observe(content); }
      } catch (_) { recover(); }
    });
  });
})();
