<?php
define('IN_MYBB',1);
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/stikers/external.php';
function check($value,$message) {if(!$value) throw new RuntimeException($message);}
class StickerSecurityInspector extends AF_AE_StickerRemote {
 public int $reads=0;public array $ips=['93.184.216.34'];public array $response=[];
 protected function addresses(string $host): array {return $this->ips;}
 protected function read(string $url,string $host,string $ip): array {$this->reads++;return $this->response;}
}
$probe=new StickerSecurityInspector();
$probe->response=['status'=>206,'mime'=>'image/gif','body'=>base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')];
check($probe->inspect('https://images.example.org/no-extension')['ext']==='gif','Image signatures should allow extensionless direct URLs');
foreach (['http://example.org/a.png','file:///etc/passwd','data:image/png;base64,a','javascript:alert(1)','https://user:pass@example.org/a.png','https://example.org:8443/a.png','https://example.org/a.png#fragment','https://127.0.0.1/a.png','https://169.254.169.254/a.png','https://[::ffff:127.0.0.1]/a.png','https://[::1]/a.png','https://2130706433/a.png','https://example.org/a.png?x=[/img]'] as $url) {
 $before=$probe->reads;$rejected=false;
 try {$probe->inspect($url);}catch(RuntimeException $e){$rejected=true;}
 check($rejected && $before===$probe->reads,'Unsafe destination reached transport: '.$url);
}
foreach (['10.0.0.1','100.64.0.1','192.0.2.1','198.18.0.1','224.0.0.1','fc00::1','fe80::1','2001:db8::1','2002:7f00:1::'] as $ip) check(!af_ae_sticker_public_ip($ip),'Special IP accepted: '.$ip);
$probe->ips=['93.184.216.34','10.0.0.1'];$before=$probe->reads;
try {$probe->inspect('https://mixed.example.org/a.png');throw new Exception('Mixed DNS accepted');}catch(RuntimeException $e){}
check($before===$probe->reads,'Mixed DNS reached transport');$probe->ips=['93.184.216.34'];
foreach ([['status'=>302,'mime'=>'image/gif','body'=>$probe->response['body']],['status'=>200,'mime'=>'text/html','body'=>$probe->response['body']],['status'=>200,'mime'=>'image/png','body'=>'<svg xmlns="http://www.w3.org/2000/svg"/>'],['status'=>200,'mime'=>'image/gif','body'=>'<html>Not an image</html>']] as $response) {
 $probe->response=$response;$rejected=false;
 try{$probe->inspect('https://images.example.org/a.gif');}catch(RuntimeException $e){$rejected=true;}
 check($rejected,'Invalid status/content accepted');
}
// Real transport must fail closed with a forward proxy; never unset session proxy variables.
class StickerProxyProbe extends AF_AE_StickerRemote {protected function addresses(string $host): array {return ['93.184.216.34'];}}
$hasProxy=false;foreach(['HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','http_proxy','https_proxy','all_proxy'] as $key) if(getenv($key)) $hasProxy=true;
if($hasProxy) {try{(new StickerProxyProbe())->inspect('https://images.example.org/a.png');throw new Exception('Proxy bypass accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'прокси'),'Unexpected transport failure');}}
echo "External sticker security passed: public-only DNS/IP, HTTPS, protocol/port/auth controls, redirect/status/signature/MIME checks and proxy fail-closed.\n";
