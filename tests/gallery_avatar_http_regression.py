"""Short optional HTTP check. Run fixture with MYBB_UPLOAD_SOURCE pointing to stock 1.8.40 functions_upload.php."""
import base64, hashlib, json, os, urllib.request, urllib.error, uuid
url=os.environ.get('AG_AVATAR_TEST_URL','http://127.0.0.1:8098')
gif=base64.b64decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
def submit(extra=None, content=gif, standard=False):
    fields={'my_post_key':'valid'}
    if not standard: fields.update(ag_avatar_confirm='1',ag_avatar_media_id='42')
    fields.update(extra or {})
    boundary=uuid.uuid4().hex
    parts=[]
    for key,value in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="avatarupload"; filename="original.gif"\r\nContent-Type: image/gif\r\n\r\n'.encode()+content+b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    request=urllib.request.Request(url,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try:
        with urllib.request.urlopen(request,timeout=10) as response: return response.status,json.load(response)
    except urllib.error.HTTPError as response: return response.code,json.load(response)
status,data=submit()
assert status==200 and not data['result'].get('error'), data
assert data['avatar_hash']==data['original_hash']==hashlib.sha256(gif).hexdigest(), data
for extra in [{'my_post_key':'bad'},{'denied':'1'},{'foreign':'1'},{'mime_denied':'1'},{'disabled':'1'}]:
    status,data=submit(extra)
    assert status==422 and data.get('error'), (extra,status,data)
status,data=submit(content=gif+b'different')
assert status==422 and data.get('error'),data
status,data=submit({'too_small':'1'})
assert status==200 and data['result'].get('error')=='Size denied',data
status,data=submit({'disabled':'1'},standard=True)
assert status==200 and not data['result'].get('error'),data
print('Native MyBB 1.8.40 upload_avatar passed on PHP 8.5: real multipart GIF, identical original/target hashes, CSRF/owner/permission/MIME/size failures, disabled-Gallery native upload.')
