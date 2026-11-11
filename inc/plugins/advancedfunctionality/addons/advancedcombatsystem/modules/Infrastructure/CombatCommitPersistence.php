<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Private SQL-only implementation details of the single combat writer. No native hooks/callbacks. */
trait AF_ACS_CombatCommitPersistence
{
    private function facts(array $row, bool $lock = false): array
    {
        $sid=$row['session_id'];$eid=$row['encounter_id'];$draft=$row['draft_id'];
        $suffix=$lock?' FOR UPDATE':'';
        $one=fn($name,$column,$value)=>$this->sql('SELECT * FROM '.$this->table($name).' WHERE '.$column.'=?'.$suffix,[$value])['rows'];
        $f=['session'=>$one('sessions','id',$sid),'encounter'=>$one('encounters','id',$eid),'draft'=>$one('drafts','id',$draft)];
        $f['rounds']=$this->sql('SELECT * FROM '.$this->table('rounds').' WHERE encounter_id=? ORDER BY id'.$suffix,[$eid])['rows'];
        $f['turns']=$this->sql('SELECT t.* FROM '.$this->table('turns').' t JOIN '.$this->table('rounds').' r ON r.id=t.round_id WHERE r.encounter_id=? ORDER BY t.id'.$suffix,[$eid])['rows'];
        $f['individuals']=$this->sql('SELECT x.* FROM '.$this->table('entity_turns').' x JOIN '.$this->table('turns').' t ON t.id=x.turn_id JOIN '.$this->table('rounds').' r ON r.id=t.round_id WHERE r.encounter_id=? ORDER BY x.id'.$suffix,[$eid])['rows'];
        foreach (['entities','control_groups','controllers','session_roles'] as $name) {
            $f[$name]=$this->sql('SELECT * FROM '.$this->table($name).' WHERE session_id=? ORDER BY id'.$suffix,[$sid])['rows'];
        }
        foreach (['entity_states','resources','cooldowns'] as $name) {
            $order=$name==='entity_states'?'entity_id':'id';
            $f[$name]=$this->sql('SELECT x.* FROM '.$this->table($name).' x JOIN '.$this->table('entities').' e ON e.id=x.entity_id WHERE e.session_id=? ORDER BY x.'.$order.$suffix,[$sid])['rows'];
        }
        foreach (['participants','sides','side_relationships'] as $name) {
            $f[$name]=$this->sql('SELECT * FROM '.$this->table($name).' WHERE encounter_id=? ORDER BY id'.$suffix,[$eid])['rows'];
        }
        $f['equipment']=$this->sql('SELECT e.*,s.content_hash,SHA2(s.payload_json,256) AS actual_hash FROM '.$this->table('equipment').' e JOIN '.$this->table('snapshots').' s ON s.id=e.item_snapshot_id WHERE e.session_id=? ORDER BY e.id'.$suffix,[$sid])['rows'];
        $f['effects']=$this->statusRows($sid,$lock);$f['reactions']=$this->reactionRows($sid,$eid,$lock);$f['barriers']=$this->barrierRows($sid,$eid,$lock);
        $f['barrier_targets']=$this->sql('SELECT t.* FROM '.$this->table('barrier_targets').' t JOIN '.$this->table('barrier_pools').' p ON p.id=t.pool_id JOIN '.$this->table('effects').' e ON e.id=p.effect_id WHERE e.session_id=? AND e.encounter_id=? ORDER BY t.pool_id,t.entity_id'.$suffix,[$sid,$eid])['rows'];
        $f['reaction_rolls']=$this->sql('SELECT * FROM '.$this->table('rolls')." WHERE session_id=? AND sealed_action_id IS NULL AND check_kind='reaction' ORDER BY id".$suffix,[$sid])['rows'];
        $f['reaction_snapshots']=$this->sql('SELECT s.id,s.content_hash,SHA2(s.payload_json,256) AS actual_hash FROM '.$this->table('snapshots').' s JOIN '.$this->table('reactions').' r ON r.definition_snapshot_id=s.id WHERE s.session_id=? AND r.encounter_id=? ORDER BY s.id'.$suffix,[$sid,$eid])['rows'];
        $f['seals']=$this->sql('SELECT * FROM '.$this->table('sealed_actions').' WHERE draft_id=? ORDER BY action_index'.$suffix,[$draft])['rows'];
        $f['rolls']=$this->sql('SELECT x.* FROM '.$this->table('rolls').' x JOIN '.$this->table('sealed_actions').' a ON a.id=x.sealed_action_id WHERE a.draft_id=? ORDER BY x.id'.$suffix,[$draft])['rows'];
        $f['snapshots']=$this->sql('SELECT id,content_hash,SHA2(payload_json,256) AS actual_hash FROM '.$this->table('snapshots').' WHERE session_id=? AND (id IN (SELECT template_snapshot_id FROM '.$this->table('entities').' WHERE session_id=?) OR id IN (SELECT definition_snapshot_id FROM '.$this->table('sealed_actions').' WHERE draft_id=?) OR id IN (SELECT definition_snapshot_id FROM '.$this->table('effects').' WHERE session_id=?)) ORDER BY id'.$suffix,[$sid,$sid,$draft,$sid])['rows'];
        $f['boundaries']=$this->sql('SELECT operation_key,kind,status,payload_hash,result_json FROM '.$this->table('operations')." WHERE session_id=? AND (kind LIKE 'clock.%' OR kind='round.turn_closed' OR status<>'committed') ORDER BY operation_key".$suffix,[$sid])['rows'];
        return $f;
    }

    private static function factsHash(array $facts): string
    {
        // SQL rows have stable column/order/type; no JSON size limit or lossy large-ID conversion.
        $saved=ini_get('serialize_precision');ini_set('serialize_precision','-1');
        try { return hash('sha256',serialize($facts)); }
        finally { ini_set('serialize_precision',(string)$saved); }
    }

    private static function indexed(array $rows, string $key = 'id'): array
    {
        $out=[];foreach ($rows as $row) { $out[(string)$row[$key]]=$row; }return $out;
    }

    private function authorizeFacts(array $row, array $f, array $actions): array
    {
        // No polymorphic access callbacks under the transaction: authenticated request identity only.
        $uid=(string)(int)($GLOBALS['mybb']->user['uid']??0);$now=time();
        if (isset($GLOBALS['mybb']->usergroup['canview']) && !$GLOBALS['mybb']->usergroup['canview']) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        $session=$f['session'][0]??null;$enc=$f['encounter'][0]??null;
        $turn=self::indexed($f['turns'])[$row['turn_id']]??null;
        $round=$turn?(self::indexed($f['rounds'])[(string)$turn['round_id']]??null):null;
        if ($uid!==$row['author_uid'] || !$session || $session['status']!=='active' || (string)$session['tid']!==$row['tid']
            || !$enc || $enc['status']!=='active' || !$turn || $turn['status']!=='pending' || !$round || $round['status']!=='open'
            || (string)$round['round_number']!==(string)$enc['round_number'] || $row['active_turn_id']!==$row['turn_id']) {
            throw new RuntimeException('ACS_COMBAT_SCOPE_CONFLICT');
        }
        $roles=array_filter($f['session_roles'],static fn($r)=>(string)$r['uid']===$uid && ($r['expires_at']===null || (int)$r['expires_at']>$now));
        if (!array_intersect(array_column($roles,'role'),['owner','gm','assistant_gm','player'])) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        $entities=self::indexed($f['entities']);$states=self::indexed($f['entity_states'],'entity_id');$grants=[];$roster=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);
        $fp=$roster['fingerprint']??'';unset($roster['fingerprint']);
        if (!hash_equals($fp,hash('sha256',self::json($roster)))) { throw new RuntimeException('ACS_ROSTER_INVALID'); }
        $members=array_column($roster['members']??[],'entity_id');
        $individuals=array_values(array_filter($f['individuals'],static fn($r)=>(string)$r['turn_id']===$row['turn_id']));
        if (!$members || count($individuals)!==count($members)
            || array_diff(array_map('strval',array_column($individuals,'entity_id')),$members)) { throw new RuntimeException('ACS_ROSTER_INVALID'); }
        foreach ($members as $id) {
            $e=$entities[$id]??null;
            $controllers=array_filter($f['controllers'],static fn($c)=>(string)$c['entity_id']===$id && $c['status']==='active'
                && ($c['valid_until']===null || (int)$c['valid_until']>$now));
            $c=$controllers?array_values($controllers)[0]:null;
            if (!$e || (string)$e['control_group_id']!==(string)$turn['control_group_id'] || ($e['owner_uid']!==null && (string)$e['owner_uid']!==$uid)
                || count($controllers)!==1 || (string)$c['controller_uid']!==$uid || $c['mode']!=='exclusive' || (int)$c['valid_from']>$now) {
                throw new RuntimeException('ACS_COMBAT_CONTROL_DENIED');
            }
            if (json_decode($states[$id]['layers_json'],true)!==['schema_version'=>1,'layers'=>[]]) { throw new RuntimeException('ACS_COMBAT_EFFECT_WRITER_UNSUPPORTED'); }
            $grants[$id]=json_decode($c['permissions_json'],true,16,JSON_THROW_ON_ERROR);
        }
        foreach ($actions as $a) { if (!in_array($a['actor_id'],$members,true)
            || ($grants[$a['actor_id']]['schema_version']??null)!==1 || ($grants[$a['actor_id']]['combat_operations_enabled']??false)!==true) { throw new RuntimeException('ACS_COMBAT_CONTROL_DENIED'); } }
        foreach ($f['snapshots'] as $s) { if (!hash_equals($s['content_hash'],$s['actual_hash'])) { throw new RuntimeException('ACS_SNAPSHOT_HASH_MISMATCH'); } }
        return $turn;
    }

    private function operation(array $row, string $key, string $kind, array $request, array $result): void
    {
        $request=['schema_version'=>1,'actor_uid'=>$row['author_uid']]+$request;
        $result=['schema_version'=>1,'session_id'=>$row['session_id'],'boundary_key'=>$key]+$result;
        $json=self::json($request);
        $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',
            [$key,$row['session_id'],$row['encounter_id'],$kind,hash('sha256',$json),'committed',$json,self::json($result),time()]);
    }

    private function projectReactions(array $facts,AF_ACS_ResolverResult $result):array
    {
        $versions=[];$individuals=self::indexed($facts['individuals']);$rows=self::indexed($facts['reactions']);
        foreach($result->serverPlan()['reaction_updates']??[] as $id=>$update){$row=$rows[$id]??null;
            if(!$row||(int)$row['reaction_version']!==$update['before_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
            $config=json_decode($row['config_json'],true,64,JSON_THROW_ON_ERROR);$config['last_execution']=$update['execution'];
            if($this->sql('UPDATE '.$this->table('reactions').' SET config_json=?,reaction_version=? WHERE id=? AND reaction_version=? AND status=?',[self::json($config),$update['after_version'],(string)$id,$row['reaction_version'],'active'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        }
        foreach($result->serverPlan()['reaction_limits']??[] as $entity=>$slot){$old=$individuals[$slot['id']]??null;
            if(!$old||(string)$old['entity_id']!==(string)$entity||(int)$old['entity_turn_version']!==$slot['version'])throw new RuntimeException('ACS_REACTION_LIMIT_CONFLICT');
            if((int)$old['reaction_used']===$slot['used'])continue;
            if((int)$old['reaction_used']!==0||$slot['used']!==1)throw new RuntimeException('ACS_REACTION_LIMIT_CONFLICT');self::integer((string)$old['entity_turn_version'],'18446744073709551614');
            if($this->sql('UPDATE '.$this->table('entity_turns').' SET reaction_used=1,entity_turn_version=entity_turn_version+1 WHERE id=? AND entity_turn_version=? AND reaction_used=0',[$slot['id'],$old['entity_turn_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');$versions[$slot['id']]=(string)((int)$old['entity_turn_version']+1);
        }return $versions;
    }
    private function project(array $row, array $f, array $turn, AF_ACS_ResolverResult $result,array $frozen): array
    {
        $before=$result->serverInitial();$after=$result->serverState();$changes=[];
        $states=self::indexed($f['entity_states'],'entity_id');$resources=[];
        foreach ($f['resources'] as $r) { $resources[(string)$r['entity_id']][$r['resource_key']]=$r; }
        foreach ($after['entities'] as $id=>$value) {
            $id=(string)$id;$old=$before['entities'][$id];$s=$states[$id];$resource=$resources[$id]['hp']??null;
            if (array_keys($old['resources'])!==array_keys($value['resources']) || $value['recovery_blocked'] || !$resource
                || count($resources[$id])!==count($old['resources'])+1 || (string)$resource['is_integer']!=='1'
                || (float)$resource['current_value']!==(float)$old['hp'] || (float)$resource['maximum_value']!==(float)$old['max_hp']) {
                throw new RuntimeException('ACS_COMBAT_RESOURCE_WRITER_UNSUPPORTED');
            }
            foreach($old['resources'] as $key=>$balance){$stored=$resources[$id][$key]??null;
                if(!$stored||(string)$stored['is_integer']!=='1'||(string)$stored['current_value']!==(string)$balance['current']||(string)$stored['maximum_value']!==(string)$balance['maximum'])throw new RuntimeException('ACS_COMBAT_RESOURCE_WRITER_UNSUPPORTED');
            }
            foreach($old['resources'] as $key=>$balance)if($value['resources'][$key]['maximum']!==$balance['maximum'])throw new RuntimeException('ACS_COMBAT_RESOURCE_WRITER_UNSUPPORTED');
            if ($value===$old) { continue; }
            self::integer((string)$resource['resource_version'],'18446744073709551614');
            if ($this->sql('UPDATE '.$this->table('entity_states').' SET hp=?,hp_max=?,life_state=?,state_version=? WHERE entity_id=? AND state_version=?',
                [$value['hp'],$value['max_hp'],$value['life_state'],$value['version'],$id,$s['state_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            if ($this->sql('UPDATE '.$this->table('resources').' SET current_value=?,maximum_value=?,resource_version=resource_version+1 WHERE id=? AND resource_version=?',
                [$value['hp'],$value['max_hp'],$resource['id'],$resource['resource_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            foreach($value['resources'] as $key=>$balance){$stored=$resources[$id][$key];if($balance['current']===$old['resources'][$key]['current'])continue;self::integer((string)$stored['resource_version'],'18446744073709551614');
                if($this->sql('UPDATE '.$this->table('resources').' SET current_value=?,resource_version=resource_version+1 WHERE id=? AND resource_version=?',[$balance['current'],$stored['id'],$stored['resource_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');}
            $changes[]=['entity_id'=>$id,'before'=>$old,'after'=>$value];
        }
        $group=(string)$turn['control_group_id'];$ap=$before['groups'][$group]['ap']['current']-$after['groups'][$group]['ap']['current'];
        if ($ap<0 || $ap>AF_ACS_Clock::availableAP((int)$turn['ap_budget'],(int)$turn['ap_spent'],(int)$turn['pre_round_ap_commitment'])) { throw new RuntimeException('ACS_AP_STATE_INVALID'); }
        foreach ($after['groups'] as $id=>$state) { if ((string)$id!==$group && $state!==$before['groups'][$id]) { throw new RuntimeException('ACS_COMBAT_GROUP_WRITER_UNSUPPORTED'); } }
        $reactionVersions=$this->projectReactions($f,$result);
        $boundaries=self::indexed($f['boundaries'],'operation_key');$closed=[];
        foreach ($f['individuals'] as $individual) {
            if(isset($reactionVersions[(string)$individual['id']]))$individual['entity_turn_version']=$reactionVersions[(string)$individual['id']];
            if ((string)$individual['turn_id']!==$row['turn_id']) { continue; }
            $id=(string)$individual['id'];$start=AF_ACS_Clock::entityStart($id);$end=AF_ACS_Clock::entityEnd($id);
            if ((string)$individual['end_done']!=='0' || isset($boundaries[$end])) { throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE'); }
            $startMissing=(string)$individual['start_done']==='0';
            if ($startMissing) {
                $instances=[];foreach($f['effects'] as $effect){$payload=json_decode($effect['payload_json'],true,64,JSON_THROW_ON_ERROR);if(($payload['kind']??null)==='barrier')continue;$instances[]=$payload['instance'];}
                if(AF_ACS_Clock::hasStartWork($instances,(string)$individual['entity_id'],$row['session_id'],$row['encounter_id']))throw new RuntimeException('ACS_STATUS_START_REQUIRED');
                foreach ($f['cooldowns'] as $cd) { if ((string)$cd['entity_id']===(string)$individual['entity_id']) { throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED'); } }
                if (isset($boundaries[$start])) { throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE'); }
                $this->operation($row,$start,'clock.entity_start',['entity_turn_id'=>$id],['entity_turn_id'=>$id,'cooldowns'=>[]]);
            } elseif (($boundaries[$start]['status']??null)!=='committed') { throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE'); }
            self::integer((string)$individual['entity_turn_version'],'18446744073709551613');
            if ($this->sql('UPDATE '.$this->table('entity_turns').' SET start_done=1,end_done=1,entity_turn_version=entity_turn_version+? WHERE id=? AND entity_turn_version=?',
                [$startMissing?2:1,$id,$individual['entity_turn_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            $endTrace=$result->serverPlan()['entity_end_boundaries'][$end]??null;
            $this->operation($row,$end,'clock.entity_end',['entity_turn_id'=>$id],['entity_turn_id'=>$id]+($endTrace?['statuses'=>$endTrace]:[]));$closed[]=$id;
        }
        if (!$closed) { throw new RuntimeException('ACS_ROSTER_INVALID'); }
        self::integer((string)$turn['turn_version'],'18446744073709551614');
        if ($this->sql('UPDATE '.$this->table('turns')." SET ap_spent=ap_spent+?,status='resolved',closed_by_submission=?,deadline_at=NULL,remaining_deadline_seconds=NULL,turn_version=turn_version+1 WHERE id=? AND turn_version=? AND status='pending'",
            [$ap,$row['submission_id'],$row['turn_id'],$turn['turn_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
        $this->operation($row,'turn:'.$row['turn_id'].':closed','round.turn_closed',['turn_id'=>$row['turn_id']],['turn_id'=>$row['turn_id'],'status'=>'resolved','submission_id'=>$row['submission_id']]);
        $snapshotIds=[];foreach($frozen['seals'] as $i=>$seal)$snapshotIds[$i]=$seal['payload']['definition_snapshot_id'];
        $statuses=$this->persistStatusInstances($row['session_id'],$before['status_instances']??[],$after['status_instances']??[],$snapshotIds);
        $barriers=$this->persistBarriers($row['session_id'],$row['encounter_id'],$before['barriers'],$after['barriers'],$result->serverPlan()['reaction_updates']??[]);
        return ['entities'=>$changes,'ap_spent'=>$ap,'entity_turns_closed'=>$closed,'statuses'=>$statuses,'barriers'=>$barriers];
    }
}
