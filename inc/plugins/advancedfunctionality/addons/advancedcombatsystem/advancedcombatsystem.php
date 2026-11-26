<?php

if (!defined('IN_MYBB') || !defined('AF_ADDONS')) {
    die('No direct access');
}

require_once __DIR__.'/modules/Bootstrap/Context.php';

/**
 * AF invokes this on enabled requests and ignores the return value.
 * Register only the requested session page or lightweight ATF provider.
 * Ordinary pages never load the session command stack or integrations.
 */
function af_advancedcombatsystem_init(): string
{
    global $mybb,$plugins;
    static $registered=false;
    if(!$registered && af_is_addon_enabled('advancedcombatsystem') && isset($plugins)) {
        $registered=true;
        require_once __DIR__.'/modules/Bootstrap/PostLifecycleHooks.php';
        AF_ACS_PostLifecycleHooks::register($plugins);
        if (defined('THIS_SCRIPT') && in_array(THIS_SCRIPT,['newreply.php','showthread.php'],true)) {
            require_once __DIR__.'/modules/Bootstrap/EditorPublicationEntry.php';
            if (THIS_SCRIPT==='newreply.php') { $plugins->add_hook('global_end','af_advancedcombatsystem_reply_entry',PHP_INT_MAX); }
            $plugins->add_hook('pre_output_page','af_advancedcombatsystem_reply_decorate',20);
        }
        if (defined('THIS_SCRIPT') && in_array(THIS_SCRIPT, ['newthread.php', 'editpost.php', 'showthread.php'], true)) {
            require_once __DIR__.'/modules/Presentation/AtfThreadCreation.php';
        }
        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='showthread.php' && !empty($mybb->user['uid'])) {
            require_once __DIR__.'/modules/Presentation/ThreadComponents.php';
            $plugins->add_hook('global_end','af_advancedcombatsystem_register_thread_component');
        }
        if (defined('THIS_SCRIPT') && THIS_SCRIPT==='misc.php' && $mybb->get_input('action')==='acs_modal') {
            require_once __DIR__.'/modules/Presentation/ModalWorkflow.php';
            $plugins->add_hook('misc_start', 'af_advancedcombatsystem_modal_dispatch');
        }
        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='misc.php' && $mybb->get_input('action')==='acs_loot')$plugins->add_hook('misc_start','af_advancedcombatsystem_loot_page');
        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='misc.php' && $mybb->get_input('action')==='acs_archive')$plugins->add_hook('misc_start','af_advancedcombatsystem_archive_page');
        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='misc.php' && $mybb->get_input('action')==='acs_recovery')$plugins->add_hook('misc_start','af_advancedcombatsystem_recovery_page');
        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='misc.php' && $mybb->get_input('action')==='acs_sessions')$plugins->add_hook('misc_start','af_advancedcombatsystem_sessions_page');
    }
    return AF_ACS_Context::current();
}

/** Administrative paths only: ordinary bootstrap never loads the SQL stack. */
function af_advancedcombatsystem_migrations(): AF_ACS_Migrations
{
    global $db;
    require_once __DIR__.'/modules/Administration/Migrations.php';
    return new AF_ACS_Migrations($db);
}

function af_advancedcombatsystem_is_installed(): bool
{
    try {
        $state = af_advancedcombatsystem_migrations()->status();
        return $state['status'] === 'READY';
    } catch (Throwable $error) { return false; }
}

/** Same AF/MyBB ACP authorization boundary and CSRF token; no public endpoint. */
function af_advancedcombatsystem_lifecycle(string $operation, callable $command): bool
{
    global $mybb;
    if (!defined('IN_ADMINCP') || !IN_ADMINCP || $mybb->request_method !== 'post') {
        throw new RuntimeException('ACS_ACP_POST_REQUIRED');
    }
    require_once __DIR__.'/modules/Application/AuthorizationService.php';
    (new AF_ACS_AuthorizationService)->requirePermission('admin.lifecycle');
    verify_post_check($mybb->get_input('my_post_key'));
    try {
        // Turning ACS off must remain possible even with broken dependencies.
        if ($operation !== 'deactivate' && (PHP_VERSION_ID < 80400 || PHP_INT_SIZE !== 8 || !extension_loaded('mysqli') || !extension_loaded('mbstring')
            || (int)$mybb->version_code < 1840 || (int)$mybb->version_code >= 1900)) { throw new RuntimeException('ACS_ENVIRONMENT_INCOMPATIBLE'); }
        $command();
        af_clear_addon_lifecycle_diagnostic('advancedcombatsystem');
        return true;
    } catch (Throwable $error) {
        $safe = preg_match('/\AACS_[A-Z_]+(?: \[error_id=[a-f0-9]{32}\])?\z/D', $error->getMessage()) ? $error->getMessage() : 'ACS_DATABASE_ERROR';
        $failure = new RuntimeException($safe);
        af_store_addon_lifecycle_diagnostic('advancedcombatsystem', $operation, 'acs_lifecycle', $failure);
        throw $failure;
    }
}

function af_advancedcombatsystem_install(): bool
{
    return af_advancedcombatsystem_lifecycle('install', static function () {
        af_advancedcombatsystem_migrations()->run();
        af_advancedcombatsystem_session_settings();
        require_once __DIR__.'/modules/Administration/NotificationTask.php';
        af_advancedcombatsystem_install_notification_task();
    });
}

function af_advancedcombatsystem_activate(): bool
{
    return af_advancedcombatsystem_lifecycle('activate', static function () {
        af_advancedcombatsystem_migrations()->activate();
        unset($GLOBALS['af_acs_deactivated']);
        // Passive lifecycle observers only; combat publication remains disabled. AF owns enabled.
    });
}

function af_advancedcombatsystem_deactivate(): bool
{
    return af_advancedcombatsystem_lifecycle('deactivate', static function () {
        $GLOBALS['af_acs_deactivated'] = true;
        // Preserve every row, template and setting. AF persists enabled=0.
    });
}

function af_advancedcombatsystem_upgrade(): bool
{
    return af_advancedcombatsystem_lifecycle('upgrade', static function () {
        af_advancedcombatsystem_migrations()->run();
        af_advancedcombatsystem_session_settings();
        require_once __DIR__.'/modules/Administration/NotificationTask.php';
        af_advancedcombatsystem_install_notification_task();
    });
}

function af_advancedcombatsystem_uninstall(string $confirmation = ''): bool
{
    return af_advancedcombatsystem_lifecycle('uninstall', static function () use ($confirmation) {
        af_advancedcombatsystem_migrations()->uninstall($confirmation);
    });
}

function af_advancedcombatsystem_delegations(): AF_ACS_DelegationService
{
    global $db;
    require_once __DIR__.'/modules/Application/DelegationService.php';
    return new AF_ACS_DelegationService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess);
}

function af_advancedcombatsystem_sessions(): AF_ACS_SessionService
{
    global $db;
    if(!af_is_addon_enabled('advancedcombatsystem') || !empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
    require_once __DIR__.'/modules/Application/SessionService.php';
    return new AF_ACS_SessionService(new AF_ACS_SessionRepository($db),new AF_ACS_SessionAccess);
}
/** Explicit service composition. A future pet plugin may supply its typed owner adapter. */
function af_advancedcombatsystem_entities(?AF_ACS_PermanentPetSource $pets=null): AF_ACS_EntityService
{
    global $db;
    if(!af_is_addon_enabled('advancedcombatsystem') || !empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
    require_once __DIR__.'/modules/Application/EntityService.php';
    return new AF_ACS_EntityService(new AF_ACS_EntityRepository($db),new AF_ACS_SessionAccess,new AF_ACS_RegistrationSource($pets));
}

/** Explicit GM command/view surface; never invoked on ordinary thread views. */
function af_advancedcombatsystem_encounters(): AF_ACS_EncounterService
{
    global $db;
    if(!af_is_addon_enabled('advancedcombatsystem') || !empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
    require_once __DIR__.'/modules/Application/EncounterService.php';
    return new AF_ACS_EncounterService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess);
}

function af_advancedcombatsystem_sessions_page(): void
{
    require_once __DIR__.'/modules/Presentation/Sessions.php';
    require_once __DIR__.'/modules/Presentation/StandalonePage.php';
    add_breadcrumb('Сессии ACS','misc.php?action=acs_sessions');
    $assets='';
    ob_start();
    try { AF_ACS_SessionsPage::render(false,$assets); }
    catch (Throwable $error) { echo '<p role="alert">Сессии ACS недоступны.</p>'; }
    AF_ACS_StandalonePage::output('Сессии ACS',ob_get_clean(),$assets);
}
function af_advancedcombatsystem_session_settings(): void
{
    global $db;
    $group = 'af_acs_sessions';
    af_ensure_settinggroup($group, 'ACS: Сессии', 'Права создания сессий и расширенного экспорта.');
    $definitions = [
        'af_acs_session_gm_groups' => ['Группы мастеров ACS', 'Пусто: создание доступно подтверждённым администраторам.', 'text', '', 1],
        'af_acs_default_member_role' => ['Роль новых участников', 'Применяется только к новым сессиям.', "select\nobserver=Наблюдатель\nplayer=Игрок", 'observer', 2],
        'af_acs_archive_cursor_key' => ['Ключ курсоров архива', 'Серверный секрет. Смена инвалидирует прежние курсоры.', 'text', null, 3],
        'af_acs_archive_export_groups' => ['Расширенный экспорт ACS', 'Требуются права session GM и native доступ. Пусто — запрещено.', 'text', '', 4],
    ];
    foreach ($definitions as $name => [$title, $description, $type, $default, $order]) {
        $found = $db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit'=>1]);
        if ($db->fetch_field($found, 'sid')) { continue; }
        af_ensure_setting($group, $name, $title, $description, $type, $default ?? bin2hex(random_bytes(32)), $order);
    }
    af_rebuild_and_reload_settings();
}

/** Existing AF router; safe errors, native session access, no mutation on GET. */
function af_advancedcombatsystem_xmlhttp(): void
{
    require_once __DIR__.'/modules/Presentation/SafeError.php';
    if(($GLOBALS['mybb']->get_input('op')??'')!=='archive_export')ob_start([AF_ACS_SafeError::class,'normalize']);
    if($GLOBALS['mybb']->get_input('op')==='versions'){require_once __DIR__.'/modules/Presentation/VersionController.php';AF_ACS_VersionController::dispatch();}
    global $mybb;
    if(in_array($mybb->get_input('op'),['alerts_status','alerts_retry'],true)){require_once __DIR__.'/modules/Presentation/NotificationController.php';AF_ACS_NotificationController::dispatch();}
    if(in_array($mybb->get_input('op'),['statistics_sync','statistics_profile','statistics_status','statistics_recover'],true)){require_once __DIR__.'/modules/Presentation/StatisticsController.php';AF_ACS_StatisticsController::dispatch();}
    if(in_array($mybb->get_input('op'),['archive_events','archive_policy','archive_export','archive_roster','archive_manifest'],true)){require_once __DIR__.'/modules/Presentation/ArchiveController.php';AF_ACS_ArchiveController::dispatch();}
    if(in_array($mybb->get_input('op'),['achievement_status','achievement_profile','achievement_configure','achievement_evaluate','achievement_migrate','achievement_review','correction_event','correction_status'],true)){require_once __DIR__.'/modules/Presentation/AchievementController.php';AF_ACS_AchievementController::dispatch();}
    if(in_array($mybb->get_input('op'),['reward_status','reward_configure','reward_distribute','reward_issue','reward_recover'],true)){require_once __DIR__.'/modules/Presentation/RewardController.php';AF_ACS_RewardController::dispatch();}
    if(in_array($mybb->get_input('op'),['loot_issue','loot_issue_status','loot_issue_recover'],true)){require_once __DIR__.'/modules/Presentation/LootIssueController.php';AF_ACS_LootIssueController::dispatch();}
    if(in_array($mybb->get_input('op'),['loot_allocation_view','loot_gm_assign','loot_free_claim','loot_claim','loot_allocation_close'],true)){require_once __DIR__.'/modules/Presentation/LootAllocationController.php';AF_ACS_LootAllocationController::dispatch();}
    if(in_array($mybb->get_input('op'),['loot_status','loot_configure','loot_generate'],true)){require_once __DIR__.'/modules/Presentation/LootController.php';AF_ACS_LootController::dispatch();}
    if(in_array($mybb->get_input('op'),['outcome_status','end_conditions_set','outcome_confirm','outcome_continue','participation_set','release','rejoin_encounter'],true)){require_once __DIR__.'/modules/Presentation/EndConditionController.php';AF_ACS_EndConditionController::dispatch();}
    if(in_array($mybb->get_input('op'),['ambush_status','ambush_ready','ambush_start'],true)){require_once __DIR__.'/modules/Presentation/AmbushController.php';AF_ACS_AmbushController::dispatch();}
    if(in_array($mybb->get_input('op'),['builder_context','builder_preview','builder_validate'],true)){require_once __DIR__.'/modules/Presentation/BuilderController.php';AF_ACS_BuilderController::dispatchBuilder();}
    if(in_array($mybb->get_input('op'),['prepared_release','prepared_cancel','prepared_publish','draft_save','draft_restore','seal','seal_cancel_tail','seal_confirm','seal_prepare_reactions','seal_status'],true)){require_once __DIR__.'/modules/Presentation/ApiController.php';AF_ACS_ApiController::dispatch();}
    if($mybb->get_input('op')==='combat_preview'){require_once __DIR__.'/modules/Presentation/BuilderController.php';AF_ACS_BuilderController::dispatch();}
    try {
        if(empty($mybb->user['uid']))throw new RuntimeException('ACS_LOGIN_REQUIRED');
        if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');
        $service=af_advancedcombatsystem_sessions();$op=$mybb->get_input('op');
        if($op==='list')$data=$service->list($mybb->get_input('after')?:'0');
        elseif($op==='get')$data=$service->view($mybb->get_input('session_id'),true);
        elseif(str_starts_with($op,'round_manage_')) {
            $rounds=af_advancedcombatsystem_encounters()->rounds();$sid=$mybb->get_input('session_id');$eid=$mybb->get_input('encounter_id');
            $input=[];foreach(['session_id','encounter_id','expected_version','encounter_version','turn_id','turn_version','round_id','operation_key','reason','confirm','deadline_mode','seconds'] as $field)$input[$field]=$mybb->get_input($field);
            $data=$op==='round_manage_overview'?$rounds->overview($sid,$eid,$mybb->get_input('after')?:'0'):$rounds->command(substr($op,13),$input);
        }
        elseif(str_starts_with($op,'reaction_')) {
            if($op!=='reaction_view'&&array_diff(array_keys($_POST),['action','addon','op','my_post_key','session_id','encounter_id','entity_id','expected_version','encounter_version','operation_key','reason','trigger_key','reaction_id','reaction_version','targets']))throw new RuntimeException('ACS_REACTION_INPUT_INVALID');
            $reactions=af_advancedcombatsystem_encounters()->reactions();$input=[];
            foreach(['session_id','encounter_id','entity_id','expected_version','encounter_version','operation_key','reason'] as $field)$input[$field]=$mybb->get_input($field);
            if($op==='reaction_view')$data=$reactions->view($input['session_id'],$input['encounter_id'],$input['entity_id']);
            else{if($op==='reaction_register'){$input['trigger_key']=$mybb->get_input('trigger_key');$raw=$mybb->get_input('targets');if($raw!=='')$input['targets']=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}else foreach(['reaction_id','reaction_version'] as $field)$input[$field]=$mybb->get_input($field);$data=$reactions->command(substr($op,9),$input);}
        }
        elseif(str_starts_with($op,'clock_')) {
            $clock=af_advancedcombatsystem_encounters()->clock();$sid=$mybb->get_input('session_id');
            $input=[];foreach(['session_id','expected_version','encounter_id','encounter_version','operation_key','reason','round_id','entity_turn_id','control_group_id','confirm_full','effect_order'] as $f)$input[$f]=$mybb->get_input($f);
            $data=match($op) {
                'clock_sustained'=>$clock->sustained($sid,$mybb->get_input('encounter_id')),
                'clock_overview'=>$clock->overview($sid),
                'clock_ap_available'=>$clock->available($sid,$mybb->get_input('turn_id'),$mybb->get_input('entity_id'),(int)AF_ACS_Repository::integer($mybb->get_input('cost')?:'0','2147483647',true)),
                default=>$clock->command(substr($op,6),$input),
            };
        }
        elseif(str_starts_with($op,'delegation_')) {
            $delegations=af_advancedcombatsystem_delegations();$sid=$mybb->get_input('session_id');
            if($op==='delegation_list')$data=$delegations->view($sid,$mybb->get_input('after')?:'0');
            else{
                $input=[];foreach(['session_id','expected_version','operation_key','reason','owner_uid','delegate_uid','entity_ids','control_group_id','permitted_actions','valid_from','valid_until','duration_seconds','delegation_id','delegation_version'] as $field)if(array_key_exists($field,$_POST))$input[$field]=in_array($field,['entity_ids','permitted_actions'],true)?$_POST[$field]:$mybb->get_input($field);
                $data=$delegations->command(substr($op,11),$input);
            }
        }
        elseif(str_starts_with($op,'encounter_')) {
            $encounters=af_advancedcombatsystem_encounters();$sid=$mybb->get_input('session_id');$eid=$mybb->get_input('encounter_id');
            $input=[];foreach(['session_id','expected_version','encounter_id','encounter_version','operation_key','reason','name','side_a','side_b','relationship','entity_id','side_id','uid','tid','mode','uids','assignment_version','visibility_scope','participant_version'] as $field)$input[$field]=$mybb->get_input($field);
            $data=match($op) {
                'encounter_list'=>$encounters->list($sid,$mybb->get_input('after')?:'0'),
                'encounter_get'=>$encounters->view($sid,$eid),
                'encounter_round_get'=>$encounters->roundView($sid,$eid,$mybb->get_input('after')?:'0'),
                'encounter_events'=>$encounters->events($sid,$eid,$mybb->get_input('after')?:'0'),
                'encounter_readiness'=>$encounters->readiness($sid,$eid),
                default=>$encounters->command(substr($op,10),$input),
            };
        }
        elseif(in_array($op,['inventory_operation_lookup','inventory_operation_recover'],true)) {
            require_once __DIR__.'/modules/Application/RecoveryService.php';$recovery=new AF_ACS_RecoveryService($db,new AF_ACS_SessionAccess);
            $data=$op==='inventory_operation_lookup'?$recovery->inspectInventory($mybb->get_input('session_id'),$mybb->get_input('operation_key')):$recovery->recoverInventory($mybb->get_input('session_id'),$mybb->get_input('operation_key'),$mybb->get_input('expected_operation_version'));
        }
        elseif(str_starts_with($op,'custody_')) {
            require_once __DIR__.'/modules/Application/CustodyService.php';
            $custody=new AF_ACS_CustodyService($db,new AF_ACS_SessionAccess);$sid=$mybb->get_input('session_id');
            if($op==='custody_view')$data=$custody->view($sid);
            elseif($op==='custody_lookup')$data=$custody->lookup($sid,$mybb->get_input('operation_key'));
            else {
                $input=[];foreach(['session_id','expected_version','expected_custody_version','operation_key','reason','inventory_item_id','owner_entity_id','custodian_entity_id'] as $field)$input[$field]=$mybb->get_input($field);
                $data=$custody->command(substr($op,8),$input);
            }
        }
        elseif($op==='item_equip') {
            require_once __DIR__.'/modules/Application/ItemActionService.php';
            $input=[];foreach(['session_id','entity_id','ability_key','expected_version','operation_key'] as $field)$input[$field]=$mybb->get_input($field);
            $data=(new AF_ACS_ItemActionService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess))->equipFree($input);
        }
        elseif(in_array($op,['entity_list','entity_snapshot','entity_register','entity_validate'],true)) {
            $entities=af_advancedcombatsystem_entities();$sid=$mybb->get_input('session_id');
            $input=[];foreach(['session_id','expected_version','operation_key','reason','kind','source_key','name','initial_hp','preparation_fingerprint'] as $field)$input[$field]=$mybb->get_input($field);
            $data=match($op) {
                'entity_list'=>$entities->list($sid,$mybb->get_input('after')?:'0'),
                'entity_snapshot'=>$entities->snapshot($sid,$mybb->get_input('entity_id')),
                'entity_validate'=>$entities->validate($sid,$input),
                'entity_register'=>$entities->register($input),
            };
        }
        else {
            $input=[];foreach(['operation_key','reason','tid','owner_uid','session_id','expected_version','uid','role'] as $field)$input[$field]=$mybb->get_input($field);
            $data=$service->command($op,$input);
        }
        $version=$data['session_version']??$data['session']['session_version']??null;
        $versions=$version===null?[]:['session_version'=>$version];
        $encounterVersion=$data['encounter_version']??$data['encounter']['encounter_version']??null;
        if($encounterVersion!==null)$versions['encounter_version']=$encounterVersion;
        $response=['ok'=>true,'request_id'=>bin2hex(random_bytes(16)),'data'=>$data,'versions'=>$versions?:new stdClass,'warnings'=>[]];
    } catch(Throwable $error) {
        $failure=af_advancedcombatsystem_session_failure($error);
        http_response_code(match($failure['category']) {'PERMISSION_DENIED'=>403,'STATE_CONFLICT','RECOVERY_REQUIRED'=>409,'VALIDATION_ERROR'=>422,default=>503});
        $response=['ok'=>false,'request_id'=>$failure['request_id'],'error'=>$failure];
    }
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    echo json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
}
function af_acs_registration_diagnostic_groups(array $issues): array
{
    $messages=[
        'source_required'=>'Выберите источник для проверки.',
        'invalid_integer'=>'Некорректный идентификатор источника или начальное HP.',
        'integer_range'=>'Идентификатор источника или начальное HP вне допустимого диапазона.',
        'invalid_text'=>'Проверьте длину имени экземпляра.',
        'json_too_large'=>'Данные источника превышают допустимый размер снимка.',
        'inv_source_changed'=>'Инвентарь изменился во время проверки. Повторите проверку.',
        'inv_version_conflict'=>'Версия инвентаря изменилась. Повторите проверку.',
        'inv_incomplete_snapshot'=>'Не удалось получить полный состав инвентаря. Повторите проверку.',
        'access_denied'=>'Недоступен по правам. Проверьте доступ к теме анкеты и Knowledge Base.',
        'definition_access_denied'=>'Недоступен по правам. Нет доступа к определению KB.',
        'registration_ownership_required'=>'Регистрация разрешена только владельцу листа.',
        'sheet_lookup_invalid'=>'Введите точный slug из ссылки на лист, например thread-52.',
        'sheet_lookup_unavailable'=>'Среди ваших листов нет указанного slug. Проверьте, создан ли лист для этой темы и под каким аккаунтом.',
        'gm_sheet_lookup_unavailable'=>'Лист с таким идентификатором отсутствует или недоступен. Чтобы проверить тему отдельно, используйте tid:номер.',
        'character_source_unavailable'=>'Лист отсутствует или недоступен для регистрации.',
        'character_topic_unavailable'=>'Лист или исходная тема недоступны для регистрации.',
        'character_accept_link_invalid'=>'Принятая анкета, её владелец, slug или связанная запись KB расходятся. Проверьте привязку CharacterWorkflow.',
        'character_definition_unavailable'=>'Связанная запись KB отсутствует, отключена или недоступна.',
        'sheet_lookup_other_topic'=>'Среди ваших листов нет указанного slug. Лист этого аккаунта привязан к другой теме. Проверьте связь анкеты в CharacterSheets.',
        'sheet_topic_missing'=>'Тема-источник отсутствует. Проверьте привязку листа в CharacterSheets.',
        'character_rules_invalid'=>'Правила связанной записи KB некорректны. Проверьте JSON определения.',
        'sheet_topic_mismatch'=>'Slug листа и TID анкеты расходятся. Проверьте привязку листа в CharacterSheets.',
        'explicit_character_link_missing'=>'У темы нет явной связи с персонажем KB. Проверьте принятие анкеты и синхронизацию в CharacterWorkflow.',
        'character_definition_missing'=>'Связанная запись персонажа отсутствует или отключена. Проверьте связь с KB.',
        'character_owner_link_mismatch'=>'Владелец или тема в записи KB не совпадают с листом. Проверьте привязку в CharacterWorkflow.',
        'dependency_unavailable'=>'Проверьте состояние необходимых аддонов и их таблиц.',
        'arpg_contract_required'=>'Связанный персонаж KB должен использовать контракт ARPG.',
        'initial_hp_required'=>'Укажите начальное HP в форме регистрации.',
        'hp_range'=>'Проверьте максимальное HP источника и начальное HP в форме.',
        'hp_integer_required'=>'Начальное и максимальное HP должны быть целыми числами.',
        'definition_version_conflict'=>'Сверьте закреплённые версии способностей с определениями в KB.',
        'definition_limit'=>'Превышен предел связанных определений способностей.',
        'equipment_access_denied'=>'Недоступен по правам. Инвентарь может читать только его владелец.',
        'equipment_source_unavailable'=>'Проверьте доступность AdvancedInventory.',
        'equipment_source_changed'=>'Экипировка изменилась во время проверки. Повторите её.',
        'equipment_binding_invalid'=>'Проверьте предметы и их экипированные слоты в AdvancedInventory.',
        'equipment_definition_unavailable'=>'Проверьте определения экипированных предметов в KB.',
        'equipment_provenance_blocked'=>'Не подтверждено включение экипированного предмета в характеристики листа.',
        'element_resistance_value_invalid'=>'Проверьте числовое значение сопротивления в правилах KB.',
        'element_resistance_scope_invalid'=>'Для стихийного сопротивления укажите scope=elemental.',
        'element_resistance_unit_invalid'=>'Для стихийного сопротивления укажите unit=RES.',
        'element_resistance_alias_collision'=>'Уберите дублирующие ключи одного элемента в сопротивлениях KB.',

        'permanent_pet_source_unavailable'=>'Плагин постоянных питомцев ещё не подключён.',
        'source_changed_during_read'=>'Источник изменился во время проверки. Проверьте его повторно.',
        'status_resistance_alias_conflict'=>'Конфликт значений сопротивления статусам.',
        'custom_ability_effects_missing'=>'Требует настройки для ACS. Добавьте эффект собственной способности.',
        'custom_ability_target_missing'=>'Требует настройки для ACS. Выберите цель собственной способности.',
        'duration_unit_missing'=>'Для указанного времени нужна поддерживаемая единица.',
        'unsupported_duration_unit'=>'Единица времени не поддерживается.',
        'formula_binding_missing'=>'У профиля формулы отсутствует ссылка на определение формулы.',
        'invalid_formula_contract'=>'Определение формулы не прошло проверку структуры KB.',
        'formula_version_mismatch'=>'Версия формулы расходится с версией, указанной в профиле.',
        'invalid_number'=>'Значение должно быть конечным числом.',
        'alias_conflict'=>'В сохранённых полях одного параметра указаны разные значения.',
        'reference_unresolved'=>'Ссылка ведёт на недоступное или отсутствующее определение KB.',
        'build_invalid'=>'Не удалось прочитать сохранённые настройки листа. Проверьте лист в CharacterSheets.',
        'ability_version_pin_mismatch'=>'Версия способности KB изменилась. Выберите её заново в анкете и синхронизируйте запись.',
        'equipment_provenance_incomplete'=>'Не подтверждено происхождение бонусов экипировки.',
        'bestiary_modifier_provenance_unavailable'=>'Шаблон содержит модификаторы без подтверждённого итогового расчёта.',
        'resource_semantics_unverified'=>'Для дополнительных ресурсов отсутствует подтверждённый контракт.',
    ];
    $stats=[];
    foreach (function_exists('af_kb_arpg_character_stat_registry') ? af_kb_arpg_character_stat_registry() : [] as $key=>$stat) {
        $canonical=$stat['acs_key']??$key;
        foreach ([$canonical,$stat['vm_field'],'characteristics.'.$canonical,'character_stats.'.$stat['vm_field']] as $path) {
            $stats[$path]=[$canonical,$stat['label'],$stat['vm_field']];
        }
    }
    if (isset($stats['character_status_resist'])) {
        $stats['character_status_resistance']=$stats['character_stats.character_status_resistance']=$stats['character_status_resist'];
    }
    $groups=[];
    $knownStatIssues=[];
    foreach ($issues as $issue) {
        $stat=$stats[$issue['path']]??null;
        if ($stat && in_array($issue['code'],['required_stat_missing','invalid_stat_value'],true)) {
            $knownStatIssues[$stat[0]][$issue['code']]=true;
        }
    }
    foreach ($issues as $issue) {
        $rawCode=$issue['code'];$code=$rawCode;$path=$issue['path'];$object=$path;
        $message=$messages[$code]??'Проверьте указанный источник данных.';
        $stat=$stats[$path]??null;
        if ($stat && in_array($code,['required_stat_missing','required_stat_missing_or_invalid','invalid_stat_value'],true)) {
            [$object,$label]=$stat;
            if ($code==='required_stat_missing_or_invalid') {
                if (isset($knownStatIssues[$object]['required_stat_missing'])) $code='required_stat_missing';
                elseif (isset($knownStatIssues[$object]['invalid_stat_value'])) $code='invalid_stat_value';
            }
            $label=$object==='accuracy'?'Точность (Accuracy)':$label;
            $message=match($code) {
                'required_stat_missing'=>'Не заполнена характеристика '.$label.'.',
                'invalid_stat_value'=>'Недопустимое значение характеристики '.$label.'.',
                default=>'Проверьте наличие и значение характеристики '.$label.'.',
            };
            if ($object==='accuracy') $message='Не удалось подтвердить расчёт Точности. Проверьте базовую точность выбранных определений и разрешённую корректировку персонажа в KB.';
        } elseif ($code==='required_resistance_missing') {
            $object='resistances';
        }
        if (preg_match('/\A(character_abilities\.(\d+))(?:\.|$)/',$path,$match)) {
            $ability = $issue['ability'] ?? '';
            $label = $ability !== '' ? '«'.$ability.'»' : 'Способность №'.((int)$match[2]+1);
            if (in_array($code,['invalid_number','duration_unit_missing','unsupported_duration_unit'],true)
                && preg_match('/\.(duration|cooldown)(?:_value|_unit)?$/D',$path,$time)) {
                $object = preg_replace('/_(?:value|unit)$/D','',$path);
                $code = 'invalid_ability_timing';
                $message = $time[1]==='duration' ? 'Некорректная длительность. Проверьте число и единицу времени.'
                    : 'Некорректный кулдаун. Проверьте число и единицу времени.';
            } elseif ($code==='reference_unresolved' && str_ends_with($path,'.cost_resource')) {
                $message = 'Ресурс стоимости способности не найден в KB.';
            } elseif (in_array($code,['formula_binding_missing','reference_unresolved'],true)
                && preg_match('/\.(formula_profile|formula_ref)(?:\.|$)/',$path,$formula)) {
                $message = 'Не найдена формула способности. Проверьте определение и его привязку в KB.';
                if (!empty($issue['reference'])) $object=$match[1].'.'.$formula[1].'.'.$issue['reference'];
            }
            $message=$label.' · '.$message;
        }
        $id=$code.':'.$object;
        $groups[$id]??=['code'=>$code,'path'=>$object,'message'=>$message,'paths'=>[],'technical'=>[]];
        $groups[$id]['paths'][$path]=$path;
        $groups[$id]['technical'][$rawCode.':'.$path]=['code'=>$rawCode,'path'=>$path];
    }
    foreach ($groups as &$group) {
        $group['paths']=array_values($group['paths']);
        $group['technical']=array_values($group['technical']);
        if ($group['code']==='required_resistance_missing') {
            $group['message']='Не заполнены сопротивления основным стихиям ('.count($group['paths']).' из 17). '
                .'Проверьте resistances в правилах связанной записи KB.';
        }
    }
    unset($group);
    return array_values($groups);
}

/** Source preview telemetry reuses the ACP diagnostic journal and carries no source payload. */
function af_advancedcombatsystem_source_check_error(Throwable $error,string $phase,string $operation='entity_validate'): array
{
    global $db;
    $id=bin2hex(random_bytes(16));
    $code=preg_match('/\A(?:ACS|INV)_[A-Z0-9_]{1,90}\z/D',$error->getMessage()) ? $error->getMessage() : 'UNEXPECTED_EXCEPTION';
    $component=$error instanceof AF_ADVINV_StorageException ? $error->component : null;
    try {
        require_once __DIR__.'/modules/Infrastructure/Repository.php';
        $id=(new AF_ACS_Repository($db))->recordDiagnostic($id,'INTERNAL_ERROR',$operation,$phase,$code,$component);
    } catch (Throwable $diagnosticError) {
        // The server log still carries the incident ID if ACP storage is unavailable.
    }
    error_log(sprintf('ACS %s error_id=%s phase=%s exception=%s code=%s native_code=%d location=%s:%d',
        $operation,$id,$phase,get_class($error),$code,(int)$error->getCode(),basename($error->getFile()),$error->getLine()));
    $schemaBlocked=$code==='INV_SCHEMA_NOT_READY';
    return ['ready'=>false,'status'=>'error','diagnostics'=>[],'error_id'=>$id,'inventory_schema_blocked'=>$schemaBlocked,
        'message'=>($schemaBlocked ? 'Схема AdvancedInventory не подготовлена для ACS. Обратитесь к администратору'
            : 'Не удалось проверить источник персонажа').'. Код обращения: '.$id];
}

function af_advancedcombatsystem_session_failure(Throwable $error,?string $registrationPhase=null,string $operation='entity_register'): array
{
    global $db;
    if($registrationPhase===null && $error instanceof AF_ACS_RegistrationBlocked) {
        $id=bin2hex(random_bytes(16));
        $diagnostics=AF_ACS_CapabilityValidator::safeDiagnostics($error->diagnostics);
        return ['category'=>'VALIDATION_ERROR','request_id'=>$id,'error_id'=>$id,'message'=>'Регистрация заблокирована.',
            'diagnostics'=>$diagnostics,'diagnostic_groups'=>af_acs_registration_diagnostic_groups($diagnostics)];
    }
    $category=match($error->getMessage()) {
        'ACS_CUSTODY_VERSION_CONFLICT','ACS_CUSTODY_ALREADY_HELD','ACS_CUSTODY_NOT_HELD','ACS_CUSTODY_SLOT_CONFLICT','ACS_CUSTODY_ITEM_UNAVAILABLE','ACS_CUSTODY_SOURCE_CHANGED','ACS_CUSTODY_RESERVATION_CONFLICT','ACS_CUSTODY_OBLIGATIONS_BLOCKED','ACS_CUSTODY_SESSION_UNAVAILABLE','ACS_CUSTODY_ENTITY_UNAVAILABLE','ACS_ROUND_STATE_CONFLICT','ACS_ROUND_OBLIGATIONS_BLOCKED','ACS_OBLIGATIONS_SCHEMA_UNVERIFIED','ACS_PENDING_TURNS_REMAIN','ACS_FORFEIT_EXCEPTION_UNAVAILABLE','ACS_CLOCK_BOUNDARY_STATE','ACS_CLOCK_DEPENDENCY_BLOCKED','ACS_COMBAT_RESET_REQUIRED','ACS_COOLDOWN_SOURCE_UNAVAILABLE','ACS_COOLDOWN_UNCONFIRMED','ACS_RESOURCE_STATE_CONFLICT','ACS_REST_COMBAT_ACTIVE','ACS_REST_LIMIT','ACS_REST_WINDOW_UNAVAILABLE','ACS_REST_POLICY_UNAVAILABLE','ACS_AP_STATE_INVALID','ACS_FIRST_ROUND_EXISTS','ACS_ROSTER_BLOCKED','ACS_ROSTER_INVALID','ACS_TURN_STATE_INVALID','ACS_ENCOUNTER_TRANSITION_BLOCKED','ACS_PARTICIPANT_EXISTS','ACS_SIDE_HAS_PARTICIPANTS','ACS_SIDE_NAME_DUPLICATE','ACS_ENTITY_UNAVAILABLE','ACS_CONTROL_INVALID','ACS_SNAPSHOT_INVALID','ACS_ENTITY_ALREADY_REGISTERED','ACS_DEFINITION_VERSION_CONFLICT','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_SESSION_OBLIGATIONS_BLOCKED','ACS_SESSION_TRANSITION_BLOCKED'=>'STATE_CONFLICT',
        'ACS_INVENTORY_RECOVERY_PERMISSION_DENIED','ACS_CUSTODY_PERMISSION_DENIED','ACS_AP_ACCESS_DENIED','ACS_GM_REQUIRED','ACS_ENCOUNTER_UNAVAILABLE','ACS_REGISTRATION_DENIED','ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_CREATE_DENIED','ACS_ROLE_ESCALATION_DENIED','ACS_ADMIN_PERMISSION_REQUIRED','ACS_LOGIN_REQUIRED','ACS_CSRF_REQUIRED'=>'PERMISSION_DENIED',
        'ACS_UI_CONFIRMATION_REQUIRED','ACS_CUSTODY_INPUT_INVALID','ACS_CUSTODY_STACK_UNSUPPORTED','ACS_CUSTODY_ITEM_UNSUPPORTED','ACS_CUSTODY_PINNED_EQUIPMENT_REQUIRED','ACS_CUSTODY_TEMPORARY_USE_UNSUPPORTED','ACS_DEADLINE_POLICY_INVALID','ACS_DEADLINE_DISABLED','ACS_DEADLINE_AUTOMATIC_DISABLED','ACS_DEADLINE_NOT_DUE','ACS_DEADLINE_RANGE','ACS_ROUND_OPERATION_UNAVAILABLE','ACS_ROUND_CONFIRMATION_REQUIRED','ACS_REST_CONFIRMATION_REQUIRED','ACS_REST_NO_TARGETS','ACS_CLOCK_OPERATION_UNAVAILABLE','ACS_RELATION_INVALID','ACS_SIDE_UNAVAILABLE','ACS_INVALID_JSON','ACS_JSON_TOO_LARGE','ACS_INVALID_KEY','ACS_ENTITY_KIND_INVALID','ACS_SOURCE_REQUIRED','ACS_SOURCE_CHECK_REQUIRED','ACS_HP_INTEGER_REQUIRED','ACS_HP_RANGE','ACS_DEFINITION_LIMIT','ACS_INVALID_TEXT','ACS_REASON_REQUIRED','ACS_ROLE_INVALID','ACS_INVALID_INTEGER','ACS_INTEGER_RANGE','ACS_OPERATION_KEY_REQUIRED','ACS_POST_REQUIRED','ACS_REQUEST_TOO_LARGE','ACS_RATE_LIMIT','ACS_OWNER_PROTECTED','ACS_OWNER_UNCHANGED','ACS_USER_UNAVAILABLE'=>'VALIDATION_ERROR',
        'ACS_INVENTORY_RECOVERY_REQUIRED','ACS_INVENTORY_OPERATION_INTEGRITY','ACS_COMMIT_UNKNOWN','ACS_ROLLBACK_UNKNOWN','ACS_RECOVERY_REQUIRED'=>'RECOVERY_REQUIRED',
        default=>'INTERNAL_ERROR',
    };
    $publicMessage=match($error->getMessage()) {
        'ACS_SOURCE_CHECK_REQUIRED'=>'Проверьте источник перед подтверждением регистрации.',
        'ACS_HP_INTEGER_REQUIRED'=>'Начальное и максимальное HP должны быть целыми числами.',
        'ACS_HP_RANGE'=>'Проверьте максимальное HP источника и начальное HP в форме.',
        'ACS_DEFINITION_VERSION_CONFLICT'=>'Данные источника изменились или версии определений расходятся. Повторите проверку источника.',
        'ACS_INVENTORY_RECOVERY_REQUIRED'=>'Предметная операция ожидает lookup прежнего исхода. Не создавайте новый ключ.',
        'ACS_SESSION_OBLIGATIONS_BLOCKED','ACS_ROUND_OBLIGATIONS_BLOCKED','ACS_OBLIGATIONS_SCHEMA_UNVERIFIED'=>'Операция заблокирована незавершёнными или неподтверждёнными обязательствами. Черновики и sealed данные сохранены.',
        'ACS_PENDING_TURNS_REMAIN'=>'Сначала закройте все допустимые pending ходы и их границы.',
        'ACS_FORFEIT_EXCEPTION_UNAVAILABLE'=>'Не все существа группы выбыли. Для обычного пропуска используйте gm_skip.',
        'ACS_ROUND_CONFIRMATION_REQUIRED'=>'Подтвердите опасную операцию и укажите причину.',
        'ACS_DEADLINE_AUTOMATIC_DISABLED'=>'Выбранная политика не разрешает автоматический пропуск или уведомление.',
        'ACS_REST_LIMIT'=>'Короткий отдых группы уже использован в этом интервале.',
        'ACS_REST_COMBAT_ACTIVE'=>'Отдых недоступен до завершения активного столкновения, паузы или засады.',
        'ACS_REST_WINDOW_UNAVAILABLE'=>'Для короткого отдыха нужно подтверждённое завершённое столкновение.',
        'ACS_REST_CONFIRMATION_REQUIRED'=>'Подтвердите разрешение мастера на полноценный отдых.',
        'ACS_COOLDOWN_SOURCE_UNAVAILABLE','ACS_COOLDOWN_UNCONFIRMED'=>'Кулдаун не имеет подтверждённого источника и версии. Граница не обработана.',
        'ACS_CLOCK_DEPENDENCY_BLOCKED'=>'Граница требует ещё не подключённого обработчика эффектов.',
        default=>'Сессия или команда недоступна. Проверьте права, поля и версию данных.',
    };
    if ($registrationPhase!==null) {
        $category=match($error->getMessage()) {
            'INV_SOURCE_CHANGED','INV_VERSION_CONFLICT','ACS_EQUIPMENT_SOURCE_CHANGED','ACS_EQUIPMENT_VERSION_CONFLICT',
            'ACS_EQUIPMENT_ALREADY_INITIALIZED','ACS_GROUP_AMBIGUOUS','ACS_PUBLICATION_UNRESOLVED'=>'STATE_CONFLICT',
            'INV_ACCESS_DENIED','ACS_EQUIPMENT_ACCESS_DENIED'=>'PERMISSION_DENIED',
            'ACS_CHARACTER_OWNER_ROLE_REQUIRED','ACS_CONTROL_INVALID','ACS_SNAPSHOT_HASH_MISMATCH','ACS_PARTICIPANT_UNAVAILABLE'=>'STATE_CONFLICT',
            'INV_SCHEMA_NOT_READY','ACS_DEPENDENCY_UNAVAILABLE'=>'DEPENDENCY_ERROR',
            default=>$category,
        };
        if ($error->getMessage()==='INV_SCHEMA_NOT_READY') {
            $publicMessage='Схема AdvancedInventory не подготовлена для ACS. Обратитесь к администратору.';
        }
    }
    $id=bin2hex(random_bytes(16));$requestId=$id;
    $result=['category'=>$category,'request_id'=>$requestId,'error_id'=>$id,'message'=>$publicMessage];
    if ($registrationPhase!==null && $error instanceof AF_ACS_RegistrationBlocked) {
        $diagnostics=AF_ACS_CapabilityValidator::safeDiagnostics($error->diagnostics);
        $result=['category'=>'VALIDATION_ERROR','message'=>'Регистрация заблокирована.','diagnostics'=>$diagnostics,
            'diagnostic_groups'=>af_acs_registration_diagnostic_groups($diagnostics)]+$result;
    }
    if ($registrationPhase!==null) {
        $code=preg_match('/\A(?:ACS|INV)_[A-Z0-9_]{1,90}\z/D',$error->getMessage()) ? $error->getMessage() : 'UNEXPECTED_EXCEPTION';
        $component=$error instanceof AF_ADVINV_StorageException ? $error->component : match($registrationPhase) {
            'inventory.fence','inventory.read','entity.persist'=>$registrationPhase,
            'role_assign'=>'session_roles.assign',
            'role_notification'=>'outbox.alerts_source',
            'role_audit'=>'session.audit',
            'role_commit'=>'transaction.commit',
            'role_transaction'=>'transaction.begin',
            'role_topic_access'=>'session.target_access',
            'role_form','role_modal'=>'gm.role_request',
            'role_input'=>'session.role_input',
            'role_authorize'=>'session.authorization',
            'role_guard'=>'session.version_guard',
            'role_user'=>'session.target_user',
            default=>null,
        };
        try {
            require_once __DIR__.'/modules/Infrastructure/Repository.php';
            $result['error_id']=(new AF_ACS_Repository($db))->recordDiagnostic($id,$result['category'],$operation,$registrationPhase,$code,$component);
        } catch (Throwable $diagnosticError) {
            error_log(sprintf('ACS %s error_id=%s phase=%s code=%s',$operation,$id,$registrationPhase,$code));
        }
        return $result;
    }
    try {
        if (in_array($category,['INTERNAL_ERROR','RECOVERY_REQUIRED'],true)) {
            require_once __DIR__.'/modules/Infrastructure/Repository.php';
            $result['error_id']=(new AF_ACS_Repository($db))->recordDiagnostic($id,$category,'session_command','validation');
        }
    } catch(Throwable $diagnosticError) {error_log('ACS session '.$id.' '.$category);}
    return $result;
}

/** Explicit native misc page; no recovery commands are executed from a postbit renderer. */
function af_advancedcombatsystem_recovery_page(): void
{
    global $mybb;
    require_once __DIR__.'/modules/Presentation/RecoveryPage.php';
    require_once __DIR__.'/modules/Presentation/ThreadComponents.php';
    try {$body=AF_ACS_RecoveryPage::render($mybb->get_input('session_id'));}
    catch(Throwable $error){http_response_code(403);$body='<p role="alert">Восстановление недоступно. Нужны текущие права мастера этой сессии и доступ к теме.</p>';}
    require_once __DIR__.'/modules/Presentation/StandalonePage.php';
    add_breadcrumb('Сессии ACS','misc.php?action=acs_sessions');
    add_breadcrumb('Восстановление ACS');
    AF_ACS_StandalonePage::output('Восстановление ACS',$body,af_advancedcombatsystem_presentation_css('has_acs_recovery'));
}

/** Explicit loot navigation only; normal thread requests do not load the allocation stack. */
function af_advancedcombatsystem_loot_page():void
{
    global $mybb;
    require_once __DIR__.'/modules/Presentation/LootAllocationPage.php';
    require_once __DIR__.'/modules/Presentation/ThreadComponents.php';
    try{$body=AF_ACS_LootAllocationPage::render($mybb->get_input('session_id'),$mybb->get_input('encounter_id'));}
    catch(Throwable $error){$failure=af_advancedcombatsystem_session_failure($error);http_response_code(403);$body='<p role="alert">Завершение и награды недоступны. Проверьте текущие права сессии и доступ к теме. error_id='.htmlspecialchars_uni($failure['error_id']).'</p>';}
    require_once __DIR__.'/modules/Presentation/StandalonePage.php';
    add_breadcrumb('Сессии ACS','misc.php?action=acs_sessions');
    add_breadcrumb('Добыча и награды ACS');
    AF_ACS_StandalonePage::output('Добыча и награды ACS',$body,af_advancedcombatsystem_presentation_css('has_acs_completion'));
}

function af_advancedcombatsystem_archive_page():void
{
    global $mybb,$db;require_once __DIR__.'/modules/Presentation/ArchivePage.php';
    require_once __DIR__.'/modules/Presentation/ThreadComponents.php';
    try{$service=new AF_ACS_ArchiveService($db,new AF_ACS_SessionAccess);
        if($mybb->request_method==='post'){$r=$_POST;foreach(['action','my_post_key'] as $k)unset($r[$k]);$service->policy($r);$params=['session_id'=>$r['session_id'],'encounter_id'=>$r['encounter_id']];}
        else $params=array_map(static fn($v)=>is_string($v)?$v:'',$_GET);
        $body=AF_ACS_ArchivePage::render($service,$params);
    }
    catch(Throwable){http_response_code(403);$body='<p role="alert">Архив недоступен. Проверьте права и параметры фильтра.</p>';}
    require_once __DIR__.'/modules/Presentation/StandalonePage.php';
    add_breadcrumb('Сессии ACS','misc.php?action=acs_sessions');
    add_breadcrumb('Боевой архив ACS');
    AF_ACS_StandalonePage::output('Боевой архив ACS',$body,af_advancedcombatsystem_presentation_css('has_acs_archive'));
}
