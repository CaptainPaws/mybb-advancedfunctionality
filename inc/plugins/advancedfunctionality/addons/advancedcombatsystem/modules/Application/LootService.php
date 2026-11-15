<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/EncounterService.php';
require_once __DIR__.'/../Infrastructure/Adapters/KnowledgeBaseAdapter.php';

/** Explicit GM backend. Generation and ownership are separate commands/subsystems. */
final class AF_ACS_LootService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access,private ?AF_ACS_RandomSource $rng=null) {}
    private function authorize(string $sid,string $eid):array
    {
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$this->access->thread($s['tid']);
        if(!AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$this->access->uid()),$this->access->uid()))throw new RuntimeException('ACS_GM_REQUIRED');
        return $this->repo->encounter($sid,$eid)??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
    }
    public function inspect(string $sid,string $eid,string $source):array
    {
        $sid=AF_ACS_Repository::integer($sid);$eid=AF_ACS_Repository::integer($eid);$this->authorize($sid,$eid);
        return ['session_id'=>$sid,'encounter_id'=>$eid,'source_key'=>$source,'pool'=>$this->repo->lootPool($sid,$eid,$source)];
    }
    public function command(string $op,array $r):array
    {
        $this->access->csrf();
        $fields=['session_id','encounter_id','expected_version','encounter_version','operation_key','reason',...match($op){'loot_configure'=>['kb_key','source_kind','source_entity_id'],'loot_generate'=>['source_key'],default=>throw new RuntimeException('ACS_LOOT_INPUT_INVALID')}];
        if(array_diff(array_keys($r),$fields)||array_diff($fields,array_keys($r)))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        foreach(['session_id','encounter_id','expected_version','encounter_version'] as $f)$r[$f]=AF_ACS_Repository::integer($r[$f],'18446744073709551614');
        if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        $r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;
        $this->authorize($r['session_id'],$r['encounter_id']);
        $source=null;
        if($op==='loot_configure'){
            if(!in_array($r['source_kind'],['monster','encounter','kb'],true)||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$r['kb_key']))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            $entity=$r['source_kind']==='monster'?AF_ACS_Repository::integer($r['source_entity_id']):null;
            if($entity===null&&$r['source_entity_id']!=='')throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            $source=['identity'=>match($r['source_kind']){'monster'=>'monster:'.$entity,'encounter'=>'encounter','kb'=>'kb:'.$r['kb_key']},'kind'=>$r['source_kind'],'entity_id'=>$entity,'kb_key'=>$r['kb_key']];
        }elseif(!preg_match('/\A(?:encounter|monster:[1-9][0-9]*|kb:[a-z][a-z0-9_-]{1,63})\z/D',$r['source_key']))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        return $this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],'encounter.'.$op,$r,
            fn()=>$this->authorize($r['session_id'],$r['encounter_id']),
            function($s)use($op,$r,$source){
                $e=$this->repo->encounter($s['id'],$r['encounter_id'],true);
                if($e['encounter_version']!==$r['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                if($op==='loot_configure')return $this->repo->configureLoot($s,$e,$source,(new AF_ACS_KnowledgeBaseAdapter)->lootDefinition($r['kb_key'],(int)$r['actor_uid']));
                return $this->repo->generateLoot($s,$e,$r['source_key'],$this->rng??new AF_ACS_RandomSource);
            },
            $op==='loot_generate'?fn($s,$event)=>$this->repo->linkLootEvent($s,$event):null,
            $op==='loot_generate'?fn()=>$this->repo->lootReplay($r['session_id'],$r['encounter_id'],$r['source_key']):null);
    }
}
