"""Focused contract checks, no forum DB and no executable owner API.
Run: python tests/acs_inventory_owner_contract.py (requires jsonschema).
"""
import copy
import hashlib
import json
from pathlib import Path
from jsonschema import Draft202012Validator, validators

ROOT = Path(__file__).resolve().parents[1]
schema = json.loads((ROOT / 'docs/contracts/ACS_INVENTORY_OWNER_REQUEST.schema.json').read_text())
Draft202012Validator.check_schema(schema)
# JSON Schema treats 1.0 as integer; the server contract additionally requires
# integer runtime type (PHP is_int), checked here without coercion.
strict_types = Draft202012Validator.TYPE_CHECKER.redefine("integer", lambda checker, value: type(value) is int)
validator = validators.extend(Draft202012Validator, type_checker=strict_types)(schema)


def canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode()


def payload_hash(request):
    return hashlib.sha256(canonical({k: v for k, v in request.items() if k not in ('operation_key', 'payload_hash')})).hexdigest()


payloads = {
    'reserve': dict(owner_uid=10, item_id=21, qty=2, session_id='session-1', expected_inventory_version=7),
    'consume': dict(reservation_id='42', qty=1, expected_version=1),
    'release': dict(reservation_id='42', reason='Явное завершение / сессии'),
    'transfer': dict(item_id=21, qty=1, from_uid=10, to_uid=11, expected_versions=dict(from_=7, to=4)),
    'grant': dict(recipient_uid=10, normalized_item=dict(qty=1, definition_ref=dict(type='arpg_item', key='potion', version='v1', hash='a'*64), snapshot_json='{"title":"Зелье"}', entity='equipment', subtype='consumable'), provenance=dict(system='acs', source_key='reward-1')),
    'lookup': dict(target_operation_key='acs:reserve:1'),
}
payloads['transfer']['expected_versions']['from'] = payloads['transfer']['expected_versions'].pop('from_')
requests = []
for operation, payload in payloads.items():
    request = dict(schema_version=1, operation=operation, operation_key=f'acs:{operation}:1', actor=dict(uid=10, authorization_ref='grant-1'), scope=dict(system='acs', context_key='session-1'), payload_hash='0'*64, payload=payload)
    request['payload_hash'] = payload_hash(request)
    validator.validate(request)
    assert payload_hash(dict(reversed(list(request.items())))) == request['payload_hash']
    changed = copy.deepcopy(request)
    changed['actor']['uid'] = 11
    assert payload_hash(changed) != request['payload_hash']
    changed = copy.deepcopy(request)
    changed['scope']['context_key'] = 'session-2'
    assert payload_hash(changed) != request['payload_hash']
    changed = copy.deepcopy(request)
    changed['payload_hash'] = 'f'*64
    assert payload_hash(changed) == request['payload_hash']  # hash field excluded
    for key in ('validated', 'damage', 'permissions'):
        bad = copy.deepcopy(request)
        bad[key] = True
        assert not validator.is_valid(bad), (operation, key)
    requests.append(request)

for request in requests:
    path = request['payload']['normalized_item'] if request['operation'] == 'grant' else request['payload']
    if 'qty' not in path:
        continue
    for qty in (0, -1, '1', True, 1.0, 1.5, 2147483648):
        bad = copy.deepcopy(request)
        target = bad['payload']['normalized_item'] if bad['operation'] == 'grant' else bad['payload']
        target['qty'] = qty
        assert not validator.is_valid(bad), (request['operation'], qty)

# Boundary inventory: catches new direct write call sites, including constants and
# ACP's variable table loop. Deliberately conservative, not a PHP control-flow proof.
import re
base = ROOT / 'inc/plugins/advancedfunctionality/addons'
expected = {
    'advancedinventory/advancedinventory.php': {
        'af_advancedinventory_install', 'af_advancedinventory_upgrade_schema',
        'af_advancedinventory_uninstall', 'af_advinv_normalize_customization_items_in_db',
        'af_advancedinventory_api_equip', 'af_advancedinventory_api_unequip',
        'af_advancedinventory_api_bind_support_slot', 'af_advancedinventory_api_unbind_support_slot',
        'af_advancedinventory_api_update', 'af_advinv_delete_owner_item_slot',
        'af_advancedinventory_api_sell', 'af_advinv_add_item_locked', 'af_inv_remove_item',
    },
    'charactersheets/modules/ajax.php': {'af_charactersheets_handle_api_impl'},
}
found = {}
for file in base.rglob('*.php'):
    current = '<file>'
    for line in file.read_text().splitlines():
        match = re.search(r'\bfunction\s+(\w+)\s*\(', line)
        if match:
            current = match[1]
        if re.search(r'->(?:insert_query|update_query|delete_query|drop_table)\s*\(\s*(?:AF_ADVINV_TABLE_ITEMS|[\'"]af_advinv_(?:items|equipped|support_slots)[\'"])', line) or re.search(r'->write_query\(.*(?:CREATE TABLE|ALTER TABLE|UPDATE).*?(?:AF_ADVINV_TABLE_ITEMS|af_advinv_(?:equipped|support_slots))', line):
            found.setdefault(str(file.relative_to(base)), set()).add(current)
assert found == expected, (found, expected)
admin = (base / 'advancedinventory/admin.php').read_text()
assert "'af_advinv_items'," in admin and "'af_advinv_equipped'," in admin
assert "$db->delete_query($table, '1=1')" in admin
# Guard against treating these legacy paths as an owner gateway.
adapter = (base / 'advancedcombatsystem/modules/Infrastructure/Adapters/InventoryAdapter.php').read_text()
assert 'ACS_INVENTORY_WRITERS_NOT_INTEGRATED' in adapter
assert 'af_inv_remove_item(' not in adapter and '->sql(' not in adapter
print('PASS: six payloads, strict quantities, hash binding and direct writer inventory (not runtime authorization/atomicity)')
if __name__ == '__main__':
    # Optional golden vectors for PHP/other consumers; stdout stays concise by default.
    import sys
    if len(sys.argv) == 2:
        Path(sys.argv[1]).write_text(json.dumps(requests, ensure_ascii=False, indent=2)+'\n')
