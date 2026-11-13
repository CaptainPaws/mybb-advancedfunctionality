<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Domain/Cooperation.php';

/** Authorization and safe DTOs; no mechanical mutation or detection on reads. */
final class AF_ACS_CooperationService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    private function visible(array $v,AF_ACS_AudienceService $audience,string $actor,bool $gm):bool
    {return ($gm||$v['definition']['visibility']==='known')&&$audience->knowledge('object',$v['entity_id'],$actor)['level']==='identified';}
    public function canReadAction(string $sid,string $eid,array $action):bool
    {
        if(!preg_match('/\Aobject:([1-9][0-9]*):coop-(.+)\z/D',$action['ability_key']??'',$m))return true;
        $v=$this->repo->objectiveProjection($sid,$eid)[$m[1]]??null;if($v===null)return true;
        $gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];$audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);
        if(!$this->visible($v,$audience,$action['actor_id'],$gm))return false;
        if(preg_match('/\Aassist-([1-9][0-9]*)\z/D',$m[2],$recipient)&&$audience->knowledge('entity',$recipient[1],$action['actor_id'])['level']!=='identified')return false;
        return true;
    }
    private function recipients(string $sid,string $eid,string $actor):array
    {
        $scope=(new AF_ACS_QueryService($this->repo,$this->access))->builderScope($sid,$eid);$source=$scope['actors'][$actor]??null;$out=[];
        if(!$source)return [];$allies=[$source['side_id']];foreach($this->repo->allRelations($eid) as $r)if($r['relationship']==='allied'){if($r['side_a']===$source['side_id'])$allies[]=$r['side_b'];if($r['side_b']===$source['side_id'])$allies[]=$r['side_a'];}
        foreach($scope['targets'] as $target)if($target['active']&&in_array($actor,$target['known_to']??[],true)&&in_array($target['side_id'],$allies,true))$out[$target['id']]=$target;
        return $out;
    }
    public function catalog(string $sid,string $eid,string $actor,array $inspection):array
    {
        $gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];$audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);$objects=$this->repo->objectProjection($sid,$eid);$out=[];$recipients=null;
        foreach($this->repo->objectiveProjection($sid,$eid) as $id=>$v){if(!$this->visible($v,$audience,$actor,$gm))continue;$d=$v['definition'];$key=$d['kind']==='cooperative_check'?'coop-check':'coop-contribute';$selections=[$key=>['operation'=>$d['kind'],'recipient'=>null]];
            if($d['assist']!==null){$recipients??=$this->recipients($sid,$eid,$actor);foreach($recipients as $recipient)$selections['coop-assist-'.$recipient['id']]=['operation'=>'assist','recipient'=>$recipient['id']];}
            foreach($selections as $key=>$selection){$costs=$selection['operation']==='assist'?[['kind'=>'ap','amount'=>$d['assist']['ap_cost']]]:$d['action']['costs'];$label=match($selection['operation']){'assist'=>'Помощь · '.($recipients[$selection['recipient']]['name']??'Участник'),'cooperative_check'=>'Совместная проверка',default=>'Внести вклад'};
                $choice=['key'=>'object:'.$id.':'.$key,'label'=>$label.' · '.$d['label'],'object_label'=>$audience->knowledge('object',(string)$id,$actor)['details']['name']??'Известный объект #'.$id,'category'=>'cooperation','object_interaction'=>true,'object_id'=>(string)$id,'costs'=>$costs,'supported'=>true,'target_policy'=>['kind'=>'object_only'],'legal_targets'=>[(string)$id],'ap_cost'=>array_values(array_filter($costs,static fn($c)=>$c['kind']==='ap'))[0]['amount']];
                try{AF_ACS_Cooperation::eligible($v,$objects[$id],$actor,$inspection['context']['reaction_kinds'][$actor],AF_ACS_Resolver::interactionActorStats($inspection['context'],$actor),$inspection['context']['abilities'][$actor],$selection);}catch(Throwable $e){$choice['supported']=false;$choice['reason']=AF_ACS_BuilderService::message($e);}$out[]=$choice;
            }
        }return $out;
    }
    public function prepare(string $sid,string $eid,string $actor,string $key,?string $draft):array
    {
        [$id,$actionKey]=AF_ACS_ObjectInteraction::selection($key);$selection=AF_ACS_Cooperation::selection($actionKey)??throw new RuntimeException('ACS_COOPERATION_UNSUPPORTED');
        $gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];$audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);
        $v=$this->repo->objectiveProjection($sid,$eid)[$id]??throw new RuntimeException('preview_target_forbidden');if(!$this->visible($v,$audience,$actor,$gm))throw new RuntimeException('preview_target_forbidden');
        $recipient=$selection['recipient'];if($recipient!==null&&!isset($this->recipients($sid,$eid,$actor)[$recipient]))throw new RuntimeException('preview_target_forbidden');
        $p=(new AF_ACS_QueryService($this->repo,$this->access))->inspect($sid,$eid,$actor,$recipient??$actor,$draft,true);$object=$p['context']['state']['objects'][$id];
        AF_ACS_Cooperation::eligible($v,$object,$actor,$p['context']['reaction_kinds'][$actor],AF_ACS_Resolver::interactionActorStats($p['context'],$actor),$p['context']['abilities'][$actor],$selection);
        $dto=$this->repo->objectDefinitionSnapshot($sid,$id);$pin=['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']];
        $binding=['entity_id'=>$id,'key'=>$actionKey,'object_version'=>$object['object_version'],'objective_id'=>$v['id'],'objective_version'=>$v['objective_version'],'selection'=>$selection,'tool'=>null];$costs=$selection['operation']==='assist'?[['kind'=>'ap','amount'=>$v['definition']['assist']['ap_cost']]]:$v['definition']['action']['costs'];
        $definition=['definition_version'=>$dto['definition_version'],'object_interaction'=>$binding];
        $p['context']['abilities'][$actor][$key]=['supported'=>true,'source_ref'=>$pin,'costs'=>$costs,'components'=>[],'target_policy'=>['kind'=>'object_only'],'cooperative_action'=>$binding];$p['choices'][$key]=['supported'=>true];$p['versions']['cooperation']=$binding;
        $p['context']['behavior_targets'][$actor][$id]=['relationship'=>'neutral','kind'=>'object','active'=>true];$p['context']['reaction_kinds'][$id]='object';$p['context']['relationships'][$actor][$id]='neutral';
        return ['inspection'=>$p,'definition'=>$definition];
    }
    public function progress(string $sid,string $eid,array $actors):array
    {
        $gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];$audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);$out=[];
        foreach($this->repo->objectiveProjection($sid,$eid) as $v){$known=false;foreach($actors as $actor)if($this->visible($v,$audience,$actor,$gm))$known=true;if(!$known)continue;
            $dto=['objective_id'=>$v['id'],'entity_id'=>$v['entity_id'],'label'=>$v['definition']['label'],'kind'=>$v['definition']['kind'],'status'=>$v['status'],'progress'=>$v['progress'],'cap'=>$v['definition']['cap'],'objective_version'=>$v['objective_version']];
            if($gm)$dto['gm_details']=['conditions'=>$v['definition'],'contributions'=>$v['records'],'assists'=>$v['assists']];$out[]=$dto;
        }return $out;
    }
}
