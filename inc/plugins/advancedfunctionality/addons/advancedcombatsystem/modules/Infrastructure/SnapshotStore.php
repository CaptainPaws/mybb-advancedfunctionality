<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';

final class AF_ACS_SnapshotStore extends AF_ACS_Repository
{
    public function append(string $sessionId, array $source, array $payload): string
    {
        $json = self::json($payload);
        // Arithmetic/engine identifiers are explicit, never inferred on reload.
        self::text($payload['numeric_policy'] ?? '', 64);
        self::text($payload['engine_version'] ?? '', 64);
        $values = [self::integer($sessionId), self::key($source['source_system'] ?? '', 32), self::text($source['source_key'] ?? '', 191), self::key($source['contract_id'] ?? '', 96), self::text($source['contract_version'] ?? '', 64), $payload['schema_version'], hash('sha256', $json), $json, time()];
        return $this->transactions->run(function () use ($sessionId, $values) {
            $this->writable();
            $this->lockSession($sessionId);
            return $this->sql('INSERT INTO '.$this->table('snapshots').' (session_id,source_system,source_key,contract_id,contract_version,schema_version,content_hash,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)', $values)['id'];
        });
    }

    public function get(string $id): ?array
    {
        return $this->sql('SELECT * FROM '.$this->table('snapshots').' WHERE id=?', [self::integer($id)])['rows'][0] ?? null;
    }
}
