<?php
require __DIR__.'/appearance_postbit_settings_regression.php';
$components=[];
foreach (['topbar','sidebar','message_background'] as $key) {
    $components[$key]=['background_media_id'=>42,'background_size'=>'contain','background_position'=>'right','background_repeat'=>'repeat-y','haze_color'=>'#9911ff','haze_intensity'=>.25,'haze_opacity'=>.5,'overlay_mode'=>'darken','overlay_intensity'=>1,'overlay_start'=>1,'overlay_end'=>0,'overlay_angle'=>90];
}
foreach (['signature_title','signature_subtitle','signature_quote','signature_buttons'] as $key) $components[$key]=['font_family'=>$font,'text_align'=>'right','font_style'=>'italic','glow_color'=>'#123456','glow_intensity'=>4];
$components['signature_quote']+=['background_color'=>'#112233','background_opacity'=>.4,'divider_color'=>'#445566','padding'=>12];
$components['signature_buttons']+=['border_color'=>'#abcdef','hover_color'=>'#123456','hover_background_color'=>'#654321','hover_border_color'=>'#fedcba'];
$decoded=af_aa_personal_decode(json_encode(['components'=>$components]),7,false,false);
check(af_aa_personal_decode(af_aa_personal_encode($decoded),7,false,false)===$decoded,'New fields round trip');
$css=af_aa_studio_compile(7,$decoded);
foreach (['topbar','sidebar','message_background'] as $key) {
    check(str_contains($css,'--aa-'.$key.'-image:url('),'Missing real-layer image variable');
    check(str_contains($css,'--aa-'.$key.'-mask:linear-gradient(90deg,color-mix(in srgb,#000000 100%,transparent),color-mix(in srgb,#000000 0%,transparent))'),'Wrong mask strength/angle');
    check(str_contains($css,'--aa-'.$key.'-haze:linear-gradient(115deg,color-mix(in srgb,#9911ff 12.5%,transparent)'),'Wrong haze strength');
    foreach (['none','lighten'] as $mode) {
        $output=af_aa_studio_compile(7,['components'=>[$key=>['overlay_mode'=>$mode]]]);
        check(str_contains($output,'--aa-'.$key.'-mask:'.($mode==='none'?'none;':'linear-gradient(180deg,color-mix(in srgb,#ffffff 50%')),'Mode does not reach real-layer variable');
    }
    check(!str_contains(af_aa_studio_compile(7,['components'=>[$key=>[]]]),'--aa-'.$key.'-mask:'),'Inherit must leave native mask alone');
}
$profile=af_aa_signature_compile_css(7,$decoded);
check(str_contains($profile,'.af-asb-signature--quote .af-asb-signature__copy .af-asb-signature__text'),'Quote scope absent');
check(str_contains($profile,'border-inline-start-color:#445566'),'Quote divider absent');
check(str_contains($profile,'.af-asb-signature__button:is(:hover,:focus-visible)'),'Button hover scope absent');
check(!str_contains($profile,'--aa-sidebar-'),'Profile leaks postbit paint');
if (($path=getenv('AA_TEST_CSS_OUTPUT'))!==false) {
    file_put_contents($path,$css);
    $cases=[];
    foreach (['darken','lighten','none','zero'] as $mode) {
        $values=['overlay_mode'=>$mode==='zero'?'darken':$mode,'overlay_intensity'=>$mode==='zero'?0:1,'overlay_start'=>1,'overlay_end'=>1];
        $cases[$mode]=af_aa_studio_compile(7,['components'=>array_fill_keys(['topbar','sidebar','message_background'],$values)]);
    }
    file_put_contents($path.'.json',json_encode($cases));
}
echo "Background layers passed: three independent regions, sparse inheritance, mask limits, haze, signature profile scope, local fonts, round trip.\n";
