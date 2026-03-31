<?php

if (!defined('IN_MYBB')) {
    exit;
}

if (!function_exists('af_ae_bbcode_abbr_trim_quoted')) {
    function af_ae_bbcode_abbr_trim_quoted(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $f = substr($value, 0, 1);
        $l = substr($value, -1);
        if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
            $value = substr($value, 1, -1);
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        return trim($value);
    }

    function af_ae_bbcode_abbr_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '' || stripos($message, '[abbr') === false) {
            return;
        }

        $message = preg_replace_callback(
            '~\[abbr(?:=([^\]]+))?\](.*?)\[/abbr\]~is',
            static function (array $m): string {
                $rawTip = af_ae_bbcode_abbr_trim_quoted((string)($m[1] ?? ''));
                $inner = (string)($m[2] ?? '');

                if ($rawTip === '') {
                    $rawTip = strip_tags($inner);
                }

                $safeTip = htmlspecialchars_uni($rawTip);

                return '<abbr class="af-bb-abbr" data-af-bb="abbr" title="' . $safeTip . '">' . $inner . '</abbr>';
            },
            $message
        );
    }
}
