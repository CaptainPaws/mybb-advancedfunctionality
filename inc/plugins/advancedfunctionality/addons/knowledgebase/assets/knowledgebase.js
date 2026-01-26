(function () {
    function initRepeater(containerId, addButtonId, templateId, indexAttr) {
        var container = document.getElementById(containerId);
        var addBtn = document.getElementById(addButtonId);
        var template = document.getElementById(templateId);
        if (!container || !addBtn || !template) {
            return;
        }

        function currentIndex() {
            return parseInt(container.getAttribute(indexAttr) || '0', 10);
        }

        function bumpIndex() {
            var next = currentIndex() + 1;
            container.setAttribute(indexAttr, String(next));
            return next;
        }

        addBtn.addEventListener('click', function () {
            var index = currentIndex();
            var html = template.innerHTML.replace(/__INDEX__/g, String(index));
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            while (wrapper.firstChild) {
                container.appendChild(wrapper.firstChild);
            }
            bumpIndex();
        });

        container.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            if (target.classList.contains('af-kb-remove')) {
                var item = target.closest('.af-kb-block-item, .af-kb-rel-item-edit');
                if (item && container.contains(item)) {
                    item.remove();
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initRepeater('af-kb-blocks', 'af-kb-add-block', 'af-kb-block-template', 'data-index');
        initRepeater('af-kb-relations', 'af-kb-add-relation', 'af-kb-relation-template', 'data-index');
    });
})();
