<?php
/**
 * ACP controller for AdvancedThreadFields (AF router)
 *
 * IMPORTANT:
 * В админ-роутере AF параметр `action` зарезервирован под действия самого роутера.
 * Поэтому мы используем `do` для внутренних действий аддона.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

// bootstrap аддона (константы таблиц, helpers)
$bootstrap = AF_ADDONS.'advancedthreadfields/advancedthreadfields.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
}

// safety fallback
if (!defined('AF_ATF_TABLE_FIELDS'))  define('AF_ATF_TABLE_FIELDS',  'af_atf_fields');
if (!defined('AF_ATF_TABLE_VALUES'))  define('AF_ATF_TABLE_VALUES',  'af_atf_values');
if (!defined('AF_ATF_TABLE_GROUPS'))  define('AF_ATF_TABLE_GROUPS',  'af_atf_groups');

class AF_Admin_Advancedthreadfields
{
    private const ROUTER_MODULE = 'advancedfunctionality';
    private const ROUTER_ACTION = 'advancedthreadfields';
    private const LANG_FILE     = 'advancedfunctionality_advancedthreadfields';

    private static function url(array $params = []): string
    {
        // В AF router аддоны открываются по af_view=...
        // action зарезервирован под действия роутера, НЕ используем его для аддона.
        $base = [
            'module'  => self::ROUTER_MODULE,
            'af_view' => self::ROUTER_ACTION, // оставляем константу как "имя вьюхи"
        ];

        $all = array_merge($base, $params);

        // на всякий случай: если кто-то передал action — выкинем, чтобы не ломать роутер
        unset($all['action']);

        return 'index.php?'.http_build_query($all, '', '&');
    }


    private static function go(array $params = []): void
    {
        admin_redirect(self::url($params));
    }

    /**
     * Подгрузка языков (ТОЛЬКО load, без sync).
     * Канон AF: языки уже созданы из manifest.php и лежат в нужных папках.
     */
    private static function load_lang(): void
    {
        global $lang;

        // Каноничный хелпер AF (если есть) — он сам разруливает admin/front пути.
        if (function_exists('af_load_addon_lang')) {
            // В ACP нам критичен admin-lang:
            af_load_addon_lang('advancedthreadfields', true);

            // Иногда удобно иметь и фронтовые ключи (если часть оказалась там):
            af_load_addon_lang('advancedthreadfields', false);
            return;
        }

        // Фоллбек: штатный MyBB loader
        if (!is_object($lang)) {
            if (class_exists('MyLanguage')) {
                $lang = new MyLanguage();
            } else {
                return;
            }
        }

        if (method_exists($lang, 'load')) {
            // admin: inc/languages/<lang>/admin/advancedfunctionality_advancedthreadfields.lang.php
            $lang->load(self::LANG_FILE, true);

            // front (на всякий): inc/languages/<lang>/advancedfunctionality_advancedthreadfields.lang.php
            $lang->load(self::LANG_FILE, false);
        }
    }

    public static function dispatch(): void
    {
        global $mybb;

        self::load_lang();

        // Внутренние действия аддона — только через `do`
        $do = (string)$mybb->get_input('do');

        switch ($do) {
            // groups
            case 'group_add':
                self::page_group_form(0);
                return;
            case 'group_edit':
                self::page_group_form((int)$mybb->get_input('gid'));
                return;
            case 'group_delete':
                self::page_group_delete((int)$mybb->get_input('gid'));
                return;
            case 'group_view':
                self::page_group_view((int)$mybb->get_input('gid'));
                return;

            // fields
            case 'field_add':
                self::page_field_form(0, (int)$mybb->get_input('gid'));
                return;
            case 'field_edit':
                self::page_field_form((int)$mybb->get_input('fieldid'), 0);
                return;
            case 'field_delete':
                self::page_field_delete((int)$mybb->get_input('fieldid'));
                return;

            // all fields
            case 'fields':
                self::page_fields_all();
                return;

            default:
                self::page_groups_list();
                return;
        }
    }


    /* -------------------------------- GROUPS: LIST -------------------------------- */

    private static function page_groups_list(): void
    {
        global $db;

        $table = new Table;
        $table->construct_header('ID', ['class' => 'align_center', 'width' => '5%']);
        $table->construct_header('Title');
        $table->construct_header('Forums', ['width' => '30%']);
        $table->construct_header('Active', ['class' => 'align_center', 'width' => '8%']);
        $table->construct_header('Sort', ['class' => 'align_center', 'width' => '8%']);
        $table->construct_header('Actions', ['class' => 'align_center', 'width' => '20%']);

        if (!$db->table_exists(AF_ATF_TABLE_GROUPS)) {
            $table->construct_cell('Таблица групп не установлена. Перезапусти install/activate аддона.', ['colspan' => 6]);
            $table->construct_row();
            $table->output('AdvancedThreadFields — Groups');
            return;
        }

        $q = $db->simple_select(AF_ATF_TABLE_GROUPS, '*', '', ['order_by' => 'sortorder', 'order_dir' => 'ASC']);
        $has = false;
        while ($g = $db->fetch_array($q)) {
            $has = true;
            $gid = (int)$g['gid'];

            $title = htmlspecialchars_uni($g['title']);
            $forums = htmlspecialchars_uni($g['forums']);
            $active = ((int)$g['active'] ? 'Yes' : 'No');

            $viewUrl = self::url(['do' => 'group_view', 'gid' => $gid]);
            $editUrl = self::url(['do' => 'group_edit', 'gid' => $gid]);
            $delUrl  = self::url(['do' => 'group_delete', 'gid' => $gid]);

            $actions = '<a href="'.$viewUrl.'">Fields</a> | <a href="'.$editUrl.'">Edit</a> | <a href="'.$delUrl.'">Delete</a>';

            $table->construct_cell($gid, ['class' => 'align_center']);
            $table->construct_cell($title);
            $table->construct_cell($forums === '' ? '<em>All</em>' : $forums);
            $table->construct_cell($active, ['class' => 'align_center']);
            $table->construct_cell((int)$g['sortorder'], ['class' => 'align_center']);
            $table->construct_cell($actions, ['class' => 'align_center']);
            $table->construct_row();
        }

        if (!$has) {
            $table->construct_cell('Групп пока нет. Создай первую — “Форма анкеты” и выбери форумы, где она работает.', ['colspan' => 6]);
            $table->construct_row();
        }

        $table->output('AdvancedThreadFields — Groups');

        echo '<div style="margin-top:10px; display:flex; gap:10px;">
            <a class="button button_primary" href="'.self::url(['do' => 'group_add']).'">+ Add group</a>
            <a class="button" href="'.self::url(['do' => 'fields']).'">All fields (legacy)</a>
        </div>';
    }

    private static function page_group_view(int $gid): void
    {
        global $db;

        if ($gid <= 0) {
            self::go();
        }

        $group = null;
        if ($db->table_exists(AF_ATF_TABLE_GROUPS)) {
            $q = $db->simple_select(AF_ATF_TABLE_GROUPS, '*', "gid=".(int)$gid, ['limit' => 1]);
            $group = $db->fetch_array($q);
        }

        if (!$group) {
            flash_message('Group not found', 'error');
            self::go();
        }

        echo '<div style="margin: 6px 0 12px 0;">
            <strong>Group:</strong> '.htmlspecialchars_uni($group['title']).' &nbsp; | &nbsp;
            <strong>Forums:</strong> '.(trim((string)$group['forums']) === '' ? '<em>All</em>' : htmlspecialchars_uni($group['forums'])).'
        </div>';

        $table = new Table;
        $table->construct_header('ID', ['class' => 'align_center', 'width' => '5%']);
        $table->construct_header('Title');
        $table->construct_header('name');
        $table->construct_header('type', ['width' => '10%']);
        $table->construct_header('Active', ['class' => 'align_center', 'width' => '8%']);
        $table->construct_header('Req', ['class' => 'align_center', 'width' => '6%']);
        $table->construct_header('ShowThread', ['class' => 'align_center', 'width' => '10%']);
        $table->construct_header('ShowForum', ['class' => 'align_center', 'width' => '10%']);
        $table->construct_header('Actions', ['class' => 'align_center', 'width' => '16%']);

        if (!$db->table_exists(AF_ATF_TABLE_FIELDS)) {
            $table->construct_cell('Таблицы полей не установлены.', ['colspan' => 9]);
            $table->construct_row();
            $table->output('Fields');
            return;
        }

        $q = $db->simple_select(AF_ATF_TABLE_FIELDS, '*', "groupid=".(int)$gid, ['order_by' => 'sortorder', 'order_dir' => 'ASC']);
        $has = false;
        while ($f = $db->fetch_array($q)) {
            $has = true;
            $fieldid = (int)$f['fieldid'];

            $editUrl = self::url(['do' => 'field_edit', 'fieldid' => $fieldid]);
            $delUrl  = self::url(['do' => 'field_delete', 'fieldid' => $fieldid]);

            $table->construct_cell($fieldid, ['class' => 'align_center']);
            $table->construct_cell(htmlspecialchars_uni($f['title']));
            $table->construct_cell(htmlspecialchars_uni($f['name']));
            $table->construct_cell(htmlspecialchars_uni($f['type']));
            $table->construct_cell(((int)$f['active'] ? 'Yes' : 'No'), ['class' => 'align_center']);
            $table->construct_cell(((int)$f['required'] ? 'Yes' : 'No'), ['class' => 'align_center']);
            $table->construct_cell(((int)$f['show_thread'] ? 'Yes' : 'No'), ['class' => 'align_center']);
            $table->construct_cell(((int)$f['show_forum'] ? 'Yes' : 'No'), ['class' => 'align_center']);
            $table->construct_cell('<a href="'.$editUrl.'">Edit</a> | <a href="'.$delUrl.'">Delete</a>', ['class' => 'align_center']);
            $table->construct_row();
        }

        if (!$has) {
            $table->construct_cell('В этой группе пока нет полей.', ['colspan' => 9]);
            $table->construct_row();
        }

        $table->output('AdvancedThreadFields — Fields in group');

        echo '<div style="margin-top:10px;">
            <a class="button button_primary" href="'.self::url(['do' => 'field_add', 'gid' => (int)$gid]).'">+ Add field to this group</a>
            <a class="button" href="'.self::url().'">← Back to groups</a>
        </div>';
    }

    /* -------------------------------- GROUPS: FORM -------------------------------- */
    private static function page_group_form(int $gid): void
    {
        global $db, $mybb;

        if (function_exists('af_atf_db_ensure_group_columns')) {
            af_atf_db_ensure_group_columns();
        }

        $isEdit = $gid > 0;

        $group = [
            'title'       => '',
            'description' => '',
            'forums'      => '',
            'catalog_characters_url'   => '',
            'catalog_roles_url'        => '',
            'catalog_characters_label' => 'Посмотреть канонов',
            'catalog_roles_label'      => 'Посмотреть списки ролей',
            'show_catalog_cta'         => 0,
            'active'      => 1,
            'sortorder'   => 0,
        ];

        if ($isEdit) {
            $q = $db->simple_select(AF_ATF_TABLE_GROUPS, '*', "gid=".(int)$gid, ['limit' => 1]);
            $row = $db->fetch_array($q);
            if ($row) {
                $group = array_merge($group, $row);
            } else {
                flash_message('Group not found', 'error');
                self::go();
            }
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $title = trim($mybb->get_input('title'));
            $description = $mybb->get_input('description');

            // 1) Новый путь: мультиселект форумов
            $forumsSel = $mybb->get_input('forums_select', MyBB::INPUT_ARRAY);
            if (!is_array($forumsSel)) {
                $forumsSel = [];
            }
            $forumsSel = array_values(array_unique(array_filter(array_map('intval', $forumsSel), static fn($x) => $x > 0)));

            // 2) Фоллбек: старый текстовый CSV
            $forumsCsv = trim($mybb->get_input('forums'));
            if (!empty($forumsSel)) {
                $forums = implode(',', $forumsSel);
            } else {
                $forums = $forumsCsv; // может быть пусто => All
            }

            $active = (int)$mybb->get_input('active');
            $sortorder = (int)$mybb->get_input('sortorder');
            $catalogCharactersUrl = trim($mybb->get_input('catalog_characters_url'));
            $catalogRolesUrl = trim($mybb->get_input('catalog_roles_url'));
            $catalogCharactersLabel = trim($mybb->get_input('catalog_characters_label'));
            $catalogRolesLabel = trim($mybb->get_input('catalog_roles_label'));
            $showCatalogCta = (int)$mybb->get_input('show_catalog_cta');

            if ($title === '') {
                flash_message('Title is required', 'error');
            } else {
                $data = [
                    'title'       => $db->escape_string($title),
                    'description' => $db->escape_string($description),
                    'forums'      => $db->escape_string($forums),
                    'catalog_characters_url'   => $db->escape_string($catalogCharactersUrl),
                    'catalog_roles_url'        => $db->escape_string($catalogRolesUrl),
                    'catalog_characters_label' => $db->escape_string($catalogCharactersLabel),
                    'catalog_roles_label'      => $db->escape_string($catalogRolesLabel),
                    'show_catalog_cta'         => $showCatalogCta ? 1 : 0,
                    'active'      => (int)$active,
                    'sortorder'   => (int)$sortorder,
                ];

                if ($isEdit) {
                    $db->update_query(AF_ATF_TABLE_GROUPS, $data, "gid=".(int)$gid);
                } else {
                    $db->insert_query(AF_ATF_TABLE_GROUPS, $data);
                    $gid = (int)$db->insert_id();
                }

                if (function_exists('af_atf_rebuild_cache')) {
                    af_atf_rebuild_cache(true);
                }

                flash_message('Saved', 'success');
                self::go(['do' => 'group_view', 'gid' => (int)$gid]);
            }

            $group = array_merge($group, [
                'title' => $title,
                'description' => $description,
                'forums' => $forums,
                'catalog_characters_url' => $catalogCharactersUrl,
                'catalog_roles_url' => $catalogRolesUrl,
                'catalog_characters_label' => $catalogCharactersLabel,
                'catalog_roles_label' => $catalogRolesLabel,
                'show_catalog_cta' => $showCatalogCta ? 1 : 0,
                'active' => $active,
                'sortorder' => $sortorder,
            ]);
        }

        $form = new Form(
            self::url($isEdit ? ['do' => 'group_edit', 'gid' => (int)$gid] : ['do' => 'group_add']),
            'post'
        );
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        $table = new Table;
        $table->construct_header($isEdit ? 'Edit group' : 'Add group');

        self::row($table, 'Title', $form->generate_text_box('title', $group['title'], ['maxlength' => 255]));
        self::row($table, 'Description', $form->generate_text_area('description', $group['description'], ['rows' => 4]));

        // --- Forums selector ---
        $selected = array_filter(array_map('intval', preg_split('~\s*,\s*~', trim((string)$group['forums']))));
        $selected = array_values(array_unique(array_filter($selected, static fn($x) => $x > 0)));

        $forumsHelp = '<div style="margin-top:6px; font-size:12px; opacity:.85;">
            <strong>Важно:</strong> если выбрать <u>родительский</u> форум/категорию, группа будет работать во <u>всех дочерних</u> форумах автоматически.<br>
            <strong>Если ничего не выбрано:</strong> группа активна во всех форумах.
        </div>';

        $forumsFieldHtml = '';

        // 1) Каноничный ACP-хелпер (если есть в админке)
        if (function_exists('build_forum_select')) {
            // имя должно быть массивом для multiple
            $forumsFieldHtml = build_forum_select(
                'forums_select[]',
                $selected,
                [
                    'multiple' => true,
                    'size' => 12,
                ]
            );
            // на всякий — ещё и скрытый CSV (не обязателен, но удобно для дебага/совместимости)
            $forumsFieldHtml .= '<div style="margin-top:8px; font-size:12px; opacity:.75;">
                <em>Сохранится как CSV:</em> '.htmlspecialchars_uni(trim((string)$group['forums'])).'
            </div>';
        } else {
            // 2) Фоллбек: старый текстбокс
            $forumsFieldHtml = $form->generate_text_box('forums', $group['forums'])
                . '<div style="margin-top:6px; font-size:12px; opacity:.85;">
                    Форумы (IDs через запятую; пусто = все).<br>
                    Наследование “родитель → дети” всё равно работает.
                </div>';
        }

        self::row($table, 'Forums', $forumsFieldHtml . $forumsHelp);
        self::row($table, 'Characters catalog URL', $form->generate_text_box('catalog_characters_url', $group['catalog_characters_url'], ['maxlength' => 500]));
        self::row($table, 'Roles catalog URL', $form->generate_text_box('catalog_roles_url', $group['catalog_roles_url'], ['maxlength' => 500]));
        self::row($table, 'Characters button label', $form->generate_text_box('catalog_characters_label', $group['catalog_characters_label'], ['maxlength' => 255]));
        self::row($table, 'Roles button label', $form->generate_text_box('catalog_roles_label', $group['catalog_roles_label'], ['maxlength' => 255]));
        self::row($table, 'Show catalog CTA block', $form->generate_yes_no_radio('show_catalog_cta', (int)$group['show_catalog_cta']));

        self::row($table, 'Sort order', $form->generate_numeric_field('sortorder', (int)$group['sortorder']));
        self::row($table, 'Active', $form->generate_yes_no_radio('active', (int)$group['active']));

        $table->construct_row();
        $table->construct_cell($form->generate_submit_button('Save'), ['colspan' => 2, 'class' => 'align_center']);
        $table->construct_row();

        $table->output('AdvancedThreadFields — Group');

        echo $form->end();

        echo '<div style="margin-top:10px;">
            <a class="button" href="'.self::url().'">← Back</a>
        </div>';
    }


    private static function page_group_delete(int $gid): void
    {
        global $db, $mybb;

        if ($gid <= 0) {
            self::go();
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            // удаляем поля группы (мету) + значения
            if ($db->table_exists(AF_ATF_TABLE_FIELDS)) {
                $q = $db->simple_select(AF_ATF_TABLE_FIELDS, 'fieldid', "groupid=".(int)$gid);
                while ($r = $db->fetch_array($q)) {
                    $fieldid = (int)$r['fieldid'];
                    if ($fieldid > 0 && $db->table_exists(AF_ATF_TABLE_VALUES)) {
                        $db->delete_query(AF_ATF_TABLE_VALUES, "fieldid=".$fieldid);
                    }
                }
                $db->delete_query(AF_ATF_TABLE_FIELDS, "groupid=".(int)$gid);
            }

            if ($db->table_exists(AF_ATF_TABLE_GROUPS)) {
                $db->delete_query(AF_ATF_TABLE_GROUPS, "gid=".(int)$gid);
            }

            if (function_exists('af_atf_rebuild_cache')) {
                af_atf_rebuild_cache(true);
            }

            flash_message('Deleted', 'success');
            self::go();
        }

        $form = new Form(self::url(['do' => 'group_delete', 'gid' => (int)$gid]), 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        echo '<div class="confirm_action">
            <p>Delete this group, all fields inside it, and all saved values?</p>
            <p class="buttons">
                '.$form->generate_submit_button('Delete', ['class' => 'button button_danger']).'
                <a class="button" href="'.self::url().'">Cancel</a>
            </p>
        </div>';

        echo $form->end();
    }

    /* -------------------------------- FIELDS: LEGACY ALL LIST -------------------------------- */

    private static function page_fields_all(): void
    {
        global $db;

        $table = new Table;
        $table->construct_header('ID', ['class' => 'align_center', 'width' => '5%']);
        $table->construct_header('Group', ['width' => '18%']);
        $table->construct_header('Title');
        $table->construct_header('name');
        $table->construct_header('type', ['width' => '10%']);
        $table->construct_header('Active', ['class' => 'align_center', 'width' => '8%']);
        $table->construct_header('Actions', ['class' => 'align_center', 'width' => '14%']);

        if (!$db->table_exists(AF_ATF_TABLE_FIELDS)) {
            $table->construct_cell('Таблицы не установлены.', ['colspan' => 7]);
            $table->construct_row();
            $table->output('AdvancedThreadFields — Fields');
            return;
        }

        $groups = [];
        if ($db->table_exists(AF_ATF_TABLE_GROUPS)) {
            $qg = $db->simple_select(AF_ATF_TABLE_GROUPS, 'gid,title');
            while ($g = $db->fetch_array($qg)) {
                $groups[(int)$g['gid']] = (string)$g['title'];
            }
        }

        $q = $db->simple_select(AF_ATF_TABLE_FIELDS, '*', '', ['order_by' => 'sortorder', 'order_dir' => 'ASC']);
        while ($f = $db->fetch_array($q)) {
            $fieldid = (int)$f['fieldid'];
            $gid = (int)$f['groupid'];
            $gTitle = $gid > 0 && isset($groups[$gid]) ? htmlspecialchars_uni($groups[$gid]) : '<em>—</em>';

            $editUrl = self::url(['do' => 'field_edit', 'fieldid' => $fieldid]);
            $delUrl  = self::url(['do' => 'field_delete', 'fieldid' => $fieldid]);

            $table->construct_cell($fieldid, ['class' => 'align_center']);
            $table->construct_cell($gTitle);
            $table->construct_cell(htmlspecialchars_uni($f['title']));
            $table->construct_cell(htmlspecialchars_uni($f['name']));
            $table->construct_cell(htmlspecialchars_uni($f['type']));
            $table->construct_cell(((int)$f['active'] ? 'Yes' : 'No'), ['class' => 'align_center']);
            $table->construct_cell('<a href="'.$editUrl.'">Edit</a> | <a href="'.$delUrl.'">Delete</a>', ['class' => 'align_center']);
            $table->construct_row();
        }

        $table->output('AdvancedThreadFields — All fields');

        echo '<div style="margin-top:10px;">
            <a class="button" href="'.self::url().'">← Back to groups</a>
        </div>';
    }

    /* -------------------------------- FIELDS: FORM -------------------------------- */
    private static function page_field_form(int $fieldid, int $gidFromUrl = 0): void
    {
        global $db, $mybb;

        $isEdit = $fieldid > 0;

        $field = [
            'groupid'     => 0,
            'name'        => '',
            'title'       => '',
            'description' => '',
            'type'        => 'text',
            'options'     => '',
            'required'    => 0,
            'active'      => 1,
            'show_thread' => 1,
            'show_forum'  => 0,
            'sortorder'   => 0,
            'maxlen'      => 0,
            'regex'       => '',
            'format'      => '',

            'allow_html'    => 0,
            'parse_mycode'  => 1,
            'parse_smilies' => 1,
        ];

        if ($isEdit) {
            $q = $db->simple_select(AF_ATF_TABLE_FIELDS, '*', "fieldid=".(int)$fieldid, ['limit' => 1]);
            $row = $db->fetch_array($q);
            if ($row) {
                $field = array_merge($field, $row);
            } else {
                flash_message('Field not found', 'error');
                self::go();
            }
        } elseif ($gidFromUrl > 0) {
            $field['groupid'] = $gidFromUrl;
        }

        // groups for select
        $groupOptions = [0 => '— Select group —'];
        if ($db->table_exists(AF_ATF_TABLE_GROUPS)) {
            $qg = $db->simple_select(AF_ATF_TABLE_GROUPS, 'gid,title', '', ['order_by' => 'sortorder', 'order_dir' => 'ASC']);
            while ($g = $db->fetch_array($qg)) {
                $groupOptions[(int)$g['gid']] = (string)$g['title'];
            }
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $groupid = (int)$mybb->get_input('groupid');

            $name = trim($mybb->get_input('name'));
            $title = trim($mybb->get_input('title'));
            $description = $mybb->get_input('description');
            $type = $mybb->get_input('type');
            $options = $mybb->get_input('options');
            $format = $mybb->get_input('format');
            $regex = $mybb->get_input('regex');

            $required = (int)$mybb->get_input('required');
            $active = (int)$mybb->get_input('active');
            $show_thread = (int)$mybb->get_input('show_thread');
            $show_forum = (int)$mybb->get_input('show_forum');
            $sortorder = (int)$mybb->get_input('sortorder');
            $maxlen = (int)$mybb->get_input('maxlen');

            $allow_html = (int)$mybb->get_input('allow_html');
            $parse_mycode = (int)$mybb->get_input('parse_mycode');
            $parse_smilies = (int)$mybb->get_input('parse_smilies');

            if ($groupid <= 0) {
                flash_message('Group is required (create group first).', 'error');
            } elseif ($name === '' || !preg_match('~^[a-z0-9_]{2,64}$~i', $name)) {
                flash_message('Invalid name (use a-z0-9_)', 'error');
            } elseif ($title === '') {
                flash_message('Title is required', 'error');
            } else {
                $data = [
                    'groupid'      => (int)$groupid,
                    'name'         => $db->escape_string($name),
                    'title'        => $db->escape_string($title),
                    'description'  => $db->escape_string($description),
                    'type'         => $db->escape_string($type),
                    'options'      => $db->escape_string($options),
                    'required'     => (int)$required,
                    'active'       => (int)$active,
                    'show_thread'  => (int)$show_thread,
                    'show_forum'   => (int)$show_forum,
                    'sortorder'    => (int)$sortorder,
                    'maxlen'       => (int)$maxlen,
                    'regex'        => $db->escape_string($regex),
                    'format'       => $db->escape_string($format),
                    'allow_html'    => (int)$allow_html,
                    'parse_mycode'  => (int)$parse_mycode,
                    'parse_smilies' => (int)$parse_smilies,
                ];

                if ($isEdit) {
                    $db->update_query(AF_ATF_TABLE_FIELDS, $data, "fieldid=".(int)$fieldid);
                } else {
                    $db->insert_query(AF_ATF_TABLE_FIELDS, $data);
                    $fieldid = (int)$db->insert_id();
                }

                if (function_exists('af_atf_rebuild_cache')) {
                    af_atf_rebuild_cache(true);
                }

                flash_message('Saved', 'success');
                self::go(['do' => 'group_view', 'gid' => (int)$groupid]);
            }

            $field = array_merge($field, [
                'groupid' => $groupid,
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'type' => $type,
                'options' => $options,
                'format' => $format,
                'regex' => $regex,
                'required' => $required,
                'active' => $active,
                'show_thread' => $show_thread,
                'show_forum' => $show_forum,
                'sortorder' => $sortorder,
                'maxlen' => $maxlen,
                'allow_html' => $allow_html,
                'parse_mycode' => $parse_mycode,
                'parse_smilies' => $parse_smilies,
            ]);
        }

        $formAction = $isEdit
            ? self::url(['do' => 'field_edit', 'fieldid' => (int)$fieldid])
            : self::url(['do' => 'field_add', 'gid' => (int)$field['groupid']]);

        $form = new Form($formAction, 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        $table = new Table;
        $table->construct_header($isEdit ? 'Edit field' : 'Add field');

        $types = [
            'text'      => 'Text',
            'textarea'  => 'Textarea (BBCode toolbar)',
            'image'     => 'Image (URL -> [img])',
            'select'    => 'Select',
            'radio'     => 'Radio',
            'checkbox'  => 'Checkbox',
            'url'       => 'URL',
            'number'    => 'Number',
            'usernames' => 'Users (search + tags)',
            'kb_race'   => $lang->af_atf_type_kb_race ?? 'KB select: Race',
            'kb_class'  => $lang->af_atf_type_kb_class ?? 'KB select: Class',
            'kb_theme'  => $lang->af_atf_type_kb_theme ?? 'KB select: Theme',
            'sf_attributes_pointbuy' => $lang->af_atf_type_sf_attributes_pointbuy ?? 'SF attributes point-buy',
        ];

        $nameHelp = '<div style="margin-top:6px; font-size:12px; opacity:.85;">
            <strong>Что это:</strong> технический ключ поля (используется в БД и коде).<br>
            <strong>Правила:</strong> латиница/цифры/подчёркивание, 2–64 символа. Примеры: <code>race</code>, <code>blood_type</code>, <code>char_age</code>.<br>
            <strong>Важно:</strong> если поменяешь этот ключ позже — старые сохранённые значения могут “потеряться”, потому что они привязаны к ключу.
        </div>';

        $formatHelp = '<div style="margin-top:6px; font-size:12px; opacity:.85;">
            <strong>Что это:</strong> шаблон вывода значения на фронте (как поле будет показано).<br>
            <strong>Плейсхолдеры:</strong> <code>{LABEL}</code> — красивое название варианта, <code>{VALUE}</code> — сохранённое значение (ключ).<br>
            <strong>Примеры:</strong><br>
            • <code>{LABEL}</code> → покажет “Эльф”<br>
            • <code>Раса: {LABEL}</code> → “Раса: Эльф”<br>
            • <code>({VALUE}) {LABEL}</code> → “(elf) Эльф”<br>
            <strong>Если пусто:</strong> можно считать, что выводится просто значение по умолчанию.
        </div>';

        $optionsHelp = '<div style="margin-top:6px; font-size:12px; opacity:.85;">
            <strong>Для чего:</strong> список вариантов для типов <code>Select</code>, <code>Radio</code>, <code>Checkbox</code>.<br>
            <strong>Как заполнять:</strong> <u>каждая строка</u> — один вариант. Два формата:<br>
            1) <code>key=Label</code> — рекомендуемый: <code>key</code> хранится в базе, <code>Label</code> видит пользователь.<br>
            2) <code>Label</code> — упрощённый: и хранится, и показывается одно и то же.<br>
            <br>
            <strong>Дополнительно для типа “Users (search + tags)”:</strong><br>
            • можно ограничить число выбранных юзеров через <code>max=5</code> (в отдельной строке).<br>
            • в базе сохраняются <u>uid через запятую</u> (пример: <code>12,45,301</code>).
        </div>';

        self::row($table, 'Group', $form->generate_select_box('groupid', $groupOptions, (int)$field['groupid']));
        self::row($table, 'Key (name)', $form->generate_text_box('name', $field['name'], ['maxlength' => 64]).$nameHelp);
        self::row($table, 'Title', $form->generate_text_box('title', $field['title'], ['maxlength' => 255]));
        self::row($table, 'Description', $form->generate_text_area('description', $field['description'], ['rows' => 4]));
        self::row($table, 'Type', $form->generate_select_box('type', $types, $field['type']));
        self::row($table, 'Options (one per line)', $form->generate_text_area('options', $field['options'], ['rows' => 8]).$optionsHelp);
        self::row($table, 'Sort order', $form->generate_numeric_field('sortorder', (int)$field['sortorder']));
        self::row($table, 'Max length (0=off)', $form->generate_numeric_field('maxlen', (int)$field['maxlen']));
        self::row($table, 'Regex validation (optional)', $form->generate_text_box('regex', $field['regex']));
        self::row($table, 'Display format ({LABEL} / {VALUE})', $form->generate_text_area('format', $field['format'], ['rows' => 4]).$formatHelp);

        self::row($table, 'Active', $form->generate_yes_no_radio('active', (int)$field['active']));
        self::row($table, 'Required', $form->generate_yes_no_radio('required', (int)$field['required']));
        self::row($table, 'Show in showthread', $form->generate_yes_no_radio('show_thread', (int)$field['show_thread']));
        self::row($table, 'Show in forumdisplay list', $form->generate_yes_no_radio('show_forum', (int)$field['show_forum']));

        self::row($table, 'Allow HTML (requires forum + group permissions)', $form->generate_yes_no_radio('allow_html', (int)$field['allow_html']));
        self::row($table, 'Parse MyCode (BBCode)', $form->generate_yes_no_radio('parse_mycode', (int)$field['parse_mycode']));
        self::row($table, 'Parse Smilies', $form->generate_yes_no_radio('parse_smilies', (int)$field['parse_smilies']));

        $table->construct_row();
        $table->construct_cell($form->generate_submit_button('Save'), ['colspan' => 2, 'class' => 'align_center']);
        $table->construct_row();

        $table->output('AdvancedThreadFields — Field');

        echo $form->end();

        $backGid = (int)$field['groupid'];
        echo '<div style="margin-top:10px;">
            <a class="button" href="'.self::url(['do' => 'group_view', 'gid' => $backGid]).'">← Back to group</a>
        </div>';
    }




    private static function page_field_delete(int $fieldid): void
    {
        global $db, $mybb;

        if ($fieldid <= 0) {
            self::go();
        }

        $gid = 0;
        if ($db->table_exists(AF_ATF_TABLE_FIELDS)) {
            $gid = (int)$db->fetch_field(
                $db->simple_select(AF_ATF_TABLE_FIELDS, 'groupid', "fieldid=".(int)$fieldid, ['limit' => 1]),
                'groupid'
            );
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            if ($db->table_exists(AF_ATF_TABLE_VALUES)) {
                $db->delete_query(AF_ATF_TABLE_VALUES, "fieldid=".(int)$fieldid);
            }
            if ($db->table_exists(AF_ATF_TABLE_FIELDS)) {
                $db->delete_query(AF_ATF_TABLE_FIELDS, "fieldid=".(int)$fieldid);
            }

            if (function_exists('af_atf_rebuild_cache')) {
                af_atf_rebuild_cache(true);
            }

            flash_message('Deleted', 'success');
            self::go(['do' => 'group_view', 'gid' => (int)$gid]);
        }

        $form = new Form(self::url(['do' => 'field_delete', 'fieldid' => (int)$fieldid]), 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        echo '<div class="confirm_action">
            <p>Delete this field and all its values?</p>
            <p class="buttons">
                '.$form->generate_submit_button('Delete', ['class' => 'button button_danger']).'
                <a class="button" href="'.self::url(['do' => 'group_view', 'gid' => (int)$gid]).'">Cancel</a>
            </p>
        </div>';

        echo $form->end();
    }

    private static function row(Table $table, string $label, string $content): void
    {
        $table->construct_row();
        $table->construct_cell($label, ['width' => '25%']);
        $table->construct_cell($content);
    }
}
