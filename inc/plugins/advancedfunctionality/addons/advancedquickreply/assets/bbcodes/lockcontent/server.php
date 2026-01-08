<?php
/**
 * AQR Builtin: lockcontent (server-side)
 * Парсим [hide] на parse_message_start, чтобы скрытое не утекало в HTML.
 *
 * Поддержка:
 *  - [hide] (по умолчанию = скрыть по постам)
 *  - [hide=posts=10] и [hide=10]
 *  - [hide=users=1,2,3]
 *
 * Правило: группы 3,4,6 всегда видят скрытое.
 * Для гостей ЛЮБОЙ hide скрыт и показывает "Авторизуйтесь, чтобы видеть скрытое содержимое".
 *
 * Дополнительно:
 *  - hide-блоки выделяем как цитату, НО убираем из неё <cite>Цитата:</cite>
 *    (делаем это на parse_message_end по маркеру).
 */

if (!defined('IN_MYBB')) { return; }

// защита от двойного подключения
if (defined('AF_AQR_LOCKCONTENT_LOADED')) { return; }
define('AF_AQR_LOCKCONTENT_LOADED', 1);

function af_aqr_lockcontent_privileged_groups(): array
{
    // Админы/модеры
    return [3, 4, 6];
}

function af_aqr_lockcontent_user_is_privileged(): bool
{
    global $mybb;

    $priv = af_aqr_lockcontent_privileged_groups();

    $ug = (int)($mybb->user['usergroup'] ?? 0);
    if ($ug && in_array($ug, $priv, true)) {
        return true;
    }

    $add = (string)($mybb->user['additionalgroups'] ?? '');
    if ($add !== '') {
        foreach (preg_split('~\s*,\s*~', $add, -1, PREG_SPLIT_NO_EMPTY) as $gid) {
            $gid = (int)$gid;
            if ($gid && in_array($gid, $priv, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Маркер, чтобы на parse_message_end понять:
 * "эта цитата создана нашим hide".
 */
function af_aqr_lockcontent_marker_text(): string
{
    return 'AF_AQR_LOCKCONTENT__MARK__93F6';
}

function af_aqr_lockcontent_marker_bbcode(): string
{
    // Невидимый маркер, который потом удалим из HTML
    $m = af_aqr_lockcontent_marker_text();
    return "[color=transparent][size=1]{$m}[/size][/color]";
}

function af_aqr_lockcontent_wrap_quote(string $text): string
{
    // Выделяем скрытые блоки как цитату (и плейсхолдеры тоже)
    // + добавляем маркер, чтобы потом вырезать <cite>Цитата:</cite>
    return "[quote]" . af_aqr_lockcontent_marker_bbcode() . $text . "[/quote]";
}

function af_aqr_lockcontent_placeholder_guests(): string
{
    return af_aqr_lockcontent_wrap_quote("Авторизуйтесь, чтобы видеть скрытое содержимое");
}

function af_aqr_lockcontent_placeholder_posts(int $need): string
{
    $need = max(0, (int)$need);
    return af_aqr_lockcontent_wrap_quote("Вам нужно набрать [b]{$need}[/b] сообщений для просмотра содержимого.");
}

function af_aqr_lockcontent_placeholder_users(): string
{
    return af_aqr_lockcontent_wrap_quote("Вам недоступен этот контент.");
}

function af_aqr_lockcontent_parse_uid_list(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];

    $out = [];
    foreach (preg_split('~\s*,\s*~', $raw, -1, PREG_SPLIT_NO_EMPTY) as $x) {
        $v = (int)$x;
        if ($v > 0) $out[$v] = true;
    }
    return array_keys($out); // unique
}

function af_aqr_lockcontent_default_posts_need(): int
{
    // дефолтный порог для голого [hide]
    return 10;
}

function af_aqr_lockcontent_parse_message_start(&$message): void
{
    global $mybb;

    if (!is_string($message) || $message === '' || stripos($message, '[hide') === false) {
        return;
    }

    $uid      = (int)($mybb->user['uid'] ?? 0);
    $postnum  = (int)($mybb->user['postnum'] ?? 0);
    $isLogged = $uid > 0;

    // группы 3/4/6 видят всегда
    $isPrivileged = $isLogged && af_aqr_lockcontent_user_is_privileged();

    // прожевать вложенность
    $maxLoops = 80;

    for ($i = 0; $i < $maxLoops; $i++) {
        if (!preg_match('~\[hide(?:=([^\]]+))?\](.*?)\[/hide\]~is', $message)) {
            break;
        }

        $message = preg_replace_callback(
            '~\[hide(?:=([^\]]+))?\](.*?)\[/hide\]~is',
            function ($m) use ($isLogged, $uid, $postnum, $isPrivileged) {
                $opt   = trim((string)($m[1] ?? ''));
                $inner = (string)($m[2] ?? '');

                // гости: любой hide скрыт
                if (!$isLogged) {
                    return af_aqr_lockcontent_placeholder_guests();
                }

                // привилегированные группы всегда видят (но всё равно в "цитате без cite")
                if ($isPrivileged) {
                    return af_aqr_lockcontent_wrap_quote($inner);
                }

                // Старое [hide=guests] / [hide=guest] — теперь трактуем как дефолт по постам
                if ($opt !== '' && (strcasecmp($opt, 'guests') === 0 || strcasecmp($opt, 'guest') === 0)) {
                    $need = af_aqr_lockcontent_default_posts_need();
                    if ($postnum >= $need) return af_aqr_lockcontent_wrap_quote($inner);
                    return af_aqr_lockcontent_placeholder_posts($need);
                }

                // [hide] — дефолт: по количеству сообщений
                if ($opt === '') {
                    $need = af_aqr_lockcontent_default_posts_need();
                    if ($postnum >= $need) return af_aqr_lockcontent_wrap_quote($inner);
                    return af_aqr_lockcontent_placeholder_posts($need);
                }

                // [hide=posts=10]
                if (preg_match('~^posts\s*=\s*(\d+)\s*$~i', $opt, $mm)) {
                    $need = (int)$mm[1];
                    if ($postnum >= $need) return af_aqr_lockcontent_wrap_quote($inner);
                    return af_aqr_lockcontent_placeholder_posts($need);
                }

                // [hide=10] как короткая форма порога
                if (ctype_digit($opt)) {
                    $need = (int)$opt;
                    if ($postnum >= $need) return af_aqr_lockcontent_wrap_quote($inner);
                    return af_aqr_lockcontent_placeholder_posts($need);
                }

                // [hide=users=1,2,3]
                if (preg_match('~^users\s*=\s*(.+)$~i', $opt, $mm)) {
                    $list = af_aqr_lockcontent_parse_uid_list((string)$mm[1]);
                    if (!empty($list) && in_array($uid, $list, true)) {
                        return af_aqr_lockcontent_wrap_quote($inner);
                    }
                    return af_aqr_lockcontent_placeholder_users();
                }

                // неизвестное — трактуем как дефолт "по постам"
                $need = af_aqr_lockcontent_default_posts_need();
                if ($postnum >= $need) return af_aqr_lockcontent_wrap_quote($inner);
                return af_aqr_lockcontent_placeholder_posts($need);
            },
            $message
        );
    }
}

/**
 * После парсинга BBCode в HTML:
 * - у hide-цитаты (по маркеру) вырезаем <cite>Цитата:</cite>
 * - удаляем сам маркер из тела цитаты
 */
function af_aqr_lockcontent_parse_message_end(&$message): void
{
    if (!is_string($message) || $message === '') {
        return;
    }

    $mark = af_aqr_lockcontent_marker_text();

    // быстрый skip
    if (stripos($message, $mark) === false) {
        return;
    }

    $markRe = preg_quote($mark, '~');

    /**
     * 1) Убрать <cite>...</cite> ТОЛЬКО у тех blockquote.mycode_quote,
     *    где маркер встречается очень рано (в пределах первых 800 символов до </blockquote>).
     *    Это важно: обычные цитаты не трогаем.
     */
    $message = preg_replace(
        '~(<blockquote\b[^>]*\bmycode_quote\b[^>]*>)\s*<cite\b[^>]*>.*?</cite>\s*(?=(?:(?!</blockquote>).){0,800}' . $markRe . ')~is',
        '$1',
        $message
    );

    /**
     * 2) Добавить класс af-aqr-lc-quote только “нашим” цитатам (по тому же принципу).
     *    ВАЖНО: не трогаем остальные mycode_quote.
     */
    $message = preg_replace_callback(
        '~<blockquote\b(?P<attrs>[^>]*)\bclass=(?P<q>["\'])(?P<class>[^"\']*\bmycode_quote\b[^"\']*)(?P=q)(?P<tail>[^>]*)>(?=(?:(?!</blockquote>).){0,800}' . $markRe . ')~is',
        function ($m) {
            $cls = (string)($m['class'] ?? '');
            if (stripos($cls, 'af-aqr-lc-quote') === false) {
                $cls = trim($cls . ' af-aqr-lc-quote');
            }
            return '<blockquote' . ($m['attrs'] ?? '') . 'class=' . ($m['q'] ?? '"') . $cls . ($m['q'] ?? '"') . ($m['tail'] ?? '') . '>';
        },
        $message
    );

    /**
     * 3) Удалить сам маркер из HTML.
     *    В идеале выпиливаем его вместе с “оберткой” mycode_color/mycode_size,
     *    чтобы не оставалось пустых прозрачных span.
     */
    $message = preg_replace(
        '~<span\b[^>]*\bmycode_color\b[^>]*>\s*<span\b[^>]*\bmycode_size\b[^>]*>\s*' . $markRe . '\s*</span>\s*</span>~is',
        '',
        $message
    );

    // на случай если структура отличалась — убрать хотя бы внутренний span
    $message = preg_replace(
        '~<span\b[^>]*\bmycode_size\b[^>]*>\s*' . $markRe . '\s*</span>~is',
        '',
        $message
    );

    // и на всякий — сам текст маркера
    $message = str_ireplace($mark, '', $message);

    /**
     * 4) подчистка: пустые mycode_color/mycode_size после удаления маркера
     */
    $message = preg_replace('~<span\b[^>]*\bmycode_(?:color|size)\b[^>]*>\s*</span>~i', '', $message);

    // иногда остаются "прозрачные пустышки" со style="color: transparent;"
    $message = preg_replace('~<span\b[^>]*transparent[^>]*>\s*</span>~i', '', $message);
}


// регистрируем хуки
global $plugins;
if (isset($plugins) && is_object($plugins)) {
    $plugins->add_hook('parse_message_start', 'af_aqr_lockcontent_parse_message_start');
    $plugins->add_hook('parse_message_end', 'af_aqr_lockcontent_parse_message_end');
}
