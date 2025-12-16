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
    public function ok(): bool
    {
        global $db;
        return $db->table_exists('aam_alerts') && $db->table_exists('aam_alert_types');
    }

    public function get_unread_count(int $uid): int
    {
        global $db;

        $uid = (int)$uid;
        if ($uid <= 0) return 0;

        $q = $db->simple_select('aam_alerts', 'COUNT(id) AS cnt', "uid={$uid} AND is_read=0");
        $row = $db->fetch_array($q);
        return (int)($row['cnt'] ?? 0);
    }

    public function get_newest_id(int $uid): int
    {
        global $db;

        $uid = (int)$uid;
        if ($uid <= 0) return 0;

        $q = $db->simple_select('aam_alerts', 'MAX(id) AS mid', "uid={$uid}");
        $mid = (int)$db->fetch_field($q, 'mid');
        return $mid > 0 ? $mid : 0;
    }

    public function list_alerts(int $uid, bool $unreadOnly, int $limit): array
    {
        $rows = $this->fetch_rows($uid, $unreadOnly, $limit, 0);
        return $this->map_items($rows);
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
            $newest = $this->get_newest_id($uid);
            $unread = $this->get_unread_count($uid);

            $changed = ($newest > $sinceId) || ($unread !== $sinceUnread);
            if ($changed) {
                $rows = $this->fetch_rows($uid, false, $limit, $sinceId);
                return [
                    'changed' => 1,
                    'unread' => $unread,
                    'server_newest_id' => $newest,
                    'items' => $this->map_items($rows),
                ];
            }

            // Пауза между проверками, чтобы не долбить БД десятками запросов за один HTTP-коннект
            usleep(900000); // ~0.9 сек
        } while (microtime(true) < $deadline);

        // timeout — изменений нет
        return [
            'changed' => 0,
            'unread' => $this->get_unread_count($uid),
            'server_newest_id' => $this->get_newest_id($uid),
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
    }

    public function mark_unread(int $uid, array $ids): void
    {
        global $db;
        $ids = $this->normalize_ids($ids);
        if (!$ids) return;

        $uid = (int)$uid;
        $db->update_query('aam_alerts', ['is_read' => 0], "uid={$uid} AND id IN(".implode(',', $ids).")");
    }

    public function delete_alerts(int $uid, array $ids): void
    {
        global $db;
        $ids = $this->normalize_ids($ids);
        if (!$ids) return;

        $uid = (int)$uid;
        $db->delete_query('aam_alerts', "uid={$uid} AND id IN(".implode(',', $ids).")");
    }

    public function mark_all_read(int $uid): void
    {
        global $db;
        $uid = (int)$uid;
        if ($uid <= 0) return;

        $db->update_query('aam_alerts', ['is_read' => 1], "uid={$uid} AND is_read=0");
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
        global $db;

        $uid = (int)$uid;
        if ($uid <= 0) return [];

        $limit = (int)$limit;
        if ($limit <= 0) $limit = 20;
        if ($limit > 100) $limit = 100;

        $where = "a.uid={$uid}";
        if ($unreadOnly) {
            $where .= " AND a.is_read=0";
        }
        if ($minIdExclusive > 0) {
            $where .= " AND a.id > ".(int)$minIdExclusive;
        }

        $sql = $db->write_query("
            SELECT a.*, t.code, t.title,
                   u.username AS from_username, u.avatar AS from_avatar, u.avatardimensions AS from_avatardimensions
            FROM " . TABLE_PREFIX . "aam_alerts a
            LEFT JOIN " . TABLE_PREFIX . "aam_alert_types t ON (t.id=a.type_id)
            LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid=a.from_uid)
            WHERE {$where}
            ORDER BY a.id DESC
            LIMIT {$limit}
        ");

        $rows = [];
        while ($row = $db->fetch_array($sql)) {
            $rows[] = $row;
        }
        return $rows;
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
