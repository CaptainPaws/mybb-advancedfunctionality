<?php
/** Bounded, DNS-pinned image downloads for untrusted URLs. */
if (!defined('IN_MYBB')) die('No direct access');

function ag_ip_in_network(string $ip, string $network, int $bits): bool
{
    $address = @inet_pton($ip); $base = @inet_pton($network);
    if ($address === false || $base === false || strlen($address) !== strlen($base)) return false;
    $bytes = intdiv($bits, 8); $remainder = $bits % 8;
    return substr($address, 0, $bytes) === substr($base, 0, $bytes)
        && (!$remainder || ((ord($address[$bytes]) ^ ord($base[$bytes])) & (255 << (8-$remainder))) === 0);
}

function ag_public_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        foreach (['0.0.0.0/8','100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $cidr) {
            [$base,$bits] = explode('/', $cidr);
            if (ag_ip_in_network($ip, $base, (int)$bits)) return false;
        }
        return true;
    }
    return ag_ip_in_network($ip, '2000::', 3) && !ag_ip_in_network($ip, '2001::', 23)
        && !ag_ip_in_network($ip, '2001:db8::', 32) && !ag_ip_in_network($ip, '2002::', 16);
}

/** Returns a canonical host and port without resolving attacker-controlled names yet. */
function ag_external_url_target(string $url): array
{
    if (strlen($url) > 2048 || preg_match('/[\\\\\x00-\x20\x7f]/', $url)) throw new RuntimeException('Недопустимая ссылка на изображение.');
    $p = parse_url($url);
    if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http','https'], true)
        || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) throw new RuntimeException('Разрешены только прямые HTTP/HTTPS-ссылки без логина и пароля.');
    $host = strtolower(trim($p['host'], '[]'));
    $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
    if ($port !== (strtolower($p['scheme']) === 'https' ? 443 : 80)
        || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host))) {
        throw new RuntimeException('Недопустимое назначение ссылки.');
    }
    return ['host'=>$host,'port'=>$port];
}

function ag_external_url_addresses(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) $addresses = [$host];
    else {
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip'])) $addresses[] = $record['ip'];
            if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
        }
    }
    if (!$addresses) throw new RuntimeException('Не удалось определить публичный адрес сервера изображения.');
    foreach ($addresses as $ip) if (!ag_public_ip($ip)) throw new RuntimeException('Ссылки на внутренние и служебные сети запрещены.');
    return array_values(array_unique($addresses));
}

function ag_external_temp_file(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ag-external-');
    if ($path === false) throw new RuntimeException('Временное хранилище недоступно.');
    @chmod($path, 0600);
    register_shutdown_function(static function () use ($path) {if (is_file($path)) @unlink($path);});
    return $path;
}

function ag_download_external_image(string $url, int $maxBytes): string
{
    if (!function_exists('curl_init')) throw new RuntimeException('Для внешних хранилищ требуется PHP cURL.');
    $target = ag_external_url_target($url);
    $addresses = ag_external_url_addresses($target['host']);
    // A forward proxy may re-resolve the hostname, bypassing CURLOPT_RESOLVE. Fail closed.
    foreach (['HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','http_proxy','https_proxy','all_proxy'] as $variable) {
        if (getenv($variable)) throw new RuntimeException('Безопасная загрузка по URL недоступна через сетевой прокси. Используйте файл с устройства.');
    }
    $path = ag_external_temp_file(); $handle = fopen($path, 'wb');
    if (!$handle) { @unlink($path); throw new RuntimeException('Временное хранилище недоступно.'); }
    $curl = curl_init($url); $bytes = 0;
    $ip = $addresses[0];
    $resolve = $target['host'].':'.$target['port'].':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
    try {
        curl_setopt_array($curl, [CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_RESOLVE=>[$resolve],
            CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_MAXFILESIZE=>$maxBytes, CURLOPT_HTTPHEADER=>['Accept: image/*'],
            CURLOPT_WRITEFUNCTION=>static function ($curl, $chunk) use ($handle, &$bytes, $maxBytes) {
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) return 0;
                $written = fwrite($handle, $chunk);
                return $written === false ? 0 : $written;
            }]);
        $ok = curl_exec($curl); $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $connected = (string)curl_getinfo($curl, CURLINFO_PRIMARY_IP);
        if (!$ok || $code !== 200 || !$bytes || !ag_public_ip($connected) || @inet_pton($connected) !== @inet_pton($ip)) {
            throw new RuntimeException('Не удалось получить изображение по ссылке. Проверьте прямой URL и допустимый размер; перенаправления не поддерживаются.');
        }
    } catch (Throwable $e) {
        @unlink($path); throw $e;
    } finally {curl_close($curl); fclose($handle);}
    return $path;
}

function ag_external_text(string $text, int $limit): string
{
    if (!preg_match('//u',$text) || !preg_match('/^.{0,'.$limit.'}/us',$text,$match)) throw new RuntimeException('Текст должен быть в кодировке UTF-8.');
    return $match[0];
}

function ag_external_image_metadata(string $path, int $maxBytes, string $name): array
{
    $bytes = @filesize($path); $image = @getimagesize($path);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!$bytes || $bytes > $maxBytes) throw new RuntimeException('Превышен допустимый размер изображения для внешнего хранилища.');
    if (!$image || !isset($extensions[$mime]) || ($image['mime'] ?? '') !== $mime) throw new RuntimeException('Разрешены только изображения JPG, PNG, GIF и WebP.');
    return ['path'=>$path,'bytes'=>(int)$bytes,'mime'=>$mime,'ext'=>$extensions[$mime],
        'width'=>(int)$image[0],'height'=>(int)$image[1],'name'=>ag_external_text(basename(str_replace('\\','/', $name)),200)];
}
