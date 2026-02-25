<?php
/**
 * AF CharacterSheets — ACP controller
 * Path: /inc/plugins/advancedfunctionality/addons/charactersheets/admin.php
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class AF_Admin_Charactersheets
{
    public static function dispatch(): void
    {
        global $mybb, $lang, $page, $db;

        $baseUrl = 'index.php?module=advancedfunctionality&af_view=charactersheets';
        $skillsUrl = $baseUrl . '&cs_view=skills';
        $skillRanksUrl = $baseUrl . '&cs_view=skill_ranks';

        self::ensureBootstrapIncluded();
        af_charactersheets_lang();

        if (isset($page) && method_exists($page, 'add_breadcrumb_item')) {
            $page->add_breadcrumb_item('CharacterSheets', $baseUrl);
        }

        $view = (string)$mybb->get_input('cs_view');
        if ($view === 'skills') {
            self::renderSkillsCatalog($skillsUrl);
            return;
        }
        if ($view === 'skill_ranks') {
            self::renderSkillRanks($skillRanksUrl);
            return;
        }

        if ($mybb->request_method === 'post') {
            if (function_exists('check_post_check')) {
                check_post_check($mybb->input['my_post_key'] ?? '');
            }

            $template = (string)($mybb->input['accept_post_template'] ?? '');
            af_charactersheets_set_accept_template($template);

            self::redirect($baseUrl, $lang->af_charactersheets_admin_saved ?? 'Настройки сохранены.');
        }

        if (!$db->table_exists(AF_CS_CONFIG_TABLE)) {
            echo '<div class="error">Таблица конфигурации CharacterSheets не найдена. Запустите установку аддона.</div>';
            return;
        }

        $currentTemplate = af_charactersheets_get_accept_template();
        if ($currentTemplate === '') {
            $currentTemplate = af_charactersheets_default_accept_template();
        }

        $title = $lang->af_charactersheets_admin_title ?? 'CharacterSheets';
        $subtitle = $lang->af_charactersheets_admin_subtitle ?? 'Настройка текста принятия анкеты.';
        $label = $lang->af_charactersheets_admin_accept_template ?? 'Текст сообщения принятия';
        $hint = $lang->af_charactersheets_admin_accept_template_desc ?? 'Плейсхолдеры: {mention}, {username}, {uid}, {thread_url}, {profile_url}, {accepted_by}, {sheet_url}, {sheet_slug}.';
        $save = $lang->af_charactersheets_admin_save ?? 'Сохранить';

        $postKey = htmlspecialchars((string)($mybb->post_code ?? ''), ENT_QUOTES);

        echo '<div class="form_container">';
        echo '<h2>' . htmlspecialchars_uni($title) . '</h2>';
        echo '<p style="margin-top:4px;">' . htmlspecialchars_uni($subtitle) . '</p>';

        echo '<p><a href="' . htmlspecialchars($skillsUrl, ENT_QUOTES) . '">' . htmlspecialchars_uni($lang->af_charactersheets_admin_skills ?? 'Навыки') . '</a></p>';
        echo '<p><a href="' . htmlspecialchars($skillRanksUrl, ENT_QUOTES) . '">' . htmlspecialchars_uni($lang->af_charactersheets_admin_skill_ranks ?? 'Ранги навыков') . '</a></p>';

        echo '<form action="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '" method="post">';
        echo '<input type="hidden" name="my_post_key" value="' . $postKey . '" />';

        echo '<div class="form_row">';
        echo '<label for="accept_post_template">' . htmlspecialchars_uni($label) . '</label>';
        echo '<textarea id="accept_post_template" name="accept_post_template" rows="10" style="width:100%;">'
            . htmlspecialchars_uni($currentTemplate) . '</textarea>';
        echo '<p class="description">' . htmlspecialchars_uni($hint) . '</p>';
        echo '</div>';

        echo '<div class="form_row">';
        echo '<button type="submit" class="button">' . htmlspecialchars_uni($save) . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    private static function renderSkillRanks(string $skillRanksUrl): void
    {
        global $mybb, $lang, $db;

        if ($mybb->request_method === 'post') {
            if (function_exists('check_post_check')) {
                check_post_check($mybb->input['my_post_key'] ?? '');
            }

            $defaults = af_charactersheets_skill_rank_defaults();
            $payload = [];
            for ($rank = 0; $rank <= 5; $rank++) {
                $title_ru = trim((string)($mybb->input['title_ru_' . $rank] ?? ($defaults[(string)$rank]['title_ru'] ?? '')));
                $title_en = trim((string)($mybb->input['title_en_' . $rank] ?? ($defaults[(string)$rank]['title_en'] ?? '')));
                $bonus = (int)($mybb->input['bonus_' . $rank] ?? ($defaults[(string)$rank]['bonus'] ?? 0));
                $payload[(string)$rank] = [
                    'title_ru' => $title_ru,
                    'title_en' => $title_en,
                    'bonus' => $bonus,
                ];
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                flash_message($lang->af_charactersheets_admin_skill_ranks_invalid ?? 'Некорректные данные рангов.', 'error');
                admin_redirect($skillRanksUrl);
            }

            $db->update_query(
                'settings',
                ['value' => $db->escape_string($json)],
                "name='af_cs_skill_ranks_json'"
            );
            rebuild_settings();

            flash_message($lang->af_charactersheets_admin_skill_ranks_saved ?? 'Ранги навыков сохранены.', 'success');
            admin_redirect($skillRanksUrl);
        }

        $config = af_charactersheets_skill_rank_config();
        $postKey = htmlspecialchars((string)($mybb->post_code ?? ''), ENT_QUOTES);

        echo '<div class="form_container">';
        echo '<h2>' . htmlspecialchars_uni($lang->af_charactersheets_admin_skill_ranks ?? 'Ранги навыков') . '</h2>';
        echo '<p><a href="index.php?module=advancedfunctionality&af_view=charactersheets">' . htmlspecialchars_uni($lang->af_charactersheets_admin_back ?? 'Назад') . '</a></p>';
        echo '<form action="' . htmlspecialchars($skillRanksUrl, ENT_QUOTES) . '" method="post">';
        echo '<input type="hidden" name="my_post_key" value="' . $postKey . '" />';
        echo '<table class="general">';
        echo '<tr><th>Rank</th><th>Название (RU)</th><th>Title (EN)</th><th>Бонус</th></tr>';
        for ($rank = 0; $rank <= 5; $rank++) {
            $row = (array)($config[(string)$rank] ?? []);
            echo '<tr>';
            echo '<td><input type="text" readonly value="' . $rank . '" style="width:40px" /></td>';
            echo '<td><input type="text" name="title_ru_' . $rank . '" value="' . htmlspecialchars_uni((string)($row['title_ru'] ?? '')) . '" style="width:100%" /></td>';
            echo '<td><input type="text" name="title_en_' . $rank . '" value="' . htmlspecialchars_uni((string)($row['title_en'] ?? '')) . '" style="width:100%" /></td>';
            echo '<td><input type="number" name="bonus_' . $rank . '" value="' . (int)($row['bonus'] ?? 0) . '" style="width:80px" /></td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<div class="form_row" style="margin-top:12px;"><button type="submit" class="button">' . htmlspecialchars_uni($lang->af_charactersheets_admin_save ?? 'Сохранить') . '</button></div>';
        echo '</form>';
        echo '</div>';
    }

    private static function renderSkillsCatalog(string $skillsUrl): void
    {
        global $mybb, $db, $lang;

        if (!$db->table_exists(AF_CS_SKILLS_CATALOG_TABLE)) {
            echo '<div class="error">Таблица навыков не найдена. Запустите установку аддона.</div>';
            return;
        }

        if ($mybb->request_method === 'post') {
            if (function_exists('check_post_check')) {
                check_post_check($mybb->input['my_post_key'] ?? '');
            }

            $id = (int)($mybb->input['skill_id'] ?? 0);
            $slug = trim((string)($mybb->input['slug'] ?? ''));
            $title = trim((string)($mybb->input['title'] ?? ''));
            $attr_key = trim((string)($mybb->input['attr_key'] ?? ''));
            $description = trim((string)($mybb->input['description'] ?? ''));
            $sort_order = (int)($mybb->input['sort_order'] ?? 0);
            $active = !empty($mybb->input['active']) ? 1 : 0;

            $allowed_attrs = array_keys(af_cs_get_attribute_catalog());
            if ($slug === '' || !preg_match('~^[a-z0-9_-]+$~i', $slug)) {
                flash_message($lang->af_charactersheets_admin_skill_slug_invalid ?? 'Некорректный ключ навыка.', 'error');
                admin_redirect($skillsUrl);
            }
            if ($title === '') {
                flash_message($lang->af_charactersheets_admin_skill_title_invalid ?? 'Укажите название навыка.', 'error');
                admin_redirect($skillsUrl);
            }
            if (!in_array($attr_key, $allowed_attrs, true)) {
                flash_message($lang->af_charactersheets_admin_skill_attr_invalid ?? 'Некорректный атрибут.', 'error');
                admin_redirect($skillsUrl);
            }

            $row = [
                'slug' => $db->escape_string($slug),
                'title' => $db->escape_string($title),
                'attr_key' => $db->escape_string($attr_key),
                'description' => $db->escape_string($description),
                'sort_order' => $sort_order,
                'active' => $active,
            ];

            if ($id > 0) {
                $db->update_query(AF_CS_SKILLS_CATALOG_TABLE, $row, 'id=' . $id);
            } else {
                $db->insert_query(AF_CS_SKILLS_CATALOG_TABLE, $row);
            }

            flash_message($lang->af_charactersheets_admin_skill_saved ?? 'Навык сохранён.', 'success');
            admin_redirect($skillsUrl);
        }

        $deleteId = (int)$mybb->get_input('delete');
        if ($deleteId > 0) {
            if (function_exists('check_post_check')) {
                check_post_check($mybb->get_input('my_post_key'));
            }
            $db->delete_query(AF_CS_SKILLS_CATALOG_TABLE, 'id=' . $deleteId);
            flash_message($lang->af_charactersheets_admin_skill_deleted ?? 'Навык удалён.', 'success');
            admin_redirect($skillsUrl);
        }

        $editId = (int)$mybb->get_input('skill_id');
        $editRow = [
            'id' => 0,
            'slug' => '',
            'title' => '',
            'attr_key' => 'int',
            'description' => '',
            'sort_order' => 0,
            'active' => 1,
        ];
        if ($editId > 0) {
            $row = $db->fetch_array($db->simple_select(AF_CS_SKILLS_CATALOG_TABLE, '*', 'id=' . $editId, ['limit' => 1]));
            if (is_array($row)) {
                $editRow = $row;
            }
        }

        echo '<div class="form_container">';
        echo '<h2>' . htmlspecialchars_uni($lang->af_charactersheets_admin_skills ?? 'Навыки') . '</h2>';
        echo '<p><a href="' . htmlspecialchars($skillsUrl, ENT_QUOTES) . '">' . htmlspecialchars_uni($lang->af_charactersheets_admin_back ?? 'Назад') . '</a></p>';

        $postKey = htmlspecialchars((string)($mybb->post_code ?? ''), ENT_QUOTES);
        echo '<form action="' . htmlspecialchars($skillsUrl, ENT_QUOTES) . '" method="post">';
        echo '<input type="hidden" name="my_post_key" value="' . $postKey . '" />';
        echo '<input type="hidden" name="skill_id" value="' . (int)$editRow['id'] . '" />';

        echo '<div class="form_row"><label>Slug</label><input type="text" name="slug" value="' . htmlspecialchars_uni((string)$editRow['slug']) . '" /></div>';
        echo '<div class="form_row"><label>Название</label><input type="text" name="title" value="' . htmlspecialchars_uni((string)$editRow['title']) . '" /></div>';
        echo '<div class="form_row"><label>Атрибут</label><select name="attr_key">';
        foreach (af_cs_get_attribute_catalog() as $key => $label) {
            $selected = ((string)$editRow['attr_key'] === $key) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars_uni($key) . '"' . $selected . '>' . htmlspecialchars_uni($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="form_row"><label>Описание</label><textarea name="description" rows="4" style="width:100%;">' . htmlspecialchars_uni((string)$editRow['description']) . '</textarea></div>';
        echo '<div class="form_row"><label>Сортировка</label><input type="number" name="sort_order" value="' . htmlspecialchars_uni((string)$editRow['sort_order']) . '" /></div>';
        $checked = !empty($editRow['active']) ? ' checked' : '';
        echo '<div class="form_row"><label><input type="checkbox" name="active" value="1"' . $checked . '> Активен</label></div>';
        echo '<div class="form_row"><button type="submit" class="button">' . htmlspecialchars_uni($lang->af_charactersheets_admin_save ?? 'Сохранить') . '</button></div>';
        echo '</form>';

        $rows = [];
        $q = $db->simple_select(AF_CS_SKILLS_CATALOG_TABLE, '*', '1=1', ['order_by' => 'sort_order', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($q)) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        echo '<h3>' . htmlspecialchars_uni($lang->af_charactersheets_admin_skills_list ?? 'Список навыков') . '</h3>';
        if (!$rows) {
            echo '<div class="alert">Нет навыков.</div>';
        } else {
            echo '<table class="general">';
            echo '<tr><th>ID</th><th>Slug</th><th>Название</th><th>Атрибут</th><th>Активен</th><th>Действия</th></tr>';
            foreach ($rows as $row) {
                $editLink = $skillsUrl . '&skill_id=' . (int)$row['id'];
                $deleteLink = $skillsUrl . '&delete=' . (int)$row['id'] . '&my_post_key=' . $postKey;
                echo '<tr>';
                echo '<td>' . (int)$row['id'] . '</td>';
                echo '<td>' . htmlspecialchars_uni((string)$row['slug']) . '</td>';
                echo '<td>' . htmlspecialchars_uni((string)$row['title']) . '</td>';
                echo '<td>' . htmlspecialchars_uni((string)$row['attr_key']) . '</td>';
                echo '<td>' . (!empty($row['active']) ? 'Да' : 'Нет') . '</td>';
                echo '<td>'
                    . '<a href="' . htmlspecialchars($editLink, ENT_QUOTES) . '">Редактировать</a> | '
                    . '<a href="' . htmlspecialchars($deleteLink, ENT_QUOTES) . '" onclick="return confirm(\'Удалить?\');">Удалить</a>'
                    . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }

        echo '</div>';
    }

    private static function ensureBootstrapIncluded(): void
    {
        $bootstrap = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php';
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }
    }

    private static function redirect(string $url, string $message): void
    {
        if (function_exists('flash_message')) {
            flash_message($message, 'success');
        }
        admin_redirect($url);
    }
}
