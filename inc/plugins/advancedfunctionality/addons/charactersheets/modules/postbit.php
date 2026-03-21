<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_cs_get_postbit_sheet_payload(int $uid): array
{
    global $lang;

    $payload = [
        'enabled' => false,
        'sheet_url' => '',
        'sheet_slug' => '',
        'button_label' => '',
        'application' => [],
        'application_tid' => 0,
        'application_pid' => 0,
        'application_url' => '',
        'application_topic_url' => '',
        'application_post_url' => '',
        'application_embed_url' => '',
    ];

    if (!af_charactersheets_is_enabled() || $uid <= 0) {
        return $payload;
    }

    if (!isset($lang->af_charactersheets_name)) {
        af_charactersheets_lang();
    }

    $slug = af_charactersheets_get_sheet_slug_by_uid($uid);
    if ($slug !== '') {
        $payload['enabled'] = true;
        $payload['sheet_url'] = af_charactersheets_url(['slug' => $slug]);
        $payload['sheet_slug'] = $slug;
        $payload['button_label'] = (string)($lang->af_charactersheets_sheet_button ?? 'Лист персонажа');
    }

    $application = af_charactersheets_get_application_payload_by_uid($uid);
    if ($application) {
        $payload['application'] = $application;
        $payload['application_tid'] = (int)($application['tid'] ?? 0);
        $payload['application_pid'] = (int)($application['pid'] ?? 0);
        $payload['application_url'] = (string)($application['topic_url'] ?? '');
        $payload['application_topic_url'] = (string)($application['topic_url'] ?? '');
        $payload['application_post_url'] = (string)($application['post_url'] ?? '');
        $payload['application_embed_url'] = (string)($application['embed_url'] ?? '');
    }

    if (($payload['enabled'] || $application) && !af_cs_assets_disabled_for_current_page()) {
        $GLOBALS['af_charactersheets_needs_assets'] = true;
        $GLOBALS['af_charactersheets_needs_modal'] = true;
    }

    return $payload;
}

function af_charactersheets_get_application_payload_by_uid(int $uid): array
{
    static $cache = [];

    if ($uid <= 0) {
        return [];
    }

    if (array_key_exists($uid, $cache)) {
        return (array)$cache[$uid];
    }

    global $db;

    $row = [];
    if ($db->table_exists(AF_CS_TABLE)) {
        $where = 'uid=' . (int)$uid;
        $row = $db->fetch_array($db->simple_select(
            AF_CS_TABLE,
            'tid,accepted_pid,uid,sheet_slug,accepted,accepted_at',
            $where,
            ['order_by' => 'tid', 'order_dir' => 'DESC', 'limit' => 1]
        ));
        if (!is_array($row)) {
            $row = [];
        }
    }

    $tid = (int)($row['tid'] ?? 0);
    if ($tid <= 0) {
        return $cache[$uid] = [];
    }

    $pid = 0;
    if (function_exists('get_thread')) {
        $thread = get_thread($tid);
        $pid = (int)($thread['firstpost'] ?? 0);
    }

    if ($pid <= 0) {
        $pid = (int)($row['accepted_pid'] ?? 0);
    }

    $topicUrl = function_exists('get_thread_link') ? get_thread_link($tid) : ('showthread.php?tid=' . $tid);
    $topicUrl = html_entity_decode((string)$topicUrl, ENT_QUOTES, 'UTF-8');
    $postUrl = $pid > 0
        ? ('showthread.php?tid=' . $tid . '&pid=' . $pid . '#pid' . $pid)
        : $topicUrl;
    $embedUrl = af_charactersheets_url(['action' => 'application', 'tid' => $tid]);

    return $cache[$uid] = [
        'tid' => $tid,
        'pid' => $pid,
        'topic_url' => $topicUrl,
        'post_url' => $postUrl,
        'embed_url' => $embedUrl,
    ];
}

function af_charactersheets_get_sheet_slug_by_uid(int $uid): string
{
    static $cache = [];
    if ($uid <= 0) {
        return '';
    }
    if (array_key_exists($uid, $cache)) {
        return (string)$cache[$uid];
    }

    global $db;
    $slug = '';
    if ($db->table_exists(AF_CS_SHEETS_TABLE)) {
        $row = $db->fetch_array($db->simple_select(
            AF_CS_SHEETS_TABLE,
            'slug',
            "uid=" . (int)$uid . " AND slug<>''",
            ['order_by' => 'id', 'order_dir' => 'DESC', 'limit' => 1]
        ));
        $slug = is_array($row) ? (string)($row['slug'] ?? '') : '';
    }

    if ($slug === '' && $db->table_exists(AF_CS_TABLE)) {
        $row = $db->fetch_array($db->simple_select(
            AF_CS_TABLE,
            'sheet_slug',
            "uid=" . (int)$uid . " AND sheet_created=1 AND sheet_slug<>''",
            ['order_by' => 'tid', 'order_dir' => 'DESC', 'limit' => 1]
        ));
        $slug = is_array($row) ? (string)($row['sheet_slug'] ?? '') : '';
    }

    if ($slug === '' && $db->table_exists(AF_CS_TABLE)) {
        $row = $db->fetch_array($db->simple_select(
            AF_CS_TABLE,
            'sheet_slug',
            "uid=" . (int)$uid . " AND sheet_slug<>''",
            ['order_by' => 'tid', 'order_dir' => 'DESC', 'limit' => 1]
        ));
        $slug = is_array($row) ? (string)($row['sheet_slug'] ?? '') : '';
    }

    $cache[$uid] = $slug;
    return $slug;
}
