<?php

if (!defined('IN_MYBB')) {
    die('This file cannot be accessed directly.');
}

require_once MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php';

function task_af_aam_cleanup($task): void
{
    global $lang;

    if (!isset($lang->af_aam_name)) {
        $lang->load('advancedfunctionality_advancedalertsandmentions');
    }

    $result = af_aam_cleanup_inactive_alerts();

    if (!empty($result['disabled'])) {
        add_task_log($task, $lang->af_aam_admin_cleanup_disabled ?? 'Inactive cleanup is disabled.');
        return;
    }

    $hasMessage = isset($lang->af_aam_admin_cleanup_done);

    if (($result['alerts_deleted'] ?? 0) === 0) {
        if ($hasMessage) {
            add_task_log($task, str_replace(['{1}', '{2}'], [0, 0], $lang->af_aam_admin_cleanup_done));
        } else {
            add_task_log($task, 'No alerts to delete.');
        }
        return;
    }

    if ($hasMessage) {
        $message = str_replace(
            ['{1}', '{2}'],
            [(int)$result['alerts_deleted'], (int)$result['users_affected']],
            $lang->af_aam_admin_cleanup_done
        );
        add_task_log($task, $message);
    } else {
        add_task_log(
            $task,
            'Deleted ' . (int)$result['alerts_deleted'] . ' alerts for ' . (int)$result['users_affected'] . ' users.'
        );
    }
}