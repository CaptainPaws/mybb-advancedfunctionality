<?php

if (!defined('IN_MYBB')) {
    exit;
}

if (!function_exists('af_ae_bbcode_fontsize_parse_start')) {
    function af_ae_bbcode_fontsize_parse_start(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[size') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~\[(code|php)\b[^\]]*\].*?\[/\1\]~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_FONTSIZE_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        // Прячем стандартный size от дефолтного MyBB-парсера
        $message2 = preg_replace(
            '~\[(\/?)size(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\]]+))?\]~i',
            '[$1af_size$2]',
            $message2
        );

        if (!empty($protected)) {
            $message2 = strtr($message2, $protected);
        }

        $message = $message2;
    }

    function af_ae_bbcode_fontsize_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[af_size') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~<(pre|code)\b[^>]*>.*?</\1>~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_FONTSIZE_HTML_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $guard = 0;

        while (stripos($message2, '[af_size') !== false && $guard++ < 40) {
            $before = $message2;

            $message2 = preg_replace_callback(
                '~\[af_size(?:=([^\]]+))?\]((?:(?!\[/?af_size\b).|(?R))*)\[/af_size\]~is',
                static function ($m) {
                    $rawSize = (string)($m[1] ?? '');
                    $inner   = (string)($m[2] ?? '');

                    $size = af_ae_bbcode_fontsize_normalize_value($rawSize);

                    if ($size === '') {
                        return $inner;
                    }

                    return
                        '<span class="af-bb-fontsize" data-af-fontsize="' . htmlspecialchars_uni($size) . '" style="font-size:' . htmlspecialchars_uni($size) . ';">' .
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

    function af_ae_bbcode_fontsize_trim_quoted(string $value): string
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

    function af_ae_bbcode_fontsize_normalize_value(string $raw): string
    {
        $value = strtolower(af_ae_bbcode_fontsize_trim_quoted($raw));

        if ($value === '') {
            return '';
        }

        // Поддержка старых MyBB size=1..7, если такие посты остались
        $legacyMap = [
            1 => '8px',
            2 => '10px',
            3 => '12px',
            4 => '14px',
            5 => '18px',
            6 => '24px',
            7 => '32px',
        ];

        if (preg_match('~^\d+$~', $value)) {
            $n = (int)$value;

            if (isset($legacyMap[$n])) {
                return $legacyMap[$n];
            }

            if ($n < 8) {
                $n = 8;
            } elseif ($n > 36) {
                $n = 36;
            }

            return $n . 'px';
        }

        if (preg_match('~^(\d+(?:\.\d+)?)(px|em|rem|%|pt)$~i', $value, $m)) {
            $num  = $m[1];
            $unit = strtolower($m[2]);

            if ($unit === 'px') {
                $n = (int)round((float)$num);

                if ($n < 8) {
                    $n = 8;
                } elseif ($n > 36) {
                    $n = 36;
                }

                return $n . 'px';
            }

            return $num . $unit;
        }

        return '';
    }

    // aliases, если где-то в старой логике дергаются aqr-имена
    function af_aqr_bbcode_fontsize_parse_start(&$message): void
    {
        af_ae_bbcode_fontsize_parse_start($message);
    }

    function af_aqr_bbcode_fontsize_parse_end(&$message): void
    {
        af_ae_bbcode_fontsize_parse_end($message);
    }
}
