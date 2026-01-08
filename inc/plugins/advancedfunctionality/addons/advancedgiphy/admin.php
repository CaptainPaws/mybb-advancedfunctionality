<?php
/**
 * AF Addon: AdvancedGiphy — Admin controller for AF router
 *
 * Если твой роутер AF ожидает return string — используй render().
 * Если он ожидает echo — dispatch() тоже выведет.
 */
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }

class AF_Admin_Advancedgiphy
{
    public static function dispatch(string $action = ''): string
    {
        $html = self::render($action);
        // на всякий случай: если роутер не использует return, он увидит echo
        echo $html;
        return $html;
    }

    public static function render(string $action = ''): string
    {
        global $db, $mybb, $lang;

        $title = isset($lang->af_advancedgiphy_admin_title) ? $lang->af_advancedgiphy_admin_title : 'AdvancedGiphy';
        $intro = isset($lang->af_advancedgiphy_admin_intro) ? $lang->af_advancedgiphy_admin_intro : 'GIPHY button for editor.';
        $btn   = isset($lang->af_advancedgiphy_admin_open_settings) ? $lang->af_advancedgiphy_admin_open_settings : 'Open settings';

        $gid = 0;
        $q = $db->simple_select('settinggroups', 'gid', "name='af_advancedgiphy'", ['limit' => 1]);
        $row = $db->fetch_array($q);
        if (!empty($row['gid'])) {
            $gid = (int)$row['gid'];
        }

        $settingsUrl = 'index.php?module=config-settings';
        if ($gid > 0) {
            $settingsUrl .= '&action=change&gid='.$gid;
        }

        $enabled = !empty($mybb->settings['af_advancedgiphy_enabled']) ? 'Yes' : 'No';
        $limit   = (int)($mybb->settings['af_advancedgiphy_limit'] ?? 25);
        $rating  = htmlspecialchars((string)($mybb->settings['af_advancedgiphy_rating'] ?? 'g'));
        $maxw    = (int)($mybb->settings['af_advancedgiphy_maxwidth'] ?? 100);

        $html = '';
        $html .= '<div class="af-box">';
        $html .= '<h2 style="margin:0 0 8px 0;">'.htmlspecialchars($title).'</h2>';
        $html .= '<p style="margin:0 0 12px 0;">'.htmlspecialchars($intro).'</p>';

        $html .= '<table class="general" cellspacing="0" cellpadding="5" style="width:100%;max-width:760px;">';
        $html .= '<tr><td style="width:220px;"><strong>Enabled</strong></td><td>'.$enabled.'</td></tr>';
        $html .= '<tr><td><strong>Limit</strong></td><td>'.$limit.'</td></tr>';
        $html .= '<tr><td><strong>Rating</strong></td><td>'.$rating.'</td></tr>';
        $html .= '<tr><td><strong>Max width</strong></td><td>'.$maxw.' px</td></tr>';
        $html .= '</table>';

        $html .= '<div style="margin-top:12px;">';
        $html .= '<a class="button button-primary" href="'.htmlspecialchars($settingsUrl).'">'.$btn.'</a>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}
