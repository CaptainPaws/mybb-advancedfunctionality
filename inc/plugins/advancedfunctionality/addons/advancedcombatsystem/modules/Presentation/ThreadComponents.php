<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
function af_advancedcombatsystem_register_thread_component(): void
{
    static $registered=false;
    if($registered||!af_is_addon_enabled('advancedcombatsystem')||!af_is_addon_enabled('adaptivethemeframework'))return;
    $registered=true;
    if(!function_exists('af_adaptivethemeframework_register_component')){af_advancedcombatsystem_panel_failure('ACS_PANEL_ATF_UNAVAILABLE','registration');return;}
    af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'combat_result','slot'=>'post.before_body','renderer'=>'af_advancedcombatsystem_combat_card']);
    $panel = af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'session_link','slot'=>'thread.before_quick_reply','renderer'=>'af_advancedcombatsystem_thread_session_link']);
    $toolbar = af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'toolbar_action','slot'=>'thread.toolbar_actions','renderer'=>'af_advancedcombatsystem_thread_toolbar']);
    if (!$panel || !$toolbar) af_advancedcombatsystem_panel_failure('ACS_PANEL_REGISTRATION_FAILED','registration');
}

function af_advancedcombatsystem_thread_session_link(array $context): string
{
    $entry = af_advancedcombatsystem_thread_entry($context);
    return $entry['panel'] === '' ? '' : '<div id="acs-thread-panel" tabindex="-1">'.$entry['panel'].'</div>';
}

function af_advancedcombatsystem_thread_toolbar(array $context): string
{
    return af_advancedcombatsystem_thread_entry($context)['toolbar'];
}

/** Both theme slots share one authorized projection per native thread. */
function af_advancedcombatsystem_thread_entry(array $context): array
{
    static $entries = [];
    $tid = (int)($context['tid'] ?? 0);
    if (!isset($entries[$tid])) {
        $toolbar = '';
        $panel = af_advancedcombatsystem_render_thread_entry($context, $toolbar);
        $entries[$tid] = ['panel'=>$panel, 'toolbar'=>$toolbar];
    }
    return $entries[$tid];
}

function af_advancedcombatsystem_thread_button(string $url, string $label): string
{
    $label = htmlspecialchars_uni($label);
    $builder = str_contains($url, 'acs_builder=');
    $modal = str_contains($url, 'acs_setup=1') || str_contains($url, 'acs_session=');
    return '<a '.($builder ? 'data-acs-modal="builder" ' : ($modal ? 'data-acs-modal="gm" ' : '')).'class="atf-button atf-button--secondary atf-thread__reply-action" href="'.htmlspecialchars_uni($url)
        .'" aria-label="'.$label.'" title="'.$label.'">'
        .'<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
        .'<path d="m13.5 10.5 6-6H22V7l-6 6M2 22l5-5m-2-2 4 4m1.5-5.5L4 7V4h3l11.5 11.5M22 22l-5-5m2-2-4 4"/></svg></a>';
}

/** Native showthread context. Creation is available only for a confirmed combat topic. */
function af_advancedcombatsystem_render_thread_entry(array $context, string &$toolbar, string $surface = 'thread', array &$outcome = []): string
{
    global $mybb,$db;
    $uid=(int)($mybb->user['uid']??0);$tid=(int)($context['tid']??0);
    if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated'])||!$uid)return '';
    if(!$tid){af_advancedcombatsystem_panel_failure('ACS_PANEL_CONTEXT_INVALID','context');return '';}
    $statement=null;$authorized=false;$canNotify=false;$phase='discovery';
    try {
        require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';
        $access=new AF_ACS_SessionAccess;
        $thread=$access->thread((string)$tid);
        $fid=(int)$thread['fid'];
        $repo=af_advancedcombatsystem_atf_mode_repository();
        $mode=$repo->episodeMode((string)$tid)['mode']??null;
        if ($mode==='normal') return '';

        $forumEnabled=function_exists('af_atf_acs_forum_enabled') && af_atf_acs_forum_enabled($fid);
        $combat=$mode==='combat' || ($mode===null && $forumEnabled
            && function_exists('af_atf_acs_has_topic_intent') && af_atf_acs_has_topic_intent($tid));
        $builder=$mybb->get_input('acs_builder');
        $gm=$mybb->get_input('acs_session');
        $setup=$mybb->get_input('acs_setup')==='1' || $mybb->get_input('gm_step')!=='';
        $selected=$setup?$gm:($builder!==''?$builder:($gm!==''?$gm:$mybb->get_input('acs_panel_session')));
        $validSelection=$selected===''||preg_match('/\A[1-9][0-9]{0,19}\z/D',$selected);
        // Historical sessions need an explicit ID. An old topic alone grants no creation path.
        if (!$combat && ($mode!==null || $selected==='' || !$validSelection)) return '';
        $fragment = $surface !== 'thread';
        $base='showthread.php?tid='.$tid;
        $setupUrl=$base.'&acs_setup=1&acs_atf_setup=1#acs-thread-panel';
        $link=$db->write_link??null;
        if(!$link instanceof mysqli||!preg_match('/\A[A-Za-z0-9_]*\z/D',TABLE_PREFIX))throw new RuntimeException('ACS_PANEL_STORAGE_UNAVAILABLE');
        $after=$mybb->get_input('acs_sessions_after')?:'0';
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$after))$after='0';
        // Group memberships so every authorized session occupies exactly one selector entry.
        $sql="SELECT s.id,s.status,MAX(r.role='gm' OR (r.role='owner' AND s.owner_uid=r.uid)) AS can_manage
            FROM `".TABLE_PREFIX."af_acs_sessions` s JOIN `".TABLE_PREFIX."af_acs_session_roles` r ON r.session_id=s.id
            WHERE s.tid=? AND r.uid=? AND r.role IN ('owner','gm','assistant_gm','player','observer')
            AND (r.expires_at IS NULL OR r.expires_at>?)";
        $group=' GROUP BY s.id,s.status';
        $statement=$link->prepare($sql.' AND s.id>?'.$group.' ORDER BY s.id LIMIT 101');
        if(!$statement)throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
        $now=time();$statement->bind_param('iiis',$tid,$uid,$now,$after);
        if(!$statement->execute()||!($result=$statement->get_result()))throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
        $sessions=[];while($row=$result->fetch_assoc())$sessions[(string)$row['id']]=$row;
        $statement->close();$statement=null;
        $more=count($sessions)>100;if($more)array_pop($sessions);
        $next=$more?(string)end($sessions)['id']:null;
        $chosen=$validSelection&&$selected!==''?($sessions[$selected]??null):null;
        if($validSelection&&$selected!==''&&$chosen===null){
            $statement=$link->prepare($sql.' AND s.id=?'.$group.' LIMIT 1');
            if(!$statement)throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
            $statement->bind_param('iiis',$tid,$uid,$now,$selected);
            if(!$statement->execute()||!($result=$statement->get_result()))throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
            $chosen=$result->fetch_assoc()?:null;$statement->close();$statement=null;
        }
        if (!$combat && $chosen===null) return '';
        if (!$sessions && $chosen===null) {
            if ($selected!=='' || $after!=='0' || !$forumEnabled || (int)$thread['visible']!==1
                || !af_advancedcombatsystem_atf_can_create($fid) || $repo->hasTopicSession((string)$tid)) return '';
            if (!headers_sent()) header('Cache-Control: private, no-store');
            if (!$setup) {
                if ($fragment) return '';
                $toolbar=af_advancedcombatsystem_thread_button($setupUrl,'Настроить бой');
                require_once __DIR__.'/ThreadPanel.php';
                return af_advancedcombatsystem_presentation_css('has_acs_panel').af_advancedcombatsystem_panel_script()
                    .AF_ACS_ThreadPanel::missing($setupUrl);
            }
            require_once __DIR__.'/Encounters.php';
            $body=AF_ACS_GmPanel::setup((string)$tid,$outcome);
            $toolbar=af_advancedcombatsystem_thread_button('#acs-thread-panel','Настроить бой');
            AF_ACS_Context::mark(AF_ACS_Context::INTERACTIVE);
            return ($fragment ? '' : af_advancedcombatsystem_gm_css()).$body;
        }
        $authorized=true;
        if(!headers_sent())header('Cache-Control: private, no-store');
        require_once __DIR__.'/ThreadPanel.php';
        // Multiple sessions require a deliberate choice. An invalid/stale choice never falls back silently.
        if($selected===''&&count($sessions)===1&&!$more&&$after==='0')$chosen=reset($sessions);
        if ($setup && $gm==='' && $chosen!==null && !empty($chosen['can_manage'])) {
            $url=$base.'&acs_session='.$chosen['id'].'&acs_setup=1#acs-thread-panel';
            $toolbar=af_advancedcombatsystem_thread_button($url,'Настроить бой');
            if ($surface==='gm') {
                require_once __DIR__.'/Encounters.php';
                if ($mybb->request_method==='post') {
                    // The creation service still checks the original per-actor receipt and key.
                    return AF_ACS_GmPanel::setup((string)$tid,$outcome);
                }
                $mybb->input['gm_stage']='settings';
                return AF_ACS_EncountersPage::render((string)$chosen['id'],(string)$tid,outcome:$outcome);
            }
            return '<p role="status"><a data-acs-modal="gm" href="'.htmlspecialchars_uni($url).'">Открыть существующую сессию</a></p>';
        }
        if($chosen)$sessions[(string)$chosen['id']]=$chosen;
        $choice=AF_ACS_ThreadPanel::sessions($sessions,(string)$tid,$chosen?(string)$chosen['id']:null,$next,$after);
        if($chosen===null){
            if ($fragment) return '';
            if ($setup && $gm!=='') return '';
            $toolbar=af_advancedcombatsystem_thread_button('#acs-thread-panel','Панель боя');
            $notice=$selected!==''?'Выбранная сессия недоступна. Выберите доступную сессию.':'Выберите сессию ACS для этой темы.';
            return af_advancedcombatsystem_presentation_css('has_acs_panel').'<aside class="af-acs-combat-bar">'.htmlspecialchars_uni($notice).$choice.'</aside>';
        }
        $sid=(string)$chosen['id'];
        $canNotify=(bool)$chosen['can_manage'];
        if ($gm===$sid && ($setup || $builder==='') && $canNotify) {
            $phase='gm';
            require_once __DIR__.'/Encounters.php';
            $body=AF_ACS_EncountersPage::render($sid,(string)$tid,outcome:$outcome);
            if ($combat) $toolbar=af_advancedcombatsystem_thread_button('#acs-thread-panel','Панель боя');
            AF_ACS_Context::mark(AF_ACS_Context::INTERACTIVE);
            return ($fragment ? '' : af_advancedcombatsystem_gm_css()).$body;
        }
        if ($surface==='gm' || $setup) return '';
        if ($surface==='builder') {
            require_once __DIR__.'/BuilderController.php';
            return AF_ACS_BuilderController::renderBuilder($sid,(string)$tid);
        }
        $phase='view';
        require_once __DIR__.'/../Infrastructure/ThreadPanelQuery.php';
        $panel=(new AF_ACS_ThreadPanelQuery($db))->current($sid,(string)$tid);
        $hasEncounter=$panel['encounter_id']!==null;
        if ($combat) {
            $toolbar=$hasEncounter || !$canNotify || !$panel['can_manage']
                ? af_advancedcombatsystem_thread_button(
                    $canNotify ? AF_ACS_ThreadPanel::gmUrl($panel,(string)$tid)
                        : (AF_ACS_ThreadPanel::builderUrl($panel,(string)$tid) ?? '#acs-thread-panel'),
                    'Панель боя')
                : af_advancedcombatsystem_thread_button($base.'&acs_session='.$sid.'&acs_setup=1#acs-thread-panel','Настроить бой');
        }
        AF_ACS_Context::mark(AF_ACS_Context::VIEW);
        $html=AF_ACS_ThreadPanel::render($panel,(string)$tid,$canNotify);
        if ($surface==='bar') return $html;
        $phase='assets';
        $html=af_advancedcombatsystem_presentation_css('has_acs_panel').$choice.$html;
        if ($builder==='' && $mybb->get_input('acs_draft')==='' && ($canNotify || AF_ACS_ThreadPanel::builderUrl($panel,(string)$tid)!==null)) $html.=af_advancedcombatsystem_panel_script();
        if($canNotify)foreach($GLOBALS['af_acs_panel_asset_failures']??[] as $failure)$html.='<p role="status">'.htmlspecialchars_uni('Ассеты ACS недоступны. Проверьте настройки ассетов AF и диагностику ACP. '.$failure).'</p>';
        // A failed explicit tool must not discard the already-rendered read-only panel.
        try{
            if($builder===$sid){require_once __DIR__.'/BuilderController.php';$html.=AF_ACS_BuilderController::renderBuilder($sid,(string)$tid);}
        }catch(Throwable $error){
            $id=af_advancedcombatsystem_panel_failure('ACS_PANEL_TOOL_FAILED','tools');
            $html.='<p role="status">'.htmlspecialchars_uni($canNotify?'Инструмент ACS недоступен. Проверьте диагностику ACP. error_id='.$id:'Инструмент ACS временно недоступен. Обратитесь к GM.').'</p>';
        }
        return $html;
    }catch(Throwable $error){
        if ($surface!=='thread') throw $error;
        if(in_array($error->getMessage(),['ACS_THREAD_UNAVAILABLE','ACS_LOGIN_REQUIRED','ACS_SESSION_UNAVAILABLE'],true))return '';
        $code=match($phase){'discovery'=>'ACS_PANEL_DISCOVERY_FAILED','assets'=>'ACS_PANEL_ASSET_FAILED','gm'=>'ACS_PANEL_TOOL_FAILED',default=>'ACS_PANEL_VIEW_FAILED'};
        // Preserve only machine codes; SQL/errors containing arbitrary text are never retained.
        if(preg_match('/\AACS_[A-Z_]{1,70}\z/D',$error->getMessage()))$code=$error->getMessage();
        $id=af_advancedcombatsystem_panel_failure($code,$phase);
        // No membership evidence => no HTML revealing that an ACS session might exist here.
        if(!$authorized)return '';
        $message=$canNotify?'Панель ACS временно недоступна. Проверьте диагностику ACS в ACP. '.$code.' · error_id='.$id:'Сведения ACS временно недоступны. Обратитесь к GM сессии.';
        return '<aside class="af-acs" role="status">'.htmlspecialchars_uni($message).'</aside>';
    }finally{if($statement)$statement->close();}
}

/** Existing AF diagnostic cache + server log, safe identifiers only; never a database write on GET. */
function af_advancedcombatsystem_panel_failure(string $code,string $phase): string
{
    static $errors=[];$key=$code.':'.$phase;
    if(isset($errors[$key]))return $errors[$key];
    $id=$errors[$key]=bin2hex(random_bytes(16));
    $safe=$code.' [error_id='.$id.']';
    if($code==='ACS_PANEL_ASSET_DENIED')$GLOBALS['af_acs_panel_asset_failures'][$phase]=$safe;
    if(function_exists('af_store_addon_lifecycle_diagnostic')){
        try{af_store_addon_lifecycle_diagnostic('advancedcombatsystem','thread_panel',$phase,new RuntimeException($safe));}catch(Throwable $error){}
    }
    error_log('ACS thread_panel '.$safe.' phase='.$phase);
    return $id;
}

/** No use of context uid/Appearance: only authoritative PID/TID and the current native viewer. */
function af_advancedcombatsystem_combat_card(array $context): string
{
    global $mybb,$db,$pids;
    if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated'])||empty($mybb->user['uid']))return '';
    $pid=(string)($context['pid']??'');$tid=(string)($context['tid']??'');
    if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$pid)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$tid))return '';
    try {
        require_once __DIR__.'/../Infrastructure/CombatCardQuery.php';require_once __DIR__.'/CombatCard.php';
        $ids=is_string($pids??null)?explode(',',$pids):[];
        $ids=array_values(array_filter($ids,static fn($id)=>preg_match('/\A[1-9][0-9]{0,9}\z/D',$id)));
        $ids[]=$pid;$ids=array_values(array_unique($ids));sort($ids,SORT_STRING);
        $chunk=intdiv((int)array_search($pid,$ids,true),100);$ids=array_slice($ids,$chunk*100,100);
        static $cache=[];$query=new AF_ACS_CombatCardQuery($db);$key=$query->audienceCacheKey($tid,new AF_ACS_SessionAccess).':'.implode(',',$ids);
        if(!isset($cache[$key]))$cache[$key]=$query->forPosts($tid,$ids);
        if(!isset($cache[$key][$pid]))return '';
        $html=AF_ACS_CombatCard::render($cache[$key][$pid]);
        return af_advancedcombatsystem_presentation_css('has_acs_card').$html;
    } catch(Throwable $error) {if(isset($key))$cache[$key]=[];return '';}
}
function af_advancedcombatsystem_presentation_css(string $fact): string
{
    global $mybb;
    static $sent=false;
    if($sent)return '';
    if(!function_exists('af_frontend_asset_allowed')||!af_frontend_asset_allowed('advancedcombatsystem','presentation',null,[$fact=>true])){
        if($fact==='has_acs_panel')af_advancedcombatsystem_panel_failure('ACS_PANEL_ASSET_DENIED','css');
        return '';
    }
    $sent=true;$url=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/presentation.css?v=1200';
    return '<link rel="stylesheet" href="'.htmlspecialchars_uni($url).'">';
}

/** Only a successfully authorized panel may request its enhancement. */
function af_advancedcombatsystem_panel_script(): string
{
    global $mybb;
    static $sent=false;
    if($sent)return '';
    if(!function_exists('af_frontend_asset_allowed')||!af_frontend_asset_allowed('advancedcombatsystem','thread_panel',null,['has_acs_panel'=>true])){
        af_advancedcombatsystem_panel_failure('ACS_PANEL_ASSET_DENIED','javascript');return '';
    }
    $sent=true;$url=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/thread-panel.js?v=1302';
    return '<script defer src="'.htmlspecialchars_uni($url).'"></script>';
}

function af_advancedcombatsystem_gm_css(): string
{
    global $mybb;
    if (!function_exists('af_frontend_asset_allowed') || !af_frontend_asset_allowed('advancedcombatsystem','presentation',null,['has_acs_panel'=>true])) return '';
    $url = rtrim($mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/modal-gm.css?v=1202';
    return '<link rel="stylesheet" href="'.htmlspecialchars_uni($url).'">';
}
