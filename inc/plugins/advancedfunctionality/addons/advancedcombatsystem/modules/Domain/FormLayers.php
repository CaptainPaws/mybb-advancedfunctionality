<?php
declare(strict_types=1);
require_once __DIR__.'/Modifiers.php';

/** Typed temporary projections over retained status instances; no owner writes or callbacks. */
final class AF_ACS_FormLayers
{
    private const STATS=['max_hp','accuracy','speed','crit_rate','crit_dmg','def'];
    private static function fail(string $code='ACS_FORM_UNSUPPORTED'):never {throw new AF_ACS_FormulaError($code,'transform');}
    private static function fields(mixed $v,array $keys):void {if(!is_array($v)||array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    private static function key(mixed $v):void {if(!is_string($v)||!preg_match('/\A[a-z][a-z0-9_.-]{0,95}\z/D',$v))self::fail();}
    public static function spec(mixed $f):array
    {
        self::fields($f,['schema','key','slot','mode','compatible_with','overrides','modifiers','equipment','statuses','abilities']);
        if($f['schema']!=='ic-acs.transform.v1'||!in_array($f['slot'],['primary_form','form_modifier','cosmetic_form'],true)||!in_array($f['mode'],['modifier','override','hybrid'],true))self::fail();self::key($f['key']);
        if(!is_array($f['compatible_with'])||!array_is_list($f['compatible_with'])||count(array_unique($f['compatible_with']))!==count($f['compatible_with']))self::fail();foreach($f['compatible_with'] as $key)self::key($key);
        foreach(['overrides','modifiers'] as $bucket){if(!is_array($f[$bucket])||array_diff(array_keys($f[$bucket]),self::STATS))self::fail();foreach($f[$bucket] as $stat=>$n){AF_ACS_Numeric::number($n);if($stat==='max_hp')AF_ACS_Numeric::integer($n,$bucket==='overrides'?1:-9007199254740991,9007199254740991);}}
        if($f['mode']==='modifier'&&$f['overrides']||$f['mode']==='override'&&$f['modifiers']||$f['slot']!=='primary_form'&&$f['overrides'])self::fail();
        self::fields($f['equipment'],['policy','slots']);$e=$f['equipment'];if(!in_array($e['policy'],['retain','suppress','adapt'],true)||!is_array($e['slots'])||!array_is_list($e['slots']))self::fail();$seen=[];
        foreach($e['slots'] as $slot){self::fields($slot,['slot','factor']);self::key($slot['slot']);if(isset($seen[$slot['slot']])||AF_ACS_Numeric::number($slot['factor'])<0||$slot['factor']>100||$e['policy']==='suppress'&&$slot['factor']!==0)self::fail();$seen[$slot['slot']]=true;}
        if($e['policy']==='retain'&&$e['slots']||$f['slot']!=='primary_form'&&($e['policy']!=='retain'||$e['slots']))self::fail();
        self::fields($f['statuses'],['default','rules']);if($f['statuses']['default']!=='persist'||!is_array($f['statuses']['rules'])||!array_is_list($f['statuses']['rules']))self::fail();$seen=[];
        foreach($f['statuses']['rules'] as $r){self::fields($r,['effect_key','policy','periodic_factor']);self::key($r['effect_key']);if(isset($seen[$r['effect_key']])||!in_array($r['policy'],['persist','suppress','remove','recalculate'],true)||AF_ACS_Numeric::number($r['periodic_factor'])<0||$r['periodic_factor']>100||$r['policy']!=='recalculate'&&$r['periodic_factor']!==1)self::fail();$seen[$r['effect_key']]=true;}
        self::fields($f['abilities'],['policy','keys']);if(!in_array($f['abilities']['policy'],['retain','restrict'],true)||!is_array($f['abilities']['keys'])||!array_is_list($f['abilities']['keys']))self::fail();foreach($f['abilities']['keys'] as $k)self::key($k);if($f['abilities']['policy']==='retain'&&$f['abilities']['keys'])self::fail();
        if($f['slot']!=='primary_form'&&($f['statuses']['rules']||($f['abilities']['policy']!=='retain'||$f['abilities']['keys'])))self::fail();
        if($f['slot']==='cosmetic_form'&&($f['mode']!=='modifier'||$f['modifiers']))self::fail();return $f;
    }
    public static function active(array $instances,string $entity):array
    {
        $out=[];$primary=0;foreach($instances as $row)if($row['status']==='active'&&$row['target_entity_id']===$entity&&isset($row['spec']['mechanical_parameters']['transform'])){
            $f=self::spec($row['spec']['mechanical_parameters']['transform']);if($f['slot']==='primary_form'&&++$primary>1)self::fail('ACS_FORM_PRIMARY_CONFLICT');$out[]=$row;
        }
        usort($out,static fn($a,$b)=>$a['applied_order']<=>$b['applied_order']?:strlen($a['id'])<=>strlen($b['id'])?:strcmp($a['id'],$b['id']));
        foreach($out as $i=>$a)foreach(array_slice($out,$i+1) as $b){$x=$a['spec']['mechanical_parameters']['transform'];$y=$b['spec']['mechanical_parameters']['transform'];if(!in_array($x['key'],$y['compatible_with'],true)||!in_array($y['key'],$x['compatible_with'],true))self::fail('ACS_FORM_COMPATIBILITY_CONFLICT');}return $out;
    }
    public static function requirement(mixed $r):array
    {self::fields($r,['schema','primary_forms']);if($r['schema']!=='ic-acs.form-requirement.v1'||!is_array($r['primary_forms'])||!array_is_list($r['primary_forms'])||!$r['primary_forms'])self::fail();foreach($r['primary_forms'] as $k)self::key($k);return $r;}
    public static function ability(array $instances,string $entity,string $key,?array $requirement=null):bool
    {
        $primary=null;foreach(self::active($instances,$entity) as $row){$f=$row['spec']['mechanical_parameters']['transform'];if($f['slot']==='primary_form')$primary=$f;if($f['abilities']['policy']==='restrict'&&!in_array($key,$f['abilities']['keys'],true))return false;}
        return $requirement===null||in_array($primary['key']??null,self::requirement($requirement)['primary_forms'],true);
    }
    public static function statusPolicy(array $instances,array $row):array
    {
        foreach(self::active($instances,$row['target_entity_id']) as $form)foreach($form['spec']['mechanical_parameters']['transform']['statuses']['rules'] as $rule)if($rule['effect_key']===$row['spec']['effect_key']){
            if($rule['policy']==='persist')return $rule;
            if($rule['policy']==='recalculate'&&empty($row['spec']['periodic']))self::fail('ACS_FORM_STATUS_POLICY_UNSUPPORTED');
            if(isset($row['spec']['mechanical_parameters']['transform']))self::fail('ACS_FORM_STATUS_POLICY_UNSUPPORTED');
            if(array_diff(array_keys($row['spec']['mechanical_parameters']),['blocks_ordinary_actions'])||isset($row['spec']['upkeep']))self::fail('ACS_FORM_STATUS_POLICY_UNSUPPORTED');
            return $rule;
        }return ['policy'=>'persist','periodic_factor'=>1];
    }
    public static function enabled(array $instances,array $row):bool {return self::statusPolicy($instances,$row)['policy']!=='suppress';}
    private static function value(int|float $base,string $stat,array $forms):int|float
    {
        $mods=[];$override=null;foreach($forms as $row){$f=$row['spec']['mechanical_parameters']['transform'];if(array_key_exists($stat,$f['overrides']))$override=$f['overrides'][$stat];if(array_key_exists($stat,$f['modifiers']))$mods[]=['schema'=>AF_ACS_Modifiers::SCHEMA,'stat_key'=>$stat,'operation'=>'add_flat','value'=>$f['modifiers'][$stat],'unit'=>'stat_points','phase'=>'form','priority'=>0,'condition_ref'=>null];}
        $base=$override??$base;if(!$mods)return $base;
        return AF_ACS_Modifiers::stage($base,$stat,'form',[['snapshot_id'=>'1','source_id'=>'temporary-form-layer','source_type'=>'transform','source_version'=>['fingerprint'=>'sha256:'.hash('sha256',serialize($forms))],'modifiers'=>$mods]],['stat_keys'=>[$stat],'phases'=>['form'],'unit'=>'stat_points','percent_unit'=>'percent','factor_unit'=>'factor','rounding'=>$stat==='max_hp'?'half_up':'none','output_type'=>$stat==='max_hp'?'integer':'number'])->serverResult()->clamped;
    }
    /** Current loadout is supplied by the existing equipment/custody projection, never a stored old kit. */
    public static function stats(array $base,array $instances,string $entity,?array $equipment=null):array
    {
        $forms=self::active($instances,$entity);if(!$forms)return $base;
        $overridden=[];$oldGear=[];$newGear=[];$factors=[];
        foreach($forms as $row){$f=$row['spec']['mechanical_parameters']['transform'];foreach($f['overrides'] as $stat=>$unused)if($stat!=='max_hp')$overridden[$stat]=true;foreach($f['equipment']['slots'] as $slot)$factors[$slot['slot']]=$slot['factor'];if(($overridden||$f['equipment']['policy']!=='retain')&&$equipment===null)self::fail('ACS_FORM_EQUIPMENT_UNPROVEN');}
        foreach($equipment??[] as $slot=>$bonuses)foreach($bonuses as $stat=>$bonus){$oldGear[$stat]=AF_ACS_Numeric::operation('add',$oldGear[$stat]??0,$bonus);$newGear[$stat]=AF_ACS_Numeric::operation('add',$newGear[$stat]??0,AF_ACS_Numeric::operation('multiply',$bonus,$factors[$slot]??1));}
        foreach(self::STATS as $stat)if($stat!=='max_hp'&&array_key_exists($stat,$base)){
            $value=$base[$stat]-($oldGear[$stat]??0);$value=self::value($value,$stat,$forms)+($newGear[$stat]??0);if($value<0)self::fail('ACS_FORM_STAT_INVALID');$base[$stat]=$value;
        }if(array_key_exists('hp',$base))$base['hp']=self::value($forms[0]['form_base_max_hp'],'max_hp',$forms);return $base;
    }
    /** Called only after a real status operation, never on read/preview hydration. */
    public static function transition(array $before,array &$after,array &$events):void
    {
        $targets=[];foreach([$before,$after] as $state)foreach($state['status_instances']??[] as $row)if($row['status']==='active'&&isset($row['spec']['mechanical_parameters']['transform']))$targets[$row['target_entity_id']]=true;
        foreach(array_keys($targets) as $id){$id=(string)$id;$old=self::active($before['status_instances'],$id);$forms=self::active($after['status_instances'],$id);
            if(!isset($after['entities'][$id])){if($old===$forms)continue;self::fail('ACS_FORM_TARGET_MISSING');}$base=$old[0]['form_base_max_hp']??$before['entities'][$id]['max_hp'];AF_ACS_Numeric::integer($base,1,9007199254740991);
            foreach($forms as $row){if(($row['spec']['mechanical_parameters']['transform']['equipment']['policy']!=='retain'||array_diff(array_keys($row['spec']['mechanical_parameters']['transform']['overrides']),['max_hp']))&&(!array_key_exists($id,$after['form_equipment']??[])||$after['form_equipment'][$id]===null))self::fail('ACS_FORM_EQUIPMENT_UNPROVEN');if(isset($row['form_base_max_hp'])&&$row['form_base_max_hp']!==$base)self::fail('ACS_FORM_BASE_CONFLICT');$after['status_instances'][$row['id']]['form_base_max_hp']=$base;}
            foreach($after['status_instances'] as &$row)if($row['status']==='active'&&$row['target_entity_id']===$id){$policy=self::statusPolicy($after['status_instances'],$row);if($policy['policy']==='remove'){$row['status']='removed';$row['stacks']=0;++$row['version'];$events[]=['type'=>'form_status_removed','id'=>$row['id']];}}unset($row);
            $max=self::value($base,'max_hp',$forms);AF_ACS_Numeric::integer($max,1,9007199254740991);$e=&$after['entities'][$id];if($e['max_hp']!==$max){$e['max_hp']=$max;$e['hp']=min($e['hp'],$max);++$e['version'];$events[]=['type'=>'form_max_hp','entity_id'=>$id,'maximum'=>$max,'current_hp'=>$e['hp']];}unset($e);
            if(array_column($old,'id')!==array_column($forms,'id'))$events[]=['type'=>'transform_changed','entity_id'=>$id,'before'=>array_column($old,'id'),'after'=>array_column($forms,'id')];
        }
    }
}
