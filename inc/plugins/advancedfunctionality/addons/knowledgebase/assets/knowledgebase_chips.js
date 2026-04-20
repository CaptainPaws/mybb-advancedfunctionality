(function () {
    'use strict';

    var cache = {};
    var tooltip = null;
    var tooltipTimer = null;

    function afKbEndpoint(name, fallback) {
        var map = (window && window.afKbEndpoints) ? window.afKbEndpoints : null;
        return (map && map[name]) ? map[name] : fallback;
    }

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
        if (cache[cacheKey]) return Promise.resolve(cache[cacheKey]);

        var url = afKbEndpoint('get', 'misc.php?action=kb_get') + '&type=' + encodeURIComponent(type) + '&key=' + encodeURIComponent(key);

        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                // чтобы не пытаться парсить HTML как JSON
                var ct = (res.headers && res.headers.get) ? (res.headers.get('content-type') || '') : '';
                if (!res.ok) throw new Error('HTTP ' + res.status);
                if (ct.indexOf('application/json') === -1) throw new Error('Invalid content-type');
                return res.json();
            })
            .then(function (data) {
                cache[cacheKey] = data;
                return data;
            })
            .catch(function () { return null; });
    }

    function ensureTooltip() {
        if (tooltip) return tooltip;
        tooltip = document.createElement('div');
        tooltip.className = 'af-kb-tooltip';
        tooltip.style.display = 'none';
        document.body.appendChild(tooltip);
        return tooltip;
    }

    function buildIconHtml(entry) {
        if (!entry) return '';
        if (entry.icon_url) {
            return '<img class="af-kb-icon-img" src="' + escapeHtml(entry.icon_url) + '" alt="" loading="lazy" />';
        }
        if (entry.icon_class) {
            return '<i class="' + escapeHtml(entry.icon_class) + '"></i>';
        }
        return '';
    }

    function showTooltip(chip, payload) {
        if (!payload) return;

        var html = payload.html || '';
        var text = payload.text || '';
        if (!html && !text) return;

        if (!html && text) {
            var limit = 220;
            var trimmed = text.length > limit ? text.slice(0, limit) + '…' : text;
            html = escapeHtml(trimmed).replace(/\n/g, '<br />');
        }

        var tip = ensureTooltip();
        tip.innerHTML =
            '<div class="af-kb-tooltip-inner">' +
                (payload.iconHtml ? '<span class="af-kb-tooltip-icon">' + payload.iconHtml + '</span>' : '') +
                '<div class="af-kb-tooltip-body">' + html + '</div>' +
            '</div>';
        tip.style.display = 'block';

        var rect = chip.getBoundingClientRect();
        var top = rect.top + window.scrollY - tip.offsetHeight - 8;
        if (top < 8) top = rect.bottom + window.scrollY + 8;

        var left = rect.left + window.scrollX;
        tip.style.top = top + 'px';
        tip.style.left = left + 'px';
    }

    function hideTooltip() {
        if (tooltip) tooltip.style.display = 'none';
        if (tooltipTimer) {
            clearTimeout(tooltipTimer);
            tooltipTimer = null;
        }
    }

    // отдельная модалка ТОЛЬКО для чипов
    function getOrBuildModal() {
        // отдельный бэкдроп ТОЛЬКО для чипов
        var backdrop = document.querySelector('.af-kb-modal-backdrop.af-kb-chip-modal');
        if (backdrop) {
            return backdrop;
        }

        backdrop = document.createElement('div');
        backdrop.className = 'af-kb-modal-backdrop af-kb-chip-modal';

        backdrop.innerHTML =
            '<div class="af-kb-modal">' +
                '<div class="af-kb-modal-header">' +
                    '<h3></h3>' +
                    '<button type="button" class="af-kb-modal-close">&times;</button>' +
                '</div>' +
                '<div class="af-kb-modal-body"></div>' +
            '</div>';

        document.body.appendChild(backdrop);

        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop || (event.target && event.target.classList && event.target.classList.contains('af-kb-modal-close'))) {
                backdrop.classList.remove('is-active');
            }
        });

        // ESC закрытие (чтобы не зависело от другого скрипта)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && backdrop.classList.contains('is-active')) {
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

        var iconHtml = buildIconHtml(entry);

        title.innerHTML =
            '<span class="af-kb-modal-title">' +
                (iconHtml ? '<span class="af-kb-chip-icon">' + iconHtml + '</span>' : '') +
                '<span class="af-kb-chip-title">' + escapeHtml(entry.title || entry.key) + '</span>' +
            '</span>';

        var banner = entry.banner_url
            ? '<img class="af-kb-banner" src="' + escapeHtml(entry.banner_url) + '" alt="" loading="lazy" />'
            : '';
        var bodyText = entry.body_html
            ? '<div class="af-kb-modal-main">' + entry.body_html + '</div>'
            : '';
        var blocksHtml = '';
        if (entry.sections_html && Array.isArray(entry.sections_html)) {
            entry.sections_html.forEach(function (block) {
                if (!block) return;
                var blockTitle = escapeHtml(block.label || '');
                var blockBody = block.html || '';
                if (!blockTitle && !blockBody) {
                    return;
                }
                blocksHtml += '<section class="af-kb-modal-block">' +
                    (blockTitle ? '<h4>' + blockTitle + '</h4>' : '') +
                    (blockBody ? '<div>' + blockBody + '</div>' : '') +
                    '</section>';
            });
        }

        body.innerHTML = banner + bodyText + blocksHtml;
    }

    function initChips() {
        if (window.__afKbChipsInit) return;
        window.__afKbChipsInit = true;

        document.addEventListener('mouseover', function (event) {
            var chip = event.target && event.target.closest ? event.target.closest('.af-kb-chip') : null;
            if (!chip) return;

            var cachedHtml = chip.__afKbTooltipHtml;
            if (cachedHtml) {
                showTooltip(chip, {
                    html: cachedHtml,
                    iconHtml: chip.__afKbTooltipIconHtml || ''
                });
                return;
            }

            var techHint = chip.getAttribute('data-tech-hint');
            if (techHint) {
                showTooltip(chip, {
                    text: techHint,
                    iconHtml: chip.querySelector('.af-kb-chip-icon') ? chip.querySelector('.af-kb-chip-icon').innerHTML : ''
                });
                return;
            }

            var type = chip.getAttribute('data-kb-type');
            var key = chip.getAttribute('data-kb-key');
            if (!type || !key) return;

            tooltipTimer = setTimeout(function () {
                fetchEntry(type, key).then(function (data) {
                    if (!data || !data.entry) return;
                    var hint = data.entry.tech_hint || '';
                    var tooltipHtml = data.entry.tooltip_html || '';
                    chip.__afKbTooltipIconHtml = buildIconHtml(data.entry);
                    if (tooltipHtml) {
                        chip.__afKbTooltipHtml = tooltipHtml;
                        showTooltip(chip, {
                            html: tooltipHtml,
                            iconHtml: chip.__afKbTooltipIconHtml
                        });
                        return;
                    }
                    if (hint) {
                        chip.setAttribute('data-tech-hint', hint);
                        showTooltip(chip, {
                            text: hint,
                            iconHtml: chip.__afKbTooltipIconHtml
                        });
                    }
                });
            }, 150);
        }, true);

        document.addEventListener('mouseout', function (event) {
            var chip = event.target && event.target.closest ? event.target.closest('.af-kb-chip') : null;
            if (chip) hideTooltip();
        }, true);

        document.addEventListener('click', function (event) {
            var chip = event.target && event.target.closest ? event.target.closest('.af-kb-chip') : null;
            if (!chip) return;

            // не ломаем спец-клики
            if (event.defaultPrevented) return;
            if (typeof event.button === 'number' && event.button !== 0) return;
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            event.preventDefault();
            event.stopPropagation();

            var type = chip.getAttribute('data-kb-type');
            var key = chip.getAttribute('data-kb-key');
            if (!type || !key) return;

            fetchEntry(type, key).then(function (data) {
                var backdrop = getOrBuildModal();
                renderModal(data);
                backdrop.classList.add('is-active');
            });
        }, true);
    }

    // Экспортируем, чтобы другой файл мог гарантированно поднять чипы
    window.afKbInitChips = initChips;

    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('.af-kb-chip')) initChips();
    });
})();
