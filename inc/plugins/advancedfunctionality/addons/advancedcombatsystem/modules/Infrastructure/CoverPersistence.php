<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Domain/Cover.php';
trait AF_ACS_CoverPersistence
{
    public function coverProjection(string $sid,string $eid):array
    {
        $objects=$this->objectProjection($sid,$eid);$out=[];
        $rows=$this->sql('SELECT c.*,e.session_id FROM '.$this->table('cover_assignments').' c LEFT JOIN '.$this->table('entities').' e ON e.id=c.protected_entity_id WHERE c.encounter_id=? ORDER BY c.cover_entity_id,c.protected_entity_id',[$eid])['rows'];
        foreach($rows as $r){$cover=(string)$r['cover_entity_id'];$target=(string)$r['protected_entity_id'];$stored=json_decode($r['policy_json'],true,32,JSON_THROW_ON_ERROR);if(($stored['schema_version']??null)!==1)throw new RuntimeException('ACS_COVER_SCOPE_INVALID');$policy=$stored['definition'];if((string)$r['session_id']!==$sid||!isset($objects[$cover])||!$this->sql('SELECT id FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$eid,$target])['rows']||$policy!==($objects[$cover]['definition']['cover']??null)||!in_array($r['status'],['active','inactive'],true)||(int)$r['assignment_version']<1)throw new RuntimeException('ACS_COVER_SCOPE_INVALID');AF_ACS_Cover::definition($policy);$out[$cover.':'.$target]=['cover_entity_id'=>$cover,'protected_entity_id'=>$target,'status'=>$r['status'],'assignment_version'=>(int)$r['assignment_version'],'policy'=>$policy];}return $out;
    }
    public function coverDependencies(string $sid,string $eid,array $keys,bool $lock=false):array
    {
        if(!$keys)return [];$assignments=$this->coverProjection($sid,$eid);$objects=$this->objectProjection($sid,$eid);$out=[];
        foreach($keys as $key){$out[$key]=AF_ACS_Cover::select($assignments,$objects,$key);if($lock){[$type,$id]=explode(':',$key,2);if($type==='target'||$type==='capacity'){$column=$type==='target'?'protected_entity_id':'cover_entity_id';$this->sql('SELECT id FROM '.$this->table('cover_assignments').' WHERE encounter_id=? AND '.$column.'=? ORDER BY id FOR UPDATE',[$eid,$id]);}else{foreach($type==='object'?[$id=>$out[$key]]:$out[$key] as $oid=>$o)if($o!==null)$this->sql('SELECT entity_id FROM '.$this->table('objects').' WHERE encounter_id=? AND entity_id=? FOR UPDATE',[$eid,(string)$oid]);}}}return $out;
    }
    protected function persistCover(string $sid,string $eid,array $before,array $after):array
    {
        $this->transactions->assertInternalBoundary();if($this->coverDependencies($sid,$eid,array_keys($after['cover']['reads']??[]),true)!==($after['cover']['reads']??[]))throw new RuntimeException('ACS_COVER_VERSION_CONFLICT');$changes=[];
        foreach($after['cover']['assignments']??[] as $key=>$v){$old=$before['cover']['assignments'][$key]??null;if($old===$v)continue;if($v['assignment_version']<=($old['assignment_version']??0))throw new RuntimeException('ACS_COVER_VERSION_CONFLICT');
            if($old===null)$this->sql('INSERT INTO '.$this->table('cover_assignments').' (encounter_id,cover_entity_id,protected_entity_id,status,assignment_version,policy_json) VALUES (?,?,?,?,?,?)',[$eid,$v['cover_entity_id'],$v['protected_entity_id'],$v['status'],$v['assignment_version'],self::json(['schema_version'=>1,'definition'=>$v['policy']])]);
            elseif($this->sql('UPDATE '.$this->table('cover_assignments').' SET status=?,assignment_version=? WHERE encounter_id=? AND cover_entity_id=? AND protected_entity_id=? AND assignment_version=?',[$v['status'],$v['assignment_version'],$eid,$v['cover_entity_id'],$v['protected_entity_id'],$old['assignment_version']])['affected']!==1)throw new RuntimeException('ACS_COVER_VERSION_CONFLICT');$changes[]=['before'=>$old,'after'=>$v];
        }return $changes;
    }
    public function coverCommand(array $session,array $enc,array $r):array
    {
        $this->commandWrite();if(!$this->participantExists($enc['id'],$r['protected_entity_id']))throw new RuntimeException('ACS_COVER_BLOCKED');$objects=$this->objectProjection($session['id'],$enc['id']);$assignments=$this->coverProjection($session['id'],$enc['id']);$state=['objects'=>$objects,'spatial'=>$this->spatialProjection($session['id'],$enc['id']),'cover'=>AF_ACS_Cover::initial($assignments,$objects)];$before=$state;$key=$r['cover_entity_id'].':'.$r['protected_entity_id'];if(($assignments[$key]['assignment_version']??0)!==(int)$r['expected_assignment_version'])throw new RuntimeException('ACS_COVER_VERSION_CONFLICT');AF_ACS_Cover::assign($state,$r['cover_entity_id'],$r['protected_entity_id'],$r['status']==='active');$changes=$this->persistCover($session['id'],$enc['id'],$before,$state);$session=$this->change($session,$enc,'cover_change',[]);$session['_command_result']+=['cover'=>$changes,'reason'=>$r['reason']];return $session;
    }
}
