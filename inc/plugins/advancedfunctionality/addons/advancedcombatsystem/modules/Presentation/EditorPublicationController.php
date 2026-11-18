<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Bootstrap/PublicationTestGate.php';

final class AF_ACS_EditorPublicationController
{
    public static function handle(?AF_ACS_SessionAccess $access = null): array
    {
        global $mybb,$db;
        if (!AF_ACS_PublicationTestGate::allowed()) { throw new RuntimeException('ACS_EDITOR_TEST_DISABLED'); }
        if (!defined('THIS_SCRIPT') || THIS_SCRIPT!=='newreply.php') { throw new RuntimeException('ACS_MYBB_SURFACE_UNSUPPORTED'); }
        if ($mybb->get_input('acs_mode')!=='combat') { throw new RuntimeException('ACS_EDITOR_REQUEST_INVALID'); }
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>262144) { throw new RuntimeException('ACS_REQUEST_TOO_LARGE'); }
        require_once __DIR__.'/../Application/EditorPublicationService.php';
        $service=new AF_ACS_EditorPublicationService($db,$access);$op=$mybb->get_input('acs_operation');$tid=$mybb->get_input('tid');
        if (in_array($op,['compose','status'],true)) {
            if ($mybb->request_method!=='get') { throw new RuntimeException('ACS_EDITOR_REQUEST_INVALID'); }
            return $op==='compose'?$service->compose($mybb->get_input('acs_draft_id'),$tid):$service->status($mybb->get_input('acs_submission_id'),$tid);
        }
        ($access??new AF_ACS_SessionAccess)->csrf();
        foreach (['pid','savedraft','previewpost','newattachment','updateattachment','attachmentaid','attachmentact','modoptions'] as $field) {
            if (!empty($mybb->input[$field])) { throw new RuntimeException('ACS_MYBB_BRANCH_UNSUPPORTED'); }
        }
        if (!empty($_FILES)) { throw new RuntimeException('ACS_MYBB_ATTACHMENTS_UNSUPPORTED'); }
        $service->assertNoPendingAttachments($mybb->get_input('posthash'));
        if ($op==='prepare') {
            return $service->prepare($mybb->get_input('acs_draft_id'),$tid,$mybb->get_input('acs_draft_version'),$mybb->get_input('acs_confirmation_hash'),$mybb->get_input('message'));
        }
        if ($op!=='publish' || $mybb->get_input('action')!=='do_newreply') { throw new RuntimeException('ACS_EDITOR_REQUEST_INVALID'); }
        $p=$mybb->input['postoptions']??[];
        if (!is_array($p)) { throw new RuntimeException('ACS_EDITOR_REQUEST_INVALID'); }
        return $service->publish($mybb->get_input('acs_submission_id'),$tid,$mybb->get_input('message'),[
            'subject'=>$mybb->get_input('subject'),'replyto'=>$mybb->get_input('replyto')?:'0','icon'=>$mybb->get_input('icon')?:'0',
            'signature'=>!empty($p['signature']),'disablesmilies'=>!empty($p['disablesmilies']),
            'subscriptionmethod'=>$p['subscriptionmethod']??'','posthash'=>$mybb->get_input('posthash'),
            'quick_reply'=>$mybb->get_input('method')==='quickreply',
        ]);
    }
    public static function failure(Throwable $error): array
    {
        $code=$error->getMessage();
        $messages=[
            'ACS_EDITOR_TEST_DISABLED'=>'Боевая публикация недоступна в этой конфигурации.',
            'ACS_MYBB_ATTACHMENTS_UNSUPPORTED'=>'Вложения в боевом режиме пока не поддерживаются. Уберите файлы; текст и действия сохранены.',
            'ACS_MYBB_BRANCH_UNSUPPORTED'=>'Черновики MyBB, предпросмотр, вложения и инструменты модерации не отправляют боевой пост.',
            'ACS_EDITOR_SAVE_REQUIRED'=>'Сначала сохраните этот литературный текст в серверном черновике.',
            'ACS_EDITOR_FROZEN_PROSE_CHANGED'=>'Текст этой отправки уже зафиксирован. Локальный текст сохранён; проверьте статус прежней отправки.',
            'ACS_CONFIRMATION_REQUIRED'=>'Обновите и подтвердите сводку в конструкторе на прежних бросках.',
            'ACS_VERSION_CONFLICT'=>'Операция изменилась. Проверьте её статус с прежним submission_id.',
            'ACS_MYBB_CORE_UNVERIFIED'=>'Установленный MyBB не прошёл проверку публикационного пути. Отправка заблокирована.',
            'ACS_NATIVE_LIFECYCLE_UNVERIFIED'=>'Порядок обработчиков MyBB не подтверждён. Отправка заблокирована.',
        ];
        if (!isset($messages[$code])) { $code='ACS_EDITOR_UNAVAILABLE'; }
        $category=match($code){'ACS_VERSION_CONFLICT','ACS_CONFIRMATION_REQUIRED','ACS_EDITOR_FROZEN_PROSE_CHANGED'=>'STATE_CONFLICT','ACS_EDITOR_TEST_DISABLED'=>'PERMISSION_DENIED','ACS_MYBB_CORE_UNVERIFIED','ACS_NATIVE_LIFECYCLE_UNVERIFIED'=>'DEPENDENCY_ERROR','ACS_EDITOR_UNAVAILABLE'=>'INTERNAL_ERROR',default=>'VALIDATION_ERROR'};
        $id=bin2hex(random_bytes(16));
        if(function_exists('af_store_addon_lifecycle_diagnostic')){try{af_store_addon_lifecycle_diagnostic('advancedcombatsystem','editor_publication','request',new RuntimeException($code.' [error_id='.$id.']'));}catch(Throwable $ignored){}}
        return ['ok'=>false,'error'=>['code'=>$code,'category'=>$category,'error_id'=>$id,'message'=>$messages[$code]??'Запрос не подтверждён. Текст и sealed-действия сохранены. Проверьте статус; не отправляйте новый пост вслепую.']];
    }
    public static function decorate(string &$page): void
    {
        global $mybb;
        if (!defined('THIS_SCRIPT') || !in_array(THIS_SCRIPT,['newreply.php','showthread.php'],true)
            || ($mybb->get_input('acs_draft')==='' && $mybb->get_input('acs_builder')==='') || str_contains($page,'data-acs-publication-config')) { return; }
        if (!function_exists('af_frontend_asset_allowed') || !af_frontend_asset_allowed('advancedcombatsystem','publication',null,['has_acs_publication'=>true])) { return; }
        $builderHtml='';$authorized=false;$draftId=$mybb->get_input('acs_draft');
        if($draftId!=='' && !str_contains($page,'data-acs-builder>')) {
            try {
                global $db;
                require_once __DIR__.'/BuilderController.php';
                $repo=new AF_ACS_EncounterRepository($db);$access=new AF_ACS_SessionAccess;
                $draft=$repo->draft(AF_ACS_Repository::integer($draftId),$access->uid());
                if(!$draft) return;
                $view=(new AF_ACS_SessionService($repo,$access))->view($draft['session_id']);
                if($view['session']['tid']!==$mybb->get_input('tid')) return;
                $authorized=true;
                if(in_array($draft['status'],['draft','sealed'],true)) {
                    $context=(new AF_ACS_BuilderService($repo,$access))->context($draft['session_id'],$draft['encounter_id'],$draft['turn_id']);
                    if((string)$context['draft_id']!==$draftId) return;
                    $builderHtml=AF_ACS_BuilderController::renderBuilder($draft['session_id'],$view['session']['tid'],null,$context);
                }
            } catch(Throwable $error) {
                if(!$authorized) return;
                $failure=self::failure($error);$builderHtml='<p role="status">'.htmlspecialchars_uni('Конструктор недоступен. Сохраните текст и обратитесь к GM. error_id='.$failure['error']['error_id']).'</p>';
            }
        }
        if($draftId==='' && !str_contains($page,'data-acs-builder>')) return;
        if(!headers_sent())header('Cache-Control: private, no-store');
        $config=['author_uid'=>(string)(int)$mybb->user['uid'],'draft_id'=>$draftId,'tid'=>$mybb->get_input('tid'),'endpoint'=>'newreply.php',
            'can_publish'=>AF_ACS_PublicationTestGate::allowed(),'post_key'=>$mybb->post_code];
        $json=json_encode($config,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
        $base=htmlspecialchars_uni(rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/');
        $html=$builderHtml.'<link rel="stylesheet" href="'.$base.'publication.css?v=1113"><script type="application/json" data-acs-publication-config>'.$json.'</script><script defer src="'.$base.'publication.js?v=1113"></script>';
        $pos=strripos($page,'</body>');$page=$pos===false?$page.$html:substr_replace($page,$html,$pos,0);
    }
}
