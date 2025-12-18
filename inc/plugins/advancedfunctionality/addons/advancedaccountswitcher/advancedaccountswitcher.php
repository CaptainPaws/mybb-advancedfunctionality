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
define('AF_AAS_TABLE_AUDIT', 'af_aas_audit_log');
define('AF_AAS_LOG_LIMIT', 5000);


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

    // NEW: audit log (create/link/unlink)
    if (!$db->table_exists(AF_AAS_TABLE_AUDIT)) {
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AAS_TABLE_AUDIT . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                action VARCHAR(16) NOT NULL DEFAULT '',
                actor_uid INT UNSIGNED NOT NULL DEFAULT 0,
                master_uid INT UNSIGNED NOT NULL DEFAULT 0,
                attached_uid INT UNSIGNED NOT NULL DEFAULT 0,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                useragent_hash CHAR(64) NOT NULL DEFAULT '',
                created_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY action (action),
                KEY actor_uid (actor_uid),
                KEY master_uid (master_uid),
                KEY attached_uid (attached_uid),
                KEY created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // 1.5) Поле приватности в users: скрывать мастер/привязки в публичном списке
    if (method_exists($db, 'field_exists')) {
        if (!$db->field_exists('af_aas_hide_in_list', 'users')) {
            $db->add_column('users', 'af_aas_hide_in_list', "TINYINT(1) NOT NULL DEFAULT 0");
        }
    } else {
        $has = false;
        $q = $db->write_query("SHOW COLUMNS FROM " . TABLE_PREFIX . "users LIKE 'af_aas_hide_in_list'");
        if ($q && $db->num_rows($q) > 0) { $has = true; }
        if (!$has) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . "users ADD COLUMN af_aas_hide_in_list TINYINT(1) NOT NULL DEFAULT 0");
        }
    }

    // 2) Настройки
    af_aas_ensure_settings();

    // 3) Шаблоны (берём из templates/advancedaccountswitcher.html)
    af_aas_install_templates();

    // 4) Таск cleanup
    af_aas_install_task();

    // 4.5) userlist.php alias (в корень форума)
    af_aas_install_userlist_alias();

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
    if ($db->table_exists(AF_AAS_TABLE_AUDIT)) {
        $db->drop_table(AF_AAS_TABLE_AUDIT);
    }

    // Настройки + группа
    af_aas_remove_settings();

    // Шаблоны
    af_aas_remove_templates();

    // Таск: удаляем запись из tasks
    $db->delete_query('tasks', "file='af_aas_cleanup'");

    // userlist.php alias (удаляем только если он наш)
    af_aas_remove_userlist_alias();

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

    global $plugins, $mybb;

    // ВАЖНО: ставим pre_output_page ПОЗЖЕ остальных аддонов,
    // чтобы нас не "перетирали" чужие pre_output-обработчики.
    $plugins->add_hook('pre_output_page', 'af_advancedaccountswitcher_pre_output', 50);

    // UCP entry
    $plugins->add_hook('usercp_start', 'af_aas_usercp_dispatch');

    // Добавляем пункт в левое меню UCP
    $plugins->add_hook('usercp_menu', 'af_aas_usercp_menu');

    // misc entry (switch + suggest + account list)
    $plugins->add_hook('misc_start', 'af_aas_misc_dispatch');

    // PM notify (если включено)
    $plugins->add_hook('private_send_end', 'af_aas_private_send_end');

    // Антиабьюз: если бан мастера — режем доп. аккаунты
    $plugins->add_hook('global_start', 'af_aas_enforce_master_ban');

    // Автохил алиаса: только для админа (cancp=1) и только если файла нет
    if (!defined('IN_ADMINCP')
        && !empty($mybb->usergroup)
        && (int)($mybb->usergroup['cancp'] ?? 0) === 1
        && !file_exists(MYBB_ROOT . 'userlist.php')
    ) {
        af_aas_install_userlist_alias();
    }
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

    // 0) Никаких инъекций на странице редиректа (чистая страница без ссылок)
    $isRedirect =
        (stripos($page, '<meta http-equiv="refresh"') !== false) ||
        (stripos($page, 'class="redirect"') !== false) ||
        (stripos($page, 'id="redirect"') !== false) ||
        (stripos($page, 'mybb_redirect') !== false);

    if ($isRedirect) {
        return;
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // Алиас-URL для "Пользователи"
    $prettyUserlistUrl = $bburl . '/userlist.php';
    $newHref = htmlspecialchars_uni($prettyUserlistUrl);

    // ассеты (версию подняли намеренно, чтобы пробить кэш)
    $css = $bburl . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/advancedaccountswitcher.css';
    $js  = $bburl . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/advancedaccountswitcher.js';

    if (strpos($page, 'advancedaccountswitcher.css') === false) {
        $page = str_replace(
            '</head>',
            '<link rel="stylesheet" href="' . htmlspecialchars_uni($css) . '?v=1.1.10" />' . "\n</head>",
            $page
        );
    }
    if (strpos($page, 'advancedaccountswitcher.js') === false) {
        $page = str_replace(
            '</body>',
            '<script src="' . htmlspecialchars_uni($js) . '?v=1.1.10"></script>' . "\n</body>",
            $page
        );
    }

    // ====== 1) Вставка 👥 рядом с usercp (только если включено в настройках + для авторизованных + разрешённых)
    if (!empty($mybb->settings['af_advancedaccountswitcher_ui_header'])
        && (int)$mybb->user['uid'] > 0
        && af_aas_user_allowed((int)$mybb->user['uid'])
    ) {
        if (strpos($page, '<!--af_aas_panel_icon-->') === false) {
            $widget = af_aas_render_panel_widget();
            if ($widget !== '') {

                // сначала пытаемся встать рядом с ссылкой на usercp
                $re = '~(<a[^>]+href=["\'][^"\']*usercp\.php[^"\']*["\'][^>]*>.*?</a>)~is';

                if (preg_match($re, $page)) {
                    $page = preg_replace($re, '$1' . "\n" . '<!--af_aas_panel_icon-->' . "\n" . $widget, $page, 1);
                } else {
                    // fallback: вставляем сразу после <body>
                    if (stripos($page, '<body') !== false) {
                        $page = preg_replace('~(<body[^>]*>)~i', '$1' . "\n" . '<!--af_aas_panel_icon-->' . "\n" . $widget . "\n", $page, 1);
                    } else {
                        $page = '<!--af_aas_panel_icon-->' . "\n" . $widget . "\n" . $page;
                    }
                }
            }
        }
    }

    // ====== 2) Навигация: МЕНЯЕМ memberlist.php -> userlist.php ВЕЗДЕ В HTML (не только до #content)
    $page = preg_replace_callback(
        '~<a\b([^>]*?)\bhref\s*=\s*(["\'])([^"\']*memberlist\.php[^"\']*)\2([^>]*)>~i',
        function ($m) use ($newHref) {
            // Уже меняли?
            if (stripos($m[0], 'data-af-aas-nav=') !== false) {
                return $m[0];
            }

            $before = $m[1];
            $hrefVal = $m[3];
            $after = $m[4];

            // Если это не memberlist.php (на всякий пожарный)
            if (stripos($hrefVal, 'memberlist.php') === false) {
                return $m[0];
            }

            // Чисто меняем href, остальное оставляем как было
            // Вставляем маркер data-af-aas-nav чтобы не трогать повторно
            return '<a' . $before . ' href="' . $newHref . '" data-af-aas-nav="1"' . $after . '>';
        },
        $page
    );
}


/*============================================================
   Dispatchers
============================================================*/
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

    if (defined('THIS_SCRIPT') && THIS_SCRIPT !== 'usercp.php') {
        return;
    }

    // В MyBB правильно брать action через get_input
    $action = $mybb->get_input('action');

    if ($action !== 'af_aas') {
        return;
    }

    af_aas_render_usercp_page();
    exit;
}


/* ============================================================
   Core checks
============================================================ */
function af_aas_user_allowed(int $uid): bool
{
    global $mybb;

    // ФЕЙЛСЕЙФ: админы должны видеть интерфейс всегда, иначе невозможно отлаживать
    if (!empty($mybb->usergroup) && (int)($mybb->usergroup['cancp'] ?? 0) === 1) {
        return ((int)($mybb->user['uid'] ?? 0) > 0);
    }

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

    // мастер
    $masterUser = get_user($masterUid);
    if (!$masterUser) {
        return '';
    }

    // собираем все (мастер + attached), но на выводе исключим текущий uid
    $all = [];
    $all[] = [
        'uid'       => (int)$masterUser['uid'],
        'username'  => (string)$masterUser['username'],
        'is_master' => 1,
    ];

    foreach ($accounts as $a) {
        $all[] = [
            'uid'       => (int)$a['uid'],
            'username'  => (string)$a['username'],
            'is_master' => 0,
        ];
    }

    // фильтр: убираем текущий аккаунт из списка
    $items = [];
    foreach ($all as $it) {
        if ((int)$it['uid'] === $uid) {
            continue;
        }
        $items[] = $it;
    }

    // нечего переключать — не показываем
    if (count($items) < 1) {
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

        // текущего уже нет, значит active всегда пустой
        $af_aas_active_class = '';

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

function af_aas_get_linked_accounts(int $masterUid, bool $includeHidden = true): array
{
    global $db;

    $masterUid = (int)$masterUid;
    if ($masterUid <= 0) {
        return [];
    }

    $whereHidden = '';
    if (!$includeHidden) {
        $whereHidden = " AND l.is_hidden=0";
    }

    $rows = [];
    $q = $db->query("
        SELECT
            l.attached_uid AS uid,
            u.username,
            l.is_hidden,
            l.display_order,
            l.id
        FROM " . TABLE_PREFIX . AF_AAS_TABLE_LINKS . " l
        LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid = l.attached_uid)
        WHERE l.master_uid = {$masterUid}
        {$whereHidden}
        ORDER BY l.display_order ASC, l.id ASC
    ");

    while ($r = $db->fetch_array($q)) {
        $uid = (int)($r['uid'] ?? 0);
        $un  = (string)($r['username'] ?? '');

        if ($uid <= 0 || $un === '') {
            continue;
        }

        $rows[] = [
            'uid'      => $uid,
            'username' => $un,
            'is_hidden'=> (int)($r['is_hidden'] ?? 0),
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

    af_aas_prune_log_table(AF_AAS_TABLE_LOG, AF_AAS_LOG_LIMIT);
}


function af_aas_prune_log_table(string $table, int $limit = AF_AAS_LOG_LIMIT): void
{
    global $db;

    $limit = (int)$limit;
    if ($limit < 1) {
        return;
    }

    if (!$db->table_exists($table)) {
        return;
    }

    $count = (int)$db->fetch_field($db->simple_select($table, 'COUNT(*) AS c'), 'c');
    if ($count <= $limit) {
        return;
    }

    $excess = $count - $limit;

    // MySQL: DELETE ... ORDER BY ... LIMIT ...
    $db->write_query("
        DELETE FROM " . TABLE_PREFIX . $table . "
        ORDER BY id ASC
        LIMIT " . (int)$excess . "
    ");
}

function af_aas_audit_log(string $action, int $actorUid, int $masterUid, int $attachedUid): void
{
    global $db;

    $action = trim((string)$action);
    if ($action === '') {
        return;
    }

    if (!$db->table_exists(AF_AAS_TABLE_AUDIT)) {
        return;
    }

    $ip = get_ip();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $uah = hash('sha256', $ua);

    $db->insert_query(AF_AAS_TABLE_AUDIT, [
        'action'         => $db->escape_string($action),
        'actor_uid'      => (int)$actorUid,
        'master_uid'     => (int)$masterUid,
        'attached_uid'   => (int)$attachedUid,
        'ip'             => $db->escape_string($ip),
        'useragent_hash' => $db->escape_string($uah),
        'created_at'     => TIME_NOW,
    ]);

    af_aas_prune_log_table(AF_AAS_TABLE_AUDIT, AF_AAS_LOG_LIMIT);
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
    global $mybb, $db, $lang, $templates, $theme;
    global $headerinclude, $header, $footer, $usercpnav;

    if ((int)($mybb->user['uid'] ?? 0) <= 0) {
        error_no_permission();
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        error_no_permission();
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        error_no_permission();
    }

    if (!isset($lang->af_aas_ucp_title) || trim((string)$lang->af_aas_ucp_title) === '') {
        $lang->af_aas_ucp_title = 'Дополнительные аккаунты';
    }

    require_once MYBB_ROOT . 'inc/functions_user.php';
    $usercpnav = usercp_menu();

    // гарантируем header/include/footer для полного шаблона
    if (is_object($templates)) {
        if (empty($headerinclude)) { eval('$headerinclude = "'.$templates->get('headerinclude').'";'); }
        if (empty($header))        { eval('$header        = "'.$templates->get('header').'";'); }
        if (empty($footer))        { eval('$footer        = "'.$templates->get('footer').'";'); }
    }

    $uid       = (int)$mybb->user['uid'];
    $masterUid = af_aas_get_master_uid($uid);
    $isMaster  = ($uid === $masterUid);

    add_breadcrumb($lang->nav_usercp ?? 'Панель управления пользователя', 'usercp.php');
    add_breadcrumb($lang->af_aas_ucp_title, 'usercp.php?action=af_aas');

    $bburl             = rtrim((string)$mybb->settings['bburl'], '/');
    $af_aas_page_title = $lang->af_aas_ucp_title;
    $af_aas_ucp_action = $bburl . '/usercp.php?action=af_aas';
    $my_post_key       = (string)$mybb->post_code;

    // ===== POST actions (добавили privacy)
    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $do = (string)$mybb->get_input('do');

        // 0) privacy можно сохранять любому аккаунту (не только мастеру)
        if ($do === 'save_privacy') {
            $val = (int)$mybb->get_input('hide_in_list'); // 1/0
            $val = ($val === 1) ? 1 : 0;

            // если поля ещё нет (вдруг), не падаем
            if (method_exists($db, 'field_exists') && $db->field_exists('af_aas_hide_in_list', 'users')) {
                $db->update_query('users', ['af_aas_hide_in_list' => $val], "uid=" . (int)$uid);
                $mybb->user['af_aas_hide_in_list'] = $val;
            }

            redirect($bburl . '/usercp.php?action=af_aas', 'Настройка приватности сохранена.');
        }

        // 1) остальное — только мастер
        if ($do !== '') {
            if (!$isMaster) {
                error_no_permission();
            }

            if ($do === 'create') {
                af_aas_ucp_do_create($masterUid);
            } elseif ($do === 'link_existing') {
                af_aas_ucp_do_link_existing($masterUid);
            } elseif ($do === 'unlink') {
                af_aas_ucp_do_unlink($masterUid);
            }
        }
    }

    // Warning (если не мастер)
    $af_aas_warning = '';
    if (!$isMaster && is_object($templates) && $templates->get('af_aas_ucp_warning_not_master') !== '') {
        eval('$af_aas_warning = "'.$templates->get('af_aas_ucp_warning_not_master').'";');
    }

    // ===== Список: мастер + attached =====
    $items = [];

    $masterUser = get_user($masterUid);
    if ($masterUser && !empty($masterUser['uid'])) {
        $items[] = [
            'uid'       => (int)$masterUser['uid'],
            'username'  => (string)$masterUser['username'],
            'is_master' => 1,
        ];
    }

    $linked = [];
    if (function_exists('af_aas_get_linked_accounts')) {
        $linked = af_aas_get_linked_accounts($masterUid, true);
    }

    foreach ($linked as $row) {
        $aUid = (int)($row['uid'] ?? 0);
        $aUn  = (string)($row['username'] ?? '');
        if ($aUid <= 0 || $aUn === '') continue;

        $items[] = [
            'uid'       => $aUid,
            'username'  => $aUn,
            'is_master' => 0,
        ];
    }

    $af_aas_list = '';
    $i = 0;

    foreach ($items as $it) {
        // не показываем текущий аккаунт
        if ((int)$it['uid'] === $uid) {
            continue;
        }

        $i++;
        $af_aas_row_class = ($i % 2 === 0) ? 'trow2' : 'trow1';

        $af_aas_uid      = (int)$it['uid'];
        $af_aas_username = htmlspecialchars_uni((string)$it['username']);

        if ($af_aas_uid <= 0 || $af_aas_username === '') {
            continue;
        }

        // мини-аватар 24x24
        $avatarUrl = htmlspecialchars_uni(af_aas_get_avatar_url($af_aas_uid));
        $af_aas_miniavatar = '<span class="af-aas-miniavatar"><img src="' . $avatarUrl . '" alt="" width="24" height="24" loading="lazy"></span>';

        $returnUrl = $bburl . '/usercp.php?action=af_aas';
        $af_aas_switch_url = htmlspecialchars_uni(
            $bburl . '/misc.php?action=af_aas_switch'
            . '&uid=' . $af_aas_uid
            . '&my_post_key=' . urlencode($my_post_key)
            . '&return=' . urlencode($returnUrl)
        );

        $af_aas_unlink_form = '';
        if ($isMaster && empty($it['is_master']) && is_object($templates) && $templates->get('af_aas_ucp_unlink_form') !== '') {
            $af_aas_unlink_uid = $af_aas_uid;
            eval('$af_aas_unlink_form = "'.$templates->get('af_aas_ucp_unlink_form').'";');
        }

        if (is_object($templates) && $templates->get('af_aas_ucp_row') !== '') {
            eval('$af_aas_list .= "'.$templates->get('af_aas_ucp_row').'";');
        } else {
            $af_aas_list .= '<tr>
                <td class="'.$af_aas_row_class.'">'
                    . $af_aas_miniavatar .
                    '<strong>'.$af_aas_username.'</strong> <span class="smalltext">(#'.$af_aas_uid.')</span>
                </td>
                <td class="'.$af_aas_row_class.'" style="text-align:right;">
                    <a class="button button_small" href="'.$af_aas_switch_url.'">Переключиться</a>
                    '.$af_aas_unlink_form.'
                </td>
            </tr>';
        }
    }

    if (trim($af_aas_list) === '') {
        if (is_object($templates) && $templates->get('af_aas_ucp_empty') !== '') {
            eval('$af_aas_list = "'.$templates->get('af_aas_ucp_empty').'";');
        } else {
            $af_aas_list = '<tr><td class="trow1" colspan="2">Пока нет других доступных аккаунтов.</td></tr>';
        }
    }

    // ===== Формы — только мастер
    $af_aas_create_form = '';
    $af_aas_link_form   = '';

    if ($isMaster) {
        if (!empty($mybb->settings['af_advancedaccountswitcher_allow_create'])
            && is_object($templates) && $templates->get('af_aas_ucp_form_create') !== ''
        ) {
            eval('$af_aas_create_form = "'.$templates->get('af_aas_ucp_form_create').'";');
        }

        if (!empty($mybb->settings['af_advancedaccountswitcher_allow_link_existing'])
            && is_object($templates) && $templates->get('af_aas_ucp_form_link') !== ''
        ) {
            eval('$af_aas_link_form = "'.$templates->get('af_aas_ucp_form_link').'";');
        }
    }

    // ===== Приватность (показываем всем, но смысл — скрыть мастер/привязки в публичном списке)
    $af_aas_privacy_form = '';
    $checked = (!empty($mybb->user['af_aas_hide_in_list']) && (int)$mybb->user['af_aas_hide_in_list'] === 1) ? ' checked="checked"' : '';
    $af_aas_privacy_checked = $checked;

    if (is_object($templates) && $templates->get('af_aas_ucp_privacy_form') !== '') {
        eval('$af_aas_privacy_form = "'.$templates->get('af_aas_ucp_privacy_form').'";');
    } else {
        $af_aas_privacy_form = '
        <form method="post" action="'.$af_aas_ucp_action.'" class="af-aas-form" style="margin-top:12px;">
            <input type="hidden" name="my_post_key" value="'.$my_post_key.'">
            <input type="hidden" name="do" value="save_privacy">
            <table class="tborder" cellspacing="'.(int)($theme['borderwidth'] ?? 1).'" cellpadding="'.(int)($theme['tablespace'] ?? 6).'" border="0">
                <tr><td class="thead"><strong>Приватность</strong></td></tr>
                <tr><td class="trow1">
                    <label><input type="checkbox" name="hide_in_list" value="1"'.$af_aas_privacy_checked.'> Не показывать связанные аккаунты в списке пользователей</label>
                    <div class="smalltext" style="margin-top:6px;">Если включено — в публичном списке “Пользователи” не будет виден мастер-аккаунт и не будет показана привязка для связанных аккаунтов.</div>
                    <div class="af-aas-form-actions" style="margin-top:8px;"><button type="submit" class="button">Сохранить</button></div>
                </td></tr>
            </table>
        </form>';
    }

    // ===== Рендер по канону: полный шаблон -> output_page одним куском
    $af_aas_usercpmain = '';
    if (!is_object($templates) || $templates->get('af_aas_ucp_page') === '') {
        error('Не найден шаблон af_aas_ucp_page. Переустанови шаблоны аддона.');
    }
    eval('$af_aas_usercpmain = "'.$templates->get('af_aas_ucp_page').'";');

    $page = '';
    if (!is_object($templates) || $templates->get('af_aas_usercp') === '') {
        // fallback, но всё равно одним куском — соберём цельно
        $page = '<!DOCTYPE html><html><head><title>'.$af_aas_page_title.' - '.$mybb->settings['bbname'].'</title>'.$headerinclude.'</head><body>'.$header
              . '<table width="100%" border="0" align="center"><tr>'.$usercpnav.'<td valign="top">'.$af_aas_usercpmain.'</td></tr></table>'
              . $footer . '</body></html>';
        output_page($page);
        exit;
    }

    eval('$page = "'.$templates->get('af_aas_usercp').'";');
    output_page($page);
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

    $cnt = (int)$db->fetch_field(
        $db->simple_select(AF_AAS_TABLE_LINKS, 'COUNT(*) AS c', "master_uid=" . (int)$masterUid),
        'c'
    );
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

    $userdata = [
        'username'         => $u,
        'password'         => $p1,
        'password2'        => $p2,
        'email'            => (string)$masterUser['email'],
        'usergroup'        => 2,
        'displaygroup'     => 0,
        'additionalgroups' => '',
        'regip'            => get_ip(),
        'lastip'           => get_ip(),
        'regdate'          => TIME_NOW,
        'timeformat'       => (string)($masterUser['timeformat'] ?? ''),
        'dateformat'       => (string)($masterUser['dateformat'] ?? ''),
        'timezone'         => (string)($masterUser['timezone'] ?? ''),
        'dst'              => (int)($masterUser['dst'] ?? 0),
        'language'         => (string)($masterUser['language'] ?? ''),
    ];

    $userhandler->set_data($userdata);

    // validate_user() у UserDataHandler иногда отсутствует как публичный API в разных сборках,
    // но validate_user() / validate_user_fields() в большинстве есть. Делаем аккуратный фоллбек.
    $valid = true;
    if (method_exists($userhandler, 'validate_user')) {
        $valid = (bool)$userhandler->validate_user();
    } elseif (method_exists($userhandler, 'validate_user_fields')) {
        $valid = (bool)$userhandler->validate_user_fields();
    } elseif (method_exists($userhandler, 'validate_user_info')) {
        $valid = (bool)$userhandler->validate_user_info();
    }

    if (!$valid) {
        $errors = $userhandler->get_friendly_errors();
        error(implode('<br>', (array)$errors));
    }

    $insertResult = $userhandler->insert_user();

    // ВАЖНО: insert_user() может вернуть массив, а не UID
    $newUid = 0;
    if (is_array($insertResult) && !empty($insertResult['uid'])) {
        $newUid = (int)$insertResult['uid'];
    } else {
        $newUid = (int)$insertResult;
    }

    if ($newUid <= 0) {
        error('Не удалось создать аккаунт.');
    }
    if ($newUid === (int)$masterUid) {
        error('Критическая ошибка: созданный UID совпал с мастер-аккаунтом (проверь возврат insert_user).');
    }

    // Привязываем именно созданный аккаунт
    af_aas_link_pair((int)$masterUid, (int)$newUid);

    // audit: create + link
    af_aas_audit_log('create', (int)$masterUid, (int)$masterUid, (int)$newUid);
    af_aas_audit_log('link',   (int)$masterUid, (int)$masterUid, (int)$newUid);

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
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
    af_aas_audit_log('link', (int)$masterUid, (int)$masterUid, (int)$linkUid);

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

    af_aas_audit_log('unlink', (int)$masterUid, (int)$masterUid, (int)$uid);
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

    // выпиливаем старый мусор, чтобы не маячил в ACP
    $db->delete_query('settings', "name='af_advancedaccountswitcher_nav_account_list'");

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
            'description' => 'Список ID групп через запятую. Пусто / "*" / "all" = всем авторизованным.',
            'optionscode' => 'text',
            'value'       => 'all',
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
    ];

    foreach ($settings as $name => $s) {
        $sid = (int)$db->fetch_field(
            $db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]),
            'sid'
        );

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

function af_aas_install_userlist_alias(bool $force = false): void
{
    // источник внутри аддона
    $src = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/userlist.php';

    // назначение — корень форума
    $dst = MYBB_ROOT . 'userlist.php';

    if (!file_exists($src)) {
        return;
    }

    $srcCode = @file_get_contents($src);
    if ($srcCode === false || trim($srcCode) === '') {
        return;
    }

    // Если уже есть userlist.php и он НЕ наш — не трогаем, чтобы не снести чужой файл
    if (file_exists($dst) && !$force) {
        $dstCode = @file_get_contents($dst);
        if ($dstCode !== false) {
            $isOur = (strpos($dstCode, 'AF_AAS_USERLIST_ALIAS') !== false);
            if (!$isOur) {
                return;
            }
        }
    }

    // Если наш и содержимое одинаковое — не пишем
    if (file_exists($dst)) {
        $dstCode = @file_get_contents($dst);
        if ($dstCode !== false && hash('sha256', $dstCode) === hash('sha256', $srcCode)) {
            return;
        }
    }

    // Пытаемся записать (если прав нет — просто тихо выходим, установку не роняем)
    @file_put_contents($dst, $srcCode);
}

function af_aas_remove_userlist_alias(): void
{
    $dst = MYBB_ROOT . 'userlist.php';

    if (!file_exists($dst)) {
        return;
    }

    $code = @file_get_contents($dst);
    if ($code === false) {
        return;
    }

    // удаляем только если это НАШ автофайл
    if (strpos($code, 'AF_AAS_USERLIST_ALIAS') === false) {
        return;
    }

    @unlink($dst);
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
    if (!$u) {
        return $bburl . '/images/default_avatar.png';
    }

    $avatar = (string)($u['avatar'] ?? '');
    $dims   = (string)($u['avatardimensions'] ?? '');

    // Самый правильный путь для MyBB: format_avatar
    if (function_exists('format_avatar')) {
        $fa = format_avatar($avatar, $dims);
        if (is_array($fa) && !empty($fa['image'])) {
            return (string)$fa['image'];
        }
    }

    // fallback
    if ($avatar === '') {
        $def = trim((string)($mybb->settings['defaultavatar'] ?? ''));
        if ($def !== '') {
            $def = preg_replace('~^\./~', '', $def);
            if (preg_match('~^https?://~i', $def)) return $def;
            return $bburl . '/' . ltrim($def, '/');
        }
        return $bburl . '/images/default_avatar.png';
    }

    $avatar = preg_replace('~^\./~', '', $avatar);

    if (preg_match('~^https?://~i', $avatar)) return $avatar;
    if (isset($avatar[0]) && $avatar[0] === '/') return $bburl . $avatar;

    return $bburl . '/' . ltrim($avatar, '/');
}

function af_aas_render_panel_widget(): string
{
    global $mybb, $templates;

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        return '';
    }

    $masterUid  = af_aas_get_master_uid($uid);
    $masterUser = get_user($masterUid);
    if (!$masterUser) {
        return '';
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // мастер + привязанные (общий список)
    $all = [];
    $all[] = [
        'uid'       => (int)$masterUser['uid'],
        'username'  => (string)$masterUser['username'],
        'is_master' => 1,
    ];

    $attached = af_aas_get_accounts_for_master($masterUid);
    foreach ($attached as $a) {
        $all[] = [
            'uid'       => (int)$a['uid'],
            'username'  => (string)$a['username'],
            'is_master' => 0,
        ];
    }

    $canManage = (!empty($mybb->settings['af_advancedaccountswitcher_allow_create']) || !empty($mybb->settings['af_advancedaccountswitcher_allow_link_existing']));
    if (count($all) < 2 && !$canManage) {
        return '';
    }

    // ссылки футера
    $af_aas_ucp_url          = htmlspecialchars_uni($bburl . '/usercp.php?action=af_aas');
    $af_aas_account_list_url = htmlspecialchars_uni($bburl . '/userlist.php');

    $myPostKey = (string)$mybb->post_code;

    $useTemplates = (is_object($templates)
        && $templates->get('af_aas_panel_widget') !== ''
        && $templates->get('af_aas_panel_row') !== '');

    $rows = '';
    $i = 0;

    foreach ($all as $it) {
        if ((int)$it['uid'] === $uid) {
            continue;
        }

        $i++;
        $af_aas_row_class     = ($i % 2 === 0) ? 'trow2' : 'trow1';
        $af_aas_item_uid      = (int)$it['uid'];
        $af_aas_item_username = htmlspecialchars_uni((string)$it['username']);

        $avatarUrl          = htmlspecialchars_uni(af_aas_get_avatar_url($af_aas_item_uid));
        $af_aas_item_avatar = '<span class="af-aas-avatar"><img src="' . $avatarUrl . '" alt=""></span>';

        $switchUrl              = $bburl . '/misc.php?action=af_aas_switch&uid=' . (int)$af_aas_item_uid . '&my_post_key=' . urlencode($myPostKey);
        $af_aas_item_switch_url = htmlspecialchars_uni($switchUrl);

        $af_aas_item_badge  = !empty($it['is_master']) ? '<span class="af-aas-badge">master</span>' : '';
        $af_aas_item_active = '';

        if ($useTemplates) {
            $rowOut = '';
            eval('$rowOut = "' . $templates->get('af_aas_panel_row') . '";');
            $rows .= $rowOut;
        } else {
            $rows .= '<tr class="' . $af_aas_row_class . ' af-aas-modal-row' . $af_aas_item_active . '">
                <td class="af-aas-modal-avatar" width="40">' . $af_aas_item_avatar . '</td>
                <td class="af-aas-modal-name">
                    <strong>' . $af_aas_item_username . '</strong> ' . $af_aas_item_badge . '<br />
                    <span class="smalltext">#' . (int)$af_aas_item_uid . '</span>
                </td>
                <td class="af-aas-modal-action" width="90">
                    <a class="button button_small" href="' . $af_aas_item_switch_url . '">Войти</a>
                </td>
            </tr>';
        }
    }

    if ($rows === '') {
        if (is_object($templates) && $templates->get('af_aas_panel_empty') !== '') {
            eval('$rows = "' . $templates->get('af_aas_panel_empty') . '";');
        } else {
            $rows = '<tr><td class="trow1" colspan="3">Нет других доступных аккаунтов.</td></tr>';
        }
    }

    if ($useTemplates) {
        $af_aas_panel_rows = $rows;
        $out = '';
        eval('$out = "' . $templates->get('af_aas_panel_widget') . '";');
        return $out;
    }

    return '
    <span class="af-aas-panel">
        <a href="#" class="af-aas-trigger" id="af_aas_trigger" aria-expanded="false" title="Переключить аккаунт">👥 Аккаунты</a>
    </span>

    <div id="af_aas_modal" class="modal af-aas-modal" style="display:none;">
        <div class="af-aas-modal-backdrop"></div>

        <table class="tborder af-aas-modal-table" cellspacing="1" cellpadding="6" border="0">
            <thead>
            <tr>
                <th class="thead" colspan="3">
                    <strong>Аккаунты</strong>
                    <button type="button" class="af-aas-modal-close" title="Закрыть">✕</button>
                </th>
            </tr>
            </thead>

            <tbody id="af_aas_modal_body">' . $rows . '</tbody>

            <tfoot>
            <tr>
                <td class="tfoot" colspan="3">
                    <div class="af-aas-modal-footer">
                        <a href="' . $af_aas_ucp_url . '" class="af-aas-footer-link">Управление аккаунтами</a>
                        <a href="' . $af_aas_account_list_url . '" class="af-aas-footer-link">Список аккаунтов</a>
                    </div>
                </td>
            </tr>
            </tfoot>
        </table>
    </div>';
}


function af_aas_render_account_list_page()
{
    global $mybb, $db, $cache, $lang, $templates, $theme;
    global $headerinclude, $header, $footer;

    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        error_no_permission();
    }

    // Права на просмотр списка участников — как в memberlist.php
    if (empty($mybb->usergroup) || (int)($mybb->usergroup['canviewmemberlist'] ?? 0) !== 1) {
        error_no_permission();
    }

    // Языки memberlist (используем существующие фразы MyBB)
    if (!is_object($lang) || empty($lang->nav_memberlist)) {
        $lang->load('memberlist');
    }

    require_once MYBB_ROOT . 'inc/functions_user.php';

    if (is_object($templates)) {
        if (empty($headerinclude)) { eval('$headerinclude = "'.$templates->get('headerinclude').'";'); }
        if (empty($header))        { eval('$header        = "'.$templates->get('header').'";'); }
        if (empty($footer))        { eval('$footer        = "'.$templates->get('footer').'";'); }
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // Хлебные крошки
    $pageTitle = 'Пользователи';
    add_breadcrumb($pageTitle, 'userlist.php');

    // ---------- SORT (как memberlist.php)
    $orderarrow = $sort_selected = [
        'regdate'    => '',
        'lastvisit'  => '',
        'reputation' => '',
        'postnum'    => '',
        'threadnum'  => '',
        'referrals'  => '',
        'username'   => ''
    ];

    $sort = strtolower((string)$mybb->get_input('sort'));
    if ($sort === '') {
        $sort = (string)($mybb->settings['default_memberlist_sortby'] ?? 'username');
    }

    switch ($sort) {
        case 'regdate':    $sort_field = 'u.regdate'; break;
        case 'lastvisit':  $sort_field = 'u.lastactive'; break;
        case 'reputation': $sort_field = 'u.reputation'; break;
        case 'postnum':    $sort_field = 'u.postnum'; break;
        case 'threadnum':  $sort_field = 'u.threadnum'; break;
        case 'referrals':
            if (!empty($mybb->settings['usereferrals'])) $sort_field = 'u.referrals';
            else { $sort_field = 'u.username'; $sort = 'username'; }
            break;
        default:
            $sort_field = 'u.username';
            $sort = 'username';
            break;
    }
    $sort_selected[$sort] = ' selected="selected"';

    $order = strtolower((string)$mybb->get_input('order'));
    if ($order === '') {
        $order = strtolower((string)($mybb->settings['default_memberlist_order'] ?? 'ascending'));
    }

    $order_check = ['ascending' => '', 'descending' => ''];
    if ($order === 'ascending' || (!$order && $sort === 'username')) {
        $sort_order = 'ASC';
        $sortordernow = 'ascending';
        $oppsortnext = 'descending';
        $order = 'ascending';
    } else {
        $sort_order = 'DESC';
        $sortordernow = 'descending';
        $oppsortnext = 'ascending';
        $order = 'descending';
    }
    $order_check[$order] = ' checked="checked"';

    // invis sorting rule (как memberlist) — если нельзя видеть инвизов
    if ($sort_field === 'u.lastactive' && (int)($mybb->usergroup['canviewwolinvis'] ?? 0) !== 1) {
        $sort_field = "u.invisible ASC, CASE WHEN u.invisible = 1 THEN u.regdate ELSE u.lastactive END";
    }

    // ---------- PERPAGE (как memberlist.php)
    $per_page_in = (int)$mybb->get_input('perpage', MyBB::INPUT_INT);
    if ($per_page_in > 0 && $per_page_in <= 500) {
        $per_page = $per_page_in;
    } elseif (!empty($mybb->settings['membersperpage'])) {
        $per_page = (int)$mybb->settings['membersperpage'];
    } else {
        $per_page = 20;
    }
    if ($per_page < 1) $per_page = 20;

    // ---------- FILTERS (как memberlist.php) — БЕЗ Website/Google/Skype
    $search_query = '1=1';

    // ВАЖНО: search_url_base — ВСЕ фильтры, КРОМЕ letter (чтобы буквы не “накапливали” &letter=...)
    $search_url_base = '';

    switch ($db->type) {
        case 'pgsql': $like = 'ILIKE'; break;
        default: $like = 'LIKE'; break;
    }

    // username search
    $search_username = htmlspecialchars_uni(trim((string)$mybb->get_input('username')));
    $username_match = (string)$mybb->get_input('username_match');

    if ($search_username !== '') {
        $username_like_query = $db->escape_string_like($search_username);

        if ($username_match === 'begins') {
            $search_query .= " AND u.username {$like} '".$username_like_query."%'";
            $search_url_base   .= "&username_match=begins";
        } elseif ($username_match === 'contains') {
            $search_query .= " AND u.username {$like} '%".$username_like_query."%'";
            $search_url_base   .= "&username_match=contains";
        } else {
            $username_esc = $db->escape_string(my_strtolower($search_username));
            $search_query .= " AND LOWER(u.username)='{$username_esc}'";
            $username_match = 'exact';
        }

        $search_url_base .= "&username=" . urlencode($search_username);
    } else {
        if ($username_match === '') $username_match = 'begins';
    }

    // hidden groups (showmemberlist=0)
    $usergroups_cache = $cache->read('usergroups');
    $hidden = [];
    if (is_array($usergroups_cache)) {
        foreach ($usergroups_cache as $gid => $groupcache) {
            if ((int)($groupcache['showmemberlist'] ?? 1) === 0) {
                $hidden[] = (int)$gid;
            }
        }
    }

    if (!empty($hidden)) {
        $hiddengroup = implode(',', $hidden);
        $search_query .= " AND u.usergroup NOT IN ({$hiddengroup})";

        foreach ($hidden as $hidegid) {
            switch ($db->type) {
                case 'pgsql':
                case 'sqlite':
                    $search_query .= " AND ','||u.additionalgroups||',' NOT LIKE '%,{$hidegid},%'";
                    break;
                default:
                    $search_query .= " AND CONCAT(',',u.additionalgroups,',') NOT LIKE '%,{$hidegid},%'";
                    break;
            }
        }
    }

    // ---------- letter (ОТДЕЛЬНО от search_url_base)
    $letterRaw = (string)$mybb->get_input('letter');
    $letterParam = '';
    if ($letterRaw !== '') {
        if ($letterRaw === '-1' || (int)$letterRaw === -1) {
            $search_query .= " AND u.username NOT REGEXP('[a-zA-Z]')";
            $letterParam = "&letter=-1";
        } else {
            $letter = strtoupper(substr($letterRaw, 0, 1));
            if (preg_match('~^[A-Z]$~', $letter)) {
                $search_query .= " AND u.username {$like} '".$db->escape_string_like($letter)."%'";
                $letterParam = "&letter=" . urlencode($letter);
            }
        }
    }

    // ---------- pagination
    $qTotal = $db->simple_select('users u', 'COUNT(*) AS users', $search_query);
    $num_users = (int)$db->fetch_field($qTotal, 'users');

    $pageNum = (int)$mybb->get_input('page', MyBB::INPUT_INT);
    if ($pageNum && $pageNum > 0) {
        $start = ($pageNum - 1) * $per_page;
        $pages = (int)ceil($num_users / $per_page);
        if ($pageNum > $pages) {
            $start = 0;
            $pageNum = 1;
        }
    } else {
        $start = 0;
        $pageNum = 1;
    }

    // url for pagination (ВАЖНО: добавляем letterParam отдельно)
    $baseUrl = "userlist.php?sort={$sort}&order={$order}&perpage={$per_page}{$letterParam}{$search_url_base}";
    $multipage = multipage($num_users, $per_page, $pageNum, htmlspecialchars_uni($baseUrl));

    // ---------- account-switcher linkage column
    $hasHideField = (method_exists($db, 'field_exists') && $db->field_exists('af_aas_hide_in_list', 'users'));
    $linksTable = TABLE_PREFIX . AF_AAS_TABLE_LINKS;

    // ---------- query (БЕЗ u.website/u.google/u.skype)
    $sql = "
        SELECT
            u.uid, u.username, u.regdate, u.lastactive, u.postnum, u.threadnum, u.reputation,
            u.avatar, u.avatardimensions, u.invisible
            " . ($hasHideField ? ", u.af_aas_hide_in_list AS user_hidden" : ", 0 AS user_hidden") . "
            , l.master_uid
            , m.username AS master_username
            " . ($hasHideField ? ", m.af_aas_hide_in_list AS master_hidden" : ", 0 AS master_hidden") . "
        FROM " . TABLE_PREFIX . "users u
        LEFT JOIN (
            SELECT attached_uid, MIN(master_uid) AS master_uid
            FROM {$linksTable}
            GROUP BY attached_uid
        ) l ON (l.attached_uid = u.uid)
        LEFT JOIN " . TABLE_PREFIX . "users m ON (m.uid = l.master_uid)
        WHERE {$search_query}
        ORDER BY {$sort_field} {$sort_order}
        LIMIT {$start}, {$per_page}
    ";
    $res = $db->query($sql);

    // ---------- build sort header links
    $mkSortLink = function(string $fieldKey, string $label) use ($sort, $order, $oppsortnext, $per_page, $search_url_base, $letterParam) {
        $nextOrder = ($fieldKey === $sort) ? $oppsortnext : $order;
        $u = "userlist.php?sort=" . urlencode($fieldKey) . "&order=" . urlencode($nextOrder) . "&perpage=" . (int)$per_page . $letterParam . $search_url_base;
        $arrow = '';
        if ($fieldKey === $sort) {
            $arrow = ($order === 'ascending') ? ' &#9650;' : ' &#9660;';
        }
        return '<a href="' . htmlspecialchars_uni($u) . '">' . htmlspecialchars_uni($label) . '</a>' . $arrow;
    };

    // ---------- controls UI (ПЕРЕВЁРСТКА: 3 колонки + вторая строка под ними)
    $val_username = htmlspecialchars_uni((string)$search_username);

    $sel_match_begins   = ($username_match === 'begins') ? ' selected="selected"' : '';
    $sel_match_contains = ($username_match === 'contains') ? ' selected="selected"' : '';
    $sel_match_exact    = ($username_match === 'exact') ? ' selected="selected"' : '';

    $controls = '
    <form action="userlist.php" method="get" style="margin-bottom:12px;">
        <table class="tborder" cellspacing="'.(int)($theme['borderwidth'] ?? 1).'" cellpadding="'.(int)($theme['tablespace'] ?? 6).'" border="0">
            <tr>
                <td class="thead" colspan="3"><strong>Поиск и сортировка</strong></td>
            </tr>
            <tr>
                <td class="trow1" style="width:33.33%;">
                    <strong>Ник</strong><br>
                    <input type="text" class="textbox" name="username" value="'.$val_username.'" style="width:95%;">
                </td>
                <td class="trow1" style="width:33.33%;">
                    <strong>Совпадение</strong><br>
                    <select name="username_match" class="textbox" style="width:95%;">
                        <option value="begins"'.$sel_match_begins.'>Начинается с</option>
                        <option value="contains"'.$sel_match_contains.'>Содержит</option>
                        <option value="exact"'.$sel_match_exact.'>Точно</option>
                    </select>
                </td>
                <td class="trow1" style="width:33.33%;">
                    <strong>Сортировка</strong><br>
                    <select name="sort" class="textbox" style="width:95%;">
                        <option value="username"'.$sort_selected['username'].'>Ник</option>
                        <option value="regdate"'.$sort_selected['regdate'].'>Регистрация</option>
                        <option value="lastvisit"'.$sort_selected['lastvisit'].'>Активность</option>
                        <option value="postnum"'.$sort_selected['postnum'].'>Сообщения</option>
                        <option value="threadnum"'.$sort_selected['threadnum'].'>Темы</option>
                        <option value="reputation"'.$sort_selected['reputation'].'>Репутация</option>
                    </select>
                </td>
            </tr>

            <tr>
                <td class="trow1" colspan="3">
                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                        <div>
                            <strong>Порядок</strong><br>
                            <label style="margin-right:10px;">
                                <input type="radio" name="order" value="ascending"'.$order_check['ascending'].'> ASC
                            </label>
                            <label>
                                <input type="radio" name="order" value="descending"'.$order_check['descending'].'> DESC
                            </label>
                        </div>

                        <div>
                            <strong>На странице</strong><br>
                            <input type="number" class="textbox" name="perpage" value="'.(int)$per_page.'" min="1" max="500" style="width:110px;">
                        </div>

                        <div style="margin-left:auto;">
                            <button type="submit" class="button">Показать</button>
                            <a class="button" href="userlist.php" style="margin-left:6px;">Сброс</a>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </form>';

    // ---------- letter bar + СБРОС БУКВЫ
    $letters = range('A', 'Z');
    $letterBar = '<div class="smalltext" style="margin:8px 0 14px 0;">';
    $letterBar .= '<strong>Буква:</strong> ';

    foreach ($letters as $L) {
        $u = "userlist.php?letter=" . urlencode($L)
            . "&sort=" . urlencode($sort)
            . "&order=" . urlencode($order)
            . "&perpage=" . (int)$per_page
            . $search_url_base;

        $letterBar .= '<a href="'.htmlspecialchars_uni($u).'">'.$L.'</a> ';
    }

    $uOther = "userlist.php?letter=-1&sort=" . urlencode($sort) . "&order=" . urlencode($order) . "&perpage=" . (int)$per_page . $search_url_base;
    $letterBar .= ' | <a href="'.htmlspecialchars_uni($uOther).'">#</a>';

    // отдельный сброс буквы (показывает всех, но сохраняет остальной поиск)
    $uResetLetter = "userlist.php?sort=" . urlencode($sort) . "&order=" . urlencode($order) . "&perpage=" . (int)$per_page . $search_url_base;
    $letterBar .= ' | <a href="'.htmlspecialchars_uni($uResetLetter).'">сброс</a>';

    $letterBar .= '</div>';

    // ---------- build rows
    $rows = '';
    $i = 0;

    while ($r = $db->fetch_array($res)) {
        $i++;
        $rowClass = ($i % 2 === 0) ? 'trow2' : 'trow1';

        $uid      = (int)($r['uid'] ?? 0);
        $username = (string)($r['username'] ?? '');
        if ($uid <= 0 || $username === '') continue;

        // avatar
        $fa = null;
        if (function_exists('format_avatar')) {
            $fa = format_avatar((string)($r['avatar'] ?? ''), (string)($r['avatardimensions'] ?? ''));
        }
        $img = (is_array($fa) && !empty($fa['image'])) ? (string)$fa['image'] : af_aas_get_avatar_url($uid);
        $img = htmlspecialchars_uni($img);
        $avatarHtml = '<img src="'.$img.'" alt="" width="32" height="32" style="width:32px;height:32px;border-radius:50%;object-fit:cover;display:block;">';

        // profile link
        $profileUrl = $bburl . '/member.php?action=profile&uid=' . $uid;
        $userLink = '<a href="' . htmlspecialchars_uni($profileUrl) . '"><strong>' . htmlspecialchars_uni($username) . '</strong></a>';

        // dates + nums
        $regdate    = (int)($r['regdate'] ?? 0);
        $lastactive = (int)($r['lastactive'] ?? 0);

        $regOut  = $regdate > 0 ? my_date('relative', $regdate) : '&mdash;';
        $lastOut = $lastactive > 0 ? my_date('relative', $lastactive) : '&mdash;';

        $postnum   = my_number_format((int)($r['postnum'] ?? 0));
        $threadnum = my_number_format((int)($r['threadnum'] ?? 0));

        // master link (privacy only hides the column content)
        $masterUid    = (int)($r['master_uid'] ?? 0);
        $masterName   = (string)($r['master_username'] ?? '');
        $masterHidden = (int)($r['master_hidden'] ?? 0);
        $userHidden   = (int)($r['user_hidden'] ?? 0);

        if ($masterUid > 0 && ($userHidden === 1 || $masterHidden === 1)) {
            $masterUid = 0;
            $masterName = '';
        }

        $masterLink = '&mdash;';
        if ($masterUid > 0 && $masterName !== '') {
            $mUrl = $bburl . '/member.php?action=profile&uid=' . $masterUid;
            $masterLink = '<a href="' . htmlspecialchars_uni($mUrl) . '">' . htmlspecialchars_uni($masterName) . '</a>';
        }

        $rows .= '
        <tr>
            <td class="'.$rowClass.'" style="width:40px;">'.$avatarHtml.'</td>
            <td class="'.$rowClass.'">'.$userLink.'</td>
            <td class="'.$rowClass.'" style="white-space:nowrap;">'.$regOut.'</td>
            <td class="'.$rowClass.'" style="white-space:nowrap;">'.$lastOut.'</td>
            <td class="'.$rowClass.'" style="text-align:center;white-space:nowrap;">'.$postnum.'</td>
            <td class="'.$rowClass.'" style="text-align:center;white-space:nowrap;">'.$threadnum.'</td>
            <td class="'.$rowClass.'" style="white-space:nowrap;">'.$masterLink.'</td>
        </tr>';
    }

    if (trim($rows) === '') {
        $rows = '<tr><td class="trow1" colspan="7"><em>Ничего не найдено.</em></td></tr>';
    }

    // ---------- table headers with clickable sort links
    $thUser   = $mkSortLink('username',  'Пользователь');
    $thReg    = $mkSortLink('regdate',   'Регистрация');
    $thLast   = $mkSortLink('lastvisit', 'Активность');
    $thPosts  = $mkSortLink('postnum',   'Сообщения');
    $thThread = $mkSortLink('threadnum', 'Темы');

    $content = '
    <div class="userlist">
        <h1 style="margin:0 0 10px 0;">'.$pageTitle.'</h1>
        '.$controls.'
        '.$letterBar.'
        '.$multipage.'
        <table class="tborder" cellspacing="'.(int)($theme['borderwidth'] ?? 1).'" cellpadding="'.(int)($theme['tablespace'] ?? 6).'" border="0">
            <tr>
                <td class="thead" style="width:40px;">&nbsp;</td>
                <td class="thead">'.$thUser.'</td>
                <td class="thead" style="white-space:nowrap;">'.$thReg.'</td>
                <td class="thead" style="white-space:nowrap;">'.$thLast.'</td>
                <td class="thead" style="text-align:center;white-space:nowrap;">'.$thPosts.'</td>
                <td class="thead" style="text-align:center;white-space:nowrap;">'.$thThread.'</td>
                <td class="thead" style="white-space:nowrap;">Привязка</td>
            </tr>
            '.$rows.'
        </table>
        '.$multipage.'
    </div>';

    // Полный документ одним куском — по твоему канону
    $page = '<!DOCTYPE html>
<html>
<head>
    <title>'.htmlspecialchars_uni($pageTitle).' - '.htmlspecialchars_uni((string)$mybb->settings['bbname']).'</title>
    '.$headerinclude.'
</head>
<body>
'.$header.'
'.$content.'
'.$footer.'
</body>
</html>';

    output_page($page);
    exit;
}

