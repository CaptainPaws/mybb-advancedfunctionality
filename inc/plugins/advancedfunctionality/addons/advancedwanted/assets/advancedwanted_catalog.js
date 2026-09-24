(function () {
    'use strict';

    if (!window.fetch || !window.history || !window.URL || !window.FormData) return;

    var request = null;
    var sequence = 0;

    function catalog() {
        return document.querySelector('[data-af-wanted-catalog]');
    }

    function cleanUrl(input) {
        var url = new URL(input || window.location.href, window.location.href);
        url.hash = '';
        url.searchParams.delete('ajax');
        return url;
    }

    function urlFromForm(form) {
        var url = cleanUrl(form.action || window.location.href);
        url.search = '';
        new FormData(form).forEach(function (value, name) {
            value = String(value).trim();
            if (value) url.searchParams.append(name, value);
        });
        url.searchParams.delete('page');
        return url;
    }

    function showError(root) {
        var error = root.querySelector('.af-wanted-results-error');
        if (!error) {
            error = document.createElement('p');
            error.className = 'af-wanted-results-error';
            error.setAttribute('role', 'status');
            root.insertBefore(error, root.firstChild);
        }
        error.textContent = 'Не удалось обновить каталог. Открываем обычную страницу…';
    }

    function load(input, push) {
        var root = catalog();
        if (!root) return false;

        var target = cleanUrl(input);
        var ajaxUrl = new URL(target.href);
        ajaxUrl.searchParams.set('ajax', '1');
        if (request && window.AbortController) request.abort();
        request = window.AbortController ? new AbortController() : null;
        var current = ++sequence;
        root.classList.add('is-loading');
        root.setAttribute('aria-busy', 'true');

        fetch(ajaxUrl.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: request ? request.signal : undefined
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            if (current !== sequence) return;
            var shell = document.createElement('div');
            shell.innerHTML = html;
            var replacement = shell.querySelector('[data-af-wanted-catalog]');
            if (!replacement) throw new Error('Invalid catalog response');
            root.replaceWith(replacement);
            if (push) window.history.pushState({ afWanted: true }, '', target.href);
        }).catch(function (error) {
            if (error && error.name === 'AbortError') return;
            if (current !== sequence) return;
            showError(root);
            window.location.assign(target.href);
        }).then(function () {
            var active = catalog();
            if (current === sequence && active) {
                active.classList.remove('is-loading');
                active.removeAttribute('aria-busy');
            }
        });
        return true;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-af-wanted-catalog] .af-wanted-filters');
        if (!form) return;
        var target = urlFromForm(form);
        if (load(target, true)) event.preventDefault();
    });

    document.addEventListener('change', function (event) {
        var form = event.target.closest('[data-af-wanted-catalog] .af-wanted-filters');
        if (!form || !event.target.matches('select')) return;
        form.querySelectorAll('[data-depends-on]').forEach(function (child) {
            if (child.getAttribute('data-depends-on') === event.target.name) child.value = '';
        });
        var target = urlFromForm(form);
        if (load(target, true)) event.preventDefault();
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-af-wanted-catalog] .af-wanted-tabs a, [data-af-wanted-catalog] .af-wanted-pagination a');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (load(link.href, true)) event.preventDefault();
    });

    window.addEventListener('popstate', function () {
        load(window.location.href, false);
    });
}());
