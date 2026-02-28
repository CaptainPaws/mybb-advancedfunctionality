<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }

class AF_Admin_Advancedinventory
{
    public static function dispatch(string $action = ''): string
    {
        $html = self::render($action);
        echo $html;
        return $html;
    }

    public static function render(string $action = ''): string
    {
        global $mybb;
        $view = trim((string)$mybb->get_input('sub'));
        if ($view === 'shop_map') {
            return self::render_shop_map();
        }
        return self::render_inventory_list();
    }

    private static function render_inventory_list(): string
    {
        global $db, $mybb;

        $page = max(1, (int)$mybb->get_input('page'));
        $perPage = 20;
        $search = trim((string)$mybb->get_input('username'));
        $hasItems = trim((string)$mybb->get_input('has_items'));

        $where = ['u.uid > 0'];
        if ($search !== '') {
            $like = $db->escape_string_like($search);
            $where[] = "u.username LIKE '%{$like}%'";
        }
        if ($hasItems === 'yes') {
            $where[] = 'COALESCE(inv.total_rows,0) > 0';
        } elseif ($hasItems === 'no') {
            $where[] = 'COALESCE(inv.total_rows,0) = 0';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int)$db->fetch_field($db->query("SELECT COUNT(*) AS c
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN (
                SELECT uid, COUNT(*) AS total_rows, COALESCE(SUM(qty),0) AS total_qty
                FROM " . TABLE_PREFIX . "af_inventory_items
                GROUP BY uid
            ) inv ON(inv.uid=u.uid)
            WHERE {$whereSql}"), 'c');
        $offset = ($page - 1) * $perPage;

        $q = $db->query("SELECT u.uid, u.username, COALESCE(inv.total_rows,0) AS total_rows, COALESCE(inv.total_qty,0) AS total_qty
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN (
                SELECT uid, COUNT(*) AS total_rows, COALESCE(SUM(qty),0) AS total_qty
                FROM " . TABLE_PREFIX . "af_inventory_items
                GROUP BY uid
            ) inv ON(inv.uid=u.uid)
            WHERE {$whereSql}
            ORDER BY u.username ASC
            LIMIT {$offset}, {$perPage}");

        $rows = '';
        while ($row = $db->fetch_array($q)) {
            $url = '../inventory.php?uid=' . (int)$row['uid'];
            $rows .= '<tr><td><a href="../member.php?action=profile&amp;uid=' . (int)$row['uid'] . '">' . htmlspecialchars_uni((string)$row['username']) . '</a></td><td>' . (int)$row['total_rows'] . '</td><td>' . (int)$row['total_qty'] . '</td><td><a class="button" href="' . htmlspecialchars_uni($url) . '">Открыть инвентарь</a></td></tr>';
        }

        $mapUrl = 'index.php?module=config-plugins&action=advancedinventory&sub=shop_map';
        $html = '';
        $html .= '<div class="af-box"><h2>Инвентари пользователей</h2>';
        $html .= '<p><a class="button" href="' . htmlspecialchars_uni($mapUrl) . '">Мост Shop → Inventory</a></p>';
        $html .= '<form method="get"><input type="hidden" name="module" value="config-plugins"><input type="hidden" name="action" value="advancedinventory">';
        $html .= '<input type="text" name="username" placeholder="Username" value="' . htmlspecialchars_uni($search) . '"> ';
        $html .= '<select name="has_items"><option value="">Все</option><option value="yes"' . ($hasItems === 'yes' ? ' selected' : '') . '>Непустые</option><option value="no"' . ($hasItems === 'no' ? ' selected' : '') . '>Пустые</option></select> ';
        $html .= '<button type="submit" class="button">Фильтр</button></form>';
        $html .= '<table class="table"><thead><tr><th>Пользователь</th><th>Всего предметов</th><th>Всего qty</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $html .= '<p>Всего: ' . $total . '</p>';
        $html .= '</div>';
        return $html;
    }

    private static function render_shop_map(): string
    {
        global $db, $mybb;

        if (function_exists('af_advinv_shop_map_upgrade_schema')) {
            af_advinv_shop_map_upgrade_schema();
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            self::handle_shop_map_post();
            admin_redirect('index.php?module=config-plugins&action=advancedinventory&sub=shop_map');
        }

        $editId = (int)$mybb->get_input('edit_id');
        $editing = [];
        if ($editId > 0) {
            $editing = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, '*', 'id=' . $editId, ['limit' => 1]));
        }

        $shops = [];
        $shopQ = $db->simple_select('af_shop', 'shop_id,code,title', '', ['order_by' => 'title ASC, shop_id ASC']);
        while ($row = $db->fetch_array($shopQ)) {
            $shops[(int)$row['shop_id']] = $row;
        }

        $catsByShop = [];
        $catQ = $db->simple_select('af_shop_categories', 'cat_id,shop_id,title', '', ['order_by' => 'shop_id ASC, sortorder ASC, title ASC, cat_id ASC']);
        while ($row = $db->fetch_array($catQ)) {
            $catsByShop[(int)$row['shop_id']][] = $row;
        }

        $ruleRows = '';
        $q = $db->query("SELECT * FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " ORDER BY sortorder ASC, id ASC");
        while ($row = $db->fetch_array($q)) {
            $id = (int)$row['id'];
            $shopId = (int)$row['shop_id'];
            $catId = (int)$row['cat_id'];
            $shopTitle = (string)($shops[$shopId]['title'] ?? ('#' . $shopId));
            $catTitle = 'Все категории';
            if ($catId > 0) {
                $catTitle = '#' . $catId;
                foreach (($catsByShop[$shopId] ?? []) as $catRow) {
                    if ((int)$catRow['cat_id'] === $catId) {
                        $catTitle = (string)$catRow['title'];
                        break;
                    }
                }
            }

            $ruleRows .= '<tr>'
                . '<td>' . $id . '</td>'
                . '<td>' . htmlspecialchars_uni($shopTitle) . '</td>'
                . '<td>' . htmlspecialchars_uni($catTitle) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)$row['entity']) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)$row['default_subtype']) . '</td>'
                . '<td>' . ((int)$row['enabled'] === 1 ? 'Да' : 'Нет') . '</td>'
                . '<td>' . (int)$row['sortorder'] . '</td>'
                . '<td>'
                    . '<a href="index.php?module=config-plugins&amp;action=advancedinventory&amp;sub=shop_map&amp;edit_id=' . $id . '">Редактировать</a> · '
                    . '<form method="post" style="display:inline;margin:0">'
                        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                        . '<input type="hidden" name="map_action" value="move_up">'
                        . '<input type="hidden" name="id" value="' . $id . '">'
                        . '<button type="submit" class="button button_small">↑</button>'
                    . '</form> '
                    . '<form method="post" style="display:inline;margin:0">'
                        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                        . '<input type="hidden" name="map_action" value="move_down">'
                        . '<input type="hidden" name="id" value="' . $id . '">'
                        . '<button type="submit" class="button button_small">↓</button>'
                    . '</form> '
                    . '<form method="post" style="display:inline;margin:0" onsubmit="return confirm(\'Удалить правило?\');">'
                        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                        . '<input type="hidden" name="map_action" value="delete">'
                        . '<input type="hidden" name="id" value="' . $id . '">'
                        . '<button type="submit" class="button button_small">Удалить</button>'
                    . '</form>'
                . '</td>'
                . '</tr>';
        }

        $selectedShop = max(0, (int)($editing['shop_id'] ?? (int)$mybb->get_input('shop_id')));
        $selectedCat = max(0, (int)($editing['cat_id'] ?? 0));
        $selectedEntity = (string)($editing['entity'] ?? 'resources');
        $selectedSubtype = (string)($editing['default_subtype'] ?? '');
        $selectedEnabled = (int)($editing['enabled'] ?? 1);
        $selectedSort = (int)($editing['sortorder'] ?? self::next_sortorder());

        $shopOptions = '<option value="0">Выберите магазин</option>';
        foreach ($shops as $shopId => $shopRow) {
            $shopTitle = trim((string)($shopRow['title'] ?? ''));
            $label = $shopTitle !== '' ? $shopTitle : ('#' . $shopId . ' (' . (string)$shopRow['code'] . ')');
            $shopOptions .= '<option value="' . $shopId . '"' . ($shopId === $selectedShop ? ' selected' : '') . '>' . htmlspecialchars_uni($label) . '</option>';
        }

        $catOptions = '<option value="0">Все категории магазина</option>';
        foreach (($catsByShop[$selectedShop] ?? []) as $catRow) {
            $catId = (int)$catRow['cat_id'];
            $catOptions .= '<option value="' . $catId . '"' . ($catId === $selectedCat ? ' selected' : '') . '>' . htmlspecialchars_uni((string)$catRow['title']) . '</option>';
        }

        $entityOptions = '';
        foreach (array_keys(af_advancedinventory_tabs(false)) as $entity) {
            $entityOptions .= '<option value="' . htmlspecialchars_uni($entity) . '"' . ($selectedEntity === $entity ? ' selected' : '') . '>' . htmlspecialchars_uni($entity) . '</option>';
        }

        $html = '<div class="af-box">';
        $html .= '<h2>Мост Shop → Inventory</h2>';
        $html .= '<p><a class="button" href="index.php?module=config-plugins&amp;action=advancedinventory">← К списку инвентарей</a></p>';
        $html .= '<table class="table"><thead><tr><th>ID</th><th>Магазин</th><th>Категория</th><th>Entity</th><th>Default subtype</th><th>Enabled</th><th>Sort</th><th>Действия</th></tr></thead><tbody>' . ($ruleRows !== '' ? $ruleRows : '<tr><td colspan="8">Правил пока нет.</td></tr>') . '</tbody></table>';

        $html .= '<h3>' . ($editId > 0 ? 'Редактировать правило' : 'Добавить правило') . '</h3>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        $html .= '<input type="hidden" name="map_action" value="save">';
        $html .= '<input type="hidden" name="id" value="' . (int)$editId . '">';
        $html .= '<p><label>Магазин</label><br><select name="shop_id" required>' . $shopOptions . '</select></p>';
        $html .= '<p><label>Категория</label><br><select name="cat_id">' . $catOptions . '</select></p>';
        $html .= '<p><label>Entity</label><br><select name="entity">' . $entityOptions . '</select></p>';
        $html .= '<p><label>Default subtype (опционально)</label><br><input type="text" name="default_subtype" value="' . htmlspecialchars_uni($selectedSubtype) . '"></p>';
        $html .= '<p><label>Sortorder</label><br><input type="number" min="0" name="sortorder" value="' . $selectedSort . '"></p>';
        $html .= '<p><label><input type="checkbox" name="enabled" value="1"' . ($selectedEnabled === 1 ? ' checked' : '') . '> Включено</label></p>';
        $html .= '<p><button type="submit" class="button">Сохранить правило</button></p>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    private static function handle_shop_map_post(): void
    {
        global $db, $mybb;

        $action = trim((string)$mybb->get_input('map_action'));
        $id = (int)$mybb->get_input('id');

        if ($action === 'delete' && $id > 0) {
            $db->delete_query(AF_ADVINV_TABLE_SHOP_MAP, 'id=' . $id);
            return;
        }

        if (($action === 'move_up' || $action === 'move_down') && $id > 0) {
            self::shift_sortorder($id, $action === 'move_up' ? -1 : 1);
            return;
        }

        if ($action !== 'save') {
            return;
        }

        $shopId = (int)$mybb->get_input('shop_id');
        $catId = max(0, (int)$mybb->get_input('cat_id'));
        $entity = trim((string)$mybb->get_input('entity'));
        $defaultSubtype = trim((string)$mybb->get_input('default_subtype'));
        $enabled = (int)$mybb->get_input('enabled') === 1 ? 1 : 0;
        $sortorder = max(0, (int)$mybb->get_input('sortorder'));

        if ($shopId <= 0 || !af_advinv_entity_exists($entity)) {
            return;
        }

        $payload = [
            'shop_id' => $shopId,
            'cat_id' => $catId,
            'entity' => $db->escape_string($entity),
            'default_subtype' => $db->escape_string($defaultSubtype),
            'enabled' => $enabled,
            'sortorder' => $sortorder,
            'updated_at' => TIME_NOW,
        ];

        if ($id > 0) {
            $db->update_query(AF_ADVINV_TABLE_SHOP_MAP, $payload, 'id=' . $id);
        } else {
            $db->insert_query(AF_ADVINV_TABLE_SHOP_MAP, $payload);
        }
    }

    private static function shift_sortorder(int $id, int $direction): void
    {
        global $db;

        $current = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, 'id,sortorder', 'id=' . $id, ['limit' => 1]));
        if (!$current) {
            return;
        }

        $sort = (int)$current['sortorder'];
        $targetSort = $direction < 0 ? max(0, $sort - 1) : $sort + 1;

        $neighborWhere = $direction < 0 ? 'sortorder < ' . $sort : 'sortorder > ' . $sort;
        $neighborOrder = $direction < 0 ? 'sortorder DESC, id DESC' : 'sortorder ASC, id ASC';

        $neighbor = (array)$db->fetch_array($db->query("SELECT id, sortorder
            FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . "
            WHERE {$neighborWhere}
            ORDER BY {$neighborOrder}
            LIMIT 1"));
        if ($neighbor) {
            $db->update_query(AF_ADVINV_TABLE_SHOP_MAP, ['sortorder' => (int)$neighbor['sortorder']], 'id=' . $id);
            $db->update_query(AF_ADVINV_TABLE_SHOP_MAP, ['sortorder' => $sort], 'id=' . (int)$neighbor['id']);
            return;
        }

        $db->update_query(AF_ADVINV_TABLE_SHOP_MAP, ['sortorder' => $targetSort], 'id=' . $id);
    }

    private static function next_sortorder(): int
    {
        global $db;
        $max = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, 'MAX(sortorder) AS m'), 'm');
        return $max + 10;
    }
}
