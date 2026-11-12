<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/../Infrastructure/EncounterRepository.php';

/** One current server audience policy. No public DTO cache and no detection during reads. */
final class AF_ACS_AudienceService
{
    private array $rows,$subjects,$statuses,$permissions=[],$temporaryActors=[],$actors=[],$audiences=['actor'=>[],'control_group'=>[],'side'=>[],'encounter'=>[]];
    private bool $gm;
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access,string $sid,private string $eid)
    {
        $view=(new AF_ACS_SessionService($repo,$access))->view($sid);$this->gm=$view['can_manage'];
        $this->rows=$repo->awarenessProjection($sid,$eid);$this->subjects=$repo->perceptionSubjects($sid,$eid);$this->statuses=$repo->statusProjection($sid)['instances'];$this->audiences['encounter']=[$eid];
        foreach($repo->participants($eid,'0',201) as $m)if($m['participation']==='engaged'){
            $this->permissions[$m['entity_id']]=json_decode($m['visibility_json'],true,16,JSON_THROW_ON_ERROR)['scope']??null;
            $control=$repo->controllerFor($sid,$m['entity_id'],$access->uid());
            if(!$control)continue;
            $actor=['entity_id'=>$m['entity_id'],'group_id'=>$m['control_group_id'],'side_id'=>$m['side_id'],'encounter_id'=>$eid];if(isset($control['control_overlay'])){if(($this->permissions[$m['entity_id']]??null)==='participants'&&AF_ACS_Awareness::known($this->rows,'entity',$m['entity_id'],AF_ACS_Awareness::audiences($actor))['level']==='identified'){$this->actors[$m['entity_id']]=$actor;$this->temporaryActors[$m['entity_id']]=true;$this->audiences['actor'][]=$m['entity_id'];}continue;}$this->actors[$m['entity_id']]=$actor;
            foreach(AF_ACS_Awareness::audiences($actor) as $kind=>$ids)$this->audiences[$kind]=array_values(array_unique([...$this->audiences[$kind],...$ids]));
        }
    }
    public function knowledge(string $kind,string $id,?string $actor=null):array
    {
        if($this->gm||$kind==='entity'&&isset($this->actors[$id]))return ['level'=>'identified','details'=>[],'version'=>0];
        if($kind==='entity'&&($this->permissions[$id]??null)!=='participants')return ['level'=>'unknown','details'=>[],'version'=>0];
        $audiences=$actor!==null&&isset($this->temporaryActors[$actor])?['actor'=>[$actor],'encounter'=>[$this->eid]]:($actor!==null?(isset($this->actors[$actor])?AF_ACS_Awareness::audiences($this->actors[$actor]):[]):$this->audiences);
        return AF_ACS_Awareness::known($this->rows,$kind,$id,$audiences);
    }
    public function canTarget(string $id,?string $actor=null):bool
    {
        if($id===$actor&&($this->gm||isset($this->actors[$id])))return true;
        $known=$this->knowledge('entity',$id,$actor);$base=$this->subjects['entity:'.$id]['perception']['stealth']??null;
        return AF_ACS_Awareness::targetable($known['level'],$base,$this->statuses,$id);
    }
    public function name(string $id,string $native):string
    {return $this->gm||isset($this->actors[$id])?$native:($this->knowledge('entity',$id)['details']['name']??'Участник');}
    public function notices():array
    {
        $out=[];foreach($this->subjects as $subject){if($subject['kind']==='entity'&&$subject['perception']===null&&!array_filter($this->rows,static fn($r)=>$r['subject_kind']==='entity'&&$r['subject_id']===$subject['id']))continue;$v=$this->knowledge($subject['kind'],$subject['id']);if($v['level']==='unknown')continue;
            $entry=['level'=>$v['level'],'details'=>$v['details']];if($v['level']==='identified')$entry+=['subject_kind'=>$subject['kind'],'subject_id'=>$subject['id']];
            if($this->gm)$entry+=['gm_only'=>true,'awareness'=>array_values(array_filter($this->rows,static fn($r)=>$r['subject_kind']===$subject['kind']&&$r['subject_id']===$subject['id']))];$out[]=$entry;
        }return $out;
    }
    /** Projection only: stored receipts/results and their verification hashes remain immutable. */
    public function receipt(array $receipt):array
    {
        if($this->gm)return $receipt;
        if(isset($receipt['spawns'])){
            $visible=[];foreach($receipt['spawns'] as $spawn){$kind=$spawn['kind']==='spawn_object'?'object':'entity';if($this->knowledge($kind,$spawn['entity_id'])['level']!=='identified')continue;$visible[]=array_intersect_key($spawn,array_flip(['entity_id','kind','source_event_id','spawn_index','control_group_id']));}
            unset($receipt['spawns']);if($visible)$receipt['spawns']=$visible;
        }return $receipt;
    }
    public function stamp():string
    {return hash('sha256',serialize([$this->access->uid(),$this->eid,$this->gm,$this->audiences,$this->actors,$this->temporaryActors,$this->permissions,$this->rows,$this->statuses]));}
}
