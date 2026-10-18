<?php
/** Pure safety checks without credentials, DNS lookups or network requests. */
define('IN_MYBB',1);define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS.'advancedgallery/providers.php';
function check($ok,$message) {if(!$ok)throw new RuntimeException($message);}
function reject($callback,$message) {try{$callback();}catch(RuntimeException $e){return;}throw new RuntimeException($message);}
foreach(['127.0.0.1','10.1.2.3','172.16.0.1','192.168.1.1','169.254.169.254','100.64.1.1','0.0.0.0','224.0.0.1','198.19.0.1','192.0.2.1','::1','fe80::1','fc00::1','::ffff:127.0.0.1','2002:7f00:1::','2001:db8::1'] as $ip)check(!ag_public_ip($ip),'SSRF destination accepted: '.$ip);
foreach(['8.8.8.8','1.1.1.1','2606:4700:4700::1111'] as $ip) check(ag_public_ip($ip),'Public IP rejected');
foreach(['file:///etc/passwd','gopher://host.test/','http://127.1/a','http://2130706433/a','http://0x7f000001/a','https://host.test:8443/a','https://user:pass@host.test/a','https://host.test/a#fragment','https://host.test\\@localhost/a',"https://host.test/\r\nX: foo"] as $url)reject(fn()=>ag_external_url_target($url),'Unsafe URL accepted');
check(ag_external_url_target('https://images.example.org/a.png?token=public')['port']===443,'Direct public URL rejected');
reject(fn()=>ag_external_url_addresses('127.0.0.1'),'Private literal resolved');
$path=ag_external_temp_file();file_put_contents($path,'<svg/>');reject(fn()=>ag_external_image_metadata($path,1000,'file.png'),'SVG accepted');unlink($path);
foreach([[200,'not json'],[200,'"string"'],[200,'{}'],[503,'{"success":true}'],[200,'{"success":false,"error":{"message":"SECRET"}}']] as [$code,$body]) {
    try {AG_ImgBBProvider::parseResponse($code,$body);throw new Exception('Bad response accepted');}catch(RuntimeException $e) {check(!str_contains($e->getMessage(),'SECRET'),'API body leaked in error');}
}
$good=['success'=>true,'status'=>200,'data'=>['id'=>'abc','url'=>'https://i.ibb.co/abc/image.png','url_viewer'=>'https://ibb.co/abc','image'=>['mime'=>'image/png'],'width'=>1,'height'=>1,'thumb'=>['url'=>'https://i.ibb.co/abc/thumb.png'],'delete_url'=>'https://ibb.co/abc/PRIVATE_DELETE_TOKEN']];
check(AG_ImgBBProvider::parseResponse(200,json_encode($good))['availability']==='available','Valid provider response');
foreach(['http://i.ibb.co/a.png','https://localhost/a.png','https://i.ibb.co.evil.test/a.png','https://user@i.ibb.co/a.png'] as $bad) {$copy=$good;$copy['data']['url']=$bad;reject(fn()=>AG_ImgBBProvider::parseResponse(200,json_encode($copy)),'Unsafe API URL accepted');}
$bad=$good;$bad['data']['url']=['SECRET'];reject(fn()=>AG_ImgBBProvider::parseResponse(200,json_encode($bad)),'Array URL accepted');
class ImgBBPrivateFailureDB {function write_query($sql,$hide=0) {check($hide===1,'Private SQL errors are not hidden');throw new RuntimeException('SQL SECRET');}}
$db=new ImgBBPrivateFailureDB();
try {ag_provider_private_write('INSERT SECRET');throw new Exception('Private failure accepted');}catch(RuntimeException $e) {check(!str_contains($e->getMessage(),'SECRET'),'Private SQL leaked in error');}
$next=new class extends AG_ExternalStorageProvider {
    function id(): string {return 'postimages';} function label(): string {return 'Future provider';}
    function maxBytes(): int {return 1024;} function validOriginalUrl(string $url): bool {return false;}
    function sendFile(array $source): array {throw new RuntimeException('Not implemented');}
};
ag_register_storage_provider($next);check(ag_storage_providers()['postimages']===$next,'Provider registration requires editing existing upload handler');
echo "ImgBB security passed: private/special IPv4/IPv6, protocol/port/userinfo controls, format checks, strict API validation, sanitized errors.\n";
