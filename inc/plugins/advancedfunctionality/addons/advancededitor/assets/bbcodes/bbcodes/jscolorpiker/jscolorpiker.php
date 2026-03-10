<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_ae_jscolorpiker_normalize_color(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^#([0-9a-f]{3})$/i', $raw, $m)) {
        $s = strtoupper($m[1]);
        return '#' . $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
    }

    if (preg_match('/^#([0-9a-f]{6})$/i', $raw, $m)) {
        return '#' . strtoupper($m[1]);
    }

    if (preg_match('/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/i', $raw, $m)) {
        $r = max(0, min(255, (int)$m[1]));
        $g = max(0, min(255, (int)$m[2]));
        $b = max(0, min(255, (int)$m[3]));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    return '';
}
