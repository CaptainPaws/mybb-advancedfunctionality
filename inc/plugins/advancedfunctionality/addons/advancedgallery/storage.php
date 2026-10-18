<?php
/** Local media accounting. Originals are counted once, independent of album/status. */
if (!defined('IN_MYBB')) die('No direct access');
require_once __DIR__.'/storage_disk.php';

function ag_upload_error(string $message): void
{
    if (ag_is_ajax_request()) ag_json_response(['ok'=>false, 'error'=>$message], 422);
    error($message);
}

function ag_storage_exempt(int $uid): bool
{
    global $mybb, $cache;
    if ($uid <= 0 || $uid !== (int)($mybb->user['uid'] ?? 0)
        || !(int)af_ag_get_setting('storage_exempt_staff', 1)) return false;
    if (ag_can_moderate() || !empty($mybb->usergroup['cancp']) || !empty($mybb->usergroup['issupermod'])) return true;
    $groups = isset($cache) ? $cache->read('usergroups') : [];
    foreach (ag_get_user_groups() as $gid) {
        if (!empty($groups[$gid]['cancp']) || !empty($groups[$gid]['issupermod'])) return true;
    }
    return function_exists('is_moderator') && is_moderator(0, '', $uid);
}

function ag_storage_usage(int $uid): array
{
    global $db;
    $used = max(0, (int)$db->fetch_field($db->simple_select('af_gallery_media',
        'COALESCE(SUM(filesize),0) AS used', "uid_owner='{$uid}' AND type='local'"), 'used'));
    $limit = ag_storage_exempt($uid) ? 0 : max(0, (float)af_ag_get_setting('max_storage_mb', 0)) * 1048576;
    return ['used_bytes'=>$used, 'limit_bytes'=>$limit, 'available_bytes'=>$limit > 0 ? max(0, $limit-$used) : null,
        'percent'=>$limit > 0 ? min(100, $used/$limit*100) : null, 'full'=>$limit > 0 && $used >= $limit];
}

function ag_storage_mb(float $bytes): string
{
    return number_format($bytes / 1048576, 2, '.', '');
}

function ag_storage_widget(): string
{
    global $mybb;
    if ((int)($mybb->user['uid'] ?? 0) <= 0) return '';
    $s = ag_storage_usage((int)$mybb->user['uid']);
    $html = '<section class="ag-storage" data-ag-storage aria-live="polite"><p data-ag-storage-used>Использовано: '.ag_storage_mb($s['used_bytes']).' МБ';
    $html .= $s['limit_bytes'] > 0 ? ' из '.ag_storage_mb($s['limit_bytes']).' МБ' : ' · Без ограничений';
    $html .= '</p><progress data-ag-storage-progress max="100" value="'.(float)$s['percent'].'"'.($s['limit_bytes'] ? '' : ' hidden').' aria-label="Заполнение пользовательской квоты"></progress>';
    $html .= '<p data-ag-storage-available'.($s['limit_bytes'] ? '' : ' hidden').'>Доступно: '.ag_storage_mb((float)$s['available_bytes']).' МБ</p>';
    return $html.'<p data-ag-storage-warning role="status"'.($s['full'] ? '' : ' hidden').'>Квота хранилища исчерпана. Удалите ненужные локальные файлы, чтобы загрузить новые. Существующие изображения остаются доступны.</p><p data-ag-storage-result></p></section>';
}

/** Database aggregates; EXISTS keeps multi-album files from being counted twice. */
function ag_storage_admin_stats(): array
{
    global $db;
    $stats = $db->fetch_array($db->simple_select('af_gallery_media',
        "SUM(CASE WHEN type='local' THEN 1 ELSE 0 END) AS local_files, COUNT(DISTINCT CASE WHEN type='local' THEN uid_owner END) AS users, COALESCE(SUM(CASE WHEN type='local' THEN filesize ELSE 0 END),0) AS bytes, SUM(CASE WHEN type='remote' THEN 1 ELSE 0 END) AS remote_files")) ?: [];
    if (ag_system_album_schema_available()) {
        $q = $db->write_query("SELECT COUNT(*) AS files, COALESCE(SUM(m.filesize),0) AS bytes FROM ".TABLE_PREFIX."af_gallery_media m WHERE m.type='local' AND EXISTS (SELECT 1 FROM ".TABLE_PREFIX."af_gallery_album_media am INNER JOIN ".TABLE_PREFIX."af_gallery_albums a ON a.id=am.album_id WHERE am.media_id=m.id AND a.system_key IS NOT NULL)");
        $stats['system'] = $db->fetch_array($q);
    }
    return $stats;
}

function ag_storage_admin_widget(): string
{
    $s = ag_storage_admin_stats();
    $html = '<section class="af-box"><h3>Локальное хранилище Gallery</h3><dl>';
    foreach (['Всего локальных файлов'=>(int)($s['local_files'] ?? 0), 'Пользователей с локальными файлами'=>(int)($s['users'] ?? 0),
        'Суммарный объём локальных оригиналов'=>ag_storage_mb((float)$s['bytes']).' МБ', 'Внешних изображений (не входят в объём)'=>(int)($s['remote_files'] ?? 0)] as $label=>$value) {
        $html .= '<dt>'.htmlspecialchars_uni($label).'</dt><dd>'.$value.'</dd>';
    }
    if (isset($s['system'])) {
        $html .= '<dt>В системных альбомах</dt><dd>'.(int)$s['system']['files'].' файлов · '.ag_storage_mb((float)$s['system']['bytes']).' МБ</dd>';
        $html .= '<dt>Остальные локальные файлы</dt><dd>'.((int)$s['local_files']-(int)$s['system']['files']).' файлов · '.ag_storage_mb((float)$s['bytes']-(float)$s['system']['bytes']).' МБ</dd>';
    }
    $html .= '</dl><p>Оригиналы учитываются один раз, включая все статусы модерации. Миниатюры не входят в пользовательскую квоту.</p>';
    $html .= ag_storage_disk_widget(ag_storage_disk());
    return $html.'</section>';
}
