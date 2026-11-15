<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/AchievementService.php';
final class AF_ACS_CorrectionService
{
    private AF_ACS_EncounterRepository $repo;
    public function __construct(object $database,private AF_ACS_SessionAccess $access){$this->repo=new AF_ACS_EncounterRepository($database);}
    private function authorize(string $sid):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$uid=$this->access->uid();$this->access->thread($s['tid']);
        if(!AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$uid),$uid))throw new RuntimeException('ACS_GM_REQUIRED');$this->access->correctionThread($s['tid']);return $s;
    }
    private function authorizeProgress(string $id):void
    {
        $sessions=$this->repo->achievementSessions($id);if(count($sessions)>200)throw new RuntimeException('ACS_ACHIEVEMENT_SUBJECT_LIMIT');
        foreach($sessions as $s)$this->authorize((string)$s['session_id']);
    }
    private function authorizeSource(string $sid,string $eid,string $source):void
    {
        $this->authorize($sid);foreach($this->repo->achievementSourceProgressIds($sid,$eid,$source) as $id)$this->authorizeProgress($id);
    }
    public function command(array $r):array
    {
        $this->access->csrf();$fields=['session_id','encounter_id','original_event_id','expected_version','expected_review_version','operation_key','reason','plan'];
        if(array_diff(array_keys($r),$fields)||array_diff($fields,array_keys($r)))throw new RuntimeException('ACS_CORRECTION_INPUT_INVALID');
        foreach($r as $k=>$v)if($k==='plan'?!is_array($v):!is_string($v))throw new RuntimeException('ACS_CORRECTION_INPUT_INVALID');
        foreach(['session_id','encounter_id','original_event_id','expected_version','expected_review_version'] as $k)$r[$k]=AF_ACS_Repository::integer($r[$k],'9007199254740990',$k==='expected_review_version');
        AF_ACS_Achievements::correction($r['plan']);if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_CORRECTION_INPUT_INVALID');
        $this->authorizeSource($r['session_id'],$r['encounter_id'],$r['original_event_id']);$r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;
        return $this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],'correction.review',$r,fn()=>$this->authorizeSource($r['session_id'],$r['encounter_id'],$r['original_event_id']),fn($s)=>$this->repo->reviewCorrection($s,$r['encounter_id'],$r['original_event_id'],$r['expected_review_version'],$r['plan'],fn($id)=>$this->authorizeProgress($id)),fn($s,$event)=>$this->repo->linkCorrectionEvent($s,$event));
    }
    public function view(string $sid,string $eid,string $source):array
    {$sid=AF_ACS_Repository::integer($sid);$eid=AF_ACS_Repository::integer($eid);$source=AF_ACS_Repository::integer($source);$this->authorizeSource($sid,$eid,$source);$this->repo->achievementSource($sid,$eid,$source);return ['review'=>$this->repo->latestCorrection($sid,$eid,$source)];}
}
