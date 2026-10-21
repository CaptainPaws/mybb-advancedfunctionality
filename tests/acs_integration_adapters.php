<?php
declare(strict_types=1);
define('IN_ADMINCP',true);
// Reuse the small write-forbidden owner fixture and its real CS/KB pipeline checks.
require __DIR__.'/combat_snapshot_readonly.php';
$db->tables = $baseline;
require_once AF_ADDONS.'advancedcombatsystem/modules/Administration/Diagnostics.php';
require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php';
require_once AF_ADDONS.'advancedcombatsystem/modules/Administration/IntegrationCapabilities.php';
function af_discover_addons(): array {
    $rows = [];
    foreach (['knowledgebase','charactersheets','advancedcombatsystem','advancedinventory'] as $id) $rows[] = ['id'=>$id,'version'=>'fixture'];
    return $rows;
}
function af_advancedcombatsystem_migrations(): object { return new class { public function status(): array { return ['schema_version'=>'001_foundation','error'=>null,'migrations'=>[],'database'=>['fixture']]; } }; }
foreach (array_keys(af_kb_arpg_primary_elements()) as $key) {
    if ($key === 'life') continue;
    $sourceKey = $key === 'air' ? 'wind' : $key;
    $db->tables['af_kb_entries'][] = snapshot_entry(100+count($db->tables['af_kb_entries']),'arpg_element',$sourceKey,
        ['entity_kind'=>'primary_element','element_revision'=>1,'root_element'=>$key]);
}
$monsterRules = af_kb_default_type_profile_payload_arpg('arpg_bestiary');
$monsterRules['family'] = $monsterRules['archetype'] = $monsterRules['faction'] = 'fixture';
$monsterRules['combat_stats']['accuracy'] = 25;
$monsterRules['combat_stats']['status_resist'] = 0;
$monsterRules['resists'] = $res;
$monsterRules['magic_direction_ref'] = $direction;
$monsterRules['ability_keys'] = [['ability_name'=>'Claw','damage_type'=>'physical','magic_direction_ref'=>$direction,
    'cooldown_unit'=>'own_turn','duration_unit'=>'own_turn']];
$envelope = af_kb_arpg_envelope_defaults('arpg_bestiary'); $envelope['rules'] = $monsterRules;
$errors = [];
$json = af_kb_validate_rules_json_by_type_arpg('arpg_bestiary',json_encode($envelope,JSON_THROW_ON_ERROR),$errors);
snapshot_assert(!$errors,'Monster roundtrip validation failed: '.implode('; ',$errors));
$db->tables['af_kb_entries'][] = ['id'=>80,'type'=>'arpg_bestiary','key'=>'monster','active'=>1,'data_json'=>$json,'meta_json'=>'{}'];
$before = $db->tables;
$kb = new AF_ACS_KnowledgeBaseAdapter;
$definition = $kb->readDefinition('arpg_bestiary','monster',7);
snapshot_assert($definition['ready'],'KB adapter blocked valid monster: '.json_encode($definition['diagnostics']));
snapshot_assert($definition['data']['combat_stats']['accuracy'] === 25 && $definition['data']['combat_stats']['status_resistance'] === 0,'Stats lost in adapter');
snapshot_assert(count($definition['data']['element_resistances']) === 17 && isset($definition['data']['element_resistances']['air']),'17 RES not propagated');
snapshot_assert($definition['data']['classification']['magic_direction']['root_element'] === 'life' && $definition['data']['classification']['element'] === null,'Direction inferred damage');
$characterDefinition = $kb->readDefinition('character','oc-51',7);
snapshot_assert($characterDefinition['ready'] && $characterDefinition['readiness_scope'] === 'definition_inputs_not_calculated_snapshot','KB definition was confused with calculated CS data');
$characterConflict = $rules; $characterConflict['character_stats']['character_status_resistance'] = 1;
$db->tables['af_kb_entries'][0] = snapshot_entry(11,'character','oc-51',$characterConflict);
$blockedCharacter = $kb->readDefinition('character','oc-51',7);
snapshot_assert(!$blockedCharacter['ready'] && !array_key_exists('status_resistance',$blockedCharacter['data']['definition_stats']),'Character alias conflict left a usable value');
$db->tables = $before;
$air = $kb->readDefinition('arpg_element','air',7);
snapshot_assert($air['ready'] && $air['data']['element_definition']['source_key'] === 'wind','Alias source lost');
$bloodDto = $kb->readDefinition('arpg_element','blood',7);
snapshot_assert($bloodDto['ready'] && $bloodDto['data']['element_definition']['root_element'] === 'life','Blood hierarchy lost');
snapshot_assert($kb->primaryElements(7)['ready'],'Actual primary catalog not ready');
snapshot_assert(!$kb->readDefinition('arpg_bestiary','monster',8)['ready'],'Spoofed KB actor accepted');
$monsterIndex = count($db->tables['af_kb_entries'])-1;
$missing = json_decode($json,true,512,JSON_THROW_ON_ERROR); unset($missing['rules']['combat_stats']['accuracy']);
$db->tables['af_kb_entries'][$monsterIndex]['data_json'] = json_encode($missing,JSON_THROW_ON_ERROR);
snapshot_assert(!$kb->readDefinition('arpg_bestiary','monster',7)['ready'],'Missing Accuracy accepted');
$db->tables = $before;
$conflict = json_decode($json,true,512,JSON_THROW_ON_ERROR); $conflict['rules']['combat_stats']['status_resistance'] = 1;
$db->tables['af_kb_entries'][$monsterIndex]['data_json'] = json_encode($conflict,JSON_THROW_ON_ERROR);
$blocked = $kb->readDefinition('arpg_bestiary','monster',7);
snapshot_assert(!$blocked['ready'] && in_array('status_resistance_alias_conflict',array_column($blocked['diagnostics'],'code'),true),'Status conflict lost');
$db->tables = $before;
$private = json_decode($db->tables['af_kb_entries'][3]['data_json'],true,512,JSON_THROW_ON_ERROR); $private['visibility'] = ['internal'=>true];
$db->tables['af_kb_entries'][3] = snapshot_entry(14,'arpg_element','blood',$private);
$blocked = $kb->readDefinition('arpg_bestiary','monster',7);
snapshot_assert(!$blocked['ready'] && !isset($blocked['data']),'Private nested definition leaked');
$db->tables = $before;
$abilityRules = af_kb_default_type_profile_payload_arpg('arpg_ability');
$abilityRules['type'] = 'active'; $abilityRules['slot'] = 'active'; $abilityRules['damage_type'] = 'physical'; $abilityRules['targeting'] = 'single_enemy';
$abilityRules['schema'] = AF_KB_ARPG_RULES_SCHEMA; $abilityRules['type_profile'] = 'ability'; $abilityRules['version'] = '1.0';
$abilityRules['magic_direction_ref'] = $direction; $abilityRules['cooldown_unit'] = 'own_turn'; $abilityRules['duration_unit'] = 'own_turn';
$db->tables['af_kb_entries'][] = snapshot_entry(90,'arpg_ability','blood_skill',$abilityRules);
snapshot_assert($kb->readDefinition('arpg_ability','blood_skill',7)['ready'],'Valid ability not readable');
$abilityRules['damage_type'] = 'elemental'; $abilityRules['element'] = 'life';
$db->tables['af_kb_entries'][count($db->tables['af_kb_entries'])-1] = snapshot_entry(90,'arpg_ability','blood_skill',$abilityRules);
snapshot_assert(!$kb->readDefinition('arpg_ability','blood_skill',7)['ready'],'Legacy element silently replaced explicit damage_element');
$db->tables = $before;
$mybb->usergroup['cancp'] = 1;
$mybb->admin = ['permissions'=>['advancedfunctionality'=>['index'=>1]]];
$diagnostics = new AF_ACS_Diagnostics;
$rows = $diagnostics->capabilities(true,['sheet_id'=>3,'kb_type'=>'arpg_bestiary','kb_key'=>'monster']);
foreach (AF_ACS_CapabilityValidator::IDS as $id) snapshot_assert($rows[$id]['readiness'] === 'READY','Capability not ready '.$id.': '.json_encode($rows[$id]));
snapshot_assert($rows['character_snapshot']['scope'] === 'selected_character' && $rows['accuracy_roundtrip']['scope'] === 'kb_memory_contract','Capability scope hidden');
snapshot_assert($rows['cs.combat_snapshot']['readiness'] === 'BLOCKED' && $rows['inv.reserve_consume']['readiness'] === 'BLOCKED','Future immutable/reservation gate falsely ready');
$ownerSnapshot = af_charactersheets_create_combat_snapshot(3,7);
unset($ownerSnapshot['equipment_policy']);
snapshot_assert(!AF_ACS_CapabilityValidator::character($ownerSnapshot)['ready'],'Missing inclusion policy accepted');
$withoutTarget = $diagnostics->capabilities(true);
snapshot_assert($withoutTarget['character_snapshot']['readiness'] === 'BLOCKED' && $withoutTarget['accuracy_roundtrip']['readiness'] === 'READY','Source-specific state conflated with contract');
$GLOBALS['disabled'] = ['knowledgebase'];
snapshot_assert($diagnostics->capabilities(true)['accuracy_registry']['readiness'] === 'BLOCKED','Disabled owner ready');
$GLOBALS['disabled'] = [];
$mybb->admin = ['permissions'=>[]];
try { $diagnostics->capabilities(true); throw new RuntimeException('Unauthorized diagnostics accepted'); }
catch (RuntimeException $e) { snapshot_assert($e->getMessage() === 'ACS_ADMIN_PERMISSION_REQUIRED','Wrong permission failure'); }
$mybb->admin = ['permissions'=>['advancedfunctionality'=>['index'=>1]]];
// Native ACP dispatch: metadata rows only, no private snapshot payload.
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function af_sync_addon_languages(...$args): void {}
function af_load_addon_lang(...$args): void {}
final class Table {
    private array $cells = [];
    public function construct_header($value): void { $this->cells[] = $value; }
    public function construct_cell($value): void { $this->cells[] = $value; }
    public function construct_row(): void {}
    public function output($title): void { echo $title.implode('|',$this->cells); }
}
$lang = (object)(require AF_ADDONS.'advancedcombatsystem/manifest.php')['lang']['english']['admin'];
$mybb = new class($mybb) {
    public array $user; public array $usergroup; public array $settings; public array $admin;
    public string $request_method = 'get';
    public function __construct($source) { foreach (['user','usergroup','settings','admin'] as $field) $this->$field = $source->$field; }
    public function get_input($key): string { return ['acs_section'=>'integrations','acs_probe_sheet_id'=>'3','acs_probe_kb_type'=>'arpg_bestiary','acs_probe_kb_key'=>'monster'][$key] ?? ''; }
};
require_once AF_ADDONS.'advancedcombatsystem/admin.php';
ob_start(); AF_Admin_Advancedcombatsystem::dispatch(); $html = ob_get_clean();
snapshot_assert(str_contains($html,'canonical_elements_17') && str_contains($html,'selected_character') && str_contains($html,'method="get"'),'ACP capability/form dispatch failed');
snapshot_assert(!str_contains($html,'sha256:') && !str_contains($html,'Blood healing') && !str_contains($html,'source_fingerprint'),'ACP printed full snapshot data');
snapshot_assert($db->tables === $before,'Read-only integration changed rows');
snapshot_assert(!class_exists('AF_ACS_Migrations',false) && !function_exists('af_charactersheets_compute_sheet_view'),'Probes loaded migrations or heavy calculator');
echo "ACS adapters/capabilities: KB roundtrip, source gates, 17 RES/wind/blood, permissions/private refs, ACP metadata and no writes passed\n";
