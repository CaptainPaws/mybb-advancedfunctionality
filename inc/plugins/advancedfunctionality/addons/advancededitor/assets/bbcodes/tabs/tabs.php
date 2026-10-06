<?php

declare(strict_types=1);

if (!defined('IN_MYBB')) { return; }
if (defined('AF_AE_TABS_LOADED')) { return; }
define('AF_AE_TABS_LOADED', 1);

function af_ae_tabs_parse_attr_string(string $raw): array
{
    $attrs = [];

    if (preg_match_all('~([a-z_][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))~i', $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower(trim((string)($match[1] ?? '')));
            if ($key === '') {
                continue;
            }

            $value = '';
            if (($match[2] ?? '') !== '') {
                $value = (string)$match[2];
            } elseif (($match[3] ?? '') !== '') {
                $value = (string)$match[3];
            } else {
                $value = (string)($match[4] ?? '');
            }

            $attrs[$key] = $value;
        }
    }

    return $attrs;
}

function af_ae_tabs_normalize_position(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['top', 'bottom', 'left', 'right'], true) ? $value : 'top';
}

function af_ae_tabs_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function af_ae_tabs_extract_items(string $content): array
{
    $items = [];

    if (preg_match_all('~\[tab([^\]]*)\](.*?)\[/tab\]~is', $content, $matches, PREG_SET_ORDER)) {
        $idx = 1;
        foreach ($matches as $match) {
            $attrs = af_ae_tabs_parse_attr_string((string)($match[1] ?? ''));
            $title = trim((string)($attrs['title'] ?? ''));
            if ($title === '') {
                $title = 'Вкладка ' . $idx;
            }

            $items[] = [
                'title' => $title,
                'html' => (string)($match[2] ?? ''),
            ];
            $idx++;
        }
    }

    return $items;
}

function af_ae_tabs_render_block(string $attrString, string $inner): string
{
    static $instance = 0;
    $instance++;

    $attrs = af_ae_tabs_parse_attr_string($attrString);
    $position = af_ae_tabs_normalize_position((string)($attrs['position'] ?? 'top'));
    $items = af_ae_tabs_extract_items($inner);

    if (!$items) {
        return '';
    }

    $uid = 'af-ae-tabs-' . $instance . '-' . substr(md5((string)$instance . $position), 0, 8);

    $html = '<section class="af-ae-tabs af-ae-tabs--' . af_ae_tabs_escape($position) . '" data-af-tabs-root data-position="' . af_ae_tabs_escape($position) . '" data-active-index="0">';

    $html .= '<div class="af-ae-tabs__nav" role="tablist" aria-label="Tabs">';
    foreach ($items as $i => $item) {
        $tabId = $uid . '-tab-' . $i;
        $panelId = $uid . '-panel-' . $i;
        $isActive = ($i === 0);

        $html .= '<button type="button" class="af-ae-tabs__tab' . ($isActive ? ' is-active' : '') . '"'
            . ' id="' . af_ae_tabs_escape($tabId) . '"'
            . ' data-af-tabs-trigger data-index="' . $i . '"'
            . ' role="tab"'
            . ' aria-controls="' . af_ae_tabs_escape($panelId) . '"'
            . ' aria-selected="' . ($isActive ? 'true' : 'false') . '"'
            . ' tabindex="' . ($isActive ? '0' : '-1') . '">'
            . af_ae_tabs_escape((string)$item['title'])
            . '</button>';
    }
    $html .= '</div>';

    $html .= '<div class="af-ae-tabs__body">';
    foreach ($items as $i => $item) {
        $tabId = $uid . '-tab-' . $i;
        $panelId = $uid . '-panel-' . $i;
        $isActive = ($i === 0);

        $html .= '<div class="af-ae-tabs__panel' . ($isActive ? ' is-active' : '') . '"'
            . ' id="' . af_ae_tabs_escape($panelId) . '"'
            . ' data-af-tabs-panel data-index="' . $i . '"'
            . ' role="tabpanel"'
            . ' aria-labelledby="' . af_ae_tabs_escape($tabId) . '"'
            . ($isActive ? '' : ' hidden')
            . '>'
            . (string)$item['html']
            . '</div>';
    }
    $html .= '</div>';

    $html .= '</section>';

    return $html;
}

function af_ae_bbcode_tabs_parse_end(&$message): void
{
    if (!is_string($message) || $message === '' || stripos($message, '[tabs') === false) {
        return;
    }

    $maxLoops = 40;
    $pattern = '~\[tabs([^\]]*)\](.*?)\[/tabs\]~is';

    for ($i = 0; $i < $maxLoops; $i++) {
        if (!preg_match($pattern, $message)) {
            break;
        }

        $message = preg_replace_callback(
            $pattern,
            static function (array $match): string {
                $attrString = (string)($match[1] ?? '');
                $inner = (string)($match[2] ?? '');
                return af_ae_tabs_render_block($attrString, $inner);
            },
            $message
        );

        if (!is_string($message)) {
            break;
        }
    }
}
