<?php
/** Uses the isolated gallery_test fixture initialized by gallery_imgbb_fixture.php --init. */
define('AG_TEST_BOOTSTRAP_ONLY', true);
require __DIR__.'/gallery_media_api_integration.php';
$q=$db->simple_select('settings');
while ($row=$db->fetch_array($q)) $mybb->settings[$row['name']]=$row['value'];
check(ag_media_source(['type'=>'local','provider'=>'imgbb'])==='local','Local classification follows storage type');
check(ag_media_source(['type'=>'remote','provider'=>'direct','remote_url'=>'https://i.ibb.co/a.png'])==='url','Pasted ImgBB URL must stay URL source');
check(ag_media_source(['type'=>'remote','provider'=>'imgbb'])==='imgbb','Managed provider classification');
check(!isset(ag_source_labels()['postimages']),'Unimplemented Postimages must be absent');
$controls=ag_provider_upload_controls();
check(str_contains($controls,'value="imgbb"') && str_contains($controls,'value="url"'),'Available sources missing');
$mybb->settings['af_advancedgallery_imgbb_groups']='4';
$mybb->settings['af_advancedgallery_remote_enabled']='0';
$controls=explode('</select>',ag_provider_upload_controls(),2)[0];
check(!str_contains($controls,'value="imgbb"') && !str_contains($controls,'value="url"'),'Unavailable upload sources exposed');
check(str_contains($controls,'value="local"'),'Local selector missing when external providers disabled');
$_GET=['source'=>"imgbb' OR 1=1 --"];
check(ag_source_filter_sql('m')==='','Untrusted source entered SQL');
$_GET=['source'=>'url','action'=>'album','id'=>'2','sort'=>'manual'];
check(str_contains(ag_source_filter(),'name="sort" value="manual"'),'Filter drops album sort');
check(str_contains(ag_render_album_sort_filter(2,'manual'),'name="source" value="url"'),'Sort drops source filter');
echo "Gallery sources passed: stored source classification, no fake Postimages, hidden unavailable providers, SQL allowlist and preserved sort/filter context.\n";
