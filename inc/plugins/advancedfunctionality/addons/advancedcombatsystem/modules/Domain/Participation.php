<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Numeric.php';
require_once __DIR__.'/PreparedAction.php';

/** A1.28–31: participation is an axis separate from life, control, possessions and Clock. */
final class AF_ACS_Participation
{
    public const SCHEMA='ic-acs.participation.v1';
    public static function policy(mixed $p):array
    {
        if(!is_array($p)||count($p)!==6||array_diff(array_keys($p),['schema','spatial','obstacles','pursuit','release_to','rejoin_from'])
            ||($p['schema']??null)!==self::SCHEMA||!in_array($p['spatial']??null,['unmapped','same_zone'],true)
            ||($p['obstacles']??null)!=='none'||($p['pursuit']??null)!=='capable_hostile'||($p['release_to']??null)!=='surrendered'
            ||!is_array($p['rejoin_from']??null)||!array_is_list($p['rejoin_from'])||!$p['rejoin_from']||count($p['rejoin_from'])>3)throw new DomainException('ACS_PARTICIPATION_UNSUPPORTED');
        foreach($p['rejoin_from'] as $state)if(!is_string($state)||!in_array($state,['surrendered','escaped','withdrawn'],true))throw new DomainException('ACS_PARTICIPATION_UNSUPPORTED');
        if(count(array_unique($p['rejoin_from']))!==count($p['rejoin_from']))throw new DomainException('ACS_PARTICIPATION_UNSUPPORTED');return $p;
    }
    public static function kind(string $key):string
    {
        if(!preg_match('/\Aparticipation:(surrender|escape|group_withdrawal|capture)\z/D',$key,$m))throw new DomainException('ACS_PARTICIPATION_UNSUPPORTED');return $m[1];
    }
    public static function probability(float $runner,float $pursuer):float
    {
        AF_ACS_Numeric::number($runner);AF_ACS_Numeric::number($pursuer);
        if($runner<0||$pursuer<0||!is_finite($runner+$pursuer))throw new DomainException('ACS_PARTICIPATION_SPEED_INVALID');
        return $runner===0.0&&$pursuer===0.0?60.0:max(10.0,min(90.0,60.0+30.0*($runner-$pursuer)/($runner+$pursuer)));
    }
    public static function spatial(array &$state,array $policy,string $a,?string $b=null):void
    {
        $s=&$state['spatial'];$mapped=($s['pin']??null)!==null;
        if(($policy['spatial']==='unmapped')===$mapped)throw new DomainException('ACS_PARTICIPATION_SPATIAL_UNSUPPORTED');
        if($mapped){$zone=AF_ACS_Spatial::position($s,$a);if($zone===null)throw new DomainException('ACS_PARTICIPATION_SPATIAL_UNSUPPORTED');
            if($b!==null&&AF_ACS_Spatial::position($s,$b)!==$zone)throw new DomainException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');
            if($b!==null)AF_ACS_Spatial::check($s,$a,$b,['schema'=>'ic-acs.range.v1','distances'=>['near','engaged'],'line_of_effect'=>true]);}
        // No obstacle, evacuation, prisoner transport or teleport executor is implied by v1.
        foreach($state['objects']??[] as $o)if($o['status']==='active')throw new DomainException('ACS_PARTICIPATION_OBSTACLE_UNSUPPORTED');
    }
    public static function check(array $context,array &$state,string $runner,array $policy):array
    {
        self::spatial($state,$policy,$runner);$context['state']=$state;$speed=null;
        foreach($state['participants'] as $id=>$p){$id=(string)$id;
            if($id===$runner||$p['participation']!=='engaged'||($state['entities'][$id]['life_state']??null)!=='active'
                ||($context['relationships'][$runner][$id]??null)!=='hostile'||!AF_ACS_Effects::ordinaryActionAllowed($state,$id))continue;
            if($policy['spatial']==='same_zone'){$zone=AF_ACS_Spatial::position($state['spatial'],$id);if($zone===null)throw new DomainException('ACS_PARTICIPATION_SPATIAL_UNSUPPORTED');if($zone!==AF_ACS_Spatial::position($state['spatial'],$runner))continue;}
            try{AF_ACS_Behavior::check($state['status_instances']??[],$id,'participation:pursuit','ordinary',[$runner],$context['behavior_targets'][$id]??[],['kind'=>'any_entity'],$context['status_scope']);}
            catch(AF_ACS_FormulaError $e){if(in_array($e->diagnosticCode,['ACS_BEHAVIOR_ACTION_BLOCKED','ACS_BEHAVIOR_NO_LEGAL_TARGET','ACS_BEHAVIOR_TARGET_BLOCKED'],true))continue;throw $e;}
            if(empty($context['participation_visible'][$id]))throw new DomainException('ACS_PARTICIPATION_UNAVAILABLE');
            $value=(float)AF_ACS_Resolver::interactionActorStats($context,$id)['speed'];AF_ACS_Numeric::number($value);if($value<0)throw new DomainException('ACS_PARTICIPATION_SPEED_INVALID');$speed=$speed===null?$value:max($speed,$value);
        }
        $own=(float)AF_ACS_Resolver::interactionActorStats($context,$runner)['speed'];
        return ['automatic'=>$speed===null,'probability'=>$speed===null?100.0:self::probability($own,$speed)];
    }
    public static function plan(array $context,array &$state,string $actor,array $targets,array $spec,?array $tape,string $mode):array
    {
        $policy=self::policy($spec['policy']);$kind=$spec['kind'];$members=$state['participants'];$source=$members[$actor]??null;
        if(!$source||$source['participation']!=='engaged'||($state['entities'][$actor]['life_state']??null)!=='active')throw new DomainException('ACS_PARTICIPATION_STATE_CONFLICT');
        if($kind==='surrender'||$kind==='escape'){if($targets!==[$actor])throw new DomainException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');}
        elseif($kind==='capture'){if(count($targets)!==1||$targets[0]===$actor)throw new DomainException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');}
        elseif($kind==='group_withdrawal'){if(!in_array($actor,$targets,true))throw new DomainException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');}
        else throw new DomainException('ACS_PARTICIPATION_UNSUPPORTED');
        $out=[];$effects=[];$random=false;
        foreach($targets as $id){if(!in_array($id,$context['allowed_targets'][$actor]??[],true))throw new DomainException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');$p=$members[$id]??throw new DomainException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');$life=$state['entities'][$id]['life_state']??null;
            if($kind==='capture'){
                self::spatial($state,$policy,$actor,$id);AF_ACS_Cover::check($state,$actor,$id,['environment_policy'=>AF_ACS_Cover::policy(null)]);
                if(!in_array($p['participation'],['engaged','surrendered'],true)||$life==='dead')throw new DomainException('ACS_PARTICIPATION_TARGET_UNAVAILABLE');
                if($p['participation']!=='surrendered'&&$life!=='incapacitated')throw new DomainException('ACS_RESISTING_CAPTURE_UNSUPPORTED');
                $effects[]=['kind'=>'participation','entity_id'=>$id,'from'=>$p['participation'],'to'=>'captured'];$out[$id]=['result'=>'captured'];continue;
            }
            if($kind==='group_withdrawal'&&($p['group_id']!==$source['group_id']||empty($spec['authorized'][$id])))throw new DomainException('ACS_PARTICIPATION_CONTROL_DENIED');
            if($p['participation']!=='engaged')throw new DomainException('ACS_PARTICIPATION_STATE_CONFLICT');
            if($life!=='active'){$out[$id]=['result'=>'remains','reason'=>'incapable'];continue;}
            if($kind==='surrender'){$effects[]=['kind'=>'participation','entity_id'=>$id,'from'=>'engaged','to'=>'surrendered'];$out[$id]=['result'=>'surrendered'];continue;}
            if(!AF_ACS_Effects::ordinaryActionAllowed($state,$id)){$out[$id]=['result'=>'remains','reason'=>'blocked'];continue;}
            $check=self::check($context,$state,$id,$policy);$random=$random||!$check['automatic'];$success=null;
            if($mode==='seal'){
                if($check['automatic'])$success=true;else{$t=isset($tape['targets'])?($tape['targets'][$id]??null):$tape;if(!is_array($t)||!isset($t['hit'],$t['crit']))throw new DomainException('ACS_PARTICIPATION_ROLL_REQUIRED');AF_ACS_Effects::statusSample($t['crit']);AF_ACS_Effects::statusSample($t['hit']);$success=$t['hit']['u']*100.0<$check['probability'];}
                if($success)$effects[]=['kind'=>'participation','entity_id'=>$id,'from'=>'engaged','to'=>$kind==='escape'?'escaped':'withdrawn'];
            }
            $out[$id]=['result'=>$success===null?'conditional':($success?'departed':'failed')]+$check;
        }
        if($mode==='seal'&&in_array($kind,['surrender','capture'],true)&&$tape!==AF_ACS_PreparedAction::TAPE)throw new DomainException('ACS_PARTICIPATION_TAPE_INVALID');
        return ['effects'=>$effects,'results'=>$out,'needs_roll'=>$random];
    }
}
