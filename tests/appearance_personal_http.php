<?php
/** Use the isolated appearance_personal_integration.php server, never a real forum. */
function http_check($value,$message){if(!$value)throw new RuntimeException($message);}
function appearance_request(string $operation,array $fields=[],int $uid=7,string $method='GET',bool $json=false): array {
    $url='http://127.0.0.1:8100/apstudio.php?api=personal&fixture_uid='.$uid;
    if($method==='GET')$url.='&'.http_build_query(['operation'=>$operation]+$fields);
    $body=$method==='GET'?'':($json?json_encode(['operation'=>$operation]+$fields):http_build_query(['operation'=>$operation]+$fields));
    $context=stream_context_create(['http'=>['method'=>$method,'content'=>$body,'header'=>'Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded'),'ignore_errors'=>true]]);
    $text=file_get_contents($url,false,$context);$headers=http_get_last_response_headers();preg_match('/HTTP\/\S+ (\d+)/',$headers[0],$m);
    return [(int)$m[1],json_decode($text,true),$text];
}
$key=['my_post_key'=>'appearance-fixture-key'];
[$status,$data]=appearance_request('list',[],60);http_check($status===200&&$data['data']['slots']['total_with_system']===2&&$data['data']['system_preset']['id']===0,'Virtual/default slots');
[$status,$data]=appearance_request('create',$key+['title'=>'HTTP style','settings_json'=>'{"text_color":"#112244"}'],60,'POST',true);http_check($status===200&&$data['ok'],'HTTP create');$id=$data['data']['id'];
[$status]=appearance_request('create',$key+['title'=>'Overflow','settings_json'=>'{}'],60,'POST');http_check($status===409,'POST quota bypass');
[$status]=appearance_request('clone',$key+['id'=>$id,'title'=>'Clone'],60,'POST');http_check($status===409,'POST clone quota bypass');
[$status]=appearance_request('save',$key+['id'=>$id,'settings_json'=>'{}'],61,'POST');http_check($status===404,'Foreign preset edit');
[$status]=appearance_request('rename',$key+['id'=>$id,'title'=>'Foreign','uid'=>60],61,'POST');http_check($status===403,'Owner UID spoof');
[$status]=appearance_request('rename',['my_post_key'=>'wrong','id'=>$id,'title'=>'Wrong CSRF'],60,'POST');http_check($status===403,'CSRF bypass');
[$status]=appearance_request('delete',['id'=>$id],60);http_check($status===405,'GET write bypass');
[$status]=appearance_request('save',$key+['id'=>$id,'settings_json'=>'[1]'],60,'POST');http_check($status===422,'Invalid JSON accepted');
[$status]=appearance_request('save',$key+['id'=>$id,'settings_json'=>'{"custom_css":"body{display:none}"}'],60,'POST',true);http_check($status===422,'Raw CSS accepted');
[$status]=appearance_request('save',$key+['id'=>$id],60,'POST');http_check($status===422,'Missing settings silently clears definition');
[$status]=appearance_request('rename',$key+['id'=>$id,'title'=>'Renamed HTTP'],60,'POST');http_check($status===200,'Rename HTTP');
[$status]=appearance_request('draft',$key+['id'=>$id,'settings_json'=>'{"text_color":"#334455"}'],60,'POST');http_check($status===200,'Draft HTTP');
[$status,$row]=appearance_request('get',['id'=>$id],60);http_check(str_contains($row['data']['settings_json'],'#112244')&&str_contains($row['data']['draft_json'],'#334455'),'Draft overwrote published definition');
[$status]=appearance_request('activate',$key+['id'=>$id,'target'=>'postbit'],60,'POST');http_check($status===200,'Active HTTP');
[$status,$list]=appearance_request('list',[],60);http_check($list['data']['assignment']['preset_id']===$id,'Assignment separated incorrectly');
[$status]=appearance_request('reset',$key+['target'=>'postbit'],60,'POST');http_check($status===200,'Reset HTTP');
[$status]=appearance_request('delete',$key+['id'=>$id],60,'POST');http_check($status===200,'Delete HTTP');
[$status,$list]=appearance_request('list',[],60);http_check($list['data']['slots']['available']===1,'Delete did not free HTTP slot');
[$status,$data]=appearance_request('draft',$key+['target'=>'postbit','title'=>'New draft','settings_json'=>'{}'],60,'POST');http_check($status===200,'New draft HTTP');$id=$data['data']['id'];
[$status]=appearance_request('activate',$key+['id'=>$id],60,'POST');http_check($status===422,'Draft active bypass');
[$status]=appearance_request('save',$key+['id'=>$id,'settings_json'=>'{"text_color":"#557799"}'],60,'POST');http_check($status===200,'Publish draft HTTP');
[$status]=appearance_request('list',[],0);http_check($status===403,'Guest API access');
[$status]=appearance_request('create',$key+['target'=>'profile','title'=>'Not implemented'],61,'POST');http_check($status===422,'Reserved editor enabled');
[$status,$list]=appearance_request('list',['target'=>'sheet'],61);http_check($status===200&&$list['data']['system_preset']['id']===0&&!$list['data']['target']['implemented'],'Reserved registry/default missing');
[$status,$list]=appearance_request('list',[],7);$own=(int)$list['data']['presets'][0]['id'];
[$status]=appearance_request('save',$key+['id'=>$own,'settings_json'=>'{"text_color":"#113355","font_family":"serif","accent_color":"#446688","author_tint":"#102030","effect_preset":"soft_glow"}'],7,'POST');http_check($status===200,'Prepare rendered preset');
[$status]=appearance_request('activate',$key+['id'=>$own],7,'POST');http_check($status===200,'Prepare render assignment');
echo "Appearance HTTP passed: virtual/free capacity, CRUD/draft/active/reset, direct POST/clone quota, ownership/UID/CSRF/method gates, invalid JSON/CSS, reserved targets.\n";
