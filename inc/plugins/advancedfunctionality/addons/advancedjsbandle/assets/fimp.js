/**
 * Fancy Inline Moderation Popup
 * @description A jQuery MyBB addon to make inline moderation tool modern and fancy
 */

$(function () {
	// 1) FIMP НЕ ДОЛЖЕН работать на moderation.php (иначе прячет confirm-формы!)
	var path = (location.pathname || '').toLowerCase();
	if (path.indexOf('moderation.php') !== -1) return;

	// ATF uses the native MyBB modbit, sometimes without an id attribute.
	// Do not set the initialized flag until the real form is present.
	if (window.__afFimpInit) return;
	var icc = $('#inlinemoderation_options').filter('form').first();
	if (!icc.length) return;
	var modType = String(icc.find('input[name="modtype"]').val() || '');
	if (modType !== 'inlinepost' && modType !== 'inlinethread') return;
	var checkboxSelector = 'input[type="checkbox"][name^="inlinemod_"], input[type="checkbox"][id^="inlinemod_"]';
	function selectedCheckboxes() { return $(checkboxSelector).filter(':checked'); }
	if (!$(checkboxSelector).length) return;
	window.__afFimpInit = true;

	// guard на случай, если уже добавили блок
	if ($('#fimp').length) return;

	$('body').append('<div id="fimp" class="control-group"><span></span></div>');

	// Build absolute path to fimp.svg next to this script
	var fimpSvgBase = (function () {
		try {
			var cs = document.currentScript;
			if (cs && cs.src) return cs.src.replace(/[^\/]+$/, 'fimp.svg');
		} catch (e) {}

		try {
			var scripts = document.getElementsByTagName('script');
			for (var k = scripts.length - 1; k >= 0; k--) {
				var s = scripts[k];
				if (s && s.src && /fimp(\.min)?\.js(\?.*)?$/i.test(s.src)) {
					return s.src.replace(/[^\/]+$/, 'fimp.svg');
				}
			}
		} catch (e2) {}

		return 'fimp.svg';
	})();

	var ict = '<button type="button" class="fimp" title="{t}" data-action="{v}">{l}</button>',
		ico = '<svg class="icon"><use href="' + fimpSvgBase + '#{i}" /></svg>';

	// Скрываем стандартную форму выбора (ТОЛЬКО на showthread)
	icc.hide();

	// Build buttons из option, но пропускаем пустые/placeholder
	icc.find('option').each(function () {
		var val = String($(this).val() || '');
		var txt = String($(this).text() || '');

		if (!val) return; // "Выберите инструмент"
		if (!txt) return;

		var iid = val.replace(/threads|posts/ig, "");
		$('#fimp').append(
			ict.replace('{t}', txt).replace('{v}', val).replace('{l}', ico.replace('{i}', iid))
		);
	});

	function updateCount() {
		var x = selectedCheckboxes().length;

		$('#fimp').find('span').text(x);

		if (x < 2) $('.fimp[data-action=multimergeposts]').attr("disabled", true);
		else $('.fimp[data-action=multimergeposts]').removeAttr("disabled");

		if (x > 0) $('#fimp').fadeIn();
		else $('#fimp').fadeOut();
	}

	updateCount();
	$(document).on('change.afFimp', checkboxSelector, updateCount);

	function runInlineModeration(formEl) {
		// Используем родной механизм MyBB — он сам соберёт выбранные pid и покажет confirm
		if (window.inlineModeration && typeof window.inlineModeration.submit === 'function') {
			window.inlineModeration.submit(formEl);
			return;
		}

		// fallback на крайний случай
		if (formEl && typeof formEl.requestSubmit === 'function') formEl.requestSubmit();
		else if (formEl && typeof formEl.submit === 'function') formEl.submit();
	}

	$(document).on('click', '#fimp .fimp', function () {
		var action = String($(this).data('action') || '');
		if (!action) return;

		// выставляем action в select
		var $sel = icc.find('select[name="action"]');
		if ($sel.length) $sel.val(action);

		$('#fimp>span').html("<span class='loader'></span>");

		runInlineModeration(icc.get(0));
	});

	$('#fimp>span').on('click', function () {
		if (window.inlineModeration && typeof window.inlineModeration.clearChecked === 'function') {
			window.inlineModeration.clearChecked();
		}
		updateCount();
	});
});
