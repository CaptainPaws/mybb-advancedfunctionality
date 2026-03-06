<?php
/**
 * AE BBCode Pack: table
 * Рендерит [table]...[tr][td]... в HTML table.
 * КРИТИЧНО: чистит <br> которые MyBB вставляет из переносов.
 */

if (!defined('IN_MYBB')) { exit; }

/**
 * НОВОЕ "каноничное" имя для AdvancedEditor.
 */

function af_ae_bbcode_table_parse_start(&$message): void
{
    if (!is_string($message) || $message === '' || stripos($message, '[table') === false) {
        return;
    }

    $protected = [];
    $message2 = preg_replace_callback('~\[(code|php)\b[^\]]*\].*?\[/\1\]~is', function ($m) use (&$protected) {
        $key = '%%AE_TBL_BBCODE_PROTECT_' . count($protected) . '%%';
        $protected[$key] = $m[0];
        return $key;
    }, $message);

    if (!is_string($message2) || $message2 === '') {
        return;
    }

    $message2 = preg_replace('~\[(/?)table\b([^\]]*)\]~i', '[$1af_table$2]', $message2);
    $message2 = preg_replace('~\[(/?)tr\b([^\]]*)\]~i', '[$1af_tr$2]', $message2);
    $message2 = preg_replace('~\[(/?)td\b([^\]]*)\]~i', '[$1af_td$2]', $message2);
    $message2 = preg_replace('~\[(/?)th\b([^\]]*)\]~i', '[$1af_th$2]', $message2);

    if (!empty($protected)) {
        $message2 = strtr($message2, $protected);
    }

    $message = $message2;
}

function af_ae_bbcode_table_parse_end(&$message): void
{
    af_ae_bbcode_table_parse($message);
}

function af_ae_bbcode_table_parse(&$message): void
{
    if (!is_string($message) || $message === '') return;

    // быстрый skip
    if (stripos($message, '[table') === false && stripos($message, '[af_table') === false) return;

    // защитим <pre>/<code>
    $protected = [];
    $message2 = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', function ($m) use (&$protected) {
        $key = '%%AE_TBL_PROTECT_' . count($protected) . '%%';
        $protected[$key] = $m[0];
        return $key;
    }, $message);

    if (!is_string($message2) || $message2 === '') return;

    // поддержка pre-pass: возвращаем каноничные теги для рендера
    $message2 = preg_replace('~\[(/?)af_table\b([^\]]*)\]~i', '[$1table$2]', $message2);
    $message2 = preg_replace('~\[(/?)af_tr\b([^\]]*)\]~i', '[$1tr$2]', $message2);
    $message2 = preg_replace('~\[(/?)af_td\b([^\]]*)\]~i', '[$1td$2]', $message2);
    $message2 = preg_replace('~\[(/?)af_th\b([^\]]*)\]~i', '[$1th$2]', $message2);

    // ВАЖНО: рендерим вложенные таблицы изнутри наружу
    $guard = 0;
    while (stripos($message2, '[table') !== false && $guard++ < 30) {
        $before = $message2;

        $message2 = preg_replace_callback(
            '~\[table([^\]]*)\]((?:(?!\[table).)*?)\[/table\]~is',
            function ($m) {
                $attrRaw = (string)($m[1] ?? '');
                $body    = (string)($m[2] ?? '');

                $attrs = af_ae_bbcode_table_parse_attrs($attrRaw);

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

                // Инлайн-стили ячеек для фронта/preview (независимо от загрузки CSS темы).
                $headersMode = !empty($attrs['headers']) ? $attrs['headers'] : '';
                $rowIndex = 0;
                $x = preg_replace_callback('~<tr>(.*?)</tr>~is', function ($mr) use (&$rowIndex, $attrs, $headersMode) {
                    $rowIndex++;
                    $colIndex = 0;
                    $rowHtml = preg_replace_callback('~<(td|th)([^>]*)>(.*?)</\1>~is', function ($mc) use (&$colIndex, $attrs, $headersMode, $rowIndex) {
                        $colIndex++;
                        $tag = strtolower((string)$mc[1]);
                        $extraAttrs = (string)$mc[2];
                        $innerHtml = (string)$mc[3];

                        $isHeaderByMode = false;
                        if ($headersMode === 'row' && $rowIndex === 1) $isHeaderByMode = true;
                        if ($headersMode === 'col' && $colIndex === 1) $isHeaderByMode = true;
                        if ($headersMode === 'both' && ($rowIndex === 1 || $colIndex === 1)) $isHeaderByMode = true;

                        $shouldHeaderStyle = ($tag === 'th') || $isHeaderByMode;

                        $styleFromCell = '';
                        if (preg_match('~\bstyle\s*=\s*"([^"]*)"~i', $extraAttrs, $sm)) {
                            $styleFromCell = trim((string)$sm[1]);
                        }

                        $cellStyle = af_ae_bbcode_table_build_cell_style($attrs, $shouldHeaderStyle, $styleFromCell);
                        $newAttrs = preg_replace('~\bstyle\s*=\s*"[^"]*"~i', '', $extraAttrs);
                        $newAttrs = trim((string)$newAttrs);

                        return '<' . $tag . ($newAttrs !== '' ? ' ' . $newAttrs : '') . ' style="' . htmlspecialchars_uni($cellStyle) . '">' . $innerHtml . '</' . $tag . '>';
                    }, (string)$mr[1]);

                    return '<tr>' . $rowHtml . '</tr>';
                }, $x);

                $styles = [];

                // layout
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

                // NEW: css variables for rendering
                // bgcolor/textcolor apply to td/th via CSS
                if (!empty($attrs['bgcolor'])) {
                    $styles[] = '--af-tbl-bg:' . $attrs['bgcolor'];
                }
                if (!empty($attrs['textcolor'])) {
                    $styles[] = '--af-tbl-txt:' . $attrs['textcolor'];
                }
                // header-specific variables
                if (!empty($attrs['hbgcolor'])) {
                    $styles[] = '--af-tbl-hbg:' . $attrs['hbgcolor'];
                }
                if (!empty($attrs['htextcolor'])) {
                    $styles[] = '--af-tbl-htxt:' . $attrs['htextcolor'];
                }

                // borders: if border=0 -> width 0
                $borderOn = (!isset($attrs['border']) || $attrs['border'] !== '0');
                if (!$borderOn) {
                    $styles[] = '--af-tbl-bw:0px';
                } else {
                    if (!empty($attrs['borderwidth'])) {
                        $styles[] = '--af-tbl-bw:' . $attrs['borderwidth'];
                    }
                    if (!empty($attrs['bordercolor'])) {
                        $styles[] = '--af-tbl-bc:' . $attrs['bordercolor'];
                    }
                }

                $styleAttr = $styles ? (' style="' . htmlspecialchars_uni(implode(';', $styles)) . '"') : '';


                $dataAttrs = [' data-af-table="1"'];
                $map = [
                    'width' => 'width',
                    'align' => 'align',
                    'headers' => 'headers',
                    'bgcolor' => 'bgcolor',
                    'textcolor' => 'textcolor',
                    'hbgcolor' => 'hbgcolor',
                    'htextcolor' => 'htextcolor',
                    'border' => 'border',
                    'bordercolor' => 'bordercolor',
                    'borderwidth' => 'borderwidth',
                ];

                foreach ($map as $src => $dst) {
                    $value = isset($attrs[$src]) ? trim((string)$attrs[$src]) : '';
                    if ($dst === 'border') {
                        if ($value === '') {
                            $value = '1';
                        }
                    } elseif ($value === '') {
                        continue;
                    }
                    $dataAttrs[] = ' data-af-' . $dst . '="' . htmlspecialchars_uni($value) . '"';
                }

                // legacy alias for older CSS/selectors
                $headers = !empty($attrs['headers']) ? trim((string)$attrs['headers']) : '';
                if ($headers !== '') {
                    $dataAttrs[] = ' data-headers="' . htmlspecialchars_uni($headers) . '"';
                }

                return '<table class="af-ae-table"' . implode('', $dataAttrs) . $styleAttr . '>' . $x . '</table>';
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

function af_ae_bbcode_table_parse_attrs(string $raw): array
{
    $raw = trim($raw);

    $out = [
        'width'         => '',
        'align'         => '',
        'headers'       => '',

        // colors (по умолчанию пусто => наследуем от темы)
        'bgcolor'       => '',   // td/th общий фон (если задан)
        'textcolor'     => '',   // td/th общий цвет текста (если задан)

        // header-specific (по умолчанию пусто => наследуем/дефолт темы)
        'hbgcolor'      => '',   // фон только заголовков (th)
        'htextcolor'    => '',   // цвет текста только заголовков (th)

        // borders
        'border'        => '1',  // если нет атрибута, оставляем 1 как раньше
        'bordercolor'   => '',
        'borderwidth'   => '',
    ];

    if ($raw === '') {
        return $out;
    }

    // width
    if (preg_match('~\bwidth\s*=\s*([0-9]{1,4})(px|%|em|rem|vw|vh)\b~i', $raw, $m)) {
        $out['width'] = $m[1] . strtolower($m[2]);
    } elseif (preg_match('~\bwidth\s*=\s*([0-9]{1,4})(px|%)\b~i', $raw, $m2)) {
        $out['width'] = $m2[1] . strtolower($m2[2]);
    }

    if (preg_match('~\balign\s*=\s*(left|center|right)\b~i', $raw, $m)) {
        $out['align'] = strtolower($m[1]);
    }

    if (preg_match('~\bheaders\s*=\s*(none|row|col|both)\b~i', $raw, $m)) {
        $h = strtolower($m[1]);
        $out['headers'] = ($h === 'none') ? '' : $h;
    }

    // общие цвета
    if (preg_match('~\bbgcolor\s*=\s*(#[0-9a-f]{3}(?:[0-9a-f]{3})?)\b~i', $raw, $m)) {
        $out['bgcolor'] = strtolower($m[1]);
    }
    if (preg_match('~\btextcolor\s*=\s*(#[0-9a-f]{3}(?:[0-9a-f]{3})?)\b~i', $raw, $m)) {
        $out['textcolor'] = strtolower($m[1]);
    }

    // заголовки: отдельные атрибуты
    // поддержим два имени на выбор: hbgcolor/theadbg и htextcolor/theadcolor
    if (preg_match('~\b(hbgcolor|theadbg)\s*=\s*(#[0-9a-f]{3}(?:[0-9a-f]{3})?)\b~i', $raw, $m)) {
        $out['hbgcolor'] = strtolower($m[2]);
    }
    if (preg_match('~\b(htextcolor|theadcolor)\s*=\s*(#[0-9a-f]{3}(?:[0-9a-f]{3})?)\b~i', $raw, $m)) {
        $out['htextcolor'] = strtolower($m[2]);
    }

    // borders
    if (preg_match('~\bborder\s*=\s*(0|1)\b~i', $raw, $m)) {
        $out['border'] = $m[1];
    }

    if (preg_match('~\bbordercolor\s*=\s*(#[0-9a-f]{3}(?:[0-9a-f]{3})?)\b~i', $raw, $m)) {
        $out['bordercolor'] = strtolower($m[1]);
    }

    if (preg_match('~\bborderwidth\s*=\s*([0-9]{1,2})px\b~i', $raw, $m)) {
        $n = (int)$m[1];
        if ($n < 0) $n = 0;
        if ($n > 20) $n = 20;
        $out['borderwidth'] = $n . 'px';
    }

    return $out;
}

function af_ae_bbcode_table_build_cell_style(array $attrs, bool $isHeader, string $existingStyle = ''): string
{
    $styles = [];
    $existingStyle = trim($existingStyle);
    if ($existingStyle !== '') {
        $styles[] = rtrim($existingStyle, ';');
    }

    if (!empty($attrs['bgcolor'])) {
        $styles[] = 'background-color:' . $attrs['bgcolor'];
    }
    if (!empty($attrs['textcolor'])) {
        $styles[] = 'color:' . $attrs['textcolor'];
    }

    if ($isHeader) {
        if (!empty($attrs['hbgcolor'])) {
            $styles[] = 'background-color:' . $attrs['hbgcolor'];
        }
        if (!empty($attrs['htextcolor'])) {
            $styles[] = 'color:' . $attrs['htextcolor'];
        }
        $styles[] = 'font-weight:700';
    }

    $borderOn = (!isset($attrs['border']) || $attrs['border'] !== '0');
    if ($borderOn) {
        $bw = !empty($attrs['borderwidth']) ? $attrs['borderwidth'] : '1px';
        $bc = !empty($attrs['bordercolor']) ? $attrs['bordercolor'] : '#888888';
        $styles[] = 'border:' . $bw . ' solid ' . $bc;
    } else {
        $styles[] = 'border:0';
    }

    $styles[] = 'padding:6px 8px';
    $styles[] = 'vertical-align:top';

    return implode(';', $styles);
}

function af_aqr_bbcode_table_parse_start(&$message): void
{
    af_ae_bbcode_table_parse_start($message);
}

function af_aqr_bbcode_table_parse_end(&$message): void
{
    af_ae_bbcode_table_parse_end($message);
}

/**
 * ОБРАТНАЯ СОВМЕСТИМОСТЬ: если диспетчер старый (AQR) — он зовёт это имя.
 * Мы просто прокидываем на AE-функцию.
 */
function af_aqr_bbcode_table_parse(&$message): void
{
    af_ae_bbcode_table_parse($message);
}

function af_aqr_bbcode_table_parse_attrs(string $raw): array
{
    return af_ae_bbcode_table_parse_attrs($raw);
}
