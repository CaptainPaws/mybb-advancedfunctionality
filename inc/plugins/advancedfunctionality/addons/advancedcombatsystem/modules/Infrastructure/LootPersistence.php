<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Domain/Rewards.php';
require_once __DIR__.'/RandomSource.php';

trait AF_ACS_LootPersistence
{
    public function configureLoot(array $session,array $encounter,array $source,array $graph):array
    {
        $this->commandWrite();
        if($session['status']!=='active'||$encounter['status']!=='preparation'||$encounter['round_number']!=='0'||$encounter['start_operation_id']!==null)throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        if($graph['definition']['source_kind']!==$source['kind'])throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        if($source['kind']==='monster'){
            $e=$this->entity($session['id'],$source['entity_id']);
            if(!$e||$e['kind']!=='monster'||!$this->sql('SELECT id FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$encounter['id'],$source['entity_id']])['rows'])throw new RuntimeException('ACS_LOOT_SOURCE_UNAVAILABLE');
            $source['entity_snapshot_id']=$e['template_snapshot_id'];
        }
        $config=json_decode($encounter['config_json'],true,64,JSON_THROW_ON_ERROR);$sources=$config['loot_sources']??[];
        if(count($sources)>=16||isset($sources[$source['identity']]))throw new RuntimeException('ACS_LOOT_SOURCE_ALREADY_PINNED');
        $snapshot=$this->insertSnapshot($session['id'],['source_system'=>'knowledgebase','source_key'=>$source['kb_key'],'contract_id'=>AF_ACS_Rewards::SCHEMA,'contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>AF_ACS_Rewards::ENGINE,'source'=>$source,'graph'=>$graph]);
        $config['loot_sources'][$source['identity']]=['snapshot_id'=>$snapshot,'hash'=>$this->sql('SELECT content_hash FROM '.$this->table('snapshots').' WHERE id=?',[$snapshot])['rows'][0]['content_hash']];
        $pin=$this->insertSnapshot($session['id'],['source_system'=>'acs','source_key'=>'encounter-loot-config','contract_id'=>'ic-acs.encounter-config.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>AF_ACS_Rewards::ENGINE,'config'=>$config]);
        if($this->sql('UPDATE '.$this->table('encounters').' SET config_json=?,config_snapshot_id=?,encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=?',[self::json($config),$pin,$encounter['id'],$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$encounter['id'],'encounter_sequence'=>$encounter['next_event_sequence'],'encounter_version'=>$this->encounter($session['id'],$encounter['id'])['encounter_version'],'source_key'=>$source['identity'],'definition_snapshot_id'=>$snapshot];return $session;
    }
    public function lootPool(string $sid,string $eid,string $source):?array
    {
        $r=$this->sql('SELECT * FROM '.$this->table('loot_pools').' WHERE session_id=? AND encounter_id=? AND source_key=?',[$sid,$eid,$source])['rows'][0]??null;
        if(!$r)return null;
        if(!hash_equals($r['result_hash'],hash('sha256',$r['result_json'])))throw new RuntimeException('ACS_LOOT_RESULT_INVALID');
        $g=json_decode($r['result_json'],true,64,JSON_THROW_ON_ERROR);
        if(($g['session_id']??null)!==$sid||($g['encounter_id']??null)!==$eid||($g['source']['identity']??null)!==$source||($g['generation_key']??null)!==$r['generation_key']||($g['outcome']['outcome_id']??null)!==$r['outcome_id']||($g['definition_snapshot_id']??null)!==(string)$r['definition_snapshot_id']||($g['event_id']??null)!==($r['event_id']===null?null:(string)$r['event_id']))throw new RuntimeException('ACS_LOOT_RESULT_INVALID');
        $rows=$this->sql('SELECT e.id,e.item_snapshot_id,e.qty,e.result_json,x.payload_json,x.content_hash FROM '.$this->table('loot_entries').' e JOIN '.$this->table('snapshots').' x ON x.id=e.item_snapshot_id AND x.session_id=? WHERE e.pool_id=? ORDER BY e.id',[$sid,$r['id']])['rows'];
        if(count($rows)!==count($g['entries']))throw new RuntimeException('ACS_LOOT_RESULT_INVALID');
        foreach($rows as $i=>$entry){
            $saved=$g['entries'][$i];$item=json_decode($entry['payload_json'],true,64,JSON_THROW_ON_ERROR)['definition']??[];
            if((string)$entry['id']!==$saved['entry_id']||(string)$entry['item_snapshot_id']!==$saved['item_snapshot_id']||(int)$entry['qty']!==$saved['qty']||json_decode($entry['result_json'],true,64,JSON_THROW_ON_ERROR)!==array_diff_key($saved,['entry_id'=>1])||!hash_equals($entry['content_hash'],hash('sha256',$entry['payload_json']))||($item['definition_version']['fingerprint']??null)!==$saved['item_ref']['fingerprint'])throw new RuntimeException('ACS_LOOT_RESULT_INVALID');
        }
        foreach($g['roll_ids'] as $i=>$id){
            $roll=$this->sql('SELECT session_id,causal_key,check_kind,roll_index,rng_version,value_json FROM '.$this->table('rolls').' WHERE id=?',[$id])['rows'][0]??null;
            if(!$roll||(string)$roll['session_id']!==$sid||$roll['causal_key']!==$g['generation_key'].':'.$i||$roll['check_kind']!=='loot.'.$g['mode']||(int)$roll['roll_index']!==$i||$roll['rng_version']!=='uniform53-v1'||json_decode($roll['value_json'],true,16,JSON_THROW_ON_ERROR)!==['schema_version'=>1]+$g['tape'][$i])throw new RuntimeException('ACS_LOOT_RESULT_INVALID');
        }
        return ['pool_id'=>(string)$r['id'],'pool_version'=>(string)$r['pool_version'],'status'=>$r['status'],'result_hash'=>$r['result_hash'],'generation'=>$g];
    }
    public function lootReplay(string $sid,string $eid,string $source):?array
    {
        $pool=$this->lootPool($sid,$eid,$source);if(!$pool)return null;
        $r=$this->sql('SELECT o.result_json,o.status,o.payload_hash,o.request_json,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('loot_pools').' p JOIN '.$this->table('events').' e ON e.id=p.event_id AND e.session_id=p.session_id AND e.encounter_id=p.encounter_id JOIN '.$this->table('event_details').' d ON d.event_id=e.id JOIN '.$this->table('operations')." o ON o.operation_key=e.operation_key WHERE p.id=? AND e.event_type='encounter.loot_generate'",[$pool['pool_id']])['rows'][0]??null;
        if(!$r||$r['status']!=='committed'||!hash_equals($r['payload_hash'],hash('sha256',$r['request_json']))||!hash_equals($r['content_hash'],hash('sha256',$r['payload_json']."\n".$r['visibility_json'])))throw new RuntimeException('ACS_LOOT_RESULT_INVALID');$receipt=json_decode($r['result_json'],true,64,JSON_THROW_ON_ERROR);
        $details=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(($receipt['pool']['generation']??null)!==$pool['generation']||($receipt['pool']['result_hash']??null)!==$pool['result_hash']||($receipt['event_id']??null)!==$pool['generation']['event_id']||($details['result']['pool']['generation']??null)!==$pool['generation']||($details['request']??null)!==json_decode($r['request_json'],true,64,JSON_THROW_ON_ERROR))throw new RuntimeException('ACS_LOOT_RESULT_INVALID');return $receipt;
    }
    public function generateLoot(array $session,array $encounter,string $identity,AF_ACS_RandomSource $rng):array
    {
        $this->commandWrite();$sid=$session['id'];$eid=$encounter['id'];
        if($session['status']!=='active'||$encounter['status']!=='completed'||!($outcome=$this->confirmedOutcome($sid,$eid)))throw new RuntimeException('ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED');
        $config=$this->configSnapshot($sid,$outcome['config_snapshot_id']);
        if(!$config||!hash_equals($config['content_hash'],hash('sha256',$config['payload_json'])))throw new RuntimeException('ACS_LOOT_PIN_INVALID');
        $c=json_decode($config['payload_json'],true,64,JSON_THROW_ON_ERROR)['config'];$pin=$c['loot_sources'][$identity]??throw new RuntimeException('ACS_LOOT_SOURCE_UNAVAILABLE');
        $p=$this->sql('SELECT * FROM '.$this->table('snapshots').' WHERE id=? AND session_id=?',[$pin['snapshot_id'],$sid])['rows'][0]??null;
        if(!$p||$p['content_hash']!==$pin['hash']||!hash_equals($p['content_hash'],hash('sha256',$p['payload_json'])))throw new RuntimeException('ACS_LOOT_PIN_INVALID');
        $p=json_decode($p['payload_json'],true,64,JSON_THROW_ON_ERROR);$graph=$p['graph'];$d=AF_ACS_Rewards::definition($graph['definition']);$source=$p['source'];
        if($source['identity']!==$identity)throw new RuntimeException('ACS_LOOT_PIN_INVALID');
        $resolution=$this->sql('SELECT candidate_json,candidate_hash FROM '.$this->table('encounter_resolutions')." WHERE session_id=? AND encounter_id=? AND status='confirmed'",[$sid,$eid])['rows'][0];
        if(!hash_equals($resolution['candidate_hash'],hash('sha256',$resolution['candidate_json'])))throw new RuntimeException('ACS_LOOT_ELIGIBILITY_UNAVAILABLE');
        $candidate=json_decode($resolution['candidate_json'],true,64,JSON_THROW_ON_ERROR);$facts=$candidate['loot_eligibility']??throw new RuntimeException('ACS_LOOT_ELIGIBILITY_UNAVAILABLE');
        $allowed=AF_ACS_Rewards::permitted($d,$source,$facts,$outcome['outcome']);$eligible=AF_ACS_Rewards::eligibility($d,$facts,$outcome['outcome']);
        if($allowed&&$source['kind']==='monster'){
            $member=array_values(array_filter($facts,fn($f)=>$f['entity_id']===$source['entity_id']))[0]??null;
            $hostile=false;foreach($candidate['relationships'] as $r)if($r['relationship']==='hostile'&&in_array($member['side_id']??null,[(string)$r['side_a'],(string)$r['side_b']],true)&&in_array($outcome['outcome']['winner_side_id'],[(string)$r['side_a'],(string)$r['side_b']],true))$hostile=true;
            if(!$hostile||$member['template_snapshot_id']!==$source['entity_snapshot_id'])$allowed=false;
        }
        $key='loot:'.hash('sha256',$sid.':'.$eid.':'.$outcome['outcome_id'].':'.$identity);$tape=[];$rolls=[];
        $count=$allowed?($d['mode']==='guaranteed'?0:($d['mode']==='independent_roll'?count($d['entries']):$d['draws'])):0;
        for($i=0;$i<$count;$i++){
            $sample=$rng->reaction();$tape[]=$sample;
            $rolls[]=$this->sql('INSERT INTO '.$this->table('rolls').' (session_id,sealed_action_id,causal_key,check_kind,roll_index,rng_version,value_json,created_at) VALUES (?,NULL,?,?,?,?,?,?)',[$sid,$key.':'.$i,'loot.'.$d['mode'],$i,'uniform53-v1',self::json(['schema_version'=>1]+$sample),time()])['id'];
        }
        $generated=$allowed?AF_ACS_Rewards::generate($d,$tape):[];
        $result=['schema_version'=>1,'engine_version'=>AF_ACS_Rewards::ENGINE,'generation_key'=>$key,'session_id'=>$sid,'encounter_id'=>$eid,'outcome'=>$outcome,'source'=>$source,'definition_snapshot_id'=>$pin['snapshot_id'],'table_version'=>$graph['table']['definition_version'],'mode'=>$d['mode'],'condition_met'=>$allowed,'eligibility'=>$eligible,'eligibility_hash'=>hash('sha256',self::json(['schema_version'=>1,'members'=>$facts])),'roll_ids'=>$rolls,'tape'=>$tape,'entries'=>[],'event_id'=>null];
        $id=$this->sql('INSERT INTO '.$this->table('loot_pools').' (session_id,encounter_id,generation_key,outcome_id,source_key,definition_snapshot_id,status,pool_version,mode,event_id,result_json,result_hash) VALUES (?,?,?,?,?,?,?,1,?,NULL,?,?)',[$sid,$eid,$key,$outcome['outcome_id'],$identity,$pin['snapshot_id'],$generated?'open':'empty',$d['mode'],self::json($result),hash('sha256',self::json($result))])['id'];
        foreach($generated as $index=>$entry){
            $item=$graph['items'][$entry['key']]??throw new RuntimeException('ACS_LOOT_ITEM_PIN_INVALID');
            if(($item['definition_version']['fingerprint']??null)!==$entry['item']['fingerprint']||($item['definition_version']['declared_version']??null)!==$entry['item']['version'])throw new RuntimeException('ACS_LOOT_ITEM_PIN_INVALID');
            $snapshot=$this->insertSnapshot($sid,['source_system'=>'knowledgebase','source_key'=>$entry['item']['key'],'contract_id'=>'ic-acs.loot-item.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>AF_ACS_Rewards::ENGINE,'definition'=>$item]);
            $data=['schema_version'=>1,'source_entry_key'=>$entry['key'],'draw_index'=>$index,'item_ref'=>$entry['item'],'item_snapshot_id'=>$snapshot,'qty'=>$entry['qty'],'eligible_groups'=>$eligible];
            $entryId=$this->sql('INSERT INTO '.$this->table('loot_entries').' (pool_id,item_snapshot_id,qty,status,recipient_group_id,recipient_uid,allocation_version,issue_operation_key,result_json) VALUES (?,?,?,\'generated\',NULL,NULL,0,NULL,?)',[$id,$snapshot,$entry['qty'],self::json($data)])['id'];$result['entries'][]=['entry_id'=>$entryId]+$data;
        }
        // Event ref/hash are finalized by the existing command afterEvent, before its COMMIT.
        $this->sql('UPDATE '.$this->table('loot_pools').' SET result_json=?,result_hash=? WHERE id=?',[self::json($result),hash('sha256',self::json($result)),$id]);
        if($this->sql('UPDATE '.$this->table('encounters').' SET encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=?',[$eid,$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$eid,'encounter_sequence'=>$encounter['next_event_sequence'],'encounter_version'=>$this->encounter($sid,$eid)['encounter_version'],'pool'=>$this->lootPool($sid,$eid,$identity)];return $session;
    }
    public function linkLootEvent(array $session,string $event):array
    {
        $this->transactions->assertInternalBoundary();$pool=$session['_command_result']['pool'];$r=$pool['generation'];$r['event_id']=$event;$json=self::json($r);
        $this->sql('UPDATE '.$this->table('loot_pools').' SET event_id=?,result_json=?,result_hash=? WHERE id=? AND event_id IS NULL',[$event,$json,hash('sha256',$json),$pool['pool_id']]);
        $session['_command_result']['pool']=$this->lootPool($session['id'],$r['encounter_id'],$r['source']['identity']);return $session;
    }
}
