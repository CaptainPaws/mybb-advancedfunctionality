<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/QueryService.php';
require_once __DIR__.'/../Domain/Participation.php';

/** Synthetic typed encounter actions use the existing Builder, seals and combat writer. */
final class AF_ACS_ParticipationService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    public function catalog(string $sid,string $eid,string $actor):array
    {
        $enc=$this->repo->encounter($sid,$eid);if(!isset(json_decode($enc['config_json'],true)['participation_policy']))return [];
        $this->repo->participationPolicy($enc);$out=[];
        foreach(['surrender'=>'Капитуляция','escape'=>'Побег','group_withdrawal'=>'Отступление группы','capture'=>'Захват'] as $kind=>$label)$out[]=['key'=>'participation:'.$kind,'label'=>$label,'supported'=>true,'category'=>'ordinary','target_policy'=>['kind'=>in_array($kind,['surrender','escape'],true)?'self_only':'any_entity'],'ap_cost'=>$kind==='surrender'?0:1,'participation_action'=>true];
        return $out;
    }
    public function prepare(string $sid,string $eid,string $actor,string $key,array $targets,?string $draft):array
    {
        $kind=AF_ACS_Participation::kind($key);$enc=$this->repo->encounter($sid,$eid);$policy=$this->repo->participationPolicy($enc);
        $q=new AF_ACS_QueryService($this->repo,$this->access);$p=$q->inspect($sid,$eid,$actor,$kind==='capture'?($targets[0]??'0'):$actor,$draft,true,false,false,true,in_array($kind,['escape','group_withdrawal'],true));
        $authorized=[];$audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);
        foreach($targets as $id){if(!isset($p['context']['state']['participants'][$id])||!$audience->canTarget($id,$actor))throw new RuntimeException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');
            if($kind==='group_withdrawal'){
                $grant=$this->repo->controllerFor($sid,$id,$this->access->uid());if(!$grant)throw new RuntimeException('ACS_PARTICIPATION_CONTROL_DENIED');AF_ACS_Control::action($grant,$key);
                if(($p['context']['turns'][$id]['id']??null)!==($p['context']['turns'][$actor]['id']??null))throw new RuntimeException('ACS_PARTICIPATION_ROSTER_REQUIRED');
                $authorized[$id]=AF_ACS_AuthorizationService::controlProof($grant);
            }
        }
        $p['context']['allowed_targets'][$actor]=$targets;
        $spec=['kind'=>$kind,'policy'=>$policy,'authorized'=>$authorized];$state=$p['context']['state'];$preview=AF_ACS_Participation::plan($p['context'],$state,$actor,$targets,$spec,null,'preview');
        $pin=['declared_version'=>'1','fingerprint'=>'sha256:'.hash('sha256',$enc['config_json'])];$ref=['key'=>'encounter-participation-'.$eid,'version'=>'1','fingerprint'=>$pin['fingerprint']];
        $ability=['supported'=>true,'source_ref'=>$ref,'components'=>[],'costs'=>[['kind'=>'ap','amount'=>$kind==='surrender'?0:1]],'target_policy'=>['kind'=>in_array($kind,['surrender','escape'],true)?'self_only':'any_entity'],'participation_action'=>$spec,'no_random'=>!$preview['needs_roll']];
        $p['context']['abilities'][$actor][$key]=$ability;$p['choices'][$key]=['supported'=>true];$p['versions']['participation_authorization']=$authorized;
        return ['inspection'=>$p,'ability'=>$ability,'definition'=>['definition_version'=>$pin,'participation_action'=>['kind'=>$kind,'policy'=>$policy,'config_snapshot_id'=>$enc['config_snapshot_id']]]];
    }
}
