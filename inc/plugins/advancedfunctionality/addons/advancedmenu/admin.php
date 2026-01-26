<?php
/**
 * ACP controller for AdvancedMenu (AF router)
 *
 * IMPORTANT (canon like AdvancedThreadFields):
 * - AF router выбирает аддон через af_view=...
 * - Параметр action зарезервирован под действия самого роутера.
 * - Внутренние действия аддона ведём через do=list/add/edit/delete/toggle/settings
 * - AF router сам делает output_header/output_footer — здесь не дублируем.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

// bootstrap аддона (константы таблиц, helpers)
$bootstrap = AF_ADDONS.'advancedmenu/advancedmenu.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
}

class AF_Admin_Advancedmenu
{
    private const ROUTER_MODULE = 'advancedfunctionality';
    private const ROUTER_VIEW   = 'advancedmenu';
    private const LANG_FILE     = 'advancedfunctionality_advancedmenu';

    private static function url(array $params = []): string
    {
        // Канон AF: аддон открываем через af_view
        $base = [
            'module'  => self::ROUTER_MODULE,
            'af_view' => self::ROUTER_VIEW,
        ];

        $all = array_merge($base, $params);

        // Никогда не даём пролезть action — он у роутера зарезервирован
        unset($all['action']);

        return 'index.php?'.http_build_query($all, '', '&');
    }

    private static function go(array $params = []): void
    {
        admin_redirect(self::url($params));
    }

    /**
     * Подгрузка языков (только load).
     * Канон AF: языки уже лежат в /inc/languages/<lang>/ (front/admin).
     */
    private static function load_lang(): void
    {
        global $lang;

        if (function_exists('af_load_addon_lang')) {
            af_load_addon_lang(self::ROUTER_VIEW, true);  // admin
            af_load_addon_lang(self::ROUTER_VIEW, false); // front (на всякий)
            return;
        }

        if (!is_object($lang)) {
            if (class_exists('MyLanguage')) {
                $lang = new MyLanguage();
            } else {
                return;
            }
        }

        if (method_exists($lang, 'load')) {
            $lang->load(self::LANG_FILE, true);
            $lang->load(self::LANG_FILE, false);
        }
    }

    public static function dispatch(): void
    {
        global $mybb, $page;

        // ensure/install (идемпотентно)
        if (function_exists('af_advancedmenu_ensure_installed')) {
            af_advancedmenu_ensure_installed();
        }

        self::load_lang();

        $do  = (string)$mybb->get_input('do');
        $loc = (string)$mybb->get_input('loc');
        $loc = ($loc === 'panel') ? 'panel' : 'top';

        // breadcrumb
        if (is_object($page) && method_exists($page, 'add_breadcrumb_item')) {
            $page->add_breadcrumb_item('AdvancedMenu', self::url(['loc' => $loc]));
        }

        switch ($do) {
            case 'add':
                self::page_form($loc, 0);
                return;

            case 'edit':
                self::page_form($loc, (int)$mybb->get_input('id', MyBB::INPUT_INT));
                return;

            case 'delete':
                self::page_delete($loc, (int)$mybb->get_input('id', MyBB::INPUT_INT));
                return;

            case 'toggle':
                self::do_toggle($loc, (int)$mybb->get_input('id', MyBB::INPUT_INT));
                return;

            case 'settings':
                self::page_settings($loc);
                return;

            case 'list':
            default:
                self::page_list($loc);
                return;
        }
    }

    private static function output_iconpicker_assets(): void
    {
        global $mybb, $page;

        $bburl = isset($mybb->settings['bburl']) ? rtrim((string)$mybb->settings['bburl'], '/') : '';
        if ($bburl === '') {
            return;
        }

        // Font Awesome CSS (для превью и чтобы JS мог распарсить список иконок)
        $faCss = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';

        $base = $bburl . '/inc/plugins/advancedfunctionality/addons/advancedmenu/assets';

        // ACP ассеты
        $css = $base . '/advancedmenu_admin.css?v=1';
        $js  = $base . '/advancedmenu_admin.js?v=1';

        $out  = "\n" . '<!-- AF AdvancedMenu ACP IconPicker assets -->' . "\n";
        $out .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($faCss) . '" />' . "\n";
        $out .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($css) . '" />' . "\n";
        $out .= '<script type="text/javascript">' . "\n";
        $out .= 'window.afAdvancedMenuIconPickerConfig = window.afAdvancedMenuIconPickerConfig || {};' . "\n";
        $out .= 'window.afAdvancedMenuIconPickerConfig.cssUrl = ' . json_encode($faCss) . ';' . "\n";
        $out .= '</script>' . "\n";
        $out .= '<script type="text/javascript" src="' . htmlspecialchars_uni($js) . '" defer="defer"></script>' . "\n";

        // 1) Пытаемся положить в HEAD (если роутер ещё не вывел header — будет идеально)
        if (is_object($page) && property_exists($page, 'extra_header')) {
            $page->extra_header .= $out;
        }

        // 2) ФОЛБЭК: выводим прямо сейчас в BODY.
        // Это спасает кейс, когда output_header уже произошёл ДО page_form().
        // Дубликаты не страшны: JS защищён window.__afAdvancedMenuAdminLoaded.
        echo $out;
    }

    /* ----------------------------- LIST ----------------------------- */

    private static function page_list(string $loc): void
    {
        global $db, $page;

        $active = ($loc === 'panel') ? 'panel' : 'top';

        $tabs = [
            'top' => [
                'title' => 'Верхнее меню (top_links)',
                'link'  => self::url(['loc' => 'top', 'do' => 'list']),
                'description' => 'Пункты для <ul class="menu top_links"> в header.',
            ],
            'panel' => [
                'title' => 'Юзерское меню (panel_links)',
                'link'  => self::url(['loc' => 'panel', 'do' => 'list']),
                'description' => 'Пункты для <ul class="menu panel_links"> в header_welcomeblock_member.',
            ],
            'settings' => [
                'title' => 'Настройки',
                'link'  => self::url(['loc' => $active, 'do' => 'settings']),
                'description' => 'Режимы append/replace и паттерны скрытия/защиты.',
            ],
        ];

        if (is_object($page) && method_exists($page, 'output_nav_tabs')) {
            $page->output_nav_tabs($tabs, $active);
        }

        echo '<div style="margin: 10px 0;">';
        echo '<a class="button button_primary" href="'.htmlspecialchars_uni(self::url(['do' => 'add', 'loc' => $active])).'">+ Добавить пункт</a>';
        echo '</div>';

        require_once MYBB_ADMIN_DIR.'inc/class_table.php';

        $table = new Table;
        $table->construct_header('ID', ['width' => '60px']);
        $table->construct_header('Slug', ['width' => '180px']);
        $table->construct_header('Название');
        $table->construct_header('Ссылка');
        $table->construct_header('Порядок', ['width' => '80px']);
        $table->construct_header('Enabled', ['width' => '80px']);
        $table->construct_header('Действия', ['width' => '260px']);

        $q = $db->simple_select(
            AF_AM_TABLE_ITEMS,
            '*',
            "location='".$db->escape_string($active)."'",
            ['order_by' => 'sort_order, id', 'order_dir' => 'ASC']
        );

        $rows = 0;
        while ($row = $db->fetch_array($q)) {
            $rows++;
            $id = (int)$row['id'];

            $slug  = htmlspecialchars_uni((string)$row['slug']);
            $title = htmlspecialchars_uni((string)$row['title']);
            $url   = htmlspecialchars_uni((string)$row['url']);

            $enabled = ((int)$row['enabled'] === 1);
            $enabledHtml = $enabled
                ? '<span style="color:#0a0; font-weight:700;">Да</span>'
                : '<span style="color:#a00; font-weight:700;">Нет</span>';

            $actions = [];
            $actions[] = '<a class="button button_small" href="'.htmlspecialchars_uni(self::url(['do' => 'edit', 'loc' => $active, 'id' => $id])).'">Редактировать</a>';
            $actions[] = '<a class="button button_small" href="'.htmlspecialchars_uni(self::url(['do' => 'toggle', 'loc' => $active, 'id' => $id])).'">'.($enabled ? 'Выключить' : 'Включить').'</a>';
            $actions[] = '<a class="button button_small button_danger" href="'.htmlspecialchars_uni(self::url(['do' => 'delete', 'loc' => $active, 'id' => $id])).'">Удалить</a>';

            $table->construct_cell($id);
            $table->construct_cell($slug);
            $table->construct_cell($title);
            $table->construct_cell($url);
            $table->construct_cell((int)$row['sort_order']);
            $table->construct_cell($enabledHtml);
            $table->construct_cell(implode(' ', $actions));
            $table->construct_row();
        }

        if ($rows === 0) {
            $table->construct_cell('<em>Пока нет пунктов. Добавь первый.</em>', ['colspan' => 7]);
            $table->construct_row();
        }

        $table->output($active === 'top' ? 'Пункты верхнего меню' : 'Пункты юзерского меню');
    }

    /* ----------------------------- ADD/EDIT ----------------------------- */
    private static function page_form(string $loc, int $id): void
    {
        global $mybb, $db;

        require_once MYBB_ADMIN_DIR.'inc/class_form.php';

        $loc = ($loc === 'panel') ? 'panel' : 'top';
        $isEdit = ($id > 0);

        // дефолт: в panel обычно не надо показывать гостям
        $defaultVis = ($loc === 'panel') ? 'users' : '';

        $data = [
            'slug'       => '',
            'title'      => '',
            'url'        => '',
            'icon'       => '',
            'hint'       => '',
            'sort_order' => 10,
            'enabled'    => 1,
            'visibility' => $defaultVis,
        ];


        if ($isEdit) {
            $row = $db->fetch_array($db->simple_select(AF_AM_TABLE_ITEMS, '*', "id='".(int)$id."'", ['limit' => 1]));
            if (!$row) {
                self::simple_error('Пункт не найден.');
                return;
            }
            $loc  = ($row['location'] === 'panel') ? 'panel' : 'top';
            $data = array_merge($data, $row);
        }

        // разобрать visibility для UI
        $visMode = 'all';
        $visGroups = '';

        $visRaw = trim((string)$data['visibility']);
        if ($visRaw === '' || strcasecmp($visRaw, 'all') === 0) {
            $visMode = 'all';
        } elseif (strcasecmp($visRaw, 'guests') === 0 || strcasecmp($visRaw, 'guest') === 0) {
            $visMode = 'guests';
        } elseif (strcasecmp($visRaw, 'users') === 0 || strcasecmp($visRaw, 'user') === 0 || strcasecmp($visRaw, 'members') === 0) {
            $visMode = 'users';
        } elseif (stripos($visRaw, 'groups:') === 0) {
            $visMode = 'groups';
            $visGroups = trim(substr($visRaw, 7));
        } else {
            // неизвестное — покажем как groups, чтобы админ мог поправить
            $visMode = 'groups';
            $visGroups = $visRaw;
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $slug    = trim((string)$mybb->get_input('slug'));
            $title   = trim((string)$mybb->get_input('title'));
            $url     = trim((string)$mybb->get_input('url'));
            $icon    = trim((string)$mybb->get_input('icon'));
            $hint    = trim((string)$mybb->get_input('hint'));
            $sort    = (int)$mybb->get_input('sort_order', MyBB::INPUT_INT);
            $enabled = ((int)$mybb->get_input('enabled', MyBB::INPUT_INT) === 1) ? 1 : 0;

            $visModePost   = trim((string)$mybb->get_input('visibility_mode'));
            $visGroupsPost = trim((string)$mybb->get_input('visibility_groups'));

            if (!preg_match('~^[a-z][a-z0-9_]{2,63}$~', $slug)) {
                self::simple_error('Slug должен быть: латиница/цифры/_, начинаться с буквы, длина 3–64.');
                return;
            }
            if ($title === '') {
                self::simple_error('Название не может быть пустым.');
                return;
            }
            if ($url === '') {
                self::simple_error('Ссылка не может быть пустой.');
                return;
            }
            if ($sort < 0) { $sort = 0; }

            // icon: лёгкая защита (нам не нужен <script> в этом поле)
            // мы ожидаем: классы FA / URL картинки / текст (эмодзи)
            // если админ всё же вставил HTML — оставим как legacy, но аккуратно:
            if ($icon !== '') {
                $iconTrim = $icon;
                if (strpos($iconTrim, '<') !== false || strpos($iconTrim, '>') !== false) {
                    // legacy HTML — сохраняем как есть, но режем самые опасные куски
                    if (function_exists('af_advancedmenu_sanitize_icon_html')) {
                        $iconTrim = af_advancedmenu_sanitize_icon_html($iconTrim);
                    }
                } else {
                    // простая нормализация пробелов
                    $iconTrim = preg_replace('~\s+~', ' ', $iconTrim);
                    $iconTrim = trim((string)$iconTrim);
                }
                $icon = $iconTrim;
            }
            // hint: только текст, без HTML
            if ($hint !== '') {
                // вырубаем теги, сжимаем пробелы
                $hint = strip_tags($hint);
                $hint = preg_replace('~\s+~u', ' ', $hint);
                $hint = trim((string)$hint);

                // ограничим длину под varchar(255)
                if (function_exists('my_substr')) {
                    $hint = my_substr($hint, 0, 255);
                } else {
                    $hint = mb_substr($hint, 0, 255, 'UTF-8');
                }
            }

            // visibility сборка
            $visibility = '';
            if ($visModePost === 'guests') {
                $visibility = 'guests';
            } elseif ($visModePost === 'users') {
                $visibility = 'users';
            } elseif ($visModePost === 'groups') {
                // нормализуем список gid
                $gids = [];
                foreach (explode(',', $visGroupsPost) as $g) {
                    $g = (int)trim($g);
                    if ($g > 0) {
                        $gids[] = $g;
                    }
                }
                $gids = array_values(array_unique($gids));
                $visibility = 'groups:'.implode(',', $gids);
            } else {
                $visibility = ''; // all
            }

            $where = "location='".$db->escape_string($loc)."' AND slug='".$db->escape_string($slug)."'";
            if ($isEdit) {
                $where .= " AND id!='".(int)$id."'";
            }
            $exists = (int)$db->fetch_field($db->simple_select(AF_AM_TABLE_ITEMS, 'id', $where), 'id');
            if ($exists > 0) {
                self::simple_error('Такой slug уже существует в этом меню.');
                return;
            }

            $save = [
                'location'   => $loc,
                'slug'       => $db->escape_string($slug),
                'title'      => $db->escape_string($title),
                'url'        => $db->escape_string($url),
                'icon'       => $db->escape_string($icon),
                'hint'       => $db->escape_string($hint),
                'visibility' => $db->escape_string($visibility),
                'sort_order' => $sort,
                'enabled'    => $enabled,
                'updated_at' => TIME_NOW,
            ];

            if ($isEdit) {
                $db->update_query(AF_AM_TABLE_ITEMS, $save, "id='".(int)$id."'");
            } else {
                $save['created_at'] = TIME_NOW;
                $db->insert_query(AF_AM_TABLE_ITEMS, $save);
            }

            if (function_exists('af_advancedmenu_rebuild_cache')) {
                af_advancedmenu_rebuild_cache();
            }

            admin_redirect(self::url(['loc' => $loc, 'do' => 'list']), 'Сохранено.');
            return;
        }

        // ассеты пикера (FA + наши CSS/JS)
        self::output_iconpicker_assets();

        echo '<div style="margin: 10px 0;">';
        echo '<a class="button" href="'.htmlspecialchars_uni(self::url(['loc' => $loc, 'do' => 'list'])).'">← Назад к списку</a>';
        echo '</div>';

        $formAction = self::url([
            'do'  => $isEdit ? 'edit' : 'add',
            'loc' => $loc,
            'id'  => $isEdit ? $id : null
        ]);

        $form = new Form($formAction, 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        $container = new FormContainer($isEdit ? 'Редактировать пункт' : 'Добавить пункт');

        $container->output_row(
            'Slug (техническое имя)',
            'Например: <code>mainpage</code>. Будет доступен как {$menu_mainpage} или {$panel_mainpage}.',
            $form->generate_text_box('slug', htmlspecialchars_uni((string)$data['slug']), ['style' => 'width: 320px;']),
            'slug'
        );

        $container->output_row(
            'Название',
            'Отображаемый текст ссылки.',
            $form->generate_text_box('title', htmlspecialchars_uni((string)$data['title']), ['style' => 'width: 520px;']),
            'title'
        );

        $container->output_row(
            'Ссылка',
            'Можно абсолютную или относительную (например <code>index.php</code> или <code>/</code>).',
            $form->generate_text_box('url', htmlspecialchars_uni((string)$data['url']), ['style' => 'width: 520px;']),
            'url'
        );
        
        $container->output_row(
            'Подсказка (tooltip)',
            'Появится при наведении на пункт меню. Только текст, без HTML.',
            $form->generate_text_box('hint', htmlspecialchars_uni((string)($data['hint'] ?? '')), ['style' => 'width: 520px;', 'placeholder' => 'Например: Перейти к профилю']),
            'hint'
        );
        

        // Иконка: инпут + кнопка пикера + превью (JS сам подцепит по data-атрибутам)
        $iconVal = (string)($data['icon'] ?? '');
        $iconInput = $form->generate_text_box('icon', htmlspecialchars_uni($iconVal), [
            'style' => 'width: 420px;',
            'id' => 'af-am-icon-input',
            'data-af-am-icon-input' => '1',
            'placeholder' => 'Например: fa-solid fa-house (или URL / эмодзи)',
        ]);

        $iconUi = ''
            .'<div class="af-am-iconrow">'
            .'  <div class="af-am-iconleft">'.$iconInput.'</div>'
            .'  <div class="af-am-iconright">'
            .'    <button type="button" class="button af-am-iconpick-btn" data-af-am-iconpick="1">Выбрать иконку</button>'
            .'    <button type="button" class="button af-am-iconclear-btn" data-af-am-iconclear="1" title="Очистить">×</button>'
            .'    <span class="af-am-iconpreview" data-af-am-iconpreview="1" aria-hidden="true"></span>'
            .'  </div>'
            .'</div>'
            .'<div class="af-am-iconhint">'
            .'  <div><strong>Форматы:</strong></div>'
            .'  <ul style="margin:6px 0 0 18px;">'
            .'    <li><code>fa-solid fa-house</code> / <code>fa-regular fa-user</code> / <code>fa-brands fa-discord</code></li>'
            .'    <li>URL картинки: <code>/images/icon.svg</code> или <code>https://site/icon.png</code></li>'
            .'    <li>Текст/эмодзи: <code>🔔</code></li>'
            .'  </ul>'
            .'</div>';

        $container->output_row(
            'Иконка (опционально)',
            'Нормальный пикер для FontAwesome + поддержка URL/эмодзи.',
            $iconUi,
            'icon'
        );

        $visSelectOptions = [
            'all'    => 'Всем (гости + пользователи)',
            'guests' => 'Только гостям',
            'users'  => 'Только авторизованным',
            'groups' => 'Только группам (ID ниже)',
        ];

        $container->output_row(
            'Видимость',
            'Гости — это uid=0. “Группы” — перечисли ID групп через запятую.',
            $form->generate_select_box('visibility_mode', $visSelectOptions, $visMode, ['style' => 'width: 360px;']),
            'visibility_mode'
        );

        $container->output_row(
            'Видимость: группы (ID через запятую)',
            'Используется только если “Видимость” = “Только группам”. Пример: <code>4,6</code>.',
            $form->generate_text_box('visibility_groups', htmlspecialchars_uni($visGroups), ['style' => 'width: 240px;']),
            'visibility_groups'
        );

        $container->output_row(
            'Порядок',
            'Чем меньше — тем выше в списке.',
            $form->generate_numeric_field('sort_order', (int)$data['sort_order'], ['style' => 'width: 120px;']),
            'sort_order'
        );

        $container->output_row(
            'Включён',
            '',
            $form->generate_yes_no_radio('enabled', (int)$data['enabled'], true),
            'enabled'
        );

        $container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button('Сохранить');
        $buttons[] = $form->generate_reset_button('Сбросить');

        $form->output_submit_wrapper($buttons);
        $form->end();
    }

    /* ----------------------------- DELETE ----------------------------- */

    private static function page_delete(string $loc, int $id): void
    {
        global $mybb, $db;

        $row = $db->fetch_array($db->simple_select(AF_AM_TABLE_ITEMS, '*', "id='".(int)$id."'", ['limit' => 1]));
        if (!$row) {
            self::simple_error('Пункт не найден.');
            return;
        }

        $realLoc = ($row['location'] === 'panel') ? 'panel' : 'top';

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $db->delete_query(AF_AM_TABLE_ITEMS, "id='".(int)$id."'");

            if (function_exists('af_advancedmenu_rebuild_cache')) {
                af_advancedmenu_rebuild_cache();
            }

            admin_redirect(self::url(['loc' => $realLoc, 'do' => 'list']), 'Удалено.');
            return;
        }

        require_once MYBB_ADMIN_DIR.'inc/class_form.php';

        $form = new Form(self::url(['do' => 'delete', 'loc' => $realLoc, 'id' => $id]), 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        echo '<div class="confirm_action" style="margin-top: 15px;">';
        echo '<h2>Удалить пункт?</h2>';
        echo '<p><strong>'.htmlspecialchars_uni((string)$row['title']).'</strong> (slug: <code>'.htmlspecialchars_uni((string)$row['slug']).'</code>)</p>';
        echo '<div style="margin-top: 12px;">';
        echo $form->generate_submit_button('Да, удалить', ['class' => 'button button_danger']).' ';
        echo '<a class="button" href="'.htmlspecialchars_uni(self::url(['loc' => $realLoc, 'do' => 'list'])).'">Отмена</a>';
        echo '</div>';
        echo '</div>';

        echo $form->end();
    }

    /* ----------------------------- TOGGLE ----------------------------- */

    private static function do_toggle(string $loc, int $id): void
    {
        global $db;

        $row = $db->fetch_array($db->simple_select(AF_AM_TABLE_ITEMS, '*', "id='".(int)$id."'", ['limit' => 1]));
        if (!$row) {
            self::simple_error('Пункт не найден.');
            return;
        }

        $enabled = ((int)$row['enabled'] === 1) ? 0 : 1;
        $db->update_query(AF_AM_TABLE_ITEMS, ['enabled' => $enabled, 'updated_at' => TIME_NOW], "id='".(int)$id."'");

        if (function_exists('af_advancedmenu_rebuild_cache')) {
            af_advancedmenu_rebuild_cache();
        }

        $realLoc = ($row['location'] === 'panel') ? 'panel' : 'top';
        admin_redirect(self::url(['loc' => $realLoc, 'do' => 'list']), 'Обновлено.');
    }

    /* ----------------------------- SETTINGS (INFO PAGE) ----------------------------- */

    private static function page_settings(string $loc): void
    {
        global $page;

        $active = ($loc === 'panel') ? 'panel' : 'top';

        $tabs = [
            'top' => [
                'title' => 'Верхнее меню (top_links)',
                'link'  => self::url(['loc' => 'top', 'do' => 'list']),
                'description' => '',
            ],
            'panel' => [
                'title' => 'Юзерское меню (panel_links)',
                'link'  => self::url(['loc' => 'panel', 'do' => 'list']),
                'description' => '',
            ],
            'settings' => [
                'title' => 'Настройки',
                'link'  => self::url(['loc' => $active, 'do' => 'settings']),
                'description' => '',
            ],
        ];

        if (is_object($page) && method_exists($page, 'output_nav_tabs')) {
            $page->output_nav_tabs($tabs, 'settings');
        }

        echo '<div style="margin: 10px 0;">';
        echo '<p>Настройки аддона находятся в <strong>Настройки → AdvancedMenu</strong> (settings.php) внутри ACP.</p>';
        echo '<ul style="margin-left: 18px;">';
        echo '<li><strong>Top menu mode</strong>: append / replace</li>';
        echo '<li><strong>Panel menu mode</strong>: append / replace (с защитой AAS/AAM)</li>';
        echo '<li><strong>Hide patterns</strong>: удаляет <code>&lt;li&gt;</code> по подстроке</li>';
        echo '<li><strong>Protect patterns</strong>: сохраняет “святые” <code>&lt;li&gt;</code> в replace режиме</li>';
        echo '</ul>';
        echo '</div>';
    }

    private static function simple_error(string $msg): void
    {
        echo '<div class="error" style="margin-top: 15px;"><p>'.htmlspecialchars_uni($msg).'</p></div>';
    }
}
