<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

enum AF_ACS_Role: string
{
    case Owner = 'owner';
    case Gm = 'gm';
    case AssistantGm = 'assistant_gm';
    case Player = 'player';
    case Observer = 'observer';
}

/** Global AF ACP permissions and server-resolved session membership remain separate. */
final class AF_ACS_AuthorizationService
{
    public static function matrix(): array
    {
        return [
            'admin.view' => ['scope' => 'global', 'policy' => 'AF_ACP_INDEX'],
            'admin.permissions.view' => ['scope' => 'global', 'policy' => 'AF_ACP_INDEX'],
            'admin.diagnostics.view' => ['scope' => 'global', 'policy' => 'AF_ACP_INDEX'],
            'admin.integrations.view' => ['scope' => 'global', 'policy' => 'AF_ACP_INDEX'],
            'admin.schema.manage' => ['scope' => 'global', 'policy' => 'AF_ACP_INDEX'],
            'admin.lifecycle' => ['scope' => 'global', 'policy' => 'AF_ACP_INDEX'],
            'session.view' => ['scope' => 'session', 'policy' => 'NATIVE_THREAD_AND_MEMBERSHIP'],
            'session.manage' => ['scope' => 'session', 'policy' => 'NATIVE_THREAD_AND_OWNER_GM'],
            'entity.register.own' => ['scope'=>'entity','policy'=>'SESSION_MEMBER_AND_VERIFIED_OWNER'],
            'entity.register.bestiary' => ['scope'=>'entity','policy'=>'NATIVE_THREAD_AND_OWNER_GM'],
            'encounter.pause' => ['scope'=>'session','policy'=>'OWNER_GM_CONFIRM_REASON'],
            'encounter.deadline.manage' => ['scope'=>'session','policy'=>'OWNER_GM_CONFIRM_REASON'],
            'turn.skip' => ['scope'=>'session','policy'=>'OWNER_GM_BOUNDARIES_OBLIGATIONS'],
            'round.advance' => ['scope'=>'session','policy'=>'OWNER_GM_ROSTER_CLOSED'],
            'delegation.grant'=>['scope'=>'entity','policy'=>'OWNER_CONSENT_OR_EXPLICIT_REPLACEMENT'],
            'delegation.revoke'=>['scope'=>'entity','policy'=>'OWNER_DELEGATE_OR_EXPLICIT_REPLACEMENT'],
            'npc.controller.assign' => ['scope'=>'entity','policy'=>'NATIVE_THREAD_AND_OWNER_GM_NPC_ONLY'],
            'encounter.manage' => ['scope'=>'session','policy'=>'NATIVE_THREAD_AND_OWNER_GM'],
            'entity.control' => ['scope' => 'entity', 'policy' => 'CURRENT_ENTITY_ASSIGNMENT'],
            'entity.consume' => ['scope' => 'entity', 'policy' => 'RESERVED_DENY'],
            'confirmed.correct' => ['scope' => 'critical', 'policy' => 'RESERVED_DENY'],
            'rewards.adjust' => ['scope' => 'critical', 'policy' => 'RESERVED_DENY'],
            'persistent.modify' => ['scope' => 'critical', 'policy' => 'RESERVED_DENY'],
            'control.transfer' => ['scope' => 'critical', 'policy' => 'RESERVED_DENY'],
            'session.owner.transfer' => ['scope'=>'critical','policy'=>'AF_ACP_INDEX_SESSION_ONLY'],
            'session.reopen' => ['scope' => 'critical', 'policy' => 'RESERVED_DENY'],
            'admin.recover' => ['scope' => 'critical', 'policy' => 'RESERVED_DENY'],
        ];
    }

    /** A single generation describes the whole assignment, never one budget per UID.
     * NULL UID is the gm_pool marker, or an explicitly unassigned exclusive/shared NPC.
     * Caller supplies current persisted rows; no request role or administrative bypass.
     */
    public static function assignment(array $rows): array
    {
        if (!$rows || count($rows)>100) throw new RuntimeException('ACS_CONTROL_INVALID');
        $first=$rows[0];$uids=[];
        if (!in_array($first['mode'],['exclusive','shared','gm_pool'],true)) throw new RuntimeException('ACS_CONTROL_INVALID');
        foreach ($rows as $row) {
            foreach (['session_id','entity_id','mode','assignment_version'] as $key) {
                if ((string)$row[$key] !== (string)$first[$key]) throw new RuntimeException('ACS_CONTROL_INVALID');
            }
            if ($row['status']!=='active' || (int)$row['valid_from']>time()
                || $row['valid_until']!==null && (int)$row['valid_until']<=time()) throw new RuntimeException('ACS_CONTROL_INVALID');
            $uid=$row['controller_uid']===null?null:(string)$row['controller_uid'];
            if (in_array($uid,$uids,true)) throw new RuntimeException('ACS_CONTROL_INVALID');
            $uids[]=$uid;
        }
        if (($first['mode']!=='shared' && count($rows)!==1)
            || in_array(null,$uids,true) && count($rows)!==1
            || $first['mode']==='gm_pool' && $uids!==[null]) throw new RuntimeException('ACS_CONTROL_INVALID');
        return ['mode'=>$first['mode'],'assignment_version'=>(string)$first['assignment_version'],'uids'=>array_values(array_filter($uids,static fn($u)=>$u!==null))];
    }

    public static function sessionGm(array $session,array $roles,string $uid): bool
    {
        foreach ($roles as $role) {
            if ((string)$role['uid']!==$uid || $role['expires_at']!==null && (int)$role['expires_at']<=time()) continue;
            if ($role['role']==='gm' || $role['role']==='owner' && (string)$session['owner_uid']===$uid) return true;
        }
        return false;
    }

    /** Native thread access is checked by the service before this entity-specific grant. */
    public static function controller(array $entity,array $rows,array $session,array $roles,string $uid,array $delegations=[],string $category='ordinary',array $context=[]): ?array
    {
        try {$set=self::assignment($rows);} catch (RuntimeException) {return null;}
        if ((string)$entity['session_id']!==(string)$session['id'] || (string)$rows[0]['session_id']!==(string)$session['id']
            || (string)$rows[0]['entity_id']!==(string)($entity['id']??$entity['entity_id'])) return null;
        if($entity['kind']==='summon'){
            if($category!=='ordinary'||$entity['owner_uid']!==null)return null;
            $d=json_decode($entity['definition_json'],true,64,JSON_THROW_ON_ERROR);$v=$d['lifecycle']??null;
            if(($v['status']??'active')!=='active'||!empty($v['pending_independent']))return null;
            if(($v['independent']??($d['spawn']['spec']['group']==='independent'))===true){$entity['kind']='monster';}
            else{
                $id=(string)$entity['source_entity_id'];$source=$context['entities'][$id]??null;if(!$source||$source['kind']==='summon'||(string)$source['control_group_id']!==(string)$entity['control_group_id'])return null;
                $sourceRows=array_values(array_filter($context['controllers']??[],static fn($c)=>(string)$c['entity_id']===$id&&$c['status']==='active'&&($c['valid_until']===null||(int)$c['valid_until']>time())));
                $proof=self::controller($source,$sourceRows,$session,$roles,$uid,[],$category);$grant=$rows[0];
                if(!$proof && in_array($source['kind'],['character','pet'],true)){
                    $borrow=$entity;$borrow['owner_uid']=$source['owner_uid'];$borrow['kind']='pet';
                    $proof=self::delegatedController($borrow,$grant,$session,$roles,$uid,$delegations,$category);
                }
                if(!$proof || (json_decode($proof['permissions_json'],true)['combat_operations_enabled']??false)!==true)return null;
                $grant['source_control_proof']=self::controlProof($proof);return $grant;
            }
        }
        $owned=in_array($entity['kind'],['character','pet'],true);
        if ($owned) {
            if ($entity['owner_uid']===null || $set['mode']!=='exclusive' || $set['uids']!==[(string)$entity['owner_uid']]) return null;
            if((string)$entity['owner_uid']!==$uid)return self::delegatedController($entity,$rows[0],$session,$roles,$uid,$delegations,$category);
        } elseif (!in_array($entity['kind'],['npc','monster'],true) || $entity['owner_uid']!==null || !self::sessionGm($session,$roles,$uid)) return null;
        foreach ($rows as $row) {
            if ((string)$row['controller_uid']===$uid || !$owned && $set['mode']==='gm_pool') return $row;
        }
        return null;
    }

    public const DELEGATION_ACTIONS=['ordinary','item_consume','equipment_change','escape','surrender'];

    /** Persisted consent is independent of controller_uid; grants never change ownership. */
    private static function delegatedController(array $entity,array $controller,array $session,array $roles,string $uid,array $grants,string $category): ?array
    {
        if(!in_array($category,self::DELEGATION_ACTIONS,true))return null;
        $member=false;foreach($roles as $role)if((string)$role['uid']===$uid && ($role['expires_at']===null || (int)$role['expires_at']>time()) && in_array($role['role'],['owner','gm','assistant_gm','player'],true))$member=true;
        if(!$member)return null;
        foreach($grants as $grant){
            if((string)$grant['session_id']!==(string)$session['id'] || (string)$grant['owner_uid']!==(string)$entity['owner_uid'] || (string)$grant['delegate_uid']!==$uid || $grant['status']!=='active' || $grant['valid_until']!==null && (int)$grant['valid_until']<=time())continue;
            try{$scope=json_decode($grant['scope_json'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}
            if(($scope['schema_version']??null)!==1 || ($scope['scope']??null)!=='session' || !isset($scope['valid_from']) || (int)$scope['valid_from']>time()
                || !is_array($scope['entity_ids']??null) || !array_is_list($scope['entity_ids']) || !is_array($scope['permitted_actions']??null) || !array_is_list($scope['permitted_actions']) || !in_array($category,$scope['permitted_actions'],true) || array_diff($scope['permitted_actions'],self::DELEGATION_ACTIONS))continue;
            if(!in_array('ordinary',$scope['permitted_actions'],true) || (($scope['control_group_id']??null)!==null)===(bool)$scope['entity_ids'])continue;
            $exact=in_array((string)$entity['id'],$scope['entity_ids']??[],true);
            $group=($scope['control_group_id']??null)!==null && (string)$entity['control_group_id']===$scope['control_group_id'];
            if(!$exact&&!$group)continue;
            $controller['delegation_id']=(string)$grant['id'];$controller['delegation_version']=(string)$grant['delegation_version'];
            $controller['delegation_category']=$category;return $controller;
        }
        return null;
    }

    public static function controlProof(array $grant): string
    {
        return ($grant['delegation_id']??'owner').':'.($grant['delegation_version']??'0').':'.$grant['assignment_version'].(isset($grant['source_control_proof'])?':source:'.$grant['source_control_proof']:'');
    }

    /** Retains native MyBB super-admin access ONLY for existing ACP facilities. */
    private function afAdministrator(): bool
    {
        global $mybb;
        if (!defined('IN_ADMINCP') || !IN_ADMINCP || !is_object($mybb)
            || (int)($mybb->user['uid'] ?? 0) <= 0 || empty($mybb->usergroup['cancp'])) { return false; }
        $uid = (int)$mybb->user['uid'];
        if (function_exists('is_super_admin') && is_super_admin($uid)) { return true; }
        // Read the server-resolved MyBB permissions; never accept request grants.
        $permission = $mybb->admin['permissions']['advancedfunctionality']['index'] ?? null;
        return in_array($permission, [1, '1', true], true);
    }

    /** The role argument is never a grant; session permissions resolve persisted membership. */
    public function decision(string $permission, ?string $sessionId = null, ?AF_ACS_Role $role = null): array
    {
        $entry = self::matrix()[$permission] ?? null;
        if (!$entry) { return ['allowed' => false, 'reason' => 'ACS_UNKNOWN_PERMISSION']; }
        if ($entry['scope'] === 'global') {
            $allowed = $this->afAdministrator();
            return ['allowed' => $allowed, 'reason' => $allowed ? null : 'ACS_ADMIN_PERMISSION_REQUIRED'];
        }
        if($permission==='session.owner.transfer' && $sessionId!==null && $sessionId!=='') {
            $allowed=$this->afAdministrator();return ['allowed'=>$allowed,'reason'=>$allowed?null:'ACS_ADMIN_PERMISSION_REQUIRED'];
        }
        if ($entry['scope'] === 'critical') { return ['allowed' => false, 'reason' => 'ACS_CRITICAL_OPERATION_NOT_IMPLEMENTED']; }
        if ($sessionId === null || $sessionId === '') { return ['allowed' => false, 'reason' => 'ACS_SESSION_CONTEXT_REQUIRED']; }
        if(in_array($permission,['session.view','session.manage','entity.register.own','entity.register.bestiary','encounter.manage','encounter.pause','encounter.deadline.manage','turn.skip','round.advance'],true) && function_exists('af_advancedcombatsystem_sessions')) {
            try {$view=af_advancedcombatsystem_sessions()->view($sessionId);$allowed=$permission==='session.view' || $view['can_manage'];
                if($permission==='entity.register.own')$allowed=$view['can_register_own'];return ['allowed'=>$allowed,'reason'=>$allowed?null:'ACS_SESSION_UNAVAILABLE'];}
            catch(Throwable $error) {return ['allowed'=>false,'reason'=>'ACS_SESSION_UNAVAILABLE'];}
        }
        return ['allowed' => false, 'reason' => 'ACS_SESSION_AUTHORIZATION_NOT_IMPLEMENTED'];
    }

    public function requirePermission(string $permission, ?string $sessionId = null, ?AF_ACS_Role $role = null): void
    {
        $decision = $this->decision($permission, $sessionId, $role);
        if (!$decision['allowed']) { throw new RuntimeException($decision['reason']); }
    }
}
