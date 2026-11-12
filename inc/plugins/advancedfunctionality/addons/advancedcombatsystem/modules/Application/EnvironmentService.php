<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/../Infrastructure/EncounterRepository.php';
require_once __DIR__.'/../Domain/Effects.php';
require_once __DIR__.'/../Domain/Resolver.php';

/** Narrow GM backend API. No JSON editing or ordinary object-interaction UI. */
final class AF_ACS_EnvironmentService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access) {}
    private function authorize(string $sid):array
    {
        $v=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);
        if(!AF_ACS_AuthorizationService::sessionGm($v['session'],$this->repo->roles($sid,$this->access->uid()),$this->access->uid()))throw new RuntimeException('ACS_GM_REQUIRED');
        if($v['session']['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');return $v['session'];
    }
    public function command(string $operation,array $input):array
    {
        $this->access->csrf();if(!in_array($operation,['create','activate','deactivate','damage'],true))throw new RuntimeException('ACS_OBJECT_UNSUPPORTED');
        $allowed=['session_id','encounter_id','expected_version','expected_encounter_version','expected_object_version','operation_key','reason'];
        array_push($allowed,...($operation==='create'?['source_entity_id','ability_key']:['entity_id']));if($operation==='damage')array_push($allowed,'amount','damage_type');
        if(array_diff(array_keys($input),$allowed)||array_diff($allowed,array_keys($input)))throw new RuntimeException('ACS_OBJECT_INPUT_INVALID');
        $r=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'operation'=>$operation];
        foreach($allowed as $key){if($key==='operation_key')continue;$r[$key]=$input[$key];}
        foreach(['session_id','encounter_id','expected_version','expected_encounter_version','expected_object_version',$operation==='create'?'source_entity_id':'entity_id'] as $key)$r[$key]=AF_ACS_Repository::integer((string)$r[$key],'9007199254740991',$key==='expected_object_version'&&$operation==='create');
        if($operation==='create'&&$r['expected_object_version']!=='0')throw new RuntimeException('ACS_OBJECT_VERSION_CONFLICT');
        if(!is_string($r['reason'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000||!mb_check_encoding($r['reason'],'UTF-8')||str_contains($r['reason'],"\0"))throw new RuntimeException('ACS_REASON_REQUIRED');
        if($operation==='create'&&(!is_string($r['ability_key'])||!preg_match('/\A[a-z0-9][a-z0-9_-]{0,95}\z/D',$r['ability_key'])))throw new RuntimeException('ACS_OBJECT_INPUT_INVALID');
        if($operation==='damage'){$r['amount']=(int)AF_ACS_Repository::integer((string)$r['amount'],'9007199254740991');if(!is_string($r['damage_type']))throw new RuntimeException('ACS_OBJECT_INPUT_INVALID');}else{$r['amount']=null;$r['damage_type']=null;}
        if(!is_string($input['operation_key'])||!preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/D',$input['operation_key']))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $this->authorize($r['session_id']);$encounter=null;
        return $this->repo->command('session:'.$this->access->uid().':object:'.$input['operation_key'],'encounter.environment_'.$operation,$r,
            function($session)use($r){$this->authorize($r['session_id']);if(!$session||$session['id']!==$r['session_id'])throw new RuntimeException('ACS_SESSION_UNAVAILABLE');},
            function($session)use($r,$operation,&$encounter){
                $encounter=$this->repo->encounter($r['session_id'],$r['encounter_id'],true);
                if(!$encounter||$encounter['status']!=='active')throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
                if($encounter['encounter_version']!==$r['expected_encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                if($operation!=='create')return $this->repo->changeObject($session,$encounter,$r);
                $this->repo->objectCreationPlan($r['session_id'],$r['encounter_id'],$r['source_entity_id'],$r['ability_key']);
                return $this->repo->change($session,$encounter,'environment_change',[]);
            },$operation==='create'?function($session,$event)use(&$encounter,$r){return $this->repo->createObjectsAtEvent($session,$event,$encounter,$r);}:null);
    }
}
