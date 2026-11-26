<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/GmIntent.php';

/** Composes existing services/forms, with no new command backend or mechanical authority. */
final class AF_ACS_GmPanel
{
    private static function esc(mixed $v):string{return htmlspecialchars_uni((string)$v);}
    public static function state(string $v):string
    {return ['active'=>'Активно','preparation'=>'Подготовка','ambush_ready'=>'Готовность засады','pending_resolution'=>'Ожидание исхода','completed'=>'Завершено','paused'=>'Пауза','pending_close'=>'Подготовлено закрытие','closed'=>'Закрыто','archived'=>'Архив','engaged'=>'В составе','surrendered'=>'Сдался','escaped'=>'Покинул бой','withdrawn'=>'Выведен','captured'=>'Пленён','despawned'=>'Призыв завершён','incapacitated'=>'Недееспособен','dead'=>'Мёртв','exclusive'=>'Один управляющий','shared'=>'Совместное управление','gm_pool'=>'Мастера сессии'][$v]??$v;}
    public static function fields(array $fields):void
    {foreach($fields as $key=>$value)echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';}
    public static function form(string $url,string $op,array $s,?array $e=null,string $id=''):void
    {
        global $mybb;echo '<form method="post" action="'.self::esc($url).'"'.($id!==''?' id="'.self::esc($id).'"':'').'>';
        self::fields(['acs_encounter_op'=>$op,'session_id'=>$s['id'],'tid'=>$s['tid'],'expected_version'=>$s['session_version'],'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code]+($e?['encounter_id'=>$e['id'],'encounter_version'=>$e['encounter_version']]:[]));
        echo AF_ACS_GmIntent::checkbox(str_starts_with($op,'session_')?substr($op,8):$op);
    }
    public static function submit(string $label):void
    {echo '<label>Причина <input name="reason" maxlength="1000" required></label><button type="submit">'.self::esc($label).'</button></form>';}
    public static function setup(string $tid, array &$outcome = []): string
    {
        global $mybb;
        $notice = '';
        if ($mybb->request_method === 'post' && $mybb->get_input('acs_encounter_op') === 'session_create') {
            try {
                if ($mybb->get_input('tid') !== $tid) throw new RuntimeException('ACS_THREAD_UNAVAILABLE');
                $result = af_advancedcombatsystem_atf_create($tid);
                $outcome=['committed'=>true,'sid'=>$result['session_id']];
                $mybb->input['acs_encounter_op'] = '';
                $mybb->input['acs_session'] = $result['session_id'];
                $mybb->input['gm_stage'] = 'settings';
                return '<p role="status">Сессия создана. Настройте столкновение.</p>'
                    .AF_ACS_EncountersPage::render($result['session_id'],$tid);
            } catch (Throwable $error) {
                if (isset($result['session_id'])) {
                    $url = 'showthread.php?tid='.$tid.'&acs_session='.$result['session_id'].'&acs_setup=1&gm_stage=settings#acs-thread-panel';
                    return '<p role="status">Сессия создана. <a href="'.self::esc($url).'">Открыть созданную сессию</a>.</p>';
                }
                $failure = af_advancedcombatsystem_session_failure($error);
                $outcome=['error'=>true,'uncertain'=>in_array($failure['category'],['RECOVERY_REQUIRED','INTERNAL_ERROR'],true)];
                $notice = '<p role="alert">Создание не подтверждено. При неизвестном ответе повторяйте прежнюю форму/ключ. error_id='.self::esc($failure['error_id']).'</p>';
            }
        }

        ob_start();
        echo '<section class="af-acs-gm"><h2>Настройки боя</h2>'.$notice;
        echo '<form method="post" action="'.self::esc('showthread.php?tid='.$tid.'&acs_setup=1&acs_atf_setup=1#acs-thread-panel').'">';
        self::fields(['acs_encounter_op'=>'session_create','operation_key'=>'atf-thread-create-'.$tid,'tid'=>$tid,'my_post_key'=>$mybb->post_code]);
        echo '<p>Подготовка боя будет привязана к текущей теме.</p>';
        echo '<button type="submit">Начать подготовку</button></form></section>';
        return ob_get_clean();
    }

    private const STEPS = ['settings'=>'Настройки','roster'=>'Состав','check'=>'Проверка','start'=>'Запуск'];

    public static function stage(string $stage,string $legacy): string
    {
        if (isset(self::STEPS[$stage])) return $stage;
        return match ($legacy) {'3'=>'roster','4'=>'check','5','6'=>'start',default=>'settings'};
    }

    public static function stepUrl(string $base,string $eid,string $stage): string
    {
        return $base.($eid!==''?'&acs_encounter='.$eid:'').'&acs_setup=1&gm_stage='.$stage;
    }

    public static function sides(array $sides,string $field,string $selected=''): void
    {
        echo '<select name="'.self::esc($field).'" required><option value="">Выберите сторону</option>';
        foreach ($sides as $side) {
            echo '<option value="'.self::esc($side['id']).'"'.((string)$side['id']===$selected?' selected':'').'>'.self::esc($side['name']).'</option>';
        }
        echo '</select>';
    }

    public static function preparation(AF_ACS_EncounterService $service,array $session,array $list,string $base,string $eid,string $stage,array $sourceCheck,string $registered): void
    {
        global $mybb;
        $sid=$session['id'];
        if ($eid==='') $stage='settings';
        $url=self::stepUrl($base,$eid,$stage);
        $view=$eid!==''?$service->view($sid,$eid,$session['tid'],$mybb->get_input('participant_after')?:'0',
            $mybb->get_input('side_after')?:'0',$mybb->get_input('relation_after')?:'0'):[];
        $encounter=$view['encounter']??[];
        echo '<header class="af-acs-gm__statusbar"><h2>ACS · Панель GM</h2><div class="af-acs-gm__pills">';
        echo '<span class="af-acs-gm__pill">Сессия #'.self::esc($sid).'</span>';
        if ($encounter) {
            echo '<span class="af-acs-gm__pill">Столкновение #'.self::esc($eid).'</span><span class="af-acs-gm__pill">'.self::esc(self::state($encounter['status'])).'</span>';
        }
        echo '<span class="af-acs-gm__pill'.($session['status']==='active'?' af-acs-gm__pill--active':'').'">'.self::esc(self::state($session['status'])).'</span></div></header>';
        $steps=array_keys(self::STEPS);
        $index=array_search($stage,$steps,true);
        $resume=$url;
        if ($stage==='roster' && $mybb->get_input('gm_picker')==='1') {
            $resume.='&gm_picker=1&kind='.rawurlencode($mybb->get_input('kind'));
            foreach (['source_key','source_slug','source_after','gm_side'] as $field) {
                $value=$mybb->get_input($field);
                if ($value!=='' && ($registered==='' || $field==='gm_side')) $resume.='&'.$field.'='.rawurlencode($value);
            }
        }
        if ($stage==='roster' && $registered!=='') {
            $resume=$url.'&gm_picker=1&kind=recent&gm_entity='.rawurlencode($registered);
            $side=$mybb->get_input('gm_side');
            if ($side!=='') $resume.='&gm_side='.rawurlencode($side);
        }
        $resumeAttr=$registered!=='' || $sourceCheck!==[] ? ' data-acs-gm-resume="'.self::esc($resume).'"' : '';
        echo '<nav aria-label="Шаги подготовки"'.$resumeAttr.'><ol class="af-acs-gm__steps">';
        $icons=['settings'=>'fa-gear','roster'=>'fa-users','check'=>'fa-shield','start'=>'fa-play'];
        foreach (self::STEPS as $key=>$label) {
            echo '<li>';
            if ($eid==='' && $key!=='settings') echo self::esc($label);
            else echo '<a href="'.self::esc(self::stepUrl($base,$eid,$key)).'"'.($key===$stage?' aria-current="step"':'').'><i class="fa-solid '.$icons[$key].'" aria-hidden="true"></i> '.self::esc($label).'</a>';
            echo '</li>';
        }
        echo '</ol></nav>';
        if ($stage!=='roster') echo '<div class="af-acs-gm__stage"><h3>'.self::esc(self::STEPS[$stage]).'</h3>';

        if ($stage==='settings') {
            $sessionView=af_advancedcombatsystem_sessions()->view($sid);
            self::sessionMembers($sessionView,$url);
        }

        if ($eid==='') {
            if ($session['status']==='active') {
                self::form($url,'create',$session);
                echo '<label>Название столкновения <input name="name" maxlength="191" required value="'.self::esc($mybb->get_input('name')).'"></label>';
                self::submit('Создать столкновение');
            } else {
                echo '<p>Создание доступно в активной сессии.</p>';
            }
        } else {
            $config=json_decode($encounter['config_json'],true,64,JSON_THROW_ON_ERROR);
            if ($stage!=='roster') echo '<p><strong>'.self::esc($config['title']).'</strong></p>';
            switch ($stage) {
                case 'settings':
                    self::settings($view,$url,true);
                    break;
                case 'roster':
                    self::composition($service,$view,$url,$sourceCheck,$registered);
                    break;
                case 'check':
                case 'start':
                    if ($stage==='start') self::participants($view,$url);
                    if ($session['status']==='active' && $encounter['status']==='preparation') {
                        $ready=$service->readiness($sid,$eid);
                        self::readiness($ready,$view);
                        if ($stage==='start' && $ready['can_start']) {
                            self::form($url,'round_start',$session,$encounter);
                            self::submit('Подтвердить запуск боя');
                        } elseif (!$ready['can_start']) {
                            echo '<p>Запуск станет доступен после устранения причин выше.</p>';
                        }
                    } else {
                        echo '<p>Проверка подготовки и запуск доступны до начала боя в активной сессии.</p>';
                        echo '<a href="'.self::esc($base.'&acs_encounter='.$eid.'&acs_tab=rounds').'">Перейти к раундам</a>';
                    }
                    break;
            }
            if ($stage!=='roster') {
                echo '<nav aria-label="Переход между шагами">';
                if ($index>0) echo '<a href="'.self::esc(self::stepUrl($base,$eid,$steps[$index-1])).'">Назад</a>';
                if ($index<count($steps)-1) echo '<a href="'.self::esc(self::stepUrl($base,$eid,$steps[$index+1])).'">Далее</a>';
                echo '</nav>';
            }
        }
        if ($stage!=='roster') echo '</div>';

        echo '<details class="af-acs-gm__advanced"><summary>Расширенные настройки и диагностика</summary>';
        echo '<p>Сессия #'.self::esc($sid).' · версия '.self::esc($session['session_version']).'</p>';
        if ($eid!=='') {
            echo '<p>Столкновение #'.self::esc($eid).' · версия '.self::esc($encounter['encounter_version']).'</p>';
            $advanced=$base.'&acs_encounter='.$eid.'&acs_tab=';
            echo '<nav aria-label="Расширенное управление">';
            foreach (['overview'=>'Диагностика и снимки','participants'=>'Управление участниками','rounds'=>'Раунды и засада','events'=>'Журнал','management'=>'Управление и восстановление'] as $tab=>$label) {
                echo '<a href="'.self::esc($advanced.$tab).'">'.self::esc($label).'</a>';
            }
            echo '</nav>';
        }
        echo '<p><a href="'.self::esc($base.'&acs_tab=management').'">Настройки сессии и список столкновений</a></p>';
        if ($list['items']) {
            echo '<ul>';
            foreach ($list['items'] as $row) {
                $config=json_decode($row['config_json'],true);
                echo '<li><a href="'.self::esc(self::stepUrl($base,(string)$row['id'],'settings')).'">'.self::esc($config['title']??'Столкновение').'</a></li>';
            }
            echo '</ul>';
        }
        if ($list['next_after']!==null) echo '<a href="'.self::esc($url.'&encounter_after='.$list['next_after']).'">Следующие столкновения</a> · ';
        if ($eid!=='') echo '<a href="'.self::esc(self::stepUrl($base,'','settings')).'">Создать другое столкновение</a>';
        echo '</details>';
        if ($stage==='settings') {
            echo '<details class="af-acs-gm__advanced"><summary>Дополнительные действия</summary>';
            self::sessionControls($sessionView,$url);
            self::recovery($sid,$url,$sessionView['can_audit']);
            echo '</details>';
        }
    }

    private static function participants(array $view,string $url): void
    {
        $sides=array_column($view['sides'],'name','id');
        echo '<h4>В столкновении</h4>';
        if (!$view['participants']) echo '<p>Участников пока нет.</p>';
        else {
            echo '<ul>';
            foreach ($view['participants'] as $participant) {
                echo '<li>'.self::esc($participant['name']).' · '.self::esc($sides[$participant['side_id']]??'Сторона вне этой страницы');
                if ($participant['participation']!=='engaged') echo ' · '.self::esc(self::state($participant['participation']));
                echo '</li>';
            }
            echo '</ul>';
            if (count($view['participants'])===100) echo '<a href="'.self::esc($url.'&participant_after='.end($view['participants'])['id']).'">Следующие участники</a>';
        }
    }

    private static function readiness(array $ready,array $view): void
    {
        echo '<p role="status"><strong>'.($ready['can_start']?'Готово к запуску':'Требуются исправления').'</strong></p>';
        echo '<p>Проверены состав, права управления, сохранённые источники и настройки боя.</p>';
        if ($ready['can_start']) return;
        $participants=array_column($view['participants'],'name','entity_id');
        $sides=array_column($view['sides'],'name','id');
        $messages=[];
        foreach ($ready['diagnostics'] as $issue) {
            $message=AF_ACS_EncountersPage::issue($issue['code']);
            if (isset($issue['entity_id'])) $message.=' '.($participants[$issue['entity_id']]??'Существо #'.$issue['entity_id']);
            if (isset($issue['side_a'],$issue['side_b'])) $message.=' '.($sides[$issue['side_a']]??'Сторона #'.$issue['side_a']).' / '.($sides[$issue['side_b']]??'Сторона #'.$issue['side_b']);
            $messages[$message]=$message;
        }
        echo '<ul>';
        foreach ($messages as $message) echo '<li>'.self::esc($message).'</li>';
        echo '</ul>';
    }

    private static function composition(AF_ACS_EncounterService $service,array $view,string $url,array $sourceCheck,string $registered): void
    {
        global $mybb,$db;
        require_once __DIR__.'/Entities.php';
        require_once __DIR__.'/../Infrastructure/GmPanelQuery.php';
        $session=$view['session'];$encounter=$view['encounter'];$sid=$session['id'];
        $sessionView=af_advancedcombatsystem_sessions()->view($sid);
        $editable=$session['status']==='active' && $encounter['status']==='preparation';
        $characters=$sessionView['can_register_own'] || $sessionView['can_register_characters'];
        $bestiary=$sessionView['can_manage'];
        $kind=$mybb->get_input('kind');
        if (!in_array($kind,array_merge($characters?['character']:[],$bestiary?['npc','monster']:[],['recent']),true)) {
            $kind=$characters?'character':($bestiary?'npc':'recent');
        }
        $people=$view['participants'];
        $bySide=[];
        foreach ($people as $person) $bySide[$person['side_id']][]=$person;
        $playerCount=count(array_filter($people,static fn($row)=>$row['kind']==='character'));
        $opponentCount=count(array_filter($people,static fn($row)=>in_array($row['kind'],['npc','monster'],true)));
        $canManageSides=$editable && $sessionView['can_audit']
            && $encounter['round_number']==='0' && $encounter['start_operation_id']===null;
        $sideAfter=$mybb->get_input('side_after');
        $sideActionUrl=$sideAfter===''?$url:$url.'&side_after='.rawurlencode($sideAfter);
        $query=new AF_ACS_GmPanelQuery($db);
        $occupiedSides=$canManageSides?array_fill_keys($query->occupiedSides($sid,$encounter['id'],$session['tid']),true):[];
        $health=array_column($query->inspection($sid,$encounter['id'],$session['tid'],array_column($people,'entity_id'),true),null,'entity_id');
        $selectedSide=$mybb->get_input('gm_side');
        $sideQuery=isset(array_column($view['sides'],'name','id')[$selectedSide])?'&gm_side='.rawurlencode($selectedSide):'';
        $pickerUrl=$url.'&gm_picker=1&kind='.$kind.$sideQuery;
        $inRoster=array_fill_keys(array_column($people,'entity_id'),true);
        $candidates=[];
        if ($editable && $view['sides'] && $kind==='recent' && $registered==='') {
            $candidates=$service->candidates($sid,$mybb->get_input('candidate_after')?:'0',$kind==='recent');
        }
        $available=array_filter($candidates,static fn($entity)=>!isset($inRoster[$entity['id']]));
        $recentId=$mybb->get_input('gm_entity');
        $selectedEntity=$registered!==''?$registered:($kind==='recent'?$recentId:'');
        $recentSelection=$editable && $selectedEntity!=='' ? $service->assignmentCandidate($sid,$encounter['id'],$selectedEntity) : null;
        $owner=$recentSelection && $recentSelection['kind']==='character' && $recentSelection['owner_uid']!==null
            ? af_advancedcombatsystem_sessions()->memberProfile($sid,(string)$recentSelection['owner_uid']) : null;
        $canSave=$editable && $view['sides'] && $recentSelection!==null
            && ($recentSelection['kind']!=='character' || ($owner && self::hasPlayingRole($owner)));
        $pageLabel=count($people)===100 || $mybb->get_input('participant_after')!==''?' на странице':'';
        $readinessUrl=$pickerUrl;
        foreach (['source_key','source_slug','source_after','gm_entity','candidate_after'] as $field) {
            $value=$mybb->get_input($field);
            if ($value!=='') $readinessUrl.='&'.$field.'='.rawurlencode($value);
        }

        $checked=false;
        if ($mybb->get_input('gm_readiness')==='1' && $editable) {
            $readiness=$service->readiness($sid,$encounter['id']);
            $checked=$readiness['can_start'];
            self::readiness($readiness,$view);
        }

        echo '<div class="af-acs-gm__composition">';
        echo '<section class="af-acs-gm__column"><h4><i class="fa-solid fa-users" aria-hidden="true"></i> Стороны</h4><div class="af-acs-gm__column-body">';
        foreach ($view['sides'] as $side) {
            $members=$bySide[$side['id']]??[];
            echo '<details class="af-acs-gm__side" open><summary><strong>'.self::esc($side['name']).'</strong><span class="af-acs-gm__pill">'.count($members).'</span></summary>';
            if (!$members) echo isset($occupiedSides[$side['id']])?'<p>Участники на другой странице состава.</p>':'<p>Участников пока нет.</p>';
            foreach ($members as $person) self::rosterCard($person,$health[$person['entity_id']]??[],true,$url,$view,$editable,$checked);
            if ($editable) echo '<div class="af-acs-gm__side-actions"><a class="atf-button" href="'.self::esc($url.'&gm_picker=1&kind='.$kind.'&gm_side='.$side['id']).'">Добавить</a><a class="atf-button" href="'.self::esc($url.'&gm_relations=1').'">Отношения</a></div>';
            if ($canManageSides) self::sideActions($view,$sideActionUrl,$side,isset($occupiedSides[$side['id']]));
            echo '</details>';
        }
        if (!$view['sides']) echo '<p>Создайте стороны столкновения, например «Игроки» и «Враги».</p>';
        if ($editable) self::sideSettings($view,$url);
        echo '<div class="af-acs-gm__summary"><strong>Сводка по составу</strong><p>Участников'.$pageLabel.': '.count($people).' · Игроков: '.$playerCount.' · NPC/монстров: '.$opponentCount.'</p></div>';
        echo '</div></section>';

        echo '<section class="af-acs-gm__column"><h4><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Состав столкновения</h4><div class="af-acs-gm__column-body">';
        echo '<p>Участники текущего столкновения'.$pageLabel.' ('.count($people).')</p>';
        if (!$people) echo '<p>Добавьте зарегистрированных существ на стороны столкновения.</p>';
        foreach ($people as $person) self::rosterCard($person,$health[$person['entity_id']]??[],false,$url,$view,$editable,$checked);
        if (count($people)===100) echo '<a href="'.self::esc($url.'&participant_after='.end($people)['id']).'">Следующие участники</a>';
        echo '</div></section>';

        echo '<section class="af-acs-gm__column af-acs-gm__picker" aria-labelledby="af-acs-gm-picker-title"><h4 id="af-acs-gm-picker-title"><i class="fa-solid fa-plus" aria-hidden="true"></i> Добавить в состав</h4><div class="af-acs-gm__column-body">';
        if ($editable) {
            echo '<nav aria-label="Источники состава" class="af-acs-gm__sources">';
            foreach (array_filter(['character'=>$characters?'Персонажи':'','npc'=>$bestiary?'NPC/Монстры':'','recent'=>'Недавние']) as $type=>$label) {
                $active=$kind===$type || ($type==='npc' && $kind==='monster');
                echo '<a href="'.self::esc($url.'&gm_picker=1&kind='.$type.$sideQuery).'"'.($active?' aria-current="page"':'').'>'.self::esc($label).'</a>';
            }
            echo '</nav>';
            if ($registered!=='') {
                if ($view['sides'] && $recentSelection) self::offerAssignment($view,$pickerUrl,$recentSelection,$owner,$selectedSide);
                else echo '<p>Существо зарегистрировано. Откройте «Недавние», чтобы добавить его в состав.</p>';
            } elseif ($kind==='recent') {
                if ($recentSelection && $view['sides']) self::offerAssignment($view,$pickerUrl,$recentSelection,$owner,$selectedSide);
                if (!$available) echo '<p>Нет зарегистрированных существ для добавления на текущей странице.</p>';
                else {
                    $cursor=$mybb->get_input('candidate_after');
                    $recentUrl=$pickerUrl.($cursor!==''?'&candidate_after='.rawurlencode($cursor):'');
                    echo '<div class="af-acs-gm__results">';
                    foreach ($available as $candidate) {
                        echo '<article class="af-acs-gm__entity af-acs-gm__entity--result">'.AF_ACS_EntitiesPage::portrait((int)($candidate['owner_uid']??0))
                            .'<div class="af-acs-gm__entity-label"><strong>'.self::esc($candidate['name']).'</strong><small>'.self::esc(AF_ACS_EntitiesPage::kindLabel($candidate['kind'])).'</small></div>'
                            .'<a class="atf-button" href="'.self::esc($recentUrl.'&gm_entity='.$candidate['id']).'">Добавить</a></article>';
                    }
                    echo '</div>';
                }
                if (count($candidates)===20) echo '<a href="'.self::esc($pickerUrl.'&candidate_after='.end($candidates)['id']).'">Предыдущие регистрации</a>';
            } else {
                if ($kind==='character' && $sessionView['can_register_characters']) echo '<p>Можно добавить доступный лист участника форума.</p>';
                if ($kind!=='character') {
                    echo '<nav aria-label="Тип существа">';
                    foreach (['npc'=>'NPC','monster'=>'Монстр'] as $type=>$label) {
                        echo '<a href="'.self::esc($url.'&gm_picker=1&kind='.$type.$sideQuery).'"'.($type===$kind?' aria-current="page"':'').'>'.$label.'</a>';
                    }
                    echo '</nav>';
                }
                AF_ACS_EntitiesPage::render($sid,$sessionView,$pickerUrl,null,true,$sourceCheck,true,$kind);
            }
        } else {
            echo '<p>Добавление в состав подготовки доступно до запуска в активной сессии.</p>';
        }
        echo '</div></section></div>';

        $checkUrl=preg_replace('/gm_stage=roster$/','gm_stage=check',$url);
        echo '<nav class="af-acs-gm__actions" aria-label="Действия состава">';
        if ($editable) echo '<a class="atf-button" href="'.self::esc($readinessUrl.'&gm_readiness=1').'"><i class="fa-solid fa-shield" aria-hidden="true"></i> Проверить готовность</a>';
        echo '<button class="atf-button" type="submit" form="af-acs-gm-assignment"'.($canSave?'':' disabled title="Выберите зарегистрированное существо и сторону"').'><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Сохранить состав</button>';
        echo '<a class="atf-button atf-button--primary af-acs-gm__next" href="'.self::esc($checkUrl).'">Далее: Проверка</a></nav>';
    }

    private static function rosterCard(array $person,array $health,bool $compact,string $url,array $view,bool $editable,bool $checked): void
    {
        $kind=AF_ACS_EntitiesPage::kindLabel($person['kind']);
        $portrait=AF_ACS_EntitiesPage::portrait((int)($person['owner_uid']??0),(string)($health['portrait']??''));
        $source=$person['kind']==='character'?'Лист персонажа':(in_array($person['kind'],['npc','monster'],true)?'Бестиарий':'Сессия');
        $valid=$health && $health['snapshot_valid'];
        $available=$valid && $person['life_state']==='active' && $person['participation']==='engaged';
        $ready=$available && $checked;
        $status=$ready?'Готов':($available?'Ожидает проверки':(!$valid?'Проверьте снимок':self::state($person['participation']==='engaged'?$person['life_state']:$person['participation'])));
        echo '<article class="af-acs-gm__entity'.($compact?' af-acs-gm__entity--compact':'').'">'.$portrait.'<div class="af-acs-gm__entity-label"><strong>'.self::esc($person['name']).'</strong><small>'.self::esc($kind.' · '.$source).'</small></div>';
        if ($compact) {
            echo '<div><small>HP '.self::esc($health['hp']??'—').' / '.self::esc($health['hp_max']??'—').'</small><small>AP '.self::esc($health['ap']??'—').'</small></div>';
        }
        echo '<span class="af-acs-gm__status'.($ready?' af-acs-gm__status--ready':'').'">'.self::esc($status).'</span>';
        if ($editable && !$compact) {
            echo '<div class="af-acs-gm__entity-actions"><button type="button" disabled title="Порядок задаёт ACS" aria-label="Изменить порядок"><i class="fa-solid fa-sort" aria-hidden="true"></i></button>'
                .'<button type="button" disabled title="Удаление при подготовке недоступно" aria-label="Удалить из состава"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>';
            echo '<details class="af-acs-gm__entity-tools"><summary><i class="fa-solid fa-pen" aria-hidden="true"></i> Настроить</summary>';
            self::form($url,'participant_side',$view['session'],$view['encounter']);
            self::fields(['entity_id'=>$person['entity_id']]);
            echo '<label>Сторона ';
            self::sides($view['sides'],'side_id',(string)$person['side_id']);
            echo '</label>';
            self::submit('Сохранить сторону');
            echo '<p class="af-acs-gm__muted">Изменение порядка и удаление недоступны при подготовке.</p></details>';
        }
        echo '</article>';
    }

    private static function sideActions(array $view,string $url,array $side,bool $occupied): void
    {
        echo '<details class="af-acs-gm__side-tools"><summary>Действия</summary><details><summary>Переименовать</summary>';
        self::form($url,'side_rename',$view['session'],$view['encounter']);
        self::fields(['side_id'=>$side['id']]);
        echo '<label>Название стороны <input name="name" maxlength="191" value="'.self::esc($side['name']).'" required></label>';
        self::submit('Переименовать');
        echo '</details><details><summary>Удалить</summary>';
        if ($occupied) {
            echo '<p>Сначала переместите всех участников этой стороны через «Настроить» в составе столкновения.</p>';
        } else {
            self::form($url,'side_delete',$view['session'],$view['encounter']);
            self::fields(['side_id'=>$side['id']]);
            self::submit('Удалить сторону');
        }
        echo '</details></details>';
    }

    private static function sideSettings(array $view,string $url): void
    {
        global $mybb;
        $session=$view['session'];$encounter=$view['encounter'];$sides=$view['sides'];
        echo '<details><summary>Создать сторону</summary>';
        self::form($url,'side_create',$session,$encounter);
        echo '<label>Название стороны <input name="name" maxlength="191" required></label>';
        self::submit('Добавить сторону');
        echo '</details><details'.($mybb->get_input('gm_relations')==='1'?' open data-acs-side-settings tabindex="-1"':'').'><summary>Отношения сторон</summary>';
        if (count($sides)<2) echo '<p>Сначала создайте две стороны.</p>';
        else {
            self::form($url.'&gm_relations=1','relation_set',$session,$encounter);
            echo '<label>Первая сторона ';self::sides($sides,'side_a');echo '</label><label>Вторая сторона ';
            self::sides($sides,'side_b');
            echo '</label><label>Отношение <select name="relationship" required><option value="">Выберите отношение</option><option value="hostile">Враждебные</option><option value="allied">Союзники</option><option value="neutral">Нейтральные</option></select></label>';
            self::submit('Сохранить отношение');
            $names=array_column($sides,'name','id');
            foreach ($view['relationships'] as $row) {
                echo '<p>'.self::esc(($names[$row['side_a']]??'Другая сторона').' / '.($names[$row['side_b']]??'Другая сторона')).' · '.self::esc(['hostile'=>'Враждебные','allied'=>'Союзники','neutral'=>'Нейтральные'][$row['relationship']]??'Не определено').'</p>';
            }
        }
        echo '</details>';
        if (count($sides)===100) echo '<a href="'.self::esc($url.'&side_after='.end($sides)['id']).'">Следующие стороны</a>';
        if (count($view['relationships'])===100) echo '<a href="'.self::esc($url.'&relation_after='.end($view['relationships'])['id']).'">Следующие отношения</a>';
    }

    private static function offerAssignment(array $view,string $url,array $entity,?array $owner,string $side): void
    {
        $session=$view['session'];
        if ($entity['kind']==='character') {
            echo '<h5>'.self::esc($entity['name']).'</h5>';
            self::ownerStatus($owner);
            if (!$owner || !self::hasPlayingRole($owner)) {
                if (!empty($owner['editable'])) {
                    $return=preg_replace('/([?&])kind=[^&]*/','$1kind=recent',$url).'&gm_entity='.rawurlencode((string)$entity['id']);
                    echo '<details><summary>Добавить владельца как игрока</summary>';
                    self::form($return,'session_set_role',$session);
                    self::fields(['uid'=>$owner['uid'],'role'=>'player']);
                    self::submit('Добавить владельца как игрока');
                    echo '</details>';
                }
                return;
            }
        }
        self::assignment($session,$view['encounter'],$view['sides'],$url,(string)$entity['id'],$side,$entity['kind']==='character'?'':(string)$entity['name']);
    }

    private static function hasPlayingRole(array $member): bool
    {
        return (bool)array_intersect($member['roles'],AF_ACS_SessionService::PLAYING_ROLES);
    }

    private const ROLE_LABELS=['owner'=>'Владелец','player'=>'Игрок','observer'=>'Наблюдатель','assistant_gm'=>'Помощник мастера','gm'=>'Мастер'];

    private static function memberStatus(array $member): string
    {
        return $member['roles'] ? implode(', ',array_map(static fn($role)=>self::ROLE_LABELS[$role]??$role,$member['roles']))
            : 'Не участвует в сессии';
    }

    public static function ownerStatus(?array $member): void
    {
        if (!$member) {
            echo '<p>Профиль владельца недоступен.</p>';
            return;
        }
        echo '<p><strong>'.self::esc($member['username']).'</strong> · '.self::esc(self::memberStatus($member)).'</p>';
        if (!self::hasPlayingRole($member)) echo '<p>Чтобы персонаж мог участвовать в столкновении, его владельцу требуется игровая роль в этой сессии.</p>';
    }

    private static function assignment(array $session,array $encounter,array $sides,string $url,string $entityId,string $selectedSide='',string $name=''): void
    {
        self::form($url,'participant_add',$session,$encounter,'af-acs-gm-assignment');
        self::fields(['entity_id'=>$entityId]);
        if ($name!=='') echo '<strong>'.self::esc($name).'</strong>';
        echo '<label>Сторона ';
        self::sides($sides,'side_id',$selectedSide);echo '</label>';
        self::submit('Добавить в столкновение');
    }

    public static function overview(array $sessionView,array $view,string $url):void
    {
        $s=$sessionView['session'];$e=$view['encounter'];
        echo '<h3>Сессия #'.self::esc($s['id']).' · столкновение #'.self::esc($e['id']).'</h3><p>Сессия: '.self::esc(self::state($s['status'])).' · версия '.self::esc($s['session_version']).'. Столкновение: '.self::esc(self::state($e['status'])).' · версия '.self::esc($e['encounter_version']).'.</p><p>Обычный просмотр не проверяет все внешние источники и не исполняет границы. Готовность проверяется отдельной командой и повторно при запуске.</p>';
        if($e['status']==='preparation'){self::form($url,'readiness',$s,$e);echo '<button>Проверить готовность подготовки</button></form>';}else echo '<p>Столкновение уже вышло из подготовки. Текущий roster, AP и сроки — на вкладке «Раунды».</p>';
        echo '<details><summary>Роли участников сессии</summary><ul>';foreach($sessionView['members'] as $member)echo '<li>UID '.self::esc($member['uid']).' · '.self::esc(['owner'=>'Владелец','gm'=>'Мастер','assistant_gm'=>'Помощник','player'=>'Игрок','observer'=>'Наблюдатель'][$member['role']]??$member['role']).'</li>';echo '</ul></details>';
        self::obligations($sessionView);
        echo '<p>В текущей странице участников: '.count($view['participants']).'. Полный состав доступен с пагинацией на вкладке «Участники».</p>';
    }
    public static function obligations(array $view):void
    {
        $data=$view['outstanding_obligations']??null;if($data===null){echo '<p>Обязательства: подробности недоступны по текущим правам.</p>';return;}
        echo '<h3>Обязательства сессии</h3><ul>';
        foreach(['confirmed_completed'=>'Подтверждены','pending'=>'Ожидают','recovery_required'=>'Требуют восстановления','explicitly_blocked'=>'Блокируют'] as $key=>$label)echo '<li>'.self::esc($label).': '.count($data[$key]).'</li>';
        echo '</ul><p>Pending не означает отказ. Recovery требует проверки прежней операции; закрытие сохраняет обязательства.</p>';
    }
    public static function inspection(array $view):void
    {
        global $db;require_once __DIR__.'/../Infrastructure/GmPanelQuery.php';$s=$view['session'];$e=$view['encounter'];
        $rows=(new AF_ACS_GmPanelQuery($db))->inspection($s['id'],$e['id'],$s['tid'],array_column($view['participants'],'entity_id'));
        echo '<details><summary>Снимки, группы и ресурсы текущей страницы</summary><ul>';
        foreach($rows as $r){echo '<li>Существо #'.self::esc($r['entity_id']).' · группа #'.self::esc($r['group_id']).' · snapshot #'.self::esc($r['snapshot_id']).' · '.($r['snapshot_valid']?'целостность подтверждена':'BLOCKED: hash не совпадает').' · HP '.self::esc($r['hp']).' / '.self::esc($r['hp_max']);foreach($r['resources'] as $resource)if($resource['key']!=='hp')echo ' · '.self::esc($resource['key']).' '.self::esc($resource['current']).' / '.self::esc($resource['maximum']);echo '</li>';}
        echo '</ul><p>Целостность снимка не означает готовность всех owner contracts. Полную готовность и права проверяет отдельная проверка и сервер запуска.</p></details>';
    }
    private static function sessionMembers(array $view,string $url): void
    {
        global $mybb;
        require_once __DIR__.'/Entities.php';
        $session=$view['session'];
        $service=af_advancedcombatsystem_sessions();
        echo '<section class="atf-card af-acs-gm__members"><h3>Участники сессии</h3>';
        if ($view['can_manage']) {
            $query=$mybb->get_input('member_search');
            parse_str((string)parse_url($url,PHP_URL_QUERY),$scope);
            echo '<form class="af-acs-gm__search" method="get" action="showthread.php">';
            self::fields($scope);
            echo '<label>Никнейм или UID <input type="search" name="member_search" maxlength="80" value="'.self::esc($query).'"></label>'
                .'<button class="atf-button" type="submit">Найти</button></form>';
            if ($query!=='') {
                $results=$service->searchMembers($session['id'],$query);
                if (!$results) echo '<p>Доступные пользователи не найдены.</p>';
                $searchUrl=$url.'&member_search='.rawurlencode($query);
                foreach ($results as $member) {
                    if ($member['uid']===$mybb->get_input('member_uid')) {
                        self::memberCard($session,$searchUrl.'&member_uid='.$member['uid'],$member);
                    } else {
                        echo '<a class="atf-button" href="'.self::esc($searchUrl.'&member_uid='.$member['uid']).'">'
                            .self::esc($member['username']).'</a> ';
                    }
                }
            }
        }
        echo '<div class="af-acs-gm__member-grid">';
        foreach ($service->listedMemberProfiles($view) as $member) self::memberCard($session,$url,$member);
        echo '</div></section>';
    }

    private static function memberCard(array $session,string $url,array $member): void
    {
        echo '<article class="af-acs-gm__entity">'.AF_ACS_EntitiesPage::portrait((int)$member['uid'])
            .'<div class="af-acs-gm__entity-label"><strong>'.self::esc($member['username']).'</strong>'
            .'<small>UID '.self::esc($member['uid']).'</small><span>'.self::esc(self::memberStatus($member)).'</span></div>';
        if ($member['editable']) {
            $label=$member['roles']?'Изменить роль':'Добавить участника';
            echo '<div class="af-acs-gm__entity-tools"><details><summary>'.self::esc($label).'</summary>';
            self::form($url,'session_set_role',$session);
            self::fields(['uid'=>$member['uid']]);
            echo '<label>Роль <select name="role">';
            $current=$member['roles'][0]??'player';
            foreach (self::ROLE_LABELS as $role=>$text) {
                if ($role==='owner' || (!$member['privileged'] && in_array($role,['gm','assistant_gm'],true))) continue;
                echo '<option value="'.$role.'"'.($role===$current?' selected':'').'>'.self::esc($text).'</option>';
            }
            echo '</select></label>';
            self::submit($label);
            echo '</details>';
            if ($member['roles']) {
                echo '<details><summary>Удалить доступ</summary>';
                self::form($url,'session_remove_member',$session);
                self::fields(['uid'=>$member['uid']]);
                self::submit('Удалить доступ');
                echo '</details>';
            }
            echo '</div>';
        }
        echo '</article>';
    }

    public static function sessionControls(array $v,string $url):void
    {
        $s=$v['session'];
        if ($s['status']==='active' && (new AF_ACS_SessionAccess())->administrator()) {
            self::form($url,'session_transfer_owner',$s);
            echo '<label>UID нового владельца <input name="uid" type="number" min="1" required></label>';
            self::submit('Передать владение сессией');
        }
        foreach(['request_close'=>'Подготовить закрытие сессии','close'=>'Закрыть сессию','archive'=>'Архивировать сессию','reopen_session'=>'Переоткрыть сессию'] as $op=>$label)if(!empty($v['can_'.($op==='reopen_session'?'reopen':$op)])){self::form($url,'session_'.$op,$s);self::submit($label);}
    }
    public static function settings(array $view,string $url,bool $compact=false):void
    {
        $s=$view['session'];$e=$view['encounter'];$config=json_decode($e['config_json'],true,64,JSON_THROW_ON_ERROR);
        if (!$compact) echo '<h3>Настройка столкновения</h3><p>Название: '.self::esc($config['title']??('#'.$e['id'])).'. Конфигурация закрепляется снимком; произвольный JSON и определения KB здесь не редактируются.</p>';
        if($s['status']!=='active'||$e['status']!=='preparation'){echo '<p>Настройка условий завершения доступна только до запуска.</p>';return;}
        if(!af_advancedcombatsystem_sessions()->view($s['id'])['can_audit']){echo '<p>Для изменения условий нужны права мастера этой сессии.</p>';return;}
        $policy=$config['end_conditions']??null;
        echo '<p>'.($policy?'Условия завершения сохранены.':'Условия завершения ещё не настроены. Автоматическое предложение исхода пока недоступно.').'</p>';
        self::form($url,'end_conditions_set',$s,$e);
        foreach(['last_active_side'=>'Одна активная враждебная сторона','no_active_sides'=>'Не осталось активных сторон'] as $key=>$label)echo '<label><input type="checkbox" name="'.($key==='last_active_side'?'gm_last_side':'gm_no_sides').'" value="1"'.($policy===null||in_array($key,$policy['conditions'],true)?' checked':'').'>'.self::esc($label).'</label>';
        echo '<label><input type="checkbox" name="gm_continue" value="1"'.(!empty($policy['allow_continuation'])?' checked':'').'> Разрешить отдельное решение о продолжении</label><label>Когда активных сторон нет <select name="gm_mutual">';
        $current=$policy['mutual_outcomes']??['draw'];$selected=count($current)>1?'both':$current[0];foreach(['draw'=>'Ничья','inconclusive'=>'Без окончательного исхода','both'=>'Выбор между этими исходами'] as $key=>$label)echo '<option value="'.$key.'"'.($selected===$key?' selected':'').'>'.$label.'</option>';
        echo '</select></label>';self::submit('Сохранить условия завершения');
    }
    public static function recovery(string $sid,string $url,bool $allowed):void
    {
        global $mybb;if(!$allowed){echo '<p>BLOCKED: восстановление требует текущих session GM прав.</p>';return;}
        echo '<p><a href="'.self::esc($url.'&gm_recovery=1').'">Открыть восстановление прежних операций</a></p>';
        if($mybb->get_input('gm_recovery')==='1')try{require_once __DIR__.'/RecoveryPage.php';echo AF_ACS_RecoveryPage::render($sid,$url.'&gm_recovery=1');}catch(Throwable $error){$failure=af_advancedcombatsystem_session_failure($error);echo '<p role="alert">BLOCKED: проверка прежнего состояния недоступна. Не создавайте новую операцию. error_id='.self::esc($failure['error_id']).'</p>';}
    }
    public static function ending(AF_ACS_EncounterService $service,array $view,string $url):void
    {
        $s=$view['session'];$e=$view['encounter'];
        if($s['status']!=='active'){echo '<p>Операции столкновения заблокированы состоянием сессии. Переоткрытие — отдельная разрешённая команда.</p>';return;}
        try{$state=$service->resolution($s['id'],$e['id']);}catch(Throwable $error){$failure=af_advancedcombatsystem_session_failure($error);echo '<p role="alert">BLOCKED: состояние исхода не подтверждено. Проверьте текущие session GM права, версии и диагностику. error_id='.self::esc($failure['error_id']).'</p>';return;}
        if($state['confirmed'])echo '<p>Исход подтверждён и сохранён. Старые события не редактируются; награды и добыча используют отдельные разрешённые операции.</p>';
        echo '<p><a href="misc.php?action=acs_loot&amp;session_id='.self::esc($s['id']).'&amp;encounter_id='.self::esc($e['id']).'">Завершение, добыча и награды</a></p>';
        $pending=$state['pending'];
        if(!$pending){echo '<p>Серверного кандидата исхода нет. Принудительно выбрать победителя или завершить бой в обход end-condition контракта нельзя.</p>';return;}
        echo '<h3>Ожидание подтверждения исхода</h3><p>Сохранённое решение #'.self::esc($pending['resolution_id']).'; условие '.self::esc($pending['condition']).'.</p>';
        self::form($url,'outcome_confirm',$s,$e);self::fields(['resolution_id'=>$pending['resolution_id']]);echo '<label>Разрешённый сервером исход <select name="outcome">';
        foreach($pending['outcomes'] as $outcome)echo '<option value="'.self::esc($outcome).'">'.self::esc(['victory'=>'Победа','draw'=>'Ничья','inconclusive'=>'Без окончательного исхода','defeat'=>'Поражение','escape'=>'Выход из боя'][$outcome]??$outcome).'</option>';echo '</select></label>';self::submit('Подтвердить исход столкновения');
        if($pending['allow_continuation']){self::form($url,'outcome_continue',$s,$e);self::fields(['resolution_id'=>$pending['resolution_id']]);self::submit('Продолжить по разрешённой политике');}
    }
}
