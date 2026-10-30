<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
function af_advancedcombatsystem_register_thread_component(): void
{
    if(!af_is_addon_enabled('advancedcombatsystem') || !af_is_addon_enabled('adaptivethemeframework') || !function_exists('af_adaptivethemeframework_register_component'))return;
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
        $selected=$mybb->get_input('acs_session');
        $filter=$selected!==''?' AND s.id=?':'';
        if($selected!=='' && !preg_match('/\A[1-9][0-9]{0,19}\z/D',$selected))return '';
        $statement=$link->prepare('SELECT s.id,r.role FROM `'.TABLE_PREFIX.'af_acs_sessions` s JOIN `'.TABLE_PREFIX.'af_acs_session_roles` r ON r.session_id=s.id WHERE s.tid=? AND r.uid=? AND r.role IN (\'owner\',\'gm\',\'assistant_gm\') AND (r.expires_at IS NULL OR r.expires_at>?) '.$filter.' ORDER BY s.id LIMIT 10');
        if(!$statement)return '';
        $now=time();
        if($selected!=='')$statement->bind_param('iiis',$tid,$uid,$now,$selected);else $statement->bind_param('iii',$tid,$uid,$now);
        if(!$statement->execute())return '';
        $links=[];$rows=$statement->get_result();if(!$rows)return '';
        while($row=$rows->fetch_assoc()) {
            $id=htmlspecialchars_uni((string)$row['id']);
            $links[]='<a href="misc.php?action=acs_sessions&amp;session_id='.$id.'">Сессия ACS #'.$id.'</a>';
            if(in_array($row['role'],['owner','gm'],true)) {
                $links[]='<a href="showthread.php?tid='.$tid.'&amp;acs_session='.$id.'">Панель GM</a>';
                if($selected===(string)$row['id']) {
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
