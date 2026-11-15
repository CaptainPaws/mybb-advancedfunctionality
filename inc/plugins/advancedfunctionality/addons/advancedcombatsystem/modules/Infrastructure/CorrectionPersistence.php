<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Domain/Achievements.php';
trait AF_ACS_CorrectionPersistence
{
    public function latestCorrection(string $sid,string $eid,string $original):?array
    {
        $r=$this->sql('SELECT * FROM '.$this->table('correction_reviews').' WHERE session_id=? AND encounter_id=? AND original_event_id=? ORDER BY review_version DESC LIMIT 1',[$sid,$eid,$original])['rows'][0]??null;if(!$r)return null;
        if(!hash_equals($r['result_hash'],hash('sha256',$r['result_json'])))throw new RuntimeException('ACS_CORRECTION_INTEGRITY');
        $body=json_decode($r['result_json'],true,64,JSON_THROW_ON_ERROR);$command=$this->rewardCommandReceipt($sid,$eid,'correction.review','$.review.review_id',(string)$r['id']);
        if(!$command||($command['result']['review']??null)!==$body||(string)$r['event_id']!==$command['result']['event_id']||$body['original_event_id']!==$original||$body['review_version']!==(string)$r['review_version']||$body['disposition']!==$r['disposition'])throw new RuntimeException('ACS_CORRECTION_INTEGRITY');
        if((string)($this->event((string)$r['event_id'])['correction_of_event_id']??'')!==$original)throw new RuntimeException('ACS_CORRECTION_INTEGRITY');
        if($body['source_hash']!==$this->achievementSource($sid,$eid,$original)['source_hash'])throw new RuntimeException('ACS_CORRECTION_INTEGRITY');
        return $body;
    }
    public function reviewCorrection(array $s,string $eid,string $original,string $expected,array $plan,callable $progressAccess):array
    {
        $this->commandWrite();$plan=AF_ACS_Achievements::correction($plan);$source=$this->achievementSource($s['id'],$eid,$original);$previous=$this->latestCorrection($s['id'],$eid,$original);
        if(($previous['review_version']??'0')!==$expected)throw new RuntimeException('ACS_VERSION_CONFLICT');
        if($previous!==null&&$previous['source_hash']!==$source['source_hash'])throw new RuntimeException('ACS_CORRECTION_INTEGRITY');
        $version=(string)((int)self::integer($expected,'9007199254740990',true)+1);$empty=self::json(['schema_version'=>1]);
        $id=$this->sql('INSERT INTO '.$this->table('correction_reviews').' (session_id,encounter_id,original_event_id,review_version,disposition,event_id,result_json,result_hash) VALUES (?,?,?,?,?,NULL,?,?)',[$s['id'],$eid,$original,$version,$plan['disposition'],$empty,hash('sha256',$empty)])['id'];
        $affected=$this->sql('SELECT DISTINCT progress_id FROM '.$this->table('achievement_events').' WHERE session_id=? AND source_event_id=?',[$s['id'],$original])['rows'];$changes=[];
        foreach($affected as $a){
            $progressAccess((string)$a['progress_id']);$r=$this->achievementProgress((string)$a['progress_id']);$p=$r['progress'];
            $n=$this->sql('SELECT COUNT(*) AS n FROM '.$this->table('achievement_events')." a WHERE a.progress_id=? AND COALESCE((SELECT c.disposition FROM ".$this->table('correction_reviews')." c WHERE c.original_event_id=a.source_event_id ORDER BY c.review_version DESC LIMIT 1),'include')='include'",[$r['id']])['rows'][0]['n'];
            $p['review_count']=(int)$n;$p['review_version']++;$p['review_required']=true;$json=self::json($p);
            $this->sql('UPDATE '.$this->table('achievement_progress').' SET progress_json=?,progress_hash=?,progress_version=progress_version+1 WHERE id=? AND progress_version=?',[$json,hash('sha256',$json),$r['id'],$r['progress_version']]);
            $changes[]=['progress_id'=>$r['id'],'previous_version'=>$r['progress_version'],'review_count'=>$p['review_count'],'awards_retained'=>$p['awards']];
        }
        $included=$plan['disposition']==='include';$review=['schema_version'=>1,'review_id'=>$id,'original_event_id'=>$original,'source_hash'=>$source['source_hash'],'review_version'=>$version,'previous_review_id'=>$previous['review_id']??null,'disposition'=>$plan['disposition'],'plan'=>$plan,
            'base'=>['confirmed_event_count'=>1,'primary_allocated_damage'=>$source['primary_allocated_damage'],'outcome'=>$source['outcome']],
            'aggregate'=>['included_event_count'=>$included?1:0,'primary_allocated_damage'=>$included?$source['primary_allocated_damage']:0,'outcome_review'=>$included?'included':'excluded','original_outcome'=>$source['outcome']],
            'progress_revisions'=>$changes,'compensation_status'=>$plan['compensation']==='none'?'not_requested':'blocked','compensation_reason'=>$plan['compensation']==='none'?null:$plan['compensation'].'_compensation_owner_contract_unavailable'];
        $json=self::json($review);$this->sql('UPDATE '.$this->table('correction_reviews').' SET result_json=?,result_hash=? WHERE id=?',[$json,hash('sha256',$json),$id]);
        $s['_command_result']=['encounter_id'=>$eid,'review'=>$review];return $s;
    }
    public function linkCorrectionEvent(array $s,string $event):array
    {$this->transactions->assertInternalBoundary();$this->sql('UPDATE '.$this->table('events').' SET correction_of_event_id=? WHERE id=? AND correction_of_event_id IS NULL',[$s['_command_result']['review']['original_event_id'],$event]);$this->sql('UPDATE '.$this->table('correction_reviews').' SET event_id=? WHERE id=? AND event_id IS NULL',[$event,$s['_command_result']['review']['review_id']]);return $s;}
}
