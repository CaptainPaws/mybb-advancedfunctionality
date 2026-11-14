<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/AmbushController.php';

/** Loaded only inside the explicitly opened GM rounds panel. */
final class AF_ACS_AmbushPage
{
    public static function render(string $sid,string $eid,string $tid,?AF_ACS_AmbushPanelService $service=null):string
    {
        global $mybb,$db;
        $dto=($service??new AF_ACS_AmbushPanelService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess))->snapshot($sid,$eid);
        if($dto['tid']!==$tid)throw new RuntimeException('ACS_AMBUSH_UNAVAILABLE');
        if(!function_exists('af_frontend_asset_allowed')||!af_frontend_asset_allowed('advancedcombatsystem','ambush',null,['has_acs_ambush'=>true]))return '<p>Интерфейс засады недоступен.</p>';
        $esc=static fn($v)=>htmlspecialchars_uni((string)$v);
        $config=json_encode(['context'=>$dto,'post_key'=>$mybb->post_code,'endpoint'=>'xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem'],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
        $asset=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/ambush.js?v=0922c1';
        return '<section data-acs-ambush aria-label="Старт из засады"><h3>Засада</h3><p>Подготовка оплачивается до первого раунда. Мастер не получает прав на чужие предметы.</p><a href="'.$esc('showthread.php?tid='.$tid.'&acs_builder='.$sid.'&acs_encounter='.$eid).'">Подготовить действие в конструкторе</a><div data-part="summary"></div><div data-part="groups"></div><p data-part="notice" role="status" aria-live="polite"></p><label>Причина <input data-field="reason" maxlength="1000" required></label><div><button type="button" data-op="ready" disabled>Перейти к готовности</button> <button type="button" data-op="start" disabled>Начать засаду</button> <button type="button" data-op="status">Проверить результат запуска</button> <button type="button" data-op="resume" hidden>Продолжить прежний запрос</button></div><noscript>Для управления засадой нужен JavaScript. Просмотр страницы не запускает столкновение.</noscript><script type="application/json" data-acs-ambush-config>'.$config.'</script></section><script src="'.$esc($asset).'" defer></script>';
    }
}
