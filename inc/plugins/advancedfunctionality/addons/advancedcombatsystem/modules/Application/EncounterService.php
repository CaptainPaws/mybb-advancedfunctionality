<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/../Domain/Clock.php';
require_once __DIR__.'/../Infrastructure/EncounterRepository.php';

/** GM preparation and first-round roster. No combat action or turn-closing endpoints. */
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
    public function rounds(?callable $now=null): AF_ACS_RoundManagementService
    {
        require_once __DIR__.'/RoundManagementService.php';return new AF_ACS_RoundManagementService($this->repository,$this->access,$now);
    }
    public function clock(): AF_ACS_ClockService
    {
        require_once __DIR__.'/ClockService.php';return new AF_ACS_ClockService($this->repository,$this->access);
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
    public function readiness(string $sid,string $eid,bool $forNext=false): array
    {
        $session=$this->authorize($sid);$encounter=$this->repository->encounter($sid,$eid);
        if(!$encounter)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        $issues=[];
        if($session['status']!=='active' || $encounter['status']!==($forNext?'active':'preparation'))$issues[]=['code'=>'preparation_required'];
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
                $after=$participant['id'];if($participant['participation']!=='engaged')continue;++$count;
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
        $issues=array_merge($issues,$this->rosterIssues($this->repository->rosterSources($sid,$eid),$sid));
        return ['preparation_ready'=>!$issues,'can_start'=>!$issues,'diagnostics'=>array_slice($issues,0,100),
            'blocked_capabilities'=>$issues?[['capability'=>'round_roster','status'=>'BLOCKED','stage'=>'ACS-2.3.1','reason'=>'Исправьте причины блокировки подготовки.']]:[],
            'encounter_version'=>$encounter['encounter_version'],'session_version'=>$session['session_version']];
    }
    /** Read-only dependency gate: no silently skipped AP modifiers, ticks or upkeep. */
    private function rosterIssues(array $sources,string $sid): array
    {
        $issues=[];$seen=[];
        if(!$sources)$issues[]=['code'=>'participants_missing'];
        foreach($sources as $source) {
            if(isset($seen[$source['entity_id']]))$issues[]=['code'=>'ACS_CONTROL_INVALID','entity_id'=>$source['entity_id']];
            $seen[$source['entity_id']]=true;
            try{foreach($this->repository->allCooldowns($sid,$source['entity_id']) as $cooldown)$this->repository->cooldownSource($sid,$cooldown);}
            catch(Throwable $error){$issues[]=['code'=>'cooldown_source_unavailable','entity_id'=>$source['entity_id']];}
            try {
                $group=json_decode($source['group_config'],true,32,JSON_THROW_ON_ERROR);
                $layers=json_decode($source['layers_json'],true,32,JSON_THROW_ON_ERROR);
                if($group!==['schema_version'=>1] || $layers!==['schema_version'=>1,'layers'=>[]])throw new RuntimeException('Unsupported boundary state');
            } catch(Throwable $error) {$issues[]=['code'=>'round_boundary_contract_unavailable','entity_id'=>$source['entity_id']];}
        }
        return $issues;
    }
    /** Materialized rows only; this never opens a round or consumes a boundary. */
    public function roundView(string $sid,string $eid,string $after='0'): array
    {
        $this->authorize($sid);if(!$this->repository->encounter($sid,$eid))throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        $encounter=$this->repository->encounter($sid,$eid);$round=$this->repository->currentRound($encounter);if(!$round)return ['round'=>null,'turns'=>[],'next_after'=>null];
        $turns=$this->repository->roundTurns($round['id'],$after);
        $individuals=[];foreach($this->repository->individualTurns(array_column($turns,'id')) as $row) {
            $row['start_boundary']=AF_ACS_Clock::entityStart($row['id']);$row['end_boundary']=AF_ACS_Clock::entityEnd($row['id']);
            $individuals[$row['turn_id']][$row['entity_id']]=$row;
        }
        foreach($turns as &$turn) {
            if(!AF_ACS_TurnState::tryFrom($turn['status']))throw new RuntimeException('ACS_TURN_STATE_INVALID');
            $roster=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);
            $fingerprint=$roster['fingerprint']??null;unset($roster['fingerprint']);
            if(($roster['schema_version']??null)!==1 || ($roster['contract_id']??null)!=='IC-ACS.round-roster.v1'
                || ($roster['clock_version']??null)!==AF_ACS_Clock::VERSION || ($roster['numeric_policy']??null)!=='binary64-half_up-v1'
                || ($roster['round_id']??null)!==$round['id'] || ($roster['control_group_id']??null)!==$turn['control_group_id']
                || !is_string($fingerprint) || !hash_equals($fingerprint,hash('sha256',AF_ACS_Repository::json($roster))))throw new RuntimeException('ACS_ROSTER_INVALID');
            $turn['roster']=$roster;$turn['roster_fingerprint']=$fingerprint;$turn['individual_turns']=$individuals[$turn['id']]??[];unset($turn['roster_json']);
            if(count($turn['individual_turns'])!==count($roster['members']))throw new RuntimeException('ACS_ROSTER_INVALID');
            foreach($roster['members'] as $member)if(!isset($turn['individual_turns'][$member['entity_id']]))throw new RuntimeException('ACS_ROSTER_INVALID');
        }unset($turn);
        return ['round'=>$round,'turns'=>$turns,'ap_refresh_boundary'=>AF_ACS_Clock::apRefresh($round['id']),'next_after'=>count($turns)===100?(string)end($turns)['id']:null];
    }
    private static function code(Throwable $error): string
    {
        return in_array($error->getMessage(),['ACS_SIDE_UNAVAILABLE','ACS_ENTITY_UNAVAILABLE','ACS_CONTROL_INVALID','ACS_SNAPSHOT_HASH_MISMATCH','ACS_SNAPSHOT_INVALID','ACS_DEPENDENCY_UNAVAILABLE','ACS_HP_RANGE'],true)?$error->getMessage():'participant_validation_failed';
    }
    public function command(string $op,array $input): array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($input['session_id']??null);
        if(!in_array($op,['create','side_create','relation_set','participant_add','participant_side','controller_assign','round_start'],true))throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
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
                if($op==='round_start' && ($encounter['status']!=='preparation' || $this->repository->firstRound($encounter['id'])))throw new RuntimeException('ACS_FIRST_ROUND_EXISTS');
                if($encounter['status']!=='preparation' && !($encounter['status']==='active' && $op==='participant_add'))throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
                if($encounter['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                if($op==='round_start') {
                    $ready=$this->readiness($sid,$encounter['id']);
                    if(!$ready['can_start'])throw new RuntimeException('ACS_ROSTER_BLOCKED');
                    return $this->repository->openFirstRound($session,$encounter,$this->repository->rosterSources($sid,$encounter['id']),$request['actor_uid']);
                }
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
