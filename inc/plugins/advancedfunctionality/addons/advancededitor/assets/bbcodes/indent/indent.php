<?php
/**
 * AE BBCode Pack: Indent
 *
 * Работает через общий диспетчер AE в parse_message_end:
 * - [indent=1]текст до <br> или конца
 * - [indent=2]...[/indent] (тоже поддерживаем)
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

function af_ae_bbcode_indent_parse_end(string &$message): void
{
    if ($message === '' || stripos($message, '[indent=') === false) {
        return;
    }

    // 1) Сначала парный вариант: [indent=2]...[/indent]
    $message = preg_replace_callback(
        '~\[indent=(1|2|3)\]([\s\S]*?)\[/indent\]~i',
        function(array $m): string {
            $lvl = (int)$m[1];
            if ($lvl < 1) $lvl = 1;
            if ($lvl > 3) $lvl = 3;

            $inner = $m[2];
            return '<div class="af-indent af-indent-' . $lvl . '">' . $inner . '</div>';
        },
        $message
    );

    // 2) Одинарный вариант: [indent=2]ТЕКСТ ДО <br> ИЛИ КОНЦА
    // ВАЖНО: после parse_message у тебя переносы уже обычно <br />, поэтому стопорим по <br>
    $message = preg_replace_callback(
        '~\[indent=(1|2|3)\]([\s\S]*?)(?=<br\s*/?>|\r?\n|$)~i',
        function(array $m): string {
            $lvl = (int)$m[1];
            if ($lvl < 1) $lvl = 1;
            if ($lvl > 3) $lvl = 3;

            $inner = $m[2];
            return '<div class="af-indent af-indent-' . $lvl . '">' . $inner . '</div>';
        },
        $message
    );
}
