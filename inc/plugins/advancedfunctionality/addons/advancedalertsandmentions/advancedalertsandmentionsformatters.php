<?php
/**
 * Advanced Alerts and Mentions — форматирование текстов уведомлений и описание типов.
 *
 * ВАЖНО:
 * - Этот файл подключается из advancedalertsandmentions.php
 * - Здесь нет хуков, только форматирование и помощь по типам.
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_AAM_ID')) {
    // На всякий случай, но по идее константа уже определена в основном файле
    define('AF_AAM_ID', 'advancedalertsandmentions');
}

/**
 * Возвращает список "известных" типов уведомлений.
 * Можно использовать для каких-нибудь проверок/будущей админки.
 */
function af_aam_get_known_types(): array
{
    return [
        'rep',
        'pm',
        'post_threadauthor',
        'subscribed_thread',
        'quoted',
        'mention',
        // кастомные типы (например achievements) регистрируются своими аддонами
    ];
}

/**
 * Форматирование одного уведомления: текст + URL.
 *
 * На входе ожидается массив из SELECT'а:
 *  - code
 *  - title
 *  - uid
 *  - from_uid
 *  - object_id
 *  - extra (JSON)
 */
function af_aam_format_alert(array $alert): array
{
    global $lang, $mybb;

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    $code   = $alert['code'] ?? '';
    $title  = $alert['title'] ?? '';
    $extra  = [];

    if (!empty($alert['extra'])) {
        $decoded = json_decode($alert['extra'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    $fromUser = $alert['from_username'] ?? '';
    if ($fromUser === '') {
        $fromUser = af_aam_format_username((int)($alert['from_uid'] ?? 0));
    }
    $subject  = (string)($extra['subject'] ?? '');
    $pid      = (int)($extra['pid'] ?? 0);
    $tid      = (int)($extra['tid'] ?? (int)($alert['object_id'] ?? 0));

    $url  = '';
    $text = $code;

    switch ($code) {
        // --- Репутация ---
        case 'rep':
            $repChange = (int)($extra['reputation'] ?? 0);
            $text = $lang->sprintf($lang->af_aam_text_rep, $fromUser, $repChange);

            $targetUid = (int)($alert['uid'] ?? 0);
            $url = 'reputation.php?uid=' . $targetUid;
            if (!empty($extra['rid'])) {
                $url .= '#rid' . (int)$extra['rid'];
            }
            break;

        // --- Личные сообщения ---
        case 'pm':
            // Ожидаем, что object_id = pmid
            $pmid    = (int)($alert['object_id'] ?? 0);
            $subject = (string)($extra['subject'] ?? '');

            if ($fromUser === '') {
                // подстраховка, если $fromUser не вычислился
                $fromUser = $lang->af_aam_unknown_user ?? 'Кто-то';
            }

            $text = $lang->sprintf($lang->af_aam_text_pm, $fromUser, $subject);

            if ($pmid > 0) {
                $url = 'private.php?action=read&pmid=' . $pmid;
            } else {
                // На крайний случай – просто список ЛС
                $url = 'private.php';
            }
            break;


        // --- Ответ в твоей теме ---
        case 'post_threadauthor':
            $text = $lang->sprintf($lang->af_aam_text_reply, $fromUser, $subject);
            if ($pid > 0) {
                $url = 'showthread.php?pid=' . $pid . '#pid' . $pid;
            } else {
                $url = 'showthread.php?tid=' . $tid;
            }
            break;

        // --- Новый пост в подписанной теме ---
        case 'subscribed_thread':
            $text = $lang->sprintf($lang->af_aam_text_subscribed, $fromUser, $subject);
            if ($pid > 0) {
                $url = 'showthread.php?pid=' . $pid . '#pid' . $pid;
            } else {
                $url = 'showthread.php?tid=' . $tid;
            }
            break;


        // --- Цитата ---
        case 'quoted':
            $text = $lang->sprintf($lang->af_aam_text_quote, $fromUser, $subject);
            if ($pid > 0) {
                $url = 'showthread.php?pid=' . $pid . '#pid' . $pid;
            } else {
                $url = 'showthread.php?tid=' . $tid;
            }
            break;


        // --- Упоминание @username / @"Имя" ---
        case 'mention':
            $text = $lang->sprintf($lang->af_aam_text_mention, $fromUser, $subject);
            if ($pid > 0) {
                $url = 'showthread.php?pid=' . $pid . '#pid' . $pid;
            } else {
                $url = 'showthread.php?tid=' . $tid;
            }
            break;


        // "В подписанном форуме создана новая тема ..." 
        case 'subscribed_forum':
            $text = $lang->sprintf($lang->af_aam_text_subscribed_forum, $fromUser, $subject);

            if ($pid > 0) {
                // Сразу на первый пост темы
                $url = 'showthread.php?pid=' . $pid . '#pid' . $pid;
            } elseif ($tid > 0) {
                $url = 'showthread.php?tid=' . $tid;
            } elseif (!empty($extra['fid'])) {
                $url = 'forumdisplay.php?fid=' . (int)$extra['fid'];
            }
            break;

        // --- Все остальные коды (в т.ч. кастомные) ---
        default:
            $labelKey   = 'af_aam_alert_type_' . $code;
            $customText = (string)($extra['message'] ?? '');

            if ($customText !== '') {
                $text = $customText;
            } elseif ($title !== '') {
                $text = $title;
            } elseif (isset($lang->{$labelKey})) {
                $text = $lang->{$labelKey};
            } else {
                $text = $code;
            }

            if (!empty($extra['url'])) {
                $url = (string)$extra['url'];
            }
            
    }

    // Если в extra явно передали URL – он имеет приоритет
    if (!empty($extra['url'])) {
        $url = (string)$extra['url'];
    }

    return [
        'text' => $text,
        'url'  => $url,
    ];
}

/**
 * Упрощённый помощник: сразу вернуть только текст.
 */
function af_aam_format_alert_text(array $alert): string
{
    $formatted = af_aam_format_alert($alert);
    return $formatted['text'];
}

/**
 * Форматирование имени пользователя для текста уведомлений.
 */
function af_aam_format_username(int $uid): string
{
    global $lang;

    if ($uid <= 0) {
        return $lang->af_aam_text_unknown_user ?? 'User';
    }

    $user = get_user($uid);
    if (empty($user['username'])) {
        return $lang->af_aam_text_unknown_user ?? 'User';
    }

    return $user['username'];
}

function af_aam_avatar_data(int $uid): array
{
    $fallbackInitial = '?';
    $username = '';

    if ($uid > 0) {
        $user = get_user($uid);
        if (!empty($user)) {
            $username = $user['username'] ?? '';
            $avatar = format_avatar($user['avatar'] ?? '', $user['avatardimensions'] ?? '', '32|32');
            if (!empty($avatar['image'])) {
                return [
                    'url'      => $avatar['image'],
                    'width'    => (int)($avatar['width'] ?? 32),
                    'height'   => (int)($avatar['height'] ?? 32),
                    'username' => $username,
                    'initial'  => mb_substr($username, 0, 1, 'UTF-8'),
                ];
            }
            $fallbackInitial = mb_substr($username, 0, 1, 'UTF-8');
        }
    }

    return [
        'url'      => '',
        'width'    => 32,
        'height'   => 32,
        'username' => $username,
        'initial'  => $fallbackInitial,
    ];
}

function af_aam_render_avatar_html(int $uid, string $username = ''): string
{
    $data = af_aam_avatar_data($uid);

    if ($username === '') {
        $username = $data['username'] ?: ($data['initial'] ?: '?');
    }

    if (!empty($data['url'])) {
        $img = htmlspecialchars_uni($data['url']);
        $alt = htmlspecialchars_uni($username);
        $w   = (int)($data['width'] ?? 32);
        $h   = (int)($data['height'] ?? 32);
        return '<span class="af-aam-avatar"><img src="' . $img . '" alt="' . $alt . '" width="' . $w . '" height="' . $h . '" loading="lazy" /></span>';
    }

    $initial = htmlspecialchars_uni($data['initial'] ?: mb_substr($username, 0, 1, 'UTF-8'));
    return '<span class="af-aam-avatar af-aam-avatar--placeholder" aria-hidden="true">' . $initial . '</span>';
}