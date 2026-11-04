<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Existing Effects own the mechanics; this trait projects their pools under the caller's transaction. */
trait AF_ACS_BarrierPersistence
{
    public function barrierRows(string $sid,string $eid,bool $lock=false):array
    {
        $rows=$this->sql('SELECT p.*,e.session_id,e.encounter_id,e.source_entity_id,e.target_entity_id,e.definition_snapshot_id,e.effect_key,e.scope,e.stacks,e.remaining_duration,e.duration_unit,e.applied_order AS effect_order,e.effect_version,e.status AS effect_status,e.payload_json FROM '.$this->table('barrier_pools').' p JOIN '.$this->table('effects').' e ON e.id=p.effect_id WHERE e.session_id=? AND e.encounter_id=? ORDER BY p.applied_order,p.id'.($lock?' FOR UPDATE':''),[$sid,$eid])['rows'];
        foreach($rows as &$r)foreach(['id','effect_id','session_id','encounter_id','source_entity_id','target_entity_id','definition_snapshot_id'] as $k)$r[$k]=self::integer((string)$r[$k]);unset($r);return $rows;
    }
    public function barrierProjection(string $sid,string $eid):array
    {
        require_once __DIR__.'/../Domain/Effects.php';$rows=$this->barrierRows($sid,$eid);$pools=[];$next=1;$proof=hash_init('sha256');
        $reactions=$this->reactionProjection($sid,$eid)['entries'];
        $orphans=$this->sql('SELECT e.id FROM '.$this->table('effects').' e LEFT JOIN '.$this->table('barrier_pools')." p ON p.effect_id=e.id WHERE e.session_id=? AND e.encounter_id=? AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.kind'))='barrier' AND p.id IS NULL",[$sid,$eid])['rows'];if($orphans)throw new RuntimeException('ACS_BARRIER_PROJECTION_INVALID');
        foreach($rows as $r){$data=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR);$p=$data['pool']??[];$id=self::integer((string)$r['id']);$reaction=$reactions[$data['origin_reaction_id']??'']??null;
            $required=['source_entity_id','source_ref','scope','scope_key','targets','strength','maximum_strength','barrier_priority','applied_order','status','version','absorbs','duration'];if(!is_array($p)||array_diff($required,array_keys($p))||array_diff(array_keys($p),[...$required,'last_duration_boundary'])||!is_array($p['duration']??null)||array_keys($p['duration'])!==['value','unit'])throw new RuntimeException('ACS_BARRIER_SCHEMA_INVALID');
            if(($data['schema_version']??null)!==1||($data['kind']??null)!=='barrier'||!$reaction||$reaction['spec']['effect']['kind']!=='mass_defense'||($p['source_entity_id']??null)!==$r['source_entity_id']||($p['source_ref']??null)!==$reaction['spec']['ability_ref']||$r['scope']!=='combat')throw new RuntimeException('ACS_BARRIER_PIN_INVALID');
            $origin=$this->sql('SELECT definition_snapshot_id FROM '.$this->table('reactions').' WHERE id=?',[$reaction['id']])['rows'][0]??[];
            if($r['definition_snapshot_id']!==(string)($origin['definition_snapshot_id']??'')||$r['effect_key']!==$p['source_ref']['key']||$r['duration_unit']!==$p['duration']['unit']||$r['effect_status']!==$p['status']||(int)$r['stacks']!==($p['status']==='active'?1:0))throw new RuntimeException('ACS_BARRIER_PIN_INVALID');
            $spec=$reaction['spec']['effect'];$targets=$reaction['protected_targets'];
            if($p['scope']==='per_target')$targets=[$r['target_entity_id']];
            if($p['targets']!==$targets||!in_array($r['target_entity_id'],$reaction['protected_targets'],true)||$p['scope']!==$spec['scope']||$p['maximum_strength']!==$spec['strength']||$p['absorbs']!==$spec['absorbs']||$p['barrier_priority']!==$spec['priority']||$p['duration']['unit']!==$spec['duration']['unit']||$p['duration']['value']>$spec['duration']['value']||$p['scope_key']!==($p['scope']==='per_target'?$r['target_entity_id']:$spec['scope_key']))throw new RuntimeException('ACS_BARRIER_PIN_INVALID');
            foreach(['strength','maximum_strength','applied_order','version'] as $k){$column=$k==='version'?'pool_version':$k;if($p[$k]!== (int)self::integer((string)$r[$column],'9007199254740991',true))throw new RuntimeException('ACS_BARRIER_PROJECTION_INVALID');}
            $members=array_column($this->sql('SELECT entity_id FROM '.$this->table('barrier_targets').' WHERE pool_id=? ORDER BY entity_id',[$id])['rows'],'entity_id');$expected=$p['targets'];sort($expected,SORT_STRING);$actual=array_map('strval',$members);sort($actual,SORT_STRING);
            if($actual!==$expected||$p['status']!==$r['status']||$p['scope']!==$r['pool_kind']||(int)$r['effect_version']!==$p['version']||(int)$r['remaining_duration']!==$p['duration']['value']||json_decode($r['absorbs_json'],true)!==['schema_version'=>1,'values'=>$p['absorbs']])throw new RuntimeException('ACS_BARRIER_PROJECTION_INVALID');
            $pools[$id]=$p;$next=max($next,$p['applied_order']+1);hash_update($proof,self::json(['schema_version'=>1,'row'=>$r,'targets'=>$actual]));
        }return ['pools'=>$pools,'next_order'=>$next,'fingerprint'=>hash_final($proof)];
    }
    protected function persistBarriers(string $sid,string $eid,array $before,array $after,array $updates):array
    {
        if(array_diff_key($before,$after))throw new RuntimeException('ACS_BARRIER_HISTORY_REQUIRED');$rows=[];foreach($this->barrierRows($sid,$eid,true) as $r)$rows[(string)$r['id']]=$r;$map=[];$changes=[];$origins=[];
        foreach($updates as $reaction=>$u)foreach($u['execution']['pool_ids']??[] as $id)$origins[$id]=(string)$reaction;
        foreach($after as $id=>$p){$id=(string)$id;$old=$before[$id]??null;if($old===$p)continue;
            if($old===null){if(!preg_match('/\Anew:(?:0|[1-9][0-9]*)\z/D',$id)||!isset($origins[$id]))throw new RuntimeException('ACS_BARRIER_ORIGIN_REQUIRED');
                $r=$this->sql('SELECT * FROM '.$this->table('reactions').' WHERE id=? AND encounter_id=?',[$origins[$id],$eid])['rows'][0]??null;if(!$r)throw new RuntimeException('ACS_BARRIER_ORIGIN_REQUIRED');
                $order=(int)$this->sql('SELECT COALESCE(MAX(applied_order),0) AS n FROM '.$this->table('effects').' WHERE session_id=?',[$sid])['rows'][0]['n']+1;
                $effect=$this->sql('INSERT INTO '.$this->table('effects').' (session_id,encounter_id,source_entity_id,target_entity_id,definition_snapshot_id,effect_key,scope,status,stacks,remaining_duration,duration_unit,applied_order,effect_version,parent_effect_id,payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)',[$sid,$eid,$p['source_entity_id'],$p['targets'][0],$r['definition_snapshot_id'],$p['source_ref']['key'],'combat',$p['status'],$p['status']==='active'?1:0,$p['duration']['value'],$p['duration']['unit'],$order,$p['version'],self::json(['schema_version'=>1,'kind'=>'barrier','origin_reaction_id'=>$origins[$id],'pool'=>$p])])['id'];
                $poolId=$this->sql('INSERT INTO '.$this->table('barrier_pools').' (effect_id,pool_kind,strength,maximum_strength,barrier_priority,applied_order,status,pool_version,absorbs_json) VALUES (?,?,?,?,?,?,?,?,?)',[$effect,$p['scope'],$p['strength'],$p['maximum_strength'],$p['barrier_priority'],$p['applied_order'],$p['status'],$p['version'],self::json(['schema_version'=>1,'values'=>$p['absorbs']])])['id'];
                foreach($p['targets'] as $target)$this->sql('INSERT INTO '.$this->table('barrier_targets').' (pool_id,entity_id) VALUES (?,?)',[$poolId,$target]);$map[$id]=$poolId;
            }else{$row=$rows[$id]??null;if(!$row||(int)$row['pool_version']!==$old['version']||$p['version']<=$old['version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                if($p['duration']['unit']!==$old['duration']['unit'])throw new RuntimeException('ACS_BARRIER_IMMUTABLE');
                foreach(['source_entity_id','source_ref','scope','scope_key','targets','maximum_strength','barrier_priority','absorbs','applied_order'] as $k)if($p[$k]!==$old[$k])throw new RuntimeException('ACS_BARRIER_IMMUTABLE');
                if($this->sql('UPDATE '.$this->table('barrier_pools').' SET strength=?,status=?,pool_version=? WHERE id=? AND pool_version=?',[$p['strength'],$p['status'],$p['version'],$id,$old['version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
                $data=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$data['pool']=$p;
                if($this->sql('UPDATE '.$this->table('effects').' SET status=?,stacks=?,remaining_duration=?,effect_version=?,payload_json=? WHERE id=? AND effect_version=?',[$p['status'],$p['status']==='active'?1:0,$p['duration']['value'],$p['version'],self::json($data),$row['effect_id'],$old['version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');$poolId=$id;
            }$changes[]=['id'=>$poolId,'virtual_id'=>$id,'before'=>$old,'after'=>$p];
        }return ['id_map'=>$map,'changes'=>$changes];
    }
    protected function expireCombatBarriers(array $session,array $encounter):array
    {
        $p=$this->barrierProjection($session['id'],$encounter['id']);$after=$p['pools'];
        foreach($after as &$pool)if($pool['status']==='active'){$pool['status']='expired';$pool['version']=AF_ACS_Numeric::integer($pool['version']+1,1,9007199254740991);}unset($pool);
        return $this->persistBarriers($session['id'],$encounter['id'],$p['pools'],$after,[]);
    }

}
