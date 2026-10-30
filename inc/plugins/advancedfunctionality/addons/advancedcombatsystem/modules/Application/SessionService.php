<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/SessionRepository.php';
require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';

final class AF_ACS_SessionService
{
    public function __construct(private AF_ACS_SessionRepository $repository, private AF_ACS_SessionAccess $access) {}
    public function view(string $id): array
    {
        $session=$this->repository->session(AF_ACS_Repository::integer($id));
        $this->authorize($session,false);
        return ['session'=>$session,'members'=>$this->repository->members($id),'can_manage'=>$this->canManage($session)];
    }
    private function canManage(array $session): bool
    {
        if($session['status']!=='active')return false;
        if($this->access->administrator())return true;
        $roles=array_column($this->repository->roles((string)$session['id'],$this->access->uid()),'role');
        return in_array('gm',$roles,true) || (in_array('owner',$roles,true) && (string)$session['owner_uid']===$this->access->uid());
    }
    private function authorize(?array $session, bool $manage): array
    {
        if(!$session)throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        $thread=$this->access->thread((string)$session['tid']);
        $roles=$this->repository->roles((string)$session['id'],$this->access->uid());
        if(!$this->access->administrator() && (!$roles || ($manage && !$this->canManage($session))))throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        return $thread;
    }
    public function list(string $after='0',?string $tid=null): array
    {
        $uid=$this->access->uid();$rows=$this->repository->candidates($uid,$this->access->administrator(),$after,$tid);$visible=[];
        foreach($rows as $row) {
            try {$thread=$this->authorize($row,false);$visible[]=['session'=>$row,'subject'=>(string)$thread['subject']];}
            catch(RuntimeException $error) {if(!in_array($error->getMessage(),['ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE'],true))throw $error;}
        }
        return ['items'=>$visible,'next_after'=>count($rows)===100?(string)end($rows)['id']:null];
    }
    /** No actor_uid, global permission, owner role or config accepted from client. */
    public function command(string $operation,array $input): array
    {
        $this->access->csrf();$actor=$this->access->uid();
        if(!in_array($operation,['create','set_role','remove_member','transfer_owner'],true))throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
        $key=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=trim((string)($input['reason']??''));if($reason==='' || mb_strlen($reason)>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        $request=['schema_version'=>1,'actor_uid'=>$actor,'reason'=>$reason];
        if($operation==='create') {
            $request['tid']=AF_ACS_Repository::integer($input['tid']??null,'4294967295');
            $request['owner_uid']=AF_ACS_Repository::integer($input['owner_uid']??$actor,'4294967295');
        } else {
            $request['session_id']=AF_ACS_Repository::integer($input['session_id']??null);
            $request['expected_version']=AF_ACS_Repository::integer($input['expected_version']??null,'18446744073709551614');
            $request['uid']=AF_ACS_Repository::integer($input['uid']??null,'4294967295');
            if($operation==='set_role') {
                $role=AF_ACS_Role::tryFrom((string)($input['role']??''));
                if(!$role || $role===AF_ACS_Role::Owner)throw new RuntimeException('ACS_ROLE_INVALID');$request['role']=$role->value;
            }
        }
        return $this->repository->command('session:'.$actor.':'.$key,'session.'.$operation,$request,
            function(?array $session) use($operation,$request,$actor) {
                if($operation==='create') {
                    if(!$this->access->canCreate() || ($request['owner_uid']!==$actor && !$this->access->administrator()))throw new RuntimeException('ACS_CREATE_DENIED');
                    $this->access->thread($request['tid']);$this->access->user($request['owner_uid']);$this->access->thread($request['tid'],$request['owner_uid']);
                    if($session)$this->authorize($session,false);
                } else {
                    $this->authorize($session,true);
                    if($session['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
                    if($operation==='transfer_owner')(new AF_ACS_AuthorizationService)->requirePermission('session.owner.transfer',(string)$session['id']);
                }
            },
            function(?array $session) use($operation,$request) {
                if($operation==='create')return $this->repository->insertSession($request['tid'],$request['owner_uid'],$this->access->defaults());
                $id=(string)$session['id'];$uid=$request['uid'];$this->access->user($uid);
                if($operation==='transfer_owner') {
                    if($uid===(string)$session['owner_uid'])throw new RuntimeException('ACS_OWNER_UNCHANGED');
                    $this->access->thread((string)$session['tid'],$uid);
                    $this->repository->assign($id,(string)$session['owner_uid'],'gm');$this->repository->assign($id,$uid,'owner');$this->repository->owner($id,$uid);
                } else {
                    if($uid===(string)$session['owner_uid'])throw new RuntimeException('ACS_OWNER_PROTECTED');
                    // Only owner/admin can promote or remove other masters. GM manages players/observers.
                    $actorRoles=array_column($this->repository->roles($id,$this->access->uid()),'role');
                    $targetRoles=array_column($this->repository->roles($id,$uid),'role');
                    if(!$this->access->administrator() && !in_array('owner',$actorRoles,true) && (array_intersect($targetRoles,['gm','assistant_gm']) || in_array($request['role']??'', ['gm','assistant_gm'],true)))throw new RuntimeException('ACS_ROLE_ESCALATION_DENIED');
                    if($operation==='remove_member')$this->repository->remove($id,$uid);
                    else {$this->access->thread((string)$session['tid'],$uid);$this->repository->assign($id,$uid,$request['role']);}
                }
                return $session;
            });
    }
}
