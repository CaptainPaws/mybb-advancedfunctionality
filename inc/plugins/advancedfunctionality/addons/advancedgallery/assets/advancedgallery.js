(function () {
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        return Promise.resolve();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.classList.contains('ag-copy-bbcode')) {
            event.preventDefault();
            var bbcode = target.getAttribute('data-bbcode') || '';
            copyText(bbcode).then(function () {
                target.textContent = 'Copied';
                setTimeout(function () {
                    target.textContent = 'Copy BBCode';
                }, 1500);
            });
        }
    });

    function toggleAllowedGroups() {
        var select = document.getElementById('ag_visibility');
        var wrapper = document.getElementById('ag_allowed_groups_wrap');
        if (!select || !wrapper) {
            return;
        }
        if (select.value === 'groups') {
            wrapper.style.display = 'flex';
        } else {
            wrapper.style.display = 'none';
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target && event.target.id === 'ag_visibility') {
            toggleAllowedGroups();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        toggleAllowedGroups();
    });
})();
