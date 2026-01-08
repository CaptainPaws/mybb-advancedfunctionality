<?php
/**
 * AF Addon: AdvancedOnlineAvatar
 * MyBB 1.8.x, PHP 8.0–8.4
 *
 * Задача: на /online.php добавить аватары рядом с ником.
 * Реализация: pre_output_page
 *  - вытаскиваем uid из ссылок member.php?action=profile&uid=...
 *  - батчем получаем avatar из users
 *  - подставляем <span><img></span> перед ссылкой
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AOA_ID', 'advancedonlineavatar');
define('AF_AOA_VER', '1.0.0');

define('AF_AOA_MARK_DONE', '<!--af_aoa_done-->');
define('AF_AOA_MARK_ASSETS', '<!--af_aoa_assets-->');

function af_advancedonlineavatar_install(): bool
{
    // Ничего в БД не создаём
    return true;
}

function af_advancedonlineavatar_uninstall(): bool
{
    return true;
}

function af_advancedonlineavatar_activate(): bool
{
    return true;
}

function af_advancedonlineavatar_deactivate(): bool
{
    return true;
}

function af_advancedonlineavatar_init(): void
{
    // no-op
}

function af_advancedonlineavatar_pre_output(&$page = ''): void
{
    global $db, $mybb, $lang;

    if (!is_string($page) || $page === '') {
        return;
    }

    // только /online.php
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'online.php') {
        return;
    }

    // защита от повторной обработки
    if (strpos($page, AF_AOA_MARK_DONE) !== false) {
        return;
    }

    // 1) Подключим CSS (без шаблонов) — вставкой перед </head>
    if (strpos($page, AF_AOA_MARK_ASSETS) === false) {
        $cssUrl = rtrim($mybb->settings['bburl'], '/')
            . '/inc/plugins/advancedfunctionality/addons/' . AF_AOA_ID
            . '/assets/advancedonlineavatar.css?v=' . rawurlencode(AF_AOA_VER);

        $linkTag = AF_AOA_MARK_ASSETS . "\n"
            . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n";

        if (stripos($page, '</head>') !== false) {
            $page = preg_replace('~</head>~i', $linkTag . '</head>', $page, 1);
        } else {
            $page = $linkTag . $page;
        }
    }

    // дефолт для гостя — ЯВНО
    $guestDefaultAvatar = '/images/default_avatar.png';

    // дефолт для юзера БЕЗ аватара — ДОЛЖЕН БЫТЬ КАК У ГОСТЯ
    $userFallbackAvatar = $guestDefaultAvatar;

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    $normalizeUrl = static function (string $url) use ($bburl): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^(https?:)?//~i', $url)) {
            return $url;
        }
        if (strpos($url, '/') === 0) {
            return $bburl . $url;
        }
        return $bburl . '/' . $url;
    };

    // 2) Собираем UID
    $uids = [];
    if (preg_match_all('~member\.php\?[^"\']*(?:\&amp;|\&)uid=([0-9]+)~i', $page, $mU)) {
        foreach ($mU[1] as $uidStr) {
            $uid = (int)$uidStr;
            if ($uid > 0) {
                $uids[$uid] = true;
            }
        }
    }

    // 3) Батчем тянем аватары
    $avatars = [];
    if (!empty($uids)) {
        $uidList = implode(',', array_map('intval', array_keys($uids)));

        $query = $db->simple_select('users', 'uid, avatar', 'uid IN (' . $uidList . ')');
        while ($row = $db->fetch_array($query)) {
            $avatars[(int)$row['uid']] = (string)$row['avatar'];
        }
    }

    $guestAvatarUrl = $normalizeUrl($guestDefaultAvatar);
    $guestHtml = '';
    if ($guestAvatarUrl !== '') {
        $guestHtml = '<span class="af-aoa-avatar af-aoa-guest" aria-hidden="true">'
                   . '<img src="' . htmlspecialchars_uni($guestAvatarUrl) . '" alt="">'
                   . '</span>';
    }

    // метки гостя
    $guestLabels = [];
    if (is_object($lang) && !empty($lang->guest)) {
        $guestLabels[] = (string)$lang->guest;
    }
    $guestLabels[] = 'Guest';
    $guestLabels[] = 'Гость';
    $guestLabels = array_values(array_unique(array_filter($guestLabels)));

    $guestLabelRe = '';
    if (!empty($guestLabels)) {
        $guestLabelRe = '~(?:' . implode('|', array_map(function ($s) {
            return preg_quote($s, '~');
        }, $guestLabels)) . ')~iu';
    }

    // 4) Модифицируем ТОЛЬКО первый столбец в строках данных
    $page = preg_replace_callback('~<tr\b[^>]*>[\s\S]*?</tr>~i', function ($trM) use (
        $avatars,
        $userFallbackAvatar,
        $normalizeUrl,
        $guestHtml,
        $guestLabelRe
    ) {
        $tr = $trM[0];

        // пропускаем заголовочные строки/секции
        if (preg_match('~<th\b~i', $tr)) {
            return $tr;
        }
        if (preg_match('~\bclass\s*=\s*(["\'])[^"\']*\b(thead|tcat|tfoot)\b[^"\']*\1~i', $tr)) {
            return $tr;
        }

        // найдём первый td
        if (!preg_match('~(<td\b[^>]*>)([\s\S]*?)(</td>)~i', $tr, $tdM)) {
            return $tr;
        }

        $tdOpen  = $tdM[1];
        $tdInner = $tdM[2];
        $tdClose = $tdM[3];

        // если это всё же заголовочный td — не трогаем
        if (preg_match('~\bclass\s*=\s*(["\'])[^"\']*\b(thead|tcat|tfoot)\b[^"\']*\1~i', $tdOpen)) {
            return $tr;
        }

        // защита от дубля
        if (strpos($tdInner, 'af-aoa-avatar') !== false) {
            return $tr;
        }

        // 4.1) Если в первом столбце есть uid — вставляем аватар пользователя
        if (preg_match('~member\.php\?[^"\']*(?:\&amp;|\&)uid=([0-9]+)~i', $tdInner, $um)) {
            $uid = (int)$um[1];

            $avatarRaw = $avatars[$uid] ?? '';
            $avatarRaw = trim((string)$avatarRaw);

            // ВАЖНО: если у пользователя пустой аватар — ставим дефолт КАК У ГОСТЯ
            if ($avatarRaw === '') {
                $avatarRaw = $userFallbackAvatar;
            }

            $avatarUrl = $normalizeUrl($avatarRaw);
            if ($avatarUrl !== '') {
                $userHtml = '<span class="af-aoa-avatar" aria-hidden="true">'
                          . '<img src="' . htmlspecialchars_uni($avatarUrl) . '" alt="">'
                          . '</span>';

                $newTd = $tdOpen . $userHtml . $tdInner . $tdClose;
                return preg_replace('~' . preg_quote($tdOpen, '~') . '[\s\S]*?' . preg_quote($tdClose, '~') . '~i', $newTd, $tr, 1);
            }

            return $tr;
        }

        // 4.2) Иначе — кандидат на гостя
        if ($guestHtml !== '' && $guestLabelRe !== '' && preg_match($guestLabelRe, strip_tags($tdInner))) {
            $newTd = $tdOpen . $guestHtml . $tdInner . $tdClose;
            return preg_replace('~' . preg_quote($tdOpen, '~') . '[\s\S]*?' . preg_quote($tdClose, '~') . '~i', $newTd, $tr, 1);
        }

        return $tr;
    }, $page);

    $page .= "\n" . AF_AOA_MARK_DONE;
}
