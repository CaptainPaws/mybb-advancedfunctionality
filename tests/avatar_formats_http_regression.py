"""Short optional PHP 8.5/GD HTTP matrix; same native MyBB/APF fixture as the avatar tests."""
import hashlib,io,json,os,urllib.request,urllib.error,uuid
from PIL import Image
URL=os.environ.get('AG_AVATAR_TEST_URL','http://127.0.0.1:8098')
def image(fmt,animated=False):
    stream=io.BytesIO();first=Image.new('RGB',(12,16),'#336699')
    if animated:first.save(stream,format=fmt,save_all=True,append_images=[Image.new('RGB',(12,16),'#cc8844')],duration=100,loop=0)
    else:first.save(stream,format=fmt)
    return stream.getvalue()
def send(content,mime,extension,extra=None,gallery=False,secondary=False):
    fields={'my_post_key':'valid','action':'do_apf_secondary_avatar' if secondary else 'do_avatar','uid':'999'}
    if not secondary:fields.update(atf_avatar_async='1',native_route='1',atf_avatar_editor='1')
    if gallery:fields.update(ag_avatar_confirm='1',ag_avatar_media_id='42')
    fields.update(extra or {})
    boundary=uuid.uuid4().hex
    data=b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k,v in fields.items())
    name='secondary_avatar_upload' if secondary else 'avatarupload'
    data+=f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="avatar.{extension}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode()
    req=urllib.request.Request(URL,data=data,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try:
        with urllib.request.urlopen(req,timeout=15) as r:
            body=r.read().decode();
            try:return r.status,json.loads(body)
            except ValueError:raise AssertionError(body[:1500])
    except urllib.error.HTTPError as r:return r.code,json.load(r)
for fmt,mime,ext in [('JPEG','image/jpeg','jpg'),('PNG','image/png','png'),('GIF','image/gif','gif'),('WEBP','image/webp','webp')]:
    content=image(fmt,animated=fmt=='GIF')
    code,result=send(content,mime,ext)
    assert code==200 and result['ok'] and result['avatar']['role']=='primary' and result['avatar']['uid']==7,(fmt,result)
    assert result['avatar']['url'].split('?')[0].endswith('.png' if fmt=='WEBP' else '.'+ext),result
    if fmt!='WEBP':
        expected=json.dumps([result['avatar']['url'],result['avatar']['dimensions'],result['avatar']['type'],hashlib.sha256(content).hexdigest()],separators=(',',':')).encode()
        assert result['avatar']['version']==hashlib.sha256(expected).hexdigest(),(fmt,'Original bytes changed')
    code,result=send(content,mime,ext,secondary=True)
    assert code==200 and not result.get('error') and result['primary']=='untouched-primary',result
    assert result['avatar_hash']==hashlib.sha256(content).hexdigest(),(fmt,result)
webp=open('work/avatar-format.webp','rb').read()
code,result=send(webp,'image/webp','webp',{'media_format':'webp'},gallery=True)
assert code==200 and result['ok'] and '.png?' in result['avatar']['url'] and result['avatar']['mime']=='image/png',result
code,result=send(webp,'image/webp','webp',{'media_format':'webp'},gallery=True,secondary=True)
assert code==200 and result['avatar_hash']==result['original_hash']==hashlib.sha256(webp).hexdigest(),result
code,result=send(image('WEBP',True),'image/webp','webp')
assert code==422 and 'Анимированный WebP' in result['error'],result
for extra in [{'my_post_key':'bad'},{'denied':'1'},{'mime_denied':'1'},{'too_small':'1'}]:
    code,result=send(image('WEBP'),'image/webp','webp',extra)
    assert code==422 and result.get('error'),(extra,result)
print('Actual save matrix passed: primary JPEG/PNG/animated GIF/WebP -> native MyBB users write/read-back; APF all four byte-preserved; Gallery WebP both roles; animated-WebP refusal; permissions/CSRF/size/MIME.')

animated=image('WEBP',True)
code,result=send(animated,'image/webp','webp',secondary=True)
assert code==200 and result['avatar_hash']==hashlib.sha256(animated).hexdigest(),result
code,result=send(image('JPEG'),'image/jpeg','jpg',{'mismatch_receipt':'1'})
assert code==500 and not result['ok'] and result.get('error'),result
print('Animation bytes and non-fictitious receipts verified: original GIF hashes, APF animated WebP retained, mismatched database expectation cannot return success.')
