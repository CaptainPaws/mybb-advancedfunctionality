<?php
if(!defined('IN_MYBB')||!defined('AF_APUI_ID')){die('No direct access');}

/** IC-PROFILE: existing consumer store, current native viewer, no writes. */
function af_apui_combat_statistics_contract(int $ownerUid,array $filters=[],string $cursor=''):array
{
    global $db,$mybb;
    if(empty($mybb->usergroup['canviewprofiles'])||empty($mybb->usergroup['canview'])||!af_is_addon_enabled('advancedcombatsystem'))return [];
    require_once __DIR__.'/../../advancedcombatsystem/modules/Application/StatisticsService.php';
    return (new AF_ACS_StatisticsService($db,new AF_ACS_SessionAccess))->profile((string)$ownerUid,$filters,$cursor);
}

/** Lazy native fragment; links/forms also work without interception as standalone fragments. */
function af_apui_render_combat_statistics(int $ownerUid):string
{
    global $mybb,$db;
    if(empty($mybb->usergroup['canviewprofiles'])||empty($mybb->usergroup['canview'])||!af_is_addon_enabled('advancedcombatsystem'))return '<p>Боевая статистика недоступна.</p>';
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $base=['action'=>'profile','uid'=>$ownerUid,'af_profile_tab'=>'combat'];$url=static fn($p)=>'member.php?'.http_build_query($base+$p);
    $view=$mybb->get_input('combat_view')==='achievements'?'achievements':'statistics';
    $html='<section class="af-apui-combat"><h2>Боевая статистика</h2><nav aria-label="Боевая история профиля">';
    foreach(['statistics'=>'Итоги и история','achievements'=>'Достижения'] as $key=>$label)$html.='<a data-af-acs-profile-page '.($view===$key?'aria-current="page" ':'').'href="'.$escape($url(['combat_view'=>$key])).'">'.$label.'</a> ';
    $html.='</nav>';
    if($view==='achievements'){
        require_once __DIR__.'/../../advancedcombatsystem/modules/Application/AchievementService.php';
        $model=(new AF_ACS_AchievementService($db,new AF_ACS_SessionAccess))->profile((string)$ownerUid,$mybb->get_input('achievement_cursor'));
        $html.='<h3>Разрешённые достижения</h3><p>Накопленный прогресс доступен владельцу и уполномоченным GM. Все источники должны быть завершены и иметь разрешённый reveal_all. Достижение не подтверждает начисление его награды.</p>';
        foreach($model['achievements'] as $a)$html.='<article><h4>'.$escape($a['name']).'</h4><p>'.$escape($a['subject_kind']).' #'.$escape($a['subject_key']).' · прогресс '.$escape($a['progress']).' · '.($a['unlocked']?'Открыто':'Ещё не открыто').'</p></article>';
        if(!$model['achievements'])$html.='<p>На этой странице нет доступных достижений. Скрытые источники не учитываются и не перечисляются.</p>';
        if($model['next_cursor']!==null)$html.='<p><a data-af-acs-profile-page href="'.$escape($url(['combat_view'=>'achievements','achievement_cursor'=>$model['next_cursor']])).'">Следующая страница достижений</a></p>';
        return $html.'</section>';
    }
    $filters=[];foreach(['entity','control_group','kind','category','event_type'] as $key){$value=$mybb->get_input($key);if($value!=='')$filters[$key]=$value;}
    $model=af_apui_combat_statistics_contract($ownerUid,$filters,$mybb->get_input('combat_cursor'));
    if(!$model)return $html.'<p>Боевая статистика недоступна.</p></section>';
    $labels=['actual_hp_damage'=>'Фактический урон HP','barrier_absorption'=>'Поглощение барьерами','effective_healing'=>'Эффективное лечение','received_damage'=>'Полученный урон','hits'=>'Попадания','critical_hits'=>'Критические попадания','reactions'=>'Реакции','intercepts'=>'Перехваты','applied_statuses'=>'Наложенные статусы','ap_spent'=>'Потрачено AP','items_consumed'=>'Расход предметов'];
    $kinds=['entity'=>'Существа','character'=>'Персонажи','pet'=>'Питомцы','summon'=>'Призывы','control_group'=>'Группы управления'];
    $html.='<p>Итоги всех доступных завершённых боёв по выбранным фильтрам. Виды субъектов не складываются между собой. Активные бои, скрытые и частичные архивы в эти итоги не входят.</p>';
    $html.='<form data-af-acs-profile-filter method="get" action="member.php">';foreach($base as $key=>$value)$html.='<input type="hidden" name="'.$key.'" value="'.$escape($value).'">';
    $options=['event_type'=>['combat.resolved'=>'Боевые действия','clock.status_tick'=>'Периодические эффекты','encounter.prepared_register'=>'Оплата подготовки','encounter.ambush_start'=>'Начало из засады'],'kind'=>$kinds,'category'=>$labels,'entity'=>array_combine($model['filters']['entity'],$model['filters']['entity']),'control_group'=>array_combine($model['filters']['control_group'],$model['filters']['control_group'])];
    foreach($options as $key=>$values){$html.='<label>'.$escape(['event_type'=>'Тип исходного события','kind'=>'Вид субъекта','category'=>'Категория показателей','entity'=>'Существо (ID)','control_group'=>'Группа (ID)'][$key]).' <select name="'.$key.'"><option value="">Все</option>';
        foreach($values as $value=>$label)$html.='<option value="'.$escape($value).'"'.(($filters[$key]??'')===(string)$value?' selected':'').'>'.$escape($label).'</option>';$html.='</select></label>';}
    $html.='<button>Применить</button><a data-af-acs-profile-page href="'.$escape($url([])).'">Сбросить</a></form>';
    if(!$model['totals_by_subject_kind'])$html.='<p>Нет разрешённых точных итогов. Это не нулевой результат и не подтверждение отсутствия боёв.</p>';
    foreach($model['totals_by_subject_kind'] as $kind=>$metrics){$html.='<details open><summary>'.$escape($kinds[$kind]).'</summary><dl>';foreach($metrics as $key=>$value)$html.='<dt>'.$escape($labels[$key]).'</dt><dd>'.$escape($value).'</dd>';$html.='</dl></details>';}
    $html.='<h3>История завершённых боёв</h3><ol>';
    foreach($model['completed_encounters'] as $fight)$html.='<li><a href="'.$escape($fight['archive_url']).'">Сражение #'.$escape($fight['encounter_id']).'</a> · версия '.$escape($fight['aggregate_version']).'</li>';
    $html.='</ol>';if(!$model['completed_encounters'])$html.='<p>На этой странице нет доступных боёв.</p>';
    if($model['next_cursor']!==null)$html.='<p><a data-af-acs-profile-page href="'.$escape($url($filters+['combat_cursor'=>$model['next_cursor']])).'">Следующая страница боёв</a></p>';
    return $html.'</section>';
}
