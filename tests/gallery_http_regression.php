<?php
/** Run inside the PHP fixture server container after gallery_media_api_integration.php. */
function ensure($value,$message){if(!$value)throw new RuntimeException($message);}
function request($action,$fields=[], $file=null,$extra='') {
    $url='http://127.0.0.1:8099/gallery.php?action='.$action.$extra;
    $method=$fields || $file!==null ? 'POST' : 'GET';
    $headers=[];$content='';
    if($file!==null){
        $boundary='GalleryFixtureBoundary';$headers[]='Content-Type: multipart/form-data; boundary='.$boundary;
        foreach($fields as $key=>$value)$content.="--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n{$value}\r\n";
        $content.="--{$boundary}\r\nContent-Disposition: form-data; name=\"ag_file\"; filename=\"fixture.png\"\r\nContent-Type: image/png\r\n\r\n{$file}\r\n--{$boundary}--\r\n";
    } elseif($fields){$headers[]='Content-Type: application/x-www-form-urlencoded';$content=http_build_query($fields);}
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$content,'ignore_errors'=>true]]);
    $body=file_get_contents($url,false,$context);preg_match('~HTTP/\S+ (\d+)~',http_get_last_response_headers()[0],$m);
    return [(int)$m[1],$body,json_decode($body,true)];
}
$key=['my_post_key'=>'fixture-key','ajax'=>'1'];
[$code,$html]=request('picker',[],null,'&purpose=appearance_avatars&fixture_uid=30');
ensure($code===200 && str_contains($html,'data-initial-album="-2"'),'Missing-purpose picker');
ensure(str_contains($html,'name="purpose" value="appearance_avatars"') && !str_contains($html,'<select name="album_id"'),'Purpose upload form');
[$code,,$rows]=request('picker_data',[],null,'&purpose=appearance_avatars&fixture_uid=30');ensure($code===200 && $rows===[],'Missing purpose returned all');
[$code,,$error]=request('upload_do',$key+['purpose'=>'appearance_avatars'],'not an image','&fixture_uid=30');ensure($code===422,'Invalid image accepted');
[$code]=request('picker',[],null,'&purpose=invalid');ensure($code===422,'Invalid purpose accepted');
[$code]=request('upload_do',$key+['purpose'=>'appearance_profile_bg'],'fake','&fixture_group=5');ensure($code===403,'Upload group bypass');
[$code]=request('upload_do',['my_post_key'=>'wrong','purpose'=>'appearance_avatars'],'fake');ensure($code===403,'CSRF bypass');
$image=imagecreatetruecolor(64,48);imagefill($image,0,0,imagecolorallocate($image,70,110,210));ob_start();imagepng($image);$png=ob_get_clean();unset($image);
[$code,,$data]=request('upload_do',$key+['purpose'=>'appearance_profile_bg'],$png);ensure($code===200 && $data['ok'],'Upload failed');
$id=$data['media_id'];$album=$data['album_id'];ensure($id>0 && $album>0 && $data['status']==='approved','Upload metadata');
$public=file_get_contents($data['url_full']);ensure($public===$png,'Direct file delivery changed');
$thumb=file_get_contents($data['thumb']);ensure(getimagesizefromstring($thumb)[0]===32,'HTTP thumbnail missing');
[$code,,$rows]=request('picker_data',[],null,'&purpose=appearance_profile_bg');ensure($code===200 && in_array($id,array_column($rows,'media_id')),'Purpose picker missing upload');
[$code,,$rows]=request('picker_data',[],null,'&purpose=appearance_avatars');ensure($rows===[],'Purpose isolation');
[$code,,$rows]=request('picker_data',[],null,'&purpose=appearance_avatars&album_id=-1');ensure(in_array($id,array_column($rows,'media_id')),'Other owned album images missing');
[$code]=request('picker_data',[],null,'&purpose=appearance_avatars&album_id='.$album.'&fixture_uid=8');ensure($code===403,'Foreign private album filter accepted');
[$code,,$owned]=request('owned',[],null,'&id='.$id.'&owner=7&fixture_uid=8');ensure($owned===null,'Foreign owner spoof');
[$code]=request('picker_select',$key+['purpose'=>'appearance_avatars','media_id'=>$id],null,'&fixture_uid=8');ensure($code===403,'Foreign media selected');
[$code,,$selected]=request('picker_select',$key+['purpose'=>'appearance_avatars','media_id'=>$id]);ensure($code===200 && $selected['media_id']===$id,'Own ID selection failed');
[$code]=request('delete',$key+['id'=>$id]);ensure($code===422,'Retained single delete succeeded');
[$code,,$bulk]=request('bulk_do',$key+['bulk_action'=>'delete','media_ids'=>[$id]]);ensure($code===200 && $bulk['affected']===0,'Retained bulk delete succeeded');
ensure(file_get_contents($data['url_full'])===$png,'Retained URL broken');
[$code]=request('album_edit_do',$key+['id'=>$album,'title'=>'My title','visibility'=>'public','system_key'=>'appearance_avatars','allowed_groups'=>'2']);ensure($code===200,'System album rename failed');
[$code,$html]=request('picker',[],null,'&purpose=appearance_profile_bg');ensure(str_contains($html,'My title'),'Title not editable');
[$code]=request('album_delete',$key+['id'=>$album]);ensure($code===422,'System album deleted');
[$code,,$pending]=request('upload_do',$key+['purpose'=>'appearance_sheet_bg'],$png,'&pending=1');ensure($code===200 && $pending['status']==='pending','Moderation bypass');
[$code,,$rows]=request('picker_data',[],null,'&purpose=appearance_sheet_bg');ensure($rows===[],'Pending media selected');
[$code]=request('picker_select',$key+['purpose'=>'appearance_sheet_bg','media_id'=>$pending['media_id']]);ensure($code===403,'Pending selection bypass');
[$code]=request('upload_do',$key+['purpose'=>'appearance_postbit_bg'],$png,'&storage_limit=1');ensure($code===422,'Total storage quota bypass');
[$code,,$ordinary]=request('upload_do',$key+['album_id'=>2],$png);ensure($code===200 && $ordinary['ok'] && $ordinary['album_id']===2,'Legacy upload broken');
[$code,,$legacy]=request('picker_data',[],null,'&album_id=-1');ensure(is_array($legacy)&&in_array($ordinary['id'],array_column($legacy,'id')),'Legacy picker changed');
[$code]=request('delete',$key+['id'=>$ordinary['id']]);ensure($code===200,'Unretained ordinary media delete broken');
$context=stream_context_create(['http'=>['ignore_errors'=>true]]);file_get_contents($ordinary['url_full'],false,$context);ensure(str_contains(http_get_last_response_headers()[0],'404'),'Ordinary file not deleted');
echo "Gallery HTTP passed: lazy GET, purpose/MIME/permissions/CSRF, upload path/direct URL/thumbnail, scoped/all picker, ownership, retention single/bulk, rename/privacy input, moderation, storage quota, legacy upload/picker/delete.\n";
