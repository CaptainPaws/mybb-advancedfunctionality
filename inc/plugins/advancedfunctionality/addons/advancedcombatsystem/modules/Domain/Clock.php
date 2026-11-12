<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Effects.php';

enum AF_ACS_TurnState: string
{
    case Pending='pending';
    case Resolved='resolved';
    case Passed='passed';
    case Forfeited='forfeited';
}

/** Pure Clock identities and virtual boundary evaluation. No SQL or polling. */
final class AF_ACS_Clock
{
    public const VERSION='acs-clock-1';
    private static function id(string $id): string
    {
        if(!preg_match('/\A[1-9][0-9]{0,19}\z/D',$id) || (strlen($id)===20 && strcmp($id,'18446744073709551615')>0))throw new InvalidArgumentException('ACS_INVALID_INTEGER');return $id;
    }
    public static function apRefresh(string $roundId): string {return 'round:'.self::id($roundId).':ap_refresh';}
    public static function entityStart(string $entityTurnId): string {return 'entity_turn:'.self::id($entityTurnId).':start';}
    public static function entityEnd(string $entityTurnId): string {return 'entity_turn:'.self::id($entityTurnId).':end';}
    public static function roundEnd(string $roundId):string{return 'round:'.self::id($roundId).':next';}
    public static function hasObjectStartWork(array $objects,string $entity):bool
    {
        foreach($objects as $v)if($v['status']==='active'&&$v['source_entity_id']===$entity){$m=AF_ACS_Environment::definition($v['definition']);foreach(['passive','triggers'] as $kind)foreach($m[$kind] as $entry)if($entry['event']==='source_turn_start'&&($kind!=='triggers'||!isset($v['fired'][$entry['key']])))return true;}return false;
    }
    public static function hasSummonStartWork(array $summons,string $entity):bool
    {foreach($summons as $v)if($v['status']==='active'&&$v['source_entity_id']===$entity&&$v['upkeep']!==null)return true;return false;}
    public static function hasStartWork(array $instances,string $entity,string $sid,string $eid):bool
    {
        foreach($instances as $row)if($row['status']==='active'&&$row['session_id']===$sid&&(!empty($row['spec']['periodic'])||isset($row['spec']['upkeep']))&&($row['spec']['scope']!=='combat'||$row['encounter_id']===$eid)
            &&((isset($row['spec']['upkeep'])&&$row['source_entity_id']===$entity)||($row['duration_unit']!=='round'&&($row['duration_unit']==='own_turn'?$row['source_entity_id']:$row['target_entity_id'])===$entity)))return true;
        return false;
    }
    /** Same boundary vocabulary; pure status/Damage/Effects pipeline, no RNG or scheduler. */
    public static function advance(array $state,array $boundary,array $defenses=[],array $catalog=[]):AF_ACS_EffectsResult
    {
        if(($boundary['encounter_status']??null)!=='active')throw new RuntimeException('ACS_STATUS_CLOCK_PAUSED');
        $phase=$boundary['phase']??null;$start=in_array($phase,['entity_start','round_start'],true);
        $key=match($phase){'entity_start'=>self::entityStart($boundary['id']),'entity_end'=>self::entityEnd($boundary['id']),
            'round_start'=>self::apRefresh($boundary['id']),'round_end'=>self::roundEnd($boundary['id']),default=>throw new RuntimeException('ACS_STATUS_BOUNDARY_INVALID')};
        self::id($boundary['session_id']);self::id($boundary['encounter_id']);
        $initial=$state;$checked=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[]]);$state=$checked->serverState();$events=$checked->serverTrace()['events'];$signals=$checked->serverTrace()['signals'];$tickKeys=[];
        $queue=array_keys($state['status_instances']??[]);
        usort($queue,static fn($a,$b)=>$state['status_instances'][$a]['applied_order']<=>$state['status_instances'][$b]['applied_order']?:strlen((string)$a)<=>strlen((string)$b)?:strcmp((string)$a,(string)$b));
        foreach($queue as $id){$row=$state['status_instances'][$id];
            if($row['status']!=='active'||$row['session_id']!==$boundary['session_id']||($row['spec']['scope']==='combat'&&$row['encounter_id']!==$boundary['encounter_id']))continue;
            $round=$row['duration_unit']==='round';
            if($round!==str_starts_with($phase,'round_'))continue;
            if($round){if(!in_array($row['target_entity_id'],$boundary['entity_ids']??[],true))continue;}
            elseif(($row['duration_unit']==='own_turn'?$row['source_entity_id']:$row['target_entity_id'])!==($boundary['entity_id']??null))continue;
            $periodic=!empty($row['spec']['periodic']);
            $durationAtStart=$periodic&&($row['spec']['duration_event']??null)==='on_turn_start';
            $decrement=$start===$durationAtStart&&($row['last_duration_boundary']??null)!==$key;
            $doTick=$periodic&&$start&&($row['last_tick_boundary']??null)!==$key;
            if($periodic&&!$start&&($row['last_tick_boundary']??null)!==($round?self::apRefresh($boundary['id']):self::entityStart($boundary['id'])))$decrement=false;
            if(!$doTick&&!$decrement)continue;
            $steps=[];
            if($doTick&&$row['remaining_duration']>0){
                foreach($row['spec']['periodic'] as $tick){
                    $target=$row['target_entity_id'];if(!isset($state['entities'][$target]))throw new RuntimeException('ACS_STATUS_TARGET_MISSING');
                    if($state['entities'][$target]['life_state']==='dead'){$steps[]=['type'=>'tick_skipped','reason'=>'dead','entity_id'=>$target];continue;}
                    if($tick['kind']==='heal')$op=['kind'=>'heal','entity_id'=>$target,'amount'=>$tick['value']];
                    elseif($tick['kind']==='damage'){
                        $value=$tick['value'];$allocation=AF_ACS_Damage::calculate([['source_index'=>0,'damage_type'=>$tick['damage_type'],'damage_element'=>$tick['damage_element'],'raw'=>$value,'after_crit'=>$value]],$defenses[$target]??[],[],isset($catalog['by_target'])?($catalog['by_target'][$target]??throw new RuntimeException('ACS_STATUS_CATALOG_MISSING')):$catalog);
                        $op=['kind'=>'damage','targets'=>[['entity_id'=>$target,'allocation'=>$allocation]]];
                    }else throw new RuntimeException('ACS_STATUS_TICK_UNSUPPORTED');
                    $r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[$op]]);$state=$r->serverState();$steps[]=['effect_ref'=>$tick['effect_ref'],'source_ref'=>$tick['source_ref'],'effects'=>$r->serverTrace()];$signals=[...$signals,...$r->serverTrace()['signals']];
                }
                $tickKeys[]='effect:'.$id.':tick:'.$key;
            }
            $r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'status_duration_boundary','id'=>(string)$id,'boundary'=>$key,'tick'=>$doTick,'decrement'=>$decrement]]]);
            $state=$r->serverState();$events[]=['effect_id'=>(string)$id,'ticks'=>$steps,'duration'=>$r->serverTrace()['events']];
        }
        // Mandatory ticks and their immediate state transitions are complete before upkeep.
        if($phase==='entity_start'){
            $queue=array_keys($state['status_instances']??[]);
            usort($queue,static fn($a,$b)=>($state['status_instances'][$a]['upkeep_priority']??PHP_INT_MAX)<=>($state['status_instances'][$b]['upkeep_priority']??PHP_INT_MAX)
                ?:$state['status_instances'][$a]['applied_order']<=>$state['status_instances'][$b]['applied_order']?:strlen((string)$a)<=>strlen((string)$b)?:strcmp((string)$a,(string)$b));
            foreach($queue as $id){$row=$state['status_instances'][$id];
                if($row['status']!=='active'||!isset($row['spec']['upkeep'])||$row['source_entity_id']!==($boundary['entity_id']??null)||$row['session_id']!==$boundary['session_id']||($row['spec']['scope']==='combat'&&$row['encounter_id']!==$boundary['encounter_id']))continue;
                $r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'status_upkeep','id'=>(string)$id,'boundary'=>$key]]]);$state=$r->serverState();
                $events=[...$events,...$r->serverTrace()['events']];$signals=[...$signals,...$r->serverTrace()['signals']];
            }
        }
        foreach($state['barriers'] as $id=>&$pool){
            if($pool['status']!=='active'||($pool['last_duration_boundary']??null)===$key)continue;$unit=$pool['duration']['unit'];
            $eligible=$unit==='round'?$phase==='round_end':($phase==='entity_end'&&($unit==='own_turn'?$pool['source_entity_id']:$pool['targets'][0])===($boundary['entity_id']??null));
            if(!$eligible)continue;$pool['duration']['value']=max(0,$pool['duration']['value']-1);$pool['last_duration_boundary']=$key;$pool['version']=AF_ACS_Numeric::integer($pool['version']+1,1,9007199254740991);if($pool['duration']['value']===0)$pool['status']='expired';$events[]=['type'=>'barrier_duration','pool_id'=>(string)$id,'remaining_duration'=>$pool['duration']['value'],'status'=>$pool['status']];
        }unset($pool);
        $state=AF_ACS_Spawn::boundary($state,$boundary,$key,$events);
        $state=AF_ACS_Environment::boundary($state,$boundary,$key,$events);
        return new AF_ACS_EffectsResult($state,['engine_version'=>self::VERSION,'boundary_key'=>$key,'events'=>$events,'signals'=>$signals,'tick_keys'=>$tickKeys,'persistent'=>false],$initial);
    }
    /** This is a state graph, never authorization or permission to skip boundaries/publication. */
    public static function transitionExists(AF_ACS_TurnState $from,AF_ACS_TurnState $to): bool
    {
        return $from===AF_ACS_TurnState::Pending && in_array($to,[AF_ACS_TurnState::Resolved,AF_ACS_TurnState::Passed,AF_ACS_TurnState::Forfeited],true);
    }
    public static function closed(AF_ACS_TurnState $state): bool {return $state!==AF_ACS_TurnState::Pending;}
    public static function availableAP(int $budget,int $spent,int $commitment=0): int
    {
        if($budget<0 || $spent<0 || $spent>$budget || $commitment!==0)throw new RuntimeException('ACS_AP_STATE_INVALID');return $budget-$spent;
    }
    /** A1.7 + N1: exact integer quarter, one terminal half_up, then resource clamp. */
    public static function restHP(int $current,int $maximum,bool $full): int
    {
        if($current<0 || $maximum<0 || $current>$maximum || $maximum>9007199254740991)throw new RuntimeException('ACS_HP_RANGE');
        if($full)return $maximum;
        $recovery=intdiv($maximum,4)+(($maximum%4)>=2?1:0);
        return min($maximum,$current+$recovery);
    }
    public static function deadlinePolicy(array $config): array
    {
        $p=$config['deadline_policy']??['schema_version'=>1,'mode'=>'off','seconds'=>null];
        if(!is_array($p) || ($p['schema_version']??null)!==1 || !in_array($p['mode']??null,['off','notify','timeout_pass','gm_skip'],true))throw new RuntimeException('ACS_DEADLINE_POLICY_INVALID');
        if($p['mode']!=='off' && (!is_int($p['seconds']??null) || $p['seconds']<=0 || $p['seconds']>9007199254740991))throw new RuntimeException('ACS_DEADLINE_POLICY_INVALID');return $p;
    }
    public static function deadlineAfter(int $now,int $seconds): int
    {
        if($now<0 || $seconds<0 || $now>9007199254740991-$seconds)throw new RuntimeException('ACS_DEADLINE_RANGE');return $now+$seconds;
    }
    /** Stable serialization order is not initiative; every pending group has the same opportunity. */
    public static function rosterHash(array $groupPayloads): string
    {
        uksort($groupPayloads,static fn($a,$b)=>strlen((string)$a)<=>strlen((string)$b) ?: strcmp((string)$a,(string)$b));
        $parts=[];foreach($groupPayloads as $group=>$json)$parts[]=self::id((string)$group).':'.hash('sha256',$json);
        return hash('sha256',implode("\n",$parts));
    }
}
