<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/ArchiveQuery.php';
require_once __DIR__.'/../Application/AudienceService.php';
require_once __DIR__.'/../../../advancedalertsandmentions/combat_delivery.php';

/** Recipient context only; never impersonates MyBB globals or grants global ACP rights. */
final class AF_ACS_NotificationAccess extends AF_ACS_SessionAccess
{
    public function __construct(private string $recipient){}
    public function uid():string
    {if(!function_exists('user_permissions')||empty(user_permissions((int)$this->recipient)['canview']))throw new RuntimeException('ACS_ALERT_ACCESS_NOT_VERIFIED');return $this->recipient;}
    public function administrator():bool{return false;}
}

/** Existing ACS outbox, source proofs and permission services; no notification/mechanics store. */
final class AF_ACS_NotificationOutbox extends AF_ACS_Repository implements AF_AAM_DeliveryAuthority
{
    private AF_ACS_EncounterRepository $repo;
    private array $controls=[],$memberCache=[],$roleCache=[],$delegationCache=[],$audiences=[];
    public function __construct(object $database){parent::__construct($database);$this->repo=new AF_ACS_EncounterRepository($database);}
    private function put(string $key,string $kind,array $body,string $status='pending'):void
    {
        $json=self::json($body);$hash=hash('sha256',$json);
        $this->sql('INSERT INTO '.$this->table('outbox').' (message_key,submission_id,kind,payload_hash,payload_json,status,attempts,available_at,created_at) VALUES (?,?,?,?,?,?,0,?,?) ON DUPLICATE KEY UPDATE message_key=message_key',[$key,$key,$kind,$hash,$json,$status,time(),time()]);
        $old=$this->sql('SELECT payload_hash FROM '.$this->table('outbox').' WHERE message_key=?',[$key])['rows'][0];
        if(!hash_equals($old['payload_hash'],$hash))throw new RuntimeException('ACS_ALERT_KEY_CONFLICT');
    }
    private function members(string $sid):array
    {
        if(isset($this->memberCache[$sid]))return $this->memberCache[$sid];
        $members=$this->repo->members($sid);if(count($members)>200)throw new RuntimeException('ACS_ALERT_RECIPIENT_LIMIT');$out=[];
        foreach($members as $r)if($r['expires_at']===null||(int)$r['expires_at']>time())$out[$r['uid']]=$r['uid'];return $this->memberCache[$sid]=array_values($out);
    }
    private function controllers(string $sid,string $entity):array
    {
        $key=$sid.':'.$entity;if(isset($this->controls[$key]))return $this->controls[$key];$out=[];
        $e=$this->repo->entity($sid,$entity);if(!$e)return [];
        $rows=$this->repo->controllers($sid,$entity);$session=$this->repo->session($sid);$context=$this->repo->controlContext($sid,$e);
        foreach($this->members($sid) as $uid){$k=$sid.':'.$uid;$roles=$this->roleCache[$k]??=$this->repo->roles($sid,$uid);$delegations=$this->delegationCache[$k]??=$this->repo->delegationRows($sid,$uid);
            if(AF_ACS_AuthorizationService::controller($e,$rows,$session,$roles,$uid,$delegations,'ordinary',$context))$out[]=$uid;
        }
        return $this->controls[$key]=$out;
    }
    private function queue(string $sid,string $identity,string $uid,string $type,array $guard=[]):void
    {
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        $m=['operation_key'=>'acs:alert:'.hash('sha256',$identity.':'.$uid.':'.$type),'recipient_uid'=>$uid,'event_identity'=>$identity,'type'=>$type,'payload'=>['session_id'=>$sid,'tid'=>$s['tid'],'gm_only'=>$type==='acs_gm'&&empty($guard['important'])]];
        $m['payload_hash']=hash('sha256',AF_AAM_CombatDelivery::json($m));
        // No hidden actor/target/source/count/name reaches AAM; guards stay in ACS only.
        $this->put($m['operation_key'],'alerts.delivery',['session_id'=>$sid,'message'=>$m,'guard'=>$guard]);
    }
    private function gmRecipients(string $sid):array
    {
        $s=$this->repo->session($sid);return array_values(array_filter($this->members($sid),fn($uid)=>AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$uid),$uid)));
    }
    private function important(string $sid,string $identity,string $type,?string $eid=null):void
    {
        // Important session facts go to account owners and current GMs, never one pet/account row.
        $rows=$eid===null?$this->sql('SELECT DISTINCT owner_uid FROM '.$this->table('entities').' WHERE session_id=? AND owner_uid IS NOT NULL LIMIT 201',[$sid])['rows']:$this->sql('SELECT DISTINCT e.owner_uid FROM '.$this->table('entities').' e JOIN '.$this->table('participants').' p ON p.entity_id=e.id WHERE e.session_id=? AND p.encounter_id=? AND e.owner_uid IS NOT NULL LIMIT 201',[$sid,$eid])['rows'];
        if(count($rows)>200)throw new RuntimeException('ACS_ALERT_RECIPIENT_LIMIT');
        foreach(array_unique([...array_map('strval',array_column($rows,'owner_uid')),...$this->gmRecipients($sid)]) as $uid)$this->queue($sid,$identity,$uid,$type,['important'=>true]);
    }
    private function eventSource(string $sid,array $source):void
    {
        $id=self::integer($source['event_id']??null);
        $e=$this->sql('SELECT e.*,d.payload_json,d.visibility_json,d.content_hash,o.status AS operation_status FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id JOIN '.$this->table('operations').' o ON o.operation_key=e.operation_key WHERE e.session_id=? AND e.id=?',[$sid,$id])['rows'][0]??throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');
        $body=(new AF_ACS_ArchiveQuery($this->connection))->verify($e);$identity='acs:event:'.$id;$kind=$e['event_type'];
        if($kind==='combat.resolved'){
            $recipients=[];$actions=$body['plan']['actions']??[];if(count($actions)>100)throw new RuntimeException('ACS_ALERT_SOURCE_LIMIT');
            foreach($actions as $a){$actor=(string)$a['actor_id'];foreach($this->controllers($sid,$actor) as $uid)$recipients[$uid]=['entity_id'=>$actor];
                foreach($a['targets']??[] as $target)foreach($this->controllers($sid,(string)$target) as $uid){
                    $audience=$this->audiences[$sid.':'.$e['encounter_id'].':'.$uid]??=new AF_ACS_AudienceService($this->repo,new AF_ACS_NotificationAccess($uid),$sid,(string)$e['encounter_id']);
                    if($audience->knowledge('entity',$actor)['level']==='identified'&&$audience->canTarget($actor))$recipients[$uid]??=['entity_id'=>(string)$target,'source_entity_id'=>$actor,'encounter_id'=>(string)$e['encounter_id']];
                }
                foreach($a['reactions']??[] as $reaction)if(!empty($reaction['executed'])&&isset($reaction['entity_id']))foreach($this->controllers($sid,(string)$reaction['entity_id']) as $uid)$recipients[$uid]??=['entity_id'=>(string)$reaction['entity_id']];
            }
            foreach($recipients as $uid=>$guard)$this->queue($sid,$identity,(string)$uid,'acs_combat',$guard);return;
        }
        if($kind==='encounter.outcome_confirm'){$outcome=$this->repo->confirmedOutcome($sid,(string)$e['encounter_id'])??throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');if($outcome['terminal_event_id']!==$id)throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');$this->important($sid,$identity,'acs_completed',(string)$e['encounter_id']);return;}
        if(in_array($kind,['encounter.gm_skip','encounter.gm_forfeit','encounter.deadline_process'],true)&&isset($body['result']['turn_id'])&&in_array($body['result']['status']??null,['passed','forfeited'],true)){
            $t=$this->repo->clockTurn($sid,(string)$body['result']['turn_id']);$rows=$this->sql('SELECT DISTINCT e.owner_uid FROM '.$this->table('entities').' e WHERE e.session_id=? AND e.control_group_id=? AND e.owner_uid IS NOT NULL LIMIT 201',[$sid,$t['control_group_id']])['rows'];
            if(count($rows)>200)throw new RuntimeException('ACS_ALERT_RECIPIENT_LIMIT');$uids=array_map('strval',array_column($rows,'owner_uid'));
            foreach($this->sql('SELECT entity_id FROM '.$this->table('entity_turns').' WHERE turn_id=? LIMIT 101',[$t['id']])['rows'] as $entity)$uids=[...$uids,...$this->controllers($sid,(string)$entity['entity_id'])];
            foreach(array_unique($uids) as $uid)$this->queue($sid,$identity,$uid,'acs_missed');return;
        }
        if(in_array($kind,['encounter.pause','encounter.resume','encounter.deadline_set','encounter.extend_deadline','session.close','session.archive','session.reopen'],true)){$this->important($sid,$identity,'acs_gm',$e['encounter_id']===null?null:(string)$e['encounter_id']);return;}
        // GM-only command metadata is never broadcast to players/observers.
        foreach($this->gmRecipients($sid) as $uid)$this->queue($sid,$identity,$uid,'acs_gm');
    }
    public function seed(string $sid):void
    {
        $sid=self::integer($sid);$this->controls=[];$this->memberCache=[];$this->roleCache=[];$this->delegationCache=[];$this->audiences=[];
        $this->transactions->run(function()use($sid){
            $this->writable();$this->lockSession($sid);
            $sources=$this->sql('SELECT * FROM '.$this->table('outbox')." WHERE kind='alerts.source' AND status='pending' AND available_at<=UNIX_TIMESTAMP() AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.session_id'))=? ORDER BY created_at,message_key LIMIT 20",[$sid])['rows'];
            foreach($sources as $source){
                if(!hash_equals($source['payload_hash'],hash('sha256',$source['payload_json'])))throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');$p=json_decode($source['payload_json'],true,32,JSON_THROW_ON_ERROR);
                if(isset($p['event_id']))$this->eventSource($sid,$p);
                elseif(isset($p['reward_id'])){
                    $r=$this->repo->rewardIntentEvidence($sid,$p['reward_id']);if($r['status']!=='issued'||$r['operation']['status']!=='committed')throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');
                    $receipt=json_decode($r['operation']['result_json'],true,64,JSON_THROW_ON_ERROR)['owner_receipt']??[];AF_ACS_RewardDistribution::receipt($r['intent'],$r['authority'],$receipt);
                    $this->queue($sid,'acs:reward:'.$p['reward_id'],(string)$r['payload']['recipient']['uid'],'acs_reward');
                }elseif(isset($p['loot_entry_id'],$p['encounter_id'])){
                    $f=$this->repo->lootIssueIntent($sid,$p['encounter_id'],$p['loot_entry_id']);
                    if($f['entry']['status']!=='issued'||$f['operation']['status']!=='committed')throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');
                    $receipt=json_decode($f['operation']['result_json'],true,64,JSON_THROW_ON_ERROR)['owner_receipt']??[];AF_ACS_LootIssue::receipt($f['intent'],$receipt);
                    $this->queue($sid,'acs:loot:'.$p['loot_entry_id'].':'.$f['entry']['allocation_version'],(string)$f['entry']['recipient_uid'],'acs_reward');
                }else throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');
                $this->sql('UPDATE '.$this->table('outbox')." SET status='delivered' WHERE message_key=?",[$source['message_key']]);
            }
            // Timers inspect durable state only. No timeout/Clock boundary is executed here.
            $turns=$this->sql('SELECT t.id,t.deadline_at,t.control_group_id,r.id AS round_id,e.id AS encounter_id FROM '.$this->table('turns').' t JOIN '.$this->table('rounds').' r ON r.id=t.round_id JOIN '.$this->table('encounters')." e ON e.id=r.encounter_id WHERE e.session_id=? AND e.status='active' AND r.status='open' AND r.round_number=e.round_number AND t.status='pending' AND EXISTS (SELECT 1 FROM ".$this->table('entity_turns')." et WHERE et.turn_id=t.id AND et.start_done=1 AND et.end_done=0) ORDER BY t.id LIMIT 101",[$sid])['rows'];
            if(count($turns)>100)throw new RuntimeException('ACS_ALERT_SOURCE_LIMIT');
            foreach($turns as $turn){$entities=$this->sql('SELECT entity_id FROM '.$this->table('entity_turns').' WHERE turn_id=? AND start_done=1 AND end_done=0 LIMIT 101',[$turn['id']])['rows'];if(count($entities)>100)throw new RuntimeException('ACS_ALERT_SOURCE_LIMIT');
                $recipients=[];foreach($entities as $entity)foreach($this->controllers($sid,(string)$entity['entity_id']) as $uid)$recipients[$uid]=(string)$entity['entity_id'];
                foreach($recipients as $uid=>$entity){$guard=['control_group_id'=>(string)$turn['control_group_id'],'turn_id'=>(string)$turn['id']];$this->queue($sid,'acs:turn:'.$turn['id'],(string)$uid,'acs_turn',$guard);$this->queue($sid,'acs:round:'.$turn['round_id'],(string)$uid,'acs_round');
                    if($turn['deadline_at']!==null&&(int)$turn['deadline_at']>time()&&(int)$turn['deadline_at']<=time()+300)$this->queue($sid,'acs:deadline:'.$turn['id'].':'.$turn['deadline_at'],(string)$uid,'acs_deadline',$guard+['deadline_at'=>(string)$turn['deadline_at']]);
                }
            }
            $pending=$this->sql('SELECT submission_id,author_uid FROM '.$this->table('submissions')." WHERE session_id=? AND status='recovery_required' ORDER BY submission_id LIMIT 101",[$sid])['rows'];if(count($pending)>100)throw new RuntimeException('ACS_ALERT_SOURCE_LIMIT');
            foreach($pending as $row){
                $owners=$this->sql('SELECT DISTINCT g.owner_uid FROM '.$this->table('submissions').' s JOIN '.$this->table('turns').' t ON t.id=s.turn_id JOIN '.$this->table('control_groups').' g ON g.id=t.control_group_id WHERE s.session_id=? AND s.submission_id=? AND g.owner_uid IS NOT NULL',[$sid,$row['submission_id']])['rows'];
                foreach(array_unique([$row['author_uid'],...array_map('strval',array_column($owners,'owner_uid')),...$this->gmRecipients($sid)]) as $uid)$this->queue($sid,'acs:publication:'.$row['submission_id'],(string)$uid,'acs_publication');
            }
        });
    }
    public function authorize(array $m):bool
    {
        $row=$this->sql('SELECT payload_hash,payload_json FROM '.$this->table('outbox')." WHERE message_key=? AND kind='alerts.delivery'",[$m['operation_key']])['rows'][0]??throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');
        if(!hash_equals($row['payload_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');$saved=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR);
        if(AF_AAM_CombatDelivery::json($saved['message'])!==AF_AAM_CombatDelivery::json($m))throw new RuntimeException('ACS_ALERT_KEY_CONFLICT');
        $sid=$m['payload']['session_id'];$uid=$m['recipient_uid'];$s=$this->repo->session($sid);
        if(!$s||$s['tid']!==$m['payload']['tid']||!$this->repo->roles($sid,$uid)||!get_user((int)$uid))return false;
        try{(new AF_ACS_NotificationAccess($uid))->thread($s['tid'],$uid);}catch(Throwable){throw new RuntimeException('ACS_ALERT_ACCESS_NOT_VERIFIED');}
        $g=$saved['guard'];if(isset($g['entity_id'])&&!$this->repo->controllerFor($sid,$g['entity_id'],$uid))return false;
        if(isset($g['turn_id'])){$t=$this->repo->clockTurn($sid,$g['turn_id']);$e=$t?$this->repo->encounter($sid,$t['encounter_id']):null;if(!$t||!$e||$t['status']!=='pending'||$e['status']!=='active'||$t['current_round']!==$t['round_number'])return false;if(isset($g['control_group_id'])){
            $entities=$this->sql('SELECT entity_id FROM '.$this->table('entity_turns').' WHERE turn_id=? AND start_done=1 AND end_done=0 LIMIT 101',[$g['turn_id']])['rows'];$controlled=false;
            if(count($entities)>100)throw new RuntimeException('ACS_ALERT_SOURCE_LIMIT');foreach($entities as $entity)if($this->repo->controllerFor($sid,(string)$entity['entity_id'],$uid)){$controlled=true;break;}if(!$controlled)return false;
        }if(isset($g['deadline_at'])&&((string)$t['deadline_at']!==$g['deadline_at']||(int)$t['deadline_at']<=time()))return false;}
        if(isset($g['source_entity_id'])){$a=new AF_ACS_AudienceService($this->repo,new AF_ACS_NotificationAccess($uid),$sid,$g['encounter_id']);if($a->knowledge('entity',$g['source_entity_id'])['level']!=='identified'||!$a->canTarget($g['source_entity_id']))return false;}
        if($m['type']==='acs_gm'&&empty($g['important'])&&!AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$uid),$uid))return false;
        return true;
    }
    public function pending(string $sid):array
    {
        return $this->sql('SELECT * FROM '.$this->table('outbox')." WHERE kind='alerts.delivery' AND status IN ('pending','recovery_required') AND available_at<=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.session_id'))=? ORDER BY available_at,message_key LIMIT 20",[time(),$sid])['rows'];
    }
    public function acknowledge(string $sid,array $m,array $receipt):void
    {
        foreach(['operation_key','payload_hash','recipient_uid','event_identity','type'] as $k)if(($receipt[$k]??null)!==$m[$k])throw new RuntimeException('ACS_ALERT_RECEIPT_INVALID');
        if(!in_array($receipt['status']??null,['delivered','skipped'],true))throw new RuntimeException('ACS_ALERT_RECEIPT_INVALID');
        $this->transactions->run(function()use($sid,$m,$receipt){$this->writable();$this->lockSession($sid);$this->put($m['operation_key'].':ack','alerts.ack',$receipt,'delivered');$this->sql('UPDATE '.$this->table('outbox')." SET status='delivered',attempts=attempts+1 WHERE message_key=?",[$m['operation_key']]);});
    }
    public function defer(string $key,int $attempts=0):void
    {
        $this->sql('UPDATE '.$this->table('outbox')." SET status='recovery_required',attempts=LEAST(attempts+1,1000000),available_at=? WHERE message_key=? AND status<>'delivered'",[time()+min(3600,60*(2**min(6,max(0,$attempts)))),$key]);
    }
    public function deferSources(string $sid):void
    {
        $this->sql('UPDATE '.$this->table('outbox')." SET available_at=?,attempts=LEAST(attempts+1,1000000) WHERE kind='alerts.source' AND status='pending' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.session_id'))=? ORDER BY created_at,message_key LIMIT 20",[time()+300,$sid]);
    }
    public function boundary():void{$this->transactions->assertExternalBoundary();}
    public function schedule():array
    {
        $old=$this->sql('SELECT payload_json,payload_hash FROM '.$this->table('outbox')." WHERE message_key='alerts:scheduler'")['rows'][0]??null;if($old&&!hash_equals($old['payload_hash'],hash('sha256',$old['payload_json'])))throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');$after=$old?self::integer(json_decode($old['payload_json'],true,8,JSON_THROW_ON_ERROR)['after'],zero:true):'0';
        $rows=$this->sql('SELECT s.id FROM '.$this->table('sessions')." s WHERE s.id>? AND (s.status='active' OR EXISTS (SELECT 1 FROM ".$this->table('outbox')." o WHERE o.kind IN ('alerts.source','alerts.delivery') AND o.status IN ('pending','recovery_required') AND JSON_UNQUOTE(JSON_EXTRACT(o.payload_json,'$.session_id'))=CAST(s.id AS CHAR)) OR EXISTS (SELECT 1 FROM ".$this->table('submissions')." sub WHERE sub.session_id=s.id AND sub.status='recovery_required')) ORDER BY s.id LIMIT 21",[$after])['rows'];
        return ['ids'=>array_map('strval',array_column(array_slice($rows,0,20),'id')),'after'=>count($rows)>20?(string)$rows[19]['id']:'0'];
    }
    public function advance(string $after):void
    {
        $body=self::json(['after'=>$after]);$this->sql('INSERT INTO '.$this->table('outbox')." (message_key,submission_id,kind,payload_hash,payload_json,status,attempts,available_at,created_at) VALUES ('alerts:scheduler','alerts:scheduler','alerts.scheduler',?,?,'delivered',0,?,?) ON DUPLICATE KEY UPDATE payload_hash=VALUES(payload_hash),payload_json=VALUES(payload_json)",[hash('sha256',$body),$body,time(),time()]);
    }
}
