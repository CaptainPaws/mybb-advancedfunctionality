<?php
$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$admin = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/admin.php');
$modal = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted_modal.js');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted.css');

$checks = [
    'guest and user durations are separate settings with 3/7 defaults' =>
        strpos($core, "'af_wanted_guest_reservation_days','Срок брони гостя, дней','3'") !== false
        && strpos($core, "'af_wanted_user_reservation_days','Срок брони пользователя, дней','7'") !== false
        && strpos($core, 'af_wanted_reservation_days($uid===0)*86400') !== false,
    'expired reservations normalize in the AF runtime lifecycle' =>
        strpos($core, 'AF invokes addon init from its global_start bootstrap') !== false
        && strpos($core, 'af_wanted_normalize_expired_reservations();') !== false
        && strpos($core, "reserved_until>0 AND reserved_until<") !== false
        && strpos($core, 'application_tid IS NULL') !== false,
    'guest reservation does not block apply and is consumed by application' =>
        strpos($core, '$guestReservation=') !== false
        && strpos($core, "reserved_by_uid IS NULL AND reserved_guest_name<>''") !== false
        && strpos($core, "reserved_by_uid=NULL,reserved_guest_name='',reserved_at=0,reserved_until=0") !== false,
    'ACP supports owner mode owner value and exact date override' =>
        strpos($admin, 'name="reservation_owner_type"') !== false
        && strpos($admin, 'Пользователь / гость') !== false
        && strpos($admin, 'type="date" name="reserved_until"') !== false
        && strpos($admin, "'reserved_until' => (int)\$until") !== false,
    'Wanted chips use the owner modal runtime by stable ID' =>
        strpos($core, 'data-wanted-id=') !== false
        && strpos($core, 'advancedwanted_modal.js') !== false
        && strpos($modal, "fetch('wanted.php?action=modal&ajax=1&id='") !== false,
    'cards put status below title and use a full-width cropped image' =>
        strpos($css, 'flex-direction: column') !== false
        && strpos($css, 'aspect-ratio: 4 / 3') !== false
        && strpos($css, 'object-fit: cover') !== false
        && strpos($css, 'object-position: center') !== false,
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) $failed[] = $label;
}
exit($failed ? 1 : 0);
