<?php
/**
 * Advanced functionality — экосистема внутренних аддонов
 * MyBB 1.8.x, PHP 8.0–8.4
 *
 * Добавлено:
 *  - Автогенерация языковых файлов RU/EN (фронт и админ) для ядра и внутренних аддонов.
 *  - Синхронизация языков аддонов по описанию в manifest.php['lang'].
 */

if (!defined('IN_MYBB')) { die('No direct access'); }


define('AF_PLUGIN_ID', 'advancedfunctionality');
define('AF_BASE', MYBB_ROOT.'inc/plugins/'.AF_PLUGIN_ID.'/');
define('AF_ADDONS', AF_BASE.'addons/');
define('AF_ADMIN', AF_BASE.'admin/');
define('AF_ASSETS', AF_BASE.'assets/');
define('AF_CACHE',  AF_BASE.'cache/');
define('AF_ADMIN_PROXY_DIR', MYBB_ROOT.'admin/modules/'.AF_PLUGIN_ID.'/');
define('AF_ADMIN_PROXY', AF_ADMIN_PROXY_DIR.'index.php');
define('AF_SETTINGS_FILE', MYBB_ROOT.'inc/settings.php');


// Папки языков (ядро)
define('AF_LANG_BASE', MYBB_ROOT.'inc/languages/');
define('AF_LANG_EN', AF_LANG_BASE.'english/');
define('AF_LANG_EN_ADMIN', AF_LANG_EN.'admin/');
define('AF_LANG_RU', AF_LANG_BASE.'russian/');
define('AF_LANG_RU_ADMIN', AF_LANG_RU.'admin/');

global $plugins;

/* ========================= INFO ========================= */
function advancedfunctionality_info()
{
    return [
        'name'          => 'Advanced functionality',
        'description'   => 'Экосистема внутренних аддонов: единая точка включения/отключения и параметры.',
        'website'       => '',
        'author'        => 'CaptainPaws',
        'authorsite'    => 'https://github.com/CaptainPaws',
        'version'       => '1.1.0',
        'compatibility' => '18*',
        'codename'      => AF_PLUGIN_ID
    ];
}

function advancedfunctionality_is_installed()
{
    global $db;
    $query = $db->simple_select('settinggroups', 'gid', "name='af_core'", ['limit' => 1]);
    return (bool)$db->fetch_field($query, 'gid');
}

function advancedfunctionality_install()
{
    // ВАЖНО: никаких $lang->load() ДО записи файлов языков.

    // 1) Папки плагина + языковые каталоги
    af_ensure_scaffold(/*force_refresh*/ false);

    // 2) Сразу пишем языковые файлы ядра (EN/RU, front/admin)
    af_ensure_core_languages(true);

    // 3) Настройки ядра
    $gid = af_ensure_settinggroup('af_core', 'Advanced functionality', 'Основные настройки экосистемы аддонов');
    af_ensure_setting('af_core', 'af_core_admin_link', 'Ссылка в админке', 'Показывать пункт "Расширенный функционал" в меню Конфигурация.', 'yesno', '1', 1);
    rebuild_settings();

    // 4) Прокси-модуль ACP (чтобы модуль сразу открывался)
    af_write_admin_proxy();

    // 5) Первичная синхронизация языков уже лежащих аддонов (если есть)
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        af_sync_addon_languages($meta, true);
    }

    // 6) Служебный кэш
    af_write_cache('installed_at', (string)TIME_NOW);
}

/**
 * ЕДИНСТВЕННАЯ версия activate() — объединяет обе логики:
 * - пересборка папок/роутера
 * - обновление языков ядра и аддонов
 * - запись last_activation
 */
function advancedfunctionality_activate()
{
    // 1) Папки и «железо»
    af_ensure_scaffold(true);

    // 2) Языки ядра (обновим на случай правок)
    af_ensure_core_languages(true);

    // 3) Языки всех найденных аддонов (ленивое обновление)
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        af_sync_addon_languages($meta, true);
    }

    // 4) Прокси для ACP — пересоберём
    af_write_admin_proxy();

    // 5) Кэш
    af_write_cache('last_activation', (string)TIME_NOW);
}

function advancedfunctionality_uninstall()
{
    global $db;

    $gid = af_find_gid('af_core');
    if ($gid) {
        $db->delete_query('settings', "gid='".(int)$gid."'");
        $db->delete_query('settinggroups', "gid='".(int)$gid."'");
        rebuild_settings();
    }

    // Сносим admin/modules/advancedfunctionality полностью (и proxy, и meta)
    $adminAbs = af_admin_absdir();
    $modDir   = rtrim($adminAbs,'/').'/modules/'.AF_PLUGIN_ID;

    if (is_dir($modDir)) {
        // безопасное рекурсивное удаление
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($modDir);
    }

    // Папки и языки ядра не трогаем — по требованиям оставляем экосистему.
}


function advancedfunctionality_deactivate()
{
    af_write_cache('needs_refresh', '1');
}

/* ========================= LANG LOAD ========================= */
function advancedfunctionality_load_lang()
{
    global $lang;
    if (!isset($lang->af)) {
        $lang->load('advancedfunctionality');
    }
    // В админке MyBB сам подхватывает admin/версию, но продублируем:
    if (defined('IN_ADMINCP')) {
        $lang->load('advancedfunctionality');
    }
}

/** Абсолютный путь к каталогу админки (устойчиво к кастомному имени папки) */
function af_admin_absdir(): string
{
    $root = rtrim(MYBB_ROOT, '/');

    if (defined('MYBB_ADMIN_DIR')) {
        $d = rtrim((string)MYBB_ADMIN_DIR, '/');
    } else {
        $d = 'admin';
    }
    if ($d === '' || $d === '.' || $d === '..') { $d = 'admin'; }

    // Абсолютный путь?
    if (isset($d[0]) && ($d[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $d))) {
        return $d;
    }
    return $root.'/'.ltrim($d, '/');
}

function af_rebuild_and_reload_settings(): void
{
    global $mybb;

    // 1) Пересобрать inc/settings.php из БД
    rebuild_settings();

    // 2) Если включён OPcache — инвалидируем конкретный файл
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(AF_SETTINGS_FILE, /*force*/ true);
    }

    // 3) На всякий случай — сброс метаданных файла
    clearstatcache(true, AF_SETTINGS_FILE);

    // 4) Чистое чтение и заливка в рантайм
    $settings = null;
    /** @noinspection PhpIncludeInspection */
    require AF_SETTINGS_FILE;
    if (isset($settings) && is_array($settings)) {
        $mybb->settings = $settings;
    }
}


/* ===================== ВСПОМОГАТЕЛЬНЫЕ ====================== */
function af_ensure_scaffold(bool $force_refresh = false): void
{
    foreach ([AF_BASE, AF_ADDONS, AF_ADMIN, AF_ASSETS, AF_CACHE] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
    }
    foreach ([AF_LANG_EN, AF_LANG_EN_ADMIN, AF_LANG_RU, AF_LANG_RU_ADMIN] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
    }

    // --- 1) Пересборка маршрутизатора плагина (inc/plugins/.../admin/router.php)
    $router = AF_ADMIN.'router.php';
    if (!is_file($router) || $force_refresh) {
        $code = <<<'PHP'
<?php
// Router for Advanced functionality admin module
if (!defined('IN_MYBB')) { die('No direct access'); }

require_once MYBB_ROOT.'inc/plugins/advancedfunctionality.php';

class AF_Admin
{
    public static function dispatch()
    {
        global $mybb, $page, $lang, $db;

        af_ensure_scaffold(false);
        af_ensure_core_languages(false);
        $lang->load('advancedfunctionality');

        $page->add_breadcrumb_item($lang->af_admin_title);

        // Секции (для module_meta и активного заголовка)
        $sections = self::collectAdminSections();

        $action = $mybb->get_input('af_action');
        $addon  = $mybb->get_input('addon');
        $view   = $mybb->get_input('af_view');

        if ($action && $addon) {
            verify_post_check($mybb->get_input('my_post_key'));

            if ($action === 'enable') {
                self::enableAddon($addon);
                flash_message($lang->af_addon_enabled, 'success');
            } elseif ($action === 'disable') {
                self::disableAddon($addon);
                $bootstrap = self::addonBootstrap($addon);
                if ($bootstrap && is_file($bootstrap)) {
                    require_once $bootstrap;
                    $fn = 'af_'.$addon.'_deactivate';
                    if (function_exists($fn)) { $fn(); }
                }
                flash_message($lang->af_addon_disabled, 'success');
            }
            admin_redirect('index.php?module='.AF_PLUGIN_ID.'&_='.TIME_NOW);
        }

        af_reload_settings_runtime();

    // Заголовок ACP
    $page->output_header($lang->af_admin_title);

    // Никаких своих <div id="left_menu"> и <div id="content"> — всё внутри системного #page
    if ($view) {
        // Подпишем хлебные крошки для секции
        foreach ($sections as $sec) {
            if ($sec['slug'] === $view) {
                $page->add_breadcrumb_item($sec['title']);
                break;
            }
        }

        // Роутинг в контроллер аддона
        $ctrl = self::findAdminController($view);
        if ($ctrl && file_exists($ctrl['path'])) {
            require_once $ctrl['path'];
            $klass = $ctrl['class'];
            if (class_exists($klass) && method_exists($klass, 'dispatch')) {
                // ВАЖНО: контроллер НЕ должен вызывать output_header/output_footer
                call_user_func([$klass, 'dispatch']);
            } else {
                echo '<div class="error">Контроллер найден, но класс/метод не обнаружен.</div>';
            }
        } else {
            echo '<div class="error">Не найден контроллер для указанного раздела.</div>';
        }
    } else {
        // Обзор аддонов
        $table = new Table;
        $table->construct_header($lang->af_tbl_name_desc, ['width' => '55%']);
        $table->construct_header($lang->af_tbl_version,   ['width' => '10%', 'class' => 'align_center']);
        $table->construct_header($lang->af_tbl_status,    ['width' => '10%', 'class' => 'align_center']);
        $table->construct_header($lang->af_tbl_toggle,    ['width' => '15%', 'class' => 'align_center']);
        $table->construct_header($lang->af_tbl_settings,  ['width' => '10%', 'class' => 'align_center']);

        $addons = self::discoverAddons();

        if (!$addons) {
            $table->construct_cell($lang->af_no_addons, ['colspan' => 5, 'class'=>'align_center']);
            $table->construct_row();
        } else {
            foreach ($addons as $meta) {
                af_sync_addon_languages($meta, false);
                $enabled = self::isAddonEnabled($meta['id']);

                $name  = htmlspecialchars_uni($meta['name']);
                $desc  = htmlspecialchars_uni($meta['description'] ?? '');
                $ver   = htmlspecialchars_uni($meta['version'] ?? '1.0.0');

                $authorHtml = '';
                if (!empty($meta['author'])) {
                    $a = htmlspecialchars_uni($meta['author']);
                    if (!empty($meta['authorsite'])) {
                        $u = htmlspecialchars_uni($meta['authorsite']);
                        $authorHtml = "<br /><span class='smalltext' style='color:#666;'>{$lang->af_author}: <a href=\"{$u}\" target=\"_blank\" rel=\"noopener\">{$a}</a></span>";
                    } else {
                        $authorHtml = "<br /><span class='smalltext' style='color:#666;'>{$lang->af_author}: {$a}</span>";
                    }
                }

                if (!empty($meta['admin']['slug']) && $enabled) {
                    $desc .= ($desc ? ' ' : '').'(Админ-страница: '
                        . 'Advanced functionality → <a href="index.php?module='.AF_PLUGIN_ID.'&amp;af_view='.htmlspecialchars_uni($meta['admin']['slug']).'">'
                        . htmlspecialchars_uni($meta['admin']['title'] ?? $meta['name']).'</a>)';
                }
                $nameDesc = "<strong>{$name}</strong><br /><span class='smalltext'>{$desc}</span>{$authorHtml}";

                $table->construct_cell($nameDesc);
                $table->construct_cell($ver, ['class' => 'align_center']);
                $table->construct_cell($enabled ? "<span style='color:green;'>{$lang->af_on}</span>" : "<span style='color:#a00;'>{$lang->af_off}</span>", ['class'=>'align_center']);

                $btn_text  = $enabled ? $lang->af_btn_disable : $lang->af_btn_enable;
                $act       = $enabled ? 'disable' : 'enable';
                $form_html = '<form method="post" action="index.php?module='.AF_PLUGIN_ID.'" style="margin:0;">'
                        . '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'">'
                        . '<input type="hidden" name="af_action" value="'.htmlspecialchars_uni($act).'">'
                        . '<input type="hidden" name="addon" value="'.htmlspecialchars_uni($meta['id']).'">'
                        . '<input type="submit" class="submit_button" value="'.htmlspecialchars_uni($btn_text).'">'
                        . '</form>';
                $table->construct_cell($form_html, ['class'=>'align_center']);

                $gid = self::findSettingsGroup('af_'.$meta['id']);
                if ($gid) {
                    $gear = "⚙";
                    $url  = "index.php?module=config-settings&amp;action=change&amp;gid=".$gid;
                    $table->construct_cell("<a href=\"{$url}\" title=\"{$lang->af_open_settings}\" style=\"font-size:20px;text-decoration:none;\">{$gear}</a>", ['class'=>'align_center']);
                } else {
                    $table->construct_cell("—", ['class'=>'align_center']);
                }

                $table->construct_row();
            }
        }

        $table->output($lang->af_admin_title);
    }

    // Закрываем системный layout
    $page->output_footer();
    exit;

    }


    public static function collectAdminSections(): array
    {
        $out = [];
        foreach (self::discoverAddons() as $meta) {
            if (!self::isAddonEnabled($meta['id'])) continue;
            if (empty($meta['admin']['slug'])) continue;
            $out[] = [
                'id'    => $meta['id'],
                'slug'  => $meta['admin']['slug'],
                'title' => $meta['admin']['title'] ?? $meta['name'],
            ];
        }
        usort($out, fn($a,$b)=>strcasecmp($a['title'],$b['title']));
        return $out;
    }

    public static function findAdminController(string $slug): ?array
    {
        foreach (self::discoverAddons() as $meta) {
            if (!self::isAddonEnabled($meta['id'])) continue;
            if (!empty($meta['admin']['slug']) && $meta['admin']['slug'] === $slug) {
                $path = $meta['path'].($meta['admin']['controller'] ?? '');
                $klass = 'AF_Admin_'.preg_replace('~[^A-Za-z0-9]+~', '', ucfirst($slug));
                return ['path'=>$path, 'class'=>$klass];
            }
        }
        return null;
    }

    // ==== ниже — как у тебя было (enable/disable/discover и т.д.) ====

    public static function discoverAddons(): array { return af_discover_addons(); }
    public static function isAddonEnabled(string $id): bool
    {
        global $mybb;
        return isset($mybb->settings['af_'.$id.'_enabled']) && $mybb->settings['af_'.$id.'_enabled'] === '1';
    }
    public static function enableAddon(string $id): void
    {
        $bootstrap = self::addonBootstrap($id);
        if ($bootstrap && is_file($bootstrap)) {
            require_once $bootstrap;
            $fn = 'af_'.$id.'_install';
            if (function_exists($fn)) { $fn(); }
        }
        self::ensureEnabledSetting($id, 1);
        af_rebuild_and_reload_settings();
    }
    public static function disableAddon(string $id): void
    {
        self::ensureEnabledSetting($id, 0);
        af_rebuild_and_reload_settings();
    }
    public static function addonBootstrap(string $id): ?string
    {
        $list = self::discoverAddons();
        foreach ($list as $meta) { if ($meta['id'] === $id) return $meta['bootstrap'] ?? null; }
        return null;
    }
    public static function ensureEnabledSetting(string $id, int $value): void
    {
        global $db;
        $group_name = 'af_'.$id;
        $setting    = 'af_'.$id.'_enabled';

        $q = $db->simple_select('settinggroups','gid',"name='".$db->escape_string($group_name)."'", ['limit'=>1]);
        $gid = (int)$db->fetch_field($q, 'gid');
        if (!$gid) {
            $gid = af_ensure_settinggroup($group_name, 'AF: '.$id, 'Настройки внутреннего аддона '.$id);
        }

        af_ensure_setting($group_name, $setting, 'Включить аддон', 'Включает или отключает аддон.', 'yesno', ($value ? '1' : '0'), 1);

        $name_esc = $db->escape_string($setting);
        $dupes_q  = $db->simple_select('settings', 'sid', "name='{$name_esc}'", ['order_by' => 'sid', 'order_dir' => 'asc']);
        $sids     = [];
        while ($r = $db->fetch_array($dupes_q)) { $sids[] = (int)$r['sid']; }
        if (count($sids) > 1) {
            $keep = array_shift($sids);
            $db->delete_query('settings', "sid IN (".implode(',', array_map('intval',$sids)).")");
            $db->update_query('settings', ['gid' => (int)$gid, 'value' => ($value ? '1' : '0')], "sid=".(int)$keep);
        }
    }
    public static function findSettingsGroup(string $name): ?int
    {
        global $db;
        $q = $db->simple_select('settinggroups','gid',"name='".$db->escape_string($name)."'", ['limit'=>1]);
        $gid = $db->fetch_field($q, 'gid');
        return $gid ? (int)$gid : null;
    }
}

AF_Admin::dispatch();
PHP;
        @file_put_contents($router, $code);
    }

    // --- 2) Админ-модуль: module_meta.php + index.php-прокси (в РЕАЛЬНУЮ папку админки)
    $adminAbs = af_admin_absdir();
    $modDir   = rtrim($adminAbs,'/').'/modules/'.AF_PLUGIN_ID;
    if (!is_dir($modDir)) { @mkdir($modDir, 0777, true); }

    // 2.1 module_meta.php — системный левый сайдбар с динамическими подпунктами
    $metaFile = $modDir.'/module_meta.php';
    if (!is_file($metaFile) || $force_refresh) {
        $s  = <<<PHP
<?php
if (!defined('IN_MYBB')) die('No direct access');

/**
 * Интеграция AF в системный левый сайдбар MyBB.
 * Подпункты набираются из ВКЛЮЧЁННЫХ аддонов, у которых manifest['admin']['slug'] задан.
 */
function advancedfunctionality_meta()
{
    global \$page, \$mybb;

    // Подтягиваем основной файл плагина, чтобы были доступны af_* хелперы
    require_once MYBB_ROOT.'inc/plugins/advancedfunctionality.php';

    // Базовый подпункт — обзор
    \$sub_menu   = [];
    \$sub_menu[] = [
        'id'    => 'index',
        'title' => 'Обзор аддонов',
        'link'  => 'index.php?module=advancedfunctionality',
    ];

    // Динамические подпункты из включённых аддонов с admin-страницами
    \$addons = af_discover_addons();
    foreach (\$addons as \$meta) {
        if (empty(\$meta['admin']['slug'])) { continue; }
        if (!af_is_addon_enabled(\$meta['id'])) { continue; }

        \$slug  = preg_replace('~[^a-z0-9_\\-]+~i', '', (string)\$meta['admin']['slug']);
        \$title = !empty(\$meta['admin']['title']) ? \$meta['admin']['title'] : \$meta['name'];

        \$sub_menu[] = [
            'id'    => 'view_'.\$slug,
            'title' => \$title,
            'link'  => 'index.php?module=advancedfunctionality&af_view='.rawurlencode(\$slug),
        ];
    }

    // Регистрируем пункт верхнего уровня + подпункты
    \$page->add_menu_item('Расширенный функционал', 'advancedfunctionality', 'index.php?module=advancedfunctionality', 60, \$sub_menu);
    return true;
}

/**
 * Подсветка активного подпункта и выбор файла контроллера.
 * Мы всегда возвращаем 'index.php' — дальше роутер AF разбирает af_view сам.
 */
function advancedfunctionality_action_handler(\$action)
{
    global \$page, \$mybb;
    \$page->active_module = 'advancedfunctionality';

    \$view = \$mybb->get_input('af_view');
    if (\$view) {
        \$page->active_action = 'view_'.preg_replace('~[^a-z0-9_\\-]+~i', '', (string)\$view);
        return 'index.php';
    }

    \$page->active_action = 'index';
    return 'index.php';
}

/** Права доступа модуля */
function advancedfunctionality_admin_permissions()
{
    return [
        'name'        => 'Расширенный функционал',
        'permissions' => ['index' => 'Доступ'],
        'disporder'   => 60
    ];
}
PHP;
        @file_put_contents($metaFile, $s);
    }

    // 2.2 index.php — прокси в наш router.php (без изменений)
    $idx = $modDir.'/index.php';
    if (!is_file($idx) || $force_refresh) {
        $code = <<<PHP
<?php
if (!defined('IN_MYBB')) die('No direct access');
require_once MYBB_ROOT.'inc/plugins/advancedfunctionality/admin/router.php';
PHP;
        @file_put_contents($idx, $code);
    }

}




function af_write_admin_proxy(): void
{
    $adminAbs = af_admin_absdir();
    $proxyDir = rtrim($adminAbs,'/').'/modules/'.AF_PLUGIN_ID;
    if (!is_dir($proxyDir)) @mkdir($proxyDir, 0777, true);

    // В админке IN_MYBB уже определён — лишние init/require не нужны.
    $code = <<<PHP
<?php
if (!defined('IN_MYBB')) die('No direct access');
require_once MYBB_ROOT.'inc/plugins/advancedfunctionality/admin/router.php';
PHP;
    @file_put_contents($proxyDir.'/index.php', $code);
}



function af_write_cache(string $key, string $value): void
{
    @file_put_contents(AF_CACHE.$key.'.txt', $value);
}

function af_find_gid(string $group_name): ?int
{
    global $db;
    $q = $db->simple_select('settinggroups','gid',"name='".$db->escape_string($group_name)."'", ['limit'=>1]);
    $gid = $db->fetch_field($q, 'gid');
    return $gid ? (int)$gid : null;
}

function af_ensure_settinggroup(string $name, string $title, string $desc): int
{
    global $db;
    $gid = af_find_gid($name);
    if ($gid) return $gid;

    $max = $db->fetch_field($db->simple_select('settinggroups','MAX(disporder) AS m'), 'm');
    $disp = (int)$max + 1;

    $ins = [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'disporder'   => $disp,
        'isdefault'   => 0
    ];
    $db->insert_query('settinggroups', $ins);
    return (int)$db->insert_id();
}

function af_ensure_setting(string $group_name, string $name, string $title, string $desc, string $type, string $value, int $disporder): void
{
    global $db;

    // 0) На всякий случай — нормализуем имя (MyBB обычно хранит латиницу/нижнее подчёркивание)
    $name = preg_replace('~[^a-z0-9_]+~i', '_', $name);

    // 1) Гарантируем группу
    $gid = af_find_gid($group_name);
    if (!$gid)
    {
        $gid = af_ensure_settinggroup($group_name, $group_name, $group_name);
    }

    // 2) Найдём все строки с таким же name (возможны дубликаты после старых тестов)
    $name_esc = $db->escape_string($name);
    $dupes_q  = $db->simple_select('settings', 'sid,gid', "name='{$name_esc}'");
    $sids     = [];
    while ($row = $db->fetch_array($dupes_q)) {
        $sids[] = (int)$row['sid'];
    }

    // 3) Если ничего не нашли — просто вставим
    if (!$sids)
    {
        $row = [
            'name'        => $name_esc,
            'title'       => $db->escape_string($title),
            'description' => $db->escape_string($desc),
            'optionscode' => $db->escape_string($type),
            'value'       => $db->escape_string($value),
            'disporder'   => (int)$disporder,
            'gid'         => (int)$gid,
        ];
        $db->insert_query('settings', $row);
        return;
    }

    // 4) Если нашлась хотя бы одна — оставим первую как «каноническую», остальные удалим
    $keep_sid = array_shift($sids); // первая найденная
    if (!empty($sids)) {
        $db->delete_query('settings', "sid IN (".implode(',', array_map('intval',$sids)).")");
    }

    // 5) Обновим «каноническую» запись по содержимому (и переведём её в верный gid)
    $row = [
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value'       => $db->escape_string($value),
        'disporder'   => (int)$disporder,
        'gid'         => (int)$gid,
    ];
    $db->update_query('settings', $row, "sid=".(int)$keep_sid);
}


/* =================== LANG: ядро и аддоны =================== */
/**
 * Создаёт/обновляет языковые файлы ядра (RU/EN фронт+админ).
 * $force=true — перезаписать.
 */
function af_ensure_core_languages(bool $force = false): void
{
    // RU фронт
    af_write_lang_file(
        AF_LANG_RU.'advancedfunctionality.lang.php',
        [
            'af' => 'Advanced functionality',
        ],
        $force
    );

    // RU админ
    af_write_lang_file(
        AF_LANG_RU_ADMIN.'advancedfunctionality.lang.php',
        [
            'af_admin_title'   => 'Расширенный функционал',
            'af_tbl_name_desc' => 'Название и описание',
            'af_tbl_version'   => 'Версия',
            'af_tbl_status'    => 'Статус',
            'af_tbl_toggle'    => 'Переключить',
            'af_tbl_settings'  => 'Параметры',
            'af_no_addons'     => 'Внутренние плагины (аддоны) не найдены.',
            'af_on'            => 'Включён',
            'af_off'           => 'Отключён',
            'af_btn_enable'    => 'Включить',
            'af_btn_disable'   => 'Отключить',
            'af_open_settings' => 'Открыть параметры',
            'af_addon_enabled' => 'Аддон включён.',
            'af_addon_disabled'=> 'Аддон отключён.',
            'af_author'        => 'Автор',
        ],
        $force
    );

    // EN front
    af_write_lang_file(
        AF_LANG_EN.'advancedfunctionality.lang.php',
        [
            'af' => 'Advanced functionality',
        ],
        $force
    );

    // EN admin
    af_write_lang_file(
        AF_LANG_EN_ADMIN.'advancedfunctionality.lang.php',
        [
            'af_admin_title'   => 'Advanced functionality',
            'af_tbl_name_desc' => 'Name & description',
            'af_tbl_version'   => 'Version',
            'af_tbl_status'    => 'Status',
            'af_tbl_toggle'    => 'Toggle',
            'af_tbl_settings'  => 'Settings',
            'af_no_addons'     => 'No internal addons found.',
            'af_on'            => 'Enabled',
            'af_off'           => 'Disabled',
            'af_btn_enable'    => 'Enable',
            'af_btn_disable'   => 'Disable',
            'af_open_settings' => 'Open settings',
            'af_addon_enabled' => 'Addon enabled.',
            'af_addon_disabled'=> 'Addon disabled.',
            'af_author'        => 'Author',
        ],
        $force
    );
}


/**
 * Синхронизирует языки одного аддона по его manifest.php['lang'].
 * Формат:
 *  'lang' => [
 *    'russian' => ['front' => ['key'=>'val', ...], 'admin' => ['key'=>'val', ...]],
 *    'english' => ['front' => [...], 'admin' => [...]],
 *  ]
 */
function af_sync_addon_languages(array $meta, bool $force = false): void
{
    if (empty($meta['id'])) return;
    $id = $meta['id'];
    $lang = $meta['lang'] ?? null;
    // Имена файлов: advancedfunctionality_{id}.lang.php
    $fname_front = 'advancedfunctionality_'.$id.'.lang.php';

    // Если lang отсутствует — создадим минимальные заглушки, чтобы $lang->load() не падал
    $ru_front  = $lang['russian']['front'] ?? [];
    $ru_admin  = $lang['russian']['admin'] ?? [];
    $en_front  = $lang['english']['front'] ?? [];
    $en_admin  = $lang['english']['admin'] ?? [];

    // RU
    if (!empty($ru_front) || $force) {
        af_write_lang_file(AF_LANG_RU.$fname_front, $ru_front ?: [
            'af_'.$id.'_name'        => ucfirst($id),
            'af_'.$id.'_description' => '',
        ], $force);
    }
    if (!empty($ru_admin) || $force) {
        af_write_lang_file(AF_LANG_RU_ADMIN.$fname_front, $ru_admin ?: [], $force);
    }
    // EN
    if (!empty($en_front) || $force) {
        af_write_lang_file(AF_LANG_EN.$fname_front, $en_front ?: [
            'af_'.$id.'_name'        => ucfirst($id),
            'af_'.$id.'_description' => '',
        ], $force);
    }
    if (!empty($en_admin) || $force) {
        af_write_lang_file(AF_LANG_EN_ADMIN.$fname_front, $en_admin ?: [], $force);
    }
}

/**
 * Пишет PHP-файл с массивом $l[...] — безопасно, читабельно.
 */
function af_write_lang_file(string $fullpath, array $pairs, bool $force): void
{
    if (is_file($fullpath) && !$force) return;

    if (!is_dir(dirname($fullpath))) {
        @mkdir(dirname($fullpath), 0777, true);
    }

    // Экранируем значения, собираем код
    $buf  = "<?php\n";
    foreach ($pairs as $k => $v) {
        $key = preg_replace('~[^a-z0-9_]+~i', '_', (string)$k);
        $val = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$v);
        $buf .= "\$l['{$key}'] = '{$val}';\n";
    }
    @file_put_contents($fullpath, $buf);
}

function af_reload_settings_runtime(): void
{
    global $mybb;
    if (is_file(MYBB_ROOT.'inc/settings.php')) {
        /** @noinspection PhpIncludeInspection */
        require MYBB_ROOT.'inc/settings.php';
        if (isset($settings) && is_array($settings)) {
            $mybb->settings = $settings;
        }
    }
}



/* =================== ХУКИ ЗАГРУЗКИ АДДОНОВ =================== */
$plugins->add_hook('global_start', 'advancedfunctionality_bootstrap_addons', 1);
$plugins->add_hook('pre_output_page', 'advancedfunctionality_bootstrap_addons_preoutput', 1);

function advancedfunctionality_bootstrap_addons()
{
    // Подключаем активные аддоны и их языки (front)
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        $id = $meta['id'];
        if (af_is_addon_enabled($id)) {
            // гарантируем языки (лениво), грузим их
            af_sync_addon_languages($meta, false);
            af_load_addon_lang($id);

            if (!empty($meta['bootstrap']) && is_file($meta['bootstrap'])) {
                require_once $meta['bootstrap'];
                $fn = 'af_'.$id.'_init';
                if (function_exists($fn)) { $fn(); }
            }
        }
    }
}

function advancedfunctionality_bootstrap_addons_preoutput($page)
{
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        $id = $meta['id'];
        if (af_is_addon_enabled($id) && !empty($meta['bootstrap']) && is_file($meta['bootstrap'])) {
            $fn = 'af_'.$id.'_pre_output';
            if (function_exists($fn)) {
                $page = $fn($page);
            }
        }
    }
    return $page;
}

function af_discover_addons(): array
{
    $out = [];
    if (!is_dir(AF_ADDONS)) return $out;
    $scan = scandir(AF_ADDONS);
    foreach ($scan as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = AF_ADDONS.$entry.'/';
        if (!is_dir($path)) continue;
        $manifest = $path.'manifest.php';
        if (is_file($manifest)) {
            $meta = @include $manifest;
            if (is_array($meta) && !empty($meta['id']) && !empty($meta['name'])) {
                $meta['path'] = $path;
                if (!empty($meta['bootstrap'])) $meta['bootstrap'] = $path.$meta['bootstrap'];
                $out[] = $meta;
            }
        }
    }
    usort($out, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    return $out;
}

function af_is_addon_enabled(string $id): bool
{
    global $mybb;
    return isset($mybb->settings['af_'.$id.'_enabled']) && $mybb->settings['af_'.$id.'_enabled'] === '1';
}


/** Грузим $lang для аддона: advancedfunctionality_{id} (front/admin подхватится средой) */
function af_load_addon_lang(string $id): void
{
    global $lang;
    $file = 'advancedfunctionality_'.$id;
    $lang->load($file);
    if (defined('IN_ADMINCP')) {
        $lang->load($file); // дублирование для админ-контекста
    }
}
