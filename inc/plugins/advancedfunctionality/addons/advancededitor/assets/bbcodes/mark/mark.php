<?php

if (!defined('IN_MYBB')) {
    exit;
}

if (!function_exists('af_ae_bbcode_mark_trim_quoted')) {
    function af_ae_bbcode_mark_trim_quoted(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        $f = substr($value, 0, 1);
        $l = substr($value, -1);
        if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
            $value = substr($value, 1, -1);
        }

        return trim($value);
    }

    function af_ae_bbcode_mark_normalize_color(?string $value): string
    {
        $value = af_ae_bbcode_mark_trim_quoted((string)$value);
        if ($value === '') return '';

        if (preg_match('~^#([0-9a-f]{3})$~i', $value, $m)) {
            $s = strtoupper($m[1]);
            return '#' . $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
        }

        if (preg_match('~^#([0-9a-f]{6})$~i', $value, $m)) {
            return '#' . strtoupper($m[1]);
        }

        return '';
    }

    function af_ae_bbcode_mark_parse_attrs(string $raw): array
    {
        $attrs = [];

        if (preg_match_all('~([a-z_][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))~i', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $key = strtolower((string)$row[1]);
                $val = $row[2] !== '' ? $row[2] : ($row[3] !== '' ? $row[3] : ($row[4] ?? ''));
                $attrs[$key] = (string)$val;
            }
        }

        return $attrs;
    }

    function af_ae_bbcode_mark_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[mark') === false) {
            return;
        }

        $result = preg_replace_callback(
            '~\[mark([^\]]*)\]((?:(?!\[/?mark\b).|(?R))*)\[/mark\]~is',
            static function ($m) {
                $attrsRaw = (string)($m[1] ?? '');
                $inner = (string)($m[2] ?? '');
                $attrs = af_ae_bbcode_mark_parse_attrs($attrsRaw);

                $bg = af_ae_bbcode_mark_normalize_color($attrs['bgcolor'] ?? '') ?: '#FFF2A8';
                $text = af_ae_bbcode_mark_normalize_color($attrs['textcolor'] ?? '') ?: '#202020';

                $style = 'background-color:' . htmlspecialchars_uni($bg) . ';color:' . htmlspecialchars_uni($text) . ';';

                return '<span class="af-ae-mark-render" data-af-bb="mark" data-bgcolor="' . htmlspecialchars_uni($bg) . '" data-textcolor="' . htmlspecialchars_uni($text) . '" style="' . $style . '">' . $inner . '</span>';
            },
            $message
        );

        if (is_string($result)) {
            $message = $result;
        }
    }
}
