<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Native observations share the existing journal. No post writes and no mechanics. */
trait AF_ACS_PostLifecyclePersistence
{
    public function linkedSubmissions(string $column, string $value): array
    {
        if (!in_array($column,['pid','tid'],true)) { throw new InvalidArgumentException('ACS_POST_SCOPE_INVALID'); }
        return $this->sql('SELECT submission_id FROM '.$this->table('submissions').' WHERE '.$column.'=? AND pid IS NOT NULL ORDER BY submission_id',[self::integer($value,'4294967295')])['rows'];
    }

    /** Trusted native hook only: target IDs select existing links, never establish new identity. */
    public function observePost(string $id, string $event, bool $invalidate = false): array
    {
        self::key($event,64);$this->transactions->assertExternalBoundary();
        return $this->transactions->run(function()use($id,$event,$invalidate):array {
            $this->writable();$row=$this->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
            $this->lockSession($row['session_id']);$row=$this->submission($id);
            if ($row['pid']===null) { throw new RuntimeException('ACS_POST_CONFIRMATION_DENIED'); }
            $post=$this->knownPostState($row);
            $e=json_decode($row['evidence_json'],true,32,JSON_THROW_ON_ERROR);
            $observation=['schema_version'=>1,'post'=>$post,'invalidating_operation'=>$invalidate];
            $hash=hash('sha256',self::json(['schema_version'=>1,'post'=>$post]));
            $to=$row['status'];
            if (!in_array($to,['committed','rejected','recovery_required'],true)
                && ($invalidate || !in_array($post['state'],['confirmed','moderation_pending'],true)
                    || ($to==='post_confirmed' && $post['state']!=='confirmed'))) { $to='recovery_required'; }
            if (($e['lifecycle']['hash']??null)===$hash && $to===$row['status']) { return $row; }
            if ($to!==$row['status']) { AF_ACS_Publication::requireTransition($row['status'],$to); }
            $e['lifecycle']=['hash'=>$hash,'event'=>$event,'observation'=>$observation];
            self::integer($row['state_version'],'18446744073709551614');$now=time();
            if ($this->sql('UPDATE '.$this->table('submissions').' SET status=?,state_version=state_version+1,evidence_json=?,updated_at=? WHERE submission_id=? AND state_version=?',[$to,self::json($e),$now,$id,$row['state_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            $updated=$this->submission($id);
            $this->audit($updated,$row['status'],$to,(string)(int)($GLOBALS['mybb']->user['uid']??0),'native_post_lifecycle',['schema_version'=>1,'event'=>$event,'post'=>$post,'observation_hash'=>$hash]);
            return $updated;
        });
    }

    /** Author has revalidated the frozen plan; the short transaction rechecks actual native state. */
    public function confirmApprovedPost(string $id, string $version, array $controls): array
    {
        return $this->change($id,$version,function($row){
            $uid=$this->recoveryActor($row,false,true);
            if ($uid!==$row['author_uid']) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        },function($row)use($controls){
            if ($row['status']!=='moderation_pending') { throw new RuntimeException('ACS_POST_CONFIRMATION_DENIED'); }
            $post=$this->knownPostState($row);
            if ($post['state']!=='confirmed') { throw new RuntimeException('ACS_POST_CONFIRMATION_DENIED'); }
            $frozen=json_decode($row['sealed_json'],true,64,JSON_THROW_ON_ERROR);
            $seal=json_decode($frozen['dependency_json'],true,32,JSON_THROW_ON_ERROR)['seal']??[];
            foreach (['dependency_hash','simulation_hash'] as $k) {
                if (!hash_equals($seal[$k]??'',$controls[$k]??'')) { throw new RuntimeException('ACS_COMBAT_CONFIRMATION_CONFLICT'); }
            }
            if (!hash_equals($frozen['confirmation_hash'],$controls['confirmation_hash']??'')) { throw new RuntimeException('ACS_COMBAT_CONFIRMATION_CONFLICT'); }
            return ['post_confirmed','approved_post_revalidated',['schema_version'=>1,'pid'=>$post['pid'],'fid'=>$post['fid'],'visible'=>'1','controls'=>array_intersect_key($controls,array_flip(['dependency_hash','simulation_hash','confirmation_hash']))],$post['pid']];
        });
    }
}
