<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/**
 * MyBB Task: AF AAS cleanup
 * Копируется при install() аддона в: /inc/tasks/task_af_aas_cleanup.php
 */
function task_af_aas_cleanup($task)
{
    global $db, $mybb;

    // 1) чистим тени-сессии
    if ($db->table_exists('sessions')) {
        $timeoutMinutes = (int)($mybb->settings['sessiontimeout'] ?? 15);
        if ($timeoutMinutes < 1) { $timeoutMinutes = 15; }

        $cut = TIME_NOW - ($timeoutMinutes * 60);
        $db->delete_query('sessions', "location='af_aas_shadow' AND time < " . (int)$cut);
    }

    // 2) чистим “битые” связи (если юзера удалили)
    if ($db->table_exists('af_aas_links')) {
        $db->query("
            DELETE l FROM " . TABLE_PREFIX . "af_aas_links l
            LEFT JOIN " . TABLE_PREFIX . "users um ON (um.uid = l.master_uid)
            LEFT JOIN " . TABLE_PREFIX . "users ua ON (ua.uid = l.attached_uid)
            WHERE um.uid IS NULL OR ua.uid IS NULL
        ");
    }

    add_task_log($task, "AF AAS cleanup выполнен.");
}
