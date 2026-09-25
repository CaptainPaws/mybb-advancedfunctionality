<?php

function talent_tree_visual_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$render = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.css');

talent_tree_visual_assert(strpos($render, 'af-cs-arpg-talent-connections') !== false, 'Talent prerequisites must be rendered as an SVG connection layer');
talent_tree_visual_assert(strpos($render, 'af-cs-arpg-tier-line') !== false, 'Talent tiers must have visible level guides');
talent_tree_visual_assert(strpos($render, 'af-cs-arpg-branch-label') !== false, 'Talent branches must be labelled');
talent_tree_visual_assert(strpos($render, '$isRoot = !$prereqs') !== false, 'Nodes without prerequisites must be identified as roots');
talent_tree_visual_assert(strpos($render, "'is-active' : (in_array(\$parentKey, \$activeKeys, true) ? 'is-open' : 'is-locked')") !== false, 'Connections must communicate progression state');
talent_tree_visual_assert(strpos($render, 'aria-label=') !== false, 'Talent nodes must expose their state to assistive technology');
talent_tree_visual_assert(strpos($css, '.af-cs-arpg-talent-node.is-active .af-cs-arpg-talent-orbit') !== false, 'Active nodes must have a distinct treatment');
talent_tree_visual_assert(strpos($css, '@media (prefers-reduced-motion:reduce)') !== false, 'Talent animation must honor reduced motion preferences');

echo "CharacterSheets ARPG talent tree visual regression checks passed.\n";
