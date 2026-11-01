<?php
declare(strict_types=1);
require __DIR__.'/frontend_asset_permission_regression.php';
$manifest=require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/manifest.php';
$context=['script'=>'showthread.php'];
if(af_frontend_asset_allowed($manifest,null,$context)||af_frontend_asset_allowed($manifest,'builder',$context)||af_frontend_asset_allowed($manifest,'builder',$context,['has_acs_builder'=>false])||!af_frontend_asset_allowed($manifest,'builder',$context,['has_acs_builder'=>true]))throw new RuntimeException('ACS builder frontend asset scope violated');
if($manifest['frontend']['directory_fallback']!==false)throw new RuntimeException('Global directory fallback enabled');
echo "ACS builder assets: denied by default; explicit rendered component only passed\n";
