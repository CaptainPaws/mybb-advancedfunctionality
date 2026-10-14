<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) die('No direct access');

/** Validated ACP editor, using the existing AF admin controller dispatcher. */
class AF_Admin_Adaptivethemeframework
{
    public static function dispatch(string $action = ''): void
    {
        global $mybb, $db;
        af_adaptivethemeframework_ensure_page_settings();
        $definitions = af_adaptivethemeframework_page_setting_definitions();
        $values = (array)$mybb->settings;
        $error = '';
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $values = [];
            foreach ($definitions as $key => $definition) $values[$key] = $mybb->get_input($key);
            $validated = af_adaptivethemeframework_validate_page_settings($values);
            foreach ($values as $key => $raw) {
                if (!preg_match('/^\d+$/D', (string)$raw) || (int)$raw !== $validated[$key]) {
                    $error = 'Проверьте диапазоны значений и порядок narrow < mobile < tablet.';
                    break;
                }
            }
            if ($error === '') {
                foreach ($validated as $key => $value) {
                    $db->update_query('settings', ['value' => (string)$value], "name='" . $db->escape_string($key) . "'");
                }
                af_rebuild_and_reload_settings();
                flash_message('Настройки внешней рамки сохранены.', 'success');
                admin_redirect('index.php?module=advancedfunctionality&af_view=adaptivethemeframework');
                return;
            }
        }
        echo '<h2>Adaptive Theme Framework — внешняя рамка</h2>';
        if ($error !== '') echo '<p class="error">' . htmlspecialchars_uni($error) . '</p>';
        echo '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=adaptivethemeframework">';
        echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        echo '<p>Все значения в px. narrow &lt; mobile &lt; tablet.</p>';
        foreach ($definitions as $key => [$title, $default, $min, $max]) {
            echo '<p><label>' . htmlspecialchars_uni($title) . ' <input type="number" required name="' . $key . '" min="' . $min . '" max="' . $max . '" value="' . htmlspecialchars_uni((string)($values[$key] ?? $default)) . '"></label> (' . $min . '–' . $max . 'px)</p>';
        }
        echo '<button type="submit">Сохранить</button></form>';
    }
}
