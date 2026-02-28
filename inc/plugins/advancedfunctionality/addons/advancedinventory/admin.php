<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }
if (!defined('AF_ADVINV_TABLE_SHOP_MAP')) { define('AF_ADVINV_TABLE_SHOP_MAP', 'af_advinv_shop_map'); }
if (!defined('AF_ADVINV_TABLE_ENTITIES')) { define('AF_ADVINV_TABLE_ENTITIES', 'af_advinv_entities'); }
if (!defined('AF_ADVINV_TABLE_ENTITY_FILTERS')) { define('AF_ADVINV_TABLE_ENTITY_FILTERS', 'af_advinv_entity_filters'); }

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
        global $mybb;

        @ini_set('log_errors', '1');
        try {
            $html = self::render($action);
            echo $html;
            return $html;
        } catch (\Throwable $e) {
            $payload = [
                'do' => trim((string)($mybb->get_input('do') ?? '')),
                'uid' => (int)($mybb->user['uid'] ?? 0),
                'get' => (array)($_GET ?? []),
                'post' => (array)($_POST ?? []),
                'msg' => (string)$e->getMessage(),
                'file' => (string)$e->getFile(),
                'line' => (int)$e->getLine(),
            ];
            error_log('[AF-ADVINV][admin_exception] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            error_log('[AF-ADVINV][admin_exception_trace] ' . $e->getTraceAsString());
            echo '<div class="error">Advanced Inventory admin error. Check PHP error log for details.</div>';
            if (self::is_super_admin()) {
                echo '<div class="error">' . htmlspecialchars_uni((string)$e->getMessage()) . ' <small>' . htmlspecialchars_uni((string)$e->getFile() . ':' . (int)$e->getLine()) . '</small></div>';
            }
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
            return self::render_entities();
        }
        if ($view === 'entity_filters') {
            return self::render_entity_filters();
        }
        return self::render_inventory_list();
    }

    private static function is_super_admin(): bool
    {
        global $mybb, $config;

        $uid = (int)($mybb->user['uid'] ?? 0);
        if ($uid <= 0) {
            return false;
        }

        $list = trim((string)($config['super_admins'] ?? ''));
        if ($list === '') {
            return false;
        }

        $uids = array_map('intval', array_filter(array_map('trim', explode(',', $list)), 'strlen'));
        return in_array($uid, $uids, true);
    }

    private static function render_inventory_list(): string
    {
        global $db, $mybb;

        if ($mybb->request_method === 'post' && trim((string)$mybb->get_input('do')) === 'reset_test_data') {
            verify_post_check($mybb->get_input('my_post_key'));
            $confirm = trim((string)$mybb->get_input('confirm'));
            if ($confirm !== 'yes') {
                flash_message('Сброс отменён: отсутствует подтверждение confirm=yes.', 'error');
                admin_redirect(self::baseUrl());
            }

            $deleted = self::reset_test_data_tables();
            $parts = [];
            foreach ($deleted as $table => $count) {
                $parts[] = $table . ': ' . $count;
            }
            flash_message('Тестовые данные магазина и инвентаря очищены. ' . implode(', ', $parts), 'success');
            admin_redirect(self::baseUrl());
        }

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
        $filtersUrl = self::baseUrl('entity_filters');
        $html = '';
        $html .= '<div class="af-box"><h2>Инвентари пользователей</h2>';
        $html .= '<p><a class="button" href="' . htmlspecialchars_uni($entitiesUrl) . '">Категории инвентаря</a> <a class="button" href="' . htmlspecialchars_uni($filtersUrl) . '">Сабфильтры категорий</a> <a class="button" href="' . htmlspecialchars_uni($mapUrl) . '">Мост Shop → Inventory</a></p>';
        $html .= '<form method="post" action="' . htmlspecialchars_uni(self::baseUrl()) . '" onsubmit="return confirm(\'Сбросить тестовые данные Shop + Inventory? Действие необратимо.\');" style="margin:0 0 12px 0;">';
        $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        $html .= '<input type="hidden" name="do" value="reset_test_data">';
        $html .= '<input type="hidden" name="confirm" value="yes">';
        $html .= '<button type="submit" class="button" style="background:#b94a48;color:#fff;border-color:#953b39;">Сбросить тестовые данные (Shop + Inventory)</button>';
        $html .= '</form>';
        $html .= '<form method="get"><input type="hidden" name="module" value="advancedfunctionality"><input type="hidden" name="af_view" value="advancedinventory">';
        $html .= '<input type="text" name="username" placeholder="Username" value="' . htmlspecialchars_uni($search) . '"> ';
        $html .= '<select name="has_items"><option value="">Все</option><option value="yes"' . ($hasItems === 'yes' ? ' selected' : '') . '>Непустые</option><option value="no"' . ($hasItems === 'no' ? ' selected' : '') . '>Пустые</option></select> ';
        $html .= '<button type="submit" class="button">Фильтр</button></form>';
        $html .= '<table class="table"><thead><tr><th>Пользователь</th><th>Всего предметов</th><th>Всего qty</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $html .= '<p>Всего: ' . $total . '</p>';
        $html .= '</div>';
        return $html;
    }

    private static function reset_test_data_tables(): array
    {
        global $db;

        $tables = [
            'af_shop_slots',
            'af_shop_orders',
            'af_shop_cart_items',
            'af_shop_carts',
            'af_shop_inventory_legacy',
            'af_advinv_equipped',
            'af_advinv_items',
        ];

        $deleted = [];
        foreach ($tables as $table) {
            $deleted[$table] = 0;
            if (!$db->table_exists($table)) {
                continue;
            }

            $db->delete_query($table, '1=1');
            $deleted[$table] = (int)$db->affected_rows();
        }

        @error_log('[AF-ADVINV][reset_test_data] ' . json_encode($deleted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $deleted;
    }

    private static function render_entities(): string
    {
        global $db, $mybb;

        if (function_exists('af_advinv_entities_upgrade_schema')) {
            af_advinv_entities_upgrade_schema();
        } else {
            self::ensure_entities_schema();
        }
        if (function_exists('af_advinv_entity_filters_upgrade_schema')) {
            af_advinv_entity_filters_upgrade_schema();
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $targetEntity = self::handle_entity_post();
            $redirectParams = [];
            if ($targetEntity !== '') {
                $redirectParams['edit_entity'] = $targetEntity;
            }
            admin_redirect(self::baseUrl('entities', $redirectParams));
        }

        $editEntity = trim((string)$mybb->get_input('edit_entity'));
        $editing = [];
        if ($editEntity !== '' && $db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            $editing = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ENTITIES, '*', "entity='" . $db->escape_string($editEntity) . "'", ['limit' => 1]));
        }

        $rowsHtml = '';
        foreach (self::load_entities_with_meta() as $slug => $row) {
            $rowsHtml .= '<tr>'
                . '<td>' . htmlspecialchars_uni($slug) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['title_ru'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['title_en'] ?? '')) . '</td>'
                . '<td>' . ((int)($row['enabled'] ?? 0) === 1 ? 'Да' : 'Нет') . '</td>'
                . '<td>' . (int)($row['sortorder'] ?? 0) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['renderer'] ?? 'generic')) . '</td>'
                . '<td>'
                . '<a href="' . htmlspecialchars_uni(self::baseUrl('entities', ['edit_entity' => $slug])) . '">Редактировать</a> · '
                . '<a href="' . htmlspecialchars_uni(self::baseUrl('entity_filters', ['entity' => $slug])) . '">Сабфильтры</a> · '
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

    private static function handle_entity_post(): string
    {
        global $db, $mybb;

        $action = trim((string)$mybb->get_input('entity_action'));
        $entity = trim((string)$mybb->get_input('entity'));

        if ($action === 'delete' && $entity !== '') {
            if ($db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
                $db->delete_query(AF_ADVINV_TABLE_ENTITIES, "entity='" . $db->escape_string($entity) . "'");
                if ($db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS)) {
                    $db->delete_query(AF_ADVINV_TABLE_ENTITY_FILTERS, "entity='" . $db->escape_string($entity) . "'");
                }
            }
            return '';
        }

        if ($action === 'move_up' || $action === 'move_down') {
            self::shift_entity_sortorder($entity, $action === 'move_up' ? -1 : 1);
            return $entity;
        }

        if ($action !== 'save' || !$db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            return '';
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
            return '';
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
            if ($db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS)) {
                $db->update_query(AF_ADVINV_TABLE_ENTITY_FILTERS, ['entity' => $db->escape_string($entity), 'updated_at' => TIME_NOW], "entity='" . $db->escape_string($originalEntity) . "'");
            }
        }

        $exists = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ENTITIES, 'COUNT(*) AS c', "entity='" . $db->escape_string($entity) . "'", ['limit' => 1]), 'c');
        if ($exists > 0) {
            $db->update_query(AF_ADVINV_TABLE_ENTITIES, $payload, "entity='" . $db->escape_string($entity) . "'");
        } else {
            $db->insert_query(AF_ADVINV_TABLE_ENTITIES, $payload);
        }

        return $entity;
    }

    private static function render_entity_filters(): string
    {
        global $db, $mybb;

        if (function_exists('af_advinv_entities_upgrade_schema')) {
            af_advinv_entities_upgrade_schema();
        }
        if (function_exists('af_advinv_entity_filters_upgrade_schema')) {
            af_advinv_entity_filters_upgrade_schema();
        }

        $entity = strtolower(trim((string)$mybb->get_input('entity')));
        if ($entity === '') {
            $entity = (string)array_key_first(self::load_entity_options());
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $action = trim((string)$mybb->get_input('entity_action'));
            if (in_array($action, ['save_filter', 'delete_filter', 'move_up_filter', 'move_down_filter'], true)) {
                $entity = self::handle_entity_filter_post($action);
            }
            admin_redirect(self::baseUrl('entity_filters', ['entity' => $entity]));
        }

        if ($entity === '') {
            return '<div class="af-box"><h2>Сабфильтры категорий</h2><p>Сначала создайте хотя бы одну категорию инвентаря.</p></div>';
        }

        $optionsHtml = '';
        foreach (self::load_entity_options() as $slug => $title) {
            $selected = $slug === $entity ? ' selected' : '';
            $optionsHtml .= '<option value="' . htmlspecialchars_uni($slug) . '"' . $selected . '>' . htmlspecialchars_uni($title) . ' (' . htmlspecialchars_uni($slug) . ')</option>';
        }

        $html = '<div class="af-box">';
        $html .= '<h2>Сабфильтры категорий</h2>';
        $html .= '<p><a class="button" href="' . htmlspecialchars_uni(self::baseUrl()) . '">← К списку инвентарей</a> <a class="button" href="' . htmlspecialchars_uni(self::baseUrl('entities')) . '">Категории</a></p>';
        $html .= '<form method="get"><input type="hidden" name="module" value="advancedfunctionality"><input type="hidden" name="af_view" value="advancedinventory"><input type="hidden" name="do" value="entity_filters">';
        $html .= '<label>Категория: <select name="entity">' . $optionsHtml . '</select></label> <button type="submit" class="button">Открыть</button>';
        $html .= '</form>';
        $html .= self::render_entity_subfilters($entity);
        $html .= '</div>';

        return $html;
    }

    private static function render_entity_subfilters(string $entity): string
    {
        global $db, $mybb;

        if (!$db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS)) {
            return '';
        }

        $editFilterId = (int)$mybb->get_input('edit_filter_id');
        $editing = [];
        if ($editFilterId > 0) {
            $editing = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ENTITY_FILTERS, '*', "id={$editFilterId} AND entity='" . $db->escape_string($entity) . "'", ['limit' => 1]));
        }

        $rowsHtml = '';
        $q = $db->simple_select(AF_ADVINV_TABLE_ENTITY_FILTERS, '*', "entity='" . $db->escape_string($entity) . "'", ['order_by' => 'sortorder,id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($q)) {
            $id = (int)$row['id'];
            $rowsHtml .= '<tr><td>' . $id . '</td><td>' . htmlspecialchars_uni((string)$row['code']) . '</td><td>' . htmlspecialchars_uni((string)$row['title_ru']) . '</td><td>' . htmlspecialchars_uni((string)$row['title_en']) . '</td><td>' . ((int)$row['enabled'] === 1 ? 'Да' : 'Нет') . '</td><td>' . (int)$row['sortorder'] . '</td><td>'
                . '<a href="' . htmlspecialchars_uni(self::baseUrl('entity_filters', ['entity' => $entity, 'edit_filter_id' => $id])) . '">Редактировать</a> · '
                . '<form method="post" style="display:inline;margin:0">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="entity_action" value="move_up_filter">'
                . '<input type="hidden" name="entity" value="' . htmlspecialchars_uni($entity) . '">'
                . '<input type="hidden" name="filter_id" value="' . $id . '">'
                . '<button type="submit" class="button button_small">↑</button>'
                . '</form> '
                . '<form method="post" style="display:inline;margin:0">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="entity_action" value="move_down_filter">'
                . '<input type="hidden" name="entity" value="' . htmlspecialchars_uni($entity) . '">'
                . '<input type="hidden" name="filter_id" value="' . $id . '">'
                . '<button type="submit" class="button button_small">↓</button>'
                . '</form> '
                . '<form method="post" style="display:inline;margin:0" onsubmit="return confirm(\'Удалить сабфильтр?\');">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="entity_action" value="delete_filter">'
                . '<input type="hidden" name="entity" value="' . htmlspecialchars_uni($entity) . '">'
                . '<input type="hidden" name="filter_id" value="' . $id . '">'
                . '<button type="submit" class="button button_small">Удалить</button>'
                . '</form>'
                . '</td></tr>';
        }

        $selectedId = (int)($editing['id'] ?? 0);
        $selectedCode = (string)($editing['code'] ?? '');
        $selectedTitleRu = (string)($editing['title_ru'] ?? '');
        $selectedTitleEn = (string)($editing['title_en'] ?? '');
        $selectedSort = (int)($editing['sortorder'] ?? self::next_filter_sortorder($entity));
        $selectedEnabled = (int)($editing['enabled'] ?? 1);
        $selectedMatchJson = (string)($editing['match_json'] ?? '');

        $html = '<hr><h3>Сабфильтры категории: ' . htmlspecialchars_uni($entity) . '</h3>';
        $html .= '<table class="table"><thead><tr><th>ID</th><th>Code</th><th>Title RU</th><th>Title EN</th><th>Enabled</th><th>Sort</th><th>Действия</th></tr></thead><tbody>' . ($rowsHtml !== '' ? $rowsHtml : '<tr><td colspan="7">Сабфильтров пока нет.</td></tr>') . '</tbody></table>';
        $html .= '<h4>' . ($selectedId > 0 ? 'Редактировать сабфильтр' : 'Добавить сабфильтр') . '</h4>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        $html .= '<input type="hidden" name="entity_action" value="save_filter">';
        $html .= '<input type="hidden" name="entity" value="' . htmlspecialchars_uni($entity) . '">';
        $html .= '<input type="hidden" name="filter_id" value="' . $selectedId . '">';
        $html .= '<p><label>Code</label><br><input type="text" maxlength="32" name="filter_code" value="' . htmlspecialchars_uni($selectedCode) . '" required></p>';
        $html .= '<p><label>Title RU</label><br><input type="text" maxlength="255" name="filter_title_ru" value="' . htmlspecialchars_uni($selectedTitleRu) . '"></p>';
        $html .= '<p><label>Title EN</label><br><input type="text" maxlength="255" name="filter_title_en" value="' . htmlspecialchars_uni($selectedTitleEn) . '"></p>';
        $html .= '<p><label>Sortorder</label><br><input type="number" name="filter_sortorder" value="' . $selectedSort . '"></p>';
        $html .= '<p><label>Match JSON (опционально)</label><br><textarea name="filter_match_json" rows="5" cols="70">' . htmlspecialchars_uni($selectedMatchJson) . '</textarea></p>';
        $html .= '<p><label><input type="checkbox" name="filter_enabled" value="1"' . ($selectedEnabled === 1 ? ' checked' : '') . '> Включено</label></p>';
        $html .= '<p><button type="submit" class="button">Сохранить сабфильтр</button></p>';
        $html .= '</form>';

        return $html;
    }

    private static function handle_entity_filter_post(string $action): string
    {
        global $db, $mybb;

        if (!$db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS)) {
            return '';
        }

        $entity = strtolower(trim((string)$mybb->get_input('entity')));
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $entity)) {
            return '';
        }

        $filterId = (int)$mybb->get_input('filter_id');

        if ($action === 'delete_filter' && $filterId > 0) {
            $db->delete_query(AF_ADVINV_TABLE_ENTITY_FILTERS, 'id=' . $filterId . " AND entity='" . $db->escape_string($entity) . "'");
            return $entity;
        }

        if (($action === 'move_up_filter' || $action === 'move_down_filter') && $filterId > 0) {
            self::shift_filter_sortorder($entity, $filterId, $action === 'move_up_filter' ? -1 : 1);
            return $entity;
        }

        if ($action !== 'save_filter') {
            return $entity;
        }

        $code = strtolower(trim((string)$mybb->get_input('filter_code')));
        $titleRu = trim((string)$mybb->get_input('filter_title_ru'));
        $titleEn = trim((string)$mybb->get_input('filter_title_en'));
        $sortorder = (int)$mybb->get_input('filter_sortorder');
        $enabled = (int)$mybb->get_input('filter_enabled') === 1 ? 1 : 0;
        $matchJson = trim((string)$mybb->get_input('filter_match_json'));

        if (!preg_match('/^[a-z0-9_]{1,32}$/', $code) || $code === 'all') {
            return $entity;
        }
        if ($matchJson !== '') {
            $decoded = @json_decode($matchJson, true);
            if (!is_array($decoded)) {
                $matchJson = '';
            }
        }

        $payload = [
            'entity' => $db->escape_string($entity),
            'code' => $db->escape_string($code),
            'title_ru' => $db->escape_string(substr($titleRu, 0, 255)),
            'title_en' => $db->escape_string(substr($titleEn, 0, 255)),
            'sortorder' => $sortorder,
            'enabled' => $enabled,
            'match_json' => $db->escape_string($matchJson),
            'updated_at' => TIME_NOW,
        ];

        if ($filterId > 0) {
            $db->update_query(AF_ADVINV_TABLE_ENTITY_FILTERS, $payload, 'id=' . $filterId . " AND entity='" . $db->escape_string($entity) . "'");
            return $entity;
        }

        $exists = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ENTITY_FILTERS, 'COUNT(*) AS c', "entity='" . $db->escape_string($entity) . "' AND code='" . $db->escape_string($code) . "'", ['limit' => 1]), 'c');
        if ($exists > 0) {
            $db->update_query(AF_ADVINV_TABLE_ENTITY_FILTERS, $payload, "entity='" . $db->escape_string($entity) . "' AND code='" . $db->escape_string($code) . "'");
        } else {
            $db->insert_query(AF_ADVINV_TABLE_ENTITY_FILTERS, $payload);
        }

        return $entity;
    }

    private static function shift_filter_sortorder(string $entity, int $filterId, int $direction): void
    {
        global $db;

        $entityEsc = $db->escape_string($entity);
        $current = (array)$db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ENTITY_FILTERS, 'id,sortorder', 'id=' . $filterId . " AND entity='" . $entityEsc . "'", ['limit' => 1]));
        if (!$current) {
            return;
        }

        $sort = (int)$current['sortorder'];
        $neighborWhere = $direction < 0 ? 'sortorder < ' . $sort : 'sortorder > ' . $sort;
        $neighborOrder = $direction < 0 ? 'sortorder DESC, id DESC' : 'sortorder ASC, id ASC';
        $neighbor = (array)$db->fetch_array($db->query("SELECT id, sortorder
            FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS . "
            WHERE entity='" . $entityEsc . "' AND {$neighborWhere}
            ORDER BY {$neighborOrder}
            LIMIT 1"));
        if (!$neighbor) {
            return;
        }

        $db->update_query(AF_ADVINV_TABLE_ENTITY_FILTERS, ['sortorder' => (int)$neighbor['sortorder'], 'updated_at' => TIME_NOW], 'id=' . $filterId . " AND entity='" . $entityEsc . "'");
        $db->update_query(AF_ADVINV_TABLE_ENTITY_FILTERS, ['sortorder' => $sort, 'updated_at' => TIME_NOW], 'id=' . (int)$neighbor['id'] . " AND entity='" . $entityEsc . "'");
    }

    private static function render_shop_map(): string
    {
        global $db, $mybb;

        if (function_exists('af_advinv_shop_map_upgrade_schema')) {
            af_advinv_shop_map_upgrade_schema();
        } else {
            self::ensure_shop_map_schema();
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
        $catsByShopCode = [];
        if ($db->table_exists('af_shop_categories')) {
            $catCols = self::table_columns('af_shop_categories');
            if (isset($catCols['shop_id']) && (isset($catCols['cat_id']) || isset($catCols['id']))) {
                $catIdCol = isset($catCols['cat_id']) ? 'cat_id' : 'id';
                $titleCol = isset($catCols['title_ru']) ? 'title_ru' : (isset($catCols['title']) ? 'title' : (isset($catCols['title_en']) ? 'title_en' : ''));

                $qShops = $db->query("SELECT DISTINCT shop_id FROM " . TABLE_PREFIX . "af_shop_categories ORDER BY shop_id ASC");
                while ($shop = $db->fetch_array($qShops)) {
                    $shopId = trim((string)($shop['shop_id'] ?? ''));
                    if ($shopId === '') {
                        continue;
                    }
                    $shops[$shopId] = ['code' => $shopId, 'title' => 'Shop #' . $shopId];
                }

                $titleExpr = $titleCol !== '' ? "COALESCE(NULLIF(c.{$titleCol}, ''), CONCAT('#', c.{$catIdCol}))" : "CONCAT('#', c.{$catIdCol})";
                $qCats = $db->query("SELECT c.{$catIdCol} AS cat_id, c.shop_id, {$titleExpr} AS title
                    FROM " . TABLE_PREFIX . "af_shop_categories c
                    ORDER BY c.shop_id ASC, c.{$catIdCol} ASC");
                while ($c = $db->fetch_array($qCats)) {
                    $shopCode = trim((string)($c['shop_id'] ?? ''));
                    if ($shopCode === '') {
                        continue;
                    }
                    $catsByShopCode[$shopCode][] = [
                        'cat_id' => (int)$c['cat_id'],
                        'title' => trim((string)($c['title'] ?? ('#' . (int)$c['cat_id']))),
                    ];
                }
            }
        }

        if (!$shops && $db->table_exists('af_shop_slots')) {
            $slotCols = self::table_columns('af_shop_slots');
            if (isset($slotCols['shop_id'])) {
                $qShops = $db->query("SELECT DISTINCT shop_id FROM " . TABLE_PREFIX . "af_shop_slots ORDER BY shop_id ASC");
                while ($shop = $db->fetch_array($qShops)) {
                    $shopId = trim((string)($shop['shop_id'] ?? ''));
                    if ($shopId === '') {
                        continue;
                    }
                    $shops[$shopId] = ['code' => $shopId, 'title' => 'Shop #' . $shopId];
                }
            }
        }

        $ruleRows = '';
        $qRules = $db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, '*', '', ['order_by' => 'sortorder,id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($qRules)) {
            $id = (int)$row['id'];
            $shopCode = trim((string)($row['shop_code'] ?? ''));
            $catId = (int)($row['shop_cat_id'] ?? 0);
            $shopTitle = (string)($shops[$shopCode]['title'] ?? $shopCode);

            $catTitle = 'Все категории';
            if ($catId > 0) {
                foreach (($catsByShopCode[$shopCode] ?? []) as $catRow) {
                    if ((int)$catRow['cat_id'] === $catId) {
                        $catTitle = (string)$catRow['title'];
                        break;
                    }
                }
            }

            $ruleRows .= '<tr>'
                . '<td>' . $id . '</td>'
                . '<td>' . htmlspecialchars_uni($shopTitle) . ' <small>(' . htmlspecialchars_uni($shopCode) . ')</small></td>'
                . '<td>' . htmlspecialchars_uni($catTitle) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)$row['inventory_entity']) . '</td>'
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

        $selectedShopCode = trim((string)($editing['shop_code'] ?? (string)$mybb->get_input('shop_code')));
        $selectedCat = max(0, (int)($editing['shop_cat_id'] ?? 0));
        $selectedEntity = (string)($editing['inventory_entity'] ?? 'resources');
        $selectedSubtype = (string)($editing['default_subtype'] ?? '');
        $selectedEnabled = (int)($editing['enabled'] ?? 1);
        $selectedSort = (int)($editing['sortorder'] ?? self::next_sortorder());

        $shopOptions = '<option value="">Выберите магазин</option>';
        foreach ($shops as $shopCode => $shopRow) {
            $label = trim((string)$shopRow['title']);
            if ($label === '') { $label = $shopCode; }
            $shopOptions .= '<option value="' . htmlspecialchars_uni($shopCode) . '"' . ($shopCode === $selectedShopCode ? ' selected' : '') . '>' . htmlspecialchars_uni($label . ' (' . $shopCode . ')') . '</option>';
        }

        $catOptions = '<option value="0">Все категории магазина</option>';
        foreach (($catsByShopCode[$selectedShopCode] ?? []) as $catRow) {
            $catId = (int)$catRow['cat_id'];
            $catOptions .= '<option value="' . $catId . '"' . ($catId === $selectedCat ? ' selected' : '') . '>' . htmlspecialchars_uni((string)$catRow['title']) . '</option>';
        }

        $entityOptions = '';
        foreach (array_keys(self::load_entity_options()) as $entity) {
            $entityOptions .= '<option value="' . htmlspecialchars_uni($entity) . '"' . ($selectedEntity === $entity ? ' selected' : '') . '>' . htmlspecialchars_uni($entity) . '</option>';
        }

        $catsJson = htmlspecialchars_uni(json_encode($catsByShopCode, JSON_UNESCAPED_UNICODE));

        $html = '<div class="af-box">';
        $html .= '<h2>Мост Shop → Inventory</h2>';
        $html .= '<p><a class="button" href="' . htmlspecialchars_uni(self::baseUrl()) . '">← К списку инвентарей</a></p>';
        $html .= '<table class="table"><thead><tr><th>ID</th><th>Магазин</th><th>Категория</th><th>Entity</th><th>Default subtype</th><th>Enabled</th><th>Sort</th><th>Действия</th></tr></thead><tbody>' . ($ruleRows !== '' ? $ruleRows : '<tr><td colspan="8">Правил пока нет.</td></tr>') . '</tbody></table>';

        $html .= '<h3>' . ($editId > 0 ? 'Редактировать правило' : 'Добавить правило') . '</h3>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        $html .= '<input type="hidden" name="map_action" value="save">';
        $html .= '<input type="hidden" name="id" value="' . (int)$editId . '">';
        $html .= '<p><label>Магазин</label><br><select name="shop_code" id="shop_map_shop_code" required>' . $shopOptions . '</select></p>';
        $html .= '<p><label>Категория</label><br><select name="shop_cat_id" id="shop_map_shop_cat_id">' . $catOptions . '</select></p>';
        $html .= '<p><label>Entity</label><br><select name="inventory_entity">' . $entityOptions . '</select></p>';
        $html .= '<p><label>Default subtype (опционально)</label><br><input type="text" name="default_subtype" value="' . htmlspecialchars_uni($selectedSubtype) . '"><br><small>Если subtype не определится по KB rules, использовать это значение (weapon/armor/loot…).</small></p>';
        $html .= '<p><label>Sortorder</label><br><input type="number" min="0" name="sortorder" value="' . $selectedSort . '"></p>';
        $html .= '<p><label><input type="checkbox" name="enabled" value="1"' . ($selectedEnabled === 1 ? ' checked' : '') . '> Включено</label></p>';
        $html .= '<p><button type="submit" class="button">Сохранить правило</button></p>';
        $html .= '</form>';
        $html .= '<script>(function(){var cats=' . $catsJson . ';var shop=document.getElementById("shop_map_shop_code");var cat=document.getElementById("shop_map_shop_cat_id");if(!shop||!cat){return;}var selected=' . (int)$selectedCat . ';function render(){var code=shop.value||"";var rows=cats[code]||[];cat.innerHTML="<option value=\"0\">Все категории магазина</option>";for(var i=0;i<rows.length;i++){var o=document.createElement("option");o.value=String(rows[i].cat_id||0);o.textContent=rows[i].title||("#"+o.value);if(parseInt(o.value,10)===selected){o.selected=true;}cat.appendChild(o);}selected=0;}shop.addEventListener("change",render);render();})();</script>';
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

        $shopCode = trim((string)$mybb->get_input('shop_code'));
        $shopCatId = max(0, (int)$mybb->get_input('shop_cat_id'));
        $entity = trim((string)$mybb->get_input('inventory_entity'));
        $defaultSubtype = trim((string)$mybb->get_input('default_subtype'));
        $enabled = (int)$mybb->get_input('enabled') === 1 ? 1 : 0;
        $sortorder = max(0, (int)$mybb->get_input('sortorder'));

        if ($shopCode === '' || !self::entity_exists($entity)) {
            return;
        }

        $payload = [
            'shop_code' => $db->escape_string($shopCode),
            'shop_cat_id' => $shopCatId,
            'inventory_entity' => $db->escape_string($entity),
            'default_subtype' => $defaultSubtype === '' ? null : $db->escape_string($defaultSubtype),
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

    private static function next_filter_sortorder(string $entity): int
    {
        global $db;
        if (!$db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS) || $entity === '') {
            return 10;
        }
        $max = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ENTITY_FILTERS, 'MAX(sortorder) AS m', "entity='" . $db->escape_string($entity) . "'"), 'm');
        return $max + 10;
    }
    private static function table_columns(string $table): array
    {
        global $db;
        if (!$db->table_exists($table)) {
            return [];
        }
        $cols = [];
        $q = $db->write_query("SHOW COLUMNS FROM " . TABLE_PREFIX . $table);
        while ($row = $db->fetch_array($q)) {
            $name = trim((string)($row['Field'] ?? ''));
            if ($name !== '') {
                $cols[$name] = true;
            }
        }
        return $cols;
    }

    private static function load_entity_options(): array
    {
        $options = [];
        foreach (self::load_entities_with_meta() as $entity => $row) {
            $titleRu = trim((string)($row['title_ru'] ?? ''));
            $titleEn = trim((string)($row['title_en'] ?? ''));
            $options[$entity] = $titleRu !== '' ? $titleRu : ($titleEn !== '' ? $titleEn : $entity);
        }
        return $options;
    }

    private static function load_entities_with_meta(): array
    {
        global $db;

        $entities = [];
        if ($db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            $q = $db->simple_select(AF_ADVINV_TABLE_ENTITIES, '*', '', ['order_by' => 'sortorder,entity', 'order_dir' => 'ASC']);
            while ($row = $db->fetch_array($q)) {
                $entity = trim((string)($row['entity'] ?? ''));
                if ($entity !== '') {
                    $entities[$entity] = $row;
                }
            }
        }
        return $entities;
    }

    private static function entity_exists(string $entity): bool
    {
        $entity = trim($entity);
        if ($entity === '') {
            return false;
        }
        $entities = self::load_entity_options();
        return isset($entities[$entity]);
    }

    private static function ensure_shop_map_schema(): void
    {
        global $db;

        if (!$db->table_exists(AF_ADVINV_TABLE_SHOP_MAP)) {
            $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                shop_code VARCHAR(32) NOT NULL,
                shop_cat_id INT UNSIGNED NOT NULL DEFAULT 0,
                inventory_entity VARCHAR(32) NOT NULL,
                default_subtype VARCHAR(32) NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                sortorder INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at INT UNSIGNED NOT NULL DEFAULT 0,
                KEY shop_cat_enabled (shop_code, shop_cat_id, enabled, sortorder),
                KEY enabled_sort (enabled, sortorder)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }

        $columns = self::table_columns(AF_ADVINV_TABLE_SHOP_MAP);
        $columnSql = [
            'shop_code' => "ADD COLUMN shop_code VARCHAR(32) NOT NULL DEFAULT ''",
            'shop_cat_id' => "ADD COLUMN shop_cat_id INT UNSIGNED NOT NULL DEFAULT 0",
            'inventory_entity' => "ADD COLUMN inventory_entity VARCHAR(32) NOT NULL DEFAULT 'resources'",
            'default_subtype' => "ADD COLUMN default_subtype VARCHAR(32) NULL",
            'enabled' => "ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1",
            'sortorder' => "ADD COLUMN sortorder INT UNSIGNED NOT NULL DEFAULT 0",
            'updated_at' => "ADD COLUMN updated_at INT UNSIGNED NOT NULL DEFAULT 0",
        ];
        foreach ($columnSql as $name => $sql) {
            if (!isset($columns[$name])) {
                $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " {$sql}");
            }
        }

        $indexes = [];
        $idxQ = $db->write_query("SHOW INDEX FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP);
        while ($idx = $db->fetch_array($idxQ)) {
            $indexes[(string)($idx['Key_name'] ?? '')] = true;
        }
        if (!isset($indexes['shop_cat_enabled'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " ADD KEY shop_cat_enabled (shop_code, shop_cat_id, enabled, sortorder)");
        }
        if (!isset($indexes['enabled_sort'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " ADD KEY enabled_sort (enabled, sortorder)");
        }
    }

    private static function ensure_entities_schema(): void
    {
        global $db;

        if (!$db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . " (
                entity VARCHAR(32) NOT NULL,
                title_ru VARCHAR(255) NOT NULL DEFAULT '',
                title_en VARCHAR(255) NOT NULL DEFAULT '',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                sortorder INT NOT NULL DEFAULT 0,
                renderer VARCHAR(32) NOT NULL DEFAULT 'generic',
                settings_json MEDIUMTEXT NULL,
                updated_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (entity),
                KEY enabled_sort (enabled, sortorder)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }

        $columns = self::table_columns(AF_ADVINV_TABLE_ENTITIES);
        $columnSql = [
            'entity' => "ADD COLUMN entity VARCHAR(32) NOT NULL",
            'title_ru' => "ADD COLUMN title_ru VARCHAR(255) NOT NULL DEFAULT ''",
            'title_en' => "ADD COLUMN title_en VARCHAR(255) NOT NULL DEFAULT ''",
            'enabled' => "ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1",
            'sortorder' => "ADD COLUMN sortorder INT NOT NULL DEFAULT 0",
            'renderer' => "ADD COLUMN renderer VARCHAR(32) NOT NULL DEFAULT 'generic'",
            'settings_json' => "ADD COLUMN settings_json MEDIUMTEXT NULL",
            'updated_at' => "ADD COLUMN updated_at INT UNSIGNED NOT NULL DEFAULT 0",
        ];
        foreach ($columnSql as $name => $sql) {
            if (!isset($columns[$name])) {
                $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . " {$sql}");
            }
        }

        $indexes = [];
        $idxQ = $db->write_query("SHOW INDEX FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES);
        while ($idx = $db->fetch_array($idxQ)) {
            $indexes[(string)($idx['Key_name'] ?? '')] = true;
        }
        if (!isset($indexes['enabled_sort'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . " ADD KEY enabled_sort (enabled, sortorder)");
        }
    }

}
