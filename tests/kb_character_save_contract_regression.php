<?php
declare(strict_types=1);

define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');

final class CharacterSaveDbStub
{
    public function table_exists(string $table): bool { return false; }
    public function escape_string(string $value): string { return $value; }
    public function simple_select(...$args): array { return ['table' => (string)($args[0] ?? '')]; }
    public function fetch_array($query): array
    {
        if (($query['table'] ?? '') === 'af_kb_relations') {
            return ['id' => 1, 'from_key' => 'demihuman', 'rel_type' => AF_KB_REL_ORIGIN_HAS_VARIANT, 'sortorder' => 0];
        }
        if (($query['table'] ?? '') === 'af_kb_entries') {
            return [
                'id' => 2, 'type' => AF_KB_TYPE_ORIGIN, 'key' => 'demihuman', 'active' => 1,
                'title_ru' => '', 'title_en' => '', 'short_ru' => '', 'short_en' => '',
                'body_ru' => '', 'body_en' => '', 'tech_ru' => '{}', 'tech_en' => '{}',
                'meta_json' => '{}', 'data_json' => '{}', 'icon_class' => '', 'icon_url' => '',
                'banner_url' => '', 'bg_url' => '', 'item_kind' => '', 'sortorder' => 0,
            ];
        }
        return [];
    }
}

$db = new CharacterSaveDbStub();
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';

function htmlspecialchars_uni($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

final class postParser
{
    public function parse_message(string $message, array $options): string { return htmlspecialchars_uni($message); }
}

final class CharacterTemplatesStub
{
    public function get(string $name): string
    {
        return addslashes((string)file_get_contents(AF_ADDONS . 'knowledgebase/templates/' . $name . '.html'));
    }
}

$templates = new CharacterTemplatesStub();

function character_save_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$manualPayload = [
    'schema' => AF_KB_RULES_SCHEMA,
    'type_profile' => 'character',
    'version' => '1.0',
    'character_profile' => [
        'category' => 'originals',
        'character_pic' => '/uploads/character.webp',
        'character_prototype' => 'Prototype',
        'character_name' => 'Name Surname',
        'character_name_ru' => 'Имя Фамилия',
        'character_nicknames' => 'Nickname',
        'character_element' => 'fire',
        'character_gen' => 'female',
        'character_origin' => 'demihuman',
        'character_origin_variant' => 'demi_bear',
        'character_class' => 'vanguard',
        'character_faction' => 'guild',
        'character_app' => 'Description',
        'character_weapon' => 'greatsword',
        'character_age' => '27',
        'character_height' => '175',
        'character_weight' => '70',
        'character_activity' => 'Mercenary',
        'character_post' => 'Sample post',
        'character_userinfo' => 'Player',
    ],
    'character_abilities' => [[
        'slot_index' => 1,
        'title' => 'Strike',
        'effects' => [['effect_type' => 'damage', 'value' => 10]],
    ]],
    'character_links' => [],
    'character_meta' => ['source' => 'kb_manual'],
];

$errors = [];
$normalized = af_kb_validate_rules_json_by_type(
    'character',
    json_encode($manualPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    $errors,
    'arpg'
);
$saved = af_kb_decode_json($normalized);

character_save_assert($errors === [], 'Manual ARPG Character validation failed: ' . implode('; ', $errors));
character_save_assert(($saved['schema'] ?? '') === AF_KB_CHARACTER_SCHEMA, 'Manual save did not use the Character contract schema');
character_save_assert(($saved['type_profile'] ?? '') === 'character', 'Manual save changed the Character type profile');
character_save_assert(($saved['character_meta']['mechanic'] ?? '') === 'arpg', 'Manual save lost the nested mechanic');
character_save_assert(!array_key_exists('mechanic', $saved), 'Manual save added a fake top-level mechanic');
character_save_assert(!array_key_exists('rules', $saved), 'Manual save added a fake top-level rules envelope');
foreach (['character_profile', 'character_abilities', 'character_links', 'character_meta'] as $key) {
    character_save_assert(isset($saved[$key]) && is_array($saved[$key]), 'Character contract section is missing: ' . $key);
}
$expectedProfile = [
    'character_name' => 'Name Surname', 'character_name_ru' => 'Имя Фамилия',
    'character_pic' => '/uploads/character.webp', 'character_nicknames' => 'Nickname',
    'character_prototype' => 'Prototype', 'character_element' => 'fire',
    'character_gen' => 'female', 'character_origin' => 'demihuman',
    'character_origin_variant' => 'demi_bear', 'character_class' => 'vanguard',
    'character_faction' => 'guild', 'character_activity' => 'Mercenary',
    'character_weapon' => 'greatsword', 'character_age' => '27',
    'character_height' => '175', 'character_weight' => '70',
    'character_app' => 'Description', 'character_post' => 'Sample post',
    'character_userinfo' => 'Player',
];
foreach ($expectedProfile as $key => $expected) {
    character_save_assert(($saved['character_profile'][$key] ?? '') === $expected, $key . ' did not survive normalization');
}
character_save_assert(($saved['character_profile']['character_class'] ?? '') === 'vanguard', 'Class/archetype did not survive normalization');
character_save_assert(($saved['character_abilities'][0]['effects'][0]['effect_type'] ?? '') === 'damage', 'Structured effects did not survive normalization');

$sheet = af_kb_extract_character_contract(['data_json' => $normalized, 'meta_json' => '{}']);
character_save_assert(($sheet['profile']['character_origin'] ?? '') === 'demihuman', 'Character Sheet cannot read the manual Character origin');
character_save_assert(($sheet['profile']['character_class'] ?? '') === 'vanguard', 'Character Sheet cannot read the manual Character class/archetype');
character_save_assert(($sheet['profile']['character_element'] ?? '') === 'fire', 'Character Sheet cannot read the manual Character element');
character_save_assert(($sheet['abilities'][0]['effects'][0]['effect_type'] ?? '') === 'damage', 'Character Sheet cannot read structured ability effects');

$prefill = af_kb_build_character_application_prefill(['id' => 42, 'type' => 'character', 'key' => 'test-canon', 'data_json' => $normalized, 'meta_json' => '{}']);
character_save_assert(($prefill['mechanic'] ?? '') === 'arpg', 'KB application prefill lost the ARPG mechanic');
foreach ($expectedProfile as $key => $expected) {
    character_save_assert(($prefill['values'][$key] ?? '') === $expected, $key . ' was lost in KB to ATF prefill');
}

$weaponOptions = af_kb_get_arpg_mechanics_options('weapon_type', 'weapon_type');
character_save_assert(in_array('greatsword', array_column($weaponOptions, 'key'), true), 'Weapon source of truth did not populate greatsword');
character_save_assert(af_kb_character_profile_resolved_value('character_weapon', 'greatsword', true) !== 'greatsword', 'Weapon display leaked the canonical key');

$rendered = af_kb_render_character_entry(['type' => 'character', 'key' => 'manual', 'data_json' => $normalized, 'meta_json' => '{}'], [], true);
foreach (['Name Surname', 'Имя Фамилия', 'Женский', 'Greatsword', '27', '175', '70'] as $visible) {
    character_save_assert(strpos($rendered, $visible) !== false, 'Public Character page omitted: ' . $visible);
}
foreach (['Sample post', 'Player'] as $privateApplicationValue) {
    character_save_assert(strpos($rendered, $privateApplicationValue) === false, 'Public Character page exposed application-only data: ' . $privateApplicationValue);
}

$card = af_kb_character_catalog_card(['type' => 'character', 'key' => 'manual', 'data_json' => $normalized, 'meta_json' => '{}'], []);
character_save_assert(strpos($card, 'Name Surname') !== false, 'Catalog card omitted the EN name');
character_save_assert(strpos($card, 'Женский') !== false, 'Catalog card omitted the resolved gender');

// Re-validating the stored result exercises the edit path and must remain stable.
$editErrors = [];
$edited = af_kb_validate_rules_json_by_type('character', $normalized, $editErrors, 'arpg');
character_save_assert($editErrors === [], 'Manual ARPG Character edit validation failed: ' . implode('; ', $editErrors));
character_save_assert(af_kb_decode_json($edited) === $saved, 'Character edit normalization is not idempotent');

// DnD Character retains its legacy schema and is never forced into ARPG shape.
$dndPayload = $manualPayload;
$dndPayload['character_meta'] = ['source' => 'kb_manual'];
$dndErrors = [];
$dndNormalized = af_kb_validate_rules_json_by_type(
    'character',
    json_encode($dndPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    $dndErrors,
    'dnd'
);
$dndSaved = af_kb_decode_json($dndNormalized);
character_save_assert($dndErrors === [], 'DnD Character validation regressed: ' . implode('; ', $dndErrors));
character_save_assert(($dndSaved['schema'] ?? '') === AF_KB_RULES_SCHEMA, 'DnD Character schema changed');
character_save_assert(empty($dndSaved['character_meta']['mechanic']), 'DnD Character was marked as ARPG');
character_save_assert(isset($dndSaved['traits'], $dndSaved['grants'], $dndSaved['choices']), 'DnD Character legacy normalization changed');

$source = file_get_contents(AF_ADDONS . 'knowledgebase/knowledgebase.php');
character_save_assert(is_string($source), 'Cannot read KB server source');
$characterRoute = strpos($source, "} elseif (\$type === 'character') {");
$genericArpgRoute = strpos($source, "} elseif (\$mechanicKey === 'arpg') {", $characterRoute ?: 0);
character_save_assert($characterRoute !== false && $genericArpgRoute !== false && $characterRoute < $genericArpgRoute, 'Save routing does not exempt Character from the generic ARPG envelope');

echo "KB Character save contract regression checks passed.\n";
