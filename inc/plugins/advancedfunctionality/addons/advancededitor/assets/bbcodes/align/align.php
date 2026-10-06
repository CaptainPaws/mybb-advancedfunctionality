<?php

if (!defined('IN_MYBB')) {
    exit;
}

if (!function_exists('af_ae_bbcode_align_parse_start')) {
    function af_ae_bbcode_align_parse_start(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        $hasAlign =
            stripos($message, '[align') !== false ||
            stripos($message, '[left]') !== false ||
            stripos($message, '[center]') !== false ||
            stripos($message, '[right]') !== false ||
            stripos($message, '[justify]') !== false;

        if (!$hasAlign) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~\[(code|php)\b[^\]]*\].*?\[/\1\]~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_ALIGN_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $message2 = af_ae_bbcode_align_normalize_legacy_tags($message2);
        $message2 = af_ae_bbcode_align_collapse_same_align_tags($message2);

        // Прячем align от дефолтного MyBB-парсера
        $message2 = preg_replace(
            '~\[(\/?)align(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\]]+))?\]~i',
            '[$1af_align$2]',
            $message2
        );

        if (!empty($protected)) {
            $message2 = strtr($message2, $protected);
        }

        $message = $message2;
    }

    function af_ae_bbcode_align_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[af_align') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~<(pre|code)\b[^>]*>.*?</\1>~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_ALIGN_HTML_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $guard = 0;

        while (stripos($message2, '[af_align') !== false && $guard++ < 40) {
            $before = $message2;

            $message2 = preg_replace_callback(
                '~\[af_align(?:=([^\]]+))?\]((?:(?!\[/?af_align\b).|(?R))*)\[/af_align\]~is',
                static function ($m) {
                    $rawAlign = (string)($m[1] ?? '');
                    $inner    = (string)($m[2] ?? '');

                    $align = af_ae_bbcode_align_normalize_value($rawAlign);
                    if ($align === '') {
                        return $inner;
                    }

                    return
                        '<div class="af-bb-align" data-af-align="' . htmlspecialchars_uni($align) . '" style="text-align:' . htmlspecialchars_uni($align) . ';">' .
                            $inner .
                        '</div>';
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

    function af_ae_bbcode_align_normalize_legacy_tags(string $message): string
    {
        $map = [
            'left'    => 'left',
            'center'  => 'center',
            'right'   => 'right',
            'justify' => 'justify',
        ];

        foreach ($map as $legacy => $align) {
            $message = preg_replace(
                '~\[' . preg_quote($legacy, '~') . '\]~i',
                '[align=' . $align . ']',
                $message
            );

            $message = preg_replace(
                '~\[/'. preg_quote($legacy, '~') . '\]~i',
                '[/align]',
                $message
            );
        }

        $message = preg_replace('~\[/align\s*=\s*[^\]]+\]~i', '[/align]', $message);

        return $message;
    }

    function af_ae_bbcode_align_collapse_same_align_tags(string $message): string
    {
        $guard = 0;

        while ($guard++ < 30) {
            $changed = false;

            $message2 = preg_replace_callback(
                '~\[align=([^\]]+)\]\s*\[align=([^\]]+)\]([\s\S]*?)\[/align\]\s*\[/align\]~i',
                static function ($m) use (&$changed) {
                    $outer = af_ae_bbcode_align_normalize_value((string)($m[1] ?? ''));
                    $inner = af_ae_bbcode_align_normalize_value((string)($m[2] ?? ''));
                    $body  = (string)($m[3] ?? '');

                    if ($outer !== '' && $outer === $inner) {
                        $changed = true;
                        return '[align=' . $outer . ']' . $body . '[/align]';
                    }

                    return (string)$m[0];
                },
                $message
            );

            if (!is_string($message2) || $message2 === $message || !$changed) {
                break;
            }

            $message = $message2;
        }

        return $message;
    }

    function af_ae_bbcode_align_trim_quoted(string $value): string
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

    function af_ae_bbcode_align_normalize_value(string $raw): string
    {
        $value = strtolower(af_ae_bbcode_align_trim_quoted($raw));

        return match ($value) {
            'left', 'center', 'right', 'justify' => $value,
            'start' => 'left',
            'end'   => 'right',
            default => '',
        };
    }

    function af_aqr_bbcode_align_parse_start(&$message): void
    {
        af_ae_bbcode_align_parse_start($message);
    }

    function af_aqr_bbcode_align_parse_end(&$message): void
    {
        af_ae_bbcode_align_parse_end($message);
    }
}