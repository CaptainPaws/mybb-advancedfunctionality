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
        else {
            $input=[];foreach(['operation_key','reason','tid','owner_uid','session_id','expected_version','uid','role'] as $field)$input[$field]=$mybb->get_input($field);
            $data=$service->command($op,$input);
        }
        $version=$data['session_version']??$data['session']['session_version']??null;
        $response=['ok'=>true,'request_id'=>bin2hex(random_bytes(16)),'data'=>$data,'versions'=>$version===null?(object)[]:['session_version'=>$version],'warnings'=>[]];
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
    $category=match($error->getMessage()) {
        'ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_SESSION_TRANSITION_BLOCKED'=>'STATE_CONFLICT',
        'ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_CREATE_DENIED','ACS_ROLE_ESCALATION_DENIED','ACS_ADMIN_PERMISSION_REQUIRED','ACS_LOGIN_REQUIRED','ACS_CSRF_REQUIRED'=>'PERMISSION_DENIED',
        'ACS_REASON_REQUIRED','ACS_ROLE_INVALID','ACS_INVALID_INTEGER','ACS_INTEGER_RANGE','ACS_OPERATION_KEY_REQUIRED','ACS_POST_REQUIRED','ACS_REQUEST_TOO_LARGE','ACS_RATE_LIMIT','ACS_OWNER_PROTECTED','ACS_OWNER_UNCHANGED','ACS_USER_UNAVAILABLE'=>'VALIDATION_ERROR',
        'ACS_COMMIT_UNKNOWN','ACS_ROLLBACK_UNKNOWN','ACS_RECOVERY_REQUIRED'=>'RECOVERY_REQUIRED',
        default=>'INTERNAL_ERROR',
    };
    $id=bin2hex(random_bytes(16));$requestId=$id;
    try {if(!in_array($category,['INTERNAL_ERROR','RECOVERY_REQUIRED'],true))return ['category'=>$category,'request_id'=>$requestId,'error_id'=>$id,'message'=>'Сессия или команда недоступна. Проверьте права, поля и версию данных.'];
        require_once __DIR__.'/modules/Infrastructure/Repository.php';$id=(new AF_ACS_Repository($db))->recordDiagnostic($id,$category,'session_command','validation');}
    catch(Throwable $diagnosticError) {error_log('ACS session '.$id.' '.$category);}
    return ['category'=>$category,'request_id'=>$requestId,'error_id'=>$id,'message'=>'Сессия или команда недоступна. Проверьте права, поля и версию данных.'];
}
