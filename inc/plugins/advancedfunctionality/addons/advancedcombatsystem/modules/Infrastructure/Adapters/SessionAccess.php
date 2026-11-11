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
    /** Preparing a combat reply does not bypass native posting restrictions. */
    public function draftThread(string $tid): void
    {
        global $mybb;
        $thread=$this->thread($tid);$fid=(int)($thread['fid']??0);
        $permissions=forum_permissions($fid,(int)$this->uid());
        if(!$fid||empty($permissions['canpostreplys'])||!empty($mybb->user['suspendposting'])
            || (!empty($permissions['canonlyreplyownthreads'])&&(string)($thread['uid']??'')!==$this->uid())
            || (!empty($thread['closed'])&&!is_moderator($fid,'canpostclosedthreads')))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
    }
    /** Only native explicit admin permission or a persisted owner-approved quest rule. */
    public function delegationReplacement(array $session,string $owner,array $ids,?string $group,array $actions): ?array
    {
        global $mybb;
        if(defined('IN_ADMINCP') && IN_ADMINCP && !empty($mybb->usergroup['cancp'])
            && in_array($mybb->admin['permissions']['advancedfunctionality']['acs_delegation_override']??null,[1,'1',true],true))return ['kind'=>'native_admin','permission'=>'acs_delegation_override','actor_uid'=>$this->uid()];
        $config=json_decode($session['config_json'],true,32,JSON_THROW_ON_ERROR);
        foreach($config['delegation_replacement_rules']??[] as $rule){
            if(($rule['schema_version']??null)!==1 || (string)($rule['approved_by_uid']??'')!==$owner || !is_string($rule['rule_id']??null)
                || !preg_match('/\A[a-z0-9_.:-]{1,96}\z/D',$rule['rule_id']) || (int)($rule['valid_from']??PHP_INT_MAX)>time() || (int)($rule['valid_until']??0)<=time()
                || !is_array($rule['permitted_actions']??null) || array_diff($actions,$rule['permitted_actions']))continue;
            if($group!==null ? ($rule['control_group_id']??null)!==$group : !is_array($rule['entity_ids']??null)||array_diff($ids,$rule['entity_ids']))continue;
            return ['kind'=>'quest_rule','rule_id'=>$rule['rule_id'],'approved_by_uid'=>$owner,'valid_until'=>(string)$rule['valid_until']];
        }
        return null;
    }
    public function defaults(): array
    {
        global $mybb;
        $role=(string)($mybb->settings['af_acs_default_member_role']??'observer');
        if(!in_array($role,['player','observer'],true))throw new RuntimeException('ACS_DEFAULTS_INVALID');
        return ['schema_version'=>1,'default_member_role'=>$role];
    }
}
