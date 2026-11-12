<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Domain/Spawn.php';

/** Commit-only identity projection. The parent combat event is the durable source event. */
trait AF_ACS_SpawnPersistence
{
    private function commitSpawns(array $row,string $event,array $prepared,array $plan): array
    {
        $out=[];
        foreach($plan['spawns']??[] as $index=>$intent){
            $actor=$intent['source_entity_id'];$s=AF_ACS_Spawn::descriptor($intent['spec']);
            $action=$prepared['ordered'][$intent['action_index']];
            $ability=$prepared['context']['abilities'][$actor][$action['ability_key']];$binding=null;
            foreach($ability['spawns'] as $b)if($b['effect_index']===$intent['effect_index'])$binding=$b;
            if(!$binding || $binding['spec']!==$s)throw new RuntimeException('ACS_SPAWN_TEMPLATE_INVALID');
            $template=$binding['template'];$projection=AF_ACS_Spawn::template($s,$template);
            $source=$prepared['context']['spawn_sources'][$actor];$object=$s['kind']==='spawn_object';
            $key='spawn:'.$event.':'.$index;
            // UNIQUE spawn_once is the final durable guard. A duplicate cannot partially insert.
            if($this->sql('SELECT id FROM '.$this->table('entities').' WHERE spawn_key=? FOR UPDATE',[$key])['rows'])throw new RuntimeException('ACS_SPAWN_DUPLICATE_IDENTITY');
            $group=$object?null:$source['control_group_id'];
            if($s['group']==='independent')$group=$this->sql('INSERT INTO '.$this->table('control_groups').' (session_id,owner_uid,kind,group_version,config_json) VALUES (?,NULL,?,1,?)',
                [$row['session_id'],'independent',self::json(['schema_version'=>1,'admission'=>'next_round_pending','source_event_id'=>$event])])['id'];
            $spawn=['source_event_id'=>$event,'spawn_index'=>$index,'encounter_id'=>$row['encounter_id'],'source_entity_id'=>$actor,'side_id'=>$source['side_id'],'zone_id'=>null,
                'spec'=>$s,'admission'=>$object?'passive_object':'pending_roster','created_round_turn_id'=>$row['turn_id']];
            $snapshotPayload=['schema_version'=>1,'numeric_policy'=>AF_ACS_Numeric::POLICY,'engine_version'=>AF_ACS_Resolver::VERSION,'contract_id'=>'ic-acs.spawn-snapshot.v1',
                'spawn'=>$spawn,'source_snapshot'=>$template,'pinned_abilities'=>[],'combat_stats'=>$projection['stats'],'object_definition'=>$projection['properties']];
            // Keep the already pinned ability graph; never query KB at publication.
            $retained=$this->sql('SELECT x.payload_json FROM '.$this->table('entities').' e JOIN '.$this->table('snapshots').' x ON x.id=e.template_snapshot_id AND x.session_id=e.session_id WHERE e.session_id=? AND e.id=?',[$row['session_id'],$actor])['rows'][0]??throw new RuntimeException('ACS_SPAWN_SCOPE_INVALID');
            $available=json_decode($retained['payload_json'],true,64,JSON_THROW_ON_ERROR)['pinned_abilities']??[];
            $queue=$object?[]:[$template];
            while($queue){$value=array_pop($queue);
                if(!empty($value['resolved']) && ($value['kind']??null)==='arpg_ability'){
                    $abilityKey=$value['entry_key'];$dto=$available[$abilityKey]??null;
                    if(!$dto || ($dto['definition_version']['fingerprint']??null)!==$value['fingerprint'])throw new RuntimeException('ACS_SPAWN_TEMPLATE_INVALID');
                    if(!isset($snapshotPayload['pinned_abilities'][$abilityKey])){$snapshotPayload['pinned_abilities'][$abilityKey]=$dto;$queue[]=$dto;}
                }
                foreach($value as $child)if(is_array($child))$queue[]=$child;
            }
            $snapshot=$this->insertSnapshot($row['session_id'],['source_system'=>'knowledgebase','source_key'=>$s['template']['key'],'contract_id'=>'ic-acs.spawn-snapshot.v1','contract_version'=>'1'],$snapshotPayload);
            $name=$object?$projection['properties']['name']:($template['data']['definition']['name']??$template['title']??$s['template']['key']);
            if(!is_string($name)||trim($name)==='')$name=$s['template']['key'];
            $id=$this->insertEntityIdentity(['session_id'=>$row['session_id'],'control_group_id'=>$group,'kind'=>$object?'object':'summon','owner_uid'=>null,'sheet_id'=>null,
                'template_snapshot_id'=>$snapshot,'source_entity_id'=>$actor,'spawn_key'=>$key,'name'=>$name,'definition'=>['schema_version'=>1,'spawn'=>$spawn]]);
            if(!$object){
                $hp=$projection['hp'];$this->sql('INSERT INTO '.$this->table('entity_states').' (entity_id,life_state,hp,hp_max,state_version,stats_json,layers_json) VALUES (?,?,?,?,1,?,?)',
                    [$id,'active',$hp,$hp,self::json(['schema_version'=>1,'values'=>$projection['stats']]),self::json(['schema_version'=>1,'layers'=>[]])]);
                $this->sql('INSERT INTO '.$this->table('resources').' (entity_id,resource_key,current_value,maximum_value,is_integer,resource_version,definition_json) VALUES (?,?,?,?,1,1,?)',
                    [$id,'hp',$hp,$hp,self::json(['schema_version'=>1,'source'=>'pinned_spawn_template'])]);
                $controllers=$s['controller']==='gm_pool'?[['controller_uid'=>null,'mode'=>'gm_pool','valid_from'=>time(),'valid_until'=>null]]:
                    $this->sql('SELECT * FROM '.$this->table('controllers').' WHERE session_id=? AND entity_id=? AND status=? AND valid_from<=? AND (valid_until IS NULL OR valid_until>?) ORDER BY id',[$row['session_id'],$actor,'active',time(),time()])['rows'];
                if(!$controllers)throw new RuntimeException('ACS_SPAWN_CONTROL_UNSUPPORTED');
                foreach($controllers as $controller)$this->sql('INSERT INTO '.$this->table('controllers').' (session_id,entity_id,controller_uid,mode,status,assignment_version,valid_from,valid_until,permissions_json) VALUES (?,?,?,?,?,1,?,?,?)',
                    [$row['session_id'],$id,$controller['controller_uid'],$controller['mode'],'active',$controller['valid_from'],$controller['valid_until'],self::json(['schema_version'=>1,'scope'=>'spawn_assignment','combat_operations_enabled'=>false,'source_entity_id'=>$actor])]);
            }
            $out[]=['entity_id'=>$id,'source_event_id'=>$event,'spawn_index'=>$index,'spawn_key'=>$key,'snapshot_id'=>$snapshot,'snapshot_hash'=>hash('sha256',self::json($snapshotPayload)),'kind'=>$s['kind'],'control_group_id'=>$group];
        }
        return $out;
    }
}
