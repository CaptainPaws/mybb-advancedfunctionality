<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/ArchiveQuery.php';
require_once __DIR__.'/../Domain/Statistics.php';

/** Writes are post-completion maintenance, never part of the combat transaction or profile GET. */
final class AF_ACS_StatisticsRepository extends AF_ACS_Repository
{
    public function refresh(string $sid,string $eid,string $visibility):array
    {
        if(!in_array($visibility,['private','participants','public'],true))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
        return $this->transactions->run(function()use($sid,$eid,$visibility){
            $this->writable();$repo=new AF_ACS_EncounterRepository($this->connection);$query=new AF_ACS_ArchiveQuery($this->connection);
            $this->lockSession($sid);$e=$repo->encounter($sid,$eid,true);
            if(!$e||$e['status']!=='completed'||!($outcome=$repo->confirmedOutcome($sid,$eid)))throw new RuntimeException('ACS_STATISTICS_COMPLETED_REQUIRED');
            $source=$this->sql('SELECT MAX(session_sequence) AS n FROM '.$this->table('events')." WHERE session_id=? AND encounter_id=? AND event_type IN ('encounter.outcome_confirm','correction.review')",[$sid,$eid])['rows'][0]['n'];
            $source=self::integer((string)$source,'9007199254740990');
            $old=$this->sql('SELECT aggregate_version,visibility FROM '.$this->table('statistics').' WHERE encounter_id=? LIMIT 1',[$eid])['rows'][0]??null;
            if($old&&$old['visibility']!==$visibility)throw new RuntimeException('ACS_STATISTICS_VISIBILITY_CONFLICT');
            if($old&&(string)$old['aggregate_version']===(string)$source)return ['aggregate_version'=>(string)$source,'operation_key'=>'statistics:'.$eid.':'.$source];
            $identities=[];
            foreach($this->sql('SELECT e.id,e.kind,e.owner_uid,e.control_group_id,g.owner_uid AS group_owner_uid FROM '.$this->table('entities').' e JOIN '.$this->table('participants').' p ON p.entity_id=e.id LEFT JOIN '.$this->table('control_groups').' g ON g.id=e.control_group_id WHERE e.session_id=? AND p.encounter_id=? ORDER BY e.id LIMIT 1001',[$sid,$eid])['rows'] as $id)$identities[(string)$id['id']]=$id;
            if(count($identities)>1000)throw new RuntimeException('ACS_STATISTICS_LIMIT');
            $stats=new AF_ACS_Statistics;$last=['0','0','0'];$upper=$query->horizon($sid,$eid);$count=0;$secrecySafe=true;
            $outcomeReview=$repo->latestCorrection($sid,$eid,$outcome['terminal_event_id']);
            $include=($outcomeReview['disposition']??'include')==='include';
            do{
                $rows=$query->rows($sid,$eid,$upper,$last,true,AF_ACS_Archive::filters(['event_type'=>'combat.resolved']));
                foreach($rows as $row){
                    if(++$count>10000)throw new RuntimeException('ACS_STATISTICS_LIMIT');
                    $body=$query->verify($row);
                    foreach(['initial','final'] as $state)foreach($body[$state]['perception_subjects']??[] as $subject)if(($subject['perception']['stealth']??null)!==null)$secrecySafe=false;
                    foreach($body['final']['status_instances']??[] as $status)if(isset($status['spec']['mechanical_parameters']['stealth']))$secrecySafe=false;
                    $review=$repo->latestCorrection($sid,$eid,(string)$row['id']);
                    if($include&&($review['disposition']??'include')==='include')$stats->combat($body);else $stats->excludeCombat($body);
                    $last=[(string)$row['archive_sequence'],(string)$row['session_sequence'],(string)$row['id']];
                }
            }while(count($rows)===100);
            if($include){
                // Committed Clock status ticks have their own durable operation receipts. The same
                // boundary can also appear in a combat receipt; the reducer counts its key once.
                $clocks=$this->sql('SELECT request_json,payload_hash,result_json FROM '.$this->table('operations')." WHERE session_id=? AND encounter_id=? AND kind IN ('clock.status_tick','clock.entity_start','clock.entity_end','clock.ap_refresh') AND status='committed' ORDER BY operation_key LIMIT 10001",[$sid,$eid])['rows'];
                if(count($clocks)>10000)throw new RuntimeException('ACS_STATISTICS_LIMIT');
                $statuses=$clocks?$repo->statusProjection($sid)['instances']:[];
                foreach($clocks as $clock){
                    $r=json_decode($clock['result_json'],true,64,JSON_THROW_ON_ERROR);$trace=$r['statuses']['trace']??$r['trace']??null;if(!is_array($trace))continue;
                    if(!hash_equals($clock['payload_hash'],hash('sha256',$clock['request_json'])))throw new RuntimeException('ACS_STATISTICS_INTEGRITY');
                    $stats->clock($trace,$statuses);
                }
                // Start causal seals are excluded. Only the committed start command contains
                // mechanically confirmed child plans (ordinary prepared children live in combat).
                $starts=$this->sql('SELECT e.*,d.payload_json,d.visibility_json,d.content_hash,o.status AS operation_status FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id JOIN '.$this->table('operations')." o ON o.operation_key=e.operation_key WHERE e.session_id=? AND e.encounter_id=? AND e.event_type='encounter.ambush_start' LIMIT 2",[$sid,$eid])['rows'];
                if(count($starts)>1)throw new RuntimeException('ACS_STATISTICS_INTEGRITY');
                foreach($starts as $start){$body=$query->verify($start);foreach($body['result']['prepared_executions']??[] as $execution)$stats->prepared($execution,'encounter.ambush_start');}
            }
            $preparations=$this->sql('SELECT p.* FROM '.$this->table('prepared_actions').' p WHERE p.session_id=? AND p.encounter_id=? ORDER BY p.id LIMIT 1001',[$sid,$eid])['rows'];
            if(count($preparations)>1000)throw new RuntimeException('ACS_STATISTICS_LIMIT');
            foreach($preparations as $p){
                $event=$this->sql('SELECT e.*,d.payload_json,d.visibility_json,d.content_hash,o.status AS operation_status FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id JOIN '.$this->table('operations')." o ON o.operation_key=e.operation_key WHERE e.id=? AND e.session_id=? AND e.encounter_id=? AND e.event_type='encounter.prepared_register'",[$p['source_event_id'],$sid,$eid])['rows'][0]??throw new RuntimeException('ACS_STATISTICS_INTEGRITY');
                $proof=$query->verify($event);
                if(($proof['result']['prepared_action_id']??null)!==(string)$p['id']||!hash_equals($p['contract_hash'],hash('sha256',$p['contract_json'])))throw new RuntimeException('ACS_STATISTICS_INTEGRITY');
                $contract=json_decode($p['contract_json'],true,64,JSON_THROW_ON_ERROR);if(isset($contract['hidden']))$secrecySafe=false;if($include)$stats->preparation((string)$p['entity_id'],$contract);
            }
            $subjects=$stats->subjects($identities);
            // Preserve a version marker even when correction excludes every mechanical contribution.
            $subjects[]=['subject_kind'=>'encounter','subject_key'=>$eid,'owner_uid'=>null,'entity_kind'=>'encounter','metrics'=>AF_ACS_Statistics::zero()];
            $payload=['schema_version'=>1,'session_id'=>$sid,'encounter_id'=>$eid,'aggregate_version'=>(string)$source,'source_sequence'=>(string)$source,'secrecy_safe'=>$secrecySafe,'subjects'=>$subjects];
            $key='statistics:'.$eid.':'.$source;$now=time();
            $this->sql('DELETE FROM '.$this->table('statistics').' WHERE encounter_id=?',[$eid]);
            foreach($subjects as $subject)$this->sql('INSERT INTO '.$this->table('statistics').' (encounter_id,subject_kind,subject_key,aggregate_version,visibility,source_sequence,payload_json) VALUES (?,?,?,?,?,?,?)',[$eid,$subject['subject_kind'],$subject['subject_key'],$source,$visibility,$source,self::json(['schema_version'=>1]+$subject)]);
            $envelope=self::json(['schema_version'=>1,'operation_key'=>$key,'encounter_id'=>$eid,'aggregate_version'=>(string)$source,'visibility'=>$visibility,'payload'=>$payload]);
            $this->sql('INSERT INTO '.$this->table('outbox').' (message_key,submission_id,kind,payload_hash,payload_json,status,available_at,created_at) VALUES (?,?,?,?,?,?,?,?)',[$key,$key,'statistics.aggregate',hash('sha256',$envelope),$envelope,'pending',$now,$now]);
            // Refresh pointers are superseded by this source version; later corrections remain pending.
            $this->sql('UPDATE '.$this->table('outbox')." SET status='delivered' WHERE kind='statistics.refresh' AND status='pending' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.encounter_id'))=? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.source_sequence')) AS UNSIGNED)<=?",[$eid,$source]);
            return ['aggregate_version'=>(string)$source,'operation_key'=>$key];
        });
    }
    public function pending(string $eid):array
    {return $this->sql('SELECT * FROM '.$this->table('outbox')." WHERE kind='statistics.aggregate' AND status='pending' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.encounter_id'))=? ORDER BY created_at,message_key LIMIT 20",[$eid])['rows'];}
    public function acknowledge(string $key,string $hash):void
    {$this->transactions->run(function()use($key,$hash){$this->writable();$this->sql('UPDATE '.$this->table('outbox')." SET status='delivered',attempts=attempts+1 WHERE message_key=? AND payload_hash=? AND kind='statistics.aggregate'",[$key,$hash]);});}
    public function receipt(string $key):?array
    {$r=$this->sql('SELECT receipt_json FROM '.$this->table('statistics_receipts').' WHERE operation_key=?',[$key])['rows'][0]??null;return $r?json_decode($r['receipt_json'],true,64,JSON_THROW_ON_ERROR):null;}
    /** IC-STATS owner implementation: authenticated against the durable producer envelope. */
    public function consume(string $key,string $eid,string $version,string $visibility,array $payload):array
    {
        return $this->transactions->run(function()use($key,$eid,$version,$visibility,$payload){
            $this->writable();$envelope=self::json(['schema_version'=>1,'operation_key'=>$key,'encounter_id'=>$eid,'aggregate_version'=>$version,'visibility'=>$visibility,'payload'=>$payload]);$hash=hash('sha256',$envelope);
            $source=$this->sql('SELECT payload_hash,payload_json FROM '.$this->table('outbox')." WHERE message_key=? AND kind='statistics.aggregate'",[$key])['rows'][0]??null;
            if(!$source||!hash_equals($source['payload_hash'],$hash)||$source['payload_json']!==$envelope)throw new RuntimeException('ACS_STATISTICS_OPERATION_CONFLICT');
            if($old=$this->receipt($key)){if(!hash_equals($old['payload_hash'],$hash))throw new RuntimeException('ACS_STATISTICS_OPERATION_CONFLICT');return $old;}
            $current=$this->sql('SELECT aggregate_version,payload_hash FROM '.$this->table('statistics_consumers').' WHERE encounter_id=? FOR UPDATE',[$eid])['rows'][0]??null;
            if($current&&(int)$current['aggregate_version']===(int)$version&&!hash_equals($current['payload_hash'],$hash))throw new RuntimeException('ACS_STATISTICS_OPERATION_CONFLICT');
            $stale=$current&&(int)$current['aggregate_version']>(int)$version;
            if(!$stale)$this->sql('INSERT INTO '.$this->table('statistics_consumers').' (encounter_id,aggregate_version,payload_hash,payload_json,visibility) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE aggregate_version=VALUES(aggregate_version),payload_hash=VALUES(payload_hash),payload_json=VALUES(payload_json),visibility=VALUES(visibility)',[$eid,$version,$hash,self::json($payload),$visibility]);
            $receipt=['schema_version'=>1,'operation_key'=>$key,'encounter_id'=>$eid,'aggregate_version'=>$version,'payload_hash'=>$hash,'status'=>$stale?'superseded':'accepted'];
            $this->sql('INSERT INTO '.$this->table('statistics_receipts').' (operation_key,encounter_id,aggregate_version,payload_hash,receipt_json) VALUES (?,?,?,?,?)',[$key,$eid,$version,$hash,self::json($receipt)]);return $receipt;
        });
    }
    public function profileAudience(string $eid,string $viewer):array
    {
        $rows=$this->sql('SELECT e.owner_uid,p.visibility_json FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id WHERE p.encounter_id=? LIMIT 1001',[$eid])['rows'];
        $participant=false;$safe=count($rows)<=1000;
        foreach($rows as $r){if((string)$r['owner_uid']===$viewer)$participant=true;$v=json_decode($r['visibility_json'],true,16,JSON_THROW_ON_ERROR);if(!in_array($v['scope']??null,['public','participants'],true))$safe=false;}
        return ['participant'=>$participant,'safe'=>$safe];
    }
    public function profileCandidates(string $uid,string $after):array
    {
        return $this->sql('SELECT DISTINCT c.encounter_id,c.aggregate_version,c.visibility,c.payload_hash,c.payload_json FROM '.$this->table('statistics_consumers').' c JOIN '.$this->table('statistics')." s ON s.encounter_id=c.encounter_id AND s.aggregate_version=c.aggregate_version WHERE s.subject_kind='entity' AND JSON_UNQUOTE(JSON_EXTRACT(s.payload_json,'$.owner_uid'))=? AND c.aggregate_version=(SELECT MAX(e.session_sequence) FROM ".$this->table('events')." e WHERE e.encounter_id=c.encounter_id AND e.event_type IN ('encounter.outcome_confirm','correction.review')) AND c.encounter_id>? ORDER BY c.encounter_id LIMIT 101",[$uid,$after])['rows'];
    }
}
