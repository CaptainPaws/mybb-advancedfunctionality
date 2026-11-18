<?php
if(!defined('IN_MYBB')){die('No direct access');}
/** One public error vocabulary. Raw exception text/SQL/IDs never become a message. */
final class AF_ACS_SafeError
{
    private static ?string $logged=null;
    public static function from(Throwable $error,string $operation='api',?string $errorId=null):array
    {
        $candidate=$error instanceof JsonException?'ACS_DRAFT_INPUT_INVALID':($error instanceof AF_ACS_FormulaError?$error->diagnosticCode:$error->getMessage());
        [$code,$category,$message]=match($candidate){
            'ACS_DRAFT_VERSION_CONFLICT','ACS_DRAFT_ALREADY_EXISTS'=>[$candidate,'STATE_CONFLICT','Черновик изменён в другой вкладке. Локальный текст и действия сохранены; сравните версии перед восстановлением.'],
            'ACS_OPERATION_KEY_CONFLICT'=>[$candidate,'STATE_CONFLICT','Прежний ключ связан с другим запросом. Проверьте исход сохранённой операции; новый ключ автоматически не создаётся.'],
            'ACS_DRAFT_READ_ONLY','ACS_DRAFT_TURN_UNAVAILABLE','ACS_SEAL_TURN_UNAVAILABLE','preview_turn_unavailable'=>[$candidate,'STATE_CONFLICT','Ход недоступен или изменился. Обновите состояние; текст, действия и запечатанные значения сохранены.'],
            'ACS_SEAL_IMMUTABLE','ACS_SEAL_NOTHING_TO_APPEND','ACS_SEAL_NOTHING_TO_CANCEL'=>[$candidate,'STATE_CONFLICT','Запечатанная последовательность неизменна. Обновите сохранённую сводку; новые броски не выполняются.'],
            'ACS_FRIENDLY_FIRE_CONFIRMATION_REQUIRED'=>[$candidate,'STATE_CONFLICT','Требуется отдельное подтверждение дружественного огня по актуальной серверной сводке.'],
            'ACS_BEHAVIOR_ACTION_BLOCKED','ACS_BEHAVIOR_NO_LEGAL_TARGET'=>[$candidate,'STATE_CONFLICT','Действие недоступно по текущим серверным ограничениям. Проверьте исполнителя и допустимые цели.'],
            'ACS_VERSION_CONFLICT','preview_version_conflict','builder_version_conflict','ACS_DEFINITION_VERSION_CONFLICT'=>['ACS_VERSION_CONFLICT','STATE_CONFLICT','Состояние боя изменилось. Обновите сведения и проверьте цели, расходы и права; текст и действия сохранены.'],
            'preview_target_forbidden','ACS_BEHAVIOR_TARGET_BLOCKED','ACS_TARGET_UNAVAILABLE'=>['ACS_TARGET_UNAVAILABLE','STATE_CONFLICT','Цель больше недоступна. Проверьте разрешённые цели; сохранённые действия автоматически не заменяются.'],
            'ACS_CONTROL_REVOKED','ACS_CONTROL_INVALID','ACS_DELEGATION_ACTION_DENIED','ACS_AP_ACCESS_DENIED','ACS_REGISTRATION_DENIED'=>['ACS_CONTROL_REVOKED','PERMISSION_DENIED','Право управления недоступно или отозвано. Обратитесь к GM; текст и действия сохранены.'],
            'effects_cost_unaffordable','ACS_AP_INSUFFICIENT','ACS_SEAL_QUOTA_EXHAUSTED'=>[$candidate,'STATE_CONFLICT','Недостаточно AP, ресурсов или доступной квоты. Проверьте расходы последовательности.'],
            'ACS_ITEM_UNAVAILABLE','ACS_INVENTORY_UNAVAILABLE','INV_SPENDING_NOT_AUTHORIZED','ACS_ITEM_RESERVATION_REQUIRED'=>['ACS_ITEM_UNAVAILABLE','DEPENDENCY_ERROR','Предмет или подтверждённый резерв недоступен. Проверьте экипировку и состояние Inventory.'],
            'ACS_SEAL_SUMMARY_CHANGED','ACS_SEAL_PIN_CHANGED','ACS_CONFIRMATION_REQUIRED'=>['ACS_SEAL_SUMMARY_CHANGED','STATE_CONFLICT','Результат нужно подтвердить заново. Обновите сводку на прежних запечатанных значениях; новый roll не выполняется.'],
            'ACS_RECOVERY_REQUIRED','ACS_INVENTORY_RECOVERY_REQUIRED','ACS_COMMIT_UNKNOWN','ACS_ROLLBACK_UNKNOWN','ACS_MYBB_POSTING_UNKNOWN'=>['ACS_RECOVERY_REQUIRED','RECOVERY_REQUIRED','Операция требует восстановления. Проверяйте прежнюю операцию; не отправляйте новый пост или новый ключ после неизвестного ответа.'],
            'ACS_LOGIN_REQUIRED','ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_DRAFT_UNAVAILABLE','ACS_CSRF_REQUIRED','ACS_POST_REQUIRED'=>[$candidate,'PERMISSION_DENIED','Запрос недоступен или не подтверждён. Проверьте текущие права, доступ к теме и подтверждение формы.'],
            'ACS_EDITOR_TEST_DISABLED'=>[$candidate,'PERMISSION_DENIED','Боевая публикация недоступна в этой конфигурации. Черновик и текст сохранены.'],
            'ACS_MYBB_CORE_UNVERIFIED','ACS_NATIVE_LIFECYCLE_UNVERIFIED'=>[$candidate,'DEPENDENCY_ERROR','Установленный публикационный путь не подтверждён. Отправка заблокирована; обратитесь к GM.'],
            'ACS_MYBB_ATTACHMENTS_UNSUPPORTED','ACS_MYBB_BRANCH_UNSUPPORTED'=>[$candidate,'VALIDATION_ERROR','Вложения, предпросмотр MyBB и служебные ветки не отправляют боевой пост. Текст и действия сохранены.'],
            'ACS_EDITOR_SAVE_REQUIRED'=>[$candidate,'STATE_CONFLICT','Сначала сохраните литературный текст в серверном черновике. Текущий текст не удалён.'],
            'ACS_EDITOR_FROZEN_PROSE_CHANGED'=>[$candidate,'STATE_CONFLICT','Текст отправки уже зафиксирован. Сохраните локальный вариант отдельно и проверьте прежнюю операцию.'],
            'ACS_ARCHIVE_ACCESS_DENIED','ACS_ARCHIVE_NATIVE_DENIED','ACS_ARCHIVE_EXPORT_DENIED'=>[$candidate,'PERMISSION_DENIED','Запрошенные сведения недоступны по текущим правам и политике архива.'],
            'ACS_ARCHIVE_CURSOR_INVALID','ACS_ARCHIVE_ACCESS_CHANGED'=>[$candidate,'STATE_CONFLICT','Права или версия архива изменились. Начните новую страницу с прежними фильтрами.'],
            'ACS_DISABLED','ACS_SCHEMA_NOT_READY','builder_assets_unavailable','ACS_VERSIONS_KEY_UNAVAILABLE'=>[$candidate,'DEPENDENCY_ERROR','Необходимый компонент ACS недоступен. Сохраните текст и обратитесь к GM с error_id.'],
            'ACS_SEAL_UNSUPPORTED','ACS_PREPARED_UNSUPPORTED','ACS_BEHAVIOR_UNSUPPORTED'=>[$candidate,'DEPENDENCY_ERROR','Действие не поддержано подтверждённым серверным контрактом. Выбор и текст сохранены.'],
            'ACS_DRAFT_INPUT_INVALID','ACS_REQUEST_TOO_LARGE','builder_input_invalid','preview_input_invalid','ACS_VERSIONS_INPUT_INVALID','ACS_RATE_LIMIT'=>[$candidate,'VALIDATION_ERROR','Запрос не прошёл проверку формата или лимита. Исправьте ввод; сохранённые данные не удаляются.'],
            default=>['ACS_REQUEST_UNAVAILABLE','INTERNAL_ERROR','Не удалось подтвердить результат запроса. Текст и действия сохранены. Проверьте прежнюю операцию и передайте error_id GM.'],
        };
        $id=$errorId!==null&&preg_match('/\A[a-f0-9]{32}\z/D',$errorId)?$errorId:bin2hex(random_bytes(16));
        if(self::$logged===null){
            try{global $db;require_once __DIR__.'/../Infrastructure/Repository.php';$op=preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/D',$operation)?$operation:'api';self::$logged=(new AF_ACS_Repository($db))->recordDiagnostic($id,$category,$op,preg_match('/\A[A-Za-z_]{1,64}\z/D',$candidate)?$candidate:'api_response');$id=self::$logged;}catch(Throwable){}
        }
        $dto=['code'=>$code,'category'=>$category,'message'=>$message,'error_id'=>$id];
        if(in_array($code,['ACS_PREPARED_UNSUPPORTED','ACS_SEAL_UNSUPPORTED','ACS_BEHAVIOR_UNSUPPORTED'],true))$dto['status']='UNSUPPORTED';
        elseif(str_starts_with($code,'ACS_BEHAVIOR_'))$dto['status']='BLOCKED';
        return $dto;
    }
    /** Scoped to ACS AF JSON endpoints. File exports bypass this buffer entirely. */
    public static function normalize(string $body):string
    {
        if(strlen($body)>65536)return $body;
        try{$dto=json_decode($body,true,32,JSON_THROW_ON_ERROR);if(!is_array($dto)||($dto['ok']??null)!==false||!is_array($dto['error']??null))return $body;
            $old=$dto['error'];$error=self::from(new RuntimeException(is_string($old['code']??null)?$old['code']:'ACS_REQUEST_UNAVAILABLE'),'api',is_string($old['error_id']??null)?$old['error_id']:null);
            // No legacy message/technical extras can escape the canonical DTO.
            $dto['error']=$error;return json_encode($dto,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }catch(Throwable){return $body;}
    }
}
