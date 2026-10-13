<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/jscripts/')) { $file=dirname(__DIR__).'/work/mybb1840'.$path;if(is_file($file)){header('Content-Type: '.(str_ends_with($file,'.css')?'text/css':'application/javascript'));readfile($file);return;}return false; }
if($path==='/images/default_avatar.png'||preg_match('~^/uploads/(mine|four)\.png$~',$path)){header('Content-Type: image/png');echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');return;}
if(!in_array($path,['/apstudio.php','/showthread.php','/newthread.php','/newreply.php','/editpost.php','/xmlhttp.php'],true))return false;
define('AA_STUDIO_FIXTURE',true);define('AA_MASK_FIXTURE',true);define('THIS_SCRIPT',basename($path));require __DIR__.'/appearance_personal_integration.php';
