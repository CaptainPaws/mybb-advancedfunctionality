<?php
/**
 * AE BBCode Pack: FloatBB
 * Tag: [float=left|right]...[/float]
 * Converts in parse_message_end (HTML already produced inside).
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

/**
 * AE dispatch will call: af_ae_bbcode_floatbb_parse_end(&$message)
 */
function af_ae_bbcode_floatbb_parse_end(&$message): void
{
    if (!is_string($message) || $message === '') {
        return;
    }

    // было: [floatbb
    if (stripos($message, '[float') === false) {
        return;
    }

    // Protect <pre> and <code> blocks from replacements
    $store = [];
    $i = 0;

    $message = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', function($m) use (&$store, &$i) {
        $key = '%%AF_FLOATBB_PROTECT_' . (++$i) . '%%';
        $store[$key] = $m[0];
        return $key;
    }, $message);

    // Replace [float=left|right]...[/float]
    $message = preg_replace_callback(
        '~\[float=(left|right|l|r|1|2)\](.*?)\[/float\]~is',
        function($m) {
            $dir = strtolower($m[1]);
            if ($dir === 'right' || $dir === 'r' || $dir === '2') {
                $dir = 'right';
            } else {
                $dir = 'left';
            }

            $inner = $m[2];

            return '<div class="af-floatbb af-floatbb-' . $dir . '">' . $inner . '</div>';
        },
        $message
    );

    // Restore protected blocks
    if (!empty($store)) {
        $message = strtr($message, $store);
    }
}
