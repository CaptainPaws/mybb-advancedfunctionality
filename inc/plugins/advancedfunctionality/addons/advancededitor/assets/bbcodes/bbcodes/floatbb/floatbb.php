<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_ae_bbcode_floatbb_normalize_dir(string $raw): string
{
    $raw = strtolower(trim($raw));

    if ($raw === 'right' || $raw === 'r' || $raw === '2') {
        return 'right';
    }

    return 'left';
}

function af_ae_bbcode_floatbb_parse_end(&$message): void
{
    if (!is_string($message) || $message === '') {
        return;
    }

    if (stripos($message, '[float') === false && stripos($message, '[floatbb') === false) {
        return;
    }

    $protected = [];
    $message2 = preg_replace_callback(
        '~<(pre|code)\b[^>]*>.*?</\1>~is',
        static function ($m) use (&$protected) {
            $key = '%%AF_FLOATBB_PROTECT_' . count($protected) . '%%';
            $protected[$key] = $m[0];
            return $key;
        },
        $message
    );

    if (!is_string($message2) || $message2 === '') {
        return;
    }

    // алиас: [floatbb=...] -> [float=...]
    $message2 = preg_replace('~\[(/?)floatbb\b~i', '[$1float', $message2);

    $guard = 0;

    while (stripos($message2, '[float=') !== false && $guard++ < 40) {
        $before = $message2;

        $message2 = preg_replace_callback(
            // ВАЖНО:
            // съедаем ОДИН ближайший <br /> после [/float],
            // чтобы после публикации не появлялась "пустая строка".
            '~\[float=([^\]]+)\](.*?)\[/float\](?:<br\s*/?>)?~is',
            static function ($m) {
                $dir = af_ae_bbcode_floatbb_normalize_dir((string)($m[1] ?? 'left'));
                $inner = (string)($m[2] ?? '');

                return
                    '<div class="af-floatbb af-floatbb-' . htmlspecialchars_uni($dir) . '" data-af-bb="float" data-af-dir="' . htmlspecialchars_uni($dir) . '">' .
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
