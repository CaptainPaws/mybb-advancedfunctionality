<?php
declare(strict_types=1);
// Companion to acs_mybb_gateway_regression.php --interrupt. Disposable fixture DBs only.
$socket=$argv[1] ?? ''; $marker=$argv[2] ?? '';
if (!str_starts_with($socket,'/tmp/acs222-socket/') || !file_exists($socket)) throw new RuntimeException('Use the disposable fixture socket');
$m=json_decode(file_get_contents($marker),true,16,JSON_THROW_ON_ERROR);
if (!preg_match('/\Aacs222_[a-f0-9]{12}\z/D',$m['database']) || !preg_match('/\A[a-f0-9]{32}\z/D',$m['submission_id'])) throw new RuntimeException('Unexpected fixture identity');
$db=new mysqli('localhost','root','',$m['database'],0,$socket);
$s=$db->prepare('SELECT status,last_phase,pid,evidence_json FROM fixture_af_acs_submissions WHERE submission_id=?');
$s->bind_param('s',$m['submission_id']);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
if ($r['status']!=='recovery_required' || $r['last_phase']!=='posting' || $r['pid']!==null
    || !str_contains($r['evidence_json'],'observed_pid')) throw new RuntimeException('Missing shutdown quarantine');
if ($db->query('SELECT COUNT(*) FROM fixture_posts')->fetch_row()[0]!=='2') throw new RuntimeException('Native insertion missing/duplicated');
echo "Interrupted native request: one MyISAM reply; durable RECOVERY_REQUIRED, last_phase=posting, observed PID evidence, no confirmed link\n";
$db->query('DROP DATABASE `'.$m['database'].'`');
