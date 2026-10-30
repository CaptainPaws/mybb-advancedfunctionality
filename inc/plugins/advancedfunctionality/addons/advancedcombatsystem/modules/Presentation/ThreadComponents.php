<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
function af_advancedcombatsystem_register_thread_component(): void
{
    if(!af_is_addon_enabled('advancedcombatsystem') || !af_is_addon_enabled('adaptivethemeframework') || !function_exists('af_adaptivethemeframework_register_component'))return;
    af_adaptivethemeframework_register_component(['owner'=>'advancedcombatsystem','key'=>'session_link','slot'=>'thread.before_posts','renderer'=>'af_advancedcombatsystem_thread_session_link']);
}
/** Already permission-filtered native showthread context; no command stack/modules. */
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
        $statement=$link->prepare('SELECT s.id FROM `'.TABLE_PREFIX.'af_acs_sessions` s JOIN `'.TABLE_PREFIX.'af_acs_session_roles` r ON r.session_id=s.id WHERE s.tid=? AND r.uid=? AND r.role IN (\'owner\',\'gm\',\'assistant_gm\') AND (r.expires_at IS NULL OR r.expires_at>?) ORDER BY s.id LIMIT 10');
        if(!$statement)return '';
        $now=time();$statement->bind_param('iii',$tid,$uid,$now);
        if(!$statement->execute())return '';
        $links=[];$rows=$statement->get_result();if(!$rows)return '';
        while($row=$rows->fetch_assoc()) {$id=htmlspecialchars_uni((string)$row['id']);$links[]='<a href="misc.php?action=acs_sessions&amp;session_id='.$id.'">Сессия ACS #'.$id.'</a>';}
        return $links?'<aside class="af-acs">'.implode(' · ',$links).'</aside>':'';
    } catch(Throwable $error) {return '';}
    finally {if($statement)$statement->close();}
}
