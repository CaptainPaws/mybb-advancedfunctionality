<?php
/**
 * AF Addon: AdvancedFontAwesome — Admin controller for AF router
 */
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }

class AF_Admin_Advancedfontawesome
{
    public static function dispatch(string $action = ''): string
    {
        $html = self::render($action);
        echo $html;
        return $html;
    }

    public static function render(string $action = ''): string
    {
        global $lang;

        $title = $lang->af_advancedfontawesome_admin_title ?? 'Font Awesome';
        $intro = $lang->af_advancedfontawesome_description ?? 'Font Awesome forum icons.';

        $html = '';
        $html .= '<div class="af-box">';
        $html .= '<h2 style="margin:0 0 8px 0;">' . htmlspecialchars($title) . '</h2>';
        $html .= '<p style="margin:0 0 12px 0;">' . htmlspecialchars($intro) . '</p>';

        $html .= '<div style="border:1px solid #ddd; background:#fff;">';
        $html .= '<iframe title="Forum management" src="index.php?module=forum" style="width:100%;height:900px;border:0;" loading="lazy"></iframe>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
