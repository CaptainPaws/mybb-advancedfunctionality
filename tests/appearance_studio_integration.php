<?php
/** Reuses the destructive, database-restricted stage-3 fixture. */
define('AA_STUDIO_FIXTURE',true);
require __DIR__.'/appearance_personal_integration.php';
$mybb->user['uid']=7;$id=af_aa_personal_list(7,'postbit')['presets'][0]['id'];
$json='{"display_title":"Мой постбит","description":"Новый стиль","components":{"topbar":{"background_color":"#123456","radius":8},"sidebar":{"background_media_id":1,"background_position":"top"},"message":{"font_family":"serif","font_size":18,"text_color":"#aabbcc","line_height":1.6,"letter_spacing":0.5},"signature":{"border_width":1,"decorative_line":"dashed"}},"avatar_media_id":1,"canvas":{"preset":"frost","speed":35}}';
$s=af_aa_personal_decode($json,7,true);
af_aa_personal_save(7,$id,$json);af_aa_personal_activate(7,'postbit',$id);
$row=af_aa_personal_owned_row(7,$id);appearance_check($row['title']==='Мой постбит'&&$row['description']==='Новый стиль','Publish metadata not atomic');
$css=af_aa_personal_render_css(7);$preview=af_aa_studio_preview(7,$json);
appearance_check($preview['css']===af_aa_personal_compile_css(7,$s,true),'Preview compiler diverged');
appearance_check(str_contains($css,'--aa-font_size:18px')&&str_contains($css,'uploads/mine.png'),'Runtime settings/media absent');
appearance_check(!preg_match('/[;{]position:/',$css)&&!str_contains($css,'grid-')&&!str_contains($css,'z-index'),'Unsafe CSS');
$shape=af_aa_studio_preview_markup(7);appearance_check(str_contains($shape,'data-element="fire"')&&str_contains($shape,'atf-post__signature')&&!str_contains($shape,'<form'),'Real template/identity/inert preview');
$old=file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/postbit_classic.html');appearance_check(str_contains($old,'atf-post__layout')&&str_contains($shape,'atf-post__layout'),'ATF source missing');
$mybb->settings['af_advancedappearance_personal_title_limit']=3;appearance_check(str_contains(af_aa_personal_render_css(7),'--aa-font_size'),'Lowered name quota removed style');$mybb->settings['af_advancedappearance_personal_title_limit']=80;
$before=af_aa_personal_owned_row(7,$id);af_aa_personal_save(7,$id,'{"display_title":"Черновое имя","components":{"message":{"text_color":"#445566"}}}',true);
$draft=af_aa_personal_owned_row(7,$id);appearance_check($before['title']===$draft['title']&&$before['settings_json']===$draft['settings_json'],'Draft published title or settings');
foreach(['{"components":[]}','{"components":{"grid":{}}}','{"components":{"message":{"position":"fixed"}}}','{"components":{"topbar":{"padding":25}}}','{"components":{"message":{"font_size":true}}}','{"components":{"message":{"background_color":"url(x)"}}}','{"components":{"message":{"background_media_id":2}}}'] as $bad)appearance_fails(fn()=>af_aa_studio_preview(7,$bad),422);
$round=af_aa_personal_encode(af_aa_personal_decode('{"components":{"topbar":{},"message":{}}}',7,false));appearance_check(is_array(af_aa_personal_decode($round,7,false)),'Empty component roundtrip broken');
appearance_check(af_aa_studio_canvas(7,$s)===[] && !isset($s['canvas']),'Postbit effects must ignore legacy values');
appearance_check(af_aa_studio_canvas(9,$s)===[],'Approval gate bypassed');
$GLOBALS['af_elementtheme_overrides']['styles']['fire']['effects']['enabled']=false;appearance_check(af_aa_studio_canvas(7,$s)===[],'ACP effect switch bypassed');

$post=['uid'=>7,'username'=>'Fixture 7','useravatar'=>'native-avatar'];af_aa_studio_postbit_avatar($post);appearance_check($post['useravatar']==='native-avatar'&&str_contains($post['af_atf_primary_avatar'],'uploads/mine.png'),'ATF avatar override changed native avatar or failed');
af_aa_personal_rename(7,$id,'Новое имя');$renamed=af_aa_personal_owned_row(7,$id);appearance_check(json_decode($renamed['settings_json'],true)['display_title']==='Новое имя'&&json_decode($renamed['draft_json'],true)['display_title']==='Новое имя','Existing rename API left stale builder metadata');
$mybb->settings['af_advancedappearance_free_slots_postbit']=2;$clone=af_aa_personal_clone(7,$id,'Копия');$copy=af_aa_personal_owned_row(7,$clone);appearance_check(json_decode($copy['settings_json'],true)['display_title']==='Копия'&&json_decode($copy['draft_json'],true)['display_title']==='Копия','Clone retained source display title');af_aa_personal_delete(7,$clone);$mybb->settings['af_advancedappearance_free_slots_postbit']=1;
$mybb->user['uid']=7;af_aa_personal_save(7,$id,'{}');
echo "Studio integration passed: typed components, bounded compiler, Gallery ownership, atomic metadata, separate draft, real ATF seed, disabled postbit Canvas, empty-object roundtrip.\n";

$shape=af_aa_studio_preview_markup(7);
appearance_check(str_contains($shape,'Личное звание персонажа')&&str_contains($shape,'data-aa-empty-field="1"'),'Stored/empty profile fields absent');
appearance_check(!str_contains($shape,'Private field')&&!str_contains($shape,'★')&&!str_contains($shape,'Участник'),'Hidden field or fake rank leaked');
appearance_check(str_contains($shape,'fa-solid fa-user')&&str_contains($shape,'title="Профиль"'),'Shared profile icon/tooltip absent');
$fields=af_apf_postbit_field_definitions();
appearance_check(isset($fields[1],$fields[2])&&!isset($fields[3]),'Non-postbit field exposed');
$s=af_aa_personal_decode('{"components":{"profile_field_1":{"text_color":"#123456","font_size":19},"user_status":{"text_color":"#654321"}}}',7,false,false);
$css=af_aa_personal_compile_css(7,$s,true);
appearance_check(str_contains($css,'.af-apf-postbit-field--fid1')&&str_contains($css,'#123456'),'Field style compiler diverged');
appearance_check(!str_contains(af_apf_render_postbit_fields(['fid1'=>'Original'],false),'data-aa-empty-field'),'Public renderer shows empty preview labels');
$value=$db->fetch_array($db->simple_select('userfields','*','ufid=7'));
appearance_check($value['fid1']==='Личное звание персонажа'&&$value['fid2']==='','Preview modified real profile fields');
echo "Studio fields passed: stored/empty/hidden fields, stable ID styling, real profile icon/tooltip, no fake rank or profile writes.\n";

$escaped=af_apf_render_postbit_fields(['fid1'=>'<script>alert(1)</script>'],true);
appearance_check(!str_contains($escaped,'<script>')&&str_contains($escaped,'&lt;script&gt;'),'Native field parser HTML policy bypassed');
