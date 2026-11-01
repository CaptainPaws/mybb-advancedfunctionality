<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/acs/EngineMigration.php';
// Credentials/evidence paths are private files, never flags containing passwords.
if($argc<4||$argc>5){fwrite(STDERR,"Usage: php tools/acs-engine-migration.php inspect|capture|verify|apply-next|recover PRIVATE_CONFIG STATE_DIR [PRIVATE_APPROVAL]\n");exit(2);}
try{$tool=new AcsEngineMigration($argv[2],$argv[3]);echo json_encode($tool->run($argv[1],$argv[4]??null),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";}
catch(Throwable $e){$code=$e instanceof mysqli_sql_exception?'database_operation_failed':$e->getMessage();if(!preg_match('/\A[a-z0-9_]+\z/D',$code))$code='tool_failed';fwrite(STDERR,json_encode(['error'=>$code,'database_errno'=>$e instanceof mysqli_sql_exception?(int)$e->getCode():null,'notice'=>'Stop. Preserve journal; unresolved intent requires recover or full backup recovery. No automatic SQL rollback.'])."\n");exit(2);}
