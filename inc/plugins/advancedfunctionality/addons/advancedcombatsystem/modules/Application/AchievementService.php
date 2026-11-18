<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/RewardService.php';
final class AF_ACS_AchievementService
{
    private AF_ACS_EncounterRepository $repo;
    public function __construct(private object $database,private AF_ACS_SessionAccess $access){$this->repo=new AF_ACS_EncounterRepository($database);}
    private function authorize(string $sid,bool $gm=false,bool $review=false):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$uid=$this->access->uid();$this->access->thread($s['tid']);$roles=$this->repo->roles($sid,$uid);
        if(!$roles||$gm&&!AF_ACS_AuthorizationService::sessionGm($s,$roles,$uid))throw new RuntimeException('ACS_GM_REQUIRED');
        if($review)$this->access->correctionThread($s['tid']);return $s;
    }
    private function authorizeProgress(string $id):void
    {
        $sessions=$this->repo->achievementSessions($id);if(count($sessions)>200)throw new RuntimeException('ACS_ACHIEVEMENT_SUBJECT_LIMIT');
        foreach($sessions as $s)$this->authorize((string)$s['session_id'],true);
    }
    public function command(string $op,array $r):array
    {
        $this->access->csrf();$extra=match($op){'achievement_configure'=>['encounter_version','kb_key'],'achievement_evaluate'=>['achievement_key','source_event_id'],'achievement_migrate'=>['achievement_key','progress_id','expected_progress_version'],'achievement_review'=>['progress_id','expected_progress_version'],default=>throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID')};
        $fields=['session_id','encounter_id','expected_version','operation_key','reason',...$extra];
        if(array_diff(array_keys($r),$fields)||array_diff($fields,array_keys($r)))throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
        foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
        foreach(['session_id','encounter_id','expected_version','encounter_version','source_event_id','progress_id','expected_progress_version'] as $k)if(isset($r[$k]))$r[$k]=AF_ACS_Repository::integer($r[$k],'9007199254740990');
        foreach(['kb_key','achievement_key'] as $k)if(isset($r[$k])&&!preg_match($k==='achievement_key'?'/\A[a-z][a-z0-9_.-]{2,95}\z/D':'/\A[a-z][a-z0-9_.-]{2,63}\z/D',$r[$k]))throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
        if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
        $review=in_array($op,['achievement_migrate','achievement_review'],true);
        $authorize=function()use($r,$op,$review){
            $this->authorize($r['session_id'],true,$review);
            if($review)$this->authorizeProgress($r['progress_id']);
            if($op==='achievement_evaluate')foreach($this->repo->achievementSourceProgressIds($r['session_id'],$r['encounter_id'],$r['source_event_id'],$r['achievement_key']) as $id)$this->authorizeProgress($id);
        };$authorize();$r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;
        $graph=$op==='achievement_configure'?(new AF_ACS_KnowledgeBaseAdapter)->achievementDefinition($r['kb_key'],(int)$r['actor_uid']):null;
        $evaluation=$op==='achievement_evaluate'?'achievement:'.$r['achievement_key'].':source:'.$r['source_event_id']:null;
        return $this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],match($op){'achievement_configure'=>'achievement.configure','achievement_evaluate'=>'achievement.evaluate','achievement_migrate'=>'achievement.migrate','achievement_review'=>'achievement.review'},$r,
            $authorize,function($s)use($op,$r,$graph){
                $e=$this->repo->encounter($s['id'],$r['encounter_id'],true)??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
                if(in_array($op,['achievement_migrate','achievement_review'],true))$this->authorizeProgress($r['progress_id']);
                if($op==='achievement_configure'&&$e['encounter_version']!==$r['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                return match($op){'achievement_configure'=>$this->repo->configureAchievement($s,$e,$graph),'achievement_evaluate'=>$this->repo->evaluateAchievement($s,$e['id'],$r['achievement_key'],$r['source_event_id'],fn($id)=>$this->authorizeProgress($id)),'achievement_migrate'=>$this->repo->migrateAchievement($s,$e['id'],$r['achievement_key'],$r['progress_id'],$r['expected_progress_version']),'achievement_review'=>$this->repo->acknowledgeAchievementReview($s,$e['id'],$r['progress_id'],$r['expected_progress_version'])};
            },$op==='achievement_evaluate'?fn($s,$event)=>$this->repo->linkAchievementEvent($s,$event):null,$evaluation!==null?fn()=>$this->repo->achievementReplay($r['session_id'],$r['encounter_id'],$evaluation):null);
    }
    public function view(string $sid,string $after='0'):array
    {
        $sid=AF_ACS_Repository::integer($sid);$after=AF_ACS_Repository::integer($after,zero:true);$s=$this->authorize($sid);$uid=$this->access->uid();$gm=AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$uid),$uid);if(!$gm&&$after!=='0')throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');$out=[];
        foreach($this->repo->achievementViewRows($sid,$uid,$gm,$after) as $row){
            $r=$this->repo->achievementProgress((string)$row['id']);$p=$r['progress'];$sessions=$this->repo->achievementSessions($r['id']);$allowed=count($sessions)<=200;
            foreach($sessions as $session)try{$this->authorize((string)$session['session_id'],$gm);}catch(Throwable){$allowed=false;break;}
            if(!$allowed)continue;
            $pin=null;foreach($p['pins'] as $candidate)if($candidate['snapshot_id']===$r['definition_snapshot_id'])$pin=$candidate;
            if(!$pin)throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');$d=$this->repo->achievementDefinitionPin($pin['session_id'],$pin['snapshot_id'],$pin['hash'])['definition'];
            $dto=['achievement_key'=>$r['achievement_key'],'name'=>$d['name'],'subject_kind'=>$r['subject_kind'],'subject_key'=>$r['subject_key'],'progress'=>$p['review_count'],'unlocked'=>$p['awards']>0,'awards'=>$p['awards']];
            if($gm)$dto+=['progress_id'=>$r['id'],'progress_version'=>$r['progress_version'],'visibility'=>$r['visibility'],'historical_count'=>$p['count'],'review_required'=>$p['review_required']];$out[]=$dto;
        }
        return ['achievements'=>$out];
    }
    /** Narrows the existing member/GM progress policy to one profile; no public account bypass. */
    public function profile(string $owner,string $cursor=''):array
    {
        global $mybb;
        require_once __DIR__.'/ArchiveService.php';
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        $owner=AF_ACS_Repository::integer($owner,'4294967295');$after='0';
        $secret=(string)($mybb->settings['af_acs_archive_cursor_key']??'');
        $bind=hash('sha256',AF_ACS_Repository::json(['scope'=>'profile-achievements-v1','owner'=>$owner,'viewer'=>$this->access->uid()]));
        if($cursor!==''){$v=AF_ACS_Archive::openCursor($cursor,$secret);if(($v['bind']??null)!==$bind)throw new RuntimeException('ACS_ARCHIVE_CURSOR_INVALID');$after=AF_ACS_Repository::integer($v['after']??null,zero:true);}
        $rows=$this->repo->achievementProfileRows($owner,$this->access->uid(),$after);$out=[];$processed=0;
        $archive=new AF_ACS_ArchiveService($this->database,$this->access);$checked=[];
        foreach($rows as $row){
            if($processed===100||count($out)===20)break;$processed++;$after=(string)$row['id'];
            $r=$this->repo->achievementProgress($after);$p=$r['progress'];
            if((string)($p['recipient_uid']??'')!==$owner)throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
            if($this->access->uid()===$owner&&($r['visibility']!=='visible'&&($r['visibility']!=='hidden_until_unlocked'||$p['awards']<1)))continue;
            $origins=$this->repo->achievementProfileOrigins($after);if(!$origins||count($origins)>200)continue;$allowed=true;
            foreach($origins as $origin)try{
                $identity=$origin['session_id'].':'.$origin['encounter_id'];
                if(isset($checked[$identity])){if(!$checked[$identity]){$allowed=false;break;}continue;}
                $checked[$identity]=false;
                $this->authorize((string)$origin['session_id'],$this->access->uid()!==$owner);
                $page=$archive->page((string)$origin['session_id'],(string)$origin['encounter_id'],[],limit:1,ordinaryExport:true);
                if($page['encounter_status']!=='completed'||$page['visibility']!=='reveal_all'){$allowed=false;break;}
                $checked[$identity]=true;
            }catch(Throwable){$allowed=false;break;}
            if(!$allowed)continue;
            $pin=null;foreach($p['pins'] as $candidate)if($candidate['snapshot_id']===$r['definition_snapshot_id'])$pin=$candidate;
            if(!$pin)throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
            $d=$this->repo->achievementDefinitionPin($pin['session_id'],$pin['snapshot_id'],$pin['hash'])['definition'];
            $out[]=['name'=>$d['name'],'subject_kind'=>$r['subject_kind'],'subject_key'=>$r['subject_key'],'progress'=>$p['review_count'],'unlocked'=>$p['awards']>0];
        }
        $more=$processed<count($rows);
        return ['achievements'=>$out,'next_cursor'=>$more?AF_ACS_Archive::cursor(['bind'=>$bind,'after'=>$after],$secret):null];
    }

}
