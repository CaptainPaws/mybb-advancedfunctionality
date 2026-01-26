<?php
/**
 * AQR Builtin: tquote (server-side)
 *
 * BBCode:
 *   [tquote side=left accent=#aabbcc bg=#112233]...[/tquote]
 *
 * Идея:
 *  - parse_message_start: превращаем tquote в [quote] + невидимый маркер с опциями
 *  - parse_message_end: находим quote, где есть маркер, убираем <cite>, добавляем класс,
 *    data-side и css vars (--af-tq-accent/--af-tq-bg), вырезаем маркер из HTML.
 */

if (!defined('IN_MYBB')) { return; }

if (defined('AF_AQR_TQUOTE_LOADED')) { return; }
define('AF_AQR_TQUOTE_LOADED', 1);

function af_aqr_tquote_marker_prefix(): string
{
    return 'AF_AQR_TQUOTE__MARK__A9D1|';
}

function af_aqr_tquote_marker_bbcode(string $side, string $accent, string $bg): string
{
    $side   = $side !== '' ? $side : 'left';
    $accent = $accent !== '' ? $accent : '';
    $bg     = $bg !== '' ? $bg : '';

    $m = af_aqr_tquote_marker_prefix()
        . 'side=' . $side
        . '|accent=' . $accent
        . '|bg=' . $bg;

    // невидимый маркер, который потом удалим из HTML
    return "[color=transparent][size=1]{$m}[/size][/color]";
}

function af_aqr_tquote_norm_side(string $raw): string
{
    $x = strtolower(trim($raw));
    if ($x === 'right' || $x === 'r' || $x === '2') return 'right';
    return 'left';
}

function af_aqr_tquote_norm_hex(string $raw): string
{
    $x = trim($raw);
    if ($x === '') return '';
    if (preg_match('~^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$~', $x)) return strtolower($x);
    return '';
}

function af_aqr_tquote_parse_attrs(string $attrRaw): array
{
    $attrRaw = trim($attrRaw);

    $side = 'left';
    $accent = '';
    $bg = '';

    if ($attrRaw === '') {
        return [$side, $accent, $bg];
    }

    // парсим key=value (value может быть в кавычках)
    if (preg_match_all('~(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))~', $attrRaw, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $k = strtolower((string)$row[1]);
            $v = '';
            if (isset($row[3]) && $row[3] !== '') $v = (string)$row[3];
            elseif (isset($row[4]) && $row[4] !== '') $v = (string)$row[4];
            else $v = (string)($row[5] ?? '');

            if ($k === 'side' || $k === 'dir' || $k === 'align') {
                $side = af_aqr_tquote_norm_side($v);
            } elseif ($k === 'accent' || $k === 'a' || $k === 'color') {
                $accent = af_aqr_tquote_norm_hex($v);
            } elseif ($k === 'bg' || $k === 'background') {
                $bg = af_aqr_tquote_norm_hex($v);
            }
        }
    } else {
        // короткая форма: [tquote=right] или [tquote right]
        if (preg_match('~^(?:=)?\s*(left|right|l|r|1|2)\s*$~i', $attrRaw, $mm)) {
            $side = af_aqr_tquote_norm_side((string)$mm[1]);
        }
    }

    return [$side, $accent, $bg];
}

function af_aqr_tquote_parse_message_start(&$message): void
{
    if (!is_string($message) || $message === '' || stripos($message, '[tquote') === false) {
        return;
    }

    $maxLoops = 80;

    for ($i = 0; $i < $maxLoops; $i++) {
        if (!preg_match('~\[tquote(?:=([^\]]+)|\s+([^\]]+))?\](.*?)\[/tquote\]~is', $message)) {
            break;
        }

        $message = preg_replace_callback(
            '~\[tquote(?:=([^\]]+)|\s+([^\]]+))?\](.*?)\[/tquote\]~is',
            function ($m) {
                $attr = '';
                if (isset($m[1]) && $m[1] !== '') $attr = (string)$m[1];
                elseif (isset($m[2]) && $m[2] !== '') $attr = (string)$m[2];

                $inner = (string)($m[3] ?? '');

                [$side, $accent, $bg] = af_aqr_tquote_parse_attrs($attr);

                // делаем это quote-блоком, но потом на HTML-стадии “перекрасим” цитату в tquote
                $bb = "[quote]" . af_aqr_tquote_marker_bbcode($side, $accent, $bg) . $inner . "[/quote]";
                return $bb;
            },
            $message
        );
    }
}

function af_aqr_tquote_parse_message_end(&$message): void
{
    if (!is_string($message) || $message === '') return;

    $prefix = af_aqr_tquote_marker_prefix();
    if (stripos($message, $prefix) === false) return;

    $prefixRe = preg_quote($prefix, '~');

    // 1) Убрать <cite>...</cite> только у quote, где маркер встречается очень рано
    $message = preg_replace(
        '~(<blockquote\b[^>]*\bmycode_quote\b[^>]*>)\s*<cite\b[^>]*>.*?</cite>\s*(?=(?:(?!</blockquote>).){0,1200}' . $prefixRe . ')~is',
        '$1',
        $message
    );

    // 2) Добавить класс + data-side + css vars
    $message = preg_replace_callback(
        '~<blockquote\b(?P<attrs>[^>]*)\bclass=(?P<q>["\'])(?P<class>[^"\']*\bmycode_quote\b[^"\']*)(?P=q)(?P<tail>[^>]*)>(?P<body>.*?</blockquote>)~is',
        function ($m) use ($prefix) {
            $whole = '<blockquote' . ($m['attrs'] ?? '') . 'class=' . ($m['q'] ?? '"') . ($m['class'] ?? '') . ($m['q'] ?? '"') . ($m['tail'] ?? '') . '>' . ($m['body'] ?? '');
            if (stripos($whole, $prefix) === false) return $whole;

            // вытащим opts из маркера (до первого '<')
            $side = 'left';
            $accent = '';
            $bg = '';

            if (preg_match('~' . preg_quote($prefix, '~') . '(?P<opts>[^<]*)~i', $whole, $mm)) {
                $opts = (string)($mm['opts'] ?? '');
                foreach (explode('|', $opts) as $pair) {
                    $pair = trim($pair);
                    if ($pair === '') continue;
                    $kv = explode('=', $pair, 2);
                    if (count($kv) !== 2) continue;
                    $k = strtolower(trim($kv[0]));
                    $v = trim($kv[1]);

                    if ($k === 'side') $side = af_aqr_tquote_norm_side($v);
                    if ($k === 'accent') $accent = af_aqr_tquote_norm_hex($v);
                    if ($k === 'bg') $bg = af_aqr_tquote_norm_hex($v);
                }
            }

            // класс
            $cls = (string)($m['class'] ?? '');
            if (stripos($cls, 'af-aqr-tquote') === false) {
                $cls = trim($cls . ' af-aqr-tquote');
            }

            // attrs: data-side + style vars
            $attrs = (string)($m['attrs'] ?? '');
            $tail  = (string)($m['tail'] ?? '');
            $q     = (string)($m['q'] ?? '"');

            // data-side
            if (stripos($attrs . $tail, 'data-side=') === false) {
                $tail .= ' data-side=' . $q . htmlspecialchars($side, ENT_QUOTES) . $q;
            }

            // style vars
            $styleVars = '';
            if ($accent !== '') $styleVars .= '--af-tq-accent:' . $accent . ';';
            if ($bg !== '') $styleVars .= '--af-tq-bg:' . $bg . ';';

            if ($styleVars !== '') {
                if (preg_match('~\bstyle=(["\'])(.*?)\1~is', $attrs . $tail, $sm)) {
                    $existing = (string)($sm[2] ?? '');
                    $new = rtrim($existing);
                    if ($new !== '' && substr($new, -1) !== ';') $new .= ';';
                    $new .= $styleVars;

                    // заменим в attrs+tail
                    $combined = $attrs . $tail;
                    $combined = preg_replace('~\bstyle=(["\'])(.*?)\1~is', 'style=' . $q . htmlspecialchars($new, ENT_QUOTES) . $q, $combined, 1);

                    // обратно разложить тупо: всё в tail, attrs оставим как было (чтобы не ломать)
                    $attrs = '';
                    $tail = ' ' . trim($combined);
                } else {
                    $tail .= ' style=' . $q . htmlspecialchars($styleVars, ENT_QUOTES) . $q;
                }
            }

            return '<blockquote' . $attrs . 'class=' . $q . $cls . $q . $tail . '>' . ($m['body'] ?? '');
        },
        $message
    );

    // 3) Выпилить сам маркер (и обертки mycode_color/mycode_size)
    $message = preg_replace(
        '~<span\b[^>]*\bmycode_color\b[^>]*>\s*<span\b[^>]*\bmycode_size\b[^>]*>\s*' . $prefixRe . '[^<]*\s*</span>\s*</span>~is',
        '',
        $message
    );
    $message = preg_replace(
        '~<span\b[^>]*\bmycode_size\b[^>]*>\s*' . $prefixRe . '[^<]*\s*</span>~is',
        '',
        $message
    );
    $message = preg_replace('~' . $prefixRe . '[^<]*~i', '', $message);

    // подчистка пустых span после удаления маркера
    $message = preg_replace('~<span\b[^>]*\bmycode_(?:color|size)\b[^>]*>\s*</span>~i', '', $message);
}

global $plugins;
if (isset($plugins) && is_object($plugins)) {
    $plugins->add_hook('parse_message_start', 'af_aqr_tquote_parse_message_start');
    $plugins->add_hook('parse_message_end', 'af_aqr_tquote_parse_message_end');
}

// AE dispatcher bridges (если у тебя есть единый диспатчер паков)
function af_ae_bbcode_tquote_parse_start(&$message): void
{
    af_aqr_tquote_parse_message_start($message);
}
function af_ae_bbcode_tquote_parse_end(&$message): void
{
    af_aqr_tquote_parse_message_end($message);
}
