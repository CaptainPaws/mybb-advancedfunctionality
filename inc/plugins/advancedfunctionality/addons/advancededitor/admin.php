<?php
/**
 * AF Admin Controller: AdvancedEditor
 * + Конструктор тулбара (drag&drop + предпросмотр)
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

if (!defined('AF_AE_ID')) {
    define('AF_AE_ID', 'advancededitor');
}
if (!defined('AF_AE_TABLE')) {
    define('AF_AE_TABLE', 'af_ae_buttons');
}

if (!defined('AF_AE_DO_LIST'))     define('AF_AE_DO_LIST',     'button_list');
if (!defined('AF_AE_DO_ADD'))      define('AF_AE_DO_ADD',      'button_add');
if (!defined('AF_AE_DO_EDIT'))     define('AF_AE_DO_EDIT',     'button_edit');
if (!defined('AF_AE_DO_DELETE'))   define('AF_AE_DO_DELETE',   'button_delete');
if (!defined('AF_AE_DO_TOOLBAR'))  define('AF_AE_DO_TOOLBAR',  'toolbar');
if (!defined('AF_AE_DO_FONTS'))    define('AF_AE_DO_FONTS',    'fonts');
if (!defined('AF_AE_DO_HELP'))     define('AF_AE_DO_HELP',     'formatting_help');

if (!defined('AF_AE_SETTING_LAYOUT')) define('AF_AE_SETTING_LAYOUT', 'af_advancededitor_toolbar_layout');
if (!defined('AF_AE_SETTING_FONTS'))  define('AF_AE_SETTING_FONTS',  'af_advancededitor_fontfamily_json');
if (!defined('AF_AE_SETTING_HELP_ENABLED'))  define('AF_AE_SETTING_HELP_ENABLED', 'af_advancededitor_help_enabled');
if (!defined('AF_AE_SETTING_HELP_CONTENT'))  define('AF_AE_SETTING_HELP_CONTENT', 'af_advancededitor_help_content');
if (!defined('AF_AE_SETTING_HELP_TITLE'))    define('AF_AE_SETTING_HELP_TITLE', 'af_advancededitor_help_title');
if (!defined('AF_AE_SETTING_HELP_POSITION')) define('AF_AE_SETTING_HELP_POSITION', 'af_advancededitor_help_position');

function af_ae_admin_builtin_buttons(string $bburl): array
{
    $bburl = rtrim($bburl, '/');

    $bbcodesDir = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/bbcodes/';
    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/';

    $out = [];

    if (!is_dir($bbcodesDir)) return $out;

    $packs = @scandir($bbcodesDir);
    if (!is_array($packs)) return $out;

    foreach ($packs as $pack) {
        if ($pack === '.' || $pack === '..') continue;

        $packDir = $bbcodesDir . $pack . '/';
        if (!is_dir($packDir)) continue;

        $manifest = $packDir . 'manifest.php';
        if (!is_file($manifest)) continue;

        $m = @include $manifest;
        if (!is_array($m)) continue;

        // берём кнопки (у тебя это m['buttons'])
        $buttons = [];
        if (isset($m['buttons']) && is_array($m['buttons'])) {
            $buttons = $m['buttons'];
        } elseif (!empty($m['cmd'])) {
            $buttons = [$m];
        } else {
            continue;
        }

        // берём ассеты (у тебя это m['assets']['css/js'])
        $assetsCss = [];
        $assetsJs  = [];
        if (!empty($m['assets']) && is_array($m['assets'])) {
            if (!empty($m['assets']['css']) && is_array($m['assets']['css'])) $assetsCss = $m['assets']['css'];
            if (!empty($m['assets']['js'])  && is_array($m['assets']['js']))  $assetsJs  = $m['assets']['js'];
        } else {
            // фоллбэк на случай другого формата
            if (!empty($m['css']) && is_array($m['css'])) $assetsCss = $m['css'];
            if (!empty($m['js'])  && is_array($m['js']))  $assetsJs  = $m['js'];
        }

        foreach ($buttons as $b) {
            if (!is_array($b)) continue;

            $cmd     = trim((string)($b['cmd'] ?? ''));
            $title   = trim((string)($b['title'] ?? $b['name'] ?? ''));
            $hint    = trim((string)($b['hint'] ?? $title));
            $icon    = trim((string)($b['icon'] ?? ''));
            $label   = trim((string)($b['label'] ?? '▦'));

            // ВАЖНО: для твоего table — handler
            $handler = trim((string)($b['handler'] ?? ''));

            // на будущее: если будет обычная кнопка без handler — теги
            $opentag  = (string)($b['opentag'] ?? '');
            $closetag = (string)($b['closetag'] ?? '');

            if ($cmd === '') continue;
            if ($title === '') $title = $cmd;

            // если svg-markup — не тащим как URL (твоя логика)
            if ($icon !== '' && stripos(ltrim($icon), '<svg') === 0) {
                $icon = '';
            }

            // нормализуем icon
            if ($icon !== '') {
                if (preg_match('~^(https?:)?//~i', $icon) || strpos($icon, 'data:') === 0) {
                    // ok
                } elseif (isset($icon[0]) && $icon[0] === '/') {
                    $icon = $bburl . $icon;
                } else {
                    $icon = $assetsBase . ltrim($icon, '/');
                }
            }

            // ассеты: собираем абсолютные URL (чтобы ACP мог подключить)
            $cssUrls = [];
            foreach ($assetsCss as $c) {
                $c = trim((string)$c);
                if ($c === '') continue;
                $cssUrls[] = $assetsBase . ltrim($c, '/');
            }

            $jsUrls = [];
            foreach ($assetsJs as $j) {
                $j = trim((string)$j);
                if ($j === '') continue;
                $jsUrls[] = $assetsBase . ltrim($j, '/');
            }

            $out[] = [
                'cmd'      => $cmd,
                'label'    => ($label !== '' ? $label : '▦'),
                'title'    => $title,
                'hint'     => ($hint !== '' ? $hint : $title),
                'icon'     => $icon,

                // ДОБАВИЛИ:
                'handler'  => $handler,
                'opentag'  => $opentag,
                'closetag' => $closetag,
                'assets'   => [
                    'css' => $cssUrls,
                    'js'  => $jsUrls,
                ],
            ];
        }
    }

    return $out;
}


class AF_AE_FormContainerShim
{
    private string $title;
    private array $rows = [];

    public function __construct(string $title) { $this->title = $title; }

    public function output_row(string $label, string $description, string $content): void
    {
        $this->rows[] = [$label, $description, $content];
    }

    public function end(): void
    {
        echo '<div class="table_border">';
        echo '<div class="table_heading">' . htmlspecialchars_uni($this->title) . '</div>';
        echo '<table class="table_form" cellpadding="0" cellspacing="0">';
        foreach ($this->rows as [$label, $desc, $content]) {
            echo '<tr>';
            echo '<td class="table_cell" style="width: 35%;"><strong>' . htmlspecialchars_uni($label) . '</strong><br /><span class="smalltext">' . htmlspecialchars_uni($desc) . '</span></td>';
            echo '<td class="table_cell">' . $content . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
    }
}

function af_ae_require_form_libs(): void
{
    $formPath = MYBB_ADMIN_DIR . 'inc/class_form.php';
    if (file_exists($formPath)) require_once $formPath;

    if (!class_exists('FormContainer', false)) {
        $containerPath = MYBB_ADMIN_DIR . 'inc/class_form_container.php';
        if (file_exists($containerPath)) require_once $containerPath;
    }
}

class AF_Admin_AdvancedEditor
{
    public static function dispatch(): void
    {
        global $mybb, $lang, $page;

        $do = (string)$mybb->get_input('do');
        if ($do === '') $do = AF_AE_DO_LIST;

        $page->add_breadcrumb_item('Advanced Editor');

        switch ($do) {
            case AF_AE_DO_ADD:     self::page_add(); return;
            case AF_AE_DO_EDIT:    self::page_edit(); return;
            case AF_AE_DO_DELETE:  self::page_delete(); return;
            case AF_AE_DO_TOOLBAR: self::page_toolbar(); return;
            case AF_AE_DO_FONTS:   self::page_fonts(); return;
            case AF_AE_DO_HELP:    self::page_help(); return;
            case AF_AE_DO_LIST:
            default:               self::page_list(); return;
        }
    }

    private static function base_url(string $do = AF_AE_DO_LIST, array $extra = []): string
    {
        $url = self::base_url_raw($do, $extra);
        return str_replace('&', '&amp;', $url);
    }

    private static function base_url_raw(string $do = AF_AE_DO_LIST, array $extra = []): string
    {
        $params = array_merge(
            [
                'module'  => 'advancedfunctionality',
                'af_view' => AF_AE_ID,
                'do'      => $do,
            ],
            $extra
        );

        $qs = http_build_query($params, '', '&');
        return 'index.php?' . $qs;
    }

    private static function ensure_table(): void
    {
        global $db;

        if ($db->table_exists(AF_AE_TABLE)) return;

        $collation = $db->build_create_table_collation();
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AE_TABLE . " (
                bid INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                icon VARCHAR(255) NOT NULL DEFAULT '',
                opentag VARCHAR(255) NOT NULL,
                closetag VARCHAR(255) NOT NULL DEFAULT '',
                active TINYINT(1) NOT NULL DEFAULT 1,
                disporder INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (bid),
                UNIQUE KEY name (name),
                KEY active (active),
                KEY disporder (disporder)
            ) ENGINE=MyISAM {$collation};
        ");
    }

    private static function output_tabs(string $active): void
    {
        global $page, $lang;

        $helpTabTitle = isset($lang->af_advancededitor_help_tab) ? (string)$lang->af_advancededitor_help_tab : 'Подсказка по форматированию';

        $sub_tabs = [
            'list' => [
                'title'       => 'Кнопки',
                'link'        => self::base_url(AF_AE_DO_LIST),
                'description' => 'Список кастомных кнопок редактора.',
            ],
            'add' => [
                'title'       => 'Добавить кнопку',
                'link'        => self::base_url(AF_AE_DO_ADD),
                'description' => 'Добавить новую кнопку.',
            ],
            'toolbar' => [
                'title'       => 'Тулбар',
                'link'        => self::base_url(AF_AE_DO_TOOLBAR),
                'description' => 'Конструктор тулбара (секции, drag&drop, dropdown).',
            ],
            'fonts' => [
                'title'       => 'Загрузить шрифты',
                'link'        => self::base_url(AF_AE_DO_FONTS),
                'description' => 'Загрузка файлов шрифтов в assets/fonts и управление списком.',
            ],
            'help' => [
                'title'       => $helpTabTitle,
                'link'        => self::base_url(AF_AE_DO_HELP),
                'description' => 'Контент модального окна с подсказкой по форматированию.',
            ],
        ];

        $page->output_nav_tabs($sub_tabs, $active);
    }

    private static function page_list(): void
    {
        global $db;

        self::ensure_table();
        self::output_tabs('list');

        require_once MYBB_ADMIN_DIR . 'inc/class_table.php';

        $table = new Table;
        $table->construct_header('Order', ['width' => '6%']);
        $table->construct_header('Name',  ['width' => '14%']);
        $table->construct_header('Title', ['width' => '18%']);
        $table->construct_header('Icon',  ['width' => '10%']);
        $table->construct_header('Tags');
        $table->construct_header('Active', ['width' => '8%']);
        $table->construct_header('Controls', ['width' => '12%']);

        $q = $db->simple_select(AF_AE_TABLE, '*', '1=1', ['order_by' => 'disporder ASC, name ASC']);
        while ($row = $db->fetch_array($q)) {

            $icon = trim((string)$row['icon']);
            if ($icon === '') {
                $icon = '../inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/img/af.svg';
            }

            $iconHtml = '<img src="' . htmlspecialchars_uni($icon) . '" alt="" style="width:18px;height:18px;vertical-align:middle;border-radius:4px;" />';
            $tags = htmlspecialchars_uni((string)$row['opentag']) . ' … ' . htmlspecialchars_uni((string)$row['closetag']);

            $active = ((int)$row['active'] === 1)
                ? '<span style="color:green;font-weight:700;">Yes</span>'
                : '<span style="color:#a00;font-weight:700;">No</span>';

            $controls =
                '<a href="' . self::base_url(AF_AE_DO_EDIT, ['bid' => (int)$row['bid']]) . '">Edit</a>'
                . ' | '
                . '<a href="' . self::base_url(AF_AE_DO_DELETE, ['bid' => (int)$row['bid']]) . '">Delete</a>';

            $table->construct_cell((int)$row['disporder']);
            $table->construct_cell(htmlspecialchars_uni((string)$row['name']));
            $table->construct_cell(htmlspecialchars_uni((string)$row['title']));
            $table->construct_cell($iconHtml);
            $table->construct_cell($tags);
            $table->construct_cell($active);
            $table->construct_cell($controls);

            $table->construct_row();
        }

        if ($table->num_rows() === 0) {
            $table->construct_cell('Нет кнопок. Добавь первую через вкладку "Добавить кнопку".', ['colspan' => 7]);
            $table->construct_row();
        }

        $table->output('Кастомные кнопки Advanced Editor');
    }

    private static function page_add(): void
    {
        global $mybb;

        self::ensure_table();

        if ($mybb->request_method === 'post') {
            self::save_button(0);
            return;
        }

        self::output_tabs('add');

        self::render_form('add', [
            'name'      => '',
            'title'     => '',
            'icon'      => '',
            'opentag'   => '',
            'closetag'  => '',
            'active'    => 1,
            'disporder' => 0,
        ], 0);
    }

    private static function page_edit(): void
    {
        global $mybb, $db, $page;

        self::ensure_table();

        $bid = (int)$mybb->get_input('bid');
        if ($bid <= 0) {
            flash_message('Некорректный bid', 'error');
            admin_redirect(self::base_url_raw(AF_AE_DO_LIST));
        }

        $row = $db->fetch_array($db->simple_select(AF_AE_TABLE, '*', "bid='{$bid}'", ['limit' => 1]));
        if (empty($row)) {
            flash_message('Кнопка не найдена', 'error');
            admin_redirect(self::base_url_raw(AF_AE_DO_LIST));
        }

        if ($mybb->request_method === 'post') {
            self::save_button($bid);
            return;
        }

        $sub_tabs = [
            'list' => [
                'title' => 'Кнопки',
                'link'  => self::base_url(AF_AE_DO_LIST),
            ],
            'edit' => [
                'title' => 'Редактировать',
                'link'  => self::base_url(AF_AE_DO_EDIT, ['bid' => $bid]),
            ],
            'toolbar' => [
                'title' => 'Тулбар',
                'link'  => self::base_url(AF_AE_DO_TOOLBAR),
            ],
        ];
        $page->output_nav_tabs($sub_tabs, 'edit');

        self::render_form('edit', $row, $bid);
    }

    private static function page_delete(): void
    {
        global $mybb, $db;

        self::ensure_table();

        $bid = (int)$mybb->get_input('bid');
        if ($bid <= 0) {
            flash_message('Некорректный bid', 'error');
            admin_redirect(self::base_url_raw(AF_AE_DO_LIST));
        }

        if ($mybb->request_method === 'post' && !empty($mybb->input['confirm'])) {
            $db->delete_query(AF_AE_TABLE, "bid='{$bid}'");
            flash_message('Удалено', 'success');
            admin_redirect(self::base_url_raw(AF_AE_DO_LIST));
        }

        af_ae_require_form_libs();
        $form = new Form(self::base_url_raw(AF_AE_DO_DELETE, ['bid' => $bid]), 'post');

        echo '<div class="confirm_action">';
        echo '<p>Точно удалить эту кнопку?</p>';
        echo '<p class="buttons">';
        echo $form->generate_hidden_field('my_post_key', (string)($mybb->post_code ?? ''));
        echo $form->generate_hidden_field('confirm', '1');
        echo $form->generate_submit_button('Удалить', ['class' => 'button_yes']);
        echo ' ';
        echo '<a href="' . self::base_url(AF_AE_DO_LIST) . '" class="button_no">Отмена</a>';
        echo '</p>';
        echo '</div>';

        $form->end();
    }

    private static function page_toolbar(): void
    {
        global $mybb, $db;

        self::ensure_table();
        self::output_tabs('toolbar');

        $postCode = (string)($mybb->post_code ?? '');

        if ($mybb->request_method === 'post') {
            $postedKey = (string)$mybb->get_input('my_post_key');
            if ($postCode !== '' && $postedKey !== '' && !hash_equals($postCode, $postedKey)) {
                flash_message('Неверный my_post_key. Обнови страницу ACP и попробуй снова.', 'error');
                admin_redirect(self::base_url_raw(AF_AE_DO_TOOLBAR));
            }

            $json = trim((string)($mybb->input['layout_json'] ?? ''));

            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['sections']) || !is_array($data['sections'])) {
                flash_message('Layout JSON некорректный (sections пустой или не массив).', 'error');
                admin_redirect(self::base_url_raw(AF_AE_DO_TOOLBAR));
            }

            $db->update_query('settings', [
                'value' => $db->escape_string($json),
            ], "name='" . $db->escape_string(AF_AE_SETTING_LAYOUT) . "'");

            if (function_exists('rebuild_settings')) {
                rebuild_settings();
            } else {
                require_once MYBB_ROOT . 'inc/functions.php';
                rebuild_settings();
            }

            $settingsFile = MYBB_ROOT . 'inc/settings.php';
            @clearstatcache(true, $settingsFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($settingsFile, true);
            }

            flash_message('Тулбар сохранён', 'success');
            admin_redirect(self::base_url_raw(AF_AE_DO_TOOLBAR));
        }

        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        $sceditorCss = self::resolve_sceditor_css_url($bburl);

        $available = self::get_available_buttons();

        // читаем layout из БД (источник истины)
        $layoutRaw = '';
        $q = $db->simple_select('settings', 'value', "name='" . $db->escape_string(AF_AE_SETTING_LAYOUT) . "'", ['limit' => 1]);
        $layoutRawDb = $db->fetch_field($q, 'value');
        if (is_string($layoutRawDb)) $layoutRaw = $layoutRawDb;

        $layout = null;
        if (trim($layoutRaw) !== '') {
            $decoded = json_decode($layoutRaw, true);
            if (is_array($decoded)) $layout = $decoded;
        }

        // builtins (включая assets)
        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        $builtinsWithAssets = af_ae_admin_builtin_buttons($bburl);

        // соберём ассеты всех паков (чтобы в ACP превью реально знало команды)
        $packCss = [];
        $packJs  = [];

        foreach ($builtinsWithAssets as $b) {
            if (empty($b['assets']) || !is_array($b['assets'])) continue;

            if (!empty($b['assets']['css']) && is_array($b['assets']['css'])) {
                foreach ($b['assets']['css'] as $href) {
                    $href = (string)$href;
                    if ($href !== '') $packCss[$href] = true;
                }
            }
            if (!empty($b['assets']['js']) && is_array($b['assets']['js'])) {
                foreach ($b['assets']['js'] as $src) {
                    $src = (string)$src;
                    if ($src !== '') $packJs[$src] = true;
                }
            }
        }

        $payload = [
            'available'   => $available,
            'layout'      => $layout,
            'sceditorCss' => $sceditorCss,
            'dropdownCmdPrefix' => 'af_menu_dropdown',

            // ДОБАВИЛИ:
            'packCss'     => array_keys($packCss),
            'packJs'      => array_keys($packJs),
        ];


        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') $json = '{}';

        echo "\n" . '<link rel="stylesheet" href="' . htmlspecialchars_uni($sceditorCss) . '" />';
        echo "\n" . '<link rel="stylesheet" href="' . htmlspecialchars_uni($bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/advancedquickreply_admin_toolbar.css') . '" />';
        // ПОДКЛЮЧАЕМ CSS паков (table.css и т.п.)
        if (!empty($payload['packCss']) && is_array($payload['packCss'])) {
            foreach ($payload['packCss'] as $href) {
                echo "\n" . '<link rel="stylesheet" href="' . htmlspecialchars_uni((string)$href) . '" />';
            }
        }

        // ПОДКЛЮЧАЕМ JS паков (table.js и т.п.) — ДО admin_toolbar.js
        echo "\n" . '<script src="' . htmlspecialchars_uni($bburl . '/jscripts/sceditor/jquery.sceditor.min.js') . '"></script>';
        echo "\n" . '<script src="' . htmlspecialchars_uni($bburl . '/jscripts/sceditor/jquery.sceditor.bbcode.min.js') . '"></script>';

        // ПОДКЛЮЧАЕМ JS паков (table.js и т.п.) — ПОСЛЕ SCEditor
        if (!empty($payload['packJs']) && is_array($payload['packJs'])) {
            foreach ($payload['packJs'] as $src) {
                echo "\n" . '<script src="' . htmlspecialchars_uni((string)$src) . '"></script>';
            }
        }

        echo "\n" . '<script>window.afAeAdminToolbarPayload=' . $json . ';</script>';


        $v = defined('TIME_NOW') ? (int)TIME_NOW : time();
        echo "\n" . '<script defer="defer" src="' . htmlspecialchars_uni($bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/advancedquickreply_admin_toolbar.js?v=' . $v) . '"></script>' . "\n";

        af_ae_require_form_libs();
        $form = new Form(self::base_url_raw(AF_AE_DO_TOOLBAR), 'post');

        echo '<div class="af-aqr-admin-grid">';

        echo '<div class="af-aqr-admin-box">';
        echo '<div class="af-aqr-admin-hd">Доступные кнопки</div>';
        echo '<div class="af-aqr-admin-bd">';
        echo '<div class="af-aqr-btnlist"></div>';
        echo '<div class="smalltext" style="margin-top:10px;opacity:.85;">Перетаскивай кнопки в секции справа. Двойной клик по pill — удалить из секции. Drag внутри секции — перестановка.</div>';

        echo '<div class="af-aqr-trash" id="af_aqr_trash" title="Перетащи сюда кнопку из секций, чтобы убрать её из раскладки">';
        echo '  <div class="af-aqr-trash-ico" aria-hidden="true">🗑</div>';
        echo '  <div class="af-aqr-trash-txt">';
        echo '    <div><strong>Корзина</strong></div>';
        echo '    <div class="smalltext">Перетащи сюда pill из секций, чтобы удалить из раскладки.</div>';
        echo '  </div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        echo '<div class="af-aqr-admin-box">';
        echo '<div class="af-aqr-admin-hd">Секции тулбара</div>';
        echo '<div class="af-aqr-admin-bd">';

        echo '<div class="af-aqr-row">';
        echo '<a href="#" class="button" id="af_aqr_add_group">+ Секция</a>';
        echo '<a href="#" class="button" id="af_aqr_add_dropdown">+ Dropdown</a>';
        echo '<a href="#" class="button" id="af_aqr_reset_layout" style="float:right;">Сбросить</a>';
        echo '</div>';

        echo '<div class="af-aqr-sections" style="margin-top:12px;"></div>';

        echo '<div class="af-aqr-actions">';
        echo $form->generate_hidden_field('my_post_key', $postCode);
        echo $form->generate_hidden_field('layout_json', '', ['id' => 'af_aqr_layout_json']);
        echo $form->generate_submit_button('Сохранить тулбар');
        echo '</div>';

        echo '<div class="af-aqr-preview">';
        echo '<div class="smalltext">Строка toolbar:</div>';
        echo '<div class="af-aqr-toolbarstr" style="font-family:monospace;white-space:pre-wrap;background:#262729;border:1px solid #353738;border-radius:10px;padding:10px;margin:6px 0 10px 0;"></div>';
        echo '<textarea id="af_aqr_preview_ta"></textarea>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        echo '</div>';

        $form->end();
    }

    private static function resolve_sceditor_css_url(string $bburl): string
    {
        $bburl = rtrim($bburl, '/');

        $candidates = [
            '/jscripts/sceditor/themes/default.min.css',
            '/jscripts/sceditor/themes/default.css',
            '/jscripts/sceditor/themes/modern.min.css',
            '/jscripts/sceditor/themes/modern.css',
        ];

        foreach ($candidates as $rel) {
            $fs = MYBB_ROOT . ltrim($rel, '/');
            if (file_exists($fs)) return $bburl . $rel;
        }

        return $bburl . '/jscripts/sceditor/themes/default.min.css';
    }


    private static function get_available_buttons(): array
    {
        global $db, $mybb;

        // ВАЖНО: отдаём title (человеческий), hint можно оставить как дубль
        $std = [
            ['cmd' => 'bold',        'label' => 'B',   'title' => 'Жирный',                 'hint' => 'SCEditor: bold'],
            ['cmd' => 'italic',      'label' => 'I',   'title' => 'Курсив',                 'hint' => 'SCEditor: italic'],
            ['cmd' => 'underline',   'label' => 'U',   'title' => 'Подчёркнутый',           'hint' => 'SCEditor: underline'],
            ['cmd' => 'strike',      'label' => 'S',   'title' => 'Зачёркнутый',            'hint' => 'SCEditor: strike'],
            ['cmd' => 'subscript',   'label' => 'x₂',  'title' => 'Нижний индекс',          'hint' => 'SCEditor: subscript'],
            ['cmd' => 'superscript', 'label' => 'x²',  'title' => 'Верхний индекс',         'hint' => 'SCEditor: superscript'],
            ['cmd' => 'font',        'label' => 'F',   'title' => 'Шрифт',                  'hint' => 'SCEditor: font'],
            ['cmd' => 'size',        'label' => 'Sz',  'title' => 'Размер',                 'hint' => 'SCEditor: size'],
            ['cmd' => 'color',       'label' => 'C',   'title' => 'Цвет',                   'hint' => 'SCEditor: color'],
            ['cmd' => 'removeformat','label' => '×',   'title' => 'Очистить формат',        'hint' => 'SCEditor: removeformat'],
            ['cmd' => 'undo',        'label' => '↶',   'title' => 'Отменить',               'hint' => 'SCEditor: undo'],
            ['cmd' => 'redo',        'label' => '↷',   'title' => 'Повторить',              'hint' => 'SCEditor: redo'],
            ['cmd' => 'pastetext',   'label' => 'Tx',  'title' => 'Вставить как текст',     'hint' => 'SCEditor: pastetext'],
            ['cmd' => 'horizontalrule','label' => '—', 'title' => 'Горизонтальная линия',   'hint' => 'SCEditor: horizontalrule'],

            ['cmd' => 'left',        'label' => 'L',   'title' => 'По левому краю',         'hint' => 'SCEditor: left'],
            ['cmd' => 'center',      'label' => 'C',   'title' => 'По центру',              'hint' => 'SCEditor: center'],
            ['cmd' => 'right',       'label' => 'R',   'title' => 'По правому краю',        'hint' => 'SCEditor: right'],
            ['cmd' => 'justify',     'label' => 'J',   'title' => 'По ширине',              'hint' => 'SCEditor: justify'],

            ['cmd' => 'bulletlist',  'label' => '•',   'title' => 'Маркированный список',   'hint' => 'SCEditor: bulletlist'],
            ['cmd' => 'orderedlist', 'label' => '1.',  'title' => 'Нумерованный список',    'hint' => 'SCEditor: orderedlist'],

            ['cmd' => 'quote',       'label' => '❝',   'title' => 'Цитата',                 'hint' => 'SCEditor: quote'],
            ['cmd' => 'code',        'label' => '</>', 'title' => 'Код',                    'hint' => 'SCEditor: code'],

            ['cmd' => 'image',       'label' => '🖼',  'title' => 'Изображение',            'hint' => 'SCEditor: image'],
            ['cmd' => 'link',        'label' => '🔗',  'title' => 'Ссылка',                 'hint' => 'SCEditor: link'],
            ['cmd' => 'unlink',      'label' => '⛓',  'title' => 'Убрать ссылку',           'hint' => 'SCEditor: unlink'],
            ['cmd' => 'email',       'label' => '@',   'title' => 'Email',                  'hint' => 'SCEditor: email'],
            ['cmd' => 'youtube',     'label' => '▶',   'title' => 'YouTube',                'hint' => 'SCEditor: youtube'],
            ['cmd' => 'emoticon',    'label' => '☺',   'title' => 'Смайлы',                 'hint' => 'SCEditor: emoticon'],

            ['cmd' => 'af_togglemode', 'label' => 'A↔', 'title' => 'BBCode ⇄ Визуальный',     'hint' => 'Переключить режим редактора'],
            ['cmd' => 'source',      'label' => '{ }', 'title' => 'BBCode/Визуальный',       'hint' => 'SCEditor: source'],
            ['cmd' => 'maximize',    'label' => '⤢',  'title' => 'Развернуть',              'hint' => 'SCEditor: maximize'],

            ['cmd' => '|',           'label' => '|',   'title' => 'Разделитель',            'hint' => 'Разделитель группы'],
        ];

        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');

        // builtins (из паков), уже содержат handler/opentag/closetag/assets
        $builtins = af_ae_admin_builtin_buttons($bburl);

        $custom = [];
        if ($db->table_exists(AF_AE_TABLE)) {
            $q = $db->simple_select(AF_AE_TABLE, '*', "active=1", ['order_by' => 'disporder ASC, name ASC']);
            while ($r = $db->fetch_array($q)) {
                $name = trim((string)($r['name'] ?? ''));
                if ($name === '') continue;

                $cmd = (stripos($name, 'af_') === 0) ? $name : ('af_' . $name);

                $title = trim((string)($r['title'] ?? ''));
                if ($title === '') $title = $cmd;

                $icon = trim((string)($r['icon'] ?? ''));
                if ($icon === '') {
                    $icon = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/img/af.svg';
                }

                // ВОТ ЭТО БЫЛО КРИТИЧНО НЕ ОТДАВАТЬ:
                $opentag  = (string)($r['opentag'] ?? '');
                $closetag = (string)($r['closetag'] ?? '');
                $handler  = trim((string)($r['handler'] ?? '')); // если вдруг добавишь поле позже — уже готово

                $custom[] = [
                    'cmd'      => $cmd,
                    'label'    => 'AE',
                    'title'    => $title,
                    'hint'     => $title,
                    'icon'     => $icon,
                    'handler'  => $handler,
                    'opentag'  => $opentag,
                    'closetag' => $closetag,
                ];
            }
        }

        return array_merge($std, $builtins, $custom);
    }


    private static function render_form(string $mode, array $row, int $bid): void
    {
        global $mybb;

        af_ae_require_form_libs();

        $action = ($mode === 'edit' && $bid > 0)
            ? self::base_url_raw(AF_AE_DO_EDIT, ['bid' => $bid])
            : self::base_url_raw(AF_AE_DO_ADD);

        $form = new Form($action, 'post');
        echo $form->generate_hidden_field('my_post_key', (string)($mybb->post_code ?? ''));

        if (class_exists('FormContainer', false)) {
            $container = new FormContainer('Параметры кнопки');
        } else {
            $container = new AF_AE_FormContainerShim('Параметры кнопки');
        }

        $container->output_row(
            'Name (уникально)',
            'Например: spoiler, dice, cut. Только латиница/цифры/_.',
            $form->generate_text_box('name', (string)($row['name'] ?? ''), ['maxlength' => 64])
        );

        $container->output_row(
            'Title',
            'Подсказка (title) на кнопке.',
            $form->generate_text_box('title', (string)($row['title'] ?? ''), ['maxlength' => 255])
        );

        $container->output_row(
            'Icon URL',
            'Ссылка на иконку (svg/png). Пусто = дефолт.',
            $form->generate_text_box('icon', (string)($row['icon'] ?? ''), ['maxlength' => 255])
        );

        $container->output_row(
            'Open tag',
            'Что вставлять в начале.',
            $form->generate_text_box('opentag', (string)($row['opentag'] ?? ''), ['maxlength' => 255])
        );

        $container->output_row(
            'Close tag',
            'Что вставлять в конце (может быть пусто).',
            $form->generate_text_box('closetag', (string)($row['closetag'] ?? ''), ['maxlength' => 255])
        );

        $container->output_row(
            'Active',
            'Показывать кнопку на фронте.',
            $form->generate_yes_no_radio('active', (int)($row['active'] ?? 1))
        );

        $container->output_row(
            'Order',
            'Сортировка (меньше = левее).',
            $form->generate_numeric_field('disporder', (int)($row['disporder'] ?? 0), ['min' => 0])
        );

        $container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button('Сохранить');
        $form->output_submit_wrapper($buttons);

        $form->end();
    }

    private static function save_button(int $bid): void
    {
        global $mybb, $db;

        $name      = trim((string)$mybb->get_input('name'));
        $title     = trim((string)$mybb->get_input('title'));
        $icon      = trim((string)$mybb->get_input('icon'));
        $opentag   = (string)$mybb->get_input('opentag');
        $closetag  = (string)$mybb->get_input('closetag');
        $active    = (int)$mybb->get_input('active');
        $disporder = (int)$mybb->get_input('disporder');

        if ($name === '' || !preg_match('~^[a-z0-9_\.]+$~i', $name)) {
            flash_message('Name обязателен и должен быть латиницей/цифры/_.', 'error');
            admin_redirect($bid > 0 ? self::base_url_raw(AF_AE_DO_EDIT, ['bid' => $bid]) : self::base_url_raw(AF_AE_DO_ADD));
        }

        if ($title === '') {
            flash_message('Title обязателен', 'error');
            admin_redirect($bid > 0 ? self::base_url_raw(AF_AE_DO_EDIT, ['bid' => $bid]) : self::base_url_raw(AF_AE_DO_ADD));
        }

        if ($opentag === '') {
            flash_message('Open tag обязателен', 'error');
            admin_redirect($bid > 0 ? self::base_url_raw(AF_AE_DO_EDIT, ['bid' => $bid]) : self::base_url_raw(AF_AE_DO_ADD));
        }

        $where = "name='" . $db->escape_string($name) . "'";
        if ($bid > 0) $where .= " AND bid!='{$bid}'";

        $exists = (int)$db->fetch_field($db->simple_select(AF_AE_TABLE, 'COUNT(*) AS c', $where), 'c');
        if ($exists > 0) {
            flash_message('Name уже занят', 'error');
            admin_redirect($bid > 0 ? self::base_url_raw(AF_AE_DO_EDIT, ['bid' => $bid]) : self::base_url_raw(AF_AE_DO_ADD));
        }

        $data = [
            'name'      => $db->escape_string($name),
            'title'     => $db->escape_string($title),
            'icon'      => $db->escape_string($icon),
            'opentag'   => $db->escape_string($opentag),
            'closetag'  => $db->escape_string($closetag),
            'active'    => ($active ? 1 : 0),
            'disporder' => max(0, $disporder),
        ];

        if ($bid > 0) {
            $db->update_query(AF_AE_TABLE, $data, "bid='{$bid}'");
            flash_message('Сохранено', 'success');
            admin_redirect(self::base_url_raw(AF_AE_DO_EDIT, ['bid' => $bid]));
        } else {
            $newId = (int)$db->insert_query(AF_AE_TABLE, $data);
            flash_message('Добавлено', 'success');
            admin_redirect(self::base_url_raw(AF_AE_DO_EDIT, ['bid' => $newId]));
        }
    }

    // ===== Fonts вкладка: чтобы не раздувать сообщение, я рекомендую тупо перенести твою реализацию
    // из старого admin.php и заменить:
    // - setting name -> AF_AE_SETTING_FONTS
    // - fonts dir -> /addons/advancededitor/assets/fonts/
    // ВАЖНО: логика у тебя уже рабочая, её не трогаем.
    private static function page_fonts(): void
    {
        global $mybb, $db;

        self::output_tabs('fonts');

        // ====== Paths ======
        $fontsDirAbs = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/fonts/';
        $fontsDirAbsReal = @realpath($fontsDirAbs) ?: $fontsDirAbs;

        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        $fontsBaseUrl = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/fonts/';

        // ====== Helpers ======
        $allowedExt = ['woff2', 'woff', 'ttf', 'otf'];

        $ensureDir = function () use ($fontsDirAbs): bool {
            if (is_dir($fontsDirAbs)) return true;
            @mkdir($fontsDirAbs, 0775, true);
            return is_dir($fontsDirAbs);
        };

        $safeFileName = function (string $name) : string {
            $name = trim($name);
            $name = str_replace(['\\', '/', "\0"], '_', $name);
            $name = preg_replace('~[^a-zA-Z0-9_\.\-\(\)\[\]\s]+~u', '_', $name);
            $name = preg_replace('~\s+~u', ' ', $name);
            $name = trim($name);
            if ($name === '') $name = 'font';
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($name) > 180) $name = mb_substr($name, 0, 180);
            } else {
                if (strlen($name) > 180) $name = substr($name, 0, 180);
            }
            return $name;
        };

        $guessMeta = function (string $filenameBase): array {
            $s = strtolower($filenameBase);

            $style = (strpos($s, 'italic') !== false) ? 'italic' : 'normal';

            $weight = 400;
            if (strpos($s, 'thin') !== false) $weight = 100;
            elseif (strpos($s, 'extralight') !== false || strpos($s, 'ultralight') !== false) $weight = 200;
            elseif (strpos($s, 'light') !== false) $weight = 300;
            elseif (strpos($s, 'regular') !== false || strpos($s, 'book') !== false) $weight = 400;
            elseif (strpos($s, 'medium') !== false) $weight = 500;
            elseif (strpos($s, 'semibold') !== false || strpos($s, 'demibold') !== false) $weight = 600;
            elseif (strpos($s, 'bold') !== false) $weight = 700;
            elseif (strpos($s, 'extrabold') !== false || strpos($s, 'ultrabold') !== false) $weight = 800;
            elseif (strpos($s, 'black') !== false || strpos($s, 'heavy') !== false) $weight = 900;

            if (preg_match('~\b([1-9]00)\b~', $s, $m)) {
                $w = (int)$m[1];
                if ($w >= 100 && $w <= 900) $weight = $w;
            }

            return ['weight' => $weight, 'style' => $style];
        };

        $deriveFamily = function (string $filenameBase): string {
            // "Roboto-Bold" -> "Roboto", "Inter_Regular" -> "Inter"
            $family = $filenameBase;
            if (preg_match('~^([^-_]+)[-_]~', $filenameBase, $m)) {
                $family = $m[1];
            }
            $family = trim($family);
            if ($family === '') $family = 'CustomFont';
            return $family;
        };

        $isInsideFontsDir = function (string $absPath) use ($fontsDirAbsReal): bool {
            $rp = @realpath($absPath) ?: $absPath;
            $base = rtrim($fontsDirAbsReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                return stripos(rtrim($rp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $base) === 0;
            }
            return strpos(rtrim($rp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $base) === 0;
        };

        $scanFonts = function () use ($fontsDirAbs, $allowedExt, $deriveFamily, $guessMeta): array {
            $rows = [];
            if (!is_dir($fontsDirAbs)) return $rows;

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fontsDirAbs, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($it as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile()) continue;

                $abs = $file->getPathname();
                $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) continue;

                $rel = str_replace('\\', '/', substr($abs, strlen($fontsDirAbs)));
                $rel = ltrim($rel, '/');

                $base = pathinfo($abs, PATHINFO_FILENAME);
                $family = $deriveFamily($base);
                $meta = $guessMeta($base);

                $rows[] = [
                    'abs'    => $abs,
                    'rel'    => $rel,
                    'name'   => basename($abs),
                    'ext'    => $ext,
                    'size'   => (int)@filesize($abs),
                    'family' => $family,
                    'weight' => (int)$meta['weight'],
                    'style'  => (string)$meta['style'],
                    'mtime'  => (int)@filemtime($abs),
                ];
            }

            usort($rows, function($a, $b) {
                $c = strcasecmp((string)$a['family'], (string)$b['family']);
                if ($c !== 0) return $c;
                return strcasecmp((string)$a['name'], (string)$b['name']);
            });

            return $rows;
        };

        $normList = function($arr): array {
            if (!is_array($arr)) return [];
            $out = [];
            foreach ($arr as $x) {
                if (!is_string($x)) continue;
                $x = trim($x);
                if ($x === '') continue;
                $out[] = $x;
            }
            $out = array_values(array_unique($out));
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);
            return $out;
        };

        // --- settings read (мягкая миграция) ---
        $loadFontsJson = function () use ($db, $normList): array {
            $q = $db->simple_select('settings', 'value', "name='" . $db->escape_string(AF_AE_SETTING_FONTS) . "'", ['limit' => 1]);
            $raw = (string)$db->fetch_field($q, 'value');
            $raw = trim($raw);

            $data = [];
            if ($raw !== '') {
                $tmp = json_decode($raw, true);
                if (is_array($tmp)) $data = $tmp;
            }

            $v = (int)($data['v'] ?? 1);

            $manual = [];
            $uploaded = [];

            if (isset($data['manual']) && is_array($data['manual'])) $manual = $data['manual'];
            if (isset($data['uploaded']) && is_array($data['uploaded'])) $uploaded = $data['uploaded'];

            // legacy {"families":[...]} -> manual=families
            if (!$manual && !$uploaded && isset($data['families']) && is_array($data['families'])) {
                $manual = $data['families'];
                $uploaded = [];
            }

            $manual = $normList($manual);
            $uploaded = $normList($uploaded);

            $families = array_values(array_unique(array_merge($manual, $uploaded)));
            sort($families, SORT_NATURAL | SORT_FLAG_CASE);

            return [
                'v' => ($v > 0 ? $v : 1),
                'manual' => $manual,
                'uploaded' => $uploaded,
                'families' => $families,
            ];
        };

        $saveFontsJson = function (array $manual, array $uploaded) use ($db, $normList): void {
            $manual = $normList($manual);
            $uploaded = $normList($uploaded);

            $families = array_values(array_unique(array_merge($manual, $uploaded)));
            sort($families, SORT_NATURAL | SORT_FLAG_CASE);

            $json = json_encode(
                ['v' => 1, 'manual' => $manual, 'uploaded' => $uploaded, 'families' => $families],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (!is_string($json) || $json === '') $json = '{"v":1,"manual":[],"uploaded":[],"families":[]}';

            $db->update_query('settings', [
                'value' => $db->escape_string($json),
            ], "name='" . $db->escape_string(AF_AE_SETTING_FONTS) . "'");

            if (!function_exists('rebuild_settings')) {
                require_once MYBB_ROOT . 'inc/functions.php';
            }
            rebuild_settings();

            $settingsFile = MYBB_ROOT . 'inc/settings.php';
            @clearstatcache(true, $settingsFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($settingsFile, true);
            }
        };

        // ====== Ensure fonts dir ======
        if (!$ensureDir()) {
            echo '<div class="error"><strong>Ошибка:</strong> не удалось создать папку шрифтов: '
                . htmlspecialchars_uni($fontsDirAbs) . '<br>Создай её вручную и дай права на запись.</div>';
            return;
        }

        // ====== POST handlers (action строго из POST, fallback из GET) ======
        $postCode = (string)($mybb->post_code ?? '');
        $actionPost = (string)($mybb->input['action'] ?? '');
        $actionGet  = (string)$mybb->get_input('action');
        $action = $actionPost !== '' ? $actionPost : $actionGet;

        if ($mybb->request_method === 'post' && ($action === 'delete' || $action === 'upload')) {
            $postedKey = (string)$mybb->get_input('my_post_key');
            if ($postCode !== '' && $postedKey !== '' && !hash_equals($postCode, $postedKey)) {
                flash_message('Неверный my_post_key. Обнови страницу ACP и попробуй снова.', 'error');
                admin_redirect(self::base_url_raw(AF_AE_DO_FONTS));
            }
        }

        if ($mybb->request_method === 'post' && $action === 'delete') {
            $rel = (string)$mybb->get_input('file');
            $rel = str_replace(['\\', "\0"], ['/', ''], $rel);
            $rel = ltrim($rel, '/');

            if ($rel === '' || strpos($rel, '..') !== false) {
                flash_message('Некорректный путь файла.', 'error');
                admin_redirect(self::base_url_raw(AF_AE_DO_FONTS));
            }

            $abs = $fontsDirAbs . $rel;

            if (!is_file($abs) || !$isInsideFontsDir($abs)) {
                flash_message('Файл не найден или путь некорректный.', 'error');
                admin_redirect(self::base_url_raw(AF_AE_DO_FONTS));
            }

            $ok = @unlink($abs);
            if (!$ok && is_file($abs)) {
                flash_message('Не удалось удалить файл. Проверь права на папку fonts (chmod/chown) и что файл не занят.', 'error');
                admin_redirect(self::base_url_raw(AF_AE_DO_FONTS));
            }

            // JSON: manual сохраняем, uploaded пересчитываем из файлов
            $cur = $loadFontsJson();
            $rowsNow = $scanFonts();
            $uploadedFamilies = [];
            foreach ($rowsNow as $r) $uploadedFamilies[] = (string)$r['family'];
            $uploadedFamilies = $normList($uploadedFamilies);

            $saveFontsJson($cur['manual'], $uploadedFamilies);

            flash_message('Шрифт удалён.', 'success');
            admin_redirect(self::base_url_raw(AF_AE_DO_FONTS));
        }

        if ($mybb->request_method === 'post' && $action === 'upload') {
            $errors = [];
            $uploadedCount = 0;

            if (!empty($_FILES['fonts']) && is_array($_FILES['fonts']['name'])) {
                $names = $_FILES['fonts']['name'];
                $tmps  = $_FILES['fonts']['tmp_name'];
                $errs  = $_FILES['fonts']['error'];
                $sizes = $_FILES['fonts']['size'];

                $maxBytes = 25 * 1024 * 1024; // 25 MB

                $cnt = count($names);
                for ($i = 0; $i < $cnt; $i++) {
                    $origName = (string)$names[$i];
                    $tmpName  = (string)$tmps[$i];
                    $errCode  = (int)$errs[$i];
                    $size     = (int)$sizes[$i];

                    if ($origName === '') continue;

                    if ($errCode !== UPLOAD_ERR_OK) {
                        $errors[] = $origName . ' — ошибка загрузки (код ' . $errCode . ')';
                        continue;
                    }

                    if (!is_uploaded_file($tmpName)) {
                        $errors[] = $origName . ' — подозрительный upload (is_uploaded_file=false)';
                        continue;
                    }

                    if ($size <= 0 || $size > $maxBytes) {
                        $errors[] = $origName . ' — слишком большой файл (лимит 25MB)';
                        continue;
                    }

                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt, true)) {
                        $errors[] = $origName . ' — недопустимый формат. Разрешено: ' . implode(', ', $allowedExt);
                        continue;
                    }

                    $base = pathinfo($origName, PATHINFO_FILENAME);
                    $base = $safeFileName($base);

                    $final = $base . '.' . $ext;
                    $cand = $fontsDirAbs . $final;

                    $n = 1;
                    while (is_file($cand)) {
                        $final = $base . '_' . $n . '.' . $ext;
                        $cand = $fontsDirAbs . $final;
                        $n++;
                        if ($n > 200) break;
                    }

                    if ($n > 200) {
                        $errors[] = $origName . ' — не удалось подобрать уникальное имя файла';
                        continue;
                    }

                    if (!@move_uploaded_file($tmpName, $cand)) {
                        $errors[] = $origName . ' — не удалось сохранить файл (проверь права на папку fonts)';
                        continue;
                    }

                    @chmod($cand, 0644);
                    $uploadedCount++;
                }
            } else {
                $errors[] = 'Файлы не выбраны.';
            }

            // JSON: manual сохраняем, uploaded пересчитываем из файлов
            $cur = $loadFontsJson();
            $rowsNow = $scanFonts();
            $uploadedFamilies = [];
            foreach ($rowsNow as $r) $uploadedFamilies[] = (string)$r['family'];
            $uploadedFamilies = $normList($uploadedFamilies);

            $saveFontsJson($cur['manual'], $uploadedFamilies);

            if ($errors) {
                flash_message("Загрузка завершена. Успешно: {$uploadedCount}. Ошибки: " . implode(' | ', $errors), 'error');
            } else {
                flash_message("Загружено файлов: {$uploadedCount}.", 'success');
            }

            admin_redirect(self::base_url_raw(AF_AE_DO_FONTS));
        }

        // ====== AUTO-SYNC on GET ======
        $cur = $loadFontsJson();
        $rows = $scanFonts();
        $uploadedFamilies = [];
        foreach ($rows as $r) $uploadedFamilies[] = (string)$r['family'];
        $uploadedFamilies = $normList($uploadedFamilies);

        $curUploaded = $normList($cur['uploaded'] ?? []);
        if (implode("\n", $curUploaded) !== implode("\n", $uploadedFamilies)) {
            $saveFontsJson($cur['manual'], $uploadedFamilies);
            $cur['uploaded'] = $uploadedFamilies;
            $cur['families'] = array_values(array_unique(array_merge($cur['manual'], $uploadedFamilies)));
            sort($cur['families'], SORT_NATURAL | SORT_FLAG_CASE);
        }

        // ====== Render UI ======
        require_once MYBB_ADMIN_DIR . 'inc/class_table.php';
        af_ae_require_form_libs();

        echo '<div class="table_border">';
        echo '<div class="table_heading">Шрифты AdvancedEditor</div>';
        echo '<div style="padding: 10px;">';
        echo '<div class="smalltext" style="margin-bottom:10px;opacity:.9;">'
            . 'Загружай <strong>woff2</strong> (лучше всего), плюс <strong>woff</strong> как запасной вариант. '
            . 'ttf/otf работают, но тяжелее.'
            . '<br>Системные (ручные) font-family редактируются в настройках ACP — здесь показываются только файлы.'
            . '</div>';

        // Upload form (action в POST)
        $form = new Form(self::base_url_raw(AF_AE_DO_FONTS), 'post', '', 1);
        echo $form->generate_hidden_field('my_post_key', $postCode);
        echo $form->generate_hidden_field('action', 'upload');

        if (class_exists('FormContainer', false)) {
            $container = new FormContainer('Загрузка файлов');
        } else {
            $container = new AF_AE_FormContainerShim('Загрузка файлов');
        }

        $container->output_row(
            'Файлы шрифтов',
            'Можно выбрать несколько. Разрешено: ' . implode(', ', $allowedExt),
            '<input type="file" name="fonts[]" multiple="multiple" accept=".woff2,.woff,.ttf,.otf" />'
        );

        $container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button('Загрузить');
        $form->output_submit_wrapper($buttons);
        $form->end();

        echo '</div>';
        echo '</div>';

        // List table (only files)
        $table = new Table;
        $table->construct_header('Файл');
        $table->construct_header('Family', ['width' => '18%']);
        $table->construct_header('Style/Weight', ['width' => '14%']);
        $table->construct_header('Формат', ['width' => '8%']);
        $table->construct_header('Размер', ['width' => '10%']);
        $table->construct_header('URL', ['width' => '18%']);
        $table->construct_header('Действия', ['width' => '12%']);

        if (!$rows) {
            $table->construct_cell('Шрифтов пока нет. Загрузи файлы выше.', ['colspan' => 7]);
            $table->construct_row();
        } else {
            foreach ($rows as $r) {
                $rel = (string)$r['rel'];
                $url = $fontsBaseUrl . str_replace('%2F', '/', rawurlencode($rel));

                $sizeKb = (int)ceil(((int)$r['size']) / 1024);
                $sizeText = $sizeKb . ' KB';
                $styleWeight = htmlspecialchars_uni((string)$r['style']) . ' / ' . (int)$r['weight'];

                // Delete form (строго настоящий <form> внутри ячейки, иначе submit "в пустоту")
                $actionUrl = self::base_url_raw(AF_AE_DO_FONTS);

                $deleteHtml =
                    '<form action="' . htmlspecialchars_uni($actionUrl) . '" method="post" style="display:inline;">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($postCode) . '" />'
                . '<input type="hidden" name="action" value="delete" />'
                . '<input type="hidden" name="file" value="' . htmlspecialchars_uni($rel) . '" />'
                . '<input type="submit" class="button button_small" value="Удалить" onclick="return confirm(\'Удалить этот файл?\');" />'
                . '</form>';


                $table->construct_cell(htmlspecialchars_uni((string)$r['name']));
                $table->construct_cell(htmlspecialchars_uni((string)$r['family']));
                $table->construct_cell($styleWeight);
                $table->construct_cell(htmlspecialchars_uni((string)$r['ext']));
                $table->construct_cell($sizeText);
                $table->construct_cell('<a href="' . htmlspecialchars_uni($url) . '" target="_blank" rel="noopener">открыть</a>');
                $table->construct_cell($deleteHtml);

                $table->construct_row();
            }
        }

        $table->output('Загруженные шрифты');
    }


    private static function page_help(): void
    {
        global $mybb, $db, $lang;

        self::output_tabs('help');
        af_ae_require_form_libs();

        $readSetting = static function (string $name, string $default = '') use ($db): string {
            $q = $db->simple_select('settings', 'value', "name='" . $db->escape_string($name) . "'", ['limit' => 1]);
            $value = $db->fetch_field($q, 'value');
            return ($value === null) ? $default : (string)$value;
        };

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $enabled  = ((int)$mybb->get_input('help_enabled', MyBB::INPUT_INT) === 1) ? '1' : '0';
            $title    = trim((string)$mybb->get_input('help_title'));
            $content  = trim((string)$mybb->get_input('help_content'));
            $position = strtolower(trim((string)$mybb->get_input('help_position')));
            if (!in_array($position, ['left', 'right'], true)) {
                $position = 'right';
            }

            foreach ([
                AF_AE_SETTING_HELP_ENABLED  => $enabled,
                AF_AE_SETTING_HELP_TITLE    => $title,
                AF_AE_SETTING_HELP_CONTENT  => $content,
                AF_AE_SETTING_HELP_POSITION => $position,
            ] as $name => $value) {
                $db->update_query('settings', [
                    'value' => $db->escape_string((string)$value),
                ], "name='" . $db->escape_string($name) . "'");
            }

            if (!function_exists('rebuild_settings')) {
                require_once MYBB_ROOT . 'inc/functions.php';
            }
            rebuild_settings();

            $settingsFile = MYBB_ROOT . 'inc/settings.php';
            @clearstatcache(true, $settingsFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($settingsFile, true);
            }

            flash_message('Подсказка по форматированию сохранена.', 'success');
            admin_redirect(self::base_url_raw(AF_AE_DO_HELP));
        }

        $enabledValue = $readSetting(AF_AE_SETTING_HELP_ENABLED, '1');
        $titleValue = $readSetting(AF_AE_SETTING_HELP_TITLE, 'Подсказка по форматированию');
        $contentValue = $readSetting(AF_AE_SETTING_HELP_CONTENT, '');
        $positionValue = $readSetting(AF_AE_SETTING_HELP_POSITION, 'right');
        if (!in_array($positionValue, ['left', 'right'], true)) {
            $positionValue = 'right';
        }

        $helpTabTitle = isset($lang->af_advancededitor_help_tab) ? (string)$lang->af_advancededitor_help_tab : 'Подсказка по форматированию';

        $form = new Form(self::base_url_raw(AF_AE_DO_HELP), 'post', '', 1);
        echo $form->generate_hidden_field('my_post_key', (string)$mybb->post_code);

        $container = class_exists('FormContainer', false)
            ? new FormContainer($helpTabTitle)
            : new AF_AE_FormContainerShim($helpTabTitle);

        $container->output_row(
            'Включить кнопку ?',
            'Если выключено или контент пустой, кнопка в тулбаре не показывается.',
            $form->generate_yes_no_radio('help_enabled', (int)$enabledValue)
        );

        $container->output_row(
            'Заголовок модального окна',
            'Текст заголовка подсказки.',
            $form->generate_text_box('help_title', htmlspecialchars_uni($titleValue), ['style' => 'width: 100%;'])
        );

        $container->output_row(
            'Позиция кнопки в тулбаре',
            'left — перед кнопками, right — после всех кнопок.',
            $form->generate_select_box('help_position', [
                'left' => 'Слева (в начале)',
                'right' => 'Справа (в конце)',
            ], $positionValue)
        );

        $container->output_row(
            'Контент подсказки (BBCode)',
            'Можно использовать BBCode. На фронте контент рендерится в HTML серверным парсером MyBB.',
            $form->generate_text_area('help_content', htmlspecialchars_uni($contentValue), ['rows' => 14, 'style' => 'width: 100%;'])
        );

        $container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button('Сохранить');
        $form->output_submit_wrapper($buttons);
        $form->end();
    }

}
