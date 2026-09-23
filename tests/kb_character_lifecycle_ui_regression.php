<?php
declare(strict_types=1);

function kb_lifecycle_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/knowledgebase';
$php = file_get_contents($addon . '/knowledgebase.php');
$css = file_get_contents($addon . '/assets/knowledgebase.css');
$detail = file_get_contents($addon . '/templates/knowledgebase_view_character.html');
$card = file_get_contents($addon . '/templates/knowledgebase_list_character_entry.html');
$task = file_get_contents($root . '/inc/tasks/af_kb_reservation_cleanup.php');
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');

kb_lifecycle_ui_assert(strpos($task, 'af_kb_cleanup_expired_reservations(TIME_NOW)') !== false, 'MyBB task does not execute persistent cleanup');
kb_lifecycle_ui_assert(strpos($php, 'function af_kb_release_expired_character_reservation') !== false, 'Shared persistent release helper is missing');
kb_lifecycle_ui_assert(strpos($php, "state NOT IN ('archived','draft')") !== false, 'Cleanup does not protect an active workflow relation');
kb_lifecycle_ui_assert(strpos($php, "\$availability['status'] = 'free';") !== false, 'Cleanup does not persist the free machine status');
foreach (['reserved_by_uid', 'reserved_by_name', 'reserved_until', 'reservation_extended'] as $field) {
    kb_lifecycle_ui_assert(strpos($php, "\$availability['{$field}']") !== false, "Cleanup does not clear {$field}");
}
kb_lifecycle_ui_assert(substr_count($php, 'af_kb_release_expired_character_reservation($entry, defined(\'TIME_NOW\') ? TIME_NOW : time())') === 1, 'Read fallback is missing or duplicated');
kb_lifecycle_ui_assert(strpos($php, "'pending' => 'application'") !== false && strpos($php, "'held' => 'reserved'") !== false, 'Legacy lifecycle normalization is missing');
kb_lifecycle_ui_assert(strpos($atf, "af_atf_bridge_update_canon_lifecycle((int)\$entry['id'], \$tid, \$uid, 'application')") !== false, 'Successful ATF thread insert does not enter application state');
kb_lifecycle_ui_assert(strpos($atf, "\$characterMeta['active_application_tid'] = \$status === 'archived' || \$status === 'free' ? 0 : \$tid;") !== false, 'Application transition does not retain the created tid');

kb_lifecycle_ui_assert(strpos($detail, '{$kb_entry_heading}') !== false, 'Conditional detail heading is missing');
kb_lifecycle_ui_assert(strpos($detail, '<div class="af-character-meta-row">{$kb_status_badge}{$kb_status_link}</div>') !== false, 'Detail chip row is missing');
kb_lifecycle_ui_assert(strpos($detail, '{$kb_entry_heading}') < strpos($detail, '<div class="af-character-meta-row">'), 'Detail title is not isolated above chips');
kb_lifecycle_ui_assert(strpos($card, '<h3>{$kb_character_title}</h3>') !== false, 'Catalog title is not isolated from chips');
kb_lifecycle_ui_assert(strpos($card, '<div class="af-character-meta-row">{$kb_character_status_badge}{$kb_character_status_link}</div>') !== false, 'Catalog chip row/profile link is missing');
kb_lifecycle_ui_assert(strpos($css, '.af-kb-status-badge--application') !== false, 'Application chip styling is missing');
kb_lifecycle_ui_assert(strpos($css, '.af-character-meta-row') !== false && strpos($css, 'flex-wrap: wrap') !== false, 'Responsive shared chip row styling is missing');

echo "KB Character lifecycle/UI regression checks passed.\n";
