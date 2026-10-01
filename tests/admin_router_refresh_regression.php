<?php
declare(strict_types=1);
$root=dirname(__DIR__); $core=(string)file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
function extractRouterFunction(string $source,string $name): string {$start=strpos($source,'function '.$name.'(');$brace=strpos($source,'{',$start);$d=0;for($i=$brace,$n=strlen($source);$i<$n;$i++){if($source[$i]==='{')$d++;elseif($source[$i]==='}'&&--$d===0)return substr($source,$start,$i-$start+1);}throw new RuntimeException($name);}
$tmp=sys_get_temp_dir().'/af_router_'.bin2hex(random_bytes(4)); mkdir($tmp); mkdir($tmp.'/cache');
define('AF_ADMIN',$tmp.'/'); define('AF_CACHE',$tmp.'/cache/'); define('AF_ADMIN_ROUTER_SOURCE',$tmp.'/router.dist.php');
eval(extractRouterFunction($core,'af_admin_router_signature')); eval(extractRouterFunction($core,'af_ensure_admin_router'));
$canonical="<?php\n// AF-GENERATED: admin-router v3\necho 'new';\n"; file_put_contents(AF_ADMIN_ROUTER_SOURCE,$canonical); file_put_contents(AF_ADMIN.'router.php',"<?php\n// AF-GENERATED: admin-router v1\necho 'old';\n");
$r=af_ensure_admin_router(); if($r['status']!=='updated'||file_get_contents(AF_ADMIN.'router.php')!==$canonical)throw new RuntimeException('owned old router not refreshed');
$mtime=filemtime(AF_ADMIN.'router.php'); usleep(1100000); $r=af_ensure_admin_router(); clearstatcache(); if($r['status']!=='unchanged'||filemtime(AF_ADMIN.'router.php')!==$mtime)throw new RuntimeException('identical router rewritten');
file_put_contents(AF_ADMIN.'router.php',"<?php echo 'foreign';\n"); $r=af_ensure_admin_router(); if($r['status']!=='foreign_router'||file_get_contents(AF_ADMIN.'router.php')!=="<?php echo 'foreign';\n")throw new RuntimeException('foreign router overwritten');
@unlink(AF_ADMIN.'router.php');@unlink(AF_ADMIN_ROUTER_SOURCE);@unlink(AF_CACHE.'router_diagnostic.txt');@rmdir(AF_CACHE);@rmdir(AF_ADMIN);
echo "AF generated router refresh/identity/foreign ownership regression passed.\n";
