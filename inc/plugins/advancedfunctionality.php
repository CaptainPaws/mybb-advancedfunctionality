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
define('AF_THEME_STYLESHEETS_TABLE', 'af_theme_stylesheets');



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

    // 3.1) Инфраструктура theme stylesheets для AF-аддонов
    af_theme_stylesheets_install_schema();

    // 4) Прокси-модуль ACP (чтобы модуль сразу открывался)
    af_write_admin_proxy();

    // 5) Первичная синхронизация языков уже лежащих аддонов (если есть)
    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        af_sync_addon_languages($meta, true);
    }
    af_sync_theme_stylesheets(false);

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
    af_sync_theme_stylesheets(false);

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

    // Папки/языки/AF registry theme stylesheets не трогаем:
    // registry нужен для устойчивого восстановления интеграции после reinstall.
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
        global $mybb, $page, $lang;

        af_ensure_scaffold(false);
        af_ensure_core_languages(false);
        $lang->load('advancedfunctionality');

        $page->add_breadcrumb_item($lang->af_admin_title);

        $action = $mybb->get_input('af_action');
        $addon  = $mybb->get_input('addon');
        $view   = $mybb->get_input('af_view');

        if ($action === 'sync_theme_stylesheets') {
            verify_post_check($mybb->get_input('my_post_key'));
            af_sync_theme_stylesheets(true);
            flash_message('AF theme stylesheets resynced from seed files.', 'success');
            admin_redirect('index.php?module='.AF_PLUGIN_ID.'&_='.TIME_NOW);
        }

        if (strpos((string)$action, 'theme_stylesheets_') === 0) {
            verify_post_check($mybb->get_input('my_post_key'));
            self::handleThemeStylesheetsAction($action, $addon);
        }

        if ($action && $addon && strpos((string)$action, 'theme_stylesheets_') !== 0) {
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

        if ($view === 'theme_stylesheets') {
            self::renderThemeStylesheetsPage();
        } elseif ($view) {
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

    private static function handleThemeStylesheetsAction(string $action, string $addon): void
    {
        global $mybb, $lang;

        $addonId = trim($addon);
        $logicalId = trim((string)$mybb->get_input('logical_id'));
        $confirmForce = $mybb->get_input('confirm_force', MyBB::INPUT_INT) === 1;
        $themeTid = $mybb->get_input('theme_tid', MyBB::INPUT_INT);
        $themeScope = strtolower(trim((string)$mybb->get_input('theme_scope')));
        if (!in_array($themeScope, ['current', 'all'], true)) {
            $themeScope = 'all';
        }

        if ($action === 'theme_stylesheets_force_resync' && !$confirmForce) {
            flash_message($lang->af_theme_stylesheets_force_confirm, 'error');
            admin_redirect(self::themeStylesheetsUrl($addonId !== '' ? $addonId : null, $themeTid > 0 ? $themeTid : null, $themeScope));
        }

        $op = 'status';
        if ($action === 'theme_stylesheets_sync_all') {
            $op = 'sync_all';
        } elseif ($action === 'theme_stylesheets_integrate') {
            $op = 'integrate';
        } elseif ($action === 'theme_stylesheets_set_file_mode') {
            $op = 'set_file_mode';
        } elseif ($action === 'theme_stylesheets_set_theme_mode') {
            $op = 'set_theme_mode';
        } elseif ($action === 'theme_stylesheets_set_auto_mode') {
            $op = 'set_auto_mode';
        } elseif ($action === 'theme_stylesheets_sync_addon') {
            $op = 'sync_addon';
        } elseif ($action === 'theme_stylesheets_rebuild_missing') {
            $op = 'rebuild_missing';
        } elseif ($action === 'theme_stylesheets_force_resync') {
            $op = 'force_resync';
        } elseif ($action === 'theme_stylesheets_restore_safe') {
            $op = 'restore_safe';
        } elseif ($action === 'theme_stylesheets_hash_status') {
            $op = 'hash_status';
        }

        $result = af_theme_stylesheets_execute_action($op, $addonId !== '' ? $addonId : null, $confirmForce, $themeTid > 0 ? $themeTid : null, $logicalId !== '' ? $logicalId : null);
        $message = af_theme_stylesheets_action_message($op, $result, $lang);
        flash_message($message, 'success');
        admin_redirect(self::themeStylesheetsUrl($addonId !== '' ? $addonId : null, $themeTid > 0 ? $themeTid : null, $themeScope));
    }

    private static function renderThemeStylesheetsPage(): void
    {
        global $mybb, $lang;

        $addonFilter = trim((string)$mybb->get_input('addon'));
        $themeFilter = strtolower(trim((string)$mybb->get_input('theme_scope')));
        if (!in_array($themeFilter, ['current', 'all'], true)) {
            $themeFilter = 'all';
        }

        $currentThemeTid = self::currentThemeTid();
        $rows = af_collect_theme_stylesheet_diagnostics($addonFilter !== '' ? $addonFilter : null);

        if ($themeFilter === 'current') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($currentThemeTid): bool {
                return (int)($row['theme_tid'] ?? 0) === $currentThemeTid;
            }));
        }

        echo '<h2>'.htmlspecialchars_uni($lang->af_theme_stylesheets_title).'</h2>';
        echo '<p class="smalltext">'.htmlspecialchars_uni($lang->af_theme_stylesheets_help).'</p>';
        echo '<p class="smalltext">'.htmlspecialchars_uni($lang->af_theme_stylesheets_help_actions).'</p>';

        self::renderThemeStylesheetFilters($addonFilter, $themeFilter);

        echo '<div style="margin:8px 0 14px 0;">';
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_sync_all', $lang->af_theme_stylesheets_sync_all, '', false, false, $themeFilter);
        echo '&nbsp;';
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_rebuild_missing', $lang->af_theme_stylesheets_rebuild_missing, '', false, false, $themeFilter);
        echo '&nbsp;';
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_force_resync', $lang->af_theme_stylesheets_force_resync, '', false, true, $themeFilter);
        echo '&nbsp;';
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_hash_status', $lang->af_theme_stylesheets_hash_status, '', false, false, $themeFilter);
        echo '</div>';

        $table = new Table;
        $table->construct_header($lang->af_theme_stylesheets_col_theme_id, ['width' => '5%']);
        $table->construct_header($lang->af_theme_stylesheets_col_theme_title, ['width' => '11%']);
        $table->construct_header($lang->af_theme_stylesheets_col_addon, ['width' => '8%']);
        $table->construct_header($lang->af_theme_stylesheets_col_logical, ['width' => '8%']);
        $table->construct_header($lang->af_theme_stylesheets_col_name, ['width' => '10%']);
        $table->construct_header($lang->af_theme_stylesheets_col_seed, ['width' => '14%']);
        $table->construct_header($lang->af_theme_stylesheets_col_mode, ['width' => '6%']);
        $table->construct_header($lang->af_theme_stylesheets_col_status, ['width' => '8%']);
        $table->construct_header($lang->af_theme_stylesheets_col_attached, ['width' => '10%']);
        $table->construct_header($lang->af_theme_stylesheets_col_sync, ['width' => '8%']);
        $table->construct_header($lang->af_theme_stylesheets_col_actions, ['width' => '12%']);

        if (!$rows) {
            $table->construct_cell($lang->af_theme_stylesheets_empty, ['colspan' => 11, 'class' => 'align_center']);
            $table->construct_row();
        } else {
            foreach ($rows as $row) {
                $addonId = (string)$row['addon_id'];
                $themeTid = (int)($row['theme_tid'] ?? 0);
                $statusRaw = (string)$row['status'];
                $statusLabelKey = 'af_theme_stylesheets_status_'.$statusRaw;
                $statusLabel = isset($lang->{$statusLabelKey}) ? $lang->{$statusLabelKey} : $statusRaw;
                $statusColor = '#2f6f2f';
                if ($statusRaw === 'missing' || $statusRaw === 'outdated') $statusColor = '#a66900';
                if ($statusRaw === 'not_integrated') $statusColor = '#555';
                if ($statusRaw === 'manual_override' || $statusRaw === 'duplicate_risk') $statusColor = '#a00';

                $lastSync = !empty($row['last_synced_at']) ? my_date('relative', (int)$row['last_synced_at']) : '—';
                $mode = htmlspecialchars_uni((string)$row['mode']);
                $attached = htmlspecialchars_uni((string)$row['attached_to']);
                $seedFile = htmlspecialchars_uni((string)$row['seed_file']);

                $actions = [];
                if (empty($row['is_integrated'])) {
                    $actions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_integrate', $lang->af_theme_stylesheets_integrate, $addonId, true, false, $themeFilter, $themeTid, (string)$row['logical_id']);
                }
                $actions[] = self::buildThemeStylesheetEditLink($row, $lang->af_theme_stylesheets_edit_stylesheet, 'edit_stylesheet');
                $actions[] = self::buildThemeStylesheetEditLink($row, $lang->af_theme_stylesheets_edit_properties, 'stylesheet_properties');
                $actions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_set_file_mode', $lang->af_theme_stylesheets_set_file_mode, $addonId, true, false, $themeFilter, $themeTid, (string)$row['logical_id']);
                $actions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_set_theme_mode', $lang->af_theme_stylesheets_set_theme_mode, $addonId, true, false, $themeFilter, $themeTid, (string)$row['logical_id']);
                $actions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_sync_addon', $lang->af_theme_stylesheets_sync_addon, $addonId, true, false, $themeFilter, $themeTid);
                $actions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_force_resync', $lang->af_theme_stylesheets_force_resync, $addonId, true, true, $themeFilter, $themeTid);
                $actions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_rebuild_missing', $lang->af_theme_stylesheets_rebuild_missing, $addonId, true, false, $themeFilter, $themeTid);

                $table->construct_cell((string)$themeTid, ['class' => 'align_center']);
                $table->construct_cell(htmlspecialchars_uni((string)($row['theme_title'] ?? ('Theme #'.$themeTid))));
                $table->construct_cell(htmlspecialchars_uni($addonId));
                $table->construct_cell(htmlspecialchars_uni((string)$row['logical_id']));
                $table->construct_cell(htmlspecialchars_uni((string)$row['stylesheet_name']));
                $table->construct_cell($seedFile);
                $table->construct_cell($mode, ['class' => 'align_center']);
                $table->construct_cell('<span style="color:'.$statusColor.';font-weight:600;">'.htmlspecialchars_uni($statusLabel).'</span>');
                $table->construct_cell($attached);
                $table->construct_cell(htmlspecialchars_uni($lastSync), ['class' => 'align_center']);
                $table->construct_cell(implode('<br />', $actions));
                $table->construct_row();
            }
        }

        $table->output($lang->af_theme_stylesheets_title);
    }

    private static function renderThemeStylesheetFilters(string $addonFilter, string $themeScope): void
    {
        global $lang;

        $addons = [];
        foreach (self::discoverAddons() as $meta) {
            $id = (string)($meta['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $addons[$id] = (string)($meta['name'] ?? $id);
        }
        ksort($addons, SORT_STRING);

        echo '<form method="get" action="index.php" style="margin:10px 0 12px 0;">';
        echo '<input type="hidden" name="module" value="'.AF_PLUGIN_ID.'">';
        echo '<input type="hidden" name="af_view" value="theme_stylesheets">';
        echo '<label style="margin-right:8px;">'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_addon).': ';
        echo '<select name="addon">';
        echo '<option value="">'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_all_addons).'</option>';
        foreach ($addons as $id => $title) {
            $selected = ($addonFilter === $id) ? ' selected="selected"' : '';
            echo '<option value="'.htmlspecialchars_uni($id).'"'.$selected.'>'.htmlspecialchars_uni($title).' ('.htmlspecialchars_uni($id).')</option>';
        }
        echo '</select></label>';

        echo '<label style="margin-right:8px;">'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_theme_scope).': ';
        echo '<select name="theme_scope">';
        echo '<option value="all"'.($themeScope === 'all' ? ' selected="selected"' : '').'>'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_all_themes).'</option>';
        echo '<option value="current"'.($themeScope === 'current' ? ' selected="selected"' : '').'>'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_current_theme).'</option>';
        echo '</select></label>';

        echo '<input type="submit" class="submit_button" value="'.htmlspecialchars_uni($lang->af_theme_stylesheets_apply_filter).'">';
        echo '&nbsp;<a href="index.php?module='.AF_PLUGIN_ID.'&amp;af_view=theme_stylesheets&amp;theme_scope=all">'.htmlspecialchars_uni($lang->af_theme_stylesheets_clear_filter).'</a>';
        echo '</form>';
    }

    private static function buildThemeStylesheetEditLink(array $row, string $label, string $action): string
    {
        $themeTid = (int)($row['theme_tid'] ?? 0);
        $file = trim((string)($row['db_stylesheet_name'] ?? ($row['stylesheet_name'] ?? '')));

        if ($themeTid <= 0 || $file === '') {
            return '<span style="color:#777;">'.htmlspecialchars_uni($label).'</span>';
        }

        $resolvedAction = 'edit_stylesheet';
        if ($action === 'stylesheet_properties') {
            $resolvedAction = 'stylesheet_properties';
        }

        $url = 'index.php?module=style-themes&amp;action='.$resolvedAction.'&amp;file='.rawurlencode($file).'&amp;tid='.$themeTid;
        if ($resolvedAction === 'edit_stylesheet') {
            $url .= '&amp;mode=advanced';
        }

        return '<a href="'.$url.'">'.htmlspecialchars_uni($label).'</a>';
    }

    private static function renderThemeStylesheetActionForm(string $action, string $label, string $addon = '', bool $inline = false, bool $confirm = false, string $themeScope = 'all', ?int $themeTid = null, string $logicalId = ''): string
    {
        global $mybb;

        $html = '<form method="post" action="index.php?module='.AF_PLUGIN_ID.'" style="margin:0;'.($inline ? 'display:inline-block;' : '').'">';
        $html .= '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'">';
        $html .= '<input type="hidden" name="af_view" value="theme_stylesheets">';
        $html .= '<input type="hidden" name="theme_scope" value="'.htmlspecialchars_uni($themeScope).'">';
        $html .= '<input type="hidden" name="af_action" value="'.htmlspecialchars_uni($action).'">';
        if ($addon !== '') {
            $html .= '<input type="hidden" name="addon" value="'.htmlspecialchars_uni($addon).'">';
        }
        if ($themeTid !== null && $themeTid > 0) {
            $html .= '<input type="hidden" name="theme_tid" value="'.(int)$themeTid.'">';
        }
        if ($logicalId !== '') {
            $html .= '<input type="hidden" name="logical_id" value="'.htmlspecialchars_uni($logicalId).'">';
        }
        if ($confirm) {
            $html .= '<input type="hidden" name="confirm_force" value="1">';
        }
        $html .= '<input type="submit" class="submit_button" value="'.htmlspecialchars_uni($label).'">';
        $html .= '</form>';

        return $html;
    }

    private static function currentThemeTid(): int
    {
        $theme = $GLOBALS['theme'] ?? null;
        if (is_array($theme ?? null)) {
            $tid = (int)($theme['tid'] ?? 0);
            if ($tid > 0) {
                return $tid;
            }
        }

        global $mybb;
        $tid = (int)($mybb->settings['theme'] ?? 0);
        if ($tid > 1) {
            return $tid;
        }
        global $db;
        $q = $db->simple_select('themes', 'tid', "tid > 1", ['order_by' => 'tid', 'order_dir' => 'asc', 'limit' => 1]);
        $fallback = (int)$db->fetch_field($q, 'tid');
        return $fallback > 1 ? $fallback : 1;
    }

    private static function themeStylesheetsUrl(?string $addon = null, ?int $themeTid = null, string $themeScope = 'all'): string
    {
        $url = 'index.php?module='.AF_PLUGIN_ID.'&af_view=theme_stylesheets';
        if ($addon !== null && $addon !== '') {
            $url .= '&addon='.rawurlencode($addon);
        }
        if ($themeTid !== null && $themeTid > 0) {
            $url .= '&theme_tid='.(int)$themeTid;
        }
        if (!in_array($themeScope, ['all', 'current'], true)) {
            $themeScope = 'all';
        }
        $url .= '&theme_scope='.$themeScope;
        return $url;
    }

    public static function collectAdminSections(): array
    {
        $out = [[
            'id' => 'core',
            'slug' => 'theme_stylesheets',
            'title' => 'Theme Stylesheets',
        ]];
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
        af_sync_theme_stylesheets(false, $id);
    }

    public static function disableAddon(string $id): void
    {
        self::ensureEnabledSetting($id, 0);
        af_rebuild_and_reload_settings();
        af_sync_theme_stylesheets(false, $id);
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
    global \$page, \$mybb, \$lang;

    // Подтягиваем основной файл плагина, чтобы были доступны af_* хелперы
    require_once MYBB_ROOT.'inc/plugins/advancedfunctionality.php';
    \$lang->load('advancedfunctionality');

    // Базовые подпункты
    \$sub_menu   = [];
    \$sub_menu[] = [
        'id'    => 'index',
        'title' => (string)(\$lang->af_admin_menu_overview ?? 'Обзор аддонов'),
        'link'  => 'index.php?module=advancedfunctionality',
    ];
    \$sub_menu[] = [
        'id'    => 'view_theme_stylesheets',
        'title' => (string)(\$lang->af_theme_stylesheets_title ?? 'Theme Stylesheets'),
        'link'  => 'index.php?module=advancedfunctionality&af_view=theme_stylesheets',
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

        if (!empty(\$meta['admin']['menu']) && is_array(\$meta['admin']['menu'])) {
            foreach (\$meta['admin']['menu'] as \$menuItem) {
                if (!is_array(\$menuItem)) { continue; }
                \$menuDo = preg_replace('~[^a-z0-9_\\-]+~i', '', (string)(\$menuItem['do'] ?? ''));
                if (\$menuDo === '') { continue; }
                \$menuTitle = trim((string)(\$menuItem['title'] ?? ''));
                if (\$menuTitle === '') { continue; }

                \$sub_menu[] = [
                    'id'    => 'view_'.\$slug.'_'.\$menuDo,
                    'title' => \$menuTitle,
                    'link'  => 'index.php?module=advancedfunctionality&af_view='.rawurlencode(\$slug).'&do='.rawurlencode(\$menuDo),
                ];
            }
        }
    }

    // Регистрируем пункт верхнего уровня + подпункты
    \$page->add_menu_item((string)(\$lang->af_admin_title ?? 'Advanced functionality'), 'advancedfunctionality', 'index.php?module=advancedfunctionality', 60, \$sub_menu);
    return true;
}

/**
 * Подсветка активного подпункта и выбор файла контроллера.
 * Мы всегда возвращаем 'index.php' — дальше роутер AF разбирает af_view сам.
 */
function advancedfunctionality_action_handler(\$action)
{
    global \$page, \$mybb, \$lang;
    \$page->active_module = 'advancedfunctionality';

    \$view = \$mybb->get_input('af_view');
    \$do = preg_replace('~[^a-z0-9_\\-]+~i', '', (string)\$mybb->get_input('do'));
    if (\$view) {
        \$viewSafe = preg_replace('~[^a-z0-9_\\-]+~i', '', (string)\$view);
        \$page->active_action = 'view_'.\$viewSafe.((\$do !== '') ? ('_'.\$do) : '');
        return 'index.php';
    }

    \$page->active_action = 'index';
    return 'index.php';
}

/** Права доступа модуля */
function advancedfunctionality_admin_permissions()
{
    return [
        'name'        => 'Advanced functionality',
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
            'af_admin_menu_overview' => 'Обзор аддонов',
            'af_theme_stylesheets_title' => 'Стили тем AF',
            'af_theme_stylesheets_help' => 'Сервисная страница регистрации и обслуживания стилей тем, управляемых AF.',
            'af_theme_stylesheets_help_actions' => 'Редактирование CSS и свойств выполняется в штатном редакторе stylesheet’ов ACP MyBB (кнопки Edit stylesheet / Edit properties). Sync/Rebuild/Force resync — только сервисные действия.',
            'af_theme_stylesheets_col_theme_id' => 'ID темы',
            'af_theme_stylesheets_col_theme_title' => 'Название темы',
            'af_theme_stylesheets_col_addon' => 'ID аддона',
            'af_theme_stylesheets_col_logical' => 'Логический ID',
            'af_theme_stylesheets_col_name' => 'Stylesheet MyBB',
            'af_theme_stylesheets_col_seed' => 'Seed-файл',
            'af_theme_stylesheets_col_mode' => 'Режим',
            'af_theme_stylesheets_col_status' => 'Статус',
            'af_theme_stylesheets_col_attached' => 'Подключён к',
            'af_theme_stylesheets_col_sync' => 'Последняя синхронизация',
            'af_theme_stylesheets_col_actions' => 'Действия',
            'af_theme_stylesheets_empty' => 'Зарегистрированные theme stylesheets не найдены.',
            'af_theme_stylesheets_edit_stylesheet' => 'Редактировать stylesheet',
            'af_theme_stylesheets_edit_properties' => 'Редактировать свойства',
            'af_theme_stylesheets_integrate' => 'Интегрировать в ACP',
            'af_theme_stylesheets_set_file_mode' => 'Режим file',
            'af_theme_stylesheets_set_theme_mode' => 'Режим theme',
            'af_theme_stylesheets_sync_all' => 'Синхронизировать всё',
            'af_theme_stylesheets_sync_addon' => 'Синхронизировать аддон',
            'af_theme_stylesheets_rebuild_missing' => 'Восстановить отсутствующие',
            'af_theme_stylesheets_force_resync' => 'Принудительная пересинхронизация',
            'af_theme_stylesheets_hash_status' => 'Показать статус diff/hash',
            'af_theme_stylesheets_restore_safe' => 'Восстановить из seed (safe)',
            'af_theme_stylesheets_filter_addon' => 'Фильтр по аддону',
            'af_theme_stylesheets_filter_all_addons' => 'Все аддоны',
            'af_theme_stylesheets_filter_theme_scope' => 'Темы',
            'af_theme_stylesheets_filter_current_theme' => 'Текущая тема',
            'af_theme_stylesheets_filter_all_themes' => 'Все темы',
            'af_theme_stylesheets_apply_filter' => 'Применить фильтр',
            'af_theme_stylesheets_clear_filter' => 'Сбросить',
            'af_theme_stylesheets_force_confirm' => 'Force resync требует явного подтверждения (confirm_force=1).',
            'af_theme_stylesheets_diag_found' => 'найдено в теме',
            'af_theme_stylesheets_diag_seed' => 'контрольная сумма seed',
            'af_theme_stylesheets_diag_duplicate' => 'риск дубликата',
            'af_theme_stylesheets_diag_attached' => 'подключённые страницы',
            'af_theme_stylesheets_status_ok' => 'ок',
            'af_theme_stylesheets_status_not_integrated' => 'не интегрирован',
            'af_theme_stylesheets_status_missing' => 'отсутствует',
            'af_theme_stylesheets_status_manual_override' => 'изменён в ACP (локальный override)',
            'af_theme_stylesheets_status_outdated' => 'устарел',
            'af_theme_stylesheets_status_duplicate_risk' => 'риск дубликата',
            'af_theme_stylesheets_msg_integrate' => 'Интеграция stylesheet выполнена.',
            'af_theme_stylesheets_msg_set_file_mode' => 'Режим file включён.',
            'af_theme_stylesheets_msg_set_theme_mode' => 'Режим theme включён.',
            'af_theme_stylesheets_msg_set_auto_mode' => 'Режим auto включён.',
            'af_theme_stylesheets_msg_sync_all' => 'Синхронизация всех завершена.',
            'af_theme_stylesheets_msg_sync_addon' => 'Синхронизация аддона завершена.',
            'af_theme_stylesheets_msg_rebuild_missing' => 'Восстановление отсутствующих завершено.',
            'af_theme_stylesheets_msg_force_resync' => 'Принудительная пересинхронизация завершена.',
            'af_theme_stylesheets_msg_restore_safe' => 'Безопасное восстановление завершено.',
            'af_theme_stylesheets_msg_hash_status' => 'Статус обновлён.',
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
            'af_admin_menu_overview' => 'Addons overview',
            'af_theme_stylesheets_title' => 'AF Theme Stylesheets',
            'af_theme_stylesheets_help' => 'Service page for registering and maintaining AF-managed theme stylesheets.',
            'af_theme_stylesheets_help_actions' => 'CSS and stylesheet properties are edited in native MyBB ACP stylesheet editor (Edit stylesheet / Edit properties). Sync/Rebuild/Force resync are service operations only.',
            'af_theme_stylesheets_col_theme_id' => 'Theme ID',
            'af_theme_stylesheets_col_theme_title' => 'Theme title',
            'af_theme_stylesheets_col_addon' => 'Addon ID',
            'af_theme_stylesheets_col_logical' => 'Logical ID',
            'af_theme_stylesheets_col_name' => 'MyBB stylesheet',
            'af_theme_stylesheets_col_seed' => 'Seed file',
            'af_theme_stylesheets_col_mode' => 'Mode',
            'af_theme_stylesheets_col_status' => 'Status',
            'af_theme_stylesheets_col_attached' => 'Attached to',
            'af_theme_stylesheets_col_sync' => 'Last sync',
            'af_theme_stylesheets_col_actions' => 'Actions',
            'af_theme_stylesheets_empty' => 'No registered theme stylesheets found.',
            'af_theme_stylesheets_edit_stylesheet' => 'Edit stylesheet',
            'af_theme_stylesheets_edit_properties' => 'Edit properties',
            'af_theme_stylesheets_integrate' => 'Integrate into ACP',
            'af_theme_stylesheets_set_file_mode' => 'Switch to file mode',
            'af_theme_stylesheets_set_theme_mode' => 'Switch to theme mode',
            'af_theme_stylesheets_sync_all' => 'Sync all',
            'af_theme_stylesheets_sync_addon' => 'Sync addon',
            'af_theme_stylesheets_rebuild_missing' => 'Rebuild missing',
            'af_theme_stylesheets_force_resync' => 'Force resync',
            'af_theme_stylesheets_hash_status' => 'Show diff/hash status',
            'af_theme_stylesheets_restore_safe' => 'Restore from seed (safe)',
            'af_theme_stylesheets_filter_addon' => 'Addon filter',
            'af_theme_stylesheets_filter_all_addons' => 'All addons',
            'af_theme_stylesheets_filter_theme_scope' => 'Themes',
            'af_theme_stylesheets_filter_current_theme' => 'Current theme',
            'af_theme_stylesheets_filter_all_themes' => 'All themes',
            'af_theme_stylesheets_apply_filter' => 'Apply filter',
            'af_theme_stylesheets_clear_filter' => 'clear',
            'af_theme_stylesheets_force_confirm' => 'Force resync requires explicit confirmation (confirm_force=1).',
            'af_theme_stylesheets_diag_found' => 'found in theme',
            'af_theme_stylesheets_diag_seed' => 'seed checksum',
            'af_theme_stylesheets_diag_duplicate' => 'duplicate risk',
            'af_theme_stylesheets_diag_attached' => 'attached pages',
            'af_theme_stylesheets_status_ok' => 'ok',
            'af_theme_stylesheets_status_not_integrated' => 'not integrated',
            'af_theme_stylesheets_status_missing' => 'missing',
            'af_theme_stylesheets_status_manual_override' => 'edited in ACP (local override)',
            'af_theme_stylesheets_status_outdated' => 'outdated',
            'af_theme_stylesheets_status_duplicate_risk' => 'duplicate risk',
            'af_theme_stylesheets_msg_integrate' => 'Stylesheet integrated.',
            'af_theme_stylesheets_msg_set_file_mode' => 'File mode enabled.',
            'af_theme_stylesheets_msg_set_theme_mode' => 'Theme mode enabled.',
            'af_theme_stylesheets_msg_set_auto_mode' => 'Auto mode enabled.',
            'af_theme_stylesheets_msg_sync_all' => 'Sync all completed.',
            'af_theme_stylesheets_msg_sync_addon' => 'Sync addon completed.',
            'af_theme_stylesheets_msg_rebuild_missing' => 'Rebuild missing completed.',
            'af_theme_stylesheets_msg_force_resync' => 'Force resync completed.',
            'af_theme_stylesheets_msg_restore_safe' => 'Safe restore completed.',
            'af_theme_stylesheets_msg_hash_status' => 'Status refreshed.',
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

$plugins->add_hook('xmlhttp', 'af_core_xmlhttp_bootstrap_addons', 1);


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

function af_core_xmlhttp_bootstrap_addons(): void
{
    global $mybb;

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'xmlhttp.php') {
        return;
    }
    if (!defined('AF_ADDONS')) {
        return;
    }

    $base = rtrim((string)AF_ADDONS, '/');
    if ($base === '' || !is_dir($base)) {
        return;
    }

    $manifestFiles = glob($base . '/*/manifest.php');
    if (!$manifestFiles || !is_array($manifestFiles)) {
        return;
    }

    foreach ($manifestFiles as $mf) {
        if (!is_string($mf) || $mf === '' || !is_file($mf)) {
            continue;
        }

        $manifest = require $mf;
        if (!is_array($manifest)) {
            continue;
        }

        $id = (string)($manifest['id'] ?? '');
        if ($id === '') {
            // fallback по папке
            $id = basename(dirname($mf));
        }
        if ($id === '') {
            continue;
        }

        // включён ли аддон (канон AF: af_{id}_enabled)
        $enabledKey = 'af_' . $id . '_enabled';
        if (empty($mybb->settings[$enabledKey])) {
            continue;
        }

        $bootstrap = (string)($manifest['bootstrap'] ?? ($id . '.php'));
        if ($bootstrap === '') {
            continue;
        }

        $bootstrapPath = $base . '/' . $id . '/' . $bootstrap;
        if (is_file($bootstrapPath)) {
            require_once $bootstrapPath;
        }

        // ВАЖНО: в xmlhttp-рантайме зовём ТОЛЬКО явную xmlhttp-инициализацию аддона
        $xmlInitFn = 'af_' . $id . '_xmlhttp_init';
        if (function_exists($xmlInitFn)) {
            $xmlInitFn();
        }
    }
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

    // 4) Last-resort дедуп <script src> по clean path (предохранитель против ручных инжекторов)
    $page = af_hard_dedup_script_src_tags($page);

    return $page;
}

/**
 * Last-resort дедупликация одинаковых <script src="..."> по clean path.
 * Нужна как предохранитель, если какой-то аддон обошёл AF Asset Manager.
 */
function af_hard_dedup_script_src_tags(string $page): string
{
    if ($page === '' || stripos($page, '<script') === false) {
        return $page;
    }

    $seen = [];

    return (string)preg_replace_callback(
        '~<script\b[^>]*\bsrc\s*=\s*("|\')(.*?)\1[^>]*>\s*</script>~is',
        static function (array $m) use (&$seen): string {
            $src = html_entity_decode((string)($m[2] ?? ''), ENT_QUOTES, 'UTF-8');
            $cleanPath = af_asset_clean_path($src);
            if ($cleanPath === '') {
                return $m[0];
            }

            if (isset($seen[$cleanPath])) {
                return '';
            }

            $seen[$cleanPath] = true;
            return $m[0];
        },
        $page
    );
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

function af_db_table_columns(string $table): array
{
    global $db;

    $out = [];
    $q = $db->write_query("SHOW COLUMNS FROM ".TABLE_PREFIX.$db->escape_string($table));
    while ($row = $db->fetch_array($q)) {
        $name = strtolower((string)($row['Field'] ?? ''));
        if ($name !== '') {
            $out[$name] = true;
        }
    }

    return $out;
}

function af_theme_stylesheets_install_schema(): void
{
    global $db;

    $table = TABLE_PREFIX.AF_THEME_STYLESHEETS_TABLE;
    if (!$db->table_exists(AF_THEME_STYLESHEETS_TABLE)) {
        $collation = $db->build_create_table_collation();
        $db->write_query("
            CREATE TABLE {$table} (
                id int unsigned NOT NULL auto_increment,
                theme_tid int unsigned NOT NULL default 0,
                stylesheet_sid int unsigned NOT NULL default 0,
                addon_id varchar(120) NOT NULL default '',
                logical_id varchar(190) NOT NULL default '',
                stylesheet_name varchar(190) NOT NULL default '',
                source_file varchar(255) NOT NULL default '',
                seed_file varchar(255) NOT NULL default '',
                seed_checksum char(40) NOT NULL default '',
                last_synced_checksum char(40) NOT NULL default '',
                is_integrated tinyint(1) NOT NULL default 0,
                delivery_mode varchar(20) NOT NULL default 'auto',
                discovered_from varchar(40) NOT NULL default '',
                is_admin_only tinyint(1) NOT NULL default 0,
                last_synced_at int unsigned NOT NULL default 0,
                manual_override tinyint(1) NOT NULL default 0,
                created_at int unsigned NOT NULL default 0,
                updated_at int unsigned NOT NULL default 0,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_theme_addon_logical (theme_tid, addon_id, logical_id),
                KEY idx_stylesheet_sid (stylesheet_sid),
                KEY idx_addon (addon_id)
            ) ENGINE=InnoDB {$collation};
        ");
        return;
    }

    $columns = af_db_table_columns(AF_THEME_STYLESHEETS_TABLE);
    $queries = [];
    if (!isset($columns['source_file'])) {
        $queries[] = "ALTER TABLE {$table} ADD COLUMN source_file varchar(255) NOT NULL default '' AFTER stylesheet_name";
    }
    if (!isset($columns['is_integrated'])) {
        $queries[] = "ALTER TABLE {$table} ADD COLUMN is_integrated tinyint(1) NOT NULL default 0 AFTER last_synced_checksum";
    }
    if (!isset($columns['delivery_mode'])) {
        $queries[] = "ALTER TABLE {$table} ADD COLUMN delivery_mode varchar(20) NOT NULL default 'auto' AFTER is_integrated";
    }
    if (!isset($columns['discovered_from'])) {
        $queries[] = "ALTER TABLE {$table} ADD COLUMN discovered_from varchar(40) NOT NULL default '' AFTER delivery_mode";
    }
    if (!isset($columns['is_admin_only'])) {
        $queries[] = "ALTER TABLE {$table} ADD COLUMN is_admin_only tinyint(1) NOT NULL default 0 AFTER discovered_from";
    }
    foreach ($queries as $sql) {
        $db->write_query($sql);
    }
}

function af_theme_stylesheet_base_from_rel(string $rel): string
{
    $rel = strtolower(trim($rel));
    if ($rel === '') {
        return 'main';
    }
    if (substr($rel, -4) === '.css') {
        $rel = substr($rel, 0, -4);
    }
    $rel = preg_replace('~[^a-z0-9_./-]+~', '_', $rel);
    $rel = trim(str_replace(['\\', '/'], '__', $rel), '_');
    return $rel !== '' ? $rel : 'main';
}

function af_theme_stylesheet_short_addon_id(string $addonId): string
{
    $addonId = strtolower(trim($addonId));
    $addonId = preg_replace('~[^a-z0-9]+~', '_', $addonId);
    $addonId = trim((string)$addonId, '_');
    if ($addonId === '') {
        return 'core';
    }
    $parts = preg_split('~_+~', $addonId) ?: [];
    $short = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $short .= substr($part, 0, 1);
        if (strlen($short) >= 6) {
            break;
        }
    }
    if ($short === '') {
        $short = substr($addonId, 0, 6);
    }
    return substr($short, 0, 6);
}

function af_theme_stylesheet_build_name(string $addonId, string $logicalId, string $sourceFileRel, string $preferred = ''): string
{
    $preferred = strtolower(trim($preferred));
    $preferred = preg_replace('~[^a-z0-9_.-]+~', '_', $preferred);
    if ($preferred !== '') {
        if (substr($preferred, -4) !== '.css') {
            $preferred .= '.css';
        }
        if (strlen($preferred) <= 30) {
            return $preferred;
        }
    }

    $alias = af_theme_stylesheet_short_addon_id($addonId);
    $hash = substr(sha1(strtolower($addonId.'|'.$logicalId.'|'.$sourceFileRel)), 0, 6);
    $name = 'af_'.$alias.'_'.$hash.'.css';
    if (strlen($name) > 30) {
        $name = 'af_'.substr($hash, 0, 4).'.css';
    }
    return $name;
}

function af_discover_addon_css_candidates(?array $addons = null): array
{
    $addons = is_array($addons) ? $addons : af_discover_addons();
    $out = [];

    foreach ($addons as $meta) {
        $addonId = trim((string)($meta['id'] ?? ''));
        $addonPath = rtrim((string)($meta['path'] ?? ''), '/\\');
        if ($addonId === '' || $addonPath === '') {
            continue;
        }

        $seenByRel = [];
        $manifestAssets = $meta['assets'] ?? [];
        if (is_array($manifestAssets)) {
            foreach (['front', 'admin', 'both'] as $section) {
                $isAdminOnly = ($section === 'admin');
                foreach ((array)($manifestAssets[$section]['css'] ?? []) as $assetRel) {
                    $assetRel = ltrim(str_replace('\\', '/', trim((string)$assetRel)), '/');
                    if ($assetRel === '' || substr(strtolower($assetRel), -4) !== '.css') {
                        continue;
                    }
                    $seenByRel[$assetRel] = true;
                    $base = af_theme_stylesheet_base_from_rel($assetRel);
                    $out[] = [
                        'addon_id' => $addonId,
                        'addon_meta' => $meta,
                        'source_file_abs' => $addonPath.'/'.$assetRel,
                        'source_file_rel' => $assetRel,
                        'logical_id' => $addonId.'__'.$base,
                        'stylesheet_name' => af_theme_stylesheet_build_name($addonId, $addonId.'__'.$base, $assetRel),
                        'is_admin_only' => $isAdminOnly,
                        'is_frontend_candidate' => !$isAdminOnly,
                        'suggested_attach' => [['file' => 'global']],
                        'enabled_setting' => 'af_'.$addonId.'_enabled',
                        'discovered_from' => 'manifest_assets',
                    ];
                }
            }
        }

        $assetsDir = $addonPath.'/assets';
        if (is_dir($assetsDir)) {
            $files = @scandir($assetsDir);
            if (is_array($files)) {
                sort($files, SORT_STRING);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    if (substr(strtolower($file), -4) !== '.css') {
                        continue;
                    }
                    $rel = 'assets/'.$file;
                    if (isset($seenByRel[$rel])) {
                        continue;
                    }
                    $isAdminOnly = af_asset_is_admin_only('/inc/plugins/'.AF_PLUGIN_ID.'/addons/'.$addonId.'/'.$rel);
                    $base = af_theme_stylesheet_base_from_rel($rel);
                    $out[] = [
                        'addon_id' => $addonId,
                        'addon_meta' => $meta,
                        'source_file_abs' => $assetsDir.'/'.$file,
                        'source_file_rel' => $rel,
                        'logical_id' => $addonId.'__'.$base,
                        'stylesheet_name' => af_theme_stylesheet_build_name($addonId, $addonId.'__'.$base, $rel),
                        'is_admin_only' => $isAdminOnly,
                        'is_frontend_candidate' => !$isAdminOnly,
                        'suggested_attach' => [['file' => 'global']],
                        'enabled_setting' => 'af_'.$addonId.'_enabled',
                        'discovered_from' => 'assets_dir',
                    ];
                }
            }
        }

        $manifestTheme = $meta['theme_stylesheets'] ?? [];
        if (is_array($manifestTheme)) {
            foreach ($manifestTheme as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryFile = ltrim(str_replace('\\', '/', trim((string)($entry['file'] ?? ''))), '/');
                if ($entryFile === '' || substr(strtolower($entryFile), -4) !== '.css') {
                    continue;
                }
                $entryId = trim((string)($entry['id'] ?? ''));
                $base = $entryId !== '' ? preg_replace('~[^a-z0-9_\-\.]+~i', '_', $entryId) : af_theme_stylesheet_base_from_rel($entryFile);
                $logicalId = $addonId.'__'.$base;
                $name = trim((string)($entry['stylesheet_name'] ?? ''));
                if ($name === '') {
                    $name = af_theme_stylesheet_build_name($addonId, $logicalId, $entryFile);
                }

                $out[] = [
                    'addon_id' => $addonId,
                    'addon_meta' => $meta,
                    'source_file_abs' => $addonPath.'/'.$entryFile,
                    'source_file_rel' => $entryFile,
                    'logical_id' => $logicalId,
                    'stylesheet_name' => af_theme_stylesheet_build_name($addonId, $logicalId, $entryFile, preg_replace('~[^a-z0-9_\-\.]+~i', '_', $name)),
                    'is_admin_only' => (int)($entry['admin_only'] ?? 0) === 1,
                    'is_frontend_candidate' => !((int)($entry['admin_only'] ?? 0) === 1),
                    'suggested_attach' => af_normalize_theme_stylesheet_attach($entry['attach'] ?? [['file' => 'global']]),
                    'enabled_setting' => trim((string)($entry['enabled_setting'] ?? ('af_'.$addonId.'_enabled'))),
                    'discovered_from' => 'manifest_theme_stylesheets',
                    'exclude_autodiscovery' => !empty($entry['exclude_autodiscovery']),
                    'disable_theme_integration' => !empty($entry['disable_theme_integration']),
                    'display_title' => trim((string)($entry['label'] ?? '')),
                    'delivery_hint' => trim((string)($entry['delivery_mode'] ?? 'auto')),
                ];
            }
        }
    }

    $indexed = [];
    $indexedByFile = [];
    foreach ($out as $row) {
        $addonId = (string)$row['addon_id'];
        $logicalId = (string)$row['logical_id'];
        if ($addonId === '' || $logicalId === '') {
            continue;
        }
        $k = $addonId.'|'.$logicalId;
        if (!isset($indexed[$k])) {
            $indexed[$k] = $row;
            $fileKey = $addonId.'|'.strtolower((string)($row['source_file_rel'] ?? ''));
            if ($fileKey !== $addonId.'|') {
                $indexedByFile[$fileKey] = $k;
            }
            continue;
        }
        if (($row['discovered_from'] ?? '') === 'manifest_theme_stylesheets') {
            $indexed[$k] = array_merge($indexed[$k], $row);
        }
        $fileKey = $addonId.'|'.strtolower((string)($row['source_file_rel'] ?? ''));
        if ($fileKey !== $addonId.'|' && isset($indexedByFile[$fileKey]) && $indexedByFile[$fileKey] !== $k) {
            unset($indexed[$k]);
        }
    }

    return array_values($indexed);
}

function af_discover_theme_stylesheets(?array $addons = null): array
{
    $candidates = af_discover_addon_css_candidates($addons);
    $out = [];
    foreach ($candidates as $candidate) {
        if (!empty($candidate['exclude_autodiscovery'])) {
            continue;
        }
        if (empty($candidate['is_frontend_candidate'])) {
            continue;
        }
        $out[] = [
            'addon_id' => (string)$candidate['addon_id'],
            'addon_meta' => (array)$candidate['addon_meta'],
            'logical_id' => (string)$candidate['logical_id'],
            'file' => (string)$candidate['source_file_rel'],
            'stylesheet_name' => af_theme_stylesheet_build_name(
                (string)$candidate['addon_id'],
                (string)$candidate['logical_id'],
                (string)$candidate['source_file_rel'],
                (string)$candidate['stylesheet_name']
            ),
            'attach' => af_normalize_theme_stylesheet_attach($candidate['suggested_attach'] ?? [['file' => 'global']]),
            'enabled_setting' => trim((string)($candidate['enabled_setting'] ?? '')),
            'discovered_from' => (string)($candidate['discovered_from'] ?? ''),
            'is_admin_only' => !empty($candidate['is_admin_only']) ? 1 : 0,
            'delivery_hint' => trim((string)($candidate['delivery_hint'] ?? 'auto')),
            'disable_theme_integration' => !empty($candidate['disable_theme_integration']),
        ];
    }
    return $out;
}

function af_normalize_theme_stylesheet_attach($attach): array
{
    $out = [];

    if (!is_array($attach)) {
        return [['file' => 'global']];
    }

    foreach ($attach as $one) {
        if (!is_array($one)) {
            continue;
        }

        $file = trim((string)($one['file'] ?? ''));
        $action = trim((string)($one['action'] ?? ''));

        if ($file === '') {
            continue;
        }

        if ($file !== 'global') {
            $file = basename($file);
            if (strpos($file, '.php') === false) {
                $file .= '.php';
            }
        }

        $row = ['file' => $file];
        if ($action !== '') {
            $row['action'] = preg_replace('~[^a-z0-9_\-]+~i', '', $action);
        }

        $out[] = $row;
    }

    return $out ?: [['file' => 'global']];
}

function af_build_theme_stylesheet_attach_string(array $attach): string
{
    $tokens = [];
    foreach ($attach as $row) {
        $file = (string)($row['file'] ?? '');
        if ($file === '') {
            continue;
        }

        if ($file === 'global') {
            $tokens[] = 'global';
            continue;
        }

        $token = $file;
        $action = trim((string)($row['action'] ?? ''));
        if ($action !== '') {
            $token .= '?action='.$action;
        }
        $tokens[] = $token;
    }

    $tokens = array_values(array_unique($tokens));
    return $tokens ? implode('|', $tokens) : 'global';
}

function af_get_theme_stylesheet_source(array $meta, array $entry): ?array
{
    $base = rtrim((string)($meta['path'] ?? ''), '/\\');
    if ($base === '') {
        return null;
    }

    $seedPath = $base.'/'.ltrim((string)$entry['file'], '/');
    if (!is_file($seedPath)) {
        return null;
    }

    $source = (string)@file_get_contents($seedPath);
    if ($source === '') {
        return null;
    }

    return [
        'path' => $seedPath,
        'source' => $source,
        'checksum' => sha1($source),
    ];
}

function af_get_theme_tids(): array
{
    global $db;

    $tids = [];
    $q = $db->simple_select('themes', 'tid');
    while ($row = $db->fetch_array($q)) {
        $tid = (int)($row['tid'] ?? 0);
        if ($tid > 0) {
            $tids[] = $tid;
        }
    }
    $tids = array_values(array_unique($tids));
    sort($tids, SORT_NUMERIC);

    if (!$tids) {
        return [1];
    }
    return $tids;
}

function af_mark_theme_stylesheet_managed(int $themeTid, int $sid, array $entry, array $seed, bool $manualOverride, ?string $resolvedName = null): void
{
    global $db;

    af_theme_stylesheets_install_schema();

    $themeTid = max(0, $themeTid);
    $sid = max(0, $sid);
    $addonId = $db->escape_string((string)$entry['addon_id']);
    $logicalId = $db->escape_string((string)$entry['logical_id']);
    $now = TIME_NOW;
    $existingQ = $db->simple_select(
        AF_THEME_STYLESHEETS_TABLE,
        '*',
        "theme_tid='{$themeTid}' AND addon_id='{$addonId}' AND logical_id='{$logicalId}'",
        ['limit' => 1]
    );
    $existing = $db->fetch_array($existingQ) ?: [];

    $payload = [
        'theme_tid'            => $themeTid,
        'stylesheet_sid'       => $sid,
        'addon_id'             => (string)$entry['addon_id'],
        'logical_id'           => (string)$entry['logical_id'],
        'stylesheet_name'      => (string)($resolvedName !== null && $resolvedName !== '' ? $resolvedName : $entry['stylesheet_name']),
        'source_file'          => ltrim(str_replace('\\', '/', (string)($entry['file'] ?? '')), '/'),
        'seed_file'            => str_replace('\\', '/', str_replace(AF_BASE, '', (string)$seed['path'])),
        'seed_checksum'        => (string)$seed['checksum'],
        'last_synced_checksum' => (string)$seed['checksum'],
        'is_integrated'        => $sid > 0 ? 1 : (int)($existing['is_integrated'] ?? 0),
        'delivery_mode'        => (string)($existing['delivery_mode'] ?? ($entry['delivery_hint'] ?? 'auto')),
        'discovered_from'      => (string)($entry['discovered_from'] ?? ''),
        'is_admin_only'        => !empty($entry['is_admin_only']) ? 1 : 0,
        'last_synced_at'       => $now,
        'manual_override'      => $manualOverride ? 1 : 0,
        'updated_at'           => $now,
    ];
    $id = (int)($existing['id'] ?? 0);

    if ($id > 0) {
        $db->update_query(AF_THEME_STYLESHEETS_TABLE, $payload, "id='{$id}'");
        return;
    }

    $payload['created_at'] = $now;
    $db->insert_query(AF_THEME_STYLESHEETS_TABLE, $payload);
}

function af_register_theme_stylesheet(int $themeTid, array $meta, array $entry, array $seed, bool $force = false): array
{
    global $db;

    $themeTid = max(1, $themeTid);
    $entry['stylesheet_name'] = af_theme_stylesheet_build_name((string)$entry['addon_id'], (string)$entry['logical_id'], (string)($entry['file'] ?? ''), (string)$entry['stylesheet_name']);
    $nameEsc = $db->escape_string((string)$entry['stylesheet_name']);
    $addonEsc = $db->escape_string((string)$entry['addon_id']);
    $logicalEsc = $db->escape_string((string)$entry['logical_id']);
    $attach = af_build_theme_stylesheet_attach_string((array)$entry['attach']);

    $stateQ = $db->simple_select(
        AF_THEME_STYLESHEETS_TABLE,
        '*',
        "theme_tid='{$themeTid}' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'",
        ['limit' => 1]
    );
    $state = $db->fetch_array($stateQ) ?: [];

    $sid = (int)($state['stylesheet_sid'] ?? 0);
    $row = null;
    if ($sid > 0) {
        $rowQ = $db->simple_select('themestylesheets', 'sid,name,stylesheet,attachedto', "sid='{$sid}' AND tid='{$themeTid}'", ['limit' => 1]);
        $row = $db->fetch_array($rowQ) ?: null;
    }
    if (!$row) {
        $rowQ = $db->simple_select('themestylesheets', 'sid,name,stylesheet,attachedto', "tid='{$themeTid}' AND name='{$nameEsc}'", ['order_by' => 'sid', 'order_dir' => 'asc', 'limit' => 1]);
        $row = $db->fetch_array($rowQ) ?: null;
        $sid = (int)($row['sid'] ?? 0);
    }

    $seedChecksum = (string)$seed['checksum'];
    $manualOverride = ((int)($state['manual_override'] ?? 0) === 1);
    $mustWriteSeed = false;

    if (!$row) {
        $sid = (int)$db->insert_query('themestylesheets', [
            'name'         => (string)$entry['stylesheet_name'],
            'tid'          => $themeTid,
            'attachedto'   => $attach,
            'stylesheet'   => (string)$seed['source'],
            'cachefile'    => '',
            'lastmodified' => TIME_NOW,
        ]);
        $mustWriteSeed = true;
        $manualOverride = false;
        $row = ['sid' => $sid, 'name' => (string)$entry['stylesheet_name']];
    } else {
        $currentCss = (string)($row['stylesheet'] ?? '');
        $currentChecksum = sha1($currentCss);
        $lastSyncedChecksum = (string)($state['last_synced_checksum'] ?? '');

        if ($force) {
            $mustWriteSeed = true;
            $manualOverride = false;
        } elseif ($lastSyncedChecksum === '') {
            $mustWriteSeed = ($currentChecksum === $seedChecksum);
            $manualOverride = !$mustWriteSeed;
        } elseif ($currentChecksum !== $lastSyncedChecksum) {
            $mustWriteSeed = false;
            $manualOverride = true;
        } elseif ($seedChecksum !== $lastSyncedChecksum) {
            $mustWriteSeed = true;
            $manualOverride = false;
        }

        $resolvedName = trim((string)($row['name'] ?? $entry['stylesheet_name']));
        if ($resolvedName === '' || strlen($resolvedName) > 30) {
            $resolvedName = (string)$entry['stylesheet_name'];
        }
        $update = [
            'name'         => $resolvedName,
            'attachedto'   => $attach,
            'lastmodified' => TIME_NOW,
        ];
        if ($mustWriteSeed) {
            $update['stylesheet'] = (string)$seed['source'];
        }
        $db->update_query('themestylesheets', $update, "sid='{$sid}'");
        $row['name'] = $resolvedName;
    }

    if (!function_exists('cache_stylesheet') || !function_exists('update_theme_stylesheet_list')) {
        $adminInc = rtrim(af_admin_absdir(), '/').'/inc/functions_themes.php';
        if (is_file($adminInc)) {
            require_once $adminInc;
        }
    }
    if (function_exists('cache_stylesheet')) {
        $cached = cache_stylesheet($themeTid, (string)$row['name'], $mustWriteSeed ? (string)$seed['source'] : (string)($row['stylesheet'] ?? ''));
        $db->update_query('themestylesheets', ['cachefile' => $cached !== false ? (string)$row['name'] : ''], "sid='{$sid}'");
    }
    if (function_exists('update_theme_stylesheet_list')) {
        update_theme_stylesheet_list($themeTid);
    }

    af_mark_theme_stylesheet_managed($themeTid, $sid, $entry, $seed, $manualOverride, (string)($row['name'] ?? $entry['stylesheet_name']));

    return [
        'sid' => $sid,
        'updated_from_seed' => $mustWriteSeed,
        'manual_override' => $manualOverride,
        'name' => (string)($row['name'] ?? $entry['stylesheet_name']),
    ];
}

function af_reconcile_theme_stylesheet_registry_state(int $themeTid, array $entry, ?array $seed = null): void
{
    global $db;

    $themeTid = max(1, $themeTid);
    $addonEsc = $db->escape_string((string)$entry['addon_id']);
    $logicalEsc = $db->escape_string((string)$entry['logical_id']);
    $stateQ = $db->simple_select(AF_THEME_STYLESHEETS_TABLE, '*', "theme_tid='{$themeTid}' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'", ['limit' => 1]);
    $state = $db->fetch_array($stateQ) ?: [];
    if (!$state) {
        return;
    }

    $expectedName = af_theme_stylesheet_build_name((string)$entry['addon_id'], (string)$entry['logical_id'], (string)($entry['file'] ?? ''), (string)($state['stylesheet_name'] ?? $entry['stylesheet_name']));
    $legacyName = 'af_'.(string)$entry['addon_id'].'__'.af_theme_stylesheet_base_from_rel((string)($entry['file'] ?? '')).'.css';
    $sid = (int)($state['stylesheet_sid'] ?? 0);
    $row = null;
    if ($sid > 0) {
        $rowQ = $db->simple_select('themestylesheets', 'sid,name,stylesheet', "sid='{$sid}' AND tid='{$themeTid}'", ['limit' => 1]);
        $row = $db->fetch_array($rowQ) ?: null;
    }
    if (!$row) {
        $names = array_values(array_unique(array_filter([(string)($state['stylesheet_name'] ?? ''), $expectedName, $legacyName])));
        foreach ($names as $candidateName) {
            $nameEsc = $db->escape_string($candidateName);
            $rowQ = $db->simple_select('themestylesheets', 'sid,name,stylesheet', "tid='{$themeTid}' AND name='{$nameEsc}'", ['order_by' => 'sid', 'order_dir' => 'asc', 'limit' => 1]);
            $row = $db->fetch_array($rowQ) ?: null;
            if ($row) {
                break;
            }
        }
    }

    $payload = [
        'stylesheet_name' => $expectedName,
        'updated_at' => TIME_NOW,
    ];
    if ($seed) {
        $payload['seed_checksum'] = (string)($seed['checksum'] ?? '');
        $payload['seed_file'] = str_replace('\\', '/', str_replace(AF_BASE, '', (string)($seed['path'] ?? '')));
    }
    if ($row) {
        $payload['stylesheet_sid'] = (int)($row['sid'] ?? 0);
        $payload['is_integrated'] = 1;
        $payload['stylesheet_name'] = (string)($row['name'] ?? $expectedName);
        if ($seed) {
            $currentChecksum = sha1((string)($row['stylesheet'] ?? ''));
            $lastSynced = (string)($state['last_synced_checksum'] ?? '');
            $payload['manual_override'] = ($lastSynced !== '' && $currentChecksum !== $lastSynced) ? 1 : 0;
        }
    } else {
        $payload['stylesheet_sid'] = 0;
        $payload['is_integrated'] = 0;
    }
    $db->update_query(AF_THEME_STYLESHEETS_TABLE, $payload, "id='".(int)$state['id']."'");
}

function af_ensure_theme_stylesheet_registry_row(int $themeTid, array $entry): void
{
    global $db;

    af_theme_stylesheets_install_schema();
    $themeTid = max(1, $themeTid);
    $addonEsc = $db->escape_string((string)$entry['addon_id']);
    $logicalEsc = $db->escape_string((string)$entry['logical_id']);
    $existsQ = $db->simple_select(
        AF_THEME_STYLESHEETS_TABLE,
        'id',
        "theme_tid='{$themeTid}' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'",
        ['limit' => 1]
    );
    $exists = (int)$db->fetch_field($existsQ, 'id');
    if ($exists > 0) {
        return;
    }

    $now = TIME_NOW;
    $db->insert_query(AF_THEME_STYLESHEETS_TABLE, [
        'theme_tid' => $themeTid,
        'stylesheet_sid' => 0,
        'addon_id' => (string)$entry['addon_id'],
        'logical_id' => (string)$entry['logical_id'],
        'stylesheet_name' => (string)$entry['stylesheet_name'],
        'source_file' => ltrim(str_replace('\\', '/', (string)($entry['file'] ?? '')), '/'),
        'seed_file' => '',
        'seed_checksum' => '',
        'last_synced_checksum' => '',
        'is_integrated' => 0,
        'delivery_mode' => 'auto',
        'discovered_from' => (string)($entry['discovered_from'] ?? ''),
        'is_admin_only' => !empty($entry['is_admin_only']) ? 1 : 0,
        'last_synced_at' => 0,
        'manual_override' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function af_sync_theme_stylesheets(bool $force = false, ?string $onlyAddonId = null): array
{
    global $mybb, $db;

    af_theme_stylesheets_install_schema();

    $addons = af_discover_addons();
    $entries = af_discover_theme_stylesheets($addons);
    $themeTids = af_get_theme_tids();
    $result = ['created_or_updated' => 0, 'manual_override' => 0, 'skipped' => 0];

    foreach ($entries as $entry) {
        $addonId = (string)$entry['addon_id'];
        if ($onlyAddonId !== null && $onlyAddonId !== '' && $onlyAddonId !== $addonId) {
            continue;
        }

        $enabledSetting = (string)$entry['enabled_setting'];
        if ($enabledSetting !== '') {
            if (!isset($mybb->settings[$enabledSetting]) || (string)$mybb->settings[$enabledSetting] !== '1') {
                continue;
            }
        }

        $seed = af_get_theme_stylesheet_source((array)$entry['addon_meta'], $entry);

        foreach ($themeTids as $themeTid) {
            af_ensure_theme_stylesheet_registry_row($themeTid, $entry);
            af_reconcile_theme_stylesheet_registry_state($themeTid, $entry, $seed ?: null);
            if (!$seed) {
                $result['skipped']++;
                continue;
            }
            if (empty($force)) {
                $addonEsc = $db->escape_string((string)$entry['addon_id']);
                $logicalEsc = $db->escape_string((string)$entry['logical_id']);
                $stateQ = $db->simple_select(
                    AF_THEME_STYLESHEETS_TABLE,
                    'is_integrated,stylesheet_sid',
                    "theme_tid='".(int)$themeTid."' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'",
                    ['limit' => 1]
                );
                $stateRow = $db->fetch_array($stateQ) ?: [];
                if ((int)($stateRow['is_integrated'] ?? 0) !== 1 && (int)($stateRow['stylesheet_sid'] ?? 0) <= 0) {
                    continue;
                }
            }
            $state = af_register_theme_stylesheet($themeTid, (array)$entry['addon_meta'], $entry, $seed, $force);
            if (!empty($state['updated_from_seed'])) {
                $result['created_or_updated']++;
            }
            if (!empty($state['manual_override'])) {
                $result['manual_override']++;
            }
        }
    }

    return $result;
}

function af_collect_theme_stylesheet_diagnostics(?string $onlyAddonId = null): array
{
    global $db, $mybb;

    af_theme_stylesheets_install_schema();

    $addons = af_discover_addons();
    $entries = af_discover_theme_stylesheets($addons);
    $themeTids = af_get_theme_tids();
    $rows = [];

    $themeTitles = [];
    if (!empty($themeTids)) {
        $themeQuery = $db->simple_select('themes', 'tid,name');
        while ($themeRow = $db->fetch_array($themeQuery)) {
            $themeTitles[(int)($themeRow['tid'] ?? 0)] = (string)($themeRow['name'] ?? '');
        }
    }

    foreach ($entries as $entry) {
        $addonId = (string)$entry['addon_id'];
        if ($onlyAddonId !== null && $onlyAddonId !== '' && $onlyAddonId !== $addonId) {
            continue;
        }

        $enabledSetting = (string)($entry['enabled_setting'] ?? '');
        if ($enabledSetting !== '' && (!isset($mybb->settings[$enabledSetting]) || (string)$mybb->settings[$enabledSetting] !== '1')) {
            continue;
        }

        $seed = af_get_theme_stylesheet_source((array)$entry['addon_meta'], $entry);
        if (!$seed) {
            af_theme_stylesheets_log('fallback to file mode', $addonId, (string)$entry['logical_id']);
        }
        $seedChecksum = (string)($seed['checksum'] ?? '');

        foreach ($themeTids as $themeTid) {
            $addonEsc = $db->escape_string($addonId);
            $logicalEsc = $db->escape_string((string)$entry['logical_id']);
            $stateQ = $db->simple_select(
                AF_THEME_STYLESHEETS_TABLE,
                '*',
                "theme_tid='".(int)$themeTid."' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'",
                ['limit' => 1]
            );
            $state = $db->fetch_array($stateQ) ?: [];
            if (!$state) {
                af_ensure_theme_stylesheet_registry_row((int)$themeTid, $entry);
                $stateQ = $db->simple_select(
                    AF_THEME_STYLESHEETS_TABLE,
                    '*',
                    "theme_tid='".(int)$themeTid."' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'",
                    ['limit' => 1]
                );
                $state = $db->fetch_array($stateQ) ?: [];
            }
            $nameEsc = $db->escape_string((string)($state['stylesheet_name'] ?? $entry['stylesheet_name']));

            $sid = (int)($state['stylesheet_sid'] ?? 0);
            $row = null;
            if ($sid > 0) {
                $rowQ = $db->simple_select('themestylesheets', 'sid,stylesheet,attachedto,name', "sid='{$sid}' AND tid='".(int)$themeTid."'", ['limit' => 1]);
                $row = $db->fetch_array($rowQ) ?: null;
            }
            if (!$row) {
                $rowQ = $db->simple_select('themestylesheets', 'sid,stylesheet,attachedto,name', "tid='".(int)$themeTid."' AND name='{$nameEsc}'", ['order_by' => 'sid', 'order_dir' => 'asc', 'limit' => 1]);
                $row = $db->fetch_array($rowQ) ?: null;
            }

            $expectedAttach = af_build_theme_stylesheet_attach_string((array)($entry['attach'] ?? []));
            $foundInTheme = (bool)$row;
            $currentChecksum = $foundInTheme ? sha1((string)$row['stylesheet']) : '';
            $lastSyncedChecksum = (string)($state['last_synced_checksum'] ?? '');
            $manualOverride = ((int)($state['manual_override'] ?? 0) === 1);
            $duplicateQ = $db->simple_select('themestylesheets', 'COUNT(*) AS c', "tid='".(int)$themeTid."' AND name='{$nameEsc}'");
            $duplicateCount = (int)$db->fetch_field($duplicateQ, 'c');
            $duplicateRisk = $duplicateCount > 1;
            if ($duplicateRisk) {
                af_theme_stylesheets_log('duplicate prevented', $addonId, (string)$entry['logical_id']);
            }

            $status = 'ok';
            if ((int)($state['is_integrated'] ?? 0) !== 1) {
                $status = 'not_integrated';
            } elseif (!$foundInTheme) {
                $status = 'missing';
            } elseif ($manualOverride || ($lastSyncedChecksum !== '' && $currentChecksum !== $lastSyncedChecksum)) {
                $status = 'manual_override';
            } elseif ($seedChecksum !== '' && $currentChecksum !== $seedChecksum) {
                $status = 'outdated';
            } elseif ($duplicateRisk) {
                $status = 'duplicate_risk';
            }

            $modeRequested = strtolower((string)($entry['mode'] ?? 'auto'));
            $modeStored = strtolower((string)($state['delivery_mode'] ?? 'auto'));
            if (!in_array($modeStored, ['file', 'theme', 'auto'], true)) {
                $modeStored = 'auto';
            }
            if (!in_array($modeRequested, ['file', 'theme', 'auto'], true)) {
                $modeRequested = 'auto';
            }
            $mode = $modeStored !== 'auto' ? $modeStored : $modeRequested;
            if ($mode === 'auto') {
                $mode = ((int)($state['is_integrated'] ?? 0) === 1 && $foundInTheme) ? 'theme' : 'file';
            }

            $rows[] = [
                'theme_tid' => (int)$themeTid,
                'theme_title' => (string)($themeTitles[(int)$themeTid] ?? ('Theme #'.(int)$themeTid)),
                'addon_id' => $addonId,
                'logical_id' => (string)$entry['logical_id'],
                'stylesheet_sid' => (int)($row['sid'] ?? 0),
                'stylesheet_name' => (string)($state['stylesheet_name'] ?? $entry['stylesheet_name']),
                'db_stylesheet_name' => (string)($row['name'] ?? ($state['stylesheet_name'] ?? $entry['stylesheet_name'])),
                'seed_file' => (string)($seed['path'] ?? ''),
                'mode' => $mode,
                'status' => $status,
                'is_integrated' => (int)($state['is_integrated'] ?? 0) === 1,
                'attached_to' => (string)($row['attachedto'] ?? $expectedAttach),
                'expected_attach' => $expectedAttach,
                'last_synced_at' => (int)($state['last_synced_at'] ?? 0),
                'manual_override' => $manualOverride,
                'found_in_theme' => $foundInTheme,
                'seed_checksum_match' => ($seedChecksum !== '' && (string)($state['seed_checksum'] ?? '') === $seedChecksum),
                'duplicate_risk' => $duplicateRisk,
                'attached_match' => ((string)($row['attachedto'] ?? '') === $expectedAttach),
                'seed' => $seed,
                'entry' => $entry,
                'state' => $state,
                'current_checksum' => $currentChecksum,
                'last_synced_checksum' => $lastSyncedChecksum,
            ];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return [$a['addon_id'], $a['logical_id'], $a['theme_tid']] <=> [$b['addon_id'], $b['logical_id'], $b['theme_tid']];
    });

    return $rows;
}

function af_theme_stylesheet_set_delivery_mode(int $themeTid, string $addonId, string $logicalId, string $mode): bool
{
    global $db;
    if (!in_array($mode, ['file', 'theme', 'auto'], true)) {
        return false;
    }
    $addonEsc = $db->escape_string($addonId);
    $logicalEsc = $db->escape_string($logicalId);
    $db->update_query(
        AF_THEME_STYLESHEETS_TABLE,
        ['delivery_mode' => $mode, 'updated_at' => TIME_NOW],
        "theme_tid='".(int)$themeTid."' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'"
    );
    return true;
}

function af_theme_stylesheet_integrate(int $themeTid, string $addonId, string $logicalId, bool $force = false): bool
{
    global $db;

    $entries = af_discover_theme_stylesheets();
    $match = null;
    foreach ($entries as $entry) {
        if ((string)$entry['addon_id'] === $addonId && (string)$entry['logical_id'] === $logicalId) {
            $match = $entry;
            break;
        }
    }
    if (!$match || !empty($match['disable_theme_integration'])) {
        return false;
    }
    $seed = af_get_theme_stylesheet_source((array)$match['addon_meta'], $match);
    if (!$seed) {
        return false;
    }
    af_register_theme_stylesheet($themeTid, (array)$match['addon_meta'], $match, $seed, $force);
    $addonEsc = $db->escape_string($addonId);
    $logicalEsc = $db->escape_string($logicalId);
    $db->update_query(
        AF_THEME_STYLESHEETS_TABLE,
        ['is_integrated' => 1, 'updated_at' => TIME_NOW],
        "theme_tid='".(int)$themeTid."' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'"
    );
    return true;
}

function af_theme_stylesheets_execute_action(string $action, ?string $addonId = null, bool $confirmed = false, ?int $themeTid = null, ?string $logicalId = null): array
{
    $stats = [
        'created_or_updated' => 0,
        'manual_override' => 0,
        'skipped' => 0,
        'restored' => 0,
        'missing' => 0,
        'changed' => 0,
    ];

    if ($action === 'integrate' && $themeTid !== null && $logicalId !== null && $addonId !== null) {
        $stats['changed'] = af_theme_stylesheet_integrate($themeTid, $addonId, $logicalId, false) ? 1 : 0;
        return $stats;
    }
    if ($action === 'set_file_mode' && $themeTid !== null && $logicalId !== null && $addonId !== null) {
        $stats['changed'] = af_theme_stylesheet_set_delivery_mode($themeTid, $addonId, $logicalId, 'file') ? 1 : 0;
        return $stats;
    }
    if ($action === 'set_theme_mode' && $themeTid !== null && $logicalId !== null && $addonId !== null) {
        $stats['changed'] = af_theme_stylesheet_set_delivery_mode($themeTid, $addonId, $logicalId, 'theme') ? 1 : 0;
        return $stats;
    }
    if ($action === 'set_auto_mode' && $themeTid !== null && $logicalId !== null && $addonId !== null) {
        $stats['changed'] = af_theme_stylesheet_set_delivery_mode($themeTid, $addonId, $logicalId, 'auto') ? 1 : 0;
        return $stats;
    }

    if ($action === 'sync_all') {
        $sync = af_sync_theme_stylesheets(false, null);
        af_theme_stylesheets_log('synced', $addonId ?? '*', '*');
        return array_merge($stats, $sync);
    }
    if ($action === 'sync_addon') {
        $sync = af_sync_theme_stylesheets(false, $addonId);
        af_theme_stylesheets_log('synced', $addonId ?? '*', '*');
        return array_merge($stats, $sync);
    }
    if ($action === 'force_resync') {
        if (!$confirmed) {
            return $stats;
        }
        $sync = af_sync_theme_stylesheets(true, $addonId);
        af_theme_stylesheets_log('synced', $addonId ?? '*', '*');
        return array_merge($stats, $sync);
    }

    $rows = af_collect_theme_stylesheet_diagnostics($addonId);
    foreach ($rows as $row) {
        if (empty($row['seed']) || !is_array($row['seed'])) {
            $stats['skipped']++;
            continue;
        }
        if (!empty($row['manual_override']) && in_array($action, ['rebuild_missing', 'restore_safe'], true)) {
            $stats['manual_override']++;
            af_theme_stylesheets_log('skipped due to manual override', (string)$row['addon_id'], (string)$row['logical_id']);
            continue;
        }

        if ($action === 'rebuild_missing') {
            if ((string)$row['status'] !== 'missing') {
                continue;
            }
            af_register_theme_stylesheet((int)$row['theme_tid'], (array)$row['entry']['addon_meta'], (array)$row['entry'], (array)$row['seed'], false);
            $stats['restored']++;
            $stats['missing']++;
            af_theme_stylesheets_log('restored after missing', (string)$row['addon_id'], (string)$row['logical_id']);
            continue;
        }

        if ($action === 'restore_safe') {
            $isMissing = ((string)$row['status'] === 'missing');
            $notEdited = ((string)$row['current_checksum'] !== '' && (string)$row['last_synced_checksum'] !== '' && (string)$row['current_checksum'] === (string)$row['last_synced_checksum']);
            if (!$isMissing && !$notEdited) {
                $stats['skipped']++;
                continue;
            }
            af_register_theme_stylesheet((int)$row['theme_tid'], (array)$row['entry']['addon_meta'], (array)$row['entry'], (array)$row['seed'], true);
            $stats['restored']++;
            af_theme_stylesheets_log($isMissing ? 'restored after missing' : 'synced', (string)$row['addon_id'], (string)$row['logical_id']);
        }
    }

    return $stats;
}

function af_theme_stylesheets_action_message(string $action, array $result, $lang): string
{
    $base = [
        'created_or_updated' => (int)($result['created_or_updated'] ?? 0),
        'manual_override' => (int)($result['manual_override'] ?? 0),
        'restored' => (int)($result['restored'] ?? 0),
        'skipped' => (int)($result['skipped'] ?? 0),
    ];
    $suffix = " (synced: {$base['created_or_updated']}, restored: {$base['restored']}, manual override: {$base['manual_override']}, skipped: {$base['skipped']})";

    $map = [
        'integrate' => 'af_theme_stylesheets_msg_integrate',
        'set_file_mode' => 'af_theme_stylesheets_msg_set_file_mode',
        'set_theme_mode' => 'af_theme_stylesheets_msg_set_theme_mode',
        'set_auto_mode' => 'af_theme_stylesheets_msg_set_auto_mode',
        'sync_all' => 'af_theme_stylesheets_msg_sync_all',
        'sync_addon' => 'af_theme_stylesheets_msg_sync_addon',
        'rebuild_missing' => 'af_theme_stylesheets_msg_rebuild_missing',
        'force_resync' => 'af_theme_stylesheets_msg_force_resync',
        'restore_safe' => 'af_theme_stylesheets_msg_restore_safe',
        'hash_status' => 'af_theme_stylesheets_msg_hash_status',
        'status' => 'af_theme_stylesheets_msg_hash_status',
    ];
    $key = $map[$action] ?? 'af_theme_stylesheets_msg_hash_status';
    $prefix = isset($lang->{$key}) ? (string)$lang->{$key} : 'Done';
    return $prefix.$suffix;
}

function af_theme_stylesheets_log(string $event, string $addonId, string $logicalId): void
{
    $payload = '[AF theme stylesheets] '.$event.'; addon='.$addonId.'; logical='.$logicalId;
    if (function_exists('log_admin_action')) {
        @log_admin_action($payload);
    }
    @error_log($payload);
}

function af_theme_stylesheets_signature_for_enabled_addons(): string
{
    global $mybb;

    $addons = af_discover_addons();
    $entries = af_discover_theme_stylesheets($addons);
    $parts = [];

    foreach ($entries as $entry) {
        $enabledSetting = (string)$entry['enabled_setting'];
        if ($enabledSetting !== '') {
            if (!isset($mybb->settings[$enabledSetting]) || (string)$mybb->settings[$enabledSetting] !== '1') {
                continue;
            }
        }

        $seed = af_get_theme_stylesheet_source((array)$entry['addon_meta'], $entry);
        if (!$seed) {
            continue;
        }

        $path = (string)$seed['path'];
        $parts[] = implode('|', [
            (string)$entry['addon_id'],
            (string)$entry['logical_id'],
            (string)$entry['stylesheet_name'],
            (string)$seed['checksum'],
            (string)(@filemtime($path) ?: 0),
        ]);
    }

    if (!$parts) {
        return '';
    }

    sort($parts, SORT_STRING);
    return sha1(implode(';', $parts));
}

function af_maybe_sync_theme_stylesheets_runtime(): void
{
    if (!empty($GLOBALS['af_theme_stylesheets_synced_runtime'])) {
        return;
    }
    $GLOBALS['af_theme_stylesheets_synced_runtime'] = true;

    if (defined('IN_ADMINCP')) {
        return;
    }

    $sigNow = af_theme_stylesheets_signature_for_enabled_addons();
    if ($sigNow === '') {
        return;
    }

    $sigFile = AF_CACHE.'theme_stylesheets_sig.txt';
    $sigOld = is_file($sigFile) ? trim((string)@file_get_contents($sigFile)) : '';

    if ($sigOld === $sigNow) {
        return;
    }

    af_sync_theme_stylesheets(false);
    @file_put_contents($sigFile, $sigNow, LOCK_EX);
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

    $tags = af_assets_build_tags($assets, $page);
    if ($tags === '') {
        return $page . "\n<!--af_assets_done-->";
    }

    af_assets_inject_headerinclude($assets);

    $page = af_inject_into_head($page, $tags);

    // маркер
    $page .= "\n<!--af_assets_done-->";

    return $page;
}

/**
 * Контекст Asset Manager.
 */
function af_assets_init_context(): array
{
    global $mybb;

    static $ctx = null;
    if (is_array($ctx)) {
        return $ctx;
    }

    $script = (string)(defined('THIS_SCRIPT') ? THIS_SCRIPT : basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $self   = strtolower((string)($_SERVER['PHP_SELF'] ?? ''));
    $uri    = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));

    $isAdmin = (defined('IN_ADMINCP') && IN_ADMINCP)
        || (defined('ADMIN_CP') && ADMIN_CP)
        || (strpos($self, '/admin/') !== false)
        || (strpos($uri, '/admin/') !== false)
        || ($script === 'index.php' && (strpos($self, '/admin/index.php') !== false || strpos($uri, '/admin/index.php') !== false));

    $isAjax = false;
    if (defined('IN_MYBB') && isset($mybb) && is_object($mybb)) {
        $isAjax = ((int)$mybb->get_input('ajax', MyBB::INPUT_INT) === 1);
    } elseif (isset($_REQUEST['ajax'])) {
        $isAjax = ((int)$_REQUEST['ajax'] === 1);
    }

    $ctx = [
        'is_admincp' => $isAdmin,
        'script'     => $script,
        'is_ajax'    => $isAjax,
        'bburl'      => rtrim((string)($mybb->settings['bburl'] ?? ''), '/'),
    ];

    return $ctx;
}

function af_asset_clean_path(string $urlOrPath): string
{
    $urlOrPath = trim($urlOrPath);
    if ($urlOrPath === '') {
        return '';
    }

    $urlOrPath = str_replace('\\', '/', $urlOrPath);
    $path = (string)parse_url($urlOrPath, PHP_URL_PATH);
    if ($path === '') {
        $path = $urlOrPath;
    }

    $ctx = af_assets_init_context();
    $bburl = (string)($ctx['bburl'] ?? '');
    if ($bburl !== '' && strpos($urlOrPath, $bburl) === 0) {
        $path = substr($urlOrPath, strlen($bburl));
    }

    if ($path === '') {
        return '';
    }

    $path = '/'.ltrim($path, '/');
    $path = preg_replace('~/+~', '/', $path);
    return $path;
}

function af_asset_build_url(string $cleanPath, array $opts = []): string
{
    $cleanPath = af_asset_clean_path($cleanPath);
    if ($cleanPath === '') {
        return '';
    }

    $ctx = af_assets_init_context();
    $bburl = (string)($ctx['bburl'] ?? '');

    $absPath = rtrim((string)MYBB_ROOT, '/\\') . $cleanPath;
    $buster = @filemtime($absPath);
    if (!$buster) {
        $buster = trim((string)($opts['version'] ?? ''));
    }
    if ($buster === '' || $buster === null) {
        $buster = TIME_NOW;
    }

    $url = ($bburl !== '' ? $bburl : '') . $cleanPath;
    return $url . '?v=' . rawurlencode((string)$buster);
}

function af_normalize_script_name(string $script): string
{
    $script = strtolower(trim($script));
    if ($script === '') {
        return '';
    }

    return basename(str_replace('\\', '/', $script));
}

function af_current_script_name_from_this_script(): string
{
    if (!defined('THIS_SCRIPT')) {
        return '';
    }

    return af_normalize_script_name((string)THIS_SCRIPT);
}

function af_parse_assets_blacklist_conditions(string $raw): array
{
    $out = [];
    $lines = preg_split('~\R~', $raw);
    if (!is_array($lines)) {
        return $out;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $script = '';
        $action = null;
        $qPos = strpos($line, '?');
        if ($qPos === false) {
            $script = strtolower($line);
        } else {
            $script = strtolower(trim(substr($line, 0, $qPos)));
            $query = trim(substr($line, $qPos + 1));
            if ($query !== '') {
                foreach (explode('&', $query) as $part) {
                    $part = trim((string)$part);
                    if ($part === '') {
                        continue;
                    }

                    $eqPos = strpos($part, '=');
                    if ($eqPos === false) {
                        continue;
                    }

                    $k = strtolower(trim(substr($part, 0, $eqPos)));
                    $v = strtolower(trim(substr($part, $eqPos + 1)));
                    if ($k === 'action') {
                        $action = $v;
                        break;
                    }
                }
            }
        }

        if ($script === '') {
            continue;
        }

        $out[] = ['script' => basename(str_replace('\\', '/', $script)), 'action' => $action];
    }

    return $out;
}

function af_setting_blacklist_disabled_for_current_page(string $settingName, string $defaultRaw = '', string $script = ''): bool
{
    global $mybb;

    $script = af_normalize_script_name($script);
    if ($script === '') {
        $script = af_current_script_name_from_this_script();
    }
    if ($script === '') {
        $script = af_normalize_script_name((string)basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    }
    if ($script === '') {
        return false;
    }

    $raw = trim((string)($mybb->settings[$settingName] ?? ''));
    if ($raw === '') {
        $raw = trim($defaultRaw);
    }
    if ($raw === '') {
        return false;
    }

    $action = strtolower((string)($mybb->input['action'] ?? ''));
    $conditions = af_parse_assets_blacklist_conditions($raw);
    foreach ($conditions as $cond) {
        $condScript = strtolower((string)($cond['script'] ?? ''));
        if ($condScript === '' || $condScript !== $script) {
            continue;
        }

        $condAction = strtolower((string)($cond['action'] ?? ''));
        if ($condAction === '' || $condAction === $action) {
            return true;
        }
    }

    return false;
}

function af_is_blacklisted(string $addonId, string $script = ''): bool
{
    $addonId = strtolower(trim($addonId));
    if ($addonId === '') {
        return false;
    }

    $settings = [
        'af_' . $addonId . '_assets_blacklist',
        'af_' . $addonId . '_disable_on',
    ];

    // Backward compatibility: legacy setting keys in old addons.
    $legacySettingsByAddon = [
        'advancedprofilefields' => ['af_apf_assets_blacklist'],
        'advancedthreadfields' => ['af_atf_assets_blacklist'],
        'charactersheets' => ['af_cs_assets_blacklist'],
    ];
    foreach (($legacySettingsByAddon[$addonId] ?? []) as $legacySettingName) {
        $settings[] = (string)$legacySettingName;
    }

    $defaultBySetting = [
        'af_advancededitor_disable_on' => "index.php\nforumdisplay.php\npostsactivity.php\nusercp.php\nuserlist.php\nsearch.php\ngallery.php",
    ];

    foreach ($settings as $settingName) {
        $defaultRaw = $defaultBySetting[$settingName] ?? '';
        if (af_setting_blacklist_disabled_for_current_page($settingName, $defaultRaw, $script)) {
            return true;
        }
    }

    return false;
}

function af_asset_is_admin_only(string $cleanPath): bool
{
    $cleanPath = af_asset_clean_path($cleanPath);
    if ($cleanPath === '') {
        return false;
    }

    if (preg_match('~/(?:admin)(?:/|$)~i', $cleanPath)) {
        return true;
    }

    $file = strtolower((string)pathinfo($cleanPath, PATHINFO_FILENAME));
    if (preg_match('~(?:^|[._-])admin(?:[._-]|$)~i', $file)) {
        return true;
    }

    $patterns = [
        '~/(?:acp|admincp)/~i',
        '~/(?:assets|js|css)/.*(?:^|[._-])admin(?:[._-]|$)~i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $cleanPath)) {
            return true;
        }
    }

    return false;
}

function af_add_js_once(string $urlOrPath, array $meta = []): void
{
    af_add_asset_once('js', $urlOrPath, $meta);
}

function af_add_css_once(string $urlOrPath, array $meta = []): void
{
    af_add_asset_once('css', $urlOrPath, $meta);
}

function af_add_asset_once(string $type, string $urlOrPath, array $meta = []): void
{
    $rawUrl = trim((string)($meta['raw_url'] ?? ''));
    $cleanPath = af_asset_clean_path($urlOrPath);
    if ($rawUrl === '' && $cleanPath === '' || ($type !== 'js' && $type !== 'css')) {
        return;
    }

    $ctx = af_assets_init_context();
    $scope = strtolower((string)($meta['scope'] ?? 'both'));
    if ($scope === 'admin' && empty($ctx['is_admincp'])) {
        return;
    }
    if ($scope === 'front' && !empty($ctx['is_admincp'])) {
        return;
    }

    if (empty($ctx['is_admincp']) && af_asset_is_admin_only($cleanPath)) {
        return;
    }

    if (!isset($GLOBALS['af_assets_loaded']) || !is_array($GLOBALS['af_assets_loaded'])) {
        $GLOBALS['af_assets_loaded'] = ['js' => [], 'css' => []];
    }
    $assetKey = $rawUrl !== '' ? 'raw:'.$rawUrl : $cleanPath;
    if (!empty($GLOBALS['af_assets_loaded'][$type][$assetKey])) {
        return;
    }
    $GLOBALS['af_assets_loaded'][$type][$assetKey] = true;

    if (!isset($GLOBALS['af_assets_queue']) || !is_array($GLOBALS['af_assets_queue'])) {
        $GLOBALS['af_assets_queue'] = ['js' => [], 'css' => []];
    }

    $url = $rawUrl !== '' ? $rawUrl : af_asset_build_url($cleanPath, $meta);
    if ($url === '') {
        return;
    }
    $GLOBALS['af_assets_queue'][$type][$assetKey] = $url;
}

function af_assets_build_tags(array $assets = [], string $page = ''): string
{
    $queueCss = $GLOBALS['af_assets_queue']['css'] ?? [];
    $queueJs  = $GLOBALS['af_assets_queue']['js'] ?? [];

    $tags = "\n<!--af_assets_begin-->\n";

    foreach ($queueCss as $cleanPath => $href) {
        if (!empty($page) && stripos($page, (string)$cleanPath) !== false) continue;
        $tags .= '<link rel="stylesheet" type="text/css" href="'.htmlspecialchars_uni((string)$href).'" />'."\n";
    }
    foreach ($queueJs as $cleanPath => $src) {
        if (!empty($page) && stripos($page, (string)$cleanPath) !== false) continue;
        $tags .= '<script type="text/javascript" src="'.htmlspecialchars_uni((string)$src).'" defer></script>'."\n";
    }

    $tags .= "<!--af_assets_end-->\n";

    if ($tags === "\n<!--af_assets_begin-->\n<!--af_assets_end-->\n") {
        return '';
    }

    return $tags;
}

function af_assets_inject_headerinclude(array $assets): void
{
    global $headerinclude;

    $tags = af_assets_build_tags($assets);
    if ($tags === '') {
        return;
    }

    if (!is_string($headerinclude)) {
        $headerinclude = '';
    }

    if (strpos($headerinclude, '<!--af_assets_begin-->') !== false) {
        return;
    }

    $headerinclude .= "\n" . $tags;
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

function af_current_theme_tid(): int
{
    $theme = $GLOBALS['theme'] ?? null;
    if (is_array($theme)) {
        $tid = (int)($theme['tid'] ?? 0);
        if ($tid > 0) {
            return $tid;
        }
    }
    global $mybb, $db;
    $tid = (int)($mybb->settings['theme'] ?? 1);
    if ($tid > 1) {
        return $tid;
    }
    $q = $db->simple_select('themes', 'tid', "tid > 1", ['order_by' => 'tid', 'order_dir' => 'asc', 'limit' => 1]);
    $fallback = (int)$db->fetch_field($q, 'tid');
    return $fallback > 1 ? $fallback : 1;
}

function af_theme_stylesheet_frontend_href_for_candidate(string $addonId, string $fileRel): string
{
    global $db;

    $base = af_theme_stylesheet_base_from_rel($fileRel);
    $logicalId = $addonId.'__'.$base;
    $themeTid = af_current_theme_tid();
    $addonEsc = $db->escape_string($addonId);
    $logicalEsc = $db->escape_string($logicalId);

    $q = $db->simple_select(
        AF_THEME_STYLESHEETS_TABLE,
        '*',
        "theme_tid='".(int)$themeTid."' AND addon_id='{$addonEsc}' AND logical_id='{$logicalEsc}'",
        ['limit' => 1]
    );
    $state = $db->fetch_array($q) ?: [];
    if (!$state) {
        return '';
    }

    $deliveryMode = strtolower((string)($state['delivery_mode'] ?? 'auto'));
    if (!in_array($deliveryMode, ['file', 'theme', 'auto'], true)) {
        $deliveryMode = 'auto';
    }
    if ($deliveryMode === 'file') {
        return '';
    }

    $sid = (int)($state['stylesheet_sid'] ?? 0);
    $isIntegrated = (int)($state['is_integrated'] ?? 0) === 1;
    if (!$isIntegrated || $sid <= 0) {
        return '';
    }

    $sidQ = $db->simple_select('themestylesheets', 'sid,name', "sid='{$sid}' AND tid='".(int)$themeTid."'", ['limit' => 1]);
    $sidRow = $db->fetch_array($sidQ) ?: [];
    if (!$sidRow) {
        return '';
    }

    $name = trim((string)($sidRow['name'] ?? ''));
    if ($name === '') {
        return '';
    }

    global $mybb;
    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    return $bburl.'/css.php?stylesheet='.rawurlencode($name).'&tid='.(int)$themeTid.'&v='.(int)($state['updated_at'] ?? TIME_NOW);
}

/**
 * Собирает все css/js из assets/ у включённых аддонов.
 * URL строим от bburl.
 */
function af_collect_enabled_addon_assets(): array
{
    if (!isset($GLOBALS['af_assets_queue']) || !is_array($GLOBALS['af_assets_queue'])) {
        $GLOBALS['af_assets_queue'] = ['css' => [], 'js' => []];
    }

    $css = [];
    $js  = [];

    $addons = af_discover_addons();
    foreach ($addons as $meta) {
        $id = $meta['id'] ?? '';
        if ($id === '' || !af_is_addon_enabled($id)) continue;
        if (af_is_blacklisted($id)) continue;

        $manifestAssets = $meta['assets'] ?? null;
        if (is_array($manifestAssets)) {
            $version = (string)($meta['version'] ?? '');
            foreach (['front' => 'front', 'admin' => 'admin', 'both' => 'both'] as $section => $scope) {
                if (empty($manifestAssets[$section]) || !is_array($manifestAssets[$section])) {
                    continue;
                }
                foreach (['css', 'js'] as $type) {
                    foreach ((array)($manifestAssets[$section][$type] ?? []) as $relAsset) {
                        $relAsset = trim((string)$relAsset);
                        if ($relAsset === '') continue;
                        $cleanPath = '/inc/plugins/'.AF_PLUGIN_ID.'/addons/'.$id.'/'.ltrim(str_replace('\\', '/', $relAsset), '/');
                        if ($type === 'css') {
                            $themeHref = af_theme_stylesheet_frontend_href_for_candidate($id, ltrim(str_replace('\\', '/', $relAsset), '/'));
                            if ($themeHref !== '') {
                                af_add_css_once($cleanPath, ['scope' => $scope, 'raw_url' => $themeHref]);
                            } else {
                                af_add_css_once($cleanPath, ['scope' => $scope, 'version' => $version]);
                            }
                        }
                        if ($type === 'js') af_add_js_once($cleanPath, ['scope' => $scope, 'version' => $version]);
                    }
                }
            }
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

            $cleanPath = '/inc/plugins/'.AF_PLUGIN_ID.'/addons/'.$id.'/assets/'.$f;
            if ($ext === 'css') {
                $themeHref = af_theme_stylesheet_frontend_href_for_candidate($id, 'assets/'.$f);
                if ($themeHref !== '') {
                    af_add_css_once($cleanPath, ['scope' => 'both', 'raw_url' => $themeHref]);
                } else {
                    af_add_css_once($cleanPath, ['scope' => 'both', 'version' => (string)($meta['version'] ?? '')]);
                }
            }
            if ($ext === 'js')  af_add_js_once($cleanPath, ['scope' => 'both', 'version' => (string)($meta['version'] ?? '')]);
        }
    }

    $css = array_values($GLOBALS['af_assets_queue']['css'] ?? []);
    $js  = array_values($GLOBALS['af_assets_queue']['js'] ?? []);

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
