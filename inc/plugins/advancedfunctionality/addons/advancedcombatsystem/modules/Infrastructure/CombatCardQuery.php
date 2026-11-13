<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/TerminalReceiptPersistence.php';
require_once __DIR__.'/Adapters/SessionAccess.php';
require_once __DIR__.'/../Domain/Publication.php';
require_once __DIR__.'/../Application/AudienceService.php';

/** Batched, immutable result projection. No snapshots, Builder, Resolver or mechanical writers. */
final class AF_ACS_CombatCardQuery extends AF_ACS_Repository
{
    use AF_ACS_TerminalReceiptPersistence;
    /** Opaque request-cache partition; freshly checked native rights and current authorization/knowledge generations. */
    public function audienceCacheKey(string $tid,AF_ACS_SessionAccess $access):string
    {
        $access->thread($tid);$uid=$access->uid();$sidRows=$this->sql('SELECT s.id,s.session_version,e.id AS encounter_id,e.encounter_version,a.id AS awareness_id,a.awareness_version,a.audience_kind,a.audience_id FROM '.$this->table('sessions').' s LEFT JOIN '.$this->table('encounters').' e ON e.session_id=s.id LEFT JOIN '.$this->table('awareness').' a ON a.encounter_id=e.id WHERE s.tid=? ORDER BY s.id,e.id,a.id',[$tid])['rows'];
        $roles=$this->sql('SELECT r.* FROM '.$this->table('session_roles').' r JOIN '.$this->table('sessions').' s ON s.id=r.session_id WHERE s.tid=? AND r.uid=? ORDER BY r.id',[$tid,$uid])['rows'];
        $controllers=$this->sql('SELECT c.* FROM '.$this->table('controllers').' c JOIN '.$this->table('sessions').' s ON s.id=c.session_id WHERE s.tid=? ORDER BY c.id',[$tid])['rows'];
        $delegations=$this->sql('SELECT d.* FROM '.$this->table('delegations').' d JOIN '.$this->table('sessions').' s ON s.id=d.session_id WHERE s.tid=? AND d.delegate_uid=? ORDER BY d.id',[$tid,$uid])['rows'];
        return hash('sha256',serialize([$uid,$tid,$access->administrator(),time(),$sidRows,$roles,$controllers,$delegations]));
    }
    public function forPosts(string $tid, array $pids, ?AF_ACS_SessionAccess $access = null): array
    {
        $this->transactions->assertExternalBoundary();$access??=new AF_ACS_SessionAccess;$uid=$access->uid();
        $tid=self::integer($tid,'4294967295');$topic=$access->thread($tid);$fid=self::integer((string)($topic['fid']??''),'4294967295');
        $ids=[];foreach($pids as $id){$ids[]=self::integer($id,'4294967295');} $ids=array_values(array_unique($ids));
        if (!$ids) { return []; }if(count($ids)>100){throw new RuntimeException('ACS_CARD_PAGE_LIMIT');}
        $role='SELECT 1 FROM '.$this->table('session_roles').' r WHERE r.session_id=s.session_id AND r.uid=? AND (r.expires_at IS NULL OR r.expires_at>?)';
        $rows=$this->sql('SELECT s.*,p.visible AS native_visible,EXISTS('.$role." AND r.role IN ('owner','gm')) AS detailed FROM ".$this->table('submissions').' s JOIN `'.$this->prefix.'posts` p ON p.pid=s.pid WHERE s.tid=? AND p.tid=s.tid AND p.uid=s.author_uid AND p.fid=? AND s.pid IN ('.implode(',',array_fill(0,count($ids),'?')).') AND EXISTS('.$role.')',array_merge([$uid,time(),$tid,$fid],$ids,[$uid,time()]))['rows'];
        $allowed=[];$needDetails=false;$scopes=[];$repo=new AF_ACS_EncounterRepository($this->connection);
        foreach($rows as $row){
            $scope=$scopes[(string)$row['session_id']]??=(new AF_ACS_SessionService($repo,$access))->view((string)$row['session_id']);$row['detailed']=$scope['can_manage'];
            if((string)$row['native_visible']!=='1' && (string)$row['author_uid']!==$uid && !(bool)$row['detailed'])continue;
            foreach(['pid','tid','turn_id','author_uid','session_id','state_version'] as $k)$row[$k]=(string)$row[$k];
            $allowed[$row['submission_id']]=$row;$needDetails=$needDetails||(bool)$row['detailed'];
        }
        if(!$allowed)return [];
        $records=$this->terminalRecords(array_keys($allowed),$needDetails);$out=[];$names=[];
        foreach($allowed as $id=>$row){
            $receipt=$this->verifyTerminalRecord($row,$records[$id]??null);
            if($receipt && (($receipt['session_id']??null)!==$row['session_id'] || ($receipt['turn_id']??null)!==$row['turn_id'] || ($receipt['pid']??null)!==$row['pid'] || ($receipt['fid']??null)!==$fid))throw new RuntimeException('ACS_CARD_LINK_INVALID');
            $card=AF_ACS_Publication::card($receipt?'committed':$row['status']);
            if(!$receipt && $row['status']==='moderation_pending' && (string)$row['native_visible']==='1')$card['label']='Сообщение одобрено, боевой результат ожидает подтверждения';
            $card+=['schema_version'=>1,'submission_id'=>$id,'pid'=>$row['pid'],'session_id'=>$row['session_id'],'details'=>null,'can_manage'=>(bool)$row['detailed']];
            if($receipt && (bool)$row['detailed']){
                $summary=json_decode($records[$id]['card_summary'],true,64,JSON_THROW_ON_ERROR);
                $actions=json_decode($records[$id]['card_actions'],true,64,JSON_THROW_ON_ERROR);
                if(($summary['schema_version']??null)!==1 || !is_array($summary['actions']??null) || !is_array($actions) || count($summary['actions'])!==count($actions))throw new RuntimeException('ACS_CARD_RESULT_INVALID');
                $details=[];$statusRows=json_decode($records[$id]['card_statuses']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                $history=json_decode($records[$id]['card_boundaries']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                foreach($summary['actions'] as $i=>$step){
                    $entry=array_intersect_key($step,array_flip(['actor_id','target_id','ability_key','outcome','rolls','probabilities','costs','damage','item','interaction','cooperation','spatial','cover']));
                    foreach(['actor_id','target_id'] as $k){$entry[$k]=self::integer($entry[$k]??null);$names[$row['session_id']][$entry[$k]]=true;}
                    $entry['components']=[];$entry['mechanics']=[];
                    foreach($actions[$i]['strikes']??[$actions[$i]] as $strike){
                        foreach($strike['allocation']['components']??[] as $c)$entry['components'][]=array_intersect_key($c,array_flip(['damage_type','element','raw','after_crit','effective_defense','post_defense','allocated','immunity']));
                        $original=(string)($strike['original_target']??$entry['target_id']);$actual=(string)($strike['resolved_target']??$original);
                        $entry['mechanics'][]=['type'=>'target','original_target'=>$original,'resolved_target'=>$actual,'outcome'=>$strike['scenario'],'rolls'=>$strike['rolls'],'damage'=>$strike['allocation']['integer_budget']];
                        foreach($strike['reactions']??[] as $reaction)if(($reaction['queue_kind']??null)!=='prepared')$entry['mechanics'][]=['type'=>'reaction']+array_intersect_key($reaction,array_flip(['id','entity_id','phase','executed','reason','redirect_success','original_target','resolved_target']));
                    }
                    foreach($statusRows as $status)if(in_array($status['target_entity_id'],[$entry['actor_id'],...($actions[$i]['targets']??[]),$actual],true))$entry['mechanics'][]=['type'=>'status','effect_key'=>$status['spec']['effect_key'],'target_entity_id'=>$status['target_entity_id'],'stacks'=>$status['stacks'],'remaining_duration'=>$status['remaining_duration'],'duration_unit'=>$status['duration_unit'],'status'=>$status['status']];
                    if($i===0)foreach($history as $boundary)foreach($boundary['result']['statuses']['trace']['events']??[] as $event){
                        if(($event['type']??null)==='upkeep_result')$entry['mechanics'][]=['type'=>'upkeep']+array_intersect_key($event,array_flip(['id','source_entity_id','paid','reason','costs','boundary_key']));
                        elseif(isset($event['effect_id']))$entry['mechanics'][]=['type'=>'tick','effect_id'=>$event['effect_id'],'tick_count'=>count($event['ticks']??[]),'boundary_key'=>$boundary['boundary_key']];
                    }
                    $details[]=$entry;
                }
                $card['details']=$details;$card['result_hash']=$receipt['result_hash'];
            }
            $out[$row['pid']]=$card;
        }
        // Names are presentation only, never taken from an Appearance mask or used in result hashes.
        $labels=[];
        if($names){
            $all=[];foreach($names as $set)$all=array_merge($all,array_keys($set));$all=array_values(array_unique($all));
            foreach($this->sql('SELECT id,session_id,name FROM '.$this->table('entities').' WHERE id IN ('.implode(',',array_fill(0,count($all),'?')).')',$all)['rows'] as $e)$labels[(string)$e['session_id']][(string)$e['id']]=$e['name'];
        }
        foreach($out as &$card)if($card['details']!==null)foreach($card['details'] as &$step){
            $step['actor_name']=$labels[$card['session_id']][$step['actor_id']]??'Участник';$step['target_name']=$labels[$card['session_id']][$step['target_id']]??'Участник';
        }unset($step,$card);
        return $out;
    }
}
