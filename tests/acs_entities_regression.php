<?php
declare(strict_types=1);
// Focused real MariaDB persistence test + existing write-forbidden owner API fixture.
$socket=$argv[1]??'';
if(!str_starts_with($socket,'/tmp/acs222-socket/') || !file_exists($socket))throw new RuntimeException('Use the disposable local ACS socket');
require __DIR__.'/combat_snapshot_readonly.php';
$db->tables=$baseline;
define('TABLE_PREFIX','fixture_');
require AF_ADDONS.'advancedcombatsystem/advancedcombatsystem.php';
require AF_ADDONS.'advancedcombatsystem/modules/Administration/Migrations.php';
require AF_ADDONS.'advancedcombatsystem/modules/Application/EntityService.php';
function htmlspecialchars_uni(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function verify_post_check(string $key,bool $silent=false):bool{return $key==='token';}
function blocked(callable $callback,string $code):void {
    try{$callback();}catch(Throwable $e){snapshot_assert(str_contains($e->getMessage(),$code),'Unexpected failure '.$e->getMessage());return;}
    throw new RuntimeException('Expected '.$code);
}
$mybb=new class($mybb) {
    public array $user;public array $usergroup;public array $settings;public string $request_method='post';public string $post_code='token';public array $input=['my_post_key'=>'token'];
    public function __construct($source){foreach(['user','usergroup','settings'] as $key)$this->$key=$source->$key;}
    public function get_input(string $key):string{return (string)($this->input[$key]??'');}
};
$access=new class extends AF_ACS_SessionAccess {
    public function thread(string $tid,?string $uid=null):array {if($tid!=='51')throw new RuntimeException('ACS_THREAD_UNAVAILABLE');return ['tid'=>51,'subject'=>'Fixture'];}
    public function user(string $uid):array {return ['uid'=>(int)$uid];}
    public function canCreate():bool{return $this->uid()==='7';}
};
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$server=new mysqli('localhost','root','','',0,$socket);$database='acs222_'.bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $link=new mysqli('localhost','root','',$database,0,$socket);$link->set_charset('utf8mb4');
    $migrations=new AF_ACS_Migrations($link);$migrations->run();
    snapshot_assert($migrations->status()['schema_version']==='004_round_roster','Schema version');
    // Simulate interrupted DDL after groups/entities, never use this against production.
    foreach(['entity_states','resources','controllers'] as $table)$link->query('DROP TABLE fixture_af_acs_'.$table);
    $link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=2 WHERE version='002_entities'");
    $migrations->run();$migrations->run();snapshot_assert($migrations->status()['error']===null,'Resume/repeat failed');
    $repo=new AF_ACS_EntityRepository($link);$sessions=new AF_ACS_SessionService($repo,$access);
    $created=$sessions->command('create',['tid'=>'51','owner_uid'=>'7','operation_key'=>'session_fixture_0001','reason'=>'Test session']);$sid=$created['session_id'];
    $source=new AF_ACS_RegistrationSource;$entities=new AF_ACS_EntityService($repo,$access,$source);
    $before=$db->tables;
    $input=['session_id'=>$sid,'expected_version'=>$created['session_version'],'kind'=>'character','source_key'=>'3','initial_hp'=>'90','name'=>'Hero','operation_key'=>'character_fixture_01','reason'=>'Register hero'];
    snapshot_assert($entities->validate($sid,$input)['ready'],'Valid owner preparation failed');
    $result=$entities->register($input);snapshot_assert($entities->register($input)===$result,'Registration replay changed IDs/version');
    snapshot_assert(count($entities->list($sid)['items'])===1,'Duplicate entity');
    $snapshot=$entities->snapshot($sid,$result['entity_id']);
    snapshot_assert($snapshot['source_snapshot']['characteristics']['accuracy']===25.0,'Equipment applied twice');
    snapshot_assert(count($snapshot['source_snapshot']['resistances'])===17 && !isset($snapshot['source_snapshot']['resistances']['blood']),'RES contract lost');
    snapshot_assert($snapshot['source_snapshot']['classification']['magic_direction']['root_element']==='life','Blood/root lost');
    snapshot_assert($db->tables===$before,'Character registration wrote external data');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row()[0]==='2','Audit not exactly once');
    $event=$link->query('SELECT payload_json FROM fixture_af_acs_event_details ORDER BY event_id DESC LIMIT 1')->fetch_assoc();
    snapshot_assert(json_decode($event['payload_json'],true)['result']['snapshot_id']===$result['snapshot_id'],'Audit snapshot link missing');
    $rowsBefore=$link->query('SELECT COUNT(*) FROM fixture_af_acs_snapshots')->fetch_row();
    $missing=$rules;unset($missing['character_stats']['character_accuracy']);$db->tables['af_kb_entries'][0]=snapshot_entry(11,'character','oc-51',$missing);
    $invalid=array_replace($input,['operation_key'=>'missing_accuracy_001','expected_version'=>$result['session_version']]);
    $validation=$entities->validate($sid,$invalid);snapshot_assert(!$validation['ready'],'Incomplete character accepted');
    blocked(fn()=>$entities->register($invalid),'ACS_REGISTRATION_BLOCKED');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_snapshots')->fetch_row()===$rowsBefore,'Failed registration left partial snapshot');
    // Committed retry and saved read must not load the changed live source.
    snapshot_assert($entities->register($input)===$result,'Changed source broke committed replay');
    snapshot_assert($entities->snapshot($sid,$result['entity_id'])===$snapshot,'Saved immutable snapshot changed with KB');
    $db->tables=$before;
    blocked(fn()=>$entities->register(array_replace($input,['operation_key'=>'duplicate_identity_1','expected_version'=>$result['session_version']])),'ACS_ENTITY_ALREADY_REGISTERED');
    // Public/editable source is not ownership: a GM cannot register another player's sheet.
    $gm=$sessions->command('set_role',['session_id'=>$sid,'expected_version'=>$result['session_version'],'uid'=>'8','role'=>'gm','operation_key'=>'appoint_gm_fixture1','reason'=>'Appoint GM']);
    $mybb->user['uid']=8;
    snapshot_assert(!$entities->validate($sid,$input)['ready'],'GM gained another owner sheet');
    blocked(fn()=>$entities->snapshot($sid,$result['entity_id']),'ACS_REGISTRATION_DENIED');
    $mybb->user['uid']=9;blocked(fn()=>$entities->list($sid),'ACS_SESSION_UNAVAILABLE');$mybb->user['uid']=7;
    // Real KB normalization + adapter for two independent instances.
    $monster=af_kb_default_type_profile_payload_arpg('arpg_bestiary');
    $monster['family']=$monster['archetype']=$monster['faction']='fixture';
    $monster['combat_stats']['hp']=100;$monster['combat_stats']['accuracy']=0;$monster['combat_stats']['status_resist']=0;$monster['resists']=$res;$monster['magic_direction_ref']=$direction;
    $ability=af_kb_default_type_profile_payload_arpg('arpg_ability');
    $ability['schema']=AF_KB_ARPG_RULES_SCHEMA;$ability['type_profile']='ability';$ability['version']='1.0';
    $ability['type']='active';$ability['slot']='active';$ability['damage_type']='physical';$ability['targeting']='single_enemy';
    $ability['cooldown_unit']='own_turn';$ability['duration_unit']='own_turn';$ability['magic_direction_ref']=$direction;
    $db->tables['af_kb_entries'][]=snapshot_entry(79,'arpg_ability','claw',$ability);
    $errors=[];$reference=af_kb_arpg_prepare_bestiary_ability_ref(['_editor_ability_key'=>'claw','ability_name'=>'Claw','damage_type'=>'physical','magic_direction_ref'=>$direction,'cooldown_unit'=>'own_turn','duration_unit'=>'own_turn'],$errors);
    snapshot_assert(!$errors,'Ability pin fixture failed');$monster['ability_keys']=[$reference];
    $envelope=af_kb_arpg_envelope_defaults('arpg_bestiary');$envelope['rules']=$monster;$errors=[];
    $json=af_kb_validate_rules_json_by_type_arpg('arpg_bestiary',json_encode($envelope,JSON_THROW_ON_ERROR),$errors);snapshot_assert(!$errors,'Invalid monster fixture');
    $db->tables['af_kb_entries'][]=['id'=>80,'type'=>'arpg_bestiary','key'=>'monster','active'=>1,'data_json'=>$json,'meta_json'=>'{}'];
    $monsterInput=['session_id'=>$sid,'expected_version'=>$gm['session_version'],'kind'=>'monster','source_key'=>'monster','initial_hp'=>'30','operation_key'=>'monster_fixture_0001','reason'=>'First monster'];
    $first=$entities->register($monsterInput);
    $second=$entities->register(array_replace($monsterInput,['operation_key'=>'monster_fixture_0002','expected_version'=>$first['session_version']]));
    snapshot_assert($first['entity_id']!==$second['entity_id'] && $first['snapshot_id']!==$second['snapshot_id'] && $first['control_group_id']!==$second['control_group_id'],'Monsters share mutable identity/group');
    $pinned=$entities->snapshot($sid,$first['entity_id']);
    snapshot_assert(isset($pinned['pinned_abilities']['claw']['data']['definition']),'Referenced ability content not pinned');
    $abilityIndex=count($db->tables['af_kb_entries'])-2;
    $ability['version']='2.0';$db->tables['af_kb_entries'][$abilityIndex]=snapshot_entry(79,'arpg_ability','claw',$ability);
    snapshot_assert($entities->snapshot($sid,$first['entity_id'])['pinned_abilities']['claw']['data']['definition']['version']==='1.0','Ability pin changed with KB');
    snapshot_assert($pinned['source_snapshot']['characteristics']['accuracy']===0,'Explicit zero lost');
    $index=count($db->tables['af_kb_entries'])-1;$updated=json_decode($json,true);$updated['rules']['combat_stats']['accuracy']=55;$db->tables['af_kb_entries'][$index]['data_json']=json_encode($updated);
    snapshot_assert($entities->snapshot($sid,$first['entity_id'])===$pinned,'Template change mutated pinned definition');
    snapshot_assert($entities->register($monsterInput)===$first,'Monster committed retry reread KB');
    // No plugin exists yet: future pet boundary must fail closed without records.
    $pet=array_replace($monsterInput,['kind'=>'pet','source_key'=>'pet-1','operation_key'=>'pet_unavailable_001','expected_version'=>$second['session_version']]);
    $check=$entities->validate($sid,$pet);snapshot_assert(!$check['ready'] && $check['diagnostics'][0]['code']==='permanent_pet_source_unavailable','Missing pet plugin not diagnosed');
    // Future owner-provider fixture demonstrates same owner group, no separate AP.
    $petSource=new class($snapshot['source_snapshot']) implements AF_ACS_PermanentPetSource {
        public function __construct(private array $dto){}
        public function read(string $key,int $actor):array {$dto=$this->dto;$dto['identity']['source_type']='permanent_pet';$dto['identity']['source_key']=$key;$dto['contract_id']='IC-ACS.permanent-pet-preparation.v1';unset($dto['fingerprint']);$dto['fingerprint']=af_kb_arpg_acs_fingerprint($dto);return $dto;}
        public function choices(int $actor,int $after):array{return ['items'=>[['key'=>'pet-1','label'=>'Test pet']],'next_after'=>null];}
    };
    $petService=new AF_ACS_EntityService($repo,$access,new AF_ACS_RegistrationSource($petSource));$registeredPet=$petService->register($pet);
    snapshot_assert($registeredPet['control_group_id']===$result['control_group_id'],'Pet received independent group');
    snapshot_assert($petService->snapshot($sid,$registeredPet['entity_id'])['source_snapshot']['identity']['owner_uid']===7,'Pet owner lost');
    $saved=$entities->snapshot($sid,$result['entity_id']);
    $GLOBALS['af_acs_deactivated']=true;blocked(fn()=>$entities->register($monsterInput),'ACS_DISABLED');unset($GLOBALS['af_acs_deactivated']);
    $migrations->assertInstalled();snapshot_assert($entities->snapshot($sid,$result['entity_id'])===$saved,'Reactivation changed snapshot');
    $mybb->request_method='get';blocked(fn()=>$entities->register($input),'ACS_POST_REQUIRED');$mybb->request_method='post';$mybb->input['my_post_key']='bad';blocked(fn()=>$entities->register($input),'ACS_CSRF_REQUIRED');
    $mybb->input=['my_post_key'=>'token','kind'=>'character'];$mybb->request_method='get';
    require AF_ADDONS.'advancedcombatsystem/modules/Presentation/Entities.php';
    $externalBefore=$db->tables;$acsBefore=$link->query('SELECT COUNT(*) FROM fixture_af_acs_operations')->fetch_row();
    ob_start();AF_ACS_EntitiesPage::render($sid,$sessions->view($sid),'misc.php?action=acs_sessions',$entities);$html=ob_get_clean();
    snapshot_assert(str_contains($html,'Зарегистрированные существа') && str_contains($html,'value="3"') && str_contains($html,'Начальное HP') && !str_contains($html,'sha256:'),'Registration form/picker leaked or missing');
    snapshot_assert($db->tables===$externalBefore && $link->query('SELECT COUNT(*) FROM fixture_af_acs_operations')->fetch_row()===$acsBefore,'GET form changed permanent data');
    snapshot_assert(!function_exists('af_charactersheets_compute_sheet_view'),'Registration loaded saving view/calculator');
    echo "ACS entities: migration resume/repeat, owner snapshot, missing Accuracy rollback, replay/pins, two monster IDs/groups, 17 RES/blood/equipment, access/CSRF, future pet binding and reactivation passed\n";
    if(isset($acsEncounterFixture))$acsEncounterFixture(compact('sid','result','first','second','registeredPet','link','access','sessions','entities','migrations'));
} finally {$server->query('DROP DATABASE `'.$database.'`');}
