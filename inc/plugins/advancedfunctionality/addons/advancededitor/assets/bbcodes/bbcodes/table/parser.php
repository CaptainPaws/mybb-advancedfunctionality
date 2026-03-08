<?php
/**
 * AE BBCode Pack: table
 * Каноничный парсер таблиц: attrs/normalization/render/data-af-* и inline styles — только тут.
 */

if (!defined('IN_MYBB')) { exit; }

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
    if (stripos($message, '[table') === false && stripos($message, '[af_table') === false) return;

    $protected = [];
    $message2 = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', function ($m) use (&$protected) {
        $key = '%%AE_TBL_PROTECT_' . count($protected) . '%%';
        $protected[$key] = $m[0];
        return $key;
    }, $message);

    if (!is_string($message2) || $message2 === '') return;

    $message2 = preg_replace('~\[(/?)af_table\b([^\]]*)\]~i', '[$1table$2]', $message2);
    $message2 = preg_replace('~\[(/?)af_tr\b([^\]]*)\]~i', '[$1tr$2]', $message2);
    $message2 = preg_replace('~\[(/?)af_td\b([^\]]*)\]~i', '[$1td$2]', $message2);
    $message2 = preg_replace('~\[(/?)af_th\b([^\]]*)\]~i', '[$1th$2]', $message2);

    $guard = 0;
    while (stripos($message2, '[table') !== false && $guard++ < 30) {
        $before = $message2;

        $message2 = preg_replace_callback(
            '~\[table([^\]]*)\]((?:(?!\[table).)*?)\[/table\]~is',
            function ($m) {
                $attrs = af_ae_bbcode_table_parse_attrs((string)($m[1] ?? ''));
                $body = (string)($m[2] ?? '');

                $body = preg_replace('~\]\s*<br\s*/?>\s*\[~i', '][', $body);
                $body = preg_replace('~<br\s*/?>\s*\[(/?(?:tr|td|th)(?:[^\]]*)?)\]~i', '[$1]', $body);
                $body = preg_replace('~\[(/?(?:tr|td|th)(?:[^\]]*)?)\]\s*<br\s*/?>~i', '[$1]', $body);
                $body = preg_replace('~^(?:\s*<br\s*/?>\s*)+~i', '', $body);
                $body = preg_replace('~(?:\s*<br\s*/?>\s*)+$~i', '', $body);

                $x = $body;
                $x = preg_replace('~\[(/?)tr\]~i', '<$1tr>', $x);
                $x = preg_replace_callback('~\[(td|th)([^\]]*)\]~i', function ($cell) {
                    $tag = strtolower((string)$cell[1]);
                    $width = af_ae_bbcode_table_parse_cell_width((string)($cell[2] ?? ''));
                    $style = $width !== '' ? ' style="width:' . htmlspecialchars_uni($width) . '"' : '';
                    return '<' . $tag . $style . '>';
                }, $x);
                $x = preg_replace('~\[/td\]~i', '</td>', $x);
                $x = preg_replace('~\[/th\]~i', '</th>', $x);

                $headersMode = !empty($attrs['headers']) ? $attrs['headers'] : '';
                $rowIndex = 0;
                $x = preg_replace_callback('~<tr>(.*?)</tr>~is', function ($rowMatch) use (&$rowIndex, $attrs, $headersMode) {
                    $rowIndex++;
                    $colIndex = 0;

                    $cells = preg_replace_callback('~<(td|th)([^>]*)>(.*?)</\1>~is', function ($cellMatch) use (&$colIndex, $attrs, $headersMode, $rowIndex) {
                        $colIndex++;
                        $tag = strtolower((string)$cellMatch[1]);
                        $extraAttrs = (string)$cellMatch[2];
                        $innerHtml = (string)$cellMatch[3];

                        $isHeaderByMode = false;
                        if ($headersMode === 'row' && $rowIndex === 1) $isHeaderByMode = true;
                        if ($headersMode === 'col' && $colIndex === 1) $isHeaderByMode = true;
                        if ($headersMode === 'both' && ($rowIndex === 1 || $colIndex === 1)) $isHeaderByMode = true;

                        $styleFromCell = '';
                        if (preg_match('~\bstyle\s*=\s*"([^"]*)"~i', $extraAttrs, $sm)) {
                            $styleFromCell = trim((string)$sm[1]);
                        }

                        $cellStyle = af_ae_bbcode_table_build_cell_style($attrs, ($tag === 'th') || $isHeaderByMode, $styleFromCell);
                        $newAttrs = trim((string)preg_replace('~\bstyle\s*=\s*"[^"]*"~i', '', $extraAttrs));

                        return '<' . $tag . ($newAttrs !== '' ? ' ' . $newAttrs : '') . ' style="' . htmlspecialchars_uni($cellStyle) . '">' . $innerHtml . '</' . $tag . '>';
                    }, (string)$rowMatch[1]);

                    return '<tr>' . $cells . '</tr>';
                }, $x);

                $tableStyle = af_ae_bbcode_table_build_table_style($attrs);
                $styleAttr = $tableStyle !== '' ? ' style="' . htmlspecialchars_uni($tableStyle) . '"' : '';
                $dataAttrs = af_ae_bbcode_table_build_data_attrs($attrs);
                $dataHeaders = $attrs['headers'] !== '' ? ' data-headers="' . htmlspecialchars_uni($attrs['headers']) . '"' : '';

                return '<table class="af-ae-table" data-af-table="1"' . $dataAttrs . $styleAttr . $dataHeaders . '>' . $x . '</table>';
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

function af_ae_bbcode_table_read_attr(string $raw, string $name): string
{
    $name = preg_quote($name, '~');
    if (!preg_match("~\\b" . $name . "\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s\\]]+))~i", $raw, $m)) {
        return '';
    }

    if (isset($m[2]) && $m[2] !== '') return trim((string)$m[2]);
    if (isset($m[3]) && $m[3] !== '') return trim((string)$m[3]);
    if (isset($m[4]) && $m[4] !== '') return trim((string)$m[4]);
    return '';
}

function af_ae_bbcode_table_parse_attrs(string $raw): array
{
    $out = [
        'width' => '',
        'align' => '',
        'headers' => '',
        'bgcolor' => '',
        'textcolor' => '',
        'hbgcolor' => '',
        'htextcolor' => '',
        'border' => '1',
        'bordercolor' => '',
        'borderwidth' => '',
    ];

    $raw = trim($raw);
    if ($raw === '') return $out;

    $width = af_ae_bbcode_table_read_attr($raw, 'width');
    if (preg_match('~^([0-9]{1,4})(px|%|em|rem|vw|vh)?$~i', $width, $wm)) {
        $out['width'] = $wm[1] . (!empty($wm[2]) ? strtolower($wm[2]) : 'px');
    }

    $align = strtolower(af_ae_bbcode_table_read_attr($raw, 'align'));
    if (in_array($align, ['left', 'center', 'right'], true)) $out['align'] = $align;

    $headers = strtolower(af_ae_bbcode_table_read_attr($raw, 'headers'));
    if (in_array($headers, ['row', 'col', 'both'], true)) $out['headers'] = $headers;

    foreach (['bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'bordercolor'] as $key) {
        $v = strtolower(af_ae_bbcode_table_read_attr($raw, $key));
        if ($v !== '' && preg_match('~^#[0-9a-f]{3}(?:[0-9a-f]{3})?$~i', $v)) {
            $out[$key] = $v;
        }
    }

    $border = af_ae_bbcode_table_read_attr($raw, 'border');
    if ($border === '0' || $border === '1') $out['border'] = $border;

    $borderWidth = af_ae_bbcode_table_read_attr($raw, 'borderwidth');
    if (preg_match('~^([0-9]{1,2})px$~i', $borderWidth, $bm)) {
        $n = (int)$bm[1];
        if ($n < 0) $n = 0;
        if ($n > 20) $n = 20;
        $out['borderwidth'] = $n . 'px';
    }

    return $out;
}

function af_ae_bbcode_table_parse_cell_width(string $raw): string
{
    $width = af_ae_bbcode_table_read_attr($raw, 'width');
    if (!preg_match('~^([0-9]{1,4})(px|%|em|rem|vw|vh)?$~i', $width, $m)) {
        return '';
    }

    $unit = !empty($m[2]) ? strtolower($m[2]) : 'px';
    return $m[1] . $unit;
}

function af_ae_bbcode_table_build_table_style(array $attrs): string
{
    $styles = [];

    if (!empty($attrs['width'])) $styles[] = 'width:' . $attrs['width'];

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

    $styles[] = 'border-collapse:collapse';
    $styles[] = 'border-spacing:0';

    if (!empty($attrs['bgcolor'])) $styles[] = '--af-tbl-bg:' . $attrs['bgcolor'];
    if (!empty($attrs['textcolor'])) $styles[] = '--af-tbl-txt:' . $attrs['textcolor'];
    if (!empty($attrs['hbgcolor'])) $styles[] = '--af-tbl-hbg:' . $attrs['hbgcolor'];
    if (!empty($attrs['htextcolor'])) $styles[] = '--af-tbl-htxt:' . $attrs['htextcolor'];

    $borderOn = (!isset($attrs['border']) || $attrs['border'] !== '0');
    if (!$borderOn) {
        $styles[] = '--af-tbl-bw:0px';
    } else {
        $bw = !empty($attrs['borderwidth']) ? $attrs['borderwidth'] : '1px';
        $bc = !empty($attrs['bordercolor']) ? $attrs['bordercolor'] : '#888888';
        $styles[] = '--af-tbl-bw:' . $bw;
        $styles[] = '--af-tbl-bc:' . $bc;
        $styles[] = 'border:' . $bw . ' solid ' . $bc;
    }

    return implode(';', $styles);
}

function af_ae_bbcode_table_build_cell_style(array $attrs, bool $isHeader, string $existingStyle = ''): string
{
    $styles = [];
    $existingStyle = trim($existingStyle);
    if ($existingStyle !== '') $styles[] = rtrim($existingStyle, ';');

    if (!empty($attrs['bgcolor'])) $styles[] = 'background-color:' . $attrs['bgcolor'];
    if (!empty($attrs['textcolor'])) $styles[] = 'color:' . $attrs['textcolor'];

    if ($isHeader) {
        if (!empty($attrs['hbgcolor'])) $styles[] = 'background-color:' . $attrs['hbgcolor'];
        if (!empty($attrs['htextcolor'])) $styles[] = 'color:' . $attrs['htextcolor'];
        $styles[] = 'font-weight:700';
        $styles[] = 'text-align:left';
    }

    if (!isset($attrs['border']) || $attrs['border'] !== '0') {
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

function af_ae_bbcode_table_build_data_attrs(array $attrs): string
{
    $pairs = [];
    $map = [
        'width' => (string)($attrs['width'] ?? ''),
        'align' => (string)($attrs['align'] ?? ''),
        'headers' => (string)($attrs['headers'] ?? ''),
        'bgcolor' => (string)($attrs['bgcolor'] ?? ''),
        'textcolor' => (string)($attrs['textcolor'] ?? ''),
        'hbgcolor' => (string)($attrs['hbgcolor'] ?? ''),
        'htextcolor' => (string)($attrs['htextcolor'] ?? ''),
        'border' => (string)($attrs['border'] ?? '1'),
        'bordercolor' => (string)($attrs['bordercolor'] ?? ''),
        'borderwidth' => (string)($attrs['borderwidth'] ?? ''),
    ];

    foreach ($map as $key => $value) {
        $value = trim($value);
        if ($key === 'border' && $value === '') $value = '1';
        if ($value === '') continue;
        $pairs[] = ' data-af-' . $key . '="' . htmlspecialchars_uni($value) . '"';
    }

    return implode('', $pairs);
}

function af_aqr_bbcode_table_parse_start(&$message): void
{
    af_ae_bbcode_table_parse_start($message);
}

function af_aqr_bbcode_table_parse_end(&$message): void
{
    af_ae_bbcode_table_parse_end($message);
}

function af_aqr_bbcode_table_parse(&$message): void
{
    af_ae_bbcode_table_parse($message);
}

function af_aqr_bbcode_table_parse_attrs(string $raw): array
{
    return af_ae_bbcode_table_parse_attrs($raw);
}
