<?php
if (!defined('IN_MYBB')) die('No direct access');

function ag_provider_admin_save(): string
{
    global $db,$mybb;
    ag_ensure_provider_schema();ag_ensure_provider_settings();
    $key=trim((string)$mybb->get_input('imgbb_api_key'));
    $groups=trim((string)$mybb->get_input('imgbb_groups'));
    $max=(int)$mybb->get_input('imgbb_max_mb',MyBB::INPUT_INT);
    if (($key!=='' && !preg_match('/^[a-zA-Z0-9_-]{16,256}$/D',$key)) || !preg_match('/^(?:\d+(?:\s*,\s*\d+)*)?$/D',$groups)
        || $max<1 || $max>32) return 'Проверьте формат ключа, ID групп и размер файла (1–32 МБ).';
    foreach (['enabled','allow_url','default'] as $name) {
        $db->update_query('settings',['value'=>$mybb->get_input('imgbb_'.$name)?'1':'0'],"name='af_advancedgallery_imgbb_{$name}'");
    }
    $db->update_query('settings',['value'=>(string)$max],"name='af_advancedgallery_imgbb_max_mb'");
    $db->update_query('settings',['value'=>$db->escape_string($groups)],"name='af_advancedgallery_imgbb_groups'");
    if ($mybb->get_input('imgbb_remove_key')) $db->delete_query('af_gallery_provider_secrets',"provider='imgbb'");
    elseif ($key!=='') {
        try {ag_provider_private_write("INSERT INTO ".TABLE_PREFIX."af_gallery_provider_secrets (provider,api_key) VALUES ('imgbb','".$db->escape_string($key)."') ON DUPLICATE KEY UPDATE api_key=VALUES(api_key)");}
        catch (RuntimeException $e) {return 'Не удалось сохранить API-ключ. Повторите попытку после проверки БД.';}
    }
    if (function_exists('af_rebuild_and_reload_settings')) af_rebuild_and_reload_settings();
    elseif (function_exists('rebuild_settings')) rebuild_settings();
    return 'Настройки внешних хранилищ сохранены.';
}

function ag_provider_admin_widget(): string
{
    global $db,$mybb;
    $h='<section class="af-box"><h3>Внешние хранилища</h3><form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedgallery&amp;action=external_save">'
        .'<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'">';
    foreach (['enabled'=>'Включить ImgBB','allow_url'=>'Разрешить загрузку через URL','default'=>'ImgBB выбран по умолчанию'] as $key=>$label) {
        $h.='<p><label><input type="checkbox" name="imgbb_'.$key.'" value="1"'.(af_ag_get_setting('imgbb_'.$key,0)?' checked':'').'> '.$label.'</label></p>';
    }
    $h.='<p><label>Максимальный размер изображения (МБ) <input type="number" min="1" max="32" name="imgbb_max_mb" value="'.(int)af_ag_get_setting('imgbb_max_mb',10).'"></label></p>'
        .'<p><label>Разрешённые ID групп MyBB через запятую <input name="imgbb_groups" value="'.htmlspecialchars_uni((string)af_ag_get_setting('imgbb_groups','')).'"></label></p>'
        .'<p><label>ImgBB API Key <input type="password" name="imgbb_api_key" autocomplete="new-password" value=""></label> '
        .(ag_provider_secret('imgbb')!==''?'Ключ сохранён.':'Ключ не задан.').' Оставьте поле пустым, чтобы сохранить ключ.</p>'
        .'<p><label><input type="checkbox" name="imgbb_remove_key" value="1"> Удалить сохранённый ключ</label></p><button type="submit" class="button">Сохранить внешние хранилища</button></form>';
    if (!$db->table_exists('af_gallery_external_media')) return $h.'<p>Реактивируйте Gallery для добавления таблиц внешних хранилищ.</p></section>';
    $page=max(1,(int)$mybb->get_input('external_page',MyBB::INPUT_INT));$offset=($page-1)*50;
    $query=$db->write_query('SELECT m.id,m.uid_owner,m.provider,m.status,m.title,e.external_id,e.viewer_url,e.availability FROM '.TABLE_PREFIX.'af_gallery_media m INNER JOIN '.TABLE_PREFIX.'af_gallery_external_media e ON e.media_id=m.id ORDER BY m.id DESC LIMIT '.$offset.',50');
    $h.='<h4>Загруженные внешние изображения</h4><table class="general"><tr><th>ID Gallery / владелец</th><th>Провайдер / ID</th><th>Изображение</th><th>Публикация / доступность при загрузке</th></tr>';
    while ($row=$db->fetch_array($query)) $h.='<tr><td>'.(int)$row['id'].' / UID '.(int)$row['uid_owner'].'</td><td>'.htmlspecialchars_uni($row['provider'].' / '.$row['external_id']).'</td><td><a rel="noopener noreferrer" href="'.htmlspecialchars_uni($row['viewer_url']).'">'.htmlspecialchars_uni($row['title']?:'Открыть на хостинге').'</a></td><td>'.htmlspecialchars_uni($row['status'].' / '.$row['availability']).'</td></tr>';
    $h.='</table><p>Доступность фиксируется при загрузке; автоматические HTTP-проверки при открытии страницы не выполняются. Удаление записи Gallery не удаляет файл с хостинга.</p>';
    if ($page>1) $h.='<a href="index.php?module=advancedfunctionality&amp;af_view=advancedgallery&amp;external_page='.($page-1).'">Назад</a> ';
    $h.='<a href="index.php?module=advancedfunctionality&amp;af_view=advancedgallery&amp;external_page='.($page+1).'">Следующая страница</a>';
    return $h.'</section>';
}
