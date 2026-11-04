<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
function af_advancedcombatsystem_register_thread_component(): void
{
    if(!af_is_addon_enabled('advancedcombatsystem') || !af_is_addon_enabled('adaptivethemeframework') || !function_exists('af_adaptivethemeframework_register_component'))return;
    af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'combat_result','slot'=>'post.before_body','renderer'=>'af_advancedcombatsystem_combat_card']);
    af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'session_link','slot'=>'thread.before_posts','renderer'=>'af_advancedcombatsystem_thread_session_link']);
}
/** Native showthread context. Only an explicitly opened, authorized panel loads the GM stack. */
function af_advancedcombatsystem_thread_session_link(array $context): string
{
    global $mybb,$db;
    $uid=(int)($mybb->user['uid']??0);$tid=(int)($context['tid']??0);
    if(!af_is_addon_enabled('advancedcombatsystem') || !$uid || !$tid)return '';
    $link=$db->write_link??null;
    if(!$link instanceof mysqli || !preg_match('/\A[A-Za-z0-9_]*\z/D',TABLE_PREFIX))return '';
    // Optional slot failures must not invoke MyBB's fatal SQL error renderer.
    $statement=null;
    try {
        $builder=$mybb->get_input('acs_builder');
        $selected=$builder!==''?$builder:$mybb->get_input('acs_session');
        $filter=$selected!==''?' AND s.id=?':'';
        if($selected!=='' && !preg_match('/\A[1-9][0-9]{0,19}\z/D',$selected))return '';
        $statement=$link->prepare('SELECT s.id,r.role FROM `'.TABLE_PREFIX.'af_acs_sessions` s JOIN `'.TABLE_PREFIX.'af_acs_session_roles` r ON r.session_id=s.id WHERE s.tid=? AND r.uid=? AND r.role IN (\'owner\',\'gm\',\'assistant_gm\',\'player\') AND (r.expires_at IS NULL OR r.expires_at>?) '.$filter.' ORDER BY s.id LIMIT 10');
        if(!$statement)return '';
        $now=time();
        if($selected!=='')$statement->bind_param('iiis',$tid,$uid,$now,$selected);else $statement->bind_param('iii',$tid,$uid,$now);
        if(!$statement->execute())return '';
        $links=[];$rows=$statement->get_result();if(!$rows)return '';
        while($row=$rows->fetch_assoc()) {
            $id=htmlspecialchars_uni((string)$row['id']);
            $links[]='<a href="misc.php?action=acs_sessions&amp;session_id='.$id.'">Сессия ACS #'.$id.'</a>';
            $links[]='<a href="showthread.php?tid='.$tid.'&amp;acs_builder='.$id.'">Подготовить ход ACS</a>';
            if($builder===(string)$row['id']) {
                require_once __DIR__.'/BuilderController.php';
                if(!headers_sent())header('Cache-Control: private, no-store');
                $links[]=AF_ACS_BuilderController::renderBuilder((string)$row['id'],(string)$tid);
            }
            if(in_array($row['role'],['owner','gm'],true)) {
                $links[]='<a href="misc.php?action=acs_recovery&amp;session_id='.$id.'">Восстановление публикаций</a>';
                $links[]='<a href="showthread.php?tid='.$tid.'&amp;acs_session='.$id.'">Панель GM</a>';
                if($builder===''&&$selected===(string)$row['id']) {
                    require_once __DIR__.'/Encounters.php';
                    if(!headers_sent())header('Cache-Control: private, no-store');
                    $links[]=AF_ACS_EncountersPage::render((string)$row['id'],(string)$tid);
                }
            }
        }
        return $links?'<aside class="af-acs">'.implode(' · ',$links).'</aside>':'';
    } catch(Throwable $error) {return isset($selected) && $selected!==''?'<aside class="af-acs">Панель GM недоступна. Проверьте права и версию схемы ACS.</aside>':'';}
    finally {if($statement)$statement->close();}
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
        static $cache=[];$key=(string)$mybb->user['uid'].':'.$tid.':'.implode(',',$ids);
        if(!isset($cache[$key]))$cache[$key]=(new AF_ACS_CombatCardQuery($db))->forPosts($tid,$ids);
        if(!isset($cache[$key][$pid]))return '';
        $html=AF_ACS_CombatCard::render($cache[$key][$pid]);
        return af_advancedcombatsystem_presentation_css('has_acs_card').$html;
    } catch(Throwable $error) {if(isset($key))$cache[$key]=[];return '';}
}
function af_advancedcombatsystem_presentation_css(string $fact): string
{
    global $mybb;
    static $sent=false;
    if($sent||!function_exists('af_frontend_asset_allowed')||!af_frontend_asset_allowed('advancedcombatsystem','presentation',null,[$fact=>true]))return '';
    $sent=true;$url=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/presentation.css?v=1';
    return '<link rel="stylesheet" href="'.htmlspecialchars_uni($url).'">';
}
