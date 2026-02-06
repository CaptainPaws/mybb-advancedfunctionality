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

        self::ensureBootstrapIncluded();
        af_charactersheets_lang();

        if (isset($page) && method_exists($page, 'add_breadcrumb_item')) {
            $page->add_breadcrumb_item('CharacterSheets', $baseUrl);
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
