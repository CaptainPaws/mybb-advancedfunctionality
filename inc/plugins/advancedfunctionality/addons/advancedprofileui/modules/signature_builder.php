<?php
/** One AF content model, a draft, and a portable MyBB signature synchronized only after native validation. */
if (!defined('IN_MYBB')) die('No direct access');
require_once __DIR__.'/signature_builder_model.php';
function af_apui_sig_install(): void {
    global $db;
    $db->write_query('CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX."af_apui_signature_builder (
        uid INT UNSIGNED NOT NULL PRIMARY KEY, draft_json MEDIUMTEXT NULL, published_json MEDIUMTEXT NULL,
        published_hash VARCHAR(64) NOT NULL DEFAULT '', legacy_backup MEDIUMTEXT NULL, updated_at INT UNSIGNED NOT NULL DEFAULT 0
    )".$db->build_create_table_collation());
    unset($GLOBALS['af_apui_sig_available']);
}
function af_apui_sig_available(): bool {return $GLOBALS['af_apui_sig_available']??=isset($GLOBALS['db']) && $GLOBALS['db']->table_exists('af_apui_signature_builder');}
function af_apui_sig_row(int $uid): ?array {
    global $db;
    if (!af_apui_sig_available()) return null;
    if (!array_key_exists($uid,$GLOBALS['af_apui_sig_rows']??[])) {
        $row=$db->fetch_array($db->query('SELECT b.*,u.signature AS native_signature FROM '.TABLE_PREFIX.'af_apui_signature_builder b INNER JOIN '.TABLE_PREFIX."users u ON u.uid=b.uid WHERE b.uid={$uid}"));
        $GLOBALS['af_apui_sig_rows'][$uid]=$row?:null;
    }
    return $GLOBALS['af_apui_sig_rows'][$uid];
}
function af_apui_sig_write(int $uid,array $fields): void {
    global $db;
    if (!af_apui_sig_available()) throw new RuntimeException('Обновите AdvancedProfileUI через ACP.');
    $row=$db->fetch_array($db->simple_select('af_apui_signature_builder','uid',"uid={$uid}"));
    $fields['updated_at']=defined('TIME_NOW')?TIME_NOW:time();
    foreach ($fields as $key=>$value) if (is_string($value)) $fields[$key]=$db->escape_string($value);
    if ($row) $db->update_query('af_apui_signature_builder',$fields,"uid={$uid}");else {$fields['uid']=$uid;$db->insert_query('af_apui_signature_builder',$fields);}
    unset($GLOBALS['af_apui_sig_rows'][$uid]);
}
function af_apui_sig_owned(?array $row,string $native): bool {return $row && !empty($row['published_json']) && !empty($row['published_hash']) && hash_equals($row['published_hash'],hash('sha256',$native));}
function af_apui_sig_preview(array $model,int $uid): array {
    $html=af_apui_sig_render($model,!isset($GLOBALS['mybb']->user['showimages']) || (int)$GLOBALS['mybb']->user['showimages']===1);
    $key=function_exists('af_adaptivethemeframework_post_element')?af_adaptivethemeframework_post_element($uid):'';
    if (function_exists('af_adaptivethemeframework_signature_markup')) $html=af_adaptivethemeframework_signature_markup($html,'builder-preview',af_adaptivethemeframework_gothic_frame(),true);
    $html='<div id="aa-studio-preview"><article class="atf-post af-atf-display af-aa-postbit-user-'.$uid.'" data-element="'.htmlspecialchars_uni($key).'" data-element-surface="postbit"><div class="atf-post__content">'.$html.'</div></article></div>';
    $css='';if (function_exists('af_aa_surface_settings') && ($s=af_aa_surface_settings($uid,'postbit'))!==null) $css=af_aa_personal_compile_css($uid,$s,true);
    return ['html'=>$html,'css'=>$css];
}
function af_apui_sig_api(string $operation,int $uid): array {
    global $mybb;
    if ($uid!==(int)($mybb->user['uid']??0) || !af_apui_signature_can_edit() || $mybb->request_method!=='post' || !verify_post_check($mybb->get_input('my_post_key'),true)) throw new RuntimeException('Нет доступа или неверный CSRF-токен.');
    $v=af_apui_sig_validate(af_apui_sig_decode($mybb->get_input('af_signature_model')),$uid);
    if ($operation==='draft') af_apui_sig_write($uid,['draft_json'=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    elseif ($operation!=='preview') throw new InvalidArgumentException('Неизвестное действие.');
    return af_apui_sig_preview($v,$uid)+['message'=>$operation==='draft'?'Шаблон сохранён как черновик.':'Предпросмотр обновлён.'];
}
/** Runs before UserDataHandler::verify_signature(), never replaces it. */
function af_apui_sig_start(): void {
    global $mybb,$error;
    $action=(string)$mybb->get_input('action');if (!in_array($action,['editsig','do_editsig'],true)) return;
    $uid=(int)($mybb->user['uid']??0);$api=(string)$mybb->get_input('af_sig_api');
    if ($api!=='') {
        $GLOBALS['af_disable_pre_output']=true; // JSON embeds HTML; response-aware asset hooks must not rewrite it.
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
        try {echo json_encode(['ok'=>true,'data'=>af_apui_sig_api($api,$uid)],JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR);}
        catch(Throwable $e){http_response_code($e instanceof InvalidArgumentException?422:403);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_HEX_TAG|JSON_HEX_AMP);}
        exit;
    }
    if ($action!=='do_editsig' || $mybb->request_method!=='post' || (string)$mybb->get_input('af_signature_builder')!=='1') return;
    try {
        if (!af_apui_signature_can_edit() || !verify_post_check($mybb->get_input('my_post_key'),true)) throw new RuntimeException('Нет доступа или неверный CSRF-токен.');
        if (!af_apui_sig_available()) throw new RuntimeException('Обновите AdvancedProfileUI через ACP.');
        $v=af_apui_sig_decode($mybb->get_input('af_signature_model'));$GLOBALS['af_apui_sig_submitted']=$v;
        $v=af_apui_sig_validate($v,$uid,true,true);
        $native=(string)($mybb->user['signature']??'');
        if ($native!=='' && !af_apui_sig_owned(af_apui_sig_row($uid),$native) && (string)$mybb->get_input('af_sig_confirm_replace')!=='1') throw new InvalidArgumentException('Подтвердите переход: исходная подпись будет сохранена в резервной копии.');
        $compiled=af_apui_sig_compile($v);
        $mybb->input['signature']=$compiled;$GLOBALS['af_apui_sig_pending']=['model'=>$v,'compiled'=>$compiled,'legacy'=>$native];
    }catch(Throwable $e){$error=inline_error([htmlspecialchars_uni($e->getMessage())]);$mybb->input['action']='editsig';}
}
/** MyBB has accepted length/images/group rules and CSRF. Keep a recoverable draft before its update. */
function af_apui_sig_publish_start(): void {
    global $mybb;
    if (!isset($GLOBALS['af_apui_sig_pending'])) return;
    $p=$GLOBALS['af_apui_sig_pending'];$uid=(int)$mybb->user['uid'];$row=af_apui_sig_row($uid);
    $fields=['draft_json'=>json_encode($p['model'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
    if (!$row || $row['legacy_backup']===null) $fields['legacy_backup']=$p['legacy'];
    foreach (array_merge([$p['model']['background']],$p['model']['images']) as $image) if ($image['media_id'] && (!function_exists('ag_retain_owned_media') || !ag_retain_owned_media($uid,$image['media_id']))) error('Gallery: не удалось закрепить изображение.');
    af_apui_sig_write($uid,$fields);
}
function af_apui_sig_publish_end(): void {
    global $db,$mybb;
    if (!isset($GLOBALS['af_apui_sig_pending'])) return;
    $p=$GLOBALS['af_apui_sig_pending'];$uid=(int)$mybb->user['uid'];
    $actual=(string)$db->fetch_field($db->simple_select('users','signature',"uid={$uid}"),'signature');
    if ($actual!==$p['compiled']) {error_log('[APUI] Native signature changed by another save hook; builder remains a draft.');return;}
    af_apui_sig_write($uid,['published_json'=>json_encode($p['model'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'published_hash'=>hash('sha256',$actual),'draft_json'=>null]);
    $GLOBALS['af_apui_sig_committed']=true;
}
/** Native visibility/suspension/show-sig gates ran before this hook. */
function af_apui_sig_postbit(array &$post): void {
    if (trim((string)($post['signature']??''))==='' || !af_apui_is_enabled()) return;
    $uid=(int)($post['uid']??0);if ($uid<1) return;$row=af_apui_sig_row($uid);
    if (!af_apui_sig_owned($row,(string)($row['native_signature']??''))) return;
    try {$v=af_apui_sig_validate(af_apui_sig_decode($row['published_json']),$uid,false);}
    catch(Throwable $e){return;}
    $post['signature']=af_apui_sig_render($v,!isset($GLOBALS['mybb']->user['showimages']) || (int)$GLOBALS['mybb']->user['showimages']===1);
}
function af_apui_sig_studio_url(int $uid): string {
    $url='apstudio.php?target=postbit&component=signature';
    if (function_exists('af_aa_personal_active_row') && ($row=af_aa_personal_active_row($uid))) $url.='&edit='.(int)$row['id'];
    return $url;
}
function af_apui_sig_page(): void {
    global $mybb,$templates,$headerinclude,$af_apui_signature_builder_html,$signature;
    if (!af_apui_signature_can_edit()) return;
    if (function_exists('af_frontend_asset_allowed') && !af_frontend_asset_allowed(AF_APUI_ID,'signature_builder')) return;
    $uid=(int)$mybb->user['uid'];$row=af_apui_sig_row($uid);$native=(string)($mybb->user['signature']??'');$owned=af_apui_sig_owned($row,$native);
    $model=af_apui_sig_defaults();
    try {
        if (isset($GLOBALS['af_apui_sig_submitted'])) $model=$GLOBALS['af_apui_sig_submitted'];
        elseif (!empty($row['draft_json'])) $model=af_apui_sig_decode($row['draft_json']);
        elseif ($owned) $model=af_apui_sig_decode($row['published_json']);
    }catch(Throwable $e){} // Never replace the existing native signature merely by visiting.
    $model=af_apui_sig_seed_model($model);
    $preview=af_apui_sig_preview(af_apui_sig_defaults(),$uid);
    try {$preview=af_apui_sig_preview(af_apui_sig_validate($model,$uid,true),$uid);}catch(Throwable $e){}
    $base=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/';
    $version=max(filemtime(__DIR__.'/../assets/signature-builder.js'),filemtime(__DIR__.'/../assets/signature-builder.css'));
    $headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni($base.'signature-builder.css?v='.$version).'"><script src="'.htmlspecialchars_uni($base.'signature-builder.js?v='.$version).'" defer></script>';
    $mediaUrls=[];foreach(array_merge([$model['background']],$model['images']) as $image) {
        $url='';try {$checked=af_apui_sig_validate(['background'=>$image],$uid,true);$url=af_apui_sig_image_url(array_replace($checked['background'],['enabled'=>true]));}catch(Throwable $e){}
        $mediaUrls[]=$url;
    }
    $seed=['media_urls'=>$mediaUrls,'model'=>$model,'defaults'=>af_apui_sig_defaults(),'templates'=>af_apui_sig_templates(),'limits'=>af_apui_sig_limits(),'csrf'=>$mybb->post_code,'uid'=>$uid,'studio'=>af_apui_sig_studio_url($uid),'image_allowed'=>!empty($mybb->settings['sigmycode']) && !empty($mybb->settings['sigimgcode'])];
    $legacy=$native!=='' && !$owned?'<section class="af-sig-builder__legacy"><h2>Существующая подпись сохранена</h2><p>Произвольный BBCode/HTML нельзя автоматически разложить на поля конструктора. Открытие страницы и сохранение черновика не меняют эту подпись.</p>'.$signature.'<label><input type="checkbox" name="af_sig_confirm_replace" value="1"> Заменить подпись композицией конструктора; исходник сохранить в резервной копии.</label></section>':'';
    $backup=!empty($row['legacy_backup'])?'<details><summary>Исходная подпись до перехода</summary><pre>'.htmlspecialchars_uni($row['legacy_backup']).'</pre></details>':'';
    $status=(string)$mybb->get_input('af_sig_saved')==='1'?'Подпись обновлена.':'';
    $af_apui_signature_builder_html='<form method="post" action="usercp.php" class="af-sig-builder" data-af-signature-builder data-aa-gallery-picker><input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'"><input type="hidden" name="action" value="do_editsig"><input type="hidden" name="af_signature_builder" value="1"><input type="hidden" name="af_signature_model" data-af-sig-model><textarea name="signature" hidden data-af-ae-skip="1">'.htmlspecialchars_uni($native).'</textarea>'.$legacy.$backup.'<p role="status" aria-live="polite" data-af-sig-status>'.$status.'</p><div class="af-sig-builder__columns"><div data-af-sig-controls></div><aside><section class="af-sig-builder__panel"><h2>Предпросмотр подписи</h2><style data-af-sig-preview-style>'.$preview['css'].'</style><div data-af-sig-preview>'.$preview['html'].'</div></section><section class="af-sig-builder__panel"><h2>Оформление в APStudio</h2><p>Цвета, шрифты, стиль кнопок, декоративные элементы и общий визуальный вид подписи настраиваются в APStudio.</p><a class="atf-button" href="'.htmlspecialchars_uni(af_apui_sig_studio_url($uid)).'">Настроить оформление в APStudio →</a></section></aside></div><fieldset class="af-sig-builder__panel"><legend>Подпись в опубликованных сообщениях</legend><label><input type="radio" name="updateposts" value="0" checked> Оставить текущие настройки</label><label><input type="radio" name="updateposts" value="enable"> Включить во всех моих сообщениях</label><label><input type="radio" name="updateposts" value="disable"> Отключить во всех моих сообщениях</label></fieldset><div class="af-sig-builder__actions"><button type="button" data-af-sig-draft>Сохранить шаблон</button><button type="button" data-af-sig-preview-button>Предпросмотр</button><button type="submit" class="atf-button" disabled>Обновить подпись</button></div><script type="application/json" data-af-sig-seed>'.json_encode($seed,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE).'</script><noscript>Для конструктора нужен JavaScript. Существующая подпись не меняется.</noscript></form>';
    $source=$templates->get('usercp_editsig',1,0);
    $source=preg_replace_callback('~<form\b[^>]*>.*?</form>~is',static fn($m)=>preg_match('~<textarea\b[^>]*name=["\']signature["\']~i',$m[0])?'{$af_apui_signature_builder_html}':$m[0],$source);
    $source=str_replace(['{$signature}','{$lang->edit_sig}'],['','Конструктор подписи'],$source);
    $templates->cache['usercp_editsig']=$source;
}
