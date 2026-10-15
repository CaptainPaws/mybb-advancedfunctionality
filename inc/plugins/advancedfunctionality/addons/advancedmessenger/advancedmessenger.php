<?php
/** AdvancedMessenger: native PM operations, request-local presentation. */
if (!defined('IN_MYBB') || !defined('AF_ADDONS')) { die('No direct access'); }
require_once __DIR__.'/repository.php';
require_once __DIR__.'/view.php';

// No installation/deactivation writes: core AF owns only the enabled setting.
// In particular, this addon never leases, updates or restores private_* rows.
function af_advancedmessenger_init(): void
{
    global $plugins;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'private.php' || defined('IN_ADMINCP')) return;
    $plugins->add_hook('private_start', 'af_advancedmessenger_start', 10000);
    foreach (['private_end','private_read_end','private_send_end','private_tracking_end',
              'private_folders_end','private_empty_end','private_export_end',
              'private_results_end','private_advanced_search'] as $hook) {
        $plugins->add_hook($hook, 'af_advancedmessenger_compose', 10000);
    }
    $plugins->add_hook('private_do_send_end', 'af_advancedmessenger_sent', 10000);
    $plugins->add_hook('private_do_search_process', 'af_advancedmessenger_filter_recipient', 10000);
    $plugins->add_hook('pre_output_page', 'af_advancedmessenger_avatars', 85);
}

function af_advancedmessenger_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function af_advancedmessenger_require_api_token(): void
{
    global $mybb;
    if ($mybb->request_method !== 'post') af_advancedmessenger_json(['error'=>'method'], 405);
    if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
        af_advancedmessenger_json(['error'=>af_advancedmessenger_t('csrf')], 403);
    }
}

/** Runs only after native private.php has enforced enablepms/login/canusepms. */
function af_advancedmessenger_start(): void
{
    global $mybb, $templates;
    if (empty($mybb->user['uid']) || empty($mybb->usergroup['canusepms']) || empty($mybb->settings['enablepms'])) {
        error_no_permission();
    }
    if (function_exists('af_frontend_asset_allowed') && !af_frontend_asset_allowed('advancedmessenger','runtime')) return;
    header('Cache-Control: private, no-store');
    $action = $mybb->get_input('action');
    if ($action === 'am_history') {
        af_advancedmessenger_require_api_token();
        $anchor = af_advancedmessenger_owned($mybb->get_input('pmid', MyBB::INPUT_INT));
        if (!$anchor || (int)$anchor['folder'] === 3) af_advancedmessenger_json(['error'=>'invalid_message'], 404);
        $history = af_advancedmessenger_history($anchor, max(0,$mybb->get_input('before_time', MyBB::INPUT_INT)),
            max(0,$mybb->get_input('before_id', MyBB::INPUT_INT)));
        af_advancedmessenger_json(['html'=>af_advancedmessenger_render_history($history), 'cursor'=>$history['cursor']]);
    }
    if ($mybb->get_input('am_fragment') || $mybb->get_input('am_touch') || $mybb->get_input('am_refresh')) af_advancedmessenger_require_api_token();
    // Archives are native downloads, not interactive templates or API responses.
    if ($action === 'do_export') return;
    foreach (glob(__DIR__.'/templates/private*.html') as $file) {
        $title = basename($file, '.html');
        $templates->cache[$title] = file_get_contents($file);
    }
    // Do not mutate MyBB's developer-mode flag. Only these display templates
    // are temporarily overridden; database copies and ATF ownership stay intact.
}

function af_advancedmessenger_unread(): int
{
    global $db, $mybb;
    $q = $db->simple_select('users', 'unreadpms', 'uid='.(int)$mybb->user['uid']);
    return (int)$db->fetch_field($q, 'unreadpms');
}

function af_advancedmessenger_compose(): void
{
    global $mybb, $pm, $lang, $header, $footer;
    $action = $mybb->get_input('action');
    if ($action === 'read' && $mybb->get_input('am_touch')) {
        // Native read just applied its own receipt/denial/count logic.
        af_advancedmessenger_json(['ok'=>true, 'unread'=>af_advancedmessenger_unread()]);
    }
    $GLOBALS['am_body_class'] = !empty($mybb->settings['af_adaptivethemeframework_enabled']) ? 'atf-active am-body' : 'am-body';
    $GLOBALS['am_uid'] = (int)$mybb->user['uid'];
    $GLOBALS['am_panel'] = $mybb->get_input('am_panel') ? 1 : 0;
    $GLOBALS['am_reply_id'] = max(0, $mybb->get_input('am_reply', MyBB::INPUT_INT));
    $GLOBALS['am_labels'] = '';
    foreach (['loading','error','response','read','open','back','close','older','select','sending','draft_local','draft_restore','empty_confirm'] as $key) {
        $GLOBALS['am_label_'.$key] = af_advancedmessenger_e(af_advancedmessenger_t($key));
        $GLOBALS['am_labels'] .= ' data-am-'.str_replace('_','-',$key).'="'.af_advancedmessenger_e(af_advancedmessenger_t($key)).'"';
    }
    $GLOBALS['am_label_title'] = af_advancedmessenger_e(af_advancedmessenger_t('title'));
    $GLOBALS['am_label_latest'] = af_advancedmessenger_e(af_advancedmessenger_t('latest'));
    $GLOBALS['am_label_recipient'] = af_advancedmessenger_e(af_advancedmessenger_t('recipient'));
    $GLOBALS['am_label_recipient_hint'] = af_advancedmessenger_e(af_advancedmessenger_t('recipient_hint'));
    $GLOBALS['am_label_folder_messages'] = af_advancedmessenger_e(af_advancedmessenger_t('folder_messages'));
    $GLOBALS['am_title'] = $GLOBALS['am_label_title'];
    $GLOBALS['am_history'] = '';
    $GLOBALS['am_older'] = '';
    $GLOBALS['am_composer'] = '';
    $GLOBALS['am_key'] = '';
    $GLOBALS['am_current_pmid'] = 0;
    $GLOBALS['am_filter_name'] = my_substr(trim($mybb->get_input('am_name')),0,120);
    $GLOBALS['am_dialog_page'] = max(1,$mybb->get_input('am_page',MyBB::INPUT_INT));
    $GLOBALS['am_folder'] = max(0, $mybb->get_input('am_folder', MyBB::INPUT_INT));
    if (!array_key_exists('am_folder', $mybb->input) && array_key_exists('fid', $_GET)) {
        $GLOBALS['am_folder'] = max(0, $mybb->get_input('fid', MyBB::INPUT_INT));
        if (!$GLOBALS['am_folder']) $GLOBALS['am_folder'] = 1;
    }
    $GLOBALS['am_quota'] = (string)($GLOBALS['pmspacebar'] ?? '');
    // Transport native fragments for the addon-owned tool/form templates.
    foreach (['quota'=>'pmspacebar','notice'=>'limitwarning','pagination'=>'multipage','actions'=>'composelink'] as $slot=>$native) {
        $GLOBALS['am_pm_'.$slot] = (string)($GLOBALS[$native] ?? '');
    }
    foreach (['navigation','before_content','after_content'] as $slot) $GLOBALS['am_pm_'.$slot] = '';
    if (isset($GLOBALS['posticons'])) {
        $GLOBALS['posticons'] = preg_replace('~</?(?:table|thead|tbody|tfoot|tr|th|td)\b[^>]*>~i', '', (string)$GLOBALS['posticons']);
    }
    if ($action === 'send') {
        af_advancedmessenger_compose_editor();
    }
    if ($action === 'read') {
        $anchor = af_advancedmessenger_owned((int)($pm['pmid'] ?? 0));
        if (!$anchor) return; // Core has already rejected inaccessible IDs.
        $GLOBALS['am_current_pmid'] = (int)$anchor['pmid'];
        $history = $mybb->get_input('am_latest') ? af_advancedmessenger_history($anchor)
            : af_advancedmessenger_history($anchor,(int)$anchor['dateline'],(int)$anchor['pmid']+1);
        $GLOBALS['am_key'] = $history['key'];
        $GLOBALS['am_history'] = af_advancedmessenger_render_history($history,(int)$anchor['pmid'],(string)$GLOBALS['message']);
        if (!str_starts_with($history['key'], 'user:')) {
            // Preserve native participant display and reply-all for group mail.
            $GLOBALS['am_history'] = '<div class="am-group-info">'.(string)($pm['subject_extra'] ?? '').'</div>'
                .af_advancedmessenger_bubble($anchor, (string)$GLOBALS['message']);
        }
        $GLOBALS['am_title'] = (string)($pm['subject'] ?? ''); // Already escaped by core.
        if (str_starts_with($history['key'], 'user:')) {
            global $db;
            $q = $db->simple_select('users', 'username', 'uid='.(int)substr($history['key'],5));
            $GLOBALS['am_title'] = af_advancedmessenger_e((string)($db->fetch_field($q,'username') ?: af_advancedmessenger_t('unknown')));
        }
        if ($history['cursor']) {
            [$time,$id] = $history['cursor'];
            $GLOBALS['am_older'] = '<button type="button" id="am-older" data-time="'.$time.'" data-id="'.$id.'">'.af_advancedmessenger_e(af_advancedmessenger_t('older')).'</button>';
        }
        if (!empty($mybb->usergroup['cansendpms']) && str_starts_with($history['key'], 'user:')
            && (int)$anchor['folder'] !== 4) {
            $peer = (int)substr($history['key'], 5);
            $subject = preg_replace('/^(?:(?:re|fw):\s*)+/i', '', (string)$anchor['subject']);
            $url = 'private.php?action=send&uid='.$peer.'&subject='.rawurlencode('Re: '.$subject)
                .'&am_reply='.(int)$anchor['pmid'].'&am_panel=1';
            $GLOBALS['am_composer'] = '<iframe class="am-composer" id="am-composer" title="'.af_advancedmessenger_e(af_advancedmessenger_t('reply'))
                .'" src="'.af_advancedmessenger_e($url).'" data-am-key="'.af_advancedmessenger_e($history['key']).'"></iframe>';
        }
        if ($mybb->get_input('am_fragment')) {
            af_advancedmessenger_json(['html'=>$GLOBALS['am_history'], 'older'=>$GLOBALS['am_older'],
                'composer'=>$GLOBALS['am_composer'], 'title'=>html_entity_decode($GLOBALS['am_title'],ENT_QUOTES,'UTF-8'),
                'pmid'=>$GLOBALS['am_current_pmid'], 'key'=>$GLOBALS['am_key'], 'unread'=>af_advancedmessenger_unread()]);
        }
    }
    if ($action === '' && $mybb->get_input('am_refresh') && $mybb->get_input('am_quota')) {
        af_advancedmessenger_json(['quota'=>$GLOBALS['am_quota'], 'unread'=>af_advancedmessenger_unread()]);
    }
    if (!$mybb->get_input('am_panel')) {
        $name = $GLOBALS['am_filter_name'];
        $context = ['folder'=>$GLOBALS['am_folder'], 'active'=>$GLOBALS['am_key'], 'name'=>$name,
            'dialogs'=>af_advancedmessenger_dialogs($GLOBALS['am_folder'], max(1,$mybb->get_input('am_page', MyBB::INPUT_INT)), $name)];
        $GLOBALS['am_sidebar'] = af_advancedmessenger_sidebar($context);
    }
    if ($action === '' && $mybb->get_input('am_refresh')) {
        af_advancedmessenger_json(['sidebar'=>$GLOBALS['am_sidebar'], 'quota'=>$GLOBALS['am_quota'], 'unread'=>af_advancedmessenger_unread()]);
    }
    af_advancedmessenger_shell();
}

function af_advancedmessenger_shell(): void
{
    global $mybb, $header, $footer;
    $panel = !empty($GLOBALS['am_panel']);
    $GLOBALS['am_header'] = $panel ? '' : (string)$header;
    $GLOBALS['am_footer'] = $panel ? '' : (string)$footer.'<atf-footer-placement></atf-footer-placement>';
    $GLOBALS['am_list_open'] = in_array((int)$GLOBALS['am_folder'], [3,4], true) ? 'open' : '';
    $GLOBALS['am_outer_start'] = $panel ? '<main class="am-root am-panel" data-am-panel-page="1"'.$GLOBALS['am_labels'].'>'
        : '<main class="pun atf-page-shell am-root" data-am-root="1" data-am-uid="'.(int)$mybb->user['uid']
            .'" data-am-token="'.af_advancedmessenger_e($mybb->post_code).'" data-am-pmid="'.(int)$GLOBALS['am_current_pmid']
            .'" data-am-key="'.af_advancedmessenger_e($GLOBALS['am_key']).'" data-am-folder="'.(int)$GLOBALS['am_folder'].'"'
            .' data-am-name="'.af_advancedmessenger_e($GLOBALS['am_filter_name']).'" data-am-page="'.(int)$GLOBALS['am_dialog_page'].'"'
            .$GLOBALS['am_labels'].'><div class="am-layout"><aside class="am-sidebar" id="am-sidebar">'
            .($GLOBALS['am_sidebar'] ?? '').'<div class="am-quota">'.$GLOBALS['am_quota'].'</div></aside>'
            .'<section class="am-pane" id="am-pane"><button type="button" class="am-back">'.af_advancedmessenger_e(af_advancedmessenger_t('back')).'</button>'
            .'<p class="am-status" id="am-status" role="status" aria-live="polite"></p>';
    $GLOBALS['am_outer_end'] = $panel ? '</main>' : '</section></div>'
        .'<dialog id="am-panel-dialog" class="am-tool-dialog"><button type="button" class="am-panel-close">'
        .af_advancedmessenger_e(af_advancedmessenger_t('close')).'</button><iframe title="'.af_advancedmessenger_e(af_advancedmessenger_t('tools')).'" id="am-tool-frame"></iframe></dialog></main>';
}

/** Reuse core's send controller and one native #message/AdvancedEditor lifecycle. */
function af_advancedmessenger_compose_editor(): void
{
    global $mybb, $templates, $db;
    $GLOBALS['am_draft_key'] = 'compose:'.$mybb->get_input('pmid', MyBB::INPUT_INT).':'.$mybb->get_input('do').':'.$mybb->get_input('uid', MyBB::INPUT_INT);
    $GLOBALS['am_is_quote'] = 0;
    $reply = af_advancedmessenger_owned($mybb->get_input('am_reply', MyBB::INPUT_INT));
    if ($reply && str_starts_with(af_advancedmessenger_identity($reply, (int)$mybb->user['uid']), 'user:')) {
        $key = af_advancedmessenger_identity($reply, (int)$mybb->user['uid']);
        $GLOBALS['am_draft_key'] = $key;
        // Native uid recipient resolution/validation has already run. This is
        // reply bookkeeping only; never replace recipient validation or errors.
        $GLOBALS['pmid'] = (int)$reply['pmid'];
        $GLOBALS['do'] = 'reply';
    }
    if ($mybb->request_method !== 'post' && $mybb->get_input('do') === 'reply') {
        $quoted = af_advancedmessenger_owned($mybb->get_input('pmid', MyBB::INPUT_INT));
        if ($quoted) {
            $key = af_advancedmessenger_identity($quoted, (int)$mybb->user['uid']);
            if (str_starts_with($key,'user:')) {
                $q = $db->simple_select('users','username','uid='.(int)substr($key,5));
                $GLOBALS['to'] = af_advancedmessenger_e((string)$db->fetch_field($q,'username'));
                $GLOBALS['am_draft_key'] = $key;
                $GLOBALS['am_is_quote'] = $mybb->get_input('am_noquote') ? 0 : 1;
            }
        }
    }
    if ($mybb->get_input('am_noquote') && $mybb->request_method !== 'post') $GLOBALS['message'] = '';
    if ($mybb->request_method === 'post') {
        $ref = $mybb->get_input('am_draft_ref');
        $original = af_advancedmessenger_owned($mybb->get_input('pmid',MyBB::INPUT_INT));
        if (($original && $ref === af_advancedmessenger_identity($original,(int)$mybb->user['uid']))
            || preg_match('~^compose:\d+:(?:reply|replyall|forward)?:\d+$~D',$ref)) {
            $GLOBALS['am_draft_key'] = $ref;
        }
    }
    $GLOBALS['am_address_open'] = trim((string)($GLOBALS['to'] ?? '')) === '' ? 'open' : '';
    $GLOBALS['am_draft_key'] = af_advancedmessenger_e($GLOBALS['am_draft_key']);
    if ($mybb->get_input('am_panel')) $templates->cache['private_send'] = file_get_contents(__DIR__.'/templates/panel_send.html');
}

/** Successful native validation/insertion only; no alternate sending pipeline. */
function af_advancedmessenger_sent(): void
{
    global $mybb, $templates, $headerinclude, $lang, $header, $footer;
    $kind = $mybb->get_input('saveasdraft') ? 'draft' : 'sent';
    $am_kind = $kind;
    $am_uid = (int)$mybb->user['uid'];
    $am_body_class = !empty($mybb->settings['af_adaptivethemeframework_enabled']) ? 'atf-active am-body' : 'am-body';
    $am_result_header = $mybb->get_input('am_panel') ? '' : (string)$header;
    $am_result_footer = $mybb->get_input('am_panel') ? '' : (string)$footer.'<atf-footer-placement></atf-footer-placement>';
    $am_result_classes = $mybb->get_input('am_panel') ? 'am-root am-panel' : 'pun atf-page-shell am-root';
    $am_notice = af_advancedmessenger_e(af_advancedmessenger_t($kind === 'draft' ? 'saved' : 'sent_ok'));
    $templates->cache['advancedmessenger_result'] = file_get_contents(__DIR__.'/templates/result.html');
    eval('$result = "'.$templates->get('advancedmessenger_result').'";');
    output_page($result);
    exit;
}

/** Resolve only addon-owned avatar placeholders, including native search rows. */
function af_advancedmessenger_avatars(string &$page): string
{
    global $db;
    if (strpos($page, '<am-pm-avatar ') === false) return $page;
    preg_match_all('~<am-pm-avatar data-uid="(\d+)"></am-pm-avatar>~', $page, $matches);
    $ids = array_values(array_unique(array_filter(array_map('intval',$matches[1]))));
    $users = [];
    if ($ids) {
        $q = $db->simple_select('users','uid,username,avatar,avatartype','uid IN ('.implode(',',$ids).')');
        while ($user = $db->fetch_array($q)) $users[(int)$user['uid']] = $user;
    }
    $page = preg_replace_callback('~<am-pm-avatar data-uid="(\d+)"></am-pm-avatar>~',
        static fn(array $m): string => af_advancedmessenger_avatar($users[(int)$m[1]] ?? ['uid'=>0,'username'=>af_advancedmessenger_t('unknown'),'avatar'=>'']), $page);
    return $page;
}

/** Add a visible-recipient filter to native search results, never search BCC. */
function af_advancedmessenger_filter_recipient(): void
{
    global $mybb, $db, $lang, $searcharray;
    $name = my_substr(trim($mybb->get_input('am_recipient')),0,120);
    if ($name === '') return;
    verify_post_check($mybb->get_input('my_post_key'));
    $q = $db->simple_select('users','uid',"LOWER(username) LIKE '%".$db->escape_string_like(my_strtolower($name))."%'");
    $users = [];
    while ($row = $db->fetch_array($q)) $users[(int)$row['uid']] = true;
    if (!$users) error($lang->error_nosearchresults);
    $ids = array_values(array_filter(array_map('intval',explode(',',(string)$searcharray['querycache']))));
    $matched = [];
    foreach (array_chunk($ids,300) as $chunk) {
        $q = $db->simple_select('privatemessages','pmid,toid,recipients','uid='.(int)$mybb->user['uid'].' AND pmid IN ('.implode(',',$chunk).')');
        while ($row = $db->fetch_array($q)) {
            $recipients = my_unserialize($row['recipients']);
            $visible = is_array($recipients['to'] ?? null) ? array_map('intval',$recipients['to']) : [(int)$row['toid']];
            foreach ($visible as $uid) if (isset($users[$uid])) { $matched[] = (int)$row['pmid']; break; }
        }
    }
    if (!$matched) error($lang->error_nosearchresults);
    $searcharray['querycache'] = implode(',',$matched);
}
