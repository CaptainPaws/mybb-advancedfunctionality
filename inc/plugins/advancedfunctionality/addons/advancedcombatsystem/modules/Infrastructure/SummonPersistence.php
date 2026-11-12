<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Domain/Spawn.php';

/** Runtime projection on the existing identity, never a second creature/AP registry. */
trait AF_ACS_SummonPersistence
{
    public static function registrationView(array $p):array
    {
        if(($p['contract_id']??null)!=='ic-acs.spawn-snapshot.v1')return $p;
        $spec=$p['spawn']['spec'];AF_ACS_Spawn::template($spec,$p['source_snapshot']);
        if($spec['kind']!=='spawn_entity')throw new RuntimeException('ACS_SUMMON_TEMPLATE_INVALID');
        $p['contract_id']='IC-ACS.entity-registration.v1';$p['contract_version']='1';$p['engine_version']='acs-registration-1';$p['equipment_policy']='already_included_do_not_reapply';
        $p['source_snapshot']['characteristics']=$p['source_snapshot']['data']['combat_stats'];
        $p['source_snapshot']['resistances']=$p['source_snapshot']['data']['element_resistances'];
        return $p;
    }
    public function summonProjection(string $sid,string $eid):array
    {
        $out=[];
        foreach($this->sql('SELECT e.*,s.payload_json,s.content_hash FROM '.$this->table('entities').' e JOIN '.$this->table('snapshots').' s ON s.id=e.template_snapshot_id AND s.session_id=e.session_id WHERE e.session_id=? AND e.kind=? ORDER BY e.id',[$sid,'summon'])['rows'] as $row){
            if(!hash_equals($row['content_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_SNAPSHOT_HASH_MISMATCH');
            $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$d=json_decode($row['definition_json'],true,64,JSON_THROW_ON_ERROR);
            if(($p['contract_id']??null)!=='ic-acs.spawn-snapshot.v1'||($d['spawn']??null)!==($p['spawn']??null))throw new RuntimeException('ACS_SUMMON_IDENTITY_INVALID');
            if($p['spawn']['encounter_id']!==$eid)continue;
            AF_ACS_Spawn::template($p['spawn']['spec'],$p['source_snapshot']);
            $v=$d['lifecycle']??AF_ACS_Spawn::initial((string)$row['id'],(string)$row['source_entity_id'],$eid,$p['spawn']['spec'],(int)$row['entity_version']);
            AF_ACS_Spawn::instance($v);
            $initial=AF_ACS_Spawn::initial((string)$row['id'],(string)$row['source_entity_id'],$eid,$p['spawn']['spec']);
            foreach(['entity_id','source_entity_id','encounter_id','unit','policy','upkeep'] as $k)if($v[$k]!==$initial[$k])throw new RuntimeException('ACS_SUMMON_IDENTITY_INVALID');
            if($v['version']!==(int)$row['entity_version']||$v['remaining']>$initial['remaining'])throw new RuntimeException('ACS_SUMMON_STATE_INVALID');
            $out[(string)$row['id']]=$v;
        }
        return $out;
    }
    protected function persistSummons(string $sid,string $eid,array $before,array $after,string $actor):array
    {
        $this->transactions->assertInternalBoundary();$changes=[];
        foreach($after as $id=>$v){$old=$before[$id]??throw new RuntimeException('ACS_SUMMON_IDENTITY_INVALID');if($old===$v)continue;
            $id=(string)$id;AF_ACS_Spawn::instance($v);$row=$this->sql('SELECT * FROM '.$this->table('entities').' WHERE session_id=? AND id=? FOR UPDATE',[$sid,$id])['rows'][0]??throw new RuntimeException('ACS_SUMMON_IDENTITY_INVALID');
            if((int)$row['entity_version']!==$old['version']||$v['version']<=$old['version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
            $d=json_decode($row['definition_json'],true,64,JSON_THROW_ON_ERROR);$group=(string)$row['control_group_id'];
            if($v['status']==='despawned'||$v['pending_independent']||$old['pending_independent']){
                $items=array_map('strval',array_column($this->sql('SELECT inventory_item_id FROM '.$this->table('equipment').' WHERE session_id=? AND entity_id=?',[$sid,$id])['rows'],'inventory_item_id'));
                $this->assertInventorySettled($sid,$items);
                $ref='acs-item:'.$sid.':'.$id;
                if($this->sql('SELECT operation_key FROM '.$this->table('operations')." WHERE session_id=? AND external_system='advancedinventory' AND status IN ('prepared','recovery_required') AND (JSON_UNQUOTE(JSON_EXTRACT(request_json,'$.actor.authorization_ref'))=? OR JSON_UNQUOTE(JSON_EXTRACT(request_json,'$.actor.authorization_ref')) LIKE ?) LIMIT 1",[$sid,$ref,$ref.':%'])['rows'])throw new RuntimeException('ACS_SUMMON_OBLIGATIONS_BLOCKED');
                if($this->sql('SELECT id FROM '.$this->table('custody')." WHERE session_id=? AND (active_user_entity_id=? OR custodian_entity_id=?) AND status='held' LIMIT 1",[$sid,$id,$id])['rows'])throw new RuntimeException('ACS_SUMMON_OBLIGATIONS_BLOCKED');
            }
            if($v['status']==='active'&&$old['pending_independent']&&!$v['pending_independent']&&$v['independent']){
                $group=$this->sql('INSERT INTO '.$this->table('control_groups').' (session_id,owner_uid,kind,group_version,config_json) VALUES (?,NULL,?,1,?)',[$sid,'independent',self::json(['schema_version'=>1])])['id'];
                $this->sql('UPDATE '.$this->table('controllers')." SET status='revoked' WHERE session_id=? AND entity_id=? AND status='active'",[$sid,$id]);
                $this->sql('INSERT INTO '.$this->table('controllers').' (session_id,entity_id,controller_uid,mode,status,assignment_version,valid_from,valid_until,permissions_json) VALUES (?,?,NULL,?,?,1,?,NULL,?)',[$sid,$id,'gm_pool','active',time(),self::json(['schema_version'=>1,'scope'=>'summon_independent','combat_operations_enabled'=>true])]);
            }
            if($v['status']==='despawned'){
                $statusProjection=$this->statusProjection($sid);$finished=$statusProjection['instances'];
                foreach($finished as &$effect)if($effect['status']==='active'&&($effect['target_entity_id']===$id||($effect['source_entity_id']===$id&&(isset($effect['spec']['upkeep'])||$effect['duration_unit']==='own_turn')))){$effect['status']='removed';$effect['stacks']=0;++$effect['version'];}unset($effect);
                $this->persistStatusInstances($sid,$statusProjection['instances'],$finished,[]);
                $pools=$this->barrierProjection($sid,$eid);$finishedPools=$pools['pools'];
                foreach($finishedPools as &$pool)if($pool['status']==='active'&&($pool['source_entity_id']===$id||($pool['targets']&&!array_filter($pool['targets'],static fn($target)=>($after[$target]['status']??null)!=='despawned')))){$pool['status']='expired';++$pool['version'];}unset($pool);
                $this->persistBarriers($sid,$eid,$pools['pools'],$finishedPools,[]);
                $this->sql('UPDATE '.$this->table('participants')." SET participation='despawned',participant_version=participant_version+1 WHERE encounter_id=? AND entity_id=? AND participation<>'despawned'",[$eid,$id]);
                $this->sql('UPDATE '.$this->table('controllers')." SET status='revoked' WHERE session_id=? AND entity_id=? AND status='active'",[$sid,$id]);
                $this->sql('UPDATE '.$this->table('reactions')." SET status='cancelled',reaction_version=reaction_version+1 WHERE encounter_id=? AND entity_id=? AND status='active'",[$eid,$id]);
            }
            $d['lifecycle']=$v;
            if($this->sql('UPDATE '.$this->table('entities').' SET control_group_id=?,definition_json=?,entity_version=? WHERE session_id=? AND id=? AND entity_version=?',[$group,self::json($d),$v['version'],$sid,$id,$old['version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $key='summon:'.$id.':version:'.$v['version'];$request=self::json(['schema_version'=>1,'actor_uid'=>$actor,'entity_id'=>$id,'expected_version'=>$old['version']]);
            $change=['entity_id'=>$id,'before'=>$old,'after'=>$v,'control_group_id'=>$group];
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$sid,$eid,$v['status']==='despawned'?'summon.despawned':($v['pending_independent']?'summon.independent':'summon.boundary'),hash('sha256',$request),'committed',$request,self::json(['schema_version'=>1]+$change),time()]);$changes[]=$change;
        }
        return $changes;
    }
    /** Explicit KB admission only. Never touches budgets, attempts, deadlines or closed turns. */
    public function admitSummons(string $sid,string $eid,bool $nextRound=false):array
    {
        $this->transactions->assertInternalBoundary();$out=[];$enc=$this->encounter($sid,$eid);$round=$this->currentRound($enc);if(!$round)return [];
        foreach($this->summonProjection($sid,$eid) as $id=>$v){$id=(string)$id;if($v['status']!=='active')continue;
            $e=$this->entity($sid,$id);$p=json_decode($e['payload_json'],true,64,JSON_THROW_ON_ERROR)['spawn'];
            $g=$this->clockGroup($sid,$e['control_group_id']);$config=json_decode($g['config_json'],true);
            if($config===['schema_version'=>1,'admission'=>'next_round_pending','source_event_id'=>$p['source_event_id']]){
                if($this->sql('UPDATE '.$this->table('control_groups').' SET config_json=?,group_version=group_version+1 WHERE id=? AND group_version=?',[self::json(['schema_version'=>1]),$g['id'],$g['group_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            }
            $visibility=$this->sql('SELECT visibility_json FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$eid,$v['source_entity_id']])['rows'][0]['visibility_json']??self::json(['schema_version'=>1,'scope'=>'gm']);
            $newParticipant=!$this->sql('SELECT id FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$eid,$id])['rows'];if($newParticipant)$this->sql('INSERT INTO '.$this->table('participants').' (encounter_id,entity_id,side_id,participation,registered_sequence,participant_version,visibility_json) VALUES (?,?,?,?,?,1,?)',[$eid,$id,$p['side_id'],'engaged',$enc['next_event_sequence'],$visibility]);
            if($newParticipant)$out[]=['entity_id'=>$id,'source_event_id'=>$p['source_event_id'],'admission'=>'next_round'];
            if($newParticipant)$this->sql('UPDATE '.$this->table('controllers').' SET permissions_json=? WHERE session_id=? AND entity_id=? AND status=?',[self::json(['schema_version'=>1,'scope'=>'summon_assignment','combat_operations_enabled'=>true]),$sid,$id,'active']);
            if($nextRound){if($v['pending_independent']){$next=$v;$next['pending_independent']=false;++$next['version'];$this->persistSummons($sid,$eid,[$id=>$v],[$id=>$next],(string)$GLOBALS['mybb']->user['uid']);}continue;}
            if($v['independent']||$v['policy']['admission']!=='current_round')continue;
            foreach($this->allRoundTurns($round['id']) as $t){if($t['control_group_id']!==$e['control_group_id']||$t['status']!=='pending')continue;
                $r=json_decode($t['roster_json'],true,64,JSON_THROW_ON_ERROR);if(in_array($id,array_column($r['members'],'entity_id'),true))continue;
                $before=$r;$fp=$r['fingerprint'];unset($r['fingerprint']);if(!hash_equals($fp,hash('sha256',self::json($r))))throw new RuntimeException('ACS_ROSTER_INVALID');
                $member=null;foreach($this->rosterSources($sid,$eid) as $candidate)if($candidate['entity_id']===$id)$member=$candidate;
                if(!$member)throw new RuntimeException('ACS_ROSTER_INVALID');unset($member['group_config'],$member['layers_json']);$r['members'][]=$member;$r['fingerprint']=hash('sha256',self::json($r));
                if($this->sql('UPDATE '.$this->table('turns').' SET roster_json=?,turn_version=turn_version+1 WHERE id=? AND turn_version=? AND status=?',[self::json($r),$t['id'],$t['turn_version'],'pending'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
                $individual=$this->sql('INSERT INTO '.$this->table('entity_turns').' (turn_id,entity_id,start_done,end_done,reaction_used,entity_turn_version) VALUES (?,?,0,0,0,1)',[$t['id'],$id])['id'];
                $out[]=['entity_id'=>$id,'turn_id'=>$t['id'],'entity_turn_id'=>$individual,'roster_before'=>$before,'roster_after'=>$r];
            }
        }
        return $out;
    }
}
