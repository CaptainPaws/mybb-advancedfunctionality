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
    public static function form(string $url,string $op,array $s,?array $e=null):void
    {
        global $mybb;echo '<form method="post" action="'.self::esc($url).'">';
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
        $steps=array_keys(self::STEPS);
        $index=array_search($stage,$steps,true);
        $resume=$url;
        if ($stage==='roster' && $registered==='' && $mybb->get_input('gm_picker')==='1') {
            $resume.='&gm_picker=1&kind='.rawurlencode($mybb->get_input('kind'));
            foreach (['source_key','source_slug','source_after'] as $field) {
                $value=$mybb->get_input($field);
                if ($value!=='') $resume.='&'.$field.'='.rawurlencode($value);
            }
        }
        $resumeAttr=$registered!=='' || $sourceCheck!==[] ? ' data-acs-gm-resume="'.self::esc($resume).'"' : '';
        echo '<nav aria-label="Шаги подготовки"'.$resumeAttr.'><ol class="af-acs-gm__steps">';
        foreach (self::STEPS as $key=>$label) {
            echo '<li>';
            if ($eid==='' && $key!=='settings') echo self::esc($label);
            else echo '<a href="'.self::esc(self::stepUrl($base,$eid,$key)).'"'.($key===$stage?' aria-current="step"':'').'>'.self::esc($label).'</a>';
            echo '</li>';
        }
        echo '</ol></nav><h3>'.self::esc(self::STEPS[$stage]).'</h3>';

        if ($eid==='') {
            if ($session['status']==='active') {
                self::form($url,'create',$session);
                echo '<label>Название столкновения <input name="name" maxlength="191" required value="'.self::esc($mybb->get_input('name')).'"></label>';
                self::submit('Создать столкновение');
            } else {
                echo '<p>Создание доступно в активной сессии.</p>';
            }
        } else {
            $view=$service->view($sid,$eid,$session['tid'],$mybb->get_input('participant_after')?:'0',$mybb->get_input('side_after')?:'0',$mybb->get_input('relation_after')?:'0');
            $encounter=$view['encounter'];
            $config=json_decode($encounter['config_json'],true,64,JSON_THROW_ON_ERROR);
            echo '<p><strong>'.self::esc($config['title']).'</strong> · '.self::esc(self::state($encounter['status'])).'</p>';
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
            echo '<nav aria-label="Переход между шагами">';
            if ($index>0) echo '<a href="'.self::esc(self::stepUrl($base,$eid,$steps[$index-1])).'">Назад</a>';
            if ($index<count($steps)-1) echo '<a href="'.self::esc(self::stepUrl($base,$eid,$steps[$index+1])).'">Далее</a>';
            echo '</nav>';
        }

        echo '<details><summary>Расширенные настройки и диагностика</summary>';
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
        global $mybb;
        $session=$view['session'];$encounter=$view['encounter'];
        $sessionView=af_advancedcombatsystem_sessions()->view($session['id']);
        $editable=$session['status']==='active' && $encounter['status']==='preparation';
        $allowed=array_merge($sessionView['can_register_own']?['character']:[],$sessionView['can_manage']?['npc','monster']:[]);
        $kind=$mybb->get_input('kind');
        $picker=$editable && $registered==='' && $mybb->get_input('gm_picker')==='1' && in_array($kind,$allowed,true);
        echo '<div class="af-acs-gm__composition'.($picker?' af-acs-gm__composition--picker':'').'">';
        if ($picker) {
            $pickerUrl=$url.'&gm_picker=1&kind='.rawurlencode($kind);
            echo '<aside class="af-acs-gm__picker" aria-labelledby="af-acs-gm-picker-title"><h4 id="af-acs-gm-picker-title">'.($kind==='character'?'Выбор персонажа':'Выбор NPC или монстра').'</h4>';
            echo '<nav aria-label="Источник существа">';
            if ($kind!=='character') {
                foreach (['npc'=>'NPC','monster'=>'Монстр'] as $type=>$label) {
                    echo '<a href="'.self::esc($url.'&gm_picker=1&kind='.$type).'"'.($kind===$type?' aria-current="page"':'').'>'.$label.'</a>';
                }
            }
            echo '<a href="'.self::esc($url).'">Закрыть выбор</a></nav>';
            require_once __DIR__.'/Entities.php';
            AF_ACS_EntitiesPage::render($session['id'],$sessionView,$pickerUrl,null,true,$sourceCheck,true);
            echo '</aside>';
        }
        echo '<div>';
        if ($editable) {
            echo '<nav aria-label="Регистрация существ">';
            if (in_array('character',$allowed,true)) echo '<a href="'.self::esc($url.'&gm_picker=1&kind=character').'">Добавить персонажа</a>';
            else echo '<span>Регистрация персонажа доступна владельцу листа с правом регистрации в сессии.</span>';
            if (in_array('npc',$allowed,true)) echo '<a href="'.self::esc($url.'&gm_picker=1&kind=npc').'">Добавить NPC или монстра</a>';
            echo '</nav>';
        } else echo '<p>Состав подготовки можно менять до запуска в активной сессии.</p>';

        if ($editable && $registered!=='') {
            if ($view['sides']) self::assignment($session,$encounter,$view['sides'],$url,$registered);
            else echo '<p>Существо зарегистрировано. Создайте сторону, затем добавьте его в состав.</p>';
        }
        self::participants($view,$url);
        echo '<h4>Зарегистрированные существа</h4>';
        $list=af_advancedcombatsystem_entities()->list($session['id'],$mybb->get_input('entity_after')?:'0');
        if (!$list['items']) echo '<p>Существ пока нет.</p>';
        else {
            $types=['character'=>'Персонаж','pet'=>'Питомец','npc'=>'NPC','monster'=>'Монстр','summon'=>'Призванное существо'];
            echo '<ul>';
            foreach ($list['items'] as $entity) {
                echo '<li>'.self::esc($entity['name']).' · '.self::esc($types[$entity['kind']]??'Существо').'</li>';
            }
            echo '</ul>';
            if ($list['next_after']!==null) echo '<a href="'.self::esc($url.'&entity_after='.$list['next_after']).'">Следующие существа</a>';
        }

        if ($editable) {
            self::form($url,'side_create',$session,$encounter);
            echo '<label>Название стороны <input name="name" maxlength="191" required></label>';
            self::submit('Добавить сторону');
            if (!$view['sides']) echo '<p>Создайте сторону, чтобы добавить в неё существо.</p>';
            else {
                echo '<p>Стороны: '.self::esc(implode(', ',array_column($view['sides'],'name'))).'</p>';
                $inRoster=array_fill_keys(array_column($view['participants'],'entity_id'),true);
                $candidates=$service->candidates($session['id'],$mybb->get_input('candidate_after')?:'0');
                $available=array_filter($candidates,static fn($entity)=>!isset($inRoster[$entity['id']]) && (string)$entity['id']!==$registered);
                if ($available) self::assignment($session,$encounter,$view['sides'],$url,'',$available);
                if (count($candidates)===100) echo '<a href="'.self::esc($url.'&candidate_after='.end($candidates)['id']).'">Следующие существа для добавления</a>';
                if ($view['participants']) {
                    self::form($url,'participant_side',$session,$encounter);
                    echo '<label>Участник <select name="entity_id" required><option value="">Выберите участника</option>';
                    foreach ($view['participants'] as $row) echo '<option value="'.self::esc($row['entity_id']).'">'.self::esc($row['name']).'</option>';
                    echo '</select></label><label>Новая сторона ';
                    self::sides($view['sides'],'side_id');echo '</label>';
                    self::submit('Изменить сторону');
                }
                if (count($view['sides'])>1) {
                    self::form($url,'relation_set',$session,$encounter);
                    echo '<label>Первая сторона ';self::sides($view['sides'],'side_a');echo '</label><label>Вторая сторона ';
                    self::sides($view['sides'],'side_b');
                    echo '</label><label>Отношение <select name="relationship" required><option value="">Выберите отношение</option><option value="hostile">Враждебные</option><option value="allied">Союзники</option><option value="neutral">Нейтральные</option></select></label>';
                    self::submit('Сохранить отношение');
                    $names=array_column($view['sides'],'name','id');
                    if ($view['relationships']) {
                        echo '<ul>';
                        foreach ($view['relationships'] as $row) {
                            echo '<li>'.self::esc($names[$row['side_a']]??'Другая сторона').' / '.self::esc($names[$row['side_b']]??'Другая сторона').' · '.self::esc(['hostile'=>'Враждебные','allied'=>'Союзники','neutral'=>'Нейтральные'][$row['relationship']]??'Не определено').'</li>';
                        }
                        echo '</ul>';
                    }
                }
                if (count($view['sides'])===100) echo '<a href="'.self::esc($url.'&side_after='.end($view['sides'])['id']).'">Следующие стороны</a>';
                if (count($view['relationships'])===100) echo '<a href="'.self::esc($url.'&relation_after='.end($view['relationships'])['id']).'">Следующие отношения</a>';
            }
        }
        echo '</div></div>';
    }

    private static function assignment(array $session,array $encounter,array $sides,string $url,string $registered='',array $candidates=[]): void
    {
        self::form($url,'participant_add',$session,$encounter);
        if ($registered!=='') {
            self::fields(['entity_id'=>$registered]);
            echo '<p>Выберите сторону для только что зарегистрированного существа.</p>';
        } else {
            echo '<label>Зарегистрированное существо <select name="entity_id" required><option value="">Выберите существо</option>';
            foreach ($candidates as $candidate) echo '<option value="'.self::esc($candidate['id']).'">'.self::esc($candidate['name']).'</option>';
            echo '</select></label>';
        }
        echo '<label>Сторона ';
        self::sides($sides,'side_id');echo '</label>';
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
    public static function sessionControls(array $v,string $url):void
    {
        $s=$v['session'];
        if($v['can_manage'])foreach(['set_role'=>'Изменить роль участника','remove_member'=>'Удалить доступ участника'] as $op=>$label){
            self::form($url,'session_'.$op,$s);echo '<h4>'.self::esc($label).'</h4><label>UID <input name="uid" type="number" min="1" required></label>';
            if($op==='set_role'){$default=json_decode($s['config_json'],true)['default_member_role']??'observer';echo '<label>Роль <select name="role">';foreach(['gm'=>'Мастер','assistant_gm'=>'Помощник','player'=>'Игрок','observer'=>'Наблюдатель'] as $key=>$text)echo '<option value="'.$key.'"'.($key===$default?' selected':'').'>'.$text.'</option>';echo '</select></label>';}
            self::submit($label);
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
