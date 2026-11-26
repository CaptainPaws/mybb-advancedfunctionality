<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/SessionService.php';
require_once __DIR__.'/BestiaryPortraits.php';

/** Read-only showthread projection. No resolver execution, clock boundaries or raw state DTO. */
final class AF_ACS_ThreadPanelQuery extends AF_ACS_Repository
{
    use AF_ACS_BestiaryPortraits;

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

        // Apply audience rules before exposing metadata or resolving portraits.
        $rows=$this->sql('SELECT e.id,e.name,e.kind,g.owner_uid,e.control_group_id,'
            .'p.side_id,side.name AS side_name,p.participation,s.hp,s.hp_max,u.avatar FROM '.$this->table('participants').' p JOIN '
            .$this->table('entities').' e ON e.id=p.entity_id JOIN '.$this->table('control_groups').' g ON g.id=e.control_group_id'
            .' JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id LEFT JOIN '.$this->table('sides').' side'
            .' ON side.id=p.side_id AND side.encounter_id=p.encounter_id LEFT JOIN `'.TABLE_PREFIX."users` u ON u.uid=e.owner_uid AND e.kind='character'"
            ." WHERE p.encounter_id=? AND e.session_id=? AND p.participation<>'despawned'"
            ." ORDER BY p.side_id,e.control_group_id,CASE WHEN e.kind='character' THEN 0 ELSE 1 END,e.id",[$eid,$sid])['rows'];
        $needAudience=!$gm&&(bool)array_filter($rows,static fn($r)=>(string)$r['owner_uid']!==$uid);
        if($needAudience)$repo->cacheThreadPanelReads($sid);
        $audience=$needAudience?new AF_ACS_AudienceService($repo,$access,$sid,$eid):null;
        $groups=[];$portraitIds=[];
        foreach($rows as $r){
            $id=(string)$r['id'];$own=(string)$r['owner_uid']===$uid;
            if(!$gm&&!$own&&($audience->knowledge('entity',$id)['level']!=='identified'||!$audience->canTarget($id)))continue;
            $exact=$gm||$own;
            // Awareness grants identity, not numeric state, portraits or group/side membership.
            $gid=$exact?(string)$r['control_group_id']:'visible-'.$id;
            $groups[$gid]??=['own'=>$own,'ap'=>null,'members'=>[]];
            $groups[$gid]['members'][$id]=[
                'id'=>$id,'name'=>$exact?$r['name']:$audience->name($id,$r['name']),
                'kind'=>$exact?$r['kind']:null,'side_id'=>$exact?$r['side_id']:null,
                'side_name'=>$exact?$r['side_name']:null,'participation'=>$exact?$r['participation']:null,
                'avatar'=>$exact?(string)$r['avatar']:'','hp'=>$exact?[(string)$r['hp'],(string)$r['hp_max']]:null,
                'round_member'=>false,
            ];
            if ($exact && in_array($r['kind'],['npc','monster'],true)) $portraitIds[]=$id;
        }

        $portraits=$this->bestiaryPortraits($sid,$portraitIds);
        foreach ($rows as $row) {
            $id=$row['id'];$gid=$row['control_group_id'];
            if (isset($portraits[$id])) $groups[$gid]['members'][$id]['avatar']=$portraits[$id];
        }

        $round=$repo->currentRound($e);
        if ($round) {
            $turns=$this->sql('SELECT t.control_group_id,t.ap_budget,t.ap_spent,t.pre_round_ap_commitment,m.entity_id FROM '
                .$this->table('turns').' t JOIN '.$this->table('entity_turns').' m ON m.turn_id=t.id WHERE t.round_id=?',
                [$round['id']])['rows'];
            foreach ($turns as $turn) {
                $gid=$turn['control_group_id'];$id=$turn['entity_id'];
                if (!isset($groups[$gid]['members'][$id])) continue;
                $budget=(int)$turn['ap_budget'];
                $spent=(int)$turn['ap_spent'];$commitment=(int)$turn['pre_round_ap_commitment'];
                if ($spent<0 || $commitment<0 || $commitment>$budget || $spent>$budget-$commitment) continue;
                $available=$budget-$spent-$commitment;
                $groups[$gid]['ap']=[$available,$budget];
                $groups[$gid]['members'][$id]['round_member']=true;
            }
        }
        $canBuild=$view['can_register_own'] && in_array($e['status'],['preparation','active','paused'],true);
        if ($canBuild) {
            try { $access->draftThread($tid); }
            catch (RuntimeException) { $canBuild=false; }
        }
        $config=json_decode($e['config_json'],true,32,JSON_THROW_ON_ERROR);
        return ['session_status'=>$view['session']['status'],'session_id'=>$sid,'encounter_id'=>$eid,'title'=>$config['title']??'Столкновение','status'=>$e['status'],'round'=>(string)$e['round_number'],'groups'=>$groups,'can_view_roster'=>$gm,'can_manage'=>$view['can_manage'],'can_build'=>$canBuild];
    }
}
