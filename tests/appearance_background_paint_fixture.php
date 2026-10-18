<?php
// Uses the actual validator/compiler and isolated no-DB contracts.
ob_start();require __DIR__.'/appearance_postbit_settings_regression.php';ob_end_clean();
$result=[];
foreach(['full'=>['overlay_gradient'=>'uniform','overlay_mode'=>'darken','overlay_intensity'=>1,'overlay_start'=>.3,'overlay_end'=>.3],
    'q20'=>['overlay_mode'=>'darken','overlay_intensity'=>.2],
    'q50'=>['overlay_mode'=>'darken','overlay_intensity'=>.5],
    'q80'=>['overlay_mode'=>'darken','overlay_intensity'=>.8],
    'gradient'=>['overlay_mode'=>'darken','overlay_gradient'=>'linear','overlay_intensity'=>1,'overlay_start'=>.2,'overlay_end'=>.8,'overlay_angle'=>180],
    'color'=>['overlay_mode'=>'none','background_saturation'=>100],
    'half'=>['overlay_mode'=>'none','background_saturation'=>50],
    'gray'=>['overlay_mode'=>'none','background_saturation'=>0]] as $name=>$values){
    $values+=['background_url'=>'https://images.example.org/red.png','background_color'=>'#ffffff'];
    $s=af_aa_personal_decode(json_encode(['components'=>['topbar'=>$values,'sidebar'=>$values,'message_background'=>$values]]),7,false,false);
    $result[$name]=af_aa_personal_compile_css(7,$s,true);
}
check(str_contains($result['full'],'#000000 100%'),'Maximum strength still multiplied by gradient stops');
reject('{"components":{"sidebar":{"background_saturation":101}}}');
reject('{"components":{"sidebar":{"background_saturation":-1}}}');
check(!in_array('background_saturation',af_aa_postbit_surface()['components']['plaque']['fields'],true),'Unsupported saturation control exposed');
echo json_encode($result);
