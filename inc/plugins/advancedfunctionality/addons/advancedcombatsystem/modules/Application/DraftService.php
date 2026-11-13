<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/AudienceService.php';
require_once __DIR__.'/CooperationService.php';
require_once __DIR__.'/../Infrastructure/EncounterRepository.php';

/** Unsealed plans are untrusted draft data, never execution authorization or calculated outcomes. */
final class AF_ACS_DraftService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    private function context(string $sid,string $eid,string $turnId,bool $write):array
    {
        $view=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);$uid=$this->access->uid();
        if($write)$this->access->draftThread($view['session']['tid']);
        $roles=array_column($this->repo->roles($sid,$uid),'role');
        if(!array_intersect($roles,['owner','gm','assistant_gm','player']))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $encounter=$this->repo->encounter($sid,$eid);$turn=$this->repo->clockTurn($sid,$turnId);
        if(!$encounter||!$turn||$turn['encounter_id']!==$eid)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $editable=$view['session']['status']==='active'&&in_array($encounter['status'],['active','paused'],true)&&$turn['round_status']==='open'&&$turn['round_number']===$turn['current_round']&&$turn['status']==='pending';
        $roster=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);$fp=$roster['fingerprint']??null;unset($roster['fingerprint']);
        if(!is_string($fp)||!hash_equals($fp,hash('sha256',AF_ACS_Repository::json($roster))))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $actors=[];
        foreach($roster['members']??[] as $member){
            $id=AF_ACS_Repository::integer($member['entity_id']??null);$e=$this->repo->entity($sid,$id);$c=$this->repo->controllerFor($sid,$id,$uid);
            if(!$e||$e['control_group_id']!==$turn['control_group_id']||!$c)continue;
            $actors[$id]=['entity_version'=>(string)$e['entity_version'],'state_version'=>(string)$e['state_version'],'snapshot_id'=>(string)$e['template_snapshot_id'],'snapshot_hash'=>$e['content_hash'],'controller_version'=>$c['assignment_version'],'control_proof'=>AF_ACS_AuthorizationService::controlProof($c)];
        }
        if(!$actors)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        if($write&&!$editable)throw new RuntimeException('ACS_DRAFT_TURN_UNAVAILABLE');
        return ['editable'=>$editable,'actors'=>$actors,'dependencies'=>['schema_version'=>1,'scope'=>'draft_control','session_version'=>$view['session']['session_version'],'encounter_version'=>$encounter['encounter_version'],'round_id'=>$turn['round_id'],'turn_version'=>$turn['turn_version'],'roster_hash'=>$fp,'entities'=>$actors]];
    }
    public function save(array $input):array
    {
        $this->access->csrf();
        if(array_diff(array_keys($input),['session_id','encounter_id','turn_id','draft_id','expected_draft_version','operation_key','prose','actions']))throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
        $request=['schema_version'=>1,'author_uid'=>$this->access->uid()];
        foreach(['session_id','encounter_id','turn_id'] as $f)$request[$f]=AF_ACS_Repository::integer($input[$f]??null);
        $request['draft_id']=($input['draft_id']??'')===''?null:AF_ACS_Repository::integer($input['draft_id']);
        $request['expected_draft_version']=AF_ACS_Repository::integer($input['expected_draft_version']??null,'18446744073709551614',true);
        $key=$input['operation_key']??null;
        if(!is_string($key)||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');$request['operation_key']=$key;
        $prose=$input['prose']??null;$actions=$input['actions']??null;
        if(!is_string($prose)||strlen($prose)>65536||str_contains($prose,"\0")||!mb_check_encoding($prose,'UTF-8')||!is_array($actions)||!array_is_list($actions)||count($actions)>100)throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
        foreach($actions as &$a){
            if(!is_array($a)||array_diff(array_keys($a),['actor_id','ability_key','targets','parameters'])||!is_string($a['ability_key']??null)||!preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,95}\z/D',$a['ability_key'])||!is_array($a['targets']??null)||!array_is_list($a['targets'])||count($a['targets'])>100||!is_array($a['parameters']??[]))throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
            $a['actor_id']=AF_ACS_Repository::integer($a['actor_id']??null);foreach($a['targets'] as &$id)$id=AF_ACS_Repository::integer($id);unset($id);$a['parameters']??=[];
        }unset($a);
        $mechanics=AF_ACS_Repository::json(['schema_version'=>1,'actions'=>$actions]);if(strlen($mechanics)>65536)throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
        return $this->repo->saveDraft($request,$mechanics,$prose,function()use($request,$actions){
            $context=$this->context($request['session_id'],$request['encounter_id'],$request['turn_id'],true);
            $audience=new AF_ACS_AudienceService($this->repo,$this->access,$request['session_id'],$request['encounter_id']);
            foreach($actions as $a){if(!(new AF_ACS_CooperationService($this->repo,$this->access))->canReadAction($request['session_id'],$request['encounter_id'],$a))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');if(!isset($context['actors'][$a['actor_id']]))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');foreach($a['targets'] as $target)if($audience->knowledge(str_starts_with($a['ability_key'],'object:')?'object':'entity',$target,$a['actor_id'])['level']!=='identified')throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');}
            return $context['dependencies'];
        });
    }
    public function restore(string $id):array
    {
        $row=$this->repo->draft(AF_ACS_Repository::integer($id),$this->access->uid());if(!$row)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $context=$this->context($row['session_id'],$row['encounter_id'],$row['turn_id'],false);
        $mechanics=json_decode($row['mechanics_json'],true,32,JSON_THROW_ON_ERROR);$dependencies=json_decode($row['dependency_json'],true,32,JSON_THROW_ON_ERROR);
        $audience=new AF_ACS_AudienceService($this->repo,$this->access,$row['session_id'],$row['encounter_id']);
        foreach($mechanics['actions']??[] as $a){if(!(new AF_ACS_CooperationService($this->repo,$this->access))->canReadAction($row['session_id'],$row['encounter_id'],$a))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');if(!isset($context['actors'][$a['actor_id']]))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');foreach($a['targets'] as $target)if($audience->knowledge(str_starts_with($a['ability_key'],'object:')?'object':'entity',$target,$a['actor_id'])['level']!=='identified')throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');}
        $sealMeta=$dependencies['seal']??null;unset($dependencies['seal']);$prefix=[];
        foreach($this->repo->sealRows($row['id'],true) as $sealed)$prefix[]=['id'=>(string)$sealed['id'],'action'=>$this->repo->sealPayload($sealed)['action']];
        return ['schema_version'=>1,'draft_id'=>$row['id'],'draft_version'=>$row['draft_version'],'session_id'=>$row['session_id'],'encounter_id'=>$row['encounter_id'],'turn_id'=>$row['turn_id'],'author_uid'=>$row['author_uid'],'status'=>$row['status'],'prose'=>$row['prose'],'actions'=>$mechanics['actions'],'dependencies'=>$dependencies,'dependency_changed'=>$dependencies!==$context['dependencies'],'sealed_prefix'=>$prefix,'seal_meta'=>$sealMeta,'editable'=>$context['editable']&&in_array($row['status'],['draft','sealed'],true)&&$row['pid']===null,'saved_at'=>$row['updated_at'],'validated_for_execution'=>false];
    }
}
