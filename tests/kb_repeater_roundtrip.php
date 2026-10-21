<?php
declare(strict_types=1);
define('IN_MYBB',1);
define('AF_ADDONS',__DIR__.'/../inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS.'knowledgebase/knowledgebase.php';
$rules=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$payload=af_kb_arpg_envelope_defaults('arpg_ability');
$payload['rules']=array_replace($payload['rules'],af_kb_default_type_profile_payload_arpg('arpg_ability'),$rules);
$errors=[];
$saved=af_kb_validate_rules_json_by_type_arpg('arpg_ability',json_encode($payload),$errors);
if($errors)throw new RuntimeException(implode('; ',$errors));
$decoded=json_decode($saved,true)['rules'];
foreach(['resources','effects','modifiers','triggers','conditions','stacking','upgrade_requirements','grants'] as $key) {
 if(($decoded[$key]??null)!==$rules[$key])throw new RuntimeException($key.' changed on save');
}
echo "DOM-generated repeaters survive KB save/read: OK\n";
