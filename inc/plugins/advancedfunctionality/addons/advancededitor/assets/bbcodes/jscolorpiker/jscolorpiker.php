<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_ae_jscolorpiker_alpha_to_hex(float $alpha): string
{
    if ($alpha < 0) {
        $alpha = 0;
    }

    if ($alpha > 1) {
        $alpha = 1;
    }

    $value = (int)round($alpha * 255);
    $hex = strtoupper(str_pad(dechex($value), 2, '0', STR_PAD_LEFT));

    return $hex;
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

    if (preg_match('/^#([0-9a-f]{8})$/i', $raw, $m)) {
        $hex = strtoupper($m[1]);

        if (substr($hex, 6, 2) === 'FF') {
            return '#' . substr($hex, 0, 6);
        }

        return '#' . $hex;
    }

    if (preg_match('/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/i', $raw, $m)) {
        $r = max(0, min(255, (int)$m[1]));
        $g = max(0, min(255, (int)$m[2]));
        $b = max(0, min(255, (int)$m[3]));

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    if (preg_match('/^rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]*\.?[0-9]+)\s*\)$/i', $raw, $m)) {
        $r = max(0, min(255, (int)$m[1]));
        $g = max(0, min(255, (int)$m[2]));
        $b = max(0, min(255, (int)$m[3]));
        $a = (float)$m[4];

        $base = sprintf('#%02X%02X%02X', $r, $g, $b);
        $alphaHex = af_ae_jscolorpiker_alpha_to_hex($a);

        if ($alphaHex === 'FF') {
            return $base;
        }

        return $base . $alphaHex;
    }

    return '';
}
