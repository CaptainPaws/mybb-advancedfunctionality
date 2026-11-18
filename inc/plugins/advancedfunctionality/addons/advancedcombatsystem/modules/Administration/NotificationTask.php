<?php
if(!defined('IN_MYBB')){die('No direct access');}
/** Controlled ACS lifecycle only, never a showthread/consumer schema repair. */
function af_advancedcombatsystem_install_notification_task():void
{
    global $db;
    $source=__DIR__.'/../../tasks/task_af_acs_notifications.php';$destination=MYBB_ROOT.'inc/tasks/af_acs_notifications.php';
    if(!is_dir(dirname($destination))||file_put_contents($destination,file_get_contents($source))===false)throw new RuntimeException('ACS_ALERT_TASK_INSTALL_FAILED');
    require_once MYBB_ROOT.'inc/functions_task.php';
    $old=$db->fetch_array($db->simple_select('tasks','tid',"file='af_acs_notifications'",['limit'=>1]));
    if($old)return; // Preserve the owner's enabled/scheduling preferences on upgrade.
    $task=['title'=>'ACS: AAM outbox','description'=>'Bounded idempotent notification delivery; no combat execution','file'=>'af_acs_notifications','minute'=>'*','hour'=>'*','day'=>'*','month'=>'*','weekday'=>'*','enabled'=>1,'logging'=>1];
    $task['nextrun']=fetch_next_run($task);$db->insert_query('tasks',$task);
}
