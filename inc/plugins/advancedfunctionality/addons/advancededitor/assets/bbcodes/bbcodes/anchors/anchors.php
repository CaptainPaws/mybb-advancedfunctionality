<?php

if (!defined('IN_MYBB')) {
    exit;
}

if (!function_exists('af_ae_bbcode_anchor_strip_quotes')) {
    function af_ae_bbcode_anchor_strip_quotes(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $first = substr($value, 0, 1);
        $last = substr($value, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        return trim($value);
    }

    function af_ae_bbcode_anchor_slug(string $value): string
    {
        $value = af_ae_bbcode_anchor_strip_quotes($value);
        $value = preg_replace('~[\x00-\x1F\x7F]+~u', '', $value);
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('~\s+~u', '_', $value);
        $value = preg_replace('~[^a-z0-9_\-]+~u', '_', $value);
        $value = preg_replace('~_+~', '_', $value);
        $value = trim((string)$value, '_-');

        if ($value === '') {
            return '';
        }

        if (!preg_match('~^[a-z]~', $value)) {
            $value = 'a_' . $value;
        }

        return substr($value, 0, 96);
    }

    function af_ae_bbcode_anchors_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        if (stripos($message, '[anchor') === false && stripos($message, '[anchorlink') === false) {
            return;
        }

        $message = preg_replace_callback(
            '~\[anchor\s+id\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*\]\s*\[/anchor\]~iu',
            static function (array $m): string {
                $raw = '';
                if (isset($m[1]) && $m[1] !== '') {
                    $raw = (string)$m[1];
                } elseif (isset($m[2]) && $m[2] !== '') {
                    $raw = (string)$m[2];
                } else {
                    $raw = (string)($m[3] ?? '');
                }

                $key = af_ae_bbcode_anchor_slug($raw);
                if ($key === '') {
                    return '';
                }

                $safeKey = htmlspecialchars_uni($key);
                $fallbackId = htmlspecialchars_uni('af-ae-anchor-' . $key);

                return '<span class="af-bb-anchor-target" data-af-bb="anchor" data-af-anchor-key="' . $safeKey . '" id="' . $fallbackId . '"></span>';
            },
            $message
        );

        $message = preg_replace_callback(
            '~\[anchorlink\s+target\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*\](.*?)\[/anchorlink\]~isu',
            static function (array $m): string {
                $raw = '';
                if (isset($m[1]) && $m[1] !== '') {
                    $raw = (string)$m[1];
                } elseif (isset($m[2]) && $m[2] !== '') {
                    $raw = (string)$m[2];
                } else {
                    $raw = (string)($m[3] ?? '');
                }

                $content = (string)($m[4] ?? '');
                $key = af_ae_bbcode_anchor_slug($raw);

                if ($key === '') {
                    return $content;
                }

                $safeKey = htmlspecialchars_uni($key);
                $href = htmlspecialchars_uni('#af-ae-anchor-' . $key);

                return '<a href="' . $href . '" class="af-bb-anchor-link" data-af-bb="anchorlink" data-af-anchor-target="' . $safeKey . '">' . $content . '</a>';
            },
            $message
        );
    }
}
