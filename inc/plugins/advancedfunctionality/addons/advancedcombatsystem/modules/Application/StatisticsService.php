<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Infrastructure/StatisticsRepository.php';
require_once __DIR__.'/ArchiveService.php';
require_once __DIR__.'/../Infrastructure/Adapters/StatisticsAdapter.php';

final class AF_ACS_StatisticsService
{
    private AF_ACS_StatisticsRepository $stats;
    private AF_ACS_EncounterRepository $repo;
    public function __construct(private object $database,private AF_ACS_SessionAccess $access)
    {$this->stats=new AF_ACS_StatisticsRepository($database);$this->repo=new AF_ACS_EncounterRepository($database);}
    public function synchronize(string $sid,string $eid,string $visibility):array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($sid);$eid=AF_ACS_Repository::integer($eid);
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$this->access->thread($s['tid']);
        if(!AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$this->access->uid()),$this->access->uid()))throw new RuntimeException('ACS_GM_REQUIRED');
        $result=$this->stats->refresh($sid,$eid,$visibility);$pending=false;
        foreach($this->stats->pending($eid) as $row){
            try{
                if(!hash_equals($row['payload_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_STATISTICS_INTEGRITY');
                $m=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
                $receipt=AF_ACS_StatisticsAdapter::publishCompletedAggregate($m['operation_key'],$m['encounter_id'],$m['aggregate_version'],$m['visibility'],$m['payload']);
                if(($receipt['operation_key']??null)!==$row['message_key']||!hash_equals($row['payload_hash'],$receipt['payload_hash']??'')||!in_array($receipt['status']??null,['accepted','superseded'],true))throw new RuntimeException('ACS_STATISTICS_RECEIPT_INVALID');
                $this->stats->acknowledge($row['message_key'],$row['payload_hash']);
            }catch(Throwable){$pending=true;}
        }
        return $result+['publication'=>($pending||$this->stats->pending($eid))?'pending':'delivered'];
    }
    /** IC-PROFILE v1. All filters and totals operate AFTER encounter authorization. No writes. */
    public function profile(string $owner,array $filters=[]):array
    {
        $owner=AF_ACS_Repository::integer($owner,'4294967295');
        if(array_diff(array_keys($filters),['entity','control_group','kind','category','event_type']))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
        foreach($filters as $v)if(!is_string($v))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
        if(isset($filters['kind'])&&!in_array($filters['kind'],['entity','character','pet','summon','control_group'],true))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
        if(isset($filters['category'])&&!in_array($filters['category'],AF_ACS_Statistics::METRICS,true))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
        if(isset($filters['event_type'])&&!in_array($filters['event_type'],['combat.resolved','clock.status_tick','encounter.prepared_register','encounter.ambush_start'],true))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
        foreach(['entity','control_group'] as $k)if(isset($filters[$k]))AF_ACS_Repository::integer($filters[$k]);
        $archive=new AF_ACS_ArchiveService($this->database,$this->access);$totals=[];$history=[];$choices=['entity'=>[],'control_group'=>[]];$after='0';$scanned=0;
        do{
            $rows=$this->stats->profileCandidates($owner,$after);
            foreach($rows as $row){
                if(++$scanned>1000)throw new RuntimeException('ACS_STATISTICS_LIMIT');$after=(string)$row['encounter_id'];
                $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$sid=$p['session_id'];$eid=$p['encounter_id'];
                $envelope=AF_ACS_Repository::json(['schema_version'=>1,'operation_key'=>'statistics:'.$row['encounter_id'].':'.$row['aggregate_version'],'encounter_id'=>(string)$row['encounter_id'],'aggregate_version'=>(string)$row['aggregate_version'],'visibility'=>$row['visibility'],'payload'=>$p]);
                if(!hash_equals($row['payload_hash'],hash('sha256',$envelope))||$eid!==(string)$row['encounter_id']||$p['aggregate_version']!==(string)$row['aggregate_version'])throw new RuntimeException('ACS_STATISTICS_INTEGRITY');
                if(($p['secrecy_safe']??false)!==true)continue;
                try{
                    // Ordinary archive projection deliberately applies member secrecy even to a GM.
                    // A hidden or approximate archive cannot disclose exact totals or membership.
                    $page=$archive->page($sid,$eid,[],limit:1,ordinaryExport:true);
                    if($page['encounter_status']!=='completed'||$page['visibility']!=='reveal_all')continue;
                    $s=$this->repo->session($sid);$gm=AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$this->access->uid()),$this->access->uid());
                    $audience=$this->stats->profileAudience($eid,$this->access->uid());if(!$audience['safe'])continue;
                    if($row['visibility']==='private'&&$this->access->uid()!==$owner&&!$gm)continue;
                    if($row['visibility']==='participants'&&!$gm&&!$audience['participant'])continue;
                }catch(Throwable){continue;}
                $subjects=[];
                foreach($p['subjects'] as $subject){
                    if((string)$subject['owner_uid']!==$owner)continue;
                    $kind=$subject['subject_kind'];$id=$subject['subject_key'];
                    if(isset($choices[$kind]))$choices[$kind][$id]=$id;
                    if(isset($filters['kind'])&&$kind!==$filters['kind'])continue;
                    if(isset($filters['entity'])&&($kind==='control_group'||$id!==$filters['entity']))continue;
                    if(isset($filters['control_group'])&&($kind!=='control_group'||$id!==$filters['control_group']))continue;
                    $metrics=isset($filters['event_type'])?($subject['event_aggregates'][$filters['event_type']]??AF_ACS_Statistics::zero()):$subject['metrics'];if(isset($filters['category']))$metrics=array_intersect_key($metrics,[$filters['category']=>true]);
                    $subjects[]=['subject_kind'=>$kind,'subject_key'=>$id,'metrics'=>$metrics,'event_aggregates'=>$subject['event_aggregates']??[]];$totals[$kind]??=AF_ACS_Statistics::zero();
                    foreach($metrics as $k=>$n){$sum=$totals[$kind][$k]+$n;if(!is_int($sum)||$sum>9007199254740991)throw new RuntimeException('ACS_STATISTICS_OVERFLOW');$totals[$kind][$k]=$sum;}
                }
                if($subjects)$history[]=['encounter_id'=>$eid,'aggregate_version'=>(string)$row['aggregate_version'],'visibility'=>$row['visibility'],'subjects'=>$subjects,'archive_url'=>'misc.php?action=acs_archive&session_id='.$sid.'&encounter_id='.$eid];
            }
        }while(count($rows)===101);
        foreach($choices as &$values)$values=array_values($values);unset($values);
        return ['schema_version'=>1,'read_only'=>true,'owner_uid'=>$owner,'totals_by_subject_kind'=>$totals,'completed_encounters'=>$history,'event_categories'=>AF_ACS_Statistics::METRICS,'filters'=>$choices];
    }
}
