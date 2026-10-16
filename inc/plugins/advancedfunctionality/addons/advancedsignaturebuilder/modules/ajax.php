<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_api(string $operation,int $uid): array {
    global $mybb;
    if ($uid!==(int)($mybb->user['uid']??0) || !af_asb_enabled() || !af_asb_can_edit() || !af_asb_asset_allowed('editor') || $mybb->request_method!=='post' || !verify_post_check($mybb->get_input('my_post_key'),true)) throw new RuntimeException('Нет доступа или неверный CSRF-токен.');
    $v=af_asb_validate(af_asb_decode($mybb->get_input('af_signature_model')),$uid);
    if ($operation==='draft') af_asb_write($uid,['draft_json'=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    elseif ($operation!=='preview') throw new InvalidArgumentException('Неизвестное действие.');
    return af_asb_preview($v,$uid)+['message'=>$operation==='draft'?'Шаблон сохранён как черновик.':'Предпросмотр обновлён.'];
}
