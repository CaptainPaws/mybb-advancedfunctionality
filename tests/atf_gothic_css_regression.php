<?php
/** Reproduce MyBB 1.8.40's real minifier transformation; no browser demo. */
$base=dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/';
function mybb1840Minify(string $css): string {
    $css=preg_replace('@/\*.*?\*/@s','',$css);
    $css=preg_replace('@\s*([{}:;,])\s*@','$1',$css);
    $css=preg_replace('@;}@','}',$css);
    return trim(preg_replace('@#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3@i','#$1$2$3',$css));
}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
check(str_contains(mybb1840Minify('body.atf-active :is(.atf-post,.atf-signature-surface) {color:red}'),'body.atf-active:is('),'Original regression not reproduced');
$post=file_get_contents($base.'assets/surfaces/postbit.css');
$sig=file_get_contents($base.'assets/surfaces/signature.css');
foreach(['--atf-post-corner','--atf-post-gem-facets','--atf-post-gem-mask','--atf-post-metal'] as $variable){
    check(substr_count($post,$variable.':')===1,"Common variable missing/duplicated: $variable");
    check(!str_contains($sig,$variable.':'),"Common variable duplicated in signature: $variable");
}
foreach(['.atf-gothic-frame','.atf-gothic-corner','.atf-post__avatar-inlays','.atf-post__divider'] as $selector)check(str_contains($post,$selector),"Missing decoration $selector");
foreach([$post,$sig] as $css){
    $min=mybb1840Minify($css);
    check(!str_contains($min,'body.atf-active:is('),'MyBB minification breaks host selector');
    check(str_contains($min,'body.atf-active .atf-signature-surface '),'Profile descendant selectors lost');
}
check(str_contains(mybb1840Minify($post),'body.atf-active .atf-post .atf-gothic-frame'),'Postbit frame selector lost');
define('IN_MYBB',1);
$manifest=require $base.'manifest.php';
foreach(['surface_postbit','surface_signature'] as $id){
    $entries=array_values(array_filter($manifest['theme_stylesheets'],fn($entry)=>$entry['id']===$id));
    check(count($entries)===1,"Stylesheet registration duplicated: $id");
    check(is_file($base.$entries[0]['file']),"Missing CSS file: $id");
    foreach(['showthread.php','member.php'] as $script)check(in_array($script,array_column($entries[0]['attach'],'file'),true),"$id not attached to $script");
}
$runtime=file_get_contents($base.'adaptivethemeframework.php');
check(!str_contains($runtime,'assets/adaptivethemeframework.signature.js'),'Obsolete standalone script URL still emitted');
check(str_contains($runtime,'presentation-preferences.js'),'Shared runtime delivery missing');
echo "ATF gothic CSS: MyBB minifier regression reproduced; unique shared decorations, safe selectors, post/profile attachments and runtime delivery passed.\n";
