<?php
/**
 * AF Addon: Advanced Account Switcher
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AAS_ID', 'advancedaccountswitcher');
define('AF_AAS_TABLE_LINKS', 'af_aas_links');
define('AF_AAS_TABLE_LOG',   'af_aas_switch_log');

function af_advancedaccountswitcher_install()
{
    global $db;

    // 1) Таблицы
    if (!$db->table_exists(AF_AAS_TABLE_LINKS)) {
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AAS_TABLE_LINKS . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                master_uid INT UNSIGNED NOT NULL,
                attached_uid INT UNSIGNED NOT NULL,
                is_secondary TINYINT(1) NOT NULL DEFAULT 0,
                is_shared TINYINT(1) NOT NULL DEFAULT 0,
                is_hidden TINYINT(1) NOT NULL DEFAULT 0,
                display_order INT NOT NULL DEFAULT 0,
                created_at INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY master_attached (master_uid, attached_uid),
                KEY master_uid (master_uid),
                KEY attached_uid (attached_uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    if (!$db->table_exists(AF_AAS_TABLE_LOG)) {
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AAS_TABLE_LOG . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_uid INT UNSIGNED NOT NULL DEFAULT 0,
                from_uid INT UNSIGNED NOT NULL DEFAULT 0,
                to_uid INT UNSIGNED NOT NULL DEFAULT 0,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                useragent_hash CHAR(64) NOT NULL DEFAULT '',
                created_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY actor_uid (actor_uid),
                KEY from_uid (from_uid),
                KEY to_uid (to_uid),
                KEY created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // 2) Настройки
    af_aas_ensure_settings();

    // 3) Шаблоны (берём из templates/advancedaccountswitcher.html)
    af_aas_install_templates();

    // 4) Таск cleanup (создаём файл + регистрируем в tasks)
    af_aas_install_task();

    // 5) Rebuild settings
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    } else {
        rebuild_settings();
    }
}

function af_advancedaccountswitcher_uninstall()
{
    global $db;

    // Таблицы
    if ($db->table_exists(AF_AAS_TABLE_LINKS)) {
        $db->drop_table(AF_AAS_TABLE_LINKS);
    }
    if ($db->table_exists(AF_AAS_TABLE_LOG)) {
        $db->drop_table(AF_AAS_TABLE_LOG);
    }

    // Настройки + группа
    af_aas_remove_settings();

    // Шаблоны
    af_aas_remove_templates();

    // Таск: удаляем запись из tasks (файл не трогаем — как обычно в MyBB, но можно и удалить вручную)
    $db->delete_query('tasks', "file='af_aas_cleanup'");

    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    } else {
        rebuild_settings();
    }
}

function af_advancedaccountswitcher_activate() { /* no-op (AF рулит enable/disable) */ }
function af_advancedaccountswitcher_deactivate() { /* no-op */ }

/**
 * AF hook entrypoint: called by AF core on global_start
 */
function af_advancedaccountswitcher_init()
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    global $plugins;

    // Основной вывод (инъекции + ассеты)
    $plugins->add_hook('pre_output_page', 'af_advancedaccountswitcher_pre_output');

    // UCP entry
    $plugins->add_hook('usercp_start', 'af_aas_usercp_dispatch');

    // Добавляем пункт в левое меню UCP, чтобы пользователь вообще видел куда идти
    $plugins->add_hook('usercp_menu', 'af_aas_usercp_menu');

    // misc entry (switch + suggest + account list)
    $plugins->add_hook('misc_start', 'af_aas_misc_dispatch');

    // PM notify (если включено)
    $plugins->add_hook('private_send_end', 'af_aas_private_send_end');

    // Антиабьюз: если бан мастера — режем доп. аккаунты
    $plugins->add_hook('global_start', 'af_aas_enforce_master_ban');
}


/**
 * AF hook entrypoint: called by AF core on pre_output_page (или через add_hook выше)
 */
function af_advancedaccountswitcher_pre_output(&$page)
{
    global $mybb;

    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        return;
    }
    if ((int)$mybb->user['uid'] <= 0) {
        return;
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        return;
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // ассеты
    $css = $bburl . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/advancedaccountswitcher.css';
    $js  = $bburl . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/advancedaccountswitcher.js';

    if (strpos($page, 'advancedaccountswitcher.css') === false) {
        $page = str_replace('</head>', '<link rel="stylesheet" href="' . htmlspecialchars_uni($css) . '?v=1.0.1" />' . "\n</head>", $page);
    }
    if (strpos($page, 'advancedaccountswitcher.js') === false) {
        $page = str_replace('</body>', '<script src="' . htmlspecialchars_uni($js) . '?v=1.0.1"></script>' . "\n</body>", $page);
    }

    // 1) Вставка 👥 рядом с ссылкой на usercp в panel_links
    if (strpos($page, '<!--af_aas_panel_icon-->') === false) {
        $widget = af_aas_render_panel_widget();
        if ($widget !== '') {
            $re = '~(<a[^>]+href=["\'][^"\']*usercp\.php[^"\']*["\'][^>]*class=["\'][^"\']*\busercp\b[^"\']*["\'][^>]*>.*?</a>)~is';

            if (preg_match($re, $page)) {
                $page = preg_replace($re, '$1' . "\n" . '<!--af_aas_panel_icon-->' . "\n" . $widget, $page, 1);
            } else {
                // fallback: если тема нестандартная — вставим сразу после <body>
                if (stripos($page, '<body') !== false) {
                    $page = preg_replace('~(<body[^>]*>)~i', '$1' . "\n" . '<!--af_aas_panel_icon-->' . "\n" . $widget . "\n", $page, 1);
                } else {
                    $page = '<!--af_aas_panel_icon-->' . "\n" . $widget . "\n" . $page;
                }
            }
        }
    }

    // 2) Ссылка Account list в навигации (опционально)
    if (!empty($mybb->settings['af_advancedaccountswitcher_nav_account_list']) && strpos($page, '<!--af_aas_nav_account_list-->') === false) {
        $url = $bburl . '/misc.php?action=af_aas_account_list';
        $li  = "\n" . '<!--af_aas_nav_account_list-->' . "\n"
             . '<li class="af-aas-nav-item"><a href="' . htmlspecialchars_uni($url) . '" class="af-aas-accountlist">Account list</a></li>' . "\n";

        // пробуем вставить после memberlist
        $reMember = '~(<li[^>]*>\s*<a[^>]+href=["\'][^"\']*memberlist\.php[^"\']*["\'][^>]*>.*?</a>\s*</li>)~is';
        if (preg_match($reMember, $page)) {
            $page = preg_replace($reMember, '$1' . $li, $page, 1);
        } else {
            // fallback: просто добавим перед закрытием первого <ul ...> навигации (очень мягко)
            $reUl = '~(<ul[^>]*class=["\'][^"\']*(?:menu|navigation|nav)[^"\']*["\'][^>]*>)~is';
            if (preg_match($reUl, $page)) {
                $page = preg_replace($reUl, '$1' . "\n" . $li, $page, 1);
            }
        }
    }
}


/* ============================================================
   Dispatchers
============================================================ */

function af_aas_misc_dispatch()
{
    global $mybb;

    if (THIS_SCRIPT !== 'misc.php') {
        return;
    }

    $action = (string)($mybb->input['action'] ?? '');

    if ($action === 'af_aas_switch') {
        af_aas_handle_switch();
    }

    if ($action === 'af_aas_user_suggest') {
        af_aas_handle_user_suggest();
    }

    if ($action === 'af_aas_account_list') {
        af_aas_render_account_list_page();
    }
}


function af_aas_usercp_dispatch()
{
    global $mybb;

    if (THIS_SCRIPT !== 'usercp.php') {
        return;
    }

    $action = (string)($mybb->input['action'] ?? '');
    if ($action !== 'af_aas') {
        return;
    }

    af_aas_render_usercp_page();
}

/* ============================================================
   Core checks
============================================================ */
function af_aas_user_allowed(int $uid): bool
{
    global $mybb;

    // Пусто / "*" / "all" => разрешено всем авторизованным
    $allowedRaw = trim((string)($mybb->settings['af_advancedaccountswitcher_allowed_groups'] ?? ''));
    if ($allowedRaw === '' || $allowedRaw === '*' || strtolower($allowedRaw) === 'all') {
        return ((int)($mybb->user['uid'] ?? 0) > 0);
    }

    $allowedIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $allowedRaw)))));
    if (!$allowedIds) {
        return ((int)($mybb->user['uid'] ?? 0) > 0);
    }

    $user = $mybb->user;

    $gids = [];
    $gids[] = (int)($user['usergroup'] ?? 0);

    $adds = trim((string)($user['additionalgroups'] ?? ''));
    if ($adds !== '') {
        foreach (explode(',', $adds) as $g) {
            $g = (int)trim($g);
            if ($g > 0) { $gids[] = $g; }
        }
    }

    foreach ($gids as $gid) {
        if (in_array((int)$gid, $allowedIds, true)) {
            return true;
        }
    }

    return false;
}


/**
 * Определяем master uid для текущего uid:
 * - если uid мастер — мастер = uid (есть привязки как master_uid)
 * - если uid attached — мастер = master_uid из links
 * - иначе мастер = uid
 */
function af_aas_get_master_uid(int $uid): int
{
    global $db;

    // 1) Если пользователь выступает как attached — найдём мастера
    $q = $db->simple_select(AF_AAS_TABLE_LINKS, 'master_uid', "attached_uid=" . (int)$uid, ['limit' => 1]);
    $row = $db->fetch_array($q);
    if (!empty($row['master_uid'])) {
        return (int)$row['master_uid'];
    }

    // 2) Если пользователь мастер — он и есть мастер
    return $uid;
}

/* ============================================================
   Rendering
============================================================ */
function af_aas_render_header_switcher(): string
{
    global $mybb, $templates;

    $uid = (int)$mybb->user['uid'];
    if ($uid <= 0) {
        return '';
    }

    $masterUid = af_aas_get_master_uid($uid);

    $accounts = af_aas_get_accounts_for_master($masterUid);

    // мастер тоже в список (первым)
    $masterUser = get_user($masterUid);
    if (!$masterUser) {
        return '';
    }

    $items = [];
    $items[] = [
        'uid'       => (int)$masterUser['uid'],
        'username'  => (string)$masterUser['username'],
        'is_master' => 1,
    ];
    foreach ($accounts as $a) {
        $items[] = [
            'uid'       => (int)$a['uid'],
            'username'  => (string)$a['username'],
            'is_master' => 0,
        ];
    }

    // нечего переключать — не показываем
    if (count($items) < 2) {
        return '';
    }

    // если шаблоны не установлены — тихо не ломаем страницу
    if (!is_object($templates)) {
        return '';
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $myPostKey = (string)$mybb->post_code;

    $af_aas_header_items = '';
    foreach ($items as $it) {
        $af_aas_uid = (int)$it['uid'];
        $af_aas_active_class = ($af_aas_uid === $uid) ? ' af-aas-active' : '';

        $label = htmlspecialchars_uni((string)$it['username']);
        if (!empty($it['is_master'])) {
            $label .= ' <span class="af-aas-badge">master</span>';
        }
        $af_aas_label = $label;

        $switchUrl = $bburl . '/misc.php?action=af_aas_switch&uid=' . (int)$af_aas_uid . '&my_post_key=' . urlencode($myPostKey);
        $af_aas_switch_url = htmlspecialchars_uni($switchUrl);

        eval('$af_aas_header_items .= "'.$templates->get('af_aas_header_item').'";');
    }

    $out = '';
    eval('$out = "'.$templates->get('af_aas_header_switcher').'";');
    return $out;
}


/* ============================================================
   Data: linked accounts
============================================================ */

function af_aas_get_accounts_for_master(int $masterUid): array
{
    global $db;

    $masterUid = (int)$masterUid;
    if ($masterUid <= 0) { return []; }

    $rows = [];
    $q = $db->query("
        SELECT l.attached_uid AS uid, u.username, l.is_hidden, l.display_order
        FROM " . TABLE_PREFIX . AF_AAS_TABLE_LINKS . " l
        LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid = l.attached_uid)
        WHERE l.master_uid = {$masterUid}
        ORDER BY l.display_order ASC, l.id ASC
    ");

    while ($r = $db->fetch_array($q)) {
        if (empty($r['uid']) || empty($r['username'])) {
            continue;
        }
        // скрытые не показываем в хедере
        if ((int)$r['is_hidden'] === 1) {
            continue;
        }
        $rows[] = [
            'uid'      => (int)$r['uid'],
            'username' => $r['username'],
        ];
    }

    return $rows;
}

/* ============================================================
   Switch
============================================================ */

function af_aas_handle_switch()
{
    global $mybb, $db, $session;

    if ((int)$mybb->user['uid'] <= 0) {
        error_no_permission();
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        error_no_permission();
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        error_no_permission();
    }

    verify_post_check($mybb->input['my_post_key'] ?? '');

    $currentUid = (int)$mybb->user['uid'];
    $targetUid  = (int)($mybb->input['uid'] ?? 0);

    if ($targetUid <= 0) {
        error('Некорректный UID для переключения.');
    }

    $masterUid = af_aas_get_master_uid($currentUid);

    // Разрешаем переключиться на мастера
    if ($targetUid !== $masterUid) {
        // Иначе — target должен быть attached у этого master
        $ok = (bool)$db->fetch_field(
            $db->simple_select(AF_AAS_TABLE_LINKS, 'id', "master_uid=" . (int)$masterUid . " AND attached_uid=" . (int)$targetUid, ['limit' => 1]),
            'id'
        );
        if (!$ok) {
            error_no_permission();
        }
    }

    // Теневая сессия: оставляем запись о прошлом uid, чтобы не исчезал мгновенно из онлайна
    if (!empty($mybb->settings['af_advancedaccountswitcher_shadow_session'])) {
        af_aas_create_shadow_session($currentUid);
    }

    // Меняем cookie mybbuser
    $targetUser = get_user($targetUid);
    if (!$targetUser || empty($targetUser['uid'])) {
        error('Целевой пользователь не найден.');
    }

    // Если включена защита — не даём переключиться на забаненного
    if (af_aas_is_user_banned((int)$targetUser['uid'])) {
        error('Нельзя переключиться на забаненный аккаунт.');
    }

    $loginkey = (string)($targetUser['loginkey'] ?? '');
    if ($loginkey === '') {
        error('У целевого аккаунта отсутствует loginkey.');
    }

    my_setcookie('mybbuser', (int)$targetUser['uid'] . '_' . $loginkey, null, true);

    // Обновляем текущую сессию в sessions на новый uid (чтобы эффект был сразу)
    if (!empty($session->sid)) {
        $db->update_query('sessions', ['uid' => (int)$targetUser['uid']], "sid='" . $db->escape_string($session->sid) . "'");
    }

    // Лог
    if (!empty($mybb->settings['af_advancedaccountswitcher_log_switches'])) {
        af_aas_log_switch((int)$mybb->user['uid'], $currentUid, (int)$targetUser['uid']);
    }

    // Редирект назад
    $return = (string)($mybb->input['return'] ?? '');
    if ($return !== '' && strpos($return, $mybb->settings['bburl']) === 0) {
        redirect($return, 'Переключено.');
    }

    $bburl = rtrim($mybb->settings['bburl'], '/');
    redirect($bburl . '/index.php', 'Переключено.');
}

function af_aas_create_shadow_session(int $uid)
{
    global $db, $session;

    $uid = (int)$uid;
    if ($uid <= 0) { return; }

    // минимальная “тень” — отдельная запись в sessions, которая умрёт по sessiontimeout
    $ip = get_ip();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $sid = md5(uniqid('af_aas', true));

    $data = [
        'sid'        => $db->escape_string($sid),
        'uid'        => $uid,
        'ip'         => $db->escape_string($ip),
        'time'       => TIME_NOW,
        'location'   => $db->escape_string('af_aas_shadow'),
        'useragent'  => $db->escape_string($ua),
        'anonymous'  => 0,
        'nopermission' => 0,
    ];

    // Удалим старые тени этого uid (чтоб не плодить мусор)
    $db->delete_query('sessions', "uid={$uid} AND location='af_aas_shadow'");

    $db->insert_query('sessions', $data);
}

function af_aas_log_switch(int $actorUid, int $fromUid, int $toUid)
{
    global $db;

    $ip = get_ip();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $uah = hash('sha256', $ua);

    $db->insert_query(AF_AAS_TABLE_LOG, [
        'actor_uid'      => (int)$actorUid,
        'from_uid'       => (int)$fromUid,
        'to_uid'         => (int)$toUid,
        'ip'             => $db->escape_string($ip),
        'useragent_hash' => $db->escape_string($uah),
        'created_at'     => TIME_NOW,
    ]);
}

/* ============================================================
   Suggest users (autocomplete)
============================================================ */

function af_aas_handle_user_suggest()
{
    global $mybb, $db;

    if ((int)$mybb->user['uid'] <= 0) {
        af_aas_json(['ok' => 0, 'error' => 'noauth'], 403);
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        af_aas_json(['ok' => 0, 'error' => 'disabled'], 403);
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        af_aas_json(['ok' => 0, 'error' => 'noperm'], 403);
    }

    $q = trim((string)($mybb->input['query'] ?? ''));
    if (mb_strlen($q) < 2) {
        af_aas_json(['ok' => 1, 'items' => []]);
    }

    $masterUid = af_aas_get_master_uid((int)$mybb->user['uid']);

    // исключаем самого мастера и уже привязанных
    $escaped = $db->escape_string_like($q);
    $sql = "
        SELECT u.uid, u.username
        FROM " . TABLE_PREFIX . "users u
        WHERE u.username LIKE '{$escaped}%'
          AND u.uid <> " . (int)$masterUid . "
          AND u.uid NOT IN (
              SELECT attached_uid FROM " . TABLE_PREFIX . AF_AAS_TABLE_LINKS . " WHERE master_uid=" . (int)$masterUid . "
          )
        ORDER BY u.username ASC
        LIMIT 10
    ";
    $res = $db->query($sql);

    $items = [];
    while ($r = $db->fetch_array($res)) {
        $items[] = [
            'uid'      => (int)$r['uid'],
            'username' => $r['username'],
        ];
    }

    af_aas_json(['ok' => 1, 'items' => $items]);
}

function af_aas_json(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   UCP page: create/link/unlink
============================================================ */
function af_aas_render_usercp_page()
{
    global $mybb, $db, $lang, $templates, $plugins;
    global $header, $headerinclude, $footer, $theme;
    global $usercpnav, $usercpmain;

    if ((int)$mybb->user['uid'] <= 0) {
        error_no_permission();
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        error_no_permission();
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        error_no_permission();
    }

    $uid       = (int)$mybb->user['uid'];
    $masterUid = af_aas_get_master_uid($uid);

    // Управление — только из мастера
    $isMasterContext = ($uid === $masterUid);

    // обработка POST
    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->input['my_post_key'] ?? '');

        $do = (string)($mybb->input['do'] ?? '');
        if ($do === 'create' && $isMasterContext) {
            af_aas_ucp_do_create($masterUid);
        } elseif ($do === 'link_existing' && $isMasterContext) {
            af_aas_ucp_do_link_existing($masterUid);
        } elseif ($do === 'unlink' && $isMasterContext) {
            af_aas_ucp_do_unlink($masterUid);
        }
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $af_aas_ucp_action = htmlspecialchars_uni($bburl . '/usercp.php?action=af_aas');
    $my_post_key = htmlspecialchars_uni((string)$mybb->post_code);

    // список привязанных
    $attached = af_aas_get_accounts_for_master($masterUid);
    $rowsHtml = '';

    $rowIndex = 0;
    foreach ($attached as $a) {
        $rowIndex++;
        $af_aas_row_class = ($rowIndex % 2 === 0) ? 'trow2' : 'trow1';

        $af_aas_uid = (int)$a['uid'];
        $af_aas_username = htmlspecialchars_uni((string)$a['username']);
        $af_aas_switch_url = htmlspecialchars_uni(
            $bburl . '/misc.php?action=af_aas_switch&uid=' . (int)$af_aas_uid . '&my_post_key=' . urlencode((string)$mybb->post_code)
        );

        $af_aas_unlink_form = '';
        if ($isMasterContext) {
            $af_aas_unlink_uid = (int)$af_aas_uid;
            eval('$af_aas_unlink_form = "' . $templates->get('af_aas_ucp_unlink_form') . '";');
        }

        eval('$rowsHtml .= "' . $templates->get('af_aas_ucp_row') . '";');
    }

    if ($rowsHtml === '') {
        $af_aas_list = '';
        eval('$af_aas_list = "' . $templates->get('af_aas_ucp_empty') . '";');
    } else {
        $af_aas_list = $rowsHtml;
    }

    // warning / формы
    $af_aas_warning = '&nbsp;';
    $af_aas_create_form = '';
    $af_aas_link_form = '';

    if (!$isMasterContext) {
        eval('$af_aas_warning = "' . $templates->get('af_aas_ucp_warning_not_master') . '";');
    } else {
        if (!empty($mybb->settings['af_advancedaccountswitcher_allow_create'])) {
            eval('$af_aas_create_form = "' . $templates->get('af_aas_ucp_form_create') . '";');
        }
        if (!empty($mybb->settings['af_advancedaccountswitcher_allow_link_existing'])) {
            eval('$af_aas_link_form = "' . $templates->get('af_aas_ucp_form_link') . '";');
        }
    }

    $af_aas_page_title = 'Дополнительные аккаунты';

    // Хлебные крошки как в MyBB
    add_breadcrumb($lang->nav_usercp ?? 'User CP', 'usercp.php');
    add_breadcrumb($af_aas_page_title, 'usercp.php?action=af_aas');

    // ---- ВАЖНО: стандартный UCP-лейаут ----
    // 1) Собираем дополнительные пункты UCP-меню через хук (как делает usercp.php)
    $usercpnav = '';
    if (is_object($plugins)) {
        $plugins->run_hooks('usercp_menu');
    }
    // 2) Оборачиваем навигацию стандартным шаблоном usercp_nav
    if (is_object($templates) && $templates->get('usercp_nav')) {
        eval('$usercpnav = "' . $templates->get('usercp_nav') . '";');
    }

    // 3) Основной контент страницы (наш шаблон)
    $usercpmain = '';
    eval('$usercpmain = "' . $templates->get('af_aas_ucp_page') . '";');

    // 4) Рендерим через стандартный шаблон usercp (если он есть), иначе fallback
    eval('$header = "' . $templates->get('header') . '";');
    eval('$footer = "' . $templates->get('footer') . '";');

    if (is_object($templates) && $templates->get('usercp')) {
        $page = '';
        eval('$page = "' . $templates->get('usercp') . '";');
        output_page($page);
    } else {
        // Очень мягкий fallback на случай кастомной темы без usercp-шаблона
        output_page($header . $usercpmain . $footer);
    }

    exit;
}



function af_aas_ucp_do_create(int $masterUid)
{
    global $mybb, $db;

    if (empty($mybb->settings['af_advancedaccountswitcher_allow_create'])) {
        error_no_permission();
    }

    $max = (int)($mybb->settings['af_advancedaccountswitcher_max_linked'] ?? 5);
    if ($max < 1) { $max = 1; }

    $cnt = (int)$db->fetch_field($db->simple_select(AF_AAS_TABLE_LINKS, 'COUNT(*) AS c', "master_uid=" . (int)$masterUid), 'c');
    if ($cnt >= $max) {
        error('Достигнут лимит дополнительных аккаунтов для этого мастер-аккаунта.');
    }

    $u  = trim((string)($mybb->input['new_username'] ?? ''));
    $p1 = (string)($mybb->input['new_password'] ?? '');
    $p2 = (string)($mybb->input['new_password2'] ?? '');

    if ($u === '' || $p1 === '' || $p2 === '') {
        error('Заполни все поля.');
    }
    if ($p1 !== $p2) {
        error('Пароли не совпадают.');
    }

    $masterUser = get_user($masterUid);
    if (!$masterUser) {
        error('Мастер-аккаунт не найден.');
    }

    require_once MYBB_ROOT . 'inc/datahandlers/user.php';
    $userhandler = new UserDataHandler('insert');

    // создаём без активации, email = мастер, группа = Registered (2)
    $userdata = [
        'username'       => $u,
        'password'       => $p1,
        'password2'      => $p2,
        'email'          => (string)$masterUser['email'],
        'usergroup'      => 2,
        'displaygroup'   => 0,
        'additionalgroups'=> '',
        'regip'          => get_ip(),
        'lastip'         => get_ip(),
        'regdate'        => TIME_NOW,
        'timeformat'     => (string)($masterUser['timeformat'] ?? ''),
        'dateformat'     => (string)($masterUser['dateformat'] ?? ''),
        'timezone'       => (string)($masterUser['timezone'] ?? ''),
        'dst'            => (int)($masterUser['dst'] ?? 0),
        'language'       => (string)($masterUser['language'] ?? ''),
    ];

    $userhandler->set_data($userdata);

    if (!$userhandler->validate_user()) {
        $errors = $userhandler->get_friendly_errors();
        error(implode('<br>', $errors));
    }

    $newUid = (int)$userhandler->insert_user();
    if ($newUid <= 0) {
        error('Не удалось создать аккаунт.');
    }

    // Привязываем
    af_aas_link_pair($masterUid, $newUid);

    $bburl = rtrim($mybb->settings['bburl'], '/');
    redirect($bburl . '/usercp.php?action=af_aas', 'Аккаунт создан и привязан.');
}

function af_aas_ucp_do_link_existing(int $masterUid)
{
    global $mybb, $db;

    if (empty($mybb->settings['af_advancedaccountswitcher_allow_link_existing'])) {
        error_no_permission();
    }

    $max = (int)($mybb->settings['af_advancedaccountswitcher_max_linked'] ?? 5);
    if ($max < 1) { $max = 1; }

    $cnt = (int)$db->fetch_field($db->simple_select(AF_AAS_TABLE_LINKS, 'COUNT(*) AS c', "master_uid=" . (int)$masterUid), 'c');
    if ($cnt >= $max) {
        error('Достигнут лимит дополнительных аккаунтов для этого мастер-аккаунта.');
    }

    $linkUid = (int)($mybb->input['link_uid'] ?? 0);
    $linkUsername = trim((string)($mybb->input['link_username'] ?? ''));
    $linkPassword = (string)($mybb->input['link_password'] ?? '');

    if ($linkUid <= 0 && $linkUsername !== '') {
        $u = get_user_by_username($linkUsername, ['fields' => 'uid']);
        if ($u && !empty($u['uid'])) {
            $linkUid = (int)$u['uid'];
        }
    }

    if ($linkUid <= 0 || $linkPassword === '') {
        error('Выбери аккаунт из списка и введи пароль.');
    }
    if ($linkUid === $masterUid) {
        error('Нельзя привязать мастер-аккаунт к самому себе.');
    }

    // проверим что он не уже привязан и не attached к другому мастеру
    $exists = (int)$db->fetch_field(
        $db->simple_select(AF_AAS_TABLE_LINKS, 'id', "attached_uid=" . (int)$linkUid, ['limit' => 1]),
        'id'
    );
    if ($exists > 0) {
        error('Этот аккаунт уже привязан (возможно к другому мастер-аккаунту).');
    }

    // проверяем пароль привязываемого аккаунта
    $user = get_user($linkUid);
    if (!$user) {
        error('Аккаунт не найден.');
    }
    require_once MYBB_ROOT . 'inc/functions_user.php';
    $ok = validate_password_from_uid($linkUid, $linkPassword);
    if (!$ok) {
        error('Пароль неверный.');
    }

    af_aas_link_pair($masterUid, $linkUid);

    $bburl = rtrim($mybb->settings['bburl'], '/');
    redirect($bburl . '/usercp.php?action=af_aas', 'Аккаунт привязан.');
}

function af_aas_ucp_do_unlink(int $masterUid)
{
    global $mybb, $db;

    $uid = (int)($mybb->input['uid'] ?? 0);
    if ($uid <= 0) {
        error('Некорректный UID.');
    }

    $db->delete_query(AF_AAS_TABLE_LINKS, "master_uid=" . (int)$masterUid . " AND attached_uid=" . (int)$uid);

    $bburl = rtrim($mybb->settings['bburl'], '/');
    redirect($bburl . '/usercp.php?action=af_aas', 'Аккаунт отвязан.');
}

function af_aas_link_pair(int $masterUid, int $attachedUid)
{
    global $db;

    $now = TIME_NOW;

    // display_order = max + 1
    $maxOrder = (int)$db->fetch_field(
        $db->simple_select(AF_AAS_TABLE_LINKS, 'MAX(display_order) AS m', "master_uid=" . (int)$masterUid),
        'm'
    );

    $db->insert_query(AF_AAS_TABLE_LINKS, [
        'master_uid'    => (int)$masterUid,
        'attached_uid'  => (int)$attachedUid,
        'is_secondary'  => 0,
        'is_shared'     => 0,
        'is_hidden'     => 0,
        'display_order' => $maxOrder + 1,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);
}

/* ============================================================
   PM notify -> master (advancedalertsandmentions integration)
============================================================ */

function af_aas_private_send_end()
{
    global $mybb, $db, $pmhandler;

    if (empty($mybb->settings['af_advancedaccountswitcher_pm_notify_master'])) {
        return;
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        return;
    }

    // Пытаемся вытащить получателей из pmhandler (MyBB стандарт)
    $toids = '';
    if (is_object($pmhandler) && !empty($pmhandler->pm_insert_data['toid'])) {
        $toids = (string)$pmhandler->pm_insert_data['toid'];
    }

    if ($toids === '') {
        return;
    }

    $recipients = array_filter(array_map('intval', explode(',', $toids)));
    if (!$recipients) { return; }

    foreach ($recipients as $rid) {
        // если rid — attached, то уведомляем мастера
        $masterUid = (int)$db->fetch_field(
            $db->simple_select(AF_AAS_TABLE_LINKS, 'master_uid', "attached_uid=" . (int)$rid, ['limit' => 1]),
            'master_uid'
        );
        if ($masterUid <= 0) {
            continue;
        }

        af_aas_send_aam_alert($masterUid, 'aas_pm_to_attached', [
            'attached_uid' => (int)$rid,
        ]);
    }
}

/**
 * Мягкая интеграция: если ваша система advancedalertsandmentions предоставляет API — используем его.
 * Если нет — не падаем, просто молча пропускаем.
 */
function af_aas_send_aam_alert(int $toUid, string $type, array $data = [])
{
    // Вариант 1: если у вас есть функция-API (подстроим позже под реальное имя)
    if (function_exists('af_aam_add_alert')) {
        @af_aam_add_alert($toUid, $type, $data);
        return;
    }
    if (function_exists('af_aam_create_alert')) {
        @af_aam_create_alert($toUid, $type, $data);
        return;
    }

    // Вариант 2: если таблицы существуют — можно будет дописать прямую вставку,
    // но без знания вашей схемы сейчас лучше не гадать и не ломать.
}

/* ============================================================
   Ban propagation / enforcement
============================================================ */

function af_aas_enforce_master_ban()
{
    global $mybb, $db;

    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        return;
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_ban_propagation'])) {
        return;
    }
    if ((int)$mybb->user['uid'] <= 0) {
        return;
    }

    $uid = (int)$mybb->user['uid'];
    $masterUid = af_aas_get_master_uid($uid);

    if ($masterUid <= 0) {
        return;
    }

    // если мастер забанен — баним attached
    if (af_aas_is_user_banned($masterUid)) {
        if ($uid !== $masterUid && !af_aas_is_user_banned($uid)) {
            af_aas_ban_user_like_master($uid, $masterUid);
        }
    }
}

function af_aas_is_user_banned(int $uid): bool
{
    global $db;

    $u = get_user($uid);
    if (!$u) { return false; }

    // В MyBB бан обычно = usergroup 7 + запись в mybb_banned
    if ((int)$u['usergroup'] === 7) {
        return true;
    }

    if ($db->table_exists('banned')) {
        $bid = (int)$db->fetch_field(
            $db->simple_select('banned', 'uid', "uid=" . (int)$uid, ['limit' => 1]),
            'uid'
        );
        return ($bid > 0);
    }

    return false;
}

function af_aas_ban_user_like_master(int $uid, int $masterUid)
{
    global $db;

    $user = get_user($uid);
    if (!$user) { return; }

    // Уже есть запись?
    if ($db->table_exists('banned')) {
        $exists = (int)$db->fetch_field($db->simple_select('banned', 'uid', "uid=" . (int)$uid, ['limit' => 1]), 'uid');
        if ($exists <= 0) {
            $db->insert_query('banned', [
                'uid'                => (int)$uid,
                'gid'                => 7,
                'oldgroup'           => (int)($user['usergroup'] ?? 2),
                'oldadditionalgroups'=> (string)($user['additionalgroups'] ?? ''),
                'olddisplaygroup'    => (int)($user['displaygroup'] ?? 0),
                'admin'              => 'system',
                'dateline'           => TIME_NOW,
                'bantime'            => '---', // перманент
                'lifted'             => 0,
                'reason'             => 'Master account is banned',
            ]);
        }
    }

    // Переводим в banned group
    $db->update_query('users', [
        'usergroup'        => 7,
        'displaygroup'     => 0,
        'additionalgroups' => '',
    ], "uid=" . (int)$uid);
}

/* ============================================================
   Settings & templates & task install helpers
============================================================ */

function af_aas_ensure_settings()
{
    global $db;

    // группа
    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='af_advancedaccountswitcher'", ['limit' => 1]),
        'gid'
    );

    if ($gid <= 0) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => 'af_advancedaccountswitcher',
            'title'       => 'AF: Advanced Account Switcher',
            'description' => 'Настройки дополнительных аккаунтов и переключения.',
            'disporder'   => 100,
            'isdefault'   => 0,
        ]);
    }

    $settings = [
        'af_advancedaccountswitcher_enabled' => [
            'title'       => 'Включить аддон',
            'description' => 'Включает функционал дополнительных аккаунтов и переключения.',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 1,
        ],
        'af_advancedaccountswitcher_allowed_groups' => [
            'title'       => 'Группы, которым доступны доп. аккаунты',
            'description' => 'Список ID групп через запятую. Пусто / "*" / "all" = всем авторизованным. Рекомендую: 2,4 (Registered+Admins).',
            'optionscode' => 'text',
            'value'       => '2,4',
            'disporder'   => 2,
        ],
        'af_advancedaccountswitcher_max_linked' => [
            'title'       => 'Максимум доп. аккаунтов',
            'description' => 'Сколько дополнительных аккаунтов можно привязать к мастер-аккаунту.',
            'optionscode' => 'numeric',
            'value'       => '5',
            'disporder'   => 3,
        ],
        'af_advancedaccountswitcher_allow_create' => [
            'title'       => 'Разрешить создание доп. аккаунтов',
            'description' => 'Мастер может создать новый дополнительный аккаунт прямо из UCP (без активации).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 4,
        ],
        'af_advancedaccountswitcher_allow_link_existing' => [
            'title'       => 'Разрешить привязку существующих аккаунтов',
            'description' => 'Мастер может привязать уже существующий аккаунт (username + пароль).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 5,
        ],
        'af_advancedaccountswitcher_ui_header' => [
            'title'       => 'Показывать переключатель в шапке',
            'description' => 'Вставляет переключатель в шапку (через pre_output_page).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 6,
        ],
        'af_advancedaccountswitcher_log_switches' => [
            'title'       => 'Логировать переключения',
            'description' => 'Пишет переключения в таблицу логов.',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 7,
        ],
        'af_advancedaccountswitcher_ban_propagation' => [
            'title'       => 'Распространять бан мастера на доп. аккаунты',
            'description' => 'Если мастер забанен — доп. аккаунты будут заблокированы/забанены.',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 8,
        ],
        'af_advancedaccountswitcher_shadow_session' => [
            'title'       => 'Оставлять “теневую” сессию предыдущего аккаунта',
            'description' => 'Создаёт отдельную запись в sessions (умирает по стандартному sessiontimeout).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 9,
        ],
        'af_advancedaccountswitcher_pm_notify_master' => [
            'title'       => 'Уведомлять мастера о ЛС на доп. аккаунты',
            'description' => 'Через advancedalertsandmentions (если доступно).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 10,
        ],
        'af_advancedaccountswitcher_nav_account_list' => [
        'title'       => 'Показывать ссылку Account list в навигации',
        'description' => 'Добавляет ссылку “Account list” рядом с “Список участников”.',
        'optionscode' => 'yesno',
        'value'       => '1',
        'disporder'   => 11,
        ],
        
    ];

    foreach ($settings as $name => $s) {
        $sid = (int)$db->fetch_field($db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]), 'sid');
        $row = [
            'name'        => $name,
            'title'       => $db->escape_string($s['title']),
            'description' => $db->escape_string($s['description']),
            'optionscode' => $db->escape_string($s['optionscode']),
            'value'       => $db->escape_string($s['value']),
            'disporder'   => (int)$s['disporder'],
            'gid'         => (int)$gid,
        ];

        if ($sid > 0) {
            $db->update_query('settings', $row, "sid=" . (int)$sid);
        } else {
            $db->insert_query('settings', $row);
        }
    }
}

function af_aas_remove_settings()
{
    global $db;

    $db->delete_query('settings', "name LIKE 'af_advancedaccountswitcher_%'");
    $db->delete_query('settinggroups', "name='af_advancedaccountswitcher'");
}

function af_aas_install_templates()
{
    global $db;

    $path = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/templates/advancedaccountswitcher.html';
    if (!file_exists($path)) {
        return;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return;
    }

    // Парсер: <!-- TEMPLATE: name --> ... <!-- /TEMPLATE -->
    preg_match_all('~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-]+)\s*-->\s*(.*?)\s*<!--\s*/TEMPLATE\s*-->~s', $raw, $m, PREG_SET_ORDER);
    if (!$m) {
        return;
    }

    foreach ($m as $tpl) {
        $title = trim((string)$tpl[1]);
        $html  = trim((string)$tpl[2]);

        // строго только наши шаблоны (чтобы remove_templates работал и не было мусора)
        if ($title === '' || $html === '' || strpos($title, 'af_aas_') !== 0) {
            continue;
        }

        $exists = (int)$db->fetch_field(
            $db->simple_select('templates', 'tid', "title='" . $db->escape_string($title) . "'", ['limit' => 1]),
            'tid'
        );

        $row = [
            'title'    => $db->escape_string($title),
            'template' => $db->escape_string($html),
            'sid'      => -2,
            'version'  => '1800',
            'dateline' => TIME_NOW,
        ];

        if ($exists > 0) {
            $db->update_query('templates', $row, "tid=" . (int)$exists);
        } else {
            $db->insert_query('templates', $row);
        }
    }
}

function af_aas_remove_templates()
{
    global $db;
    $db->delete_query('templates', "title LIKE 'af_aas_%'");
}

function af_aas_install_task()
{
    global $db;

    // 1) источник внутри аддона
    $src = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/tasks/task_af_aas_cleanup.php';

    // 2) назначение — стандарт MyBB
    $dstDir = MYBB_ROOT . 'inc/tasks';
    $dst    = $dstDir . '/task_af_aas_cleanup.php';

    if (!is_dir($dstDir)) {
        @mkdir($dstDir, 0755, true);
    }

    // если исходника нет — не роняем установку, но и таск не ставим
    if (!file_exists($src)) {
        return;
    }

    $srcCode = file_get_contents($src);
    if ($srcCode === false || trim($srcCode) === '') {
        return;
    }

    // копируем/обновляем, если отличается
    $needWrite = true;
    if (file_exists($dst)) {
        $dstCode = file_get_contents($dst);
        if ($dstCode !== false && hash('sha256', $dstCode) === hash('sha256', $srcCode)) {
            $needWrite = false;
        }
    }

    if ($needWrite) {
        @file_put_contents($dst, $srcCode);
    }

    // 3) запись в tasks
    $exists = (int)$db->fetch_field(
        $db->simple_select('tasks', 'tid', "file='af_aas_cleanup'", ['limit' => 1]),
        'tid'
    );

    if ($exists <= 0) {
        $db->insert_query('tasks', [
            'title'       => 'AF AAS cleanup',
            'description' => 'Cleanup для Advanced Account Switcher: тени-сессии, битые связи.',
            'file'        => 'af_aas_cleanup',
            'minute'      => '0',
            'hour'        => '3',
            'day'         => '*',
            'month'       => '*',
            'weekday'     => '*',
            'enabled'     => 1,
            'logging'     => 1,
        ]);
    }
}
function af_aas_usercp_menu()
{
    global $mybb, $usercpnav;

    if ((int)$mybb->user['uid'] <= 0) {
        return;
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        return;
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        return;
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $url = $bburl . '/usercp.php?action=af_aas';

    // стандартный стиль UCP-меню (табличный)
    $usercpnav .= '<tr><td class="trow1 smalltext">'
        . '<a href="' . htmlspecialchars_uni($url) . '">👥 Дополнительные аккаунты</a>'
        . '</td></tr>';
}

function af_aas_get_avatar_url(int $uid): string
{
    global $mybb;

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $u = get_user($uid);
    $avatar = (string)($u['avatar'] ?? '');

    if ($avatar === '') {
        return $bburl . '/images/default_avatar.png';
    }

    // абсолютный
    if (preg_match('~^https?://~i', $avatar)) {
        return $avatar;
    }

    // относительный (uploads/avatars/...)
    if ($avatar[0] === '/') {
        return $bburl . $avatar;
    }

    return $bburl . '/' . ltrim($avatar, '/');
}

function af_aas_render_panel_widget(): string
{
    global $mybb, $templates;

    if (!is_object($templates)) {
        return '';
    }

    $uid = (int)$mybb->user['uid'];
    if ($uid <= 0) {
        return '';
    }

    $masterUid = af_aas_get_master_uid($uid);
    $masterUser = get_user($masterUid);
    if (!$masterUser) {
        return '';
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // формируем список: мастер + привязанные
    $items = [];
    $items[] = [
        'uid'       => (int)$masterUser['uid'],
        'username'  => (string)$masterUser['username'],
        'is_master' => 1,
    ];

    $attached = af_aas_get_accounts_for_master($masterUid);
    foreach ($attached as $a) {
        $items[] = [
            'uid'       => (int)$a['uid'],
            'username'  => (string)$a['username'],
            'is_master' => 0,
        ];
    }

    // если нет переключения И нет смысла показывать — можно скрыть
    $canManage = (!empty($mybb->settings['af_advancedaccountswitcher_allow_create']) || !empty($mybb->settings['af_advancedaccountswitcher_allow_link_existing']));
    if (count($items) < 2 && !$canManage) {
        return '';
    }

    $af_aas_ucp_url = htmlspecialchars_uni($bburl . '/usercp.php?action=af_aas');
    $af_aas_account_list_url = htmlspecialchars_uni($bburl . '/misc.php?action=af_aas_account_list');
    $myPostKey = (string)$mybb->post_code;

    $af_aas_panel_rows = '';
    $i = 0;
    foreach ($items as $it) {
        $i++;
        $af_aas_row_class = ($i % 2 === 0) ? 'trow2' : 'trow1';
        $af_aas_item_uid = (int)$it['uid'];
        $af_aas_item_username = htmlspecialchars_uni((string)$it['username']);
        $af_aas_item_avatar_url = htmlspecialchars_uni(af_aas_get_avatar_url($af_aas_item_uid));
        $af_aas_item_avatar = '<span class="af-aas-avatar"><img src="' . $af_aas_item_avatar_url . '" alt="" /></span>';

        $switchUrl = $bburl . '/misc.php?action=af_aas_switch&uid=' . (int)$af_aas_item_uid . '&my_post_key=' . urlencode($myPostKey);
        $af_aas_item_switch_url = htmlspecialchars_uni($switchUrl);

        $af_aas_item_badge = !empty($it['is_master']) ? '<span class="af-aas-badge">master</span>' : '';
        $af_aas_item_active = ($af_aas_item_uid === $uid) ? ' af-aas-active' : '';

        eval('$af_aas_panel_rows .= "'.$templates->get('af_aas_panel_row').'";');
    }

    if ($af_aas_panel_rows === '') {
        eval('$af_aas_panel_rows = "'.$templates->get('af_aas_panel_empty').'";');
    }

    $out = '';
    eval('$out = "'.$templates->get('af_aas_panel_widget').'";');
    return $out;
}

function af_aas_render_account_list_page()
{
    global $mybb, $db, $templates, $header, $footer, $theme, $headerinclude;

    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        error_no_permission();
    }

    // Я бы не светил это гостям: список связок = чувствительная штука
    if ((int)$mybb->user['uid'] <= 0) {
        error_no_permission();
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        error_no_permission();
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // пагинация по мастерам
    $perPage = 50;
    $pageNum = max(1, (int)($mybb->input['page'] ?? 1));
    $start   = ($pageNum - 1) * $perPage;

    // total distinct masters
    $total = (int)$db->fetch_field(
        $db->query("SELECT COUNT(DISTINCT master_uid) AS c FROM " . TABLE_PREFIX . AF_AAS_TABLE_LINKS),
        'c'
    );

    // master_uid list
    $masters = [];
    $res = $db->query("
        SELECT DISTINCT master_uid
        FROM " . TABLE_PREFIX . AF_AAS_TABLE_LINKS . "
        ORDER BY master_uid ASC
        LIMIT " . (int)$start . ", " . (int)$perPage . "
    ");
    while ($r = $db->fetch_array($res)) {
        $masters[] = (int)$r['master_uid'];
    }

    // groups data
    $groups = []; // master_uid => [attached...]
    if ($masters) {
        $in = implode(',', array_map('intval', $masters));
        $res2 = $db->query("
            SELECT l.master_uid, l.attached_uid, um.username AS master_name, ua.username AS attached_name
            FROM " . TABLE_PREFIX . AF_AAS_TABLE_LINKS . " l
            LEFT JOIN " . TABLE_PREFIX . "users um ON (um.uid = l.master_uid)
            LEFT JOIN " . TABLE_PREFIX . "users ua ON (ua.uid = l.attached_uid)
            WHERE l.master_uid IN ({$in})
            ORDER BY l.master_uid ASC, ua.username ASC
        ");
        while ($r = $db->fetch_array($res2)) {
            $muid = (int)$r['master_uid'];
            if (!isset($groups[$muid])) {
                $groups[$muid] = [
                    'master_uid'  => $muid,
                    'master_name' => (string)($r['master_name'] ?? ('#' . $muid)),
                    'attached'    => [],
                ];
            }
            $groups[$muid]['attached'][] = [
                'uid'      => (int)$r['attached_uid'],
                'username' => (string)($r['attached_name'] ?? ('#' . (int)$r['attached_uid'])),
            ];
        }
    }

    // MyBB-style pagination
    $baseUrl   = $bburl . '/misc.php?action=af_aas_account_list';
    $multipage = '';
    if ($total > $perPage) {
        $multipage = multipage($total, $perPage, $pageNum, $baseUrl);
    }

    add_breadcrumb($title, 'misc.php?action=af_aas_account_list');

    // table rows with trow1/trow2
    $rowsHtml = '';
    $i = 0;

    foreach ($groups as $g) {
        $i++;
        $af_aas_row_class = ($i % 2 === 0) ? 'trow2' : 'trow1';

        $af_aas_master_uid = (int)$g['master_uid'];
        $af_aas_master_name = htmlspecialchars_uni((string)$g['master_name']);
        $af_aas_master_url = htmlspecialchars_uni($bburl . '/member.php?action=profile&uid=' . $af_aas_master_uid);

        $af_aas_attached_list = '';
        foreach ($g['attached'] as $a) {
            $auid = (int)$a['uid'];
            $aname = htmlspecialchars_uni((string)$a['username']);
            $aurl = htmlspecialchars_uni($bburl . '/member.php?action=profile&uid=' . $auid);
            $af_aas_attached_list .= '<a href="' . $aurl . '">' . $aname . '</a>, ';
        }
        $af_aas_attached_list = rtrim($af_aas_attached_list, ', ');
        if ($af_aas_attached_list === '') {
            $af_aas_attached_list = '<em>—</em>';
        }

        eval('$rowsHtml .= "' . $templates->get('af_aas_account_list_row') . '";');
    }

    if ($rowsHtml === '') {
        $rowsHtml = '
            <tr>
                <td class="trow1" colspan="2"><em>Пока нет привязок.</em></td>
            </tr>
        ';
    }

    $title = 'Account list';
    add_breadcrumb($title, 'misc.php?action=af_aas_account_list');

    eval('$header = "' . $templates->get('header') . '";');
    eval('$footer = "' . $templates->get('footer') . '";');

    $borderwidth = isset($theme['borderwidth']) ? (int)$theme['borderwidth'] : 1;
    $tablespace  = isset($theme['tablespace']) ? (int)$theme['tablespace'] : 6;

    $af_aas_page_title = $title;
    $af_aas_account_rows = $rowsHtml;

    if ($af_aas_account_rows === '') {
        $af_aas_account_rows = '
            <tr>
                <td class="trow1" colspan="2"><em>Пока нет привязок.</em></td>
            </tr>
        ';
    }

    eval('$header = "' . $templates->get('header') . '";');
    eval('$footer = "' . $templates->get('footer') . '";');
    eval('$headerinclude = "' . $templates->get('headerinclude') . '";');

    eval('$page = "' . $templates->get('af_aas_account_list_page') . '";');
    output_page($page);
    exit;
}
