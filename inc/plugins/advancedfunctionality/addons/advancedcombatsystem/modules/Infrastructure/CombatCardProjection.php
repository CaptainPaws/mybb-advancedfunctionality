<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Whitelist of already verified immutable calculations, called only for the authorized detailed audience. */
final class AF_ACS_CombatCardProjection
{
    private static function pick(array $row,array $keys):array {return array_intersect_key($row,array_flip($keys));}
    public static function entity(string $id):string {return 'Участник #'.$id;}
    private static function rolls(?array $rolls):array
    {
        if(!$rolls)return [];$out=self::pick($rolls,['hit_used','crit_used','hit_success','crit_success']);
        foreach(['hit','crit'] as $kind)if(isset($rolls[$kind]['u']))$out[$kind]=['u'=>$rolls[$kind]['u']];
        return $out;
    }
    private static function formulas(array $rows):array
    {
        $out=[];foreach($rows as $r){
            $inputs=[];foreach($r['inputs']??[] as $key=>$value)if(is_int($value)||is_float($value))$inputs[$key]=$value;
            $steps=[];foreach($r['steps']??[] as $step)$steps[]=self::pick($step,['index','type','operator','argument_indexes','raw']);
            $out[]=['ref'=>self::pick($r['formula_ref']??[],['key','version']),'inputs'=>$inputs,'steps'=>$steps,'terminal'=>self::pick($r['terminal']??[],['raw','rounded','clamped']),'unit'=>$r['output_unit']??null];
        }return $out;
    }
    /** Traverses only known effect-trace containers, never arbitrary definitions, policies or owner envelopes. */
    public static function events(array $trace,array $statuses=[]):array
    {
        $out=[];$keys=[
            'damage'=>['entity_id','allocated','absorbed','hp_loss','overkill','damage_type'],
            'heal'=>['entity_id','calculated','effective_recovery','overheal'],
            'barrier_absorb'=>['pool_id','entity_id','amount'],'barrier_applied'=>['pool_id'],
            'barrier_restored'=>['pool_id','restored','excess'],'barrier_duration'=>['pool_id','remaining_duration','status'],
            'status_created'=>['id'],'status_refreshed'=>['id','stacks','remaining_duration'],'status_removed'=>['id','stacks'],
            'status_duration'=>['id','remaining','status'],'status_combat_ended'=>['id'],'status_rejected'=>['id'],
            'status_removal_check'=>['kind','success'],'upkeep_result'=>['id','source_entity_id','paid','reason'],
            'sustained_stopped'=>['id','reason'],'absorb_effect'=>['mode','entity_id','amount','paid','resource'],
            'ability_copied'=>['id'],'effect_transferred'=>['old_id','new_id'],'effect_dispelled'=>['id'],
            'transform_changed'=>['entity_id'],'form_status_removed'=>['id'],'form_max_hp'=>['entity_id','maximum','current_hp'],
            'cost_paid'=>['entity_id','kind','resource','amount'],'participation'=>['entity_id','before','after'],
            'summon.despawned'=>['entity_id','remaining','reason'],'summon.independent'=>['entity_id','status'],
            'summon.duration'=>['entity_id','remaining'],'summon.upkeep'=>['entity_id'],
            'object.triggered'=>['entity_id','cause_id'],'object.passive'=>['entity_id','cause_id'],
        ];
        foreach($trace['events']??[] as $event){
            $type=$event['type']??'';
            if(isset($keys[$type])){
                $v=['type'=>$type]+self::pick($event,$keys[$type]);
                if(str_starts_with($type,'status_')&&isset($event['id'],$statuses[$event['id']])){
                    $s=$statuses[$event['id']];$v+=['effect_key'=>$s['spec']['effect_key'],'target_entity_id'=>$s['target_entity_id']];
                }
                $out[]=$v;
            }elseif(str_starts_with($type,'object.') && isset($event['entity_id'])){
                $out[]=['type'=>'object.change','entity_id'=>$event['entity_id'],'operation'=>substr($type,7),'before_status'=>$event['before']['status']??null,'after_status'=>$event['after']['status']??null];
            }
            if(isset($event['effects'])&&is_array($event['effects']))$out=[...$out,...self::events($event['effects'],$statuses)];
            if(isset($event['trace'])&&is_array($event['trace']))$out=[...$out,...self::events($event['trace'],$statuses)];
        }return $out;
    }
    private static function traces(array $rows,array $statuses):array
    {$out=[];foreach($rows as $r)$out=[...$out,...self::events($r,$statuses)];return $out;}
    private static function strike(array $s,array $statuses):array
    {
        $out=['target_id'=>(string)($s['resolved_target']??$s['targets'][0]??''),'original_target'=>(string)($s['original_target']??$s['targets'][0]??''),
            'outcome'=>$s['scenario'],'damage'=>$s['allocation']['integer_budget']??0,'rolls'=>self::rolls($s['rolls']??null),
            'probabilities'=>self::pick($s['probabilities']??[],['P_hit','P_crit']),'components'=>[],'formulas'=>self::formulas($s['formula_trace']??[]),'causes'=>[],'reactions'=>[],
            'events'=>self::traces($s['effects_trace']??[],$statuses)];
        foreach($s['allocation']['components']??[] as $c)$out['components'][]=self::pick($c,['damage_type','damage_element','raw','after_crit','effective_defense','post_defense','allocated','immunity']);
        if(isset($s['causal_id']))$out['causes'][]=['id'=>$s['causal_id'],'kind'=>'primary'];
        foreach($s['derived']??[] as $d)$out['causes'][]=self::pick($d,['id','cause','kind','basis','basis_hp_loss','calculated','skipped']);
        foreach($s['reactions']??[] as $r){
            if(($r['queue_kind']??null)==='prepared')continue;
            $v=self::pick($r,['id','entity_id','phase','executed','reason','redirect_success','original_target','resolved_target','causal_key']);
            if(isset($r['roll_ref']['id']))$v['roll_id']=$r['roll_ref']['id'];
            $v['events']=self::events($r['effects']??[],$statuses);
            foreach($r['passives']??[] as $p){$v['events']=[...$v['events'],...self::events($p['effects']??[],$statuses)];$out['causes'][]=self::pick($p,['causal_id','cause']);}
            $out['reactions'][]=$v;
        }
        foreach($s['effect_responses']??[] as $r){
            $out['causes'][]=self::pick($r,['id','kind','phase','parent_event_id','executed','reason','source','target']);
            if(isset($r['sample']['u']))$out['causes'][array_key_last($out['causes'])]['roll']=$r['sample']['u'];
            $out['events']=[...$out['events'],...self::traces($r['effects_trace']??[],$statuses)];
        }
        return $out;
    }
    public static function identities(array $dto):array
    {
        $out=[];foreach($dto as $key=>$value){
            if(in_array($key,['actor_id','entity_id','target_id','target_entity_id','original_target','resolved_target'],true)&&is_string($value)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$value))$out[$value]=true;
            if($key==='targets'&&is_array($value))foreach($value as $id)$out[(string)$id]=true;
            if(is_array($value))$out+=array_fill_keys(self::identities($value),true);
        }return array_map('strval',array_keys($out));
    }
    public static function labels(array $dto,array $labels):array
    {
        foreach($dto as $key=>&$value)if(is_array($value))$value=self::labels($value,$labels);unset($value);
        if(isset($dto['actor_id']))$dto['actor_name']=$labels[$dto['actor_id']]??self::entity((string)$dto['actor_id']);
        foreach(['entity_id'=>'entity_name','target_entity_id'=>'entity_name','target_id'=>'target_name','original_target'=>'original_name'] as $key=>$field)if(isset($dto[$key])&&is_string($dto[$key]))$dto[$field]=$labels[$dto[$key]]??self::entity((string)$dto[$key]);
        if(isset($dto['targets']))$dto['target_names']=array_map(static fn($id)=>$labels[$id]??self::entity((string)$id),$dto['targets']);
        return $dto;
    }
    public static function actions(array $summary,array $actions,array $statuses,array $prepared,array $idMap=[]):array
    {
        $out=[];foreach($actions as $i=>$action){
            $saved=$summary[$i]??[];$actor=(string)$action['actor_id'];
            $entry=['actor_id'=>$actor,'actor_name'=>self::entity($actor),'ability_key'=>$saved['ability_key']??$action['ability_ref']['key']??'Действие',
                'targets'=>array_map('strval',$action['targets']??[]),'outcome'=>$action['scenario'],
                'costs'=>array_map(static fn($c)=>self::pick($c,['kind','resource','amount']),$action['costs']??[]),
                'strikes'=>[],'mechanics'=>[],'prepared'=>[],'spawns'=>[]];
            foreach(['item'=>['operation','item_id','qty'],'interaction'=>['entity_id','key','outcome','progress'],'cooperation'=>['operation','progress','cap','completed'],'spatial'=>['operation'],'cover'=>['operation']] as $key=>$fields)if(isset($saved[$key]))$entry[$key]=self::pick($saved[$key],$fields);
            foreach($action['strikes']??[$action] as $s)$entry['strikes'][]=self::strike($s,$statuses);
            // Preserve stage-9 effect descriptions without reporting unrelated final statuses as newly applied.
            $touched=[];foreach($entry['strikes'] as $s){$events=$s['events'];foreach($s['reactions'] as $r)$events=[...$events,...$r['events']];foreach($events as $e)foreach(['id','new_id'] as $key)if(isset($e[$key],$statuses[$e[$key]]))$touched[$e[$key]]=$statuses[$e[$key]];}
            if(isset($action['granted_use']))$entry['granted_use']=self::pick($action['granted_use'],['remaining_uses']);
            $entry['mechanics']=AF_ACS_EffectSummary::mechanics($action,$touched,[],$idMap);
            foreach($prepared as $p)if(($p['action_index']??null)===($action['action_index']??$i)){
                $v=self::pick($p,['prepared_action_id','execution_id','parent_event_id','source_event_id','outcome','reason','terminal_state']);$v['phase']=$p['policy']['phase']??$p['phase']??null;
                $child=$p['resolver_plan']['actions']??[];$v['actions']=$child?self::actions([],$child,$statuses,[],$idMap):[];$entry['prepared'][]=$v;
            }
            foreach($action['spawns']??[] as $spawn)$entry['spawns'][]=self::pick($spawn,['kind','spawn_index','source_entity_id']);
            $out[]=$entry;
        }return $out;
    }
}
