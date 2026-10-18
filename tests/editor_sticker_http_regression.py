"""Real multipart/parallel HTTP against editor_sticker_fixture.php (isolated DB/files)."""
import base64,concurrent.futures,json,urllib.request,urllib.parse
ORIGIN='http://127.0.0.1:8102/misc.php'
PNG=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6l8AAAAASUVORK5CYII=')
def call(action,fields=None,file=None,uid=7,group=2,additional='',name='image.png'):
    query=urllib.parse.urlencode(dict(action=action,uid=uid,group=group,additional=additional))
    values=dict(my_post_key='fixture-key');values.update(fields or {})
    headers={};data=None
    if file is not None:
        boundary='StickerQuotaBoundary';data=b''
        for key,value in values.items():data+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
        data+=f'--{boundary}\r\nContent-Disposition: form-data; name="sticker"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n'.encode()+file+f'\r\n--{boundary}--\r\n'.encode()
        headers['Content-Type']=f'multipart/form-data; boundary={boundary}'
    else:data=urllib.parse.urlencode(values).encode()
    response=urllib.request.urlopen(urllib.request.Request(ORIGIN+'?'+query,data=data,headers=headers))
    body=response.read();assert b'Warning:' not in body and b'Fatal error' not in body,body
    return json.loads(body)
def listing(uid=7,**kw):return call('af_ae_stikers_list',uid=uid,**kw)['data']
def upload(file,**kw):return call('af_ae_stikers_upload',file=file,**kw)
def config(**values):return call('config',values)
assert listing()['storage']['used_bytes']==100
assert listing()['sizes']['upload_max_mb']==2
# Multipart content must agree with extension; oversized files are rejected before persistence.
assert not upload(b'<svg/>')['success']
assert not upload(PNG,name='fake.gif')['success']
assert not upload(PNG+b'x'*(2*1048576))['success']
assert not upload(PNG,fields=dict(my_post_key='invalid'))['success']
assert not upload(PNG,uid=0)['success']
# Two 600 KiB uploads fit separately, but not together under the 1 MiB quota.
padded=PNG+b'\0'*(600*1024-len(PNG))
with concurrent.futures.ThreadPoolExecutor(2) as pool:results=list(pool.map(lambda _:upload(padded),range(2)))
assert sum(bool(r['success']) for r in results)==1,results
assert listing()['storage']['used_bytes']==100+len(padded)
image=next(r['data']['id'] for r in results if r['success'])
# Changing browser flags cannot exempt a normal account.
assert not upload(padded,fields=dict(is_admin=1,is_moderator=1,staff_exempt=1))['success']
# URL references never consume local bytes; PHP must not contact these addresses.
external=call('af_ae_stikers_upload',dict(sticker_url='https://images.example.org/sticker.png'))
assert external['success'] and listing()['storage']['used_bytes']==100+len(padded),external
assert not call('af_ae_stikers_upload',dict(sticker_url='javascript:alert(1)'))['success']
assert not call('af_ae_stikers_upload',dict(sticker_url='file:///etc/passwd'))['success']
config(allow_external=0)
assert not call('af_ae_stikers_upload',dict(sticker_url='https://images.example.org/other.png'))['success']
config(allow_external=1)
assert not call('af_ae_stikers_delete',dict(id=image),uid=8)['success']
assert call('af_ae_stikers_delete',dict(id=image))['success']
assert listing()['storage']['used_bytes']==100
# Primary/additional administrators, supermods and MyBB forum moderators are exempt.
for uid,group,additional in [(8,4,''),(10,2,'4'),(11,6,''),(9,2,'')]:
    assert listing(uid,group=group,additional=additional)['storage']['limit_bytes']==0
    assert upload(PNG+b'\0'*1100000,uid=uid,group=group,additional=additional)['success']
config(upload_max_mb=1)
assert not upload(PNG+b'\0'*1100000,uid=8,group=4)['success']
config(upload_max_mb=2)
config(staff_exempt=0)
assert listing(8,group=4)['storage']['full']
assert not upload(PNG,uid=8,group=4)['success']
config(staff_exempt=1)
# Existing over-quota data stays available; changing the quota blocks only new files.
config(user_quota_mb=0)
large=upload(PNG+b'\0'*1100000)
assert large['success'] and listing()['storage']['limit_bytes']==0
config(user_quota_mb=1)
assert listing()['storage']['full'] and any(s['id']==large['data']['id'] for s in listing()['user_stickers'])
assert not upload(PNG)['success']
assert call('af_ae_stikers_delete',dict(id=large['data']['id']))['success']
assert listing()['storage']['used_bytes']==100
print('Sticker HTTP passed: actual bytes/formats, parallel quota enforcement, UID/CSRF, staff groups/forum moderation, URL toggle, no local URL bytes, over-quota preservation and deletion.')

external_gif=call('af_ae_stikers_upload',dict(sticker_url='https://images.example.org/verified.gif',sticker_title='Мой GIF'))
assert external_gif['success'],external_gif
record=next(s for s in listing()['user_stickers'] if s['id']==external_gif['data']['id'])
assert record['source']=='url' and record['title']=='Мой GIF',record
assert listing()['storage']['used_bytes']==100
assert not any(s['id']==record['id'] for s in listing(8)['user_stickers'])
for url in ['http://images.example.org/image.png','https://127.0.0.1/image.png','https://[::1]/image.png','https://private.example.org/image.png']:
    assert not call('af_ae_stikers_upload',dict(sticker_url=url))['success'],url
assert call('af_ae_stikers_delete',dict(id=record['id']))['success']
print('External sticker HTTP passed: HTTPS/public destinations, title/source persistence, GIF support, owner isolation, metadata-only deletion and no quota bytes.')
