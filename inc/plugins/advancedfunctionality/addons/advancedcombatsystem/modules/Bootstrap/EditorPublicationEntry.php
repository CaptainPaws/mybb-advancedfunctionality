<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/PublicationTestGate.php';

/** The early bridge rejects stale combat forms even when the addon is disabled. */
final class AF_ACS_ReplyEntrySource
{
    public const CORE = [
        'global.php'=>'2d46b1ce6d68e241a0f006a4a2122ddf52a84c012295a5f93c284a4bdc5e795b',
        'inc/class_plugins.php'=>'fff8dc9fb8f96a034d2f5b4365b58ac1bc4bb079d5c31124ab2587e7ac19a7c3',
        'inc/functions_post.php'=>'6887dbdc3ea8a1584ecbaed39ab397601906968cdea0c41e27afc39fe3e58bb9',
        'inc/functions_search.php'=>'29c3a03aa9237b3a91c3f029fb1a97e7bcbb5bab2e2f98b044ff91c3e7b2bcda',
        'inc/functions_user.php'=>'fba8a7421e4e82d9d907c040bff4775a4a55a5f0f0d241acf72df70a6a3ba03f',
        'inc/functions_upload.php'=>'e46cffdc1f5cf633c89128e156c687d6572220522d889a8feff806ed03393549',
        'inc/class_parser.php'=>'f887d425dc5971944facd3f6a03e8e2dc3b62fd706ba94df8ef528984bf38c9e',
    ];
    public static function verify(): void
    {
        require_once __DIR__.'/../Infrastructure/Adapters/MybbNativeReply.php';
        foreach (self::CORE+AF_ACS_MybbNativeReply::CORE as $file=>$hash) {
            if (!is_file(MYBB_ROOT.$file) || !hash_equals($hash,hash_file('sha256',MYBB_ROOT.$file))) { throw new RuntimeException('ACS_MYBB_CORE_UNVERIFIED'); }
        }
    }
}
function af_advancedcombatsystem_reply_guard(): void
{
    global $mybb,$plugins;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT!=='newreply.php') { return; }
    $mode=$mybb->get_input('acs_mode');$op=$mybb->get_input('acs_operation');
    if (in_array($mode,['','narrative'],true) && $op==='') { return; }
    require_once __DIR__.'/../Presentation/EditorPublicationController.php';
    try {
        if ($mode!=='combat') { throw new RuntimeException('ACS_EDITOR_REQUEST_INVALID'); }
        if (!AF_ACS_PublicationTestGate::allowed()) { throw new RuntimeException('ACS_EDITOR_TEST_DISABLED'); }
        AF_ACS_ReplyEntrySource::verify();
        if ($op==='publish') {
            if ($mybb->get_input('action')!=='acs_publication') { throw new RuntimeException('ACS_EDITOR_REQUEST_INVALID'); }
            $mybb->input['action']='do_newreply';$_POST['action']='do_newreply';$_REQUEST['action']='do_newreply';
        }
        $plugins->add_hook('global_end','af_advancedcombatsystem_reply_entry',PHP_INT_MAX);
    } catch (Throwable $error) {
        http_response_code(409);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
        echo json_encode(AF_ACS_EditorPublicationController::failure($error),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}

/** Runs at the end of global.php, before any newreply entry-script creation branch. */
function af_advancedcombatsystem_reply_entry(): void
{
    global $mybb,$plugins,$lang,$parser;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT!=='newreply.php') { return; }
    $mode=$mybb->get_input('acs_mode');$op=$mybb->get_input('acs_operation');
    if (in_array($mode,['','narrative'],true) && $op==='') { return; }
    require_once __DIR__.'/../Presentation/EditorPublicationController.php';
    try {
        if ($mode!=='combat') { throw new RuntimeException('ACS_EDITOR_REQUEST_INVALID'); }
        if (!AF_ACS_PublicationTestGate::allowed()) { throw new RuntimeException('ACS_EDITOR_TEST_DISABLED'); }
        // Exiting must not omit a later global_end callback. Refuse an unverified hook order.
        $hooks=$plugins->hooks['global_end']??[];ksort($hooks);$priority=end($hooks);$last=$priority?end($priority):null;
        if (($last['function']??null)!==__FUNCTION__) { throw new RuntimeException('ACS_NATIVE_LIFECYCLE_UNVERIFIED'); }
        AF_ACS_ReplyEntrySource::verify();
        if (isset($GLOBALS['maintimer'])) { $GLOBALS['globaltime']=$GLOBALS['maintimer']->getTime(); }
        // Same prelude as verified newreply.php; the original script never resumes after dispatch.
        foreach (['inc/functions_post.php','inc/functions_search.php','inc/functions_user.php','inc/functions_upload.php','inc/class_parser.php'] as $file) { require_once MYBB_ROOT.$file; }
        $parser=new postParser;$lang->load('newreply');
        $response=['ok'=>true,'data'=>AF_ACS_EditorPublicationController::handle()];
    } catch (Throwable $error) {
        http_response_code(409);$response=AF_ACS_EditorPublicationController::failure($error);
    }
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
function af_advancedcombatsystem_reply_decorate(string &$page): void
{
    require_once __DIR__.'/../Presentation/EditorPublicationController.php';
    AF_ACS_EditorPublicationController::decorate($page);
}
