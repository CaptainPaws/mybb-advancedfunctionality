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
define('AF_GATEWAY_STUB', AF_BASE.'gateway_stub.php');
// Runtime файл в КОРНЕ форума
define('AF_GATEWAY_RUNTIME', MYBB_ROOT.'advancedfunctionality_gateway.php');



// Папки языков (ядро)
define('AF_LANG_BASE', MYBB_ROOT.'inc/languages/');
define('AF_LANG_EN', AF_LANG_BASE.'english/');
define('AF_LANG_EN_ADMIN', AF_LANG_EN.'admin/');
define('AF_LANG_RU', AF_LANG_BASE.'russian/');
define('AF_LANG_RU_ADMIN', AF_LANG_RU.'admin/');


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

    // 6) Gateway runtime (в корень форума)
    af_ensure_gateway_runtime(true);

    // 7) Служебный кэш
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
    // НЕ форсим scaffold — чтобы не перезаписывать router.php и admin proxy.
    // Только создаём каталоги, если их нет.
    af_ensure_scaffold(false);

    // Языки ядра можно обновлять безопасно
    af_ensure_core_languages(true);

    // Языки аддонов синхронизируем
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        af_sync_addon_languages($meta, true);
    }

    // Шаблоны аддонов -> в БД (master templates)
    af_sync_all_addon_templates(true);

    // Шлюз: обновляем runtime в корне форума из stub
    af_ensure_gateway_runtime(true);

    // Кэш
    af_write_cache('last_activation', (string)TIME_NOW);

    // сбрасываем флаг "нужно обновить"
    @file_put_contents(AF_CACHE.'needs_refresh.txt', '0');
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
function af_front_ensure_header_bits(): void
{
    global $templates, $headerinclude, $header, $footer;

    if (!is_object($templates)) {
        return;
    }

    if (empty($headerinclude)) {
        eval('$headerinclude = "'.$templates->get('headerinclude').'";');
    }
    if (empty($header)) {
        eval('$header = "'.$templates->get('header').'";');
    }
    if (empty($footer)) {
        eval('$footer = "'.$templates->get('footer').'";');
    }
}

/**
 * Рендерит MyBB-строку с {$var} (и {$theme['x']}) через eval() и выводит как полный документ одним куском.
 * Используй это вместо "return '...{$headerinclude}...';"
 */
function af_front_output_template_string(string $pageTitle, string $templateString, array $vars = []): void
{
    global $mybb, $lang, $headerinclude, $header, $footer;

    af_front_ensure_header_bits();

    // Вбрасываем переменные в локальный scope для {$var}
    foreach ($vars as $k => $v) {
        if (is_string($k) && preg_match('~^[a-zA-Z_][a-zA-Z0-9_]*$~', $k)) {
            ${$k} = $v;
        }
    }

    // Безопасно готовим строку для eval
    $safe = str_replace(["\\", "\""], ["\\\\", "\\\""], $templateString);
    $safe = str_replace(["\r\n", "\r", "\n"], ["\\n", "", "\\n"], $safe);

    $content = '';
    eval('$content = "'.$safe.'";');

    // Если аддон уже вернул полный HTML-документ — просто отдаём
    if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<html') !== false) {
        output_page($content);
        exit;
    }

    $bbname = htmlspecialchars_uni((string)($mybb->settings['bbname'] ?? ''));
    $title  = htmlspecialchars_uni($pageTitle);

    $page = "<!DOCTYPE html>\n";
    $page .= "<html".(!empty($lang->settings['htmllang']) ? " lang=\"".htmlspecialchars_uni($lang->settings['htmllang'])."\"" : "").">\n";
    $page .= "<head>\n";
    $page .= "<title>{$title} - {$bbname}</title>\n";
    $page .= $headerinclude . "\n";
    $page .= "</head>\n<body>\n";
    $page .= $header . "\n";
    $page .= $content . "\n";
    $page .= $footer . "\n";
    $page .= "</body>\n</html>";

    output_page($page);
    exit;
}


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

        // ВАЖНО: тут должен быть ТОЛЬКО ОДИН nowdoc. Никаких "$code = <<<'PHP'" внутри.
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

        $page->output_header($lang->af_admin_title);

        if ($view) {
            $sections = self::collectAdminSections();
            foreach ($sections as $sec) {
                if ($sec['slug'] === $view) {
                    $page->add_breadcrumb_item($sec['title']);
                    break;
                }
            }

            $ctrl = self::findAdminController($view);
            if ($ctrl && file_exists($ctrl['path'])) {
                require_once $ctrl['path'];
                $klass = $ctrl['class'];

                if (class_exists($klass) && method_exists($klass, 'dispatch')) {
                    // контроллер НЕ должен вызывать output_header/output_footer
                    call_user_func([$klass, 'dispatch']);
                } else {
                    echo '<div class="error">Контроллер найден, но класс/метод не обнаружен.</div>';
                }
            } else {
                echo '<div class="error">Не найден контроллер для указанного раздела.</div>';
            }
        } else {
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
                $path  = $meta['path'].($meta['admin']['controller'] ?? '');
                $klass = 'AF_Admin_'.preg_replace('~[^A-Za-z0-9]+~', '', ucfirst($slug));
                return ['path'=>$path, 'class'=>$klass];
            }
        }
        return null;
    }

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
        foreach (self::discoverAddons() as $meta) {
            if (($meta['id'] ?? '') === $id) {
                return $meta['bootstrap'] ?? null;
            }
        }
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

function af_gateway_signature(): string
{
    return 'AF-GENERATED: advancedfunctionality_gateway v2';
}


/**
 * Пишет/обновляет runtime gateway-файл в корне форума из stub-файла.
 * - если runtime уже существует БЕЗ нашей сигнатуры — не трогаем
 * - если существует с сигнатурой — обновляем при отличии
 */
function af_ensure_gateway_runtime(bool $force_refresh = false): void
{
    $stub = AF_GATEWAY_STUB;
    $dst  = AF_GATEWAY_RUNTIME;

    if (!is_file($stub)) {
        return;
    }

    $code = (string)@file_get_contents($stub);
    if ($code === '') {
        return;
    }

    // Базовый маркер "нашести" — принимаем любую версию (v1/v2/будущие)
    $baseMarker = 'AF-GENERATED: advancedfunctionality_gateway';

    // Stub должен содержать базовый маркер и текущую сигнатуру (защита от "левых" файлов)
    if (strpos($code, $baseMarker) === false || strpos($code, af_gateway_signature()) === false) {
        return;
    }

    if (is_file($dst)) {
        $cur = (string)@file_get_contents($dst);

        // Если runtime вообще не наш (нет базового маркера) — не трогаем
        if (strpos($cur, $baseMarker) === false) {
            return;
        }

        // Если не форсим и контент идентичен — не перезаписываем
        if (!$force_refresh && $cur === $code) {
            return;
        }
    }

    @file_put_contents($dst, $code, LOCK_EX);
}


/** Подключает runtime gateway (или stub как fallback), чтобы функция роутера точно существовала */
function af_require_gateway_router(): void
{
    if (function_exists('af_gateway_xmlhttp_router')) {
        return;
    }

    if (is_file(AF_GATEWAY_RUNTIME)) {
        require_once AF_GATEWAY_RUNTIME;
        return;
    }

    if (is_file(AF_GATEWAY_STUB)) {
        require_once AF_GATEWAY_STUB;
        return;
    }
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

    // Перезаписываем, если файл отсутствует, требует force или содержимое отличается (например, после обновления manifest.php)
    if (!$force && is_file($fullpath)) {
        $current = @file_get_contents($fullpath);
        if ($current === $buf) {
            return;
        }
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
global $plugins;

// Языки ядра (полезно и во фронте, и в ACP)
$plugins->add_hook('global_start', 'advancedfunctionality_load_lang', 0);

// Подключение аддонов
$plugins->add_hook('global_start', 'advancedfunctionality_bootstrap_addons', 1);
$plugins->add_hook('pre_output_page', 'advancedfunctionality_bootstrap_addons_preoutput', 1);
// Буфер-страховка для страниц, которые печатаются без output_page() (особенно usercp/misc)
$plugins->add_hook('global_start', 'advancedfunctionality_outputbuffer_start', -5);


// XMLHTTP роутинг (аналог MyAlerts)
$plugins->add_hook('xmlhttp', 'af_xmlhttp_router', -1);

function advancedfunctionality_bootstrap_addons()
{
    // защита от повторной загрузки за один запрос
    if (!empty($GLOBALS['af_addons_bootstrapped'])) {
        return;
    }
    $GLOBALS['af_addons_bootstrapped'] = true;

    // Подключаем активные аддоны и их языки (front)
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        $id = $meta['id'] ?? '';
        if ($id === '') continue;

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


function advancedfunctionality_bootstrap_addons_preoutput(&$page)
{
    if (af_should_skip_preoutput()) {
        return $page;
    }

    // если уже прогнали через AF — не трогаем (важно, чтобы буфер-страховка не “двоила” инъекции)
    if (is_string($page) && strpos($page, '<!--af_core_preoutput_done-->') !== false) {
        return $page;
    }

    // гарантируем, что init аддонов уже был
    advancedfunctionality_bootstrap_addons();

    $page = af_apply_preoutput_filters((string)$page);

    // маркер “AF уже обработал HTML”
    if (strpos($page, '<!--af_core_preoutput_done-->') === false) {
        $page .= "\n<!--af_core_preoutput_done-->";
    }

    return $page;
}

function advancedfunctionality_outputbuffer_start()
{
    // не стартуем буфер в админке и в xmlhttp
    if (defined('IN_ADMINCP')) return;
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'xmlhttp.php') return;

    $script = defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : basename($_SERVER['SCRIPT_NAME'] ?? '');
    // минимальный безопасный whitelist: ровно те места, где у тебя “ручной вывод” чаще всего
    $whitelist = ['usercp.php', 'misc.php'];
    if (!in_array($script, $whitelist, true)) {
        return;
    }

    if (!empty($GLOBALS['af_ob_started'])) {
        return;
    }
    $GLOBALS['af_ob_started'] = true;

    // запоминаем уровень, чтобы корректно снять именно наш буфер
    $GLOBALS['af_ob_level'] = ob_get_level();

    ob_start();

    // shutdown сработает даже если кто-то делает exit;
    register_shutdown_function('advancedfunctionality_outputbuffer_flush');
}

function advancedfunctionality_outputbuffer_flush()
{
    if (empty($GLOBALS['af_ob_started'])) {
        return;
    }

    // если буфер уже кто-то снял — выходим молча
    if (!isset($GLOBALS['af_ob_level']) || ob_get_level() <= (int)$GLOBALS['af_ob_level']) {
        return;
    }

    $html = (string)ob_get_clean();
    if ($html === '') {
        return;
    }

    if (af_should_skip_preoutput()) {
        echo $html;
        return;
    }

    // если уже обработано через pre_output_page (output_page) — просто отдаём как есть
    if (strpos($html, '<!--af_core_preoutput_done-->') !== false) {
        echo $html;
        return;
    }

    // гарантируем init аддонов
    advancedfunctionality_bootstrap_addons();

    // прогоняем pre_output аддонов вручную
    $html = af_apply_preoutput_filters($html);

    // ставим маркер, чтобы ничего не двоилось
    if (strpos($html, '<!--af_core_preoutput_done-->') === false) {
        $html .= "\n<!--af_core_preoutput_done-->";
    }

    echo $html;
}

/**
 * Общий прогон всех enabled аддонов по pre_output.
 * Вынесено отдельно, чтобы использовать и из pre_output_page, и из буфера.
 */
function af_apply_preoutput_filters(string $page): string
{
    if (af_should_skip_preoutput()) {
        return $page;
    }

    // 0) Рантайм-синк шаблонов (очень лёгкий, с сигнатурой)
    af_maybe_sync_templates_runtime();

    // 1) Прогоняем pre_output аддонов
    $addons = af_discover_addons();

    foreach ($addons as $meta) {
        $id = $meta['id'] ?? '';
        if ($id === '' || !af_is_addon_enabled($id)) {
            continue;
        }

        // На всякий случай гарантируем bootstrap
        if (!empty($meta['bootstrap']) && is_file($meta['bootstrap'])) {
            require_once $meta['bootstrap'];
        }

        $fn = 'af_'.$id.'_pre_output';
        if (!function_exists($fn)) {
            continue;
        }

        $before = $page;
        $res = $fn($page);

        // поддерживаем оба стиля: by-ref и “вернул строку”
        if (is_string($res) && $res !== '' && $res !== $before) {
            $page = $res;
        }
    }

    // 2) Вклеиваем ассеты (CSS/JS) всех включённых аддонов
    $page = af_inject_enabled_addon_assets($page);

    // 3) Страховка: если внезапно нет НИ ОДНОГО rel="stylesheet" — вклеим $stylesheets
    $page = af_inject_core_stylesheets_if_missing($page);

    return $page;
}

function af_is_ajax_request(): bool
{
    global $mybb;

    if (defined('IN_MYBB') && isset($mybb) && is_object($mybb)) {
        if ((int)$mybb->get_input('ajax', MyBB::INPUT_INT) === 1) {
            return true;
        }
    }

    $xrw = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($xrw === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

function af_should_skip_preoutput(): bool
{
    if (!empty($GLOBALS['af_disable_pre_output'])) {
        return true;
    }

    if (af_is_ajax_request()) {
        return true;
    }

    return defined('AF_NO_PRE_OUTPUT') && AF_NO_PRE_OUTPUT;
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
/**
 * Detect current context: AdminCP or frontend.
 */
function af_is_admin_context(): bool
{
    if (defined('IN_ADMINCP') && IN_ADMINCP) {
        return true;
    }

    // Иногда в прокси/роутерах IN_ADMINCP может не быть, но путь/переменные намекают
    if (!empty($_SERVER['PHP_SELF']) && strpos((string)$_SERVER['PHP_SELF'], '/admin/') !== false) {
        return true;
    }

    return false;
}

/**
 * Build ordered list of language candidates for loading.
 * - AdminCP: cplanguage -> user language -> bblanguage -> english
 * - Front:  user language -> bblanguage -> english
 */
function af_lang_get_candidates(bool $admin, ?string $override = null): array
{
    $langs = [];

    if (is_string($override) && trim($override) !== '') {
        $langs[] = trim($override);
    }

    $mybb = $GLOBALS['mybb'] ?? null;

    if ($admin) {
        if (is_object($mybb) && !empty($mybb->settings['cplanguage'])) {
            $langs[] = (string)$mybb->settings['cplanguage'];
        }
    }

    if (is_object($mybb) && !empty($mybb->user['language'])) {
        $langs[] = (string)$mybb->user['language'];
    }

    if (is_object($mybb) && !empty($mybb->settings['bblanguage'])) {
        $langs[] = (string)$mybb->settings['bblanguage'];
    }

    // last resort
    $langs[] = 'english';

    // normalize unique
    $langs = array_values(array_unique(array_filter(array_map('trim', $langs), static function ($x) {
        return is_string($x) && $x !== '';
    })));

    return $langs;
}

/**
 * Ensure addon lang file exists physically (create placeholder if missing).
 * Returns absolute path if exists/created; otherwise null.
 */
function af_lang_ensure_addon_file(string $addonId, string $lang, bool $admin): ?string
{
    if (!defined('MYBB_ROOT')) {
        return null;
    }

    $addonId = trim($addonId);
    if ($addonId === '') {
        return null;
    }

    $lang = trim($lang);
    if ($lang === '') {
        $lang = 'english';
    }

    $file = 'advancedfunctionality_' . $addonId . '.lang.php';
    $dir  = MYBB_ROOT . 'inc/languages/' . $lang . '/' . ($admin ? 'admin/' : '');
    $path = $dir . $file;

    if (file_exists($path)) {
        return $path;
    }

    // Try create directories
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        return null; // no perms
    }

    // Placeholder content — цель: не падать с "does not exist"
    $mode = $admin ? 'admin' : 'front';

    $content =
        "<?php\n"
      . "/**\n"
      . " * Auto-generated placeholder for AF addon: {$addonId}\n"
      . " * Lang: {$lang}, Mode: {$mode}\n"
      . " * Purpose: prevent missing-language include errors.\n"
      . " */\n"
      . "if (!isset(\$l) || !is_array(\$l)) { \$l = []; }\n"
      . "\$l['af_{$addonId}_name'] = \$l['af_{$addonId}_name'] ?? '" . addslashes($addonId) . "';\n"
      . "\$l['af_{$addonId}_description'] = \$l['af_{$addonId}_description'] ?? '';\n";

    if ($admin) {
        $content .= "\$l['af_{$addonId}_group'] = \$l['af_{$addonId}_group'] ?? '" . addslashes($addonId) . "';\n";
        $content .= "\$l['af_{$addonId}_group_desc'] = \$l['af_{$addonId}_group_desc'] ?? '';\n";
    }

    @file_put_contents($path, $content, LOCK_EX);

    return file_exists($path) ? $path : null;
}

/**
 * Merge loaded $l array into global $lang object safely.
 */
function af_lang_merge(array $l): void
{
    if (empty($l)) {
        return;
    }

    global $lang;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    foreach ($l as $k => $v) {
        if (!is_string($k)) {
            continue;
        }
        // MyBB language vars обычно доступны как $lang->key
        $lang->$k = $v;
    }
}

/**
 * Stable AF addon language loader.
 *
 * Supported usages (backward-friendly):
 *  - af_load_addon_lang('addonid')
 *  - af_load_addon_lang('addonid', true)  // admin
 *  - af_load_addon_lang('addonid', false) // front
 *  - af_load_addon_lang('addonid', true, 'russian') // force lang
 *
 * IMPORTANT: This does not depend on MyBB internal $lang->load() signature.
 * It includes the language file directly and merges $l into $lang object.
 */
function af_load_addon_lang(string $addonId, $admin = null, ?string $langOverride = null): void
{
    $addonId = trim($addonId);
    if ($addonId === '') {
        return;
    }

    // Backward-compat: if second argument is string, treat it as lang override
    if (is_string($admin) && $langOverride === null) {
        $langOverride = $admin;
        $admin = null;
    }

    $isAdmin = is_bool($admin) ? $admin : af_is_admin_context();

    $candidates = af_lang_get_candidates($isAdmin, $langOverride);

    foreach ($candidates as $lng) {
        $path = af_lang_ensure_addon_file($addonId, $lng, $isAdmin);
        if (!$path) {
            continue;
        }

        $l = [];
        // include in local scope, capture $l
        @include $path;

        if (is_array($l) && !empty($l)) {
            af_lang_merge($l);
            return;
        }
    }

    // If everything failed, last-resort: try english placeholder explicitly
    $path = af_lang_ensure_addon_file($addonId, 'english', $isAdmin);
    if ($path) {
        $l = [];
        @include $path;
        if (is_array($l) && !empty($l)) {
            af_lang_merge($l);
        }
    }
}


/**
 * XMLHTTP роутер AF (аналог myalerts_xmlhttp()).
 * Срабатывает в /xmlhttp.php до того, как MyBB выведет что-либо.
 */
function af_xmlhttp_router(): void
{
    global $mybb;

    // MyBB: /xmlhttp.php?action=...
    $action = $mybb->get_input('action');

    // Мы обслуживаем только свои экшены
    if ($action === '') {
        return;
    }

    // ЖЁСТКО фиксируем канон для AAM:
    // твой JS/шаблоны должны ходить сюда:
    // /xmlhttp.php?action=af_aam_api&op=...
    if ($action === 'af_aam_api') {
        af_xmlhttp_call_addon('advancedalertsandmentions');
        return; // call_addon сам либо отдаст JSON и exit, либо ничего не сделает
    }

    // На будущее: универсальный экшен вида:
    // /xmlhttp.php?action=af_addon_api&addon=<id>
    if ($action === 'af_addon_api') {
        $addon = $mybb->get_input('addon');
        if ($addon !== '') {
            $addon = preg_replace('~[^a-z0-9_]+~i', '', (string)$addon);
            if ($addon !== '') {
                af_xmlhttp_call_addon($addon);
            }
        }
        return;
    }

    // Остальное не трогаем — пусть обрабатывают другие плагины/ядро
}

/**
 * Подключает bootstrap аддона и вызывает его xmlhttp-хендлер.
 * Если аддон не включён — ничего не делает.
 */
function af_xmlhttp_call_addon(string $id): void
{
    // ВАЖНО: никаких fatals — если чего-то нет, просто выходим
    if (!function_exists('af_discover_addons')) {
        return;
    }

    // аддон должен быть включён через AF settings
    if (function_exists('af_is_addon_enabled')) {
        if (!af_is_addon_enabled($id)) {
            return;
        }
    } else {
        // fallback, если вдруг helper не объявлен
        global $mybb;
        if (empty($mybb->settings['af_'.$id.'_enabled']) || (string)$mybb->settings['af_'.$id.'_enabled'] !== '1') {
            return;
        }
    }

    $bootstrap = af_get_addon_bootstrap_path($id);
    if (!$bootstrap || !is_file($bootstrap)) {
        return;
    }

    require_once $bootstrap;

    // Канонический контракт: аддон может объявить af_{id}_xmlhttp()
    $fn = 'af_'.$id.'_xmlhttp';
    if (function_exists($fn)) {
        $fn(); // обычно внутри будет JSON + exit
        return;
    }

    // Fallback именно для AAM (если ты оставила старую точку входа)
    if ($id === 'advancedalertsandmentions') {
        if (function_exists('af_aam_xmlhttp')) {
            af_aam_xmlhttp();
            return;
        }
    }
}

/**
 * Находит абсолютный путь до bootstrap-файла аддона по его id.
 */
function af_get_addon_bootstrap_path(string $id): ?string
{
    $id = preg_replace('~[^a-z0-9_]+~i', '', $id);
    if ($id === '') {
        return null;
    }

    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        if (empty($meta['id']) || (string)$meta['id'] !== $id) {
            continue;
        }

        // meta может хранить:
        // - path (абсолютный путь до папки аддона)
        // - bootstrap (имя файла или абсолютный путь)
        $bootstrap = $meta['bootstrap'] ?? '';
        $path      = $meta['path'] ?? '';

        if (!is_string($bootstrap) || $bootstrap === '') {
            return null;
        }

        // Абсолютный путь?
        if (isset($bootstrap[0]) && ($bootstrap[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $bootstrap))) {
            return $bootstrap;
        }

        // Если есть meta['path'] — клеим к нему
        if (is_string($path) && $path !== '') {
            return rtrim($path, '/\\') . '/' . ltrim($bootstrap, '/\\');
        }

        // Последний fallback: стандартная структура AF
        return rtrim(AF_ADDONS, '/\\') . '/' . $id . '/' . ltrim($bootstrap, '/\\');
    }

    return null;
}
/* =================== AUTO TEMPLATES SYNC =================== */

/**
 * Синхронизирует шаблоны ВСЕХ аддонов (templates/*.html) в master templates (sid=-2).
 * Вызывается на activate(force=true). В рантайме — через af_maybe_sync_templates_runtime().
 */
function af_sync_all_addon_templates(bool $force = false): void
{
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        af_sync_addon_templates($meta, $force);
    }
}

/**
 * Рантайм-проверка: если набор templates-файлов поменялся (mtime/size) — пересинхронизируем.
 * Чтобы не “жевать диск” каждый хит — используем сигнатуру в кэше.
 */
function af_maybe_sync_templates_runtime(): void
{
    if (!empty($GLOBALS['af_templates_synced_runtime'])) {
        return;
    }
    $GLOBALS['af_templates_synced_runtime'] = true;

    // не в админке и не в xmlhttp
    if (defined('IN_ADMINCP')) return;
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'xmlhttp.php') return;

    $sigNow = af_templates_signature_for_enabled_addons();
    if ($sigNow === '') return;

    $sigFile = AF_CACHE.'templates_sig.txt';
    $sigOld  = is_file($sigFile) ? (string)@file_get_contents($sigFile) : '';

    // форсим если стоит needs_refresh
    $needsRefresh = false;
    $nrFile = AF_CACHE.'needs_refresh.txt';
    if (is_file($nrFile)) {
        $needsRefresh = trim((string)@file_get_contents($nrFile)) === '1';
    }

    if (!$needsRefresh && $sigOld === $sigNow) {
        return;
    }

    // пересинк только включённых аддонов (достаточно для рантайма)
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        $id = $meta['id'] ?? '';
        if ($id === '' || !af_is_addon_enabled($id)) continue;
        af_sync_addon_templates($meta, true);
    }

    @file_put_contents($sigFile, $sigNow, LOCK_EX);
    @file_put_contents($nrFile, '0', LOCK_EX);
}

/**
 * Сигнатура templates по ВКЛЮЧЁННЫМ аддонам: учитываем mtime+size всех templates/*.html.
 */
function af_templates_signature_for_enabled_addons(): string
{
    $addons = af_discover_addons();
    $parts  = [];

    foreach ($addons as $meta) {
        $id = $meta['id'] ?? '';
        if ($id === '' || !af_is_addon_enabled($id)) continue;

        $dir = rtrim((string)($meta['path'] ?? ''), '/\\').'/templates/';
        if (!is_dir($dir)) continue;

        $files = glob($dir.'*.html');
        if (!$files) continue;

        sort($files, SORT_STRING);
        foreach ($files as $f) {
            $mt = @filemtime($f) ?: 0;
            $sz = @filesize($f) ?: 0;
            $parts[] = $id.'|'.basename($f).'|'.$mt.'|'.$sz;
        }
    }

    if (!$parts) return '';
    return sha1(implode(';', $parts));
}

/**
 * Синхронизирует шаблоны ОДНОГО аддона:
 * - читает все templates/*.html
 * - парсит блоки <!-- TEMPLATE: name --> ... 
 * - кладёт в mybb_templates (sid=-2)
 */
function af_sync_addon_templates(array $meta, bool $force = false): void
{
    if (empty($meta['id']) || empty($meta['path'])) {
        return;
    }

    $dir = rtrim((string)$meta['path'], '/\\').'/templates/';
    if (!is_dir($dir)) {
        return;
    }

    $files = glob($dir.'*.html');
    if (!$files) {
        return;
    }

    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $raw = (string)@file_get_contents($file);
        if ($raw === '') continue;

        $tpls = af_parse_templates_bundle($raw);
        foreach ($tpls as $title => $html) {
            af_upsert_master_template($title, $html, $force);
        }
    }
}

/**
 * Парсер “бандла” шаблонов:
 * <!-- TEMPLATE: some_name -->
 * html...
 * <!-- TEMPLATE: other_name -->
 * html...
 */
function af_parse_templates_bundle(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    $raw = str_replace("\r", "\n", $raw);

    $out = [];
    $re  = '~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-\.]+)\s*-->\s*(.*?)(?=(?:<!--\s*TEMPLATE:)|\z)~s';
    if (preg_match_all($re, $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $name = trim((string)$one[1]);
            $html = (string)$one[2];
            $html = preg_replace("~\n{3,}~", "\n\n", $html);
            $out[$name] = trim($html);
        }
    }
    return $out;
}

/**
 * Upsert шаблона в master set (sid=-2) с дедупликацией.
 */
function af_upsert_master_template(string $title, string $html, bool $force = false): void
{
    global $db;

    $title = trim($title);
    if ($title === '') return;

    // MyBB любит title без пробелов/экзотики
    $titleNorm = preg_replace('~[^a-zA-Z0-9_\-\.]+~', '_', $title);

    $sid = -2;

    $tEsc = $db->escape_string($titleNorm);
    $q = $db->simple_select('templates', 'tid,template', "title='{$tEsc}' AND sid='{$sid}'", ['order_by'=>'tid','order_dir'=>'asc']);
    $rows = [];
    while ($r = $db->fetch_array($q)) {
        $rows[] = $r;
    }

    // дедуп
    if (count($rows) > 1) {
        $keep = (int)$rows[0]['tid'];
        $kill = [];
        for ($i=1; $i<count($rows); $i++) $kill[] = (int)$rows[$i]['tid'];
        if ($kill) {
            $db->delete_query('templates', "tid IN (".implode(',', $kill).")");
        }
        // оставляем $keep
        $rows = [ $rows[0] ];
        $rows[0]['tid'] = $keep;
    }

    $payload = [
        'title'    => $tEsc,
        'template' => $db->escape_string($html),
        'sid'      => $sid,
        'version'  => '1800',
        'dateline' => TIME_NOW,
    ];

    if (!$rows) {
        $db->insert_query('templates', $payload);
        return;
    }

    $tid = (int)$rows[0]['tid'];
    if (!$force) {
        $cur = (string)$rows[0]['template'];
        if ($cur === (string)$html) {
            return;
        }
    }

    $db->update_query('templates', $payload, "tid='{$tid}'");
}


/* =================== AUTO ASSETS INJECTION =================== */

/**
 * Вклеивает CSS/JS включённых аддонов в HTML один раз.
 */
function af_inject_enabled_addon_assets(string $page): string
{
    // уже вклеивали
    if (strpos($page, '<!--af_assets_done-->') !== false) {
        return $page;
    }

    if (af_should_skip_assets_injection($page)) {
        return $page;
    }

    $assets = af_collect_enabled_addon_assets();
    $css = $assets['css'] ?? [];
    $js  = $assets['js']  ?? [];

    if (!$css && !$js) {
        // всё равно ставим маркер, чтобы не проверять второй раз
        return $page . "\n<!--af_assets_done-->";
    }

    $tags = "\n<!--af_assets_begin-->\n";

    foreach ($css as $href) {
        if ($href === '') continue;
        if (stripos($page, $href) !== false) continue;
        $tags .= '<link rel="stylesheet" type="text/css" href="'.htmlspecialchars_uni($href).'" />'."\n";
    }
    foreach ($js as $src) {
        if ($src === '') continue;
        if (stripos($page, $src) !== false) continue;
        $tags .= '<script type="text/javascript" src="'.htmlspecialchars_uni($src).'" defer></script>'."\n";
    }

    $tags .= "<!--af_assets_end-->\n";

    $page = af_inject_into_head($page, $tags);

    // маркер
    $page .= "\n<!--af_assets_done-->";

    return $page;
}

/**
 * Страховка: если страница пришла вообще без rel="stylesheet", а $stylesheets есть —
 * вклеиваем $stylesheets в head.
 */
function af_inject_core_stylesheets_if_missing(string $page): string
{
    if (defined('IN_ADMINCP')) return $page;
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'xmlhttp.php') return $page;

    // если уже есть хотя бы один stylesheet — не трогаем
    if (stripos($page, 'rel="stylesheet"') !== false || stripos($page, "rel='stylesheet'") !== false) {
        return $page;
    }

    global $stylesheets;
    if (empty($stylesheets) || !is_string($stylesheets)) {
        return $page;
    }

    // не дублим
    if (strpos($page, '<!--af_core_stylesheets_rescue-->') !== false) {
        return $page;
    }

    $tags = "\n<!--af_core_stylesheets_rescue-->\n".$stylesheets."\n";

    return af_inject_into_head($page, $tags);
}

/**
 * Собирает все css/js из assets/ у включённых аддонов.
 * URL строим от bburl.
 */
function af_collect_enabled_addon_assets(): array
{
    global $mybb;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return ['css'=>[], 'js'=>[]];
    }

    $css = [];
    $js  = [];

    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        $id = $meta['id'] ?? '';
        if ($id === '' || !af_is_addon_enabled($id)) continue;

        // Эти аддоны управляют ассетами сами через pre_output.
        // В авто-сканере отключаем их полностью, чтобы не получать дубль версий.
        if ($id === 'advancededitor' || $id === 'advancedprofilefields' || $id === 'advancedalertsandmentions') {
            continue;
        }

        $assetsDir = rtrim((string)($meta['path'] ?? ''), '/\\').'/assets/';
        if (!is_dir($assetsDir)) continue;

        $files = @scandir($assetsDir);
        if (!$files) continue;

        sort($files, SORT_STRING);

        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!is_file($assetsDir.$f)) continue;

            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ($ext !== 'css' && $ext !== 'js') continue;

            $mt = @filemtime($assetsDir.$f) ?: 0;
            $rel = 'inc/plugins/'.AF_PLUGIN_ID.'/addons/'.$id.'/assets/'.$f;
            $url = $bburl.'/'.ltrim($rel,'/');
            if ($mt > 0) {
                $url .= '?v='.$mt;
            }

            if ($ext === 'css') $css[] = $url;
            if ($ext === 'js')  $js[]  = $url;
        }
    }

    // дедуп + стабильность
    $css = array_values(array_unique($css));
    $js  = array_values(array_unique($js));

    return ['css'=>$css, 'js'=>$js];
}

/**
 * Не вставляем ассеты на редиректах/прокладках и т.п.
 */
function af_should_skip_assets_injection(string $page): bool
{
    if (defined('IN_ADMINCP')) return true;
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'xmlhttp.php') return true;

    // редирект-страницы: meta refresh, “redirecting”, “переадресация”
    $p = strtolower($page);
    if (strpos($p, 'http-equiv="refresh"') !== false) return true;
    if (strpos($p, 'redirecting') !== false) return true;
    if (strpos($p, 'переадрес') !== false) return true;
    if (strpos($p, 'class="redirect"') !== false) return true;

    return false;
}

/**
 * Вставляет $insert перед </head>, иначе перед </body>, иначе в конец.
 */
function af_inject_into_head(string $page, string $insert): string
{
    $pos = stripos($page, '</head>');
    if ($pos !== false) {
        return substr($page, 0, $pos) . $insert . substr($page, $pos);
    }

    $pos = stripos($page, '</body>');
    if ($pos !== false) {
        return substr($page, 0, $pos) . $insert . substr($page, $pos);
    }

    return $page . $insert;
}
