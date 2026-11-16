<?php
if (!defined('IN_MYBB')) {die('No direct access');}

/** Pure reduction of verified, applied traces. Never allocation budgets, HP deltas or RNG. */
final class AF_ACS_Statistics
{
    public const METRICS=['actual_hp_damage','barrier_absorption','effective_healing','received_damage','hits','critical_hits','reactions','intercepts','applied_statuses','ap_spent','items_consumed'];
    private array $entities=[];
    private array $boundaries=[];
    private array $eventAggregates=[];
    private string $eventType='combat.resolved';
    public static function zero():array{return array_fill_keys(self::METRICS,0);}
    private function add(string $id,string $metric,mixed $n):void
    {
        if(!is_int($n)||$n<0||$n>9007199254740991)throw new RuntimeException('ACS_STATISTICS_TRACE_INVALID');
        if($n===0)return;
        $this->entities[$id]??=self::zero();$sum=$this->entities[$id][$metric]+$n;
        if(!is_int($sum)||$sum>9007199254740991)throw new RuntimeException('ACS_STATISTICS_OVERFLOW');
        $this->entities[$id][$metric]=$sum;
        $this->eventAggregates[$id][$this->eventType]??=self::zero();
        $this->eventAggregates[$id][$this->eventType][$metric]+=$n;
    }
    private function effects(array $trace,string $source,string $category):void
    {
        foreach($trace['events']??[] as $e){
            $id=(string)($e['entity_id']??$source);
            switch($e['type']??''){
                case 'damage':$this->add($source,'actual_hp_damage',$e['hp_loss']);$this->add($id,'received_damage',$e['hp_loss']);break;
                case 'barrier_absorb':$this->add($id,'barrier_absorption',$e['amount']);break;
                case 'heal':$this->add($source,'effective_healing',$e['effective_recovery']);break;
                case 'cost_paid':if($e['kind']==='ap')$this->add($id,'ap_spent',$e['amount']);break;
                case 'status_created':case 'status_refreshed':$this->add($source,'applied_statuses',1);break;
            }
        }
    }
    private function boundary(array $trace,array $statuses):void
    {
        $key=$trace['boundary_key']??null;if(!is_string($key))return;
        if(isset($this->boundaries[$key]))return;$this->boundaries[$key]=true;
        $previous=$this->eventType;$this->eventType='clock.status_tick';
        $objects=[];foreach($trace['events']??[] as $e)if(($e['type']??null)==='object.boundary')$objects[(string)$e['entity_id']]=$e['before']['source_entity_id'];
        foreach($trace['events']??[] as $e){
            if(($e['type']??null)==='cost_paid')$this->effects(['events'=>[$e]],(string)$e['entity_id'],'ticks');
            if(in_array($e['type']??null,['object.passive','object.triggered'],true)){
                $source=$objects[(string)$e['entity_id']]??throw new RuntimeException('ACS_STATISTICS_OBJECT_SOURCE_INVALID');
                $this->effects($e['effects'],(string)$source,'ticks');
            }
        }
        foreach($trace['events']??[] as $e)foreach($e['ticks']??[] as $tick){
            $source=$statuses[(string)$e['effect_id']]['source_entity_id']??null;
            if($source===null)throw new RuntimeException('ACS_STATISTICS_TICK_SOURCE_INVALID');
            if(isset($tick['effects']))$this->effects($tick['effects'],(string)$source,'ticks');
        }
        $this->eventType=$previous;
    }
    private function plan(array $plan):void
    {
        if(($plan['mode']??null)!=='seal'||!empty($plan['conditional']))throw new RuntimeException('ACS_STATISTICS_UNCONFIRMED');
        foreach($plan['actions']??[] as $action)foreach($action['strikes']??[$action] as $a){
            $actor=(string)$a['actor_id'];$target=(string)($a['resolved_target']??$a['targets'][0]??$actor);
            if(($a['rolls']['hit_success']??false)===true)$this->add($actor,'hits',1);
            if(($a['rolls']['crit_success']??false)===true)$this->add($actor,'critical_hits',1);
            // Primary trace may include legacy reflection/lifesteal steps. Only reflected damage
            // belongs to the defender; healing in the same trace belongs to the actor.
            foreach($a['effects_trace']??[] as $trace){
                $primary=$trace;$primary['events']=[];$reflected=$trace;$reflected['events']=[];
                foreach($trace['events']??[] as $e){if(($e['type']??'')==='damage'&&(string)$e['entity_id']===$actor&&$actor!==$target)$reflected['events'][]=$e;else $primary['events'][]=$e;}
                $this->effects($primary,$actor,'actions');$this->effects($reflected,$target,'reflection');
            }
            foreach($a['status_evaluations']??[] as $s)$this->effects(['events'=>$s['instance_events']??[]],(string)$s['source'],'statuses');
            foreach($a['reactions']??[] as $r)if(($r['executed']??false)===true){
                $id=(string)$r['entity_id'];$this->add($id,'reactions',1);
                if(($r['redirect_success']??false)===true)$this->add($id,'intercepts',1);
                $this->effects($r['effects']??[],$id,'reactions');
                foreach($r['passives']??[] as $passive)$this->effects($passive['effects'],$id,'reactions');
            }
            foreach($a['effect_responses']??[] as $r)if(($r['executed']??false)===true)foreach($r['effects_trace']??[] as $trace)$this->effects($trace,(string)$r['source'],'reflection');
            if(($a['object_interaction']['tool']['mode']??null)==='consumes')$this->add($actor,'items_consumed',1);
            if(($a['item_action']['operation']??null)==='consume')$this->add($actor,'items_consumed',$a['item_action']['qty']);
        }
    }
    public function combat(array $body):void
    {
        if(($body['persistent']??null)!==true)throw new RuntimeException('ACS_STATISTICS_UNCONFIRMED');
        $this->plan($body['plan']);
        foreach($body['changes']['prepared_executions']??[] as $p)$this->prepared($p);
        $statuses=($body['initial']['status_instances']??[])+($body['final']['status_instances']??[]);
        foreach($body['boundary_history']??[] as $b){$r=$b['result'];$this->boundary($r['statuses']['trace']??$r['trace']??$r,$statuses);}
        foreach($body['plan']['entity_end_boundaries']??[] as $trace)$this->boundary($trace,$statuses);
    }
    public function excludeCombat(array $body):void
    {
        foreach($body['plan']['entity_end_boundaries']??[] as $trace)if(isset($trace['boundary_key']))$this->boundaries[$trace['boundary_key']]=true;
    }
    public function prepared(array $p,string $eventType='combat.resolved'):void
    {
        if(($p['outcome']??null)!=='executed')return;
        $previous=$this->eventType;$this->eventType=$eventType;
        $this->plan($p['resolver_plan']);
        foreach($p['owner_receipts']??[] as $receipt)if(($receipt['operation_type']??null)==='consume'&&($receipt['status']??null)==='committed'){
            // Receipt quantity is taken from its verified typed owner result.
            $qty=$receipt['qty']??null;
            if($qty===null)throw new RuntimeException('ACS_STATISTICS_ITEM_RECEIPT_UNSUPPORTED');
            $this->add((string)$p['resolver_plan']['actions'][0]['actor_id'],'items_consumed',$qty);
        }
        $this->eventType=$previous;
    }
    public function clock(array $trace,array $statuses):void{$this->boundary($trace,$statuses);}
    public function preparation(string $actor,array $contract):void
    {
        $previous=$this->eventType;$this->eventType='encounter.prepared_register';
        foreach($contract['paid_costs']??[] as $cost)if($cost['kind']==='ap'&&$cost['state']==='paid')$this->add($actor,'ap_spent',$cost['amount']);
        foreach($contract['item_bindings']??[] as $item)if(($item['timing']??null)==='on_prepare')$this->add($actor,'items_consumed',$item['qty']);
        $this->eventType=$previous;
    }
    public function subjects(array $identities):array
    {
        $out=[];$groups=[];
        foreach($identities as $id=>$identity)$this->entities[(string)$id]??=self::zero();
        foreach($this->entities as $id=>$metrics){
            $identity=$identities[(string)$id]??throw new RuntimeException('ACS_STATISTICS_SUBJECT_INVALID');
            $base=['owner_uid'=>$identity['owner_uid'],'entity_kind'=>$identity['kind'],'metrics'=>$metrics,'event_aggregates'=>$this->eventAggregates[$id]??[]];
            $out[]=['subject_kind'=>'entity','subject_key'=>(string)$id]+$base;
            if(in_array($identity['kind'],['character','pet','summon'],true))$out[]=['subject_kind'=>$identity['kind'],'subject_key'=>(string)$id]+$base;
            if($identity['control_group_id']!==null){$gid=(string)$identity['control_group_id'];$groups[$gid]??=['owner_uid'=>$identity['group_owner_uid'],'entity_kind'=>'control_group','metrics'=>self::zero(),'event_aggregates'=>[]];
                foreach($metrics as $k=>$v){$n=$groups[$gid]['metrics'][$k]+$v;if(!is_int($n)||$n>9007199254740991)throw new RuntimeException('ACS_STATISTICS_OVERFLOW');$groups[$gid]['metrics'][$k]=$n;}
                foreach($base['event_aggregates'] as $type=>$values){$groups[$gid]['event_aggregates'][$type]??=self::zero();foreach($values as $k=>$n)$groups[$gid]['event_aggregates'][$type][$k]+=$n;}
            }
        }
        foreach($groups as $id=>$data)$out[]=['subject_kind'=>'control_group','subject_key'=>(string)$id]+$data;
        usort($out,static fn($a,$b)=>[$a['subject_kind'],$a['subject_key']]<=>[$b['subject_kind'],$b['subject_key']]);return $out;
    }
}
