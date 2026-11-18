<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/Adapters/SessionAccess.php';
/** Small batched read path. No Repository/QueryService/Resolver/Builder/catalogue dependency. */
final class AF_ACS_VersionQuery
{
    private mysqli $link;
    public int $queries=0;
    public function __construct(object $database)
    {
        $this->link=$database instanceof mysqli?$database:($database->write_link??throw new RuntimeException('ACS_SCHEMA_NOT_READY'));
        if(!preg_match('/\A[A-Za-z0-9_]*\z/D',TABLE_PREFIX))throw new RuntimeException('ACS_SCHEMA_NOT_READY');
    }
    private function read(string $sql,array $values):array
    {
        $this->queries++;$s=$this->link->prepare($sql);if(!$s)throw new RuntimeException('ACS_SCHEMA_NOT_READY');
        try{if($values&&!$s->bind_param(str_repeat('s',count($values)),...$values)||!$s->execute())throw new RuntimeException('ACS_SCHEMA_NOT_READY');return $s->get_result()->fetch_all(MYSQLI_ASSOC);}finally{$s->close();}
    }
    private static function id(string $v,bool $empty=false):string
    {if($empty&&$v==='')return '';if(!preg_match('/\A[1-9][0-9]{0,19}\z/D',$v))throw new RuntimeException('ACS_VERSIONS_INPUT_INVALID');return $v;}
    public function current(string $sid,string $tid,string $eid='',string $draft=''):array
    {
        global $mybb;
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        self::id($sid);self::id($tid);self::id($eid,true);self::id($draft,true);$access=new AF_ACS_SessionAccess;$uid=$access->uid();$access->thread($tid);$p=TABLE_PREFIX;
        $rows=$this->read('SELECT s.owner_uid,s.status,s.session_version,r.role,r.expires_at FROM '.$p.'af_acs_sessions s JOIN '.$p.'af_acs_session_roles r ON r.session_id=s.id WHERE s.id=? AND s.tid=? AND r.uid=? AND (r.expires_at IS NULL OR r.expires_at>?) ORDER BY r.role',[$sid,$tid,$uid,time()]);
        if(!$rows)throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$gm=false;foreach($rows as $r)if($r['role']==='gm'||$r['role']==='owner'&&(string)$r['owner_uid']===$uid)$gm=true;
        $e=$this->read('SELECT id,encounter_version,round_number,next_event_sequence FROM '.$p.'af_acs_encounters WHERE session_id=?'.($eid!==''?' AND id=?':'').' ORDER BY CASE WHEN status IN (\'active\',\'paused\',\'pending_resolution\') THEN 0 WHEN status IN (\'preparation\',\'ambush_ready\') THEN 1 ELSE 2 END,id DESC LIMIT 1',$eid!==''?[$sid,$eid]:[$sid])[0]??null;
        if(!$e&&$eid!=='')throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$eid=$e?(string)$e['id']:'';
        $round=$e?($this->read('SELECT round_version FROM '.$p.'af_acs_rounds WHERE encounter_id=? AND round_number=? LIMIT 1',[$eid,$e['round_number']])[0]??null):null;
        // Exact entity metadata is restricted to GM or own group. No hidden placeholder/count.
        $entities=$e?$this->read('SELECT e.id,e.entity_version,s.state_version FROM '.$p.'af_acs_entities e JOIN '.$p.'af_acs_participants p ON p.entity_id=e.id JOIN '.$p.'af_acs_entity_states s ON s.entity_id=e.id LEFT JOIN '.$p.'af_acs_control_groups g ON g.id=e.control_group_id WHERE e.session_id=? AND p.encounter_id=?'.($gm?'':' AND (e.owner_uid=? OR g.owner_uid=?)').' ORDER BY e.id LIMIT 1001',$gm?[$sid,$eid]:[$sid,$eid,$uid,$uid]):[];
        if(count($entities)>1000)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');
        $d=null;if($draft!==''){$d=$this->read('SELECT d.draft_version FROM '.$p.'af_acs_drafts d JOIN '.$p.'af_acs_encounters e ON e.id=d.encounter_id WHERE d.id=? AND d.author_uid=? AND e.session_id=? AND e.id=?',[$draft,$uid,$sid,$eid])[0]??null;if(!$d)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');}
        $fence=$this->read('SELECT s.session_version,e.encounter_version,d.draft_version FROM '.$p.'af_acs_sessions s LEFT JOIN '.$p.'af_acs_encounters e ON e.session_id=s.id AND e.id=? LEFT JOIN '.$p.'af_acs_drafts d ON d.encounter_id=e.id AND d.id=? AND d.author_uid=? WHERE s.id=? AND s.tid=?',[$eid?:'0',$draft?:'0',$uid,$sid,$tid])[0]??null;
        if(!$fence||(string)$fence['session_version']!==(string)$rows[0]['session_version']||($e&&(string)$fence['encounter_version']!==(string)$e['encounter_version'])||($d&&(string)$fence['draft_version']!==(string)$d['draft_version']))throw new RuntimeException('ACS_VERSION_CONFLICT');
        $secret=(string)($mybb->settings['af_acs_archive_cursor_key']??'');if(!preg_match('/\A[a-f0-9]{64}\z/D',$secret))throw new RuntimeException('ACS_VERSIONS_KEY_UNAVAILABLE');
        $opaque=static fn($key,$value)=>hash_hmac('sha256',json_encode([$uid,$sid,$eid,$key,$value,$rows],JSON_THROW_ON_ERROR),hex2bin($secret));
        $versions=['encounter_version'=>$e?$opaque('encounter',$e['encounter_version']):null,'round_version'=>$round?$opaque('round',$round['round_version']):null,'event_sequence'=>$e?$opaque('events',$e['next_event_sequence']):null,'entity_version'=>$opaque('entities',$entities),'draft_version'=>$d?(string)$d['draft_version']:null];
        return ['session_id'=>$sid,'encounter_id'=>$eid,'versions'=>$versions,'read_only'=>true,'can_profile'=>$gm];
    }
}
