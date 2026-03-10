<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

if (!function_exists('af_ae_bbcode_resizeimg_trim_quoted')) {
    function af_ae_bbcode_resizeimg_trim_quoted(string $value): string
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

    function af_ae_bbcode_resizeimg_parse_dimension(string $raw): int
    {
        $raw = strtolower(af_ae_bbcode_resizeimg_trim_quoted($raw));

        if ($raw === '') {
            return 0;
        }

        if (preg_match('~^(\d+)(?:px)?$~i', $raw, $m)) {
            $value = (int)$m[1];

            if ($value < 1) {
                $value = 1;
            } elseif ($value > 4000) {
                $value = 4000;
            }

            return $value;
        }

        return 0;
    }

    function af_ae_bbcode_resizeimg_parse_attrs(string $raw): array
    {
        $raw = trim($raw);

        $out = [
            'width'  => 0,
            'height' => 0,
        ];

        if ($raw === '') {
            return $out;
        }

        if (preg_match_all('~(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))~', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $key = strtolower((string)$row[1]);
                $val = '';

                if (isset($row[3]) && $row[3] !== '') {
                    $val = (string)$row[3];
                } elseif (isset($row[4]) && $row[4] !== '') {
                    $val = (string)$row[4];
                } else {
                    $val = (string)($row[5] ?? '');
                }

                if ($key === 'width' || $key === 'w') {
                    $out['width'] = af_ae_bbcode_resizeimg_parse_dimension($val);
                } elseif ($key === 'height' || $key === 'h') {
                    $out['height'] = af_ae_bbcode_resizeimg_parse_dimension($val);
                }
            }
        }

        return $out;
    }

    function af_ae_bbcode_resizeimg_build_af_img_tag(array $attrs, string $inner): string
    {
        $parts = [];

        if (!empty($attrs['width'])) {
            $parts[] = 'width=' . (int)$attrs['width'];
        }

        if (!empty($attrs['height'])) {
            $parts[] = 'height=' . (int)$attrs['height'];
        }

        $attrString = $parts ? ' ' . implode(' ', $parts) : '';

        return '[af_img' . $attrString . ']' . $inner . '[/af_img]';
    }

    /**
     * Перехватываем только [img ...] с width/height.
     * Обычный [img]url[/img] не трогаем — его дальше парсит MyBB как обычно.
     */
    function af_ae_bbcode_resizeimg_parse_start(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[img') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~\[(code|php)\b[^\]]*\].*?\[/\1\]~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_RESIZEIMG_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $message2 = preg_replace_callback(
            '~\[img(?:=([^\]]+)|\s+([^\]]+))?\](.*?)\[/img\]~is',
            static function ($m) {
                $attrRaw = '';

                if (isset($m[1]) && $m[1] !== '') {
                    $attrRaw = (string)$m[1];
                } elseif (isset($m[2]) && $m[2] !== '') {
                    $attrRaw = (string)$m[2];
                }

                $inner = (string)($m[3] ?? '');

                if (trim($attrRaw) === '') {
                    return $m[0];
                }

                $attrs = af_ae_bbcode_resizeimg_parse_attrs($attrRaw);

                if ((int)$attrs['width'] <= 0 && (int)$attrs['height'] <= 0) {
                    return $m[0];
                }

                return af_ae_bbcode_resizeimg_build_af_img_tag($attrs, $inner);
            },
            $message2
        );

        if (!empty($protected)) {
            $message2 = strtr($message2, $protected);
        }

        $message = $message2;
    }

    /**
     * Рендерим только наш временный [af_img ...]...[/af_img]
     */
    function af_ae_bbcode_resizeimg_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[af_img') === false) {
            return;
        }

        $protected = [];

        $message2 = preg_replace_callback(
            '~<(pre|code)\b[^>]*>.*?</\1>~is',
            static function ($m) use (&$protected) {
                $key = '%%AE_RESIZEIMG_HTML_PROTECT_' . count($protected) . '%%';
                $protected[$key] = $m[0];
                return $key;
            },
            $message
        );

        if (!is_string($message2) || $message2 === '') {
            return;
        }

        $guard = 0;

        while (stripos($message2, '[af_img') !== false && $guard++ < 40) {
            $before = $message2;

            $message2 = preg_replace_callback(
                '~\[af_img(?:\s+([^\]]+))?\](.*?)\[/af_img\]~is',
                static function ($m) {
                    $attrRaw = isset($m[1]) ? (string)$m[1] : '';
                    $src     = trim((string)($m[2] ?? ''));

                    if ($src === '') {
                        return '';
                    }

                    $attrs = af_ae_bbcode_resizeimg_parse_attrs($attrRaw);
                    $width = (int)($attrs['width'] ?? 0);
                    $height = (int)($attrs['height'] ?? 0);

                    $html = '<img class="af-resizeimg" data-af-img="1" data-af-src="' . htmlspecialchars_uni($src) . '" src="' . htmlspecialchars_uni($src) . '" alt=""';

                    if ($width > 0) {
                        $html .= ' data-af-img-width="' . $width . '" width="' . $width . '"';
                    }

                    if ($height > 0) {
                        $html .= ' data-af-img-height="' . $height . '" height="' . $height . '"';
                    }

                    $style = [];

                    if ($width > 0) {
                        $style[] = 'width:' . $width . 'px';
                    }

                    if ($height > 0) {
                        $style[] = 'height:' . $height . 'px';
                    } elseif ($width > 0) {
                        $style[] = 'height:auto';
                    }

                    if ($style) {
                        $html .= ' style="' . htmlspecialchars_uni(implode(';', $style)) . ';"';
                    }

                    $html .= ' />';

                    return $html;
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

    function af_aqr_bbcode_resizeimg_parse_start(&$message): void
    {
        af_ae_bbcode_resizeimg_parse_start($message);
    }

    function af_aqr_bbcode_resizeimg_parse_end(&$message): void
    {
        af_ae_bbcode_resizeimg_parse_end($message);
    }
}