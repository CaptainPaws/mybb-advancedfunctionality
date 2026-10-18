<?php
if (!defined('IN_MYBB')) die('No direct access');

function af_ae_sticker_ip_in_network(string $ip, string $network, int $bits): bool
{
    $address = @inet_pton($ip); $base = @inet_pton($network);
    if ($address === false || $base === false || strlen($address) !== strlen($base)) return false;
    $bytes = intdiv($bits, 8); $remainder = $bits % 8;
    return substr($address, 0, $bytes) === substr($base, 0, $bytes)
        && (!$remainder || ((ord($address[$bytes]) ^ ord($base[$bytes])) & (255 << (8-$remainder))) === 0);
}

function af_ae_sticker_public_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        foreach (['0.0.0.0/8','100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $cidr) {
            [$base,$bits] = explode('/', $cidr);
            if (af_ae_sticker_ip_in_network($ip, $base, (int)$bits)) return false;
        }
        return true;
    }
    return af_ae_sticker_ip_in_network($ip, '2000::', 3) && !af_ae_sticker_ip_in_network($ip, '2001::', 23)
        && !af_ae_sticker_ip_in_network($ip, '2001:db8::', 32) && !af_ae_sticker_ip_in_network($ip, '2002::', 16);
}

/** Inspect a bounded prefix in memory; never download a permanent sticker original. */
class AF_AE_StickerRemote
{
    public function inspect(string $url): array
    {
        if (strlen($url)>255 || preg_match('/[\x00-\x20\\\\]/',$url) || !filter_var($url,FILTER_VALIDATE_URL)) throw new RuntimeException('Укажите прямой HTTPS URL изображения.');
        $p=parse_url($url);
        if (!$p || strtolower($p['scheme'] ?? '')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (int)($p['port'] ?? 443)!==443) throw new RuntimeException('Разрешены только HTTPS-ссылки без авторизации и перенаправлений.');
        if (preg_match('/[\[\]]/',($p['path'] ?? '').($p['query'] ?? ''))) throw new RuntimeException('Квадратные скобки в ссылке должны быть URL-кодированы.');
        $host=strtolower(trim($p['host'],'[]'));
        if (!filter_var($host,FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host)) throw new RuntimeException('Недопустимый адрес источника.');
        $ips=filter_var($host,FILTER_VALIDATE_IP) ? [$host] : $this->addresses($host);
        if (!$ips) throw new RuntimeException('Не удалось определить адрес источника.');
        foreach ($ips as $ip) if (!af_ae_sticker_public_ip($ip)) throw new RuntimeException('Внутренние и служебные адреса запрещены.');
        $response=$this->read($url,$host,$ips[0]);
        if (!$response['body']) throw new RuntimeException('Источник не вернул изображение.');
        $info=@getimagesizefromstring($response['body']);
        $mime=class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->buffer($response['body']) : '';
        $formats=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
        if (!in_array($response['status'],[200,206],true) || !isset($formats[$mime]) || !$info || ($info['mime'] ?? '')!==$mime || $response['mime']!==$mime || empty($info[0]) || empty($info[1])) throw new RuntimeException('Источник должен возвращать изображение PNG, JPEG, GIF или WebP.');
        return ['ext'=>$formats[$mime],'mime'=>$mime];
    }
    protected function addresses(string $host): array
    {
        $ips=[];
        foreach (@dns_get_record($host,DNS_A|DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip'])) $ips[]=$record['ip'];
            if (isset($record['ipv6'])) $ips[]=$record['ipv6'];
        }
        return array_values(array_unique($ips));
    }
    protected function read(string $url,string $host,string $ip): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Проверка внешних стикеров требует PHP cURL.');
        // A forward proxy could resolve a different destination and bypass DNS pinning.
        foreach (['HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','http_proxy','https_proxy','all_proxy'] as $key) if (getenv($key)) throw new RuntimeException('Безопасная проверка URL недоступна через сетевой прокси.');
        $curl=curl_init($url);$body='';$cut=false;$headerBytes=0;$limit=262144;
        try {
            curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_RESOLVE=>filter_var($host,FILTER_VALIDATE_IP)?[]:[$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],
                CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_RANGE=>'0-'.($limit-1),CURLOPT_HTTPHEADER=>['Accept: image/png,image/jpeg,image/gif,image/webp'],
                CURLOPT_HEADERFUNCTION=>static function($curl,$line) use (&$headerBytes) {$headerBytes+=strlen($line);return $headerBytes>65536?0:strlen($line);},
                CURLOPT_WRITEFUNCTION=>static function($curl,$chunk) use (&$body,&$cut,$limit) {
                    $remaining=$limit-strlen($body);$body.=substr($chunk,0,$remaining);
                    if (strlen($chunk)>$remaining) {$cut=true;return 0;}
                    return strlen($chunk);
                }]);
            $ok=curl_exec($curl);$errno=curl_errno($curl);$connected=(string)curl_getinfo($curl,CURLINFO_PRIMARY_IP);
            if ((!$ok && !($cut && $errno===CURLE_WRITE_ERROR)) || @inet_pton($connected)!==@inet_pton($ip)) throw new RuntimeException('Не удалось проверить изображение. Нужна доступная прямая ссылка без перенаправлений.');
            return ['body'=>$body,'status'=>(int)curl_getinfo($curl,CURLINFO_HTTP_CODE),'mime'=>strtolower(trim(explode(';',(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE))[0]))];
        } finally {curl_close($curl);}
    }
}
function af_ae_sticker_inspect_external(string $url): array
{
    $inspector=$GLOBALS['af_ae_sticker_remote_inspector'] ?? new AF_AE_StickerRemote();
    return $inspector->inspect($url);
}
