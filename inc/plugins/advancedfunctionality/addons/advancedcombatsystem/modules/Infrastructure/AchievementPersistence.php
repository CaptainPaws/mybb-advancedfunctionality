<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/AchievementSources.php';
trait AF_ACS_AchievementPersistence
{
    use AF_ACS_AchievementSources;
    public function achievementReplay(string $sid,string $eid,string $evaluation):?array
    {return $this->rewardCommandReceipt($sid,$eid,'achievement.evaluate','$.evaluation_key',$evaluation)['result']??null;}
    public function evaluateAchievement(array $s,string $eid,string $key,string $sourceId,callable $progressAccess):array
    {
        $this->commandWrite();$source=$this->achievementSource($s['id'],$eid,$sourceId);
        if(!is_array($source['facts'])||!is_string($source['config_snapshot_id']))throw new RuntimeException('ACS_ACHIEVEMENT_FROZEN_FACTS_REQUIRED');
        $pin=$this->achievementPin($s['id'],$source['config_snapshot_id'],$key);$d=$pin['definition'];$evaluation='achievement:'.$key.':source:'.$sourceId;$results=[];$rewards=[];
        if($d['timing']!==$source['timing'])throw new RuntimeException('ACS_ACHIEVEMENT_TIMING_INVALID');
        $review=$this->latestCorrection($s['id'],$eid,$sourceId);$included=($review['disposition']??'include')==='include';
        foreach(AF_ACS_Achievements::subjects($d,$source) as $subject){
            $old=$this->sql('SELECT id FROM '.$this->table('achievement_progress').' WHERE achievement_key=? AND subject_kind=? AND subject_key=? FOR UPDATE',[$key,$d['subject'],$subject['subject_key']])['rows'][0]??null;
            if($old){
                $progressAccess((string)$old['id']);$r=$this->achievementProgress((string)$old['id']);$p=$r['progress'];$known=false;foreach($p['pins'] as $knownPin)if($knownPin['hash']===$pin['hash'])$known=true;
                if(!$known)throw new RuntimeException('ACS_ACHIEVEMENT_DEFINITION_MIGRATION_REQUIRED');
                if($p['recipient_uid']!==$subject['recipient_uid'])throw new RuntimeException('ACS_ACHIEVEMENT_SUBJECT_MIGRATION_REQUIRED');
            }else{
                $p=['schema_version'=>1,'count'=>0,'review_count'=>0,'awards'=>0,'review_version'=>0,'review_required'=>false,'recipient_uid'=>$subject['recipient_uid'],'pins'=>[['session_id'=>$s['id'],'snapshot_id'=>$pin['snapshot_id'],'hash'=>$pin['hash'],'semantics'=>$pin['semantics']]]];
                $json=self::json($p);$id=$this->sql('INSERT INTO '.$this->table('achievement_progress').' (achievement_key,definition_snapshot_id,subject_kind,subject_key,progress_version,visibility,progress_json,progress_hash) VALUES (?,?,?,?,1,?,?,?)',[$key,$pin['snapshot_id'],$d['subject'],$subject['subject_key'],$d['visibility'],$json,hash('sha256',$json)])['id'];$r=$this->achievementProgress($id);
            }
            $before=$p;$p=AF_ACS_Achievements::next($d,$p,$included);if($p['awards']-$before['awards']>128)throw new RuntimeException('ACS_ACHIEVEMENT_PROGRESS_LIMIT');$json=self::json($p);
            $this->sql('UPDATE '.$this->table('achievement_progress').' SET progress_json=?,progress_hash=?,progress_version=progress_version+1 WHERE id=? AND progress_version=?',[$json,hash('sha256',$json),$r['id'],$r['progress_version']]);
            $body=['schema_version'=>1,'evaluation_key'=>$evaluation,'achievement_key'=>$key,'subject_kind'=>$d['subject'],'subject'=>$subject,'source_hash'=>$source['source_hash'],'review_id'=>$review['review_id']??null,'included'=>$included,'definition_hash'=>$pin['hash'],'before'=>$before,'after'=>$p];$json=self::json($body);
            $id=$this->sql('INSERT INTO '.$this->table('achievement_events').' (progress_id,session_id,encounter_id,source_event_id,definition_snapshot_id,progress_key,event_id,result_json,result_hash) VALUES (?,?,?,?,?,?,NULL,?,?)',[$r['id'],$s['id'],$eid,$sourceId,$pin['snapshot_id'],'achievement:'.hash('sha256',$key.':'.$d['subject'].':'.$subject['subject_key'].':'.$sourceId),$json,hash('sha256',$json)])['id'];
            if($d['reward']!==null)for($ordinal=$before['awards']+1;$ordinal<=$p['awards'];$ordinal++)$rewards[]=AF_ACS_RewardService::recordAchievementObligation($this,$s,$id,$ordinal);
            $results[]=['contribution_id'=>$id,'result'=>$body];
        }
        $s['_command_result']=['encounter_id'=>$eid,'source_event_id'=>$sourceId,'evaluation_key'=>$evaluation,'contributions'=>$results,'reward_ids'=>$rewards];return $s;
    }
    public function linkAchievementEvent(array $s,string $event):array
    {
        $this->transactions->assertInternalBoundary();
        $this->sql('UPDATE '.$this->table('events').' SET cause_event_id=? WHERE id=? AND cause_event_id IS NULL',[$s['_command_result']['source_event_id'],$event]);
        foreach($s['_command_result']['contributions'] as $c)$this->sql('UPDATE '.$this->table('achievement_events').' SET event_id=? WHERE id=? AND event_id IS NULL',[$event,$c['contribution_id']]);return $s;
    }
    public function migrateAchievement(array $s,string $eid,string $key,string $id,string $expected):array
    {
        $this->commandWrite();$e=$this->encounter($s['id'],$eid)??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');$pin=$this->achievementPin($s['id'],$e['config_snapshot_id'],$key);$r=$this->achievementProgress($id);$p=$r['progress'];
        if($r['progress_version']!==$expected||$r['achievement_key']!==$key||$r['subject_kind']!==$pin['definition']['subject'])throw new RuntimeException('ACS_VERSION_CONFLICT');
        $related=$this->sql('SELECT id FROM '.$this->table('achievement_events').' WHERE progress_id=? AND session_id=? LIMIT 1',[$id,$s['id']])['rows'];
        if(!$related)throw new RuntimeException('ACS_ACHIEVEMENT_UNAVAILABLE');
        if($pin['definition']['identity_policy']!=='compatible_carry'||count($p['pins'])>=16)throw new RuntimeException('ACS_ACHIEVEMENT_DEFINITION_MIGRATION_REQUIRED');
        foreach($p['pins'] as $old){if($old['semantics']!==$pin['semantics'])throw new RuntimeException('ACS_ACHIEVEMENT_INCOMPATIBLE_DEFINITION');if($old['hash']===$pin['hash'])throw new RuntimeException('ACS_ACHIEVEMENT_ALREADY_PINNED');}
        $p['pins'][]=['session_id'=>$s['id'],'snapshot_id'=>$pin['snapshot_id'],'hash'=>$pin['hash'],'semantics'=>$pin['semantics']];$json=self::json($p);
        $this->sql('UPDATE '.$this->table('achievement_progress').' SET definition_snapshot_id=?,progress_version=progress_version+1,progress_json=?,progress_hash=? WHERE id=? AND progress_version=?',[$pin['snapshot_id'],$json,hash('sha256',$json),$id,$expected]);
        $s['_command_result']=['encounter_id'=>$eid,'progress_id'=>$id,'previous_progress_version'=>$expected,'definition_snapshot_id'=>$pin['snapshot_id'],'awards_preserved'=>$p['awards']];return $s;
    }
    public function acknowledgeAchievementReview(array $s,string $eid,string $id,string $version):array
    {
        $this->commandWrite();$r=$this->achievementProgress($id);$p=$r['progress'];
        if(!$this->sql('SELECT id FROM '.$this->table('achievement_events').' WHERE session_id=? AND encounter_id=? AND progress_id=? LIMIT 1',[$s['id'],$eid,$id])['rows'])throw new RuntimeException('ACS_ACHIEVEMENT_UNAVAILABLE');
        if($r['progress_version']!==$version||!$p['review_required'])throw new RuntimeException('ACS_VERSION_CONFLICT');
        $p['review_required']=false;$json=self::json($p);$this->sql('UPDATE '.$this->table('achievement_progress').' SET progress_json=?,progress_hash=?,progress_version=progress_version+1 WHERE id=? AND progress_version=?',[$json,hash('sha256',$json),$id,$version]);
        $s['_command_result']=['encounter_id'=>$eid,'progress_id'=>$id,'reviewed_count'=>$p['review_count'],'historical_awards_retained'=>$p['awards'],'no_owner_mutation'=>true];return $s;
    }
    /** Bounded durable source queue, usable after close/archive without reactivating combat. */
    public function achievementObligations(string $sid):array
    {
        $out=[];$encounters=$this->sql('SELECT id,config_json FROM '.$this->table('encounters')." WHERE session_id=? AND JSON_LENGTH(JSON_EXTRACT(config_json,'$.achievement_sources'))>0 LIMIT 201",[$sid])['rows'];
        if(count($encounters)>200)return [['state'=>'explicitly_blocked','reference'=>'source_scan_limit','scope'=>null]];
        foreach($encounters as $e){
            $config=json_decode($e['config_json'],true,64,JSON_THROW_ON_ERROR);$events=$this->sql('SELECT id,event_type FROM '.$this->table('events')." WHERE session_id=? AND encounter_id=? AND event_type IN ('combat.resolved','encounter.outcome_confirm') ORDER BY id LIMIT 201",[$sid,$e['id']])['rows'];
            if(count($events)>200)return [['state'=>'explicitly_blocked','reference'=>'source_scan_limit','scope'=>null]];
            foreach($config['achievement_sources'] as $key=>$ref){$pin=$this->achievementDefinitionPin($sid,$ref['snapshot_id'],$ref['hash']);$timing=$pin['definition']['timing'];
                foreach($events as $event){if(($event['event_type']==='combat.resolved')!==($timing==='on_confirmed_event'))continue;$identity='achievement:'.$key.':source:'.$event['id'];
                    if(!$this->achievementReplay($sid,(string)$e['id'],$identity))$out[]=['state'=>'pending','reference'=>$identity,'scope'=>'achievement.evaluate'];
                    if(count($out)>200)return [['state'=>'explicitly_blocked','reference'=>'source_scan_limit','scope'=>null]];
                }
            }
        }return $out;
    }
    /** SQL visibility filter precedes pagination/count; no hidden totals or source refs in player DTO. */
    public function achievementViewRows(string $sid,string $uid,bool $gm,string $after):array
    {
        return $this->sql('SELECT p.id FROM '.$this->table('achievement_progress').' p WHERE p.id>? AND EXISTS (SELECT 1 FROM '.$this->table('achievement_events').' a WHERE a.progress_id=p.id AND a.session_id=?)'.($gm?'':" AND JSON_UNQUOTE(JSON_EXTRACT(p.progress_json,'$.recipient_uid'))=? AND (p.visibility='visible' OR (p.visibility='hidden_until_unlocked' AND JSON_EXTRACT(p.progress_json,'$.awards')>0))").' ORDER BY p.id LIMIT 100',$gm?[$after,$sid]:[$after,$sid,$uid])['rows'];
    }
    public function achievementSourceProgressIds(string $sid,string $eid,string $source,?string $key=null):array
    {
        $rows=$this->sql('SELECT DISTINCT a.progress_id FROM '.$this->table('achievement_events').' a JOIN '.$this->table('achievement_progress').' p ON p.id=a.progress_id WHERE a.session_id=? AND a.encounter_id=? AND a.source_event_id=?'.($key===null?'':' AND p.achievement_key=?').' LIMIT 201',$key===null?[$sid,$eid,$source]:[$sid,$eid,$source,$key])['rows'];
        if(count($rows)>200)throw new RuntimeException('ACS_ACHIEVEMENT_SUBJECT_LIMIT');return array_map(fn($r)=>(string)$r['progress_id'],$rows);
    }
    public function achievementSessions(string $id):array
    {return $this->sql('SELECT DISTINCT session_id FROM '.$this->table('achievement_events').' WHERE progress_id=? LIMIT 201',[$id])['rows'];}
}
