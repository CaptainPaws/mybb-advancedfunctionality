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
const AF_AAM_BASE         = 'inc/plugins/advancedfunctionality/addons/' . AF_AAM_ID . '/';
const AF_AAM_TABLE_ALERTS = 'aam_alerts';
const AF_AAM_TABLE_TYPES  = 'aam_alert_types';

// Подключаем форматтеры и описание типов
require_once MYBB_ROOT . AF_AAM_BASE . 'advancedalertsandmentionsformatters.php';

/**
 * Ленивая загрузка внутренних файлов аддона (чтобы не плодить require_once по коду).
 */
function af_aam_require(string $rel): void
{
    static $loaded = [];
    if (isset($loaded[$rel])) {
        return;
    }

    $path = MYBB_ROOT . AF_AAM_BASE . ltrim($rel, '/');
    if (is_file($path)) {
        require_once $path;
    }

    $loaded[$rel] = true;
}

function af_aam_get_setting_texts(string $settingName, string $fallbackTitle, string $fallbackDesc): array
{
    global $lang;

    // приоритет: стандартный стиль MyBB -> setting_{name}
    $titleKey = 'setting_' . $settingName;
    $descKey  = 'setting_' . $settingName . '_desc';

    $title = '';
    $desc  = '';

    if (isset($lang->{$titleKey}) && trim((string)$lang->{$titleKey}) !== '') {
        $title = (string)$lang->{$titleKey};
    } elseif (isset($lang->{$settingName}) && trim((string)$lang->{$settingName}) !== '') {
        // fallback на твой стиль ключей (если он есть)
        $title = (string)$lang->{$settingName};
    } else {
        $title = $fallbackTitle;
    }

    if (isset($lang->{$descKey}) && trim((string)$lang->{$descKey}) !== '') {
        $desc = (string)$lang->{$descKey};
    } elseif (isset($lang->{$settingName . '_desc'}) && trim((string)$lang->{$settingName . '_desc'}) !== '') {
        $desc = (string)$lang->{$settingName . '_desc'};
    } else {
        $desc = $fallbackDesc;
    }

    return [$title, $desc];
}


// База/репозиторий и подсказки упоминаний нужны не только для xmlhttp
af_aam_require('repo.php');
af_aam_require('mentions.php');
af_aam_require('xmlhttp.php');


/**
 * Регистрация (или обновление) типа уведомления. Используется и в админке, и клиентскими модулями.
 */
function af_aam_register_type(string $code, string $title = '', int $canBeUserDisabled = 1, int $defaultUserEnabled = 1, int $enabled = 1): ?int
{
    global $db;

    $code = trim($code);
    if ($code === '' || !$db->table_exists(AF_AAM_TABLE_TYPES)) {
        return null;
    }

    // если title пустой ИЛИ равен коду — используем нормальный фоллбек
    $title = trim($title);
    if ($title === '' || my_strtolower($title) === my_strtolower($code)) {
        $title = af_aam_default_type_title($code);
    }

    $escapedCode = $db->escape_string($code);

    $existing = $db->fetch_array($db->simple_select(AF_AAM_TABLE_TYPES, '*', "code='{$escapedCode}'"));
    if ($existing) {
        $update = [];

        // обновляем title, если:
        // - текущий title пустой
        // - или текущий title равен коду
        // - или пришёл другой title (и он не пустой)
        $existingTitle = (string)($existing['title'] ?? '');
        if ($title !== '' && ($existingTitle === '' || my_strtolower($existingTitle) === my_strtolower($code) || $title !== $existingTitle)) {
            $update['title'] = $db->escape_string($title);
        }

        if ((int)$existing['can_be_user_disabled'] !== (int)$canBeUserDisabled) {
            $update['can_be_user_disabled'] = (int)$canBeUserDisabled;
        }
        if ((int)$existing['default_user_enabled'] !== (int)$defaultUserEnabled) {
            $update['default_user_enabled'] = (int)$defaultUserEnabled;
        }
        if ((int)$existing['enabled'] !== (int)$enabled) {
            $update['enabled'] = (int)$enabled;
        }

        if (!empty($update)) {
            $db->update_query(AF_AAM_TABLE_TYPES, $update, "id=" . (int)$existing['id']);
        }

        return (int)$existing['id'];
    }

    $insert = [
        'code'                 => $escapedCode,
        'title'                => $db->escape_string($title),
        'enabled'              => (int)$enabled,
        'can_be_user_disabled' => (int)$canBeUserDisabled,
        'default_user_enabled' => (int)$defaultUserEnabled,
    ];

    $db->insert_query(AF_AAM_TABLE_TYPES, $insert);
    return (int)$db->insert_id();
}


/**
 * Слияние старых кодов типов (от MyAlerts) в наши канонические.
 * Прогоняется из install()/activate(), чтобы вычистить дубли.
 */
function af_aam_cleanup_legacy_types(): void
{
    global $db, $lang;

    if (!$db->table_exists(AF_AAM_TABLE_TYPES)) {
        return;
    }

    // old_code => new_code
    $aliases = [
        'reputation'   => 'rep',
        'subscription' => 'subscribed_thread',
        'reply'        => 'post_threadauthor',
        'quote'        => 'quoted',
    ];

    // на всякий случай подгружаем язык – пригодится для title
    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    foreach ($aliases as $oldCode => $newCode) {
        $oldEsc = $db->escape_string($oldCode);
        $newEsc = $db->escape_string($newCode);

        $oldRow = $db->fetch_array(
            $db->simple_select(AF_AAM_TABLE_TYPES, '*', "code='{$oldEsc}'")
        );
        $newRow = $db->fetch_array(
            $db->simple_select(AF_AAM_TABLE_TYPES, '*', "code='{$newEsc}'")
        );

        // вообще нет ни старого, ни нового — ничего не делаем
        if (!$oldRow && !$newRow) {
            continue;
        }

        // есть и старый, и новый → переносим все алерты и удаляем старый тип
        if ($oldRow && $newRow) {
            if ($db->table_exists(AF_AAM_TABLE_ALERTS)) {
                $db->update_query(
                    AF_AAM_TABLE_ALERTS,
                    ['type_id' => (int)$newRow['id']],
                    "type_id=" . (int)$oldRow['id']
                );
            }

            $db->delete_query(AF_AAM_TABLE_TYPES, "id=" . (int)$oldRow['id']);
            continue;
        }

        // есть только старый, нового нет → переименовываем старый в канонический код
        if ($oldRow && !$newRow) {
            $update = ['code' => $newEsc];

            $labelKey = 'af_aam_alert_type_' . $newCode;
            if (isset($lang->{$labelKey})) {
                $update['title'] = $db->escape_string($lang->{$labelKey});
            }

            $db->update_query(AF_AAM_TABLE_TYPES, $update, "id=" . (int)$oldRow['id']);
        }
    }
}

/**
 * Точка входа для ядра AdvancedFunctionality:
 * вызывается из af_{id}_init() внутри хуков global_start.
 */
function af_advancedalertsandmentions_init(): void
{
    global $plugins;

    // глобальная инициализация (подключение CSS/JS, шапка)
    af_aam_bootstrap();
    af_aam_backfill_type_titles();


    // остальные хуки (после global_start)
    $plugins->add_hook('usercp_menu',                  'af_aam_usercp_menu');
    $plugins->add_hook('usercp_start',                 'af_aam_usercp_start');
    $plugins->add_hook('misc_start',                   'af_aam_misc_router');

    $plugins->add_hook('reputation_do_add_end',        'af_aam_rep_do_add_end');
    $plugins->add_hook('datahandler_pm_insert_end',    'af_aam_pm_insert_end');
    $plugins->add_hook('datahandler_post_insert_post', 'af_aam_post_insert_end');
    $plugins->add_hook('datahandler_post_insert_thread', 'af_aam_thread_insert_end');
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

function af_aam_install_task_file(): void
{
    $source = MYBB_ROOT . AF_AAM_BASE . 'tasks/task_af_aam_cleanup.php';
    $destination = MYBB_ROOT . 'inc/tasks/af_aam_cleanup.php';

    if (!file_exists($source) || !is_readable($source) || !is_dir(dirname($destination))) {
        return;
    }

    $contents = file_get_contents($source);
    if ($contents === false) {
        return;
    }

    $dstHash = file_exists($destination) ? md5_file($destination) : '';
    $srcHash = md5($contents);

    $shouldWrite = (!file_exists($destination) || !$dstHash || $dstHash !== $srcHash);
    if ($shouldWrite) {
        @file_put_contents($destination, $contents);
    }
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
    if (!$db->field_exists('af_aam_prefs', 'users')) {
        $db->add_column('users', 'af_aam_prefs', "MEDIUMTEXT NOT NULL");
    }

    // группа настроек AF: AAM
    $groupPrimary = 'af_' . AF_AAM_ID;
    $groupLegacy  = 'af_aam';
    $gid = null;

    $gids = [];
    $gq = $db->simple_select('settinggroups', 'gid,name', "name IN('{$groupPrimary}','{$groupLegacy}')");
    while ($g = $db->fetch_array($gq)) {
        $gids[$g['name']] = (int)$g['gid'];
    }

    if (isset($gids[$groupPrimary])) {
        $gid = $gids[$groupPrimary];
        if (isset($gids[$groupLegacy]) && $gids[$groupLegacy] !== $gid) {
            $db->update_query('settings', ['gid' => $gid], 'gid=' . (int)$gids[$groupLegacy]);
            $db->delete_query('settinggroups', 'gid=' . (int)$gids[$groupLegacy]);
        }
    } elseif (isset($gids[$groupLegacy])) {
        $gid = $gids[$groupLegacy];
        $db->update_query('settinggroups', [
            'name'        => $groupPrimary,
            'title'       => $db->escape_string($lang->af_aam_group),
            'description' => $db->escape_string($lang->af_aam_group_desc),
            'disporder'   => 50,
        ], 'gid=' . $gid);
    } else {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => $groupPrimary,
            'title'       => $db->escape_string($lang->af_aam_group),
            'description' => $db->escape_string($lang->af_aam_group_desc),
            'disporder'   => 50,
            'isdefault'   => 0,
        ]);
    }

    // --- правильные тексты для проблемных настроек (и фолбэки на случай пустого языка)
    [$autocleanTitle, $autocleanDesc] = af_aam_get_setting_texts(
        'af_aam_autoclean_days',
        'Автоочистка уведомлений (дней)',
        'Удалять уведомления старше N дней. 0 = не удалять автоматически.'
    );

    [$toastTitle, $toastDesc] = af_aam_get_setting_texts(
        'af_aam_toast_limit',
        'Лимит тост-уведомлений',
        'Сколько тостов максимум показывать подряд. 0 = выключить тосты полностью.'
    );

    $settings = [
        'af_aam_enabled' => [
            'title'       => $lang->af_aam_enabled ?? 'Включить уведомления',
            'description' => $lang->af_aam_enabled_desc ?? 'Включает систему уведомлений и упоминаний.',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 1,
        ],
        'af_aam_per_page' => [
            'title'       => $lang->af_aam_per_page ?? 'Уведомлений на страницу',
            'description' => $lang->af_aam_per_page_desc ?? 'Количество уведомлений в UCP.',
            'optionscode' => 'text',
            'value'       => '20',
            'disporder'   => 2,
        ],
        'af_aam_dropdown_limit' => [
            'title'       => $lang->af_aam_dropdown_limit ?? 'Уведомлений в выпадающем списке',
            'description' => $lang->af_aam_dropdown_limit_desc ?? 'Сколько последних уведомлений показывать в модалке/дропдауне.',
            'optionscode' => 'text',
            'value'       => '5',
            'disporder'   => 3,
        ],
        'af_aam_autorefresh' => [
            'title'       => $lang->af_aam_autorefresh ?? 'Автообновление (сек)',
            'description' => $lang->af_aam_autorefresh_desc ?? '0 = выключено. Иначе — интервал автообновления списка уведомлений.',
            'optionscode' => 'text',
            'value'       => '0',
            'disporder'   => 4,
        ],
        'af_aam_sound' => [
            'title'       => $lang->af_aam_sound ?? 'Звук уведомления',
            'description' => $lang->af_aam_sound_desc ?? 'Включить звук при новых уведомлениях (если браузер разрешает).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 5,
        ],
        'af_aam_max_alerts_per_user' => [
            'title'       => $lang->af_aam_max_alerts_per_user ?? 'Макс. уведомлений на пользователя',
            'description' => $lang->af_aam_max_alerts_per_user_desc ?? '0 = без лимита. Иначе — старые будут удаляться.',
            'optionscode' => 'text',
            'value'       => '0',
            'disporder'   => 6,
        ],
        'af_aam_autoclean_days' => [
            'title'       => $autocleanTitle,
            'description' => $autocleanDesc,
            'optionscode' => 'text',
            'value'       => '0',
            'disporder'   => 7,
        ],
        'af_aam_inactive_days' => [
            'title'       => $lang->af_aam_inactive_days ?? 'Очистка по неактивности (дней)',
            'description' => $lang->af_aam_inactive_days_desc ?? 'Удалять уведомления пользователей, которые не заходили N дней. 0 = выключено.',
            'optionscode' => 'text',
            'value'       => '0',
            'disporder'   => 8,
        ],
        'af_aam_toast_limit' => [
            'title'       => $toastTitle,
            'description' => $toastDesc,
            'optionscode' => 'text',
            'value'       => '5',
            'disporder'   => 9,
        ],
        'af_aam_mention_button_enabled' => [
            'title'       => $lang->af_aam_mention_button_enabled ?? 'Кнопка «Упомянуть» в постбите',
            'description' => $lang->af_aam_mention_button_enabled_desc ?? 'Если выключить — кнопка под постом исчезнет, но клик по никнейму (в постбите) всё равно будет вставлять упоминание в редактор.',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 20,
        ],
        'af_aam_all_allowed_groups' => [
            'title'       => $lang->af_aam_all_allowed_groups ?? '@all: кому разрешено использовать',
            'description' => $lang->af_aam_all_allowed_groups_desc ?? 'ID групп через запятую. Только эти группы смогут писать @all. Пусто = никто не может.',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 21,
        ],
        'af_aam_all_target_groups' => [
            'title'       => $lang->af_aam_all_target_groups ?? '@all: кому приходит уведомление',
            'description' => $lang->af_aam_all_target_groups_desc ?? 'ID групп через запятую. Пусто = всем зарегистрированным пользователям. Если указано — уведомление получат только пользователи, состоящие в этих группах (основная или доп. группа).',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 22,
        ],
    ];

    foreach ($settings as $name => $data) {
        $nameEsc = $db->escape_string($name);

        $existing = $db->fetch_array(
            $db->simple_select('settings', 'sid,title,description,gid,optionscode,value,disporder', "name='{$nameEsc}'", ['limit' => 1])
        );

        $row = $data;
        $row['name']        = $name;
        $row['gid']         = $gid;
        $row['title']       = (string)($row['title'] ?? '');
        $row['description'] = (string)($row['description'] ?? '');

        $rowDb = [
            'gid'         => (int)$row['gid'],
            'title'       => $db->escape_string($row['title']),
            'description' => $db->escape_string($row['description']),
            'optionscode' => $db->escape_string((string)$row['optionscode']),
            'value'       => $db->escape_string((string)$row['value']),
            'disporder'   => (int)$row['disporder'],
        ];

        if ($existing) {
            $upd = [];

            // фикс пустых заголовков/описаний (твоя проблема №1)
            if (trim((string)$existing['title']) === '' && trim((string)$row['title']) !== '') {
                $upd['title'] = $rowDb['title'];
            }
            if (trim((string)$existing['description']) === '' && trim((string)$row['description']) !== '') {
                $upd['description'] = $rowDb['description'];
            }

            // приводим gid/параметры к актуальным (безопасно)
            if ((int)$existing['gid'] !== (int)$gid) {
                $upd['gid'] = (int)$gid;
            }
            if ((string)$existing['optionscode'] !== (string)$data['optionscode']) {
                $upd['optionscode'] = $rowDb['optionscode'];
            }
            if ((string)$existing['value'] === '' && (string)$data['value'] !== '') {
                // не перезатираем пользовательские значения, но заполняем пустое
                $upd['value'] = $rowDb['value'];
            }
            if ((int)$existing['disporder'] !== (int)$data['disporder']) {
                $upd['disporder'] = $rowDb['disporder'];
            }

            if (!empty($upd)) {
                $db->update_query('settings', $upd, 'sid=' . (int)$existing['sid']);
            }
            continue;
        }

        $insert = $rowDb;
        $insert['name'] = $nameEsc;
        $db->insert_query('settings', $insert);
    }

    rebuild_settings();

    // дефолтные типы уведомлений
    $defaultTypes = ['rep', 'pm', 'post_threadauthor', 'subscribed_thread', 'quoted', 'mention'];

    foreach ($defaultTypes as $code) {
        $titleKey = 'af_aam_alert_type_' . $code;
        $title = isset($lang->{$titleKey}) ? $lang->{$titleKey} : $code;

        af_aam_register_type($code, $title, 1, 1, 1);
    }

    af_aam_ensure_mycode();
    af_aam_install_templates();
    af_aam_cleanup_legacy_types();
    af_aam_install_task_file();

    // таск автоочистки по неактивности
    require_once MYBB_ROOT . 'inc/functions_task.php';
    $existingTask = $db->fetch_field(
        $db->simple_select('tasks', 'tid', "file='af_aam_cleanup'"),
        'tid'
    );

    if (!$existingTask) {
        $task = [
            'title'       => 'AF AAM: Inactive users cleanup',
            'description' => 'Удаление уведомлений неактивных пользователей',
            'file'        => 'af_aam_cleanup',
            'minute'      => '0',
            'hour'        => '3',
            'day'         => '*',
            'month'       => '*',
            'weekday'     => '*',
            'enabled'     => 1,
            'logging'     => 1,
        ];

        $task['nextrun'] = fetch_next_run($task);
        $db->insert_query('tasks', $task);
    }
}


function af_advancedalertsandmentions_uninstall(): void
{
    global $db, $cache;

    if ($db->table_exists(AF_AAM_TABLE_ALERTS)) {
        $db->drop_table(AF_AAM_TABLE_ALERTS);
    }
    if ($db->table_exists(AF_AAM_TABLE_TYPES)) {
        $db->drop_table(AF_AAM_TABLE_TYPES);
    }

    if ($db->field_exists('af_aam_disabled_types', 'users')) {
        $db->drop_column('users', 'af_aam_disabled_types');
    }
    if ($db->field_exists('af_aam_prefs', 'users')) {
        $db->drop_column('users', 'af_aam_prefs');
    }

    if ($db->table_exists(AF_AAM_TABLE_TELEGRAM)) {
        $db->drop_table(AF_AAM_TABLE_TELEGRAM);
    }

    // настройки — ОДИН раз, без дублей
    $db->delete_query(
        'settings',
        "name IN(
            'af_aam_enabled',
            'af_aam_per_page',
            'af_aam_dropdown_limit',
            'af_aam_autorefresh',
            'af_aam_sound',
            'af_aam_autoclean_days',
            'af_aam_toast_limit',
            'af_aam_max_alerts_per_user',
            'af_aam_inactive_days',
            'af_aam_mention_button_enabled',
            'af_aam_all_allowed_groups',
            'af_aam_all_target_groups'

        )"
    );
    $db->delete_query('settinggroups', "name IN('af_aam','af_" . AF_AAM_ID . "')");

    if ($cache) {
        $cache->delete('af_aam_last_autoclean');
    }

    $db->delete_query('tasks', "file='af_aam_cleanup'");

    $taskFile = MYBB_ROOT . 'inc/tasks/af_aam_cleanup.php';
    if (file_exists($taskFile)) {
        @unlink($taskFile);
    }

    rebuild_settings();

    // удаляем шаблоны
    $titles = [
        'af_aam_header_icon',
        'af_aam_modal',
        'af_aam_list_page',
        'af_aam_list_row',
        'af_aam_ucp_prefs',
        'af_aam_ucp_prefs_row',
        'af_aam_alert_row_popup',
        'af_aam_alert_row_popup_empty',
        'af_aam_js_popup',
    ];
    $in = "'" . implode("','", array_map('my_strtolower', $titles)) . "'";
    $db->delete_query('templates', "title IN({$in})");

    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
    find_replace_templatesets('headerinclude', '#{\$af_aam_js}{\$af_aam_css}#i', '');
    find_replace_templatesets('header_welcomeblock_member', '#{\$af_aam_header_icon}#i', '');
    find_replace_templatesets('footer', '#{\$af_aam_modal}#i', '');
}

function af_aam_ensure_mycode(): void
{
    global $db, $lang;

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    $hasAllowHtml     = $db->field_exists('allowhtml', 'mycode');
    $hasAllowMyCode   = $db->field_exists('allowmycode', 'mycode');
    $hasAllowSmilies  = $db->field_exists('allowsmilies', 'mycode');
    $hasAllowImgCode  = $db->field_exists('allowimgcode', 'mycode');
    $hasAllowVideo    = $db->field_exists('allowvideocode', 'mycode');

    /**
     * ВАЖНО:
     * - Regex максимально безопасные (не допускаем < > " и скобки), иначе XSS через MyCode — это реальность.
     * - parseorder:
     *   @all ставим раньше, потом mention.
     */
    $mycodes = [
        [
            'title'       => 'AF AAM Mention All',
            'description' => $lang->af_aam_mycode_desc ?? '@all',
            // ловим @all как отдельное слово (не @all123 и не mail@all.com)
            'regex'       => '(^|[^a-zA-Z0-9_])@all(?![a-zA-Z0-9_])',
            'replacement' => '$1<a href="javascript:void(0)" class="af-aam-mention-all" data-mention-all="1">@all</a>',
            'parseorder'  => 48,
        ],
        [
            'title'       => 'AF AAM Mention (uid)',
            'description' => $lang->af_aam_mycode_desc ?? 'Упоминание пользователя с привязкой к uid',
            // username ограничиваем безопасными символами (без < > " [ ])
            'regex'       => '\[mention=([0-9]+)\]([^\r\n\[\]<>"]+)\[/mention\]',
            // @ И username внутри ОДНОЙ ссылки (и жирность/цвет будут “как у ссылки”)
            'replacement' => '<a class="af-aam-mention-link" data-uid="$1" data-username="$2" href="member.php?action=profile&amp;uid=$1">@${2}</a>',
            'parseorder'  => 50,
        ],
        [
            'title'       => 'AF AAM Mention (name)',
            'description' => $lang->af_aam_mycode_desc ?? 'Упоминание пользователя по имени',
            // аналогично: безопасный набор
            'regex'       => '\[mention\]([^\r\n\[\]<>"]+)\[/mention\]',
            'replacement' => '<a class="af-aam-mention-link" data-username="$1" href="member.php?action=profile&amp;username=$1">@${1}</a>',
            'parseorder'  => 51,
        ],
    ];

    foreach ($mycodes as $code) {
        $row = [
            'title'       => $db->escape_string($code['title']),
            'description' => $db->escape_string($code['description']),
            'regex'       => $db->escape_string($code['regex']),
            'replacement' => $db->escape_string($code['replacement']),
            'active'      => 1,
            'parseorder'  => (int)$code['parseorder'],
        ];

        if ($hasAllowHtml)    { $row['allowhtml'] = 0; }
        if ($hasAllowMyCode)  { $row['allowmycode'] = 1; }
        if ($hasAllowSmilies) { $row['allowsmilies'] = 1; }
        if ($hasAllowImgCode) { $row['allowimgcode'] = 0; }
        if ($hasAllowVideo)   { $row['allowvideocode'] = 0; }

        $existing = $db->fetch_array(
            $db->simple_select('mycode', 'cid', "title='{$row['title']}'")
        );

        if ($existing) {
            $db->update_query('mycode', $row, 'cid=' . (int)$existing['cid']);
        } else {
            $db->insert_query('mycode', $row);
        }
    }
}



// AF-ядро само управляет "включено/выключено"
// При активации прогоняем install() ещё раз, чтобы обновить шаблоны/вставки
function af_advancedalertsandmentions_activate(): void
{
    af_advancedalertsandmentions_install();

    // гарантированно лечим title=code сразу при активации
    af_aam_backfill_type_titles();
}


function af_advancedalertsandmentions_deactivate(): void
{
    // Специально ничего не трогаем: AF просто перестаёт вызывать init()
}

// помощник для добавления шаблонов (upsert)
function af_aam_insert_template(string $title, string $template): void
{
    global $db;

    $titleEsc = $db->escape_string($title);

    $data = [
        'template' => $db->escape_string($template),
        'version'  => '1839',
        'dateline' => TIME_NOW,
    ];

    // Ищем ВСЕ шаблоны с таким title (и мастер, и кастомные у тем)
    $query = $db->simple_select('templates', 'tid,sid', "title='{$titleEsc}'");
    $found = false;

    while ($row = $db->fetch_array($query)) {
        $found = true;
        $db->update_query('templates', $data, "tid=".(int)$row['tid']);
    }

    // Если ни одного не было — создаём мастер-шаблон sid = -2
    if (!$found) {
        $data['title'] = $titleEsc;
        $data['sid']   = -2;
        $db->insert_query('templates', $data);
    }
}


/**
 * Читает templates из advancedalertsandmentions.html.
 * Формат блоков:
 * <!-- TEMPLATE: af_aam_header_icon --> ... <!-- /TEMPLATE -->
 */
function af_aam_load_templates_from_file(): array
{
    // было: MYBB_ROOT . AF_AAM_BASE . 'advancedalertsandmentions.html'
    // стало: templates/advancedalertsandmentions.html
    $file = MYBB_ROOT . AF_AAM_BASE . 'templates/advancedalertsandmentions.html';

    if (!file_exists($file) || !is_readable($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    if ($contents === false) {
        return [];
    }

    $templates = [];
    if (preg_match_all(
        '/<!--\s*TEMPLATE:\s*([a-z0-9_]+)\s*-->(.*?)<!--\s*\/TEMPLATE\s*-->/is',
        $contents,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $m) {
            $name = trim($m[1]);
            $tpl  = trim($m[2]);
            if ($name !== '' && $tpl !== '') {
                $templates[$name] = $tpl;
            }
        }
    }

    return $templates;
}



/**
 * Установка/обновление шаблонов и врезка в header/footer.
 */
function af_aam_install_templates(): void
{
    global $db;

    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    // грузим шаблоны из файла
    $fileTemplates = af_aam_load_templates_from_file();

    // must-have список шаблонов
    $required = [
        'af_aam_header_icon',
        'af_aam_modal',
        'af_aam_alert_row_popup',
        'af_aam_alert_row_popup_empty',
        'af_aam_list_page',
        'af_aam_list_row',
        'af_aam_ucp_prefs',
        'af_aam_ucp_prefs_row',
        'af_aam_js_popup',
    ];

    foreach ($required as $title) {
        if (!isset($fileTemplates[$title])) {
            continue;
        }
        af_aam_insert_template($title, $fileTemplates[$title]);
    }

    // сначала вычистим старые вставки, если они уже есть
    find_replace_templatesets('headerinclude', '#{\$af_aam_js}{\$af_aam_css}#i', '');
    find_replace_templatesets('header_welcomeblock_member', '#{\$af_aam_header_icon}#i', '');
    find_replace_templatesets('footer', '#{\$af_aam_modal}#i', '');

    // а теперь добавим по одному разу
    find_replace_templatesets('headerinclude', '#$#', '{$af_aam_js}{$af_aam_css}');
    find_replace_templatesets(
        'header_welcomeblock_member',
        '#{\$modcplink}#i',
        '{$af_aam_header_icon}{$modcplink}'
    );
    find_replace_templatesets('footer', '#$#', '{$af_aam_modal}');
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

function af_aam_parse_gid_csv(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $out = [];
    foreach (preg_split('/[,\s]+/', $raw) as $p) {
        $n = (int)trim($p);
        if ($n > 0) {
            $out[$n] = true;
        }
    }

    return array_keys($out);
}

function af_aam_user_group_ids(array $user): array
{
    $ids = [];

    $primary = (int)($user['usergroup'] ?? 0);
    if ($primary > 0) {
        $ids[$primary] = true;
    }

    $add = (string)($user['additionalgroups'] ?? '');
    if ($add !== '') {
        foreach (explode(',', $add) as $p) {
            $n = (int)trim($p);
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
    }

    return array_keys($ids);
}

function af_aam_user_in_any_group(array $user, array $allowedGids): bool
{
    if (empty($allowedGids)) {
        return false;
    }

    $userGids = af_aam_user_group_ids($user);
    foreach ($userGids as $gid) {
        if (in_array((int)$gid, $allowedGids, true)) {
            return true;
        }
    }
    return false;
}

function af_aam_should_load_mentions_js(): bool
{
    if (!af_aam_is_enabled()) {
        return false;
    }

    if (!empty($GLOBALS['in_admincp']) || defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return false;
    }

    global $mybb;
    if (empty($mybb->user['uid'])) {
        return false;
    }

    // Скрипт с подсказками нужен везде, где может появиться редактор или кнопка «Упомянуть».
    // Раньше он грузился только на нескольких страницах, из-за чего клики по нику/кнопке
    // перестали работать после изменений в шаблонах. Грузим всегда (кроме админки).
    return true;
}

function af_aam_bootstrap(): void
{
    global $mybb, $db, $templates, $lang;
    global $af_aam_js, $af_aam_css, $af_aam_header_icon, $af_aam_modal, $af_aam_mentions_js;
    global $af_aam_unread, $af_aam_modal_list, $af_aam_new_indicator;
    global $af_aam_asset_base, $af_aam_autorefresh;
    global $af_aam_sound_enabled, $af_aam_toast_limit;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    // периодическая автоочистка даже без новых уведомлений
    af_aam_maybe_autoclean(false);

    // web-URL к аддону и ассетам
    $base = rtrim((string)$mybb->settings['bburl'], '/');
    $addonBaseUrl = $base . '/' . AF_AAM_BASE;          // .../addons/advancedalertsandmentions/
    $assetsUrl    = $addonBaseUrl . 'assets/';          // .../addons/advancedalertsandmentions/assets/

    // чтобы шаблоны могли использовать {$af_aam_asset_base} для JS/CSS
    $af_aam_asset_base  = $assetsUrl;
    $af_aam_autorefresh = (int)($mybb->settings['af_aam_autorefresh'] ?? 0);

    // cache-bust по mtime (после переносов это реально спасает нервы)
    $cssFile = MYBB_ROOT . AF_AAM_BASE . 'assets/advancedalertsandmentions.css';
    $cssVer  = is_file($cssFile) ? (int)@filemtime($cssFile) : TIME_NOW;

    $af_aam_css = '<link rel="stylesheet" href="' . $assetsUrl . 'advancedalertsandmentions.css?v=' . $cssVer . '" />';

    $af_aam_sound_enabled = (int)($mybb->settings['af_aam_sound'] ?? 1);
    $af_aam_toast_limit   = (int)($mybb->settings['af_aam_toast_limit'] ?? 5);

    // mentions js теперь тоже в assets/
    $mentionsFile = MYBB_ROOT . AF_AAM_BASE . 'assets/aam_mentions.js';
    $mentionsVer  = is_file($mentionsFile) ? (int)@filemtime($mentionsFile) : TIME_NOW;

    $af_aam_mentions_js = af_aam_should_load_mentions_js()
        ? '<script type="text/javascript" src="' . $assetsUrl . 'aam_mentions.js?v=' . $mentionsVer . '"></script>'
        : '';

    // гость — ничего
    if (empty($mybb->user['uid'])) {
        $af_aam_header_icon = '';
        $af_aam_modal       = '';
        $af_aam_js          = '';
        return;
    }

    // считаем непрочитанные
    $uid = (int)$mybb->user['uid'];
    $query = $db->simple_select(AF_AAM_TABLE_ALERTS, 'COUNT(id) AS cnt', "uid={$uid} AND is_read=0");
    $row = $db->fetch_array($query);

    $af_aam_unread = (int)($row['cnt'] ?? 0);
    $af_aam_new_indicator = ($af_aam_unread > 0) ? 'alerts--new' : '';

    // последние N уведомлений для модального окна (с учётом prefs)
    $limit = (int)($mybb->settings['af_aam_dropdown_limit'] ?? 0);
    if ($limit <= 0) {
        $limit = 20;
    }

    $prefs = af_aam_get_user_prefs($uid);
    $disabled = [];
    if (!empty($prefs['disabled_types']) && is_array($prefs['disabled_types'])) {
        $disabled = array_values(array_filter(array_map('strval', $prefs['disabled_types'])));
    }

    $items = af_aam_repo()->list_alerts($uid, false, $limit);
    if ($disabled) {
        $items = array_values(array_filter($items, function ($it) use ($disabled) {
            $code = (string)($it['type_code'] ?? $it['code'] ?? $it['type'] ?? '');
            return $code === '' ? true : !in_array($code, $disabled, true);
        }));
    }

    af_aam_attach_avatars($items, 32);
    $af_aam_modal_list = af_aam_render_rows($items);

    // собираем JS и header icon через шаблоны
    $af_aam_js = '';
    eval('$af_aam_js          = "'.$templates->get('af_aam_js_popup').'";');
    eval('$af_aam_header_icon = "'.$templates->get('af_aam_header_icon').'";');
    eval('$af_aam_modal       = "'.$templates->get('af_aam_modal').'";');
}


/**
 * Приводит URL уведомления к полному виду с учётом bburl.
 */
function af_aam_append_pid_fragment(string $url): string
{
    // если якорь уже есть — оставляем как есть
    if (strpos($url, '#') !== false) {
        return $url;
    }

    // добавляем #pid{pid}, если параметр есть, но якоря нет
    if (preg_match('~[?&]pid=([0-9]+)~', $url, $m)) {
        $pid = (int)$m[1];
        if ($pid > 0) {
            return $url . '#pid' . $pid;
        }
    }

    return $url;
}

function af_aam_normalize_url(string $url): string
{
    global $mybb;

    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = af_aam_append_pid_fragment($url);

    // Уже абсолютный URL
    if (preg_match('#^(https?|ftp)://#i', $url)) {
        return $url;
    }

    $base = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($base === '') {
        // На крайняк оставим как есть, чтобы хоть как-то кликалось
        return $url;
    }

    // Если строка начинается с / — клеим к корню
    if ($url[0] === '/') {
        return $base . $url;
    }

    // Обычный относительный путь
    return $base . '/' . $url;
}

function af_aam_default_type_title(string $code): string
{
    global $lang;

    $code = trim($code);

    // язык если есть
    $key = 'af_aam_alert_type_' . $code;
    if (isset($lang->{$key}) && trim((string)$lang->{$key}) !== '') {
        return (string)$lang->{$key};
    }

    // жёсткий fallback (RU)
    $map = [
        'mention'           => 'Упоминания по никнейму',
        'pm'                => 'Личные сообщения',
        'post_threadauthor' => 'Новый ответ в вашей теме',
        'quoted'            => 'Цитирования',
        'rep'               => 'Репутация',
        'subscribed_thread' => 'Ответ в теме подписки',
        'subscribed_forum'  => 'Новая тема в форуме',
    ];

    return $map[$code] ?? $code;
}

/**
 * Подлечить старые/пустые title у типов, чтобы в чекбоксах не торчали коды.
 */
function af_aam_backfill_type_titles(): void
{
    global $db, $lang;

    if (!$db->table_exists(AF_AAM_TABLE_TYPES)) {
        return;
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    // чиним:
    // 1) title пустой/NULL
    // 2) title == code (то, что у тебя сейчас и видно в ACP)
    $q = $db->simple_select(AF_AAM_TABLE_TYPES, 'id,code,title');
    while ($row = $db->fetch_array($q)) {
        $code  = trim((string)($row['code'] ?? ''));
        $title = (string)($row['title'] ?? '');

        if ($code === '') {
            continue;
        }

        if ($title === '' || my_strtolower($title) === my_strtolower($code)) {
            $fixed = af_aam_default_type_title($code);

            // не делаем лишних апдейтов, если вдруг fixed тоже равен коду
            if ($fixed !== '' && $fixed !== $title) {
                $db->update_query(
                    AF_AAM_TABLE_TYPES,
                    ['title' => $db->escape_string($fixed)],
                    'id=' . (int)$row['id']
                );
            }
        }
    }
}



/**
 * Нормализует аватар: делает абсолютным, и даёт дефолтный, если пусто.
 * $size — желаемый размер (квадрат).
 */

/**
 * Возвращает количество непрочитанных уведомлений пользователя.
 */
function af_aam_unread_count(int $uid): int
{
    global $db;

    $uid = (int)$uid;

    $q = $db->simple_select(
        AF_AAM_TABLE_ALERTS,
        'COUNT(id) AS cnt',
        "uid = {$uid} AND is_read = 0"
    );

    $row = $db->fetch_array($q);

    return (int)($row['cnt'] ?? 0);
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

    $active = in_array($mybb->get_input('action'), ['af_aam_list', 'af_aam_prefs'], true);
    $class  = 'usercp_nav_item usercp_nav_aam' . ($active ? ' active' : '');

    $row = '<tr>
    <td class="trow1 smalltext">
        <a href="usercp.php?action=af_aam_list" class="' . $class . '">' .
            htmlspecialchars_uni($lang->af_aam_link_alerts) .
        '</a>
    </td>
</tr>';

    // Пытаемся встроиться сразу под "Главная" (usercp_nav_home)
    $pattern = '#(<tr>\s*<td class="trow1 smalltext"><a href="usercp\.php" class="usercp_nav_item usercp_nav_home">\{\$lang->ucp_nav_home\}</a></td>\s*</tr>)#i';

    if (preg_match($pattern, $usercpnav)) {
        $usercpnav = preg_replace($pattern, '$1' . "\n" . $row, $usercpnav, 1);
    } else {
        // если вдруг шаблон уже правился и не совпадает — просто добавим в конец
        $usercpnav .= $row;
    }
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
        // Очистить все уведомления
        if ($mybb->request_method === 'post' && $mybb->get_input('af_aam_clear_all') !== '') {
            verify_post_check($mybb->get_input('my_post_key'));
            $db->delete_query(AF_AAM_TABLE_ALERTS, "uid={$uid}");
            redirect('usercp.php?action=af_aam_list', $lang->af_aam_cleared_all);
        }

        $page = max(1, (int)$mybb->get_input('page', MyBB::INPUT_INT));
        $offset = ($page - 1) * $perPage;

        $totalQuery = $db->simple_select(AF_AAM_TABLE_ALERTS, 'COUNT(id) AS cnt', "uid={$uid}");
        $totalRow = $db->fetch_array($totalQuery);
        $total = (int)($totalRow['cnt'] ?? 0);

        $af_aam_list_rows = '';
        if ($total > 0) {
            $sql = $db->write_query("
                SELECT a.*, t.code, t.title, u.username AS from_username, u.avatar AS from_avatar, u.avatardimensions AS from_avatardimensions
                FROM " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " a
                LEFT JOIN " . TABLE_PREFIX . AF_AAM_TABLE_TYPES . " t ON (t.id=a.type_id)
                LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid = a.from_uid)
                WHERE a.uid={$uid}
                ORDER BY a.dateline DESC
                LIMIT {$offset}, {$perPage}
            ");
            while ($alert = $db->fetch_array($sql)) {
                $formatted = af_aam_format_alert($alert);
                $text = $formatted['text'];

                $url = af_aam_normalize_url($formatted['url'] ?? '');
                if ($url === '') {
                    $url = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/usercp.php?action=af_aam_list';
                }

                $af_aam_url        = htmlspecialchars_uni($url);
                $date              = my_date($mybb->settings['dateformat'] . ' ' . $mybb->settings['timeformat'], (int)$alert['dateline']);
                $af_aam_read_class = ((int)$alert['is_read'] === 1) ? 'af-aam-row-read' : 'af-aam-row-unread';

                $af_aam_text       = htmlspecialchars_uni($text);
                $af_aam_date       = htmlspecialchars_uni($date);
                $af_aam_alert_id   = (int)$alert['id'];
                $af_aam_alert_avatar = af_aam_render_avatar_html((int)($alert['from_uid'] ?? 0), (string)($alert['from_username'] ?? ''));

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
    global $mybb;

    if (!af_aam_is_enabled()) {
        return;
    }

    if ($mybb->get_input('action') === 'af_mention_suggest') {
        if (!$mybb->user['uid']) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([]);
            exit;
        }

        // на всякий случай гарантируем, что helper'ы (включая af_aam_attach_avatars) загружены
        if (function_exists('af_aam_require')) {
            af_aam_require('xmlhttp.php');
        }

        $q = (string)$mybb->get_input('q');
        $items = af_aam_suggest_users($q, 10);

        // ДОБАВЛЕНО: приклеиваем аватары, чтобы фронт не рисовал "буквы"
        if (function_exists('af_aam_attach_avatars')) {
            af_aam_attach_avatars($items, 24);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($items, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mybb->get_input('action') === 'af_aam_list') {
        if (!$mybb->user['uid']) {
            error_no_permission();
        }
        redirect('usercp.php?action=af_aam_list');
        exit;
    }
}


/**
 * Вытаскивает ID уведомления из запроса:
 * поддерживает id, alert_id и alert_id[].
 */
function af_aam_request_alert_id(): int
{
    global $mybb;

    // 1) Прямой параметр id
    $id = (int)$mybb->get_input('id', MyBB::INPUT_INT);
    if ($id > 0) {
        return $id;
    }

    // 2) Одиночный alert_id
    $id = (int)$mybb->get_input('alert_id', MyBB::INPUT_INT);
    if ($id > 0) {
        return $id;
    }

    // 3) Массив alert_id[]
    $ids = $mybb->get_input('alert_id', MyBB::INPUT_ARRAY);
    if (is_array($ids) && !empty($ids)) {
        $first = reset($ids);
        $id = (int)$first;
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

/**
* Отправка JSON-ответа с возможным debug-блоком.
*
* @param array $payload Основные данные (ok, alerts, unread_count и т.п.)
* @param array $debug   Отладочная инфа: op, id, uid, affected_rows и т.п.
*/
function af_aam_json_response(array $payload, array $debug = [])
{
    header('Content-Type: application/json; charset=utf-8');

    if (!empty($debug)) {
        $payload['debug'] = $debug;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Контрактный xmlhttp-хендлер для AF core router:
 * AF вызывает af_{addon_id}_xmlhttp()
 */
function af_advancedalertsandmentions_xmlhttp(): bool
{
    if (!af_aam_is_enabled()) {
        return false;
    }

    // Вся логика — внутри af_aam_xmlhttp()
    af_aam_xmlhttp();
    return true; // формально, хотя ниже обычно будет exit
}

/**
 * Единая точка входа для xmlhttp-логики аддона.
 * Контроллер лежит в addons/advancedalertsandmentions/xmlhttp.php
 */
function af_aam_xmlhttp(): bool
{
    if (!af_aam_is_enabled()) {
        return false;
    }

    // Подгружаем контроллер xmlhttp
    af_aam_require('xmlhttp.php');

    // Функциональный контроллер
    if (function_exists('af_aam_xmlhttp_dispatch')) {
        af_aam_xmlhttp_dispatch(); // обычно делает JSON + exit
        return true;
    }

    // Классовый контроллер (если когда-то перейдёшь на класс)
    if (class_exists('AF_AAM_XmlHttp') && method_exists('AF_AAM_XmlHttp', 'dispatch')) {
        AF_AAM_XmlHttp::dispatch();
        return true;
    }

    return false;
}



// ================ ТРИГГЕРЫ УВЕДОМЛЕНИЙ =======================
function af_aam_get_type_id(string $code): ?int
{
    global $db;

    $code = trim($code);
    if ($code === '') {
        return null;
    }

    if (!$db->table_exists(AF_AAM_TABLE_TYPES)) {
        return null;
    }

    $escapedCode = $db->escape_string($code);
    $row = $db->fetch_array(
        $db->simple_select(
            AF_AAM_TABLE_TYPES,
            'id, enabled, title, code',
            "code='{$escapedCode}'"
        )
    );

    if ($row) {
        if ((int)$row['enabled'] !== 1) {
            return null;
        }

        // если тип есть, но title = '' или title = code — подлечим сразу
        $existingTitle = (string)($row['title'] ?? '');
        if ($existingTitle === '' || my_strtolower($existingTitle) === my_strtolower($code)) {
            $fixed = af_aam_default_type_title($code);
            $db->update_query(
                AF_AAM_TABLE_TYPES,
                ['title' => $db->escape_string($fixed)],
                'id=' . (int)$row['id']
            );
        }

        return (int)$row['id'];
    }

    // типа нет → регистрируем на лету уже с нормальным фоллбеком
    $title = af_aam_default_type_title($code);
    $newId = af_aam_register_type($code, $title, 1, 1, 1);

    if ($newId === null) {
        return null;
    }

    return (int)$newId;
}


function af_aam_user_allows_type(int $uid, string $code): bool
{
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

function af_aam_maybe_autoclean(bool $force = false): void
{
    global $db, $mybb, $cache;

    $days = (int)($mybb->settings['af_aam_autoclean_days'] ?? 0);
    if ($days <= 0) {
        return;
    }

    $minInterval = 3600; // не чаще раза в час, чтобы не грузить базу
    $state = $cache->read('af_aam_last_autoclean');
    $lastTs = is_array($state) && !empty($state['ts']) ? (int)$state['ts'] : 0;

    if (!$force && $lastTs > 0 && (TIME_NOW - $lastTs) < $minInterval) {
        return;
    }

    $threshold = TIME_NOW - ($days * 86400);
    $db->delete_query(AF_AAM_TABLE_ALERTS, "dateline < {$threshold}");
    $cache->update('af_aam_last_autoclean', ['ts' => TIME_NOW]);
}

function af_aam_enforce_max_alerts(int $uid): void
{
    global $db, $mybb;

    $limit = (int)($mybb->settings['af_aam_max_alerts_per_user'] ?? 0);
    if ($limit <= 0) {
        return;
    }

    $uid = (int)$uid;
    if ($uid <= 0) {
        return;
    }

    $query = $db->simple_select(AF_AAM_TABLE_ALERTS, 'COUNT(*) AS cnt', "uid={$uid}");
    $count = (int)$db->fetch_field($query, 'cnt');
    if ($count <= $limit) {
        return;
    }

    $toDelete = $count - $limit;
    $db->write_query(
        "DELETE FROM " . TABLE_PREFIX . AF_AAM_TABLE_ALERTS . " WHERE uid={$uid} ORDER BY dateline ASC, id ASC LIMIT {$toDelete}"
    );
}

function af_aam_cleanup_inactive_alerts(?int $inactiveDays = null): array
{
    global $db, $mybb;

    if ($inactiveDays === null) {
        $inactiveDays = (int)($mybb->settings['af_aam_inactive_days'] ?? 0);
    }

    $result = [
        'alerts_deleted'  => 0,
        'users_affected'  => 0,
        'disabled'        => false,
    ];

    if ($inactiveDays <= 0) {
        $result['disabled'] = true;
        return $result;
    }

    if (!$db->table_exists(AF_AAM_TABLE_ALERTS)) {
        return $result;
    }

    $cutoff = TIME_NOW - ($inactiveDays * 86400);
    $alertsTable = TABLE_PREFIX . AF_AAM_TABLE_ALERTS;
    $usersTable  = TABLE_PREFIX . 'users';

    $statsQuery = $db->write_query(
        "SELECT a.uid, COUNT(*) AS cnt FROM {$alertsTable} a " .
        "INNER JOIN {$usersTable} u ON u.uid = a.uid " .
        "WHERE u.lastactive > 0 AND u.lastactive < {$cutoff} GROUP BY a.uid"
    );

    while ($row = $db->fetch_array($statsQuery)) {
        $result['alerts_deleted'] += (int)$row['cnt'];
        $result['users_affected']++;
    }

    if ($result['alerts_deleted'] === 0) {
        return $result;
    }

    $db->write_query(
        "DELETE a FROM {$alertsTable} a INNER JOIN {$usersTable} u ON u.uid = a.uid " .
        "WHERE u.lastactive > 0 AND u.lastactive < {$cutoff}"
    );

    return $result;
}

function af_aam_add_alert(int $uid, string $code, int $objectId = 0, int $fromUid = 0, array $extra = [], int $forced = 0): void
{
    global $db, $mybb;

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
    $alertId = (int)$db->insert_id();
    // Автоочистка старых уведомлений
    af_aam_maybe_autoclean(true);
    af_aam_enforce_max_alerts($uid);

}

// репутация
function af_aam_rep_do_add_end(): void
{
    global $mybb, $reputation;

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
    global $mybb, $db;

    if (!af_aam_is_enabled()) {
        return;
    }

    if (!is_object($pmhandler)) {
        return;
    }

    // Данные сообщения
    $message = $pmhandler->pm_insert_data ?? [];
    $fromUid = (int)($message['fromid'] ?? 0);
    if ($fromUid <= 0) {
        return;
    }

    // Дата/время ЛС – используем для поиска строки в БД
    $dateline = (int)($message['dateline'] ?? TIME_NOW);

    // Получатели: сначала toid, потом recipients
    $recipients = [];
    if (!empty($message['toid'])) {
        $recipients = array_map('intval', explode(',', (string)$message['toid']));
    } elseif (!empty($message['recipients'])) {
        $recData = @unserialize($message['recipients']);
        if (is_array($recData) && !empty($recData['to']) && is_array($recData['to'])) {
            $recipients = array_map('intval', $recData['to']);
        }
    }

    if (empty($recipients)) {
        return;
    }

    $subject = (string)($message['subject'] ?? '');

    foreach ($recipients as $toUid) {
        $toUid = (int)$toUid;
        if ($toUid <= 0 || $toUid === $fromUid) {
            continue;
        }

        // Пробуем найти pmid для КОНКРЕТНОГО получателя:
        // в MyBB на каждого получателя создаётся отдельная строка в privatemessages
        $pmid = 0;

        $pmQuery = $db->simple_select(
            'privatemessages',
            'pmid',
            "uid={$toUid} AND fromid={$fromUid} AND dateline={$dateline}",
            [
                'order_by'  => 'pmid',
                'order_dir' => 'DESC',
                'limit'     => 1,
            ]
        );
        $pmid = (int)$db->fetch_field($pmQuery, 'pmid');

        // Даже если вдруг не нашли pmid — всё равно создадим алерт, просто с object_id = 0
        af_aam_add_alert(
            $toUid,
            'pm',
            $pmid,          // теперь почти всегда > 0
            $fromUid,
            [
                'subject' => $subject,
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

    // Черновики не трогаем
    if (!empty($posthandler->data['savedraft'])) {
        return;
    }

    // Исходные данные
    $data = $posthandler->data ?? [];
    $post = $posthandler->post_insert_data ?? [];

    $pid = 0;
    $tid = 0;

    $fromUid = (int)($mybb->user['uid'] ?? 0);
    if ($fromUid <= 0) {
        return;
    }

    if (!empty($post['pid'])) {
        $pid = (int)$post['pid'];
    }
    if (!empty($post['tid'])) {
        $tid = (int)$post['tid'];
    }

    if (!$pid && !empty($posthandler->return_values['pid'])) {
        $pid = (int)$posthandler->return_values['pid'];
    }
    if (!$tid && !empty($posthandler->return_values['tid'])) {
        $tid = (int)$posthandler->return_values['tid'];
    }

    if (!$tid && !empty($data['tid'])) {
        $tid = (int)$data['tid'];
    }

    // Без tid вообще не знаем, к какой теме привязать
    if ($tid <= 0) {
        return;
    }

    // pid может быть 0: в этом случае будем ссылаться хотя бы на тему
    $pid = (int)$pid;
    if ($pid <= 0) {
        $pidQuery = $db->simple_select(
            'posts',
            'pid',
            "tid={$tid} AND uid={$fromUid}",
            ['order_by' => 'dateline', 'order_dir' => 'DESC', 'limit' => 1]
        );
        $pid = (int)$db->fetch_field($pidQuery, 'pid');
    }

    // подгружаем тему
    $thread = get_thread($tid);
    if (!$thread) {
        return;
    }

    $threadUid = (int)($thread['uid'] ?? 0);

    // Текст поста
    $message = trim((string)($data['message'] ?? ''));

    // Список тех, кого мы уведомили по цитатам/упоминаниям/@all,
    // чтобы не дублировать "автор темы" и "подписки"
    $notifiedUids = [];

    if ($message !== '') {

        // 0) @all (упоминание всех/групп)
        // ВАЖНО: object_id у алерта = tid (а pid кладём в extra)
        if (function_exists('af_aam_handle_all_mention')) {
            af_aam_handle_all_mention(
                $message,
                $fromUid,
                (int)$tid,
                [
                    'tid'     => (int)$tid,
                    'pid'     => (int)$pid,
                    'fid'     => (int)($thread['fid'] ?? 0),
                    'subject' => (string)($thread['subject'] ?? ''),
                ]
            );
            // твой обработчик сейчас void, поэтому notifiedUids отсюда не расширяем
        }

        // 1) Цитаты + обычные упоминания (@ник, @"Имя", [mention])
        $notifiedUids = array_merge(
            $notifiedUids,
            af_aam_process_message_mentions($message, (int)$tid, (int)$pid, $thread, (int)$fromUid)
        );

        // нормализуем
        $notifiedUids = array_values(array_unique(array_map('intval', $notifiedUids)));
    }

    // 2) Уведомление автору темы (если это не он сам и не получил упоминание/цитату)
    if ($threadUid > 0 && $threadUid !== $fromUid && !in_array($threadUid, $notifiedUids, true)) {
        af_aam_add_alert(
            $threadUid,
            'post_threadauthor',
            (int)$tid,     // object_id = tid
            (int)$fromUid,
            [
                'tid'     => (int)$tid,
                'pid'     => (int)$pid,
                'subject' => (string)($thread['subject'] ?? ''),
                'fid'     => (int)($thread['fid'] ?? 0),
            ]
        );
    }

    // 3) Подписчики темы
    $subs = $db->write_query("
        SELECT s.uid
        FROM " . TABLE_PREFIX . "threadsubscriptions s
        INNER JOIN " . TABLE_PREFIX . "users u ON (u.uid=s.uid)
        WHERE s.tid={$tid} AND s.uid<>{$fromUid}
    ");
    while ($row = $db->fetch_array($subs)) {
        $subUid = (int)$row['uid'];
        if ($subUid <= 0 || $subUid === $threadUid || in_array($subUid, $notifiedUids, true)) {
            continue;
        }

        af_aam_add_alert(
            $subUid,
            'subscribed_thread',
            (int)$tid,     // object_id = tid
            (int)$fromUid,
            [
                'tid'     => (int)$tid,
                'pid'     => (int)$pid,
                'subject' => (string)($thread['subject'] ?? ''),
                'fid'     => (int)($thread['fid'] ?? 0),
            ]
        );
    }
}



/**
 * Обработка цитат и упоминаний в тексте поста/темы.
 */
function af_aam_process_message_mentions(string $message, int $tid, int $pid, array $thread, int $fromUid): array
{
    global $db;

    $notifiedUids = [];

    $tid = (int)$tid;
    $pid = (int)$pid;
    $fromUid = (int)$fromUid;

    if ($tid <= 0 || $fromUid <= 0) {
        return [];
    }

    $message = trim($message);
    if ($message === '') {
        return [];
    }

    $threadSubject = $thread['subject'] ?? '';

    // --- ЦИТАТЫ [quote="Username"] ---
    if (preg_match_all('#\[quote=("|\')(.*?)(\\1)[^\]]*\]#i', $message, $m)) {
        $names = array_unique(array_map('trim', $m[2]));
        if (!empty($names)) {
            $in     = array_map([$db, 'escape_string'], $names);
            $inList = "'" . implode("','", $in) . "'";
            $sql    = $db->simple_select('users', 'uid,username', "username IN({$inList})");

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
                        'tid'     => $tid,
                        'pid'     => $pid,
                        'subject' => $threadSubject,
                    ]
                );
                $notifiedUids[] = $toUid;
            }
        }
    }

    // --- УПОМИНАНИЯ: [mention], @"Имя Фамилия" и @username ---
    $mentionedUids      = [];
    $mentionedUsernames = [];

    // [mention]User[/mention] или [mention=123]User[/mention]
    if (preg_match_all('#\[mention(?:=([0-9]+))?\](.+?)\[/mention\]#iu', $message, $mTags)) {
        foreach ($mTags[2] as $idx => $name) {
            $idVal = isset($mTags[1][$idx]) ? (int)$mTags[1][$idx] : 0;
            if ($idVal > 0) {
                $mentionedUids[] = $idVal;
            }

            $n = trim($name);
            if ($n !== '') {
                $mentionedUsernames[] = $n;
            }
        }
    }

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

    $targetUsers = [];

    $mentionedUids = array_unique(array_filter(array_map('intval', $mentionedUids)));
    if (!empty($mentionedUids)) {
        $uidList = implode(',', $mentionedUids);
        $sqlByUid = $db->simple_select('users', 'uid,username', "uid IN({$uidList})");
        while ($u = $db->fetch_array($sqlByUid)) {
            $targetUsers[(int)$u['uid']] = $u['username'];
        }
    }

    $mentionedUsernames = array_unique($mentionedUsernames);
    if (!empty($mentionedUsernames)) {
        $in     = array_map([$db, 'escape_string'], $mentionedUsernames);
        $inList = "'" . implode("','", $in) . "'";
        $sql    = $db->simple_select('users', 'uid,username', "username IN({$inList})");

        while ($u = $db->fetch_array($sql)) {
            $targetUsers[(int)$u['uid']] = $u['username'];
        }
    }

    if (!empty($targetUsers)) {
        foreach ($targetUsers as $toUid => $toName) {
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
                    'subject' => $threadSubject,
                ]
            );
            $notifiedUids[] = $toUid;
        }
    }

    return array_values(array_unique($notifiedUids));
}

function af_aam_thread_insert_end(&$posthandler): void
{
    global $db, $mybb;

    if (!af_aam_is_enabled() || !is_object($posthandler)) {
        return;
    }

    // Черновики не трогаем
    if (!empty($posthandler->data['savedraft'])) {
        return;
    }

    $data = $posthandler->data ?? [];
    $post = $posthandler->post_insert_data ?? [];

    $tid = 0;
    $fid = 0;
    $pid = 0;

    if (!empty($post['tid'])) {
        $tid = (int)$post['tid'];
    } elseif (!empty($posthandler->return_values['tid'])) {
        $tid = (int)$posthandler->return_values['tid'];
    }

    if (!empty($post['pid'])) {
        $pid = (int)$post['pid'];
    } elseif (!empty($posthandler->return_values['pid'])) {
        $pid = (int)$posthandler->return_values['pid'];
    }

    if (!empty($data['fid'])) {
        $fid = (int)$data['fid'];
    } elseif (!empty($post['fid'])) {
        $fid = (int)$post['fid'];
    }

    if ($tid <= 0 || $fid <= 0) {
        return;
    }

    $fromUid = (int)($mybb->user['uid'] ?? 0);
    if ($fromUid <= 0) {
        return;
    }

    $thread  = get_thread($tid);
    $subject = '';

    if ($thread && !empty($thread['subject'])) {
        $subject = (string)$thread['subject'];
    } elseif (!empty($data['subject'])) {
        $subject = (string)$data['subject'];
    }

    // Упоминания в первом посте темы (включая @all)
    $message = trim((string)($data['message'] ?? ''));

    if ($message !== '') {

        // @all
        // ВАЖНО: object_id у алерта = tid, pid кладём в extra
        if (function_exists('af_aam_handle_all_mention')) {
            af_aam_handle_all_mention(
                $message,
                (int)$fromUid,
                (int)$tid,
                [
                    'tid'     => (int)$tid,
                    'pid'     => (int)$pid,
                    'fid'     => (int)$fid,
                    'subject' => (string)$subject,
                ]
            );
        }

        // обычные упоминания/цитаты
        af_aam_process_message_mentions(
            $message,
            (int)$tid,
            (int)$pid,
            ($thread ?: ['subject' => $subject, 'fid' => $fid]),
            (int)$fromUid
        );
    }

    // Все, кто подписан на форум, кроме автора темы
    $subs = $db->write_query("
        SELECT s.uid
        FROM " . TABLE_PREFIX . "forumsubscriptions s
        INNER JOIN " . TABLE_PREFIX . "users u ON (u.uid = s.uid)
        WHERE s.fid = {$fid} AND s.uid <> {$fromUid}
    ");

    while ($row = $db->fetch_array($subs)) {
        $toUid = (int)$row['uid'];
        if ($toUid <= 0 || $toUid === $fromUid) {
            continue;
        }

        af_aam_add_alert(
            $toUid,
            'subscribed_forum',
            (int)$tid,       // object_id = tid
            (int)$fromUid,
            [
                'tid'     => (int)$tid,
                'pid'     => (int)$pid,
                'fid'     => (int)$fid,
                'subject' => (string)$subject,
            ]
        );
    }
}


// ================ ПОСТБИТ: КНОПКА «СОБАЧКА» ===================
function af_aam_postbit_mention_button(array &$post): void
{
    global $mybb, $lang;

    if (!af_aam_is_enabled()) {
        return;
    }

    // гостям не нужно (и чтобы лишние data-атрибуты не лепить)
    if (empty($mybb->user['uid'])) {
        return;
    }

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_' . AF_AAM_ID);
    }

    if (empty($post['uid']) || empty($post['username'])) {
        return;
    }

    $uid      = (int)$post['uid'];
    $username = (string)$post['username']; // MyBB уже чистит username

    /**
     * 1) ВСЕГДА делаем кликабельным ник автора (для вставки mention по клику),
     * даже если кнопка "@" выключена настройкой.
     */
    if (!empty($post['profilelink']) && is_string($post['profilelink'])) {
        if (stripos($post['profilelink'], 'af-aam-mention-user') === false) {
            $post['profilelink'] = preg_replace_callback(
                '#<a\b([^>]*)>#i',
                function ($m) use ($uid, $username) {
                    $attrs = $m[1];

                    $data = ' data-uid="' . (int)$uid . '" data-username="' . htmlspecialchars_uni($username) . '"';

                    if (preg_match('#\bclass\s*=\s*("|\')([^"\']*)\1#i', $attrs)) {
                        $attrs = preg_replace(
                            '#\bclass\s*=\s*("|\')([^"\']*)\1#i',
                            'class="$2 af-aam-mention-user"',
                            $attrs,
                            1
                        );
                        return '<a' . $attrs . $data . '>';
                    }

                    return '<a class="af-aam-mention-user"' . $attrs . $data . '>';
                },
                $post['profilelink'],
                1
            );
        }
    }

    /**
     * 2) КНОПКА "@" — только если включена настройкой.
     * Фолбэк на альтернативное имя (на случай если где-то сохранилось иначе).
     */
    $mentionButtonEnabled = $mybb->settings['af_aam_mention_button_enabled']
        ?? $mybb->settings['af_' . AF_AAM_ID . '_mention_button_enabled']
        ?? 1;

    if ((int)$mentionButtonEnabled !== 1) {
        return; // кнопку не добавляем, но "клик по нику" уже включён выше
    }

    $title = htmlspecialchars_uni($lang->af_aam_mention_button ?? 'Mention');

    $button = '<a href="javascript:void(0)" class="af-aam-mention-button" data-username="' .
        htmlspecialchars_uni($username) . '" data-uid="' . $uid . '" title="' . $title . '">@</a>';

    // Вставка кнопки в блок кнопок постбита
    if (!empty($post['button_rep'])) {
        $post['button_rep'] .= ' ' . $button;
    } elseif (!empty($post['button_quote'])) {
        $post['button_quote'] .= ' ' . $button;
    } else {
        $post['button_quote'] = $button;
    }
}
