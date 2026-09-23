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
kb_character_status_assert(strpos($php, "['free', 'pending', 'occupied', 'held']") !== false, 'Existing status contract changed');
kb_character_status_assert(strpos($php, "['category'] ?? '')) !== 'canons'") !== false, 'Status save does not require the actual canons category');
kb_character_status_assert(strpos($php, "['mechanic'] ?? '')) !== 'arpg'") !== false, 'Status save does not protect DnD Characters');
kb_character_status_assert(strpos($php, 'if (!af_kb_can_edit())') !== false, 'Moderation permission check is missing');
kb_character_status_assert(strpos($php, 'verify_post_check(') !== false, 'Status save CSRF check is missing');
kb_character_status_assert(strpos($php, "'availability' =") === false, 'Invalid direct availability storage detected');
kb_character_status_assert(strpos($php, "\$characterMeta['availability'] = [") !== false, 'Status is not stored in character_meta.availability');
kb_character_status_assert(strpos($php, "'can_apply' => \$category === 'canons' && \$effectiveStatus === 'free'") !== false, 'Canon application availability is not recalculated from effective status');

echo "KB Character status regression checks passed.\n";
