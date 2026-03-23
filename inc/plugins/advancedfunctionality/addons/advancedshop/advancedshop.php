<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ADVSHOP_ID', 'advancedshop');
define('AF_ADVSHOP_BASE', AF_ADDONS . AF_ADVSHOP_ID . '/');
define('AF_ADVSHOP_TPL_DIR', AF_ADVSHOP_BASE . 'templates/');
define('AF_ADVSHOP_ASSETS_DIR', AF_ADVSHOP_BASE . 'assets/');
define('AF_ADVSHOP_ALIAS_MARKER', "define('AF_ADVANCEDSHOP_PAGE_ALIAS', 1);");
define('AF_ADVSHOP_MANAGE_ALIAS_MARKER', "define('AF_ADVANCEDSHOP_MANAGE_PAGE_ALIAS', 1);");
define('AF_ADVSHOP_ASSETS_BLACKLIST_DEFAULT', "index.php\nforumdisplay.php\npostsactivity.php\nusercp.php\nuserlist.php\nsearch.php\ngallery.php");
define('AF_ADVSHOP_ASSETS_BLACKLIST_DESC', 'По одной строке. Форматы: `script.php` или `script.php?action=xxx`.');
define('AF_KB_TABLE_ENTRIES', 'af_kb_entries');
define('AF_KB_TABLE_ENTRIES_LEGACY', 'kb_entries');
if (!defined('AF_ADVSHOP_DEBUG_LOG') && defined('AF_CACHE')) {
    define('AF_ADVSHOP_DEBUG_LOG', AF_CACHE . 'advancedshop_debug.log');
}

function af_advancedshop_kb_table_entries(): string
{
    global $db;

    static $picked = null;
    if (is_string($picked)) {
        return $picked;
    }

    $picked = '';
    if (is_object($db)) {
        $candidates = [AF_KB_TABLE_ENTRIES, AF_KB_TABLE_ENTRIES_LEGACY];
        foreach ($candidates as $candidate) {
            if ($db->table_exists($candidate)) {
                $picked = $candidate;
                break;
            }
        }
    }

    af_advancedshop_inv_debug('kb_table_resolve', ['picked' => $picked]);
    return $picked;
}

function af_advancedshop_kb_table(): string
{
    $table = af_advancedshop_kb_table_entries();
    return $table === '' ? '' : TABLE_PREFIX . $table;
}

function af_advancedshop_kb_schema_meta(): array
{
    global $db;

    static $meta = null;
    if (is_array($meta)) {
        return $meta;
    }

    $kbTableSql = af_advancedshop_kb_table();
    $columns = [];
    $kbTable = af_advancedshop_kb_table_entries();
    if ($kbTable !== '' && $db->table_exists($kbTable)) {
        $resCols = $db->query("SHOW COLUMNS FROM {$kbTableSql}");
        while ($row = $db->fetch_array($resCols)) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
    }

    $meta = [
        'kb_table_sql' => $kbTableSql,
        'columns' => $columns,
        'exists' => $kbTable !== '' && $db->table_exists($kbTable),
    ];
    return $meta;
}

function af_advancedshop_appearance_presets_table(): string
{
    global $db;

    if ($db->table_exists('af_aa_presets')) {
        return TABLE_PREFIX . 'af_aa_presets';
    }

    return '';
}

function af_advancedshop_appearance_supported_targets(): array
{
    static $targets = null;

    if (is_array($targets)) {
        return $targets;
    }

    if (function_exists('af_aa_get_supported_targets_registry')) {
        $targets = [];

        foreach (af_aa_get_supported_targets_registry() as $targetKey => $meta) {
            if (empty($meta['purchasable'])) {
                continue;
            }

            $targets[$targetKey] = [
                'group' => (string)($meta['group'] ?? 'unsupported'),
                'label' => (string)($meta['label'] ?? $targetKey),
            ];
        }

        return $targets;
    }

    $targets = [
        'apui_theme_pack' => ['group' => 'theme_pack', 'label' => 'Общие пак-темы'],
        'apui_profile_pack' => ['group' => 'profile_pack', 'label' => 'Профили'],
        'apui_postbit_pack' => ['group' => 'postbit_pack', 'label' => 'Постбиты'],
        'apui_thread_pack' => ['group' => 'thread_pack', 'label' => 'Страница темы'],
        'apui_application_pack' => ['group' => 'application_pack', 'label' => 'Анкеты'],
        'apui_sheet_pack' => ['group' => 'sheet_pack', 'label' => 'Листы персонажа'],
        'apui_inventory_pack' => ['group' => 'inventory_pack', 'label' => 'Инвентарь'],
        'apui_achievements_pack' => ['group' => 'achievements_pack', 'label' => 'Ачивки'],
        'apui_fragment_pack' => ['group' => 'fragment_pack', 'label' => 'Разное'],
        'apui_fragment_pack:profile_body' => ['group' => 'fragment_pack', 'label' => 'Разное · Профиль: фон body'],
        'apui_fragment_pack:profile_banner' => ['group' => 'fragment_pack', 'label' => 'Разное · Профиль: баннер'],
        'apui_fragment_pack:profile_avatar_frame' => ['group' => 'fragment_pack', 'label' => 'Разное · Профиль: рамка аватара'],
        'apui_fragment_pack:postbit_author' => ['group' => 'fragment_pack', 'label' => 'Разное · Постбит: фон автора'],
        'apui_fragment_pack:postbit_name' => ['group' => 'fragment_pack', 'label' => 'Разное · Постбит: блок никнейма'],
        'apui_fragment_pack:postbit_plaque' => ['group' => 'fragment_pack', 'label' => 'Разное · Постбит: плашка'],
        'apui_fragment_pack:postbit_plaque_icon' => ['group' => 'fragment_pack', 'label' => 'Разное · Постбит: иконка плашки'],
        'apui_fragment_pack:postbit_avatar_frame' => ['group' => 'fragment_pack', 'label' => 'Разное · Постбит: рамка аватара'],
        'apui_fragment_pack:thread_body' => ['group' => 'fragment_pack', 'label' => 'Разное · Тема: фон страницы'],
        'apui_fragment_pack:thread_banner' => ['group' => 'fragment_pack', 'label' => 'Разное · Тема: баннер темы'],
    ];

    return $targets;
}

function af_advancedshop_appearance_supported_target_keys(): array
{
    return array_keys(af_advancedshop_appearance_supported_targets());
}

function af_advancedshop_appearance_group_for_target(string $targetKey): string
{
    $targetKey = function_exists('af_aa_normalize_target_key')
        ? af_aa_normalize_target_key($targetKey)
        : mb_strtolower(trim($targetKey));
    $targets = af_advancedshop_appearance_supported_targets();
    return (string)($targets[$targetKey]['group'] ?? 'unsupported');
}

function af_advancedshop_appearance_target_label(string $targetKey): string
{
    $targetKey = function_exists('af_aa_normalize_target_key')
        ? af_aa_normalize_target_key($targetKey)
        : mb_strtolower(trim($targetKey));
    $targets = af_advancedshop_appearance_supported_targets();

    if (isset($targets[$targetKey]['label'])) {
        return (string)$targets[$targetKey]['label'];
    }

    return $targetKey === '' ? 'Unknown target' : $targetKey;
}

function af_advancedshop_appearance_supported_group_labels(): array
{
    if (function_exists('af_aa_get_target_group_labels')) {
        return af_aa_get_target_group_labels();
    }

    return [
        'all' => 'Все группы',
        'theme_pack' => 'Общие пак-темы',
        'profile_pack' => 'Профили',
        'postbit_pack' => 'Постбиты',
        'thread_pack' => 'Страница темы',
        'application_pack' => 'Анкеты',
        'sheet_pack' => 'Листы персонажа',
        'inventory_pack' => 'Инвентарь',
        'achievements_pack' => 'Ачивки',
        'fragment_pack' => 'Разное',
    ];
}

function af_advancedshop_appearance_validate_target(string $targetKey): string
{
    $targetKey = function_exists('af_aa_normalize_target_key')
        ? af_aa_normalize_target_key($targetKey)
        : mb_strtolower(trim($targetKey));
    if ($targetKey === '') {
        throw new RuntimeException('Appearance preset has empty target_key.');
    }

    if (
        function_exists('af_aa_is_supported_target')
        && !af_aa_is_supported_target($targetKey, ['purchasable' => true])
    ) {
        $supported = implode(', ', af_aa_get_supported_target_keys(['purchasable' => true]));
        throw new RuntimeException('Appearance target not supported: ' . $targetKey . '. Supported targets: ' . $supported . '.');
    }

    if (!function_exists('af_aa_is_supported_target') && !in_array($targetKey, af_advancedshop_appearance_supported_target_keys(), true)) {
        $supported = implode(', ', af_advancedshop_appearance_supported_target_keys());
        throw new RuntimeException('Appearance target not supported: ' . $targetKey . '. Supported targets: ' . $supported . '.');
    }

    return $targetKey;
}

function af_advancedshop_appearance_active_target(): string
{
    return 'apui_fragment_pack:profile_banner';
}

function af_advancedshop_appearance_fetch_preset(int $presetId): array
{
    global $db;

    $presetId = (int)$presetId;
    if ($presetId <= 0) {
        return [];
    }

    $table = af_advancedshop_appearance_presets_table();
    if ($table === '') {
        return [];
    }

    $query = $db->query("SELECT * FROM " . $table . " WHERE id=" . $presetId . " LIMIT 1");
    $row = (array)$db->fetch_array($query);
    if (!$row) {
        return [];
    }

    return $row;
}

function af_advancedshop_appearance_fetch_preset_by_slug(string $slug): array
{
    global $db;

    $slug = trim($slug);
    if ($slug === '') {
        return [];
    }

    $table = af_advancedshop_appearance_presets_table();
    if ($table === '') {
        return [];
    }

    $query = $db->query(
        "SELECT * FROM " . $table . " WHERE slug='" . $db->escape_string($slug) . "' ORDER BY enabled DESC, sortorder ASC, id ASC LIMIT 1"
    );
    $row = (array)$db->fetch_array($query);
    if (!$row) {
        return [];
    }

    return $row;
}

function af_advancedshop_appearance_resolve_preset(int $sourceRefId = 0, int $presetId = 0, string $presetSlug = ''): array
{
    $resolved = [];
    if ($sourceRefId > 0) {
        $resolved = af_advancedshop_appearance_fetch_preset($sourceRefId);
    }
    if (!$resolved && $presetId > 0) {
        $resolved = af_advancedshop_appearance_fetch_preset($presetId);
    }
    if (!$resolved && trim($presetSlug) !== '') {
        $resolved = af_advancedshop_appearance_fetch_preset_by_slug($presetSlug);
    }

    if (!$resolved) {
        throw new RuntimeException('Appearance preset not found. Проверьте preset ID или slug.');
    }

    $targetKey = af_advancedshop_appearance_validate_target((string)($resolved['target_key'] ?? ''));
    $resolved['target_key'] = $targetKey;
    $resolved['appearance_group'] = af_advancedshop_appearance_group_for_target($targetKey);
    $resolved['target_label'] = af_advancedshop_appearance_target_label($targetKey);

    if ((int)($resolved['enabled'] ?? 0) !== 1) {
        throw new RuntimeException('Appearance preset is disabled and cannot be sold.');
    }

    return $resolved;
}

function af_advancedshop_slot_payload_from_source(string $sourceType, array $input, array $fallbackSlot = []): array
{
    global $db;

    $sourceType = af_advancedshop_normalize_source_type($sourceType);
    if ($sourceType === 'appearance') {
        $preset = af_advancedshop_appearance_resolve_preset(
            (int)($input['source_ref_id'] ?? 0),
            (int)($input['preset_id'] ?? 0),
            (string)($input['preset_slug'] ?? '')
        );

        $presetId = (int)$preset['id'];
        return [
            'source_type' => 'appearance',
            'source_ref_id' => $presetId,
            'kb_id' => 0,
            'kb_type' => 'appearance',
            'kb_key' => 'appearance:' . $presetId,
            'appearance_preset' => $preset,
        ];
    }

    $kbId = (int)($input['kb_id'] ?? ($fallbackSlot['kb_id'] ?? 0));
    if ($kbId <= 0) {
        $sourceRefId = (int)($input['source_ref_id'] ?? 0);
        if ($sourceRefId > 0) {
            $kbId = $sourceRefId;
        }
    }
    if ($kbId <= 0) {
        throw new RuntimeException('kb_id required for KB source');
    }

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $kbSelect = [$kbIdCol . ' AS kb_id'];
    if (!empty($kbCols['type'])) { $kbSelect[] = ($kbCols['type'] === 'type' ? '`type`' : $kbCols['type']) . ' AS kb_type'; }
    if (!empty($kbCols['key'])) { $kbSelect[] = ($kbCols['key'] === 'key' ? '`key`' : $kbCols['key']) . ' AS kb_key'; }
    if (!empty($kbCols['meta_json'])) { $kbSelect[] = $kbCols['meta_json'] . ' AS kb_meta'; }
    $kbRow = $db->fetch_array($db->query("SELECT " . implode(', ', $kbSelect) . " FROM " . af_advancedshop_kb_table() . " WHERE " . $kbIdCol . "=" . $kbId . " LIMIT 1"));
    if (!$kbRow) {
        throw new RuntimeException('KB entry not found');
    }

    $kbType = af_advancedshop_normalize_kb_type((string)($kbRow['kb_type'] ?? ($input['kb_type'] ?? ($fallbackSlot['kb_type'] ?? 'item'))));
    if ($kbType === '') {
        $kbType = 'item';
    }
    $kbKey = trim((string)($kbRow['kb_key'] ?? ($input['kb_key'] ?? ($fallbackSlot['kb_key'] ?? ''))));

    return [
        'source_type' => 'kb',
        'source_ref_id' => $kbId,
        'kb_id' => $kbId,
        'kb_type' => $kbType,
        'kb_key' => $kbKey,
        'appearance_preset' => [],
    ];
}

function af_advancedshop_kb_cols(): array
{
    return [
        'id' => 'id',
        'type' => '`type`',
        'key' => '`key`',
        'title_ru' => 'title_ru',
        'title_en' => 'title_en',
        'title' => '',
        'short_ru' => 'short_ru',
        'short_en' => 'short_en',
        'short' => '',
        'tech_ru' => 'tech_ru',
        'tech_en' => 'tech_en',
        'tech' => '',
        'body_ru' => 'body_ru',
        'body_en' => 'body_en',
        'body' => '',
        'meta_json' => 'meta_json',
        'data_json' => '',
        'active' => 'active',
        'sortorder' => 'sortorder',
    ];
}


function af_advancedshop_normalize_source_type(string $sourceType): string
{
    $sourceType = mb_strtolower(trim($sourceType));
    return $sourceType === 'appearance' ? 'appearance' : 'kb';
}

function af_advancedshop_source_type_from_slot(array $slot): string
{
    $sourceType = af_advancedshop_normalize_source_type((string)($slot['source_type'] ?? ''));
    if ($sourceType !== '') {
        return $sourceType;
    }

    return 'kb';
}

function af_advancedshop_source_ref_id_from_slot(array $slot): int
{
    $sourceRefId = (int)($slot['source_ref_id'] ?? 0);
    if ($sourceRefId > 0) {
        return $sourceRefId;
    }

    return (int)($slot['kb_id'] ?? 0);
}

function af_advancedshop_init(): void
{
    global $plugins;
    af_advancedshop_ensure_slots_schema();
    af_advancedshop_ensure_appearance_active_schema();
    $plugins->add_hook('global_start', 'af_advancedshop_register_routes', 10);
    $plugins->add_hook('misc_start', 'af_advancedshop_misc_router', 10);
    $plugins->add_hook('pre_output_page', 'af_advancedshop_pre_output', 10);
}

function af_advancedshop_ensure_slots_schema(): void
{
    global $db;

    if (!$db->table_exists('af_shop_slots')) {
        return;
    }

    if (!$db->field_exists('kb_type', 'af_shop_slots')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_slots ADD COLUMN kb_type VARCHAR(32) NOT NULL DEFAULT 'item' AFTER cat_id");
    }
    if (!$db->field_exists('kb_key', 'af_shop_slots')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_slots ADD COLUMN kb_key VARCHAR(128) NOT NULL DEFAULT '' AFTER kb_id");
    }
    if (!$db->field_exists('source_type', 'af_shop_slots')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_slots ADD COLUMN source_type VARCHAR(32) NOT NULL DEFAULT 'kb' AFTER cat_id");
    }
    if (!$db->field_exists('source_ref_id', 'af_shop_slots')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_slots ADD COLUMN source_ref_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER source_type");
    }

    $db->write_query("UPDATE " . TABLE_PREFIX . "af_shop_slots SET source_type='kb' WHERE source_type='' OR source_type IS NULL");
    $db->write_query("UPDATE " . TABLE_PREFIX . "af_shop_slots SET source_ref_id=kb_id WHERE source_ref_id=0 AND kb_id>0");

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? '';
    $kbTypeCol = $kbCols['type'] ?? '';
    $kbKeyCol = $kbCols['key'] ?? '';
    if ($kbIdCol === '' || $kbTypeCol === '' || $kbKeyCol === '') {
        return;
    }

    $safeTypeCol = $kbTypeCol === 'type' ? '`type`' : $kbTypeCol;
    $safeKeyCol = $kbKeyCol === 'key' ? '`key`' : $kbKeyCol;
    $db->write_query(
        "UPDATE " . TABLE_PREFIX . "af_shop_slots s
        INNER JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
        SET s.kb_type=COALESCE(NULLIF(e." . $safeTypeCol . ", ''), s.kb_type),
            s.kb_key=COALESCE(NULLIF(e." . $safeKeyCol . ", ''), s.kb_key)
        WHERE s.kb_key='' OR s.kb_type=''
        "
    );
}

function af_advancedshop_ensure_appearance_active_schema(): void
{
    global $db;

    if (!$db->table_exists('af_aa_active')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_aa_active (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT UNSIGNED NOT NULL,
            target_key VARCHAR(100) NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            applied_at INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_entity_target (entity_type, entity_id, target_key),
            KEY idx_item (item_id),
            KEY idx_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }

    $idxRes = $db->query("SHOW INDEX FROM " . TABLE_PREFIX . "af_aa_active WHERE Key_name='uniq_entity_target'");
    $hasUnique = false;
    while ($idx = $db->fetch_array($idxRes)) {
        if (!empty($idx['Key_name'])) {
            $hasUnique = true;
            break;
        }
    }

    if (!$hasUnique) {
        $dupRes = $db->query("SELECT entity_type, entity_id, target_key, MAX(id) AS keep_id
            FROM " . TABLE_PREFIX . "af_aa_active
            GROUP BY entity_type, entity_id, target_key
            HAVING COUNT(*) > 1");
        while ($dup = $db->fetch_array($dupRes)) {
            $db->write_query("DELETE FROM " . TABLE_PREFIX . "af_aa_active
                WHERE entity_type='" . $db->escape_string((string)$dup['entity_type']) . "'
                  AND entity_id=" . (int)$dup['entity_id'] . "
                  AND target_key='" . $db->escape_string((string)$dup['target_key']) . "'
                  AND id<>" . (int)$dup['keep_id']);
        }
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_aa_active ADD UNIQUE KEY uniq_entity_target (entity_type, entity_id, target_key)");
    }
}

function af_advancedshop_register_routes(): void
{
    // registration placeholder (canonical requirement)
}

function af_advancedshop_alias_target_path(string $alias = 'shop'): string
{
    return MYBB_ROOT . ($alias === 'shop_manage' ? 'shop_manage.php' : 'shop.php');
}

function af_advancedshop_alias_asset_path(string $alias = 'shop'): string
{
    return AF_ADVSHOP_ASSETS_DIR . ($alias === 'shop_manage' ? 'shop_manage.php' : 'shop.php');
}

function af_advancedshop_alias_marker(string $alias = 'shop'): string
{
    return $alias === 'shop_manage' ? AF_ADVSHOP_MANAGE_ALIAS_MARKER : AF_ADVSHOP_ALIAS_MARKER;
}

function af_advancedshop_alias_is_ours(string $path, string $alias = 'shop'): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $content = (string)file_get_contents($path);
    return strpos($content, af_advancedshop_alias_marker($alias)) !== false;
}

function af_advancedshop_alias_sync(string $alias = 'shop'): bool
{
    $target = af_advancedshop_alias_target_path($alias);
    $asset = af_advancedshop_alias_asset_path($alias);
    if (!is_file($asset) || !is_readable($asset)) {
        return false;
    }

    if (is_file($target) && !af_advancedshop_alias_is_ours($target, $alias)) {
        return false;
    }

    return @copy($asset, $target);
}

function af_advancedshop_alias_sync_notice_on_failure(string $alias = 'shop'): void
{
    $target = af_advancedshop_alias_target_path($alias);
    if (!is_file($target) || af_advancedshop_alias_is_ours($target, $alias)) {
        return;
    }

    if (defined('IN_ADMINCP') && function_exists('flash_message')) {
        $scriptName = $alias === 'shop_manage' ? 'shop_manage.php' : 'shop.php';
        flash_message('Advanced Shop: ' . $scriptName . ' already exists and is not managed by AF, alias was not installed.', 'error');
    }
}

function af_advancedshop_alias_available(): bool
{
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'shop.php') {
        return true;
    }

    return af_advancedshop_alias_is_ours(af_advancedshop_alias_target_path('shop'), 'shop');
}

function af_advancedshop_url(string $action = 'shop', array $params = [], bool $html = false): string
{
    $useAlias = af_advancedshop_alias_available();
    $script = $useAlias ? 'shop.php' : 'misc.php';

    if ($useAlias) {
        if ($action !== '' && $action !== 'shop' && $action !== 'view') {
            $params = array_merge(['action' => $action], $params);
        }
    } else {
        $params = array_merge(['action' => $action], $params);
    }

    $url = $script;
    if ($params) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url .= '?' . ($html ? str_replace('&', '&amp;', $query) : $query);
    }

    return $url;
}

function af_advancedshop_manage_url(string $shopCode = '', string $view = '', int $catId = 0, bool $html = false): string
{
    $params = [];
    if ($shopCode !== '') {
        $params['shop'] = $shopCode;
    }
    if ($view !== '') {
        $params['view'] = $view;
    }
    if ($catId > 0) {
        $params['cat_id'] = $catId;
    }

    $url = 'shop_manage.php';
    if ($params) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url .= '?' . ($html ? str_replace('&', '&amp;', $query) : $query);
    }

    return $url;
}

function af_advancedshop_manage_cat_id_input(): int
{
    global $mybb;

    $catId = (int)$mybb->get_input('cat_id', MyBB::INPUT_INT);
    if ($catId <= 0) {
        $catId = (int)$mybb->get_input('cat', MyBB::INPUT_INT);
    }

    if ($catId <= 0 && isset($_REQUEST['cat_id'])) {
        $catId = (int)$_REQUEST['cat_id'];
    }
    if ($catId <= 0 && isset($_REQUEST['cat'])) {
        $catId = (int)$_REQUEST['cat'];
    }

    return max(0, $catId);
}

function af_advancedshop_shops_table(): string
{
    global $db;

    if ($db->table_exists('af_shop_shops')) {
        return 'af_shop_shops';
    }

    return 'af_shop';
}

function af_advancedshop_ensure_shops_schema(): void
{
    global $db;

    if (!$db->table_exists('af_shop_shops')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_shops (
            shop_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            title_ru VARCHAR(255) NOT NULL DEFAULT '',
            title_en VARCHAR(255) NOT NULL DEFAULT '',
            bg_url VARCHAR(255) NOT NULL DEFAULT '',
            icon_url VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sortorder INT NOT NULL DEFAULT 0,
            settings_json MEDIUMTEXT NULL,
            UNIQUE KEY uniq_code (code),
            KEY enabled_sort (enabled, sortorder, shop_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!$db->field_exists('bg_url', 'af_shop_shops')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_shops ADD COLUMN bg_url VARCHAR(255) NOT NULL DEFAULT '' AFTER title_en");
    }
    if (!$db->field_exists('icon_url', 'af_shop_shops')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_shops ADD COLUMN icon_url VARCHAR(255) NOT NULL DEFAULT '' AFTER bg_url");
    }

    if ($db->table_exists('af_shop')) {
        if (!$db->field_exists('bg_url', 'af_shop')) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop ADD COLUMN bg_url VARCHAR(255) NOT NULL DEFAULT '' AFTER title");
        }
        if (!$db->field_exists('icon_url', 'af_shop')) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop ADD COLUMN icon_url VARCHAR(255) NOT NULL DEFAULT '' AFTER bg_url");
        }
    }

    if ($db->table_exists('af_shop')) {
        $qLegacy = $db->simple_select('af_shop', 'shop_id, code, title, enabled', '', ['order_by' => 'shop_id ASC']);
        while ($legacy = $db->fetch_array($qLegacy)) {
            $code = trim((string)($legacy['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $exists = $db->fetch_array($db->simple_select('af_shop_shops', 'shop_id', "code='" . $db->escape_string($code) . "'", ['limit' => 1]));
            if ($exists) {
                continue;
            }

            $legacyTitle = trim((string)($legacy['title'] ?? ''));
            $db->insert_query('af_shop_shops', [
                'shop_id' => (int)($legacy['shop_id'] ?? 0),
                'code' => $db->escape_string($code),
                'title_ru' => $db->escape_string($legacyTitle),
                'title_en' => $db->escape_string($legacyTitle),
                'bg_url' => $db->escape_string((string)($legacy['bg_url'] ?? '')),
                'icon_url' => $db->escape_string((string)($legacy['icon_url'] ?? '')),
                'enabled' => (int)($legacy['enabled'] ?? 1),
                'sortorder' => (int)($legacy['shop_id'] ?? 0),
                'settings_json' => null,
            ]);
        }
    }

    $shopsCount = (int)$db->fetch_field($db->simple_select('af_shop_shops', 'COUNT(*) AS c'), 'c');
    if ($shopsCount === 0 && af_advancedshop_seed_demo_enabled()) {
        $demoShops = [
            ['code' => 'game', 'title_ru' => 'Игровой магазин', 'title_en' => 'Game Shop', 'sortorder' => 10],
            ['code' => 'customization', 'title_ru' => 'Кастомизация', 'title_en' => 'Customization', 'sortorder' => 20],
            ['code' => 'other', 'title_ru' => 'Другое', 'title_en' => 'Other', 'sortorder' => 30],
        ];
        foreach ($demoShops as $shop) {
            $db->insert_query('af_shop_shops', [
                'code' => $db->escape_string((string)$shop['code']),
                'title_ru' => $db->escape_string((string)$shop['title_ru']),
                'title_en' => $db->escape_string((string)$shop['title_en']),
                'bg_url' => '',
                'icon_url' => '',
                'enabled' => 1,
                'sortorder' => (int)$shop['sortorder'],
                'settings_json' => null,
            ]);
        }
    }
}

function af_advancedshop_css_background_style(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $safe = str_replace(["\\", '"', "'", ')', '(', "\r", "\n"], '', $url);
    if ($safe === '') {
        return '';
    }

    return ' style="background-image:url(\'' . htmlspecialchars_uni($safe) . '\');"';
}

function af_advancedshop_seed_demo_enabled(): bool
{
    global $db, $mybb;

    $value = isset($mybb->settings['af_advancedshop_seed_demo'])
        ? (string)$mybb->settings['af_advancedshop_seed_demo']
        : '';

    if ($value === '' && isset($db) && is_object($db)) {
        $value = (string)$db->fetch_field(
            $db->simple_select('settings', 'value', "name='af_advancedshop_seed_demo'", ['limit' => 1]),
            'value'
        );
    }

    return (int)$value === 1;
}

function af_advancedshop_install(): void
{
    global $db, $lang;
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang('advancedshop');
    }

    $gid = af_advancedshop_ensure_setting_group(
        $lang->af_advancedshop_group ?? 'AF: Shop',
        $lang->af_advancedshop_group_desc ?? 'Shop addon settings.'
    );
    af_advancedshop_ensure_setting('af_advancedshop_enabled', $lang->af_advancedshop_enabled ?? 'Enable shop', $lang->af_advancedshop_enabled_desc ?? 'Yes/No', 'yesno', '1', 1, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_manage_groups', $lang->af_advancedshop_manage_groups ?? 'Manage groups', $lang->af_advancedshop_manage_groups_desc ?? 'CSV IDs', 'text', '3,4,6', 2, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_currency_slug', $lang->af_advancedshop_currency_slug ?? 'Currency', $lang->af_advancedshop_currency_slug_desc ?? 'credits', 'text', 'credits', 3, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_items_per_page', 'Items per page', 'Shop page size', 'numeric', '24', 4, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_allow_guest_view', 'Allow guest view', 'Guests may browse the shop', 'yesno', '1', 5, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_seed_demo', 'Seed demo shops', 'Create demo shops (game/customization/other) on install/activate when no shops exist.', 'yesno', '0', 6, $gid);
    af_advancedshop_ensure_setting(
        'af_shop_assets_blacklist',
        'Assets blacklist',
        AF_ADVSHOP_ASSETS_BLACKLIST_DESC,
        'textarea',
        AF_ADVSHOP_ASSETS_BLACKLIST_DEFAULT,
        7,
        $gid,
        true
    );

    af_advancedshop_ensure_shops_schema();
    if (!$db->table_exists('af_shop_categories')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_categories (
            cat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            parent_id INT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            sortorder INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            KEY shop_sort (shop_id, parent_id, sortorder)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_slots')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_slots (
            slot_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            cat_id INT UNSIGNED NOT NULL,
            source_type VARCHAR(32) NOT NULL DEFAULT 'kb',
            source_ref_id INT UNSIGNED NOT NULL DEFAULT 0,
            kb_type VARCHAR(32) NOT NULL DEFAULT 'item',
            kb_id INT UNSIGNED NOT NULL,
            kb_key VARCHAR(128) NOT NULL DEFAULT '',
            price INT NOT NULL DEFAULT 0,
            currency VARCHAR(32) NOT NULL DEFAULT 'credits',
            stock INT NOT NULL DEFAULT -1,
            limit_per_user INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sortorder INT NOT NULL DEFAULT 0,
            meta_json MEDIUMTEXT NULL,
            KEY shop_cat_sort (shop_id, cat_id, enabled, sortorder),
            KEY kb_idx (kb_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_carts')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_carts (
            cart_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            uid INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            KEY shop_uid (shop_id, uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_cart_items')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_cart_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cart_id INT UNSIGNED NOT NULL,
            slot_id INT UNSIGNED NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            KEY cart_slot (cart_id, slot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_orders')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_orders (
            order_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            uid INT UNSIGNED NOT NULL,
            total INT NOT NULL DEFAULT 0,
            currency VARCHAR(32) NOT NULL DEFAULT 'credits',
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'paid',
            items_json MEDIUMTEXT NOT NULL,
            KEY uid_created (uid, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    af_advancedshop_templates_install_or_update();
    af_advancedshop_ensure_slots_schema();
    af_advancedshop_ensure_appearance_active_schema();
    if (!af_advancedshop_alias_sync('shop')) {
        af_advancedshop_alias_sync_notice_on_failure('shop');
    }
    if (!af_advancedshop_alias_sync('shop_manage')) {
        af_advancedshop_alias_sync_notice_on_failure('shop_manage');
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedshop_activate(): void
{
    global $lang;
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang('advancedshop');
    }
    $gid = af_advancedshop_ensure_setting_group(
        $lang->af_advancedshop_group ?? 'AF: Shop',
        $lang->af_advancedshop_group_desc ?? 'Shop addon settings.'
    );
    af_advancedshop_ensure_setting('af_advancedshop_enabled', $lang->af_advancedshop_enabled ?? 'Enable shop', $lang->af_advancedshop_enabled_desc ?? 'Yes/No', 'yesno', '1', 1, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_manage_groups', $lang->af_advancedshop_manage_groups ?? 'Manage groups', $lang->af_advancedshop_manage_groups_desc ?? 'CSV IDs', 'text', '3,4,6', 2, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_currency_slug', $lang->af_advancedshop_currency_slug ?? 'Currency', $lang->af_advancedshop_currency_slug_desc ?? 'credits', 'text', 'credits', 3, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_items_per_page', 'Items per page', 'Shop page size', 'numeric', '24', 4, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_allow_guest_view', 'Allow guest view', 'Guests may browse the shop', 'yesno', '1', 5, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_seed_demo', 'Seed demo shops', 'Create demo shops (game/customization/other) on install/activate when no shops exist.', 'yesno', '0', 6, $gid, true);
    af_advancedshop_ensure_setting(
        'af_shop_assets_blacklist',
        'Assets blacklist',
        AF_ADVSHOP_ASSETS_BLACKLIST_DESC,
        'textarea',
        AF_ADVSHOP_ASSETS_BLACKLIST_DEFAULT,
        7,
        $gid,
        true
    );
    af_advancedshop_ensure_shops_schema();
    af_advancedshop_ensure_slots_schema();
    af_advancedshop_ensure_appearance_active_schema();
    af_advancedshop_write_inventory_schema_snapshot();
    af_advancedshop_templates_install_or_update();
    if (!af_advancedshop_alias_sync('shop')) {
        af_advancedshop_alias_sync_notice_on_failure('shop');
    }
    if (!af_advancedshop_alias_sync('shop_manage')) {
        af_advancedshop_alias_sync_notice_on_failure('shop_manage');
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedshop_deactivate(): void
{
    foreach (['shop', 'shop_manage'] as $alias) {
        $target = af_advancedshop_alias_target_path($alias);
        if (af_advancedshop_alias_is_ours($target, $alias)) {
            @unlink($target);
        }
    }
}

function af_advancedshop_uninstall(): void
{
    global $db;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedshop'", ['limit' => 1]), 'gid');
    if ($gid > 0) {
        $db->delete_query('settings', 'gid=' . $gid);
        $db->delete_query('settinggroups', 'gid=' . $gid);
    }
    foreach (['advancedshop_%'] as $like) {
        $db->delete_query('templates', "title LIKE '" . $db->escape_string($like) . "'");
    }

    foreach (['shop', 'shop_manage'] as $alias) {
        $target = af_advancedshop_alias_target_path($alias);
        if (af_advancedshop_alias_is_ours($target, $alias)) {
            @unlink($target);
        }
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedshop_is_installed(): bool
{
    global $db;
    return $db->table_exists('af_shop_shops') || $db->table_exists('af_shop');
}

function af_advancedshop_shop_routes(): array
{
    return ['shop','shop_category','shop_cart','shop_checkout','shop_add_to_cart','shop_update_cart','shop_manage','shop_manage_categories','shop_manage_category_create','shop_manage_category_update','shop_manage_category_delete','kb_category_update','kb_category_delete','shop_manage_sortorder_rebuild','shop_manage_slots','shop_manage_slot_create','shop_manage_slot_update','shop_manage_slot_delete','shop_kb_search','shop_appearance_search','shop_kb_schema','shop_kb_probe','shop_health'];
}

function af_advancedshop_render_shop_page(): void
{
    global $mybb;

    $action = (string)$mybb->get_input('action');
    if ($action === '' || $action === 'view') {
        $action = 'shop';
    }

    af_advancedshop_redirect_legacy_manage_action($action);

    af_advancedshop_dispatch($action);
}

function af_advancedshop_redirect_legacy_manage_action(string $action): void
{
    global $db, $mybb;

    if (strtolower((string)($mybb->request_method ?? 'get')) !== 'get') {
        return;
    }

    $mode = trim((string)$mybb->get_input('do'));
    if ($mode !== '') {
        return;
    }

    $legacyActions = [
        'shop_manage',
        'shop_manage_categories',
        'shop_manage_category_create',
        'shop_manage_category_update',
        'shop_manage_category_delete',
        'shop_manage_sortorder_rebuild',
        'shop_manage_slots',
        'shop_manage_slot_create',
        'shop_manage_slot_update',
        'shop_manage_slot_delete',
    ];
    if (!in_array($action, $legacyActions, true)) {
        return;
    }

    $legacyShop = trim((string)$mybb->get_input('shop'));
    $catId = (int)$mybb->get_input('cat');
    if ($catId <= 0) {
        $catId = (int)$mybb->get_input('cat_id');
    }

    $shopCode = '';
    if ($legacyShop !== '') {
        $shopsTable = af_advancedshop_shops_table();
        if (ctype_digit($legacyShop)) {
            $row = $db->fetch_array($db->simple_select($shopsTable, 'code', 'shop_id=' . (int)$legacyShop, ['limit' => 1]));
            $shopCode = (string)($row['code'] ?? '');
        } else {
            $row = $db->fetch_array($db->simple_select($shopsTable, 'code', "code='" . $db->escape_string($legacyShop) . "'", ['limit' => 1]));
            $shopCode = (string)($row['code'] ?? '');
        }
    }

    if ($shopCode === '') {
        header('Location: ' . af_advancedshop_manage_url());
        exit;
    }

    $isSlotsAction = $action === 'shop_manage_slots' || $action === 'shop_manage_slot_create' || $action === 'shop_manage_slot_update' || $action === 'shop_manage_slot_delete';
    $target = af_advancedshop_manage_url($shopCode, $isSlotsAction ? 'slots' : 'categories', $isSlotsAction ? $catId : 0);

    header('Location: ' . $target);
    exit;
}

function af_advancedshop_dispatch(string $action): void
{
    global $mybb;

    $routes = af_advancedshop_shop_routes();
    if (!in_array($action, $routes, true)) {
        error_no_permission();
    }

    $apiActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart', 'shop_manage_categories', 'shop_manage_category_create', 'shop_manage_category_update', 'shop_manage_category_delete', 'kb_category_update', 'kb_category_delete', 'shop_manage_sortorder_rebuild', 'shop_manage_slots', 'shop_manage_slot_create', 'shop_manage_slot_update', 'shop_manage_slot_delete', 'shop_kb_search', 'shop_appearance_search', 'shop_kb_schema', 'shop_kb_probe', 'shop_health'];
    $buyActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart'];
    $manageActions = ['shop_manage', 'shop_manage_categories', 'shop_manage_category_create', 'shop_manage_category_update', 'shop_manage_category_delete', 'kb_category_update', 'kb_category_delete', 'shop_manage_sortorder_rebuild', 'shop_manage_slots', 'shop_manage_slot_create', 'shop_manage_slot_update', 'shop_manage_slot_delete', 'shop_kb_search', 'shop_appearance_search', 'shop_kb_schema', 'shop_health'];

    if ((int)($mybb->settings['af_advancedshop_enabled'] ?? 1) !== 1) {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }

    if (in_array($action, ['shop', 'shop_category', 'shop_cart'], true) && !af_advancedshop_can_view_shop()) {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }
    if (in_array($action, $buyActions, true) && !af_advancedshop_can_buy()) {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }
    if (in_array($action, $manageActions, true) && !af_advancedshop_can_manage()) {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }
    $postKeyActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart', 'shop_manage_category_create', 'shop_manage_category_update', 'shop_manage_category_delete', 'kb_category_update', 'kb_category_delete', 'shop_manage_sortorder_rebuild', 'shop_manage_slot_create', 'shop_manage_slot_update', 'shop_manage_slot_delete'];
    if (in_array($action, $postKeyActions, true)) {
        af_advancedshop_assert_post_key();
    }
    if (in_array($action, ['shop_manage_slots', 'shop_manage_categories'], true) && strtolower($mybb->request_method) === 'post') {
        af_advancedshop_assert_post_key();
    }

    try {
        switch ($action) {
            case 'shop':
                af_advancedshop_render_hub();
                return;
            case 'shop_category':
                af_advancedshop_render_shop(true);
                return;
            case 'shop_cart': af_advancedshop_render_cart(); return;
            case 'shop_checkout': af_advancedshop_checkout(); return;
            case 'shop_add_to_cart': af_advancedshop_add_to_cart(); return;
            case 'shop_update_cart': af_advancedshop_update_cart(); return;
            case 'shop_manage': af_advancedshop_render_manage(); return;
            case 'shop_manage_categories': af_advancedshop_manage_categories(); return;
            case 'shop_manage_category_create': af_advancedshop_manage_category_create(); return;
            case 'shop_manage_category_update':
            case 'kb_category_update': af_advancedshop_manage_category_update(); return;
            case 'shop_manage_category_delete':
            case 'kb_category_delete': af_advancedshop_manage_category_delete(); return;
            case 'shop_manage_sortorder_rebuild': af_advancedshop_manage_sortorder_rebuild(); return;
            case 'shop_manage_slots': af_advancedshop_manage_slots(); return;
            case 'shop_manage_slot_create': af_advancedshop_manage_slot_create(); return;
            case 'shop_manage_slot_update': af_advancedshop_manage_slot_update(); return;
            case 'shop_manage_slot_delete': af_advancedshop_manage_slot_delete(); return;
            case 'shop_kb_search': af_advancedshop_kb_search(); return;
            case 'shop_appearance_search': af_advancedshop_appearance_search(); return;
            case 'shop_kb_schema': af_advancedshop_kb_schema(); return;
            case 'shop_kb_probe': af_advancedshop_kb_probe(); return;
            case 'shop_health': af_advancedshop_health_ping(); return;
        }
    } catch (mysqli_sql_exception $e) {
        if (in_array($action, $apiActions, true)) {
            $details = af_advancedshop_can_manage() ? ['details' => $e->getMessage()] : [];
            af_advancedshop_json_err('DB error', 500, $details);
        }
        throw $e;
    } catch (Throwable $e) {
        if (in_array($action, $apiActions, true)) {
            $details = af_advancedshop_can_manage() ? ['details' => $e->getMessage()] : [];
            af_advancedshop_json_err('Server error', 500, $details);
        }
        throw $e;
    }
}

function af_advancedshop_misc_router(): void
{
    global $mybb;
    if (($mybb->input['action'] ?? '') === '') { return; }

    $action = (string)$mybb->get_input('action');
    if (!in_array($action, af_advancedshop_shop_routes(), true)) {
        return;
    }

    if (!af_advancedshop_alias_available()) {
        af_advancedshop_dispatch($action);
        return;
    }

    $params = $_GET;
    unset($params['action']);
    $target = af_advancedshop_url($action, $params, false);
    header('Location: ' . $target);
    exit;
}

function af_advancedshop_templates_install_or_update(): void
{
    global $db;

    $db->delete_query('templates', "title IN ('advancedshop_inventory','advancedshop_inventory_equipment','advancedshop_inventory_equipment_slot','advancedshop_inventory_grid','advancedshop_inventory_layout','advancedshop_inventory_slot','advancedshop_inventory_tabs','advancedshop_equipment_panel')");

    foreach (glob(AF_ADVSHOP_TPL_DIR . '*.html') ?: [] as $file) {
        $name = basename($file, '.html');
        $template = (string)file_get_contents($file);
        $row = [
            'title' => $db->escape_string($name),
            'template' => $db->escape_string($template),
            'sid' => -2,
            'version' => '',
            'dateline' => TIME_NOW,
        ];
        $existing = (int)$db->fetch_field($db->simple_select('templates', 'tid', "title='".$db->escape_string($name)."'", ['limit' => 1]), 'tid');
        if ($existing > 0) {
            $db->update_query('templates', $row, 'tid=' . $existing);
        } else {
            $db->insert_query('templates', $row);
        }
    }
}

function af_advancedshop_tpl(string $name): string
{
    global $templates;
    return $templates->get($name, 1, 0);
}

function af_advancedshop_inventory_url(int $uid, bool $html = false, array $extra = []): string
{
    $params = array_merge(['uid' => $uid], $extra);
    if (function_exists('af_advancedinventory_url')) {
        return af_advancedinventory_url('inventory', $params, $html);
    }

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return 'inventory.php' . ($query !== '' ? ('?' . ($html ? str_replace('&', '&amp;', $query) : $query)) : '');
}

function af_advancedshop_ensure_setting_group(string $title, string $desc): int
{
    global $db;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedshop'", ['limit' => 1]), 'gid');
    if ($gid > 0) { return $gid; }
    $disp = (int)$db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm') + 1;
    $db->insert_query('settinggroups', ['name' => 'af_advancedshop', 'title' => $db->escape_string($title), 'description' => $db->escape_string($desc), 'disporder' => $disp, 'isdefault' => 0]);
    return (int)$db->insert_id();
}

function af_advancedshop_ensure_setting(string $name, string $title, string $desc, string $code, string $value, int $order, int $gid, bool $preserveExistingValue = false): void
{
    global $db;
    $sid = (int)$db->fetch_field($db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]), 'sid');
    $row = ['name' => $db->escape_string($name), 'title' => $db->escape_string($title), 'description' => $db->escape_string($desc), 'optionscode' => $db->escape_string($code), 'value' => $db->escape_string($value), 'disporder' => $order, 'gid' => $gid, 'isdefault' => 0];
    if ($sid > 0) {
        if ($preserveExistingValue) {
            unset($row['value']);
        }
        $db->update_query('settings', $row, 'sid=' . $sid);
    }
    else { $db->insert_query('settings', $row); }
}

function af_advancedshop_assert_post_key(): void
{
    global $mybb;
    if (strtolower($mybb->request_method) !== 'post') {
        af_advancedshop_json_err('POST required', 405);
    }
    if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
        af_advancedshop_json_err('Invalid post key', 403);
    }
}

function af_advancedshop_parse_groups_csv(string $csv): array
{
    $ids = [];
    foreach (explode(',', $csv) as $part) {
        $id = (int)trim($part);
        if ($id > 0) { $ids[$id] = $id; }
    }
    return array_values($ids);
}

function af_advancedshop_user_group_ids(): array
{
    global $mybb;
    $ids = [(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',', (string)($mybb->user['additionalgroups'] ?? '')) as $g) {
        $gid = (int)trim($g);
        if ($gid > 0) { $ids[] = $gid; }
    }
    return array_values(array_unique(array_filter($ids)));
}

function af_advancedshop_can_manage(): bool
{
    return af_advancedshop_user_can_manage();
}

function af_advancedshop_user_can_manage(): bool
{
    global $mybb;

    if ((int)($mybb->user['uid'] ?? 0) <= 0) {
        return false;
    }

    if ((int)($mybb->usergroup['cancp'] ?? 0) === 1 || (int)($mybb->user['usergroup'] ?? 0) === 4) {
        return true;
    }

    $allowed = af_advancedshop_parse_groups_csv((string)($mybb->settings['af_advancedshop_manage_groups'] ?? '3,4,6'));
    return (bool)array_intersect($allowed, af_advancedshop_user_group_ids());
}

function af_advancedshop_can_view_shop(): bool
{
    global $mybb;
    if ((int)($mybb->user['uid'] ?? 0) > 0) {
        return true;
    }
    return (int)($mybb->settings['af_advancedshop_allow_guest_view'] ?? 1) === 1;
}

function af_advancedshop_can_buy(): bool
{
    global $mybb;
    return (int)($mybb->user['uid'] ?? 0) > 0;
}

function af_advancedshop_render_shop(bool $strictByCode = false): void
{
    global $db, $mybb, $lang, $headerinclude, $header, $footer;
    if (!af_advancedshop_can_view_shop()) {
        error_no_permission();
    }
    $shop = af_advancedshop_current_shop($strictByCode);
    if (!$shop) {
        af_advancedshop_render_shop_not_found();
    }
    add_breadcrumb($lang->af_advancedshop_hub_title ?? 'Выбор магазина', af_advancedshop_url('shop'));
    $shopId = (int)$shop['shop_id'];
    $catId = (int)$mybb->get_input('cat');

    $flatCats = [];
    $isManagerView = af_advancedshop_user_can_manage();
    $catWhere = 'shop_id=' . $shopId;
    if (!$isManagerView) {
        $catWhere .= ' AND enabled=1';
    }
    $qCats = $db->simple_select('af_shop_categories', '*', $catWhere, ['order_by' => 'sortorder ASC, cat_id ASC']);
    while ($cat = $db->fetch_array($qCats)) {
        $flatCats[] = $cat;
    }
    $slotCounts = [];
    $qSlotCounts = $db->query('SELECT cat_id, COUNT(*) AS cnt FROM ' . TABLE_PREFIX . 'af_shop_slots WHERE shop_id=' . $shopId . ' GROUP BY cat_id');
    while ($countRow = $db->fetch_array($qSlotCounts)) {
        $slotCounts[(int)$countRow['cat_id']] = (int)$countRow['cnt'];
    }
    $cats = af_advancedshop_render_shop_categories_tree($flatCats, (string)$shop['code'], $catId, $isManagerView, $slotCounts);
    af_advancedshop_debug_categories((string)$shop['code'], $shopId, $flatCats);

    $slotsHtml = '';
    $where = 's.shop_id=' . $shopId . ' AND s.enabled=1';
    if ($catId > 0) { $where .= ' AND s.cat_id=' . $catId; }
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $slotHasKbType = $db->field_exists('kb_type', 'af_shop_slots');
    $slotHasKbKey = $db->field_exists('kb_key', 'af_shop_slots');
    $kbSelect = ['e.' . $kbIdCol . ' AS kb_id'];
    if (!empty($kbCols['title_ru'])) { $kbSelect[] = 'e.' . $kbCols['title_ru'] . ' AS kb_title_ru'; }
    if (!empty($kbCols['title_en'])) { $kbSelect[] = 'e.' . $kbCols['title_en'] . ' AS kb_title_en'; }
    if (empty($kbCols['title_ru']) && empty($kbCols['title_en']) && !empty($kbCols['title'])) { $kbSelect[] = 'e.' . $kbCols['title'] . ' AS kb_title'; }
    if (!empty($kbCols['short_ru'])) { $kbSelect[] = 'e.' . $kbCols['short_ru'] . ' AS kb_short_ru'; }
    if (!empty($kbCols['short_en'])) { $kbSelect[] = 'e.' . $kbCols['short_en'] . ' AS kb_short_en'; }
    if (empty($kbCols['short_ru']) && empty($kbCols['short_en']) && !empty($kbCols['short'])) { $kbSelect[] = 'e.' . $kbCols['short'] . ' AS kb_short'; }
    if (!empty($kbCols['body_ru'])) { $kbSelect[] = 'e.' . $kbCols['body_ru'] . ' AS kb_body_ru'; }
    if (!empty($kbCols['body_en'])) { $kbSelect[] = 'e.' . $kbCols['body_en'] . ' AS kb_body_en'; }
    if (empty($kbCols['body_ru']) && empty($kbCols['body_en']) && !empty($kbCols['body'])) { $kbSelect[] = 'e.' . $kbCols['body'] . ' AS kb_body'; }
    if (!empty($kbCols['meta_json'])) { $kbSelect[] = 'e.' . $kbCols['meta_json'] . ' AS kb_meta'; }
    if (!empty($kbCols['type'])) { $kbSelect[] = 'e.' . ($kbCols['type'] === 'type' ? '`type`' : $kbCols['type']) . ' AS kb_type'; }
    if (!empty($kbCols['key'])) { $kbSelect[] = 'e.' . ($kbCols['key'] === 'key' ? '`key`' : $kbCols['key']) . ' AS kb_key'; }
    if ($slotHasKbType) { $kbSelect[] = 's.kb_type AS slot_kb_type'; }
    if ($slotHasKbKey) { $kbSelect[] = 's.kb_key AS slot_kb_key'; }
    $qSlots = $db->query("SELECT s.*, " . implode(', ', $kbSelect) . "
        FROM " . TABLE_PREFIX . "af_shop_slots s
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
        WHERE {$where}
        ORDER BY s.sortorder ASC, s.slot_id DESC");

    while ($slot = $db->fetch_array($qSlots)) {
        $slot_id = (int)$slot['slot_id'];
        $slot_price = af_advancedshop_money_format((int)$slot['price']);
        $slot_currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol((string)$slot['currency']));

        $sourceType = af_advancedshop_source_type_from_slot($slot);
        $sourceRefId = af_advancedshop_source_ref_id_from_slot($slot);
        $slot_kb_id = (int)$slot['kb_id'];
        $slot_kb_type = (string)($slot['slot_kb_type'] ?? ($slot['kb_type'] ?? 'item'));
        if ($slot_kb_type === '') { $slot_kb_type = 'item'; }
        $slot_kb_key = (string)($slot['slot_kb_key'] ?? ($slot['kb_key'] ?? ''));

        $profile = af_advancedshop_kb_item_profile($slot);
        $slot_rarity_value = (string)$profile['rarity'];
        $slot_title_raw = '';
        $slot_short_raw = '';
        $slot_icon_raw = '';

        if ($sourceType === 'appearance') {
            $preset = af_advancedshop_appearance_fetch_preset($sourceRefId);
            $slot_title_raw = trim((string)($preset['title'] ?? ''));
            $slot_short_raw = trim((string)($preset['description'] ?? ''));
            $slot_icon_raw = trim((string)($preset['preview_image'] ?? ''));
            try {
                $preset['target_key'] = af_advancedshop_appearance_validate_target((string)($preset['target_key'] ?? ''));
            } catch (RuntimeException $e) {
                continue;
            }
            if ((int)($preset['enabled'] ?? 0) !== 1) {
                continue;
            }
            if ($slot_title_raw === '') {
                $slot_title_raw = 'Preset #' . $sourceRefId;
            }
            $slot_kb_id = 0;
            $slot_kb_key = 'appearance:' . $sourceRefId;
            $slot_kb_type = 'appearance';
            $slot_rarity_value = 'unique';
        } else {
            $kbTitle = af_advancedshop_pick_lang((string)($slot['kb_title_ru'] ?? ''), (string)($slot['kb_title_en'] ?? ''));
            if ($kbTitle === '') { $kbTitle = (string)($slot['kb_title'] ?? ''); }
            $slot_title_raw = $kbTitle ?: ('#' . (int)$slot['kb_id']);
            $shortText = af_advancedshop_pick_lang((string)($slot['kb_short_ru'] ?? ''), (string)($slot['kb_short_en'] ?? ''));
            if ($shortText === '') { $shortText = (string)($slot['kb_short'] ?? ''); }
            if ($shortText === '') {
                $bodyText = af_advancedshop_pick_lang((string)($slot['kb_body_ru'] ?? ''), (string)($slot['kb_body_en'] ?? ''));
                if ($bodyText === '') { $bodyText = (string)($slot['kb_body'] ?? ''); }
                $shortText = mb_substr(strip_tags($bodyText), 0, 140);
            }
            $slot_short_raw = $shortText;
            $meta = @json_decode((string)($slot['kb_meta'] ?? '{}'), true);
            $slot_icon_raw = (string)($meta['ui']['icon_url'] ?? ($slot['icon_url'] ?? ''));
        }

        $slot_title = htmlspecialchars_uni($slot_title_raw);
        $slot_short = htmlspecialchars_uni($slot_short_raw);
        $slot_icon = htmlspecialchars_uni($slot_icon_raw);
        $slot_rarity = htmlspecialchars_uni($slot_rarity_value);
        $slot['rarity_label'] = af_advancedshop_rarity_label($slot_rarity_value);
        $slot_rarity_label = htmlspecialchars_uni((string)$slot['rarity_label']);
        $slot['rarity_class'] = 'af-rarity-' . $slot_rarity_value;
        $slot_rarity_class = htmlspecialchars_uni((string)$slot['rarity_class']);
        $slot_kb_url = $sourceType === 'appearance'
            ? '#'
            : htmlspecialchars_uni(af_advancedshop_kb_entry_url($slot_kb_id, $slot_kb_type, $slot_kb_key));
        eval('$slotsHtml .= "' . af_advancedshop_tpl('advancedshop_product_card') . '";');
    }

    $balance = (int)af_shop_get_balance((int)($mybb->user['uid'] ?? 0), (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits'));
    $currencySlug = (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits');
    $currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol($currencySlug));
    $balance = af_advancedshop_money_format($balance);
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    $shop_title = htmlspecialchars_uni($lang->af_advancedshop_shop_title ?? 'Shop');
    $cart_url = af_advancedshop_url('shop_cart', ['shop' => (string)$shop['code']], true);
    $shop_manage_button = '';
    if (af_advancedshop_user_can_manage()) {
        $manage_url = htmlspecialchars_uni(af_advancedshop_manage_url((string)$shop['code']));
        $shop_manage_button = '<a class="af-shop-manage-link" href="' . $manage_url . '" title="Manage" aria-label="Manage">⚙</a>';
    }
    $inventory_link = '';
    if ((int)($mybb->user['uid'] ?? 0) > 0) {
        $inventory_link = '<a class="af-shop-btn" href="' . htmlspecialchars_uni(af_advancedshop_inventory_url((int)$mybb->user['uid'])) . '">Инвентарь</a>';
    }
    $balance_badge = '<span class="af-shop-balance">' . htmlspecialchars_uni($lang->af_advancedshop_balance ?? 'Balance') . ': <strong>' . $balance . '</strong> ' . $currency_symbol . '</span>';
    $assets = af_advancedshop_assets_html();
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_shop') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_render_hub(): void
{
    global $db, $lang, $headerinclude, $header, $footer;

    if (!af_advancedshop_can_view_shop()) {
        error_no_permission();
    }

    $cards_html = '';
    $shopsTable = af_advancedshop_shops_table();
    $qShops = $db->simple_select(
        $shopsTable,
        'shop_id, code, title_ru, title_en, bg_url',
        'enabled=1',
        ['order_by' => 'sortorder ASC, shop_id ASC']
    );

    while ($shop = $db->fetch_array($qShops)) {
        $code = trim((string)($shop['code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $shop_code = htmlspecialchars_uni($code);
        $shop_title_raw = af_advancedshop_pick_lang((string)($shop['title_ru'] ?? ''), (string)($shop['title_en'] ?? ''));
        if ($shop_title_raw === '') {
            $shop_title_raw = $code;
        }
        $shop_title = htmlspecialchars_uni($shop_title_raw);
        $shop_bg_style = af_advancedshop_css_background_style((string)($shop['bg_url'] ?? ''));
        $shop_open_url = af_advancedshop_url('shop_category', ['shop' => $code], true);
        $shop_manage_button = '';
        if (af_advancedshop_user_can_manage()) {
            $manage_url = htmlspecialchars_uni(af_advancedshop_manage_url($code));
            $shop_manage_button = '<a class="af-shop-manage-link" href="' . $manage_url . '" title="Manage" aria-label="Manage">⚙</a>';
        }
        $shop_open_text = htmlspecialchars_uni($lang->af_advancedshop_hub_open ?? 'Открыть');
        eval('$cards_html .= "' . af_advancedshop_tpl('advancedshop_hub_card') . '";');
    }

    if ($cards_html === '') {
        $cards_html = '<div class="af-status-error">' . htmlspecialchars_uni($lang->af_advancedshop_hub_empty ?? 'Нет доступных магазинов') . '</div>';
    }

    $shop_title = htmlspecialchars_uni($lang->af_advancedshop_hub_title ?? 'Выбор магазина');
    $assets = af_advancedshop_assets_html();
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_hub_page') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_render_shop_not_found(): void
{
    global $headerinclude, $header, $footer;

    http_response_code(404);
    $shop_home_url = htmlspecialchars_uni(af_advancedshop_url('shop', [], true));
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_shop_not_found') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_assets_html(): string
{
    if (af_shop_assets_disabled_for_current_page()) {
        return '';
    }

    [$cssUrl, $jsUrl] = af_advancedshop_asset_urls();
    $endpointScript = af_advancedshop_alias_available() ? 'shop.php' : 'misc.php';
    return '<!-- af_advancedshop_assets -->'
        . '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">'
        . '<script>window.AFSHOP=window.AFSHOP||{};window.AFSHOP.endpointScript=' . json_encode($endpointScript) . ';</script>'
        . '<script defer src="' . htmlspecialchars_uni($jsUrl) . '"></script>';
}

function af_shop_assets_disabled_for_current_page(): bool
{
    global $mybb;

    $script = af_advancedshop_normalize_script_name(defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '');
    if ($script === '') {
        return false;
    }

    $action = af_advancedshop_normalize_action_name((string)$mybb->get_input('action'));

    $defaultBlacklist = preg_split('~\R~', AF_ADVSHOP_ASSETS_BLACKLIST_DEFAULT) ?: [];

    $customRaw = (string)($mybb->settings['af_shop_assets_blacklist'] ?? AF_ADVSHOP_ASSETS_BLACKLIST_DEFAULT);
    $lines = preg_split('~\R~', $customRaw) ?: [];

    $entries = array_merge($defaultBlacklist, $lines);
    foreach ($entries as $line) {
        $entry = af_advancedshop_parse_assets_blacklist_entry((string)$line);
        if ($entry['script'] === '' || $entry['script'] !== $script) {
            continue;
        }

        if ($entry['action'] === null || $entry['action'] === $action) {
            return true;
        }
    }

    return false;
}

function af_advancedshop_parse_assets_blacklist_entry(string $line): array
{
    $entry = trim($line);
    if ($entry === '') {
        return ['script' => '', 'action' => null];
    }

    $scriptPart = $entry;
    $actionPart = null;

    $questionPos = strpos($entry, '?');
    if ($questionPos !== false) {
        $scriptPart = substr($entry, 0, $questionPos);
        $query = substr($entry, $questionPos + 1);

        foreach (explode('&', $query) as $pair) {
            if (trim($pair) === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $key = isset($parts[0]) ? strtolower(trim(urldecode((string)$parts[0]))) : '';
            if ($key !== 'action') {
                continue;
            }

            $value = isset($parts[1]) ? urldecode((string)$parts[1]) : '';
            $actionPart = $value;
            break;
        }
    }

    return [
        'script' => af_advancedshop_normalize_script_name($scriptPart),
        'action' => $actionPart === null ? null : af_advancedshop_normalize_action_name($actionPart),
    ];
}

function af_advancedshop_normalize_script_name(string $script): string
{
    $script = trim($script);
    if ($script === '') {
        return '';
    }

    return strtolower(basename(str_replace('\\', '/', $script)));
}

function af_advancedshop_normalize_action_name(string $action): string
{
    $action = trim($action);
    if ($action === '') {
        return '';
    }

    return strtolower($action);
}

function af_advancedshop_asset_urls(): array
{
    global $mybb;
    $base = rtrim((string)$mybb->settings['bburl'], '/') . '/inc/plugins/advancedfunctionality/addons/advancedshop/assets';
    $cssPath = AF_ADVSHOP_BASE . 'assets/advancedshop.css';
    $jsPath = AF_ADVSHOP_BASE . 'assets/advancedshop.js';
    $vCss = @file_exists($cssPath) ? (string)@filemtime($cssPath) : '1';
    $vJs = @file_exists($jsPath) ? (string)@filemtime($jsPath) : '1';
    return [$base . '/advancedshop.css?v=' . rawurlencode($vCss), $base . '/advancedshop.js?v=' . rawurlencode($vJs)];
}

function af_advancedshop_pre_output(string &$page = ''): void
{
    global $mybb;
    if (af_shop_assets_disabled_for_current_page()) {
        af_advancedshop_strip_assets_from_html($page);
        return;
    }

    if (!af_advancedshop_should_load_assets_for_page((string)$page)) {
        af_advancedshop_strip_assets_from_html($page);
        return;
    }

    $action = (string)($mybb->input['action'] ?? '');
    $thisScript = defined('THIS_SCRIPT') ? THIS_SCRIPT : '';
    $isShopScript = $thisScript === 'shop.php';
    if (!$isShopScript && !in_array($action, ['shop', 'shop_category', 'shop_cart', 'shop_manage', 'shop_manage_slots', 'af_charactersheet'], true)) {
        return;
    }

    [$cssUrl, $jsUrl] = af_advancedshop_asset_urls();
    if (strpos($page, '<!-- af_advancedshop_assets -->') !== false) {
        return;
    }
    $bits = "\n<!-- af_advancedshop_assets -->\n"
        . '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n"
        . '<script defer src="' . htmlspecialchars_uni($jsUrl) . '"></script>' . "\n";
    if (strpos($page, '</head>') !== false) {
        $page = str_replace('</head>', $bits . '</head>', $page);
    } else {
        $page = $bits . $page;
    }
}

function af_advancedshop_should_load_assets_for_page(string $page): bool
{
    $page = strtolower($page);
    if ($page === '') {
        return false;
    }

    if (strpos($page, '<!-- af_advancedshop_assets -->') !== false) {
        return true;
    }

    return strpos($page, 'class="af-shop') !== false
        || strpos($page, "class='af-shop") !== false
        || strpos($page, 'data-af-shop-modal') !== false;
}

function af_advancedshop_strip_assets_from_html(string &$page): void
{
    if ($page === '') {
        return;
    }

    $basePattern = '[^"\']*?/inc/plugins/advancedfunctionality/addons/advancedshop/assets/[^"\']+';
    $page = (string)preg_replace(
        '~<script\b[^>]*\bsrc=("|\')' . $basePattern . '\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i',
        '',
        $page
    );
    $page = (string)preg_replace(
        '~<link\b[^>]*\bhref=("|\')' . $basePattern . '\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
        '',
        $page
    );
    $page = str_replace('<!-- af_advancedshop_assets -->', '', $page);
    $page = (string)preg_replace(
        '~<script>\s*window\.AFSHOP=window\.AFSHOP\|\|\{\};window\.AFSHOP\.endpointScript=.*?</script>\s*~i',
        '',
        $page
    );
}

function af_advancedshop_render_cart(): void
{
    global $db, $mybb, $lang, $headerinclude, $header, $footer;
    if ((int)($mybb->user['uid'] ?? 0) <= 0) { error_no_permission(); }
    $shop = af_advancedshop_current_shop();
    add_breadcrumb($lang->af_advancedshop_hub_title ?? 'Выбор магазина', af_advancedshop_url('shop'));
    add_breadcrumb($lang->af_advancedshop_cart_title ?? 'Cart', af_advancedshop_url('shop_cart', ['shop' => (string)$shop['code']]));
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], (int)$mybb->user['uid']);
    [$itemsHtml, $total] = af_advancedshop_build_cart_items($cart);
    $balance = (int)af_shop_get_balance((int)$mybb->user['uid'], (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits'));
    $can_checkout = $balance >= $total ? '' : 'disabled="disabled"';
    $msg = $balance >= $total ? '' : '<div class="af-shop-error">' . htmlspecialchars_uni($lang->af_advancedshop_error_not_enough_money ?? 'Not enough money') . '</div>';
    $assets = af_advancedshop_assets_html();
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    $shop_url = af_advancedshop_url('shop_category', ['shop' => (string)$shop['code']], true);
    $inventory_url = htmlspecialchars_uni(af_advancedshop_inventory_url((int)$mybb->user['uid']));
    $currencySlug = (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits');
    $currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol($currencySlug));
    $balance = af_advancedshop_money_format($balance);
    $total = af_advancedshop_money_format($total);
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_cart') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_get_or_create_cart(int $shopId, int $uid): array
{
    global $db;
    $row = $db->fetch_array($db->simple_select('af_shop_carts', '*', 'shop_id=' . $shopId . ' AND uid=' . $uid, ['limit' => 1]));
    if ($row) { return $row; }
    $db->insert_query('af_shop_carts', ['shop_id' => $shopId, 'uid' => $uid, 'updated_at' => TIME_NOW]);
    return $db->fetch_array($db->simple_select('af_shop_carts', '*', 'cart_id=' . (int)$db->insert_id(), ['limit' => 1]));
}

function af_advancedshop_build_cart_items(array $cart): array
{
    global $db;
    $itemsHtml = '';
    $total = 0;
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $titleRuCol = $kbCols['title_ru'] ?? null;
    $titleEnCol = $kbCols['title_en'] ?? null;
    $titleCol = $kbCols['title'] ?? null;
    $metaCol = $kbCols['meta_json'] ?? null;

    $select = [
        'ci.*',
        's.price',
        's.currency',
        's.kb_id',
        's.source_type',
        's.source_ref_id',
        ($titleRuCol ? 'e.' . $titleRuCol . ' AS kb_title_ru' : "'' AS kb_title_ru"),
        ($titleEnCol ? 'e.' . $titleEnCol . ' AS kb_title_en' : "'' AS kb_title_en"),
        ($titleCol ? 'e.' . $titleCol . ' AS kb_title' : "'' AS kb_title"),
        'e.`key` AS kb_key',
        ($metaCol ? 'e.' . $metaCol . ' AS kb_meta' : "'' AS kb_meta"),
    ];

    $q = $db->query("SELECT " . implode(', ', $select) . "
        FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_slots s ON(s.slot_id=ci.slot_id)
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
        WHERE ci.cart_id=" . (int)$cart['cart_id'] . " ORDER BY ci.id ASC");
    while ($row = $db->fetch_array($q)) {
        $item_id = (int)$row['id'];
        $slot_id = (int)$row['slot_id'];
        $qty = max(1, (int)$row['qty']);
        $price = (int)$row['price'];
        $sum = $qty * $price;
        $total += $sum;
        $sourceType = af_advancedshop_source_type_from_slot($row);
        $sourceRefId = af_advancedshop_source_ref_id_from_slot($row);
        $item_title_raw = '';
        $item_icon_raw = '';
        if ($sourceType === 'appearance') {
            $preset = af_advancedshop_appearance_fetch_preset($sourceRefId);
            $item_title_raw = trim((string)($preset['title'] ?? ''));
            if ($item_title_raw === '') {
                $item_title_raw = 'Preset #' . $sourceRefId;
            }
            $item_icon_raw = trim((string)($preset['preview_image'] ?? ''));
        } else {
            $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);
            $item_icon_raw = (string)($meta['ui']['icon_url'] ?? '');
            $item_title_raw = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
            if ($item_title_raw === '') { $item_title_raw = (string)($row['kb_title'] ?? ''); }
        }
        $item_icon = htmlspecialchars_uni($item_icon_raw);
        $item_title = htmlspecialchars_uni($item_title_raw);
        $price = af_advancedshop_money_format($price);
        $sum = af_advancedshop_money_format($sum);
        $currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol((string)($row['currency'] ?? 'credits')));
        eval('$itemsHtml .= "' . af_advancedshop_tpl('advancedshop_cart_item') . '";');
    }
    return [$itemsHtml, $total];
}

function af_advancedshop_add_to_cart(): void
{
    global $mybb, $db;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json_err('auth', 403); }
    $shop = af_advancedshop_current_shop();
    $slotId = (int)$mybb->get_input('slot');
    $qty = max(1, (int)$mybb->get_input('qty'));
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], $uid);
    $existing = $db->fetch_array($db->simple_select('af_shop_cart_items', '*', 'cart_id=' . (int)$cart['cart_id'] . ' AND slot_id=' . $slotId, ['limit' => 1]));
    if ($existing) {
        $db->update_query('af_shop_cart_items', ['qty' => (int)$existing['qty'] + $qty], 'id=' . (int)$existing['id']);
    } else {
        $db->insert_query('af_shop_cart_items', ['cart_id' => (int)$cart['cart_id'], 'slot_id' => $slotId, 'qty' => $qty]);
    }
    $db->update_query('af_shop_carts', ['updated_at' => TIME_NOW], 'cart_id=' . (int)$cart['cart_id']);
    af_advancedshop_json_ok();
}

function af_advancedshop_update_cart(): void
{
    global $mybb, $db;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json_err('auth', 403); }
    $itemId = (int)$mybb->get_input('item_id');
    $qty = (int)$mybb->get_input('qty');
    $item = $db->fetch_array($db->query("SELECT ci.* FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_carts c ON(c.cart_id=ci.cart_id)
        WHERE ci.id={$itemId} AND c.uid={$uid} LIMIT 1"));
    if (!$item) { af_advancedshop_json_err('not_found', 404); }
    if ($qty <= 0) {
        $db->delete_query('af_shop_cart_items', 'id=' . $itemId);
    } else {
        $db->update_query('af_shop_cart_items', ['qty' => $qty], 'id=' . $itemId);
    }
    af_advancedshop_json_ok();
}

function af_advancedshop_checkout(): void
{
    global $mybb, $db, $lang;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json_err('auth', 403); }
    if ((int)($mybb->settings['af_advancedinventory_enabled'] ?? 0) !== 1) {
        af_advancedshop_json_err('Покупка недоступна: Advanced Inventory отключён или не установлен.', 400);
    }
    af_advancedshop_write_inventory_schema_snapshot();
    $shop = af_advancedshop_current_shop();
    $currency = (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits');
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], $uid);
    [$items, $total] = af_advancedshop_checkout_collect_items((int)$cart['cart_id']);
    if (!$items || $total <= 0) { af_advancedshop_json_err('empty', 400); }

    $balance = af_shop_get_balance($uid, $currency);
    if ($balance < $total) {
        af_advancedshop_json_err($lang->af_advancedshop_error_not_enough_money ?? 'Not enough money', 400);
    }

    $db->write_query('START TRANSACTION');
    try {
        $orderPayload = [
            'shop_id' => (int)$shop['shop_id'],
            'uid' => $uid,
            'total' => $total,
            'currency' => $db->escape_string($currency),
            'created_at' => TIME_NOW,
            'status' => 'paid',
            'items_json' => $db->escape_string(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
        af_advancedshop_inv_debug('checkout_db_op', [
            'stage' => 'af_advancedshop_checkout',
            'op' => 'insert_query',
            'table' => 'af_shop_orders',
            'fields' => array_keys($orderPayload),
            'uid' => $uid,
            'payload' => $orderPayload,
        ]);
        $orderId = (int)$db->insert_query('af_shop_orders', $orderPayload);

        af_shop_sub_balance($uid, $currency, $total, 'shop_purchase', ['order_id' => $orderId, 'shop' => $shop['code']]);

        foreach ($items as $item) {
            af_advancedshop_inv_debug('checkout_grant_start', ['uid' => $uid, 'item' => $item]);
            af_advancedshop_grant_inventory_item($uid, $item);
        }

        $db->delete_query('af_shop_cart_items', 'cart_id=' . (int)$cart['cart_id']);
        $db->write_query('COMMIT');
    } catch (mysqli_sql_exception $e) {
        $db->write_query('ROLLBACK');
        af_advancedshop_inv_debug('checkout_failed', [
            'stage' => 'af_advancedshop_checkout',
            'uid' => $uid,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'items' => $items,
        ]);
        af_advancedshop_json_err('Покупка не завершена: ' . $e->getMessage(), 500);
    } catch (Throwable $e) {
        $db->write_query('ROLLBACK');
        af_advancedshop_inv_debug('checkout_failed', [
            'stage' => 'af_advancedshop_checkout',
            'uid' => $uid,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'items' => $items,
        ]);
        af_advancedshop_json_err('Покупка не завершена: ' . $e->getMessage(), 500);
    }

    $balanceAfter = max(0, (int)$balance - (int)$total);
    af_advancedshop_json_ok([
        'checkout' => [
            'total_minor' => (int)$total,
            'total_major' => af_advancedshop_money_format((int)$total),
            'currency' => $currency,
            'currency_symbol' => af_advancedshop_currency_symbol($currency),
            'order_id' => (int)$orderId,
            'balance_minor' => $balanceAfter,
            'balance_major' => af_advancedshop_money_format($balanceAfter),
        ],
        'links' => [
            'shop' => af_advancedshop_url('shop_category', ['shop' => (string)$shop['code']]),
            'inventory' => af_advancedshop_inventory_url($uid),
        ],
    ]);
}


function af_advancedshop_inv_debug(string $event, array $context = []): void
{
    $line = '[AF-ADVSHOP][' . date('c') . '][' . $event . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @error_log($line);
    if (defined('AF_ADVSHOP_DEBUG_LOG')) {
        @file_put_contents(AF_ADVSHOP_DEBUG_LOG, $line . "\n", FILE_APPEND);
    }
}

function af_advancedshop_inv_payload_slot(array $profile): string
{
    $slot = trim((string)($profile['slot'] ?? ''));
    if (in_array($slot, ['equipment', 'resources', 'pets', 'customization'], true)) {
        return $slot;
    }

    $kind = mb_strtolower(trim((string)($profile['item_kind'] ?? '')));
    if (in_array($kind, ['weapon', 'armor', 'ammo', 'consumable'], true)) {
        return 'equipment';
    }
    if (in_array($kind, ['loot', 'chests', 'stones', 'resource', 'resources'], true)) {
        return 'resources';
    }
    if (in_array($kind, ['pet', 'pets', 'egg', 'eggs'], true)) {
        return 'pets';
    }
    if (in_array($kind, ['profile', 'postbit', 'sheet', 'customization'], true)) {
        return 'customization';
    }

    $tags = is_array($profile['tags'] ?? null) ? $profile['tags'] : [];
    $tags = array_map(static function ($v) { return mb_strtolower(trim((string)$v)); }, $tags);
    if (array_intersect($tags, ['weapon', 'armor', 'ammo', 'consumable'])) {
        return 'equipment';
    }
    if (array_intersect($tags, ['loot', 'chests', 'stones', 'resource', 'resources'])) {
        return 'resources';
    }

    return 'resources';
}

function af_advancedshop_inv_payload_subtype(array $profile): string
{
    $sub = mb_strtolower(trim((string)($profile['item_kind'] ?? '')));
    if ($sub !== '') {
        return $sub;
    }
    return 'loot';
}

function af_advancedshop_json_decode_assoc(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }
    $decoded = @json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function af_advancedshop_slot_lookup(int $slotId): array
{
    global $db;
    if ($slotId <= 0) {
        return [];
    }

    $select = 's.slot_id, s.cat_id, s.kb_type, s.kb_key, s.meta_json';
    if ($db->field_exists('title', 'af_shop_categories')) {
        $select .= ', c.title AS cat_title';
    }

    $query = $db->query("SELECT {$select}
        FROM " . TABLE_PREFIX . "af_shop_slots s
        LEFT JOIN " . TABLE_PREFIX . "af_shop_categories c ON(c.cat_id=s.cat_id)
        WHERE s.slot_id=" . (int)$slotId . "
        LIMIT 1");

    return (array)$db->fetch_array($query);
}

function af_advancedshop_detect_inventory_target_from_kb_meta(array $meta): array
{
    $rulesItem = is_array($meta['rules']['item'] ?? null) ? (array)$meta['rules']['item'] : [];
    $supportedKinds = ['weapon', 'armor', 'ammo', 'consumable'];

    $kind = mb_strtolower(trim((string)($rulesItem['item_kind'] ?? '')));
    $kindSource = 'rules.item.item_kind';

    if ($kind === '') {
        $kind = mb_strtolower(trim((string)($rulesItem['item_type'] ?? '')));
        $kindSource = 'rules.item.item_type';
    }

    if ($kind === '') {
        $tags = is_array($rulesItem['tags'] ?? null) ? $rulesItem['tags'] : [];
        foreach ($tags as $tag) {
            $tagNorm = mb_strtolower(trim((string)$tag));
            if (in_array($tagNorm, $supportedKinds, true)) {
                $kind = $tagNorm;
                $kindSource = 'rules.item.tags';
                break;
            }
        }
    }

    if ($kind === '') {
        $kindSource = 'fallback';
    }

    $slot = 'resources';
    $subtype = '';
    if (in_array($kind, $supportedKinds, true)) {
        $slot = 'equipment';
        $subtype = $kind;
    }

    if ($kind === 'weapon') {
        $slot = 'equipment';
        $subtype = 'weapon';
    }

    return [
        'slot' => $slot,
        'subtype' => $subtype,
        'kind_detected' => $kind,
        'kind_source' => $kindSource,
    ];
}

function af_advancedshop_map_inventory_target(array $kbMeta, array $shopItem): array
{
    return af_advancedshop_detect_inventory_target_from_kb_meta($kbMeta);
}

function af_advancedshop_resolve_inventory_target(array $slotRow, array $kbProfile, array $kbRow): array
{
    $kbMeta = af_advancedshop_json_decode_assoc((string)($kbRow['kb_meta_json'] ?? ''));
    $mapped = af_advancedshop_detect_inventory_target_from_kb_meta($kbMeta);
    return [
        'slot' => (string)$mapped['slot'],
        'subtype' => (string)$mapped['subtype'],
        'source' => (string)($mapped['kind_source'] ?? 'kb_meta'),
    ];
}

function af_advancedshop_checkout_collect_items(int $cartId): array
{
    global $db;
    $items = [];
    $total = 0;
    $q = $db->query("SELECT ci.qty, s.slot_id, s.shop_id, sh.code AS shop_code, s.cat_id, s.source_type, s.source_ref_id, s.kb_id, s.kb_type, s.kb_key, s.meta_json, s.price, s.currency
        FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_slots s ON(s.slot_id=ci.slot_id)
        INNER JOIN " . TABLE_PREFIX . af_advancedshop_shops_table() . " sh ON(sh.shop_id=s.shop_id)
        WHERE ci.cart_id={$cartId} AND s.enabled=1");
    while ($row = $db->fetch_array($q)) {
        $qty = max(1, (int)$row['qty']);
        $price = max(0, (int)$row['price']);
        $items[] = [
            'slot_id' => (int)$row['slot_id'],
            'shop_id' => (int)$row['shop_id'],
            'shop_code' => (string)($row['shop_code'] ?? ''),
            'cat_id' => (int)$row['cat_id'],
            'source_type' => af_advancedshop_source_type_from_slot($row),
            'source_ref_id' => af_advancedshop_source_ref_id_from_slot($row),
            'kb_id' => (int)$row['kb_id'],
            'kb_type' => (string)($row['kb_type'] ?? 'item'),
            'kb_key' => (string)($row['kb_key'] ?? ''),
            'slot_meta_json' => (string)($row['meta_json'] ?? ''),
            'qty' => $qty,
            'price_each' => $price,
            'currency' => (string)$row['currency'],
        ];
        $total += $qty * $price;
    }
    return [$items, $total];
}

function af_advancedshop_apply_shop_map_target(array $target, array $item, bool $hasKb): array
{
    $shopCode = trim((string)($item['shop_code'] ?? ''));
    $catId = (int)($item['cat_id'] ?? 0);
    if ($shopCode === '' || !function_exists('af_advinv_shop_map_resolve')) {
        return $target;
    }

    $map = af_advinv_shop_map_resolve($shopCode, $catId);
    if (!$map) {
        return $target;
    }

    $mappedEntity = trim((string)($map['entity'] ?? ''));
    if ($mappedEntity !== '') {
        $target['slot'] = $mappedEntity;
    }

    $defaultSubtype = trim((string)($map['default_subtype'] ?? ''));
    if ($hasKb) {
        if (trim((string)($target['subtype'] ?? '')) === '' && $defaultSubtype !== '') {
            $target['subtype'] = $defaultSubtype;
        }
    } elseif ($defaultSubtype !== '') {
        $target['subtype'] = $defaultSubtype;
    }

    $target['mapped_rule_id'] = (int)($map['id'] ?? 0);
    $target['mapped_cat_id'] = (int)($map['cat_id'] ?? 0);
    $target['kind_source'] = $hasKb ? 'shop_map_kb' : 'shop_map_nonkb';

    return $target;
}

function af_advancedshop_grant_inventory_item(int $uid, array $item): void
{
    global $db;
    af_advancedshop_ensure_inventory_grant_available($uid);
    af_advancedshop_inv_debug('grant_stage', ['step' => 'before_legacy_grant', 'uid' => $uid]);
    af_advancedshop_grant_legacy_inventory_bypass($uid, $item);

    $sourceType = af_advancedshop_normalize_source_type((string)($item['source_type'] ?? 'kb'));
    $sourceRefId = (int)($item['source_ref_id'] ?? 0);

    if ($sourceType === 'appearance') {
        $preset = af_advancedshop_appearance_fetch_preset($sourceRefId);
        if (!$preset || (int)($preset['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('Appearance preset недоступен.');
        }
        $preset['target_key'] = af_advancedshop_appearance_validate_target((string)($preset['target_key'] ?? ''));

        $settingsRaw = (string)($preset['settings_json'] ?? '');
        $metaPayload = [
            'source_type' => 'appearance',
            'appearance' => [
                'preset_id' => (int)$preset['id'],
                'target_key' => (string)$preset['target_key'],
                'preview_image' => (string)$preset['preview_image'],
                'settings_json' => $settingsRaw,
            ],
        ];

        $payload = [
            'slot' => 'customization',
            'subtype' => str_replace(':', '__', (string)$preset['target_key']),
            'kb_type' => 'appearance',
            'kb_key' => 'appearance:' . (int)$preset['id'],
            'qty' => max(1, (int)($item['qty'] ?? 1)),
            'meta_json' => json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'title' => trim((string)($preset['title'] ?? ('Preset #' . (int)$preset['id']))),
            'icon' => trim((string)($preset['preview_image'] ?? '')),
        ];

        try {
            $invId = (int)af_inv_add_item($uid, $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('Покупка не завершена: ' . $e->getMessage(), 0, $e);
        }
        if ($invId <= 0) {
            throw new RuntimeException('Не удалось выдать appearance-предмет в инвентарь.');
        }
        return;
    }

    $kbId = (int)($item['kb_id'] ?? 0);
    $kbTypeFromItem = trim((string)($item['kb_type'] ?? ''));
    $kbKeyFromItem = trim((string)($item['kb_key'] ?? ''));

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $kb = [];
    if ($kbId > 0) {
        $kbTableSql = af_advancedshop_kb_table();
        $select = [$kbIdCol . ' AS kb_id'];
        if (!empty($kbCols['type'])) { $select[] = ($kbCols['type'] === 'type' ? '`type`' : $kbCols['type']) . ' AS kb_type'; }
        if (!empty($kbCols['key'])) { $select[] = ($kbCols['key'] === 'key' ? '`key`' : $kbCols['key']) . ' AS kb_key'; }
        if (!empty($kbCols['meta_json'])) { $select[] = $kbCols['meta_json'] . ' AS kb_meta_json'; }

        $kbTableName = af_advancedshop_kb_table_entries();
        if ($kbTableName === '') {
            af_advancedshop_inv_debug('checkout_kb_lookup_warning', ['uid' => $uid, 'kb_id' => $kbId, 'reason' => 'kb_table_unresolved']);
        }

        $hasTitleRu = $kbTableName !== '' && $db->field_exists('title_ru', $kbTableName);
        $hasTitleEn = $kbTableName !== '' && $db->field_exists('title_en', $kbTableName);
        $hasTitle = $kbTableName !== '' && $db->field_exists('title', $kbTableName);
        $hasNameRu = $kbTableName !== '' && $db->field_exists('name_ru', $kbTableName);
        $hasNameEn = $kbTableName !== '' && $db->field_exists('name_en', $kbTableName);
        $hasName = $kbTableName !== '' && $db->field_exists('name', $kbTableName);

        if ($hasTitleRu && $hasTitleEn) {
            $select[] = "COALESCE(NULLIF(title_ru,''), NULLIF(title_en,''), '') AS kb_title";
        } elseif ($hasTitle) {
            $select[] = "COALESCE(NULLIF(title,''), '') AS kb_title";
        } elseif ($hasNameRu && $hasNameEn) {
            $select[] = "COALESCE(NULLIF(name_ru,''), NULLIF(name_en,''), '') AS kb_title";
        } elseif ($hasName) {
            $select[] = "COALESCE(NULLIF(name,''), '') AS kb_title";
        }

        if ($kbTableName !== '' && $db->field_exists('icon_url', $kbTableName)) {
            $select[] = 'icon_url AS kb_icon';
        }

        af_advancedshop_inv_debug('checkout_db_op', [
            'stage' => 'af_advancedshop_grant_inventory_item',
            'op' => 'select',
            'table' => af_advancedshop_kb_table(),
            'fields' => $select,
            'uid' => $uid,
            'kb_id' => $kbId,
        ]);

        if ($kbTableSql !== '') {
            try {
                $kb = (array)$db->fetch_array($db->query("SELECT " . implode(',', $select) . " FROM " . $kbTableSql . " WHERE " . $kbIdCol . "=" . $kbId . " LIMIT 1"));
            } catch (Throwable $e) {
                $kb = [];
                af_advancedshop_inv_debug('checkout_kb_lookup_warning', [
                    'uid' => $uid,
                    'kb_id' => $kbId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        } else {
            af_advancedshop_inv_debug('checkout_kb_lookup_warning', ['uid' => $uid, 'kb_id' => $kbId, 'reason' => 'kb_table_unresolved']);
        }

        if (!$kb) {
            af_advancedshop_inv_debug('checkout_kb_lookup_warning', ['uid' => $uid, 'kb_id' => $kbId, 'reason' => 'kb_row_missing_or_lookup_failed']);
        }
    }

    $metaJson = '';
    if (is_string($kb['kb_meta_json'] ?? null) && trim((string)$kb['kb_meta_json']) !== '') {
        $metaJson = (string)$kb['kb_meta_json'];
    }

    $metaPayload = af_advancedshop_json_decode_assoc($metaJson);
    $hasKbIdentity = $kbId > 0 || ($kbTypeFromItem !== '' && $kbKeyFromItem !== '');
    $hasKbData = $hasKbIdentity && ($metaPayload !== [] || !empty($kb));
    $target = $hasKbData ? af_advancedshop_detect_inventory_target_from_kb_meta($metaPayload) : ['slot' => 'resources', 'subtype' => '', 'kind_detected' => '', 'kind_source' => 'nonkb'];

    if ($hasKbData && (string)($target['kind_detected'] ?? '') === 'weapon') {
        $target['slot'] = 'equipment';
        $target['subtype'] = 'weapon';
    }

    $target = af_advancedshop_apply_shop_map_target($target, $item, $hasKbData);

    if (!$hasKbData && trim((string)($target['subtype'] ?? '')) === '') {
        af_advancedshop_inv_debug('checkout_grant_blocked', [
            'uid' => $uid,
            'reason' => 'nonkb_subtype_missing',
            'item' => $item,
            'mapped_rule_id' => (int)($target['mapped_rule_id'] ?? 0),
        ]);
        throw new RuntimeException('Не удалось выдать non-KB товар: в правиле моста требуется default_subtype.');
    }

    af_advancedshop_inv_debug('grant_classify', [
        'uid' => $uid,
        'kb_key' => (string)($kb['kb_key'] ?? $kbKeyFromItem),
        'kind_detected' => (string)($target['kind_detected'] ?? ''),
        'kind_source' => (string)($target['kind_source'] ?? ''),
        'slot' => (string)$target['slot'],
        'subtype' => (string)$target['subtype'],
        'shop_id' => (int)($item['shop_id'] ?? 0),
        'cat_id' => (int)($item['cat_id'] ?? 0),
        'mapped_rule_id' => (int)($target['mapped_rule_id'] ?? 0),
    ]);

    if ($hasKbData) {
        $metaPayload['shop'] = [
            'slot_id' => (int)($item['slot_id'] ?? 0),
            'kb_id' => (int)($item['kb_id'] ?? 0),
            'price_each' => (int)($item['price_each'] ?? 0),
        ];
        $metaJson = json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $metaJson;
    } else {
        $metaJson = (string)($item['slot_meta_json'] ?? '');
    }

    $payload = [
        'slot' => (string)$target['slot'],
        'subtype' => (string)$target['subtype'],
        'kb_type' => (string)($kb['kb_type'] ?? ($kbTypeFromItem !== '' ? $kbTypeFromItem : 'item')),
        'kb_key' => (string)($kb['kb_key'] ?? $kbKeyFromItem),
        'qty' => max(1, (int)($item['qty'] ?? 1)),
        'meta_json' => $metaJson,
    ];

    $kbTitle = trim((string)($kb['kb_title'] ?? ''));
    $kbIcon = trim((string)($kb['kb_icon'] ?? ''));
    if ($kbTitle !== '') {
        $payload['title'] = $kbTitle;
    }
    if ($kbIcon !== '') {
        $payload['icon'] = $kbIcon;
    }

    if ($hasKbData && (trim((string)$payload['kb_type']) === '' || trim((string)$payload['kb_key']) === '')) {
        af_advancedshop_inv_debug('checkout_grant_blocked', ['uid' => $uid, 'reason' => 'kb_identity_missing', 'item' => $item, 'kb' => $kb]);
        throw new RuntimeException('Не удалось выдать предмет: отсутствуют kb_type/kb_key.');
    }

    af_advancedshop_inv_debug('grant_payload_ready', [
        'uid' => $uid,
        'slot' => (string)$payload['slot'],
        'subtype' => (string)$payload['subtype'],
        'kb_type' => (string)$payload['kb_type'],
        'kb_key' => (string)$payload['kb_key'],
        'qty' => (int)$payload['qty'],
        'slot_id' => (int)($item['slot_id'] ?? 0),
        'target_source' => (string)($target['kind_source'] ?? ($target['source'] ?? '')),
        'title_present' => isset($payload['title']) ? 1 : 0,
        'icon_present' => isset($payload['icon']) ? 1 : 0,
    ]);

    af_advancedshop_inv_debug('checkout_grant_payload', ['uid' => $uid, 'payload' => $payload]);
    try {
        $invId = (int)af_inv_add_item($uid, $payload);
    } catch (Throwable $e) {
        af_advancedshop_inv_debug('checkout_failed', [
            'stage' => 'af_advancedshop_grant_inventory_item',
            'uid' => $uid,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'table' => TABLE_PREFIX . 'af_advinv_items',
            'fields' => array_keys($payload),
            'payload' => $payload,
        ]);
        throw new RuntimeException('Покупка не завершена: ' . $e->getMessage(), 0, $e);
    }
    af_advancedshop_inv_debug('checkout_grant_done', ['uid' => $uid, 'inv_item_id' => $invId]);
    if ($invId <= 0) {
        throw new RuntimeException('Не удалось выдать предмет в инвентарь.');
    }
}

function af_advancedshop_ensure_inventory_grant_available(int $uid): void
{
    if (function_exists('af_inv_add_item')) {
        return;
    }

    $invBootstrap = AF_ADDONS . 'advancedinventory/advancedinventory.php';
    if (is_file($invBootstrap)) {
        require_once $invBootstrap;
    }

    if (!function_exists('af_inv_add_item')) {
        af_advancedshop_inv_debug('checkout_grant_blocked', [
            'uid' => $uid,
            'reason' => 'af_inv_add_item_missing_after_bootstrap',
            'bootstrap' => $invBootstrap,
        ]);
        throw new RuntimeException('Покупка недоступна: функция af_inv_add_item не найдена.');
    }
}

function af_advancedshop_grant_legacy_inventory_bypass(int $uid, array $item): void
{
    af_advancedshop_inv_debug('grant_stage', [
        'step' => 'legacy_grant_disabled',
        'uid' => $uid,
        'slot_id' => (int)($item['slot_id'] ?? 0),
    ]);
}

function af_advancedshop_write_inventory_schema_snapshot(): void
{
    if (function_exists('af_advancedinventory_write_schema_markdown')) {
        af_advancedinventory_write_schema_markdown();
        return;
    }

    $invBootstrap = AF_ADDONS . 'advancedinventory/advancedinventory.php';
    if (is_file($invBootstrap)) {
        require_once $invBootstrap;
    }

    if (function_exists('af_advancedinventory_write_schema_markdown')) {
        af_advancedinventory_write_schema_markdown();
    }
}

function af_advancedshop_render_manage(): void
{
    global $lang, $headerinclude, $header, $footer, $db, $mybb;
    if (!af_advancedshop_can_manage()) { error_no_permission(); }
    $shop = af_advancedshop_current_shop();
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    add_breadcrumb($lang->af_advancedshop_manage_title ?? 'Manage Shop', af_advancedshop_manage_url((string)$shop['code']));

    $flat = [];
    $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'parent_id ASC, sortorder ASC, title ASC, cat_id ASC']);
    while ($cat = $db->fetch_array($q)) {
        $flat[] = $cat;
    }
    $ordered = af_advancedshop_category_tree_rows($flat);
    $descendantsMap = af_advancedshop_category_descendants_map($flat);

    $parentMap = [0 => '—'];
    foreach ($flat as $cat) {
        $parentMap[(int)$cat['cat_id']] = (string)$cat['title'];
    }

    $rows = '';
    foreach ($ordered as $catWrap) {
        $cat = $catWrap['row'];
        $depth = (int)$catWrap['depth'];
        $cat_id = (int)$cat['cat_id'];
        $cat_title = htmlspecialchars_uni((string)$cat['title']);
        $cat_description = htmlspecialchars_uni((string)$cat['description']);
        $cat_parent = (int)$cat['parent_id'];
        $cat_parent_title = htmlspecialchars_uni((string)($parentMap[$cat_parent] ?? '—'));
        $cat_enabled = (int)$cat['enabled'];
        $cat_enabled_checked = $cat_enabled ? 'checked="checked"' : '';
        $cat_sortorder = (int)$cat['sortorder'];
        $slots_url = af_advancedshop_manage_url((string)$shop['code'], 'slots', $cat_id, true);
        $cat_depth = $depth;
        $blockedParents = $descendantsMap[$cat_id] ?? [];
        $blockedParents[$cat_id] = true;
        $cat_parent_options = af_advancedshop_parent_options_html($ordered, $cat_parent, $blockedParents);
        eval('$rows .= "' . af_advancedshop_tpl('advancedshop_manage_category_row') . '";');
    }

    $assets = af_advancedshop_assets_html();
    $health_block = '<div class="af-shop-health" id="af-shop-health">'
        . '<strong>AF Shop health</strong> '
        . '<span data-health-js>JS loaded: no</span> '
        . '<span data-health-postkey>postKey present: no</span> '
        . '<span data-health-api>API ping: ...</span>'
        . '</div>';
    eval('$categories_table = "' . af_advancedshop_tpl('advancedshop_manage_categories') . '";');
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_manage') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}
function af_advancedshop_manage_categories(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();
    $flat = [];
    $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'sortorder ASC, title ASC, cat_id ASC']);
    while ($r = $db->fetch_array($q)) {
        $flat[] = $r;
    }
    $rows = [];
    foreach (af_advancedshop_category_tree_rows($flat) as $item) {
        $r = $item['row'];
        $rows[] = [
            'cat_id' => (int)$r['cat_id'],
            'title' => (string)$r['title'],
            'description' => (string)$r['description'],
            'parent_id' => (int)$r['parent_id'],
            'enabled' => (int)$r['enabled'],
            'sortorder' => (int)$r['sortorder'],
            'depth' => (int)$item['depth'],
        ];
    }
    af_advancedshop_json_ok(['categories' => $rows]);
}
function af_advancedshop_manage_category_create(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
        af_advancedshop_json_err('Invalid post key', 403);
    }

    $shop = af_advancedshop_current_shop();
    $title = trim((string)$mybb->get_input('title'));
    if ($title === '') {
        $title = trim((string)$mybb->get_input('name'));
    }
    if ($title === '') { af_advancedshop_json_err('Title required', 422); }
    if (my_strlen($title) > 255) { af_advancedshop_json_err('Title too long', 422); }

    $parentId = (int)$mybb->get_input('parent_id');
    if ($parentId <= 0) { $parentId = (int)$mybb->get_input('parent'); }
    if ($parentId <= 0) { $parentId = (int)$mybb->get_input('parentid'); }
    $parentId = max(0, $parentId);
    $sortorder = (int)$mybb->get_input('sortorder');
    $catId = (int)$db->insert_query('af_shop_categories', [
        'shop_id' => (int)$shop['shop_id'],
        'parent_id' => $parentId,
        'title' => $db->escape_string($title),
        'description' => $db->escape_string((string)$mybb->get_input('description')),
        'sortorder' => $sortorder,
        'enabled' => 1,
    ]);

    af_advancedshop_json_ok([
        'cat' => [
            'cat_id' => $catId,
            'title' => $title,
            'description' => (string)$mybb->get_input('description'),
            'parent_id' => $parentId,
            'enabled' => 1,
            'sortorder' => $sortorder,
        ],
    ]);
}

function af_advancedshop_manage_category_update(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    af_advancedshop_assert_post_key();
    $shop = af_advancedshop_current_shop();

    $catId = (int)$mybb->get_input('cat_id');
    if ($catId <= 0) { af_advancedshop_json_err('Category not found', 404); }
    $existing = $db->fetch_array($db->simple_select('af_shop_categories', '*', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$existing) { af_advancedshop_json_err('Category not found', 404); }

    $title = trim((string)$mybb->get_input('title'));
    if ($title === '') { af_advancedshop_json_err('Title required', 422); }
    if (my_strlen($title) > 255) { af_advancedshop_json_err('Title too long', 422); }
    $parentId = max(0, (int)$mybb->get_input('parent_id'));
    if ($parentId <= 0) { $parentId = max(0, (int)$mybb->get_input('parent')); }
    if ($parentId <= 0) { $parentId = max(0, (int)$mybb->get_input('parentid')); }
    if ($parentId === $catId) { af_advancedshop_json_err('Invalid parent category', 422); }
    if ($parentId > 0) {
        $flat = [];
        $q = $db->simple_select('af_shop_categories', 'cat_id,parent_id', 'shop_id=' . (int)$shop['shop_id']);
        while ($row = $db->fetch_array($q)) {
            $flat[] = $row;
        }
        $descendantsMap = af_advancedshop_category_descendants_map($flat);
        if (!empty($descendantsMap[$catId][$parentId])) {
            af_advancedshop_json_err('Invalid parent category', 422);
        }
    }

    $db->update_query('af_shop_categories', [
        'parent_id' => $parentId,
        'title' => $db->escape_string($title),
        'description' => $db->escape_string((string)$mybb->get_input('description')),
        'sortorder' => (int)$mybb->get_input('sortorder'),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
    ], 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);

    af_advancedshop_json_ok();
}

function af_advancedshop_manage_category_delete(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    af_advancedshop_assert_post_key();
    $shop = af_advancedshop_current_shop();

    $catId = (int)$mybb->get_input('cat_id');
    if ($catId <= 0) { af_advancedshop_json_err('Category not found', 404); }
    $existing = $db->fetch_array($db->simple_select('af_shop_categories', 'cat_id', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$existing) { af_advancedshop_json_err('Category not found', 404); }

    $hasChildren = (int)$db->fetch_field($db->simple_select('af_shop_categories', 'COUNT(*) AS c', 'parent_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']), 'c');
    if ($hasChildren > 0) {
        af_advancedshop_json_err('Category has children', 409);
    }

    $hasSlots = (int)$db->fetch_field($db->simple_select('af_shop_slots', 'COUNT(*) AS c', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']), 'c');
    if ($hasSlots > 0) {
        af_advancedshop_json_err('Category in use', 409);
    }

    $db->delete_query('af_shop_categories', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);
    af_advancedshop_json_ok();
}

function af_advancedshop_manage_slots(): void
{
    global $mybb, $db, $headerinclude, $header, $footer, $lang;

    if (!af_advancedshop_can_manage()) {
        af_advancedshop_json_err('Not allowed', 403);
    }

    $shop = af_advancedshop_current_shop();
    $catId = af_advancedshop_manage_cat_id_input();
    $do = trim((string)$mybb->get_input('do'));

    if (strtolower($mybb->request_method) === 'get' && $do === '') {
        if ($catId <= 0) {
            error_no_permission();
        }

        $category = $db->fetch_array(
            $db->simple_select(
                'af_shop_categories',
                '*',
                'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'],
                ['limit' => 1]
            )
        );

        if (!$category) {
            error_no_permission();
        }

        $shop_code = htmlspecialchars_uni((string)$shop['code']);
        $cat_id = $catId;
        $category_id = $catId;
        $category_title_raw = (string)$category['title'];
        $category_title = htmlspecialchars_uni($category_title_raw);

        add_breadcrumb($lang->af_advancedshop_manage_title ?? 'Manage Shop', af_advancedshop_manage_url((string)$shop['code']));
        add_breadcrumb($lang->af_advancedshop_manage_categories ?? 'Categories', af_advancedshop_manage_url((string)$shop['code'], 'categories'));
        add_breadcrumb($lang->af_advancedshop_manage_slots ?? 'Slots', af_advancedshop_manage_url((string)$shop['code'], 'slots', $catId));
        add_breadcrumb($category_title_raw, af_advancedshop_manage_url((string)$shop['code'], 'slots', $catId));

        $manage_url = htmlspecialchars_uni(af_advancedshop_manage_url((string)$shop['code']));
        $assets = af_advancedshop_assets_html();

        eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_manage_slots') . '";');
        eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
        output_page($page);
        exit;
    }

    if (strtolower($mybb->request_method) === 'get' && ($do === 'list' || $do === '')) {
        if ($catId <= 0) {
            af_advancedshop_json_err('Category not found', 404);
        }

        $rows = [];
        $kbCols = af_advancedshop_kb_cols();
        $kbIdCol = $kbCols['id'] ?? 'id';
        $titleSelect = [];

        if (!empty($kbCols['title_ru'])) {
            $titleSelect[] = 'e.' . $kbCols['title_ru'] . ' AS kb_title_ru';
        }
        if (!empty($kbCols['title_en'])) {
            $titleSelect[] = 'e.' . $kbCols['title_en'] . ' AS kb_title_en';
        }
        if (empty($titleSelect) && !empty($kbCols['title'])) {
            $titleSelect[] = 'e.' . $kbCols['title'] . ' AS kb_title';
        }
        if (!empty($kbCols['meta_json'])) {
            $titleSelect[] = 'e.' . $kbCols['meta_json'] . ' AS kb_meta';
        }
        if (!empty($kbCols['type'])) {
            $titleSelect[] = 'e.' . ($kbCols['type'] === 'type' ? '`type`' : $kbCols['type']) . ' AS kb_type';
        }
        if (!empty($kbCols['key'])) {
            $titleSelect[] = 'e.' . ($kbCols['key'] === 'key' ? '`key`' : $kbCols['key']) . ' AS kb_key';
        }
        if ($db->field_exists('kb_type', 'af_shop_slots')) {
            $titleSelect[] = 's.kb_type AS slot_kb_type';
        }
        if ($db->field_exists('kb_key', 'af_shop_slots')) {
            $titleSelect[] = 's.kb_key AS slot_kb_key';
        }
        if (!$titleSelect) {
            $titleSelect[] = "'' AS kb_title";
        }

        $q = $db->query(
            "SELECT s.*, " . implode(', ', $titleSelect) . "
            FROM " . TABLE_PREFIX . "af_shop_slots s
            LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
            WHERE s.shop_id=" . (int)$shop['shop_id'] . " AND s.cat_id=" . $catId . "
            ORDER BY s.sortorder ASC, s.slot_id DESC"
        );

        while ($r = $db->fetch_array($q)) {
            $title = af_advancedshop_pick_lang((string)($r['kb_title_ru'] ?? ''), (string)($r['kb_title_en'] ?? ''));
            if ($title === '') {
                $title = (string)($r['kb_title'] ?? '');
            }

            $meta = @json_decode((string)($r['kb_meta'] ?? '{}'), true);
            $profile = af_advancedshop_kb_item_profile($r);
            $sourceType = af_advancedshop_source_type_from_slot($r);
            $sourceRefId = af_advancedshop_source_ref_id_from_slot($r);
            $appearancePreset = [];

            if ($sourceType === 'appearance') {
                $appearancePreset = af_advancedshop_appearance_fetch_preset($sourceRefId);
                $title = trim((string)($appearancePreset['title'] ?? $title));
                $meta['ui']['icon_url'] = (string)($appearancePreset['preview_image'] ?? ($meta['ui']['icon_url'] ?? ''));
            }

            $appearanceTargetKey = (string)($appearancePreset['target_key'] ?? '');

            $rows[] = [
                'slot_id' => (int)$r['slot_id'],
                'source_type' => $sourceType,
                'source_ref_id' => $sourceRefId,
                'kb_id' => (int)$r['kb_id'],
                'kb_type' => (string)($r['slot_kb_type'] ?? ($r['kb_type'] ?? 'item')),
                'kb_key' => (string)($r['slot_kb_key'] ?? ($r['kb_key'] ?? '')),
                'appearance_target' => $appearanceTargetKey,
                'appearance_target_label' => af_advancedshop_appearance_target_label($appearanceTargetKey),
                'appearance_group' => af_advancedshop_appearance_group_for_target($appearanceTargetKey),
                'appearance_group_label' => (string)(af_advancedshop_appearance_supported_group_labels()[af_advancedshop_appearance_group_for_target($appearanceTargetKey)] ?? af_advancedshop_appearance_group_for_target($appearanceTargetKey)),
                'appearance_preset_id' => (int)($appearancePreset['id'] ?? 0),
                'appearance_preset_slug' => (string)($appearancePreset['slug'] ?? ''),
                'appearance_preset_title' => (string)($appearancePreset['title'] ?? ''),
                'appearance_preview_image' => (string)($appearancePreset['preview_image'] ?? ''),
                'appearance_enabled' => (int)($appearancePreset['enabled'] ?? 0),
                'title' => $title,
                'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
                'rarity' => (string)$profile['rarity'],
                'rarity_label' => af_advancedshop_rarity_label((string)$profile['rarity']),
                'rarity_class' => 'af-rarity-' . (string)$profile['rarity'],
                'debug_rarity_raw' => (string)$profile['rarity_raw'],
                'debug_rarity_final' => (string)$profile['rarity'],
                'debug_data_json_present' => (string)$profile['data_json_present'],
                'price' => (int)$r['price'],
                'price_major' => af_advancedshop_money_format((int)$r['price']),
                'cat_id' => (int)$r['cat_id'],
                'currency' => (string)$r['currency'],
                'stock' => (int)$r['stock'],
                'limit_per_user' => (int)$r['limit_per_user'],
                'sortorder' => (int)$r['sortorder'],
                'enabled' => (int)$r['enabled'],
            ];
        }

        af_advancedshop_json_ok(['rows' => $rows]);
    }

    af_advancedshop_json_err('unsupported', 400);
}

function af_advancedshop_manage_slot_create(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $catId = (int)$mybb->get_input('cat_id');
    if ($catId <= 0) { af_advancedshop_json_err('cat_id required', 422); }
    $cat = $db->fetch_array($db->simple_select('af_shop_categories', 'cat_id', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$cat) { af_advancedshop_json_err('Category not found', 404); }

    $sourceType = af_advancedshop_normalize_source_type((string)$mybb->get_input('source_type'));
    try {
        $sourcePayload = af_advancedshop_slot_payload_from_source($sourceType, [
            'source_ref_id' => (int)$mybb->get_input('source_ref_id'),
            'preset_id' => (int)$mybb->get_input('preset_id'),
            'preset_slug' => trim((string)$mybb->get_input('preset_slug')),
            'kb_id' => (int)$mybb->get_input('kb_id'),
            'kb_type' => (string)$mybb->get_input('kb_type'),
            'kb_key' => trim((string)$mybb->get_input('kb_key')),
        ]);
    } catch (RuntimeException $e) {
        af_advancedshop_json_err($e->getMessage(), 422);
    }

    $duplicateWhere = 'shop_id=' . (int)$shop['shop_id'] . ' AND cat_id=' . $catId . " AND source_type='" . $db->escape_string($sourcePayload['source_type']) . "' AND source_ref_id=" . (int)$sourcePayload['source_ref_id'];
    $duplicate = $db->fetch_array($db->simple_select('af_shop_slots', 'slot_id', $duplicateWhere, ['limit' => 1]));
    if ($duplicate) { af_advancedshop_json_err('Slot with this source already exists in category', 409); }

    $priceMinor = af_advancedshop_money_to_minor((string)$mybb->get_input('price'));
    $currency = (string)$mybb->get_input('currency');
    if ($currency === '') {
        $currency = (string)$mybb->settings['af_advancedshop_currency_slug'];
    }

    $slotId = (int)$db->insert_query('af_shop_slots', [
        'shop_id' => (int)$shop['shop_id'],
        'cat_id' => $catId,
        'source_type' => $db->escape_string($sourcePayload['source_type']),
        'source_ref_id' => (int)$sourcePayload['source_ref_id'],
        'kb_type' => $db->escape_string((string)$sourcePayload['kb_type']),
        'kb_id' => (int)$sourcePayload['kb_id'],
        'kb_key' => $db->escape_string((string)$sourcePayload['kb_key']),
        'price' => $priceMinor,
        'currency' => $db->escape_string($currency),
        'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
        'limit_per_user' => max(0, (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT)),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
        'meta_json' => $db->escape_string((string)$mybb->get_input('meta_json')),
    ]);

    af_advancedshop_json_ok(['slot' => [
        'slot_id' => $slotId,
        'cat_id' => $catId,
        'source_type' => (string)$sourcePayload['source_type'],
        'source_ref_id' => (int)$sourcePayload['source_ref_id'],
        'kb_id' => (int)$sourcePayload['kb_id'],
        'kb_type' => (string)$sourcePayload['kb_type'],
        'kb_key' => (string)$sourcePayload['kb_key'],
        'price' => $priceMinor,
        'price_major' => af_advancedshop_money_format($priceMinor),
        'currency' => $currency,
        'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
        'limit_per_user' => max(0, (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT)),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
        'appearance_preset' => $sourcePayload['appearance_preset'] ?? [],
    ]]);
}

function af_advancedshop_manage_slot_update(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $slotId = (int)$mybb->get_input('slot_id');
    if ($slotId <= 0) { af_advancedshop_json_err('Slot not found', 404); }
    $slot = $db->fetch_array($db->simple_select('af_shop_slots', '*', 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$slot) { af_advancedshop_json_err('Slot not found', 404); }

    $priceMinor = af_advancedshop_money_to_minor((string)$mybb->get_input('price'));
    $sourceType = af_advancedshop_normalize_source_type((string)$mybb->get_input('source_type'));

    try {
        $sourcePayload = af_advancedshop_slot_payload_from_source($sourceType, [
            'source_ref_id' => (int)$mybb->get_input('source_ref_id'),
            'preset_id' => (int)$mybb->get_input('preset_id'),
            'preset_slug' => trim((string)$mybb->get_input('preset_slug')),
            'kb_id' => (int)$mybb->get_input('kb_id'),
            'kb_type' => (string)$mybb->get_input('kb_type'),
            'kb_key' => trim((string)$mybb->get_input('kb_key')),
        ], $slot);
    } catch (RuntimeException $e) {
        af_advancedshop_json_err($e->getMessage(), 422);
    }

    $duplicateWhere = 'shop_id=' . (int)$shop['shop_id'] . ' AND cat_id=' . (int)$slot['cat_id'] . " AND source_type='" . $db->escape_string($sourcePayload['source_type']) . "' AND source_ref_id=" . (int)$sourcePayload['source_ref_id'] . ' AND slot_id<>' . $slotId;
    $duplicate = $db->fetch_array($db->simple_select('af_shop_slots', 'slot_id', $duplicateWhere, ['limit' => 1]));
    if ($duplicate) { af_advancedshop_json_err('Slot with this source already exists in category', 409); }

    $update = [
        'source_type' => $db->escape_string($sourcePayload['source_type']),
        'source_ref_id' => (int)$sourcePayload['source_ref_id'],
        'kb_type' => $db->escape_string((string)$sourcePayload['kb_type']),
        'kb_id' => (int)$sourcePayload['kb_id'],
        'kb_key' => $db->escape_string((string)$sourcePayload['kb_key']),
        'price' => $priceMinor,
        'currency' => $db->escape_string((string)($mybb->get_input('currency') ?: $slot['currency'])),
        'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
        'limit_per_user' => max(0, (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT)),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
    ];
    $db->update_query('af_shop_slots', $update, 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id']);

    af_advancedshop_json_ok(['slot' => [
        'slot_id' => $slotId,
        'cat_id' => (int)$slot['cat_id'],
        'source_type' => (string)$sourcePayload['source_type'],
        'source_ref_id' => (int)$sourcePayload['source_ref_id'],
        'kb_id' => (int)$sourcePayload['kb_id'],
        'kb_type' => (string)$sourcePayload['kb_type'],
        'kb_key' => (string)$sourcePayload['kb_key'],
        'price' => (int)$update['price'],
        'price_major' => af_advancedshop_money_format((int)$update['price']),
        'currency' => (string)($mybb->get_input('currency') ?: $slot['currency']),
        'stock' => (int)$update['stock'],
        'limit_per_user' => (int)$update['limit_per_user'],
        'enabled' => (int)$update['enabled'],
        'sortorder' => (int)$update['sortorder'],
        'appearance_preset' => $sourcePayload['appearance_preset'] ?? [],
    ]]);
}

function af_advancedshop_manage_slot_delete(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $slotId = (int)$mybb->get_input('slot_id');
    if ($slotId <= 0) { af_advancedshop_json_err('Slot not found', 404); }
    $db->delete_query('af_shop_slots', 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id']);
    af_advancedshop_json_ok(['deleted' => $slotId]);
}

function af_advancedshop_appearance_search(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('forbidden', 403); }

    $table = af_advancedshop_appearance_presets_table();
    if ($table === '') {
        af_advancedshop_json_err('AdvancedAppearance presets table not found. Expected af_aa_presets.', 500);
    }

    $q = trim((string)$mybb->get_input('q'));
    $group = mb_strtolower(trim((string)$mybb->get_input('group')));
    if ($group === '') {
        $group = 'all';
    }

    $targetSql = [];
    foreach (af_advancedshop_appearance_supported_target_keys() as $targetKey) {
        $targetSql[] = "'" . $db->escape_string($targetKey) . "'";
    }

    $where = ['target_key IN (' . implode(', ', $targetSql) . ')'];
    if ($q !== '') {
        $like = $db->escape_string_like($q);
        $where[] = "(title LIKE '%" . $like . "%' OR description LIKE '%" . $like . "%' OR slug LIKE '%" . $like . "%')";
    }
    if ($group !== '' && $group !== 'all') {
        $groupTargets = [];
        foreach (af_advancedshop_appearance_supported_target_keys() as $targetKey) {
            if (af_advancedshop_appearance_group_for_target($targetKey) === $group) {
                $groupTargets[] = "'" . $db->escape_string($targetKey) . "'";
            }
        }
        if (!$groupTargets) {
            af_advancedshop_json_ok(['items' => [], 'group' => $group, 'group_label' => (string)(af_advancedshop_appearance_supported_group_labels()[$group] ?? $group), 'table' => $table]);
        }
        $where[] = 'target_key IN (' . implode(', ', $groupTargets) . ')';
    }
    $whereSql = implode(' AND ', $where);

    $items = [];
    $query = $db->query("SELECT id, slug, title, description, preview_image, target_key, enabled FROM " . $table . " WHERE " . $whereSql . " ORDER BY enabled DESC, sortorder ASC, id DESC LIMIT 100");
    while ($row = $db->fetch_array($query)) {
        $targetKey = mb_strtolower(trim((string)$row['target_key']));
        $items[] = [
            'preset_id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'title' => (string)$row['title'],
            'description' => (string)$row['description'],
            'preview_image' => (string)$row['preview_image'],
            'target_key' => $targetKey,
            'target_label' => af_advancedshop_appearance_target_label($targetKey),
            'group' => af_advancedshop_appearance_group_for_target($targetKey),
            'group_label' => (string)(af_advancedshop_appearance_supported_group_labels()[af_advancedshop_appearance_group_for_target($targetKey)] ?? af_advancedshop_appearance_group_for_target($targetKey)),
            'enabled' => (int)$row['enabled'],
        ];
    }

    af_advancedshop_json_ok([
        'items' => $items,
        'table' => $table,
        'group' => $group,
        'group_label' => (string)(af_advancedshop_appearance_supported_group_labels()[$group] ?? 'Все группы'),
        'groups' => af_advancedshop_appearance_supported_group_labels(),
    ]);
}

function af_advancedshop_kb_search(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('forbidden', 403); }
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $titleRuCol = $kbCols['title_ru'] ?? null;
    $titleEnCol = $kbCols['title_en'] ?? null;
    $titleCol = $kbCols['title'] ?? null;
    $shortRuCol = $kbCols['short_ru'] ?? null;
    $shortEnCol = $kbCols['short_en'] ?? null;
    $shortCol = $kbCols['short'] ?? null;
    $typeCol = $kbCols['type'] ?? null;
    $keyCol = $kbCols['key'] ?? null;
    $q = trim((string)$mybb->get_input('q'));
    $typeFilter = af_advancedshop_normalize_kb_type((string)$mybb->get_input('kb_type'));
    if ($typeFilter === '' || $typeFilter === 'all') {
        $typeFilter = 'all';
    }
    $rarityFilter = mb_strtolower(trim((string)$mybb->get_input('rarity')));
    $itemTypeFilter = mb_strtolower(trim((string)$mybb->get_input('item_type')));
    $spellLevelFilter = trim((string)$mybb->get_input('spell_level'));
    $spellSchoolFilter = mb_strtolower(trim((string)$mybb->get_input('spell_school')));
    $limit = (int)$mybb->get_input('limit', MyBB::INPUT_INT);
    if ($limit <= 0) { $limit = 50; }
    $limit = min(100, max(1, $limit));
    $escaped = $db->escape_string($q);
    $where = '1=1';
    if (!empty($kbCols['active'])) {
        $where .= ' AND ' . $kbCols['active'] . '=1';
    }
    if ($escaped !== '') {
        $searchParts = [];
        foreach (array_filter([$titleRuCol, $titleEnCol, $titleCol, $shortRuCol, $shortEnCol, $shortCol, $keyCol]) as $column) {
            $searchParts[] = $column . " LIKE '%{$escaped}%'";
        }
        if ($searchParts) {
            $where .= ' AND (' . implode(' OR ', $searchParts) . ')';
        }
    }
    if (!empty($typeCol) && $typeFilter !== 'all') {
        if ($typeFilter === 'spell') {
            $where .= " AND " . ($typeCol === 'type' ? '`type`' : $typeCol) . " IN('spell','ritual')";
        } else {
            $where .= " AND " . ($typeCol === 'type' ? '`type`' : $typeCol) . "='item'";
        }
    }
    $select = [
        $kbIdCol . ' AS kb_id',
        ($titleRuCol ? $titleRuCol . ' AS kb_title_ru' : "'' AS kb_title_ru"),
        ($titleEnCol ? $titleEnCol . ' AS kb_title_en' : "'' AS kb_title_en"),
        ($titleCol ? $titleCol . ' AS kb_title' : "'' AS kb_title"),
        ($shortRuCol ? $shortRuCol . ' AS kb_short_ru' : "'' AS kb_short_ru"),
        ($shortEnCol ? $shortEnCol . ' AS kb_short_en' : "'' AS kb_short_en"),
        ($shortCol ? $shortCol . ' AS kb_short' : "'' AS kb_short"),
        (!empty($kbCols['meta_json']) ? $kbCols['meta_json'] . ' AS kb_meta' : "'' AS kb_meta"),
        ($typeCol ? (($typeCol === 'type' ? '`type`' : $typeCol) . ' AS kb_type') : "'item' AS kb_type"),
        ($keyCol ? (($keyCol === 'key' ? '`key`' : $keyCol) . ' AS kb_key') : "'' AS kb_key"),
    ];
    $orderSort = !empty($kbCols['sortorder']) ? $kbCols['sortorder'] . ' ASC, ' : '';
    $sql = "SELECT " . implode(',', $select) . " FROM " . af_advancedshop_kb_table() . " WHERE {$where} ORDER BY {$orderSort}{$kbIdCol} DESC LIMIT " . ($limit * 2);
    $res = $db->query($sql);
    $items = [];
    while ($row = $db->fetch_array($res)) {
        $profile = af_advancedshop_kb_item_profile($row);
        $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);
        $title = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
        if ($title === '') { $title = (string)($row['kb_title'] ?? ''); }
        if ($title === '') { $title = (string)($row['title'] ?? ''); }
        $short = af_advancedshop_pick_lang((string)($row['kb_short_ru'] ?? ''), (string)($row['kb_short_en'] ?? ''));
        if ($short === '') { $short = (string)($row['kb_short'] ?? ''); }
        $itemType = mb_strtolower(trim((string)($meta['rules']['item']['item_kind'] ?? $meta['rules']['item']['type'] ?? '')));
        $spellLevel = (string)($meta['rules']['spell']['level'] ?? $meta['rules']['ritual']['level'] ?? '');
        $spellSchool = mb_strtolower(trim((string)($meta['rules']['spell']['school'] ?? $meta['rules']['ritual']['school'] ?? '')));

        if ($rarityFilter !== '' && mb_strtolower((string)$profile['rarity']) !== $rarityFilter) { continue; }
        if ($itemTypeFilter !== '' && $itemType !== $itemTypeFilter) { continue; }
        if ($spellLevelFilter !== '' && $spellLevel !== $spellLevelFilter) { continue; }
        if ($spellSchoolFilter !== '' && $spellSchool !== $spellSchoolFilter) { continue; }

        $items[] = [
            'kb_id' => (int)$row['kb_id'],
            'kb_type' => (string)($row['kb_type'] ?? 'item'),
            'kb_key' => (string)($row['kb_key'] ?? ''),
            'title' => $title,
            'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
            'rarity' => (string)$profile['rarity'],
            'stack_max' => (int)$profile['stack_max'],
            'short' => $short,
            'price_minor' => max(0, (int)($profile['price'] ?? 0)),
            'price_major' => af_advancedshop_money_format(max(0, (int)($profile['price'] ?? 0))),
            'currency' => (string)($profile['currency'] ?? 'credits'),
            'item_type' => $itemType,
            'spell_level' => $spellLevel,
            'spell_school' => $spellSchool,
        ];
        if (count($items) >= $limit) {
            break;
        }
    }
    af_advancedshop_json_ok(['items' => $items]);
}

function af_advancedshop_normalize_kb_type(string $type): string
{
    $normalized = mb_strtolower(trim($type));
    if (in_array($normalized, ['spell', 'ritual'], true)) {
        return 'spell';
    }
    if ($normalized === 'all') {
        return 'all';
    }
    if ($normalized === '' || $normalized === 'item') {
        return 'item';
    }
    return $normalized;
}

function af_advancedshop_kb_probe(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage() && !af_advancedshop_can_moderate_inventory()) {
        af_advancedshop_json_err('Not allowed', 403);
    }

    $kbId = (int)$mybb->get_input('id');
    if ($kbId <= 0) {
        af_advancedshop_json_err('id required', 422);
    }

    $kbCols = af_advancedshop_kb_cols();
    $kbTableName = af_advancedshop_kb_table_entries();
    $kbTableSql = af_advancedshop_kb_table();
    if ($kbTableName === '' || $kbTableSql === '') {
        af_advancedshop_json_err('Knowledge base table is not available', 503);
    }
    $kbIdCol = $kbCols['id'] ?? 'id';
    $select = [
        'e.' . $kbIdCol . ' AS id',
        'e.' . ($kbCols['type'] === 'type' ? '`type`' : $kbCols['type']) . ' AS type',
        'e.' . ($kbCols['key'] === 'key' ? '`key`' : $kbCols['key']) . ' AS `key`',
        'e.' . $kbCols['title_ru'] . ' AS title_ru',
        'e.' . $kbCols['title_en'] . ' AS title_en',
        'e.' . $kbCols['short_ru'] . ' AS short_ru',
        'e.' . $kbCols['short_en'] . ' AS short_en',
        'e.' . $kbCols['body_ru'] . ' AS body_ru',
        'e.' . $kbCols['body_en'] . ' AS body_en',
        'e.' . $kbCols['tech_ru'] . ' AS tech_ru',
        'e.' . $kbCols['tech_en'] . ' AS tech_en',
        'e.' . $kbCols['meta_json'] . ' AS meta_json',
        'e.' . $kbCols['active'] . ' AS active',
        'e.' . $kbCols['sortorder'] . ' AS sortorder',
    ];
    if ($kbTableName !== '' && $db->field_exists('icon_url', $kbTableName)) {
        $select[] = 'e.icon_url AS icon_url';
    }
    if ($kbTableName !== '' && $db->field_exists('bg_url', $kbTableName)) {
        $select[] = 'e.bg_url AS bg_url';
    }

    $row = $db->fetch_array($db->query("SELECT " . implode(', ', $select) . "\n        FROM " . $kbTableSql . " e\n        WHERE e." . $kbIdCol . "=" . $kbId . "\n        LIMIT 1"));
    if (!$row) {
        af_advancedshop_json_err('Entry not found', 404);
    }

    $resolved = af_advancedshop_kb_resolve_data_json($row);
    $profile = af_advancedshop_kb_item_profile(array_merge($row, $resolved));

    af_advancedshop_json_ok([
        'kb_entry_row' => $row,
        'data_json_source' => [
            'found_in_column' => (string)($resolved['kb_data_column'] ?? '') !== '' ? (string)$resolved['kb_data_column'] : null,
            'found_in_meta_json' => !empty($resolved['kb_data_from_meta']),
            'found_in_other_table' => (string)($resolved['kb_data_other_table'] ?? '') !== '' ? (string)$resolved['kb_data_other_table'] : null,
        ],
        'data_json_preview' => mb_substr((string)($resolved['kb_data'] ?? ''), 0, 200),
        'parsed_ok' => (string)($profile['data_json_present'] ?? 'no') === 'yes',
        'parsed_item_rarity' => (string)($profile['rarity'] ?? 'common'),
        'parsed_item_kind' => (string)($profile['item_kind'] ?? ''),
        'parsed_equip_slot' => (string)($profile['equip_slot'] ?? ''),
    ]);
}

function af_advancedshop_parse_bbcode(string $text): string
{
    if ($text === '') { return ''; }
    if (!class_exists('postParser')) { require_once MYBB_ROOT . 'inc/class_parser.php'; }
    $parser = new postParser();
    return $parser->parse_message($text, [
        'allow_html' => 0,
        'allow_mycode' => 1,
        'allow_basicmycode' => 1,
        'allow_smilies' => 1,
        'allow_imgcode' => 1,
        'allow_videocode' => 1,
        'filter_badwords' => 1,
        'nl2br' => 1,
    ]);
}

function af_advancedshop_decode_json_assoc(string $json): array
{
    $decoded = @json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function af_advancedshop_pick_lang(string $ru, string $en): string
{
    global $mybb;
    $lang = (string)($mybb->settings['bblanguage'] ?? 'russian');
    return $lang === 'english' ? ($en ?: $ru) : ($ru ?: $en);
}

function af_advancedshop_money_scale(): int
{
    global $mybb;
    $scale = (int)($mybb->settings['af_advancedshop_money_scale'] ?? 100);
    return $scale > 0 ? $scale : 100;
}

function af_shop_price_to_storage(string $input): int
{
    $normalized = str_replace(',', '.', trim($input));
    if ($normalized === '') {
        return 0;
    }
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
        return 0;
    }

    $parts = explode('.', $normalized, 2);
    $major = (int)$parts[0];
    $minor = 0;
    if (isset($parts[1])) {
        $minorPart = str_pad(substr($parts[1], 0, 2), 2, '0', STR_PAD_RIGHT);
        $minor = (int)$minorPart;
    }

    return max(0, ($major * 100) + $minor);
}

function af_shop_price_to_display(int $stored): string
{
    $stored = max(0, $stored);
    if ($stored === 0) {
        return '0';
    }

    $formatted = number_format($stored / 100, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function af_advancedshop_money_format(int $amountMinor): string
{
    return af_shop_price_to_display($amountMinor);
}

function af_advancedshop_money_to_minor(string $amount): int
{
    return af_shop_price_to_storage($amount);
}

function af_advancedshop_currency_symbol(string $slug): string
{
    $slug = trim($slug);
    if ($slug === 'credits') {
        return '¢';
    }
    return $slug;
}


function af_advancedshop_render_shop_categories_tree(array $rows, string $shopCode, int $activeCatId, bool $isManagerView = false, array $slotCounts = []): string
{
    $childrenByParent = [];
    foreach ($rows as $row) {
        $parentId = max(0, (int)($row['parent_id'] ?? 0));
        if (!isset($childrenByParent[$parentId])) {
            $childrenByParent[$parentId] = [];
        }
        $childrenByParent[$parentId][] = $row;
    }

    $rendered = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, $childrenByParent, $shopCode, $activeCatId, $isManagerView, $slotCounts, &$rendered): string {
        $html = '';
        foreach ($childrenByParent[$parentId] ?? [] as $cat) {
            $rowCatId = (int)$cat['cat_id'];
            $rendered[$rowCatId] = true;
            $hasChildren = !empty($childrenByParent[$rowCatId]);
            $activeClass = $activeCatId === $rowCatId ? 'is-active' : '';
            $cat_url = af_advancedshop_url('shop_category', ['shop' => $shopCode, 'cat' => $rowCatId], true);
            $catTitleRaw = (string)$cat['title'];
            if ($isManagerView) {
                $labels = [];
                if ((int)($cat['enabled'] ?? 0) !== 1) {
                    $labels[] = 'disabled';
                }
                if (((int)($slotCounts[$rowCatId] ?? 0)) === 0) {
                    $labels[] = 'empty';
                }
                if ($labels) {
                    $catTitleRaw .= ' [' . implode(', ', $labels) . ']';
                }
            }
            $cat_title = htmlspecialchars_uni($catTitleRaw);
            $cat_depth = $depth;
            $cat_toggle = '<button type="button" class="af-cat-toggle' . ($hasChildren ? '' : ' is-empty') . '" data-cat="' . $rowCatId . '" aria-expanded="true"' . ($hasChildren ? '' : ' aria-hidden="true" tabindex="-1"') . '><span class="af-cat-toggle__icon">' . ($hasChildren ? '▾' : '') . '</span></button>';
            $cat_children = '';
            if ($hasChildren) {
                $childHtml = $walk($rowCatId, $depth + 1);
                $cat_children = '<div class="af-cat-children" data-parent="' . $rowCatId . '">' . $childHtml . '</div>';
            }
            eval('$html .= "' . af_advancedshop_tpl('advancedshop_shop_category') . '";');
        }
        return $html;
    };

    $html = $walk(0, 0);
    foreach ($rows as $row) {
        if (!isset($rendered[(int)$row['cat_id']])) {
            $parentId = max(0, (int)($row['parent_id'] ?? 0));
            $html .= $walk($parentId, 0);
        }
    }
    return $html;
}

function af_advancedshop_debug_categories(string $shopCode, int $shopId, array $categories): void
{
    $catIds = [];
    foreach ($categories as $cat) {
        $catIds[] = (int)($cat['cat_id'] ?? 0);
    }

    error_log('[af_advancedshop][shop_category] shop_code=' . $shopCode . ' shop_id=' . $shopId . ' categories_count=' . count($catIds) . ' cat_ids=' . implode(',', $catIds));
}

function af_advancedshop_category_tree_rows(array $rows): array
{
    $byParent = [];
    foreach ($rows as $row) {
        $parentId = max(0, (int)($row['parent_id'] ?? 0));
        if (!isset($byParent[$parentId])) {
            $byParent[$parentId] = [];
        }
        $byParent[$parentId][] = $row;
    }

    $walk = static function (int $parentId, int $depth) use (&$walk, &$byParent): array {
        $out = [];
        foreach (($byParent[$parentId] ?? []) as $row) {
            $out[] = ['row' => $row, 'depth' => $depth];
            $out = array_merge($out, $walk((int)$row['cat_id'], $depth + 1));
        }
        return $out;
    };

    $out = $walk(0, 0);
    $listed = [];
    foreach ($out as $item) {
        $listed[(int)$item['row']['cat_id']] = true;
    }
    foreach ($rows as $row) {
        $catId = (int)$row['cat_id'];
        if (!isset($listed[$catId])) {
            $out[] = ['row' => $row, 'depth' => 0];
        }
    }

    return $out;
}


function af_advancedshop_category_descendants_map(array $rows): array
{
    $childrenByParent = [];
    foreach ($rows as $row) {
        $parentId = max(0, (int)($row['parent_id'] ?? 0));
        $catId = (int)($row['cat_id'] ?? 0);
        if ($catId <= 0) {
            continue;
        }
        if (!isset($childrenByParent[$parentId])) {
            $childrenByParent[$parentId] = [];
        }
        $childrenByParent[$parentId][] = $catId;
    }

    $walk = static function (int $catId) use (&$walk, &$childrenByParent): array {
        $result = [];
        foreach ($childrenByParent[$catId] ?? [] as $childId) {
            $result[$childId] = true;
            foreach ($walk($childId) as $descendantId => $trueValue) {
                $result[$descendantId] = $trueValue;
            }
        }
        return $result;
    };

    $map = [];
    foreach ($rows as $row) {
        $catId = (int)($row['cat_id'] ?? 0);
        if ($catId <= 0) {
            continue;
        }
        $map[$catId] = $walk($catId);
    }

    return $map;
}

function af_advancedshop_parent_options_html(array $treeRows, int $selectedParentId, array $blockedParents = []): string
{
    $html = '<option value="0"' . ($selectedParentId === 0 ? ' selected="selected"' : '') . '>— Root —</option>';
    foreach ($treeRows as $treeRow) {
        $row = $treeRow['row'] ?? [];
        $catId = (int)($row['cat_id'] ?? 0);
        if ($catId <= 0 || isset($blockedParents[$catId])) {
            continue;
        }
        $depth = max(0, (int)($treeRow['depth'] ?? 0));
        $title = str_repeat('— ', $depth) . (string)($row['title'] ?? '');
        $html .= '<option value="' . $catId . '"' . ($selectedParentId === $catId ? ' selected="selected"' : '') . '>' . htmlspecialchars_uni($title) . '</option>';
    }
    return $html;
}

function af_advancedshop_manage_sortorder_rebuild(): void
{
    global $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $flat = [];
    $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'parent_id ASC, sortorder ASC, title ASC, cat_id ASC']);
    while ($row = $db->fetch_array($q)) {
        $flat[] = $row;
    }

    $grouped = [];
    foreach ($flat as $row) {
        $grouped[max(0, (int)$row['parent_id'])][] = (int)$row['cat_id'];
    }

    foreach ($grouped as $parentId => $catIds) {
        $pos = 10;
        foreach ($catIds as $catId) {
            $db->update_query('af_shop_categories', ['sortorder' => $pos], 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);
            $pos += 10;
        }
    }

    af_advancedshop_json_ok(['rebuilt' => true]);
}

function af_advancedshop_kb_schema(): void
{
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $schema = af_advancedshop_kb_schema_meta();
    $cols = af_advancedshop_kb_cols();

    af_advancedshop_json_ok([
        'kb_table' => (string)($schema['kb_table_sql'] ?? ''),
        'columns' => array_values($schema['columns'] ?? []),
        'picked' => [
            'id' => (string)($cols['id'] ?? ''),
            'type' => (string)($cols['type'] ?? ''),
            'key' => (string)($cols['key'] ?? ''),
            'meta_json' => (string)($cols['meta_json'] ?? ''),
            'data_json' => (string)($cols['data_json'] ?? ''),
        ],
    ]);
}

function af_advancedshop_health_ping(): void
{
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    af_advancedshop_json_ok(['ping' => 'ok']);
}

function af_advancedshop_kb_rules_from_meta($metaRaw): array
{
    if (!is_string($metaRaw) || trim($metaRaw) === '') {
        return [];
    }

    if (function_exists('af_kb_extract_rules_from_meta_json')) {
        $rules = af_kb_extract_rules_from_meta_json($metaRaw);
        return is_array($rules) ? $rules : [];
    }

    $meta = @json_decode($metaRaw, true);
    if (!is_array($meta) || !is_array($meta['rules'] ?? null)) {
        return [];
    }

    return (array)$meta['rules'];
}

function af_advancedshop_kb_resolve_data_json(array $kbRow): array
{
    $metaRaw = (string)($kbRow['kb_meta'] ?? $kbRow['meta_json'] ?? '');
    $rules = af_advancedshop_kb_rules_from_meta($metaRaw);
    if (!$rules) {
        return [
            'kb_data' => '',
            'kb_data_column' => null,
            'kb_data_from_meta' => false,
            'kb_data_other_table' => '',
        ];
    }

    return [
        'kb_data' => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        'kb_data_column' => 'meta_json.rules',
        'kb_data_from_meta' => true,
        'kb_data_other_table' => '',
    ];
}

function af_advancedshop_kb_item_profile(array $kbRow): array
{
    $default = [
        'rarity' => 'common',
        'item_kind' => '',
        'kb_key' => '',
        'slot' => '',
        'equip_slot' => '',
        'armor_ac_bonus' => 0,
        'weapon_damage_bonus' => 0,
        'weapon_damage_type' => '',
        'stack_max' => 1,
        'currency' => 'credits',
        'price' => 0,
        'tags' => [],
        'rarity_raw' => '',
        'data_json_present' => 'no',
    ];

    $metaRaw = (string)($kbRow['kb_meta'] ?? $kbRow['meta_json'] ?? '');
    $rules = af_advancedshop_kb_rules_from_meta($metaRaw);
    if (!$rules) {
        return $default;
    }

    $item = is_array($rules['item'] ?? null) ? $rules['item'] : [];
    $rawRarity = (string)($item['rarity'] ?? '');

    $tags = $rules['tags'] ?? ($item['tags'] ?? []);
    if (!is_array($tags)) { $tags = []; }

    $equip = is_array($item['equip'] ?? null) ? $item['equip'] : [];
    $equipArmor = is_array($equip['armor'] ?? null) ? $equip['armor'] : [];

    return [
        'rarity' => af_advancedshop_normalize_rarity($rawRarity),
        'item_kind' => (string)($item['item_kind'] ?? ''),
        'kb_key' => trim((string)($kbRow['kb_key'] ?? $kbRow['key'] ?? '')),
        'slot' => (string)($item['slot'] ?? ''),
        'equip_slot' => af_advancedshop_normalize_equip_slot_code((string)($equip['slot'] ?? ($item['slot'] ?? ''))),
        'armor_ac_bonus' => max(0, (int)($equipArmor['ac_bonus'] ?? 0)),
        'weapon_damage_bonus' => (int)($item['weapon']['damage_bonus'] ?? 0),
        'weapon_damage_type' => trim((string)($item['weapon']['damage_type'] ?? '')),
        'stack_max' => max(1, (int)($item['stack_max'] ?? 1)),
        'currency' => (string)($item['currency'] ?? 'credits'),
        'price' => max(0, (int)($item['price'] ?? 0)),
        'tags' => $tags,
        'rarity_raw' => $rawRarity,
        'data_json_present' => 'yes',
    ];
}

function af_advancedshop_extract_rarity(array $data): string
{
    return af_advancedshop_kb_item_profile(['kb_meta' => json_encode(['rules' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])['rarity'];
}

function af_advancedshop_normalize_equip_slot_code(string $slot): string
{
    $slot = mb_strtolower(trim($slot));
    $aliases = [
        'weapon_main' => 'mainhand',
        'weapon_off' => 'offhand',
        'weapon_side' => 'offhand',
        'weapon' => 'mainhand',
        'armor' => 'body',
        'armor_body' => 'body',
        'armor_head' => 'head',
    ];

    return $aliases[$slot] ?? $slot;
}

function af_advancedshop_normalize_rarity(string $rarity): string
{
    $value = mb_strtolower(trim($rarity));
    if ($value === '') {
        return 'common';
    }

    $map = [
        'обычная' => 'common',
        'обыкновенная' => 'common',
        'необычная' => 'uncommon',
        'редкая' => 'rare',
        'уникальная' => 'unique',
        'незаконная' => 'illegal',
        'ограниченная' => 'restricted',
        'легендарная' => 'legendary',
        'мифическая' => 'mythic',
    ];
    if (isset($map[$value])) {
        return $map[$value];
    }

    if (in_array($value, ['common', 'uncommon', 'rare', 'unique', 'illegal', 'restricted', 'legendary', 'mythic'], true)) {
        return $value;
    }

    return 'common';
}

function af_advancedshop_rarity_label(string $rarity): string
{
    global $lang;
    $normalized = af_advancedshop_normalize_rarity($rarity);
    $key = 'af_advancedshop_rarity_' . $normalized;
    return $lang->{$key} ?? ucfirst($normalized);
}

function af_advancedshop_kb_entry_url(int $kbId, string $type = '', string $entryKey = ''): string
{
    if ($type !== '' && $entryKey !== '') {
        return 'misc.php?action=kb&type=' . urlencode($type) . '&key=' . urlencode($entryKey);
    }
    return 'misc.php?action=kb';
}

function af_shop_get_balance(int $uid, string $currency_slug): int
{
    $currency_slug = $currency_slug === '' ? 'credits' : $currency_slug;
    if ($currency_slug === 'credits' && function_exists('af_balance_get')) {
        $bal = af_balance_get($uid);
        return (int)($bal['credits'] ?? 0);
    }
    return 0;
}

function af_shop_add_balance(int $uid, string $currency_slug, int $amount, string $reason, array $meta = []): void
{
    if ($currency_slug === 'credits' && function_exists('af_balance_add_credits')) {
        $meta['reason'] = $reason;
        $meta['source'] = 'advancedshop';
        af_balance_add_credits($uid, $amount / 100, $meta);
    }
}

function af_shop_sub_balance(int $uid, string $currency_slug, int $amount, string $reason, array $meta = []): void
{
    af_shop_add_balance($uid, $currency_slug, -abs($amount), $reason, $meta);
}

function af_advancedshop_json(array $data): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_advancedshop_json_ok(array $payload = []): void
{
    af_advancedshop_json(array_merge(['ok' => true], $payload));
}

function af_advancedshop_json_err(string $message, int $code = 400, array $extra = []): void
{
    af_advancedshop_debug_log('shop_json_error', ['code' => $code, 'error' => $message, 'action' => (string)($_REQUEST['action'] ?? '')]);
    if (function_exists('http_response_code')) {
        http_response_code($code);
    }
    af_advancedshop_json(array_merge(['ok' => false, 'error' => $message, 'code' => $code], $extra));
}

function af_advancedshop_debug_log(string $event, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $path = MYBB_ROOT . 'inc/plugins/advancedfunctionality/cache/advancedshop.log';
    @file_put_contents($path, $line, FILE_APPEND);
}

function af_advancedshop_render_shop_manage_page(): void
{
    global $mybb, $db;

    if (!af_advancedshop_can_manage()) {
        error_no_permission();
    }

    $shopCode = trim((string)$mybb->get_input('shop'));
    if ($shopCode === '') {
        $shops = [];
        $q = $db->simple_select(af_advancedshop_shops_table(), '*', 'enabled=1', ['order_by' => 'sortorder ASC, shop_id ASC']);
        while ($row = $db->fetch_array($q)) {
            $shops[] = $row;
        }

        add_breadcrumb('Shop Manager', af_advancedshop_manage_url());

        $rows = '';
        foreach ($shops as $shop) {
            $title = trim((string)($shop['title_ru'] ?? ''));
            if ($title === '') {
                $title = trim((string)($shop['title_en'] ?? ''));
            }
            if ($title === '') {
                $title = (string)($shop['title'] ?? (string)$shop['code']);
            }

            $rows .= '<tr>'
                . '<td><code>' . htmlspecialchars_uni((string)$shop['code']) . '</code></td>'
                . '<td>' . htmlspecialchars_uni($title) . '</td>'
                . '<td><a href="' . htmlspecialchars_uni(af_advancedshop_manage_url((string)$shop['code'])) . '">Manage</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3">No enabled shops found.</td></tr>';
        }

        $af_advancedshop_content = '<div class="af-shop af-shop-manage">'
            . '<h2>Shop Manager</h2>'
            . '<table class="tborder" cellpadding="6" cellspacing="1" width="100%">'
            . '<tr><th>Code</th><th>Title</th><th>Action</th></tr>'
            . $rows
            . '</table>'
            . '</div>';

        eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
        output_page($page);
        exit;
    }

    $action = trim((string)$mybb->get_input('action'));
    if ($action !== '' && in_array($action, ['shop_manage_slots', 'shop_appearance_search', 'shop_kb_search'], true)) {
        af_advancedshop_dispatch($action);
        return;
    }

    $view = trim((string)$mybb->get_input('view'));
    if ($view === 'slots') {
        af_advancedshop_manage_slots();
        return;
    }

    af_advancedshop_render_manage();
}
