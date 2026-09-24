<?php
$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$js = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted_modal.js');
$kbJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase_chips.js');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted.css');

function wanted_chip_assert($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}

$rendererStart = strpos($core, 'function af_wanted_parse_message_end');
$rendererEnd = strpos($core, 'function af_wanted_ensure_chip_runtime', $rendererStart);
$renderer = substr($core, $rendererStart, $rendererEnd - $rendererStart);
wanted_chip_assert(strpos($renderer, 'class="af-wanted-post-chip"') !== false
    && strpos($renderer, 'data-wanted-title=') !== false
    && strpos($renderer, 'data-kb-') === false
    && strpos($renderer, 'af-kb-chip') === false,
    'Wanted post renderer has entity-owned classes and data attributes');
wanted_chip_assert(strpos($js, "closest('.af-wanted-post-chip')") !== false
    && strpos($js, "event.key !== 'Enter'") !== false
    && strpos($js, "event.key !== ' '") !== false
    && strpos($js, "event.key === 'Escape'") !== false,
    'Wanted modal supports delegated mouse, keyboard activation and Escape close');
wanted_chip_assert(strpos($kbJs, 'data-wanted-id') === false
    && strpos($kbJs, "wanted.php?action=modal") === false,
    'KB modal runtime has no Wanted entity knowledge');
wanted_chip_assert(strpos($css, 'grid-template-columns: minmax(0, 1fr) minmax(14rem, 19rem)') !== false
    && substr_count($css, 'box-sizing: border-box') >= 4
    && strpos($css, '.af-wanted-field-image { display: block; width: 100%; max-width: 100%; height: auto;') !== false,
    'detail grid and images use bounded Firefox-safe sizing');
wanted_chip_assert(substr_count($core, "' за&nbsp;'.\$owner") === 2,
    'reservation renderers retain visible whitespace before their owner');
