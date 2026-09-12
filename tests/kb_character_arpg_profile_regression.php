<?php
declare(strict_types=1);

define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';

function character_arpg_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

character_arpg_assert(af_kb_resolve_type_alias('character') === 'character', 'Public character route was aliased away');
character_arpg_assert(af_kb_is_arpg_ready_type('character'), 'Character is not ARPG-ready');
character_arpg_assert(!af_kb_is_arpg_ready_type('race'), 'Legacy DnD race was silently made ARPG-ready');

$arpgProfile = af_kb_get_mechanic_profile('arpg');
character_arpg_assert(
    ($arpgProfile['type_profile_map']['character'] ?? '') === 'arpg_character',
    'Character does not resolve to the internal ARPG profile'
);

$characterDefault = null;
foreach (af_kb_default_type_definitions() as $definition) {
    if (($definition['type_key'] ?? '') === 'character') {
        $characterDefault = $definition;
        break;
    }
}
character_arpg_assert(is_array($characterDefault), 'Character default type is missing');
character_arpg_assert(($characterDefault['mechanic_key'] ?? '') === 'arpg', 'New character types do not default to ARPG');
character_arpg_assert(($characterDefault['type_key'] ?? '') === 'character', 'Public character type key changed');

$source = file_get_contents(AF_ADDONS . 'knowledgebase/assets/knowledgebase.js');
character_arpg_assert(is_string($source), 'Cannot read KB editor source');
character_arpg_assert(
    substr_count($source, "mechanic === 'arpg' && uiProfile !== 'character'") === 2,
    'Character editor is not excluded from ARPG envelope handling at both boundaries'
);

echo "KB character ARPG profile regression checks passed.\n";
