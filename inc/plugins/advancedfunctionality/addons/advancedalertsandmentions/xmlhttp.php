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

    $uids = [];
    foreach ($items as $it) {
        $fu = af_aam_item_from_uid(is_array($it) ? $it : []);
        if ($fu > 0) $uids[$fu] = true;
    }
    $uids = array_keys($uids);

    $defaultUrl = af_aam_default_avatar_url();

    // если не нашли ни одного uid — приклеим дефолт всем items
    if (!$uids) {
        foreach ($items as &$it) {
            $uname = af_aam_item_from_username(is_array($it) ? $it : []);
            $payload = [
                'url'      => $defaultUrl,
                'width'    => $size,
                'height'   => $size,
                'username' => $uname,
            ];
            $it['avatar'] = $payload;              // текущий формат (объект)
            $it['avatar_payload'] = $payload;      // совместимость
            $it['avatar_url'] = $payload['url'];   // совместимость со старым JS (строка)
        }
        unset($it);
        return;
    }

    $in = implode(',', array_map('intval', $uids));
    $map = [];

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

    $uid = (int)$uid;
    if ($uid <= 0) return [];

    $row = $db->fetch_array($db->simple_select('users', 'af_aam_prefs', "uid={$uid}", ['limit' => 1]));
    $raw = (string)($row['af_aam_prefs'] ?? '');
    if ($raw === '') return [];

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
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
    $defaultUrl = htmlspecialchars(af_aam_default_avatar_url(), ENT_QUOTES, 'UTF-8');

    foreach ($items as $it) {
        $id = (int)($it['id'] ?? 0);
        if ($id <= 0) continue;

        $isRead = !empty($it['is_read']);
        $cls = $isRead ? 'alert--read' : 'alert--unread';

        $text = htmlspecialchars((string)($it['text'] ?? 'Уведомление'), ENT_QUOTES, 'UTF-8');
        $url  = (string)($it['url'] ?? '');
        $href = $url !== '' ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '#';

        $aUrl = $defaultUrl;
        $aW = 32; $aH = 32; $aAlt = '';

        if (!empty($it['avatar']) && is_array($it['avatar'])) {
            $rawUrl = (string)($it['avatar']['url'] ?? '');
            if ($rawUrl !== '') $aUrl = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');

            $aW   = (int)($it['avatar']['width'] ?? 32);
            $aH   = (int)($it['avatar']['height'] ?? 32);
            $aAlt = htmlspecialchars((string)($it['avatar']['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        }

        if ($aW <= 0) $aW = 32;
        if ($aH <= 0) $aH = 32;

        $html .= '<tr class="af-aam-alert-row '.$cls.'" data-alert-id="'.$id.'">';

        // аватар
        $html .= '<td class="af-aam-alert-avatar">';
        $html .= '<img src="'.$aUrl.'" width="'.$aW.'" height="'.$aH.'" alt="'.$aAlt.'" loading="lazy">';
        $html .= '</td>';

        // текст
        $html .= '<td class="af-aam-alert-text"><a class="af-aam-alert-link" href="'.$href.'">'.$text.'</a></td>';

        // действия
        $html .= '<td class="af-aam-alert-actions">';
        $html .= '<a href="#" class="markReadAlertButton'.($isRead ? ' hidden' : '').'" title="Прочитано">✔</a> ';
        $html .= '<a href="#" class="markUnreadAlertButton'.($isRead ? '' : ' hidden').'" title="Непрочитано">↺</a> ';
        $html .= '<a href="#" class="deleteAlertButton" title="Удалить">✕</a>';
        $html .= '</td></tr>';
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

        $items  = $repo->list_alerts($uid, (bool)$unreadOnly, $limit);

        // prefs: отключённые типы
        $prefs = af_aam_get_user_prefs($uid);
        $disabled = [];
        if (!empty($prefs['disabled_types']) && is_array($prefs['disabled_types'])) {
            $disabled = array_values(array_filter(array_map('strval', $prefs['disabled_types'])));
        }

        if ($disabled) {
            $items = array_values(array_filter($items, function($it) use ($disabled) {
                $code = (string)($it['type_code'] ?? $it['code'] ?? $it['type'] ?? '');
                return $code === '' ? true : !in_array($code, $disabled, true);
            }));
        }

        // аватары для модалки/тостов
        af_aam_attach_avatars($items, 32);

        $unread = $repo->get_unread_count($uid);
        $newest = $repo->get_newest_id($uid);


        af_aam_json([
            'ok' => 1,
            'unread_count' => $unread,
            'unread' => $unread,
            'server_newest_id' => $newest,
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
        af_aam_json([
            'ok' => 1,
            'unread' => 0,
            'unread_count' => 0,
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
        global $db;

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

        $html =
            '<form id="af_aam_prefs_form" class="af-aam-prefs-form">'
        . '  <div class="af-aam-prefs-section">'
        . '    <div class="af-aam-prefs-title">Типы уведомлений</div>'
        .      ($rows !== '' ? $rows : '<div class="af-aam-prefs-note">Нет активных типов уведомлений.</div>')
        . '  </div>'
        . '  <div class="af-aam-prefs-section">'
        . '    <div class="af-aam-prefs-title">Плашки</div>'
        . '    <label class="af-aam-pref-row">'
        . '      <input type="checkbox" id="af_aam_pref_toasts" '.($toasts ? 'checked="checked"' : '').'> Показывать тост-плашки'
        . '    </label>'
        . '  </div>'
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
        af_aam_set_user_prefs($uid, $prefs);

        af_aam_json(['ok' => 1, 'saved' => 1]);
    }


    af_aam_json(['ok' => 0, 'error' => 'unknown_op', 'op' => $op]);
}
