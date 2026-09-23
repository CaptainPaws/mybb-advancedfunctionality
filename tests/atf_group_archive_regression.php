<?php
declare(strict_types=1);

function group_archive_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$admin = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/admin.php');

group_archive_assert(is_string($atf) && is_string($admin), 'Unable to read ATF sources');
group_archive_assert(strpos($atf, '`archive_fid` INT UNSIGNED NOT NULL DEFAULT 0') !== false, 'Group archive_fid schema is missing');
group_archive_assert(strpos($admin, "'archive_fid'  => \$archiveFid") !== false, 'Group archive_fid is not persisted');
group_archive_assert(strpos($admin, "generate_numeric_field('archive_fid'") !== false, 'Group archive_fid control is missing');
group_archive_assert(strpos($atf, 'function af_atf_get_group_archive_forum_id') !== false, 'Per-group archive resolver is missing');
group_archive_assert(strpos($atf, "['af_atf_application_archive_fid']") !== false, 'Legacy global fallback was removed');
group_archive_assert(strpos($atf, 'af_atf_get_group_for_thread($tid, $sourceFid)') !== false, 'Move does not resolve the thread group');
group_archive_assert(strpos($atf, '!af_atf_group_is_character_application($group)') !== false, 'Character lifecycle is not constrained to a character group');
group_archive_assert(strpos($atf, 'af_atf_get_group_archive_forum_id($group)') !== false, 'Move does not use the resolved group archive');
group_archive_assert(strpos($atf, 'af_atf_is_application_archive_forum($fid, $tid)') !== false, 'Archive display is not group-aware');

echo "ATF per-group archive regression checks passed.\n";
