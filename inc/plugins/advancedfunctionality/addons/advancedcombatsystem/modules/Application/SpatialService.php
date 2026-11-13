<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Domain/Spatial.php';
require_once __DIR__.'/../Infrastructure/Adapters/KnowledgeBaseAdapter.php';
final class AF_ACS_SpatialService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    private function gm(string $sid):array
    {$v=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);if(!$v['can_manage'])throw new RuntimeException('ACS_GM_REQUIRED');return $v['session'];}
    public function command(string $op,array $input):array
    {
        $this->access->csrf();$extra=match($op){'configure'=>['kb_key'],'connection'=>['from','to','status'],'position'=>['entity_id','zone_id','visibility'],'zone'=>['zone_id','visibility'],'engagement'=>['entity_id','target_id','status'],default=>throw new RuntimeException('ACS_SPATIAL_UNSUPPORTED')};
        $fields=['session_id','encounter_id','expected_version','expected_encounter_version','expected_spatial_version','operation_key','reason',...$extra];if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new RuntimeException('ACS_SPATIAL_INPUT_INVALID');$r=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'operation'=>$op]+$input;
        foreach(['session_id','encounter_id','expected_version','expected_encounter_version','expected_spatial_version','entity_id','target_id','zone_id','from','to'] as $key)if(isset($r[$key]))$r[$key]=AF_ACS_Repository::integer($r[$key],'9007199254740991',$key==='expected_spatial_version');$r['expected_spatial_version']=(int)$r['expected_spatial_version'];
        if(!is_string($r['operation_key'])||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key']))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');if(!is_string($r['reason'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        if(isset($r['visibility'])&&!in_array($r['visibility'],$op==='zone'?['public','gm']:['known','gm'],true)||isset($r['status'])&&!in_array($r['status'],$op==='connection'?['open','blocked']:['active','inactive'],true))throw new RuntimeException('ACS_SPATIAL_INPUT_INVALID');$this->gm($r['session_id']);$pin=null;
        if($op==='configure'){$pin=$this->repo->spatialProjection($r['session_id'],$r['encounter_id'])['pin']??(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_mechanics',$r['kb_key'],(int)$this->access->uid());AF_ACS_Spatial::definition($pin);if($r['expected_spatial_version']!==0)throw new RuntimeException('ACS_SPATIAL_VERSION_CONFLICT');}
        return $this->repo->command('session:'.$this->access->uid().':spatial:'.$r['operation_key'],'encounter.spatial_'.$op,$r,function()use($r){$this->gm($r['session_id']);},function($session)use($r,$pin){$e=$this->repo->encounter($r['session_id'],$r['encounter_id'],true);if(!$e||!in_array($e['status'],['active','paused'],true)||$e['encounter_version']!==$r['expected_encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');return $this->repo->spatialCommand($session,$e,$r,$pin);});
    }
    public function canReadAction(string $sid,string $eid,array $action):bool
    {
        $gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];if($gm)return true;$s=$this->repo->spatialProjection($sid,$eid);$actor=$action['actor_id'];
        if(preg_match('/\Aspatial:move:([1-9][0-9]*)\z/D',$action['ability_key'],$m))return $s['pin']!==null&&AF_ACS_Spatial::disclosed($s,$actor,false)&&($s['zones'][$m[1]]['visibility']??null)==='public';
        foreach($action['targets'] as $target)if($target!==$actor&&(!AF_ACS_Spatial::disclosed($s,$actor,false)||!AF_ACS_Spatial::disclosed($s,$target,false)))return false;return true;
    }
    public function view(string $sid,string $eid):array
    {
        $v=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);$s=$this->repo->spatialProjection($sid,$eid);$gm=$v['can_manage'];$a=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);$zones=[];$positions=[];$contacts=[];
        foreach($s['zones'] as $z)if($gm||$z['visibility']==='public')$zones[]=['id'=>$z['id'],'name'=>$z['name'],'version'=>$z['version']];
        foreach($this->repo->participants($eid,'0',201) as $p){$id=$p['entity_id'];if(!$gm&&$p['participation']!=='engaged')continue;if($a->knowledge('entity',$id)['level']!=='identified'||!AF_ACS_Spatial::disclosed($s,$id,$gm))continue;$copy=$s;$zone=AF_ACS_Spatial::position($copy,$id);$positions[]=['entity_id'=>$id,'zone_id'=>$zone,'version'=>$s['positions'][$id]['version']??0]+($gm?['visibility'=>$s['positions'][$id]['visibility']??'known']:[]);}
        $visible=array_column($positions,'entity_id');foreach($s['engagements'] as $e)if(in_array($e['a'],$visible,true)&&in_array($e['b'],$visible,true))$contacts[]=$e;
        return ['enabled'=>$s['pin']!==null,'zones'=>$zones,'positions'=>$positions,'engagements'=>$contacts]+($gm?['connections'=>array_values($s['connections'])]:[]);
    }
    public function catalog(string $sid,string $eid,string $actor):array
    {
        $s=$this->repo->spatialProjection($sid,$eid);if($s['pin']===null)return [];$gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];if(!AF_ACS_Spatial::disclosed($s,$actor,$gm))return [];$d=AF_ACS_Spatial::definition($s['pin']);$from=AF_ACS_Spatial::position($s,$actor);$out=[];
        foreach($s['zones'] as $z)if($z['id']!==$from&&($gm||$z['visibility']==='public')){$edge=$s['connections'][$from.':'.$z['id']]??null;$out[]=['key'=>'spatial:move:'.$z['id'],'label'=>'Переместиться · '.$z['name'],'supported'=>true,'category'=>'spatial','spatial_action'=>true,'target_policy'=>['kind'=>'self_only'],'target'=>'self','ap_cost'=>$edge['ap_cost']??1];}
        $q=(new AF_ACS_QueryService($this->repo,$this->access))->builderScope($sid,$eid);foreach($q['targets'] as $t)if($t['id']!==$actor&&in_array($actor,$t['known_to']??[],true))foreach(['engage'=>'Вступить в контакт','leave'=>'Выйти из контакта'] as $kind=>$label)$out[]=['key'=>'spatial:'.$kind.':'.$t['id'],'label'=>$label.' · '.$t['name'],'category'=>'spatial','spatial_action'=>true,'supported'=>true,'target_policy'=>['kind'=>'any_entity'],'legal_targets'=>[$t['id']],'ap_cost'=>$kind==='engage'?$d['engage_ap']:$d['leave_ap']];return $out;
    }
    public function prepare(string $sid,string $eid,string $actor,string $key,array $targets,?string $draft):array
    {
        if(!preg_match('/\Aspatial:(move|engage|leave):([1-9][0-9]{0,19})\z/D',$key,$m))throw new RuntimeException('ACS_SPATIAL_UNSUPPORTED');$s=$this->repo->spatialProjection($sid,$eid);if($s['pin']===null)throw new RuntimeException('ACS_SPATIAL_UNSUPPORTED');$gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];if(!AF_ACS_Spatial::disclosed($s,$actor,$gm))throw new RuntimeException('preview_target_forbidden');
        if($m[1]==='move'){if($targets!==[$actor]||!isset($s['zones'][$m[2]])||!$gm&&$s['zones'][$m[2]]['visibility']!=='public')throw new RuntimeException('preview_target_forbidden');$target=$actor;}else{if($targets!==[$m[2]])throw new RuntimeException('preview_target_forbidden');$target=$m[2];}
        $p=(new AF_ACS_QueryService($this->repo,$this->access))->inspect($sid,$eid,$actor,$target,$draft,true);$pin=$s['pin'];$ref=['key'=>$pin['source']['key'],'version'=>$pin['definition_version']['declared_version'],'fingerprint'=>$pin['definition_version']['fingerprint']];$binding=['kind'=>$m[1],'id'=>$m[2]];
        $ability=['supported'=>true,'source_ref'=>$ref,'components'=>[],'costs'=>[['kind'=>'ap','amount'=>1]],'target_policy'=>['kind'=>$m[1]==='move'?'self_only':'any_entity'],'spatial_action'=>$binding];$p['context']['abilities'][$actor][$key]=$ability;$p['choices'][$key]=['supported'=>true];return ['inspection'=>$p,'definition'=>['definition_version'=>$pin['definition_version'],'spatial_action'=>$binding],'ability'=>$ability];
    }
}
