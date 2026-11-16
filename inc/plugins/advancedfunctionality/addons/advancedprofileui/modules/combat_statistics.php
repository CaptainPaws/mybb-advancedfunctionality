<?php
if (!defined('IN_MYBB') || !defined('AF_APUI_ID')) {die('No direct access');}

/** IC-PROFILE v1: native viewer context, owner UID, whitelisted filters, read-only DTO. */
function af_apui_combat_statistics_contract(int $ownerUid,array $filters=[]):array
{
    global $db,$mybb;
    if(empty($mybb->usergroup['canviewprofiles'])||empty($mybb->usergroup['canview'])||!af_is_addon_enabled('advancedcombatsystem'))return [];
    require_once __DIR__.'/../../advancedcombatsystem/modules/Application/StatisticsService.php';
    return (new AF_ACS_StatisticsService($db,new AF_ACS_SessionAccess))->profile((string)$ownerUid,$filters);
}

/** Minimal lazy tab. No postbit/CharacterSheets queries, awards, writes or styling bundle. */
function af_apui_render_combat_statistics(int $ownerUid):string
{
    global $mybb;
    $filters=[];foreach(['entity','control_group','kind','category','event_type'] as $key){$value=$mybb->get_input($key);if($value!=='')$filters[$key]=$value;}
    $model=af_apui_combat_statistics_contract($ownerUid,$filters);
    if(!$model)return '<p>Боевая статистика недоступна.</p>';
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $labels=['actual_hp_damage'=>'Урон HP','barrier_absorption'=>'Поглощение барьерами','effective_healing'=>'Эффективное лечение','received_damage'=>'Полученный урон','hits'=>'Попадания','critical_hits'=>'Критические попадания','reactions'=>'Реакции','intercepts'=>'Перехваты','applied_statuses'=>'Наложенные статусы','ap_spent'=>'Потрачено AP','items_consumed'=>'Расход предметов'];
    $kinds=['entity'=>'Существа','character'=>'Персонажи','pet'=>'Питомцы','summon'=>'Призывы','control_group'=>'Группы управления'];
    $html='<section><h2>Боевая статистика</h2><p>Только подтверждённые завершённые сражения, доступные вам. Итоги разных видов субъектов не складываются.</p>';
    $html.='<form method="get" action="member.php"><input type="hidden" name="action" value="profile"><input type="hidden" name="uid" value="'.$ownerUid.'"><input type="hidden" name="af_profile_tab" value="combat">';
    $options=['event_type'=>['combat.resolved'=>'Боевые действия','clock.status_tick'=>'Периодические эффекты','encounter.prepared_register'=>'Оплата подготовки','encounter.ambush_start'=>'Начало из засады'],'kind'=>$kinds,'category'=>$labels,'entity'=>array_combine($model['filters']['entity'],$model['filters']['entity']),'control_group'=>array_combine($model['filters']['control_group'],$model['filters']['control_group'])];
    foreach($options as $key=>$values){$html.='<label>'.$escape(['event_type'=>'Тип исходного события','kind'=>'Вид субъекта','category'=>'Вид события','entity'=>'Существо','control_group'=>'Группа'][$key]).' <select name="'.$key.'"><option value="">Все</option>';
        foreach($values as $value=>$label)$html.='<option value="'.$escape($value).'"'.(($filters[$key]??'')===(string)$value?' selected':'').'>'.$escape($label).'</option>';$html.='</select></label> ';}
    $html.='<button type="submit">Применить</button></form>';
    foreach($model['totals_by_subject_kind'] as $kind=>$metrics){$html.='<h3>'.$escape($kinds[$kind]).'</h3><dl>';foreach($metrics as $key=>$value)$html.='<dt>'.$escape($labels[$key]).'</dt><dd>'.$escape($value).'</dd>';$html.='</dl>';}
    $html.='<h3>История завершённых сражений</h3><ul>';
    foreach($model['completed_encounters'] as $fight)$html.='<li><a href="'.$escape($fight['archive_url']).'">Сражение '.$escape($fight['encounter_id']).'</a>, версия '.$escape($fight['aggregate_version']).'</li>';
    return $html.'</ul></section>';
}
