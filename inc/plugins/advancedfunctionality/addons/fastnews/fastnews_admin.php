<?php
/**
 * AF Admin Controller: Fast News (внутри /addons/fastnews/)
 *
 * Две страницы:
 * 1) Контент:  index.php?module=advancedfunctionality&af_view=fastnews
 * 2) Настройки: index.php?module=advancedfunctionality&af_view=fastnews&action=settings
 *
 * Важно: контент хранится в datacache (НЕ settings.php).
 * Вставка на фронте через {$fastnews}.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

class AF_Admin_Fastnews
{
    public static function dispatch(): void
    {
        global $mybb, $db, $lang, $page;

        if (function_exists('af_load_addon_lang')) {
            af_load_addon_lang('fastnews');
        }

        // Подключаем bootstrap для функций cache/parse/build
        if (!function_exists('af_fastnews_get_raw')) {
            require_once MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/fastnews/fastnews.php';
        }

        $title = $lang->af_fastnews_admin_title ?? 'Быстрые новости';

        if (isset($page) && method_exists($page, 'add_breadcrumb_item')) {
            $page->add_breadcrumb_item('Advanced functionality', 'index.php?module='.AF_PLUGIN_ID);
            $page->add_breadcrumb_item($title);
        }

        $action = (string)$mybb->get_input('action');

        // Навигация (две кнопки-табки)
        $base_url     = 'index.php?module='.AF_PLUGIN_ID.'&af_view=fastnews';
        $url_content  = $base_url;
        $url_settings = $base_url.'&action=settings';

        echo '<h2>'.htmlspecialchars_uni($title).'</h2>';
        echo '<div style="margin:10px 0; display:flex; gap:8px;">';
        echo '  <a class="button'.($action === 'settings' ? '' : ' active').'" href="'.htmlspecialchars_uni($url_content).'">'
             .htmlspecialchars_uni($lang->af_fastnews_admin_quickedit ?? 'Контент').'</a>';
        echo '  <a class="button'.($action === 'settings' ? ' active' : '').'" href="'.htmlspecialchars_uni($url_settings).'">'
             .htmlspecialchars_uni($lang->af_fastnews_admin_settings_tab ?? 'Настройки').'</a>';
        echo '</div>';

        if ($action === 'settings') {
            self::render_settings($base_url);
            return;
        }

        self::render_content($base_url);
    }

    private static function render_content(string $base_url): void
    {
        global $mybb, $lang;

        $txt_preview = $lang->af_fastnews_admin_preview ?? 'Предпросмотр';
        $txt_edit    = $lang->af_fastnews_admin_quickedit ?? 'Редактирование';
        $txt_save    = $lang->af_fastnews_admin_save ?? 'Сохранить';
        $txt_saved   = $lang->af_fastnews_admin_saved ?? 'Содержимое сохранено.';

        $raw   = af_fastnews_get_raw();
        $saved = false;

        if ($mybb->request_method === 'post' && $mybb->get_input('form') === 'content') {
            verify_post_check($mybb->get_input('my_post_key'));

            $new_raw = (string)$mybb->get_input('af_fastnews_html');
            af_fastnews_set_raw($new_raw);

            $raw   = $new_raw;
            $saved = true;
        }

        if ($saved) {
            echo '<div class="success">'.htmlspecialchars_uni($txt_saved).'</div>';
        }

        // Предпросмотр
        $preview_html = '';
        if ($raw !== '') {
            $preview_html = af_fastnews_parse_message($raw);
        }

        echo '<div class="group_title">'.htmlspecialchars_uni($txt_preview).'</div>';
        echo '<div class="border_wrapper">';
        echo '  <div class="af_fastnews" style="padding:12px; background:#f9f9f9; border:1px solid #ddd;">';
        echo        ($preview_html !== '' ? $preview_html : '<em style="color:#888">'
                     .htmlspecialchars_uni($lang->af_fastnews_html_desc ?? 'Можно HTML/BBCode (если разрешено на форуме).')
                     .'</em>');
        echo '  </div>';
        echo '</div>';

        // Форма редактирования
        echo '<div class="group_title" style="margin-top:16px;">'.htmlspecialchars_uni($txt_edit).'</div>';
        echo '<div class="border_wrapper">';
        echo '  <form method="post" action="'.htmlspecialchars_uni($base_url).'" style="margin:0;">';
        echo '    <input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
        echo '    <input type="hidden" name="form" value="content" />';

        echo '    <div class="row">';
        echo '      <label>'.htmlspecialchars_uni($lang->af_fastnews_html ?? 'Содержимое блока').'</label><br />';
        echo '      <textarea name="af_fastnews_html" rows="10" style="width:100%;">'.htmlspecialchars_uni($raw).'</textarea>';
        echo '      <div class="description">'.htmlspecialchars_uni(
                    $lang->af_fastnews_html_help ?? 'Вставка на форуме через переменную шаблона: {$fastnews}'
                ).'</div>';
        echo '    </div>';

        echo '    <div class="row" style="margin-top:10px; display:flex; gap:8px; align-items:center;">';
        echo '      <input type="submit" class="button" value="'.htmlspecialchars_uni($txt_save).'" />';
        echo '    </div>';

        echo '  </form>';
        echo '</div>';
    }

    private static function render_settings(string $base_url): void
    {
        global $mybb, $db, $lang;

        $txt_save  = $lang->af_fastnews_admin_save ?? 'Сохранить';
        $txt_saved = $lang->af_fastnews_admin_saved ?? 'Настройки сохранены.';

        $saved = false;

        if ($mybb->request_method === 'post' && $mybb->get_input('form') === 'settings') {
            verify_post_check($mybb->get_input('my_post_key'));

            $enabled = (int)$mybb->get_input('af_fastnews_enabled');
            $groups  = trim((string)$mybb->get_input('af_fastnews_visible_for'));

            self::update_setting('af_fastnews_enabled', (string)$enabled);
            self::update_setting('af_fastnews_visible_for', $groups);

            if (function_exists('rebuild_settings')) {
                rebuild_settings();
            }

            $saved = true;
        }

        if ($saved) {
            echo '<div class="success">'.htmlspecialchars_uni($txt_saved).'</div>';
        }

        $enabled = (int)($mybb->settings['af_fastnews_enabled'] ?? 0);
        $groups  = (string)($mybb->settings['af_fastnews_visible_for'] ?? '');

        echo '<div class="group_title">'.htmlspecialchars_uni($lang->af_fastnews_admin_settings ?? 'Настройки').'</div>';
        echo '<div class="border_wrapper">';
        echo '  <form method="post" action="'.htmlspecialchars_uni($base_url.'&action=settings').'" style="margin:0;">';
        echo '    <input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
        echo '    <input type="hidden" name="form" value="settings" />';

        // enabled
        echo '    <div class="row">';
        echo '      <label>'.htmlspecialchars_uni($lang->af_fastnews_enabled ?? 'Включить блок').'</label><br />';
        echo '      <select name="af_fastnews_enabled">';
        echo '        <option value="1"'.($enabled ? ' selected="selected"' : '').'>'.$lang->yes.'</option>';
        echo '        <option value="0"'.(!$enabled ? ' selected="selected"' : '').'>'.$lang->no.'</option>';
        echo '      </select>';
        echo '      <div class="description">'.htmlspecialchars_uni($lang->af_fastnews_enabled_desc ?? 'Да/Нет').'</div>';
        echo '    </div>';

        // visible_for
        echo '    <div class="row" style="margin-top:10px;">';
        echo '      <label>'.htmlspecialchars_uni($lang->af_fastnews_visible_for ?? 'ID групп, через запятую').'</label><br />';
        echo '      <input type="text" name="af_fastnews_visible_for" value="'.htmlspecialchars_uni($groups).'" style="width:100%;" />';
        echo '      <div class="description">'.htmlspecialchars_uni($lang->af_fastnews_visible_for_desc ?? 'Пусто — показывать всем.').'</div>';
        echo '    </div>';

        echo '    <div class="row" style="margin-top:10px; display:flex; gap:8px; align-items:center;">';
        echo '      <input type="submit" class="button" value="'.htmlspecialchars_uni($txt_save).'" />';
        echo '    </div>';

        echo '  </form>';
        echo '</div>';
    }

    private static function update_setting(string $name, string $value): void
    {
        global $db;
        $db->update_query(
            'settings',
            ['value' => $db->escape_string($value)],
            "name='".$db->escape_string($name)."'"
        );
    }
}
