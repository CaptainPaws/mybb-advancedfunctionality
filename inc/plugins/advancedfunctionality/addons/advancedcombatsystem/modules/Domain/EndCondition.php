<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Typed encounter rules only. Never infer death, rewards or victory from HP totals. */
final class AF_ACS_EndCondition
{
    public const SCHEMA = 'ic-acs.end-conditions.v1';

    private static function tokens(mixed $value, array $allowed): bool
    {
        if (!is_array($value) || !array_is_list($value) || !$value || count($value)>count($allowed)) return false;
        foreach ($value as $token) if (!is_string($token) || !in_array($token,$allowed,true)) return false;
        return count(array_unique($value))===count($value);
    }

    public static function policy(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value),['schema','conditions','allow_continuation','mutual_outcomes'])
            || ($value['schema']??null)!==self::SCHEMA || !is_bool($value['allow_continuation']??null)
            || !self::tokens($value['conditions']??null,['last_active_side','no_active_sides'])
            || !self::tokens($value['mutual_outcomes']??null,['draw','inconclusive'])) {
            throw new RuntimeException('ACS_END_CONDITION_UNSUPPORTED');
        }
        return $value;
    }

    public static function evaluate(array $policy, array $participants, array $relations, bool $departed=false): ?array
    {
        self::policy($policy);
        $sides=[];
        $active=[];
        foreach ($participants as $participant) {
            if (!in_array($participant['life_state'],['active','incapacitated','dead'],true)
                || !in_array($participant['participation'],['engaged','surrendered','escaped','withdrawn','captured','despawned'],true)) {
                throw new RuntimeException('ACS_END_STATE_INVALID');
            }
            // Departed participants retain their side; only engaged active participants can continue combat.
            if ($participant['participation']==='despawned' || !$departed && $participant['participation']!=='engaged') continue;
            $side=(string)($participant['side_id']??'');
            if ($side==='') throw new RuntimeException('ACS_END_STATE_INVALID');
            $sides[$side]=true;
            if ($participant['participation']==='engaged' && $participant['life_state']==='active') $active[$side]=true;
        }
        if (count($sides)<2) return null;

        // No alliance winner semantics in v1: each involved pair must be explicitly hostile.
        $hostile=[];
        foreach ($relations as $relation) {
            if ($relation['relationship']!=='hostile') continue;
            $hostile[$relation['side_a'].':'.$relation['side_b']]=true;
            $hostile[$relation['side_b'].':'.$relation['side_a']]=true;
        }
        $ids=array_keys($sides);
        for ($i=0;$i<count($ids);++$i) for ($j=$i+1;$j<count($ids);++$j) {
            if (!isset($hostile[$ids[$i].':'.$ids[$j]])) return null;
        }
        if (count($active)===1 && in_array('last_active_side',$policy['conditions'],true)) {
            return ['condition'=>'last_active_side','outcomes'=>['victory'],'winner_side_id'=>(string)array_key_first($active)];
        }
        if (!$active && in_array('no_active_sides',$policy['conditions'],true)) {
            return ['condition'=>'no_active_sides','outcomes'=>$policy['mutual_outcomes'],'winner_side_id'=>null];
        }
        return null;
    }
}
