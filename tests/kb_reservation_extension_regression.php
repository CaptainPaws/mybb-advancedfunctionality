<?php
declare(strict_types=1);

function kb_reservation_extension_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/';
$kb = file_get_contents($root . 'knowledgebase/knowledgebase.php');
$bridge = file_get_contents($root . 'advancedthreadfields/advancedthreadfields.php');
$css = file_get_contents($root . 'knowledgebase/assets/knowledgebase.css');

kb_reservation_extension_assert(is_string($kb) && is_string($bridge) && is_string($css), 'Unable to read reservation sources');
kb_reservation_extension_assert(strpos($kb, "'kb_character_extend'") !== false, 'Extension action is not routed');
kb_reservation_extension_assert(strpos($kb, 'function af_kb_handle_character_extend(): void') !== false, 'Public extension handler is missing');
kb_reservation_extension_assert(strpos($kb, 'name="action" value="kb_character_extend"') !== false, 'Extension form does not submit its action');
kb_reservation_extension_assert(strpos($kb, 'verify_post_check(') !== false, 'Extension endpoint lacks CSRF verification');
kb_reservation_extension_assert(strpos($kb, "'can_extend' => \$category === 'canons'") !== false, 'Extension visibility is not limited to canons');
kb_reservation_extension_assert(strpos($kb, "\$reservedByUid === (int)(\$GLOBALS['mybb']->user['uid'] ?? 0)") !== false, 'Extension visibility is not limited to the reservation owner');
kb_reservation_extension_assert(strpos($kb, "&& \$holdUntilTs >= TIME_NOW && !\$reservationExtended") !== false, 'Extension visibility does not require an active, unused reservation');
kb_reservation_extension_assert(strpos($kb, "(int)(\$meta['active_application_tid'] ?? 0) <= 0") !== false, 'Extension visibility does not exclude submitted applications');
kb_reservation_extension_assert(strpos($kb, "\$availability['reserved_until'] = \$until + af_kb_character_reservation_duration();") !== false, 'Extension is not based on the existing expiry');
kb_reservation_extension_assert(strpos($kb, "\$availability['reservation_extended'] = 1;") !== false, 'Extension use is not persisted');
kb_reservation_extension_assert(strpos($kb, "'reservation_extended' => 0") !== false, 'A new reservation does not reset the extension flag');
kb_reservation_extension_assert(strpos($kb, "\$availability['reservation_extended'] = 0;") !== false, 'Expired reservations do not clear the extension flag');
kb_reservation_extension_assert(strpos($bridge, "\$availability['reservation_extended'] = 0;") !== false, 'Lifecycle release does not clear the extension flag');
kb_reservation_extension_assert(strpos($css, '.af-kb-status-badge--reserved') !== false, 'Reserved status chip has no visual variant');
kb_reservation_extension_assert(strpos($css, '.af-kb-status-link__icon') !== false, 'Profile chip icon layout is not defined');
kb_reservation_extension_assert(strpos($css, 'white-space: nowrap;') !== false, 'Status/profile chips can wrap internally');

echo "KB reservation extension regression checks passed.\n";
