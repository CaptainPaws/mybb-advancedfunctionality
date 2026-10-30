<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/../Infrastructure/EncounterRepository.php';

/** GM-only preparation; combat start belongs to the future atomic roster service. */
final class AF_ACS_EncounterService
{
    public function __construct(private AF_ACS_EncounterRepository $repository,private AF_ACS_SessionAccess $access) {}
    private function authorize(string $sid,bool $write=false,?string $tid=null): array
    {
        $view=(new AF_ACS_SessionService($this->repository,$this->access))->view($sid);$session=$view['session'];
        $roles=array_column($this->repository->roles($sid,$this->access->uid()),'role');
        if(!$this->access->administrator() && !(in_array('gm',$roles,true) || (in_array('owner',$roles,true) && $session['owner_uid']===$this->access->uid())))throw new RuntimeException('ACS_GM_REQUIRED');
        if($tid!==null && $session['tid']!==$tid)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        if($write && $session['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
        return $session;
    }
    public function context(string $sid,string $tid): array {return $this->authorize($sid,tid:$tid);}
    public function list(string $sid,string $after='0',?string $tid=null): array
    {
        $this->authorize($sid,tid:$tid);$rows=$this->repository->encounters($sid,$after);
        return ['items'=>$rows,'next_after'=>count($rows)===100?(string)end($rows)['id']:null];
    }
    public function view(string $sid,string $eid,?string $tid=null,string $after='0',string $sideAfter='0',string $relationAfter='0'): array
    {
        $session=$this->authorize($sid,tid:$tid);$encounter=$this->repository->encounter($sid,$eid);
        if(!$encounter)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        return ['session'=>$session,'encounter'=>$encounter,'sides'=>$this->repository->sides($eid,$sideAfter),'relationships'=>$this->repository->relations($eid,$relationAfter),
            'participants'=>$this->repository->participants($eid,$after),'controllers'=>$this->repository->masters($sid)];
    }
    public function events(string $sid,string $eid,string $after='0'): array
    {
        $this->authorize($sid);if(!$this->repository->encounter($sid,$eid))throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        return $this->repository->events($sid,$eid,$after);
    }
    public function candidates(string $sid,string $after='0'): array
    {
        $this->authorize($sid);return $this->repository->candidatesForEncounter($sid,$after);
    }
    private static function text(mixed $value,int $limit): string
    {
        if(!is_string($value) || trim($value)==='' || mb_strlen($value)>$limit || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0"))throw new RuntimeException('ACS_INVALID_TEXT');return trim($value);
    }
    private function side(string $eid,string $id): void
    {
        if(!$this->repository->sideExists($eid,$id))throw new RuntimeException('ACS_SIDE_UNAVAILABLE');
    }
    /** Validates stored versions/hashes only, never re-snapshots live CS/KB definitions. */
    private function entity(string $sid,string $id,string $eid,bool $checkController=true): array
    {
        $entity=$this->repository->entity($sid,$id);
        if(!$entity || !in_array($entity['kind'],['character','pet','npc','monster'],true) || $entity['group_session']!==$sid || $this->repository->occupied($id,$eid))throw new RuntimeException('ACS_ENTITY_UNAVAILABLE');
        $owned=in_array($entity['kind'],['character','pet'],true);
        if($owned && ($entity['owner_uid']===null || $entity['group_owner']!==$entity['owner_uid'] || $entity['group_kind']!=='player'))throw new RuntimeException('ACS_CONTROL_INVALID');
        if(!$owned && ($entity['owner_uid']!==null || $entity['group_owner']!==null || $entity['group_kind']!=='independent'))throw new RuntimeException('ACS_CONTROL_INVALID');
        $controllers=$this->repository->controllers($sid,$id);
        if(count($controllers)!==1 || $controllers[0]['mode']!=='exclusive' || $controllers[0]['controller_uid']===null)throw new RuntimeException('ACS_CONTROL_INVALID');
        $controller=$controllers[0]['controller_uid'];
        if($owned && $controller!==$entity['owner_uid'])throw new RuntimeException('ACS_CONTROL_INVALID');
        if($checkController) {
        $this->access->user($controller);$this->access->thread((string)$this->repository->session($sid)['tid'],$controller);
        $roles=array_column($this->repository->roles($sid,$controller),'role');
        if(!array_intersect($roles,$owned?['owner','gm','assistant_gm','player']:['owner','gm']))throw new RuntimeException('ACS_CONTROL_INVALID');
        }
        if(!hash_equals($entity['content_hash'],hash('sha256',$entity['payload_json'])))throw new RuntimeException('ACS_SNAPSHOT_HASH_MISMATCH');
        $snapshot=json_decode($entity['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(($snapshot['schema_version']??null)!==1 || ($snapshot['contract_id']??null)!=='IC-ACS.entity-registration.v1'
            || ($snapshot['numeric_policy']??null)!=='binary64-half_up-v1' || ($snapshot['engine_version']??null)!=='acs-registration-1'
            || empty($snapshot['source_snapshot']['ready']) || ($snapshot['equipment_policy']??null)!=='already_included_do_not_reapply')throw new RuntimeException('ACS_SNAPSHOT_INVALID');
        $source=$snapshot['source_snapshot'];
        if($owned && (string)($source['identity']['owner_uid']??'')!==$entity['owner_uid'])throw new RuntimeException('ACS_CONTROL_INVALID');
        if($entity['kind']==='character' && (string)($source['identity']['sheet_id']??'')!==(string)$entity['sheet_id'])throw new RuntimeException('ACS_CONTROL_INVALID');
        if(!af_is_addon_enabled('knowledgebase'))throw new RuntimeException('ACS_DEPENDENCY_UNAVAILABLE');
        require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
        require_once __DIR__.'/../Infrastructure/Adapters/CapabilityValidator.php';
        $issues=[];AF_ACS_CapabilityValidator::stats((array)($source['characteristics']??[]),$issues);AF_ACS_CapabilityValidator::resistances((array)($source['resistances']??[]),$issues);
        if($issues || array_filter($source['diagnostics']??[],static fn($i)=>($i['severity']??'error')==='error'))throw new RuntimeException('ACS_SNAPSHOT_INVALID');
        AF_ACS_Repository::integer($entity['hp'],'9007199254740991',true);AF_ACS_Repository::integer($entity['hp_max'],'9007199254740991',true);
        if((float)$entity['hp']>(float)$entity['hp_max'])throw new RuntimeException('ACS_HP_RANGE');
        $entity['_controller']=$controllers[0];return $entity;
    }
    public function readiness(string $sid,string $eid): array
    {
        $session=$this->authorize($sid);$encounter=$this->repository->encounter($sid,$eid);
        if(!$encounter)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        $issues=[];
        if($session['status']!=='active' || $encounter['status']!=='preparation')$issues[]=['code'=>'preparation_required'];
        $config=$this->repository->configSnapshot($sid,$encounter['config_snapshot_id']);
        try {
            if(!$config || !hash_equals($config['content_hash'],hash('sha256',$config['payload_json'])))throw new RuntimeException('Invalid config');
            $pinned=json_decode($config['payload_json'],true,32,JSON_THROW_ON_ERROR);
            $current=json_decode($encounter['config_json'],true,32,JSON_THROW_ON_ERROR);
            if(($pinned['schema_version']??null)!==1 || ($pinned['config']??null)!==$current
                || ($current['schema_version']??null)!==1 || ($current['defaults_version']??null)!=='acs-preparation-1' || ($current['visibility']??null)!==$encounter['visibility']
                || $encounter['visibility']!=='hidden')throw new RuntimeException('Invalid config');
        } catch(Throwable $error) {$issues[]=['code'=>'config_snapshot_invalid'];}
        $sides=[];foreach($this->repository->allSides($eid) as $side)$sides[$side['id']]=true;
        $used=[];$groups=[];$count=0;$after='0';
        do {
            $participants=$this->repository->participants($eid,$after);
            foreach($participants as $participant) {
                ++$count;$after=$participant['id'];
                try {
                    if(!isset($sides[$participant['side_id']??'']))throw new RuntimeException('ACS_SIDE_UNAVAILABLE');
                    $used[$participant['side_id']]=true;$entity=$this->entity($sid,$participant['entity_id'],$eid);
                    // Independent monsters never share a future turn group.
                    if($entity['owner_uid']===null && isset($groups[$entity['control_group_id']]))throw new RuntimeException('ACS_CONTROL_INVALID');
                    $groups[$entity['control_group_id']]=true;
                } catch(Throwable $error) {$issues[]=['code'=>self::code($error),'entity_id'=>$participant['entity_id']];}
            }
        } while(count($participants)===100);
        if(!$count)$issues[]=['code'=>'participants_missing'];
        $relations=[];foreach($this->repository->allRelations($eid) as $row)$relations[$row['side_a'].':'.$row['side_b']]=$row['relationship'];
        $ids=array_keys($used);
        for($i=0;$i<count($ids);++$i)for($j=$i+1;$j<count($ids);++$j) {
            $a=(string)$ids[$i];$b=(string)$ids[$j];if(strlen($a)>strlen($b)||(strlen($a)===strlen($b)&&strcmp($a,$b)>0))[$a,$b]=[$b,$a];
            if(!in_array($relations[$a.':'.$b]??null,['allied','neutral','hostile'],true))$issues[]=['code'=>'side_relationship_missing','side_a'=>$a,'side_b'=>$b];
        }
        return ['preparation_ready'=>!$issues,'can_start'=>false,'diagnostics'=>array_slice($issues,0,100),
            'blocked_capabilities'=>[['capability'=>'first_round_roster','status'=>'PROPOSED','stage'=>'ACS-2.3.1','reason'=>'Первый round roster ещё не реализован. Столкновение остаётся в подготовке.']],
            'encounter_version'=>$encounter['encounter_version'],'session_version'=>$session['session_version']];
    }
    private static function code(Throwable $error): string
    {
        return in_array($error->getMessage(),['ACS_SIDE_UNAVAILABLE','ACS_ENTITY_UNAVAILABLE','ACS_CONTROL_INVALID','ACS_SNAPSHOT_HASH_MISMATCH','ACS_SNAPSHOT_INVALID','ACS_DEPENDENCY_UNAVAILABLE','ACS_HP_RANGE'],true)?$error->getMessage():'participant_validation_failed';
    }
    public function command(string $op,array $input): array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($input['session_id']??null);
        if(!in_array($op,['create','side_create','relation_set','participant_add','participant_side','controller_assign'],true))throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        $key=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $request=['schema_version'=>1,'session_id'=>$sid,'actor_uid'=>$this->access->uid(),'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'reason'=>self::text($input['reason']??null,1000)];
        if(isset($input['tid']) && $input['tid']!=='')$request['tid']=AF_ACS_Repository::integer($input['tid'],'4294967295');
        if($op==='create')$request['name']=self::text($input['name']??null,191);
        else {
            $request['encounter_id']=AF_ACS_Repository::integer($input['encounter_id']??null);
            $request['encounter_version']=AF_ACS_Repository::integer($input['encounter_version']??null,'18446744073709551614');
            foreach(match($op){'relation_set'=>['side_a','side_b'],'participant_add','participant_side'=>['entity_id','side_id'],'controller_assign'=>['entity_id','uid'],default=>[]} as $field)$request[$field]=AF_ACS_Repository::integer($input[$field]??null,$field==='uid'?'4294967295':'18446744073709551615');
            if($op==='side_create')$request['name']=self::text($input['name']??null,191);
            if($op==='relation_set'){$relation=(string)($input['relationship']??'');if(!in_array($relation,['allied','neutral','hostile'],true)||$request['side_a']===$request['side_b'])throw new RuntimeException('ACS_RELATION_INVALID');$request['relationship']=$relation;}
        }
        return $this->repository->command('session:'.$request['actor_uid'].':'.$key,'encounter.'.$op,$request,
            function($session)use($sid,$request){$this->authorize($sid,true,$request['tid']??null);},
            function($session)use($sid,$request,$op) {
                if($op==='create')return $this->repository->createEncounter($session,$request['name'],['schema_version'=>1,'defaults_version'=>'acs-preparation-1','visibility'=>'hidden']);
                $encounter=$this->repository->encounter($sid,$request['encounter_id'],true);
                if(!$encounter)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
                if($encounter['status']!=='preparation')throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
                if($encounter['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                if(in_array($op,['participant_add','participant_side'],true)){$this->side($encounter['id'],$request['side_id']);$entity=$this->entity($sid,$request['entity_id'],$encounter['id']);}
                if($op==='relation_set'){$this->side($encounter['id'],$request['side_a']);$this->side($encounter['id'],$request['side_b']);}
                $change=$request;
                if(isset($entity)){$change['_snapshot_id']=$entity['template_snapshot_id'];$change['_group_id']=$entity['control_group_id'];}
                if($op==='controller_assign') {
                    $entity=$this->entity($sid,$request['entity_id'],$encounter['id'],false);
                    if($entity['owner_uid']!==null || !in_array($entity['kind'],['npc','monster'],true) || !$this->repository->participantExists($encounter['id'],$entity['id']))throw new RuntimeException('ACS_CONTROL_INVALID');
                    $roles=array_column($this->repository->roles($sid,$request['uid']),'role');
                    if(!array_intersect($roles,['owner','gm']))throw new RuntimeException('ACS_CONTROL_INVALID');
                    $this->access->user($request['uid']);$this->access->thread($session['tid'],$request['uid']);
                    $old=AF_ACS_Repository::integer($entity['_controller']['assignment_version'],'18446744073709551614');
                    $change['_previous_assignment_version']=$old;
                    $change['_assignment_id']=$entity['_controller']['id'];
                    $change['_previous_controller_uid']=$entity['_controller']['controller_uid'];
                }
                return $this->repository->change($session,$encounter,$op,$change);
            });
    }
}
