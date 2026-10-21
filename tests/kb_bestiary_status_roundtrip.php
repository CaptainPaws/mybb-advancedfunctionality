<?php
declare(strict_types=1);
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
final class BestiaryQuery {
    public int $cursor = 0;
    public function __construct(public array $rows) {}
}
final class BestiaryReadOnlyDb {
    public array $entries = [];
    public int $reads = 0;
    public function table_exists(string $table): bool { return $table === 'af_kb_entries'; }
    public function escape_string(string $value): string { return addslashes($value); }
    public function simple_select(string $table, string $fields = '*', string $where = '', array $options = []): BestiaryQuery {
        ++$this->reads;
        preg_match("/type='([^']+)'/", $where, $type);
        preg_match("/`key`='([^']+)'/", $where, $key);
        return new BestiaryQuery(array_values(array_filter($this->entries, static fn($e) => $e['type'] === ($type[1] ?? '') && $e['key'] === ($key[1] ?? ''))));
    }
    public function fetch_array(BestiaryQuery $query) { return $query->rows[$query->cursor++] ?? false; }
    // No mutation methods: any attempted write fails the test.
}
$db = new BestiaryReadOnlyDb();
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';
function bestiary_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function bestiary_codes(array $dto): array { return array_column($dto['diagnostics'], 'code'); }
function bestiary_entry(string $type, string $key, array $rules): array {
    return ['type'=>$type, 'key'=>$key, 'active'=>1, 'data_json'=>json_encode($rules, JSON_THROW_ON_ERROR)];
}
function bestiary_save(array $rules, array &$errors): array {
    $envelope = af_kb_arpg_envelope_defaults('arpg_bestiary');
    $envelope['rules'] = $rules;
    return json_decode(af_kb_validate_rules_json_by_type_arpg('arpg_bestiary', json_encode($envelope, JSON_THROW_ON_ERROR), $errors), true, 512, JSON_THROW_ON_ERROR)['rules'];
}
bestiary_assert($db->reads === 0, 'Bootstrap performed I/O');
$registry = af_kb_arpg_character_stat_registry();
bestiary_assert(isset($registry['status_resist']) && !isset($registry['status_resistance']) && $registry['status_resist']['acs_key'] === 'status_resistance', 'Duplicate stat identity');
foreach ([['status_resist'=>0], ['status_resistance'=>12], ['status_resist'=>0,'status_resistance'=>'0']] as $stats) {
    $diagnostics = [];
    $result = af_kb_arpg_status_resistance($stats, 'stats', $diagnostics);
    bestiary_assert($result['available'] && $result['value'] == reset($stats) && !$diagnostics, 'Alias/zero resolution failed');
    bestiary_assert($result['source_values'] === $stats, 'Alias source lost');
}
$rules = af_kb_default_type_profile_payload_arpg('arpg_bestiary');
$rules['family'] = $rules['archetype'] = $rules['faction'] = 'fixture';
$rules['combat_stats']['accuracy'] = 25;
$rules['combat_stats']['status_resist'] = 0;
foreach (array_keys(af_kb_arpg_primary_elements()) as $element) {
    $sourceKey = $element === 'air' ? 'wind' : $element;
    $rules['resists'][$sourceKey] = ['scope'=>'elemental','unit'=>'RES','value'=>0];
}
$db->entries['life'] = bestiary_entry('arpg_element', 'life', ['entity_kind'=>'primary_element','element_revision'=>1,'root_element'=>'life']);
$life = af_kb_arpg_read_element('life');
$rulesBlood = ['entity_kind'=>'magic_direction','element_revision'=>1,'root_element'=>'life', 'parent_ref'=>array_intersect_key($life['definition'], array_flip(['key','kind','definition_version','fingerprint']))];
$db->entries['blood'] = bestiary_entry('arpg_element', 'blood', $rulesBlood);
$blood = af_kb_arpg_read_element('blood');
bestiary_assert($blood['supported'] && $blood['root_element'] === 'life', 'blood root lost');
$direction = array_intersect_key($blood['definition'], array_flip(['key','definition_version','fingerprint']));
$rules['magic_direction_ref'] = $direction;
$abilityRules = ['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'ability','version'=>'2.0', 'damage_type'=>'elemental','damage_element'=>'life','magic_direction_ref'=>$direction];
$db->entries['blood_bolt'] = bestiary_entry('arpg_ability', 'blood_bolt', $abilityRules);
$d = [];
$ref = af_kb_arpg_acs_reference('arpg_ability', 'blood_bolt', 'ability', $d);
$rules['ability_keys'] = [['ability_kb_key'=>'blood_bolt', 'ability_definition_version'=>$ref['definition_version'], 'ability_fingerprint'=>$ref['fingerprint'],
    'magic_direction_ref'=>$direction,'damage_type'=>'elemental','damage_element'=>'life','cooldown_unit'=>'own_turn','duration_unit'=>'own_turn']];
foreach ([25, 0] as $accuracy) {
    $rules['combat_stats']['accuracy'] = $accuracy;
    $errors = [];
    $saved = bestiary_save($rules, $errors);
    bestiary_assert(!$errors, 'Save failed: ' . implode('; ', $errors));
    bestiary_assert($saved['combat_stats']['accuracy'] === $accuracy && $saved['combat_stats']['status_resist'] === 0, 'Numeric roundtrip failed');
    bestiary_assert($saved['resists'] === $rules['resists'], 'Keyed RES lost');
    bestiary_assert($saved['magic_direction_ref'] === $direction && $saved['ability_keys'][0]['ability_fingerprint'] === $ref['fingerprint'], 'Reference pin lost');
    $entry = bestiary_entry('arpg_bestiary', 'monster', $saved);
    $before = $db->entries;
    $dto = af_kb_arpg_normalize_entry($entry);
    bestiary_assert($db->entries === $before, 'Read mutated DB');
    bestiary_assert($dto['supported'], 'Complete definition blocked: ' . implode(', ', bestiary_codes($dto)));
    bestiary_assert(count($dto['normalized']['element_resistances']) === 17 && isset($dto['normalized']['element_resistances']['air']) && !isset($dto['normalized']['element_resistances']['wind']), '17 RES/alias failed');
    bestiary_assert(!isset($dto['normalized']['element_resistances']['blood']), 'Child RES created');
    bestiary_assert($dto['normalized']['classification']['magic_direction']['root_element'] === 'life', 'Direction projection lost');
    bestiary_assert($dto['normalized']['classification']['element'] === null, 'Implicit life damage');
    bestiary_assert($dto['normalized']['combat_stats']['status_resistance'] === 0 && !isset($dto['normalized']['combat_stats']['status_resist']), 'Stat applied twice');
    $d = [];
    bestiary_assert($dto['definition_version']['fingerprint'] === af_kb_arpg_acs_source($entry, $d)['fingerprint'], 'Source fingerprint changed');
    bestiary_assert(af_kb_arpg_normalize_entry($entry) === $dto, 'Read not deterministic');
}
$conflict = $rules;
$conflict['combat_stats']['status_resistance'] = 7;
$errors = [];
$saved = bestiary_save($conflict, $errors);
bestiary_assert((bool)array_filter($errors, static fn($e) => str_contains($e, 'status_resistance_alias_conflict')), 'Save silently chose alias');
$dto = af_kb_arpg_normalize_entry(bestiary_entry('arpg_bestiary', 'conflict', $conflict));
bestiary_assert(in_array('status_resistance_alias_conflict', bestiary_codes($dto), true) && !isset($dto['normalized']['combat_stats']['status_resistance']), 'Conflicting DTO usable');
$legacy = $rules;
unset($legacy['combat_stats']['accuracy'], $legacy['combat_stats']['status_resist'], $legacy['resists']['fire']);
$errors = [];
$saved = bestiary_save($legacy, $errors);
bestiary_assert(!$errors && !isset($saved['combat_stats']['accuracy']) && !isset($saved['combat_stats']['status_resist']) && !isset($saved['resists']['fire']), 'Legacy values invented');
$dto = af_kb_arpg_normalize_entry(bestiary_entry('arpg_bestiary','legacy',$saved));
bestiary_assert(in_array('required_stat_missing', bestiary_codes($dto), true) && in_array('required_resistance_missing', bestiary_codes($dto), true), 'Incomplete legacy not diagnosed');
foreach (['blood','unknown'] as $invalid) {
    $bad = $rules;
    $bad['resists'][$invalid] = ['scope'=>'elemental','unit'=>'RES','value'=>0];
    $errors = [];
    bestiary_save($bad, $errors);
    bestiary_assert((bool)$errors, 'Unknown/child RES accepted');
}
$bad = $rules;
$bad['ability_keys'][0]['ability_fingerprint'] = str_repeat('0', 64);
$dto = af_kb_arpg_normalize_entry(bestiary_entry('arpg_bestiary','stale',$bad));
bestiary_assert(in_array('ability_version_pin_mismatch', bestiary_codes($dto), true), 'Stale ability accepted');
foreach (['status_resist','status_resistance'] as $key) {
    $modifier = ['stat_key'=>$key,'mode'=>'flat','value'=>3];
    bestiary_assert(!af_kb_arpg_validate_origin_variant_modifier($modifier), 'Modifier alias rejected');
    bestiary_assert(af_kb_arpg_apply_origin_variant_modifiers(['character_status_resist'=>2], ['modifiers'=>[$modifier]])['character_status_resist'] === 5.0, 'Modifier applied twice');
    $projected = af_kb_arpg_project_stat_identifiers(['modifiers'=>[$modifier], 'raw'=>$modifier]);
    bestiary_assert($projected['modifiers'][0]['stat_key'] === 'status_resistance' && $projected['modifiers'][0]['source_stat_key'] === $key && $projected['raw'] === $modifier, 'Modifier provenance lost');
}
foreach (['arpg_origin','arpg_archetype'] as $type) {
    $source = ['status_resist_base'=>0];
    $dto = af_kb_arpg_normalize_entry(bestiary_entry($type, 'base', $source));
    bestiary_assert($dto['normalized']['status_resistance_base']['available'] && $dto['normalized']['status_resistance_base']['value'] === 0, 'Base zero lost');
    $errors = [];
    af_kb_arpg_validate_status_fields(['status_resist_base'=>0, 'status_resistance_base'=>1], $errors);
    bestiary_assert((bool)$errors, 'Base alias conflict ignored');
}
foreach ([[], ['character_status_resist'=>0], ['character_status_resistance'=>4]] as $stats) {
    $rulesVm = ['schema'=>AF_KB_CHARACTER_SCHEMA, 'character_meta'=>['mechanic'=>'arpg'], 'character_stats'=>$stats];
    $dto = af_kb_arpg_normalize_entry(bestiary_entry('character','vm',$rulesVm));
    bestiary_assert($dto['normalized']['status_resistance']['available'] === (bool)$stats, 'VM missing/zero distinction lost');
    $errors = [];
    $rulesVm['type_profile'] = 'character';
    $saved = json_decode(af_kb_validate_rules_json_by_type_arpg('character', json_encode($rulesVm, JSON_THROW_ON_ERROR), $errors), true, 512, JSON_THROW_ON_ERROR);
    foreach (['character_status_resist','character_status_resistance'] as $field) {
        bestiary_assert(array_key_exists($field, $saved['character_stats'] ?? []) === array_key_exists($field, $stats), 'VM save invented/dropped stat');
    }
}
echo "KB bestiary: alias/zero/conflict/missing, Accuracy save/read, 17 RES/wind, blood root, pinned ability and stable fingerprint passed\n";
