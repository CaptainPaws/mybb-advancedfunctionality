<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Deliberate staging opt-in, independent of the permanently false production capability. */
final class AF_ACS_PublicationTestGate
{
    public static function allowed(): bool
    {
        global $mybb;
        if (!defined('AF_ACS_ENVIRONMENT') || AF_ACS_ENVIRONMENT!=='test'
            || !defined('AF_ACS_PUBLICATION_TEST_UIDS') || !is_array(AF_ACS_PUBLICATION_TEST_UIDS)
            || !defined('AF_ACS_PUBLICATION_TEST_ORIGIN') || !is_string(AF_ACS_PUBLICATION_TEST_ORIGIN)
            || !af_is_addon_enabled('advancedcombatsystem') || !af_is_addon_enabled('advancededitor') || !empty($GLOBALS['af_acs_deactivated'])) { return false; }
        $uid=(string)(int)($mybb->user['uid']??0);
        if ($uid==='0' || !in_array($uid,array_map('strval',AF_ACS_PUBLICATION_TEST_UIDS),true)) { return false; }
        $origin=rtrim(AF_ACS_PUBLICATION_TEST_ORIGIN,'/');$url=parse_url($origin);$forum=parse_url((string)($mybb->settings['bburl']??''));
        if (!$url || !$forum || !in_array($url['scheme']??'',['http','https'],true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path'])) { return false; }
        $host=strtolower($url['host']).(isset($url['port'])?':'.$url['port']:'');
        $forumOrigin=($forum['scheme']??'').'://'.strtolower($forum['host']??'').(isset($forum['port'])?':'.$forum['port']:'');
        return $origin===$forumOrigin && strtolower((string)($_SERVER['HTTP_HOST']??''))===$host
            && (!isset($_SERVER['HTTP_ORIGIN']) || $_SERVER['HTTP_ORIGIN']===$origin);
    }
}
