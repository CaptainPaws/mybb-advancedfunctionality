<?php
declare(strict_types=1);
define('IN_MYBB',1);
define('MYBB_ROOT',dirname(__DIR__).'/');
define('AF_ADDONS',MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/');
define('THIS_SCRIPT','showthread.php');
function af_is_addon_enabled(string $id): bool { return !in_array($id,$GLOBALS['disabled'] ?? [],true); }
function forum_permissions(int $fid): array { return ['canview'=>1,'canviewthreads'=>1]; }
function get_forum(int $fid): array { return ['fid'=>$fid,'password'=>'']; }
final class SnapshotRows {
    public int $position = 0;
    public function __construct(public array $rows) {}
}
final class SnapshotReadOnlyDb {
    public array $tables = [];
    public array $queries = [];
    public bool $changeDuringRead = false;
    public function table_exists(string $table): bool { return array_key_exists($table,$this->tables); }
    public function escape_string(string $value): string { return addslashes($value); }
    public function simple_select(string $table,string $fields='*',string $where='',array $options=[]): SnapshotRows {
        $this->queries[] = [$table,$where];
        if ($this->changeDuringRead && $table === 'af_advinv_items') {
            $this->tables['af_cs_sheets'][0]['updated_at']++;
        }
        if (($options['order_by'] ?? '') === 'id' && in_array($table,['threads','af_charactersheets_accept','af_advinv_equipped'],true)) throw new RuntimeException('No id column');
        $rows = $this->tables[$table] ?? [];
        preg_match_all("/(?:`)?(id|uid|tid|type|key|entity|subtype|to_key|to_type|from_type|rel_type)(?:`)?=(?:'([^']*)'|(\d+))/",$where,$matches,PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            $rows = array_values(array_filter($rows,static fn($row) => (string)($row[$match[1]] ?? '') === $value));
        }
        return new SnapshotRows($rows);
    }
    public function fetch_array(SnapshotRows $rows) { return $rows->rows[$rows->position++] ?? false; }
    // No INSERT/UPDATE/DELETE/DDL methods: any attempted write fails immediately.
}
function snapshot_assert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$mybb = (object)['user'=>['uid'=>7,'usergroup'=>2],'usergroup'=>['cancp'=>0],'settings'=>['af_kb_public_catalog'=>1]];
$db = new SnapshotReadOnlyDb();
require_once AF_ADDONS.'charactersheets/charactersheets.php';
snapshot_assert(!$db->queries,'showthread bootstrap queried snapshot sources');
snapshot_assert(!function_exists('af_charactersheets_prepare_combat_snapshot') && !function_exists('af_charactersheets_compute_sheet_view'),'Heavy snapshot/calculator loaded globally');
require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/CharacterSheetAdapter.php';
function snapshot_entry(int $id,string $type,string $key,array $rules): array {
    return ['id'=>$id,'type'=>$type,'key'=>$key,'active'=>1,'data_json'=>json_encode($rules,JSON_THROW_ON_ERROR),'meta_json'=>'{}'];
}
$stats = [];
foreach (af_kb_arpg_character_stat_registry() as $entry) $stats[$entry['vm_field']] = 0;
$stats['character_accuracy'] = 20;
$stats['character_hp'] = 100;
$res = [];
foreach (array_keys(af_kb_arpg_primary_elements()) as $key) $res[$key === 'air' ? 'wind' : $key] = ['scope'=>'elemental','unit'=>'RES','value'=>0];
$rules = ['schema'=>AF_KB_CHARACTER_SCHEMA,'version'=>'1.0','character_profile'=>['character_level'=>1],
    'character_stats'=>$stats,'character_meta'=>['mechanic'=>'arpg','source'=>'af_atf_bridge','source_tid'=>51,'availability'=>['owner_uid'=>7]],
    'resistances'=>$res,'character_abilities'=>[]];
$itemRules = ['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'item','version'=>'1.0',
    'base_stats'=>[['stat_key'=>'accuracy','mode'=>'flat','value'=>5]]];
$db->tables = [
    'af_cs_sheets'=>[['id'=>3,'uid'=>7,'tid'=>51,'build_json'=>'{}','progress_json'=>'{}','updated_at'=>5]],
    'af_charactersheets_accept'=>[['tid'=>51,'uid'=>7,'kb_entry_id'=>11]],
    'threads'=>[['tid'=>51,'fid'=>2,'uid'=>7,'visible'=>1]],
    'af_kb_entries'=>[snapshot_entry(11,'character','oc-51',$rules),snapshot_entry(12,'arpg_item','ring',$itemRules),
        snapshot_entry(13,'arpg_element','life',['entity_kind'=>'primary_element','element_revision'=>1,'root_element'=>'life'])],
    'af_advinv_items'=>[['id'=>9,'uid'=>7,'entity'=>'equipment','subtype'=>'gear','kb_type'=>'arpg_item','kb_key'=>'ring','qty'=>1,'meta_json'=>'{}']],
    'af_advinv_equipped'=>[['uid'=>7,'equip_slot'=>'gear_1','item_id'=>9,'updated_at'=>5]],
];
$life = af_kb_arpg_read_element('life');
$parent = array_intersect_key($life['definition'],array_flip(['key','kind','definition_version','fingerprint']));
$db->tables['af_kb_entries'][] = snapshot_entry(14,'arpg_element','blood',['entity_kind'=>'magic_direction','element_revision'=>1,'root_element'=>'life','parent_ref'=>$parent]);
$blood = af_kb_arpg_read_element('blood');
$direction = array_intersect_key($blood['definition'],array_flip(['key','definition_version','fingerprint']));
$rules['magic_direction_ref'] = $direction;
$rules['character_abilities'] = [['ability_name'=>'Blood healing','damage_type'=>'healing','magic_direction_ref'=>$direction]];
$db->tables['af_kb_entries'][0] = snapshot_entry(11,'character','oc-51',$rules);
$baseline = $db->tables;
$adapter = new AF_ACS_CharacterSheetAdapter();
$dto = $adapter->createSnapshot(3,7);
snapshot_assert($dto['ready'],'Valid snapshot blocked: '.json_encode($dto['diagnostics']));
snapshot_assert($dto['base_characteristics']['accuracy'] === 20.0 && $dto['characteristics']['accuracy'] === 25.0,'Equipment not counted exactly once');
snapshot_assert($dto['characteristics']['status_resistance'] === 0.0,'Status alias lost');
snapshot_assert(count($dto['resistances']) === 17 && isset($dto['resistances']['air']) && !isset($dto['resistances']['wind']),'17 RES/wind normalization failed');
snapshot_assert(!isset($dto['resistances']['blood']) && $dto['classification']['magic_direction']['root_element'] === 'life','Direction/root/RES failed');
snapshot_assert($dto['abilities'][0]['definition']['damage']['element'] === null,'Healing inferred elemental damage');
$equipmentTrace = array_values(array_filter($dto['source_breakdown'],static fn($r) => $r['source_type'] === 'equipment'));
snapshot_assert(count($equipmentTrace) === 1 && $equipmentTrace[0]['included_in_total'] && $equipmentTrace[0]['before'] === 20.0 && $equipmentTrace[0]['after'] === 25.0,'Equipment provenance missing');
snapshot_assert($dto['equipment_policy'] === 'already_included_do_not_reapply' && !$dto['persistent'],'Snapshot persistence/reapply contract wrong');
snapshot_assert($db->tables === $baseline,'Read mutated persistent data');
snapshot_assert($adapter->createSnapshot(3,7) === $dto,'Repeated snapshot not deterministic');
snapshot_assert(!function_exists('af_charactersheets_compute_sheet_view') && !function_exists('af_charactersheets_sync_fixed_skills'),'Snapshot loaded sync stack');
$denied = $adapter->createSnapshot(3,8,['owner_uid'=>7,'is_admin'=>true]);
snapshot_assert(!$denied['ready'] && !isset($denied['identity']),'Spoofed actor accessed snapshot');
$mybb->user['uid'] = 8;
$denied = $adapter->createSnapshot(3,8);
snapshot_assert(!$denied['ready'] && !isset($denied['identity']),'Other user accessed owner data');
$mybb->user['uid'] = 7;
// Explicit origin/growth and manual additions reuse KB's exact operation order.
$originRules = af_kb_default_type_profile_payload_arpg('arpg_origin');
$originRules['schema'] = AF_KB_ARPG_RULES_SCHEMA;
$originRules['version'] = '1.0';
$originRules['hp_base'] = 100;
$originRules['accuracy_base'] = 99;
$originRules['status_resist_base'] = 2;
$withOrigin = $rules;
$withOrigin['character_profile']['character_origin'] = 'human';
$withOrigin['character_stats']['character_hp'] = 10;
$db->tables['af_kb_entries'][0] = snapshot_entry(11,'character','oc-51',$withOrigin);
$db->tables['af_kb_entries'][] = snapshot_entry(15,'arpg_origin','human',$originRules);
$originDto = $adapter->createSnapshot(3,7);
snapshot_assert($originDto['ready'] && $originDto['characteristics']['hp'] === 110.0 && $originDto['characteristics']['accuracy'] === 25.0 && $originDto['characteristics']['status_resistance'] === 2.0,'KB origin/manual order or authoritative Accuracy changed');
$db->tables = $baseline;
// A private linked definition must not leak through an unsuccessful DTO.
$privateBlood = json_decode($db->tables['af_kb_entries'][3]['data_json'],true,512,JSON_THROW_ON_ERROR);
$privateBlood['visibility'] = ['internal'=>true];
$db->tables['af_kb_entries'][3] = snapshot_entry(14,'arpg_element','blood',$privateBlood);
$privateDto = $adapter->createSnapshot(3,7);
snapshot_assert(!$privateDto['ready'] && !isset($privateDto['classification']) && !isset($privateDto['sources']),'Private linked definition leaked');
$db->tables = $baseline;
$missing = $rules; unset($missing['character_stats']['character_accuracy']);
$db->tables['af_kb_entries'][0] = snapshot_entry(11,'character','oc-51',$missing);
$dtoMissing = $adapter->createSnapshot(3,7);
snapshot_assert(!$dtoMissing['ready'] && !isset($dtoMissing['characteristics']['accuracy']),'Missing Accuracy invented');
$db->tables = $baseline;
$zero = $rules; $zero['character_stats']['character_accuracy'] = 0;
$db->tables['af_kb_entries'][0] = snapshot_entry(11,'character','oc-51',$zero);
snapshot_assert($adapter->createSnapshot(3,7)['characteristics']['accuracy'] === 5.0,'Explicit zero lost');
$db->tables = $baseline;
$opaque = $rules; $opaque['character_meta']['source'] = 'unknown';
$db->tables['af_kb_entries'][0] = snapshot_entry(11,'character','oc-51',$opaque);
snapshot_assert(!$adapter->createSnapshot(3,7)['ready'],'Opaque equipment inclusion accepted');
$db->tables = $baseline;
$db->changeDuringRead = true;
snapshot_assert(!$adapter->createSnapshot(3,7)['ready'],'Concurrent source change not detected');
$db->changeDuringRead = false;
$db->tables = $baseline;
$conflict = $rules; $conflict['character_stats']['character_status_resistance'] = 1;
$db->tables['af_kb_entries'][0] = snapshot_entry(11,'character','oc-51',$conflict);
snapshot_assert(!$adapter->createSnapshot(3,7)['ready'],'Status alias conflict accepted');
echo "CombatSnapshot: permission boundary, deterministic read-only DTO, Accuracy/missing/zero, 17 RES/wind, blood/root, equipment +5 once, source drift and alias conflicts passed\n";
