<?php
declare(strict_types=1);
require __DIR__.'/kb_bestiary_status_roundtrip.php';
$db->entries['test_damage']=bestiary_entry('arpg_mechanics','test_damage',[
    'service_kind'=>'formula_def','version'=>'1.0','entries'=>[
        ['key'=>'test_damage','version'=>'1.0','inputs'=>[], 'expression'=>['type'=>'literal','value'=>1], 'output_type'=>'number','output_unit'=>'damage','rounding'=>'none','notes'=>'Fixture only']
    ],
]);
$before=$db->entries;
$payload=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$errors=[];$saved=bestiary_save($payload,$errors);
bestiary_assert(!$errors,'Editor payload rejected: '.implode('; ',$errors));
bestiary_assert($db->entries===$before,'Preparing refs wrote owner data');
$ref=[];$expected=af_kb_arpg_acs_reference('arpg_ability','blood_bolt','fixture',$ref);
$ability=$saved['ability_keys'][0];
bestiary_assert(!isset($ability['_editor_ability_key']) && $ability['ability_kb_key']==='blood_bolt','Selection marker not consumed');
bestiary_assert($ability['ability_fingerprint']===$expected['fingerprint'] && $ability['ability_definition_version']===$expected['definition_version'],'Server did not create exact ability pins');
bestiary_assert($saved['magic_direction_ref']['key']==='blood' && !isset($saved['damage_element']),'Direction changed damage semantics');
bestiary_assert(count($saved['resists'])===17 && isset($saved['resists']['wind']) && !isset($saved['resists']['air']),'RES wind alias duplicated');
bestiary_assert($saved['resists']['wind']['value']===0 && $saved['resists']['fire']['value']===7,'Missing/zero or entered resistance lost');
bestiary_assert($saved['formula_refs'][0]['key']==='test_damage' && $saved['formula_refs'][0]['version']==='1.0','Server formula version resolution failed');
$errors=[];$again=bestiary_save($saved,$errors);
bestiary_assert(!$errors && $again['ability_keys'][0]['ability_fingerprint']===$ability['ability_fingerprint'],'Reopening/re-saving changed pin');
// New source versions never silently refresh previously pinned definitions.
$db->entries['blood_bolt']['data_json']=json_encode(array_replace($abilityRules,['version'=>'3.0']));
$errors=[];$unchanged=bestiary_save($saved,$errors);
bestiary_assert($unchanged['ability_keys'][0]['ability_fingerprint']===$ability['ability_fingerprint'],'Ordinary save re-pinned stale version');
$saved['ability_keys'][0]['_editor_ability_key']='blood_bolt';
$errors=[];$updated=bestiary_save($saved,$errors);
bestiary_assert(!$errors && $updated['ability_keys'][0]['ability_fingerprint']!==$ability['ability_fingerprint'],'Explicit refresh did not update pin');
echo "Bestiary editor save/read, server-pinned ability/direction/formula, 17 RES and explicit refresh: OK\n";
