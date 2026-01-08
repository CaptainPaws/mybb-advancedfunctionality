<?php
/**
 * AF Admin Controller: AdvancedQuickReply
 * + Конструктор тулбара (drag&drop + предпросмотр)
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

if (!defined('AF_AQR_ID')) {
    define('AF_AQR_ID', 'advancedquickreply');
}
if (!defined('AF_AQR_TABLE')) {
    define('AF_AQR_TABLE', 'af_aqr_buttons');
}

if (!defined('AF_AQR_DO_LIST'))     define('AF_AQR_DO_LIST',     'button_list');
if (!defined('AF_AQR_DO_ADD'))      define('AF_AQR_DO_ADD',      'button_add');
if (!defined('AF_AQR_DO_EDIT'))     define('AF_AQR_DO_EDIT',     'button_edit');
if (!defined('AF_AQR_DO_DELETE'))   define('AF_AQR_DO_DELETE',   'button_delete');

// НОВОЕ
if (!defined('AF_AQR_DO_TOOLBAR'))  define('AF_AQR_DO_TOOLBAR',  'toolbar');
if (!defined('AF_AQR_DO_FONTS'))    define('AF_AQR_DO_FONTS',    'fonts');


function af_aqr_admin_builtin_buttons(string $bburl): array
{
    $bburl = rtrim($bburl, '/');

    $bbcodesDir = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/bbcodes/';
    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/';

    $out = [];

    if (!is_dir($bbcodesDir)) {
        return $out;
    }

    $packs = @scandir($bbcodesDir);
    if (!is_array($packs)) {
        return $out;
    }

    foreach ($packs as $pack) {
        if ($pack === '.' || $pack === '..') continue;

        $packDir = $bbcodesDir . $pack . '/';
        if (!is_dir($packDir)) continue;

        $manifest = $packDir . 'manifest.php';
        if (!is_file($manifest)) continue;

        $m = @include $manifest;
        if (!is_array($m)) continue;

        // Поддержка ДВУХ форматов:
        // 1) новый/правильный: ['buttons' => [ ... ]]
        // 2) старый/плоский: ['cmd' => ..., 'title' => ...]
        $buttons = [];
        if (isset($m['buttons']) && is_array($m['buttons'])) {
            $buttons = $m['buttons'];
        } elseif (!empty($m['cmd']) && !empty($m['title'])) {
            $buttons = [$m];
        } else {
            continue;
        }

        foreach ($buttons as $b) {
            if (!is_array($b)) continue;

            $cmd   = trim((string)($b['cmd'] ?? ''));
            $title = trim((string)($b['title'] ?? ''));
            $hint  = trim((string)($b['hint'] ?? $title));
            $icon  = trim((string)($b['icon'] ?? ''));
            $label = trim((string)($b['label'] ?? '▦'));

            if ($cmd === '' || $title === '') continue;

            // Если вдруг кто-то сунул SVG разметкой — НЕ превращаем в url(...)
            // (в админском UI это всё равно background-image, так что SVG-строка там токсична)
            if ($icon !== '' && stripos(ltrim($icon), '<svg') === 0) {
                $icon = '';
            }

            // icon: поддержка "bbcodes/..", абсолютных и data:
            if ($icon !== '') {
                if (preg_match('~^(https?:)?//~i', $icon) || strpos($icon, 'data:') === 0) {
                    // ok
                } elseif (isset($icon[0]) && $icon[0] === '/') {
                    $icon = $bburl . $icon;
                } else {
                    $icon = $assetsBase . $icon;
                }
            }

            $out[] = [
                'cmd'   => $cmd,
                'label' => ($label !== '' ? $label : '▦'),
                'hint'  => ($hint !== '' ? $hint : $title),
                'icon'  => $icon,
            ];
        }
    }

    return $out;
}

class AF_AQR_FormContainerShim
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

function af_aqr_require_form_libs(): void
{
    $formPath = MYBB_ADMIN_DIR . 'inc/class_form.php';
    if (file_exists($formPath)) {
        require_once $formPath;
    }
    if (!class_exists('FormContainer', false)) {
        $containerPath = MYBB_ADMIN_DIR . 'inc/class_form_container.php';
        if (file_exists($containerPath)) {
            require_once $containerPath;
        }
    }
}

class AF_Admin_AdvancedQuickReply
{
    public static function dispatch(): void
    {
        global $mybb, $lang, $page;

        self::load_lang();

        $do = (string)$mybb->get_input('do');
        if ($do === '') $do = AF_AQR_DO_LIST;

        $page->add_breadcrumb_item($lang->af_advancedquickreply_group ?? 'Advanced Quick Reply');

        switch ($do) {
            case AF_AQR_DO_ADD:
                self::page_add();
                return;

            case AF_AQR_DO_EDIT:
                self::page_edit();
                return;

            case AF_AQR_DO_DELETE:
                self::page_delete();
                return;

            case AF_AQR_DO_TOOLBAR:
                self::page_toolbar();
                return;

            case AF_AQR_DO_FONTS:
                self::page_fonts();
                return;

            case AF_AQR_DO_LIST:
            default:
                self::page_list();
                return;
        }
    }

    private static function load_lang(): void
    {
        global $lang;
        if (!is_object($lang)) return;
    }

    private static function base_url(string $do = AF_AQR_DO_LIST, array $extra = []): string
    {
        $url = self::base_url_raw($do, $extra);
        return str_replace('&', '&amp;', $url);
    }

    private static function base_url_raw(string $do = AF_AQR_DO_LIST, array $extra = []): string
    {
        $params = array_merge(
            [
                'module'  => 'advancedfunctionality',
                'af_view' => AF_AQR_ID,
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

        if ($db->table_exists(AF_AQR_TABLE)) return;

        $collation = $db->build_create_table_collation();
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AQR_TABLE . " (
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
        global $page;

        $sub_tabs = [
            'list' => [
                'title'       => 'Кнопки',
                'link'        => self::base_url(AF_AQR_DO_LIST),
                'description' => 'Список кастомных кнопок редактора.',
            ],
            'add' => [
                'title'       => 'Добавить кнопку',
                'link'        => self::base_url(AF_AQR_DO_ADD),
                'description' => 'Добавить новую кнопку.',
            ],
            'toolbar' => [
                'title'       => 'Тулбар',
                'link'        => self::base_url(AF_AQR_DO_TOOLBAR),
                'description' => 'Конструктор тулбара (секции, drag&drop, dropdown).',
            ],
            'fonts' => [
                'title'       => 'Загрузить шрифты',
                'link'        => self::base_url(AF_AQR_DO_FONTS),
                'description' => 'Загрузка файлов шрифтов в assets/fonts и управление списком.',
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

        $q = $db->simple_select(AF_AQR_TABLE, '*', '1=1', ['order_by' => 'disporder ASC, name ASC']);
        while ($row = $db->fetch_array($q)) {

            $icon = trim((string)$row['icon']);
            if ($icon === '') {
                $icon = '../inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/aqr-icon.svg';
            }

            $iconHtml = '<img src="' . htmlspecialchars_uni($icon) . '" alt="" style="width:18px;height:18px;vertical-align:middle;border-radius:4px;" />';
            $tags = htmlspecialchars_uni((string)$row['opentag']) . ' … ' . htmlspecialchars_uni((string)$row['closetag']);

            $active = ((int)$row['active'] === 1)
                ? '<span style="color:green;font-weight:700;">Yes</span>'
                : '<span style="color:#a00;font-weight:700;">No</span>';

            $controls =
                '<a href="' . self::base_url(AF_AQR_DO_EDIT, ['bid' => (int)$row['bid']]) . '">Edit</a>'
                . ' | '
                . '<a href="' . self::base_url(AF_AQR_DO_DELETE, ['bid' => (int)$row['bid']]) . '">Delete</a>';

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

        $table->output('Кастомные кнопки редактора');
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
            admin_redirect(self::base_url_raw(AF_AQR_DO_LIST));
        }

        $row = $db->fetch_array($db->simple_select(AF_AQR_TABLE, '*', "bid='{$bid}'", ['limit' => 1]));
        if (empty($row)) {
            flash_message('Кнопка не найдена', 'error');
            admin_redirect(self::base_url_raw(AF_AQR_DO_LIST));
        }

        if ($mybb->request_method === 'post') {
            self::save_button($bid);
            return;
        }

        $sub_tabs = [
            'list' => [
                'title' => 'Кнопки',
                'link'  => self::base_url(AF_AQR_DO_LIST),
            ],
            'edit' => [
                'title' => 'Редактировать',
                'link'  => self::base_url(AF_AQR_DO_EDIT, ['bid' => $bid]),
            ],
            'toolbar' => [
                'title' => 'Тулбар',
                'link'  => self::base_url(AF_AQR_DO_TOOLBAR),
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
            admin_redirect(self::base_url_raw(AF_AQR_DO_LIST));
        }

        if ($mybb->request_method === 'post' && !empty($mybb->input['confirm'])) {
            $db->delete_query(AF_AQR_TABLE, "bid='{$bid}'");
            flash_message('Удалено', 'success');
            admin_redirect(self::base_url_raw(AF_AQR_DO_LIST));
        }

        af_aqr_require_form_libs();

        $form = new Form(self::base_url_raw(AF_AQR_DO_DELETE, ['bid' => $bid]), 'post');

        echo '<div class="confirm_action">';
        echo '<p>Точно удалить эту кнопку?</p>';
        echo '<p class="buttons">';
        echo $form->generate_hidden_field('my_post_key', (string)($mybb->post_code ?? ''));
        echo $form->generate_hidden_field('confirm', '1');
        echo $form->generate_submit_button('Удалить', ['class' => 'button_yes']);
        echo ' ';
        echo '<a href="' . self::base_url(AF_AQR_DO_LIST) . '" class="button_no">Отмена</a>';
        echo '</p>';
        echo '</div>';

        $form->end();
    }

    private static function page_toolbar(): void
    {
        global $mybb, $db;

        self::ensure_table();
        self::output_tabs('toolbar');

        // В ACP post key уже подготовлен MyBB: это ИСТОЧНИК ИСТИНЫ
        $postCode = (string)($mybb->post_code ?? '');

        // save
        if ($mybb->request_method === 'post') {

            $postedKey = (string)$mybb->get_input('my_post_key');
            if ($postCode !== '' && $postedKey !== '' && !hash_equals($postCode, $postedKey)) {
                flash_message('Неверный my_post_key. Обнови страницу ACP и попробуй снова.', 'error');
                admin_redirect(self::base_url_raw(AF_AQR_DO_TOOLBAR));
            }

            $json = (string)($mybb->input['layout_json'] ?? '');
            $json = trim($json);

            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['sections']) || !is_array($data['sections'])) {
                flash_message('Layout JSON некорректный (sections пустой или не массив).', 'error');
                admin_redirect(self::base_url_raw(AF_AQR_DO_TOOLBAR));
            }

            $db->update_query('settings', [
                'value' => $db->escape_string($json),
            ], "name='af_advancedquickreply_toolbar_layout'");

            if (function_exists('rebuild_settings')) {
                rebuild_settings();
            } else {
                require_once MYBB_ROOT . 'inc/functions.php';
                rebuild_settings();
            }

            // IMPORTANT: на хостингах с OPcache settings.php может не обновляться мгновенно
            $settingsFile = MYBB_ROOT . 'inc/settings.php';
            @clearstatcache(true, $settingsFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($settingsFile, true);
            }

            flash_message('Тулбар сохранён', 'success');
            admin_redirect(self::base_url_raw(AF_AQR_DO_TOOLBAR));
        }

        $bburl = (string)($mybb->settings['bburl'] ?? '');
        $bburl = rtrim($bburl, '/');

        // Подбираем корректный SCEditor CSS, чтобы не ловить 404
        $sceditorCss = self::resolve_sceditor_css_url($bburl);

        // available buttons: стандартные + кастомные из таблицы (active=1)
        $available = self::get_available_buttons();

        /**
        * ВАЖНО: layout читаем ИЗ БД, а не из $mybb->settings.
        * Иначе после сохранения на хостингах с OPcache ты видишь старую раскладку,
        * пока settings.php не перечитается (и “помогает” только ручной refresh).
        */
        $layoutRaw = '';
        $q = $db->simple_select('settings', 'value', "name='af_advancedquickreply_toolbar_layout'", ['limit' => 1]);
        $layoutRawDb = $db->fetch_field($q, 'value');
        if (is_string($layoutRawDb)) {
            $layoutRaw = $layoutRawDb;
        } else {
            $layoutRaw = (string)($mybb->settings['af_advancedquickreply_toolbar_layout'] ?? '');
        }

        $layout = null;
        if (trim($layoutRaw) !== '') {
            $decoded = json_decode($layoutRaw, true);
            if (is_array($decoded)) $layout = $decoded;
        }

        $payload = [
            'available'   => $available,
            'layout'      => $layout,
            'sceditorCss' => $sceditorCss,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') $json = '{}';

        // Assets (CSS + SCEditor + наш JS)
        echo "\n" . '<link rel="stylesheet" href="' . htmlspecialchars_uni($sceditorCss) . '" />';
        echo "\n" . '<link rel="stylesheet" href="' . htmlspecialchars_uni($bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/advancedquickreply_admin_toolbar.css') . '" />';

        // В ACP jQuery уже есть. Подключаем только SCEditor.
        echo "\n" . '<script src="' . htmlspecialchars_uni($bburl . '/jscripts/sceditor/jquery.sceditor.min.js') . '"></script>';
        echo "\n" . '<script src="' . htmlspecialchars_uni($bburl . '/jscripts/sceditor/jquery.sceditor.bbcode.min.js') . '"></script>';

        echo "\n" . '<script>window.afAqrAdminToolbarPayload = ' . $json . ';</script>';

        $v = defined('TIME_NOW') ? (int)TIME_NOW : time();
        echo "\n" . '<script defer="defer" src="' . htmlspecialchars_uni($bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/advancedquickreply_admin_toolbar.js?v=' . $v) . '"></script>' . "\n";

        af_aqr_require_form_libs();
        $form = new Form(self::base_url_raw(AF_AQR_DO_TOOLBAR), 'post');

        echo '<div class="af-aqr-admin-grid">';

        // left: available + TRASH
        echo '<div class="af-aqr-admin-box">';
        echo '<div class="af-aqr-admin-hd">Доступные кнопки</div>';
        echo '<div class="af-aqr-admin-bd">';
        echo '<div class="af-aqr-btnlist"></div>';

        echo '<div class="smalltext" style="margin-top:10px;opacity:.85;">Перетаскивай кнопки в секции справа. Двойной клик по pill — удалить из секции. Drag внутри секции — перестановка.</div>';

        // ЯВНАЯ КОРЗИНА
        echo '<div class="af-aqr-trash" id="af_aqr_trash" title="Перетащи сюда кнопку из секций, чтобы убрать её из раскладки">';
        echo '  <div class="af-aqr-trash-ico" aria-hidden="true">🗑</div>';
        echo '  <div class="af-aqr-trash-txt">';
        echo '    <div><strong>Корзина</strong></div>';
        echo '    <div class="smalltext">Перетащи сюда pill из секций, чтобы удалить из раскладки.</div>';
        echo '  </div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        // right: sections + preview
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
        echo '<div class="af-aqr-toolbarstr" style="font-family:monospace;white-space:pre-wrap;background:#f7f7f7;border:1px solid #e6e6e6;border-radius:10px;padding:10px;margin:6px 0 10px 0;"></div>';
        echo '<textarea id="af_aqr_preview_ta"></textarea>';
        echo '</div>';

        echo '</div>'; // bd
        echo '</div>'; // box

        echo '</div>'; // grid

        $form->end();
    }

    private static function fonts_setting_name(): string
    {
        return 'af_advancedquickreply_fontfamily_json';
    }

    private static function fonts_dir_abs(): string
    {
        return MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/fonts/';
    }

    private static function fonts_ensure_dir(): bool
    {
        $dir = self::fonts_dir_abs();
        if (is_dir($dir)) return true;
        return @mkdir($dir, 0755, true);
    }

    private static function fonts_family_id(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('~\s+~u', ' ', $name);
        $id = strtolower($name);
        $id = preg_replace('~[^a-z0-9]+~i', '_', $id);
        $id = trim($id, '_');
        if ($id === '') $id = 'font_' . (string)time();
        return $id;
    }

    private static function fonts_allowed_ext(): array
    {
        // “везде”: woff2/woff + ttf/otf как подстраховка
        return ['woff2', 'woff', 'ttf', 'otf'];
    }

    /**
    * Читаем JSON настройки ПРЯМО ИЗ БД (источник истины),
    * чтобы не упираться в settings.php/opcache.
    */
    private static function fonts_load_from_db(): array
    {
        global $db;

        $name = $db->escape_string(self::fonts_setting_name());
        $q = $db->simple_select('settings', 'value', "name='{$name}'", ['limit' => 1]);
        $raw = (string)$db->fetch_field($q, 'value');
        $raw = trim($raw);

        if ($raw === '') return ['v' => 1, 'families' => []];

        $data = json_decode($raw, true);
        if (!is_array($data)) return ['v' => 1, 'families' => []];

        if (empty($data['v'])) $data['v'] = 1;
        if (empty($data['families']) || !is_array($data['families'])) $data['families'] = [];

        // легкая нормализация
        $out = ['v' => 1, 'families' => []];

        foreach ($data['families'] as $f) {
            if (!is_array($f)) continue;

            $id = trim((string)($f['id'] ?? ''));
            $nm = trim((string)($f['name'] ?? ''));
            if ($nm === '') continue;
            if ($id === '') $id = self::fonts_family_id($nm);

            $sys = !empty($f['system']) ? 1 : 0;

            $files = [];
            if (!empty($f['files']) && is_array($f['files'])) {
                foreach (self::fonts_allowed_ext() as $ext) {
                    $val = trim((string)($f['files'][$ext] ?? ''));
                    if ($val !== '') $files[$ext] = $val;
                }
            }

            $out['families'][] = [
                'id'     => $id,
                'name'   => $nm,
                'system' => $sys,
                'files'  => $files,
            ];
        }

        return $out;
    }

    private static function fonts_save_to_db(array $data): void
    {
        global $db;

        if (empty($data['v'])) $data['v'] = 1;
        if (empty($data['families']) || !is_array($data['families'])) $data['families'] = [];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) $json = '{"v":1,"families":[]}';

        $nameEsc = $db->escape_string(self::fonts_setting_name());
        $db->update_query('settings', ['value' => $db->escape_string($json)], "name='{$nameEsc}'");

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
    }

    private static function page_fonts(): void
    {
        global $mybb, $db;

        self::output_tabs('fonts');

        $postCode = (string)($mybb->post_code ?? '');

        // действия
        $action = (string)($mybb->input['action'] ?? '');

        if ($mybb->request_method === 'post') {
            $postedKey = (string)$mybb->get_input('my_post_key');
            if ($postCode !== '' && $postedKey !== '' && !hash_equals($postCode, $postedKey)) {
                flash_message('Неверный my_post_key. Обнови страницу ACP и попробуй снова.', 'error');
                admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
            }

            // 1) загрузка файла
            if ($action === 'upload') {
                if (!self::fonts_ensure_dir()) {
                    flash_message('Не удалось создать папку assets/fonts. Проверь права на запись.', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                $familyName = trim((string)($mybb->input['family_name'] ?? ''));
                if ($familyName === '') {
                    flash_message('Укажи название семейства (Family name).', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                if (empty($_FILES['font_file']) || !is_array($_FILES['font_file'])) {
                    flash_message('Файл не выбран.', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                $file = $_FILES['font_file'];

                if (!empty($file['error'])) {
                    flash_message('Ошибка загрузки файла (код: ' . (int)$file['error'] . ').', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                $origName = (string)($file['name'] ?? '');
                $tmpName  = (string)($file['tmp_name'] ?? '');

                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (!in_array($ext, self::fonts_allowed_ext(), true)) {
                    flash_message('Недопустимый формат. Разрешено: ' . implode(', ', self::fonts_allowed_ext()) . '.', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    flash_message('Файл не распознан как загруженный через HTTP POST.', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                $fid = self::fonts_family_id($familyName);
                $safeFile = $fid . '.' . $ext;

                $destDir = self::fonts_dir_abs();
                $destAbs = $destDir . $safeFile;

                // перезапись допустима (обновление формата)
                if (!@move_uploaded_file($tmpName, $destAbs)) {
                    flash_message('Не удалось переместить файл в assets/fonts. Проверь права на запись.', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                @chmod($destAbs, 0644);

                $data = self::fonts_load_from_db();

                // найти/создать семейство
                $found = false;
                foreach ($data['families'] as &$f) {
                    if (!is_array($f)) continue;
                    if ((string)($f['id'] ?? '') === $fid) {
                        $f['name'] = $familyName;
                        if (empty($f['files']) || !is_array($f['files'])) $f['files'] = [];
                        $f['files'][$ext] = $safeFile;
                        $f['system'] = 0;
                        $found = true;
                        break;
                    }
                }
                unset($f);

                if (!$found) {
                    $data['families'][] = [
                        'id'     => $fid,
                        'name'   => $familyName,
                        'system' => 0,
                        'files'  => [
                            $ext => $safeFile,
                        ],
                    ];
                }

                self::fonts_save_to_db($data);

                flash_message('Шрифт загружен: ' . htmlspecialchars_uni($familyName) . ' (' . $ext . ')', 'success');
                admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
            }

            // 2) удалить семейство
            if ($action === 'delete_family') {
                $fid = trim((string)($mybb->input['fid'] ?? ''));
                if ($fid === '') {
                    flash_message('Некорректный fid.', 'error');
                    admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
                }

                $data = self::fonts_load_from_db();

                $new = [];
                $deletedFiles = [];

                foreach ($data['families'] as $f) {
                    if (!is_array($f)) continue;

                    if ((string)($f['id'] ?? '') === $fid) {
                        // удаляем файлы только для не-system
                        if (empty($f['system']) && !empty($f['files']) && is_array($f['files'])) {
                            foreach ($f['files'] as $fn) {
                                $fn = trim((string)$fn);
                                if ($fn !== '') $deletedFiles[] = $fn;
                            }
                        }
                        continue;
                    }
                    $new[] = $f;
                }

                $data['families'] = $new;
                self::fonts_save_to_db($data);

                // чистим файлы на диске
                $dir = self::fonts_dir_abs();
                foreach ($deletedFiles as $fn) {
                    $abs = $dir . basename($fn);
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }

                flash_message('Семейство удалено.', 'success');
                admin_redirect(self::base_url_raw(AF_AQR_DO_FONTS));
            }
        }

        // Рендер страницы
        af_aqr_require_form_libs();

        $data = self::fonts_load_from_db();
        $families = is_array($data['families']) ? $data['families'] : [];

        echo '<div class="table_border">';
        echo '<div class="table_heading">Загрузка шрифтов</div>';
        echo '<div style="padding:10px;">';
        echo '<div class="smalltext" style="margin-bottom:10px;opacity:.9;">'
            . 'Поддерживаемые форматы: <strong>woff2</strong>, <strong>woff</strong>, <strong>ttf</strong>, <strong>otf</strong>. '
            . 'Рекомендуется грузить минимум <strong>woff2</strong> + <strong>woff</strong> для лучшей совместимости.'
            . '</div>';

        $form = new Form(self::base_url_raw(AF_AQR_DO_FONTS), 'post', '', true);
        echo $form->generate_hidden_field('my_post_key', $postCode);
        echo $form->generate_hidden_field('action', 'upload');

        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';

        echo '<div style="min-width:260px;">';
        echo '<label><strong>Family name</strong></label><br />';
        echo $form->generate_text_box('family_name', '', ['style' => 'width:260px;', 'placeholder' => 'Например: Inter']);
        echo '</div>';

        echo '<div style="min-width:320px;">';
        echo '<label><strong>Файл шрифта</strong></label><br />';
        echo '<input type="file" name="font_file" accept=".woff2,.woff,.ttf,.otf" />';
        echo '</div>';

        echo '<div>';
        echo $form->generate_submit_button('Загрузить', ['class' => 'button']);
        echo '</div>';

        echo '</div>';

        $form->end();

        echo '</div>';
        echo '</div>';

        // список
        require_once MYBB_ADMIN_DIR . 'inc/class_table.php';

        $table = new Table;
        $table->construct_header('ID', ['width' => '16%']);
        $table->construct_header('Family', ['width' => '28%']);
        $table->construct_header('Files');
        $table->construct_header('Controls', ['width' => '14%']);

        foreach ($families as $f) {
            if (!is_array($f)) continue;

            $fid = htmlspecialchars_uni((string)($f['id'] ?? ''));
            $name = htmlspecialchars_uni((string)($f['name'] ?? ''));
            $sys = !empty($f['system']);

            $filesHtml = '';
            if ($sys) {
                $filesHtml = '<span class="smalltext" style="opacity:.8;">system</span>';
            } else {
                $parts = [];
                $files = (!empty($f['files']) && is_array($f['files'])) ? $f['files'] : [];
                foreach (self::fonts_allowed_ext() as $ext) {
                    $fn = trim((string)($files[$ext] ?? ''));
                    if ($fn !== '') {
                        $parts[] = '<strong>' . htmlspecialchars_uni($ext) . '</strong>: ' . htmlspecialchars_uni($fn);
                    }
                }
                $filesHtml = $parts ? implode('<br />', $parts) : '<span class="smalltext" style="opacity:.8;">(нет файлов)</span>';
            }

            // delete form
            $delForm = new Form(self::base_url_raw(AF_AQR_DO_FONTS), 'post', '', true);
            $delHtml  = $delForm->generate_hidden_field('my_post_key', $postCode);
            $delHtml .= $delForm->generate_hidden_field('action', 'delete_family');
            $delHtml .= $delForm->generate_hidden_field('fid', (string)($f['id'] ?? ''));
            $delHtml .= $delForm->generate_submit_button('Удалить', ['class' => 'button', 'onclick' => "return confirm('Удалить семейство и файлы?');"]);
            $delForm->end();

            $table->construct_cell($fid);
            $table->construct_cell($name);
            $table->construct_cell($filesHtml);
            $table->construct_cell($delHtml);

            $table->construct_row();
        }

        if ($table->num_rows() === 0) {
            $table->construct_cell('Пока нет записей (в настройке).', ['colspan' => 4]);
            $table->construct_row();
        }

        $table->output('Список шрифтов (font-family)');
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
            if (file_exists($fs)) {
                return $bburl . $rel;
            }
        }

        // Если вообще ничего не нашли — вернём то, что ты ожидала (и пусть 404 будет явным)
        return $bburl . '/jscripts/sceditor/themes/default.min.css';
    }

    private static function get_available_buttons(): array
    {
        global $db, $mybb;

        $std = [
            ['cmd' => 'bold', 'label' => 'B', 'hint' => 'SCEditor: bold'],
            ['cmd' => 'italic', 'label' => 'I', 'hint' => 'SCEditor: italic'],
            ['cmd' => 'underline', 'label' => 'U', 'hint' => 'SCEditor: underline'],
            ['cmd' => 'strike', 'label' => 'S', 'hint' => 'SCEditor: strike'],
            ['cmd' => 'subscript', 'label' => 'x₂', 'hint' => 'SCEditor: subscript'],
            ['cmd' => 'superscript', 'label' => 'x²', 'hint' => 'SCEditor: superscript'],

            ['cmd' => 'font', 'label' => 'F', 'hint' => 'SCEditor: font'],
            ['cmd' => 'size', 'label' => 'Sz', 'hint' => 'SCEditor: size'],
            ['cmd' => 'color', 'label' => 'C', 'hint' => 'SCEditor: color'],
            ['cmd' => 'removeformat', 'label' => '×', 'hint' => 'SCEditor: removeformat'],

            ['cmd' => 'undo', 'label' => '↶', 'hint' => 'SCEditor: undo'],
            ['cmd' => 'redo', 'label' => '↷', 'hint' => 'SCEditor: redo'],
            ['cmd' => 'pastetext', 'label' => 'Tx', 'hint' => 'SCEditor: pastetext'],
            ['cmd' => 'horizontalrule', 'label' => '—', 'hint' => 'SCEditor: horizontalrule'],

            ['cmd' => 'left', 'label' => 'L', 'hint' => 'SCEditor: left'],
            ['cmd' => 'center', 'label' => 'C', 'hint' => 'SCEditor: center'],
            ['cmd' => 'right', 'label' => 'R', 'hint' => 'SCEditor: right'],
            ['cmd' => 'justify', 'label' => 'J', 'hint' => 'SCEditor: justify'],

            ['cmd' => 'bulletlist', 'label' => '•', 'hint' => 'SCEditor: bulletlist'],
            ['cmd' => 'orderedlist', 'label' => '1.', 'hint' => 'SCEditor: orderedlist'],

            ['cmd' => 'quote', 'label' => '❝', 'hint' => 'SCEditor: quote'],
            ['cmd' => 'code', 'label' => '</>', 'hint' => 'SCEditor: code'],

            ['cmd' => 'image', 'label' => '🖼', 'hint' => 'SCEditor: image'],
            ['cmd' => 'link', 'label' => '🔗', 'hint' => 'SCEditor: link'],
            ['cmd' => 'unlink', 'label' => '⛓', 'hint' => 'SCEditor: unlink'],
            ['cmd' => 'email', 'label' => '@', 'hint' => 'SCEditor: email'],
            ['cmd' => 'youtube', 'label' => '▶', 'hint' => 'SCEditor: youtube'],
            ['cmd' => 'emoticon', 'label' => '☺', 'hint' => 'SCEditor: emoticon'],

            ['cmd' => 'source', 'label' => '{ }', 'hint' => 'SCEditor: source'],
            ['cmd' => 'maximize', 'label' => '⤢', 'hint' => 'SCEditor: maximize'],

            ['cmd' => '|', 'label' => '|', 'hint' => 'Разделитель группы'],
        ];

        $bburl = (string)($mybb->settings['bburl'] ?? '');
        $bburl = rtrim($bburl, '/');

        // BUILT-IN pack (предустановки плагина, не из БД)
        $builtins = af_aqr_admin_builtin_buttons($bburl);


        $custom = [];
        if ($db->table_exists(AF_AQR_TABLE)) {
            $q = $db->simple_select(AF_AQR_TABLE, '*', "active=1", ['order_by' => 'disporder ASC, name ASC']);
            while ($r = $db->fetch_array($q)) {
                $name = (string)$r['name'];
                if ($name === '') continue;

                $icon = trim((string)$r['icon']);
                if ($icon === '') {
                    $icon = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/aqr-icon.svg';
                }

                $custom[] = [
                    'cmd'   => 'af_' . $name,
                    'label' => 'AF',
                    'hint'  => (string)$r['title'],
                    'icon'  => $icon,
                ];
            }
        }

        return array_merge($std, $builtins, $custom);
    }

    private static function render_form(string $mode, array $row, int $bid): void
    {
        global $mybb;

        af_aqr_require_form_libs();

        $action = ($mode === 'edit' && $bid > 0)
            ? self::base_url_raw(AF_AQR_DO_EDIT, ['bid' => $bid])
            : self::base_url_raw(AF_AQR_DO_ADD);

        $form = new Form($action, 'post');

        // КРИТИЧНО ДЛЯ ACP: иначе MyBB отрежет POST до выполнения контроллера
        echo $form->generate_hidden_field('my_post_key', (string)($mybb->post_code ?? ''));

        if (class_exists('FormContainer', false)) {
            $container = new FormContainer('Параметры кнопки');
        } else {
            $container = new AF_AQR_FormContainerShim('Параметры кнопки');
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
            admin_redirect($bid > 0
                ? self::base_url_raw(AF_AQR_DO_EDIT, ['bid' => $bid])
                : self::base_url_raw(AF_AQR_DO_ADD)
            );
        }

        if ($title === '') {
            flash_message('Title обязателен', 'error');
            admin_redirect($bid > 0
                ? self::base_url_raw(AF_AQR_DO_EDIT, ['bid' => $bid])
                : self::base_url_raw(AF_AQR_DO_ADD)
            );
        }

        if ($opentag === '') {
            flash_message('Open tag обязателен', 'error');
            admin_redirect($bid > 0
                ? self::base_url_raw(AF_AQR_DO_EDIT, ['bid' => $bid])
                : self::base_url_raw(AF_AQR_DO_ADD)
            );
        }

        $where = "name='" . $db->escape_string($name) . "'";
        if ($bid > 0) $where .= " AND bid!='{$bid}'";

        $exists = (int)$db->fetch_field($db->simple_select(AF_AQR_TABLE, 'COUNT(*) AS c', $where), 'c');
        if ($exists > 0) {
            flash_message('Name уже занят', 'error');
            admin_redirect($bid > 0
                ? self::base_url_raw(AF_AQR_DO_EDIT, ['bid' => $bid])
                : self::base_url_raw(AF_AQR_DO_ADD)
            );
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
            $db->update_query(AF_AQR_TABLE, $data, "bid='{$bid}'");
            flash_message('Сохранено', 'success');
            admin_redirect(self::base_url_raw(AF_AQR_DO_EDIT, ['bid' => $bid]));
        } else {
            $newId = (int)$db->insert_query(AF_AQR_TABLE, $data);
            flash_message('Добавлено', 'success');
            admin_redirect(self::base_url_raw(AF_AQR_DO_EDIT, ['bid' => $newId]));
        }
    }
}
