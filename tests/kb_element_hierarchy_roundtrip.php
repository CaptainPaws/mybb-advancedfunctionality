<?php
declare(strict_types=1);
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
define('TIME_NOW', 1000);
final class ElementResult {
    public int $position = 0;
    public function __construct(public array $rows = []) {}
}
final class ElementDb {
    public array $entries = [];
    public int $reads = 0;
    public int $writes = 0;
    public function table_exists(string $table): bool { return $table === 'af_kb_entries'; }
    public function escape_string(string $value): string { return addslashes($value); }
    public function simple_select(string $table, string $fields = '*', string $where = '', array $options = []): ElementResult {
        ++$this->reads;
        $rows = array_values($this->entries);
        if ($table !== 'af_kb_entries') return new ElementResult();
        if (preg_match("/type='([^']+)'/", $where, $m)) $rows = array_filter($rows, static fn($r) => $r['type'] === $m[1]);
        if (preg_match("/`key`='([^']+)'/", $where, $m)) $rows = array_filter($rows, static fn($r) => $r['key'] === $m[1]);
        if (preg_match("/`key` (NOT )?IN \(([^)]+)\)/", $where, $m)) {
            $keys = array_map(static fn($v) => trim($v, " '"), explode(',', $m[2]));
            $rows = array_filter($rows, static fn($r) => (bool)$m[1] !== in_array($r['key'], $keys, true));
        }
        if (str_contains($where, 'active=1')) $rows = array_filter($rows, static fn($r) => !empty($r['active']));
        return new ElementResult(array_slice(array_values($rows), $options['start'] ?? 0, $options['limit'] ?? null));
    }
    public function write_query(string $sql): ElementResult {
        if (!str_contains($sql, 'GET_LOCK') && !str_contains($sql, 'RELEASE_LOCK')) throw new RuntimeException('Unexpected SQL');
        return new ElementResult([['acquired'=>1]]);
    }
    public function fetch_array(ElementResult $result) { return $result->rows[$result->position++] ?? false; }
    public function insert_query(string $table, array $data): int {
        ++$this->writes;
        foreach ($data as &$value) if (is_string($value)) $value = stripslashes($value);
        unset($value);
        if (isset($this->entries[$data['key']])) throw new RuntimeException('duplicate key');
        $data['id'] = count($this->entries) + 1;
        $this->entries[$data['key']] = $data;
        return $data['id'];
    }
}
$db = new ElementDb();
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';
function element_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function element_codes(array $result): array { return array_column($result['diagnostics'], 'code'); }
function element_save(string $key, array $rules): array {
    global $db;
    $payload = af_kb_arpg_envelope_defaults('arpg_element');
    $payload['rules'] = array_replace(af_kb_default_type_profile_payload_arpg('arpg_element'), $payload['rules'], $rules);
    $errors = [];
    $json = af_kb_validate_rules_json_by_type_arpg('arpg_element', json_encode($payload, JSON_THROW_ON_ERROR), $errors);
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $payload['rules'] = af_kb_arpg_prepare_element($key, $payload['rules'], $db->entries[$key] ?? null, $errors);
    element_assert(!$errors, 'Save rejected: ' . implode(', ', $errors));
    $db->entries[$key] = ['id'=>count($db->entries)+1, 'key'=>$key, 'type'=>'arpg_element', 'active'=>1,
        'title_ru'=>$key, 'title_en'=>$key, 'data_json'=>json_encode($payload, JSON_THROW_ON_ERROR),
        'meta_json'=>json_encode(['rules'=>$payload], JSON_THROW_ON_ERROR)];
    return $payload['rules'];
}
element_assert($db->reads === 0 && $db->writes === 0, 'KB bootstrap performed taxonomy I/O');
$keys = explode(' ', 'fire water air earth ice lightning nature light shadow death life chaos mind abyss gravity astral space');
element_assert(array_keys(af_kb_arpg_primary_elements()) === $keys, 'Wrong primary keys');
$wind = af_kb_arpg_envelope_defaults('arpg_element');
$wind['rules'] = array_replace($wind['rules'], af_kb_default_type_profile_payload_arpg('arpg_element'), ['family'=>'wind']);
$db->entries['wind'] = ['id'=>1,'type'=>'arpg_element','key'=>'wind','active'=>1,'title_ru'=>'Ветер','title_en'=>'Custom wind',
    'body_ru'=>'User content', 'icon_url'=>'custom.png','data_json'=>'{}','meta_json'=>json_encode($wind)];
$old = $db->entries['wind'];
af_kb_seed_arpg_elements();
element_assert(count($db->entries) === 17 && !isset($db->entries['air']), 'Seed duplicated air/wind');
element_assert($db->entries['wind'] === $old, 'Seed overwrote custom content');
$seeded = $db->entries;
af_kb_seed_arpg_elements();
element_assert($db->entries === $seeded, 'Repeated seed changed definitions');
$catalog = af_kb_arpg_list_primary_elements();
element_assert(array_keys($catalog) === $keys && !array_filter($catalog, static fn($r) => !$r['supported']), 'Primary catalog not ready');
element_assert($catalog['air']['source_key'] === 'wind' && $catalog['air']['root_element'] === 'air', 'Alias provenance lost');
$options = af_kb_get_public_type_options('arpg_element');
element_assert(count($options) === 17 && $options[2]['key'] === 'wind' && $options[2]['label_ru'] === 'Воздух', 'Legacy public picker duplicated/renamed wind');
$api = af_kb_arpg_read_definition('arpg_element', 'air');
element_assert($api['supported'] && $api['normalized']['element_definition']['key'] === 'air', 'Shared reader lost alias');
$errors = [];
af_kb_arpg_prepare_element('air', ['entity_kind'=>'primary_element'], null, $errors);
element_assert(in_array('element_alias_collision', $errors, true), 'Save allowed a second active air/wind');
$legacyAir = $db->entries['wind'];
$db->entries['air'] = array_replace($legacyAir, ['key'=>'air']);
element_assert(in_array('element_alias_collision', element_codes(af_kb_arpg_read_element('air')), true), 'Existing alias collision hidden');
element_assert(count(af_kb_get_public_type_options('arpg_element')) === 17, 'Alias collision created a second picker identity');
$errors = [];
af_kb_arpg_prepare_element('wind', ['entity_kind'=>'primary_element'], $db->entries['wind'], $errors, null, false);
element_assert(!$errors, 'Admin cannot deactivate a legacy duplicate');
unset($db->entries['air']);
$blood = element_save('blood', ['entity_kind'=>'magic_direction', 'parent_ref'=>['key'=>'life'], 'description_text'=>'Blood magic']);
$child = element_save('blood_child', ['entity_kind'=>'magic_direction', 'parent_ref'=>['key'=>'blood']]);
$read = af_kb_arpg_read_element('blood_child');
element_assert($read['supported'] && $read['root_element'] === 'life' && count($read['provenance']) === 3, 'Nested root/provenance failed');
$snapshot = json_encode($read, JSON_THROW_ON_ERROR);
element_assert(count(af_kb_arpg_list_magic_directions()) === 2, 'Direction listing wrong');
element_assert(count(af_kb_get_public_type_options('arpg_element')) === 17, 'Directions entered primary picker');
foreach (['blood_child','blood','absent_parent'] as $parent) {
    $errors = [];
    af_kb_arpg_prepare_element('blood', array_replace($blood, ['parent_ref'=>['key'=>$parent]]), $db->entries['blood'], $errors);
    element_assert((bool)array_intersect($errors, ['element_cycle','element_parent_missing']), 'Invalid parent accepted');
}
$errors = [];
af_kb_arpg_prepare_element('eighteenth', ['entity_kind'=>'primary_element'], null, $errors);
element_assert(in_array('element_kind_invalid', $errors, true), '18th primary allowed');
$errors = [];
af_kb_arpg_prepare_element('res_blood', ['entity_kind'=>'magic_direction','parent_ref'=>['key'=>'life'],'RES_blood'=>5], null, $errors);
element_assert(in_array('direction_res_forbidden', $errors, true), 'Direction RES allowed');
$oldBlood = $blood;
$oldRef = array_intersect_key(af_kb_arpg_read_element('blood')['definition'], array_flip(['key','definition_version','fingerprint']));
$blood = element_save('blood', array_replace($blood, ['parent_ref'=>['key'=>'earth']]));
$errors = [];
af_kb_arpg_prepare_element('blood', $oldBlood, $db->entries['blood'], $errors);
element_assert(in_array('element_version_conflict', $errors, true), 'Stale admin overwrite accepted');
$issues = [];
af_kb_arpg_acs_damage(['damage_type'=>'physical','magic_direction_ref'=>$oldRef], 'effect', $issues);
element_assert(in_array('magic_direction_version_mismatch', array_column($issues, 'code'), true), 'Old ability reference silently re-pinned');
$stale = af_kb_arpg_read_element('blood_child');
element_assert(!$stale['supported'] && in_array('element_parent_version_mismatch', element_codes($stale), true) && in_array('element_root_changed', element_codes($stale), true), 'Parent edit silently changed root');
element_assert(json_encode($read, JSON_THROW_ON_ERROR) === $snapshot, 'Existing snapshot changed');
$child = element_save('blood_child', $child);
element_assert(af_kb_arpg_read_element('blood_child')['root_element'] === 'earth', 'Explicit re-pin failed');
$parent = $db->entries['earth']; unset($db->entries['earth']);
element_assert(!af_kb_arpg_read_element('blood_child')['supported'], 'Deleted parent accepted');
$db->entries['earth'] = $parent;
$ref = array_intersect_key(af_kb_arpg_read_element('blood')['definition'], array_flip(['key','definition_version','fingerprint']));
foreach ([['damage_type'=>'physical'], ['damage_type'=>'elemental','damage_element'=>'life'], ['effect_type'=>'heal']] as $effect) {
    $issues = [];
    $damage = af_kb_arpg_acs_damage($effect + ['magic_direction_ref'=>$ref], 'effect', $issues);
    element_assert(!$issues, 'Valid explicit effect rejected');
    element_assert($damage['element'] === ($effect['damage_element'] ?? null), 'Damage inferred from direction');
}
$ability = af_kb_arpg_envelope_defaults('arpg_ability');
$ability['rules'] = array_replace($ability['rules'], af_kb_default_type_profile_payload_arpg('arpg_ability'), ['damage_type'=>'elemental','damage_element'=>'life','magic_direction_ref'=>$ref]);
$errors = [];
$abilityJson = af_kb_validate_rules_json_by_type_arpg('arpg_ability', json_encode($ability, JSON_THROW_ON_ERROR), $errors);
element_assert(!$errors, 'Explicit elemental ability cannot be saved: ' . implode(', ', $errors));
$abilityRead = af_kb_arpg_normalize_entry(['type'=>'arpg_ability','key'=>'blood_ability','data_json'=>$abilityJson]);
element_assert($abilityRead['normalized']['ability_profile']['damage']['element'] === 'life' && !$abilityRead['executable'], 'Ability projection lost explicit damage');
$issues = [];
af_kb_arpg_acs_damage(['damage_type'=>'elemental','magic_direction_ref'=>$ref], 'effect', $issues);
element_assert(in_array('damage_element_required', array_column($issues,'code'), true), 'Missing damage element guessed');
$errors = [];
af_kb_arpg_validate_element_fields(['resists'=>[['damage_type'=>'physical','value'=>3], ['damage_type'=>'fire','value'=>5]]], $errors);
element_assert(!$errors, 'Legacy resistance row forced through an implicit elemental migration');
$issues = [];
$res = af_kb_arpg_acs_resistances(['life'=>['value'=>0,'scope'=>'elemental','unit'=>'RES']], 'resistances', $issues);
element_assert(!$issues && $res[0]['canonical_element'] === 'life', 'Valid zero RES lost');
$issues = [];
af_kb_arpg_acs_resistances(['blood'=>5,'wind'=>3,'air'=>3], 'resistances', $issues);
element_assert(in_array('element_resistance_key_invalid',array_column($issues,'code'),true) && in_array('element_resistance_alias_collision',array_column($issues,'code'),true), 'Child/duplicate RES allowed');
$populated = $db;
$db = new ElementDb();
af_kb_seed_arpg_elements();
element_assert(array_keys($db->entries) === $keys && !isset($db->entries['wind']), 'Fresh seed is not canonical');
foreach ($db->entries as $entry) {
    $rules = af_kb_arpg_read_element($entry['key'])['definition']['rules'];
    element_assert(($rules['counter_element'] ?? '') === '', 'Seed invented counter-elements');
}
$db = $populated;
$writes = $db->writes;
af_kb_arpg_read_element('blood_child'); af_kb_arpg_list_primary_elements();
element_assert($db->writes === $writes, 'Read mutated content');
echo "KB elements: 17 keys, alias, repeated seed, branch/nested round-trips, cycles, root/version/delete diagnostics, explicit damage and no child RES passed\n";
