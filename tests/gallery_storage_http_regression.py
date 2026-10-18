"""Run against the isolated PHP fixture after gallery_media_api_integration.php."""
import base64
import json
import os
import urllib.error
import urllib.parse
import urllib.request

ORIGIN = os.environ.get('AG_FIXTURE_ORIGIN', 'http://127.0.0.1:8099')
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6l8AAAAASUVORK5CYII=')

def request(action, uid=7, group=2, file=None, fields=None, limit=1):
    query = urllib.parse.urlencode(dict(action=action, fixture_uid=uid, fixture_group=group, storage_limit=limit))
    data = None
    headers = {}
    if file is not None:
        fields = dict(my_post_key='fixture-key', ajax='1', **(fields or {}))
        boundary = 'GalleryStorageBoundary'
        data = b''
        for key, value in fields.items():
            data += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
        data += f'--{boundary}\r\nContent-Disposition: form-data; name="ag_file"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + file + f'\r\n--{boundary}--\r\n'.encode()
        headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
    elif fields is not None:
        data = urllib.parse.urlencode(dict(my_post_key='fixture-key', ajax='1', **fields)).encode()
    req = urllib.request.Request(ORIGIN+'/gallery.php?'+query, data=data, headers=headers)
    try:
        response = urllib.request.urlopen(req)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, json.loads(response.read())

code, data = request('upload_do', file=PNG)
assert code == 422 and not data['ok'] and data['storage']['full'], (code, data)
code, data = request('upload_do', group=4, file=PNG)
assert code == 200 and data['ok'] and data['storage']['limit_bytes'] == 0, (code, data)
staff_id = data['id']
code, data = request('upload_do', group=4, file=PNG+b'\0'*1048576)
assert code == 422 and '1 МБ' in data['error'], (code, data)
code, data = request('delete', fields=dict(id=staff_id))
assert code == 200 and data['storage']['used_bytes'] == 1048576, (code, data)
code, data = request('upload_do', uid=30, file=PNG)
assert code == 200 and data['storage']['used_bytes'] == len(PNG), (code, data)
media_id = data['id']
code, data = request('storage_usage', uid=30)
assert code == 200 and data['storage']['used_bytes'] == len(PNG), (code, data)
code, data = request('delete', uid=30, fields=dict(id=media_id))
assert code == 200 and data['storage']['used_bytes'] == 0, (code, data)
print('Storage HTTP passed: multipart upload, full quota rejection, staff exemption, single file limit for staff, own usage JSON, deletion releases bytes.')
