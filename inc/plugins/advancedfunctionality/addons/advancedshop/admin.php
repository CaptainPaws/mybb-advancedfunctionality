<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }

class AF_Admin_Advancedshop
{
    public static function dispatch(string $action = ''): string
    {
        $html = self::render($action);
        echo $html;
        return $html;
    }

    public static function render(string $action = ''): string
    {
        global $db, $lang;

        $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedshop'", ['limit' => 1]), 'gid');
        $settingsUrl = 'index.php?module=config-settings' . ($gid > 0 ? '&action=change&gid=' . $gid : '');

        $html = '';
        $html .= '<div class="af-box">';
        $html .= '<h2>Advanced Shop</h2>';
        $html .= '<p>' . htmlspecialchars_uni($lang->af_advancedshop_description ?? 'Game shop and inventory addon.') . '</p>';
        $html .= '<ul>';
        $html .= '<li><a href="../shop.php?shop=game">shop.php?shop=game</a></li>';
        $html .= '<li><a href="../shop.php?action=shop_manage&amp;shop=game">shop.php?action=shop_manage&shop=game</a></li>';
        $html .= '<li><a href="../shop.php?action=inventory">shop.php?action=inventory</a></li>';
        $html .= '</ul>';
        $html .= '<p><a class="button button-primary" href="' . htmlspecialchars_uni($settingsUrl) . '">Settings</a></p>';
        $html .= '</div>';

        return $html;
    }
}
