<?php

$root = dirname(__DIR__);
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$kb = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');

function archive_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function archive_lifecycle_function(string $source, string $name, string $next): string
{
    $start = strpos($source, 'function ' . $name);
    $end = strpos($source, 'function ' . $next, $start === false ? 0 : $start + 1);
    return $start !== false && $end !== false ? substr($source, $start, $end - $start) : '';
}

$archive = archive_lifecycle_function($atf, 'af_atf_archive_linked_canon_application', 'af_atf_handle_application_archive_move');
$move = archive_lifecycle_function($atf, 'af_atf_handle_application_archive_move', 'af_atf_bridge_sync_character_kb_from_thread');
$display = archive_lifecycle_function($atf, 'af_atf_build_display_block_for_tid_fid', 'af_atf_message_is_effectively_empty');
$publicRender = archive_lifecycle_function($kb, 'af_kb_render_character_entry', 'af_kb_resolve_character_application_forum_id');

archive_lifecycle_assert(strpos($atf, "'af_atf_application_archive_fid'") !== false, 'Archive FID setting is missing');
archive_lifecycle_assert(strpos($atf, "class_moderation_move_simple") !== false, 'Simple move hook is missing');
archive_lifecycle_assert(strpos($atf, "class_moderation_move_thread_redirect") !== false, 'Redirect move hook is missing');
archive_lifecycle_assert(strpos($move, '$newFid !== $archiveFid') !== false, 'Archive lifecycle is not gated by the explicit FID');
archive_lifecycle_assert(strpos($archive, "['kb_entry_id']") !== false, 'Workflow KB relation is not required');
archive_lifecycle_assert(strpos($archive, "['category'] ?? '')") !== false && strpos($archive, "!== 'canons'") !== false, 'Original characters are not excluded');
archive_lifecycle_assert(strpos($archive, '$activeTid !== $tid') !== false, 'Stale applications can release a newer claim');
archive_lifecycle_assert(strpos($archive, "['canon_baseline']") !== false, 'Baseline is not used for restore');
foreach (['character_profile', 'character_abilities', 'character_links'] as $field) {
    archive_lifecycle_assert(strpos($archive, $field) !== false, "{$field} is not restored");
}
archive_lifecycle_assert(strpos($archive, "['active_application_tid'] = 0") !== false, 'Active application is not cleared');
archive_lifecycle_assert(strpos($archive, "['status'] = 'free'") !== false, 'Canon is not released');
archive_lifecycle_assert(strpos($archive, 'AF_CWF_STATE_ARCHIVED') !== false, 'Application workflow history is not archived');
archive_lifecycle_assert(strpos($display, 'af_atf_get_archive_display_fields($values)') !== false, 'Stored archive values are not rendered');
archive_lifecycle_assert(strpos($archive, "delete_query") === false, 'Archive lifecycle destructively deletes data');
archive_lifecycle_assert(strpos($publicRender, "profile['character_post']") === false, 'Character post remains public in KB');
archive_lifecycle_assert(strpos($publicRender, "profile['character_userinfo']") === false, 'Character user info remains public in KB');
archive_lifecycle_assert(strpos($atf, "'character_post'") !== false && strpos($atf, "'character_userinfo'") !== false, 'Application fields were removed from ATF contract');

echo "Application archive lifecycle regression checks passed\n";
