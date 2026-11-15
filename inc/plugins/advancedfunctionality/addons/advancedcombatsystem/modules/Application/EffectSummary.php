<?php
if(!defined('IN_MYBB')){die('No direct access');}

/** Whitelists only. Callers must already authorize the controlled actor or GM immutable result. */
final class AF_ACS_EffectSummary
{
    public static function actor(array $instances,string $entity,string $encounter,array $available):array
    {
        $out=['forms'=>[],'granted_abilities'=>[],'effects'=>[]];
        foreach($instances as $r){if($r['status']!=='active'||$r['target_entity_id']!==$entity||$r['spec']['scope']==='combat'&&$r['encounter_id']!==$encounter)continue;
            $duration=['remaining'=>$r['remaining_duration'],'unit'=>$r['duration_unit']];$m=$r['spec']['mechanical_parameters'];
            if(isset($m['transform']))$out['forms'][]=['slot'=>$m['transform']['slot'],'mode'=>$m['transform']['mode']]+$duration;
            if(isset($r['grant'])&&$r['grant']['remaining_uses']>0&&in_array('granted:'.$r['id'],$available,true))$out['granted_abilities'][]=['key'=>'granted:'.$r['id'],'remaining_uses'=>$r['grant']['remaining_uses']]+$duration;
            foreach(['reflect_effect','absorb_effect'] as $kind)if(isset($m[$kind]))$out['effects'][]=['kind'=>$kind]+$duration;
        }return $out;
    }
    /** GM/owner card projection, never raw definitions, policies, samples or Inventory receipts. */
    public static function mechanics(array $action,array $statuses,array $prepared,array $idMap=[]):array
    {
        $out=[];$actor=$action['actor_id'];$targets=[$actor,...($action['targets']??[])];foreach($action['strikes']??[$action] as $strike){$targets[]=$strike['resolved_target']??$targets[1]??$actor;
            foreach($strike['effect_responses']??[] as $r)$out[]=['type'=>'reflect_effect','executed'=>$r['executed'],'damage'=>$r['allocation']['integer_budget']??0];
            foreach($strike['effects_trace']??[] as $trace)foreach($trace['events']??[] as $event)if(($event['type']??null)==='absorb_effect')$out[]=['type'=>'absorb_effect','mode'=>$event['mode'],'amount'=>$event['amount']];
        }
        foreach($statuses as $r){if(!in_array($r['target_entity_id'],$targets,true))continue;$m=$r['spec']['mechanical_parameters'];
            if(isset($m['transform']))$out[]=['type'=>'form','slot'=>$m['transform']['slot'],'mode'=>$m['transform']['mode'],'remaining'=>$r['remaining_duration'],'unit'=>$r['duration_unit'],'status'=>$r['status']];
            if(isset($r['grant']))$out[]=['type'=>'granted_ability','remaining_uses'=>$r['grant']['remaining_uses'],'remaining'=>$r['remaining_duration'],'unit'=>$r['duration_unit'],'status'=>$r['status']];
            if(isset($r['transfer']))$out[]=['type'=>'effect_transfer','previous_effect_id'=>$r['transfer']['parent_effect_id'],'effect_id'=>(string)($idMap[$r['id']]??$r['id']),'status'=>$r['status']];
        }
        foreach($prepared as $r)if(($r['action_index']??null)===($action['action_index']??null))$out[]=['type'=>'prepared_result','phase'=>$r['policy']['phase']??$r['phase']??null,'outcome'=>$r['outcome'],'terminal_state'=>$r['terminal_state']];
        return $out;
    }
}
