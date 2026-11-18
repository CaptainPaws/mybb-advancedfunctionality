<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/TerminalReceiptPersistence.php';
require_once __DIR__.'/Adapters/SessionAccess.php';
require_once __DIR__.'/../Domain/Publication.php';
require_once __DIR__.'/../Application/AudienceService.php';
require_once __DIR__.'/../Application/EffectSummary.php';
require_once __DIR__.'/CombatCardProjection.php';

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
        $rows=$this->sql('SELECT s.*,r.round_number AS card_round,p.visible AS native_visible,EXISTS('.$role." AND r.role IN ('owner','gm')) AS detailed FROM ".$this->table('submissions').' s LEFT JOIN '.$this->table('turns').' t ON t.id=s.turn_id LEFT JOIN '.$this->table('rounds').' r ON r.id=t.round_id AND r.encounter_id=s.encounter_id JOIN `'.$this->prefix.'posts` p ON p.pid=s.pid WHERE s.tid=? AND p.tid=s.tid AND p.uid=s.author_uid AND p.fid=? AND s.pid IN ('.implode(',',array_fill(0,count($ids),'?')).') AND EXISTS('.$role.')',array_merge([$uid,time(),$tid,$fid],$ids,[$uid,time()]))['rows'];
        $allowed=[];$needDetails=false;$scopes=[];$repo=new AF_ACS_EncounterRepository($this->connection);
        foreach($rows as $row){
            $scope=$scopes[(string)$row['session_id']]??=(new AF_ACS_SessionService($repo,$access))->view((string)$row['session_id']);$row['detailed']=$scope['can_manage'];
            if((string)$row['native_visible']!=='1' && (string)$row['author_uid']!==$uid && !(bool)$row['detailed'])continue;
            foreach(['pid','tid','turn_id','author_uid','session_id','state_version'] as $k)$row[$k]=(string)$row[$k];
            $allowed[$row['submission_id']]=$row;$needDetails=$needDetails||(bool)$row['detailed'];
        }
        if(!$allowed)return [];
        $records=$this->terminalRecords(array_keys($allowed),$needDetails);$out=[];
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
                $statusRows=json_decode($records[$id]['card_statuses']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                $history=json_decode($records[$id]['card_boundaries']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                $prepared=json_decode($records[$id]['card_prepared']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                $statusMap=json_decode($records[$id]['card_status_map']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                $details=AF_ACS_CombatCardProjection::actions($summary['actions'],$actions,$statusRows,$prepared,$statusMap);
                $card['encounter_id']=(string)$row['encounter_id'];
                $card['round']=$row['card_round']===null?null:(string)$row['card_round'];
                $card['actors']=array_values(array_unique(array_column($details,'actor_name')));$card['ap']=0;
                foreach($details as $step)foreach($step['costs'] as $cost)if($cost['kind']==='ap')$card['ap']+=$cost['amount'];
                $card['boundaries']=[];
                foreach($history as $boundary){$trace=$boundary['result']['statuses']['trace']??[];$events=AF_ACS_CombatCardProjection::events($trace,$statusRows);if($events)$card['boundaries'][]=['phase'=>'start','events'=>$events];}
                $end=json_decode($records[$id]['card_end']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                foreach($end as $trace){$events=AF_ACS_CombatCardProjection::events($trace,$statusRows);if($events)$card['boundaries'][]=['phase'=>'end','events'=>$events];}
                $initial=json_decode($records[$id]['card_initial_effects']??'null',true,64,JSON_THROW_ON_ERROR)??[];
                $events=AF_ACS_CombatCardProjection::events($initial,$statusRows);if($events)$card['boundaries'][]=['phase'=>'initial','events'=>$events];
                $card['spawns']=array_map(static fn($s)=>array_intersect_key($s,array_flip(['entity_id','kind','source_event_id'])),json_decode($records[$id]['card_spawns']??'null',true,64,JSON_THROW_ON_ERROR)??[]);
                $card['details']=$details;$card['result_hash']=$receipt['result_hash'];
            }
            $out[$row['pid']]=$card;
        }
        // Names come only from hash-verified registration events, never current entities or cosmetic masks.
        $needed=[];foreach($out as $card)if($card['details']!==null)$needed[$card['session_id']]=array_values(array_unique([...($needed[$card['session_id']]??[]),...AF_ACS_CombatCardProjection::identities($card['details'])]));
        $labels=[];
        if($needed){
            $sessions=array_keys($needed);$entities=[];foreach($needed as $set)$entities=[...$entities,...$set];$entities=array_values(array_unique($entities));
            if($entities){
                $sql='SELECT e.session_id,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id WHERE e.event_type=? AND e.session_id IN ('.implode(',',array_fill(0,count($sessions),'?')).') AND JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,\'$.result.entity_id\')) IN ('.implode(',',array_fill(0,count($entities),'?')).')';
                foreach($this->sql($sql,['entity.register',...$sessions,...$entities])['rows'] as $record){
                    if(!hash_equals($record['content_hash'],hash('sha256',$record['payload_json']."\n".$record['visibility_json'])))throw new RuntimeException('ACS_CARD_RESULT_INVALID');
                    $registration=json_decode($record['payload_json'],true,64,JSON_THROW_ON_ERROR);$entity=(string)($registration['result']['entity_id']??'');$name=trim((string)($registration['request']['name']??''));
                    if($name!==''&&in_array($entity,$needed[(string)$record['session_id']]??[],true))$labels[(string)$record['session_id']][$entity]=$name;
                }
            }
        }
        foreach($out as &$card)if($card['details']!==null){
            $card['details']=AF_ACS_CombatCardProjection::labels($card['details'],$labels[$card['session_id']]??[]);
            $card['actors']=array_values(array_unique(array_column($card['details'],'actor_name')));
        }unset($card);
        return $out;
    }
}
