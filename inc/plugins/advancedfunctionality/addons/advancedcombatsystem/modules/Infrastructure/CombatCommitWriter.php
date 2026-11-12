<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SubmissionStore.php';
require_once __DIR__.'/CombatCommitPersistence.php';
require_once __DIR__.'/ItemCommitPersistence.php';
require_once __DIR__.'/SpawnPersistence.php';
require_once __DIR__.'/StatusPersistence.php';
require_once __DIR__.'/ReactionPersistence.php';
require_once __DIR__.'/BarrierPersistence.php';
require_once __DIR__.'/TerminalReceiptPersistence.php';
require_once __DIR__.'/../Application/BuilderService.php';

/** The only producer of terminal, persistent Resolver results. Never calls a native post writer. */
final class AF_ACS_CombatCommitWriter extends AF_ACS_Repository
{
    use AF_ACS_SpawnPersistence, AF_ACS_ItemCommitPersistence, AF_ACS_CombatCommitPersistence, AF_ACS_TerminalReceiptPersistence, AF_ACS_StatusPersistence, AF_ACS_ReactionPersistence, AF_ACS_BarrierPersistence;
    private AF_ACS_SubmissionStore $submissions;
    private AF_ACS_EncounterRepository $preparation;
    private AF_ACS_SessionAccess $access;

    public function __construct(object $database, ?AF_ACS_SessionAccess $access = null)
    {
        parent::__construct($database);
        $this->submissions=new AF_ACS_SubmissionStore($database);
        $this->preparation=new AF_ACS_EncounterRepository($database);
        $this->access=$access ?? new AF_ACS_SessionAccess;
    }

    private function own(string $id): array
    {
        $row=$this->submissions->submission($id) ?? throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        if ($this->access->uid()!==$row['author_uid']) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        // Native permission functions run outside the ACS lock/transaction.
        $this->access->thread($row['tid']);
        if (!$this->preparation->roles($row['session_id'],$row['author_uid'])) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        return $row;
    }

    /** Authoritative receipt lookup after a lost/unknown commit response; no Resolver or post creation. */
    public function receipt(string $id): ?array { $this->transactions->assertExternalBoundary();return $this->terminal($this->own($id)); }

    /** Same terminal verifier, read-only access for the author or persisted session owner/GM. */
    public function receiptForRecovery(string $id): ?array
    {
        $this->transactions->assertExternalBoundary();
        $row=$this->submissions->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        $uid=$this->access->uid();$this->access->thread($row['tid']);
        $roles=array_column($this->preparation->roles($row['session_id'],$uid),'role');
        if (!$roles || ($uid!==$row['author_uid'] && !array_intersect($roles,['owner','gm']))) {
            throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED');
        }
        return $this->terminal($row);
    }

    private function frozen(array $row): array
    {
        if (!hash_equals($row['request_hash'],hash('sha256',$row['request_json']))) { throw new RuntimeException('ACS_COMBAT_REQUEST_CORRUPT'); }
        $s=json_decode($row['sealed_json'],true,64,JSON_THROW_ON_ERROR);
        $d=$this->preparation->draft($row['draft_id'],$row['author_uid']);
        if (($s['schema_version']??null)!==1 || !$d || $d['status']!=='publishing' || $d['pid']!==null
            || $d['session_id']!==$row['session_id'] || $d['turn_id']!==$row['turn_id'] || $d['encounter_id']!==$row['encounter_id']) {
            throw new RuntimeException('ACS_COMBAT_FROZEN_INVALID');
        }
        foreach (['mechanics_json','dependency_json','confirmation_hash','prose'] as $field) {
            if (($s[$field]??null)!==$d[$field]) { throw new RuntimeException('ACS_COMBAT_FROZEN_INVALID'); }
        }
        if (!is_string($s['confirmation_hash']) || !preg_match('/\A[a-f0-9]{64}\z/D',$s['confirmation_hash'])) { throw new RuntimeException('ACS_CONFIRMATION_REQUIRED'); }
        $actions=[];$tape=[];$seals=[];
        foreach ($this->preparation->sealRows($row['draft_id'],true) as $a) {
            $payload=$this->preparation->sealPayload($a);$random=$this->preparation->sealTape((string)$a['id']);
            if (!hash_equals($payload['tape_hash'],hash('sha256',self::json(['schema_version'=>1,'tape'=>$random])))) { throw new RuntimeException('ACS_SEAL_TAPE_INVALID'); }
            $this->preparation->verifySealDefinition($row['session_id'],$a,$payload);
            $seals[]=['id'=>(string)$a['id'],'sealed_hash'=>$a['sealed_hash'],'payload'=>$payload,'tape'=>$random];
            $actions[]=$payload['action'];$tape[]=$random;
        }
        if (!$seals || $seals!==($s['seals']??null) || $actions!==(json_decode($s['mechanics_json'],true,32,JSON_THROW_ON_ERROR)['actions']??null)) {
            throw new RuntimeException('ACS_COMBAT_FROZEN_INVALID');
        }
        return ['snapshot'=>$s,'actions'=>$actions,'tape'=>$tape,'seals'=>$seals];
    }

    private function controls(array $row, array $frozen, array $prepared, AF_ACS_ResolverResult $result, bool $requirePost = true): array
    {
        $dependencies=[];
        foreach ($frozen['seals'] as $i=>$s) {
            $a=$s['payload']['action'];$ability=$prepared['context']['abilities'][$a['actor_id']][$a['ability_key']];
            if ($ability['source_ref']!==$s['payload']['pin']) { throw new RuntimeException('ACS_SEAL_PIN_CHANGED'); }
            $v=$prepared['prepared'][$i][2];unset($v['session_version'],$v['encounter_version']);ksort($v);$dependencies[]=$v;
        }
        // Exact SealService confirmation-v1 representation; no new formulas or random source.
        $steps=[];
        foreach ($result->serverPlan()['actions'] as $i=>$step) {
            $steps[]=['sealed_action_id'=>$frozen['seals'][$i]['id'],'actor_id'=>$step['actor_id'],'target_id'=>$step['targets'][0],
                'ability_key'=>$frozen['actions'][$i]['ability_key'],'outcome'=>$step['scenario'],'rolls'=>$step['rolls'],
                'probabilities'=>$step['probabilities'],'costs'=>$step['costs'],'damage'=>$step['allocation']['integer_budget']]+AF_ACS_Resolver::targetSummary($step)+(isset($step['status_evaluations'])?['status_evaluation_hash'=>hash('sha256',self::json(['schema_version'=>1,'evaluations'=>$step['status_evaluations']]))]:[]);
        }
        $entities=[];
        foreach ($result->serverState()['entities'] as $id=>$e) { $entities[]=['entity_id'=>(string)$id,'hp'=>$e['hp'],'max_hp'=>$e['max_hp'],'life_state'=>$e['life_state']]; }
        $summary=['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,
            'rng_version'=>'uniform53-v1','actions'=>$steps,'virtual_entities'=>$entities,'virtual_groups'=>$result->serverState()['groups']];
        if(isset($result->serverPlan()['entity_end_boundaries']))$summary['status_end_hash']=hash('sha256',self::json(['schema_version'=>1,'boundaries'=>$result->serverPlan()['entity_end_boundaries']]));
        if(array_filter($result->serverState()['status_instances']??[],static fn($r)=>isset($r['spec']['upkeep'])))$summary['sustained_state_hash']=hash('sha256',self::json(['schema_version'=>1,'instances'=>$result->serverState()['status_instances']]));
        if(($reactionHash=AF_ACS_Resolver::reactionHash($result,$prepared['context']))!==null)$summary['reaction_hash']=$reactionHash;
        if (isset($result->serverPlan()['item_state'])) $summary['item_state_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'items'=>$result->serverPlan()['item_state']]));
        $dependency=hash('sha256',self::json(['schema_version'=>1,'dependencies'=>$dependencies]));
        $simulation=hash('sha256',self::json(['schema_version'=>1,'actions'=>$frozen['actions'],'tape'=>$frozen['tape'],'summary'=>$summary]));
        $confirmation=hash('sha256',"acs-confirm-v1\n".$dependency."\n".$simulation);
        $saved=json_decode($frozen['snapshot']['dependency_json'],true,32,JSON_THROW_ON_ERROR)['seal']??[];
        $evidence=json_decode($row['evidence_json'],true,32,JSON_THROW_ON_ERROR);
        if (!hash_equals($saved['dependency_hash']??'',$dependency) || !hash_equals($saved['simulation_hash']??'',$simulation)
            || !hash_equals($frozen['snapshot']['confirmation_hash'],$confirmation)
            || ($requirePost && (($evidence['pid']??null)!==$row['pid'] || ($evidence['visible']??null)!=='1'))) { throw new RuntimeException('ACS_COMBAT_CONFIRMATION_CONFLICT'); }
        return ['dependency_hash'=>$dependency,'simulation_hash'=>$simulation,'confirmation_hash'=>$confirmation,'summary'=>$summary];
    }

    /** Read-only pre-publication revalidation with the existing sealed tape. No post creation. */
    public function validatePublication(string $id, string $expectedStateVersion): array
    { return $this->validateFrozenPlan($id,$expectedStateVersion,['registered','validating']); }

    public function validateApprovedPost(string $id, string $expectedStateVersion): array
    { return $this->validateFrozenPlan($id,$expectedStateVersion,['moderation_pending']); }

    private function validateFrozenPlan(string $id, string $expectedStateVersion, array $states): array
    {
        $this->transactions->assertExternalBoundary();$this->access->csrf();$row=$this->own($id);
        if (!in_array($row['status'],$states,true) || $row['state_version']!==self::integer($expectedStateVersion)) {
            throw new RuntimeException('ACS_VERSION_CONFLICT');
        }
        $this->access->draftThread($row['tid']);
        $before=$this->facts($row);$frozen=$this->frozen($row);
        $this->authorizeFacts($row,$before,$frozen['actions']);
        $builder=new AF_ACS_BuilderService($this->preparation,$this->access);
        $scope=$builder->context($row['session_id'],$row['encounter_id'],$row['turn_id']);
        $prepared=$builder->prepare(['session_id'=>$row['session_id'],'encounter_id'=>$row['encounter_id'],'turn_id'=>$row['turn_id'],
            'scope_token'=>$scope['scope_token'],'actions'=>$frozen['actions'],'scenario'=>'hit'],$row['draft_id']);
        $ordered=$prepared['ordered'];foreach ($ordered as &$a) { unset($a['scenario']); }unset($a);
        $prepared['context']=$this->preparation->reactionContext($prepared['context'],$frozen['seals']);
        $result=AF_ACS_Resolver::resolve($prepared['context'],$ordered,$frozen['tape'],'seal');
        $controls=$this->controls($row,$frozen,$prepared,$result,false);
        if ($this->submissions->submission($id)!==$row || !hash_equals(self::factsHash($before),self::factsHash($this->facts($row)))) {
            throw new RuntimeException('ACS_COMBAT_DEPENDENCY_CONFLICT');
        }
        return $controls;
    }

    private function phase(array $row, string $to, array $evidence): array
    {
        AF_ACS_Publication::requireTransition($row['status'],$to);
        self::integer($row['state_version'],'18446744073709551614');
        if ($this->sql('UPDATE '.$this->table('submissions').' SET status=?,last_phase=?,state_version=state_version+1,evidence_json=?,updated_at=? WHERE submission_id=? AND state_version=?',
            [$to,$to,self::json($evidence),time(),$row['submission_id'],$row['state_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
        $version=$this->submissions->submission($row['submission_id'])['state_version'];
        $this->sql('INSERT INTO '.$this->table('publication_audit').' (submission_id,state_version,from_status,to_status,actor_uid,reason,evidence_json,created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$row['submission_id'],$version,$row['status'],$to,$row['author_uid'],'combat_'.$to,self::json($evidence),time()]);
        return $this->submissions->submission($row['submission_id']);
    }

    public function commit(string $id, string $expectedStateVersion): array
    {
        $this->transactions->assertExternalBoundary();
        $this->access->csrf();$row=$this->own($id);
        if ($old=$this->terminal($row)) { return $old; }
        $version=self::integer($expectedStateVersion,'18446744073709551613');
        if ($row['status']!=='post_confirmed' || $row['pid']===null || $row['state_version']!==$version) { throw new RuntimeException('ACS_COMBAT_POST_NOT_CONFIRMED'); }
        $this->access->draftThread($row['tid']);
        $topic=$this->access->thread($row['tid']);$fid=self::integer((string)($topic['fid']??''),'4294967295');
        $before=$this->facts($row);$frozen=$this->frozen($row);
        $builder=new AF_ACS_BuilderService($this->preparation,$this->access);$scope=$builder->context($row['session_id'],$row['encounter_id'],$row['turn_id']);
        $prepared=$builder->prepare(['session_id'=>$row['session_id'],'encounter_id'=>$row['encounter_id'],'turn_id'=>$row['turn_id'],
            'scope_token'=>$scope['scope_token'],'actions'=>$frozen['actions'],'scenario'=>'hit'],$row['draft_id']);
        $fingerprint=self::factsHash($before);
        if (!hash_equals($fingerprint,self::factsHash($this->facts($row)))) { throw new RuntimeException('ACS_COMBAT_DEPENDENCY_CONFLICT'); }
        return $this->transactions->run(function () use ($row,$version,$fid,$topic,$fingerprint,$frozen,$prepared): array {
            $this->writable();$this->lockSession($row['session_id']);
            $current=$this->submissions->submission($row['submission_id']);
            if ($old=$this->terminal($current)) { return $old; }
            if ($current!==$row || $current['state_version']!==$version) { throw new RuntimeException('ACS_COMBAT_VERSION_CONFLICT'); }
            $f=$this->facts($current,true);
            if (!hash_equals($fingerprint,self::factsHash($f))) { throw new RuntimeException('ACS_COMBAT_DEPENDENCY_CONFLICT'); }
            $turn=$this->authorizeFacts($current,$f,$frozen['actions']);
            $this->preparation->assertNoObligations($current['session_id'],$current['encounter_id'],$current['turn_id'],true,$current['draft_id']);
            if ($this->frozen($current)!==$frozen) { throw new RuntimeException('ACS_COMBAT_FROZEN_INVALID'); }
            $identity=json_decode($current['evidence_json'],true,32,JSON_THROW_ON_ERROR);
            if (($identity['fid']??null)!==$fid) { throw new RuntimeException('ACS_COMBAT_POST_INVALID'); }
            $post=$this->sql('SELECT p.pid,p.uid,p.tid,p.fid,p.visible,t.fid AS thread_fid,t.visible AS thread_visible,t.closed,f.open AS forum_open,f.type AS forum_type FROM `'.$this->prefix.'posts` p JOIN `'.$this->prefix.'threads` t ON t.tid=p.tid JOIN `'.$this->prefix.'forums` f ON f.fid=t.fid WHERE p.pid=?',[$current['pid']])['rows'][0]??null;
            if (!$post || (string)$post['uid']!==$current['author_uid'] || (string)$post['tid']!==$current['tid']
                || (string)$post['fid']!==$fid || (string)$post['visible']!=='1' || (string)$post['thread_fid']!==$fid
                || (string)$post['thread_visible']!=='1' || (string)$post['closed']!==(string)($topic['closed']??'0')
                || (string)$post['forum_open']!=='1' || $post['forum_type']!=='f') { throw new RuntimeException('ACS_COMBAT_POST_INVALID'); }
            $ordered=$prepared['ordered'];foreach ($ordered as &$a) { unset($a['scenario']); }unset($a);
            $prepared['context']=$this->preparation->reactionContext($prepared['context'],$frozen['seals']);
            $result=AF_ACS_Resolver::resolve($prepared['context'],$ordered,$frozen['tape'],'seal');
            $controls=$this->controls($current,$frozen,$prepared,$result);$controls['fence_hash']=$fingerprint;
            $current=$this->phase($current,'committing',['schema_version'=>1,'pid'=>$current['pid'],'controls'=>array_diff_key($controls,['summary'=>true])]);
            // Time expiry can advance while resolving even though assignment/revoke rows are locked.
            $this->authorizeFacts($current,$f,$frozen['actions']);
            $controls['owner_receipts']=$this->commitItems($current,$frozen,$result,$prepared);
            $changes=$this->project($current,$f,$turn,$result,$frozen);
            return $this->finish($current,$f,$result,$controls,$changes,$fid,$prepared);
        });
    }

    private function finish(array $row, array $f, AF_ACS_ResolverResult $resolved, array $controls, array $changes, string $fid,array $prepared): array
    {
        $session=$f['session'][0];$encounter=$f['encounter'][0];$operation='combat:'.$row['submission_id'];$now=time();
        self::integer((string)$session['session_version'],'18446744073709551614');self::integer((string)$session['next_sequence'],'18446744073709551614');
        self::integer((string)$encounter['encounter_version'],'18446744073709551614');self::integer((string)$encounter['next_event_sequence'],'18446744073709551614');
        $event=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,encounter_id,event_sequence,operation_key,event_type,source_entity_id,actor_uid,submission_id,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$row['session_id'],$session['next_sequence'],$row['encounter_id'],$encounter['next_event_sequence'],$operation,'combat.resolved',$controls['summary']['actions'][0]['actor_id'],$row['author_uid'],$row['submission_id'],AF_ACS_Resolver::VERSION,$now])['id'];
        $spawns=$this->commitSpawns($row,$event,$prepared,$resolved->serverPlan());
        if($spawns)$changes['spawns']=$spawns;
        $boundaryHistory=[];$turnIds=[];
        foreach($f['individuals'] as $individual)if((string)$individual['turn_id']===$row['turn_id'])$turnIds[]=(string)$individual['id'];
        foreach($f['boundaries'] as $boundary){if($boundary['status']!=='committed'||!in_array($boundary['kind'],['clock.entity_start','clock.ap_refresh'],true))continue;
            $data=json_decode($boundary['result_json'],true,64,JSON_THROW_ON_ERROR);if(in_array($data['entity_turn_id']??null,$turnIds,true)||isset($data['round_id'])&&(string)$data['round_id']===(string)(self::indexed($f['turns'])[$row['turn_id']]['round_id']??''))$boundaryHistory[]=['boundary_key'=>$boundary['operation_key'],'result'=>$data];
        }
        $payload=self::json(['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,
            'persistent'=>true,'submission_id'=>$row['submission_id'],'owner_receipts'=>$controls['owner_receipts']??[],'boundary_history'=>$boundaryHistory,'summary'=>$controls['summary'],'plan'=>$resolved->serverPlan(),
            'dependency_fence_hash'=>$controls['fence_hash'],'initial'=>$resolved->serverInitial(),'final'=>$resolved->serverState(),'changes'=>$changes]);$resultHash=hash('sha256',$payload);
        $visibility=self::json(['schema_version'=>1,'scope'=>'combat_participants']);
        $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,1,?,?,?,?)',
            [$event,AF_ACS_Numeric::POLICY,$payload,$visibility,hash('sha256',$payload."\n".$visibility)]);
        $link=$this->sql('INSERT INTO '.$this->table('post_links').' (submission_id,session_id,encounter_id,turn_id,pid,tid,fid,author_uid,created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$row['submission_id'],$row['session_id'],$row['encounter_id'],$row['turn_id'],$row['pid'],$row['tid'],$fid,$row['author_uid'],$now])['id'];
        $this->sql('INSERT INTO '.$this->table('combat_results').' (submission_id,turn_id,event_id,engine_version,result_hash,payload_json,created_at) VALUES (?,?,?,?,?,?,?)',
            [$row['submission_id'],$row['turn_id'],$event,AF_ACS_Resolver::VERSION,$resultHash,$payload,$now]);
        if ($this->sql('UPDATE '.$this->table('sessions').' SET session_version=session_version+1,next_sequence=next_sequence+1,updated_at=? WHERE id=? AND session_version=?',
            [$now,$row['session_id'],$session['session_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
        if ($this->sql('UPDATE '.$this->table('encounters').' SET encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=?',
            [$row['encounter_id'],$encounter['encounter_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
        if ($this->sql('UPDATE '.$this->table('drafts')." SET status='published',pid=?,draft_version=draft_version+1,updated_at=? WHERE id=? AND status='publishing'",
            [$row['pid'],$now,$row['draft_id']])['affected']!==1) { throw new RuntimeException('ACS_COMBAT_FROZEN_INVALID'); }
        $this->sql('UPDATE '.$this->table('sealed_actions')." SET status='published' WHERE draft_id=? AND status='sealed'",[$row['draft_id']]);
        $row=$this->phase($row,'committed',['schema_version'=>1,'pid'=>$row['pid'],'event_id'=>$event,'result_hash'=>$resultHash]);
        $messageKey=$operation.':committed';
        $receipt=['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'rng_version'=>'uniform53-v1','status'=>'COMMITTED','submission_id'=>$row['submission_id'],'session_id'=>$row['session_id'],
            'turn_id'=>$row['turn_id'],'pid'=>$row['pid'],'fid'=>$fid,'post_link_id'=>$link,'event_id'=>$event,'state_version'=>$row['state_version'],
            'result_hash'=>$resultHash,'dependency_hash'=>$controls['dependency_hash'],'simulation_hash'=>$controls['simulation_hash'],
            'confirmation_hash'=>$controls['confirmation_hash'],'owner_receipts'=>$controls['owner_receipts']??[],'outbox_key'=>$messageKey];if($spawns)$receipt['spawns']=$spawns;$json=self::json($receipt);
        $this->sql('INSERT INTO '.$this->table('combat_receipts').' (submission_id,turn_id,pid,request_hash,sealed_hash,receipt_hash,receipt_json,created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$row['submission_id'],$row['turn_id'],$row['pid'],$row['request_hash'],hash('sha256',$row['sealed_json']),hash('sha256',$json),$json,$now]);
        $this->sql('UPDATE '.$this->table('submissions').' SET result_json=? WHERE submission_id=?',[$json,$row['submission_id']]);
        $this->operation($row,$operation,'combat.commit',['submission_id'=>$row['submission_id'],'request_hash'=>$row['request_hash']],$receipt);
        $outbox=self::json(['schema_version'=>1,'submission_id'=>$row['submission_id'],'event_id'=>$event,'result_hash'=>$resultHash,'pid'=>$row['pid']]);
        $this->sql('INSERT INTO '.$this->table('outbox').' (message_key,submission_id,kind,payload_hash,payload_json,status,attempts,available_at,created_at) VALUES (?,?,?,?,?,?,0,?,?)',
            [$messageKey,$row['submission_id'],'combat.committed',hash('sha256',$outbox),$outbox,'pending',$now,$now]);
        return $receipt;
    }
}
