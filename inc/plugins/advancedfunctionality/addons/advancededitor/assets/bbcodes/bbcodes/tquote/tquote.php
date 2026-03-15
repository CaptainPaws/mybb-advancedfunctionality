<?php

if (!defined('IN_MYBB')) {
    exit;
}

if (defined('AF_AE_TQUOTE_LOADED')) {
    return;
}
define('AF_AE_TQUOTE_LOADED', 1);

if (!function_exists('af_ae_bbcode_tquote_parse_start')) {
    function af_ae_bbcode_tquote_parse_start(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[tquote') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~\[(code|php)\b[^\]]*\].*?\[/\1\]~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_TQUOTE_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $message2 = preg_replace(
            '~\[(\/?)tquote([^\]]*)\]~i',
            '[$1af_tquote$2]',
            $message2
        );

        if (!empty($protected)) {
            $message2 = strtr($message2, $protected);
        }

        $message = $message2;
    }

    function af_ae_bbcode_tquote_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[af_tquote') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~<(pre|code)\b[^>]*>.*?</\1>~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_TQUOTE_HTML_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $guard = 0;

        while (stripos($message2, '[af_tquote') !== false && $guard++ < 40) {
            $before = $message2;

            $message2 = preg_replace_callback(
                '~\[af_tquote(?:=([^\]]+)|\s+([^\]]+))?\]((?:(?!\[/?af_tquote\b).|(?R))*)\[/af_tquote\]~is',
                static function ($m) {
                    $attr = '';
                    if (isset($m[1]) && $m[1] !== '') {
                        $attr = (string) $m[1];
                    } elseif (isset($m[2]) && $m[2] !== '') {
                        $attr = (string) $m[2];
                    }

                    $inner = (string) ($m[3] ?? '');

                    [$side, $accent, $bg, $text] = af_ae_tquote_parse_attrs($attr);

                    $styleParts = [];

                    if ($accent !== '') {
                        $styleParts[] = '--af-tq-accent:' . $accent;
                    }

                    if ($bg !== '') {
                        $styleParts[] = '--af-tq-bg:' . $bg;
                    }

                    if ($text !== '') {
                        $styleParts[] = '--af-tq-text:' . $text;
                        $styleParts[] = 'color:' . $text;
                    }

                    $styleAttr = '';
                    if (!empty($styleParts)) {
                        $styleAttr = ' style="' . htmlspecialchars_uni(implode(';', $styleParts) . ';') . '"';
                    }

                    return
                        '<blockquote class="af-aqr-tquote" ' .
                            'data-af-tquote="1" ' .
                            'data-side="' . htmlspecialchars_uni($side) . '" ' .
                            'data-accent="' . htmlspecialchars_uni($accent) . '" ' .
                            'data-bg="' . htmlspecialchars_uni($bg) . '" ' .
                            'data-text="' . htmlspecialchars_uni($text) . '"' .
                            $styleAttr .
                        '>' .
                            $inner .
                        '</blockquote>';
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

    function af_ae_tquote_norm_side(string $raw): string
    {
        $x = strtolower(trim($raw));
        return ($x === 'right' || $x === 'r' || $x === '2') ? 'right' : 'left';
    }

    function af_ae_tquote_norm_hex(string $raw): string
    {
        $x = trim($raw);

        if ($x === '') {
            return '';
        }

        if (!preg_match('~^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$~', $x)) {
            return '';
        }

        $hex = strtolower(substr($x, 1));

        if (strlen($hex) === 3 || strlen($hex) === 4) {
            $expanded = '';
            foreach (str_split($hex) as $ch) {
                $expanded .= $ch . $ch;
            }
            $hex = $expanded;
        }

        return '#' . $hex;
    }

    function af_ae_tquote_parse_attrs(string $attrRaw): array
    {
        $attrRaw = trim($attrRaw);

        $side = 'left';
        $accent = '';
        $bg = '';
        $text = '';

        if ($attrRaw === '') {
            return [$side, $accent, $bg, $text];
        }

        if (preg_match_all('~(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))~', $attrRaw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $k = strtolower((string) $row[1]);
                $v = '';

                if (isset($row[3]) && $row[3] !== '') {
                    $v = (string) $row[3];
                } elseif (isset($row[4]) && $row[4] !== '') {
                    $v = (string) $row[4];
                } else {
                    $v = (string) ($row[5] ?? '');
                }

                if ($k === 'side' || $k === 'dir' || $k === 'align') {
                    $side = af_ae_tquote_norm_side($v);
                } elseif ($k === 'accent' || $k === 'a' || $k === 'color') {
                    $accent = af_ae_tquote_norm_hex($v);
                } elseif ($k === 'bg' || $k === 'background') {
                    $bg = af_ae_tquote_norm_hex($v);
                } elseif ($k === 'text' || $k === 'textcolor' || $k === 'fg' || $k === 'font') {
                    $text = af_ae_tquote_norm_hex($v);
                }
            }
        } else {
            if (preg_match('~^(?:=)?\s*(left|right|l|r|1|2)\s*$~i', $attrRaw, $mm)) {
                $side = af_ae_tquote_norm_side((string) $mm[1]);
            }
        }

        return [$side, $accent, $bg, $text];
    }

    function af_aqr_bbcode_tquote_parse_start(&$message): void
    {
        af_ae_bbcode_tquote_parse_start($message);
    }

    function af_aqr_bbcode_tquote_parse_end(&$message): void
    {
        af_ae_bbcode_tquote_parse_end($message);
    }
}

global $plugins;
if (isset($plugins) && is_object($plugins)) {
    $plugins->add_hook('parse_message_start', 'af_ae_bbcode_tquote_parse_start');
    $plugins->add_hook('parse_message_end', 'af_ae_bbcode_tquote_parse_end');
}
