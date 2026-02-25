<?php

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

/**
 * AF Addon: headerwelcomeavatar
 * PHP-only точный аватар в welcome-блоке + перестройка разметки
 */
function af_headerwelcomeavatar_install(): void
{
    global $db;

    // settings group
    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='af_headerwelcomeavatar'", ['limit' => 1]),
        'gid'
    );

    if (!$gid) {
        $disp = (int)$db->fetch_field(
            $db->simple_select('settinggroups', 'MAX(disporder) AS m', '1=1'),
            'm'
        );
        $disp = $disp ? ($disp + 1) : 1;

        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => 'af_headerwelcomeavatar',
            'title'       => '{$lang->af_headerwelcomeavatar_group}',
            'description' => '{$lang->af_headerwelcomeavatar_group_desc}',
            'disporder'   => $disp,
            'isdefault'   => 0,
        ]);
    }

    // helper to insert setting if missing
    $ensure = function (string $name, array $row) use ($db, $gid): void {
        $sid = (int)$db->fetch_field(
            $db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]),
            'sid'
        );
        if ($sid) return;

        $row['name'] = $name;
        $row['gid']  = $gid;

        $max = (int)$db->fetch_field(
            $db->simple_select('settings', 'MAX(disporder) AS m', "gid='{$gid}'"),
            'm'
        );
        $row['disporder'] = $max ? ($max + 1) : 1;

        $db->insert_query('settings', $row);
    };

    $ensure('af_headerwelcomeavatar_enabled', [
        'title'       => '{$lang->af_headerwelcomeavatar_enabled}',
        'description' => '{$lang->af_headerwelcomeavatar_enabled_desc}',
        'optionscode' => 'yesno',
        'value'       => '1',
    ]);

    // По умолчанию: НЕ инлайнить, а подключать файлами (как ты и хочешь)
    $ensure('af_headerwelcomeavatar_inline_css', [
        'title'       => '{$lang->af_headerwelcomeavatar_inline_css}',
        'description' => '{$lang->af_headerwelcomeavatar_inline_css_desc}',
        'optionscode' => 'yesno',
        'value'       => '0',
    ]);

    // По умолчанию: JS включён
    $ensure('af_headerwelcomeavatar_load_js', [
        'title'       => '{$lang->af_headerwelcomeavatar_load_js}',
        'description' => '{$lang->af_headerwelcomeavatar_load_js_desc}',
        'optionscode' => 'yesno',
        'value'       => '1',
    ]);

    // По умолчанию: НЕ инлайнить JS
    $ensure('af_headerwelcomeavatar_inline_js', [
        'title'       => '{$lang->af_headerwelcomeavatar_inline_js}',
        'description' => '{$lang->af_headerwelcomeavatar_inline_js_desc}',
        'optionscode' => 'yesno',
        'value'       => '0',
    ]);

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_headerwelcomeavatar_uninstall(): void
{
    global $db;

    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='af_headerwelcomeavatar'", ['limit' => 1]),
        'gid'
    );

    if ($gid) {
        $db->delete_query('settings', "gid='{$gid}'");
        $db->delete_query('settinggroups', "gid='{$gid}'");
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_headerwelcomeavatar_is_frontend(): bool
{
    if (defined('IN_ADMINCP') && IN_ADMINCP) return false;
    if (defined('THIS_SCRIPT')) {
        $s = strtolower((string)THIS_SCRIPT);
        if (strpos($s, 'modcp.php') !== false) return false;
    }
    return true;
}

function af_headerwelcomeavatar_asset_path(string $rel): string
{
    return __DIR__ . '/assets/' . ltrim($rel, '/');
}

function af_headerwelcomeavatar_bburl(string $path = ''): string
{
    global $mybb;
    $bburl = '';
    if (isset($mybb->settings['bburl'])) $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $path = ltrim((string)$path, '/');
    return $path ? ($bburl . '/' . $path) : $bburl;
}

function af_headerwelcomeavatar_default_avatar(): string
{
    return af_headerwelcomeavatar_bburl('images/default_avatar.png');
}

function af_headerwelcomeavatar_normalize_src(string $src): string
{
    $src = trim($src);
    if ($src === '') return '';
    if (preg_match('~^https?://~i', $src)) return $src;
    if (strpos($src, '//') === 0) return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https:' : 'http:') . $src;
    $src = preg_replace('~^\./~', '', $src);
    return af_headerwelcomeavatar_bburl($src);
}

function af_headerwelcomeavatar_current_avatar_src(): string
{
    global $mybb;

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) return af_headerwelcomeavatar_default_avatar();

    $avatar = (string)($mybb->user['avatar'] ?? '');
    $avatar = trim($avatar);

    if ($avatar !== '') {
        $avatar = af_headerwelcomeavatar_normalize_src($avatar);
        if ($avatar !== '') return $avatar;
    }

    // если у юзера нет аватара — всё равно дефолт
    return af_headerwelcomeavatar_default_avatar();
}

function af_headerwelcomeavatar_current_profile_href(): string
{
    global $mybb;

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid > 0) return 'member.php?uid=' . $uid;
    return 'member.php?action=login';
}

function af_headerwelcomeavatar_is_guest_welcome(string $welcomeHtml): bool
{
    // очень надёжный маркер: наличие login/register ссылок
    return (stripos($welcomeHtml, 'action=login') !== false) || (stripos($welcomeHtml, 'action=register') !== false);
}

function af_headerwelcomeavatar_add_class_to_tag(string $html, string $tag, string $classToAdd): string
{
    // Добавляет класс в первый <tag ...>
    return preg_replace_callback(
        '~<'.$tag.'\b([^>]*)>~i',
        function ($m) use ($classToAdd) {
            $attrs = $m[1];
            if (preg_match('~\bclass\s*=\s*("|\')([^"\']*)\1~i', $attrs, $cm)) {
                $old = $cm[2];
                if (preg_match('~(^|\s)'.preg_quote($classToAdd, '~').'(\s|$)~', $old)) {
                    return '<'.$tag.$attrs.'>';
                }
                $new = trim($old . ' ' . $classToAdd);
                $attrs = preg_replace('~\bclass\s*=\s*("|\')([^"\']*)\1~i', 'class="'.$new.'"', $attrs, 1);
                return '<'.$tag.$attrs.'>';
            }
            return '<'.$tag.$attrs.' class="'.$classToAdd.'">';
        },
        $html,
        1
    );
}

function af_headerwelcomeavatar_style_action_link(string $aHtml, string $variant, string $faClass): string
{
    $aHtml = af_headerwelcomeavatar_add_class_to_tag($aHtml, 'a', 'af-hw-btn');
    $aHtml = af_headerwelcomeavatar_add_class_to_tag($aHtml, 'a', 'af-hw-btn--' . $variant);

    // Убиваем спрайт, если тема его пихает inline
    $aHtml = preg_replace('~\sstyle\s*=\s*("|\')(.*?)\1~i', ' style="$2; background-image:none;"', $aHtml, 1);

    // Иконка, если ещё нет
    if (stripos($aHtml, 'af-hw-ico') === false) {
        $aHtml = preg_replace('~(<a\b[^>]*>)~i', '$1<i class="'.$faClass.' af-hw-ico"></i> ', $aHtml, 1);
    }

    return $aHtml;
}

function af_headerwelcomeavatar_rebuild_welcome_span(string $spanHtml): string
{
    // Забираем атрибуты и контент outer span
    if (!preg_match('~^<span\b([^>]*)>(.*)</span>$~is', trim($spanHtml), $m)) {
        return $spanHtml;
    }

    $attrs = $m[1];
    $inner = $m[2];

    $isGuest = af_headerwelcomeavatar_is_guest_welcome($spanHtml);

    // 1) вытащим action-ссылки в actions, удалим их из inner
    $actions = [];

    $extract = function (string $pattern, string $variant, string $fa) use (&$inner, &$actions): void {
        if (preg_match('~'.$pattern.'~is', $inner, $mm)) {
            $a = $mm[0];
            $a = af_headerwelcomeavatar_style_action_link($a, $variant, $fa);
            $actions[] = $a;
            $inner = preg_replace('~'.$pattern.'~is', '', $inner, 1);
        }
    };

    if ($isGuest) {
        $extract('<a\b[^>]*href=("|\')[^"\']*member\.php[^"\']*action=login[^"\']*\1[^>]*>.*?</a>', 'login', 'fa-solid fa-right-to-bracket');
        $extract('<a\b[^>]*href=("|\')[^"\']*member\.php[^"\']*action=register[^"\']*\1[^>]*>.*?</a>', 'reg', 'fa-solid fa-user-plus');
    } else {
        // logout: либо action=logout, либо class=logout
        $extract('<a\b[^>]*(?:class=("|\')[^"\']*\blogout\b[^"\']*\1[^>]*|href=("|\')[^"\']*member\.php[^"\']*action=logout[^"\']*\2[^>]*)>.*?</a>', 'logout', 'fa-solid fa-right-from-bracket');
    }

    // 2) подчистка хвостов после вырезания action-ссылок
    //    (часто остаются | , : пробелы и т.п.)
    $inner = preg_replace('~(&nbsp;|\xC2\xA0)+~u', ' ', $inner);
    $inner = preg_replace('~\s+~u', ' ', $inner);
    $inner = preg_replace('~(\s*(\||&bull;|&middot;|•)\s*)+$~u', ' ', $inner);
    $inner = preg_replace('~[,，]\s*$~u', '', $inner);
    $inner = trim($inner);

    // 3) Разбивка на строки (как JS делал нодами)
    //    Сначала пробуем по "Ваш последний визит" / "Your last visit" (у тем часто нет <br>)
    $line1 = '';
    $line2 = '';

    $splitMarkers = [
        'Ваш последний визит',
        'Последний визит',
        'Your last visit',
        'Last visit',
    ];

    $markerPos = -1;
    $markerLen = 0;
    foreach ($splitMarkers as $mk) {
        $p = stripos($inner, $mk);
        if ($p !== false) {
            $markerPos = (int)$p;
            $markerLen = strlen($mk);
            break;
        }
    }

    if ($markerPos >= 0) {
        $line1 = trim(substr($inner, 0, $markerPos));
        $line2 = trim(substr($inner, $markerPos));
    } else {
        // если есть <br> — используем его
        $parts = preg_split('~<br\s*/?>~i', $inner, 2);
        $line1 = trim($parts[0] ?? '');
        $line2 = trim($parts[1] ?? '');
    }

    // 4) Нормализуем точку после </strong> в line1 (твоя старая боль)
    if ($line1 !== '' && preg_match('~</strong>~i', $line1)) {
        // уберём лишние ". " сразу после strong, а потом поставим ровно одну точку
        $line1 = preg_replace('~</strong>\s*\.\s*~i', '</strong> ', $line1);
        if (preg_match('~</strong>(?!\s*\.)~i', $line1)) {
            $line1 = preg_replace('~</strong>~i', '</strong>.', $line1, 1);
        }
        $line1 = preg_replace('~</strong>\.(?!\s)~i', '</strong>. ', $line1);
    }

    // 5) гарантируем класс welcome + af-hw-welcome на outer span
    if (preg_match('~\bclass\s*=\s*("|\')([^"\']*)\1~i', $attrs, $cm)) {
        $cls = $cm[2];
        if (stripos($cls, 'welcome') === false) $cls .= ' welcome';
        if (stripos($cls, 'af-hw-welcome') === false) $cls .= ' af-hw-welcome';
        $cls = trim(preg_replace('~\s+~', ' ', $cls));
        $attrs = preg_replace('~\bclass\s*=\s*("|\')([^"\']*)\1~i', 'class="'.$cls.'"', $attrs, 1);
    } else {
        $attrs .= ' class="welcome af-hw-welcome"';
    }

    // 6) Собираем
    $out  = '<span'.$attrs.'>';
    $out .= '<div class="af-hw-line1">'.($line1 !== '' ? $line1 : ($isGuest ? 'Здравствуйте!' : 'С возвращением!')).'</div>';

    if (!$isGuest && $line2 !== '') {
        $out .= '<div class="af-hw-line2">'.$line2.'</div>';
    }

    if (!empty($actions)) {
        $out .= '<div class="af-hw-actions">'.implode(' ', $actions).'</div>';
    }

    $out .= '</span>';

    return $out;
}

function af_headerwelcomeavatar_find_welcome_span_range(string $page): array
{
    // Находим позицию открытия <span ... class="...welcome...">
    if (!preg_match('~<span\b[^>]*\bclass=("|\')[^"\']*\bwelcome\b[^"\']*\1[^>]*>~i', $page, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $start = (int)$m[0][1];

    // Дальше балансируем <span> ... </span> с учётом вложенных span
    $len = strlen($page);
    $pos = $start;
    $depth = 0;
    $openFound = false;
    $closeEnd = -1;

    // Ищем все теги span начиная с $start
    if (!preg_match_all('~</?span\b~i', $page, $tags, PREG_OFFSET_CAPTURE, $start)) {
        return [];
    }

    foreach ($tags[0] as $t) {
        $tag = strtolower($t[0]);
        $tpos = (int)$t[1];

        if (!$openFound) {
            // первый <span ...welcome...> — это наш старт
            if ($tpos !== $start) continue;
            $openFound = true;
        }

        if ($tag === '<span') {
            $depth++;
        } else { // </span
            $depth--;
            if ($depth === 0) {
                // Найдём конец закрывающего тега '>'
                $gt = strpos($page, '>', $tpos);
                if ($gt === false) return [];
                $closeEnd = $gt + 1;
                break;
            }
        }
    }

    if (!$openFound || $closeEnd < 0 || $closeEnd <= $start || $closeEnd > $len) {
        return [];
    }

    return [$start, $closeEnd];
}

function af_headerwelcomeavatar_wrap_welcome(string $page): string
{
    // анти-дубль
    if (strpos($page, '<!--af_headerwelcomeavatar-->') !== false) {
        return $page;
    }

    $range = af_headerwelcomeavatar_find_welcome_span_range($page);
    if (empty($range)) return $page;

    [$start, $end] = $range;
    $welcomeHtml = substr($page, $start, $end - $start);
    if ($welcomeHtml === '' || strpos($welcomeHtml, 'af-hw-wrap') !== false) {
        return $page;
    }

    $isGuest = af_headerwelcomeavatar_is_guest_welcome($welcomeHtml);

    $avatarHref = $isGuest ? 'member.php?action=login' : af_headerwelcomeavatar_current_profile_href();
    $avatarSrc  = $isGuest ? af_headerwelcomeavatar_default_avatar() : af_headerwelcomeavatar_current_avatar_src();

    // добавим класс на welcome-span (не ломая HTML)
    if (stripos($welcomeHtml, 'af-hw-welcome') === false) {
        if (preg_match('~\bclass\s*=\s*("|\')([^"\']*)\1~i', $welcomeHtml)) {
            $welcomeHtml = preg_replace(
                '~\bclass\s*=\s*("|\')([^"\']*)\1~i',
                'class="$2 af-hw-welcome"',
                $welcomeHtml,
                1
            );
        } else {
            $welcomeHtml = preg_replace('~<span\b~i', '<span class="af-hw-welcome"', $welcomeHtml, 1);
        }
    }

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    $pageClass = ($script === 'online.php') ? ' af-hw--online' : '';

    $wrap =
        '<!--af_headerwelcomeavatar-->' .
        '<div class="af-hw-wrap'.$pageClass.'">' .
            '<a class="af-hw-avatarlink" href="'.htmlspecialchars($avatarHref, ENT_QUOTES).'">' .
                '<img class="af-hw-avatar" alt="avatar" loading="lazy" decoding="async" src="'.htmlspecialchars($avatarSrc, ENT_QUOTES).'">' .
            '</a>' .
            '<div class="af-hw-body">' .
                $welcomeHtml .
            '</div>' .
        '</div>';


    $page = substr_replace($page, $wrap, $start, $end - $start);

    return $page;
}

function af_headerwelcomeavatar_inject_assets(string $page): string
{
    global $mybb;

    $enabled = (int)($mybb->settings['af_headerwelcomeavatar_enabled'] ?? 1);
    if ($enabled !== 1) return $page;

    $inlineCss = (int)($mybb->settings['af_headerwelcomeavatar_inline_css'] ?? 0) === 1;
    $loadJs    = (int)($mybb->settings['af_headerwelcomeavatar_load_js'] ?? 1) === 1;
    $inlineJs  = (int)($mybb->settings['af_headerwelcomeavatar_inline_js'] ?? 0) === 1;

    // База для ассетов: asset_url (если задан MyBB), иначе bburl
    $base = '';
    if (isset($mybb->asset_url) && is_string($mybb->asset_url) && $mybb->asset_url !== '') {
        $base = rtrim((string)$mybb->asset_url, '/');
    } else {
        $base = rtrim(af_headerwelcomeavatar_bburl(), '/');
    }

    $cssUrl = $base . '/inc/plugins/advancedfunctionality/addons/headerwelcomeavatar/assets/headerwelcomeavatar.css';
    $jsUrl  = $base . '/inc/plugins/advancedfunctionality/addons/headerwelcomeavatar/assets/headerwelcomeavatar.js';

    $headInject = '';

    // CSS
    if (strpos($page, 'id="af-hwa-css"') === false) {
        if ($inlineCss) {
            $cssFile = af_headerwelcomeavatar_asset_path('headerwelcomeavatar.css');
            $css = is_file($cssFile) ? (string)file_get_contents($cssFile) : '';
            if ($css !== '') {
                $headInject .= "\n" . '<style id="af-hwa-css">' . "\n" . $css . "\n" . '</style>' . "\n";
            }
        } else {
            $headInject .= "\n" .
                '<link rel="stylesheet" type="text/css" id="af-hwa-css" href="' . htmlspecialchars($cssUrl, ENT_QUOTES) . '" />' .
                "\n";
        }
    }

    // JS
    if ($loadJs && strpos($page, 'id="af-hwa-js"') === false) {
        if ($inlineJs) {
            $jsFile = af_headerwelcomeavatar_asset_path('headerwelcomeavatar.js');
            $js = is_file($jsFile) ? (string)file_get_contents($jsFile) : '';
            if ($js !== '') {
                $headInject .= "\n" . '<script id="af-hwa-js">' . "\n" . $js . "\n" . '</script>' . "\n";
            }
        } else {
            $headInject .= "\n" .
                '<script type="text/javascript" id="af-hwa-js" src="' . htmlspecialchars($jsUrl, ENT_QUOTES) . '" defer></script>' .
                "\n";
        }
    }

    if ($headInject === '') return $page;

    // Вставляем перед </head>
    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $headInject . '</head>', $page, 1);
    } else {
        $page = $headInject . $page;
    }

    return $page;
}

/* -------------------- AF Hooks -------------------- */

function af_headerwelcomeavatar_init(): void
{
    // no-op
}

function af_headerwelcomeavatar_pre_output(string &$page): void
{
    global $mybb;

    if (!af_headerwelcomeavatar_is_frontend()) return;

    $enabled = (int)($mybb->settings['af_headerwelcomeavatar_enabled'] ?? 1);
    if ($enabled !== 1) return;

    // 1) ассеты
    $page = af_headerwelcomeavatar_inject_assets($page);

    // 2) обертка welcome + точный аватар
    $page = af_headerwelcomeavatar_wrap_welcome($page);
}
