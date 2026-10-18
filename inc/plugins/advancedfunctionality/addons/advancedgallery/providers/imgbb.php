<?php
if (!defined('IN_MYBB')) die('No direct access');

class AG_ImgBBProvider extends AG_ExternalStorageProvider
{
    public function id(): string {return 'imgbb';}
    public function label(): string {return 'ImgBB';}
    public function maxBytes(): int {return min(32000000, max(1,(int)af_ag_get_setting('imgbb_max_mb',10))*1048576);}
    public function canUpload(): bool {return parent::canUpload() && ag_provider_secret('imgbb') !== '';}
    public function supportsAvatar(): bool {return true;}
    public function validOriginalUrl(string $url): bool {return self::providerUrl($url, ['i.ibb.co']) !== '';}

    private static function providerUrl(string $url, array $hosts): string
    {
        $p = parse_url($url);
        return strlen($url) <= 1000 && !str_contains($url,'[') && !str_contains($url,']') && !preg_match('/[\\\\\x00-\x20\x7f]/',$url) && $p
            && ($p['scheme'] ?? '') === 'https' && in_array(strtolower($p['host'] ?? ''),$hosts,true)
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['port']) && !isset($p['fragment']) ? $url : '';
    }

    protected function uploadFields(array $source): array
    {
        return ['key'=>ag_provider_secret('imgbb'),'image'=>new CURLFile($source['path'],$source['mime'],'image.'.$source['ext'])];
    }

    /** Only this server-side method ever receives the API key. No query-string credentials. */
    protected function request(array $source): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Для ImgBB требуется PHP cURL.');
        $curl = curl_init('https://api.imgbb.com/1/upload'); $body = '';
        try {
            curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$this->uploadFields($source),
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function ($curl,$chunk) use (&$body) {
                    if (strlen($body)+strlen($chunk)>1048576) return 0;
                    $body.=$chunk;return strlen($chunk);
                }]);
            $ok = curl_exec($curl); $code = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
            if (!$ok) throw new RuntimeException('Не удалось подтвердить загрузку на ImgBB. Проверьте состояние хостинга перед повторной попыткой.');
            return ['code'=>$code,'body'=>$body];
        } finally {curl_close($curl);}
    }

    public function sendFile(array $source): array
    {
        $response = $this->request($source);
        return self::parseResponse($response['code'], $response['body']);
    }

    public static function parseResponse(int $http, string $body): array
    {
        $json = json_decode($body,true); $data = is_array($json) ? ($json['data'] ?? null) : null;
        if ($http<200 || $http>=300 || !is_array($json) || ($json['success'] ?? false)!==true
            || !is_array($data) || (int)($json['status'] ?? 0)<200 || (int)$json['status']>=300) {
            throw new RuntimeException('Не удалось подтвердить загрузку ImgBB. Проверьте настройки хранилища или повторите попытку позже.');
        }
        foreach (['id','url','url_viewer'] as $field) if (!isset($data[$field]) || !is_string($data[$field])) throw new RuntimeException('ImgBB вернул некорректные метаданные изображения.');
        if (!is_array($data['image'] ?? null) || !is_string($data['image']['mime'] ?? null)
            || (isset($data['thumb']) && (!is_array($data['thumb']) || !is_string($data['thumb']['url'] ?? null)))
            || (isset($data['delete_url']) && !is_string($data['delete_url']))) throw new RuntimeException('ImgBB вернул некорректные метаданные изображения.');
        foreach (['width','height'] as $field) {
            if ((!is_int($data[$field] ?? null) && !is_string($data[$field] ?? null))
                || !ctype_digit((string)$data[$field]) || (float)$data[$field]<1 || (float)$data[$field]>2147483647) throw new RuntimeException('ImgBB вернул некорректные размеры изображения.');
        }
        $url = self::providerUrl((string)($data['url'] ?? ''),['i.ibb.co']);
        $thumb = self::providerUrl((string)($data['thumb']['url'] ?? $url),['i.ibb.co']);
        $viewer = self::providerUrl((string)($data['url_viewer'] ?? ''),['ibb.co']);
        $delete = (string)($data['delete_url'] ?? '');
        $mime = (string)($data['image']['mime'] ?? '');
        $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        if (!$url || strlen($url)>500 || !$thumb || strlen($thumb)>255 || !$viewer
            || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',(string)($data['id'] ?? ''))
            || !isset($extensions[$mime]) || (int)($data['width'] ?? 0)<=0 || (int)($data['height'] ?? 0)<=0
            || ($delete!=='' && !self::providerUrl($delete,['ibb.co']))) {
            throw new RuntimeException('ImgBB вернул некорректные метаданные изображения.');
        }
        return ['external_id'=>(string)$data['id'],'url'=>$url,'thumb'=>$thumb,'viewer'=>$viewer,'delete_url'=>$delete,
            'mime'=>$mime,'ext'=>$extensions[$mime],'width'=>(int)$data['width'],'height'=>(int)$data['height'],'availability'=>'available'];
    }
}
