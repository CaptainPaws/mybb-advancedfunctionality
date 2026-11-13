<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Small server-rendered GM surface in the ATF thread slot; no JS/editor/runtime. */
final class AF_ACS_EncountersPage
{
    private static function esc(mixed $value): string {return htmlspecialchars_uni((string)$value);}
    private static function form(string $url,string $op,array $session,?array $encounter=null): void
    {
        global $mybb;
        echo '<form method="post" action="'.self::esc($url).'">';
        $fields=['acs_encounter_op'=>$op,'session_id'=>$session['id'],'expected_version'=>$session['session_version'],'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code,'tid'=>$session['tid']];
        if($encounter)$fields+=['encounter_id'=>$encounter['id'],'encounter_version'=>$encounter['encounter_version']];
        foreach($fields as $key=>$value)echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
    }
    private static function submit(string $title): void
    {
        echo '<label>Причина <input name="reason" maxlength="1000" required></label><button type="submit">'.self::esc($title).'</button></form>';
    }
    private static function sides(array $sides,string $field): void
    {
        echo '<select name="'.self::esc($field).'" required><option value="">Выберите сторону</option>';
        foreach($sides as $side)echo '<option value="'.self::esc($side['id']).'">'.self::esc($side['name']).'</option>';echo '</select>';
    }
    public static function render(string $sid,string $tid,?AF_ACS_EncounterService $service=null): string
    {
        global $mybb;
        $service??=af_advancedcombatsystem_encounters();
        // Authorize and verify the native topic/session link before reading input or rendering forms.
        $list=$service->list($sid,$mybb->get_input('encounter_after')?:'0',$tid);
        $session=$service->context($sid,$tid);
        $eid=$mybb->get_input('acs_encounter');$tab=$mybb->get_input('acs_tab')?:'overview';
        $tabs=['overview'=>'Обзор','preview'=>'Предпросмотр','participants'=>'Участники','rounds'=>'Раунды','events'=>'События','management'=>'Управление'];
        if(!isset($tabs[$tab]))$tab='overview';
        $base='showthread.php?tid='.$tid.'&acs_session='.$sid;$notice='';$readiness=null;
        if($mybb->request_method==='post' && $mybb->get_input('acs_encounter_op')!=='') {
            try {
                // The posted session may not redirect a command to another thread/scope.
                if($mybb->get_input('session_id')!==$sid || $mybb->get_input('tid')!==$tid)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
                $input=[];foreach(['session_id','expected_version','encounter_id','encounter_version','operation_key','reason','name','side_a','side_b','relationship','entity_id','side_id','uid','tid','mode','uids','assignment_version','visibility_scope','participant_version','presence_template','relation_version','round_id','entity_turn_id','control_group_id','confirm_full','confirm','deadline_mode','seconds','turn_id','turn_version','effect_order'] as $field)$input[$field]=$mybb->get_input($field);
                $op=$mybb->get_input('acs_encounter_op');
                if($op==='readiness') {
                    (new AF_ACS_SessionAccess)->csrf();$eid=$input['encounter_id'];$readiness=$service->readiness($sid,$eid);
                    $notice='<p>'.($readiness['preparation_ready']?'Подготовка проверена.':'Подготовка содержит блокировки.').' Для запуска первого раунда откройте вкладку «Раунды».</p>';
                    foreach($readiness['diagnostics'] as $issue)$notice.='<p>'.self::esc(self::issue($issue['code'])).(isset($issue['entity_id'])?' · существо #'.self::esc($issue['entity_id']):'').'</p>';
                } else {
                    if($op==='cover_assignment'){$r=[];foreach(['session_id','encounter_id','expected_version','expected_encounter_version','cover_entity_id','protected_entity_id','expected_assignment_version','status','operation_key','reason'] as $field)$r[$field]=$mybb->get_input($field);$result=$service->cover()->command($r);}elseif(str_starts_with($op,'spatial_')){$spatialOp=substr($op,8);$extra=match($spatialOp){'configure'=>['kb_key'],'connection'=>['from','to','status'],'position'=>['entity_id','zone_id','visibility'],'zone'=>['zone_id','visibility'],'engagement'=>['entity_id','target_id','status'],default=>throw new RuntimeException('ACS_SPATIAL_UNSUPPORTED')};$r=[];foreach(['session_id','encounter_id','expected_version','expected_encounter_version','expected_spatial_version','operation_key','reason',...$extra] as $field)$r[$field]=$mybb->get_input($field);$result=$service->spatial()->command($spatialOp,$r);}else $result=str_starts_with($op,'manage_')?$service->rounds()->command(substr($op,7),$input):(str_starts_with($op,'clock_')?$service->clock()->command(substr($op,6),$input):$service->command($op,$input));$eid=$result['encounter_id']??$eid;$notice='<p>Изменение сохранено.</p>';
                    $session=$service->context($sid,$tid);$list=$service->list($sid,tid:$tid);
                }
            } catch(Throwable $error) {
                $failure=af_advancedcombatsystem_session_failure($error);
                $notice='<p class="error">Подготовка не изменена. Проверьте права, поля, версии и источник. Код обращения: '.self::esc($failure['error_id']).'</p>';
            }
        }
        ob_start();
        try {
            echo '<section class="af-acs"><h2>ACS · Панель GM</h2>'.$notice.'<ul>';
            foreach($list['items'] as $row){$config=json_decode($row['config_json'],true);echo '<li><a href="'.self::esc($base.'&acs_encounter='.$row['id']).'">'.self::esc($config['title']??('Столкновение #'.$row['id'])).'</a> · '.self::esc(self::state($row['status'])).'</li>';}
            echo '</ul>';
            if($list['next_after']!==null)echo '<a href="'.self::esc($base.'&encounter_after='.$list['next_after']).'">Следующие столкновения</a>';
            if($eid==='') {
                if($session['status']==='active') {self::form($base,'create',$session);echo '<h3>Новое столкновение</h3><label>Название <input name="name" maxlength="191" required></label><p>Настройки подготовки будут закреплены отдельным снимком. Пока панель и сведения доступны только мастерам.</p>';self::submit('Создать столкновение');}
                echo '</section>';return ob_get_clean();
            }
            $view=$service->view($sid,$eid,$tid,$mybb->get_input('participant_after')?:'0',$mybb->get_input('side_after')?:'0',$mybb->get_input('relation_after')?:'0');$encounter=$view['encounter'];$url=$base.'&acs_encounter='.$eid.'&acs_tab='.$tab;
            echo '<nav aria-label="Панель GM">';foreach($tabs as $key=>$name)echo '<a href="'.self::esc($base.'&acs_encounter='.$eid.'&acs_tab='.$key).'"'.($tab===$key?' aria-current="page"':'').'>'.self::esc($name).'</a> ';echo '</nav>';
            if($tab==='preview'){require_once __DIR__.'/BuilderController.php';AF_ACS_BuilderController::render($sid,$eid,$tid);}
            elseif($tab==='overview') {
                $config=json_decode($encounter['config_json'],true);echo '<h3>'.self::esc($config['title']).'</h3><p>'.self::esc(self::state($encounter['status'])).' · версия '.self::esc($encounter['encounter_version']).'</p><p>Завершение столкновения и закрытие сессии — отдельные операции будущих этапов.</p>';
            } elseif($tab==='rounds') {
                $roundView=$service->roundView($sid,$eid,$mybb->get_input('turn_after')?:'0');
                if($roundView['round']===null) {
                    echo '<p>Первый раунд не запущен.</p>';
                    if($session['status']==='active' && $encounter['status']==='preparation') {
                        self::form($url,'round_start',$session,$encounter);self::submit('Запустить первый раунд');
                        echo '<p>Перед запуском сервер повторно проверит готовность и зафиксирует roster. Порядок действий групп свободный.</p>';
                    }
                } else {
                    echo '<h3>Раунд '.self::esc($roundView['round']['round_number']).'</h3><p>Порядок действий свободный. Питомец использует общий ход владельца; самостоятельные монстры — отдельные ходы.</p><table><tr><th>Группа</th><th>Ход</th><th>Существа в зафиксированном roster</th></tr>';
                    $states=['pending'=>'Ожидает','resolved'=>'Завершён публикацией','passed'=>'Пропущен','forfeited'=>'Закрыт разрешённым исключением'];
                    foreach($roundView['turns'] as $turn) {
                        echo '<tr><td>#'.self::esc($turn['control_group_id']).'</td><td>'.self::esc($states[$turn['status']]).'</td><td><ul>';
                        foreach($turn['roster']['members'] as $member) {
                            $individual=$turn['individual_turns'][$member['entity_id']];
                            echo '<li>#'.self::esc($member['entity_id']).' '.self::esc($member['name']).' · индивидуальный ход #'.self::esc($individual['id']).'</li>';
                        }
                        echo '</ul></td></tr>';
                    }
                    echo '</table>';require_once __DIR__.'/Clock.php';AF_ACS_ClockPage::rounds($view,$roundView,$url,$service->clock());
                    echo '<p>Начало/конец индивидуальных ходов не обрабатываются просмотром. Публикация и полноценные обработчики эффектов подключаются отдельными этапами.</p>';
                    require_once __DIR__.'/RoundManagement.php';AF_ACS_RoundManagementPage::render($view,$roundView,$url,$service->rounds());
                    if($roundView['next_after']!==null)echo '<a href="'.self::esc($url.'&turn_after='.$roundView['next_after']).'">Следующие группы</a>';
                }
            } elseif($tab==='participants') {
                echo '<table><tr><th>Существо</th><th>Группа</th><th>Сторона</th><th>Управляющий</th><th>Участие</th></tr>';$sides=array_column($view['sides'],'name','id');
                foreach($view['participants'] as $p)echo '<tr><td>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).'</td><td>#'.self::esc($p['control_group_id']).'</td><td>'.self::esc($sides[$p['side_id']]??($p['side_id']===null?'Не назначена':'Сторона #'.$p['side_id'])).'</td><td>'.self::esc($p['controller_mode']).' · '.self::esc(implode(', ',$p['controller_uids'])?:'—').'</td><td>Зарегистрирован</td></tr>';echo '</table>';
                if(count($view['participants'])===100)echo '<a href="'.self::esc($url.'&participant_after='.end($view['participants'])['id']).'">Следующие участники</a>';
                echo '<p>Группа управления независима от стороны. Питомец сохраняет группу владельца; самостоятельные монстры имеют отдельные будущие ходы.</p>';
            } elseif($tab==='events') {
                $events=$service->events($sid,$eid,$mybb->get_input('event_after')?:'0');echo '<ul>';
                foreach($events as $event)echo '<li>#'.self::esc($event['event_sequence']).' · '.self::esc(self::event($event['event_type'])).' · UID '.self::esc($event['actor_uid']).'</li>';echo '</ul>';
                if(count($events)===100)echo '<a href="'.self::esc($url.'&event_after='.end($events)['id']).'">Следующие события</a>';
            } elseif($session['status']==='active' && $encounter['status']==='active') {
                echo '<h3>Добавить участника для следующего раунда</h3><p>Текущий roster неизменяем. Добавление не создаёт новый turn или EntityTurn в текущем раунде и не выдаёт AP.</p>';
                if($view['sides']) {
                    self::form($url,'participant_add',$session,$encounter);echo '<select name="entity_id" required><option value="">Выберите существо</option>';
                    $candidates=$service->candidates($sid,$mybb->get_input('candidate_after')?:'0');
                    foreach($candidates as $candidate)echo '<option value="'.self::esc($candidate['id']).'">#'.self::esc($candidate['id']).' '.self::esc($candidate['name']).'</option>';
                    echo '</select>';self::sides($view['sides'],'side_id');self::submit('Добавить для следующего раунда');
                    if(count($candidates)===100)echo '<a href="'.self::esc($url.'&candidate_after='.end($candidates)['id']).'">Следующие существа</a>';
                }
            } elseif($session['status']==='active' && $encounter['status']==='preparation') {
                self::form($url,'side_create',$session,$encounter);echo '<h3>Стороны</h3><label>Название стороны <input name="name" maxlength="191" required></label>';self::submit('Добавить сторону');
                if($view['sides']) {
                    self::form($url,'relation_set',$session,$encounter);echo '<label>Сторона A ';self::sides($view['sides'],'side_a');echo '</label><label>Сторона B ';self::sides($view['sides'],'side_b');echo '</label><select name="relationship" required><option value="">Выберите отношение</option><option value="allied">Союзники</option><option value="neutral">Нейтральные</option><option value="hostile">Враждебные</option></select>';self::submit('Сохранить отношение');
                    echo '<ul>';$names=array_column($view['sides'],'name','id');foreach($view['relationships'] as $relation)echo '<li>'.self::esc($names[$relation['side_a']]??$relation['side_a']).' / '.self::esc($names[$relation['side_b']]??$relation['side_b']).' — '.self::esc(['allied'=>'Союзники','neutral'=>'Нейтральные','hostile'=>'Враждебные'][$relation['relationship']]??'Недопустимое отношение').'</li>';echo '</ul>';
                    foreach(['participant_add'=>'Добавить участника','participant_side'=>'Изменить сторону участника'] as $op=>$title) {
                        self::form($url,$op,$session,$encounter);echo '<h3>'.self::esc($title).'</h3><select name="entity_id" required><option value="">Выберите существо</option>';
                        $candidates=$op==='participant_add'?$service->candidates($sid,$mybb->get_input('candidate_after')?:'0'):$view['participants'];
                        foreach($candidates as $candidate){$id=$candidate['entity_id']??$candidate['id'];echo '<option value="'.self::esc($id).'">#'.self::esc($id).' '.self::esc($candidate['name']).'</option>';}
                        echo '</select>';self::sides($view['sides'],'side_id');self::submit($title);
                        if($op==='participant_add' && count($candidates)===100)echo '<a href="'.self::esc($url.'&candidate_after='.end($candidates)['id']).'">Следующие существа</a>';
                    }
                }
                if(count($view['sides'])===100)echo '<a href="'.self::esc($url.'&side_after='.end($view['sides'])['id']).'">Следующие стороны</a>';
                if(count($view['relationships'])===100)echo '<a href="'.self::esc($url.'&relation_after='.end($view['relationships'])['id']).'">Следующие отношения сторон</a>';
                self::form($url,'readiness',$session,$encounter);echo '<button type="submit">Проверить готовность</button></form><p>Первый раунд запускается на вкладке «Раунды». Засада, пауза и завершение пока недоступны.</p>';
            }
            if($tab==='management' && $session['status']==='active' && in_array($encounter['status'],['active','paused'],true)){
                echo '<h3>Стороны и отношения</h3><p>Изменение не объединяет группы и не восстанавливает AP, ресурсы или ход.</p>';
                foreach($view['relationships'] as $relation){self::form($url,'relation_set',$session,$encounter);foreach(['side_a','side_b','relation_version'] as $field)echo '<input type="hidden" name="'.$field.'" value="'.self::esc($relation[$field]).'">';echo '<p>Стороны #'.self::esc($relation['side_a']).' / #'.self::esc($relation['side_b']).'</p><select name="relationship">';foreach(['allied'=>'Союзники','neutral'=>'Нейтральные','hostile'=>'Враждебные'] as $value=>$label)echo '<option value="'.$value.'"'.($relation['relationship']===$value?' selected':'').'>'.$label.'</option>';echo '</select>';self::submit('Изменить отношение');}
                foreach($view['participants'] as $p)if($p['participation']==='engaged'){self::form($url,'side_change',$session,$encounter);echo '<p>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).' · сторона #'.self::esc($p['side_id']).'</p><input type="hidden" name="entity_id" value="'.self::esc($p['entity_id']).'"><input type="hidden" name="participant_version" value="'.self::esc($p['participant_version']).'">';self::sides($view['sides'],'side_id');self::submit('Изменить сторону');}
                echo '<h3>Временное управление поверх базовых назначений</h3><ul>';foreach($service->controlOverlays($sid,$eid) as $overlay)echo '<li>Существо #'.self::esc($overlay['entity_id']).' · overlay #'.self::esc($overlay['effect_id']).' · UID '.self::esc($overlay['controller_uid']).' · осталось '.self::esc($overlay['remaining_duration']).' '.self::esc($overlay['unit']).'</li>';echo '</ul>';
            }
            if($tab==='management' && $session['status']==='active' && in_array($encounter['status'],['preparation','active','paused'],true)) {
                echo '<h3>Управление NPC / монстрами</h3><p>Измените полный список UID через запятую, чтобы назначить или снять управляющих. Пустой список снимает всех; gm_pool использует текущих мастеров этой сессии. Ресурсы и ход сохраняются.</p><p>Мастера сессии: '.self::esc(implode(', ',array_column($view['controllers'],'uid'))).'</p>';
                foreach($view['participants'] as $p) {
                    if($p['owner_uid']!==null || !in_array($p['kind'],['npc','monster'],true))continue;
                    self::form($url,'controller_set',$session,$encounter);
                    echo '<h4>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).'</h4><input type="hidden" name="entity_id" value="'.self::esc($p['entity_id']).'"><input type="hidden" name="assignment_version" value="'.self::esc($p['assignment_version']).'"><label>Режим <select name="mode">';
                    foreach(['exclusive','shared','gm_pool'] as $mode)echo '<option value="'.$mode.'"'.($p['controller_mode']===$mode?' selected':'').'>'.$mode.'</option>';
                    echo '</select></label><label>UID управляющих <input name="uids" value="'.self::esc(implode(',',$p['controller_uids'])).'" maxlength="1099"></label><p>Exclusive: один UID; shared: несколько UID; gm_pool: оставьте UID пустым.</p>';self::submit('Сохранить управление');
                }
            }
            if($tab==='management' && $session['status']==='active' && in_array($encounter['status'],['preparation','active','paused'],true)){
                echo '<h3>Доступность целей конструктора</h3><p>Скрытые цели остаются доступны только GM. Разрешение цели участникам не открывает полный snapshot персонажа.</p>';
                foreach($view['participants'] as $p){$visibility=json_decode($p['visibility_json'],true);self::form($url,'participant_visibility',$session,$encounter);
                    echo '<p>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).'</p><input type="hidden" name="entity_id" value="'.self::esc($p['entity_id']).'"><input type="hidden" name="participant_version" value="'.self::esc($p['participant_version']).'"><select name="visibility_scope">';
                    foreach(['gm'=>'Только GM','participants'=>'Участники сессии'] as $scope=>$label)echo '<option value="'.$scope.'"'.(($visibility['scope']??'gm')===$scope?' selected':'').'>'.$label.'</option>';echo '</select>';self::submit('Сохранить доступность цели');
                }
            }
            if($tab==='management'){require_once __DIR__.'/Delegations.php';AF_ACS_DelegationsPage::render($sid,'misc.php?action=acs_sessions',$service->delegations());}
            if($tab==='management'&&in_array($encounter['status'],['active','paused'],true)){require_once __DIR__.'/Spatial.php';AF_ACS_SpatialPage::render($view,$url,$service->spatial());require_once __DIR__.'/Cover.php';AF_ACS_CoverPage::render($view,$url,$service->cover());}
            if($tab==='management') {require_once __DIR__.'/Clock.php';AF_ACS_ClockPage::rest($view,$url,$service->clock());}
            echo '</section>';return ob_get_clean();
        } catch(Throwable $error) {ob_end_clean();throw $error;}
    }
    private static function state(string $state): string {return ['preparation'=>'Подготовка','active'=>'Активно','ambush_ready'=>'Засада подготовлена','paused'=>'Пауза','completed'=>'Завершено'][$state]??'Состояние недоступно';}
    private static function event(string $event): string {return ['encounter.pause'=>'Пауза','encounter.resume'=>'Возобновление','encounter.gm_skip'=>'Ручной пропуск','encounter.gm_forfeit'=>'Закрытие выбывшей группы','encounter.deadline_set'=>'Настройка дедлайнов','encounter.extend_deadline'=>'Продление дедлайна','encounter.deadline_process'=>'Обработка дедлайна','encounter.round_next'=>'Следующий раунд','encounter.round_start'=>'Запущен первый раунд','encounter.create'=>'Создано столкновение','encounter.side_create'=>'Добавлена сторона','encounter.relation_set'=>'Изменены отношения сторон','encounter.participant_add'=>'Добавлен участник','encounter.participant_side'=>'Изменена сторона участника','encounter.side_change'=>'Изменена текущая сторона','encounter.participant_visibility'=>'Изменена доступность цели','encounter.controller_set'=>'Изменён режим / список управляющих','encounter.controller_assign'=>'Назначен управляющий'][$event]??'Подтверждённое событие';}
    private static function issue(string $code): string {return ['cooldown_source_unavailable'=>'Кулдаун не имеет подтверждённого источника/версии.','round_boundary_contract_unavailable'=>'Активные слои или настройки AP требуют ещё не подключённого обработчика границ.','participants_missing'=>'Добавьте хотя бы одного участника.','side_relationship_missing'=>'Явно задайте отношения используемых сторон.','ACS_CONTROL_INVALID'=>'Проверьте владельца, управляющего и группу существа.','ACS_SIDE_UNAVAILABLE'=>'Участнику нужна допустимая сторона.','ACS_ENTITY_UNAVAILABLE'=>'Существо недоступно либо занято другим столкновением.','ACS_SNAPSHOT_HASH_MISMATCH'=>'Нарушена целостность сохранённого снимка.','ACS_SNAPSHOT_INVALID'=>'Сохранённый снимок несовместим или неполон.','ACS_DEPENDENCY_UNAVAILABLE'=>'Обязательная зависимость отключена.','preparation_required'=>'Нужны активная сессия и состояние подготовки.','config_snapshot_invalid'=>'Снимок конфигурации не прошёл проверку.'][$code]??'Участник не прошёл серверную проверку.';}
}
