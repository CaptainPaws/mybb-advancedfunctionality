/**
 * Fancy Inline Moderation Popup.
 *
 * FIMP is a presentation-only frontend for MyBB's inline moderation FORM.
 * Actions start neutral and are set only by an explicit enabled-button click.
 */
(function ($, window, document) {
  'use strict';

  $(function () {
    if (/(?:^|\/)moderation\.php$/i.test(location.pathname)) return;
    if (window.__afFimpInit) return;

    // MyBB's showthread_moderationoptions already includes $inlinemod.
    // Fail closed on duplicate forms: submitting an arbitrarily selected form
    // can execute a DIFFERENT moderation action than the button the user chose.
    var $forms = $('form#inlinemoderation_options');
    if ($forms.length !== 1) {
      if ($forms.length > 1) {
        console.warn('[FIMP] Duplicate native inline moderation forms. Keeping original controls visible.');
      }
      return;
    }
    var $form = $forms.eq(0);
    var modType = String($form.find('input[name="modtype"]').val() || '');
    if (modType !== 'inlinepost' && modType !== 'inlinethread') return;

    var $select = $form.find('select[name="action"]').first();
    var $submit = $form.find('input[type="submit"][name="go"], button[type="submit"][name="go"]').first();
    if (!$select.length || !$submit.length) return;

    var checkboxSelector = modType === 'inlinepost'
      ? 'input[type="checkbox"][name^="inlinemod_"]'
      : 'input[type="checkbox"][name^="inlinemod_"]';
    var $checkboxes = $(checkboxSelector);
    if (!$checkboxes.length) return;

    // Do not alter already-valid MyBB checkbox identities.
    $checkboxes.each(function () {
      if (this.id) return;
      var match = String(this.name || '').match(/^inlinemod_(\d+)$/);
      if (match) this.id = 'inlinemod_' + match[1];
    });

    var currentAction = null;
    // The native select defaults to its first option (often merge).
    // A neutral option prevents checkbox changes/Enter from choosing it.
    var $none = $('<option>', { value: '', text: 'Выберите действие' });
    $select.prepend($none).val('');
    $form.on('submit.afFimp', function (event) {
      if (!currentAction || $select.val() !== currentAction) event.preventDefault();
    });

    var $panel = $('<div>', { id: 'fimp', class: 'control-group' });
    var $count = $('<span>', { title: 'Снять выделение', role: 'button', tabindex: 0 });
    $panel.append($count);

    // SVG sprite path is resolved without depending on document.currentScript
    // (which is null when a deferred script's ready callback executes).
    var sprite = '';
    $('script[src]').each(function () {
      var src = String(this.src || '');
      if (/\/fimp(?:\.min)?\.js(?:\?.*)?$/i.test(src)) sprite = src.replace(/[^/]+(?:\?.*)?$/, 'fimp.svg');
    });

    // Read the native options directly. Map each button to the EXACT option
    // value, not a label, translated text, index, or guessed icon name.
    $select.find('option').each(function () {
      var value = String(this.value || '');
      if (!value || this.disabled) return;
      var title = String($(this).text() || '').trim();
      if (!title) return;
      var $button = $('<button>', {
        type: 'button',
        class: 'fimp',
        title: title,
        'aria-label': title
      }).attr('data-action', value);

      var symbol = value.replace(/threads|posts/ig, '');
      var knownSymbols = [
        'multisoftdelete', 'multirestore', 'multidelete', 'multimerge',
        'multisplit', 'multimove', 'multiapprove', 'multiunapprove',
        'delayedmoderation', 'multiclose', 'multiopen', 'multistick', 'multiunstick'
      ];
      if (sprite && knownSymbols.indexOf(symbol) !== -1) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'icon');
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', sprite + '#' + symbol);
        svg.appendChild(use);
        $button.append(svg);
      } else {
        $button.text(title);
      }
      $panel.append($button);
    });
    if (!$panel.find('button.fimp').length) return;

    $('body').append($panel);
    window.__afFimpInit = true;

    function updateCount() {
      // Always read current DOM: ATF can insert posts with AJAX.
      var count = $(checkboxSelector).filter(':checked').length;
      currentAction = null;
      $select.val('');
      $panel.attr('data-current-action', '');
      $count.text(count);
      $panel.find('button.fimp').each(function () {
        var action = this.getAttribute('data-action') || '';
        $(this).prop('disabled', !count || (action === 'multimergeposts' && count < 2));
      });
      $panel.stop(true, true);
      if (count) $panel.fadeIn(100);
      else $panel.fadeOut(100);
    }
    $(document).on('change.afFimp click.afFimp', checkboxSelector, updateCount);
    updateCount();

    function submitNativeAction(value) {
      if (!$select.find('option').filter(function () {
        return this.value === value && !this.disabled;
      }).length) return;

      // A second form means a modified/stale MyBB template. Never submit
      // moderation actions when the target form is ambiguous.
      if ($('form#inlinemoderation_options').length !== 1) return;
      currentAction = value;
      $panel.attr('data-current-action', value);
      $select.val(value);
      if (String($select.val() || '') !== value) return;
      var form = $form[0];
      var submit = $submit[0];

      // requestSubmit runs native constraint checks and MyBB's "submit"
      // listeners. Native form.submit() bypasses those safety checks.
      if (typeof form.requestSubmit === 'function') form.requestSubmit(submit);
      else $submit.trigger('click');
    }

    $panel.on('click', 'button.fimp', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (this.disabled || !$(checkboxSelector).filter(':checked').length) return;
      var action = this.getAttribute('data-action') || '';
      if (!action) return;
      submitNativeAction(action);
    });

    function clearChecked() {
      if (window.inlineModeration && typeof window.inlineModeration.clearChecked === 'function') {
        window.inlineModeration.clearChecked();
      } else {
        $(checkboxSelector).prop('checked', false).trigger('change');
      }
      updateCount();
    }
    $count.on('click', clearChecked).on('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        clearChecked();
      }
    });

    // Hide the ORIGINAL form only after successfully binding all controls.
    $form.hide();
  });
})(jQuery, window, document);
