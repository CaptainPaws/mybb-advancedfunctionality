<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';

final class AF_ACS_OperationStore extends AF_ACS_Repository
{
    /** Register/lookup only. This API never executes an external side effect. */
    public function register(string $key, string $sessionId, string $kind, array $request): array
    {
        $key = self::key($key);
        $sessionId = self::integer($sessionId);
        $kind = self::key($kind, 64);
        $json = self::json($request);
        $hash = hash('sha256', $json);
        return $this->transactions->run(function () use ($key, $sessionId, $kind, $json, $hash) {
            $this->writable();
            $this->lockSession($sessionId);
            $old = $this->get($key);
            if ($old) {
                if ($old['payload_hash'] !== $hash || (string)$old['session_id'] !== $sessionId || $old['kind'] !== $kind) { throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT'); }
                return $old;
            }
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,?,?,?)', [$key, $sessionId, $kind, $hash, 'prepared', '1', $json, self::json(['schema_version' => 1]), time()]);
            return $this->get($key);
        });
    }

    public function get(string $key): ?array
    {
        return $this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=?', [self::key($key)])['rows'][0] ?? null;
    }

    /** CAS, terminal states cannot return to prepared or be applied twice. */
    public function transition(string $key, string $expectedVersion, string $status, array $result): array
    {
        $key = self::key($key);
        $version = self::integer($expectedVersion, '18446744073709551614');
        $json = self::json($result);
        return $this->transactions->run(function () use ($key, $version, $status, $json) {
            $this->writable();
            $row = $this->get($key);
            if (!$row) { throw new RuntimeException('ACS_OPERATION_NOT_FOUND'); }
            $this->lockSession((string)$row['session_id']);
            if (!in_array($row['status'], ['prepared', 'recovery_required'], true)
                || !in_array($status, ['committed', 'rejected', 'released', 'recovery_required'], true)
                || ($row['status'] === 'recovery_required' && $status === 'recovery_required')
                || $row['external_system'] !== null || $row['encounter_id'] !== null) { throw new RuntimeException('ACS_OPERATION_TRANSITION_DENIED'); }
            $updated = $this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=? AND operation_version=?', [$status, $json, time(), $key, $version]);
            if ($updated['affected'] !== 1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            return $this->get($key);
        });
    }
}
