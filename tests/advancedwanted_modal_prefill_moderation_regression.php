<?php
$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$chips = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted_modal.js');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted.css');

function check($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}

check(strpos($core, "'pre_output_page', 'af_wanted_ensure_chip_runtime', 20") !== false
    && strpos($core, "stripos(\$page,'advancedwanted_modal.js')") !== false,
    'late Wanted BBCode guarantees its owner modal runtime exactly once');
check(strpos($chips, "fetch('wanted.php?action=modal&ajax=1&id='") !== false
    && strpos($chips, "event.target === modal") !== false
    && strpos($chips, "closest('.af-wanted-modal-close')") !== false,
    'Wanted uses its live JSON modal with close button and overlay close');
check(strpos($core, "'name_en'=>'character_name'") !== false
    && strpos($core, "'origin'=>'character_origin'") !== false
    && strpos($core, "'weapon'=>'character_weapon'") !== false
    && strpos($core, "'image'=>'character_pic'") !== false,
    'Wanted canonical keys map to canonical ATF names');
check(strpos($core, 'af_atf_prefill_store_save') !== false
    && strpos($core, '&af_atf_prefill_token=') !== false
    && strpos($core, "'wanted_id'=>\$id") !== false,
    'apply uses the existing server-side ATF prefill token transport');
check(strpos($core, 'af_wanted_origin_variant_options($origin)') !== false,
    'origin variant prefill is checked against its origin');
check(strpos($core, "\$action==='moderate_reservation'") !== false
    && strpos($core, "af_wanted_groups('moderate')") !== false
    && strpos($core, "verify_post_check(\$mybb->get_input('my_post_key'))") !== false,
    'moderator reservation mutations are POST, permission and CSRF protected');
check(strpos($core, "'reserved_by_uid'=>\$ownerUid?:null") !== false
    && strpos($core, "DateTimeImmutable::createFromFormat('!Y-m-d'") !== false,
    'moderator stores registered owners by UID and preserves an explicit date');
check(strpos($core, 'af_wanted_status_chip($e)') !== false
    && strpos($core, 'af-wanted-reservation-summary') === false
    && strpos($css, '.af-wanted-status--reservation') !== false,
    'reserved cards render one uppercase combined status chip');
