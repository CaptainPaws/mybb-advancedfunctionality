<?php
/**
 * AQR BBCode Pack: table
 * Рендерит [table]...[tr][td]... в HTML table.
 * КРИТИЧНО: чистит <br> которые MyBB вставляет из переносов.
 */

if (!defined('IN_MYBB')) { exit; }

function af_aqr_bbcode_table_parse(&$message): void
{
    if (!is_string($message) || $message === '') return;

    // быстрый skip
    if (stripos($message, '[table') === false) return;

    // защитим <pre>/<code>
    $protected = [];
    $message2 = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', function ($m) use (&$protected) {
        $key = '%%AQR_TBL_PROTECT_' . count($protected) . '%%';
        $protected[$key] = $m[0];
        return $key;
    }, $message);

    if (!is_string($message2) || $message2 === '') return;

    // ВАЖНО: рендерим вложенные таблицы изнутри наружу
    $guard = 0;
    while (stripos($message2, '[table') !== false && $guard++ < 30) {
        $before = $message2;

        $message2 = preg_replace_callback(
            '~\[table([^\]]*)\]((?:(?!\[table).)*?)\[/table\]~is',
            function ($m) {
                $attrRaw = (string)($m[1] ?? '');
                $body    = (string)($m[2] ?? '');

                $attrs = af_aqr_bbcode_table_parse_attrs($attrRaw);

                // УБИВАЕМ <br> которые появились из переносов строк между тегами
                $body = preg_replace('~\]\s*<br\s*/?>\s*\[~i', '][', $body);
                $body = preg_replace('~<br\s*/?>\s*\[(\/?(?:tr|td|th)(?:[^\]]*)?)\]~i', '[$1]', $body);
                $body = preg_replace('~\[(\/?(?:tr|td|th)(?:[^\]]*)?)\]\s*<br\s*/?>~i', '[$1]', $body);


                $body = preg_replace('~^(?:\s*<br\s*/?>\s*)+~i', '', $body);
                $body = preg_replace('~(?:\s*<br\s*/?>\s*)+$~i', '', $body);

                // tr/td/th
                $x = $body;
                // tr
                $x = preg_replace('~\[(\/?)tr\]~i', '<$1tr>', $x);

                // td/th с width=...
                $x = preg_replace_callback('~\[(td|th)([^\]]*)\]~i', function ($m4) {
                    $tag = strtolower($m4[1]);
                    $raw = (string)($m4[2] ?? '');

                    $style = '';
                    if (preg_match('~\bwidth\s*=\s*([0-9]{1,4})(px|%|em|rem|vw|vh)?\b~i', $raw, $w)) {
                        $unit = !empty($w[2]) ? strtolower($w[2]) : 'px';
                        $style = ' style="width:' . htmlspecialchars_uni($w[1] . $unit) . '"';
                    }

                    return '<' . $tag . $style . '>';
                }, $x);

                $x = preg_replace('~\[/td\]~i', '</td>', $x);
                $x = preg_replace('~\[/th\]~i', '</th>', $x);

                $styles = [];
                if (!empty($attrs['width'])) {
                    $styles[] = 'width:' . $attrs['width'];
                }
                if (!empty($attrs['align'])) {
                    if ($attrs['align'] === 'center') {
                        $styles[] = 'margin-left:auto';
                        $styles[] = 'margin-right:auto';
                    } elseif ($attrs['align'] === 'right') {
                        $styles[] = 'margin-left:auto';
                    } elseif ($attrs['align'] === 'left') {
                        $styles[] = 'margin-right:auto';
                    }
                }

                $styleAttr = $styles ? (' style="' . htmlspecialchars_uni(implode(';', $styles)) . '"') : '';

                $headers = !empty($attrs['headers']) ? $attrs['headers'] : '';
                $dataHeaders = $headers !== '' ? (' data-headers="' . htmlspecialchars_uni($headers) . '"') : '';

                return '<table class="af-aqr-table" data-af-aqr-table="1"' . $styleAttr . $dataHeaders . '>' . $x . '</table>';
            },
            $message2
        );

        if (!is_string($message2) || $message2 === '' || $message2 === $before) {
            $message2 = $before;
            break;
        }
    }

    if (!empty($protected)) {
        $message2 = strtr($message2, $protected);
    }

    $message = $message2;
}

function af_aqr_bbcode_table_parse_attrs(string $raw): array
{
    $raw = trim($raw);

    $out = [
        'width'   => '',
        'align'   => '',
        'headers' => '',
    ];

    if ($raw === '') {
        return $out;
    }

    if (preg_match('~\bwidth\s*=\s*([0-9]{1,4})(px|%)\b~i', $raw, $m)) {
        $out['width'] = $m[1] . $m[2];
    }

    if (preg_match('~\balign\s*=\s*(left|center|right)\b~i', $raw, $m2)) {
        $out['align'] = strtolower($m2[1]);
    }

    if (preg_match('~\bheaders\s*=\s*(none|row|col|both)\b~i', $raw, $m3)) {
        $h = strtolower($m3[1]);
        $out['headers'] = ($h === 'none') ? '' : $h;
    }

    return $out;
}
