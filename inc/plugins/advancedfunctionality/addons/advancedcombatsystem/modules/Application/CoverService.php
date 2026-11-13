<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/SpatialService.php';
final class AF_ACS_CoverService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    private function gm(string $sid):bool{return (new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];}
    public function command(array $r):array
    {
        $this->access->csrf();$fields=['session_id','encounter_id','expected_version','expected_encounter_version','cover_entity_id','protected_entity_id','expected_assignment_version','status','reason','operation_key'];if(array_diff(array_keys($r),$fields)||array_diff($fields,array_keys($r)))throw new RuntimeException('ACS_COVER_UNSUPPORTED');
        foreach(['session_id','encounter_id','expected_version','expected_encounter_version','cover_entity_id','protected_entity_id','expected_assignment_version'] as $k)$r[$k]=AF_ACS_Repository::integer($r[$k],'9007199254740991',$k==='expected_assignment_version');if(!in_array($r['status'],['active','inactive'],true)||!is_string($r['reason'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000||!is_string($r['operation_key'])||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key']))throw new RuntimeException('ACS_COVER_UNSUPPORTED');$r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;if(!$this->gm($r['session_id']))throw new RuntimeException('ACS_GM_REQUIRED');
        return $this->repo->command('session:'.$this->access->uid().':cover:'.$r['operation_key'],'encounter.cover_assignment',$r,function()use($r){if(!$this->gm($r['session_id']))throw new RuntimeException('ACS_GM_REQUIRED');},function($session)use($r){$enc=$this->repo->encounter($r['session_id'],$r['encounter_id'],true);if(!$enc||$enc['encounter_version']!==$r['expected_encounter_version']||!in_array($enc['status'],['active','paused'],true))throw new RuntimeException('ACS_VERSION_CONFLICT');return $this->repo->coverCommand($session,$enc,$r);});
    }
    public function canRead(string $sid,string $eid,string $actor,string $cover):bool
    {
        $gm=$this->gm($sid);$o=$this->repo->objectProjection($sid,$eid)[$cover]??null;if(!$o||!isset($o['definition']['cover']))return false;$a=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);$s=$this->repo->spatialProjection($sid,$eid);return $a->knowledge('object',$cover,$actor)['level']==='identified'&&AF_ACS_Spatial::disclosed($s,$cover,$gm)&&AF_ACS_Spatial::disclosed($s,$actor,$gm);
    }
    public function view(string $sid,string $eid,array $actors):array
    {
        $gm=$this->gm($sid);$a=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);$spatial=$this->repo->spatialProjection($sid,$eid);$out=[];foreach($this->repo->coverProjection($sid,$eid) as $r){$known=false;foreach($actors as $actor)if($this->canRead($sid,$eid,$actor,$r['cover_entity_id']))$known=true;if(!$known||!AF_ACS_Spatial::disclosed($spatial,$r['protected_entity_id'],$gm)||$a->knowledge('entity',$r['protected_entity_id'])['level']!=='identified')continue;$out[]=['cover_entity_id'=>$r['cover_entity_id'],'protected_entity_id'=>$r['protected_entity_id'],'status'=>$r['status'],'assignment_version'=>$r['assignment_version'],'category'=>$r['policy']['category']];}return $out;
    }
    public function catalog(string $sid,string $eid,string $actor):array
    {
        $assignments=$this->repo->coverProjection($sid,$eid);$audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);$out=[];foreach($this->repo->objectProjection($sid,$eid) as $id=>$o){$id=(string)$id;if(!$this->canRead($sid,$eid,$actor,$id)||($o['status']!=='active'&&($assignments[$id.':'.$actor]['status']??null)!=='active'))continue;$d=$o['definition']['cover'];$enter=($assignments[$id.':'.$actor]['status']??null)!=='active';$out[]=['key'=>'cover:'.($enter?'enter':'leave').':'.$id,'label'=>($enter?'Занять укрытие':'Выйти из укрытия').' · '.($audience->knowledge('object',$id,$actor)['details']['name']??'Известное укрытие'),'category'=>'cover','supported'=>true,'target_policy'=>['kind'=>'self_only'],'ap_cost'=>$enter?$d['occupy_ap']:$d['leave_ap']];}return $out;
    }
    public function prepare(string $sid,string $eid,string $actor,string $key,array $targets,?string $draft):array
    {
        if(!preg_match('/\Acover:(enter|leave):([1-9][0-9]*)\z/D',$key,$m)||$targets!==[$actor]||!$this->canRead($sid,$eid,$actor,$m[2]))throw new RuntimeException('preview_target_forbidden');$p=(new AF_ACS_QueryService($this->repo,$this->access))->inspect($sid,$eid,$actor,$actor,$draft,true);$dto=$this->repo->objectDefinitionSnapshot($sid,$m[2]);$binding=['kind'=>$m[1],'id'=>$m[2]];$ability=['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],'components'=>[],'costs'=>[['kind'=>'ap','amount'=>1]],'target_policy'=>['kind'=>'self_only'],'cover_action'=>$binding];$p['context']['abilities'][$actor][$key]=$ability;$p['choices'][$key]=['supported'=>true];return ['inspection'=>$p,'definition'=>['definition_version'=>$dto['definition_version'],'cover_action'=>$binding],'ability'=>$ability];
    }
}
