<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }
if (!defined('AF_ADVINV_TABLE_SHOP_MAP')) { define('AF_ADVINV_TABLE_SHOP_MAP', 'af_advinv_shop_map'); }
if (!defined('AF_ADVINV_TABLE_ENTITIES')) { define('AF_ADVINV_TABLE_ENTITIES', 'af_advinv_entities'); }

class AF_Admin_Advancedinventory
{
    private static function baseUrl(string $do = '', array $params = []): string
    {
        $query = [
            'module' => 'advancedfunctionality',
            'af_view' => 'advancedinventory',
        ];
        if ($do !== '') {
            $query['do'] = $do;
        }
        return 'index.php?' . http_build_query(array_merge($query, $params), '', '&');
    }

    public static function dispatch(string $action = ''): string
    {
        @ini_set('log_errors', '1');
        try {
            $html = self::render($action);
            echo $html;
            return $html;
        } catch (\Throwable $e) {
            error_log('[AF advancedinventory admin] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            echo '<div class="error">Advanced Inventory admin error. Check PHP error log for details.</div>';
            return '';
        }
    }

    public static function render(string $action = ''): string
    {
        global $mybb;
        $view = trim((string)$mybb->get_input('do'));
        if ($view === 'shop_map') {
            return self::render_shop_map();
        }
        if ($view === 'entities') {
            return self::render_inventory_categories();
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
                FROM " . TABLE_PREFIX . "af_advinv_items
                GROUP BY uid
            ) inv ON(inv.uid=u.uid)
            WHERE {$whereSql}"), 'c');
        $offset = ($page - 1) * $perPage;

        $q = $db->query("SELECT u.uid, u.username, COALESCE(inv.total_rows,0) AS total_rows, COALESCE(inv.total_qty,0) AS total_qty
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN (
                SELECT uid, COUNT(*) AS total_rows, COALESCE(SUM(qty),0) AS total_qty
                FROM " . TABLE_PREFIX . "af_advinv_items
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

        $mapUrl = self::baseUrl('shop_map');
        $entitiesUrl = self::baseUrl('entities');
        $html = '';
        $html .= '<div class="af-box"><h2>Инвентари пользователей</h2>';
        $html .= '<p><a class="button" href="' . htmlspecialchars_uni($entitiesUrl) . '">Категории инвентаря</a> <a class="button" href="' . htmlspecialchars_uni($mapUrl) . '">Мост Shop → Inventory</a></p>';
        $html .= '<form method="get"><input type="hidden" name="module" value="advancedfunctionality"><input type="hidden" name="af_view" value="advancedinventory">';
        $html .= '<input type="text" name="username" placeholder="Username" value="' . htmlspecialchars_uni($search) . '"> ';
        $html .= '<select name="has_items"><option value="">Все</option><option value="yes"' . ($hasItems === 'yes' ? ' selected' : '') . '>Непустые</option><option value="no"' . ($hasItems === 'no' ? ' selected' : '') . '>Пустые</option></select> ';
        $html .= '<button type="submit" class="button">Фильтр</button></form>';
        $html .= '<table class="table"><thead><tr><th>Пользователь</th><th>Всего предметов</th><th>Всего qty</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $html .= '<p>Всего: ' . $total . '</p>';
        $html .= '</div>';
        return $html;
    }

    private static function render_inventory_categories(): string
    {
        global $db, $mybb;

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            self::handle_entity_post();
            admin_redirect(self::baseUrl('entities'));
        }

        $editEntity = trim((string)$mybb->get_input('edit_entity'));
        $editing = [];
        if ($editEntity !== '' && $db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            $editing = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ENTITIES, '*', "entity='" . $db->escape_string($editEntity) . "'", ['limit' => 1]));
        }

        $rowsHtml = '';
        foreach (af_advinv_get_entities(false) as $slug => $row) {
            $rowsHtml .= '<tr>'
                . '<td>' . htmlspecialchars_uni($slug) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['title_ru'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['title_en'] ?? '')) . '</td>'
                . '<td>' . ((int)($row['enabled'] ?? 0) === 1 ? 'Да' : 'Нет') . '</td>'
                . '<td>' . (int)($row['sortorder'] ?? 0) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['renderer'] ?? 'generic')) . '</td>'
                . '<td>'
                . '<a href="' . htmlspecialchars_uni(self::baseUrl('entities', ['edit_entity' => $slug])) . '">Редактировать</a> · '
                . '<form method="post" style="display:inline;margin:0">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="entity_action" value="move_up">'
                . '<input type="hidden" name="entity" value="' . htmlspecialchars_uni($slug) . '">'
                . '<button type="submit" class="button button_small">↑</button>'
                . '</form> '
                . '<form method="post" style="display:inline;margin:0">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="entity_action" value="move_down">'
                . '<input type="hidden" name="entity" value="' . htmlspecialchars_uni($slug) . '">'
                . '<button type="submit" class="button button_small">↓</button>'
                . '</form> '
                . '<form method="post" style="display:inline;margin:0" onsubmit="return confirm(\'Удалить категорию?\');">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="entity_action" value="delete">'
                . '<input type="hidden" name="entity" value="' . htmlspecialchars_uni($slug) . '">'
                . '<button type="submit" class="button button_small">Удалить</button>'
                . '</form>'
                . '</td></tr>';
        }

        $selectedEntity = (string)($editing['entity'] ?? '');
        $selectedTitleRu = (string)($editing['title_ru'] ?? '');
        $selectedTitleEn = (string)($editing['title_en'] ?? '');
        $selectedEnabled = (int)($editing['enabled'] ?? 1);
        $selectedSort = (int)($editing['sortorder'] ?? self::next_entity_sortorder());
        $selectedRenderer = (string)($editing['renderer'] ?? 'generic');
        $selectedSettingsJson = (string)($editing['settings_json'] ?? '{}');

        $html = '<div class="af-box">';
        $html .= '<h2>Категории инвентаря</h2>';
        $html .= '<p><a class="button" href="' . htmlspecialchars_uni(self::baseUrl()) . '">← К списку инвентарей</a></p>';
        $html .= '<table class="table"><thead><tr><th>Slug</th><th>Title RU</th><th>Title EN</th><th>Enabled</th><th>Sort</th><th>Renderer</th><th>Действия</th></tr></thead><tbody>' . ($rowsHtml !== '' ? $rowsHtml : '<tr><td colspan="7">Категории пока не созданы.</td></tr>') . '</tbody></table>';

        $html .= '<h3>' . ($selectedEntity !== '' ? 'Редактировать категорию' : 'Добавить категорию') . '</h3>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        $html .= '<input type="hidden" name="entity_action" value="save">';
        $html .= '<input type="hidden" name="original_entity" value="' . htmlspecialchars_uni($selectedEntity) . '">';
        $html .= '<p><label>Slug (entity)</label><br><input type="text" name="entity" maxlength="32" value="' . htmlspecialchars_uni($selectedEntity) . '" required></p>';
        $html .= '<p><label>Title RU</label><br><input type="text" name="title_ru" maxlength="255" value="' . htmlspecialchars_uni($selectedTitleRu) . '"></p>';
        $html .= '<p><label>Title EN</label><br><input type="text" name="title_en" maxlength="255" value="' . htmlspecialchars_uni($selectedTitleEn) . '"></p>';
        $html .= '<p><label>Sortorder</label><br><input type="number" name="sortorder" value="' . $selectedSort . '"></p>';
        $html .= '<p><label>Renderer</label><br><input type="text" name="renderer" maxlength="32" value="' . htmlspecialchars_uni($selectedRenderer) . '"></p>';
        $html .= '<p><label>Settings JSON</label><br><textarea name="settings_json" rows="5" cols="70">' . htmlspecialchars_uni($selectedSettingsJson) . '</textarea></p>';
        $html .= '<p><label><input type="checkbox" name="enabled" value="1"' . ($selectedEnabled === 1 ? ' checked' : '') . '> Включено</label></p>';
        $html .= '<p><button type="submit" class="button">Сохранить категорию</button></p>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    private static function handle_entity_post(): void
    {
        global $db, $mybb;

        $action = trim((string)$mybb->get_input('entity_action'));
        $entity = trim((string)$mybb->get_input('entity'));

        if ($action === 'delete' && $entity !== '') {
            if ($db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
                $db->delete_query(AF_ADVINV_TABLE_ENTITIES, "entity='" . $db->escape_string($entity) . "'");
            }
            return;
        }

        if ($action === 'move_up' || $action === 'move_down') {
            self::shift_entity_sortorder($entity, $action === 'move_up' ? -1 : 1);
            return;
        }

        if ($action !== 'save' || !$db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            return;
        }

        $entity = strtolower(trim((string)$mybb->get_input('entity')));
        $originalEntity = trim((string)$mybb->get_input('original_entity'));
        $titleRu = trim((string)$mybb->get_input('title_ru'));
        $titleEn = trim((string)$mybb->get_input('title_en'));
        $enabled = (int)$mybb->get_input('enabled') === 1 ? 1 : 0;
        $sortorder = (int)$mybb->get_input('sortorder');
        $renderer = trim((string)$mybb->get_input('renderer'));
        $settingsJson = trim((string)$mybb->get_input('settings_json'));

        if (!preg_match('/^[a-z0-9_]{1,32}$/', $entity)) {
            return;
        }
        if ($renderer === '') {
            $renderer = 'generic';
        }
        if ($settingsJson === '') {
            $settingsJson = '{}';
        }

        $payload = [
            'entity' => $db->escape_string($entity),
            'title_ru' => $db->escape_string($titleRu),
            'title_en' => $db->escape_string($titleEn),
            'enabled' => $enabled,
            'sortorder' => $sortorder,
            'renderer' => $db->escape_string(substr($renderer, 0, 32)),
            'settings_json' => $db->escape_string($settingsJson),
            'updated_at' => TIME_NOW,
        ];

        if ($originalEntity !== '' && $originalEntity !== $entity) {
            $db->delete_query(AF_ADVINV_TABLE_ENTITIES, "entity='" . $db->escape_string($originalEntity) . "'");
        }

        $exists = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ENTITIES, 'COUNT(*) AS c', "entity='" . $db->escape_string($entity) . "'", ['limit' => 1]), 'c');
        if ($exists > 0) {
            $db->update_query(AF_ADVINV_TABLE_ENTITIES, $payload, "entity='" . $db->escape_string($entity) . "'");
        } else {
            $db->insert_query(AF_ADVINV_TABLE_ENTITIES, $payload);
        }
    }

    private static function render_shop_map(): string
    {
        global $db, $mybb;

        if (function_exists('af_advinv_shop_map_upgrade_schema')) {
            af_advinv_shop_map_upgrade_schema();
        }

        if (!$db->table_exists(AF_ADVINV_TABLE_SHOP_MAP)) {
            return '<div class="error">Не удалось подготовить таблицу правил моста (af_advinv_shop_map).</div>';
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            self::handle_shop_map_post();
            admin_redirect(self::baseUrl('shop_map'));
        }

        $editId = (int)$mybb->get_input('edit_id');
        $editing = [];
        if ($editId > 0) {
            $editing = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, '*', 'id=' . $editId, ['limit' => 1]));
        }

        $shops = [];
        $shopTitles = [];
        if ($db->table_exists('af_shop_shops')) {
            $qShops = $db->simple_select('af_shop_shops', 'id,code,title,title_ru,title_en', '', ['order_by' => 'id', 'order_dir' => 'ASC']);
            while ($s = $db->fetch_array($qShops)) {
                $shopId = (int)$s['id'];
                $shops[$shopId] = $s;
                $shopTitles[$shopId] = trim((string)($s['title_ru'] ?: ($s['title_en'] ?: ($s['title'] ?: $s['code']))));
            }
        }

        $catsByShop = [];
        if ($db->table_exists('af_shop_categories')) {
            $qCats = $db->simple_select('af_shop_categories', 'id,shop_id,title,title_ru,title_en', '', ['order_by' => 'shop_id,id', 'order_dir' => 'ASC']);
            while ($c = $db->fetch_array($qCats)) {
                $shopId = (int)$c['shop_id'];
                $catsByShop[$shopId][] = [
                    'cat_id' => (int)$c['id'],
                    'title' => trim((string)($c['title_ru'] ?: ($c['title_en'] ?: $c['title']))),
                ];
            }
        }

        $ruleRows = '';
        $qRules = $db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, '*', '', ['order_by' => 'sortorder,id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($qRules)) {
            $id = (int)$row['id'];
            $shopId = (int)$row['shop_id'];
            $catId = (int)$row['cat_id'];
            $shopTitle = (string)($shopTitles[$shopId] ?? ('#' . $shopId));

            $catTitle = 'Все категории';
            if ($catId > 0) {
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
                    . '<a href="' . htmlspecialchars_uni(self::baseUrl('shop_map', ['edit_id' => $id])) . '">Редактировать</a> · '
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
        foreach (array_keys(af_advinv_get_entities(false)) as $entity) {
            $entityOptions .= '<option value="' . htmlspecialchars_uni($entity) . '"' . ($selectedEntity === $entity ? ' selected' : '') . '>' . htmlspecialchars_uni($entity) . '</option>';
        }

        $html = '<div class="af-box">';
        $html .= '<h2>Мост Shop → Inventory</h2>';
        $html .= '<p><a class="button" href="' . htmlspecialchars_uni(self::baseUrl()) . '">← К списку инвентарей</a></p>';
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

    private static function shift_entity_sortorder(string $entity, int $direction): void
    {
        global $db;

        if ($entity === '' || !$db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            return;
        }

        $entityEsc = $db->escape_string($entity);
        $current = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ENTITIES, 'entity,sortorder', "entity='{$entityEsc}'", ['limit' => 1]));
        if (!$current) {
            return;
        }

        $sort = (int)$current['sortorder'];
        $neighborWhere = $direction < 0 ? 'sortorder < ' . $sort : 'sortorder > ' . $sort;
        $neighborOrder = $direction < 0 ? 'sortorder DESC, entity DESC' : 'sortorder ASC, entity ASC';

        $neighbor = (array)$db->fetch_array($db->query("SELECT entity, sortorder
            FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . "
            WHERE {$neighborWhere}
            ORDER BY {$neighborOrder}
            LIMIT 1"));
        if (!$neighbor) {
            return;
        }

        $db->update_query(AF_ADVINV_TABLE_ENTITIES, ['sortorder' => (int)$neighbor['sortorder'], 'updated_at' => TIME_NOW], "entity='{$entityEsc}'");
        $db->update_query(AF_ADVINV_TABLE_ENTITIES, ['sortorder' => $sort, 'updated_at' => TIME_NOW], "entity='" . $db->escape_string((string)$neighbor['entity']) . "'");
    }

    private static function next_sortorder(): int
    {
        global $db;
        $max = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, 'MAX(sortorder) AS m'), 'm');
        return $max + 10;
    }

    private static function next_entity_sortorder(): int
    {
        global $db;
        if (!$db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            return 10;
        }
        $max = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ENTITIES, 'MAX(sortorder) AS m'), 'm');
        return $max + 10;
    }
}
