<?php

if (!defined('IN_MYBB')) {
    die('This file cannot be accessed directly.');
}

require_once MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php';

function task_af_kb_reservation_cleanup($task): void
{
    $released = af_kb_cleanup_expired_reservations(TIME_NOW);
    add_task_log($task, 'Released ' . $released . ' expired Character reservation(s).');
}
