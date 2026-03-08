<?php
/**
 * AE BBCode Pack: table
 * Каноничный PHP-рендер [table]...[tr][td]/[th] для всех режимов.
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
    if (!is_string($message) || $message === '') {
        return;
    }

    if (stripos($message, '[table') === false && stripos($message, '[af_table') === false) {
        return;
    }

    $protected = [];
    $message2 = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', function ($m) use (&$protected) {
        $key = '%%AE_TBL_PROTECT_' . count($protected) . '%%';
        $protected[$key] = $m[0];
        return $key;
    }, $message);

    if (!is_string($message2) || $message2 === '') {
        return;
    }

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
                return af_ae_bbcode_table_render_html((string)($m[2] ?? ''), $attrs);
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
    $attrs = [];
    $raw = trim($raw);

    if ($raw !== '') {
        preg_match_all('~([a-z_][a-z0-9_-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s\]]+))~i', $raw, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $key = strtolower((string)$m[1]);
            $value = '';
            if (isset($m[3]) && $m[3] !== '') {
                $value = (string)$m[3];
            } elseif (isset($m[4]) && $m[4] !== '') {
                $value = (string)$m[4];
            } elseif (isset($m[5])) {
                $value = (string)$m[5];
            }
            $attrs[$key] = trim($value);
        }
    }

    return af_ae_bbcode_table_normalize_attrs($attrs);
}

function af_ae_bbcode_table_normalize_attrs(array $attrs): array
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

    $width = trim((string)($attrs['width'] ?? ''));
    if (preg_match('~^([0-9]{1,4})(px|%|em|rem|vw|vh)?$~i', $width, $m)) {
        $out['width'] = $m[1] . (!empty($m[2]) ? strtolower($m[2]) : 'px');
    }

    $align = strtolower(trim((string)($attrs['align'] ?? '')));
    if (in_array($align, ['left', 'center', 'right'], true)) {
        $out['align'] = $align;
    }

    $headers = strtolower(trim((string)($attrs['headers'] ?? '')));
    if (in_array($headers, ['row', 'col', 'both'], true)) {
        $out['headers'] = $headers;
    }

    $out['bgcolor'] = af_ae_bbcode_table_normalize_color((string)($attrs['bgcolor'] ?? ''));
    $out['textcolor'] = af_ae_bbcode_table_normalize_color((string)($attrs['textcolor'] ?? ''));
    $out['hbgcolor'] = af_ae_bbcode_table_normalize_color((string)($attrs['hbgcolor'] ?? ''));
    $out['htextcolor'] = af_ae_bbcode_table_normalize_color((string)($attrs['htextcolor'] ?? ''));
    $out['bordercolor'] = af_ae_bbcode_table_normalize_color((string)($attrs['bordercolor'] ?? ''));

    $border = trim((string)($attrs['border'] ?? '1'));
    if ($border === '0' || $border === '1') {
        $out['border'] = $border;
    }

    $borderWidth = trim((string)($attrs['borderwidth'] ?? ''));
    if (preg_match('~^([0-9]{1,2})(px)?$~i', $borderWidth, $m)) {
        $n = (int)$m[1];
        if ($n < 0) { $n = 0; }
        if ($n > 20) { $n = 20; }
        $out['borderwidth'] = $n . 'px';
    }

    if ($out['border'] === '1') {
        if ($out['bordercolor'] === '') {
            $out['bordercolor'] = '#888888';
        }
        if ($out['borderwidth'] === '') {
            $out['borderwidth'] = '1px';
        }
    }

    return $out;
}

function af_ae_bbcode_table_normalize_color(string $value): string
{
    $value = strtolower(trim($value));
    if ($value !== '' && preg_match('~^#[0-9a-f]{3}(?:[0-9a-f]{3})?$~', $value)) {
        return $value;
    }
    return '';
}

function af_ae_bbcode_table_attrs_to_style(array $attrs): string
{
    $attrs = af_ae_bbcode_table_normalize_attrs($attrs);
    $styles = ['border-collapse:collapse', 'border-spacing:0'];

    if ($attrs['width'] !== '') {
        $styles[] = 'width:' . $attrs['width'];
    }

    if ($attrs['align'] === 'center') {
        $styles[] = 'margin-left:auto';
        $styles[] = 'margin-right:auto';
    } elseif ($attrs['align'] === 'right') {
        $styles[] = 'margin-left:auto';
    } elseif ($attrs['align'] === 'left') {
        $styles[] = 'margin-right:auto';
    }

    if ($attrs['bgcolor'] !== '') {
        $styles[] = '--af-tbl-bg:' . $attrs['bgcolor'];
    }
    if ($attrs['textcolor'] !== '') {
        $styles[] = '--af-tbl-txt:' . $attrs['textcolor'];
    }
    if ($attrs['hbgcolor'] !== '') {
        $styles[] = '--af-tbl-hbg:' . $attrs['hbgcolor'];
    }
    if ($attrs['htextcolor'] !== '') {
        $styles[] = '--af-tbl-htxt:' . $attrs['htextcolor'];
    }

    if ($attrs['border'] === '1') {
        $styles[] = '--af-tbl-bw:' . $attrs['borderwidth'];
        $styles[] = '--af-tbl-bc:' . $attrs['bordercolor'];
        $styles[] = 'border:' . $attrs['borderwidth'] . ' solid ' . $attrs['bordercolor'];
    } else {
        $styles[] = '--af-tbl-bw:0px';
        $styles[] = 'border:0';
    }

    return implode(';', $styles);
}

function af_ae_bbcode_table_attrs_to_data_attrs(array $attrs): string
{
    $attrs = af_ae_bbcode_table_normalize_attrs($attrs);
    $keys = ['width', 'align', 'headers', 'bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'border', 'bordercolor', 'borderwidth'];

    $pairs = [];
    foreach ($keys as $key) {
        $pairs[] = ' data-af-' . $key . '="' . htmlspecialchars_uni((string)$attrs[$key]) . '"';
    }

    return implode('', $pairs);
}

function af_ae_bbcode_table_build_cell_style(array $attrs, bool $isHeader, string $existingStyle = ''): string
{
    $attrs = af_ae_bbcode_table_normalize_attrs($attrs);
    $styles = [];

    $width = '';
    if (preg_match('~(?:^|;)\s*width\s*:\s*([^;]+)~i', $existingStyle, $m)) {
        $width = trim((string)$m[1]);
    }
    if ($width !== '' && preg_match('~^([0-9]{1,4})(px|%|em|rem|vw|vh)?$~i', $width, $mw)) {
        $styles[] = 'width:' . $mw[1] . (!empty($mw[2]) ? strtolower($mw[2]) : 'px');
    }

    $styles[] = 'padding:6px 8px';
    $styles[] = 'vertical-align:top';
    $styles[] = 'background-color:' . ($isHeader ? ($attrs['hbgcolor'] !== '' ? $attrs['hbgcolor'] : ($attrs['bgcolor'] !== '' ? $attrs['bgcolor'] : 'transparent')) : ($attrs['bgcolor'] !== '' ? $attrs['bgcolor'] : 'transparent'));
    $styles[] = 'color:' . ($isHeader ? ($attrs['htextcolor'] !== '' ? $attrs['htextcolor'] : ($attrs['textcolor'] !== '' ? $attrs['textcolor'] : 'inherit')) : ($attrs['textcolor'] !== '' ? $attrs['textcolor'] : 'inherit'));
    $styles[] = $attrs['border'] === '1' ? ('border:' . $attrs['borderwidth'] . ' solid ' . $attrs['bordercolor']) : 'border:0';

    if ($isHeader) {
        $styles[] = 'font-weight:700';
        $styles[] = 'text-align:left';
    }

    return implode(';', $styles);
}

function af_ae_bbcode_table_render_html(string $bbcodeBody, array $attrs): string
{
    $attrs = af_ae_bbcode_table_normalize_attrs($attrs);

    $body = preg_replace('~\]\s*<br\s*/?>\s*\[~i', '][', $bbcodeBody);
    $body = preg_replace('~<br\s*/?>\s*\[(/?(?:tr|td|th)(?:[^\]]*)?)\]~i', '[$1]', $body);
    $body = preg_replace('~\[(/?(?:tr|td|th)(?:[^\]]*)?)\]\s*<br\s*/?>~i', '[$1]', $body);
    $body = preg_replace('~^(?:\s*<br\s*/?>\s*)+~i', '', (string)$body);
    $body = preg_replace('~(?:\s*<br\s*/?>\s*)+$~i', '', (string)$body);

    $x = (string)$body;
    $x = preg_replace('~\[(/?)tr\]~i', '<$1tr>', $x);

    $x = preg_replace_callback('~\[(td|th)([^\]]*)\]~i', function ($m) {
        $tag = strtolower((string)$m[1]);
        $raw = trim((string)($m[2] ?? ''));

        $style = '';
        if (preg_match('~\bwidth\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s\]]+))~i', $raw, $wm)) {
            $width = '';
            if (isset($wm[2]) && $wm[2] !== '') {
                $width = $wm[2];
            } elseif (isset($wm[3]) && $wm[3] !== '') {
                $width = $wm[3];
            } elseif (isset($wm[4])) {
                $width = $wm[4];
            }

            if (preg_match('~^([0-9]{1,4})(px|%|em|rem|vw|vh)?$~i', trim($width), $mWidth)) {
                $unit = !empty($mWidth[2]) ? strtolower($mWidth[2]) : 'px';
                $style = ' style="width:' . htmlspecialchars_uni($mWidth[1] . $unit) . '"';
            }
        }

        return '<' . $tag . $style . '>';
    }, $x);

    $x = preg_replace('~\[/td\]~i', '</td>', $x);
    $x = preg_replace('~\[/th\]~i', '</th>', $x);

    $headersMode = $attrs['headers'];
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
            if ($headersMode === 'row' && $rowIndex === 1) { $isHeaderByMode = true; }
            if ($headersMode === 'col' && $colIndex === 1) { $isHeaderByMode = true; }
            if ($headersMode === 'both' && ($rowIndex === 1 || $colIndex === 1)) { $isHeaderByMode = true; }

            $isHeader = ($tag === 'th') || $isHeaderByMode;

            $styleFromCell = '';
            if (preg_match('~\bstyle\s*=\s*"([^"]*)"~i', $extraAttrs, $sm)) {
                $styleFromCell = trim((string)$sm[1]);
            }

            $cellStyle = af_ae_bbcode_table_build_cell_style($attrs, $isHeader, $styleFromCell);
            $newAttrs = preg_replace('~\bstyle\s*=\s*"[^"]*"~i', '', $extraAttrs);
            $newAttrs = trim((string)$newAttrs);

            return '<' . $tag . ($newAttrs !== '' ? ' ' . $newAttrs : '') . ' style="' . htmlspecialchars_uni($cellStyle) . '">' . $innerHtml . '</' . $tag . '>';
        }, (string)$mr[1]);

        return '<tr>' . $rowHtml . '</tr>';
    }, $x);

    $tableStyle = af_ae_bbcode_table_attrs_to_style($attrs);
    $dataAttrs = af_ae_bbcode_table_attrs_to_data_attrs($attrs);

    return '<table class="af-ae-table" data-af-table="1"' . $dataAttrs . ' style="' . htmlspecialchars_uni($tableStyle) . '">' . $x . '</table>';
}

function af_ae_bbcode_table_build_data_attrs(array $attrs): string
{
    return af_ae_bbcode_table_attrs_to_data_attrs($attrs);
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
