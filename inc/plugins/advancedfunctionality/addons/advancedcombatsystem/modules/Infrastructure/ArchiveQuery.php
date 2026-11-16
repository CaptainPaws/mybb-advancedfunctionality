<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/EncounterRepository.php';
require_once __DIR__.'/TerminalReceiptPersistence.php';
require_once __DIR__.'/../Domain/Archive.php';
/** Bounded writer reads; no native posts.message, Resolver, transaction or persistent GET writes. */
final class AF_ACS_ArchiveQuery extends AF_ACS_Repository
{
    use AF_ACS_TerminalReceiptPersistence;
    private function scope():string
    {
        return '(e.encounter_id=? OR (e.encounter_id IS NULL AND ((e.event_type IN (\'reward.committed\',\'reward.recovery_required\') AND EXISTS (SELECT 1 FROM '.$this->table('rewards').' rw WHERE rw.session_id=e.session_id AND rw.encounter_id=? AND rw.id=JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,\'$.reward_id\')))) OR (e.event_type LIKE \'inventory.%\' AND EXISTS (SELECT 1 FROM '.$this->table('loot_entries').' le JOIN '.$this->table('loot_pools').' lp ON lp.id=le.pool_id WHERE lp.session_id=e.session_id AND lp.encounter_id=? AND le.issue_operation_key=JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,\'$.operation_key\')))))))';
    }
    public function horizon(string $sid,string $eid):string
    {return (string)$this->sql('SELECT COALESCE(MAX(e.session_sequence),0) AS n FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id WHERE e.session_id=? AND '.$this->scope(),[$sid,$eid,$eid,$eid])['rows'][0]['n'];}
    public function rows(string $sid,string $eid,string $upper,array $after,bool $gm,array $f,string $mode='reveal_all'):array
    {
        $where='e.session_id=? AND '.$this->scope().' AND e.session_sequence<=?';$args=[$eid,$sid,$eid,$eid,$eid,$upper];
        if(!$gm){$types=$mode==='keep_hidden'?['combat.resolved']:AF_ACS_Archive::PUBLIC_TYPES;$where.=' AND e.event_type IN ('.implode(',',array_fill(0,count($types),'?')).')';array_push($args,...$types);}
        if($f['event_type']!==''){$where.=' AND e.event_type=?';$args[]=$f['event_type'];}
        $anchor='COALESCE(e.event_sequence,(SELECT MAX(pr.event_sequence) FROM '.$this->table('events').' pr WHERE pr.session_id=e.session_id AND pr.encounter_id=? AND pr.session_sequence<=e.session_sequence),0)';
        $window='SELECT e.* FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id WHERE '.$where.' AND (e.session_sequence>? OR (e.session_sequence=? AND e.id>?)) ORDER BY e.session_sequence,e.id LIMIT 100';
        $sql='SELECT e.*,d.payload_json,d.visibility_json,d.content_hash,o.status AS operation_status,c.turn_id AS combat_turn,pl.pid AS source_pid,pl.fid AS source_fid,pl.tid AS source_tid,pl.author_uid AS source_author,r.round_number,p.pid AS native_pid,p.visible AS native_visible,p.tid AS native_tid,p.fid AS native_fid,p.uid AS native_uid,'.$anchor.' AS archive_sequence FROM ('.$window.') e JOIN '.$this->table('event_details').' d ON d.event_id=e.id LEFT JOIN '.$this->table('operations').' o ON o.operation_key=e.operation_key LEFT JOIN '.$this->table('combat_results').' c ON c.event_id=e.id LEFT JOIN '.$this->table('post_links').' pl ON pl.submission_id=e.submission_id LEFT JOIN '.$this->table('turns').' t ON t.id=c.turn_id LEFT JOIN '.$this->table('rounds').' r ON r.id=t.round_id LEFT JOIN `'.$this->prefix.'posts` p ON p.pid=pl.pid ORDER BY e.session_sequence,e.id';
        return $this->sql($sql,[...$args,$after[1],$after[1],$after[2]])['rows'];
    }
    public function sourceRow(string $sid,string $eid,string $id):?array
    {
        $e=$this->sql('SELECT session_sequence,event_sequence,event_type FROM '.$this->table('events').' WHERE session_id=? AND encounter_id=? AND id=?',[$sid,$eid,$id])['rows'][0]??null;
        if(!$e||$e['event_sequence']===null)return null;
        foreach($this->rows($sid,$eid,(string)$e['session_sequence'],[(string)$e['event_sequence'],(string)$e['session_sequence'],'0'],true,AF_ACS_Archive::filters(['event_type'=>$e['event_type']])) as $r)if((string)$r['id']===$id)return $r;return null;
    }
    public function verify(array $e):array
    {
        if(!hash_equals($e['content_hash'],hash('sha256',$e['payload_json']."\n".$e['visibility_json']))||$e['operation_status']!==null&&$e['operation_status']!=='committed')throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');
        if($e['event_type']==='combat.resolved'){
            $sub=$this->sql('SELECT * FROM '.$this->table('submissions').' WHERE submission_id=? AND session_id=? AND encounter_id=?',[$e['submission_id'],$e['session_id'],$e['encounter_id']])['rows'][0]??throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');
            foreach(['turn_id','session_id','pid'] as $k)$sub[$k]=(string)$sub[$k];
            $receipt=$this->terminal($sub);if(!$receipt||$receipt['event_id']!==(string)$e['id'])throw new RuntimeException('ACS_ARCHIVE_INTEGRITY');
        }
        return json_decode($e['payload_json'],true,64,JSON_THROW_ON_ERROR);
    }
    public function achievementRewardRefs(string $sid):array
    {
        $rows=$this->sql('SELECT id,operation_key FROM '.$this->table('rewards')." WHERE session_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.source_kind'))='achievement' LIMIT 201",[$sid])['rows'];
        if(count($rows)>200)throw new RuntimeException('ACS_ARCHIVE_EXPORT_LIMIT');return $rows;
    }
    public function origins(string $sid,?string $eid):array
    {
        $rows=$this->sql('SELECT DISTINCT fid,tid FROM '.$this->table('post_links').' WHERE session_id=?'.($eid===null?'':' AND encounter_id=?').' LIMIT 201',$eid===null?[$sid]:[$sid,$eid])['rows'];
        if(count($rows)>200)throw new RuntimeException('ACS_ARCHIVE_EXPORT_LIMIT');return $rows;
    }
    public function manifestRows(string $sid,string $eid,string $section,string $after):array
    {
        $suffix=' AND id>? ORDER BY id LIMIT 21';
        return match($section){
            'snapshots'=>$this->sql('SELECT id,source_system,source_key,contract_id,contract_version,payload_json,content_hash FROM '.$this->table('snapshots').' WHERE session_id=? AND (id=(SELECT config_snapshot_id FROM '.$this->table('encounters').' WHERE id=? AND session_id=?) OR id IN (SELECT e.template_snapshot_id FROM '.$this->table('entities').' e JOIN '.$this->table('participants').' p ON p.entity_id=e.id WHERE p.encounter_id=? AND e.session_id=?))'.$suffix,[$sid,$eid,$sid,$eid,$sid,$after])['rows'],
            'rewards'=>$this->sql('SELECT id,reward_type,status,reward_version,recipient_key,payload_json,payload_hash FROM '.$this->table('rewards')." WHERE session_id=? AND encounter_id=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.source_kind')),'encounter')<>'achievement'".$suffix,[$sid,$eid,$after])['rows'],
            'loot'=>$this->sql('SELECT l.id,l.qty,l.status,l.recipient_group_id,l.recipient_uid,l.allocation_version FROM '.$this->table('loot_entries').' l JOIN '.$this->table('loot_pools').' p ON p.id=l.pool_id WHERE p.session_id=? AND p.encounter_id=? AND l.id>? ORDER BY l.id LIMIT 21',[$sid,$eid,$after])['rows'],
            'custody'=>$this->sql('SELECT id,inventory_item_id,owner_uid,custodian_entity_id,active_user_entity_id,status,custody_version FROM '.$this->table('custody').' WHERE session_id=?'.$suffix,[$sid,$after])['rows'],
            default=>throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID')};
    }
}
