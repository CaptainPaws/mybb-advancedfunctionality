<?php
if(!defined('IN_MYBB')){die('No direct access');}
function task_af_acs_notifications($task):void
{
    global $db;
    try{
        if(!function_exists('af_is_addon_enabled')||!af_is_addon_enabled('advancedcombatsystem')||!af_is_addon_enabled('advancedalertsandmentions'))return;
        require_once MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php';
        require_once MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Application/NotificationService.php';
        $r=(new AF_ACS_NotificationService($db))->run();
        add_task_log($task,'ACS alerts: bounded batch completed; sessions='.$r['sessions'].'; pending='.$r['pending'].'. No mechanical action executed.');
    }catch(Throwable){add_task_log($task,'ACS alerts: delivery unavailable; retained keys remain pending. No combat rollback or new publication.');}
}
