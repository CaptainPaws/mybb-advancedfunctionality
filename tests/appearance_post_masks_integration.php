<?php
/** Destructive isolated appearance_test / aa_test_ fixture. Never run on a forum DB. */
define('AA_STUDIO_FIXTURE',true);define('AA_MASK_FIXTURE',true);define('THIS_SCRIPT','newreply.php');
require __DIR__.'/appearance_personal_integration.php';
foreach(['posts','threads','forums'] as $table)$db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);
$db->write_query('CREATE TABLE '.TABLE_PREFIX.'posts (pid INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,uid INT,tid INT,fid INT,visible INT,dateline INT,message TEXT) ENGINE=InnoDB');
$db->write_query('CREATE TABLE '.TABLE_PREFIX.'threads (tid INT PRIMARY KEY,uid INT,fid INT,visible INT,closed INT,firstpost INT) ENGINE=InnoDB');
$db->write_query('CREATE TABLE '.TABLE_PREFIX.'forums (fid INT PRIMARY KEY,type VARCHAR(2),open INT) ENGINE=InnoDB');
$db->write_query('INSERT INTO '.TABLE_PREFIX."threads VALUES (10,7,1,1,0,101)");$db->write_query('INSERT INTO '.TABLE_PREFIX."forums VALUES (1,'f',1)");
for($pid=101;$pid<=140;$pid++)$db->write_query('INSERT INTO '.TABLE_PREFIX."posts VALUES ({$pid},".($pid===104?8:7).",10,1,1,".TIME_NOW.",'Post {$pid}')");
$before=$db->fetch_array($db->simple_select(AF_AA_ASSIGNMENTS_TABLE_NAME,'*',"entity_type='user' AND entity_id=7"));af_aa_mask_install_schema();af_aa_mask_install_schema();
$after=$db->fetch_array($db->simple_select(AF_AA_ASSIGNMENTS_TABLE_NAME,'*',"entity_type='user' AND entity_id=7"));foreach(['mask_owner_uid','mask_snapshot_json','mask_topbar_media_id','mask_sidebar_media_id'] as $field)unset($after[$field]);appearance_check($before===$after,'Migration changed existing assignment');
$db->write_query('INSERT INTO '.TABLE_PREFIX."af_gallery_media VALUES (4,7,'local','approved','image/png','uploads/four.png','','',0)");
$mybb->settings['af_advancedappearance_max_slots_postbit']=20;$mybb->settings['af_advancedappearance_free_slots_postbit']=3;$standard=(int)af_aa_personal_list(7,'postbit')['presets'][0]['id'];af_aa_personal_save(7,$standard,'{"text_color":"#ddeeff"}');
$pink=af_aa_personal_create(7,'postbit','Pink','{"avatar_media_id":4,"components":{"message":{"text_color":"#ff77aa","font_family":"serif","font_size":18}}}');
$purple=af_aa_personal_create(7,'postbit','Purple','{"components":{"message":{"text_color":"#bb88ee","font_size":16}},"canvas":{"preset":"frost","speed":20}}');
$mybb->user['suspendposting']=0;$mybb->usergroup['edittimelimit']=0;
$a=af_aa_mask_prepare(7,101,json_encode(['mode'=>'set','preset_id'=>$pink,'topbar_avatar_media_id'=>1,'sidebar_avatar_media_id'=>4]));
$b=af_aa_mask_prepare(7,102,json_encode(['mode'=>'set','preset_id'=>$purple,'topbar_avatar_media_id'=>4,'sidebar_avatar_media_id'=>1]));
appearance_check(appearance_count(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post'")===0,'Preview created mask metadata');
af_aa_mask_commit(101,7,$a);af_aa_mask_commit(102,7,$b);
$mybb->input=['af_aa_mask'=>json_encode(['mode'=>'set','preset_id'=>$pink])];$_POST=['my_post_key'=>'appearance-fixture-key'];$mybb->request_method='post';
$failed=new MaskFixtureHandler();$failed->data=['uid'=>7,'pid'=>0];af_aa_mask_validate_handler($failed);appearance_check(!$failed->errors&&appearance_count(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post'")===2,'Failed native validation created a mask');
$edit=new MaskFixtureHandler();$edit->method='update';$edit->data=['uid'=>7,'pid'=>101];$edit->pid=101;$mybb->input=[];af_aa_mask_validate_handler($edit);af_aa_mask_after_handler($edit);appearance_check(af_aa_mask_row(101)['snapshot']===$a['snapshot'],'Omitted edit field changed snapshot');
$mybb->input=['af_aa_mask'=>'{"mode":"clear"}'];af_aa_mask_validate_handler($edit);appearance_check(af_aa_mask_row(101)!==null,'Opening/canceling edit changed mask'); // No end hook = cancellation.
$mybb->input=['af_aa_mask'=>'{"mode":"set","preset_id":'.$purple.'}'];$new=new MaskFixtureHandler();$new->data=['uid'=>7,'pid'=>0];af_aa_mask_validate_handler($new);$new->pid=105;af_aa_mask_after_handler($new);appearance_check((int)af_aa_mask_row(105)['preset_id']===$purple,'Successful insert hook failed');
$bad=new MaskFixtureHandler();$bad->data=['uid'=>7,'pid'=>104];af_aa_mask_validate_handler($bad);appearance_check((bool)$bad->errors,'Forged PID accepted');
foreach(['{"mode":"set","preset_id":'.$pink.',"topbar_avatar_media_id":2}','{"mode":"set","preset_id":'.$pink.',"sidebar_avatar_media_id":3}','{"mode":"set","snapshot":{"custom_css":"body{}"}}','{"mode":"set","preset_id":"'.$pink.'"}'] as $json)appearance_fails(fn()=>af_aa_mask_prepare(7,101,$json),422);
$mybb->input=['af_aa_mask'=>'{"mode":"clear"}'];$_POST=['my_post_key'=>'bad'];$csrf=new MaskFixtureHandler();$csrf->data=['uid'=>7,'pid'=>101];af_aa_mask_validate_handler($csrf);appearance_check((bool)$csrf->errors,'Silent MyBB CSRF false was ignored');
$mybb->usergroup['edittimelimit']=1;$db->update_query('posts',['dateline'=>TIME_NOW-120],'pid=101');appearance_fails(fn()=>af_aa_mask_require_edit(101),403);$db->update_query('posts',['dateline'=>TIME_NOW],'pid=101');$mybb->usergroup['edittimelimit']=0;
$GLOBALS['mask_fixture_permissions']=['caneditposts'=>0];appearance_fails(fn()=>af_aa_mask_require_edit(101),403);unset($GLOBALS['mask_fixture_permissions']);$db->update_query('threads',['closed'=>1],'tid=10');appearance_fails(fn()=>af_aa_mask_require_edit(101),403);$db->update_query('threads',['closed'=>0],'tid=10');
// Edit/delete the source and make it the live UID style: frozen post scopes must remain independent.
af_aa_personal_activate(7,'postbit',$pink);af_aa_personal_save(7,$pink,'{"components":{"message":{"text_color":"#00ff00","link_color":"#123456"}}}');
appearance_check(str_contains(af_aa_mask_css(101,7,af_aa_mask_row(101)['snapshot']),'#ff77aa'),'Source edit changed snapshot');af_aa_personal_delete(7,$pink);appearance_check(af_aa_mask_row(101)!==null,'Source deletion removed mask assignment');af_aa_personal_activate(7,'postbit',$standard);
// Forty posts, including absent masks, require one mask query and one media batch.
unset($GLOBALS['af_aa_mask_rows'],$GLOBALS['ag_media_url_rows']);$db->querylog=[];af_aa_mask_preload(range(101,140));
for($pid=101;$pid<=140;$pid++){af_aa_mask_runtime($pid,$pid===104?8:7);if($r=af_aa_mask_runtime($pid,7))af_aa_mask_css($pid,7,$r['snapshot']);}
$maskQueries=array_filter($db->querylog,fn($sql)=>str_contains($sql,' JOIN '.TABLE_PREFIX.'posts p'));
$mediaQueries=array_filter($db->querylog,fn($sql)=>str_starts_with($sql,'SELECT')&&str_contains($sql,'FROM '.TABLE_PREFIX.'af_gallery_media'));
appearance_check(count($maskQueries)===1&&count($mediaQueries)===1,'N+1 mask/media queries');
$post=get_post(101)+['useravatar'=>'Native','username'=>'Owner','af_atf_slots'=>[]];af_aa_mask_postbit($post);appearance_check($post['af_aa_user_class']==='af-aa-postbit-mask-101'&&$post['useravatar']==='Native','Mask mutated global avatar or kept live UID scope');appearance_check(str_contains($post['af_atf_primary_avatar'],'mine.png')&&str_contains($post['af_atf_secondary_avatar'],'four.png'),'Independent avatars missing');
appearance_check(str_contains($post['af_atf_slots']['post.before_body'],'#post_101')&&!str_contains(af_aa_mask_css(101,7,$a['snapshot']),'url("http://127.0.0.1:8100/uploads/four.png")'),'Topbar CSS overrode explicit avatar with source avatar');
$db->update_query('posts',['visible'=>-1],'pid=105');unset($GLOBALS['af_aa_mask_rows'][105]);appearance_check(af_aa_mask_row(105)!==null,'Soft delete erased mask');$db->update_query('posts',['visible'=>1],'pid=105');unset($GLOBALS['af_aa_mask_rows'][105]);appearance_check(af_aa_mask_runtime(105,7)!==null,'Restore lost mask');
af_aa_mask_delete_post(105);appearance_check(af_aa_mask_row(105)===null,'Hard delete did not clean mask');
$db->update_query('posts',['visible'=>-2],'pid=106');af_aa_mask_commit(106,7,$b);appearance_check(!af_aa_mask_row(106)['is_enabled']&&af_aa_mask_runtime(106,7)===null,'Draft applied a published mask');$db->update_query('posts',['visible'=>1],'pid=106');unset($GLOBALS['af_aa_mask_rows'][106]);$draft=new MaskFixtureHandler();$draft->data=['uid'=>7,'pid'=>106];$draft->pid=106;$mybb->input=[];$_POST=['my_post_key'=>'appearance-fixture-key'];af_aa_mask_validate_handler($draft);af_aa_mask_after_handler($draft);appearance_check(af_aa_mask_row(106)['is_enabled'],'Publishing native draft lost selection');af_aa_mask_delete_post(106);
$db->update_query('posts',['uid'=>8],'pid=102');unset($GLOBALS['af_aa_mask_rows'][102]);appearance_check(af_aa_mask_runtime(102,8)===null,'Author transfer applied another owner snapshot');$db->update_query('posts',['uid'=>7],'pid=102');unset($GLOBALS['af_aa_mask_rows'][102]);
$mybb->input=[];$_POST=[];
echo "Post masks integration passed: additive assignment schema, snapshots survive source changes/deletion, independent avatars, native save/omit/cancel/draft hooks, ownership/edit/CSRF/media checks, 40-post batch, soft/hard-delete/transfer semantics.\n";
