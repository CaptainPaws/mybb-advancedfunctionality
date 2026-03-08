<?php

if (!defined('IN_MYBB')) {
    exit;
}

function af_ae_bbcode_table_parse_start(&$message): void
{
    if (!is_string($message) || $message === '' || stripos($message, '[table') === false) {
        return;
    }

    $message = preg_replace('~\[(/?)table\b([^\]]*)\]~i', '[$1af_table$2]', $message);
    $message = preg_replace('~\[(/?)tr\b([^\]]*)\]~i', '[$1af_tr$2]', $message);
    $message = preg_replace('~\[(/?)td\b([^\]]*)\]~i', '[$1af_td$2]', $message);
    $message = preg_replace('~\[(/?)th\b([^\]]*)\]~i', '[$1af_th$2]', $message);
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

    $message = preg_replace('~\[(/?)af_table\b([^\]]*)\]~i', '[$1table$2]', $message);
    $message = preg_replace('~\[(/?)af_tr\b([^\]]*)\]~i', '[$1tr$2]', $message);
    $message = preg_replace('~\[(/?)af_td\b([^\]]*)\]~i', '[$1td$2]', $message);
    $message = preg_replace('~\[(/?)af_th\b([^\]]*)\]~i', '[$1th$2]', $message);

    $protected = [];
    $message = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', static function ($m) use (&$protected) {
        $key = '%%AF_TABLE_PROTECTED_' . count($protected) . '%%';
        $protected[$key] = $m[0];
        return $key;
    }, $message);

    $guard = 0;
    while (stripos($message, '[table') !== false && $guard++ < 40) {
        $before = $message;
        $message = preg_replace_callback('~\[table([^\]]*)\]((?:(?!\[table).)*)\[/table\]~is', static function ($m) {
            $attrs = af_ae_bbcode_table_parse_attrs((string)($m[1] ?? ''));
            return af_ae_bbcode_table_render_html($attrs, (string)($m[2] ?? ''));
        }, $message);

        if (!is_string($message) || $message === $before) {
            $message = $before;
            break;
        }
    }

    if (!empty($protected)) {
        $message = strtr($message, $protected);
    }
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

    if ($raw === '') {
        return $out;
    }

    preg_match_all('~([a-z]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s\]]+)~i', $raw, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $key = strtolower(trim((string)$match[1]));
        $value = trim((string)$match[2], " \t\n\r\0\x0B\"'");

        switch ($key) {
            case 'width':
                if (preg_match('~^([0-9]{1,4})(px|%|em|rem|vw|vh)?$~i', $value, $m)) {
                    $out['width'] = $m[1] . (!empty($m[2]) ? strtolower($m[2]) : 'px');
                }
                break;
            case 'align':
                $align = strtolower($value);
                if (in_array($align, ['left', 'center', 'right'], true)) {
                    $out['align'] = $align;
                }
                break;
            case 'headers':
                $headers = strtolower($value);
                if (in_array($headers, ['row', 'col', 'both'], true)) {
                    $out['headers'] = $headers;
                }
                break;
            case 'bgcolor':
            case 'textcolor':
            case 'hbgcolor':
            case 'htextcolor':
            case 'bordercolor':
                if (preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~i', $value)) {
                    $out[$key] = strtolower($value);
                }
                break;
            case 'border':
                $out['border'] = ($value === '0') ? '0' : '1';
                break;
            case 'borderwidth':
                if (preg_match('~^([0-9]{1,2})px$~i', $value, $m)) {
                    $bw = max(0, min(20, (int)$m[1]));
                    $out['borderwidth'] = $bw . 'px';
                }
                break;
        }
    }

    return $out;
}

function af_ae_bbcode_table_render_html(array $attrs, string $rawBody): string
{
    $body = preg_replace('~\]\s*<br\s*/?>\s*\[~i', '][', $rawBody);
    $body = preg_replace('~^(?:\s*<br\s*/?>\s*)+~i', '', (string)$body);
    $body = preg_replace('~(?:\s*<br\s*/?>\s*)+$~i', '', (string)$body);

    preg_match_all('~\[tr\]([\s\S]*?)\[/tr\]~i', $body, $rowsRaw, PREG_SET_ORDER);
    if (empty($rowsRaw)) {
        return $rawBody;
    }

    $rows = [];
    foreach ($rowsRaw as $rowMatch) {
        $cells = [];
        preg_match_all('~\[(td|th)([^\]]*)\]([\s\S]*?)\[/\1\]~i', (string)$rowMatch[1], $cellRaw, PREG_SET_ORDER);
        foreach ($cellRaw as $cellMatch) {
            $tag = strtolower((string)$cellMatch[1]) === 'th' ? 'th' : 'td';
            $cellAttrsRaw = (string)($cellMatch[2] ?? '');
            $cellInner = (string)($cellMatch[3] ?? '');
            $width = '';
            if (preg_match('~\bwidth\s*=\s*([^\s\]]+)~i', $cellAttrsRaw, $mW) && preg_match('~^([0-9]{1,4})(px|%|em|rem|vw|vh)?$~i', $mW[1], $mUnit)) {
                $width = $mUnit[1] . (!empty($mUnit[2]) ? strtolower($mUnit[2]) : 'px');
            }
            $cells[] = ['tag' => $tag, 'width' => $width, 'html' => $cellInner];
        }
        if (!empty($cells)) {
            $rows[] = $cells;
        }
    }

    if (empty($rows)) {
        return $rawBody;
    }

    $tableStyle = af_ae_bbcode_table_build_table_style($attrs);
    $tableDataAttrs = af_ae_bbcode_table_build_data_attrs($attrs);

    $html = '<table class="af-ae-table" data-af-table="1"' . $tableDataAttrs;
    if ($tableStyle !== '') {
        $html .= ' style="' . htmlspecialchars_uni($tableStyle) . '"';
    }
    $html .= '>';

    foreach ($rows as $rowIndex => $rowCells) {
        $html .= '<tr>';
        foreach ($rowCells as $colIndex => $cell) {
            $isHeaderByMode = af_ae_bbcode_table_is_header_cell_by_mode($attrs['headers'] ?? '', $rowIndex, $colIndex);
            $isHeader = ($cell['tag'] === 'th') || $isHeaderByMode;
            $cellStyle = af_ae_bbcode_table_build_cell_style($attrs, $isHeader, $cell['width']);
            $widthAttr = $cell['width'] !== '' ? ' data-af-width="' . htmlspecialchars_uni($cell['width']) . '"' : '';
            $tag = $cell['tag'];
            $html .= '<' . $tag . $widthAttr . ' style="' . htmlspecialchars_uni($cellStyle) . '">' . $cell['html'] . '</' . $tag . '>';
        }
        $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
}

function af_ae_bbcode_table_is_header_cell_by_mode(string $mode, int $rowIndex, int $colIndex): bool
{
    if ($mode === 'row') {
        return $rowIndex === 0;
    }
    if ($mode === 'col') {
        return $colIndex === 0;
    }
    if ($mode === 'both') {
        return $rowIndex === 0 || $colIndex === 0;
    }
    return false;
}

function af_ae_bbcode_table_build_table_style(array $attrs): string
{
    $styles = ['border-collapse:collapse', 'border-spacing:0'];

    if (!empty($attrs['width'])) {
        $styles[] = 'width:' . $attrs['width'];
    }

    $align = (string)($attrs['align'] ?? '');
    if ($align === 'center') {
        $styles[] = 'margin-left:auto';
        $styles[] = 'margin-right:auto';
    } elseif ($align === 'right') {
        $styles[] = 'margin-left:auto';
    } elseif ($align === 'left') {
        $styles[] = 'margin-right:auto';
    }

    if (!empty($attrs['bgcolor'])) {
        $styles[] = '--af-tbl-bg:' . $attrs['bgcolor'];
    }
    if (!empty($attrs['textcolor'])) {
        $styles[] = '--af-tbl-txt:' . $attrs['textcolor'];
    }
    if (!empty($attrs['hbgcolor'])) {
        $styles[] = '--af-tbl-hbg:' . $attrs['hbgcolor'];
    }
    if (!empty($attrs['htextcolor'])) {
        $styles[] = '--af-tbl-htxt:' . $attrs['htextcolor'];
    }

    if (($attrs['border'] ?? '1') === '0') {
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

function af_ae_bbcode_table_build_cell_style(array $attrs, bool $isHeader, string $width = ''): string
{
    $styles = ['padding:6px 8px', 'vertical-align:top'];

    if ($width !== '') {
        $styles[] = 'width:' . $width;
    }

    if ($isHeader) {
        $styles[] = 'font-weight:700';
        $styles[] = 'text-align:left';

        if (!empty($attrs['hbgcolor'])) {
            $styles[] = 'background-color:' . $attrs['hbgcolor'];
        } elseif (!empty($attrs['bgcolor'])) {
            $styles[] = 'background-color:' . $attrs['bgcolor'];
        }

        if (!empty($attrs['htextcolor'])) {
            $styles[] = 'color:' . $attrs['htextcolor'];
        } elseif (!empty($attrs['textcolor'])) {
            $styles[] = 'color:' . $attrs['textcolor'];
        }
    } else {
        if (!empty($attrs['bgcolor'])) {
            $styles[] = 'background-color:' . $attrs['bgcolor'];
        }
        if (!empty($attrs['textcolor'])) {
            $styles[] = 'color:' . $attrs['textcolor'];
        }
    }

    if (($attrs['border'] ?? '1') === '0') {
        $styles[] = 'border:0';
    } else {
        $bw = !empty($attrs['borderwidth']) ? $attrs['borderwidth'] : '1px';
        $bc = !empty($attrs['bordercolor']) ? $attrs['bordercolor'] : '#888888';
        $styles[] = 'border:' . $bw . ' solid ' . $bc;
    }

    return implode(';', $styles);
}

function af_ae_bbcode_table_build_data_attrs(array $attrs): string
{
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

    $result = '';
    foreach ($map as $key => $value) {
        $value = trim($value);
        if ($key === 'border' && $value === '') {
            $value = '1';
        }
        if ($value === '') {
            continue;
        }
        $result .= ' data-af-' . $key . '="' . htmlspecialchars_uni($value) . '"';
    }

    return $result;
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
