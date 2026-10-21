<?php
declare(strict_types=1);
define('IN_MYBB', 1);
define('MYBB_ROOT', dirname(__DIR__) . '/');
define('AF_ADDONS', MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/');
function my_substr(string $s, int $start, ?int $length=null): string { return mb_substr($s,$start,$length); }
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';
require_once AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php';
function damage_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach ([
    ['type_profile'=>'character','element'=>'fire','damage_element'=>''],
    ['type_profile'=>'character','damage_type'=>'elemental','element'=>'fire'],
    ['damage_type'=>'physical','damage_element'=>''],
    ['kind'=>'heal','damage_type'=>'elemental'],
    ['damage_type'=>'elemental','damage_element'=>'wind'],
    ['effects'=>[['kind'=>'damage','damage_type'=>'elemental','damage_element'=>'life'], ['kind'=>'heal']]],
] as $row) {
    $errors=[]; af_kb_arpg_validate_element_fields($row,$errors);
    damage_check(!$errors,'Valid context rejected: '.implode(', ',$errors));
}
foreach ([['damage_type'=>'elemental'],['damage_type'=>'elemental','element'=>'life'],['damage_type'=>'elemental','damage_element'=>'blood'],['damage_type'=>'physical','damage_element'=>'life']] as $row) {
    $errors=[]; af_kb_arpg_validate_element_fields($row,$errors);
    damage_check((bool)$errors,'Invalid damage accepted');
}
$row=['title'=>'Spell','damage_type'=>'elemental','damage_element'=>'air','effects'=>[['kind'=>'damage','damage_type'=>'elemental','damage_element'=>'life']]];
$atf=af_atf_normalize_character_ability_row($row);
$kb=af_kb_normalize_inline_ability_row($atf);
damage_check($kb['damage_element']==='air' && $kb['effects'][0]['damage_element']==='life','ATF → KB lost component element');
$errors=[]; af_kb_arpg_validate_element_fields($kb,$errors); damage_check(!$errors,'Roundtrip rejected');
damage_check(str_contains(af_kb_arpg_element_error_message('damage_element_required'),'Стихия урона'),'UI message not localized');
echo "Damage contexts and ATF/KB roundtrip: OK\n";
