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
            'entity.control' => ['scope' => 'entity', 'policy' => 'RESERVED_DENY'],
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
        if(in_array($permission,['session.view','session.manage','entity.register.own','entity.register.bestiary'],true) && function_exists('af_advancedcombatsystem_sessions')) {
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
