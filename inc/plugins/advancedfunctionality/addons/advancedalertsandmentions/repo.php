<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_aam_repo(): AF_AAM_Repo
{
    static $inst = null;
    if ($inst instanceof AF_AAM_Repo) {
        return $inst;
    }
    $inst = new AF_AAM_Repo();
    return $inst;
}

class AF_AAM_Repo
{
    /** @var array<int, array{unread: int, newest_id: int}> */
    private $statsCache = [];

    public function ok(): bool
    {
        global $db;
        return $db->table_exists('aam_alerts') && $db->table_exists('aam_alert_types');
    }

    public function get_unread_count(int $uid): int
    {
        $stats = $this->get_stats($uid);
        return $stats['unread'];
    }

    public function get_newest_id(int $uid): int
    {
        $stats = $this->get_stats($uid);
        return $stats['newest_id'];
    }

    public function list_alerts(int $uid, bool $unreadOnly, int $limit): array
    {
        $data = $this->list_alerts_with_stats($uid, $unreadOnly, $limit, 0);
        return $data['items'];
    }

    public function list_alerts_with_stats(int $uid, bool $unreadOnly, int $limit, int $minIdExclusive = 0): array
    {
        $bundle = $this->fetch_rows_bundle($uid, $unreadOnly, $limit, $minIdExclusive);

        return [
            'items' => $this->map_items($bundle['rows']),
            'unread' => $bundle['stats']['unread'],
            'newest_id' => $bundle['stats']['newest_id'],
        ];
    }

    /**
     * Long-poll: ждёт изменения newest_id или unread_count.
     */
    public function poll(int $uid, int $sinceId, int $sinceUnread, int $timeout, int $limit): array
    {
        $uid = (int)$uid;
        $sinceId = (int)$sinceId;
        $sinceUnread = (int)$sinceUnread;

        if ($timeout <= 0) $timeout = 25;
        if ($timeout > 30) $timeout = 30;
        if ($limit <= 0) $limit = 5;

        $deadline = microtime(true) + $timeout;

        do {
            $stats = $this->get_stats($uid);

            $changed = ($stats['newest_id'] > $sinceId) || ($stats['unread'] !== $sinceUnread);
            if ($changed) {
                $data = $this->list_alerts_with_stats($uid, false, $limit, $sinceId);
                return [
                    'changed' => 1,
                    'unread' => $data['unread'],
                    'server_newest_id' => $data['newest_id'],
                    'items' => $data['items'],
                ];
            }

            // Пауза между проверками, чтобы не долбить БД десятками запросов за один HTTP-коннект
            usleep(900000); // ~0.9 сек
        } while (microtime(true) < $deadline);

        // timeout — изменений нет
        return [
            'changed' => 0,
            'unread' => $stats['unread'],
            'server_newest_id' => $stats['newest_id'],
            'items' => [],
        ];
    }

    public function mark_read(int $uid, array $ids): void
    {
        global $db;
        $ids = $this->normalize_ids($ids);
        if (!$ids) return;

        $uid = (int)$uid;
        $db->update_query('aam_alerts', ['is_read' => 1], "uid={$uid} AND id IN(".implode(',', $ids).")");
        $this->invalidate_stats($uid);
    }

    public function mark_unread(int $uid, array $ids): void
    {
        global $db;
        $ids = $this->normalize_ids($ids);
        if (!$ids) return;

        $uid = (int)$uid;
        $db->update_query('aam_alerts', ['is_read' => 0], "uid={$uid} AND id IN(".implode(',', $ids).")");
        $this->invalidate_stats($uid);
    }

    public function delete_alerts(int $uid, array $ids): void
    {
        global $db;
        $ids = $this->normalize_ids($ids);
        if (!$ids) return;

        $uid = (int)$uid;
        $db->delete_query('aam_alerts', "uid={$uid} AND id IN(".implode(',', $ids).")");
        $this->invalidate_stats($uid);
    }

    public function mark_all_read(int $uid): void
    {
        global $db;
        $uid = (int)$uid;
        if ($uid <= 0) return;

        $db->update_query('aam_alerts', ['is_read' => 1], "uid={$uid} AND is_read=0");
        $this->invalidate_stats($uid);
    }

    // ----------------- internals -----------------

    private function normalize_ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $v) {
            $v = (int)$v;
            if ($v > 0) $out[] = $v;
        }
        $out = array_values(array_unique($out));
        return $out;
    }

    private function fetch_rows(int $uid, bool $unreadOnly, int $limit, int $minIdExclusive): array
    {
        $bundle = $this->fetch_rows_bundle($uid, $unreadOnly, $limit, $minIdExclusive);
        return $bundle['rows'];
    }

    private function fetch_rows_bundle(int $uid, bool $unreadOnly, int $limit, int $minIdExclusive): array
    {
        global $db;

        $uid = (int)$uid;
        if ($uid <= 0) {
            return [
                'rows' => [],
                'stats' => ['unread' => 0, 'newest_id' => 0],
            ];
        }

        $limit = (int)$limit;
        if ($limit <= 0) $limit = 20;
        if ($limit > 100) $limit = 100;

        $where = "uid={$uid}";
        if ($unreadOnly) {
            $where .= " AND is_read=0";
        }
        if ($minIdExclusive > 0) {
            $where .= " AND id > ".(int)$minIdExclusive;
        }

        $statsSql = "
            SELECT SUM(is_read=0) AS unread_count, MAX(id) AS newest_id
            FROM " . TABLE_PREFIX . "aam_alerts
            WHERE uid={$uid}
        ";

        $alertsSql = "
            SELECT *
            FROM " . TABLE_PREFIX . "aam_alerts
            WHERE {$where}
            ORDER BY id DESC
            LIMIT {$limit}
        ";

        $sql = $db->write_query("
            SELECT a1.*, t.code, t.title,
                   u.username AS from_username, u.avatar AS from_avatar, u.avatardimensions AS from_avatardimensions,
                   stats.unread_count, stats.newest_id
            FROM ({$statsSql}) stats
            LEFT JOIN ({$alertsSql}) a1 ON 1=1
            LEFT JOIN " . TABLE_PREFIX . "aam_alert_types t ON (t.id=a1.type_id)
            LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid=a1.from_uid)
            ORDER BY a1.id DESC
        ");

        $rows = [];
        $stats = null;

        while ($row = $db->fetch_array($sql)) {
            if ($stats === null) {
                $stats = [
                    'unread' => (int)($row['unread_count'] ?? 0),
                    'newest_id' => (int)($row['newest_id'] ?? 0),
                ];
                $this->cache_stats($uid, $stats);
            }

            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $rows[] = $row;
            }
        }

        if ($stats === null) {
            $stats = ['unread' => 0, 'newest_id' => 0];
            $this->cache_stats($uid, $stats);
        }

        return [
            'rows' => $rows,
            'stats' => $stats,
        ];
    }

    private function get_stats(int $uid): array
    {
        global $db;

        $uid = (int)$uid;
        if ($uid <= 0) {
            return ['unread' => 0, 'newest_id' => 0];
        }

        if (isset($this->statsCache[$uid])) {
            return $this->statsCache[$uid];
        }

        $q = $db->write_query("
            SELECT SUM(is_read=0) AS unread_count, MAX(id) AS newest_id
            FROM " . TABLE_PREFIX . "aam_alerts
            WHERE uid={$uid}
        ");

        $row = $db->fetch_array($q);
        $stats = [
            'unread' => (int)($row['unread_count'] ?? 0),
            'newest_id' => (int)($row['newest_id'] ?? 0),
        ];

        $this->cache_stats($uid, $stats);
        return $stats;
    }

    private function cache_stats(int $uid, array $stats): void
    {
        $uid = (int)$uid;
        if ($uid <= 0) return;

        $this->statsCache[$uid] = [
            'unread' => (int)($stats['unread'] ?? 0),
            'newest_id' => (int)($stats['newest_id'] ?? 0),
        ];
    }

    private function invalidate_stats(int $uid): void
    {
        $uid = (int)$uid;
        unset($this->statsCache[$uid]);
    }

    private function map_items(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            $text = 'Уведомление';
            $url  = '';

            if (function_exists('af_aam_format_alert')) {
                $fmt = af_aam_format_alert($row);
                if (is_array($fmt)) {
                    $text = (string)($fmt['text'] ?? $text);
                    $url  = (string)($fmt['url'] ?? $url);
                }
            }

            if (function_exists('af_aam_normalize_url')) {
                $url = af_aam_normalize_url($url);
            }

            // ВАЖНО: не теряем отправителя и тип (они нужны для аватаров и prefs-фильтра)
            $typeCode  = (string)($row['code'] ?? '');
            $typeTitle = (string)($row['title'] ?? '');

            $items[] = [
                'id'      => (int)($row['id'] ?? 0),
                'is_read' => (int)($row['is_read'] ?? 0),

                // тип
                'type_id'    => (int)($row['type_id'] ?? 0),
                'type_code'  => $typeCode,
                'type_title' => $typeTitle,

                // совместимость со старыми местами (у тебя фильтр смотрит ещё code/title)
                'code'  => $typeCode,
                'title' => $typeTitle,

                // отправитель (КЛЮЧЕВО для аватаров)
                'from_uid'      => (int)($row['from_uid'] ?? 0),
                'from_username' => (string)($row['from_username'] ?? ''),

                // контент
                'text' => $text,
                'url'  => $url,
            ];
        }

        return $items;
    }

}
