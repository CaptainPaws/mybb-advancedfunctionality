<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Infrastructure/ArchiveQuery.php';
require_once __DIR__.'/EffectSummary.php';
require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';
final class AF_ACS_ArchiveService
{
    private AF_ACS_EncounterRepository $repo;
    private AF_ACS_ArchiveQuery $query;
    public function __construct(object $database,private AF_ACS_SessionAccess $access){$this->repo=new AF_ACS_EncounterRepository($database);$this->query=new AF_ACS_ArchiveQuery($database);}
    private function context(string $sid,string $eid):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$uid=$this->access->uid();$this->access->thread($s['tid']);$roles=$this->repo->roles($sid,$uid);
        if(!$roles)throw new RuntimeException('ACS_ARCHIVE_ACCESS_DENIED');foreach($this->query->origins($sid,$eid) as $origin)$this->access->archiveForum((string)$origin['fid'],(string)$origin['tid']);$e=$this->repo->encounter($sid,$eid)??throw new RuntimeException('ACS_ARCHIVE_UNAVAILABLE');
        $gm=AF_ACS_AuthorizationService::sessionGm($s,$roles,$uid);$policy=$this->repo->archivePolicy($sid,$eid);$full=$gm||$policy['mode']==='reveal_all';
        $stamp=hash('sha256',json_encode([$sid,$eid,$uid,$s['owner_uid'],$roles,$gm,$policy],JSON_THROW_ON_ERROR));return compact('s','e','gm','full','policy','stamp');
    }
    private function secret():string
    {global $mybb;$key=(string)($mybb->settings['af_acs_archive_cursor_key']??'');if(!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new RuntimeException('ACS_ARCHIVE_CURSOR_UNAVAILABLE');return $key;}
    public function policy(array $r):array
    {
        $this->access->csrf();$keys=['session_id','encounter_id','expected_version','encounter_version','expected_policy_version','mode','operation_key','reason'];
        if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r)))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');
        foreach(['session_id','encounter_id','expected_version','encounter_version','expected_policy_version'] as $k)$r[$k]=AF_ACS_Repository::integer($r[$k],'9007199254740990',$k==='expected_policy_version');
        if(!in_array($r['mode'],['keep_hidden','reveal_results','reveal_all'],true)||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');
        $r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;$auth=function()use($r){$c=$this->context($r['session_id'],$r['encounter_id']);if(!$c['gm'])throw new RuntimeException('ACS_GM_REQUIRED');return $c;};$auth();
        return $this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],'archive.policy',$r,$auth,function($s)use($r){$e=$this->repo->encounter($s['id'],$r['encounter_id'],true);if($e['encounter_version']!==$r['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');return $this->repo->setArchivePolicy($s,$e['id'],$r['expected_policy_version'],$r['mode']);},fn($s,$event)=>$this->repo->linkArchivePolicy($s,$event));
    }
    private function project(array $c,array $e):?array
    {
        $body=$this->query->verify($e);$full=$c['full'];$gm=$c['gm'];$mode=$c['policy']['mode'];$type=$e['event_type'];
        if(!$gm&&$mode==='keep_hidden'&&$type!=='combat.resolved')return null;
        $dto=['event_id'=>(string)$e['id'],'event_sequence'=>$e['event_sequence']===null?null:(string)$e['event_sequence'],'type'=>$type,'category'=>AF_ACS_Archive::category($type),'detail_level'=>$gm||$full?'full':($mode==='keep_hidden'?'hidden':'approximate')];
        if($gm)$dto['session_sequence']=(string)$e['session_sequence'];
        if($e['source_pid']!==null){
            try{$this->access->archiveForum((string)$e['source_fid'],(string)$e['source_tid']);}catch(Throwable){return null;}
            $native=$e['native_pid']!==null&&(string)$e['native_tid']===(string)$e['source_tid']&&(string)$e['native_fid']===(string)$e['source_fid']&&(string)$e['native_uid']===(string)$e['source_author'];
            $visible=$native&&(string)$e['native_visible']==='1';$own=(string)$e['source_author']===$this->access->uid();
            if(!$gm&&$native&&!$visible&&!$own)return null;
            $dto['post']=['state'=>$visible?'available':'unavailable'];if($gm||$visible||$own)$dto['post']+=['pid'=>(string)$e['source_pid'],'url'=>$visible?'showthread.php?tid='.$e['source_tid'].'&pid='.$e['source_pid'].'#pid'.$e['source_pid']:null];
        }
        if($e['round_number']!==null&&($gm||$mode!=='keep_hidden'))$dto['round']=(string)$e['round_number'];
        if($type==='combat.resolved'&&($gm||$mode!=='keep_hidden')){
            $dto['actions']=AF_ACS_Archive::actions($body,$full);if($full)foreach($dto['actions'] as $i=>&$a)$a['calculations']=AF_ACS_EffectSummary::mechanics($body['plan']['actions'][$i],$body['final']['status_instances']??[],$body['changes']['prepared_executions']??[],$body['changes']['statuses']['id_map']??[]);unset($a);$dto['grouping']=['kind'=>'combat_action','post'=>$dto['post']??null];
            if($full){$ids=[];$groups=[];foreach($body['plan']['actions'] as $a){$ids[]=(string)$a['actor_id'];foreach($a['targets']??[] as $target)$ids[]=(string)$target;}
                foreach(array_unique($ids) as $id)if(isset($body['initial']['entities'][$id]['group_id']))$groups[]=(string)$body['initial']['entities'][$id]['group_id'];
                $dto['actors']=array_values(array_unique(array_map(static fn($a)=>(string)$a['actor_id'],$body['plan']['actions'])));$dto['entities']=array_values(array_unique($ids));$dto['control_groups']=array_values(array_unique($groups));
                $dto['participation']=$body['final']['participants']??[];
            }
        }elseif($type==='encounter.outcome_confirm'&&($gm||$mode!=='keep_hidden')){
            $outcome=$this->repo->confirmedOutcome((string)$e['session_id'],(string)$e['encounter_id']);if(!$outcome||$outcome['terminal_event_id']!==(string)$e['id'])throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');
            $dto['outcome']=['kind'=>$outcome['outcome']['kind']];if($full)$dto['outcome']=$outcome['outcome'];
        }elseif($type==='correction.review'){
            $original=(string)$e['correction_of_event_id'];$root=$this->repo->event($original);
            if(!$root||$root['session_id']!=$e['session_id']||$root['encounter_id']!=$e['encounter_id']||!in_array($root['event_type'],['combat.resolved','encounter.outcome_confirm'],true))throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');
            // Reapply original native-post permissions, never a hidden-target link oracle.
            if($root['event_type']==='combat.resolved'){$source=$this->query->sourceRow((string)$e['session_id'],(string)$e['encounter_id'],$original);$shown=$source?$this->project($c,$source):null;if(!$shown)return null;foreach(['post','round','entities','control_groups'] as $k)if(isset($shown[$k]))$dto[$k]=$shown[$k];}
            $review=$body['result']['review']??throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');$latest=$this->repo->latestCorrection((string)$e['session_id'],(string)$e['encounter_id'],$original);
            $dto['correction']=['original_event_id'=>$original,'revision'=>$review['review_version'],'disposition'=>$review['disposition'],'current_revision'=>$latest['review_version'],'current_disposition'=>$latest['disposition']];
        }
        if($gm){
            // No raw achievement progress, owner envelopes, grants, sealed JSON or global before/after.
            $reason=$body['request']['reason']??null;if(is_string($reason))$dto['reason']=$reason;
            $dto['proof']=['content_hash'=>$e['content_hash']];
            if($type!=='combat.resolved'){$ids=array_values(array_filter([(string)($e['source_entity_id']??''),(string)($e['target_entity_id']??'')]));if($ids)$dto['entities']=$ids;}
        }
        return $dto;
    }
    public function page(string $sid,string $eid,array $filters=[],string $cursor='',int $limit=20,bool $ordinaryExport=false):array
    {
        $sid=AF_ACS_Repository::integer($sid);$eid=AF_ACS_Repository::integer($eid);if($limit<1||$limit>50)throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');$f=AF_ACS_Archive::filters($filters);$c=$this->context($sid,$eid);if($ordinaryExport){$c['gm']=false;$c['full']=$c['policy']['mode']==='reveal_all';}
        if(!$c['full']&&($f['actor']!==''||$f['entity']!==''||$f['control_group']!==''))throw new RuntimeException('ACS_ARCHIVE_FILTER_DENIED');
        $bind=hash('sha256',json_encode([$sid,$eid,$this->access->uid(),$c['stamp'],$f,$limit,$ordinaryExport],JSON_THROW_ON_ERROR));$key=$this->secret();$last=['0','0','0'];$upper=$this->query->horizon($sid,$eid);
        if($cursor!==''){$v=AF_ACS_Archive::openCursor($cursor,$key);if(($v['bind']??null)!==$bind||!is_array($v['last']??null)||count($v['last'])!==3)throw new RuntimeException('ACS_ARCHIVE_CURSOR_INVALID');$upper=AF_ACS_Repository::integer($v['upper'],zero:true);$last=array_map(fn($n)=>AF_ACS_Repository::integer($n,zero:true),$v['last']);}
        $events=[];$scanned=0;$exhausted=false;
        while(count($events)<$limit&&$scanned<200){$rows=$this->query->rows($sid,$eid,$upper,$last,$c['gm'],$f,$c['policy']['mode']);if(!$rows){$exhausted=true;break;}
            foreach($rows as $row){if($row['event_sequence']!==null&&(strlen((string)$row['event_sequence'])<strlen($last[0])||strlen((string)$row['event_sequence'])===strlen($last[0])&&strcmp((string)$row['event_sequence'],$last[0])<0))throw new RuntimeException('ACS_ARCHIVE_SEQUENCE_INVALID');$last=[(string)$row['archive_sequence'],(string)$row['session_sequence'],(string)$row['id']];$scanned++;$dto=$this->project($c,$row);if($dto!==null&&AF_ACS_Archive::matches($dto,$f))$events[]=$dto;if(count($events)>=$limit||$scanned>=200)break;}
            if(count($rows)<100&&count($events)<$limit&&$scanned<200){$exhausted=true;break;}
        }
        $next=$exhausted?null:AF_ACS_Archive::cursor(['bind'=>$bind,'upper'=>$upper,'last'=>$last],$key);
        if($this->context($sid,$eid)['stamp']!==$c['stamp'])throw new RuntimeException('ACS_ARCHIVE_ACCESS_CHANGED');
        return ['schema_version'=>1,'session_id'=>$sid,'encounter_id'=>$eid,'encounter_status'=>$c['e']['status'],'visibility'=>$c['policy']['mode'],'policy_version'=>$c['policy']['policy_version'],'can_manage'=>$c['gm'],'identity_filters'=>$c['full'],'can_export_extended'=>$c['gm']&&$this->access->archiveExtended($c['s']['tid']),'management'=>$c['gm']?['expected_version'=>$c['s']['session_version'],'encounter_version'=>$c['e']['encounter_version'],'expected_policy_version'=>$c['policy']['policy_version']]:null,'events'=>$events,'next_cursor'=>$next];
    }
    public function export(string $sid,string $eid,array $filters=[],string $cursor='',string $format='json',bool $extended=false):array
    {
        $c=$this->context($sid,$eid);if($extended&&(!$c['gm']||!$this->access->archiveExtended($c['s']['tid'])))throw new RuntimeException('ACS_ARCHIVE_EXPORT_DENIED');
        $page=$this->page($sid,$eid,$filters,$cursor,20,!$extended);$file=$page;unset($file['next_cursor']);
        if($extended&&$format==='json'){$pins=$this->manifest($sid,$eid,'snapshots');$file['extended']=['scope'=>'encounter','source'=>'immutable-events','snapshots'=>$pins['items'],'snapshots_next_after'=>$pins['next_after']];}
        $bytes=match($format){'json'=>json_encode($file,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'csv'=>AF_ACS_Archive::csv($file['events']),default=>throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID')};
        if(strlen($bytes)>8388608)throw new RuntimeException('ACS_ARCHIVE_EXPORT_LIMIT');
        if($this->context($sid,$eid)['stamp']!==$c['stamp']||$extended&&!$this->access->archiveExtended($c['s']['tid']))throw new RuntimeException('ACS_ARCHIVE_ACCESS_CHANGED');
        return ['content_type'=>$format==='json'?'application/json; charset=UTF-8':'text/csv; charset=UTF-8','filename'=>'acs-archive-'.$eid.'.'.$format,'body'=>$bytes,'next_cursor'=>$page['next_cursor']];
    }
    public function manifest(string $sid,string $eid,string $section,string $after='0'):array
    {
        $c=$this->context($sid,$eid);if(!$c['gm']||!$this->access->archiveExtended($c['s']['tid']))throw new RuntimeException('ACS_ARCHIVE_EXPORT_DENIED');$after=AF_ACS_Repository::integer($after,zero:true);
        foreach($this->query->origins($sid,in_array($section,['custody','obligations'],true)?null:$eid) as $origin)$this->access->archiveForum((string)$origin['fid'],(string)$origin['tid']);
        if($section==='obligations'){
            $items=$this->repo->obligations($sid);$hidden=$this->query->achievementRewardRefs($sid);$ids=array_map('strval',array_column($hidden,'id'));$keys=array_column($hidden,'operation_key');
            foreach(['confirmed_completed','pending','recovery_required','explicitly_blocked'] as $state)$items[$state]=array_values(array_filter($items[$state],static function($v)use($ids,$keys){
                if(str_starts_with($v['source'],'achievement'))return false;
                if($v['source']==='reward'&&(str_starts_with($v['reference'],'records:')||in_array($v['reference'],$ids,true)))return false;
                return !($v['source']==='operation'&&in_array($v['reference'],$keys,true));
            }));
            return ['scope'=>'current_session_obligations','complete'=>false,'excluded_categories'=>['achievement_progress','achievement_rewards'],'items'=>$items,'next_after'=>null];
        }
        $rows=$this->query->manifestRows($sid,$eid,$section,$after);$more=count($rows)>20;if($more)array_pop($rows);
        foreach($rows as &$r){foreach($r as $k=>$v)if($v!==null&&in_array($k,['id','recipient_group_id','recipient_uid','custodian_entity_id','active_user_entity_id','owner_uid'],true))$r[$k]=(string)$v;
            if($section==='snapshots'){if(!hash_equals($r['content_hash'],hash('sha256',$r['payload_json'])))throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');$r['snapshot']=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR);unset($r['payload_json']);}
            if($section==='rewards'){if(!hash_equals($r['payload_hash'],hash('sha256',$r['payload_json'])))throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');unset($r['payload_hash'],$r['payload_json']);}
        }unset($r);return ['scope'=>$section==='custody'?'current_session_custody':'encounter','items'=>$rows,'next_after'=>$more?(string)end($rows)['id']:null];
    }
}
