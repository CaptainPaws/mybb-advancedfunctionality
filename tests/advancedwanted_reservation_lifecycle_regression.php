<?php
$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$admin = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/admin.php');

$checks = [
    'reserve is a CSRF-protected POST action' => strpos($core, 'verify_post_check') !== false
        && strpos($core, 'method="post" action="wanted.php?action=reserve&id=') !== false,
    'reserve acquisition is atomic' => strpos($core, "id=\$id AND status='open'") !== false
        && strpos($core, "affected_rows()!==1") !== false,
    'reservation owner can release with an atomic owner guard' => strpos($core, "if(\$action==='release')") !== false
        && strpos($core, "AND status='reserved' AND reserved_by_uid=") !== false
        && strpos($core, "af_wanted_lifecycle_data('release_reservation')") !== false,
    'release UI is shown only to the reservation owner' => strpos($core, '$ownsReservation=') !== false
        && strpos($core, 'action="wanted.php?action=release&id=') !== false,
    'other users cannot see apply while reserved' => strpos($core, "(\$e['status']==='open'||\$ownsReservation)") !== false,
    'reserved user profile is rendered on cards and detail' => strpos($core, 'af_wanted_reservation_html') !== false && strpos($core, 'Придержано за: ') !== false
        && strpos($core, 'ru.username reserved_name') !== false,
    'open normalization clears all lifecycle relations' => strpos($core, "'reserved_by_uid'=>null,'reserved_guest_name'=>'','reserved_at'=>0,'reserved_until'=>0") !== false,
    'archive normalization clears incompatible relations' => strpos($core, "'status'=>'archived','reserved_by_uid'=>null,'reserved_guest_name'=>'','reserved_at'=>0,'reserved_until'=>0") !== false,
    'ACP exposes dedicated lifecycle actions without a status dropdown' => strpos($admin, "['release_reservation', 'return_active', 'archive_entry']") !== false
        && strpos($admin, 'Снять reservation') !== false
        && strpos($admin, 'Вернуть в Active') !== false
        && strpos($admin, 'Отправить в Archive') !== false
        && strpos($admin, 'name="status"') === false,
    'ACP lifecycle actions are CSRF protected' => strpos($admin, 'verify_post_check') !== false
        && strpos($admin, 'name="my_post_key"') !== false,
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        $failed[] = $label;
    }
}
exit($failed ? 1 : 0);
