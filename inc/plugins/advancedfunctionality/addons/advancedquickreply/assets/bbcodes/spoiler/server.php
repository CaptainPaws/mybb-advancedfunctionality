<?php
/**
 * AQR Pack: spoiler (server-side)
 *
 * BBCode:
 *  - [spoiler]...[/spoiler]
 *  - [spoiler="Заголовок с BBCode"]...[/spoiler]
 *  - [spoiler='...']...[/spoiler]
 *  - (fallback) [spoiler=Заголовок]...[/spoiler]
 *
 * Реализация:
 *  - parse_message_start: преобразуем spoiler -> quote + MARK + TITLE + SPLIT + BODY
 *  - parse_message_end: ищем quote с MARK, вырезаем <cite>, делаем HTML спойлера,
 *    lazyify медиа в body (src -> data-src).
 */

if (!defined('IN_MYBB')) { return; }
if (defined('AF_AQR_SPOILER_LOADED')) { return; }
define('AF_AQR_SPOILER_LOADED', 1);

function af_aqr_spoiler_mark_text(): string
{
    return 'AF_AQR_SPOILER__MARK__B12C';
}
function af_aqr_spoiler_split_text(): string
{
    return 'AF_AQR_SPOILER__SPLIT__B12C';
}
function af_aqr_spoiler_mark_bbcode(): string
{
    $m = af_aqr_spoiler_mark_text();
    return "[color=transparent][size=1]{$m}[/size][/color]";
}
function af_aqr_spoiler_split_bbcode(): string
{
    $m = af_aqr_spoiler_split_text();
    return "[color=transparent][size=1]{$m}[/size][/color]";
}

function af_aqr_spoiler_default_title(): string
{
    return 'Спойлер';
}

function af_aqr_spoiler_unescape_quoted(string $s): string
{
    // если кто-то руками набрал \" или \'
    $s = str_replace(['\\\\', '\\"', "\\'"], ['\\', '"', "'"], $s);
    return $s;
}

/**
 * Оборачиваем spoiler как quote с маркерами.
 * ВАЖНО: теперь поддерживаем quoted title, где может быть BBCode с ] внутри.
 */
function af_aqr_spoiler_parse_message_start(&$message): void
{
    if (!is_string($message) || $message === '' || stripos($message, '[spoiler') === false) {
        return;
    }

    $maxLoops = 120;

    // param может быть:
    // 1) "...." (с любыми символами, кроме незакрытой ")
    // 2) '....'
    // 3) без кавычек до ]
    $re = '~\[spoiler(?:=(?:"((?:\\\\.|[^"])*)"|\'((?:\\\\.|[^\'])*)\'|([^\]]*)))?\](.*?)\[/spoiler\]~is';

    for ($i = 0; $i < $maxLoops; $i++) {
        if (!preg_match($re, $message)) {
            break;
        }

        $message = preg_replace_callback(
            $re,
            function ($m) {
                $t1 = isset($m[1]) ? (string)$m[1] : '';
                $t2 = isset($m[2]) ? (string)$m[2] : '';
                $t3 = isset($m[3]) ? (string)$m[3] : '';
                $inner = isset($m[4]) ? (string)$m[4] : '';

                $title = '';
                if ($t1 !== '') $title = af_aqr_spoiler_unescape_quoted($t1);
                else if ($t2 !== '') $title = af_aqr_spoiler_unescape_quoted($t2);
                else $title = trim($t3);

                if ($title === '') {
                    $title = af_aqr_spoiler_default_title();
                }

                return "[quote]"
                    . af_aqr_spoiler_mark_bbcode()
                    . $title
                    . af_aqr_spoiler_split_bbcode()
                    . $inner
                    . "[/quote]";
            },
            $message
        );
    }
}

/**
 * Lazyify медиа: чтобы НИЧЕГО не грузилось, пока спойлер закрыт.
 */
function af_aqr_spoiler_lazyify_media(string $html): string
{
    $html = (string)$html;
    if ($html === '') return $html;

    $ph = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    $html = preg_replace_callback('~<img\b([^>]*?)\bsrc=(["\'])([^"\']+)\2([^>]*)>~is', function ($m) use ($ph) {
        $before = (string)$m[1];
        $src    = (string)$m[3];
        $after  = (string)$m[4];

        if (stripos($before.$after, 'data-src=') !== false) return $m[0];
        if (stripos($src, 'data:') === 0) return $m[0];

        return '<img' . $before
            . ' src="' . $ph . '" data-src="' . htmlspecialchars_uni($src) . '"'
            . $after . '>';
    }, $html);

    $html = preg_replace_callback('~<img\b([^>]*?)\bsrcset=(["\'])([^"\']+)\2([^>]*)>~is', function ($m) {
        $before = (string)$m[1];
        $srcset = (string)$m[3];
        $after  = (string)$m[4];

        if (stripos($before.$after, 'data-srcset=') !== false) return $m[0];

        return '<img' . $before
            . ' data-srcset="' . htmlspecialchars_uni($srcset) . '"'
            . $after . '>';
    }, $html);

    $html = preg_replace_callback('~<iframe\b([^>]*?)\bsrc=(["\'])([^"\']+)\2([^>]*)>~is', function ($m) {
        $before = (string)$m[1];
        $src    = (string)$m[3];
        $after  = (string)$m[4];

        if (stripos($before.$after, 'data-src=') !== false) return $m[0];

        return '<iframe' . $before
            . ' src="about:blank" data-src="' . htmlspecialchars_uni($src) . '"'
            . $after . '>';
    }, $html);

    $html = preg_replace_callback('~<video\b([^>]*?)\bsrc=(["\'])([^"\']+)\2([^>]*)>~is', function ($m) {
        $before = (string)$m[1];
        $src    = (string)$m[3];
        $after  = (string)$m[4];

        if (stripos($before.$after, 'data-src=') !== false) return $m[0];

        return '<video' . $before
            . ' data-src="' . htmlspecialchars_uni($src) . '"'
            . $after . '>';
    }, $html);

    $html = preg_replace_callback('~<source\b([^>]*?)\bsrc=(["\'])([^"\']+)\2([^>]*)>~is', function ($m) {
        $before = (string)$m[1];
        $src    = (string)$m[3];
        $after  = (string)$m[4];

        if (stripos($before.$after, 'data-src=') !== false) return $m[0];

        return '<source' . $before
            . ' data-src="' . htmlspecialchars_uni($src) . '"'
            . $after . '>';
    }, $html);

    return $html;
}

function af_aqr_spoiler_parse_message_end(&$message): void
{
    if (!is_string($message) || $message === '') return;

    $mark  = af_aqr_spoiler_mark_text();
    $split = af_aqr_spoiler_split_text();

    if (stripos($message, $mark) === false) return;

    $build = function (string $innerHtml) use ($mark, $split) {
        if (stripos($innerHtml, $mark) === false) return null;

        $innerHtml = str_ireplace($split, 'AF_AQR_SPOILER__CUT__B12C', $innerHtml);
        $innerHtml = str_ireplace($mark, '', $innerHtml);

        $innerHtml = preg_replace('~<span\b[^>]*transparent[^>]*>\s*</span>~i', '', (string)$innerHtml);
        $innerHtml = preg_replace('~<span\b[^>]*\bmycode_(?:color|size)\b[^>]*>\s*</span>~i', '', (string)$innerHtml);

        $pos = stripos($innerHtml, 'AF_AQR_SPOILER__CUT__B12C');
        if ($pos === false) return null;

        $titleHtml = trim(substr($innerHtml, 0, (int)$pos));
        $bodyHtml  = trim(substr($innerHtml, (int)$pos + strlen('AF_AQR_SPOILER__CUT__B12C')));

        if ($titleHtml === '') {
            $titleHtml = htmlspecialchars_uni(af_aqr_spoiler_default_title());
        }

        $bodyHtml = af_aqr_spoiler_lazyify_media($bodyHtml);

        return
            '<blockquote class="mycode_quote af-aqr-spoiler" data-open="0">'
              . '<div class="af-aqr-spoiler-head" role="button" tabindex="0" aria-expanded="false">'
                . '<span class="af-aqr-spoiler-icon" aria-hidden="true"></span>'
                . '<div class="af-aqr-spoiler-title">' . $titleHtml . '</div>'
              . '</div>'
              . '<div class="af-aqr-spoiler-body" hidden>' . $bodyHtml . '</div>'
              . '<div class="af-aqr-spoiler-foot" hidden>'
                . '<a href="#" class="af-aqr-spoiler-collapse">свернуть спойлер</a>'
              . '</div>'
            . '</blockquote>';
    };

    $patternNested =
        '~(?P<outer_open><blockquote\b[^>]*\bclass="[^"]*\bmycode_quote\b[^"]*"[^>]*>)\s*'
      . '(?P<cite><cite\b[^>]*>.*?</cite>)\s*'
      . '(?P<inner_open><blockquote\b[^>]*>)(?P<inner>.*?)(?P<inner_close></blockquote>)\s*'
      . '(?P<outer_close></blockquote>)~is';

    $message = preg_replace_callback($patternNested, function ($m) use ($build) {
        $inner = (string)($m['inner'] ?? '');
        $out = $build($inner);
        return $out !== null ? $out : $m[0];
    }, $message);

    $patternFlat =
        '~(?P<outer_open><blockquote\b[^>]*\bclass="[^"]*\bmycode_quote\b[^"]*"[^>]*>)\s*'
      . '(?P<cite><cite\b[^>]*>.*?</cite>)\s*'
      . '(?P<inner>.*?)(?P<outer_close></blockquote>)~is';

    $message = preg_replace_callback($patternFlat, function ($m) use ($build) {
        $inner = (string)($m['inner'] ?? '');
        $out = $build($inner);
        return $out !== null ? $out : $m[0];
    }, $message);
}

global $plugins;
if (isset($plugins) && is_object($plugins)) {
    $plugins->add_hook('parse_message_start', 'af_aqr_spoiler_parse_message_start');
    $plugins->add_hook('parse_message_end',   'af_aqr_spoiler_parse_message_end');
}
