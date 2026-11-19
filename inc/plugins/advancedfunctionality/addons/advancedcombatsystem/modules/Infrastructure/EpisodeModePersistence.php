<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

/** The ATF preference is read on the ACS writer, inside gameplay's lock when mutating. */
trait AF_ACS_EpisodeModePersistence
{
    private ?bool $episodeModeStorage = null;

    public function episodeModeStorageAvailable(): bool
    {
        if ($this->episodeModeStorage !== null) { return $this->episodeModeStorage; }
        $row = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->prefix.'af_atf_acs_episode_modes'])['rows'][0] ?? null;
        if ($row !== null && $row['ENGINE'] !== 'InnoDB') { throw new RuntimeException('ACS_EPISODE_STORAGE_INVALID'); }
        return $this->episodeModeStorage = $row !== null;
    }

    protected function episodeModeTable(): string
    {
        return '`'.$this->prefix.'af_atf_acs_episode_modes`';
    }

    public function episodeMode(string $tid): ?array
    {
        if (!$this->episodeModeStorageAvailable()) { return null; }
        $row = $this->sql('SELECT mode,version FROM '.$this->episodeModeTable().' WHERE tid=?', [$tid])['rows'][0] ?? null;
        if ($row !== null && !in_array($row['mode'], ['normal','combat'], true)) {
            throw new RuntimeException('ACS_EPISODE_MODE_INVALID');
        }
        return $row;
    }

    protected function assertEpisodeCombat(string $tid): void
    {
        if (($this->episodeMode($tid)['mode'] ?? null) === 'normal') {
            throw new RuntimeException('ACS_EPISODE_NORMAL');
        }
    }
}
