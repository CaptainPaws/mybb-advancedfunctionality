<?php
if (!defined('IN_MYBB')) {die('No direct access');}

/** Same ACS session lock/transaction, existing entries and command/event receipts. No issue calls. */
trait AF_ACS_LootAllocationPersistence
{
    public function allocationEntries(string $sid,string $eid):array
    {
        $rows=$this->sql('SELECT e.*,p.source_key FROM '.$this->table('loot_entries').' e JOIN '.$this->table('loot_pools').' p ON p.id=e.pool_id WHERE p.session_id=? AND p.encounter_id=? ORDER BY e.id LIMIT 2049',[$sid,$eid])['rows'];
        if(count($rows)>2048)throw new RuntimeException('ACS_LOOT_ALLOCATION_UNAVAILABLE');
        foreach($rows as &$r)foreach(['id','pool_id','item_snapshot_id','qty','recipient_group_id','recipient_uid','allocation_version'] as $k)if($r[$k]!==null)$r[$k]=(string)$r[$k];unset($r);return $rows;
    }
    public function allocationEntry(string $sid,string $eid,string $id):array
    {
        foreach($this->allocationEntries($sid,$eid) as $r)if($r['id']===$id)return $r;
        throw new RuntimeException('ACS_LOOT_ALLOCATION_UNAVAILABLE');
    }
    public function allocationPolicy(array $pool):array
    {
        $r=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE id=? AND session_id=?',[$pool['generation']['definition_snapshot_id'],$pool['generation']['session_id']])['rows'][0]??null;
        if(!$r||!hash_equals($r['content_hash'],hash('sha256',$r['payload_json'])))throw new RuntimeException('ACS_LOOT_PIN_INVALID');
        $d=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR)['graph']['definition']??[];AF_ACS_Rewards::definition($d);
        return AF_ACS_Rewards::allocationPolicy($d['allocation']??null);
    }
    public function allocationClaims(string $id):array
    {
        $rows=$this->sql('SELECT control_group_id,claim,claim_version,closed_at FROM '.$this->table('loot_claims').' WHERE loot_entry_id=? ORDER BY control_group_id',[$id])['rows'];
        foreach($rows as &$r)foreach(['control_group_id','claim_version','closed_at'] as $k)if($r[$k]!==null)$r[$k]=(string)$r[$k];unset($r);return $rows;
    }
    /** Frozen owner is the intended recipient; a current controller/delegate cannot replace it. */
    public function allocationRecipient(string $sid,array $eligible,string $group):array
    {
        $found=null;foreach($eligible as $g)if($g['control_group_id']===$group)$found=$g;
        if(!$found||$found['owner_uid']===null)throw new RuntimeException('ACS_LOOT_NOT_ELIGIBLE');
        $current=$this->sql('SELECT owner_uid FROM '.$this->table('control_groups').' WHERE id=? AND session_id=?',[$group,$sid])['rows'][0]??null;
        if(!$current||(string)$current['owner_uid']!==$found['owner_uid'])throw new RuntimeException('ACS_LOOT_NOT_ELIGIBLE');return $found;
    }
    public function allocationReceipt(string $sid,string $eid,array $entry):?array
    {
        if($entry['allocation_version']==='0')return null;
        $rows=$this->sql('SELECT e.id AS event_id,o.request_json,o.payload_hash,o.result_json,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('operations').' o JOIN '.$this->table('events').' e ON e.operation_key=o.operation_key AND e.session_id=o.session_id AND e.encounter_id=o.encounter_id JOIN '.$this->table('event_details')." d ON d.event_id=e.id WHERE o.session_id=? AND o.encounter_id=? AND o.status='committed' AND e.event_type=o.kind AND o.kind IN ('loot.gm_assign','loot.free_claim','loot.close') AND JSON_UNQUOTE(JSON_EXTRACT(o.result_json,'$.allocation.entry_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(o.result_json,'$.allocation.allocation_version'))=?",[$sid,$eid,$entry['id'],$entry['allocation_version']])['rows'];
        if(count($rows)!==1)throw new RuntimeException('ACS_LOOT_ALLOCATION_INVALID');$r=$rows[0];
        if(!hash_equals($r['payload_hash'],hash('sha256',$r['request_json']))||!hash_equals($r['content_hash'],hash('sha256',$r['payload_json']."\n".$r['visibility_json'])))throw new RuntimeException('ACS_LOOT_ALLOCATION_INVALID');
        $receipt=json_decode($r['result_json'],true,64,JSON_THROW_ON_ERROR);$a=$receipt['allocation'];$details=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(($receipt['session_id']??null)!==$sid||($receipt['encounter_id']??null)!==$eid||($receipt['event_id']??null)!==(string)$r['event_id']||$a['event_id']!==$receipt['event_id']||$a['allocation_id']!=='loot-allocation:'.$entry['id'].':'.$entry['allocation_version']||($details['request']??null)!==json_decode($r['request_json'],true,64,JSON_THROW_ON_ERROR)||($details['result']['allocation']??null)!==$a||$a['claims']!==$this->allocationClaims($entry['id'])||$a['mode']==='need_greed'&&$a['contenders']!==AF_ACS_Rewards::contenders($a['claims'])||$a['recipient_group_id']!==$entry['recipient_group_id']||$a['recipient_uid']!==$entry['recipient_uid'])throw new RuntimeException('ACS_LOOT_ALLOCATION_INVALID');
        if($a['roll']!==null){$roll=$this->sql('SELECT session_id,check_kind,roll_index,causal_key,rng_version,value_json FROM '.$this->table('rolls').' WHERE id=?',[$a['roll']['roll_id']])['rows'][0]??null;
            if(!$roll||(string)$roll['session_id']!==$sid||$roll['check_kind']!=='loot.need_greed.tie'||(string)$roll['roll_index']!=='0'||$roll['causal_key']!==$a['allocation_id']||$roll['rng_version']!=='uniform53-v1'||json_decode($roll['value_json'],true,16,JSON_THROW_ON_ERROR)!==['schema_version'=>1]+$a['roll']['sample']||AF_ACS_Rewards::lottery($a['contenders'],$a['roll']['sample'])!==$a['recipient_group_id'])throw new RuntimeException('ACS_LOOT_ALLOCATION_INVALID');}
        return $receipt;
    }
    public function allocationReplay(string $sid,string $eid,string $op,array $request):?array
    {
        if($op==='claim')return null;
        $e=$this->allocationEntry($sid,$eid,$request['entry_id']);if($e['allocation_version']==='0')return null;
        $r=$this->allocationReceipt($sid,$eid,$e);$a=$r['allocation'];
        if($op==='gm_assign'&&($a['mode']!=='gm_assign'||$a['recipient_group_id']!==$request['control_group_id'])||$op==='free_claim'&&($a['mode']!=='free_claim'||$a['recipient_group_id']!==$request['control_group_id'])||$op==='close'&&$a['mode']!=='need_greed')throw new RuntimeException('ACS_LOOT_ALLOCATION_CLOSED');return $r;
    }
    public function applyLootAllocation(array $session,array $encounter,string $op,array $r,array $pool,array $policy,AF_ACS_RandomSource $rng):array
    {
        $this->commandWrite();$entry=$this->allocationEntry($session['id'],$encounter['id'],$r['entry_id']);
        if($entry['status']!=='generated')throw new RuntimeException('ACS_LOOT_ALLOCATION_CLOSED');
        if($entry['allocation_version']!==$r['expected_allocation_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
        $eligible=$pool['generation']['eligibility'];$claims=$this->allocationClaims($entry['id']);$now=time();
        if(in_array($op,['claim','free_claim'],true)){
            $group=$this->allocationRecipient($session['id'],$eligible,$r['control_group_id']);if($group['owner_uid']!==$r['actor_uid'])throw new RuntimeException('ACS_LOOT_NOT_ELIGIBLE');
            $old=null;foreach($claims as $c)if($c['control_group_id']===$r['control_group_id'])$old=$c;
            if(($old['claim_version']??'0')!==$r['expected_claim_version']||($old['closed_at']??null)!==null)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $claim=$op==='free_claim'?'claim':$r['claim'];
            if($old){if($this->sql('UPDATE '.$this->table('loot_claims').' SET claim=?,claim_version=claim_version+1 WHERE loot_entry_id=? AND control_group_id=? AND claim_version=? AND closed_at IS NULL',[$claim,$entry['id'],$r['control_group_id'],$r['expected_claim_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');}
            else $this->sql('INSERT INTO '.$this->table('loot_claims').' (loot_entry_id,control_group_id,claim,claim_version,closed_at) VALUES (?,?,?,1,NULL)',[$entry['id'],$r['control_group_id'],$claim]);
            $claims=$this->allocationClaims($entry['id']);
        }
        $result=['entry_id'=>$entry['id'],'pool_id'=>$entry['pool_id'],'allocation_version'=>$entry['allocation_version']];
        if($op==='claim'){
            $own=array_values(array_filter($claims,fn($c)=>$c['control_group_id']===$r['control_group_id']))[0];$result+=['claim'=>$own];
        }else{
            $version=(string)$this->sql('SELECT allocation_version+1 AS v FROM '.$this->table('loot_entries').' WHERE id=?',[$entry['id']])['rows'][0]['v'];
            $identity='loot-allocation:'.$entry['id'].':'.$version;$roll=null;$contenders=[];
            if($op==='close'){
                $contenders=AF_ACS_Rewards::contenders($claims);$winner=$contenders[0]??null;
                // Validate every non-pass contender against the retained eligibility before RNG.
                foreach($claims as $c)if($c['claim']!=='pass')$this->allocationRecipient($session['id'],$eligible,$c['control_group_id']);
                if(count($contenders)>1){$sample=$rng->reaction();$winner=AF_ACS_Rewards::lottery($contenders,$sample);
                    $id=$this->sql('INSERT INTO '.$this->table('rolls').' (session_id,sealed_action_id,causal_key,check_kind,roll_index,rng_version,value_json,created_at) VALUES (?,NULL,?,\'loot.need_greed.tie\',0,\'uniform53-v1\',?,?)',[$session['id'],$identity,self::json(['schema_version'=>1]+$sample),$now])['id'];$roll=['roll_id'=>$id,'sample'=>$sample];}
            }else $winner=$r['control_group_id'];
            $recipient=$winner===null?null:$this->allocationRecipient($session['id'],$eligible,$winner);$status=$winner===null?'allocation_closed':'awaiting_issue';
            if($this->sql('UPDATE '.$this->table('loot_entries').' SET status=?,recipient_group_id=?,recipient_uid=?,allocation_version=allocation_version+1 WHERE id=? AND allocation_version=? AND status=\'generated\'',[$status,$winner,$recipient['owner_uid']??null,$entry['id'],$entry['allocation_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $this->sql('UPDATE '.$this->table('loot_claims').' SET closed_at=? WHERE loot_entry_id=? AND closed_at IS NULL',[$now,$entry['id']]);
            $remaining=$this->sql('SELECT id FROM '.$this->table('loot_entries')." WHERE pool_id=? AND status='generated' LIMIT 1",[$entry['pool_id']])['rows'];
            $poolStatus=$remaining?'open':($this->sql('SELECT id FROM '.$this->table('loot_entries')." WHERE pool_id=? AND status='awaiting_issue' LIMIT 1",[$entry['pool_id']])['rows']?'awaiting_issue':'allocation_closed');
            self::integer($pool['pool_version'],'18446744073709551614');
            if($this->sql('UPDATE '.$this->table('loot_pools').' SET status=?,pool_version=pool_version+1 WHERE id=? AND pool_version=?',[$poolStatus,$entry['pool_id'],$pool['pool_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $result=['entry_id'=>$entry['id'],'pool_id'=>$entry['pool_id'],'allocation_id'=>$identity,'allocation_version'=>$version,'mode'=>$policy['mode'],'recipient_group_id'=>$winner,'recipient_uid'=>$recipient['owner_uid']??null,'status'=>$status,'contenders'=>$contenders,'roll'=>$roll,'claims'=>$this->allocationClaims($entry['id']),'event_id'=>null];
        }
        self::integer($encounter['encounter_version'],'18446744073709551614');self::integer($encounter['next_event_sequence'],'18446744073709551614');
        if($this->sql('UPDATE '.$this->table('encounters').' SET encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=?',[$encounter['id'],$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$encounter['id'],'encounter_sequence'=>$encounter['next_event_sequence'],'allocation'=>$result];return $session;
    }
    public function linkAllocationEvent(array $session,string $event):array
    {$this->transactions->assertInternalBoundary();$session['_command_result']['allocation']['event_id']=$event;return $session;}
}
