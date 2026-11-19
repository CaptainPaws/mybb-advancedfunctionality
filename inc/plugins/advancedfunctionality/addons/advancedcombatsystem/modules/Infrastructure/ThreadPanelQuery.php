<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/SessionService.php';

/** Read-only showthread projection. No resolver execution, clock boundaries or raw state DTO. */
final class AF_ACS_ThreadPanelQuery extends AF_ACS_Repository
{
    public function current(string $sid,string $tid): array
    {
        $access=new AF_ACS_SessionAccess;$repo=new AF_ACS_SessionRepository($this->connection);
        $view=(new AF_ACS_SessionService($repo,$access))->view($sid);
        if((string)$view['session']['tid']!==$tid)throw new RuntimeException('ACS_THREAD_UNAVAILABLE');
        $e=$this->sql('SELECT * FROM '.$this->table('encounters')." WHERE session_id=? ORDER BY CASE WHEN status IN ('active','paused','pending_resolution') THEN 0 WHEN status IN ('preparation','ambush_ready') THEN 1 ELSE 2 END,id DESC LIMIT 1",[$sid])['rows'][0]??null;
        $session=['session_id'=>$sid,'session_status'=>$view['session']['status'],'encounter_id'=>null,'can_manage'=>$view['can_manage'],'can_build'=>$view['can_register_own']];
        if(!$e)return $session;
        require_once __DIR__.'/../Application/AudienceService.php';
        $repo=new AF_ACS_EncounterRepository($this->connection);
        $eid=(string)$e['id'];$gm=$view['can_manage']||$view['can_audit'];$uid=$access->uid();

        // Batch panel fields; hidden rows never become placeholders, filter options or totals.
        $rows=$this->sql('SELECT e.id,e.name,e.kind,g.owner_uid,e.control_group_id,s.hp,s.hp_max FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id JOIN '.$this->table('control_groups').' g ON g.id=e.control_group_id JOIN '.$this->table('entity_states')." s ON s.entity_id=e.id WHERE p.encounter_id=? AND e.session_id=? AND p.participation<>'despawned' ORDER BY e.control_group_id,CASE WHEN e.kind='character' THEN 0 ELSE 1 END,e.id",[$eid,$sid])['rows'];
        $needAudience=!$gm&&(bool)array_filter($rows,static fn($r)=>(string)$r['owner_uid']!==$uid);
        if($needAudience)$repo->cacheThreadPanelReads($sid);
        $audience=$needAudience?new AF_ACS_AudienceService($repo,$access,$sid,$eid):null;
        $groups=[];
        foreach($rows as $r){
            $id=(string)$r['id'];$own=(string)$r['owner_uid']===$uid;
            if(!$gm&&!$own&&($audience->knowledge('entity',$id)['level']!=='identified'||!$audience->canTarget($id)))continue;
            $exact=$gm||$own;
            // Awareness currently grants identity, not numeric state or group/side membership.
            $gid=$exact?(string)$r['control_group_id']:'visible-'.$id;$side=$exact?(string)($r['side_id']??''):'';
            $groups[$gid]??=['id'=>$gid,'side'=>$side,'own'=>$own,'ap'=>null,'turn'=>null,'remaining'=>null,'members'=>[]];
            $groups[$gid]['own']=$groups[$gid]['own']||$own;
            $member=['name'=>$exact?$r['name']:$audience->name($id,$r['name']),'hp'=>$exact?[(string)$r['hp'],(string)$r['hp_max']]:null];
            $groups[$gid]['members'][]=$member;
        }
        $round=$repo->currentRound($e);
        if($round)foreach($repo->allRoundTurns($round['id']) as $t){$g=$t['control_group_id'];if(!isset($groups[$g]))continue;
            $groups[$g]['ap']=[max(0,(int)$t['ap_budget']-(int)$t['ap_spent']-(int)$t['pre_round_ap_commitment']),(string)$t['ap_budget']];

        }
        $canBuild=$view['can_register_own'] && in_array($e['status'],['preparation','active','paused'],true);
        if ($canBuild) {
            try { $access->draftThread($tid); }
            catch (RuntimeException) { $canBuild=false; }
        }
        $config=json_decode($e['config_json'],true,32,JSON_THROW_ON_ERROR);
        return ['session_status'=>$view['session']['status'],'session_id'=>$sid,'encounter_id'=>$eid,'title'=>$config['title']??'Столкновение','status'=>$e['status'],'round'=>(string)$e['round_number'],'groups'=>$groups,'can_manage'=>$view['can_manage'],'can_build'=>$canBuild];
    }
}
