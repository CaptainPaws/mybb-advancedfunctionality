<?php
if(!defined('IN_MYBB')||!defined('IN_ADMINCP')||!IN_ADMINCP){die('No direct access');}
require_once __DIR__.'/../Infrastructure/SessionRepository.php';
require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';
require_once __DIR__.'/../Domain/Archive.php';

/** ACP metadata only. No command, owner adapter, DDL or gameplay request/result JSON. */
final class AF_ACS_AcpReadModel extends AF_ACS_Repository
{
    public function sessions(array $filters,string $cursor=''):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.view');
        $where=[];$values=[];
        foreach(['session_id'=>'id','tid'=>'tid','owner_uid'=>'owner_uid'] as $key=>$column)if(($filters[$key]??'')!==''){$where[]='s.'.$column.'=?';$values[]=self::integer($filters[$key]);}
        if(($filters['status']??'')!==''){if(!in_array($filters['status'],['active','pending_close','closed','archived'],true))throw new RuntimeException('ACS_INVALID_KEY');$where[]='s.status=?';$values[]=$filters['status'];}
        global $mybb;$access=new AF_ACS_SessionAccess;$uid=$access->uid();$secret=(string)($mybb->settings['af_acs_archive_cursor_key']??'');
        $bind=hash('sha256',self::json(['schema_version'=>1,'scope'=>'acp-sessions-v1','uid'=>$uid,'filters'=>$filters]));$after='0';
        if($cursor!==''){$v=AF_ACS_Archive::openCursor($cursor,$secret);if(($v['bind']??null)!==$bind)throw new RuntimeException('ACS_ARCHIVE_CURSOR_INVALID');$after=self::integer($v['after']??null,zero:true);}
        $where[]='s.id>?';$values[]=$after;
        $rows=$this->sql('SELECT s.id,s.tid,s.owner_uid,s.status,s.session_version,s.created_at,s.updated_at FROM '.$this->table('sessions').' s WHERE '.implode(' AND ',$where).' ORDER BY s.id LIMIT 51',$values)['rows'];
        $more=count($rows)>50;if($more)array_pop($rows);$roles=[];$native=[];$out=[];
        if($rows){$ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
            foreach($this->sql('SELECT session_id,uid,role,expires_at FROM '.$this->table('session_roles').' WHERE session_id IN ('.$marks.') AND uid=? AND (expires_at IS NULL OR expires_at>?)',[...$ids,$uid,time()])['rows'] as $role)$roles[(string)$role['session_id']][]=$role;
        }
        foreach($rows as $row){$sid=(string)$row['id'];$tid=(string)$row['tid'];
            if (!array_key_exists($tid, $native)) {
                try {
                    $native[$tid] = $access->thread($tid);
                } catch (RuntimeException $error) {
                    if ($error->getMessage() !== 'ACS_THREAD_UNAVAILABLE') { throw $error; }
                    $native[$tid] = null;
                }
            }
            if($native[$tid]===null)continue;
            $row['episode_mode']=$this->episodeMode($tid)['mode'] ?? 'combat';$row['subject']=$native[$tid]['subject'];$row['can_gm']=AF_ACS_AuthorizationService::sessionGm($row,$roles[$sid]??[],$uid);$out[]=$row;
        }
        return ['items'=>$out,'next_cursor'=>$more?AF_ACS_Archive::cursor(['bind'=>$bind,'after'=>(string)end($rows)['id']],$secret):null];
    }
    public function errors(string $id=''):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        if($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new RuntimeException('ACS_INVALID_KEY');
        $rows=$this->sql('SELECT error_id,category,operation,phase,created_at FROM '.$this->table('diagnostics').($id!==''?' WHERE error_id=?':'').' ORDER BY created_at DESC,error_id DESC LIMIT 20',$id!==''?[$id]:[])['rows'];
        foreach($rows as &$row){foreach(['error_id','operation','phase'] as $key)if(!preg_match('/\A[A-Za-z0-9:_.-]{1,64}\z/D',$row[$key]))$row[$key]='redacted';
            if(!in_array($row['category'],['VALIDATION_ERROR','STATE_CONFLICT','PERMISSION_DENIED','DEPENDENCY_ERROR','INTERNAL_ERROR','RECOVERY_REQUIRED'],true))$row['category']='INTERNAL_ERROR';
        }unset($row);return $rows;
    }
    public function inspect(string $sid,string $key='',string $after=''):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');$sid=self::integer($sid);
        foreach([$key,$after] as $value)if($value!==''&&!preg_match('/\A[A-Za-z0-9:_.-]{1,191}\z/D',$value))throw new RuntimeException('ACS_INVALID_KEY');
        $s=$this->sql('SELECT id,tid,owner_uid,status,session_version FROM '.$this->table('sessions').' WHERE id=?',[$sid])['rows'][0]??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        $access=new AF_ACS_SessionAccess;$access->thread((string)$s['tid']);$uid=$access->uid();$repo=new AF_ACS_SessionRepository($this->connection);$gm=AF_ACS_AuthorizationService::sessionGm($s,$repo->roles($sid,$uid),$uid);
        $operations=$this->sql('SELECT operation_key,kind,status,operation_version,updated_at FROM '.$this->table('operations').' WHERE session_id=?'.($key!==''?' AND operation_key=?':'').' AND operation_key>? ORDER BY operation_key LIMIT 51',$key!==''?[$sid,$key,$after]:[$sid,$after])['rows'];
        $more=count($operations)>50;if($more)array_pop($operations);
        $submissions=$this->sql('SELECT submission_id,status,last_phase,state_version,updated_at FROM '.$this->table('submissions')." WHERE session_id=? AND status NOT IN ('committed','rejected') ORDER BY updated_at DESC,submission_id LIMIT 20",[$sid])['rows'];
        $delivery=$this->sql('SELECT kind,status,COUNT(*) AS records FROM '.$this->table('outbox')." o WHERE kind IN ('alerts.source','alerts.delivery','statistics.refresh','statistics.aggregate') AND status IN ('pending','recovery_required') AND (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.session_id')),JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.payload.session_id')))=? OR (kind='statistics.refresh' AND EXISTS (SELECT 1 FROM ".$this->table('encounters')." e WHERE e.session_id=? AND e.id=JSON_UNQUOTE(JSON_EXTRACT(o.payload_json,'$.encounter_id'))))) GROUP BY kind,status",[$sid,$sid])['rows'];
        return ['session'=>$s,'can_gm'=>$gm,'operations'=>$operations,'next_after'=>$more?(string)end($operations)['operation_key']:null,'submissions'=>$submissions,'delivery'=>$delivery];
    }
}
