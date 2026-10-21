<?php
declare(strict_types=1);
// Reuse the existing small KB database fixture, no new framework.
require __DIR__ . '/kb_element_hierarchy_roundtrip.php';
$rules = af_kb_arpg_envelope_defaults('arpg_ability');
$rules['rules'] = array_replace($rules['rules'], af_kb_default_type_profile_payload_arpg('arpg_ability'), [
    'damage_type'=>'physical','_editor_magic_direction_key'=>'blood',
    'target_count'=>['min'=>1,'max'=>2], 'costs'=>[['kind'=>'resource','resource_key'=>'mana','value'=>5,'timing'=>'use']],
    'timing'=>['cooldown'=>['value'=>2,'unit'=>'own_turn']],
    'effects'=>[['kind'=>'damage','damage_type'=>'physical','value'=>5,'custom_allowed_field'=>['keep'=>true]]],
    'custom_allowed_field'=>['keep'=>true],
]);
$errors=[];
$json=af_kb_validate_rules_json_by_type_arpg('arpg_ability',json_encode($rules),$errors);
element_assert(!$errors,'Visual payload rejected: '.implode('; ',$errors));
$saved=json_decode($json,true)['rules'];
element_assert(!isset($saved['_editor_magic_direction_key']) && $saved['magic_direction_ref']['key']==='blood','Server did not pin selected direction');
$pin=$saved['magic_direction_ref'];
$errors=[];
$reopened=af_kb_validate_rules_json_by_type_arpg('arpg_ability',$json,$errors);
$again=json_decode($reopened,true)['rules'];
element_assert(!$errors && $again['magic_direction_ref']===$pin,'Reopening changed pinned ref');
element_assert($again['costs']===$saved['costs'] && $again['timing']===$saved['timing'],'Costs/cooldown changed');
element_assert($again['effects'][0]['custom_allowed_field']===['keep'=>true] && $again['custom_allowed_field']===['keep'=>true],'Unknown supported extension lost');
$legacy=af_kb_normalize_inline_ability_row(['title'=>'Old','effects'=>[['kind'=>'heal','value'=>0,'extension'=>1]],'modifiers'=>[['stat_key'=>'atk','mode'=>'flat','value'=>2,'extension'=>1]],'extension'=>['nested'=>true]]);
element_assert($legacy['extension']===['nested'=>true] && $legacy['modifiers'][0]['extension']===1,'Inline legacy extension lost');
echo "Visual ability new/existing save-read and explicit direction pinning: OK\n";

$db->entries['formula_profile']=['id'=>100,'type'=>'arpg_mechanics','key'=>'formula_profile','active'=>1,'title_ru'=>'Профили расчёта','title_en'=>'Formula profiles',
    'data_json'=>json_encode(['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'service_mechanics','service_kind'=>'formula_profile','entries'=>[
        ['key'=>'atk_profile','title'=>'Урон от атаки','description'=>'Описание формулы','active'=>1],
        ['key'=>'disabled_profile','title'=>'Скрытая формула','is_active'=>0],
    ]])];
$choices=af_kb_arpg_editor_choices();
element_assert(count($choices['formula_profile'])===1 && $choices['formula_profile'][0]['label']==='Урон от атаки','Profile title lost or inactive choice offered');
echo "Persisted formula profile names and inactive filtering: OK\n";
