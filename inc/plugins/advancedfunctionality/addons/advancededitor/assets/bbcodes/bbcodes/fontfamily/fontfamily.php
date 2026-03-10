<?php

if (!defined('IN_MYBB')) {
    exit;
}

if (!function_exists('af_ae_bbcode_fontfamily_parse_start')) {
    function af_ae_bbcode_fontfamily_parse_start(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[font') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~\[(code|php)\b[^\]]*\].*?\[/\1\]~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_FONTFAMILY_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        // Прячем стандартный [font] от дефолтного MyBB-парсера
        $message2 = preg_replace(
            '~\[(\/?)font(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\]]+))?\]~i',
            '[$1af_font$2]',
            $message2
        );

        if (!empty($protected)) {
            $message2 = strtr($message2, $protected);
        }

        $message = $message2;
    }

    function af_ae_bbcode_fontfamily_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[af_font') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~<(pre|code)\b[^>]*>.*?</\1>~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_FONTFAMILY_HTML_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $guard = 0;

        while (stripos($message2, '[af_font') !== false && $guard++ < 40) {
            $before = $message2;

            $message2 = preg_replace_callback(
                '~\[af_font(?:=([^\]]+))?\]((?:(?!\[/?af_font\b).|(?R))*)\[/af_font\]~is',
                static function ($m) {
                    $rawFamily = (string)($m[1] ?? '');
                    $inner     = (string)($m[2] ?? '');

                    $family = af_ae_bbcode_fontfamily_normalize_value($rawFamily);
                    if ($family === '') {
                        return $inner;
                    }

                    $cssFamily = af_ae_bbcode_fontfamily_css_value($family);

                    return
                        '<span class="af-bb-fontfamily" data-af-fontfamily="' . htmlspecialchars_uni($family) . '" style="font-family:' . htmlspecialchars_uni($cssFamily) . ';">' .
                            $inner .
                        '</span>';
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

    function af_ae_bbcode_fontfamily_trim_quoted(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $first = substr($value, 0, 1);
        $last  = substr($value, -1);

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        return trim($value);
    }

    function af_ae_bbcode_fontfamily_normalize_value(string $raw): string
    {
        $value = af_ae_bbcode_fontfamily_trim_quoted($raw);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('~[\x00-\x1F\x7F]+~u', '', $value);
        $value = str_replace(['[', ']', "\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('~\s+~u', ' ', $value);
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        // Если вдруг прилетел CSS-список семейств, берём первое.
        if (strpos($value, ',') !== false) {
            $parts = explode(',', $value, 2);
            $value = af_ae_bbcode_fontfamily_trim_quoted((string)$parts[0]);
        }

        return trim($value);
    }

    function af_ae_bbcode_fontfamily_css_value(string $family): string
    {
        $family = af_ae_bbcode_fontfamily_normalize_value($family);

        if ($family === '') {
            return '';
        }

        $family = str_replace(['\\', '"', "'"], '', $family);

        if (preg_match('~\s~u', $family)) {
            return "'" . $family . "'";
        }

        return $family;
    }

    function af_aqr_bbcode_fontfamily_parse_start(&$message): void
    {
        af_ae_bbcode_fontfamily_parse_start($message);
    }

    function af_aqr_bbcode_fontfamily_parse_end(&$message): void
    {
        af_ae_bbcode_fontfamily_parse_end($message);
    }
}
