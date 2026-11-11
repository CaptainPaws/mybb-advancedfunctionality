<?php
declare(strict_types=1);
require_once __DIR__.'/Effects.php';
require_once __DIR__.'/Modifiers.php';

/** Trusted pinned item actions on detached state. Never owner SQL, RNG or permanent equipment. */
final class AF_ACS_Items
{
    private static function fail(string $code): never {throw new AF_ACS_FormulaError($code,'items');}
    public static function bonuses(array $rules): array
    {
        foreach (['math_modifiers','passive_effects','bonuses','permanent_modifiers','on_equip','on_unequip','equip_requirements','application_conditions'] as $f) if (!empty($rules[$f])) self::fail('ACS_ITEM_RULE_UNSUPPORTED');
        foreach (['weapon','bonuses','passive_bonuses','on_equip','on_unequip','requirements'] as $f) if (!empty($rules['item'][$f])) self::fail('ACS_ITEM_RULE_UNSUPPORTED');
        if (array_diff(array_keys($rules['item']['equip']??[]),['slot','slots','stackable'])) self::fail('ACS_ITEM_RULE_UNSUPPORTED');
        $out=[];
        foreach (['base_stats','modifiers'] as $bucket) foreach ($rules[$bucket]??[] as $row) {
            $stat=$row['stat_key']??null;
            if (!in_array($stat,['accuracy','speed','crit_rate','crit_dmg','def'],true) || ($row['mode']??'flat')!=='flat' || !empty($row['condition_text'])) self::fail('ACS_ITEM_MODIFIER_PROVENANCE_BLOCKED');
            $out[$stat]=($out[$stat]??0)+AF_ACS_Numeric::number($row['value']??null);
        }
        return $out;
    }
    public static function rebase(array $stats,array $old,array $new,string $snapshot,array $pin): array
    {
        $trace=[];
        foreach (array_unique([...array_keys($old),...array_keys($new)]) as $stat) {
            $delta=($new[$stat]??0)-($old[$stat]??0);
            $modifier=['schema'=>AF_ACS_Modifiers::SCHEMA,'stat_key'=>$stat,'operation'=>'add_flat','value'=>$delta,'unit'=>'stat_points','phase'=>'stat','priority'=>0,'condition_ref'=>null];
            $source=['snapshot_id'=>$snapshot,'source_id'=>'combat-loadout-delta','source_type'=>'combat_equipment','source_version'=>$pin,'modifiers'=>[$modifier]];
            $r=AF_ACS_Modifiers::stage($stats[$stat]??null,$stat,'stat',[$source],['stat_keys'=>[$stat],'phases'=>['stat'],'unit'=>'stat_points','percent_unit'=>'percent','factor_unit'=>'factor','rounding'=>'none','output_type'=>'number']);
            $stats[$stat]=$r->serverResult()->clamped;$trace[]=$r->serverTrace();
            if ($stats[$stat]<0) self::fail('ACS_ITEM_MODIFIER_PROVENANCE_BLOCKED');
        }
        return [$stats,$trace];
    }
    public static function apply(array $spec,string $actor,array $targets,array $state,array $stats,array $virtual): array
    {
        if (($spec['schema_version']??null)!==1 || $targets!==[$actor] || !is_int($spec['item_id']??null) || ($spec['qty']??null)!==1 || !in_array($spec['operation']??null,['consume','equip','swap'],true)) self::fail('ACS_ITEM_ACTION_UNSUPPORTED');
        $id=$spec['item_id'];$item=$virtual['items'][$id]??null;
        if (!$item || ($item['fingerprint']??null)!==$spec['item_fingerprint'] || ($item['available']??0)<1) self::fail('ACS_ITEM_INSUFFICIENT_QTY');
        $effects=[];$modifierTrace=[];
        if ($spec['operation']==='consume') {
            foreach ($spec['effects'] as $effect) {
                if (($effect['op']??null)!=='add_hp' || !is_int($effect['value']??null) || $effect['value']<1 || array_diff(array_keys($effect),['op','value'])) self::fail('ACS_ITEM_EFFECT_UNSUPPORTED');
                $effects[]=['kind'=>'heal','entity_id'=>$actor,'amount'=>$effect['value']];
            }
            if (!$effects) self::fail('ACS_ITEM_EFFECT_UNSUPPORTED');
            $virtual['items'][$id]['available']--;
        } else {
            if ($spec['slot']!=='weapon_mainhand' || $spec['subtype']!=='weapon') self::fail('ACS_ITEM_EQUIP_DENIED');
            $old=$virtual['equipment'][$actor][$spec['slot']]??null;
            if ($old && ($old['item_id']===$id || $spec['operation']==='equip') || !$old && $spec['operation']==='swap') self::fail('ACS_ITEM_EQUIP_CONFLICT');
            [$stats[$actor],$modifierTrace]=self::rebase($stats[$actor],$old['bonuses']??[],$spec['bonuses'],$spec['snapshot_id'],$spec['pin']);
            $virtual['equipment'][$actor][$spec['slot']]=['item_id'=>$id,'bonuses'=>$spec['bonuses'],'spec'=>$spec];
            // An equipped instance cannot simultaneously fund another item action in the post.
            $virtual['items'][$id]['available']--;
        }
        // A1.2/A1.3: one AP even at full HP/zero effect; no hit-dependent refund.
        $costs=[['kind'=>'ap','amount'=>1]];
        $r=AF_ACS_Effects::simulate($state,['actor_id'=>$actor,'costs'=>$costs,'effects'=>$effects]);
        return ['state'=>$r->serverState(),'stats'=>$stats,'virtual'=>$virtual,'trace'=>$r->serverTrace(),'modifier_trace'=>$modifierTrace,'costs'=>$costs];
    }
}
