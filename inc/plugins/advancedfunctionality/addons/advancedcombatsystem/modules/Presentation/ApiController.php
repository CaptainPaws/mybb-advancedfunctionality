<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/DraftService.php';

/** Draft transport only. Does not route into session commands or diagnostic SQL writes. */
final class AF_ACS_ApiController
{
    public static function handle(AF_ACS_DraftService $service,string $op,array $input):array
    {
        if($op==='draft_restore'){
            global $mybb;if($mybb->request_method!=='get'||array_diff(array_keys($input),['draft_id']))throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
            return $service->restore((string)($input['draft_id']??''));
        }
        if($op!=='draft_save')throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
        return $service->save($input);
    }
    public static function dispatch():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
            if((int)($_SERVER['CONTENT_LENGTH']??0)>262144)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');
            $op=$mybb->get_input('op');$input=[];
            if(in_array($op,['prepared_publish','prepared_cancel','prepared_release'],true)){
                require_once __DIR__.'/../Application/PreparedActionService.php';
                $fields=$op==='prepared_publish'?['draft_id','expected_draft_version','expected_version','operation_key']:['session_id','encounter_id','entity_id','prepared_action_id','prepared_version','expected_version','operation_key'];
                if(array_diff(array_keys($_POST),['action','addon','op','my_post_key',...$fields]))throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
                foreach($fields as $f)$input[$f]=$mybb->get_input($f);
                $prepared=new AF_ACS_PreparedActionService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess);
                if($op==='prepared_release'){require_once __DIR__.'/../Application/RecoveryService.php';$data=(new AF_ACS_RecoveryService($db,new AF_ACS_SessionAccess))->recoverPreparedItems($input);}else $data=$op==='prepared_publish'?$prepared->publish($input):$prepared->cancel($input);
            }elseif(in_array($op,['seal','seal_cancel_tail','seal_confirm','seal_prepare_reactions','seal_status'],true)){
                require_once __DIR__.'/../Application/SealService.php';
                $seal=new AF_ACS_SealService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess);
                if($op==='seal_status'){
                    if($mybb->request_method!=='get')throw new RuntimeException('ACS_POST_REQUIRED');
                    $data=$seal->status($mybb->get_input('draft_id'));
                }else{
                    if(array_diff(array_keys($_POST),['action','addon','op','my_post_key','draft_id','expected_draft_version','operation_key','confirmation_hash','friendly_fire_confirmation']))throw new RuntimeException('ACS_SEAL_INPUT_INVALID');
                    $input=[];foreach(['draft_id','expected_draft_version','operation_key'] as $f)$input[$f]=$mybb->get_input($f);
                    if($mybb->get_input('friendly_fire_confirmation')!=='')$input['friendly_fire_confirmation']=$mybb->get_input('friendly_fire_confirmation');
                    if($op==='seal_confirm')$input['confirmation_hash']=$mybb->get_input('confirmation_hash');
                    $data=$seal->command(match($op){'seal'=>'seal','seal_cancel_tail'=>'cancel_tail','seal_prepare_reactions'=>'prepare_reactions',default=>'confirm'},$input);
                }
            }elseif($op==='draft_save'){
                if(array_diff(array_keys($_POST),['action','addon','op','my_post_key','session_id','encounter_id','turn_id','draft_id','expected_draft_version','operation_key','prose','actions']))throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
                foreach(['session_id','encounter_id','turn_id','draft_id','expected_draft_version','operation_key','prose'] as $f)$input[$f]=$mybb->get_input($f);
                $raw=$mybb->get_input('actions');if(strlen($raw)>65536)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');
                $input['actions']=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            }else $input=['draft_id'=>$mybb->get_input('draft_id')];
            if(!in_array($op,['prepared_release','prepared_cancel','prepared_publish','seal','seal_cancel_tail','seal_confirm','seal_prepare_reactions','seal_status'],true))$data=self::handle(new AF_ACS_DraftService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess),$op,$input);
            $result=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $candidate=$e->getMessage();if(in_array($op??'',['prepared_publish','prepared_cancel','prepared_release'],true)&&$candidate==='ACS_VERSION_CONFLICT')$candidate='ACS_DRAFT_VERSION_CONFLICT';if($candidate==='ACS_INVENTORY_RECOVERY_REQUIRED')$candidate='ACS_RECOVERY_REQUIRED';if(in_array($op??'',['seal','seal_confirm','seal_cancel_tail'],true)&&$candidate!=='ACS_PREPARED_UNSUPPORTED'&&!str_starts_with($candidate,'ACS_BEHAVIOR_')&&($e instanceof DomainException||in_array($candidate,['builder_input_invalid','builder_parameters_unsupported','preview_target_forbidden'],true)))$candidate='ACS_SEAL_UNSUPPORTED';if(in_array($op??'',['seal','seal_confirm','seal_cancel_tail'],true)&&(str_starts_with($candidate,'ACS_ITEM_')||in_array($candidate,['INV_SPENDING_NOT_AUTHORIZED','INV_SCHEMA_NOT_READY','ACS_INVENTORY_UNAVAILABLE'],true)))$candidate='ACS_SEAL_UNSUPPORTED';$code=in_array($candidate,['ACS_PREPARED_UNSUPPORTED','ACS_BEHAVIOR_UNSUPPORTED','ACS_BEHAVIOR_ACTION_BLOCKED','ACS_BEHAVIOR_TARGET_BLOCKED','ACS_BEHAVIOR_NO_LEGAL_TARGET','ACS_FRIENDLY_FIRE_CONFIRMATION_REQUIRED','ACS_SEAL_UNSUPPORTED','ACS_SEAL_IMMUTABLE','ACS_SEAL_SUMMARY_CHANGED','ACS_SEAL_QUOTA_EXHAUSTED','ACS_SEAL_TURN_UNAVAILABLE','ACS_SEAL_NOTHING_TO_APPEND','ACS_SEAL_NOTHING_TO_CANCEL','ACS_SEAL_PIN_CHANGED','ACS_DRAFT_VERSION_CONFLICT','ACS_DRAFT_ALREADY_EXISTS','ACS_OPERATION_KEY_CONFLICT','ACS_RECOVERY_REQUIRED','ACS_DRAFT_TURN_UNAVAILABLE','ACS_DRAFT_READ_ONLY','ACS_LOGIN_REQUIRED','ACS_CSRF_REQUIRED','ACS_POST_REQUIRED','ACS_DRAFT_UNAVAILABLE','ACS_RATE_LIMIT','ACS_DISABLED','ACS_SCHEMA_NOT_READY','ACS_REQUEST_TOO_LARGE','ACS_DRAFT_INPUT_INVALID'],true)?$candidate:'ACS_DRAFT_UNAVAILABLE';
            $conflict=in_array($code,['ACS_SEAL_IMMUTABLE','ACS_SEAL_SUMMARY_CHANGED','ACS_SEAL_QUOTA_EXHAUSTED','ACS_SEAL_TURN_UNAVAILABLE','ACS_SEAL_NOTHING_TO_APPEND','ACS_SEAL_NOTHING_TO_CANCEL','ACS_SEAL_PIN_CHANGED','ACS_DRAFT_VERSION_CONFLICT','ACS_DRAFT_ALREADY_EXISTS','ACS_OPERATION_KEY_CONFLICT','ACS_RECOVERY_REQUIRED','ACS_DRAFT_TURN_UNAVAILABLE','ACS_DRAFT_READ_ONLY'],true);
            http_response_code($conflict?409:($code==='ACS_RATE_LIMIT'?429:($code==='ACS_DRAFT_INPUT_INVALID'||$code==='ACS_REQUEST_TOO_LARGE'?422:403)));
            $message=$conflict?'Черновик или ход изменился. Восстановите сохранённую версию; локальный текст не отправляйте повторно вслепую.':($code==='ACS_DRAFT_INPUT_INVALID'?'Некорректный формат черновика.':'Черновик недоступен: проверьте доступ, подтверждение запроса и состояние ACS.');
            $message=match($code){
                'ACS_RECOVERY_REQUIRED'=>'Предметная или публикационная операция ожидает проверки прежнего исхода. Сохранённые действия не повторяйте с новым ключом.',
                'ACS_PREPARED_UNSUPPORTED','ACS_SEAL_UNSUPPORTED','ACS_BEHAVIOR_UNSUPPORTED','ACS_BEHAVIOR_ACTION_BLOCKED','ACS_BEHAVIOR_TARGET_BLOCKED','ACS_BEHAVIOR_NO_LEGAL_TARGET'=>AF_ACS_BuilderService::message($e),
                'ACS_SEAL_IMMUTABLE'=>'Запечатанные действия неизменны. Отменять можно только с конца.',
                'ACS_SEAL_SUMMARY_CHANGED'=>'Сводка изменилась. Обновите её на прежних бросках и подтвердите заново.',
                'ACS_SEAL_QUOTA_EXHAUSTED'=>'Квота подготовки группы исчерпана. Отмена не возвращает квоту.',
                'ACS_SEAL_TURN_UNAVAILABLE'=>'Нужен открытый pending-ход без паузы.',
                'ACS_SEAL_PIN_CHANGED'=>'Закреплённая версия определения изменилась. Броски сохранены; новый roll не выполняется.',
                default=>$message};
            $result=['ok'=>false,'error'=>['code'=>$code,'message'=>$message]];if($code==='ACS_PREPARED_UNSUPPORTED')$result['error']['status']='UNSUPPORTED';if(str_starts_with($code,'ACS_BEHAVIOR_'))$result['error']['status']=$code==='ACS_BEHAVIOR_UNSUPPORTED'?'UNSUPPORTED':'BLOCKED';
        }
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}
