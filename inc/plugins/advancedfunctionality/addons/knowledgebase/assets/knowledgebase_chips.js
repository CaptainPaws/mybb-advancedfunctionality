(function () {
    var cache = {};
    var tooltip = null;
    var tooltipTimer = null;

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function fetchEntry(type, key) {
        var cacheKey = type + ':' + key;
        if (cache[cacheKey]) {
            return Promise.resolve(cache[cacheKey]);
        }
        return fetch('misc.php?action=kb_get&type=' + encodeURIComponent(type) + '&key=' + encodeURIComponent(key))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                cache[cacheKey] = data;
                return data;
            })
            .catch(function () { return null; });
    }

    function ensureTooltip() {
        if (tooltip) {
            return tooltip;
        }
        tooltip = document.createElement('div');
        tooltip.className = 'af-kb-tooltip';
        tooltip.style.display = 'none';
        document.body.appendChild(tooltip);
        return tooltip;
    }

    function showTooltip(chip, text) {
        if (!text) {
            return;
        }
        var limit = 220;
        var trimmed = text.length > limit ? text.slice(0, limit) + '…' : text;
        var tip = ensureTooltip();
        tip.textContent = trimmed;
        tip.style.display = 'block';
        var rect = chip.getBoundingClientRect();
        var top = rect.top + window.scrollY - tip.offsetHeight - 8;
        if (top < 8) {
            top = rect.bottom + window.scrollY + 8;
        }
        var left = rect.left + window.scrollX;
        tip.style.top = top + 'px';
        tip.style.left = left + 'px';
    }

    function hideTooltip() {
        if (tooltip) {
            tooltip.style.display = 'none';
        }
        if (tooltipTimer) {
            clearTimeout(tooltipTimer);
            tooltipTimer = null;
        }
    }

    function getOrBuildModal() {
        var backdrop = document.querySelector('.af-kb-modal-backdrop');
        if (backdrop) {
            return backdrop;
        }
        backdrop = document.createElement('div');
        backdrop.className = 'af-kb-modal-backdrop';
        backdrop.innerHTML = '<div class="af-kb-modal"><div class="af-kb-modal-header"><h3></h3><button type="button" class="af-kb-modal-close">&times;</button></div><div class="af-kb-modal-body"></div></div>';
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop || event.target.classList.contains('af-kb-modal-close')) {
                backdrop.classList.remove('is-active');
            }
        });
        return backdrop;
    }

    function renderModal(entryData) {
        var backdrop = getOrBuildModal();
        var title = backdrop.querySelector('h3');
        var body = backdrop.querySelector('.af-kb-modal-body');
        if (!entryData || !entryData.entry) {
            title.textContent = 'KB';
            body.textContent = '';
            return;
        }
        var entry = entryData.entry;
        var iconHtml = '';
        if (entry.icon_url) {
            iconHtml = '<img class="af-kb-icon-img" src="' + escapeHtml(entry.icon_url) + '" alt="" loading="lazy" />';
        } else if (entry.icon_class) {
            iconHtml = '<i class="' + escapeHtml(entry.icon_class) + '"></i>';
        }
        title.innerHTML = (iconHtml ? '<span class="af-kb-chip-icon">' + iconHtml + '</span>' : '') + escapeHtml(entry.title || entry.key);
        var short = entry.short ? '<p>' + escapeHtml(entry.short) + '</p>' : '';
        var bodyText = entry.body ? '<div>' + escapeHtml(entry.body).replace(/\n/g, '<br />') + '</div>' : '';
        body.innerHTML = short + bodyText;
    }

    function initChips() {
        if (window.__afKbChipsInit) {
            return;
        }
        window.__afKbChipsInit = true;

        document.addEventListener('mouseover', function (event) {
            var chip = event.target.closest && event.target.closest('.af-kb-chip');
            if (!chip) {
                return;
            }
            var techHint = chip.getAttribute('data-tech-hint');
            if (techHint) {
                showTooltip(chip, techHint);
                return;
            }
            var type = chip.getAttribute('data-kb-type');
            var key = chip.getAttribute('data-kb-key');
            if (!type || !key) {
                return;
            }
            tooltipTimer = setTimeout(function () {
                fetchEntry(type, key).then(function (data) {
                    if (!data || !data.entry) {
                        return;
                    }
                    var hint = data.entry.tech_hint || '';
                    if (hint) {
                        chip.setAttribute('data-tech-hint', hint);
                        showTooltip(chip, hint);
                    }
                });
            }, 150);
        });

        document.addEventListener('mouseout', function (event) {
            if (event.target.closest && event.target.closest('.af-kb-chip')) {
                hideTooltip();
            }
        });

        document.addEventListener('click', function (event) {
            var chip = event.target.closest && event.target.closest('.af-kb-chip');
            if (!chip) {
                return;
            }
            event.preventDefault();
            var type = chip.getAttribute('data-kb-type');
            var key = chip.getAttribute('data-kb-key');
            if (!type || !key) {
                return;
            }
            fetchEntry(type, key).then(function (data) {
                var backdrop = getOrBuildModal();
                renderModal(data);
                backdrop.classList.add('is-active');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.querySelector('.af-kb-chip')) {
            return;
        }
        initChips();
    });
})();
