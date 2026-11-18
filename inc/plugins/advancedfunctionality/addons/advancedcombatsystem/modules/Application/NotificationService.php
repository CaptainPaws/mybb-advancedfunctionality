<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Infrastructure/NotificationOutbox.php';

/** Bounded noncritical maintenance. No publish/Resolver/Clock/owner reward command. */
final class AF_ACS_NotificationService
{
    public function __construct(private object $database){}
    public function drain(string $sid):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!af_is_addon_enabled('advancedalertsandmentions')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_ALERT_DEPENDENCY_UNAVAILABLE');
        require_once __DIR__.'/../../../advancedalertsandmentions/advancedalertsandmentions.php';
        $repo=new AF_ACS_NotificationOutbox($this->database);$result=['acknowledged'=>0,'pending'=>0,'source_pending'=>false];
        try{$repo->seed($sid);}catch(Throwable $error){$result['source_pending']=true;$result['blocker']=preg_match('/\A(?:ACS_ALERT|AAM)_[A-Z_]+\z/D',$error->getMessage())?$error->getMessage():'ACS_ALERT_UNAVAILABLE';try{$repo->deferSources($sid);$result['error_id']=$repo->recordDiagnostic(bin2hex(random_bytes(16)),'DEPENDENCY_ERROR','alerts_delivery','source');}catch(Throwable){}}
        $owner=new AF_AAM_CombatDelivery($this->database,$repo);
        foreach($repo->pending($sid) as $row)try{
            if(!hash_equals($row['payload_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_ALERT_SOURCE_INVALID');
            $m=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR)['message'];$repo->boundary();
            // Lost owner ACK: lookup first. Absence permits the SAME idempotent delivery envelope.
            $receipt=$owner->lookup($m)??$owner->deliver($m);$repo->acknowledge($sid,$m,$receipt);$result['acknowledged']++;
        }catch(Throwable $error){$result['blocker']=preg_match('/\A(?:ACS_ALERT|AAM)_[A-Z_]+\z/D',$error->getMessage())?$error->getMessage():'ACS_ALERT_UNAVAILABLE';try{$repo->defer($row['message_key'],(int)$row['attempts']);if(!isset($result['error_id']))$result['error_id']=$repo->recordDiagnostic(bin2hex(random_bytes(16)),'DEPENDENCY_ERROR','alerts_delivery','owner_lookup');}catch(Throwable){}$result['pending']++;}
        return $result;
    }
    public function run():array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!af_is_addon_enabled('advancedalertsandmentions'))throw new RuntimeException('ACS_ALERT_DEPENDENCY_UNAVAILABLE');
        $repo=new AF_ACS_NotificationOutbox($this->database);$schedule=$repo->schedule();$result=['sessions'=>0,'pending'=>0];
        foreach($schedule['ids'] as $sid){$r=$this->drain($sid);$result['sessions']++;$result['pending']+=$r['pending']+($r['source_pending']?1:0);}
        $repo->boundary();$repo->advance($schedule['after']);return $result;
    }
}
