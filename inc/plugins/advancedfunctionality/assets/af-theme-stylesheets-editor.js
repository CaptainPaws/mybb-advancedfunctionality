(function () {
	'use strict';

	var root = document.querySelector('.af-theme-editor');
	if (!root) return;

	var source = root.querySelector('textarea.af-ts-css');
	if (!source) return;

	var editor = null;
	var initialValue = source.value;
	var isDirty = false;
	var allowNavigation = false;
	var status = root.querySelector('.af-ts-unsaved');
	var message = root.getAttribute('data-unsaved-message') || 'There are unsaved CSS changes. Leave this page?';

	function setDirty(value) {
		isDirty = !!value && (!editor || editor.getValue() !== initialValue);
		if (status) status.hidden = !isDirty;
		root.classList.toggle('af-ts-has-unsaved', isDirty);
	}

	if (window.CodeMirror) {
		try {
			var options = {
				mode: 'text/css',
				theme: 'af-theme-dark',
				lineNumbers: true,
				lineWrapping: false,
				viewportMargin: 30,
				indentWithTabs: true,
				indentUnit: 4,
				tabSize: 4,
				readOnly: source.hasAttribute('readonly'),
				extraKeys: {
					Tab: function (cm) {
						if (cm.somethingSelected()) cm.indentSelection('add');
						else cm.replaceSelection('\t', 'end', '+input');
					},
					'Shift-Tab': 'indentLess'
				}
			};
			if (window.CodeMirror.defaults && Object.prototype.hasOwnProperty.call(window.CodeMirror.defaults, 'matchBrackets')) {
				options.matchBrackets = true;
			}
			editor = window.CodeMirror.fromTextArea(source, options);

			var activeLine = null;
			function updateActiveLine(cm) {
				var nextLine = cm.getCursor().line;
				if (activeLine === nextLine) return;
				if (activeLine !== null) cm.removeLineClass(activeLine, 'background', 'af-ts-active-line');
				cm.addLineClass(nextLine, 'background', 'af-ts-active-line');
				activeLine = nextLine;
			}
			editor.on('cursorActivity', updateActiveLine);
			updateActiveLine(editor);
			editor.on('change', function () {
				setDirty(true);
			});
			window.setTimeout(function () { editor.refresh(); }, 0);
		} catch (error) {
			if (window.console && console.error) console.error('AF stylesheet editor initialization failed', error);
			editor = null;
		}
	}
	if (!editor) {
		source.addEventListener('input', function () {
			setDirty(source.value !== initialValue);
		});
	}

	root.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || form.tagName !== 'FORM') return;

		if (source.form === form) {
			if (editor) editor.save();
			allowNavigation = true;
			setDirty(false);
			return;
		}

		if (isDirty) {
			if (!window.confirm(message)) {
				event.preventDefault();
				return;
			}
			allowNavigation = true;
			setDirty(false);
		}
	});

	root.addEventListener('click', function (event) {
		var link = event.target.closest && event.target.closest('a[href]');
		if (!link || !isDirty) return;
		if (!window.confirm(message)) {
			event.preventDefault();
			return;
		}
		allowNavigation = true;
		setDirty(false);
	});

	window.addEventListener('beforeunload', function (event) {
		if (!isDirty || allowNavigation) return;
		event.preventDefault();
		event.returnValue = '';
	});
}());
