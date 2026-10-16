<?php
$editor=[['script'=>'usercp.php','action'=>'editsig'],['script'=>'usercp.php','action'=>'do_editsig']];
$public=array_map(static fn($s)=>['script'=>$s],['showthread.php','newreply.php','newthread.php','editpost.php','private.php','announcements.php','misc.php','reputation.php']);
return [
    'id'=>'advancedsignaturebuilder','name'=>'AdvancedSignatureBuilder','version'=>'1.0.1',
    'author'=>'CaptainPaws','description'=>'Конструктор содержимого подписей: композиции, черновики и совместимое сохранение MyBB.',
    'bootstrap'=>'advancedsignaturebuilder.php',
    'frontend'=>[
        'mode'=>'contextual','routes'=>array_merge($editor,$public,[['script'=>'apstudio.php'],['script'=>'studio.php']]),
        'resources'=>[
            'editor'=>['mode'=>'contextual','routes'=>$editor,'directory_fallback'=>false],
            'preview'=>['mode'=>'contextual','routes'=>array_merge($editor,[['script'=>'apstudio.php'],['script'=>'studio.php']]),'directory_fallback'=>false],
            'view'=>['mode'=>'contextual','routes'=>$public,'response_rules'=>[['has_asb_signature'=>true]],'directory_fallback'=>false],
            'studio_link'=>['mode'=>'contextual','routes'=>[['script'=>'apstudio.php']],'directory_fallback'=>false],
        ],
        'response_rules'=>[['has_asb_signature'=>true]],'directory_fallback'=>false,
    ],
    'theme_stylesheets'=>[
        ['id'=>'signature_builder','file'=>'assets/signature-builder.css','stylesheet_name'=>'af_asb_builder.css','attach'=>array_map(static fn($r)=>['file'=>$r['script'],'action'=>$r['action']],$editor),'enabled_setting'=>'af_advancedsignaturebuilder_enabled'],
        ['id'=>'signature_view','file'=>'assets/signature-view.css','stylesheet_name'=>'af_asb_view.css','attach'=>array_merge(array_map(static fn($r)=>['file'=>$r['script'],'action'=>$r['action']],$editor),array_map(static fn($r)=>['file'=>$r['script']],$public),[['file'=>'apstudio.php'],['file'=>'studio.php']]),'enabled_setting'=>'af_advancedsignaturebuilder_enabled'],
    ],
];
