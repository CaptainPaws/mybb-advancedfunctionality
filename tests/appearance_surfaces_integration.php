<?php
require __DIR__.'/appearance_surfaces_fixture.php';
foreach(['af_aa_slot_grants','af_aa_assignments','af_aa_presets','af_gallery_media'] as $table)$db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);
af_aa_ensure_schema();af_aa_personal_install_schema();
$db->write_query('CREATE TABLE '.TABLE_PREFIX."af_gallery_media (id INT PRIMARY KEY,uid_owner INT,type VARCHAR(16),status VARCHAR(16),mime VARCHAR(80),storage_path VARCHAR(255),preview_path VARCHAR(255),thumb_path VARCHAR(255),appearance_retained TINYINT DEFAULT 0) ENGINE=InnoDB");
$db->write_query('INSERT INTO '.TABLE_PREFIX."af_gallery_media VALUES (1,7,'local','approved','image/png','uploads/mine.png','','',0),(2,8,'local','approved','image/png','uploads/foreign.png','','',0)");
$ids=[];
foreach(['profile','sheet','theme'] as $target){
    $component=$target==='profile'?'banner':($target==='sheet'?'hero':'cards');
    $settings=['components'=>['page'=>['background_media_id'=>1,'background_color'=>'#123456'],$component=>['text_color'=>'#f077aa','font_family'=>'serif','radius'=>12]]];
    $json=json_encode($settings);$id=af_aa_personal_create(7,$target,'Test '.$target,$json);$ids[$target]=$id;
    appearance_check(af_aa_personal_list(7,$target)['slots']['available']===0,'Independent slot not consumed: '.$target);
    appearance_fails(fn()=>af_aa_personal_create(7,$target,'Overflow','{}'),409);
    $preview=af_aa_studio_preview(7,$json,$target);
    appearance_check(str_contains($preview['css'],'#aa-studio-preview') && str_contains($preview['css'],'#f077aa'),'Preview compiler failed: '.$target);
    $html=af_aa_studio_surface_preview_markup(7,$target);
    appearance_check(!str_contains($html,'{$'),'Unresolved template variable: '.$target);
    appearance_check(str_contains($html,$target==='profile'?'atf-profile':($target==='sheet'?'af-cs-page':'atf-forum-category')),'Native template missing.');
    af_aa_personal_save(7,$id,$json,true);appearance_check(af_aa_personal_owned_row(7,$id)['draft_json']!==null,'Draft missing.');
    af_aa_personal_save(7,$id,$json);af_aa_personal_activate(7,$target,$id);
    appearance_check((int)af_aa_personal_active_row(7,$target)['id']===$id,'Assignment not independent.');
    $css=af_aa_personal_compile_css(7,af_aa_surface_settings(7,$target),false,$target);
    appearance_check(!str_contains($css,'af-aa-postbit-user-'),'Other surface leaked into postbit.');
    appearance_fails(fn()=>af_aa_personal_decode('{"css":"body{}"}',7,false,true,false,$target),422);
    appearance_fails(fn()=>af_aa_personal_decode('{"components":{"topbar":{"text_color":"#ffffff"}}}',7,false,true,false,$target),422);
    appearance_fails(fn()=>af_aa_personal_decode('{"components":{"page":{"padding":24}}}',7,false,true,false,$target),422);
    appearance_fails(fn()=>af_aa_personal_decode('{"components":{"page":{"background_media_id":2}}}',7,false,true,false,$target),422);
    appearance_fails(fn()=>af_aa_personal_decode('{"components":{"page":{"background_media_id":"url(javascript:1)"}}}',7,false,true,false,$target),422);
    $mybb->user['uid']=8;appearance_fails(fn()=>af_aa_personal_save(8,$id,'{}'),404);$mybb->user['uid']=7;
    af_aa_personal_activate(7,$target,0);appearance_check(af_aa_personal_list(7,$target)['active_selection']['kind']==='system','System reset failed.');
    af_aa_personal_activate(7,$target,$id);
}
appearance_check(af_aa_personal_slots(7,'postbit')['purchased']===0 && af_aa_personal_slots(7,'postbit')['used']===0,'Slots crossed target boundaries.');
appearance_check(af_aa_personal_targets()['theme']['assignment_target']==='appearance_ui_theme','UI theme collided with thread assignment.');
appearance_check(af_elementtheme_resolve_surface_key(9,'profile','fire')==='' && af_elementtheme_resolve_surface_key(9,'sheet','fire')==='','Approval gate bypassed.');
foreach(['profile','sheet'] as $target){$s=['canvas'=>['preset'=>'embers','intensity'=>20]];appearance_check(af_aa_studio_canvas(7,$s,$target)['intensity']===20,'Canvas adapter failed.');appearance_check(af_aa_studio_canvas(9,$s,$target)===[],'Unapproved effects enabled.');}
appearance_fails(fn()=>af_aa_personal_decode('{"canvas":{"preset":"embers"}}',7,false,true,false,'theme'),422);
$sheet=af_aa_surface_fragment(af_aa_studio_surface_preview_markup(7,'sheet'),7,'sheet');
appearance_check(str_contains($sheet,'data-aa-surface="sheet-7"') && str_contains($sheet,'data-af-appearance-surface-effects-config'),'Lazy sheet fragment missing appearance.');
echo "Surfaces: independent CRUD/slots, real templates, preview, draft, assignments, media ownership, safe schema and approval checks passed.\n";
