<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('No direct access');
}

class AF_Admin_Advancedprofileui
{
    public static function dispatch(): void
    {
        echo '<div class="success">AdvancedProfileUI: каркас активен. Управление выполняется через включение/отключение аддона.</div>';
    }
}
