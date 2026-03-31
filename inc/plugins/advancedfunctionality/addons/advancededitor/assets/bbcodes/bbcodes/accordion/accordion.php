<?php

declare(strict_types=1);

if (!defined('IN_MYBB')) {
    return;
}

if (!function_exists('af_ae_bbcode_accordion_parse_end')) {
    function af_ae_bbcode_accordion_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        if (stripos($message, '[accordion') === false) {
            return;
        }

        $guard = 0;

        while (stripos($message, '[accordion') !== false && $guard++ < 40) {
            $before = $message;
            $message = preg_replace_callback(
                '~\[accordion([^\]]*)\](.*?)\[/accordion\](?:<br\s*/?>)?~is',
                static function (array $m): string {
                    return af_ae_accordion_render((string)($m[1] ?? ''), (string)($m[2] ?? ''));
                },
                $message
            );

            if (!is_string($message) || $message === $before) {
                $message = $before;
                break;
            }
        }
    }

    function af_ae_accordion_render(string $rawAttrs, string $inner): string
    {
        $attrs = af_ae_accordion_parse_attrs($rawAttrs);
        $direction = af_ae_accordion_normalize_direction((string)($attrs['direction'] ?? ''));

        $items = af_ae_accordion_extract_items($inner);
        if (!$items) {
            $items[] = [
                'title' => 'Раздел',
                'body'  => $inner,
            ];
        }

        $id = 'af-acc-' . substr(md5($inner . '|' . $direction . '|' . microtime(true)), 0, 10);

        $html = '<div class="af-accordion af-accordion-dir-' . htmlspecialchars_uni($direction) . '" data-af-accordion="1" data-direction="' . htmlspecialchars_uni($direction) . '">';

        foreach ($items as $idx => $item) {
            $title = af_ae_accordion_clean_title((string)($item['title'] ?? ''));
            if ($title === '') {
                $title = 'Раздел ' . ($idx + 1);
            }

            $panelId = $id . '-panel-' . $idx;
            $headerId = $id . '-header-' . $idx;

            $html .= '<section class="af-accordion-item" data-af-acc-item="1">';
            $html .= '<h4 class="af-accordion-heading" id="' . htmlspecialchars_uni($headerId) . '">';
            $html .= '<button type="button" class="af-accordion-toggle" data-af-acc-toggle="1" aria-expanded="false" aria-controls="' . htmlspecialchars_uni($panelId) . '">';
            $html .= '<span class="af-accordion-toggle-text">' . htmlspecialchars_uni($title) . '</span>';
            $html .= '<span class="af-accordion-toggle-icon" aria-hidden="true"></span>';
            $html .= '</button>';
            $html .= '</h4>';
            $html .= '<div class="af-accordion-panel" id="' . htmlspecialchars_uni($panelId) . '" role="region" aria-labelledby="' . htmlspecialchars_uni($headerId) . '" hidden>';
            $html .= '<div class="af-accordion-panel-inner">' . (string)($item['body'] ?? '') . '</div>';
            $html .= '</div>';
            $html .= '</section>';
        }

        $html .= '</div>';

        return $html;
    }

    function af_ae_accordion_extract_items(string $inner): array
    {
        $items = [];

        if (preg_match_all('~\[accitem([^\]]*)\](.*?)\[/accitem\]~is', $inner, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs = af_ae_accordion_parse_attrs((string)($m[1] ?? ''));
                $items[] = [
                    'title' => (string)($attrs['title'] ?? ''),
                    'body'  => (string)($m[2] ?? ''),
                ];
            }
        }

        return $items;
    }

    function af_ae_accordion_parse_attrs(string $raw): array
    {
        $attrs = [];

        if (preg_match_all('~([a-z_][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))~i', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $k = strtolower((string)$m[1]);
                $v = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
                $attrs[$k] = (string)$v;
            }
        }

        if (!isset($attrs['direction'])) {
            $trimmed = trim($raw);
            if ($trimmed !== '' && preg_match('~^=\s*([a-z]+)$~i', $trimmed, $m)) {
                $attrs['direction'] = strtolower((string)$m[1]);
            }
        }

        return $attrs;
    }

    function af_ae_accordion_normalize_direction(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'up', 'down', 'left', 'right' => $value,
            default => 'down',
        };
    }

    function af_ae_accordion_clean_title(string $title): string
    {
        $title = preg_replace('~<br\s*/?>~i', ' ', $title);
        $title = strip_tags((string)$title);
        $title = html_entity_decode((string)$title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('~\s+~u', ' ', (string)$title);

        return trim((string)$title);
    }
}
