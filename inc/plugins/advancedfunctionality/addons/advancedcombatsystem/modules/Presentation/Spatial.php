<?php
if(!defined('IN_MYBB')){die('No direct access');}
/** Small GM form set; no map, editor replacement, or client state editing. */
final class AF_ACS_SpatialPage
{
    private static function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function form(array $view,string $url,string $op,array $values,string $fields,string $label):void
    {
        global $mybb;$values+=['acs_encounter_op'=>'spatial_'.$op,'session_id'=>$view['session']['id'],'tid'=>$view['session']['tid'],'encounter_id'=>$view['encounter']['id'],'expected_version'=>$view['session']['session_version'],'expected_encounter_version'=>$view['encounter']['encounter_version'],'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code];
        echo '<form method="post" action="'.self::esc($url).'">';foreach($values as $key=>$value)echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';echo $fields.' <label>Причина <input name="reason" required maxlength="1000"></label> <button type="submit">'.self::esc($label).'</button></form>';
    }
    public static function render(array $view,string $url,AF_ACS_SpatialService $service):void
    {
        $data=$service->view($view['session']['id'],$view['encounter']['id']);echo '<h4>Зоны и позиции</h4>';
        if(!$data['enabled']){self::form($view,$url,'configure',['expected_spatial_version'=>'0'],'<label>KB combat template <input name="kb_key" required maxlength="64"></label>','Включить зоны по KB');return;}
        $names=array_column($data['zones'],'name','id');$options='';foreach($data['zones'] as $z)$options.='<option value="'.self::esc($z['id']).'">'.self::esc($z['name']).'</option>';
        $positions=array_column($data['positions'],null,'entity_id');foreach($view['participants'] as $p){$v=$positions[$p['entity_id']]??['version'=>0,'zone_id'=>null];$options='';foreach($data['zones'] as $z)$options.='<option value="'.self::esc($z['id']).'"'.($v['zone_id']===$z['id']?' selected':'').'>'.self::esc($z['name']).'</option>';echo '<p>'.self::esc($p['name']).' · '.self::esc($names[$v['zone_id']]??'Позиция не назначена').'</p>';self::form($view,$url,'position',['entity_id'=>$p['entity_id'],'expected_spatial_version'=>$v['version']],'<label>Зона <select name="zone_id">'.$options.'</select></label> <label>Позиция <select name="visibility"><option value="known"'.(($v['visibility']??'known')==='known'?' selected':'').'>Доступна осведомлённым</option><option value="gm"'.(($v['visibility']??'known')==='gm'?' selected':'').'>Только GM</option></select></label>','Назначить позицию');}
        foreach($data['connections']??[] as $c){echo '<p>'.self::esc($names[$c['from']]??$c['from']).' → '.self::esc($names[$c['to']]??$c['to']).' · '.self::esc($c['status']).'</p>';self::form($view,$url,'connection',['from'=>$c['from'],'to'=>$c['to'],'expected_spatial_version'=>$c['version']],'<select name="status"><option value="open">Открыть</option><option value="blocked">Блокировать</option></select>','Изменить связь');}
    }
}
