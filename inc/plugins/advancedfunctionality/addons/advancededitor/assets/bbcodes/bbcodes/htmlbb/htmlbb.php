<?php
/**
 * AE BBCode Pack: HTMLBB
 * Tag: [html]...[/html]
 *
 * IMPORTANT:
 * We do NOT output raw HTML directly; we output placeholder <div data-af-html-b64="...">
 * and let htmlbb.js render it on frontend.
 *
 * Security:
 * - For non-admins we sanitize: strip scripts, event handlers, javascript: URLs, etc.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

function af_ae_bbcode_htmlbb_manifest(): array
{
    static $m = null;
    if (is_array($m)) return $m;

    $path = __DIR__ . '/manifest.php';
    $m = is_file($path) ? (array) require $path : [];
    return $m;
}

function af_ae_bbcode_htmlbb_payload(): array
{
    $m = af_ae_bbcode_htmlbb_manifest();

    $cmd = 'af_htmlbb';
    if (!empty($m['buttons'][0]['cmd'])) {
        $cmd = (string)$m['buttons'][0]['cmd'];
    }

    // new: permission flag for editor UI
    $canUse = af_ae_htmlbb_user_can_use();

    return [
        'id'        => (string)($m['id'] ?? 'htmlbb'),
        'command'   => $cmd,
        'title'     => (string)($m['title'] ?? 'HTML-блок'),
        'can_use'   => $canUse ? 1 : 0,
        'groups'    => (string)af_ae_htmlbb_allowed_groups_raw(), // для UI/дебага
    ];
}
function af_ae_htmlbb_allowed_groups_raw(): string
{
    global $mybb;

    if (!isset($mybb) || !is_object($mybb)) return '';

    // поддержка двух вариантов имён (на случай старых/переездов)
    $raw = (string)($mybb->settings['af_ae_htmlbb_allowed_groups'] ?? '');
    if ($raw === '') {
        $raw = (string)($mybb->settings['af_advancededitor_htmlbb_allowed_groups'] ?? '');
    }

    return trim($raw);
}

function af_ae_htmlbb_allowed_groups_ids(): array
{
    $raw = af_ae_htmlbb_allowed_groups_raw();
    if ($raw === '') return [];

    $out = [];
    foreach (preg_split('~[,\s]+~', $raw) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $id = (int)$p;
        if ($id > 0) $out[$id] = $id;
    }
    return array_values($out);
}

function af_ae_htmlbb_user_can_use(): bool
{
    global $mybb, $usergroup;

    // если настройка пустая — не ограничиваем (разрешено всем, кто может постить)
    $allowed = af_ae_htmlbb_allowed_groups_ids();
    if (empty($allowed)) return true;

    // гость — не “использует” (вставлять/постить), но смотреть сможет
    if (!isset($mybb->user) || (int)($mybb->user['uid'] ?? 0) <= 0) return false;

    $ug = (int)($mybb->user['usergroup'] ?? 0);
    if ($ug && in_array($ug, $allowed, true)) return true;

    $add = (string)($mybb->user['additionalgroups'] ?? '');
    if ($add !== '') {
        foreach (explode(',', $add) as $g) {
            $g = (int)trim($g);
            if ($g && in_array($g, $allowed, true)) return true;
        }
    }

    // админа не режем никогда (на всякий)
    if (is_array($usergroup) && !empty($usergroup['cancp'])) return true;
    if (isset($mybb->usergroup) && is_array($mybb->usergroup) && !empty($mybb->usergroup['cancp'])) return true;

    return false;
}

function af_ae_htmlbb_unwrap_url_mycode_in_attributes(string $html): string
{
    if ($html === '' || stripos($html, '[url') === false) {
        return $html;
    }

    // Чиним ТОЛЬКО значения src/href, чтобы не трогать обычные [url] в тексте.
    return preg_replace_callback(
        '~\b(src|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i',
        function ($m) {
            $attr = strtolower($m[1]);

            $val = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
            $val = (string)$val;

            // Декодим на случай &quot; и т.п.
            $decoded = html_entity_decode($val, ENT_QUOTES, 'UTF-8');

            // 1) [url=... ]текст[/url] -> берём URL из параметра
            $decoded = preg_replace_callback(
                '~\[\s*url\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\]]+))\s*\](.*?)\[\s*/\s*url\s*\]~is',
                function ($mm) {
                    $u = $mm[1] !== '' ? $mm[1] : ($mm[2] !== '' ? $mm[2] : ($mm[3] ?? ''));
                    return (string)$u;
                },
                $decoded
            );

            // 2) [url]URL[/url] -> URL
            $decoded = preg_replace('~\[\s*url\s*\]~i', '', $decoded);
            $decoded = preg_replace('~\[\s*/\s*url\s*\]~i', '', $decoded);

            // 3) На всякий: одинокие хвосты
            $decoded = str_ireplace('[/url]', '', $decoded);

            $decoded = trim($decoded);

            return $attr . '="' . htmlspecialchars_uni($decoded) . '"';
        },
        $html
    );
}

/**
 * AE dispatch will call: af_ae_bbcode_htmlbb_parse_end(&$message)
 */
function af_ae_bbcode_htmlbb_parse_end(&$message): void
{
    if (!is_string($message) || $message === '') return;

    if (stripos($message, '[html]') === false) return;

    // Protect <pre> and <code> blocks from replacements
    $store = [];
    $i = 0;

    $message = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', function($m) use (&$store, &$i) {
        $key = '%%AF_HTMLBB_PROTECT_' . (++$i) . '%%';
        $store[$key] = $m[0];
        return $key;
    }, $message);

    $isAdmin = af_ae_htmlbb_is_admin();

    $message = preg_replace_callback('~\[html\](.*?)\[/html\]~is', function($m) use ($isAdmin) {
        $raw = (string)$m[1];
        $raw = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        $raw = trim($raw);

        if ($raw === '') return '';

        // КЛЮЧЕВОЕ: разворачиваем/убираем [url]...[/url] внутри src/href,
        // чтобы <img src="..."> не превращался в "https://...png">[/url]
        $raw = af_ae_htmlbb_unwrap_url_mycode_in_attributes($raw);

        $html = $isAdmin ? $raw : af_ae_htmlbb_sanitize($raw);

        // base64 payload
        $b64 = base64_encode($html);

        // fallback (если JS не грузится)
        $fallback = '<div class="af-htmlbb-fallback">[HTML]</div>';

        return '<div class="af-htmlbb" data-af-html-b64="' . htmlspecialchars_uni($b64) . '">' . $fallback . '</div>';
    }, $message);

    if (!empty($store)) {
        $message = strtr($message, $store);
    }
}

function af_ae_htmlbb_is_admin(): bool
{
    // На парсинге обычно доступны $mybb / usergroup; делаем максимально мягко.
    global $mybb, $usergroup;

    // 1) usergroup array, если есть
    if (is_array($usergroup)) {
        if (!empty($usergroup['cancp'])) return true;
        if (!empty($usergroup['issupermod'])) return true;
    }

    // 2) через $mybb->usergroup
    if (isset($mybb) && is_object($mybb)) {
        if (!empty($mybb->usergroup) && is_array($mybb->usergroup)) {
            if (!empty($mybb->usergroup['cancp'])) return true;
            if (!empty($mybb->usergroup['issupermod'])) return true;
        }
    }

    return false;
}

function af_ae_htmlbb_sanitize(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';

    // 0) Разрешаем только <script src> с домена форума, остальное вырезаем
    // (включая inline script)
    $html = af_ae_htmlbb_sanitize_script_tags($html);

    // 1) Убить on*="..." / on*='...' / on*=...
    $html = preg_replace('~\son\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html);

    // 2) Убить javascript: / data: в href/src
    // (ЭТО НЕ БЕЛЫЙ СПИСОК: картинки/ссылки с любых доменов остаются, просто режем опасные схемы)
    $html = preg_replace_callback('~\b(href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', function($m) {
        $attr = strtolower($m[1]);
        $val  = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
        $val  = html_entity_decode((string)$val, ENT_QUOTES, 'UTF-8');
        $valT = ltrim($val);

        if (preg_match('~^(javascript:|data:)~i', $valT)) {
            return $attr . '=""';
        }
        return $attr . '="' . htmlspecialchars_uni($val) . '"';
    }, $html);

    // 3) Убить <iframe srcdoc=...> (часто используют для инъекций)
    $html = preg_replace('~\ssrcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', ' srcdoc=""', $html);

    // 4) Убрать опасные теги-носители
    $html = preg_replace('~</?(object|embed|applet|link|meta|base)\b[^>]*>~is', '', $html);

    // 5) Стиль: @import — только Google Fonts; режем expression() и javascript:
    $html = preg_replace_callback('~<style\b[^>]*>(.*?)</style>~is', function($m) {
        $css = (string)$m[1];

        // только @import шрифтов гугла сохраняем, остальное вырезаем
        $css = af_ae_htmlbb_strip_css_imports($css);

        $css = preg_replace('~expression\s*\(~i', '/*expr(*/', $css);
        $css = preg_replace('~javascript\s*:~i', '/*js:*/', $css);

        return '<style>' . $css . '</style>';
    }, $html);

    return $html;
}

function af_ae_htmlbb_strip_css_imports(string $css): string
{
    $len = strlen($css);
    if ($len === 0) return '';

    $out = '';
    $i = 0;

    $inStr = false;
    $strQ  = '';

    while ($i < $len) {
        $ch = $css[$i];

        // CSS comments /* ... */
        if (!$inStr && $ch === '/' && ($i + 1 < $len) && $css[$i + 1] === '*') {
            $end = strpos($css, '*/', $i + 2);
            if ($end === false) break;
            $out .= substr($css, $i, ($end + 2) - $i);
            $i = $end + 2;
            continue;
        }

        // строки "..." или '...'
        if ($inStr) {
            $out .= $ch;

            if ($ch === '\\' && ($i + 1 < $len)) {
                $out .= $css[$i + 1];
                $i += 2;
                continue;
            }

            if ($ch === $strQ) {
                $inStr = false;
                $strQ  = '';
            }

            $i++;
            continue;
        } else {
            if ($ch === '"' || $ch === "'") {
                $inStr = true;
                $strQ  = $ch;
                $out .= $ch;
                $i++;
                continue;
            }
        }

        // Ищем @import вне строк
        if ($ch === '@') {
            // допускаем пробелы/мусор, но ищем именно "@import" впритык
            if (strtolower(substr($css, $i, 7)) === '@import') {

                // найдём конец правила @import ... ;
                $j = $i + 7;

                // пропустим пробелы
                while ($j < $len && preg_match('~\s~', $css[$j])) $j++;

                // иногда встречается "@import @import url(...)" — нормализуем
                if (strtolower(substr($css, $j, 7)) === '@import') {
                    $j += 7;
                    while ($j < $len && preg_match('~\s~', $css[$j])) $j++;
                }

                $localInStr = false;
                $localQ = '';
                $localParen = 0;

                while ($j < $len) {
                    $c = $css[$j];

                    if ($localInStr) {
                        if ($c === '\\' && ($j + 1 < $len)) { $j += 2; continue; }
                        if ($c === $localQ) { $localInStr = false; $localQ = ''; }
                        $j++;
                        continue;
                    } else {
                        if ($c === '"' || $c === "'") { $localInStr = true; $localQ = $c; $j++; continue; }
                    }

                    if ($c === '(') $localParen++;
                    if ($c === ')' && $localParen > 0) $localParen--;

                    // конец правила - первая ; вне строки
                    if ($c === ';') { $j++; break; }

                    $j++;
                }

                // ruleText включает '@import ... ;' (или без ';' если файл кривой)
                $ruleText = substr($css, $i, max(0, $j - $i));
                $ruleBody = $ruleText;

                // Вытаскиваем URL из @import (поддержка: url("..."), url('...'), url(...), "...", '...')
                $url = '';

                // 1) url(...)
                if (preg_match('~@import\s+url\(\s*(?:"([^"]+)"|\'([^\']+)\'|([^)\s]+))\s*\)~i', $ruleBody, $m)) {
                    $url = (string)($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? '')));
                }
                // 2) "@import '...'" / "@import "..."" (без url())
                else if (preg_match('~@import\s+(?:"([^"]+)"|\'([^\']+)\')~i', $ruleBody, $m)) {
                    $url = (string)($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
                }

                $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');

                // Решение: оставить только разрешённые импорты (Google Fonts), остальные вырезать
                if ($url !== '' && af_ae_htmlbb_is_allowed_css_import_url($url)) {
                    $out .= $ruleText; // сохраняем импорт как есть
                } else {
                    // вырезаем импорт полностью (ничего не добавляем)
                }

                $i = $j;
                continue;
            }
        }

        // обычный символ
        $out .= $ch;
        $i++;
    }

    return $out;
}

function af_ae_htmlbb_is_allowed_css_import_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;

    // запретим опасные схемы сразу
    $low = strtolower($url);
    if (preg_match('~^(javascript:|data:)~i', $low)) return false;

    // поддержим protocol-relative //example.com/...
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    // разрешаем только абсолютные http(s)
    $parts = @parse_url($url);
    if (!is_array($parts)) return false;

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') return false;

    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '') return false;

    // ВАЙТЛИСТ:
    // 1) Google Fonts (fonts.googleapis.com / fonts.gstatic.com)
    // 2) Наш домен (warprift.ru / www.warprift.ru)
    if ($host === 'fonts.googleapis.com') return true;
    if ($host === 'fonts.gstatic.com') return true;

    if ($host === 'warprift.ru') return true;
    if ($host === 'www.warprift.ru') return true;

    return false;
}

function af_ae_htmlbb_is_allowed_script_src(string $src): bool
{
    $src = trim($src);
    if ($src === '') return false;

    // Запрещаем опасные схемы
    if (preg_match('~^(javascript:|data:)~i', $src)) return false;

    // Никаких protocol-relative и относительных путей (ты просила строго домен+https)
    if (strpos($src, '//') === 0) return false;
    if ($src[0] === '/') return false;

    $parts = @parse_url($src);
    if (!is_array($parts)) return false;

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'https') return false;

    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host !== 'warprift.ru' && $host !== 'www.warprift.ru') return false;

    // Хоть какой-то путь должен быть
    $path = (string)($parts['path'] ?? '');
    if ($path === '' || $path === '/') return false;

    return true;
}

function af_ae_htmlbb_sanitize_script_tags(string $html): string
{
    // Разрешаем только: <script type="text/javascript" src="https://warprift.ru/..."></script>
    // (и https://www.warprift.ru/...)
    return preg_replace_callback('~<script\b([^>]*)>(.*?)</script>~is', function($m) {
        $attrs = (string)$m[1];
        $body  = (string)$m[2];

        // inline-скрипты запрещаем всегда
        if (trim($body) !== '') return '';

        // type должен быть text/javascript (строго)
        $type = '';
        if (preg_match('~\btype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $attrs, $tm)) {
            $type = (string)($tm[1] !== '' ? $tm[1] : ($tm[2] !== '' ? $tm[2] : ($tm[3] ?? '')));
            $type = trim(html_entity_decode($type, ENT_QUOTES, 'UTF-8'));
        }
        if (strtolower($type) !== 'text/javascript') {
            return '';
        }

        // вытащим src
        $src = '';
        if (preg_match('~\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $attrs, $sm)) {
            $src = (string)($sm[1] !== '' ? $sm[1] : ($sm[2] !== '' ? $sm[2] : ($sm[3] ?? '')));
            $src = trim(html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
        }

        if ($src === '' || !af_ae_htmlbb_is_allowed_script_src($src)) {
            return '';
        }

        // Собираем безопасный тег
        $safe = '<script type="text/javascript" src="' . htmlspecialchars_uni($src) . '"';

        if (preg_match('~\bdefer\b~i', $attrs)) $safe .= ' defer';
        if (preg_match('~\basync\b~i', $attrs)) $safe .= ' async';

        $safe .= '></script>';
        return $safe;
    }, $html);
}

function af_ae_bbcode_htmlbb_pre_output(&$page): void
{
    if (!is_string($page) || $page === '') return;

    // если на странице нет HTMLBB блоков — ничего не делаем
    if (stripos($page, 'data-af-html-b64=') === false && stripos($page, 'class="af-htmlbb"') === false) {
        return;
    }

    // не плодим дубли
    if (strpos($page, 'id="af-htmlbb-css"') !== false || strpos($page, 'id="af-htmlbb-js"') !== false) {
        return;
    }

    $cssUrl = af_ae_htmlbb_asset_url('htmlbb.css');
    $jsUrl  = af_ae_htmlbb_asset_url('htmlbb.js');

    $inject = "\n";
    if ($cssUrl !== '') $inject .= '<link id="af-htmlbb-css" rel="stylesheet" href="'.htmlspecialchars_uni($cssUrl).'">'."\n";
    if ($jsUrl !== '')  $inject .= '<script id="af-htmlbb-js" src="'.htmlspecialchars_uni($jsUrl).'"></script>'."\n";

    if ($inject === "\n") return;

    // вставляем перед </head>
    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $inject.'</head>', $page, 1);
        return;
    }

    // fallback — в начало
    $page = $inject . $page;
}

function af_ae_htmlbb_asset_url(string $file): string
{
    global $mybb;

    $file = ltrim($file, '/');
    if ($file === '') return '';

    $bburl = '';
    if (isset($mybb) && is_object($mybb) && isset($mybb->settings['bburl'])) {
        $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    }
    if ($bburl === '') return '';

    // 1) пробуем взять базу из manifest.php, если у тебя там есть что-то вроде assets_base/assets_url
    $m = af_ae_bbcode_htmlbb_manifest();
    foreach (['assets_base', 'assets_url', 'base_url', 'url'] as $k) {
        if (!empty($m[$k]) && is_string($m[$k])) {
            $base = rtrim((string)$m[$k], '/');
            if ($base !== '') return $base . '/' . $file;
        }
    }

    // 2) дефолтный путь (популярный в AE pack’ах)
    //    ПОДСТАВЬ тут реальный путь, если у тебя htmlbb.js/css лежат в другом месте.
    return $bburl . '/inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/htmlbb/' . $file;
}