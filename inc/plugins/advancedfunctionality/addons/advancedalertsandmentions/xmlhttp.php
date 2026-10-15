<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/mentions.php';

function af_aam_json(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function af_aam_require_login(): void
{
    global $mybb;
    if (empty($mybb->user['uid'])) {
        af_aam_json(['ok' => 0, 'error' => 'not_logged_in']);
    }
}

function af_aam_require_post_key(): void
{
    global $mybb;

    if (function_exists('verify_post_check')) {
        $key = isset($mybb->input['my_post_key']) ? (string)$mybb->input['my_post_key'] : '';
        verify_post_check($key);
        return;
    }

    af_aam_json(['ok' => 0, 'error' => 'post_key_verify_missing']);
}

function af_aam_collect_ids(): array
{
    global $mybb;

    $ids = [];

    // id=
    if (isset($mybb->input['id'])) {
        $v = (int)$mybb->input['id'];
        if ($v > 0) $ids[] = $v;
    }

    // alert_id=
    if (isset($mybb->input['alert_id']) && !is_array($mybb->input['alert_id'])) {
        $v = (int)$mybb->input['alert_id'];
        if ($v > 0) $ids[] = $v;
    }

    // alert_id[]=
    if (isset($mybb->input['alert_id']) && is_array($mybb->input['alert_id'])) {
        foreach ($mybb->input['alert_id'] as $v) {
            $v = (int)$v;
            if ($v > 0) $ids[] = $v;
        }
    }

    $ids = array_values(array_unique($ids));
    return $ids;
}

function af_aam_abs_url(string $url): string
{
    global $mybb;

    $url = trim($url);
    if ($url === '') return '';

    // уже абсолютный
    if (preg_match('~^https?://~i', $url)) return $url;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') return $url;

    if ($url[0] === '/') return $bburl . $url;
    return $bburl . '/' . ltrim($url, '/');
}

function af_aam_default_avatar_url(): string
{
    global $mybb;

    $raw = '';
    if (!empty($mybb->settings['default_avatar'])) {
        $raw = (string)$mybb->settings['default_avatar'];
    }

    if ($raw === '') {
        $raw = '/images/default_avatar.png';
    }

    return af_aam_abs_url($raw);
}


/**
 * Приклеивает avatar-пейлоад к items одним запросом по from_uid.
 * Ожидается, что каждый item содержит from_uid и (желательно) from_username.
 */
 function af_aam_item_from_uid(array $it): int
{
    $keys = [
        'from_uid',
        'sender_uid',
        'user_uid',
        'uid',
        'fromUserId',
        'from_user_id',
        'from',
        'user_id',
    ];

    foreach ($keys as $k) {
        if (isset($it[$k])) {
            $v = (int)$it[$k];
            if ($v > 0) return $v;
        }
    }

    return 0;
}

function af_aam_item_from_username(array $it): string
{
    $keys = [
        'from_username',
        'sender_username',
        'username',
        'fromUserName',
        'from_user_name',
        'from_name',
        'from_user',
    ];

    foreach ($keys as $k) {
        if (isset($it[$k])) {
            $v = trim((string)$it[$k]);
            if ($v !== '') return $v;
        }
    }

    return '';
}

function af_aam_attach_avatars(array &$items, int $size = 32): void
{
    global $db;

    if (!$items) return;

    static $cacheBySize = [];

    if (!isset($cacheBySize[$size])) {
        $cacheBySize[$size] = [];
    }

    $uids = [];
    foreach ($items as $it) {
        $fu = af_aam_item_from_uid(is_array($it) ? $it : []);
        if ($fu > 0) $uids[$fu] = true;
    }
    $uids = array_keys($uids);

    $defaultUrl = af_aam_default_avatar_url();

    $uidsToFetch = $uids ? array_values(array_diff($uids, array_keys($cacheBySize[$size]))) : [];

    $map = $cacheBySize[$size];

    if ($uidsToFetch) {
        $in = implode(',', array_map('intval', $uidsToFetch));

        $q = $db->write_query("
            SELECT uid, username, avatar, avatardimensions
            FROM " . TABLE_PREFIX . "users
            WHERE uid IN ({$in})
        ");

        while ($u = $db->fetch_array($q)) {
            $uid = (int)$u['uid'];

            $raw = trim((string)($u['avatar'] ?? ''));
            $url = $raw !== '' ? af_aam_abs_url($raw) : $defaultUrl;

            // размеры
            $w = $size; $h = $size;
            $dims = (string)($u['avatardimensions'] ?? '');
            if ($dims && strpos($dims, '|') !== false) {
                $p = explode('|', $dims);
                $dw = isset($p[0]) ? (int)$p[0] : $size;
                $dh = isset($p[1]) ? (int)$p[1] : $size;
                if ($dw > 0 && $dh > 0) {
                    $w = min($dw, 64);
                    $h = min($dh, 64);
                }
            }

            $map[$uid] = [
                'url'      => $url,
                'width'    => $w > 0 ? $w : $size,
                'height'   => $h > 0 ? $h : $size,
                'username' => (string)($u['username'] ?? ''),
            ];

            $cacheBySize[$size][$uid] = $map[$uid];
        }
    }

    foreach ($items as &$it) {
        if (!is_array($it)) $it = [];

        $fu    = af_aam_item_from_uid($it);
        $uname = af_aam_item_from_username($it);

        if ($fu > 0 && isset($map[$fu])) {
            $payload = $map[$fu];
            if ($uname !== '') {
                $payload['username'] = $uname; // приоритет никнейма из item
            }
        } else {
            $payload = [
                'url'      => $defaultUrl,
                'width'    => $size,
                'height'   => $size,
                'username' => $uname,
            ];
        }

        $it['avatar'] = $payload;            // объект (для текущего рендера)
        $it['avatar_payload'] = $payload;    // объект (на всякий)
        $it['avatar_url'] = $payload['url']; // строка (для старого JS)
    }
    unset($it);
}


/**
 * Чтение/запись prefs в users.af_aam_prefs (JSON).
 * Схема: { disabled_types: [..], toasts: 1|0 }
 */
function af_aam_get_user_prefs(int $uid): array
{
    global $db;

    static $cache = [];

    $uid = (int)$uid;
    if ($uid <= 0) return [];

    if (array_key_exists($uid, $cache)) {
        return $cache[$uid];
    }

    $row = $db->fetch_array($db->simple_select('users', 'af_aam_prefs', "uid={$uid}", ['limit' => 1]));
    $raw = (string)($row['af_aam_prefs'] ?? '');
    if ($raw === '') {
        $cache[$uid] = [];
        return [];
    }

    $data = json_decode($raw, true);
    $cache[$uid] = is_array($data) ? $data : [];
    return $cache[$uid];
}

function af_aam_set_user_prefs(int $uid, array $prefs): void
{
    global $db;

    $uid = (int)$uid;
    if ($uid <= 0) return;

    $json = $db->escape_string(json_encode($prefs, JSON_UNESCAPED_UNICODE));
    $db->update_query('users', ['af_aam_prefs' => $json], "uid={$uid}");
}


function af_aam_render_rows(array $items): string
{
    $html = '';
    foreach ($items as $it) {
        $id=(int)($it['id'] ?? 0);
        if ($id<=0) continue;
        $read=!empty($it['is_read']);
        $esc=static function($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
        $code=(string)($it['type_code'] ?? '');
        $icon=!empty($it['is_all']) ? 'bullhorn' : ($code==='pm' ? 'envelope' : ($code==='mention' ? 'at' : ($code==='rep' ? 'star' : 'comment')));
        $avatar=$esc($it['avatar']['url'] ?? af_aam_default_avatar_url());
        $name=(string)($it['from_username'] ?? '');
        $text=strip_tags((string)($it['text'] ?? 'Уведомление'));
        // Formatters supply text, never trusted HTML. Do not expose raw BBCode.
        $text=preg_replace('~\[/?[a-z][^\]\r\n]*\]~i','',$text);
        $lead='';
        if ($name!=='' && strncmp($text,$name,strlen($name))===0) { $lead='<strong>'.$esc($name).'</strong>'; $text=substr($text,strlen($name)); }
        $url=$esc(af_aam_normalize_url((string)($it['url'] ?? '')) ?: '#');
        $stamp=(int)($it['dateline'] ?? 0);
        $date=$stamp>0 ? $esc(strip_tags(my_date('relative',$stamp))) : '';
        $subject=trim((string)($it['subject'] ?? ''));
        $preview=trim((string)($it['preview'] ?? ''));
        $clean=static function($value) use ($esc) { return $esc(preg_replace('~\[/?[a-z][^\]\r\n]*\]~i','',strip_tags($value))); };
        $details=($subject!=='' ? '<span class="af-aam-card-object">'.$clean($subject).'</span>' : '')
            .($preview!=='' ? '<span class="af-aam-card-preview">'.$clean($preview).'</span>' : '');
        $html.='<article class="af-aam-alert-row '.($read?'alert--read':'alert--unread').'" data-alert-id="'.$id.'">'
            .'<a class="af-aam-alert-link af-aam-card-link" href="'.$url.'">'
            .'<span class="af-aam-card-avatar"><img src="'.$avatar.'" alt="" width="56" height="56" loading="lazy"></span>'
            .'<span class="af-aam-card-kind"><i class="fa-solid fa-'.$icon.'" aria-hidden="true"></i></span>'
            .'<span class="af-aam-card-text">'.$lead.$esc($text).$details.'</span>'
            .'<span class="af-aam-card-meta"><span>'.$date.'</span><span class="af-aam-unread-dot" aria-label="Непрочитано"></span></span></a>'
            .'<div class="af-aam-alert-actions"><button type="button" class="markReadAlertButton'.($read?' hidden':'').'" aria-label="Отметить прочитанным"><i class="fa-solid fa-check" aria-hidden="true"></i></button>'
            .'<button type="button" class="markUnreadAlertButton'.($read?'':' hidden').'" aria-label="Отметить непрочитанным"><i class="fa-solid fa-envelope" aria-hidden="true"></i></button>'
            .'<button type="button" class="deleteAlertButton" aria-label="Удалить"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div></article>';
    }
    return $html;
}

function af_aam_xmlhttp_dispatch(): void
{
    global $mybb;

    // Мы обслуживаем только action=af_aam_api
    if (($mybb->get_input('action') ?? '') !== 'af_aam_api') {
        af_aam_json(['ok' => 0, 'error' => 'wrong_action']);
    }

    $op = isset($mybb->input['op']) ? (string)$mybb->input['op'] : 'list';

    af_aam_require_login();
    $uid = (int)$mybb->user['uid'];

    $repo = af_aam_repo();
    if (!$repo->ok()) {
        af_aam_json(['ok' => 0, 'error' => 'no_alerts_storage', 'hint' => 'Need aam_alerts/aam_alert_types tables']);
    }

    // ---------- READ ONLY ----------
    if ($op === 'suggest') {
        $q = isset($mybb->input['q']) ? (string)$mybb->input['q'] : '';
        $items = af_aam_suggest_users($q, 8);

        // ДОБАВЛЕНО: приклеиваем аватары для выпадающих подсказок (иначе фронт рисует "буквы")
        af_aam_attach_avatars($items, 24);

        af_aam_json(['ok' => 1, 'items' => $items]);
    }

    if ($op === 'list') {
        $unreadOnly = !empty($mybb->input['unreadOnly']) ? 1 : 0;
        $limit = isset($mybb->input['limit']) ? (int)$mybb->input['limit'] : 0;
        if ($limit <= 0) {
            $limit = (int)($mybb->settings['af_aam_dropdown_limit'] ?? 20);
        }
        if ($limit <= 0) {
            $limit = 20;
        }

        $prefs = af_aam_get_user_prefs($uid);
        $disabled = is_array($prefs['disabled_types'] ?? null) ? $prefs['disabled_types'] : [];
        $filter = (string)($mybb->input['filter'] ?? ($unreadOnly ? 'unread' : 'all'));
        if (!in_array($filter,['all','unread','pm','mention','news'],true)) $filter='all';
        $page=$repo->modal_page($uid,$filter,$limit,(int)($mybb->input['before'] ?? 0),$disabled);
        $items=$page['items'];

        // аватары для модалки/тостов
        af_aam_attach_avatars($items, 32);

        $unread = $repo->get_unread_count($uid);
        $newest = $repo->get_newest_id($uid);

        af_aam_json([
            'ok' => 1,
            'unread_count' => $unread,
            'unread' => $unread,
            'server_newest_id' => $newest,
            'filter' => $filter,
            'counts' => $page['counts'],
            'has_more' => $page['has_more'],
            'next_before' => $page['next_before'],
            'items' => $items,
            'template' => af_aam_render_rows($items),
        ]);
    }

    if ($op === 'poll') {
        $sinceId     = isset($mybb->input['since_id']) ? (int)$mybb->input['since_id'] : 0;
        $sinceUnread = isset($mybb->input['since_unread']) ? (int)$mybb->input['since_unread'] : 0;
        $timeout     = isset($mybb->input['timeout']) ? (int)$mybb->input['timeout'] : 25;

        $listLimit = (int)($mybb->settings['af_aam_dropdown_limit'] ?? 20);
        if ($listLimit <= 0) {
            $listLimit = 20;
        }

        $r = $repo->poll($uid, $sinceId, $sinceUnread, $timeout, 5);

        $items = $r['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        // prefs: отключённые типы + тосты
        $prefs = af_aam_get_user_prefs($uid);

        $disabled = [];
        if (!empty($prefs['disabled_types']) && is_array($prefs['disabled_types'])) {
            $disabled = array_values(array_filter(array_map('strval', $prefs['disabled_types'])));
        }

        $toastsEnabled = 1;
        if (isset($prefs['toasts'])) {
            $toastsEnabled = ((int)$prefs['toasts'] ? 1 : 0);
        }

        if ($disabled) {
            $items = array_values(array_filter($items, function($it) use ($disabled) {
                $code = (string)($it['type_code'] ?? $it['code'] ?? $it['type'] ?? '');
                return $code === '' ? true : !in_array($code, $disabled, true);
            }));
        }

        // полный список для модалки (не только новые элементы)
        $listItems = $repo->list_alerts($uid, false, $listLimit);
        if ($disabled) {
            $listItems = array_values(array_filter($listItems, function($it) use ($disabled) {
                $code = (string)($it['type_code'] ?? $it['code'] ?? $it['type'] ?? '');
                return $code === '' ? true : !in_array($code, $disabled, true);
            }));
        }

        // аватары для тостов/реалтайма и для модалки
        af_aam_attach_avatars($items, 32);
        af_aam_attach_avatars($listItems, 32);

        // ВАЖНО: чтобы фронт мог обновить модалку/список без доп. запроса
        $template = af_aam_render_rows($listItems);

        af_aam_json([
            'ok' => 1,
            'changed' => (int)($r['changed'] ?? 0),
            'unread' => (int)($r['unread'] ?? 0),
            'unread_count' => (int)($r['unread'] ?? 0),
            'server_newest_id' => (int)($r['server_newest_id'] ?? 0),
            'toasts_enabled' => $toastsEnabled,
            'items' => $items,
            'template' => $template,
        ]);
    }

    // ---------- MUTATIONS ----------
    if (in_array($op, ['mark_read', 'mark_unread', 'delete', 'mark_all_read'], true)) {
        af_aam_require_post_key();
    }

    if ($op === 'mark_all_read') {
        $repo->mark_all_read($uid);
        $unread = $repo->get_unread_count($uid);
        af_aam_json([
            'ok' => 1,
            'unread' => $unread,
            'unread_count' => $unread,
            'server_newest_id' => $repo->get_newest_id($uid),
        ]);
    }

    if ($op === 'mark_read') {
        $ids = af_aam_collect_ids();
        $repo->mark_read($uid, $ids);

        $unread = $repo->get_unread_count($uid);
        af_aam_json([
            'ok' => 1,
            'unread' => $unread,
            'unread_count' => $unread,
            'server_newest_id' => $repo->get_newest_id($uid),
        ]);
    }

    if ($op === 'mark_unread') {
        $ids = af_aam_collect_ids();
        $repo->mark_unread($uid, $ids);

        $unread = $repo->get_unread_count($uid);
        af_aam_json([
            'ok' => 1,
            'unread' => $unread,
            'unread_count' => $unread,
            'server_newest_id' => $repo->get_newest_id($uid),
        ]);
    }

    if ($op === 'delete') {
        $ids = af_aam_collect_ids();
        $repo->delete_alerts($uid, $ids);

        $unread = $repo->get_unread_count($uid);
        af_aam_json([
            'ok' => 1,
            'unread' => $unread,
            'unread_count' => $unread,
            'server_newest_id' => $repo->get_newest_id($uid),
        ]);
    }

    // prefs_* оставим мягко
    if ($op === 'prefs_form') {
        global $db, $mybb, $lang;

        if (!isset($lang->af_aam_name)) {
            $lang->load('advancedfunctionality_' . AF_AAM_ID);
        }

        $prefs = af_aam_get_user_prefs($uid);
        $disabled = [];
        if (!empty($prefs['disabled_types']) && is_array($prefs['disabled_types'])) {
            $disabled = array_values(array_filter(array_map('strval', $prefs['disabled_types'])));
        }

        // тумблер тостов: если не задано — включено
        $toasts = 1;
        if (isset($prefs['toasts'])) {
            $toasts = (int)$prefs['toasts'] ? 1 : 0;
        }

        $rows = '';

        // ожидаем таблицу aam_alert_types
        $q = $db->simple_select('aam_alert_types', 'code,title,enabled', 'enabled=1', ['order_by' => 'title', 'order_dir' => 'ASC']);
        while ($t = $db->fetch_array($q)) {
            $code = (string)($t['code'] ?? '');
            if ($code === '') continue;

            $title = (string)($t['title'] ?? '');
            if ($title === '') $title = $code;

            $checked = in_array($code, $disabled, true) ? '' : ' checked="checked"';
            $rows .= '<label class="af-aam-pref-row">'
                . '<input type="checkbox" class="af-aam-pref-checkbox" value="' . htmlspecialchars_uni($code) . '"' . $checked . '> '
                . htmlspecialchars_uni($title)
                . '</label>';
        }

        $typesTitle      = htmlspecialchars_uni($lang->af_aam_prefs_title ?? 'Типы уведомлений');
        $toastTitle      = htmlspecialchars_uni($lang->af_aam_toast_limit ?? 'Плашки');
        $toastLabel      = htmlspecialchars_uni($lang->af_aam_toast_limit ?? 'Показывать тост-плашки');

        $html =
            '<form id="af_aam_prefs_form" class="af-aam-prefs-form">'
        . '  <div class="af-aam-prefs-section">'
        . '    <div class="af-aam-prefs-title">' . $typesTitle . '</div>'
        .      ($rows !== '' ? $rows : '<div class="af-aam-prefs-note">Нет активных типов уведомлений.</div>')
        . '  </div>'
        . '  <div class="af-aam-prefs-section">'
        . '    <div class="af-aam-prefs-title">' . $toastTitle . '</div>'
        . '    <label class="af-aam-pref-row">'
        . '      <input type="checkbox" id="af_aam_pref_toasts" '.($toasts ? 'checked="checked"' : '').'> ' . $toastLabel
        . '    </label>'
        . '  </div>'
        . '  <label class="af-aam-pref-row af-aam-sound-setting"><span><strong>Звуковые уведомления</strong><small>Воспроизводить звук при получении новых уведомлений</small></span><input type="checkbox" role="switch" id="af_aam_sound_toggle" '.(!isset($prefs['sound']) || $prefs['sound'] ? 'checked' : '').'></label>'
        . '  <div class="af-aam-prefs-actions">'
        . '    <button type="submit" class="button">Сохранить</button>'
        . '  </div>'
        . '</form>';

        af_aam_json(['ok' => 1, 'html' => $html]);
    }

    if ($op === 'prefs_save') {
        af_aam_require_post_key();

        // types[] — это СПИСОК ВКЛЮЧЁННЫХ
        $enabledTypes = [];
        if (isset($mybb->input['types']) && is_array($mybb->input['types'])) {
            foreach ($mybb->input['types'] as $v) {
                $v = trim((string)$v);
                if ($v !== '') $enabledTypes[] = $v;
            }
        }
        $enabledTypes = array_values(array_unique($enabledTypes));

        // все активные типы
        global $db;
        $all = [];
        $q = $db->simple_select('aam_alert_types', 'code', 'enabled=1');
        while ($r = $db->fetch_array($q)) {
            $c = trim((string)($r['code'] ?? ''));
            if ($c !== '') $all[] = $c;
        }
        $all = array_values(array_unique($all));

        // disabled = all - enabled
        $disabled = array_values(array_diff($all, $enabledTypes));

        // тосты (0/1)
        $toasts = isset($mybb->input['toasts']) ? ((int)$mybb->input['toasts'] ? 1 : 0) : 1;

        $prefs = af_aam_get_user_prefs($uid);
        $prefs['disabled_types'] = $disabled;
        $prefs['toasts'] = $toasts;
        if (isset($mybb->input['sound'])) $prefs['sound'] = (int)!empty($mybb->input['sound']);
        af_aam_set_user_prefs($uid, $prefs);

        af_aam_json(['ok' => 1, 'saved' => 1]);
    }

    af_aam_json(['ok' => 0, 'error' => 'unknown_op', 'op' => $op]);
}
