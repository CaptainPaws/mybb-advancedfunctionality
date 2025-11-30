<?php
/**
 * AF Admin Controller: Fast News (внутри /addons/fastnews/)
 * Страница редактирования контента блока:
 *  - сверху: предпросмотр
 *  - ниже: форма редактирования ТОЛЬКО af_fastnews_html
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

class AF_Admin_Fastnews
{
    public static function dispatch(): void
    {
        global $mybb, $db, $lang, $page;

        // Язык аддона
        if (function_exists('af_load_addon_lang')) {
            af_load_addon_lang('fastnews');
        }

        // Тексты
        $title        = $lang->af_fastnews_admin_title      ?? 'Быстрые новости';
        $txt_preview  = $lang->af_fastnews_admin_preview    ?? 'Предпросмотр';
        $txt_quick    = $lang->af_fastnews_admin_quickedit  ?? 'Редактирование';
        $txt_save     = $lang->af_fastnews_admin_save       ?? 'Сохранить';
        $txt_settings = $lang->af_fastnews_admin_settings   ?? 'Перейти в полные настройки';
        $txt_saved    = $lang->af_fastnews_admin_saved      ?? 'Содержимое сохранено.';

        // Хлебные крошки
        if (isset($page) && method_exists($page, 'add_breadcrumb_item')) {
            $page->add_breadcrumb_item('Advanced functionality', 'index.php?module='.AF_PLUGIN_ID);
            $page->add_breadcrumb_item($title);
        }

        // Загружаем текущее содержимое (ТОЛЬКО контент)
        $html = (string)($mybb->settings['af_fastnews_html'] ?? '');
        $saved = false;

        // Сохранение ТОЛЬКО af_fastnews_html
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $new_html = (string)$mybb->get_input('af_fastnews_html');
            self::update_setting('af_fastnews_html', $new_html);

            if (function_exists('rebuild_settings')) { rebuild_settings(); }

            $html  = $new_html;
            $saved = true;
        }

        // Предпросмотр (BBCode → HTML)
        $preview_html = '';
        if ($html !== '') {
            if (!function_exists('af_fastnews_parse_message')) {
                require_once MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/fastnews/fastnews.php';
            }
            $preview_html = af_fastnews_parse_message($html);
        }

        // Ссылка в системные настройки (там остались enable + visible_for)
        $gid = self::get_group_id('af_fastnews');

        // ===== Рендер =====
        if ($saved) {
            echo '<div class="success">'.htmlspecialchars_uni($txt_saved).'</div>';
        }

        echo '<h2>'.htmlspecialchars_uni($title).'</h2>';

        // Блок превью
        echo '<div class="group_title">'.htmlspecialchars_uni($txt_preview).'</div>';
        echo '<div class="border_wrapper">';
        echo '  <div class="af_fastnews" style="padding:12px; background:#f9f9f9; border:1px solid #ddd;">';
        echo        ($preview_html !== '' ? $preview_html : '<em style="color:#888">'
                     .htmlspecialchars_uni($lang->af_fastnews_html_desc ?? 'Можно HTML/BBCode (если разрешено на форуме).')
                     .'</em>');
        echo '  </div>';
        echo '</div>';

        // Форма редактирования контента (без переключателей/групп!)
        $action_url = 'index.php?module='.AF_PLUGIN_ID.'&af_view=fastnews';

        echo '<div class="group_title" style="margin-top:16px;">'.htmlspecialchars_uni($txt_quick).'</div>';
        echo '<div class="border_wrapper">';
        echo '  <form method="post" action="'.htmlspecialchars_uni($action_url).'" style="margin:0;">';
        echo '    <input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';

        echo '    <div class="row">';
        echo '      <label>'.htmlspecialchars_uni($lang->af_fastnews_html ?? 'Содержимое блока').'</label><br />';
        echo '      <textarea name="af_fastnews_html" rows="10" style="width:100%;">'.htmlspecialchars_uni($html).'</textarea>';
        echo '      <div class="description">'.htmlspecialchars_uni($lang->af_fastnews_html_desc ?? 'Можно HTML/BBCode (если разрешено на форуме).').'</div>';
        echo '    </div>';

        echo '    <div class="row" style="margin-top:10px; display:flex; gap:8px; align-items:center;">';
        echo '      <input type="submit" class="button" value="'.htmlspecialchars_uni($txt_save).'" />';
        if ($gid) {
            $settings_url = 'index.php?module=config-settings&action=change&gid='.(int)$gid;
            echo '      <a class="button" href="'.htmlspecialchars_uni($settings_url).'">'.htmlspecialchars_uni($txt_settings).'</a>';
        }
        echo '    </div>';

        echo '  </form>';
        echo '</div>';
    }

    private static function update_setting(string $name, string $value): void
    {
        global $db;
        $db->update_query('settings',
            ['value' => $db->escape_string($value)],
            "name='".$db->escape_string($name)."'"
        );
    }

    private static function get_group_id(string $group_name): int
    {
        global $db;
        $q = $db->simple_select('settinggroups', 'gid', "name='".$db->escape_string($group_name)."'", ['limit'=>1]);
        $gid = (int)$db->fetch_field($q, 'gid');
        return $gid ?: 0;
    }
}
