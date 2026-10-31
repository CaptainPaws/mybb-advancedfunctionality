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
        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='showthread.php' && !empty($mybb->user['uid'])) {
            require_once __DIR__.'/modules/Presentation/ThreadComponents.php';
            $plugins->add_hook('global_end','af_advancedcombatsystem_register_thread_component');
        }
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
        return $state['error'] === null && $state['schema_version'] === AF_ACS_Migrations::VERSION;
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
    });
}

function af_advancedcombatsystem_activate(): bool
{
    return af_advancedcombatsystem_lifecycle('activate', static function () {
        af_advancedcombatsystem_migrations()->assertInstalled();
        unset($GLOBALS['af_acs_deactivated']);
        // No combat hooks/assets exist yet. AF owns the persisted enabled flag.
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
    });
}

function af_advancedcombatsystem_uninstall(string $confirmation = ''): bool
{
    return af_advancedcombatsystem_lifecycle('uninstall', static function () use ($confirmation) {
        af_advancedcombatsystem_migrations()->uninstall($confirmation);
    });
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
    global $header,$headerinclude,$footer;
    require_once __DIR__.'/modules/Presentation/Sessions.php';
    ob_start();try {AF_ACS_SessionsPage::render(false);}catch(Throwable $error){echo '<p>Сессии ACS недоступны.</p>';}$body=ob_get_clean();
    header('Cache-Control: no-store');
    output_page('<html><head><title>Сессии ACS</title>'.$headerinclude.'</head><body>'.$header.'<main class="af-acs">'.$body.'</main>'.$footer.'</body></html>');exit;
}
function af_advancedcombatsystem_session_settings(): void
{
    af_ensure_settinggroup('af_acs_sessions','ACS: Сессии','Права создания и значения только для новых сессий.');
    af_ensure_setting('af_acs_sessions','af_acs_session_gm_groups','Группы мастеров ACS','ID групп через запятую. Пусто: создание только через права ACP AF.','text','',1);
    af_ensure_setting('af_acs_sessions','af_acs_default_member_role','Роль новых участников','Значение копируется в конфигурацию новой сессии. Старые сессии не изменяются.',"select\nobserver=Наблюдатель\nplayer=Игрок",'observer',2);
    af_rebuild_and_reload_settings();
}

/** Existing AF router; safe errors, native session access, no mutation on GET. */
function af_advancedcombatsystem_xmlhttp(): void
{
    global $mybb;
    try {
        if(empty($mybb->user['uid']))throw new RuntimeException('ACS_LOGIN_REQUIRED');
        if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');
        $service=af_advancedcombatsystem_sessions();$op=$mybb->get_input('op');
        if($op==='list')$data=$service->list($mybb->get_input('after')?:'0');
        elseif($op==='get')$data=$service->view($mybb->get_input('session_id'));
        elseif(str_starts_with($op,'round_manage_')) {
            $rounds=af_advancedcombatsystem_encounters()->rounds();$sid=$mybb->get_input('session_id');$eid=$mybb->get_input('encounter_id');
            $input=[];foreach(['session_id','encounter_id','expected_version','encounter_version','turn_id','turn_version','round_id','operation_key','reason','confirm','deadline_mode','seconds'] as $field)$input[$field]=$mybb->get_input($field);
            $data=$op==='round_manage_overview'?$rounds->overview($sid,$eid,$mybb->get_input('after')?:'0'):$rounds->command(substr($op,13),$input);
        }
        elseif(str_starts_with($op,'clock_')) {
            $clock=af_advancedcombatsystem_encounters()->clock();$sid=$mybb->get_input('session_id');
            $input=[];foreach(['session_id','expected_version','encounter_id','encounter_version','operation_key','reason','round_id','entity_turn_id','control_group_id','confirm_full'] as $f)$input[$f]=$mybb->get_input($f);
            $data=match($op) {
                'clock_overview'=>$clock->overview($sid),
                'clock_ap_available'=>$clock->available($sid,$mybb->get_input('turn_id'),$mybb->get_input('entity_id'),(int)AF_ACS_Repository::integer($mybb->get_input('cost')?:'0','2147483647',true)),
                default=>$clock->command(substr($op,6),$input),
            };
        }
        elseif(str_starts_with($op,'encounter_')) {
            $encounters=af_advancedcombatsystem_encounters();$sid=$mybb->get_input('session_id');$eid=$mybb->get_input('encounter_id');
            $input=[];foreach(['session_id','expected_version','encounter_id','encounter_version','operation_key','reason','name','side_a','side_b','relationship','entity_id','side_id','uid','tid'] as $field)$input[$field]=$mybb->get_input($field);
            $data=match($op) {
                'encounter_list'=>$encounters->list($sid,$mybb->get_input('after')?:'0'),
                'encounter_get'=>$encounters->view($sid,$eid),
                'encounter_round_get'=>$encounters->roundView($sid,$eid,$mybb->get_input('after')?:'0'),
                'encounter_events'=>$encounters->events($sid,$eid,$mybb->get_input('after')?:'0'),
                'encounter_readiness'=>$encounters->readiness($sid,$eid),
                default=>$encounters->command(substr($op,10),$input),
            };
        }
        elseif(in_array($op,['entity_list','entity_snapshot','entity_register','entity_validate'],true)) {
            $entities=af_advancedcombatsystem_entities();$sid=$mybb->get_input('session_id');
            $input=[];foreach(['session_id','expected_version','operation_key','reason','kind','source_key','name','initial_hp'] as $field)$input[$field]=$mybb->get_input($field);
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
function af_advancedcombatsystem_session_failure(Throwable $error): array
{
    global $db;
    if($error instanceof AF_ACS_RegistrationBlocked) {
        $id=bin2hex(random_bytes(16));
        $known=['required_stat_missing'=>'Заполните обязательную характеристику.',
            'required_resistance_missing'=>'Заполните сопротивления всем 17 основным стихиям.',
            'initial_hp_required'=>'Укажите начальное HP экземпляра. Источник не хранит текущее HP.',
            'permanent_pet_source_unavailable'=>'Плагин постоянных питомцев ещё не подключён.',
            'registration_ownership_required'=>'Регистрация разрешена только подтверждённому владельцу.',
            'status_resistance_alias_conflict'=>'Конфликт значений сопротивления статусам.',
            'equipment_provenance_incomplete'=>'Не подтверждено происхождение бонусов экипировки.',
            'bestiary_modifier_provenance_unavailable'=>'Шаблон содержит модификаторы без подтверждённого итогового расчёта.',
            'resource_semantics_unverified'=>'Для дополнительных ресурсов пока отсутствует подтверждённый контракт.'];
        $diagnostics=[];
        foreach(array_slice($error->diagnostics,0,32) as $issue) {
            $code=(string)($issue['code']??'snapshot_source_unavailable');
            if(!preg_match('/\A[a-z0-9_]{1,96}\z/D',$code))$code='snapshot_source_unavailable';
            $path=(string)($issue['path']??'source');
            if(!preg_match('/\A[a-zA-Z0-9_.]{1,191}\z/D',$path))$path='source';
            $message=$known[$code]??'Источник не готов: проверьте обязательные данные, версии и доступ.';
            if($code==='required_stat_missing' && str_contains($path,'accuracy'))$message='Заполните характеристику «Точность» в источнике.';
            $diagnostics[]=['code'=>$code,'path'=>$path,'message'=>$message];
        }
        return ['category'=>'VALIDATION_ERROR','request_id'=>$id,'error_id'=>$id,'message'=>'Регистрация заблокирована: источник не прошёл проверку.','diagnostics'=>$diagnostics];
    }
    $category=match($error->getMessage()) {
        'ACS_ROUND_STATE_CONFLICT','ACS_ROUND_OBLIGATIONS_BLOCKED','ACS_OBLIGATIONS_SCHEMA_UNVERIFIED','ACS_PENDING_TURNS_REMAIN','ACS_FORFEIT_EXCEPTION_UNAVAILABLE','ACS_CLOCK_BOUNDARY_STATE','ACS_CLOCK_DEPENDENCY_BLOCKED','ACS_COMBAT_RESET_REQUIRED','ACS_COOLDOWN_SOURCE_UNAVAILABLE','ACS_COOLDOWN_UNCONFIRMED','ACS_RESOURCE_STATE_CONFLICT','ACS_REST_COMBAT_ACTIVE','ACS_REST_LIMIT','ACS_REST_WINDOW_UNAVAILABLE','ACS_REST_POLICY_UNAVAILABLE','ACS_AP_STATE_INVALID','ACS_FIRST_ROUND_EXISTS','ACS_ROSTER_BLOCKED','ACS_ROSTER_INVALID','ACS_TURN_STATE_INVALID','ACS_ENCOUNTER_TRANSITION_BLOCKED','ACS_PARTICIPANT_EXISTS','ACS_ENTITY_UNAVAILABLE','ACS_CONTROL_INVALID','ACS_SNAPSHOT_INVALID','ACS_ENTITY_ALREADY_REGISTERED','ACS_DEFINITION_VERSION_CONFLICT','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_SESSION_TRANSITION_BLOCKED'=>'STATE_CONFLICT',
        'ACS_AP_ACCESS_DENIED','ACS_GM_REQUIRED','ACS_ENCOUNTER_UNAVAILABLE','ACS_REGISTRATION_DENIED','ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_CREATE_DENIED','ACS_ROLE_ESCALATION_DENIED','ACS_ADMIN_PERMISSION_REQUIRED','ACS_LOGIN_REQUIRED','ACS_CSRF_REQUIRED'=>'PERMISSION_DENIED',
        'ACS_DEADLINE_POLICY_INVALID','ACS_DEADLINE_DISABLED','ACS_DEADLINE_AUTOMATIC_DISABLED','ACS_DEADLINE_NOT_DUE','ACS_DEADLINE_RANGE','ACS_ROUND_OPERATION_UNAVAILABLE','ACS_ROUND_CONFIRMATION_REQUIRED','ACS_REST_CONFIRMATION_REQUIRED','ACS_REST_NO_TARGETS','ACS_CLOCK_OPERATION_UNAVAILABLE','ACS_RELATION_INVALID','ACS_SIDE_UNAVAILABLE','ACS_INVALID_JSON','ACS_JSON_TOO_LARGE','ACS_INVALID_KEY','ACS_ENTITY_KIND_INVALID','ACS_SOURCE_REQUIRED','ACS_HP_INTEGER_REQUIRED','ACS_HP_RANGE','ACS_DEFINITION_LIMIT','ACS_INVALID_TEXT','ACS_REASON_REQUIRED','ACS_ROLE_INVALID','ACS_INVALID_INTEGER','ACS_INTEGER_RANGE','ACS_OPERATION_KEY_REQUIRED','ACS_POST_REQUIRED','ACS_REQUEST_TOO_LARGE','ACS_RATE_LIMIT','ACS_OWNER_PROTECTED','ACS_OWNER_UNCHANGED','ACS_USER_UNAVAILABLE'=>'VALIDATION_ERROR',
        'ACS_COMMIT_UNKNOWN','ACS_ROLLBACK_UNKNOWN','ACS_RECOVERY_REQUIRED'=>'RECOVERY_REQUIRED',
        default=>'INTERNAL_ERROR',
    };
    $publicMessage=match($error->getMessage()) {
        'ACS_ROUND_OBLIGATIONS_BLOCKED','ACS_OBLIGATIONS_SCHEMA_UNVERIFIED'=>'Операция заблокирована незавершёнными или неподтверждёнными обязательствами. Черновики и sealed данные сохранены.',
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
    $id=bin2hex(random_bytes(16));$requestId=$id;
    try {if(!in_array($category,['INTERNAL_ERROR','RECOVERY_REQUIRED'],true))return ['category'=>$category,'request_id'=>$requestId,'error_id'=>$id,'message'=>$publicMessage];
        require_once __DIR__.'/modules/Infrastructure/Repository.php';$id=(new AF_ACS_Repository($db))->recordDiagnostic($id,$category,'session_command','validation');}
    catch(Throwable $diagnosticError) {error_log('ACS session '.$id.' '.$category);}
    return ['category'=>$category,'request_id'=>$requestId,'error_id'=>$id,'message'=>$publicMessage];
}
