<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
function af_advancedcombatsystem_register_thread_component(): void
{
    static $registered=false;
    if($registered||!af_is_addon_enabled('advancedcombatsystem')||!af_is_addon_enabled('adaptivethemeframework'))return;
    $registered=true;
    if(!function_exists('af_adaptivethemeframework_register_component')){af_advancedcombatsystem_panel_failure('ACS_PANEL_ATF_UNAVAILABLE','registration');return;}
    af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'combat_result','slot'=>'post.before_body','renderer'=>'af_advancedcombatsystem_combat_card']);
    if(!af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'session_link','slot'=>'thread.before_posts','renderer'=>'af_advancedcombatsystem_thread_session_link']))af_advancedcombatsystem_panel_failure('ACS_PANEL_REGISTRATION_FAILED','registration');
}
/** Native showthread context. Membership discovery stays lightweight; no encounter is created on GET. */
function af_advancedcombatsystem_thread_session_link(array $context): string
{
    global $mybb,$db;
    $uid=(int)($mybb->user['uid']??0);$tid=(int)($context['tid']??0);
    if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated'])||!$uid)return '';
    if(!$tid){af_advancedcombatsystem_panel_failure('ACS_PANEL_CONTEXT_INVALID','context');return '';}
    static $rendered=[];
    if(isset($rendered[$tid]))return '';
    $rendered[$tid]='';$statement=null;$authorized=false;$canNotify=false;$phase='discovery';
    try {
        require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';
        $access=new AF_ACS_SessionAccess;
        // Do not trust the request TID, context UID (thread author), or Appearance masks.
        $access->thread((string)$tid);
        if($mybb->get_input('acs_setup')==='1'){
            if(!headers_sent())header('Cache-Control: private, no-store');
            require_once __DIR__.'/Encounters.php';$body=AF_ACS_GmPanel::setup((string)$tid,$mybb->get_input('acs_session'));return $rendered[$tid]=af_advancedcombatsystem_presentation_css('has_acs_panel').$body;
        }
        $link=$db->write_link??null;
        if(!$link instanceof mysqli||!preg_match('/\A[A-Za-z0-9_]*\z/D',TABLE_PREFIX))throw new RuntimeException('ACS_PANEL_STORAGE_UNAVAILABLE');
        $builder=$mybb->get_input('acs_builder');$gm=$mybb->get_input('acs_session');
        $selected=$builder!==''?$builder:($gm!==''?$gm:$mybb->get_input('acs_panel_session'));
        $after=$mybb->get_input('acs_sessions_after')?:'0';
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$after))$after='0';
        $validSelection=$selected===''||preg_match('/\A[1-9][0-9]{0,19}\z/D',$selected);
        // Enumerate only current memberships for this native topic; do not inspect every session/encounter.
        $sql='SELECT s.id,s.owner_uid,s.status,r.role FROM `'.TABLE_PREFIX.'af_acs_sessions` s JOIN `'.TABLE_PREFIX.'af_acs_session_roles` r ON r.session_id=s.id WHERE s.tid=? AND r.uid=? AND r.role IN (\'owner\',\'gm\',\'assistant_gm\',\'player\',\'observer\') AND (r.expires_at IS NULL OR r.expires_at>?)';
        $statement=$link->prepare($sql.' AND s.id>? ORDER BY s.id LIMIT 101');
        if(!$statement)throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
        $now=time();$statement->bind_param('iiis',$tid,$uid,$now,$after);
        if(!$statement->execute()||!($result=$statement->get_result()))throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
        $sessions=[];while($row=$result->fetch_assoc())$sessions[(string)$row['id']]=$row;
        $statement->close();$statement=null;
        $more=count($sessions)>100;if($more)array_pop($sessions);
        $next=$more?(string)end($sessions)['id']:null;
        $chosen=$validSelection&&$selected!==''?($sessions[$selected]??null):null;
        if($validSelection&&$selected!==''&&$chosen===null){
            $statement=$link->prepare($sql.' AND s.id=? LIMIT 1');
            if(!$statement)throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
            $statement->bind_param('iiis',$tid,$uid,$now,$selected);
            if(!$statement->execute()||!($result=$statement->get_result()))throw new RuntimeException('ACS_PANEL_DISCOVERY_FAILED');
            $chosen=$result->fetch_assoc()?:null;$statement->close();$statement=null;
        }
        if(!$sessions&&$chosen===null)return $access->canCreate()?'<p><a href="showthread.php?tid='.htmlspecialchars_uni((string)$tid).'&amp;acs_setup=1">Создать столкновение ACS</a></p>':'';
        $authorized=true;
        if(!headers_sent())header('Cache-Control: private, no-store');
        require_once __DIR__.'/ThreadPanel.php';
        // Multiple sessions require a deliberate choice. An invalid/stale choice never falls back silently.
        if($selected===''&&count($sessions)===1&&!$more&&$after==='0')$chosen=reset($sessions);
        if($chosen)$sessions[(string)$chosen['id']]=$chosen;
        $choice=AF_ACS_ThreadPanel::sessions($sessions,(string)$tid,$chosen?(string)$chosen['id']:null,$next,$after);
        if($chosen===null){
            $notice=$selected!==''?'Выбранная сессия недоступна. Выберите доступную сессию.':'Выберите сессию ACS для этой темы.';
            return $rendered[$tid]=af_advancedcombatsystem_presentation_css('has_acs_panel').'<aside class="af-acs-panel">'.htmlspecialchars_uni($notice).$choice.'</aside>';
        }
        $sid=(string)$chosen['id'];
        $canNotify=$chosen['role']==='gm'||$chosen['role']==='owner'&&(string)$chosen['owner_uid']===(string)$uid;
        $gmHtml='';if($builder===''&&$gm===$sid&&$canNotify){try{require_once __DIR__.'/Encounters.php';$gmHtml=AF_ACS_EncountersPage::render($sid,(string)$tid);}catch(Throwable $error){$errorId=af_advancedcombatsystem_panel_failure('ACS_PANEL_TOOL_FAILED','gm');$gmHtml='<p role="alert">Панель GM временно недоступна. Проверьте диагностику. error_id='.htmlspecialchars_uni($errorId).'</p>';}}
        $phase='view';
        require_once __DIR__.'/../Infrastructure/ThreadPanelQuery.php';
        $panel=(new AF_ACS_ThreadPanelQuery($db))->current($sid,(string)$tid);
        $html=AF_ACS_ThreadPanel::render($panel,(string)$tid);
        if($canNotify)$html.='<p><a href="showthread.php?tid='.htmlspecialchars_uni((string)$tid).'&amp;acs_session='.htmlspecialchars_uni($sid).'&amp;acs_tab=overview">Панель GM и переходы сессии</a></p>';
        $phase='assets';
        $html=af_advancedcombatsystem_presentation_css('has_acs_panel').$choice.$html.af_advancedcombatsystem_panel_script();
        if($canNotify)foreach($GLOBALS['af_acs_panel_asset_failures']??[] as $failure)$html.='<p role="status">'.htmlspecialchars_uni('Ассеты ACS недоступны. Проверьте настройки ассетов AF и диагностику ACP. '.$failure).'</p>';
        // A failed explicit tool must not discard the already-rendered read-only panel.
        try{
            if($builder===$sid){require_once __DIR__.'/BuilderController.php';$html.=AF_ACS_BuilderController::renderBuilder($sid,(string)$tid);}
            $html.=$gmHtml;
        }catch(Throwable $error){
            $id=af_advancedcombatsystem_panel_failure('ACS_PANEL_TOOL_FAILED','tools');
            $html.='<p role="status">'.htmlspecialchars_uni($canNotify?'Инструмент ACS недоступен. Проверьте диагностику ACP. error_id='.$id:'Инструмент ACS временно недоступен. Обратитесь к GM.').'</p>';
        }
        return $rendered[$tid]=$html;
    }catch(Throwable $error){
        if(in_array($error->getMessage(),['ACS_THREAD_UNAVAILABLE','ACS_LOGIN_REQUIRED','ACS_SESSION_UNAVAILABLE'],true))return '';
        $code=match($phase){'discovery'=>'ACS_PANEL_DISCOVERY_FAILED','assets'=>'ACS_PANEL_ASSET_FAILED',default=>'ACS_PANEL_VIEW_FAILED'};
        // Preserve only machine codes; SQL/errors containing arbitrary text are never retained.
        if(preg_match('/\AACS_[A-Z_]{1,70}\z/D',$error->getMessage()))$code=$error->getMessage();
        $id=af_advancedcombatsystem_panel_failure($code,$phase);
        // No membership evidence => no HTML revealing that an ACS session might exist here.
        if(!$authorized)return '';
        $message=$canNotify?'Панель ACS временно недоступна. Проверьте диагностику ACS в ACP. '.$code.' · error_id='.$id:'Сведения ACS временно недоступны. Обратитесь к GM сессии.';
        return $rendered[$tid]='<aside class="af-acs" role="status">'.htmlspecialchars_uni($message).'</aside>';
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
    $sent=true;$url=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/presentation.css?v=1121';
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
    $sent=true;$url=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/thread-panel.js?v=1111b';
    return '<script defer src="'.htmlspecialchars_uni($url).'"></script>';
}
