<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../../Application/AuthorizationService.php';
/** Current authenticated MyBB actor, never an actor/role claimed in the request. */
class AF_ACS_SessionAccess
{
    public function uid(): string
    {
        global $mybb;
        if(isset($mybb->usergroup['canview']) && !$mybb->usergroup['canview'])throw new RuntimeException('ACS_LOGIN_REQUIRED');
        $uid=(int)($mybb->user['uid']??0);if($uid<=0)throw new RuntimeException('ACS_LOGIN_REQUIRED');return (string)$uid;
    }
    public function administrator(): bool {return (new AF_ACS_AuthorizationService)->decision('admin.view')['allowed'];}
    public function canCreate(): bool
    {
        global $mybb;
        if($this->administrator())return true;
        $groups=array_map('trim',explode(',',(string)($mybb->settings['af_acs_session_gm_groups']??'')));
        $actorGroups=explode(',',(string)($mybb->user['additionalgroups']??''));$actorGroups[]=(string)($mybb->user['usergroup']??'');
        return (bool)array_intersect(array_filter($groups,static fn($g)=>preg_match('/\A[0-9]+\z/D',$g)&&((int)$g)>0),$actorGroups);
    }
    public function csrf(): void
    {
        global $mybb;
        if($mybb->request_method!=='post')throw new RuntimeException('ACS_POST_REQUIRED');
        if(!verify_post_check($mybb->get_input('my_post_key'),true))throw new RuntimeException('ACS_CSRF_REQUIRED');
    }
    public function user(string $uid): array
    {
        $user=get_user((int)$uid);if(!$user)throw new RuntimeException('ACS_USER_UNAVAILABLE');return $user;
    }
    public function thread(string $tid, ?string $uid=null): array
    {
        global $mybb;
        $uid??=$this->uid();$thread=get_thread((int)$tid);$forum=$thread?get_forum((int)$thread['fid']):false;
        if(!$thread || !$forum || ($forum['type']??'')!=='f')throw new RuntimeException('ACS_THREAD_UNAVAILABLE');
        $permissions=forum_permissions((int)$thread['fid'],(int)$uid);
        if(empty($permissions['canview']) || empty($permissions['canviewthreads']) || (!empty($permissions['canonlyviewownthreads']) && (string)$thread['uid']!==$uid)
            || ((int)$thread['visible']!==1 && !is_moderator((int)$thread['fid'],'canviewunapprove',(int)$uid)))throw new RuntimeException('ACS_THREAD_UNAVAILABLE');
        foreach(explode(',',(string)($forum['parentlist']??$thread['fid'])) as $fid) {
            $parent=get_forum((int)$fid);$password=(string)($parent['password']??'');
            if($password!=='' && ($uid!==$this->uid() || !hash_equals(md5($uid.$password),(string)($mybb->cookies['forumpass'][(int)$fid]??''))))throw new RuntimeException('ACS_THREAD_UNAVAILABLE');
        }
        return $thread;
    }
    public function defaults(): array
    {
        global $mybb;
        $role=(string)($mybb->settings['af_acs_default_member_role']??'observer');
        if(!in_array($role,['player','observer'],true))throw new RuntimeException('ACS_DEFAULTS_INVALID');
        return ['schema_version'=>1,'default_member_role'=>$role];
    }
}
