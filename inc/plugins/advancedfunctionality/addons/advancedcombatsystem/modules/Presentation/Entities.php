<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

final class AF_ACS_EntitiesPage
{
    private static function esc(mixed $value): string {return htmlspecialchars_uni((string)$value);}
    public static function render(string $sid,array $view,string $url,?AF_ACS_EntityService $service=null,bool $embedded=false,array $check=[],bool $picker=false): void
    {
        global $mybb;
        $service??=af_advancedcombatsystem_entities();
        $labels=['character'=>'Персонаж игрока','pet'=>'Постоянный питомец','npc'=>'NPC','monster'=>'Монстр'];
        if (!$picker) {
            echo '<h3>Зарегистрированные существа</h3>';
            if (!$embedded) echo '<div class="af-acs-standalone__table af-acs-standalone__table--entities" role="region" aria-label="Зарегистрированные существа" tabindex="0">';
            echo '<table><thead><tr><th>Экземпляр</th><th>Тип</th><th>Владелец / контроллер</th><th>Группа / снимок</th><th>Состояние жизни</th></tr></thead><tbody>';
            $list=$service->list($sid,$mybb->get_input('entity_after')?:'0');
            foreach($list['items'] as $entity)echo '<tr><td>#'.self::esc($entity['id']).' '.self::esc($entity['name']).'</td><td>'.self::esc($labels[$entity['kind']]??$entity['kind']).'</td><td>'.self::esc($entity['owner_uid']??'—').' / '.self::esc($entity['controller_uid']??'—').'</td><td>'.self::esc($entity['control_group_id']).' / '.self::esc($entity['template_snapshot_id']).'</td><td>'.self::esc($entity['life_state']==='active'?'Дееспособен':($entity['life_state']==='incapacitated'?'Недееспособен':'Мёртв')).'</td></tr>';
            if (!$list['items']) echo '<tr><td colspan="5">Нет доступных существ.</td></tr>';
            echo '</tbody></table>';
            if (!$embedded) echo '</div>';
            echo '<p>Снимки сохранены при регистрации. Просмотр не перечитывает шаблоны и не меняет листы. Участие в столкновении пока не назначено.</p>';
            if($list['next_after']!==null)echo '<a href="'.self::esc($url.($embedded?'&session_id='.$sid:'').'&entity_after='.$list['next_after']).'">Следующие существа</a>';
        }
        $own=$view['can_register_own'];
        $gmCharacters=!empty($view['can_register_characters']);
        if(!$own && !$view['can_manage'] && !$gmCharacters)return;
        if($view['session']['status']!=='active')return;
        $allowedKinds=array_merge($own || $gmCharacters?['character']:[],$own?['pet']:[],$view['can_manage']?['npc','monster']:[]);
        $kind=$mybb->get_input('kind');if(!in_array($kind,$allowedKinds,true))$kind=$allowedKinds[0];
        if (!$picker && !$embedded) echo '<details class="af-acs-standalone__disclosure"'.($mybb->get_input('kind')!==''?' open':'').'><summary>Добавить существо</summary>';
        elseif (!$picker) echo '<h3>Добавить существо</h3>';
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        if (!$picker) {
            echo '<form method="get" action="'.self::esc(strtok($url,'?')).'">';
            self::hidden($query+['session_id'=>$sid]);
            echo '<label>Тип <select name="kind">';
            foreach($labels as $key=>$label)if(in_array($key,$allowedKinds,true))echo '<option value="'.$key.'"'.($kind===$key?' selected':'').'>'.$label.'</option>';
            echo '</select></label><button type="submit">Показать источники</button></form>';
        }
        if($kind==='pet') {echo '<p>Плагин постоянных питомцев ещё не подключён. Связь с владельцем и общая группа управления предусмотрены; регистрация станет доступна после подключения проверенного источника.</p>';if(!$embedded)echo '</details>';return;}
        $slug=$kind==='character'?$mybb->get_input('source_slug'):'';
        $after=max(0,(int)$mybb->get_input('source_after'));
        if ($kind==='character') {
            echo '<form method="get" action="'.self::esc(strtok($url,'?')).'">';
            self::hidden($query+['session_id'=>$sid,'kind'=>'character']);
            $label=$gmCharacters?'Имя персонажа, игрок, slug или ID':'Slug своего листа';
            echo '<label>'.self::esc($label).' <input name="source_slug" maxlength="191" placeholder="'.($gmCharacters?'thread-52, tid:52 или id:32':'thread-52').'" value="'.self::esc($slug).'"></label>'
                .'<button type="submit">Найти персонажа</button></form>';
        }
        $choices=$service->choices($sid,$kind,$after,$slug);
        $selected=$mybb->get_input('source_key');
        $options=$choices['items']??[];
        if (!$options) {
            $reason=$choices['reason']??'';
            if ($reason!=='') echo self::diagnostics([['code'=>$reason,'path'=>'source']]);
            else echo '<p>Нет доступных источников по этому запросу.</p>';
        } else {
            if ($selected==='' && count($options)===1) $selected=$options[0]['key'];
            $formFields=($embedded?['acs_encounter_op'=>'entity_request','tid'=>$view['session']['tid']]:[])+[
                'session_id'=>$sid,'expected_version'=>$view['session']['session_version'],'kind'=>$kind,
                'my_post_key'=>$mybb->post_code,'source_slug'=>$slug,'source_after'=>$after,
            ];
            $selectable=false;
            echo '<form method="post" action="'.self::esc($url).'">';
            self::hidden($formFields+['operation_key'=>bin2hex(random_bytes(16))]);
            echo '<label>Источник <select name="source_key" required><option value="">Выберите источник</option>';
            foreach ($options as $choice) {
                $allowed=($choice['state']??'unchecked')==='unchecked';
                $selectable=$selectable||$allowed;
                echo '<option value="'.self::esc($choice['key']).'"'.($choice['key']===$selected?' selected':'').(!$allowed?' disabled':'').'>'
                    .self::esc($choice['label'].(empty($choice['owner_name'])?'':' · '.$choice['owner_name'])
                        .(isset($choice['thread_id'])?' · тема #'.$choice['thread_id']:''))
                    .(!$allowed?' · Недоступен':'').'</option>';
            }
            echo '</select></label><label>Имя экземпляра <input name="name" maxlength="191" value="'.self::esc($mybb->get_input('name')).'"></label>'
                .'<label>Начальное HP <input name="initial_hp" type="number" min="0" max="9007199254740991" step="1" value="'.self::esc($mybb->get_input('initial_hp')).'"></label>'
                .'<p>Укажите начальное HP, если источник не хранит текущее HP.</p>'
                .'<label>Причина регистрации <input name="reason" maxlength="1000" value="'.self::esc($mybb->get_input('reason')).'"></label>'
                .'<button name="op" value="entity_validate" type="submit"'.(!$selectable?' disabled':'').'>Проверить источник</button></form>';

            foreach ($options as $choice) {
                $checked=($check['source_key']??null)===$choice['key'];
                $state=$choice['state']??'unchecked';
                if ($picker && $choice['key']!==$selected && !$checked && $state==='unchecked') continue;
                $issues=$checked?($check['diagnostics']??[]):[];
                if (!$issues && $state!=='unchecked') $issues=array_merge([['code'=>$state,'path'=>'source']],$choice['diagnostics']??[]);
                $codes=array_column($issues,'code');
                $denied=(bool)array_intersect($codes,['access_denied','definition_access_denied','registration_ownership_required','equipment_access_denied']);
                $ready=$checked && !empty($check['ready']) && $state==='unchecked';
                $message=$checked?($check['message']??''):'';
                $status=$ready?'Готов к регистрации':($denied?'Недоступен по правам':(($issues || $message!=='')?'Требует исправлений':'Требуется проверка'));
                echo '<div class="atf-card"'.($picker && $checked?' data-acs-source-result tabindex="-1"':'').'><strong>'.self::esc($choice['label']).'</strong> · '.self::esc($status);
                if (!empty($choice['owner_name'])) echo ' · '.self::esc($choice['owner_name']);
                if (isset($choice['sheet_id'])) echo ' <small>Лист #'.self::esc($choice['sheet_id']).'</small>';
                if (isset($choice['sheet_url'])) echo ' · <a href="'.self::esc(self::forumUrl($choice['sheet_url'])).'">Лист персонажа</a>';
                if (isset($choice['thread_id'])) echo ' · <a href="'.self::esc(self::forumUrl('showthread.php?tid='.$choice['thread_id'])).'">Тема #'.self::esc($choice['thread_id']).'</a>';
                if ($message!=='') echo '<p role="alert">'.self::esc($message).'</p>';
                if ($issues) {
                    echo self::diagnostics($issues);
                    if (isset($choice['application_url'])) echo ' <a href="'.self::esc(self::forumUrl($choice['application_url'])).'">Редактировать анкету</a>';
                    if (isset($choice['kb_url'])) echo ' <a href="'.self::esc(self::forumUrl($choice['kb_edit_url']??$choice['kb_url'])).'">'.(isset($choice['kb_edit_url'])?'Редактировать запись KB':'Открыть запись KB').'</a>';
                    if (!empty($view['can_audit'])) {
                        echo '<details><summary>Диагностика источника</summary><pre>'
                            .self::esc(json_encode($issues,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details>';
                    }
                }
                if ($ready) {
                    echo '<form method="post" action="'.self::esc($url).'">';
                    self::hidden($formFields+['source_key'=>$choice['key'],'name'=>$check['name']!==''?$check['name']:($kind==='character'?mb_substr($choice['label'],0,191):''),'initial_hp'=>$check['initial_hp'],
                        'operation_key'=>bin2hex(random_bytes(16)),'preparation_fingerprint'=>$check['preparation_fingerprint']]);
                    echo '<p>Подтвердите регистрацию проверенного источника. Сервер перечитает данные.</p>'
                        .'<label>Причина регистрации <input name="reason" maxlength="1000" required value="'.self::esc($check['reason']).'"></label>'
                        .'<button name="op" value="entity_register" type="submit">Зарегистрировать</button></form>';
                }
                echo '</div>';
            }
        }
        if(($choices['next_after']??null)!==null)echo '<a href="'.self::esc($url.($embedded?'&session_id='.$sid:'').($picker?'':'&kind='.$kind).'&source_after='.$choices['next_after'].($slug!==''?'&source_slug='.rawurlencode($slug):'')).'">Следующие источники</a>';
        if (!$embedded) echo '</details>';
    }
    private static function forumUrl(string $path): string
    {
        global $mybb;
        return rtrim((string)$mybb->settings['bburl'],'/').'/'.$path;
    }

    private static function hidden(array $fields): void
    {
        foreach ($fields as $key=>$value) echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
    }

    public static function diagnostics(array $issues): string
    {
        if (!$issues) return '';
        $html='<ul>';
        foreach (af_acs_registration_diagnostic_groups($issues) as $group) {
            $html.='<li>'.self::esc($group['message']).' <small>'.self::esc($group['code'].' · '.implode(', ',$group['paths'])).'</small></li>';
        }
        return $html.'</ul>';
    }
}
