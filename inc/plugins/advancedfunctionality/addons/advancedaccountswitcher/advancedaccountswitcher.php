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

function af_aas_load_lang(bool $admin = false): void
{
    global $lang, $mybb;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    static $loadedFront = false;
    static $loadedAdmin = false;

    $file = 'advancedfunctionality_advancedaccountswitcher';

    // маленький хелпер: применить $l[] к объекту языка
    $apply = function($l) use (&$lang): void {
        if (!is_array($l) || !is_object($lang)) {
            return;
        }
        foreach ($l as $k => $v) {
            if (is_string($k) && $k !== '') {
                $lang->$k = $v;
            }
        }
    };

    // маленький хелпер: попытка include lang-файла, который определяет $l = [...]
    $includeLang = function(string $path) use ($apply): bool {
        if (!file_exists($path)) {
            return false;
        }
        $l = [];
        @include $path;
        if (!empty($l) && is_array($l)) {
            $apply($l);
            return true;
        }
        return false;
    };

    if ($admin) {
        if ($loadedAdmin) {
            return;
        }

        // В ACP язык может отличаться от фронта (cplanguage)
        $cpLang = '';
        if (is_object($mybb) && !empty($mybb->settings['cplanguage'])) {
            $cpLang = (string)$mybb->settings['cplanguage'];
        }

        // выставим язык (если метод есть)
        if ($cpLang !== '' && method_exists($lang, 'set_language')) {
            $lang->set_language($cpLang);
        }

        // 1) Нормальный путь MyBB: /inc/languages/{cplanguage}/admin/{file}.lang.php
        $lang->load($file, true, true);

        // Если ключей всё ещё нет — значит файл не нашёлся/не подгрузился.
        // Проверяем по одному “железному” ключу из твоего списка:
        $probeKey = 'af_advancedaccountswitcher_allow_create';

        if (empty($lang->$probeKey)) {
            // 2) ТВОЙ кастомный путь (как ты написала): /admin/{file}.lang.php
            // (Да, это нетипично для MyBB, но мы подстрахуемся.)
            $includeLang(MYBB_ROOT . 'admin/' . $file . '.lang.php');

            // 3) Стандартный MyBB путь (на случай если язык всё же лежит правильно):
            // /inc/languages/{lang}/admin/{file}.lang.php
            if (!empty($cpLang)) {
                $includeLang(MYBB_ROOT . 'inc/languages/' . $cpLang . '/admin/' . $file . '.lang.php');
            }
            $includeLang(MYBB_ROOT . 'inc/languages/' . (string)$lang->language . '/admin/' . $file . '.lang.php');

            // 4) Фоллбек: если ты вдруг держишь языки внутри аддона
            // /inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/languages/{lang}/admin/{file}.lang.php
            if (!empty($cpLang)) {
                $includeLang(MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/languages/' . $cpLang . '/admin/' . $file . '.lang.php');
            }
            $includeLang(MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/languages/' . (string)$lang->language . '/admin/' . $file . '.lang.php');
        }

        $loadedAdmin = true;
        return;
    }

    // ---------- FRONT ----------
    if ($loadedFront) {
        return;
    }

    // 1) Нормальный путь MyBB: /inc/languages/{lang}/{file}.lang.php
    $lang->load($file);

    // если не подхватилось — пробуем фоллбеки
    $probeKey = 'af_advancedaccountswitcher_allow_create';
    if (empty($lang->$probeKey)) {
        // 2) Стандартный путь напрямую include
        $includeLang(MYBB_ROOT . 'inc/languages/' . (string)$lang->language . '/' . $file . '.lang.php');

        // 3) Фоллбек внутри аддона (если есть)
        $includeLang(MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/languages/' . (string)$lang->language . '/' . $file . '.lang.php');
    }

    $loadedFront = true;
}


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

    // вшиваем {$af_aas_header_button} в header_welcomeblock_member
    af_aas_tpl_insert_header_button_placeholder();

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

    // выпиливаем {$af_aas_header_button} из header_welcomeblock_member
    af_aas_tpl_remove_header_button_placeholder();

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

function af_advancedaccountswitcher_activate()
{
    // при активации — вшиваем плейсхолдер в header_welcomeblock_member
    af_aas_tpl_insert_header_button_placeholder();

    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    } else {
        rebuild_settings();
    }
}

function af_advancedaccountswitcher_deactivate()
{
    // при деактивации — выпиливаем плейсхолдер из header_welcomeblock_member
    af_aas_tpl_remove_header_button_placeholder();

    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    } else {
        rebuild_settings();
    }
}

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

    // NEW: готовим переменную для header_welcomeblock_member ДО рендера хедера
    af_aas_prepare_header_button_var();
}

/**
 * AF hook entrypoint: called by AF core on pre_output_page (или через add_hook выше)
 */function af_advancedaccountswitcher_pre_output(&$page)
{
    global $mybb;

    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        return;
    }

    // 0) Определяем страницу редиректа MyBB
    $isRedirect =
        (stripos($page, 'class="redirect"') !== false) ||
        (stripos($page, "class='redirect'") !== false) ||
        (stripos($page, 'id="redirect"') !== false) ||
        (stripos($page, "id='redirect'") !== false) ||
        (stripos($page, 'mybb_redirect') !== false) ||
        (stripos($page, '<div class="redirect"') !== false) ||
        (stripos($page, "<div class='redirect'") !== false);

    // На редиректе — вычищаем кнопку/модалку, даже если они попали через шаблон
    if ($isRedirect) {
        $page = preg_replace('~\s*<!--af_aas_header_button-->.*?<!--/af_aas_header_button-->\s*~is', '', $page);

        $page = preg_replace('~<span\b[^>]*class=["\'][^"\']*\baf-aas-panel\b[^"\']*["\'][^>]*>.*?</span>~is', '', $page);
        $page = preg_replace('~<a\b[^>]*id=["\']af_aas_trigger["\'][^>]*>.*?</a>~is', '', $page);
        $page = preg_replace('~<div\b[^>]*id=["\']af_aas_modal["\'][^>]*>.*?</table>\s*</div>~is', '', $page);

        return;
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // ассеты
    $css = $bburl . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/advancedaccountswitcher.css';
    $js  = $bburl . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/advancedaccountswitcher.js';

    if (strpos($page, 'advancedaccountswitcher.css') === false) {
        $page = str_replace(
            '</head>',
            '<link rel="stylesheet" href="' . htmlspecialchars_uni($css) . '?v=1.1.11" />' . "\n</head>",
            $page
        );
    }
    if (strpos($page, 'advancedaccountswitcher.js') === false) {
        $page = str_replace(
            '</body>',
            '<script src="' . htmlspecialchars_uni($js) . '?v=1.1.11"></script>' . "\n</body>",
            $page
        );
    }

    // ====== Навигация: МЕНЯЕМ memberlist.php -> userlist.php ВЕЗДЕ В HTML
    $prettyUserlistUrl = $bburl . '/userlist.php';
    $newHref = htmlspecialchars_uni($prettyUserlistUrl);

    $page = preg_replace_callback(
        '~<a\b([^>]*?)\bhref\s*=\s*(["\'])([^"\']*memberlist\.php[^"\']*)\2([^>]*)>~i',
        function ($m) use ($newHref) {
            if (stripos($m[0], 'data-af-aas-nav=') !== false) {
                return $m[0];
            }

            $before = $m[1];
            $hrefVal = $m[3];
            $after = $m[4];

            if (stripos($hrefVal, 'memberlist.php') === false) {
                return $m[0];
            }

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
    global $mybb, $templates, $lang;

    $uid = (int)$mybb->user['uid'];
    if ($uid <= 0) {
        return '';
    }

    af_aas_load_lang(false);

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
            $badge = htmlspecialchars_uni((string)($lang->af_aas_badge_master ?? ''));
            $label .= ' <span class="af-aas-badge">' . $badge . '</span>';
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
    global $mybb, $db, $session, $lang;

    af_aas_load_lang(false);

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
        error((string)$lang->af_aas_err_invalid_uid);
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
        error((string)$lang->af_aas_err_target_not_found);
    }

    // Если включена защита — не даём переключиться на забаненного
    if (af_aas_is_user_banned((int)$targetUser['uid'])) {
        error((string)$lang->af_aas_err_target_banned);
    }

    $loginkey = (string)($targetUser['loginkey'] ?? '');
    if ($loginkey === '') {
        error((string)$lang->af_aas_err_missing_loginkey);
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
        redirect($return, (string)$lang->af_aas_msg_switched);
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    redirect($bburl . '/index.php', (string)$lang->af_aas_msg_switched);
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

            redirect($bburl . '/usercp.php?action=af_aas', (string)($lang->af_aas_msg_privacy_saved ?? ''));
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
    global $mybb, $db, $lang;

    af_aas_load_lang(false);

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
        error((string)$lang->af_aas_err_limit_reached);
    }

    $u  = trim((string)($mybb->input['new_username'] ?? ''));
    $p1 = (string)($mybb->input['new_password'] ?? '');
    $p2 = (string)($mybb->input['new_password2'] ?? '');

    if ($u === '' || $p1 === '' || $p2 === '') {
        error((string)$lang->af_aas_err_fill_all_fields);
    }
    if ($p1 !== $p2) {
        error((string)$lang->af_aas_err_passwords_mismatch);
    }

    $masterUser = get_user($masterUid);
    if (!$masterUser) {
        error((string)$lang->af_aas_err_master_not_found);
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

    $newUid = 0;
    if (is_array($insertResult) && !empty($insertResult['uid'])) {
        $newUid = (int)$insertResult['uid'];
    } else {
        $newUid = (int)$insertResult;
    }

    if ($newUid <= 0) {
        error((string)$lang->af_aas_err_create_failed);
    }
    if ($newUid === (int)$masterUid) {
        error((string)$lang->af_aas_err_uid_equals_master);
    }

    af_aas_link_pair((int)$masterUid, (int)$newUid);

    af_aas_audit_log('create', (int)$masterUid, (int)$masterUid, (int)$newUid);
    af_aas_audit_log('link',   (int)$masterUid, (int)$masterUid, (int)$newUid);

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    redirect($bburl . '/usercp.php?action=af_aas', (string)$lang->af_aas_msg_created_linked);
}


function af_aas_ucp_do_link_existing(int $masterUid)
{
    global $mybb, $db, $lang;

    af_aas_load_lang(false);

    if (empty($mybb->settings['af_advancedaccountswitcher_allow_link_existing'])) {
        error_no_permission();
    }

    $max = (int)($mybb->settings['af_advancedaccountswitcher_max_linked'] ?? 5);
    if ($max < 1) { $max = 1; }

    $cnt = (int)$db->fetch_field($db->simple_select(AF_AAS_TABLE_LINKS, 'COUNT(*) AS c', "master_uid=" . (int)$masterUid), 'c');
    if ($cnt >= $max) {
        error((string)$lang->af_aas_err_limit_reached);
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
        error((string)$lang->af_aas_err_pick_account);
    }
    if ($linkUid === $masterUid) {
        error((string)$lang->af_aas_err_cannot_link_self);
    }

    $exists = (int)$db->fetch_field(
        $db->simple_select(AF_AAS_TABLE_LINKS, 'id', "attached_uid=" . (int)$linkUid, ['limit' => 1]),
        'id'
    );
    if ($exists > 0) {
        error((string)$lang->af_aas_err_already_linked);
    }

    $user = get_user($linkUid);
    if (!$user) {
        error((string)$lang->af_aas_err_account_not_found);
    }

    require_once MYBB_ROOT . 'inc/functions_user.php';
    $ok = validate_password_from_uid($linkUid, $linkPassword);
    if (!$ok) {
        error((string)$lang->af_aas_err_wrong_password);
    }

    af_aas_link_pair($masterUid, $linkUid);
    af_aas_audit_log('link', (int)$masterUid, (int)$masterUid, (int)$linkUid);

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    redirect($bburl . '/usercp.php?action=af_aas', (string)$lang->af_aas_msg_linked);
}


function af_aas_ucp_do_unlink(int $masterUid)
{
    global $mybb, $db, $lang;

    af_aas_load_lang(false);

    $uid = (int)($mybb->input['uid'] ?? 0);
    if ($uid <= 0) {
        error((string)$lang->af_aas_err_uid_invalid);
    }

    af_aas_audit_log('unlink', (int)$masterUid, (int)$masterUid, (int)$uid);
    $db->delete_query(AF_AAS_TABLE_LINKS, "master_uid=" . (int)$masterUid . " AND attached_uid=" . (int)$uid);

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    redirect($bburl . '/usercp.php?action=af_aas', (string)$lang->af_aas_msg_unlinked);
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
    global $db, $lang;

    af_aas_load_lang(false);

    $user = get_user($uid);
    if (!$user) { return; }

    $reason = (string)($lang->af_aas_ban_reason_master ?? '');

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
                'bantime'            => '---',
                'lifted'             => 0,
                'reason'             => $reason,
            ]);
        }
    }

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
    global $db, $lang;

    // ВАЖНО: тянем именно admin-язык, потому что это подписи ACP-настроек
    af_aas_load_lang(true);

    // Безопасный getter: вернёт фразу из $lang, иначе fallback, иначе ключ.
    $t = function(string $key, string $fallback = '') use ($lang): string {
        $val = '';
        if (is_object($lang) && isset($lang->$key) && is_string($lang->$key)) {
            $val = trim($lang->$key);
        }
        if ($val !== '') {
            return $val;
        }
        if ($fallback !== '') {
            return $fallback;
        }
        return $key;
    };

    // Группа настроек
    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='af_advancedaccountswitcher'", ['limit' => 1]),
        'gid'
    );

    if ($gid <= 0) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => 'af_advancedaccountswitcher',
            'title'       => $t('af_advancedaccountswitcher_group', 'AF: Advanced Account Switcher'),
            'description' => $t('af_advancedaccountswitcher_group_desc', 'Настройки дополнительных аккаунтов и переключения.'),
            'disporder'   => 100,
            'isdefault'   => 0,
        ]);
    } else {
        // Обновляем title/desc группы
        $db->update_query('settinggroups', [
            'title'       => $db->escape_string($t('af_advancedaccountswitcher_group', 'AF: Advanced Account Switcher')),
            'description' => $db->escape_string($t('af_advancedaccountswitcher_group_desc', 'Настройки дополнительных аккаунтов и переключения.')),
        ], "gid=" . (int)$gid);
    }

    // Если был мусор старых версий
    $db->delete_query('settings', "name='af_advancedaccountswitcher_nav_account_list'");

    // ВАЖНО: стандартный MyBB yes/no -> локализованные Да/Нет и нормальные радиокнопки
    $yesno = 'yesno';

    // Описания настроек + дефолты (defaults ставим ТОЛЬКО на insert)
    $settings = [
        'af_advancedaccountswitcher_enabled' => [
            'title'       => $t('af_advancedaccountswitcher_enabled'),
            'description' => $t('af_advancedaccountswitcher_enabled_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 1,
        ],
        'af_advancedaccountswitcher_allowed_groups' => [
            'title'       => $t('af_advancedaccountswitcher_allowed_groups'),
            'description' => $t('af_advancedaccountswitcher_allowed_groups_desc'),
            'optionscode' => 'text',
            'default'     => 'all',
            'disporder'   => 2,
        ],
        'af_advancedaccountswitcher_max_linked' => [
            'title'       => $t('af_advancedaccountswitcher_max_linked'),
            'description' => $t('af_advancedaccountswitcher_max_linked_desc'),
            'optionscode' => 'numeric',
            'default'     => '5',
            'disporder'   => 3,
        ],
        'af_advancedaccountswitcher_allow_create' => [
            'title'       => $t('af_advancedaccountswitcher_allow_create'),
            'description' => $t('af_advancedaccountswitcher_allow_create_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 4,
        ],
        'af_advancedaccountswitcher_allow_link_existing' => [
            'title'       => $t('af_advancedaccountswitcher_allow_link_existing'),
            'description' => $t('af_advancedaccountswitcher_allow_link_existing_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 5,
        ],
        'af_advancedaccountswitcher_ui_header' => [
            'title'       => $t('af_advancedaccountswitcher_ui_header'),
            'description' => $t('af_advancedaccountswitcher_ui_header_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 6,
        ],
        'af_advancedaccountswitcher_log_switches' => [
            'title'       => $t('af_advancedaccountswitcher_log_switches'),
            'description' => $t('af_advancedaccountswitcher_log_switches_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 7,
        ],
        'af_advancedaccountswitcher_ban_propagation' => [
            'title'       => $t('af_advancedaccountswitcher_ban_propagation'),
            'description' => $t('af_advancedaccountswitcher_ban_propagation_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 8,
        ],
        'af_advancedaccountswitcher_shadow_session' => [
            'title'       => $t('af_advancedaccountswitcher_shadow_session'),
            'description' => $t('af_advancedaccountswitcher_shadow_session_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 9,
        ],
        'af_advancedaccountswitcher_pm_notify_master' => [
            'title'       => $t('af_advancedaccountswitcher_pm_notify_master'),
            'description' => $t('af_advancedaccountswitcher_pm_notify_master_desc'),
            'optionscode' => $yesno,
            'default'     => '1',
            'disporder'   => 10,
        ],
    ];

    foreach ($settings as $name => $s) {
        $nameEsc = $db->escape_string($name);

        $q = $db->simple_select('settings', 'sid,value', "name='{$nameEsc}'", ['limit' => 1]);
        $rowExisting = $db->fetch_array($q);

        $sid = (int)($rowExisting['sid'] ?? 0);
        $currentValue = array_key_exists('value', (array)$rowExisting) ? (string)$rowExisting['value'] : null;

        $row = [
            'name'        => $name,
            'title'       => $db->escape_string((string)$s['title']),
            'description' => $db->escape_string((string)$s['description']),
            'optionscode' => $db->escape_string((string)$s['optionscode']),
            'disporder'   => (int)$s['disporder'],
            'gid'         => (int)$gid,
        ];

        if ($sid > 0) {
            // не сбрасываем value — только лечим title/description/optionscode/disporder/gid
            $row['value'] = $db->escape_string($currentValue !== null ? $currentValue : (string)$s['default']);
            $db->update_query('settings', $row, "sid=" . (int)$sid);
        } else {
            $row['value'] = $db->escape_string((string)$s['default']);
            $db->insert_query('settings', $row);
        }
    }
}


function af_aas_admin_sync_settings_if_needed(): void
{
    global $mybb;

    if (!defined('IN_ADMINCP')) {
        return;
    }
    if (!is_object($mybb)) {
        return;
    }

    // Лечим подписи настроек только в тех местах, где это реально уместно:
    // - config-settings (просмотр/редактирование настроек)
    // - config-plugins  (часто заходят туда после установки)
    $module = (string)($mybb->input['module'] ?? '');
    if ($module !== 'config-settings' && $module !== 'config-plugins') {
        return;
    }

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // 1) Сначала гарантируем загрузку admin-языка (с фоллбеками)
    af_aas_load_lang(true);

    // 2) Затем переписываем title/description/optionscode в БД (value сохраняем)
    af_aas_ensure_settings();

    // ВАЖНО: rebuild_settings тут не обязателен для отображения title/desc,
    // но пусть будет безопасно, если где-то кешируют список.
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    } else {
        rebuild_settings();
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
    global $db, $lang;

    af_aas_load_lang(true);

    $src = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/tasks/task_af_aas_cleanup.php';

    $dstDir = MYBB_ROOT . 'inc/tasks';
    $dst    = $dstDir . '/task_af_aas_cleanup.php';

    if (!is_dir($dstDir)) {
        @mkdir($dstDir, 0755, true);
    }

    if (!file_exists($src)) {
        return;
    }

    $srcCode = file_get_contents($src);
    if ($srcCode === false || trim($srcCode) === '') {
        return;
    }

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

    $exists = (int)$db->fetch_field(
        $db->simple_select('tasks', 'tid', "file='af_aas_cleanup'", ['limit' => 1]),
        'tid'
    );

    if ($exists <= 0) {
        $db->insert_query('tasks', [
            'title'       => (string)$lang->af_aas_task_title,
            'description' => (string)$lang->af_aas_task_desc,
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
    global $mybb, $usercpnav, $lang;

    if ((int)$mybb->user['uid'] <= 0) {
        return;
    }
    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        return;
    }
    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        return;
    }

    af_aas_load_lang(false);

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $url = $bburl . '/usercp.php?action=af_aas';

    $label = (string)($lang->af_aas_ucp_nav_label ?? '');
    $label = $label !== '' ? $label : '👥';

    // стандартный стиль UCP-меню (табличный)
    $usercpnav .= '<tr><td class="trow1 smalltext">'
        . '<a href="' . htmlspecialchars_uni($url) . '">' . htmlspecialchars_uni($label) . '</a>'
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
    global $mybb, $templates, $lang;

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        return '';
    }

    af_aas_load_lang(false);

    $masterUid  = af_aas_get_master_uid($uid);
    $masterUser = get_user($masterUid);
    if (!$masterUser) {
        return '';
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

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

        $badgeTxt = htmlspecialchars_uni((string)($lang->af_aas_badge_master ?? ''));
        $af_aas_item_badge  = !empty($it['is_master']) ? '<span class="af-aas-badge">' . $badgeTxt . '</span>' : '';
        $af_aas_item_active = '';

        if ($useTemplates) {
            $rowOut = '';
            eval('$rowOut = "' . $templates->get('af_aas_panel_row') . '";');
            $rows .= $rowOut;
        } else {
            $btnLogin = htmlspecialchars_uni((string)($lang->af_aas_btn_login ?? ''));
            $rows .= '<tr class="' . $af_aas_row_class . ' af-aas-modal-row' . $af_aas_item_active . '">
                <td class="af-aas-modal-avatar" width="40">' . $af_aas_item_avatar . '</td>
                <td class="af-aas-modal-name">
                    <strong>' . $af_aas_item_username . '</strong> ' . $af_aas_item_badge . '<br />
                    <span class="smalltext">#' . (int)$af_aas_item_uid . '</span>
                </td>
                <td class="af-aas-modal-action" width="90">
                    <a class="button button_small" href="' . $af_aas_item_switch_url . '">' . $btnLogin . '</a>
                </td>
            </tr>';
        }
    }

    if ($rows === '') {
        if (is_object($templates) && $templates->get('af_aas_panel_empty') !== '') {
            eval('$rows = "' . $templates->get('af_aas_panel_empty') . '";');
        } else {
            $empty = htmlspecialchars_uni((string)($lang->af_aas_panel_empty ?? ''));
            $rows = '<tr><td class="trow1" colspan="3">' . $empty . '</td></tr>';
        }
    }

    if ($useTemplates) {
        $af_aas_panel_rows = $rows;
        $out = '';
        eval('$out = "' . $templates->get('af_aas_panel_widget') . '";');
        return $out;
    }

    $tSwitch  = htmlspecialchars_uni((string)($lang->af_aas_header_title_switch ?? ''));
    $lblAcc   = htmlspecialchars_uni((string)($lang->af_aas_header_label_accounts ?? ''));
    $mTitle   = htmlspecialchars_uni((string)($lang->af_aas_modal_title_accounts ?? ''));
    $mClose   = htmlspecialchars_uni((string)($lang->af_aas_modal_close_title ?? ''));
    $fManage  = htmlspecialchars_uni((string)($lang->af_aas_footer_manage_accounts ?? ''));
    $fList    = htmlspecialchars_uni((string)($lang->af_aas_footer_account_list ?? ''));

    return '
    <span class="af-aas-panel">
        <a href="#" class="af-aas-trigger" id="af_aas_trigger" aria-expanded="false" title="' . $tSwitch . '">👥 ' . $lblAcc . '</a>
    </span>

    <div id="af_aas_modal" class="modal af-aas-modal" style="display:none;">
        <div class="af-aas-modal-backdrop"></div>

        <table class="tborder af-aas-modal-table" cellspacing="1" cellpadding="6" border="0">
            <thead>
            <tr>
                <th class="thead" colspan="3">
                    <strong>' . $mTitle . '</strong>
                    <button type="button" class="af-aas-modal-close" title="' . $mClose . '">✕</button>
                </th>
            </tr>
            </thead>

            <tbody id="af_aas_modal_body">' . $rows . '</tbody>

            <tfoot>
            <tr>
                <td class="tfoot" colspan="3">
                    <div class="af-aas-modal-footer">
                        <a href="' . $af_aas_ucp_url . '" class="af-aas-footer-link">' . $fManage . '</a>
                        <a href="' . $af_aas_account_list_url . '" class="af-aas-footer-link">' . $fList . '</a>
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

    // Языки: MyBB memberlist (для совместимости) + наш аддон (ключи UI)
    if (!is_object($lang)) {
        $lang = new MyLanguage();
    }
    $lang->load('memberlist');
    $lang->load('advancedfunctionality_advancedaccountswitcher');
    // Перебиваем заголовки колонок, если вдруг memberlist-локаль отсутствует/английская.
    // Берём из языка аддона — так гарантированно будет RU.
    $lang->username  = (string)($lang->af_aas_userlist_th_username     ?? $lang->username  ?? 'Username');
    $lang->regdate   = (string)($lang->af_aas_userlist_th_registration ?? $lang->regdate   ?? 'Registration');
    $lang->lastvisit = (string)($lang->af_aas_userlist_th_lastvisit    ?? $lang->lastvisit ?? 'Last Visit');
    $lang->postnum   = (string)($lang->af_aas_userlist_th_posts        ?? $lang->postnum   ?? 'Posts');
    $lang->threadnum = (string)($lang->af_aas_userlist_th_threads      ?? $lang->threadnum ?? 'Threads');


    require_once MYBB_ROOT . 'inc/functions_user.php';

    // Утилита: взять фразу из $lang, иначе вернуть имя ключа (НЕ хардкодим текст)
    $t = function(string $key) use ($lang): string {
        return (!empty($lang->$key) && is_string($lang->$key)) ? $lang->$key : $key;
    };

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // Заголовок страницы
    $pageTitle = $t('af_aas_userlist_title');

    // Хлебные крошки — ДО сборки header
    add_breadcrumb($pageTitle, 'userlist.php');

    // ---------- SORT (как memberlist.php)
    $sort_selected = [
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
        $oppsortnext = 'descending';
        $order = 'ascending';
    } else {
        $sort_order = 'DESC';
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

    // ---------- FILTERS
    $search_query = '1=1';
    $search_url_base = '';

    switch ($db->type) {
        case 'pgsql': $like = 'ILIKE'; break;
        default: $like = 'LIKE'; break;
    }

    // username search
    $search_username_raw = trim((string)$mybb->get_input('username'));
    $username_match = (string)$mybb->get_input('username_match');

    if ($search_username_raw !== '') {
        $username_like_query = $db->escape_string_like($search_username_raw);

        if ($username_match === 'begins') {
            $search_query .= " AND u.username {$like} '".$username_like_query."%'";
            $search_url_base .= "&username_match=begins";
        } elseif ($username_match === 'contains') {
            $search_query .= " AND u.username {$like} '%".$username_like_query."%'";
            $search_url_base .= "&username_match=contains";
        } else {
            $username_esc = $db->escape_string(my_strtolower($search_username_raw));
            $search_query .= " AND LOWER(u.username)='{$username_esc}'";
            $username_match = 'exact';
        }

        $search_url_base .= "&username=" . urlencode($search_username_raw);
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

    $baseUrl = "userlist.php?sort={$sort}&order={$order}&perpage={$per_page}{$letterParam}{$search_url_base}";
    $multipage = multipage($num_users, $per_page, $pageNum, $baseUrl);

    // ---------- linkage column
    $hasHideField = (method_exists($db, 'field_exists') && $db->field_exists('af_aas_hide_in_list', 'users'));
    $linksTable = TABLE_PREFIX . AF_AAS_TABLE_LINKS;

    // ---------- query
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

    // ---------- labels (аддон)
    $lbl_controls_title = $t('af_aas_controls_title');
    $lbl_username       = $t('af_aas_label_username');
    $lbl_match          = $t('af_aas_label_match');
    $lbl_sort           = $t('af_aas_label_sort');
    $lbl_order          = $t('af_aas_label_order');
    $lbl_perpage        = $t('af_aas_label_perpage');

    $opt_begins   = $t('af_aas_match_begins');
    $opt_contains = $t('af_aas_match_contains');
    $opt_exact    = $t('af_aas_match_exact');

    $btn_show  = $t('af_aas_btn_show');
    $btn_reset = $t('af_aas_btn_reset');

    $lbl_letter       = $t('af_aas_letter_label');
    $lbl_letter_other = $t('af_aas_letter_other');
    $lbl_letter_reset = $t('af_aas_letter_reset');

    $txt_empty  = $t('af_aas_account_list_empty'); // уже есть в манифесте
    $col_linkage = $t('af_aas_account_list_col_link'); // уже есть

    // Колонки (аддон)
    $col_user    = $t('af_aas_account_list_col_account');
    $col_reg     = $t('af_aas_account_list_col_reg');
    $col_active  = $t('af_aas_account_list_col_active');
    $col_posts   = $t('af_aas_account_list_col_posts');
    $col_threads = $t('af_aas_account_list_col_threads');
    $col_rep     = $t('af_aas_account_list_col_reputation'); // ДОБАВИМ В МАНИФЕСТ

    // ---------- controls UI
    $val_username = htmlspecialchars_uni($search_username_raw);

    $sel_match_begins   = ($username_match === 'begins') ? ' selected="selected"' : '';
    $sel_match_contains = ($username_match === 'contains') ? ' selected="selected"' : '';
    $sel_match_exact    = ($username_match === 'exact') ? ' selected="selected"' : '';

    // Опции сортировки — строго языки аддона
    $opt_sort_username = $col_user;
    $opt_sort_regdate  = $col_reg;
    $opt_sort_last     = $col_active;
    $opt_sort_posts    = $col_posts;
    $opt_sort_threads  = $col_threads;
    $opt_sort_rep      = $col_rep;

    $controls = '
    <form action="userlist.php" method="get" style="margin-bottom:12px;">
        <table class="tborder" cellspacing="'.(int)($theme['borderwidth'] ?? 1).'" cellpadding="'.(int)($theme['tablespace'] ?? 6).'" border="0">
            <tr>
                <td class="thead" colspan="3"><strong>'.htmlspecialchars_uni($lbl_controls_title).'</strong></td>
            </tr>
            <tr>
                <td class="trow1" style="width:33.33%;">
                    <strong>'.htmlspecialchars_uni($lbl_username).'</strong><br>
                    <input type="text" class="textbox" name="username" value="'.$val_username.'" style="width:95%;">
                </td>
                <td class="trow1" style="width:33.33%;">
                    <strong>'.htmlspecialchars_uni($lbl_match).'</strong><br>
                    <select name="username_match" class="textbox" style="width:95%;">
                        <option value="begins"'.$sel_match_begins.'>'.htmlspecialchars_uni($opt_begins).'</option>
                        <option value="contains"'.$sel_match_contains.'>'.htmlspecialchars_uni($opt_contains).'</option>
                        <option value="exact"'.$sel_match_exact.'>'.htmlspecialchars_uni($opt_exact).'</option>
                    </select>
                </td>
                <td class="trow1" style="width:33.33%;">
                    <strong>'.htmlspecialchars_uni($lbl_sort).'</strong><br>
                    <select name="sort" class="textbox" style="width:95%;">
                        <option value="username"'.$sort_selected['username'].'>'.htmlspecialchars_uni($opt_sort_username).'</option>
                        <option value="regdate"'.$sort_selected['regdate'].'>'.htmlspecialchars_uni($opt_sort_regdate).'</option>
                        <option value="lastvisit"'.$sort_selected['lastvisit'].'>'.htmlspecialchars_uni($opt_sort_last).'</option>
                        <option value="postnum"'.$sort_selected['postnum'].'>'.htmlspecialchars_uni($opt_sort_posts).'</option>
                        <option value="threadnum"'.$sort_selected['threadnum'].'>'.htmlspecialchars_uni($opt_sort_threads).'</option>
                        <option value="reputation"'.$sort_selected['reputation'].'>'.htmlspecialchars_uni($opt_sort_rep).'</option>
                    </select>
                </td>
            </tr>

            <tr>
                <td class="trow1" colspan="3">
                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                        <div>
                            <strong>'.htmlspecialchars_uni($lbl_order).'</strong><br>
                            <label style="margin-right:10px;">
                                <input type="radio" name="order" value="ascending"'.$order_check['ascending'].'> ASC
                            </label>
                            <label>
                                <input type="radio" name="order" value="descending"'.$order_check['descending'].'> DESC
                            </label>
                        </div>

                        <div>
                            <strong>'.htmlspecialchars_uni($lbl_perpage).'</strong><br>
                            <input type="number" class="textbox" name="perpage" value="'.(int)$per_page.'" min="1" max="500" style="width:110px;">
                        </div>

                        <div style="margin-left:auto;">
                            <button type="submit" class="button">'.htmlspecialchars_uni($btn_show).'</button>
                            <a class="button" href="userlist.php" style="margin-left:6px;">'.htmlspecialchars_uni($btn_reset).'</a>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </form>';

    // ---------- letter bar + reset
    $letters = range('A', 'Z');
    $letterBar = '<div class="smalltext" style="margin:8px 0 14px 0;">';
    $letterBar .= '<strong>'.htmlspecialchars_uni($lbl_letter).':</strong> ';

    foreach ($letters as $L) {
        $u = "userlist.php?letter=" . urlencode($L)
            . "&sort=" . urlencode($sort)
            . "&order=" . urlencode($order)
            . "&perpage=" . (int)$per_page
            . $search_url_base;

        $letterBar .= '<a href="'.htmlspecialchars_uni($u).'">'.htmlspecialchars_uni($L).'</a> ';
    }

    $uOther = "userlist.php?letter=-1&sort=" . urlencode($sort) . "&order=" . urlencode($order) . "&perpage=" . (int)$per_page . $search_url_base;
    $letterBar .= ' | <a href="'.htmlspecialchars_uni($uOther).'">'.htmlspecialchars_uni($lbl_letter_other).'</a>';

    $uResetLetter = "userlist.php?sort=" . urlencode($sort) . "&order=" . urlencode($order) . "&perpage=" . (int)$per_page . $search_url_base;
    $letterBar .= ' | <a href="'.htmlspecialchars_uni($uResetLetter).'">'.htmlspecialchars_uni($lbl_letter_reset).'</a>';

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
        $rows = '<tr><td class="trow1" colspan="7"><em>'.htmlspecialchars_uni($txt_empty).'</em></td></tr>';
    }

    // ---------- table headers (все подписи — языки аддона)
    $thUser   = $mkSortLink('username',  $col_user);
    $thReg    = $mkSortLink('regdate',   $col_reg);
    $thLast   = $mkSortLink('lastvisit', $col_active);
    $thPosts  = $mkSortLink('postnum',   $col_posts);
    $thThread = $mkSortLink('threadnum', $col_threads);

    $content = '
    <div class="userlist">
        <h1 style="margin:0 0 10px 0;">'.htmlspecialchars_uni($pageTitle).'</h1>
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
                <td class="thead" style="white-space:nowrap;">'.htmlspecialchars_uni($col_linkage).'</td>
            </tr>
            '.$rows.'
        </table>
        '.$multipage.'
    </div>';

    // headerinclude/header/footer собираем ПОСЛЕ breadcrumbs
    if (is_object($templates)) {
        if (empty($headerinclude)) { eval('$headerinclude = "'.$templates->get('headerinclude').'";'); }
        if (empty($header))        { eval('$header        = "'.$templates->get('header').'";'); }
        if (empty($footer))        { eval('$footer        = "'.$templates->get('footer').'";'); }
    }

    // Полный документ одним куском — режим B
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

function af_aas_prepare_header_button_var(): void
{
    global $mybb;

    // всегда определяем, чтобы не было notice и мусора в шаблоне
    $GLOBALS['af_aas_header_button'] = '';

    if (!is_object($mybb)) {
        return;
    }

    if (empty($mybb->settings['af_advancedaccountswitcher_enabled'])) {
        return;
    }

    if (empty($mybb->settings['af_advancedaccountswitcher_ui_header'])) {
        return;
    }

    if ((int)($mybb->user['uid'] ?? 0) <= 0) {
        return;
    }

    if (!af_aas_user_allowed((int)$mybb->user['uid'])) {
        return;
    }

    $widget = af_aas_render_panel_widget();
    if ($widget === '') {
        return;
    }

    // ВАЖНО: переменная вставляется внутрь <ul>, поэтому отдаём <li>...</li>
    $GLOBALS['af_aas_header_button'] =
        '<li class="af-aas-menuitem af-aas-menuitem-header">' . $widget . '</li>';
}


function af_aas_tpl_insert_header_button_placeholder(): void
{
    global $db;

    if (!is_object($db) || !$db->table_exists('templates')) {
        return;
    }

    $markerOpen  = '<!--af_aas_header_button-->';
    $markerClose = '<!--/af_aas_header_button-->';

    $snippet = "\n{$markerOpen}\n{\$af_aas_header_button}\n{$markerClose}\n";

    $q = $db->simple_select(
        'templates',
        'tid, template',
        "title='header_welcomeblock_member'"
    );

    while ($row = $db->fetch_array($q)) {
        $tid = (int)($row['tid'] ?? 0);
        $tpl = (string)($row['template'] ?? '');

        if ($tid <= 0 || $tpl === '') {
            continue;
        }

        // уже вставлено
        if (strpos($tpl, $markerOpen) !== false || strpos($tpl, '{$af_aas_header_button}') !== false) {
            continue;
        }

        $new = $tpl;

        // 1) приоритет: перед {$af_aam_header_icon}
        if (strpos($new, '{$af_aam_header_icon}') !== false) {
            $new = preg_replace('~\{\$af_aam_header_icon\}~', $snippet . '{$af_aam_header_icon}', $new, 1);
        }
        // 2) иначе: сразу после {$usercplink}
        elseif (strpos($new, '{$usercplink}') !== false) {
            $new = preg_replace('~\{\$usercplink\}~', '{$usercplink}' . $snippet, $new, 1);
        }
        // 3) фоллбек: перед </ul>
        elseif (stripos($new, '</ul>') !== false) {
            $new = preg_replace('~</ul>~i', $snippet . '</ul>', $new, 1);
        }
        // 4) совсем крайний случай: в конец
        else {
            $new .= $snippet;
        }

        if ($new !== $tpl) {
            $db->update_query('templates', [
                'template' => $db->escape_string($new),
            ], "tid={$tid}");
        }
    }
}

function af_aas_tpl_remove_header_button_placeholder(): void
{
    global $db;

    if (!is_object($db) || !$db->table_exists('templates')) {
        return;
    }

    $markerOpen  = '<!--af_aas_header_button-->';
    $markerClose = '<!--/af_aas_header_button-->';

    $q = $db->simple_select(
        'templates',
        'tid, template',
        "title='header_welcomeblock_member'"
    );

    while ($row = $db->fetch_array($q)) {
        $tid = (int)($row['tid'] ?? 0);
        $tpl = (string)($row['template'] ?? '');

        if ($tid <= 0 || $tpl === '') {
            continue;
        }

        $new = $tpl;

        // убираем блок по маркерам
        if (strpos($new, $markerOpen) !== false) {
            $new = preg_replace('~\s*<!--af_aas_header_button-->.*?<!--/af_aas_header_button-->\s*~is', "\n", $new);
        }

        // на всякий случай — если кто-то руками вставил переменную без маркеров
        if (strpos($new, '{$af_aas_header_button}') !== false) {
            $new = str_replace('{$af_aas_header_button}', '', $new);
        }

        if ($new !== $tpl) {
            $db->update_query('templates', [
                'template' => $db->escape_string($new),
            ], "tid={$tid}");
        }
    }
}


af_aas_admin_sync_settings_if_needed();
