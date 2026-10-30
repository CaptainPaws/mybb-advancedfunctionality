<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';

final class AF_ACS_SnapshotStore extends AF_ACS_Repository
{
    public function append(string $sessionId, array $source, array $payload): string
    {
        return $this->transactions->run(function () use ($sessionId,$source,$payload) {
            $this->writable();
            $this->lockSession($sessionId);
            return $this->insertSnapshot($sessionId,$source,$payload);
        });
    }

    public function get(string $id): ?array
    {
        return $this->sql('SELECT * FROM '.$this->table('snapshots').' WHERE id=?', [self::integer($id)])['rows'][0] ?? null;
    }
}
