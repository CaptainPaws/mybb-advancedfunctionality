<?php
declare(strict_types=1);

function kb_original_owner_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/';
$bridge = file_get_contents($root . 'advancedthreadfields/advancedthreadfields.php');
$kb = file_get_contents($root . 'knowledgebase/knowledgebase.php');

kb_original_owner_assert(is_string($bridge) && is_string($kb), 'Unable to read owner lifecycle sources');
kb_original_owner_assert(
    strpos($bridge, "'availability' => [") !== false
        && strpos($bridge, "'owner_uid' => max(0, (int)(\$thread['uid'] ?? 0))") !== false,
    'Original creation does not persist its accepted character owner'
);
kb_original_owner_assert(strpos($kb, "\$ownerUid = max(0, (int)(\$availability['owner_uid'] ?? 0));") !== false, 'Renderer does not read the canonical owner relation');
kb_original_owner_assert(strpos($kb, "'af_charactersheets_accept'") !== false && strpos($kb, "'kb_entry_id=' . (int)\$entry['id']") !== false, 'Legacy originals do not resolve their durable Character sheet owner');
kb_original_owner_assert(strpos($kb, "member.php?action=profile&amp;uid=' . (int)\$availability['owner_uid']") !== false, 'Original profile URL is not rendered');
kb_original_owner_assert(strpos($kb, "\$status === 'reserved' ? (int)(\$availability['reserved_by_uid']") !== false, 'Reservation holder is not separated from character owner');

echo "KB original owner regression checks passed.\n";
