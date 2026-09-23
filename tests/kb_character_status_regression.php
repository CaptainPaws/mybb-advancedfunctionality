<?php
declare(strict_types=1);

function kb_character_status_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/';
$php = file_get_contents($root . 'knowledgebase.php');
$editorJs = file_get_contents($root . 'assets/knowledgebase.js');
$viewJs = file_get_contents($root . 'assets/knowledgebase_chips.js');

kb_character_status_assert(is_string($php) && is_string($editorJs) && is_string($viewJs), 'Unable to read KB status sources');
kb_character_status_assert(strpos($php, 'data-af-kb-status-open="1"') !== false, 'Status button is missing');
kb_character_status_assert(strpos($php, 'name="entry_id"') !== false, 'Status form does not submit the immutable entry id');
kb_character_status_assert(strpos($viewJs, 'function initCharacterStatusModal()') !== false, 'Status modal handler is missing from the delivered view runtime');
kb_character_status_assert(strpos($editorJs, 'function initCharacterStatusModal()') === false, 'Status modal handler remains stranded or duplicated in the editor runtime');
kb_character_status_assert(strpos($viewJs, "openButton.addEventListener('click'") !== false, 'Status button has no click listener');
kb_character_status_assert(strpos($viewJs, 'initCharacterStatusModal();') !== false, 'Status modal handler is not initialized');
kb_character_status_assert(strpos($php, "['free', 'reserved', 'application', 'occupied']") !== false, 'Four-state lifecycle contract is missing');
kb_character_status_assert(strpos($php, "['category'] ?? '')) !== 'canons'") !== false, 'Status save does not require the actual canons category');
kb_character_status_assert(strpos($php, "['mechanic'] ?? '')) !== 'arpg'") !== false, 'Status save does not protect DnD Characters');
kb_character_status_assert(strpos($php, 'if (!af_kb_can_edit())') !== false, 'Moderation permission check is missing');
kb_character_status_assert(strpos($php, 'verify_post_check(') !== false, 'Status save CSRF check is missing');
kb_character_status_assert(strpos($php, "'availability' =") === false, 'Invalid direct availability storage detected');
kb_character_status_assert(strpos($php, "\$characterMeta['availability'] = [") !== false, 'Status is not stored in character_meta.availability');
kb_character_status_assert(strpos($php, '$rules = kb_parse_rules($entry);') !== false, 'Status save does not use the effective Character contract');
kb_character_status_assert(strpos($php, "'owner_uid' => \$ownerUid") !== false, 'Occupied owner uid is not persisted');
kb_character_status_assert(strpos($php, "'reserved_by_uid' => \$reservedByUid") !== false, 'Registered reservation owner is not persisted');
kb_character_status_assert(strpos($php, "'reserved_by_name' => \$reservedByUid > 0 ? '' : \$reservedByName") !== false, 'Guest reservation owner is not persisted');
kb_character_status_assert(strpos($php, "'reserved_until' => \$holdUntil") !== false, 'Reservation expiry is not persisted');
kb_character_status_assert(strpos($php, 'Для статуса "Занят" требуется UID владельца.') !== false, 'Occupied owner uid is not validated');
kb_character_status_assert(strpos($php, 'af-kb-btn--profile') !== false, 'Occupied canon profile CTA is missing');
kb_character_status_assert(strpos($php, 'Открыть анкету') !== false, 'Application topic action is missing');
kb_character_status_assert(strpos($php, "'can_apply' => \$category === 'canons' && (\$effectiveStatus === 'free'") !== false, 'Canon application availability is not recalculated from effective status');
kb_character_status_assert(strpos($php, 'function af_kb_handle_character_reserve') !== false, 'Public reservation endpoint is missing');
kb_character_status_assert(strpos($php, 'function af_kb_cleanup_expired_reservations') !== false, 'Reservation cleanup resolver is missing');

echo "KB Character status regression checks passed.\n";
