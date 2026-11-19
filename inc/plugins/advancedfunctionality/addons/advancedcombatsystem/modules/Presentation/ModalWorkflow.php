<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

require_once __DIR__.'/AtfThreadCreation.php';
require_once __DIR__.'/ThreadComponents.php';

function af_advancedcombatsystem_modal_dispatch(): never
{
    AF_ACS_ModalWorkflow::dispatch();
}

final class AF_ACS_ModalWorkflow
{
    public static function dispatch(): never
    {
        global $mybb;
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        try {
            $method = $mybb->request_method;
            $surface = $_GET['acs_surface'] ?? '';
            $tid = $_GET['tid'] ?? '';
            if (!is_string($tid) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$tid)
                || (int)$tid > 4294967295 || !in_array($surface,['gm','builder','bar'],true)
                || !in_array($method,['get','post'],true) || ($method==='post' && $surface!=='gm')
                || (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>65536) {
                throw new RuntimeException('ACS_MODAL_REQUEST_INVALID');
            }

            // Scope and navigation come exclusively from the fragment URL.
            foreach (['tid','acs_session','acs_builder','acs_setup','acs_panel_session','acs_encounter','gm_step',
                'acs_tab','acs_atf_setup','acs_sessions_after'] as $field) {
                $mybb->input[$field] = is_string($_GET[$field] ?? null) ? $_GET[$field] : '';
            }
            if (($surface==='bar' && $mybb->get_input('acs_panel_session')==='')
                || ($surface==='builder' && $mybb->get_input('acs_builder')==='')) {
                throw new RuntimeException('ACS_MODAL_REQUEST_INVALID');
            }
            if ($surface!=='gm') {
                $mybb->input['acs_setup']=$mybb->input['acs_session']=$mybb->input['gm_step']='';
            }
            if ($surface!=='builder') $mybb->input['acs_builder']='';
            if ($surface!=='bar') $mybb->input['acs_panel_session']='';

            if ($method==='post') {
                $access=af_advancedcombatsystem_atf_access();
                $access->csrf();
                $operation=$mybb->get_input('acs_encounter_op');
                $key=$mybb->get_input('operation_key');
                $sid=$mybb->get_input('acs_session');
                $postedEncounter=$_POST['encounter_id']??'';
                if ($operation==='' || ($sid==='' && $operation!=='session_create')
                    || ($postedEncounter!=='' && $postedEncounter!==$mybb->get_input('acs_encounter')) || ($_POST['tid'] ?? null)!==$tid
                    || !preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key)
                    || ($operation==='session_create' && $mybb->get_input('acs_session')===''
                        && $key!=='atf-thread-create-'.$tid)) {
                    throw new RuntimeException('ACS_MODAL_REQUEST_INVALID');
                }
            }

            $toolbar='';
            $outcome=[];
            $html=af_advancedcombatsystem_render_thread_entry(['tid'=>(int)$tid],$toolbar,$surface,$outcome);
            if ($html==='') throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
            if ($surface==='gm') $html=af_advancedcombatsystem_gm_css().$html;
            $response=['ok'=>empty($outcome['error']),'html'=>$html];
            if (!empty($outcome['uncertain'])) $response['uncertain']=true;
            if (!$response['ok']) http_response_code(409);

            if ($method==='post' && !empty($outcome['committed'])) {
                $sid=(string)$outcome['sid'];
                $response['session_id']=$sid;
                if (!empty($outcome['eid'])) $response['encounter_id']=(string)$outcome['eid'];
                // A committed command stays confirmed even if the optional projection fails.
                try {
                    $mybb->request_method='get';
                    $mybb->input['acs_setup']=$mybb->input['acs_session']=$mybb->input['gm_step']='';
                    $mybb->input['acs_panel_session']=$sid;
                    $bar=af_advancedcombatsystem_render_thread_entry(['tid'=>(int)$tid],$toolbar,'bar');
                    if ($bar!=='') $response['bar']=$bar;
                } catch (Throwable $error) {
                    af_advancedcombatsystem_panel_failure('ACS_PANEL_VIEW_FAILED','modal_refresh');
                }
            }
        } catch (Throwable $error) {
            $known=in_array($error->getMessage(),['ACS_LOGIN_REQUIRED','ACS_THREAD_UNAVAILABLE','ACS_SESSION_UNAVAILABLE',
                'ACS_CSRF_REQUIRED','ACS_MODAL_REQUEST_INVALID','ACS_GM_REQUIRED','ACS_ENCOUNTER_UNAVAILABLE'],true);
            $response=['ok'=>false,'message'=>$known
                ? 'Инструмент ACS недоступен. Проверьте права, сессию и актуальность страницы.'
                : 'Ответ ACS не подтверждён. Перед новым действием проверьте результат прежнего запроса.'];
            if (!$known) {
                $id=af_advancedcombatsystem_panel_failure('ACS_PANEL_TOOL_FAILED','modal');
                $response['message'].=' error_id='.$id;
                $response['uncertain']=$method==='post';
            }
            http_response_code($known ? 403 : 500);
        }
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        exit;
    }
}
