"""Validate proposal DDL/indexes only, on an explicitly disposable Docker MySQL.
Usage: python tests/acs_inventory_owner_schema.py <disposable-container-name>
The container must have local root with no password; never pass a forum host.
"""
from pathlib import Path
import subprocess
import sys
import uuid

container = sys.argv[1]
database = 'acs611_fixture_' + uuid.uuid4().hex
root = Path(__file__).resolve().parents[1]


def sql(query, db=None, succeeds=True):
    cmd = ['docker', 'exec', '-i', container, 'mysql', '-uroot', '--batch', '--skip-column-names']
    if db:
        cmd.append(db)
    result = subprocess.run(cmd, input=query, text=True, capture_output=True)
    if succeeds and result.returncode:
        raise AssertionError(result.stderr)
    if not succeeds:
        assert result.returncode, 'Expected constraint violation: ' + query
    return result.stdout.strip()


sql('CREATE DATABASE ' + database)
try:
    sql((root / 'docs/contracts/fixtures/ACS_INVENTORY_OWNER_PROPOSAL.sql').read_text(), database)
    engines = sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()", database).splitlines()
    assert engines == ['InnoDB'] * 4, engines
    indexes = sql("SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() GROUP BY TABLE_NAME,INDEX_NAME", database)
    for expected in ('af_advinv_owner_state\tPRIMARY\tuid', 'af_advinv_external_operations\tPRIMARY\toperation_key', 'af_advinv_reservations\treservation_once\toperation_key', 'af_advinv_reservations\titem_active\titem_id,status', 'af_advinv_reservations\towner_context\towner_uid,context_system,context_key,status'):
        assert expected in indexes, expected
    sql("INSERT INTO af_advinv_owner_state VALUES (10,1); INSERT INTO af_advinv_items(id,uid,qty,created_at,updated_at) VALUES (21,10,3,1,1)", database)
    reserve = "INSERT INTO af_advinv_reservations(operation_key,owner_uid,item_id,initial_qty,remaining_qty,context_system,context_key,status,reservation_version,created_at,updated_at) VALUES ('%s',10,21,%d,%d,'acs','%s','%s',1,1,1)"
    sql(reserve % ('acs:r1', 1, 1, 'session-1', 'active'), database)
    sql(reserve % ('acs:r2', 1, 1, 'session-2', 'active'), database)
    assert sql("SELECT qty-(SELECT SUM(remaining_qty) FROM af_advinv_reservations WHERE item_id=21 AND status='active') FROM af_advinv_items WHERE id=21", database) == '1'
    sql(reserve % ('acs:r1', 1, 1, 'session-1', 'active'), database, False)
    sql(reserve % ('acs:zero', 0, 0, 's', 'active'), database, False)
    sql(reserve % ('acs:over', 1, 2, 's', 'active'), database, False)
    sql(reserve % ('acs:terminal', 1, 1, 's', 'released'), database, False)
    sql("UPDATE af_advinv_owner_state SET inventory_version=0 WHERE uid=10", database, False)
    # SQL fixture demonstrates available accounting, not enforcement by legacy writers.
    sql("UPDATE af_advinv_reservations SET remaining_qty=0,status='consumed',reservation_version=2 WHERE operation_key='acs:r1'", database)
    sql("UPDATE af_advinv_reservations SET remaining_qty=0,status='released',reservation_version=2 WHERE operation_key='acs:r2'", database)
    operation = "INSERT INTO af_advinv_external_operations VALUES ('%s','%s','consume',10,'acs','session-1',10,21,'committed','1','{}','{\"qty\":1}','%s',1,1)"
    sql(operation % ('acs:c1', 'a'*64, 'b'*64), database)
    sql(operation % ('acs:c1', 'a'*64, 'b'*64), database, False)
    sql(operation % ('ACS:c1', 'a'*64, 'b'*64), database)  # case-sensitive key
    sql('DELETE FROM af_advinv_items WHERE id=21', database)
    assert sql('SELECT COUNT(*) FROM af_advinv_reservations', database) == '2'
    assert sql("SELECT result_json FROM af_advinv_external_operations WHERE operation_key='acs:c1'", database) == '{"qty":1}'
    sql('START TRANSACTION; UPDATE af_advinv_owner_state SET inventory_version=2 WHERE uid=10; ROLLBACK', database)
    assert sql('SELECT inventory_version FROM af_advinv_owner_state WHERE uid=10', database) == '1'
    print('PASS: MySQL ' + sql('SELECT VERSION()') + ', proposal DDL, InnoDB/indexes, constraints, cross-session sum, unique/case-sensitive keys, retained receipt and rollback')
finally:
    sql('DROP DATABASE ' + database)
