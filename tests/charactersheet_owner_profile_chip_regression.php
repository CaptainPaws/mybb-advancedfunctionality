<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$render = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');
$progress = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/templates/blocks/progress.html');
$identity = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/templates/blocks/arpg_identity.html');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.css');

function owner_profile_chip_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

owner_profile_chip_assert(strpos($render, "(int)(\$sheet['uid'] ?? 0)") !== false, 'Profile chip does not use the actual sheet owner uid');
owner_profile_chip_assert(strpos($render, "if (\$owner_uid <= 0)") !== false, 'Invalid owner uid is not rejected');
owner_profile_chip_assert(strpos($render, "'/member.php?action=profile&amp;uid=' . \$owner_uid") !== false, 'Profile URL does not contain the owner uid');
owner_profile_chip_assert(strpos($render, 'character_meta.source_uid') === false, 'Profile target must not use source_uid as a fallback');
owner_profile_chip_assert(strpos($progress, '{$profile_chip_html}') !== false, 'Standard sheet profile chip is missing beside progress');
owner_profile_chip_assert(strpos($identity, '{$sheet_profile_chip_html}') !== false, 'ARPG sheet profile chip is missing beside the level');
owner_profile_chip_assert(strpos($progress, 'af-cs-level-chip') !== false && strpos($identity, 'af-cs-level-chip') !== false, 'Level chips do not use the shared style');
owner_profile_chip_assert(strpos($css, '.af-cs-owner-chips .af-cs-level-chip,') !== false, 'Shared level/profile chip styling is missing');
owner_profile_chip_assert(strpos($css, '.af-cs-owner-chips .af-cs-profile-chip:hover') !== false, 'Profile chip hover state is missing');
owner_profile_chip_assert(strpos($css, '.af-cs-owner-chips .af-cs-profile-chip:focus-visible') !== false, 'Profile chip focus state is missing');

echo "OK\n";
