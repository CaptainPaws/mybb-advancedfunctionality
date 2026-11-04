<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/QueryService.php';

/** Controller-owned registry only. There is intentionally no reaction execution endpoint. */
final class AF_ACS_ReactionService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    private function source(string $sid,string $eid,string $id,bool $write):array
    {
        $view=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);$encounter=$this->repo->encounter($sid,$eid);$entity=$this->repo->entity($sid,$id);$c=$this->repo->controllers($sid,$id);$permissions=json_decode($c[0]['permissions_json']??'{}',true);
        if(!$view['can_register_own']||!$encounter||!$entity||count($c)!==1||$c[0]['controller_uid']!==$this->access->uid()||$entity['owner_uid']!==null&&$entity['owner_uid']!==$this->access->uid()||($permissions['combat_operations_enabled']??false)!==true)throw new RuntimeException('ACS_REACTION_ACCESS_DENIED');
        $round=$this->repo->currentRound($encounter);$individual=null;
        if($round)foreach($this->repo->allRoundTurns($round['id']) as $turn)foreach($this->repo->individualTurns([$turn['id']]) as $i)if($i['entity_id']===$id)$individual=$i;
        if(!$round||$round['status']!=='open'||!$individual)throw new RuntimeException('ACS_REACTION_ROUND_UNAVAILABLE');
        if($write&&($encounter['status']!=='active'||$entity['life_state']!=='active'||!$this->repo->reactionParticipant($sid,$eid,$id)))throw new RuntimeException('ACS_REACTION_SOURCE_UNAVAILABLE');
        if(!in_array((string)$individual['reaction_used'],['0','1'],true))throw new RuntimeException('ACS_REACTION_LIMIT_INVALID');
        return [$view['session'],$encounter,$entity,$c[0],$individual];
    }
    public function view(string $sid,string $eid,string $entity):array
    {
        [,,$source,,$turn]=$this->source($sid,$eid,$entity,false);$out=[];
        foreach($this->repo->reactionProjection($sid,$eid)['entries'] as $row)if($row['entity_id']===$entity)$out[]=['id'=>$row['id'],'phase'=>$row['phase'],'priority'=>$row['priority'],'registered_order'=>$row['registered_order'],'status'=>$row['status'],'reaction_version'=>$row['reaction_version'],'costs'=>$row['spec']['costs'],'enabled'=>$row['enabled']];
        return ['entity_id'=>$source['id'],'reaction_used'=>(int)$turn['reaction_used'],'reactions'=>$out];
    }
    private function protectionTargets(string $sid,string $eid,string $source,array $spec,array $requested):array
    {
        $selection=$spec['selection']??'self';if($selection==='self'){if($requested)throw new RuntimeException('ACS_REACTION_TARGETS_INVALID');return [];}
        $view=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);$members=$this->repo->participants($eid,'0',201);$byId=array_column($members,null,'entity_id');$side=$byId[$source]['side_id']??null;$allies=[];
        foreach($this->repo->allRelations($eid) as $rel)if($rel['relationship']==='allied'){$allies[$rel['side_a'].':'.$rel['side_b']]=true;$allies[$rel['side_b'].':'.$rel['side_a']]=true;}
        $valid=[];foreach($members as $member){if($member['participation']!=='engaged'||$side===null||$member['side_id']===null||($member['side_id']!==$side&&!isset($allies[$side.':'.$member['side_id']])))continue;
            $visibility=json_decode($member['visibility_json'],true);$controllers=$this->repo->controllers($sid,$member['entity_id']);
            if(($visibility!==['schema_version'=>1,'scope'=>'gm'])||!$view['can_manage']&&($controllers[0]['controller_uid']??null)!==$this->access->uid())continue;$valid[]=$member['entity_id'];
        }
        if($selection==='all_allies'){if($requested||!$view['can_manage'])throw new RuntimeException('ACS_REACTION_TARGETS_INVALID');$requested=$valid;usort($requested,static fn($a,$b)=>strlen($a)<=>strlen($b)?:strcmp($a,$b));}
        if(!$requested||count(array_unique($requested))!==count($requested)||array_diff($requested,$valid)||$selection==='single_ally'&&(count($requested)!==1||$requested[0]===$source))throw new RuntimeException('ACS_REACTION_TARGETS_INVALID');
        $encounter=$this->repo->encounter($sid,$eid);$round=$this->repo->currentRound($encounter);$roster=[];foreach($this->repo->allRoundTurns($round['id']) as $t)foreach($this->repo->individualTurns([$t['id']]) as $i)$roster[]=$i['entity_id'];
        if(isset($spec['target_count'])&&(count($requested)<$spec['target_count']['min']||count($requested)>$spec['target_count']['max']))throw new RuntimeException('ACS_REACTION_TARGETS_INVALID');
        if(array_diff($requested,$roster))throw new RuntimeException('ACS_REACTION_TARGETS_INVALID');return $requested;
    }
    public function command(string $op,array $input):array
    {
        $this->access->csrf();if(!in_array($op,['register','cancel'],true)||array_diff(array_keys($input),['session_id','encounter_id','entity_id','trigger_key','reaction_id','reaction_version','expected_version','encounter_version','operation_key','reason','targets']))throw new RuntimeException('ACS_REACTION_INPUT_INVALID');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$eid=AF_ACS_Repository::integer($input['encounter_id']??null);$entity=AF_ACS_Repository::integer($input['entity_id']??null);$this->source($sid,$eid,$entity,false);
        $key=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');$reason=trim((string)($input['reason']??''));if($reason===''||mb_strlen($reason)>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        $request=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'session_id'=>$sid,'encounter_id'=>$eid,'entity_id'=>$entity,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'encounter_version'=>AF_ACS_Repository::integer($input['encounter_version']??null),'reason'=>$reason];
        if($op==='register'){$trigger=$input['trigger_key']??null;if(!is_string($trigger)||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$trigger))throw new RuntimeException('ACS_REACTION_INPUT_INVALID');$request['trigger_key']=$trigger;$request['targets']=[];$ids=$input['targets']??[];if(!is_array($ids)||!array_is_list($ids)||count($ids)>100)throw new RuntimeException('ACS_REACTION_TARGETS_INVALID');foreach($ids as $id)$request['targets'][]=AF_ACS_Repository::integer($id);}
        else{$request['reaction_id']=AF_ACS_Repository::integer($input['reaction_id']??null);$request['reaction_version']=AF_ACS_Repository::integer($input['reaction_version']??null,'9007199254740990');}
        return $this->repo->command('session:'.$this->access->uid().':'.$key,'encounter.reaction_'.$op,$request,
            function($s)use($sid,$eid,$entity){$this->source($sid,$eid,$entity,false);},
            function($s)use($op,$request,$sid,$eid,$entity){[, $e,$source,$controller]=$this->source($sid,$eid,$entity,$op==='register');if($e['status']!=='active')throw new RuntimeException('ACS_REACTION_SOURCE_UNAVAILABLE');if($e['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                if($op==='register'){$graph=(new AF_ACS_KnowledgeBaseAdapter)->reactionDefinition($request['trigger_key'],(int)$this->access->uid());(new AF_ACS_QueryService($this->repo,$this->access))->reactionGrant($sid,$entity,$graph['spec']['ability_ref']);$targets=$this->protectionTargets($sid,$eid,$entity,$graph['spec'],$request['targets']);if($targets)$graph['targets']=$targets;return $this->repo->registerReaction($s,$e,$source,$controller,$graph);}
                $row=$this->repo->reactionProjection($sid,$eid)['entries'][$request['reaction_id']]??null;if(!$row||$row['entity_id']!==$entity)throw new RuntimeException('ACS_REACTION_ACCESS_DENIED');return $this->repo->cancelReaction($s,$e,$request['reaction_id'],$request['reaction_version']);});
    }
}
