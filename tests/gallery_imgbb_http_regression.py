"""Real multipart HTTP through Gallery; fixture substitutes only ImgBB transport."""
import base64, hashlib, json, os, urllib.request, urllib.parse, urllib.error
ORIGIN=os.environ.get('AG_IMGBB_ORIGIN','http://127.0.0.1:8101')
PNG=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6l8AAAAASUVORK5CYII=')
GIF=base64.b64decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
SECRET=b'fixture_only_not_a_real_imgbb_key'

def request(action,fields=None,file=None,uid=7,group=2,extra=None,raw=False,file_field="ag_file"):
    query=dict(action=action,fixture_uid=uid,fixture_group=group);query.update(extra or {})
    data=None;headers={}
    if file is not None:
        boundary='ImgBBFixtureBoundary';data=b''
        values=dict(my_post_key='fixture-key',ajax='1',storage_provider='imgbb');values.update(fields or {})
        for key,value in values.items():
            data+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
        data+=f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="image.png"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+file+f'\r\n--{boundary}--\r\n'.encode()
        headers['Content-Type']=f'multipart/form-data; boundary={boundary}'
    elif fields is not None:
        values=dict(my_post_key='fixture-key',ajax='1');values.update(fields)
        data=urllib.parse.urlencode(values).encode()
    try:r=urllib.request.urlopen(urllib.request.Request(ORIGIN+'/gallery.php?'+urllib.parse.urlencode(query),data=data,headers=headers))
    except urllib.error.HTTPError as error:r=error
    body=r.read();assert SECRET not in body and b'PRIVATE_DELETE_TOKEN' not in body,body
    return r.status,body if raw else json.loads(body)

code,result=request('upload_do',file=GIF)
assert code==200 and result['provider']=='imgbb' and result['storage']['used_bytes']==100,(code,result)
image=result['id'];album=result['album_id']
code,data=request('inspect',extra=dict(id=image));assert code==200 and data['media']['type']=='remote' and data['media']['mime']=='image/gif' and int(data['media']['filesize'])==len(GIF) and data['media']['storage_path']=='' and data['direct'] and '[img]https://i.ibb.co/' in data['bbcode'],data
for fields,file,extra in [({},PNG+b'\0'*1048576,{}),({},b'<svg/>',{}),({},PNG,dict(api_failure=1)),(dict(album_id=1),PNG,{})]:
    code,data=request('upload_do',fields=fields,file=file,extra=extra);assert code==422,(code,data)
code,data=request('upload_do',file=PNG,group=5);assert code==403,(code,data)
code,data=request('upload_do',fields=dict(my_post_key='invalid',storage_provider='imgbb'),file=None);assert code==403,(code,data)
for url in ['file:///etc/passwd','http://127.0.0.1/private','http://169.254.169.254/','https://[::1]/']:
    code,data=request('upload_do',fields=dict(storage_provider='imgbb',external_source='url',external_url=url));assert code==422,(code,data)
code,data=request('move',fields=dict(album_id=album,media_id=1));assert code==422,(code,data)
code,data=request('upload_do',fields=dict(storage_provider='local',album_id=album),file=PNG);assert code==422,(code,data)
code,data=request('upload_do',fields=dict(storage_provider='unknown'),file=PNG);assert code==403,(code,data)
code,rows=request('picker_data',extra=dict(purpose='account_avatar'))
assert code==200 and any(row['id']==image for row in rows),rows
code,selected=request('picker_select',fields=dict(purpose='account_avatar',media_id=image));assert code==200 and 'action=avatar_original' in selected['url_full'],selected
code,binary=request('avatar_original',extra=dict(purpose='account_avatar',id=image),raw=True);assert code==200 and hashlib.sha256(binary).digest()==hashlib.sha256(GIF).digest()
for purpose,field in [('account_avatar','avatarupload'),('character_avatar','secondary_avatar_upload')]:
    code,data=request('validate_avatar',fields=dict(purpose=purpose,ag_avatar_confirm=1,ag_avatar_media_id=image),file=GIF,file_field=field)
    assert code==200 and data['ok'],(code,data)
    code,data=request('validate_avatar',fields=dict(purpose=purpose,ag_avatar_confirm=1,ag_avatar_media_id=image),file=PNG,file_field=field)
    assert code==422,(code,data)
code,data=request('picker_select',uid=8,fields=dict(purpose='account_avatar',media_id=image));assert code==422,(code,data)
code,data=request('inspect',uid=8,extra=dict(id=image,uid=7));assert code==403,(code,data)
code,data=request('view_check',uid=8,extra=dict(owner=7,id=image));assert code==403,(code,data)
code,other=request('upload_do',uid=8,file=PNG);assert code==200 and other['album_id']!=album,(code,other)
code,rows=request('picker_data',uid=7,extra=dict(album_id=-1,uid=8));assert all(row['id']!=other['id'] for row in rows),rows
code,ordinary=request('fixture_album')
code,data=request('move',fields=dict(album_id=ordinary['id'],media_id=image));assert code==200,data
code,data=request('inspect',extra=dict(id=image));assert data['media']['type']=='remote' and data['storage']['used_bytes']==100,data
code,data=request('delete',uid=8,fields=dict(id=image));assert code==403,(code,data)
code,data=request('delete',fields=dict(id=image));assert code==200 and 'не выполнялось' in data['message'],(code,data)
code,data=request('inspect',extra=dict(id=image));assert code==403,(code,data)
code,pending=request('upload_do',file=PNG,extra=dict(pending=1));assert code==200 and pending['status']=='pending',pending
code,rows=request('picker_data',extra=dict(purpose='account_avatar',album_id=-1));assert all(row['id']!=pending['id'] for row in rows),rows
code,data=request('picker_select',fields=dict(purpose='account_avatar',media_id=pending['id']));assert code==422,(code,data)
print('ImgBB HTTP passed: multipart upload, metadata, size/format/API failures, SSRF, ownership, private album, avatar original proxy, BBCode, move, deletion and pending moderation; local quota unchanged and no client secrets.')
