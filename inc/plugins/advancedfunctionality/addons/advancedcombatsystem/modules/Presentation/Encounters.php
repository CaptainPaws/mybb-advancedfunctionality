<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/GmPanel.php';

/** Small server-rendered GM surface in the ATF thread slot; no JS/editor/runtime. */
final class AF_ACS_EncountersPage
{
    private static function esc(mixed $value): string {return htmlspecialchars_uni((string)$value);}
    public static function render(string $sid,string $tid,?AF_ACS_EncounterService $service=null, array &$outcome=[]): string
    {
        global $mybb;
        $service??=af_advancedcombatsystem_encounters();
        // Authorize and verify the native topic/session link before reading input or rendering forms.
        $list=$service->list($sid,$mybb->get_input('encounter_after')?:'0',$tid);
        $session=$service->context($sid,$tid);
        $eid=$mybb->get_input('acs_encounter');$tab=$mybb->get_input('acs_tab')?:'overview';
        $tabs=['overview'=>'Обзор','participants'=>'Участники','rounds'=>'Раунды','events'=>'События','management'=>'Управление'];
        $setup=$mybb->get_input('acs_setup')==='1'||$mybb->get_input('gm_step')!=='';
        $stage=AF_ACS_GmPanel::stage($mybb->get_input('gm_stage'),$mybb->get_input('gm_step'));
        if(!isset($tabs[$tab]))$tab='overview';
        $base='showthread.php?tid='.$tid.'&acs_session='.$sid;$notice='';$readiness=null;$commandBlocked=false;$sourceCheck=[];$registered='';
        if($mybb->request_method==='post' && $mybb->get_input('acs_encounter_op')!=='') {
            $op=$mybb->get_input('acs_encounter_op');
            $sourcePreview=$op==='entity_request' && $mybb->get_input('op')==='entity_validate';
            $input=[];
            $characterRegistration=$op==='entity_request' && $mybb->get_input('op')==='entity_register' && $mybb->get_input('kind')==='character';
            $registrationPhase=$characterRegistration?'registration_scope':null;
            $assignmentPhase=$op==='participant_add'?'participant_scope':null;
            $rolePhase=$op==='session_set_role'?'role_form':null;
            try {
                // The posted session may not redirect a command to another thread/scope.
                if($mybb->get_input('session_id')!==$sid || $mybb->get_input('tid')!==$tid)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
                if($eid!=='' && $mybb->get_input('encounter_id')!=='' && $mybb->get_input('encounter_id')!==$eid)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
                foreach(['session_id','expected_version','encounter_id','encounter_version','operation_key','reason','name','side_a','side_b','relationship','entity_id','side_id','uid','tid','mode','uids','assignment_version','visibility_scope','participant_version','participant_id','acs_confirm','presence_template','relation_version','round_id','entity_turn_id','control_group_id','confirm_full','confirm','deadline_mode','seconds','turn_id','turn_version','effect_order','kind','source_key','initial_hp','preparation_fingerprint','owner_uid','role','resolution_id','outcome'] as $field)$input[$field]=$mybb->get_input($field);
                if (array_key_exists('side_accent',$_POST)) $input['side_accent']=$_POST['side_accent'];
                if ($op==='side_accent') $input['side_version']=$mybb->get_input('side_version');
                $committed=['committed'=>true,'sid'=>$sid];
                AF_ACS_GmIntent::requireConfirmation(str_starts_with($op,'session_')?substr($op,8):$op);
                if(str_starts_with($op,'session_')){
                    $serviceSession=af_advancedcombatsystem_sessions();
                    $result=$serviceSession->command(substr($op,8),$input,phase:$rolePhase);
                    $outcome=$committed;
                    $notice='<p role="status">'.($op==='session_set_role'?'Роль участника сохранена.':'Переход сессии подтверждён сервером.').'</p>';
                    $session=$service->context($sid,$tid);$list=$service->list($sid,tid:$tid);
                }elseif($op==='entity_request'){
                    $entityOp=$mybb->get_input('op');if(!in_array($entityOp,['entity_validate','entity_register'],true))throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
                    $entities=af_advancedcombatsystem_entities();
                    if ($entityOp==='entity_validate') {
                        $sourceCheck=$entities->validate($sid,$input)+$input;
                    } else {
                        if ($input['preparation_fingerprint']==='') throw new RuntimeException('ACS_SOURCE_CHECK_REQUIRED');
                        $result=$entities->register($input,$registrationPhase);
                        $registered=(string)$result['entity_id'];
                        $outcome=$committed;
                        $notice='<p role="status">'.($characterRegistration?'Персонаж зарегистрирован.':'Существо зарегистрировано.').' Теперь его можно добавить в столкновение.</p>';
                        $session=$service->context($sid,$tid);
                    }
                }elseif($op==='delegation_request'){
                    $delegationOp=$mybb->get_input('op');if(!in_array($delegationOp,['delegation_grant','delegation_revoke'],true))throw new RuntimeException('ACS_DELEGATION_UNAVAILABLE');AF_ACS_GmIntent::requireConfirmation($delegationOp);
                    $request=[];foreach(['session_id','expected_version','operation_key','reason','owner_uid','delegate_uid','entity_ids','control_group_id','permitted_actions','valid_from','valid_until','duration_seconds','delegation_id','delegation_version'] as $f)if(array_key_exists($f,$_POST))$request[$f]=in_array($f,['entity_ids','permitted_actions'],true)?$_POST[$f]:$mybb->get_input($f);
                    $service->delegations()->command(substr($delegationOp,11),$request);$outcome=$committed;$session=$service->context($sid,$tid);$notice='<p role="status">Делегирование подтверждено сервером.</p>';
                }elseif(in_array($op,['end_conditions_set','outcome_confirm','outcome_continue'],true)){
                    require_once __DIR__.'/EndConditionController.php';$request=[];foreach(['session_id','encounter_id','expected_version','encounter_version','reason','operation_key',...($op==='end_conditions_set'?[]:['resolution_id']),...($op==='outcome_confirm'?['outcome']:[])] as $f)$request[$f]=$mybb->get_input($f);if($op==='end_conditions_set'){$conditions=[];if($mybb->get_input('gm_last_side')==='1')$conditions[]='last_active_side';if($mybb->get_input('gm_no_sides')==='1')$conditions[]='no_active_sides';$mutual=match($mybb->get_input('gm_mutual')){'draw'=>['draw'],'inconclusive'=>['inconclusive'],'both'=>['draw','inconclusive'],default=>throw new RuntimeException('ACS_END_INPUT_INVALID')};$request['end_conditions']=json_encode(['schema'=>'ic-acs.end-conditions.v1','conditions'=>$conditions,'allow_continuation'=>$mybb->get_input('gm_continue')==='1','mutual_outcomes'=>$mutual],JSON_THROW_ON_ERROR);}AF_ACS_EndConditionController::handle($service,$op,$request,'post');$outcome=$committed;$session=$service->context($sid,$tid);$notice=$op==='end_conditions_set'?'<p role="status">Условия завершения сохранены в снимке. Бой не запущен.</p>':'<p role="status">Решение исхода подтверждено. Сессия не закрывается автоматически.</p>';
                }elseif($op==='readiness') {
                    (new AF_ACS_SessionAccess)->csrf();$eid=$input['encounter_id'];$readiness=$service->readiness($sid,$eid);
                    $notice='<p>'.($readiness['preparation_ready']?'Подготовка проверена.':'Подготовка содержит блокировки.').' Для запуска первого раунда откройте вкладку «Раунды».</p>';
                    foreach($readiness['diagnostics'] as $issue)$notice.='<p>'.self::esc(self::issue($issue['code'])).(isset($issue['entity_id'])?' · существо #'.self::esc($issue['entity_id']):'').'</p>';
                } else {
                    if($op==='cover_assignment'){$r=[];foreach(['session_id','encounter_id','expected_version','expected_encounter_version','cover_entity_id','protected_entity_id','expected_assignment_version','status','operation_key','reason'] as $field)$r[$field]=$mybb->get_input($field);$result=$service->cover()->command($r);}elseif(str_starts_with($op,'spatial_')){$spatialOp=substr($op,8);$extra=match($spatialOp){'configure'=>['kb_key'],'connection'=>['from','to','status'],'position'=>['entity_id','zone_id','visibility'],'zone'=>['zone_id','visibility'],'engagement'=>['entity_id','target_id','status'],default=>throw new RuntimeException('ACS_SPATIAL_UNSUPPORTED')};$r=[];foreach(['session_id','encounter_id','expected_version','expected_encounter_version','expected_spatial_version','operation_key','reason',...$extra] as $field)$r[$field]=$mybb->get_input($field);$result=$service->spatial()->command($spatialOp,$r);}else $result=str_starts_with($op,'manage_')?$service->rounds()->command(substr($op,7),$input):(str_starts_with($op,'clock_')?$service->clock()->command(substr($op,6),$input):$service->command($op,$input,$assignmentPhase));$eid=$result['encounter_id']??$eid;$outcome=$committed+['eid'=>$eid];$notice='<p>Изменение сохранено.</p>';
                    $session=$service->context($sid,$tid);$list=$service->list($sid,tid:$tid);
                    if ($op==='create') {
                        $notice='<p role="status">Столкновение создано. Сохраните условия завершения или перейдите к составу.</p>';
                    } elseif ($op==='side_rename' || $op==='side_delete') {
                        $notice='<p role="status">'.($op==='side_rename'?'Сторона переименована.':'Сторона удалена.').'</p>';
                    } elseif ($op==='side_accent') {
                        $notice='<p role="status">Оформление стороны сохранено.</p>';
                    } elseif ($op==='participant_remove') {
                        $recent=AF_ACS_GmPanel::stepUrl($base,$eid,'roster').'&gm_picker=1&kind=recent&gm_entity='.rawurlencode($result['entity_id']);
                        $notice='<p role="status">Существо убрано из состава. <a href="'.self::esc($recent).'">Открыть в «Недавних»</a>.</p>';
                    } elseif ($op==='participant_add') {
                        $notice='<p role="status">Существо добавлено в столкновение.</p>';
                    } elseif ($op==='round_start') {
                        $notice='<p role="status">Бой запущен. Первый раунд открыт.</p>';
                    }
                }
            } catch(Throwable $error) {
                if ($rolePhase!==null && empty($outcome['committed'])) {
                    $failure=self::roleAssignmentFailure($error,$rolePhase);
                    $commandBlocked=$failure['uncertain'];
                } elseif (!$sourcePreview && empty($outcome['committed'])) {
                    $failure=$op==='participant_add'
                        ? af_advancedcombatsystem_session_failure($error,$assignmentPhase,'participant_add')
                        : af_advancedcombatsystem_session_failure($error,$characterRegistration?$registrationPhase:null);
                    $commandBlocked=!str_contains($error->getMessage(),'VERSION_CONFLICT') && in_array($failure['category'],['RECOVERY_REQUIRED','INTERNAL_ERROR'],true);
                }
                if ($sourcePreview) {
                    $sourceCheck=$error->getMessage()==='ACS_ENCOUNTER_UNAVAILABLE'
                        ? ['ready'=>false,'status'=>'denied','diagnostics'=>[],'message'=>'Источник недоступен для текущей темы или столкновения.']
                        : af_advancedcombatsystem_source_check_error($error,$input?'preview_dispatch':'preview_scope');
                    $sourceCheck+=$input;
                    if (!$input) $notice='<p role="alert">'.self::esc($sourceCheck['message']).'</p>';
                } elseif (!empty($outcome['committed'])) {
                    return '<p role="status">Команда подтверждена. <a href="'.self::esc($base).'">Перечитать состояние</a>.</p>';
                } elseif ($rolePhase!==null) {
                    $outcome=['error'=>true,'uncertain'=>$commandBlocked,'error_id'=>$failure['error_id']];
                    $notice='<p role="alert">'.self::esc($failure['message']).'</p>';
                } elseif ($setup && $op==='round_start' && in_array($error->getMessage(),[
                    'ACS_ROSTER_BLOCKED','ACS_VERSION_CONFLICT','ACS_FIRST_ROUND_EXISTS','ACS_ENCOUNTER_TRANSITION_BLOCKED',
                ],true)) {
                    $session=$service->context($sid,$tid);
                    $outcome=[];
                    $notice='<p role="alert">Запуск не подтверждён. Состояние подготовки обновлено. Код обращения: '.self::esc($failure['error_id']).'</p>';
                } elseif (in_array($op,['side_create','side_accent'],true) && ($message=match($error->getMessage()) {
                    'ACS_SIDE_ACCENT_INVALID'=>'Выберите цветовой акцент из палитры.',
                    'ACS_SIDE_POLICY_INVALID'=>'Текущие настройки стороны недоступны для изменения.',
                    'ACS_SIDE_UNAVAILABLE'=>'Сторона больше недоступна в этом столкновении.',
                    'ACS_ENCOUNTER_TRANSITION_BLOCKED'=>'Оформление стороны доступно до запуска столкновения.',
                    default=>null,
                })!==null) {
                    $commandBlocked=false;
                    $outcome=['error'=>true];
                    $notice='<p role="alert">'.self::esc($message).'</p>';
                } elseif (in_array($op,['side_rename','side_delete'],true) && ($message=match($error->getMessage()) {
                    'ACS_SIDE_HAS_PARTICIPANTS'=>'Сначала переместите всех участников этой стороны через «Настроить» в составе столкновения.',
                    'ACS_SIDE_NAME_DUPLICATE'=>'Сторона с таким названием уже существует.',
                    'ACS_INVALID_TEXT'=>'Проверьте название стороны и причину. Название должно содержать от 1 до 191 символа без управляющих символов.',
                    'ACS_SIDE_UNAVAILABLE'=>'Сторона больше недоступна в этом столкновении.',
                    'ACS_ENCOUNTER_TRANSITION_BLOCKED'=>'Переименование и удаление сторон доступны только до запуска столкновения.',
                    default=>null,
                })!==null) {
                    $outcome=['error'=>true];
                    $notice='<p class="error" role="alert">'.self::esc($message).' Код обращения: '.self::esc($failure['error_id']).'</p>';
                } elseif ($op==='participant_remove' && ($message=match($error->getMessage()) {
                    'ACS_ENCOUNTER_TRANSITION_BLOCKED'=>'Убрать участника можно только до запуска боя.',
                    'ACS_PARTICIPANT_UNAVAILABLE'=>'Участник больше недоступен в этом столкновении.',
                    'ACS_VERSION_CONFLICT'=>'Состав изменился. Обновите панель перед исключением участника.',
                    'ACS_ROUND_OBLIGATIONS_BLOCKED','ACS_OBLIGATIONS_SCHEMA_UNVERIFIED','ACS_CLOCK_DEPENDENCY_BLOCKED',
                    'ACS_PUBLICATION_UNRESOLVED'=>'Сначала урегулируйте незавершённые обязательства столкновения.',
                    'ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE'=>'Для исключения участника нужны действующие права GM.',
                    'ACS_UI_CONFIRMATION_REQUIRED'=>'Подтвердите исключение участника.',
                    default=>null,
                })!==null) {
                    $commandBlocked=false;
                    $outcome=['error'=>true];
                    $notice='<p role="alert">'.self::esc($message).' Код обращения: '.self::esc($failure['error_id']).'</p>';
                } elseif ($op==='entity_request' && ($error instanceof AF_ACS_RegistrationBlocked
                    || in_array($error->getMessage(),['ACS_SOURCE_CHECK_REQUIRED','ACS_DEFINITION_VERSION_CONFLICT','ACS_HP_RANGE','ACS_HP_INTEGER_REQUIRED'],true))) {
                    // Render the current form again so the modal discards a stale confirmation.
                    $sourceCheck=['ready'=>false,'diagnostics'=>$failure['diagnostics']??[],'message'=>$failure['message']
                        .' Код обращения: '.$failure['error_id']]+$input;
                    $outcome=[];
                    $notice='';
                } elseif ($op==='participant_add' && ($message=self::assignmentError($error->getMessage(),$assignmentPhase))!==null) {
                    $outcome=['error'=>true,'uncertain'=>false];
                    $commandBlocked=false;
                    $notice='<p role="alert">'.self::esc($message).' Код обращения: '.self::esc($failure['error_id']).'</p>';
                } elseif ($characterRegistration) {
                    $code=$error->getMessage();
                    $uncertain=$failure['category']==='RECOVERY_REQUIRED'
                        || in_array($code,['ACS_LOCK_RELEASE_UNKNOWN','ACS_DB_SESSION_RESTORE_FAILED','ACS_TRANSACTION_ERROR','ACS_DB_SESSION_SETTINGS_FAILED'],true)
                        || ($registrationPhase==='registration_finalize' && $failure['category']==='INTERNAL_ERROR');
                    $commandBlocked=$uncertain;
                    $outcome=['error'=>true,'uncertain'=>$uncertain];
                    $message=match(true) {
                        $uncertain=>'Исход регистрации не подтверждён. Проверьте прежнюю операцию и используйте её ключ.',
                        $code==='ACS_OPERATION_KEY_CONFLICT'=>'Ключ регистрации относится к другому запросу. Проверьте прежнюю операцию.',
                        $code==='ACS_ENTITY_ALREADY_REGISTERED'=>'Персонаж уже зарегистрирован в этой сессии.',
                        $failure['category']==='STATE_CONFLICT'=>'Состояние изменилось. Обновите панель и повторите проверку источника.',
                        $failure['category']==='INTERNAL_ERROR'=>'Регистрация прервана. Перед повторной попыткой проверьте диагностику ACS.',
                        default=>$failure['message'],
                    };
                    $notice='<p class="error" role="alert">'.self::esc($message).' Код обращения: '.self::esc($failure['error_id']).'</p>';
                } else {
                    $outcome=['error'=>true,'uncertain'=>$commandBlocked];
                    $notice='<p class="error" role="alert">'.(str_contains($error->getMessage(),'VERSION_CONFLICT')?'Состояние изменилось. Обновите текущий шаг и проверьте введённые данные. ':($error->getMessage()==='ACS_UI_CONFIRMATION_REQUIRED'?'Требуется явное подтверждение последствий. ':($error->getMessage()==='ACS_RECOVERY_REQUIRED'?'RECOVERY_REQUIRED: проверьте прежнюю операцию. ':'Команда не подтверждена. Проверьте права, BLOCKED-причины и версии. '))).'Не повторяйте неизвестный исход с новым ключом. error_id='.self::esc($failure['error_id']).'</p>';
                    if (!empty($failure['diagnostics'])) {
                        require_once __DIR__.'/Entities.php';
                        $notice.=AF_ACS_EntitiesPage::diagnostics($failure['diagnostics']);
                    }
                }
            }
        }
        if (isset($sourcePreview) && $sourcePreview) {
            $outcome=['source_status'=>$sourceCheck['status']];
            if (isset($sourceCheck['error_id'])) $outcome['error_id']=$sourceCheck['error_id'];
        }
        ob_start();
        try {
            echo '<section class="af-acs af-acs-gm'.($setup?' af-acs-gm--wizard':'').'">'.(!$setup?'<h2>ACS · Панель GM</h2>':'').$notice;
            if($commandBlocked){echo '<p>Новые команды временно не предлагаются. <a href="'.self::esc($base.'&acs_tab=overview').'">Перечитать состояние без повторного POST</a>. Для восстановления используйте прежнюю операцию и диагностику; не создавайте другой ключ.</p></section>';return ob_get_clean();}
            if ($setup) {
                AF_ACS_GmPanel::preparation($service,$session,$list,$base,$eid,$stage,$sourceCheck,$registered);
                echo '</section>';
                return ob_get_clean();
            }
            echo '<p><a href="'.self::esc('showthread.php?tid='.$tid.'&acs_panel_session='.$sid.'#acs-thread-panel').'">К панели темы</a> · <a href="'.self::esc(AF_ACS_GmPanel::stepUrl($base,$eid,'settings')).'">Подготовка боя</a></p>';
            echo '<ul>';
            foreach($list['items'] as $row){$config=json_decode($row['config_json'],true);echo '<li><a href="'.self::esc($base.'&acs_encounter='.$row['id']).'">'.self::esc($config['title']??('Столкновение #'.$row['id'])).' · '.self::esc(self::state($row['status'])).'</a></li>';}
            echo '</ul>';
            if($list['next_after']!==null)echo '<a href="'.self::esc($base.'&encounter_after='.$list['next_after']).'">Следующие столкновения</a>';
            if($eid==='') {
                if($session['status']==='active') {AF_ACS_GmPanel::form($base,'create',$session);echo '<label>Название <input name="name" maxlength="191" required></label>';AF_ACS_GmPanel::submit('Создать столкновение');}
                echo '<details><summary>Расширенные настройки и диагностика</summary>';
                $sessionView=af_advancedcombatsystem_sessions()->view($sid,true);
                AF_ACS_GmPanel::sessionControls($sessionView,$base);
                AF_ACS_GmPanel::recovery($sid,$base,$sessionView['can_audit']);
                echo '</details>';
                echo '</section>';return ob_get_clean();
            }
            $view=$service->view($sid,$eid,$tid,$mybb->get_input('participant_after')?:'0',$mybb->get_input('side_after')?:'0',$mybb->get_input('relation_after')?:'0');$encounter=$view['encounter'];echo '<p><a href="misc.php?action=acs_archive&amp;session_id='.self::esc($sid).'&amp;encounter_id='.self::esc($eid).'">Боевой архив</a></p>';echo '<p><a href="misc.php?action=acs_loot&amp;session_id='.self::esc($sid).'&amp;encounter_id='.self::esc($eid).'">Распределение добычи</a></p>';$url=$base.'&acs_encounter='.$eid.'&acs_tab='.$tab;
            echo '<nav aria-label="Панель GM">';foreach($tabs as $key=>$name)echo '<a href="'.self::esc($base.'&acs_encounter='.$eid.'&acs_tab='.$key).'"'.($tab===$key?' aria-current="page"':'').'>'.self::esc($name).'</a> ';echo '</nav>';
            if($tab==='overview') {
                $config=json_decode($encounter['config_json'],true);echo '<h3>'.self::esc($config['title']).'</h3><p>'.self::esc(self::state($encounter['status'])).' · версия '.self::esc($encounter['encounter_version']).'</p><p>Завершение столкновения и переходы сессии — отдельные подтверждаемые операции.</p>';
                AF_ACS_GmPanel::overview(af_advancedcombatsystem_sessions()->view($sid,true),$view,$url);
                AF_ACS_GmPanel::inspection($view);
            } elseif($tab==='rounds') {
                if(in_array($encounter['status'],['preparation','ambush_ready'],true)||$encounter['start_operation_id']!==null){try{require_once __DIR__.'/Ambush.php';echo AF_ACS_AmbushPage::render($sid,$eid,$tid);}catch(Throwable $error){$failure=af_advancedcombatsystem_session_failure($error);echo '<p role="alert">BLOCKED: состояние засады недоступно. Не создавайте новый запуск вместо прежнего. error_id='.self::esc($failure['error_id']).'</p>';}}
                $roundView=$service->roundView($sid,$eid,$mybb->get_input('turn_after')?:'0');
                if($roundView['round']===null) {
                    echo '<p>Первый раунд не запущен.</p>';
                    if($session['status']==='active' && $encounter['status']==='preparation' && $encounter['start_operation_id']===null) {
                        AF_ACS_GmPanel::form($url,'round_start',$session,$encounter);AF_ACS_GmPanel::submit('Начать обычное столкновение без засады');
                        echo '<p>Перед запуском сервер повторно проверит готовность и зафиксирует roster. Порядок действий групп свободный.</p>';
                    }
                    require_once __DIR__.'/RoundManagement.php';AF_ACS_RoundManagementPage::render($view,$roundView,$url,$service->rounds());
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
                    echo '<p>Начало/конец индивидуальных ходов выполняются отдельными серверными командами; просмотр их не исполняет. Публикация остаётся под действующими capability gates.</p>';
                    require_once __DIR__.'/RoundManagement.php';AF_ACS_RoundManagementPage::render($view,$roundView,$url,$service->rounds());
                    if($roundView['next_after']!==null)echo '<a href="'.self::esc($url.'&turn_after='.$roundView['next_after']).'">Следующие группы</a>';
                }
            } elseif($tab==='participants') {
                echo '<table><tr><th>Существо</th><th>Группа</th><th>Сторона</th><th>Управляющий</th><th>Участие</th></tr>';$sides=array_column($view['sides'],'name','id');
                foreach($view['participants'] as $p)echo '<tr><td>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).'</td><td>#'.self::esc($p['control_group_id']).'</td><td>'.self::esc($sides[$p['side_id']]??($p['side_id']===null?'Не назначена':'Сторона #'.$p['side_id'])).'</td><td>'.self::esc(AF_ACS_GmPanel::state($p['controller_mode']??'Нет назначения')).' · '.self::esc(implode(', ',$p['controller_uids'])?:'—').'</td><td>'.self::esc(AF_ACS_GmPanel::state($p['participation'])).' · '.self::esc(AF_ACS_GmPanel::state($p['life_state'])).'</td></tr>';echo '</table>';
                if(count($view['participants'])===100)echo '<a href="'.self::esc($url.'&participant_after='.end($view['participants'])['id']).'">Следующие участники</a>';
                AF_ACS_GmPanel::inspection($view);
                echo '<p>Группа управления независима от стороны. Питомец сохраняет группу владельца; самостоятельные монстры имеют отдельные будущие ходы.</p>';
            } elseif($tab==='events') {
                $events=$service->events($sid,$eid,$mybb->get_input('event_after')?:'0');echo '<p>Неизменяемый журнал с результатами, связанными постами и корректировками доступен по ссылке «Боевой архив». Отказы и неизвестные исходы проверяются в разделе восстановления.</p><ul>';
                foreach($events as $event)echo '<li>#'.self::esc($event['event_sequence']).' · '.self::esc(self::event($event['event_type'])).' · UID '.self::esc($event['actor_uid']).'</li>';echo '</ul>';
                if(count($events)===100)echo '<a href="'.self::esc($url.'&event_after='.end($events)['id']).'">Следующие события</a>';
            }
            if($tab==='participants' && $session['status']==='active' && $encounter['status']==='active') {
                echo '<h3>Добавить участника для следующего раунда</h3><p>Текущий roster неизменяем. Добавление не создаёт новый turn или EntityTurn в текущем раунде и не выдаёт AP.</p>';
                if($view['sides']) {
                    AF_ACS_GmPanel::form($url,'participant_add',$session,$encounter);echo '<select name="entity_id" required><option value="">Выберите существо</option>';
                    $candidates=$service->candidates($sid,$mybb->get_input('candidate_after')?:'0');
                    foreach($candidates as $candidate)echo '<option value="'.self::esc($candidate['id']).'">#'.self::esc($candidate['id']).' '.self::esc($candidate['name']).'</option>';
                    echo '</select>';AF_ACS_GmPanel::sides($view['sides'],'side_id');AF_ACS_GmPanel::submit('Добавить для следующего раунда');
                    if(count($candidates)===100)echo '<a href="'.self::esc($url.'&candidate_after='.end($candidates)['id']).'">Следующие существа</a>';
                }
            } elseif($tab==='participants' && $session['status']==='active' && $encounter['status']==='preparation') {
                AF_ACS_GmPanel::form($url,'side_create',$session,$encounter);echo '<h3>Стороны</h3><label>Название стороны <input name="name" maxlength="191" required></label>';AF_ACS_GmPanel::sideAccentPicker();AF_ACS_GmPanel::submit('Добавить сторону');
                if($view['sides']) {
                    AF_ACS_GmPanel::form($url,'relation_set',$session,$encounter);echo '<label>Сторона A ';AF_ACS_GmPanel::sides($view['sides'],'side_a');echo '</label><label>Сторона B ';AF_ACS_GmPanel::sides($view['sides'],'side_b');echo '</label><select name="relationship" required><option value="">Выберите отношение</option><option value="allied">Союзники</option><option value="neutral">Нейтральные</option><option value="hostile">Враждебные</option></select>';AF_ACS_GmPanel::submit('Сохранить отношение');
                    echo '<ul>';$names=array_column($view['sides'],'name','id');foreach($view['relationships'] as $relation)echo '<li>'.self::esc($names[$relation['side_a']]??$relation['side_a']).' / '.self::esc($names[$relation['side_b']]??$relation['side_b']).' — '.self::esc(['allied'=>'Союзники','neutral'=>'Нейтральные','hostile'=>'Враждебные'][$relation['relationship']]??'Недопустимое отношение').'</li>';echo '</ul>';
                    foreach(['participant_add'=>'Добавить участника','participant_side'=>'Изменить сторону участника'] as $op=>$title) {
                        AF_ACS_GmPanel::form($url,$op,$session,$encounter);echo '<h3>'.self::esc($title).'</h3><select name="entity_id" required><option value="">Выберите существо</option>';
                        $candidates=$op==='participant_add'?$service->candidates($sid,$mybb->get_input('candidate_after')?:'0'):$view['participants'];
                        foreach($candidates as $candidate){$id=$candidate['entity_id']??$candidate['id'];echo '<option value="'.self::esc($id).'">#'.self::esc($id).' '.self::esc($candidate['name']).'</option>';}
                        echo '</select>';AF_ACS_GmPanel::sides($view['sides'],'side_id');AF_ACS_GmPanel::submit($title);
                        if($op==='participant_add' && count($candidates)===100)echo '<a href="'.self::esc($url.'&candidate_after='.end($candidates)['id']).'">Следующие существа</a>';
                    }
                }
                if(count($view['sides'])===100)echo '<a href="'.self::esc($url.'&side_after='.end($view['sides'])['id']).'">Следующие стороны</a>';
                if(count($view['relationships'])===100)echo '<a href="'.self::esc($url.'&relation_after='.end($view['relationships'])['id']).'">Следующие отношения сторон</a>';
                AF_ACS_GmPanel::form($url,'readiness',$session,$encounter);echo '<button type="submit">Проверить готовность</button></form><p>Подготовка и запуск засады доступны на вкладке «Раунды».</p>';
            }
            if($tab==='participants' && $session['status']==='active' && in_array($encounter['status'],['active','paused'],true)){
                echo '<h3>Стороны и отношения</h3><p>Изменение не объединяет группы и не восстанавливает AP, ресурсы или ход.</p>';
                foreach($view['relationships'] as $relation){AF_ACS_GmPanel::form($url,'relation_set',$session,$encounter);foreach(['side_a','side_b','relation_version'] as $field)echo '<input type="hidden" name="'.$field.'" value="'.self::esc($relation[$field]).'">';echo '<p>Стороны #'.self::esc($relation['side_a']).' / #'.self::esc($relation['side_b']).'</p><select name="relationship">';foreach(['allied'=>'Союзники','neutral'=>'Нейтральные','hostile'=>'Враждебные'] as $value=>$label)echo '<option value="'.$value.'"'.($relation['relationship']===$value?' selected':'').'>'.$label.'</option>';echo '</select>';AF_ACS_GmPanel::submit('Изменить отношение');}
                foreach($view['participants'] as $p)if($p['participation']==='engaged'){AF_ACS_GmPanel::form($url,'side_change',$session,$encounter);echo '<p>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).' · сторона #'.self::esc($p['side_id']).'</p><input type="hidden" name="entity_id" value="'.self::esc($p['entity_id']).'"><input type="hidden" name="participant_version" value="'.self::esc($p['participant_version']).'">';AF_ACS_GmPanel::sides($view['sides'],'side_id');AF_ACS_GmPanel::submit('Изменить сторону');}
                echo '<h3>Временное управление поверх базовых назначений</h3><ul>';foreach($service->controlOverlays($sid,$eid) as $overlay)echo '<li>Существо #'.self::esc($overlay['entity_id']).' · overlay #'.self::esc($overlay['effect_id']).' · UID '.self::esc($overlay['controller_uid']).' · осталось '.self::esc($overlay['remaining_duration']).' '.self::esc($overlay['unit']).'</li>';echo '</ul>';
            }
            if($tab==='participants' && $session['status']==='active' && in_array($encounter['status'],['preparation','active','paused'],true)) {
                echo '<h3>Управление NPC / монстрами</h3><p>Измените полный список UID через запятую, чтобы назначить или снять управляющих. Пустой список снимает всех; gm_pool использует текущих мастеров этой сессии. Ресурсы и ход сохраняются.</p><p>Мастера сессии: '.self::esc(implode(', ',array_column($view['controllers'],'uid'))).'</p>';
                foreach($view['participants'] as $p) {
                    if($p['owner_uid']!==null || !in_array($p['kind'],['npc','monster'],true))continue;
                    AF_ACS_GmPanel::form($url,'controller_set',$session,$encounter);
                    echo '<h4>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).'</h4><input type="hidden" name="entity_id" value="'.self::esc($p['entity_id']).'"><input type="hidden" name="assignment_version" value="'.self::esc($p['assignment_version']).'"><label>Режим <select name="mode">';
                    foreach(['exclusive','shared','gm_pool'] as $mode)echo '<option value="'.$mode.'"'.($p['controller_mode']===$mode?' selected':'').'>'.self::esc(AF_ACS_GmPanel::state($mode)).'</option>';
                    echo '</select></label><label>UID управляющих <input name="uids" value="'.self::esc(implode(',',$p['controller_uids'])).'" maxlength="1099"></label><p>Exclusive: один UID; shared: несколько UID; gm_pool: оставьте UID пустым.</p>';AF_ACS_GmPanel::submit('Сохранить управление');
                }
            }
            if($tab==='participants' && $session['status']==='active' && in_array($encounter['status'],['preparation','active','paused'],true)){
                echo '<h3>Доступность целей конструктора</h3><p>Скрытые цели остаются доступны только GM. Разрешение цели участникам не открывает полный snapshot персонажа.</p>';
                foreach($view['participants'] as $p){$visibility=json_decode($p['visibility_json'],true);AF_ACS_GmPanel::form($url,'participant_visibility',$session,$encounter);
                    echo '<p>#'.self::esc($p['entity_id']).' '.self::esc($p['name']).'</p><input type="hidden" name="entity_id" value="'.self::esc($p['entity_id']).'"><input type="hidden" name="participant_version" value="'.self::esc($p['participant_version']).'"><select name="visibility_scope">';
                    foreach(['gm'=>'Только GM','participants'=>'Участники сессии'] as $scope=>$label)echo '<option value="'.$scope.'"'.(($visibility['scope']??'gm')===$scope?' selected':'').'>'.$label.'</option>';echo '</select>';AF_ACS_GmPanel::submit('Сохранить доступность цели');
                }
            }
            if($tab==='participants'){require_once __DIR__.'/Entities.php';AF_ACS_EntitiesPage::render($sid,af_advancedcombatsystem_sessions()->view($sid),$url,null,true,$sourceCheck);require_once __DIR__.'/Delegations.php';AF_ACS_DelegationsPage::render($sid,$url,$service->delegations(),true);}
            if($tab==='management'&&in_array($encounter['status'],['active','paused'],true)){require_once __DIR__.'/Spatial.php';AF_ACS_SpatialPage::render($view,$url,$service->spatial());require_once __DIR__.'/Cover.php';AF_ACS_CoverPage::render($view,$url,$service->cover());}
            if($tab==='management') {
                AF_ACS_GmPanel::settings($view,$url);AF_ACS_GmPanel::ending($service,$view,$url);$sessionView=af_advancedcombatsystem_sessions()->view($sid,true);AF_ACS_GmPanel::obligations($sessionView);AF_ACS_GmPanel::sessionControls($sessionView,$url);
                AF_ACS_GmPanel::recovery($sid,$url,$sessionView['can_audit']);
                require_once __DIR__.'/Clock.php';AF_ACS_ClockPage::rest($view,$url,$service->clock());
            }
            echo '</section>';return ob_get_clean();
        } catch(Throwable $error) {
            ob_end_clean();
            if (!empty($outcome['committed'])) {
                return '<p role="status">Команда подтверждена. <a href="'.self::esc($base).'">Перечитать состояние</a>.</p>';
            }
            throw $error;
        }
    }
    public static function roleAssignmentFailure(Throwable $error,string $phase): array
    {
        $code=$error->getMessage();
        $message=match($code) {
            'ACS_SESSION_NOT_FOUND'=>'Сессия не найдена.',
            'ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_LOGIN_REQUIRED'=>'Недостаточно прав мастера для назначения роли в этой сессии.',
            'ACS_USER_UNAVAILABLE'=>'Выбранный пользователь не найден.',
            'ACS_THREAD_UNAVAILABLE'=>$phase==='role_topic_access'
                ? 'Пользователь не имеет доступа к теме сессии.' : 'Тема сессии недоступна.',
            'ACS_VERSION_CONFLICT'=>'Версия сессии изменилась. Обновите панель перед назначением роли.',
            'ACS_ROLE_INVALID','ACS_ROLE_ESCALATION_DENIED','ACS_OWNER_PROTECTED'=>'Роль недоступна для назначения.',
            'ACS_ROLE_AMBIGUOUS'=>'У пользователя несколько записей ролей. Нужна проверка администратором.',
            'ACS_UI_CONFIRMATION_REQUIRED'=>'Подтвердите изменение роли.',
            'ACS_REASON_REQUIRED'=>'Укажите причину изменения роли.',
            'ACS_CSRF_REQUIRED'=>'Проверка защиты запроса не прошла. Обновите панель.',
            'ACS_ENCOUNTER_UNAVAILABLE','ACS_MODAL_REQUEST_INVALID','ACS_OPERATION_KEY_REQUIRED',
            'ACS_INVALID_INTEGER','ACS_INTEGER_RANGE'=>'Форма назначения роли недействительна. Откройте её заново.',
            'ACS_SESSION_TRANSITION_BLOCKED'=>'Роль можно назначить только в активной сессии.',
            'ACS_RATE_LIMIT','ACS_MAINTENANCE_BUSY'=>'Команда временно недоступна. Повторите позже.',
            'ACS_PUBLICATION_UNRESOLVED'=>'Сначала завершите восстановление публикации в этой сессии.',
            'ACS_OPERATION_KEY_CONFLICT'=>'Ключ уже относится к другому запросу. Проверьте прежнюю операцию.',
            default=>null,
        };
        $failure=af_advancedcombatsystem_session_failure($error,$phase,'session.set_role');
        $uncertain=$message===null && ($failure['category']==='RECOVERY_REQUIRED'
            || in_array($code,['ACS_LOCK_RELEASE_UNKNOWN','ACS_DB_SESSION_RESTORE_FAILED','ACS_TRANSACTION_ERROR','ACS_DB_SESSION_SETTINGS_FAILED'],true)
            || $phase==='role_commit' || $phase==='role_modal');
        return ['error_id'=>$failure['error_id'],'uncertain'=>$uncertain,
            'message'=>($message??($uncertain
                ? 'Результат назначения роли неизвестен. Проверьте прежнюю операцию и используйте тот же ключ.'
                : 'Назначение роли прервано. Обратитесь к администратору для проверки диагностики.'))
                .' Код обращения: '.$failure['error_id']];
    }

    private static function assignmentError(string $code,?string $phase): ?string
    {
        return match($code) {
            'ACS_CHARACTER_OWNER_ROLE_REQUIRED'=>'Владелец персонажа не имеет игровой роли в сессии.',
            'ACS_VERSION_CONFLICT'=>match($phase) {
                'participant_session'=>'Версия сессии изменилась. Обновите панель.',
                'participant_encounter'=>'Версия столкновения изменилась. Обновите состав.',
                default=>'Состояние изменилось. Обновите панель.',
            },
            'ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE'=>'Для добавления нужны действующие права мастера и доступ к сессии.',
            'ACS_THREAD_UNAVAILABLE'=>$phase==='participant_controller'?'Управляющий не имеет доступа к теме сессии.':'Тема сессии недоступна.',
            'ACS_USER_UNAVAILABLE'=>'Профиль управляющего недоступен.',
            'ACS_CONTROL_INVALID'=>'Назначение управляющего или группа существа не прошли проверку.',
            'ACS_SNAPSHOT_INVALID','ACS_SNAPSHOT_HASH_MISMATCH'=>'Сохранённый боевой снимок существа не прошёл проверку целостности.',
            'ACS_ENTITY_UNAVAILABLE','ACS_PARTICIPANT_UNAVAILABLE'=>'Существо недоступно или уже занято другим столкновением.',
            'ACS_PARTICIPANT_EXISTS'=>'Существо уже находится в составе.',
            'ACS_SIDE_UNAVAILABLE'=>'Выбранная сторона недоступна.',
            'ACS_EPISODE_NORMAL','ACS_ENCOUNTER_UNAVAILABLE','ACS_ENCOUNTER_TRANSITION_BLOCKED','ACS_SESSION_TRANSITION_BLOCKED'=>'Добавление недоступно в текущем состоянии сессии или столкновения.',
            'ACS_DEPENDENCY_UNAVAILABLE'=>'Обязательный источник боевых данных недоступен.',
            'ACS_HP_RANGE'=>'Значения HP сохранённого существа вне допустимого диапазона.',
            default=>null,
        };
    }

    private static function state(string $state): string {return ['preparation'=>'Подготовка','active'=>'Активно','ambush_ready'=>'Засада подготовлена','paused'=>'Пауза','completed'=>'Завершено','pending_resolution'=>'Ожидание исхода','encounter_start'=>'Запуск','ambush_resolution'=>'Разрешение засады'][$state]??'Состояние недоступно';}
    private static function event(string $event): string {return ['encounter.pause'=>'Пауза','encounter.resume'=>'Возобновление','encounter.gm_skip'=>'Ручной пропуск','encounter.gm_forfeit'=>'Закрытие выбывшей группы','encounter.deadline_set'=>'Настройка дедлайнов','encounter.extend_deadline'=>'Продление дедлайна','encounter.deadline_process'=>'Обработка дедлайна','encounter.round_next'=>'Следующий раунд','encounter.round_start'=>'Запущен первый раунд','encounter.create'=>'Создано столкновение','encounter.side_create'=>'Добавлена сторона','encounter.side_rename'=>'Сторона переименована','encounter.side_accent'=>'Изменено оформление стороны','encounter.side_delete'=>'Сторона удалена','encounter.relation_set'=>'Изменены отношения сторон','encounter.participant_add'=>'Добавлен участник','encounter.participant_remove'=>'Участник убран из состава','encounter.participant_side'=>'Изменена сторона участника','encounter.side_change'=>'Изменена текущая сторона','encounter.participant_visibility'=>'Изменена доступность цели','encounter.controller_set'=>'Изменён режим / список управляющих','encounter.controller_assign'=>'Назначен управляющий'][$event]??'Запись журнала';}
    public static function issue(string $code): string {return ['ACS_CHARACTER_OWNER_ROLE_REQUIRED'=>'Владелец персонажа не имеет игровой роли в сессии.','cooldown_source_unavailable'=>'Кулдаун не имеет подтверждённого источника/версии.','round_boundary_contract_unavailable'=>'Активные слои или настройки AP требуют ещё не подключённого обработчика границ.','participants_missing'=>'Добавьте хотя бы одного участника.','side_relationship_missing'=>'Явно задайте отношения используемых сторон.','ACS_CONTROL_INVALID'=>'Проверьте владельца, управляющего и группу существа.','ACS_SIDE_UNAVAILABLE'=>'Участнику нужна допустимая сторона.','ACS_ENTITY_UNAVAILABLE'=>'Существо недоступно либо занято другим столкновением.','ACS_SNAPSHOT_HASH_MISMATCH'=>'Нарушена целостность сохранённого снимка.','ACS_SNAPSHOT_INVALID'=>'Сохранённый снимок несовместим или неполон.','ACS_DEPENDENCY_UNAVAILABLE'=>'Обязательная зависимость отключена.','preparation_required'=>'Нужны активная сессия и состояние подготовки.','config_snapshot_invalid'=>'Снимок конфигурации не прошёл проверку.'][$code]??'Участник не прошёл серверную проверку.';}
}
