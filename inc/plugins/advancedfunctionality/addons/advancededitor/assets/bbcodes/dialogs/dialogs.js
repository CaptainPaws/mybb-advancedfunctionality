(function () {
  'use strict';
  function element(value) { return value && value.jquery ? value[0] : value; }
  window.af_ae_dialogs_exec = function (editor, button, caller) {
    var isLink = button.cmd === 'link';
    var source = editor.__afAeSourceAdapter || (editor.sourceMode && editor.sourceMode());
    var input = source && element(editor.getSourceEditor && editor.getSourceEditor());
    var original = input && input.value, start = input && input.selectionStart, end = input && input.selectionEnd;
    var body = !source && editor.getBody && editor.getBody(), selection = body && body.ownerDocument.getSelection();
    var range = selection && selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
    var selected = input ? input.value.slice(start, end) : range ? range.toString() : '';
    var root = document.createElement('form'); root.className = 'af-ae-basic-dialog';
    root.innerHTML = isLink
      ? '<strong>Вставить ссылку</strong><label>Адрес ссылки<input name="url" type="text" placeholder="https://…" required></label><label>Название ссылки<input name="title" type="text"></label>'
      : '<strong>Вставить код</strong><label>Код<textarea name="code" rows="7" spellcheck="false"></textarea></label>';
    root.insertAdjacentHTML('beforeend', '<p role="status"></p><footer><button type="button" class="button" data-cancel>Отмена</button><button type="submit" class="button button--primary">Вставить</button></footer>');
    root.elements[isLink ? 'title' : 'code'].value = selected;
    if (isLink && /^https?:\/\//i.test(selected)) root.elements.url.value = selected;
    function restore() {
      editor.focus();
      if (input && input.isConnected && input.value === original) input.setSelectionRange(start, end);
      if (range && range.startContainer.isConnected) {
        var helper = editor.getRangeHelper && editor.getRangeHelper();
        if (helper && helper.selectRange) helper.selectRange(range);
        else { var sel = body.ownerDocument.getSelection(); sel.removeAllRanges(); sel.addRange(range); }
      }
    }
    root.querySelector('[data-cancel]').onclick = function () { editor.closeDropDown(); restore(); };
    root.onsubmit = function (event) {
      event.preventDefault();
      var code;
      if (isLink) {
        var url = root.elements.url.value.trim(), title = root.elements.title.value || selected || url;
        if (!url || /[\s\[\]<>"\x00-\x1f]/.test(url) || (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^(https?|ftp|mailto):/i.test(url))) {
          root.querySelector('[role="status"]').textContent = 'Укажите безопасный адрес ссылки.'; return;
        }
        code = '[url=' + url + ']' + title + '[/url]';
      } else code = '[code]' + root.elements.code.value + '[/code]';
      editor.closeDropDown(); restore();
      if (input) { input.setRangeText(code, input.selectionStart, input.selectionEnd, 'end'); input.dispatchEvent(new Event('input', {bubbles:true})); }
      else editor.insert(code);
    };
    editor.createDropDown(caller, isLink ? 'af-link-dialog' : 'af-code-dialog', root);
    root.elements[isLink ? 'url' : 'code'].focus();
  };
})();
