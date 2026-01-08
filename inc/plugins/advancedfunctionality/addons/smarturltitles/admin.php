<?php
/**
 * AF Addon Admin: Smart URL Titles
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { /* этот файл подключает AF router внутри ACP */ }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

class AF_Admin_Smarturltitles
{
    public static function dispatch(): void
    {
        global $mybb, $db, $page;

        if (!isset($page) || !is_object($page)) {
            return;
        }

        $page->add_breadcrumb_item('Smart URL Titles');

        $gid = 0;
        $q = $db->simple_select('settinggroups', 'gid', "name='".$db->escape_string(AF_SUT_GROUP)."'");
        if ($db->num_rows($q)) {
            $gid = (int)$db->fetch_field($q, 'gid');
        }

        $settings_url = 'index.php?module=config-settings';
        if ($gid > 0) {
            $settings_url .= '&action=change&gid='.$gid;
        }

        $enabled = !empty($mybb->settings[AF_SUT_SETTING_ENABLED]);

        $curl_ok = function_exists('curl_init') ? 'Да' : 'Нет';
        $timeout = (string)($mybb->settings[AF_SUT_SETTING_TIMEOUT] ?? '4');
        $count   = (string)($mybb->settings[AF_SUT_SETTING_URL_COUNT] ?? '10');
        $len     = (string)($mybb->settings[AF_SUT_SETTING_TITLE_LENGTH] ?? '150');
        $range   = (string)($mybb->settings[AF_SUT_SETTING_RANGE] ?? '500000');

        $page->output_header('Smart URL Titles');

        echo '<div class="alert alert_info" style="margin: 10px 0;">'
            .'<strong>Smart URL Titles</strong><br>'
            .'Статус: <b>'.($enabled ? 'Включён' : 'Выключён').'</b><br>'
            .'cURL: <b>'.$curl_ok.'</b><br>'
            .'Таймаут: <b>'.htmlspecialchars_uni($timeout).'</b> сек, '
            .'Лимит ссылок: <b>'.htmlspecialchars_uni($count).'</b>, '
            .'Макс. длина title: <b>'.htmlspecialchars_uni($len).'</b>, '
            .'Range: <b>'.htmlspecialchars_uni($range).'</b> байт<br>'
            .'<a class="button" href="'.htmlspecialchars_uni($settings_url).'">Открыть настройки</a>'
            .'</div>';

        echo '<div class="alert alert_warning" style="margin: 10px 0;">'
            .'Подстановка title выполняется при отправке/превью/quick-edit. '
            .'Если сайт медленный/закрыт — заголовок останется URL.'
            .'</div>';

        $page->output_footer();
    }
}
