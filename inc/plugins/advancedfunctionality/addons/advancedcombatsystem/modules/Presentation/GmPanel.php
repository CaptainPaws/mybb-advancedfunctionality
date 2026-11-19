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
                $mybb->input['gm_step'] = '2';
                return '<p role="status">Сессия создана. Настройте столкновение.</p>'
                    .AF_ACS_EncountersPage::render($result['session_id'],$tid);
            } catch (Throwable $error) {
                if (isset($result['session_id'])) {
                    $url = 'showthread.php?tid='.$tid.'&acs_session='.$result['session_id'].'&acs_setup=1&gm_step=2#acs-thread-panel';
                    return '<p role="status">Сессия создана. <a href="'.self::esc($url).'">Открыть созданную сессию</a>.</p>';
                }
                $failure = af_advancedcombatsystem_session_failure($error);
                $outcome=['error'=>true,'uncertain'=>in_array($failure['category'],['RECOVERY_REQUIRED','INTERNAL_ERROR'],true)];
                $notice = '<p role="alert">Создание не подтверждено. При неизвестном ответе повторяйте прежнюю форму/ключ. error_id='.self::esc($failure['error_id']).'</p>';
            }
        }

        ob_start();
        echo '<section class="af-acs-gm"><h2>Мастер столкновения · 1. Сессия</h2>'.$notice;
        echo '<form method="post" action="'.self::esc('showthread.php?tid='.$tid.'&acs_setup=1&acs_atf_setup=1#acs-thread-panel').'">';
        self::fields(['acs_encounter_op'=>'session_create','operation_key'=>'atf-thread-create-'.$tid,'tid'=>$tid,'my_post_key'=>$mybb->post_code]);
        echo '<p>Сессия будет привязана к текущей теме. Повтор использует прежний ключ.</p>';
        echo '<button type="submit">Подтвердить создание сессии</button></form></section>';
        return ob_get_clean();
    }

    public static function wizard(string $base,string $eid,int $step):void
    {
        $labels=['Сессия','Столкновение','Участники','Проверка','Предпросмотр состава','Запуск'];
        echo '<nav aria-label="Мастер создания столкновения"><ol class="af-acs-gm__steps">';
        foreach($labels as $i=>$label){$n=$i+1;$url=$n===1?$base.($eid!==''?'&acs_encounter='.$eid:'').'&acs_setup=1&gm_step=1':$base.($eid!==''?'&acs_encounter='.$eid:'').'&acs_setup=1&gm_step='.$n;
            echo '<li><a href="'.self::esc($url).'"'.($n===$step?' aria-current="step"':'').'>'.$n.'. '.self::esc($label).'</a></li>';}
        echo '</ol></nav><p>Каждое изменение сохраняется отдельной командой. Переход между шагами не выполняет регистрацию, запуск или повтор механики.</p>';
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
    public static function settings(array $view,string $url):void
    {
        $s=$view['session'];$e=$view['encounter'];$config=json_decode($e['config_json'],true,64,JSON_THROW_ON_ERROR);
        echo '<h3>Настройка столкновения</h3><p>Название: '.self::esc($config['title']??('#'.$e['id'])).'. Конфигурация закрепляется снимком; произвольный JSON и определения KB здесь не редактируются.</p>';
        if($s['status']!=='active'||$e['status']!=='preparation'){echo '<p>Настройка условий завершения доступна только до запуска.</p>';return;}
        if(!af_advancedcombatsystem_sessions()->view($s['id'])['can_audit']){echo '<p>BLOCKED: нужны текущие session GM права, одного глобального ACP недостаточно.</p>';return;}
        $policy=$config['end_conditions']??null;
        echo '<p>'.($policy?'Условия завершения сохранены.':'Условия завершения ещё не настроены; без них сервер не предложит исход автоматически.').'</p>';
        self::form($url,'end_conditions_set',$s,$e);
        foreach(['last_active_side'=>'Одна активная враждебная сторона','no_active_sides'=>'Не осталось активных сторон'] as $key=>$label)echo '<label><input type="checkbox" name="'.($key==='last_active_side'?'gm_last_side':'gm_no_sides').'" value="1"'.($policy===null||in_array($key,$policy['conditions'],true)?' checked':'').'>'.self::esc($label).'</label>';
        echo '<label><input type="checkbox" name="gm_continue" value="1"'.(!empty($policy['allow_continuation'])?' checked':'').'> Разрешить отдельное решение о продолжении</label><label>Когда активных сторон нет <select name="gm_mutual">';
        $current=$policy['mutual_outcomes']??['draw'];$selected=count($current)>1?'both':$current[0];foreach(['draw'=>'Ничья','inconclusive'=>'Без окончательного исхода','both'=>'Выбор между этими исходами'] as $key=>$label)echo '<option value="'.$key.'"'.($selected===$key?' selected':'').'>'.$label.'</option>';
        echo '</select></label><p>Контракт v1 проверяет только поддержанные условия и явные враждебные стороны; этот UI не назначает победителя.</p>';self::submit('Сохранить условия завершения');
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
