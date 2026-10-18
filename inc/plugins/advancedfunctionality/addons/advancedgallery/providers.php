<?php
if (!defined('IN_MYBB')) die('No direct access');
require_once __DIR__.'/providers/http.php';

interface AG_StorageProvider
{
    public function id(): string;
    public function label(): string;
    public function canUpload(): bool;
    public function supportsUrl(): bool;
    public function upload(): void;
}

final class AG_LocalStorageProvider implements AG_StorageProvider
{
    public function id(): string {return 'local';}
    public function label(): string {return 'Локальная галерея';}
    public function canUpload(): bool {return ag_can_upload();}
    public function supportsUrl(): bool {return false;}
    public function upload(): void {ag_handle_local_upload();}
}

abstract class AG_ExternalStorageProvider implements AG_StorageProvider
{
    abstract public function id(): string;
    abstract public function label(): string;
    abstract public function maxBytes(): int;
    abstract public function sendFile(array $source): array;
    abstract public function validOriginalUrl(string $url): bool;
    public function supportsAvatar(): bool {return false;}
    public function downloadOriginal(array $media): string
    {
        if (!$this->validOriginalUrl((string)$media['remote_url'])) throw new RuntimeException('Недопустимый адрес оригинала.');
        return ag_download_external_image((string)$media['remote_url'],$this->maxBytes());
    }
    public function canUpload(): bool
    {
        $groups = ag_groups_from_setting($this->id().'_groups');
        return ag_can_view() && ag_can_upload() && (bool)af_ag_get_setting($this->id().'_enabled',0)
            && (!$groups || ag_user_in_groups($groups));
    }
    public function supportsUrl(): bool {return (bool)af_ag_get_setting($this->id().'_allow_url',0);}
    public function upload(): void {ag_handle_external_upload($this);}
}
require_once __DIR__.'/providers/imgbb.php';

/** Trusted PHP extensions can register additional providers (e.g. Postimages). */
function ag_storage_providers(): array
{
    if (!isset($GLOBALS['ag_storage_providers'])) $GLOBALS['ag_storage_providers'] = [
        'local'=>new AG_LocalStorageProvider(), 'imgbb'=>new AG_ImgBBProvider()];
    return $GLOBALS['ag_storage_providers'];
}
function ag_register_storage_provider(AG_StorageProvider $provider): void
{
    if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/D',$provider->id()) || $provider->id()==='local') throw new InvalidArgumentException('Invalid external storage provider.');
    ag_storage_providers();$GLOBALS['ag_storage_providers'][$provider->id()]=$provider;
}

function ag_ensure_provider_schema(): void
{
    global $db;
    if (!$db->table_exists('af_gallery_external_media')) $db->write_query('CREATE TABLE '.TABLE_PREFIX."af_gallery_external_media (
        media_id INT UNSIGNED PRIMARY KEY, external_id VARCHAR(128) NOT NULL,
        viewer_url VARCHAR(1000) NOT NULL DEFAULT '', delete_url TEXT NOT NULL,
        availability VARCHAR(24) NOT NULL DEFAULT 'unknown', uploaded_at INT NOT NULL,
        KEY external_id (external_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$db->table_exists('af_gallery_provider_secrets')) $db->write_query('CREATE TABLE '.TABLE_PREFIX."af_gallery_provider_secrets (
        provider VARCHAR(32) PRIMARY KEY, api_key TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
/** MyBB's hide_errors flag prevents SQL error pages from exposing private values. */
function ag_provider_private_write(string $sql): void
{
    global $db;
    try { $ok=$db->write_query($sql,1); }
    catch (Throwable $e) {throw new RuntimeException('Не удалось сохранить служебные данные внешнего хранилища.');}
    if (!$ok) throw new RuntimeException('Не удалось сохранить служебные данные внешнего хранилища.');
}

function ag_provider_secret(string $provider): string
{
    global $db;
    if (!$db->table_exists('af_gallery_provider_secrets')) return '';
    return (string)$db->fetch_field($db->simple_select('af_gallery_provider_secrets','api_key',
        "provider='".$db->escape_string($provider)."'",['limit'=>1]),'api_key');
}
function ag_ensure_provider_settings(): void
{
    $gid=af_ag_ensure_group('af_advancedgallery_external','Внешние хранилища','AdvancedGallery: внешние провайдеры. API-ключ задаётся и просматривается только на административной странице Gallery. В пользовательскую галерею ключ не передаётся.');
    foreach ([
        ['enabled','Включить ImgBB','Загрузка на ImgBB через сервер форума. Требуется API-ключ и PHP cURL.','yesno','0'],
        ['max_mb','Максимальный размер изображения ImgBB (МБ)','Размер одного файла. Верхняя граница API — 32 000 000 байт. Локальная объёмная квота не расходуется.','numeric','10'],
        ['groups','Разрешённые группы ImgBB','ID групп MyBB через запятую. Пусто — все пользователи с правом загрузки Gallery.','text',''],
        ['allow_url','Разрешить загрузку на ImgBB через URL','Только прямые публичные HTTP/HTTPS-ссылки; сервер скачивает и проверяет временный файл.','yesno','0'],
        ['default','Показывать ImgBB как вариант хранилища по умолчанию','Применяется только если пользователь имеет право загрузки на ImgBB.','yesno','0'],
    ] as $index=>$setting) {
        [$key,$title,$desc,$type,$value]=$setting;
        af_ag_ensure_setting($gid,'af_advancedgallery_imgbb_'.$key,$title,$desc,$type,$value,$index+1);
    }
}

function ag_managed_external_image(array $media): bool
{
    $provider=ag_storage_providers()[$media['provider'] ?? ''] ?? null;
    return ($media['type'] ?? '')==='remote' && $provider instanceof AG_ExternalStorageProvider
        && $provider->validOriginalUrl((string)($media['remote_url'] ?? ''));
}
function ag_external_details(int $id): ?array
{
    global $db;
    if (!$db->table_exists('af_gallery_external_media')) return null;
    return $db->fetch_array($db->simple_select('af_gallery_external_media','media_id,external_id,viewer_url,availability,uploaded_at',"media_id='{$id}'",['limit'=>1])) ?: null;
}

function ag_handle_upload(): void
{
    global $mybb;
    if ($mybb->request_method!=='post') error_no_permission();
    verify_post_check($mybb->get_input('my_post_key'));
    $id=trim((string)$mybb->get_input('storage_provider')) ?: 'local';
    $provider=ag_storage_providers()[$id] ?? null;
    if (!$provider || !$provider->canUpload()) error_no_permission();
    $provider->upload();
}

/** API call runs before DB transaction. Only metadata and album links are persisted. */
function ag_handle_external_upload(AG_ExternalStorageProvider $provider): void
{
    global $db,$mybb;
    $path=null;$uploaded=false;
    try {
        if (!$provider->canUpload()) throw new RuntimeException('Загрузка в это хранилище недоступна.');
        if (!$db->table_exists('af_gallery_external_media') || !ag_system_album_schema_available()) throw new RuntimeException('Реактивируйте AdvancedGallery для обновления схемы внешних хранилищ.');
        $purpose=ag_request_appearance_purpose();$uid=(int)$mybb->user['uid'];
        $mode=(string)$mybb->get_input('external_source') ?: 'file';
        if ($mode==='url') {
            if (!$provider->supportsUrl()) throw new RuntimeException('Загрузка по URL отключена администратором.');
            $path=ag_download_external_image(trim((string)$mybb->get_input('external_url')),$provider->maxBytes());
            $name='image';
        } elseif ($mode==='file') {
            $file=$_FILES['ag_file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Выберите изображение с устройства.');
            $path=$file['tmp_name'];$name=(string)$file['name'];
        } else throw new RuntimeException('Неизвестный источник изображения.');
        $source=ag_external_image_metadata($path,$provider->maxBytes(),$name);
        if (in_array($purpose,['account_avatar','character_avatar'],true)
            && (!$provider->supportsAvatar() || !in_array($source['mime'],ag_avatar_allowed_mimes($purpose),true))) throw new RuntimeException('Этот формат не поддерживается выбранным аватаром.');
        // Browser album/UID never chooses the provider album's owner.
        $requested=(int)$mybb->get_input('album_id',MyBB::INPUT_INT);
        if ($requested>0) { $album=ag_get_album($requested);if (!$album || (int)$album['uid_owner']!==$uid) throw new RuntimeException('Альбом недоступен.'); }
        ag_lock_owner_upload($uid);
        $existing=ag_get_system_album($uid,'provider_'.$provider->id());
        if ($existing) ag_enforce_media_limits((int)$existing['id']);
        $title=ag_external_text((string)$mybb->get_input('ag_title'),120);
        $description=ag_external_text((string)$mybb->get_input('ag_description'),10000);
        $remote=$provider->sendFile($source);$uploaded=true;
        $db->write_query('START TRANSACTION');
        try {
            $albumId=ag_get_or_create_system_album($uid,'provider_'.$provider->id());
            $db->insert_query('af_gallery_media',[
                'uid_owner'=>$uid,'type'=>'remote','provider'=>$db->escape_string($provider->id()),
                'status'=>ag_is_autoapprove()?'approved':'pending','created_at'=>TIME_NOW,'updated_at'=>TIME_NOW,
                'title'=>$db->escape_string($title),'description'=>$db->escape_string($description),
                'tags'=>'','views'=>0,'original_name'=>$db->escape_string($source['name']),
                'storage_path'=>'','preview_path'=>'','thumb_path'=>$db->escape_string($remote['thumb']),
                'remote_url'=>$db->escape_string($remote['url']),'mime'=>$db->escape_string($remote['mime']),
                'ext'=>$db->escape_string($remote['ext']),'filesize'=>$source['bytes'],
                'width'=>$remote['width'],'height'=>$remote['height'],'embed_html'=>'',
            ]);
            $id=(int)$db->insert_id();
            $private=['media_id'=>$id,'external_id'=>$remote['external_id'], 'viewer_url'=>$remote['viewer'],
                'delete_url'=>$remote['delete_url'],'availability'=>$remote['availability'],'uploaded_at'=>TIME_NOW];
            $values=array_map(static fn($value)=>"'".$db->escape_string((string)$value)."'",array_values($private));
            ag_provider_private_write('INSERT INTO '.TABLE_PREFIX.'af_gallery_external_media ('.implode(',',array_keys($private)).') VALUES ('.implode(',',$values).')');
            $db->insert_query('af_gallery_album_media',['album_id'=>$albumId,'media_id'=>$id,'sort_order'=>0,'created_at'=>TIME_NOW]);
            $db->update_query('af_gallery_albums',['updated_at'=>TIME_NOW],"id='{$albumId}'");
            ag_album_autoset_cover_if_empty($albumId,$id);
            $db->write_query('COMMIT');
        } catch (Throwable $e) { $db->write_query('ROLLBACK');throw new RuntimeException('Не удалось сохранить запись Gallery.'); }
    } catch (RuntimeException $e) {
        $message=$uploaded ? $provider->label().' принял файл, но запись Gallery не сохранилась. Удаление файла на хостинге не подтверждено; обратитесь к администратору.' : $e->getMessage();
    } finally {if ($path && is_file($path)) @unlink($path);}
    if (isset($message)) {ag_upload_error($message);return;}
    if (ag_is_ajax_request()) ag_json_response(['ok'=>true,'id'=>$id,'media_id'=>$id,'album_id'=>$albumId,
        'provider'=>$provider->id(),'title'=>$title,'thumb'=>$remote['thumb'],'url_full'=>$remote['url'],
        'bbcode'=>'[img]'.$remote['url'].'[/img]','status'=>ag_is_autoapprove()?'approved':'pending','storage'=>ag_storage_usage($uid)]);
    redirect('gallery.php?action=view&id='.$id,'Изображение добавлено в Gallery.');
}

/** Public UI includes capabilities only, never keys or private provider metadata. */
function ag_provider_upload_controls(): string
{
    $providers=ag_storage_providers();$available=[];
    foreach ($providers as $id=>$provider) if ($provider->canUpload()) $available[$id]=$provider;
    if (count($available)<2) return '';
    $default=isset($available['imgbb']) && (int)af_ag_get_setting('imgbb_default',0) ? 'imgbb':'local';
    $html='<div class="ag-field"><label>Место хранения: <select name="storage_provider" data-ag-storage-provider>';
    foreach ($available as $id=>$provider) $html.='<option value="'.htmlspecialchars_uni($id).'" data-url-enabled="'.($provider->supportsUrl()?'1':'0').'"'.($id===$default?' selected':'').'>'.htmlspecialchars_uni($provider->label()).'</option>';
    return $html.'</select></label></div><div data-ag-external-options hidden><p>Изображение сохранится на внешнем хостинге и попадёт в ваш служебный альбом. Позже его можно переместить в свой альбом. Ссылка на изображение будет публичной.</p><label>Источник: <select name="external_source" data-ag-external-source><option value="file">С устройства</option><option value="url" data-ag-external-url-option>По URL</option></select></label><label data-ag-external-url hidden>Прямая ссылка <input type="url" name="external_url" placeholder="https://…"></label></div>';
}

function ag_external_avatar_providers(): array
{
    return array_filter(ag_storage_providers(),static fn($p)=>$p instanceof AG_ExternalStorageProvider && $p->supportsAvatar());
}
function ag_external_provider_sql(bool $avatarsOnly=false): string
{
    global $db;
    $ids=[];
    foreach (ag_storage_providers() as $id=>$provider) if ($provider instanceof AG_ExternalStorageProvider && (!$avatarsOnly || $provider->supportsAvatar())) $ids[]="'".$db->escape_string($id)."'";
    return $ids ? implode(',',$ids) : "''";
}

function ag_provider_album_accepts(array $album, array $media): bool
{
    $key=(string)($album['system_key'] ?? '');
    return !str_starts_with($key,'provider_') || (ag_managed_external_image($media)
        && $key==='provider_'.$media['provider'] && (int)$album['uid_owner']===(int)$media['uid_owner']);
}
