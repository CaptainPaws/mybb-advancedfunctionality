<?php
/**
 * Advanced Alerts and Mentions — внутренний аддон AF
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Функционал:
 * - Таблицы уведомлений и типов уведомлений (на основе идеи MyAlerts).
 * - Триггеры: репутация, ЛС, ответы в теме, ответы в подписанной теме, цитаты, упоминания.
 * - @упоминания с подсказками и кнопкой «Упомянуть» в постбите (идея MentionMe).
 * - Иконка-колокольчик в шапке, выпадающий список, отдельная страница списка, UCP-предпочтения.
 * - Всё живёт внутри /advancedfunctionality/addons/advancedalertsandmentions.
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

const AF_AAM_ID           = 'advancedalertsandmentions';
// web-относительный путь (без /var/www)
const AF_AAM_BASE         = 'inc/plugins/advancedfunctionality/addons/' . AF_AAM_ID . '/';
const AF_AAM_TABLE_ALERTS = 'aam_alerts';
const AF_AAM_TABLE_TYPES  = 'aam_alert_types';

/**
 * Точка входа для ядра AdvancedFunctionality:
 * вызывается из af_{id}_init() внутри хуков global_start.
 */
function af_advancedalertsandmentions_init(): void
{
    global $plugins;

    // глобальная инициализация (подключение CSS/JS, шапка)
    af_aam_bootstrap();

    // остальные хуки (после global_start)
    $plugins->add_hook('usercp_menu',                  'af_aam_usercp_menu');
    $plugins->add_hook('usercp_start',                 'af_aam_usercp_start');
    $plugins->add_hook('misc_start',                   'af_aam_misc_router');
    $plugins->add_hook('xmlhttp',                      'af_aam_xmlhttp', -1);

    $plugins->add_hook('reputation_do_add_end',        'af_aam_rep_do_add_end');
    $plugins->add_hook('datahandler_pm_insert_end',    'af_aam_pm_insert_end');
    $plugins->add_hook('datahandler_post_insert_post', 'af_aam_post_insert_end');
    $plugins->add_hook('datahandler_user_insert',      'af_aam_datahandler_user_insert');

    $plugins->add_hook('postbit',                      'af_aam_postbit_mention_button');
    $plugins->add_hook('postbit_pm',                   'af_aam_postbit_mention_button');
}

/**
 * Доп. точка для pre_output_page
 */
function af_advancedalertsandmentions_pre_output(string &$page): void
{
    // Пока ничего не делаем. Оставлено на будущее.
}


// ============ СЛУЖЕБКА: УСТАНОВКА / УДАЛЕНИЕ ===================

function af_advancedalertsandmentions_is_installed(): bool
{
    global $db;
    return $db->table_exists(AF_AAM_TABLE_ALERTS) && $db->table_exists(AF_AAM_TABLE_TYPES);
}

function af_advancedalertsandmentions_install(): void
{
    global $db, $lang;

    if (!isset($lang->af_aam_group)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    if ($db->table_exists(AF_AAM_TABLE_TYPES) && !$db->field_exists('title', AF_AAM_TABLE_TYPES)) {
        $db->add_column(AF_AAM_TABLE_TYPES, 'title', "VARCHAR(255) NOT NULL DEFAULT '' AFTER code");
    }

    if ($db->table_exists(AF_AAM_TABLE_TYPES) && !$db->field_exists('default_user_enabled', AF_AAM_TABLE_TYPES)) {
        $db->add_column(AF_AAM_TABLE_TYPES, 'default_user_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER can_be_user_disabled");
    }

    // заполняем названия для уже существующих типов
    $labels = [];
    if (isset($lang->af_aam_alert_type_rep)) {
        foreach (['rep','pm','post_threadauthor','subscribed_thread','quoted','mention'] as $c) {
            $key = 'af_aam_alert_type_' . $c;
            if (isset($lang->{$key})) {
                $labels[$c] = $lang->{$key};
            }
        }
    }
    if (!empty($labels)) {
        foreach ($labels as $code => $title) {
            $db->update_query(
                AF_AAM_TABLE_TYPES,
                ['title' => $db->escape_string($title)],
                "code='" . $db->escape_string($code) . "' AND (title='' OR title IS NULL)"
            );
        }
    }

    $collation = $db->build_create_table_collation();

    // таблица типов уведомлений
    if (!$db->table_exists(AF_AAM_TABLE_TYPES)) {
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AAM_TABLE_TYPES . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(100) NOT NULL DEFAULT '',
                title VARCHAR(255) NOT NULL DEFAULT '',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                can_be_user_disabled TINYINT(1) NOT NULL DEFAULT 1,
                default_user_enabled TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY unique_code (code)
            ) ENGINE=InnoDB{$collation};
        ");
    }

    // таблица уведомлений
    if (!$db->table_exists(AF_AAM_TABLE_ALERTS)) {
        // свежая установка
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                uid INT UNSIGNED NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                dateline INT UNSIGNED NOT NULL,
                type_id INT UNSIGNED NOT NULL,
                object_id INT UNSIGNED NOT NULL DEFAULT 0,
                from_uid INT UNSIGNED NOT NULL DEFAULT 0,
                forced TINYINT(1) NOT NULL DEFAULT 0,
                extra TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_uid_read (uid, is_read),
                KEY idx_type (type_id)
            ) ENGINE=InnoDB{$collation};
        ");
    } else {
        // апгрейд старой таблицы: докидываем недостающие колонки
        if (!$db->field_exists('from_uid', AF_AAM_TABLE_ALERTS)) {
            $db->add_column(AF_AAM_TABLE_ALERTS, 'from_uid', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER object_id");
        }

        if (!$db->field_exists('forced', AF_AAM_TABLE_ALERTS)) {
            $db->add_column(AF_AAM_TABLE_ALERTS, 'forced', "TINYINT(1) NOT NULL DEFAULT 0 AFTER from_uid");
        }

        if (!$db->field_exists('extra', AF_AAM_TABLE_ALERTS)) {
            $db->add_column(AF_AAM_TABLE_ALERTS, 'extra', "TEXT NULL AFTER forced");
        }
    }

    // колонка в users для отключённых типов
    if (!$db->field_exists('af_aam_disabled_types', 'users')) {
        $db->add_column('users', 'af_aam_disabled_types', "TEXT NOT NULL");
    }

    // группа настроек AF: AAM
    $gid = null;
    $query = $db->simple_select('settinggroups', 'gid', "name='af_aam'");
    $group = $db->fetch_array($query);
    if (!$group) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => 'af_aam',
            'title'       => $db->escape_string($lang->af_aam_group),
            'description' => $db->escape_string($lang->af_aam_group_desc),
            'disporder'   => 50,
            'isdefault'   => 0,
        ]);
    } else {
        $gid = (int)$group['gid'];
    }

    $settings = [
        'af_aam_enabled' => [
            'title'       => $lang->af_aam_enabled,
            'description' => $lang->af_aam_enabled_desc,
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 1,
        ],
        'af_aam_per_page' => [
            'title'       => $lang->af_aam_per_page,
            'description' => $lang->af_aam_per_page_desc,
            'optionscode' => 'text',
            'value'       => '20',
            'disporder'   => 2,
        ],
        'af_aam_dropdown_limit' => [
            'title'       => $lang->af_aam_dropdown_limit,
            'description' => $lang->af_aam_dropdown_limit_desc,
            'optionscode' => 'text',
            'value'       => '5',
            'disporder'   => 3,
        ],
        'af_aam_autorefresh' => [
            'title'       => $lang->af_aam_autorefresh,
            'description' => $lang->af_aam_autorefresh_desc,
            'optionscode' => 'text',
            'value'       => '0',
            'disporder'   => 4,
        ],
        'af_aam_sound' => [
            'title'       => $lang->af_aam_sound,
            'description' => $lang->af_aam_sound_desc,
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 5,
        ],
    ];

    foreach ($settings as $name => $data) {
        $exists = $db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'");
        if ($db->fetch_array($exists)) {
            continue;
        }

        $data['name']  = $name;
        $data['gid']   = $gid;
        $data['title'] = $db->escape_string($data['title']);
        $data['description'] = $db->escape_string($data['description']);
        $db->insert_query('settings', $data);
    }

    rebuild_settings();

    // дефолтные типы уведомлений (на основе набора MyAlerts)
    $defaultTypes = [
        'rep',
        'pm',
        'post_threadauthor',
        'subscribed_thread',
        'quoted',
        'mention',
    ];

    foreach ($defaultTypes as $code) {
        $query = $db->simple_select(AF_AAM_TABLE_TYPES, 'id', "code='" . $db->escape_string($code) . "'");
        if ($db->fetch_array($query)) {
            continue;
        }

        $titleKey = 'af_aam_alert_type_' . $code;
        $title = $lang->{$titleKey} ?? $code;

        $db->insert_query(AF_AAM_TABLE_TYPES, [
            'code'                 => $db->escape_string($code),
            'title'                => $db->escape_string($title),
            'enabled'              => 1,
            'can_be_user_disabled' => 1,
            'default_user_enabled' => 1,
        ]);
    }

    // шаблоны: заголовок, модалка, страница списка, UCP-предпочтения
    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    // === header icon: пустой span, колокольчик придёт из JS ===
    $template = <<<HTML
<a href="#" id="af_aam_header_link" class="af-aam-header-link" title="{\$lang->af_aam_link_alerts}">
    <span class="af-aam-bell" id="af_aam_bell"></span>
    <span class="af-aam-badge" id="af_aam_badge">{\$af_aam_unread}</span>
</a>
<div id="af_aam_dropdown" class="af-aam-dropdown">
    <div class="af-aam-dropdown-inner">
        <ul id="af_aam_dropdown_list" data-empty-text="{\$lang->af_aam_no_alerts}">
            {\$af_aam_dropdown_items}
        </ul>
        <div class="af-aam-dropdown-footer">
            <a href="misc.php?action=af_aam_list">{\$lang->af_aam_link_alerts}</a>
            <button type="button" id="af_aam_mark_all">{\$lang->af_aam_mark_all}</button>
        </div>
    </div>
</div>
HTML;

    af_aam_insert_template('af_aam_header_icon', $template);


    // модальное окно
    $template = <<<HTML
<div id="af_aam_modal" class="af-aam-modal">
    <div class="af-aam-modal-backdrop"></div>
    <div class="af-aam-modal-window">
        <div class="af-aam-modal-header">
            <span class="af-aam-modal-title">{\$lang->af_aam_link_alerts}</span>
            <button type="button" class="af-aam-modal-close">✕</button>
        </div>
        <div class="af-aam-modal-body" id="af_aam_modal_body">
            {\$af_aam_modal_list}
        </div>
        <div class="af-aam-modal-footer">
            <button type="button" id="af_aam_modal_mark_all">{\$lang->af_aam_mark_all}</button>
        </div>
    </div>
</div>
HTML;

    af_aam_insert_template('af_aam_modal', $template);

    // страница списка уведомлений
    $template = <<<HTML
<html>
<head>
    <title>{\$lang->af_aam_link_alerts}</title>
    {\$headerinclude}
</head>
<body>
    {\$header}
    <div class="af-aam-list-page">
        <h2>{\$lang->af_aam_link_alerts}</h2>
        {\$af_aam_list_rows}
        {\$multipage}
    </div>
    {\$footer}
</body>
</html>
HTML;

    af_aam_insert_template('af_aam_list_page', $template);

    // строка уведомления в списке
    $template = <<<HTML
<div class="af-aam-list-row {\$af_aam_read_class}">
    <a class="af-aam-list-text" href="{\$af_aam_url}">{\$af_aam_text}</a>
    <span class="af-aam-list-date">{\$af_aam_date}</span>
</div>
HTML;

    af_aam_insert_template('af_aam_list_row', $template);

    // UCP: блок предпочтений
    $template = <<<HTML
<html>
<head>
    <title>{\$lang->af_aam_link_alerts}</title>
    {\$headerinclude}
</head>
<body>
    {\$header}
    <form action="usercp.php?action=af_aam_prefs" method="post">
        <input type="hidden" name="my_post_key" value="{\$mybb->post_code}" />
        <div class="trow1">
            {\$af_aam_prefs_rows}
        </div>
        <div class="trow2">
            <input type="submit" class="button" value="{\$lang->usercp_update_options}" />
        </div>
    </form>
    {\$footer}
</body>
</html>
HTML;

    af_aam_insert_template('af_aam_ucp_prefs', $template);

    // строка чекбокса предпочтения
    $template = <<<HTML
<div class="af-aam-pref-row">
    <label>
        <input type="checkbox" name="aam_enabled_types[]" value="{\$af_aam_type_code}" {\$af_aam_checked} />
        {\$af_aam_type_label}
    </label>
</div>
HTML;

    af_aam_insert_template('af_aam_ucp_prefs_row', $template);

    // сначала вычистим старые вставки, если они уже есть
    find_replace_templatesets('headerinclude', '#{\$af_aam_js}{\$af_aam_css}#i', '');
    find_replace_templatesets('header_welcomeblock_member', '#{\$af_aam_header_icon}#i', '');
    find_replace_templatesets('header_welcomeblock_member', '#{\$af_aam_header_bell}#i', '');
    find_replace_templatesets('footer', '#{\$af_aam_modal}#i', '');

    // а теперь добавим по одному разу
    find_replace_templatesets('headerinclude', '#$#', '{\$af_aam_js}{\$af_aam_css}');
    find_replace_templatesets('header_welcomeblock_member', '#{\$modcplink}#i', '{\$af_aam_header_icon}{\$modcplink}');
    find_replace_templatesets('footer', '#$#', '{\$af_aam_modal}');
}

function af_advancedalertsandmentions_uninstall(): void
{
    global $db;

    if ($db->table_exists(AF_AAM_TABLE_ALERTS)) {
        $db->drop_table(AF_AAM_TABLE_ALERTS);
    }
    if ($db->table_exists(AF_AAM_TABLE_TYPES)) {
        $db->drop_table(AF_AAM_TABLE_TYPES);
    }

    if ($db->field_exists('af_aam_disabled_types', 'users')) {
        $db->drop_column('users', 'af_aam_disabled_types');
    }

    // удаляем настройки
    $db->delete_query('settings', "name IN('af_aam_enabled','af_aam_per_page','af_aam_dropdown_limit','af_aam_autorefresh','af_aam_sound')");
    $db->delete_query('settinggroups', "name='af_aam'");
    rebuild_settings();

    // удаляем шаблоны
    $titles = [
        'af_aam_header_icon',
        'af_aam_header_bell',
        'af_aam_modal',
        'af_aam_list_page',
        'af_aam_list_row',
        'af_aam_ucp_prefs',
        'af_aam_ucp_prefs_row',
    ];
    $in = "'" . implode("','", array_map('my_strtolower', $titles)) . "'";

    $db->delete_query('templates', "title IN({$in})");

    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
    // чистим вставки
    find_replace_templatesets('headerinclude', '#{\$af_aam_js}{\$af_aam_css}#i', '');
    find_replace_templatesets('header_welcomeblock_member', '#{\$af_aam_header_icon}#i', '');
    find_replace_templatesets('header_welcomeblock_member', '#{\$af_aam_header_bell}#i', '');
    find_replace_templatesets('footer', '#{\$af_aam_modal}#i', '');
}

// AF-ядро само управляет "включено/выключено"
// При активации прогоняем install() ещё раз, чтобы обновить шаблоны/вставки
function af_advancedalertsandmentions_activate(): void
{
    af_advancedalertsandmentions_install();
}

function af_advancedalertsandmentions_deactivate(): void
{
    // Специально ничего не трогаем: AF просто перестаёт вызывать init()
}

// помощник для добавления шаблонов (теперь upsert, а не только insert)
function af_aam_insert_template(string $title, string $template): void
{
    global $db;

    $titleEsc = $db->escape_string($title);
    $query = $db->simple_select('templates', 'tid', "title='{$titleEsc}' AND sid='-2'");
    $row = $db->fetch_array($query);

    $data = [
        'template' => $db->escape_string($template),
        'version'  => '1839',
        'dateline' => TIME_NOW,
    ];

    if ($row) {
        $db->update_query('templates', $data, "tid=".(int)$row['tid']);
    } else {
        $data['title'] = $titleEsc;
        $data['sid']   = -2;
        $db->insert_query('templates', $data);
    }
}

// ================ ГЛОБАЛЬНЫЙ РЕНДЕР =====================

function af_aam_is_enabled(): bool
{
    global $mybb;
    if (empty($mybb->settings['af_aam_enabled']) || (int)$mybb->settings['af_aam_enabled'] !== 1) {
        return false;
    }
    // AF ядро: включён ли аддон
    if (isset($mybb->settings['af_' . AF_AAM_ID . '_enabled']) && (int)$mybb->settings['af_' . AF_AAM_ID . '_enabled'] !== 1) {
        return false;
    }
    return true;
}

function af_aam_bootstrap(): void
{
    global $mybb, $db, $templates, $lang;
    global $af_aam_js, $af_aam_css, $af_aam_header_icon, $af_aam_modal, $af_aam_unread, $af_aam_dropdown_items;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    // web-URL к ассетам
    $base = rtrim($mybb->settings['bburl'], '/');
    $assetBase = $base . '/' . AF_AAM_BASE;

    $af_aam_js  = '<script src="' . $assetBase . 'advancedalertsandmentions.js"></script>';
    $af_aam_css = '<link rel="stylesheet" href="' . $assetBase . 'advancedalertsandmentions.css" />';

    // гость — ничего
    if (empty($mybb->user['uid'])) {
        $af_aam_header_icon = '';
        $af_aam_modal       = '';
        return;
    }

    // считаем непрочитанные
    $uid = (int)$mybb->user['uid'];
    $query = $db->simple_select(AF_AAM_TABLE_ALERTS, 'COUNT(id) AS cnt', "uid={$uid} AND is_read=0");
    $row = $db->fetch_array($query);
    $af_aam_unread = (int)($row['cnt'] ?? 0);

    // последние N уведомлений для дропдауна
    $limit = (int)($mybb->settings['af_aam_dropdown_limit'] ?? 5);
    if ($limit <= 0) {
        $limit = 5;
    }

    $af_aam_dropdown_items = '';
    $sql = $db->write_query("
        SELECT a.*, t.code
        FROM " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " a
        LEFT JOIN " . TABLE_PREFIX . AF_AAM_TABLE_TYPES . " t ON (t.id=a.type_id)
        WHERE a.uid={$uid}
        ORDER BY a.dateline DESC
        LIMIT {$limit}
    ");
    while ($alert = $db->fetch_array($sql)) {
        $af_aam_dropdown_items .= af_aam_render_dropdown_item($alert);
    }

    if ($af_aam_dropdown_items === '') {
        $af_aam_dropdown_items = '<li class="af-aam-empty">' . htmlspecialchars_uni($lang->af_aam_no_alerts) . '</li>';
    }

    eval('$af_aam_header_icon = "'.$templates->get('af_aam_header_icon').'";');
    eval('$af_aam_modal       = "'.$templates->get('af_aam_modal').'";');
}

function af_aam_render_dropdown_item(array $alert): string
{
    global $lang, $mybb;

    $formatted = af_aam_format_alert($alert);
    $text = $formatted['text'];
    $url  = $formatted['url'];

    $date = my_date($mybb->settings['dateformat'] . ' ' . $mybb->settings['timeformat'], (int)$alert['dateline']);
    $class = ((int)$alert['is_read'] === 1) ? 'af-aam-item-read' : 'af-aam-item-unread';

    $id = (int)$alert['id'];

    $textHtml = '<span class="af-aam-item-text">' . htmlspecialchars_uni($text) . '</span>';
    if ($url !== '') {
        $textHtml = '<a href="' . htmlspecialchars_uni($url) . '">' . $textHtml . '</a>';
    }

    return '<li class="af-aam-item ' . $class . '" data-alert-id="' . $id . '">' .
        $textHtml .
        '<span class="af-aam-item-date">' . htmlspecialchars_uni($date) . '</span>' .
        '</li>';
}

function af_aam_pre_output_page(string &$page): void
{
    // Пока не лезем прямо в HTML, всё через шаблоны
}

// ================ UCP: меню и предпочтения =====================

function af_aam_usercp_menu(&$usercpnav): void
{
    global $lang, $mybb;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    $link = 'usercp.php?action=af_aam_list';
    $active = ($mybb->get_input('action') === 'af_aam_list' || $mybb->get_input('action') === 'af_aam_prefs');
    $class = $active ? ' class="usercp_nav_item usercp_nav_aam active"' : ' class="usercp_nav_item usercp_nav_aam"';

    $item = '<a href="' . $link . '"' . $class . '>' . htmlspecialchars_uni($lang->af_aam_link_alerts) . '</a>';

    // MyBB ждёт, что мы допишем пункт в строку меню
    $usercpnav .= $item;
}


function af_aam_usercp_start(): void
{
    global $mybb, $lang, $db, $templates, $theme, $header, $footer, $headerinclude;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    $action = $mybb->get_input('action');

    // список уведомлений в UCP
    if ($action === 'af_aam_list') {
        if (!$mybb->user['uid']) {
            error_no_permission();
        }

        $uid = (int)$mybb->user['uid'];
        $perPage = (int)($mybb->settings['af_aam_per_page'] ?? 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $page = max(1, (int)$mybb->get_input('page', MyBB::INPUT_INT));
        $offset = ($page - 1) * $perPage;

        $totalQuery = $db->simple_select(AF_AAM_TABLE_ALERTS, 'COUNT(id) AS cnt', "uid={$uid}");
        $totalRow = $db->fetch_array($totalQuery);
        $total = (int)($totalRow['cnt'] ?? 0);

        $af_aam_list_rows = '';
        if ($total > 0) {
            $sql = $db->write_query("
                SELECT a.*, t.code
                FROM " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " a
                LEFT JOIN " . TABLE_PREFIX . AF_AAM_TABLE_TYPES . " t ON (t.id=a.type_id)
                WHERE a.uid={$uid}
                ORDER BY a.dateline DESC
                LIMIT {$offset}, {$perPage}
            ");
            while ($alert = $db->fetch_array($sql)) {
                $formatted = af_aam_format_alert($alert);
                $text = $formatted['text'];
                $af_aam_url = htmlspecialchars_uni($formatted['url']);
                $date = my_date($mybb->settings['dateformat'] . ' ' . $mybb->settings['timeformat'], (int)$alert['dateline']);
                $af_aam_read_class = ((int)$alert['is_read'] === 1) ? 'af-aam-row-read' : 'af-aam-row-unread';

                $af_aam_text = htmlspecialchars_uni($text);
                $af_aam_date = htmlspecialchars_uni($date);
                eval('$af_aam_list_rows .= "'.$templates->get('af_aam_list_row').'";');
            }
        } else {
            $af_aam_list_rows = '<div class="af-aam-empty">' . htmlspecialchars_uni($lang->af_aam_no_alerts) . '</div>';
        }

        $multipage = multipage($total, $perPage, $page, 'usercp.php?action=af_aam_list');

        eval('echo "'.$templates->get('af_aam_list_page').'";');
        exit;
    }

    // настройки предпочтений
    if ($action === 'af_aam_prefs') {
        if (!$mybb->user['uid']) {
            error_no_permission();
        }

        $uid = (int)$mybb->user['uid'];

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $enabledTypes = $mybb->get_input('aam_enabled_types', MyBB::INPUT_ARRAY);
            $enabledTypes = array_map('trim', $enabledTypes);
            $enabledTypes = array_filter($enabledTypes);

            // все существующие типы
            $codes = [];
            $sql = $db->simple_select(AF_AAM_TABLE_TYPES, 'code', 'can_be_user_disabled=1');
            while ($row = $db->fetch_array($sql)) {
                $codes[] = $row['code'];
            }

            // disabled = all - enabled
            $disabled = array_diff($codes, $enabledTypes);
            $store = json_encode(array_values($disabled));
            $db->update_query('users', ['af_aam_disabled_types' => $db->escape_string($store)], "uid={$uid}");

            redirect('usercp.php?action=af_aam_prefs', $lang->usercp_options_updated);
        }

        // GET: форма
        $disabledList = [];
        if (!empty($mybb->user['af_aam_disabled_types'])) {
            $decoded = json_decode($mybb->user['af_aam_disabled_types'], true);
            if (is_array($decoded)) {
                $disabledList = $decoded;
            }
        }

        $af_aam_prefs_rows = '';
        $sql = $db->simple_select(AF_AAM_TABLE_TYPES, '*', 'enabled=1');
        while ($type = $db->fetch_array($sql)) {
            $code = $type['code'];
            $af_aam_type_code = htmlspecialchars_uni($code);
            $checked = in_array($code, $disabledList, true) ? '' : 'checked="checked"';
            $af_aam_checked = $checked;

            $labelKey = 'af_aam_alert_type_' . $code;
            $label = $type['title'] ?: (isset($lang->{$labelKey}) ? $lang->{$labelKey} : $code);
            $af_aam_type_label = htmlspecialchars_uni($label);

            eval('$af_aam_prefs_rows .= "'.$templates->get('af_aam_ucp_prefs_row').'";');
        }

        eval('echo "'.$templates->get('af_aam_ucp_prefs').'";');
        exit;
    }
}

function af_aam_datahandler_user_insert(\UserDataHandler &$dataHandler): void
{
    global $db;

    if (!$db->table_exists(AF_AAM_TABLE_TYPES) || !$db->field_exists('default_user_enabled', AF_AAM_TABLE_TYPES)) {
        return;
    }

    $disabledCodes = [];
    $query = $db->simple_select(AF_AAM_TABLE_TYPES, 'code', 'default_user_enabled=0');
    while ($row = $db->fetch_array($query)) {
        $disabledCodes[] = $row['code'];
    }

    $dataHandler->user_insert_data['af_aam_disabled_types'] = $db->escape_string(json_encode($disabledCodes));
}

// ================ MISC/ XMLHTTP: API и список ===================

function af_aam_misc_router(): void
{
    global $mybb, $db, $lang, $templates, $header, $headerinclude, $footer;

    if (!af_aam_is_enabled()) {
        return;
    }

    $action = $mybb->get_input('action');

    if ($action === 'af_aam_list') {
        if (!$mybb->user['uid']) {
            error_no_permission();
        }

        if (!isset($lang->af_aam_name)) {
            $lang->load('advancedfunctionality_' . AF_AAM_ID);
        }

        $uid = (int)$mybb->user['uid'];
        $perPage = (int)($mybb->settings['af_aam_per_page'] ?? 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $page = max(1, (int)$mybb->get_input('page', MyBB::INPUT_INT));
        $offset = ($page - 1) * $perPage;

        $totalQuery = $db->simple_select(AF_AAM_TABLE_ALERTS, 'COUNT(id) AS cnt', "uid={$uid}");
        $totalRow = $db->fetch_array($totalQuery);
        $total = (int)($totalRow['cnt'] ?? 0);

        $af_aam_list_rows = '';
        if ($total > 0) {
            $sql = $db->write_query("
                SELECT a.*, t.code
                FROM " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " a
                LEFT JOIN " . TABLE_PREFIX . AF_AAM_TABLE_TYPES . " t ON (t.id=a.type_id)
                WHERE a.uid={$uid}
                ORDER BY a.dateline DESC
                LIMIT {$offset}, {$perPage}
            ");
            while ($alert = $db->fetch_array($sql)) {
                $formatted = af_aam_format_alert($alert);
                $text = $formatted['text'];
                $af_aam_url = htmlspecialchars_uni($formatted['url']);
                $date = my_date($mybb->settings['dateformat'] . ' ' . $mybb->settings['timeformat'], (int)$alert['dateline']);
                $af_aam_read_class = ((int)$alert['is_read'] === 1) ? 'af-aam-row-read' : 'af-aam-row-unread';

                $af_aam_text = htmlspecialchars_uni($text);
                $af_aam_date = htmlspecialchars_uni($date);
                eval('$af_aam_list_rows .= "'.$templates->get('af_aam_list_row').'";');
            }
        } else {
            $af_aam_list_rows = '<div class="af-aam-empty">' . htmlspecialchars_uni($lang->af_aam_no_alerts) . '</div>';
        }

        $multipage = multipage($total, $perPage, $page, 'misc.php?action=af_aam_list');

        eval('echo "'.$templates->get('af_aam_list_page').'";');
        exit;
    }
}

function af_aam_xmlhttp(): void
{
    global $mybb, $db, $lang;

    // Универсальный ответ с ошибкой, чтобы никогда не молчать
    $error = static function (string $code, string $msg = ''): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => 0,
            'error'   => $code,
            'message' => $msg,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    };

    // Если пользователь не залогинен — явно говорим об этом
    if (empty($mybb->user['uid'])) {
        $error('not_logged_in', 'Пользователь не авторизован.');
    }

    // Аддон/настройки выключены
    if (!af_aam_is_enabled()) {
        $error('addon_disabled', 'Система уведомлений отключена настройками.');
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    $uid    = (int)$mybb->user['uid'];
    $action = (string)$mybb->get_input('action');
    $action_lc = strtolower($action);

    // ==========================
    // 1) Совместимость с MyAlerts: getNewAlerts / getNumUnreadAlerts и современные
    //    Ajax-операции myalerts_* для UI (взяты из оригинального плагина).
    // ==========================
    if ($action_lc === 'getlatestalerts' || $action_lc === 'getnewalerts') {
        $limit = (int)($mybb->settings['af_aam_dropdown_limit'] ?? 5);
        if ($limit <= 0) {
            $limit = 5;
        }

        $alerts      = [];
        $idsToMark   = [];
        $alertsHtml  = '';

        $sql = $db->write_query("
            SELECT a.*, t.code
            FROM " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " a
            LEFT JOIN " . TABLE_PREFIX . AF_AAM_TABLE_TYPES . " t ON (t.id = a.type_id)
            WHERE a.uid = {$uid}
            ORDER BY a.dateline DESC
            LIMIT {$limit}
        ");

        while ($row = $db->fetch_array($sql)) {
            $formatted = af_aam_format_alert($row);
            $id    = (int)$row['id'];
            $text  = $formatted['text'];
            $date  = my_date(
                $mybb->settings['dateformat'] . ' ' . $mybb->settings['timeformat'],
                (int)$row['dateline']
            );
            $class = ((int)$row['is_read'] === 1) ? 'af-aam-item-read' : 'af-aam-item-unread';

            $alerts[] = [
                'id'       => $id,
                'code'     => $row['code'],
                'is_read'  => (int)$row['is_read'],
                'text'     => $text,
                'url'      => $formatted['url'],
                'dateline' => (int)$row['dateline'],
                'date_fmt' => $date,
            ];

            $textHtml = '<span class="af-aam-item-text">' . htmlspecialchars_uni($text) . '</span>';
            if ($formatted['url'] !== '') {
                $textHtml = '<a href="' . htmlspecialchars_uni($formatted['url']) . '">' . $textHtml . '</a>';
            }

            $alertsHtml .= '<li class="af-aam-item ' . $class . '" data-alert-id="' . $id . '">'
                . $textHtml
                . '<span class="af-aam-item-date">' . htmlspecialchars_uni($date) . '</span>'
                . '</li>';
            $idsToMark[] = $id;
        }

        if (empty($alerts)) {
            $alertsHtml = '<li class="af-aam-empty">'
                . htmlspecialchars_uni($lang->af_aam_no_alerts)
                . '</li>';
        } elseif (!empty($idsToMark)) {
            $idsSql = implode(',', array_map('intval', $idsToMark));
            $db->update_query(
                AF_AAM_TABLE_ALERTS,
                ['is_read' => 1],
                "uid = {$uid} AND id IN ({$idsSql})"
            );
        }

        $q = $db->simple_select(
            AF_AAM_TABLE_ALERTS,
            'COUNT(id) AS cnt',
            "uid = {$uid} AND is_read = 0"
        );
        $r     = $db->fetch_array($q);
        $badge = (int)($r['cnt'] ?? 0);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'alerts'         => $alerts,
            'template'       => $alertsHtml,
            'badge'          => $badge,
            'unread_count'   => $badge,
            'unread_count_fmt' => my_number_format($badge),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action_lc === 'getnumunreadalerts' || $action_lc === 'get_num_unread_alerts') {
        $q = $db->simple_select(
            AF_AAM_TABLE_ALERTS,
            'COUNT(id) AS cnt',
            "uid = {$uid} AND is_read = 0"
        );
        $r = $db->fetch_array($q);
        $count = (int)($r['cnt'] ?? 0);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'unread'          => $count,
            'unread_count'    => $count,
            'unread_count_fmt'=> my_number_format($count),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action_lc === 'markallread' || $action_lc === 'mark_all_read') {
        $db->update_query(
            AF_AAM_TABLE_ALERTS,
            ['is_read' => 1],
            "uid = {$uid}"
        );

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'          => true,
            'template'         => '',
            'unread_count'     => 0,
            'unread_count_fmt' => '0',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action_lc === 'myalerts_mark_read' || $action_lc === 'myalerts_mark_unread') {
        $id = (int)$mybb->get_input('id', MyBB::INPUT_INT);
        $markRead = ($action_lc === 'myalerts_mark_read');

        if ($id > 0) {
            $db->update_query(
                AF_AAM_TABLE_ALERTS,
                ['is_read' => $markRead ? 1 : 0],
                "id = {$id} AND uid = {$uid}"
            );
        }

        $q = $db->simple_select(AF_AAM_TABLE_ALERTS, 'COUNT(id) AS cnt', "uid = {$uid} AND is_read = 0");
        $r = $db->fetch_array($q);
        $count = (int)($r['cnt'] ?? 0);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'          => true,
            'unread_count'     => $count,
            'unread_count_fmt' => my_number_format($count),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ==========================
    // 2) Наш JSON-API: action=af_aam_api / af_alerts_api
    // ==========================
    if ($action_lc !== 'af_aam_api' && $action_lc !== 'af_alerts_api') {
        // Не наш запрос — ничего не делаем, чтобы не ломать другие плагины
        return;
    }

    $op = (string)$mybb->get_input('op');

    // --- список уведомлений ---
    if ($op === 'list') {
        $limit = (int)$mybb->get_input('limit', MyBB::INPUT_INT);
        if ($limit <= 0) {
            $limit = (int)($mybb->settings['af_aam_dropdown_limit'] ?? 5);
        }

        $items = [];
        $sql   = $db->write_query("
            SELECT a.*, t.code
            FROM " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " a
            LEFT JOIN " . TABLE_PREFIX . AF_AAM_TABLE_TYPES . " t ON (t.id = a.type_id)
            WHERE a.uid = {$uid}
            ORDER BY a.dateline DESC
            LIMIT {$limit}
        ");

        while ($alert = $db->fetch_array($sql)) {
            $formatted = af_aam_format_alert($alert);
            $items[] = [
                'id'       => (int)$alert['id'],
                'code'     => $alert['code'],
                'is_read'  => (int)$alert['is_read'],
                'text'     => $formatted['text'],
                'url'      => $formatted['url'],
                'dateline' => (int)$alert['dateline'],
                'date_fmt' => my_date(
                    $mybb->settings['dateformat'] . ' ' . $mybb->settings['timeformat'],
                    (int)$alert['dateline']
                ),
            ];
        }

        $q = $db->simple_select(
            AF_AAM_TABLE_ALERTS,
            'COUNT(id) AS cnt',
            "uid = {$uid} AND is_read = 0"
        );
        $r     = $db->fetch_array($q);
        $badge = (int)($r['cnt'] ?? 0);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => 1,
            'items' => $items,
            'badge' => $badge,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- пометить одно уведомление прочитанным ---
    if ($op === 'mark_read') {
        $id = (int)$mybb->get_input('id', MyBB::INPUT_INT);
        if ($id > 0) {
            $db->update_query(
                AF_AAM_TABLE_ALERTS,
                ['is_read' => 1],
                "id = {$id} AND uid = {$uid}"
            );
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- пометить все уведомления прочитанными ---
    if ($op === 'mark_all') {
        $db->update_query(
            AF_AAM_TABLE_ALERTS,
            ['is_read' => 1],
            "uid = {$uid}"
        );
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- подсказки по никнейму @"Имя" ---
    if ($op === 'suggest') {
        $qStr = trim($mybb->get_input('q'));

        $items = [];
        if ($qStr !== '') {
            $like = $db->escape_string_like($qStr);
            $sql  = $db->simple_select(
                'users',
                'uid, username',
                "username LIKE '" . $like . "%'",
                ['limit' => 10]
            );

            while ($row = $db->fetch_array($sql)) {
                $items[] = [
                    'uid'      => (int)$row['uid'],
                    'username' => $row['username'],
                    'profile'  => get_profile_link($row['uid']),
                ];
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- неизвестная операция ---
    $error('unknown_op', 'Неизвестная операция API: ' . $op);
}


// ================ ТРИГГЕРЫ УВЕДОМЛЕНИЙ =======================

function af_aam_get_type_id(string $code): ?int
{
    global $db;

    $code = $db->escape_string($code);
    $row = $db->fetch_array($db->simple_select(AF_AAM_TABLE_TYPES, 'id,enabled', "code='{$code}'"));
    if (!$row || (int)$row['enabled'] !== 1) {
        return null;
    }
    return (int)$row['id'];
}

function af_aam_user_allows_type(int $uid, string $code): bool
{
    global $db;

    $uid = (int)$uid;
    if ($uid <= 0) {
        return false;
    }

    $user = get_user($uid);
    if (empty($user)) {
        return false;
    }

    if (empty($user['af_aam_disabled_types'])) {
        return true;
    }

    $decoded = json_decode($user['af_aam_disabled_types'], true);
    if (!is_array($decoded)) {
        return true;
    }

    return !in_array($code, $decoded, true);
}

function af_aam_add_alert(int $uid, string $code, int $objectId = 0, int $fromUid = 0, array $extra = [], int $forced = 0): void
{
    global $db;

    if (!af_aam_is_enabled()) {
        return;
    }

    $uid = (int)$uid;
    if ($uid <= 0) {
        return;
    }

    $typeId = af_aam_get_type_id($code);
    if ($typeId === null) {
        return;
    }

    if (!$forced && !af_aam_user_allows_type($uid, $code)) {
        return;
    }

    $insert = [
        'uid'      => $uid,
        'is_read'  => 0,
        'dateline' => TIME_NOW,
        'type_id'  => $typeId,
        'object_id'=> (int)$objectId,
        'from_uid' => (int)$fromUid,
        'forced'   => $forced ? 1 : 0,
        'extra'    => $db->escape_string(json_encode($extra)),
    ];

    $db->insert_query(AF_AAM_TABLE_ALERTS, $insert);
}

// репутация
function af_aam_rep_do_add_end(): void
{
    global $mybb, $db, $reputation;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (empty($reputation) || !is_array($reputation)) {
        return;
    }

    $toUid   = (int)$reputation['uid'];
    $fromUid = (int)$mybb->user['uid'];

    if ($toUid <= 0 || $toUid === $fromUid) {
        return;
    }

    af_aam_add_alert(
        $toUid,
        'rep',
        (int)($reputation['rid'] ?? 0),
        $fromUid,
        [
            'rid'       => (int)($reputation['rid'] ?? 0),
            'reputation'=> (int)($reputation['reputation'] ?? 0),
        ]
    );
}

// ЛС
function af_aam_pm_insert_end(&$pmhandler): void
{
    global $mybb;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!is_object($pmhandler) || !isset($pmhandler->pm_insert_data['toid'])) {
        return;
    }

    $message = $pmhandler->pm_insert_data;
    $fromUid = (int)$message['fromid'];

    $recipients = [];
    if (!empty($message['toid'])) {
        $recipients = array_map('intval', explode(',', (string)$message['toid']));
    }

    foreach ($recipients as $toUid) {
        if ($toUid <= 0 || $toUid === $fromUid) {
            continue;
        }

        af_aam_add_alert(
            $toUid,
            'pm',
            (int)$message['pmid'],
            $fromUid,
            [
                'subject' => (string)($message['subject'] ?? ''),
            ]
        );
    }
}

// посты: ответ автору темы, подписчики и упоминания
function af_aam_post_insert_end(&$posthandler): void
{
    global $db, $mybb;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!is_object($posthandler)) {
        return;
    }

    if (!empty($posthandler->data['savedraft'])) {
        return;
    }

    // данные поста
    $data = $posthandler->data;
    $post  = $posthandler->post_insert_data ?? [];
    $pid   = (int)($post['pid'] ?? 0);
    $tid   = (int)($post['tid'] ?? 0);

    if ($tid <= 0 || $pid <= 0) {
        return;
    }

    $fromUid = (int)$mybb->user['uid'];
    if ($fromUid <= 0) {
        return;
    }

    // подгружаем тему
    $thread = get_thread($tid);
    if ($thread) {
        $threadUid = (int)$thread['uid'];

        // уведомление автору темы (если он не автор этого поста)
        if ($threadUid > 0 && $threadUid !== $fromUid) {
            af_aam_add_alert(
                $threadUid,
                'post_threadauthor',
                $tid,
                $fromUid,
                [
                    'tid'     => $tid,
                    'pid'     => $pid,
                    'subject' => $thread['subject'],
                    'fid'     => (int)$thread['fid'],
                ]
            );
        }

        // подписчики темы
        $subs = $db->write_query("
            SELECT s.uid
            FROM " . TABLE_PREFIX . "threadsubscriptions s
            INNER JOIN " . TABLE_PREFIX . "users u ON (u.uid=s.uid)
            WHERE s.tid={$tid} AND s.uid<>{$fromUid}
        ");
        while ($row = $db->fetch_array($subs)) {
            $subUid = (int)$row['uid'];
            if ($subUid <= 0 || $subUid === $threadUid) {
                continue;
            }

            af_aam_add_alert(
                $subUid,
                'subscribed_thread',
                $tid,
                $fromUid,
                [
                    'tid'     => $tid,
                    'pid'     => $pid,
                    'subject' => $thread['subject'],
                    'fid'     => (int)$thread['fid'],
                ]
            );
        }
    }

    // цитаты [quote="User"]
    if (!empty($data['message'])) {
        $message = (string)$data['message'];

        // никнеймы в тегах quote
        if (preg_match_all('#\[quote=("|\')(.*?)(\\1)[^\]]*\]#i', $message, $m)) {
            $names = array_unique(array_map('trim', $m[2]));
            if (!empty($names)) {
                $in = array_map([$db, 'escape_string'], $names);
                $inList = "'" . implode("','", $in) . "'";
                $sql = $db->simple_select('users', 'uid,username', "username IN({$inList})");
                while ($u = $db->fetch_array($sql)) {
                    $toUid = (int)$u['uid'];
                    if ($toUid <= 0 || $toUid === $fromUid) {
                        continue;
                    }

                    af_aam_add_alert(
                        $toUid,
                        'quoted',
                        $tid,
                        $fromUid,
                        [
                            'tid' => $tid,
                            'pid' => $pid,
                            'subject' => $thread['subject'] ?? '',
                        ]
                    );
                }
            }
        }

        // упоминания вида @"Имя Фамилия" или @username
        $mentionedUsernames = [];

        if (preg_match_all('#@\"([^"]+)\"#u', $message, $m1)) {
            foreach ($m1[1] as $name) {
                $n = trim($name);
                if ($n !== '') {
                    $mentionedUsernames[] = $n;
                }
            }
        }

        if (preg_match_all('#@([\p{L}\p{N}_\.]+)#u', $message, $m2)) {
            foreach ($m2[1] as $name) {
                $n = trim($name);
                if ($n !== '') {
                    $mentionedUsernames[] = $n;
                }
            }
        }

        $mentionedUsernames = array_unique($mentionedUsernames);
        if (!empty($mentionedUsernames)) {
            $in = array_map([$db, 'escape_string'], $mentionedUsernames);
            $inList = "'" . implode("','", $in) . "'";
            $sql = $db->simple_select('users', 'uid,username', "username IN({$inList})");
            while ($u = $db->fetch_array($sql)) {
                $toUid = (int)$u['uid'];
                if ($toUid <= 0 || $toUid === $fromUid) {
                    continue;
                }

                af_aam_add_alert(
                    $toUid,
                    'mention',
                    $tid,
                    $fromUid,
                    [
                        'tid'     => $tid,
                        'pid'     => $pid,
                        'subject' => $thread['subject'] ?? '',
                    ]
                );
            }
        }
    }
}

// ================ ФОРМАТИРОВАНИЕ ТЕКСТА УВЕДОМЛЕНИЙ =============

function af_aam_format_alert(array $alert): array
{
    global $lang, $mybb;

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    $code  = $alert['code'] ?? '';
    $extra = [];
    if (!empty($alert['extra'])) {
        $decoded = json_decode($alert['extra'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    $fromUser = af_aam_format_username((int)($alert['from_uid'] ?? 0));
    $subject  = (string)($extra['subject'] ?? '');
    $pid      = (int)($extra['pid'] ?? 0);
    $tid      = (int)($extra['tid'] ?? (int)($alert['object_id'] ?? 0));

    $url = '';
    $text = $code;

    switch ($code) {
        case 'rep':
            $repChange = (int)($extra['reputation'] ?? 0);
            $text = $lang->sprintf($lang->af_aam_text_rep, $fromUser, $repChange);
            $targetUid = (int)($alert['uid'] ?? 0);
            $url = 'reputation.php?uid=' . $targetUid;
            if (!empty($extra['rid'])) {
                $url .= '#rid' . (int)$extra['rid'];
            }
            break;

        case 'pm':
            $text = $lang->sprintf($lang->af_aam_text_pm, $fromUser, $subject);
            if (!empty($alert['object_id'])) {
                $url = 'private.php?action=read&pmid=' . (int)$alert['object_id'];
            }
            break;

        case 'post_threadauthor':
            $text = $lang->sprintf($lang->af_aam_text_reply, $fromUser, $subject);
            $url = 'showthread.php?tid=' . $tid;
            if ($pid > 0) {
                $url .= '&pid=' . $pid . '#pid' . $pid;
            }
            break;

        case 'subscribed_thread':
            $text = $lang->sprintf($lang->af_aam_text_subscribed, $fromUser, $subject);
            $url = 'showthread.php?tid=' . $tid;
            if ($pid > 0) {
                $url .= '&pid=' . $pid . '#pid' . $pid;
            }
            break;

        case 'quoted':
            $text = $lang->sprintf($lang->af_aam_text_quote, $fromUser, $subject);
            $url = 'showthread.php?tid=' . $tid;
            if ($pid > 0) {
                $url .= '&pid=' . $pid . '#pid' . $pid;
            }
            break;

        case 'mention':
            $text = $lang->sprintf($lang->af_aam_text_mention, $fromUser, $subject);
            $url = 'showthread.php?tid=' . $tid;
            if ($pid > 0) {
                $url .= '&pid=' . $pid . '#pid' . $pid;
            }
            break;

        default:
            $labelKey = 'af_aam_alert_type_' . $code;
            $text = $lang->{$labelKey} ?? $code;
    }

    return [
        'text' => $text,
        'url'  => $url,
    ];
}

function af_aam_format_alert_text(array $alert): string
{
    $formatted = af_aam_format_alert($alert);
    return $formatted['text'];
}

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

// ================ ПОСТБИТ: КНОПКА «СОБАЧКА» ===================

function af_aam_postbit_mention_button(array &$post): void
{
    global $lang;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    if (empty($post['uid']) || empty($post['username'])) {
        return;
    }

    $username = $post['username']; // уже очищено MyBB
    $title = htmlspecialchars_uni($lang->af_aam_mention_button);

    // компактная собачка, кликабельная
    $button = '<a href="javascript:void(0)" class="af-aam-mention-button" data-username="' .
        htmlspecialchars_uni($username) . '" title="' . $title . '">@</a>';

    // сначала — после кнопки репутации
    if (!empty($post['button_rep'])) {
        $post['button_rep'] .= ' ' . $button;
    }
    // если по какой-то причине репы нет — добавляем к цитате
    elseif (!empty($post['button_quote'])) {
        $post['button_quote'] .= ' ' . $button;
    } else {
        $post['button_quote'] = $button;
    }
}

