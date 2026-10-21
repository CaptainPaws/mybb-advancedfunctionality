<?php
declare(strict_types=1);
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';
function accuracy_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function accuracy_read(array $rules, string $type = 'arpg_bestiary'): array {
    return af_kb_arpg_normalize_entry(['type' => $type, 'key' => 'accuracy_fixture', 'data_json' => json_encode($rules, JSON_THROW_ON_ERROR)]);
}
accuracy_check(isset(af_kb_arpg_character_stat_registry()['accuracy']), 'Missing registry entry');
$legacy = af_kb_default_type_profile_payload_arpg('arpg_bestiary');
$legacy['combat_stats']['status_resist'] = 0; // Explicit independent stat; no default is inferred.
$legacy['schema'] = AF_KB_ARPG_RULES_SCHEMA;
$legacy['type_profile'] = 'bestiary';
$legacy['family'] = $legacy['archetype'] = $legacy['faction'] = 'fixture';
foreach ([0, 27.5] as $value) {
    $rules = $legacy;
    $rules['combat_stats']['accuracy'] = $value;
    $payload = af_kb_arpg_migrate_legacy_payload('arpg_bestiary', ['schema' => AF_KB_ARPG_META_SCHEMA, 'mechanic' => 'arpg', 'tags' => [], 'ui' => [], 'blocks' => [], 'rules' => $rules]);
    $errors = [];
    $saved = af_kb_validate_arpg_entry_by_type('arpg_bestiary', $payload, $errors);
    accuracy_check(!$errors, 'Save validation: ' . implode('; ', $errors));
    $read = accuracy_read(json_decode(json_encode($saved['rules'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    accuracy_check($read['normalized']['combat_stats']['accuracy'] === $value, 'Accuracy lost or converted');
    accuracy_check($read['normalized']['combat_stats']['speed'] === $legacy['combat_stats']['speed'], 'Speed changed');
    accuracy_check(!in_array('required_stat_missing', array_column($read['diagnostics'], 'code'), true), 'Explicit zero treated as missing');
}
$migrated = af_kb_arpg_migrate_legacy_rules_contract('arpg_bestiary', $legacy);
accuracy_check(!array_key_exists('accuracy', $migrated['combat_stats']), 'Legacy Accuracy invented');
$read = accuracy_read($migrated);
accuracy_check((bool)array_filter($read['diagnostics'], static fn($d) => $d['code'] === 'required_stat_missing' && $d['path'] === 'combat_stats.accuracy'), 'Missing Accuracy not diagnosed');
accuracy_check(!array_key_exists('accuracy', $legacy['combat_stats']), 'Input mutated');
$errors = [];
af_kb_validate_arpg_entry_by_type('arpg_bestiary', ['schema' => AF_KB_ARPG_META_SCHEMA, 'mechanic' => 'arpg', 'tags' => [], 'ui' => [], 'blocks' => [], 'rules' => $migrated], $errors);
accuracy_check(!$errors, 'Legacy editing requires invented Accuracy: ' . implode('; ', $errors));
foreach (['arpg_item', 'arpg_talent', 'arpg_ability', 'arpg_origin_variant'] as $type) {
    $rules = af_kb_default_type_profile_payload_arpg($type);
    $rules['modifiers'] = [['stat_key' => 'accuracy', 'mode' => 'flat', 'value' => 5]];
    $payload = af_kb_arpg_migrate_legacy_payload($type, ['schema' => AF_KB_ARPG_META_SCHEMA, 'mechanic' => 'arpg', 'tags' => [], 'ui' => [], 'blocks' => [], 'rules' => $rules]);
    accuracy_check($payload['rules']['modifiers'][0]['stat_key'] === 'accuracy', 'Modifier lost');
    $diagnostics = [];
    af_kb_arpg_acs_modifiers($payload['rules'], 'definition', $diagnostics);
    accuracy_check(!in_array('unknown_stat', array_column($diagnostics, 'code'), true), 'Accuracy modifier rejected');
}
accuracy_check(af_kb_arpg_validate_origin_variant_modifier(['stat_key' => 'accuracy', 'mode' => 'flat', 'value' => 5]) === [], 'Origin modifier rejected');
$stats = af_kb_arpg_apply_origin_variant_modifiers([], ['modifiers' => [['stat_key' => 'accuracy', 'mode' => 'flat', 'value' => 5]]]);
accuracy_check($stats['character_accuracy'] === 5.0, 'Origin modifier not applied');
foreach (['arpg_origin', 'arpg_archetype'] as $type) {
    $rules = af_kb_default_type_profile_payload_arpg($type);
    accuracy_check(!array_key_exists('accuracy_base', $rules), 'Origin/archetype default invented');
    $rules['accuracy_base'] = 0;
    $rules['status_resist_base'] = 0;
    $migrated = af_kb_arpg_migrate_legacy_rules_contract($type, $rules);
    accuracy_check($migrated['accuracy_base'] === 0, 'Origin/archetype zero lost');
    $read = accuracy_read($migrated, $type);
    accuracy_check(!in_array('required_stat_missing', array_column($read['diagnostics'], 'code'), true), 'Origin/archetype zero diagnosed as missing');
}
final class AccuracyReadOnlyDb {
    public function escape_string(string $value): string { return $value; }
    public function simple_select(...$args): array { return []; }
    public function fetch_array($query): array { return []; }
    public function table_exists(string $table): bool { return false; }
}
$db = new AccuracyReadOnlyDb();
foreach ([[], ['character_accuracy' => 0], ['character_accuracy' => 19.5]] as $stats) {
    $errors = [];
    $saved = af_kb_validate_rules_json_by_type_arpg('character', json_encode([
        'schema' => AF_KB_CHARACTER_SCHEMA, 'type_profile' => 'character',
        'character_profile' => [], 'character_stats' => $stats,
        'character_meta' => ['mechanic' => 'arpg'],
    ], JSON_THROW_ON_ERROR), $errors);
    $saved = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
    accuracy_check(array_key_exists('character_accuracy', $saved['character_stats'] ?? []) === array_key_exists('character_accuracy', $stats), 'Character missing/zero distinction lost');
    if (array_key_exists('character_accuracy', $stats)) accuracy_check($saved['character_stats']['character_accuracy'] == $stats['character_accuracy'], 'Character Accuracy changed');
}
$invalid = $legacy;
$invalid['combat_stats']['accuracy'] = -1;
$errors = [];
af_kb_validate_arpg_public_entity('bestiary', ['rules' => $invalid], $errors);
accuracy_check((bool)array_filter($errors, static fn($e) => str_contains($e, 'Accuracy must')), 'Negative Accuracy accepted');
echo "KB Accuracy round-trip, modifiers and legacy missing-value checks passed\n";
