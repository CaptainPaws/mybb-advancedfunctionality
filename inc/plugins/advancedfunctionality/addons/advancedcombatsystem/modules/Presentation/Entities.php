<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

final class AF_ACS_EntitiesPage
{
    private static function esc(mixed $value): string {return htmlspecialchars_uni((string)$value);}
    public static function render(string $sid,array $view,string $url,?AF_ACS_EntityService $service=null): void
    {
        global $mybb;
        $service??=af_advancedcombatsystem_entities();
        $labels=['character'=>'Персонаж игрока','pet'=>'Постоянный питомец','npc'=>'NPC','monster'=>'Монстр'];
        echo '<h2>Зарегистрированные существа</h2><table><tr><th>Экземпляр</th><th>Тип</th><th>Владелец / контроллер</th><th>Группа / снимок</th><th>Состояние жизни</th></tr>';
        $list=$service->list($sid,$mybb->get_input('entity_after')?:'0');
        foreach($list['items'] as $entity)echo '<tr><td>#'.self::esc($entity['id']).' '.self::esc($entity['name']).'</td><td>'.self::esc($labels[$entity['kind']]??$entity['kind']).'</td><td>'.self::esc($entity['owner_uid']??'—').' / '.self::esc($entity['controller_uid']??'—').'</td><td>'.self::esc($entity['control_group_id']).' / '.self::esc($entity['template_snapshot_id']).'</td><td>'.self::esc($entity['life_state']==='active'?'Дееспособен':($entity['life_state']==='incapacitated'?'Недееспособен':'Мёртв')).'</td></tr>';
        echo '</table><p>Снимки сохранены при регистрации. Просмотр не перечитывает шаблоны и не меняет листы. Участие в столкновении пока не назначено.</p>';
        if($list['next_after']!==null)echo '<a href="'.self::esc($url.'&session_id='.$sid.'&entity_after='.$list['next_after']).'">Следующие существа</a>';
        $own=$view['can_register_own'];
        if(!$own && !$view['can_manage'])return;
        if($view['session']['status']!=='active')return;
        $allowedKinds=array_merge($own?['character','pet']:[],$view['can_manage']?['npc','monster']:[]);
        $kind=$mybb->get_input('kind');if(!in_array($kind,$allowedKinds,true))$kind=$allowedKinds[0];
        echo '<h3>Добавить существо</h3><form method="get" action="'.self::esc(strtok($url,'?')).'">';
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        foreach($query+['session_id'=>$sid] as $field=>$value)echo '<input type="hidden" name="'.self::esc($field).'" value="'.self::esc($value).'">';
        echo '<label>Тип <select name="kind">';foreach($labels as $key=>$label)if(in_array($key,$allowedKinds,true))echo '<option value="'.$key.'"'.($kind===$key?' selected':'').'>'.$label.'</option>';
        echo '</select></label><button type="submit">Показать источники</button></form>';
        if($kind==='pet') {echo '<p>Плагин постоянных питомцев ещё не подключён. Связь с владельцем и общая группа управления предусмотрены; регистрация станет доступна после подключения проверенного источника.</p>';return;}
        $choices=$service->choices($sid,$kind,(int)$mybb->get_input('source_after'));
        if(empty($choices['items']))echo '<p>Нет доступных источников на этой странице. Для персонажа нужен собственный ARPG-лист; для NPC и монстра — определение бестиария KB.</p>';
        else {
            echo '<form method="post" action="'.self::esc($url).'">';
            foreach(['session_id'=>$sid,'expected_version'=>$view['session']['session_version'],'kind'=>$kind,'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code] as $key=>$value)echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
            echo '<label>Источник <select name="source_key" required>';
            foreach($choices['items'] as $choice)echo '<option value="'.self::esc($choice['key']).'"'.($choice['key']===$mybb->get_input('source_key')?' selected':'').'>'.self::esc($choice['label']).'</option>';
            echo '</select></label><label>Имя экземпляра <input name="name" maxlength="191" value="'.self::esc($mybb->get_input('name')).'"></label><label>Начальное HP <input name="initial_hp" type="number" min="0" max="9007199254740991" step="1" value="'.self::esc($mybb->get_input('initial_hp')).'"></label><p>Обязательно, если источник не хранит текущее HP. Не выше максимума; постоянное HP персонажа не изменяется.</p><label>Причина регистрации <input name="reason" maxlength="1000" required value="'.self::esc($mybb->get_input('reason')).'"></label><button name="op" value="entity_validate" type="submit">Проверить источник</button> <button name="op" value="entity_register" type="submit">Зарегистрировать</button></form>';
        }
        if(($choices['next_after']??null)!==null)echo '<a href="'.self::esc($url.'&session_id='.$sid.'&kind='.$kind.'&source_after='.$choices['next_after']).'">Следующие источники</a>';
    }
}
