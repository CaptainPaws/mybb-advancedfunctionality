<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/AudienceService.php';

/** Explicit GM disclosure/correction event, including audience and level CAS. */
final class AF_ACS_AwarenessService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access) {}
    private function authorize(string $sid):void
    {$v=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);if(!$v['can_manage']||$v['session']['status']!=='active')throw new RuntimeException('ACS_AWARENESS_UNAVAILABLE');}
    public function set(array $r):array
    {
        $this->access->csrf();$allowed=['session_id','encounter_id','expected_version','expected_encounter_version','subject_kind','subject_id','audience_kind','audience_id','level','expected_awareness_version','details','reason','operation_key'];
        if(array_diff(array_keys($r),$allowed)||array_diff($allowed,array_keys($r)))throw new RuntimeException('ACS_AWARENESS_UNAVAILABLE');
        $key=$r['operation_key'];unset($r['operation_key']);if(!is_string($key)||!preg_match('/\A[a-z0-9][a-z0-9_.-]{0,95}\z/D',$key))throw new RuntimeException('ACS_AWARENESS_UNAVAILABLE');
        foreach(['session_id','encounter_id','expected_version','expected_encounter_version','subject_id','audience_id','expected_awareness_version'] as $k)$r[$k]=AF_ACS_Repository::integer($r[$k],$k==='expected_awareness_version'?'9007199254740991':'18446744073709551615',$k==='expected_awareness_version');
        if(!is_string($r['reason'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000||!mb_check_encoding($r['reason'],'UTF-8')||str_contains($r['reason'],"\0"))throw new RuntimeException('ACS_REASON_REQUIRED');
        $r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;$this->authorize($r['session_id']);
        return $this->repo->command('session:'.$this->access->uid().':awareness:'.$key,'encounter.awareness_set',$r,fn()=> $this->authorize($r['session_id']),function($session)use($r){
            $enc=$this->repo->encounter($r['session_id'],$r['encounter_id'],true);if(!$enc||!in_array($enc['status'],['active','paused'],true)||$enc['encounter_version']!==$r['expected_encounter_version'])throw new RuntimeException('ACS_AWARENESS_UNAVAILABLE');return $this->repo->changeAwareness($session,$enc,$r);
        });
    }
}
