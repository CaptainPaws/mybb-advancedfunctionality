<?php
/**
 * AF Addon: AdvancedMenu
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Делает кастомные пункты меню и внедряет их в:
 *  - <ul class="menu top_links"> (header)
 *  - <ul class="menu panel_links"> (header_welcomeblock_member)
 *
 * Режимы:
 *  - append: оставить старое + дописать новое
 *  - replace: заменить на новое (panel_links при этом сохраняет защищённые пункты, напр. AAS/AAM)
 *
 * Скрытие старых пунктов делается через "паттерны" (substring match по HTML <li>).
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* аддон предполагает наличие ядра AF */ }

define('AF_AM_ID', 'advancedmenu');
define('AF_AM_TABLE_ITEMS', 'af_advancedmenu_items');
define('AF_AM_TABLE_OVERRIDES', 'af_advancedmenu_overrides');
define('AF_AM_CACHE_KEY', 'af_advancedmenu_items');
define('AF_AM_ASSETS_MARK', '<!--af_advancedmenu_assets-->');
define('AF_AM_APPLIED_MARK', '<!--af_advancedmenu_applied-->');

function af_menu_containers(): array
{
    return ['main'=>'Основное меню', 'secondary'=>'Дополнительное меню', 'user_drawer'=>'Пользовательское меню'];
}

/** Drawer groups shared by providers and the ACP presentation controls. */
function af_menu_sections(): array
{
    return ['profile'=>'Профиль', 'links'=>'Ссылки', 'settings'=>'Настройки', 'theme'=>'Тема'];
}

/** Return the stable storage key used by both custom and provider items. */
function af_menu_normalize_section(string $section): string
{
    return array_key_exists($section, af_menu_sections()) ? $section : 'links';
}

function af_menu_normalize_container(string $container): string
{
    return ['top'=>'main', 'top_links'=>'main', 'panel'=>'user_drawer', 'panel_links'=>'user_drawer',
        'user_links'=>'user_drawer'][$container] ?? (array_key_exists($container, af_menu_containers()) ? $container : 'main');
}

/* Runtime catalogue contains immutable provider defaults. Presentation choices
 * are deliberately stored separately in AF_AM_TABLE_OVERRIDES. */
function af_menu_register_item(array $item): bool
{
    $key = strtolower(trim((string)($item['key'] ?? '')));
    if ($key === '' || !preg_match('~^[a-z][a-z0-9_]*$~', $key)) return false;
    $type = strtolower((string)($item['type'] ?? 'link'));
    if (!in_array($type, ['link', 'modal', 'action/system', 'widget'], true)) return false;
    $item = array_merge(['key'=>$key, 'source_addon'=>'mybb', 'label'=>$key,
        'icon'=>'', 'type'=>$type, 'default_container'=>'panel_links',
        'default_sortorder'=>100, 'visibility'=>true, 'action'=>[],
        'badge_provider'=>null, 'renderer'=>null, 'widget_config'=>[], 'section'=>'links', 'allowed_containers'=>array_keys(af_menu_containers())], $item);
    if ($type === 'widget' && !is_callable($item['renderer'])) return false;
    $item['key'] = $key; $item['type'] = $type;
    $item['default_sortorder'] = (int)$item['default_sortorder'];
    $item['default_container'] = af_menu_normalize_container((string)$item['default_container']);
    $item['section'] = array_key_exists((string)$item['section'], af_menu_sections()) ? (string)$item['section'] : 'settings';
    $item['allowed_containers'] = array_values(array_intersect(array_keys(af_menu_containers()), (array)$item['allowed_containers']));
    if (!$item['allowed_containers']) $item['allowed_containers'] = [$item['default_container']];
    $GLOBALS['af_advancedmenu_system_registry'][$key] = $item;
    return true;
}

function af_menu_register_core_items(): void
{
    global $mybb;
    $member = static function (): bool { global $mybb; return !empty($mybb->user['uid']); };
    $mod = static function (): bool { global $mybb; return !empty($mybb->usergroup['canmodcp']) || !empty($mybb->usergroup['issupermod']) || (!empty($mybb->user['uid']) && function_exists('is_moderator') && is_moderator()); };
    $admin = static function (): bool { global $mybb; return !empty($mybb->usergroup['cancp']); };
    $uid = (int)($mybb->user['uid'] ?? 0);
    $postKey = rawurlencode((string)($mybb->post_code ?? ''));
    af_menu_register_item(['key'=>'profile','label'=>'Профиль','icon'=>'fa-solid fa-user','type'=>'link','section'=>'profile','default_container'=>'user_drawer','default_sortorder'=>10,'visibility'=>$member,'action'=>['url'=>'member.php?action=profile&amp;uid='.$uid]]);
    af_menu_register_item(['key'=>'usercp','label'=>'User CP','icon'=>'fa-solid fa-user-gear','type'=>'link','section'=>'profile','default_container'=>'user_drawer','default_sortorder'=>20,'visibility'=>$member,'action'=>['url'=>'usercp.php']]);
    af_menu_register_item(['key'=>'new_posts','label'=>'Новые сообщения','icon'=>'fa-solid fa-clock-rotate-left','type'=>'link','section'=>'links','default_container'=>'user_drawer','default_sortorder'=>40,'visibility'=>$member,'action'=>['url'=>'search.php?action=getnew']]);
    af_menu_register_item(['key'=>'private_messages','label'=>'Личные сообщения','icon'=>'fa-solid fa-envelope','type'=>'link','section'=>'links','default_container'=>'user_drawer','default_sortorder'=>50,'visibility'=>$member,'action'=>['url'=>'private.php']]);
    af_menu_register_item(['key'=>'todays_posts','label'=>'Сообщения за сегодня','icon'=>'fa-solid fa-calendar-day','type'=>'link','section'=>'links','default_container'=>'user_drawer','default_sortorder'=>60,'visibility'=>$member,'action'=>['url'=>'search.php?action=getdaily']]);
    af_menu_register_item(['key'=>'logout','label'=>'Выйти','icon'=>'fa-solid fa-right-from-bracket','type'=>'action/system','section'=>'settings','default_container'=>'user_drawer','default_sortorder'=>100,'visibility'=>$member,'action'=>['url'=>'member.php?action=logout&amp;logoutkey='.$postKey]]);
    af_menu_register_item(['key'=>'theme_switcher','label'=>'Тема','icon'=>'fa-solid fa-circle-half-stroke','type'=>'widget',
        'section'=>'theme','default_container'=>'user_drawer','default_sortorder'=>80,'visibility'=>true,
        'allowed_containers'=>['user_drawer'],'renderer'=>'af_advancedmenu_render_theme_widget',
        'widget_config'=>['provider'=>'mybb_footer_theme_select']]);
    af_menu_register_item(['key'=>'modcp','label'=>'Mod CP','icon'=>'fa-solid fa-shield-halved','type'=>'link','default_container'=>'secondary','default_sortorder'=>80,'visibility'=>$mod,'action'=>['url'=>'modcp.php']]);
    af_menu_register_item(['key'=>'admincp','label'=>'Admin CP','icon'=>'fa-solid fa-screwdriver-wrench','type'=>'link','default_container'=>'secondary','default_sortorder'=>90,'visibility'=>$admin,'action'=>['url'=>'admin/index.php']]);
}

function af_menu_collect_registry(bool $force = false): array
{
    static $coreRegistered = false;
    static $calledProviders = [];
    if ($force) {
        $GLOBALS['af_advancedmenu_system_registry'] = [];
        $coreRegistered = false;
        $calledProviders = [];
    }
    if (!$coreRegistered) {
        $coreRegistered = true;
        af_menu_register_core_items();
    }

    // Manifests are the request-independent provider catalogue.  In
    // particular, AdminCP does not bootstrap frontend addon files, so looking
    // only at get_defined_functions() made its catalogue depend on load order.
    // The declarative items intentionally contain callback *names*: reading a
    // manifest must not execute the addon's frontend bootstrap or hooks.
    if (function_exists('af_discover_addons')) {
        foreach (af_discover_addons() as $addon) {
            $id = strtolower(trim((string)($addon['id'] ?? '')));
            if ($id === '' || (function_exists('af_is_addon_enabled') && !af_is_addon_enabled($id))) {
                continue;
            }
            $contract = $addon['menu_provider'] ?? null;
            if (!is_array($contract)) continue;
            foreach ((array)($contract['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                // The manifest owner is authoritative; a manifest cannot
                // impersonate another addon by supplying source_addon.
                $item['source_addon'] = $id;
                af_menu_register_item($item);
            }
            $callback = strtolower(trim((string)($contract['callback'] ?? '')));
            if ($callback !== '' && preg_match('~^af_[a-z0-9_]+_menu_provider$~', $callback)
                && function_exists($callback) && !isset($calledProviders[$callback])) {
                $calledProviders[$callback] = true;
                $callback();
            }
        }
    }

    // Addons are bootstrapped one by one. AdvancedMenu may therefore be
    // initialised before a provider function exists. Discover newly loaded
    // providers on every read instead of freezing the catalogue on the first
    // call; each provider still runs at most once per request.
    foreach (get_defined_functions()['user'] as $function) {
        if (!isset($calledProviders[$function])
            && preg_match('~^af_[a-z0-9_]+_menu_provider$~', $function)) {
            $calledProviders[$function] = true;
            $function();
        }
    }
    $items = $GLOBALS['af_advancedmenu_system_registry'] ?? [];
    uasort($items, static fn(array $a, array $b): int => [$a['default_sortorder'], $a['key']] <=> [$b['default_sortorder'], $b['key']]);
    return $items;
}

function af_menu_item_is_visible(array $item): bool
{
    $value = $item['visibility'] ?? true;
    // An unresolved callback name is expected in ACP (frontend bootstraps are
    // deliberately not loaded there).  It must never accidentally become
    // truthy merely because it is a non-empty string.
    if (is_string($value)) return function_exists($value) ? (bool)$value($item) : false;
    return is_callable($value) ? (bool)$value($item) : (bool)$value;
}

function af_menu_item_badge(array $item)
{
    $provider = $item['badge_provider'] ?? null;
    return is_callable($provider) ? $provider($item) : null;
}

/** Stable identity for a provider-owned item. Placement is intentionally absent. */
function af_menu_provider_identity(array $item): string
{
    return strtolower(trim((string)($item['source_addon'] ?? 'mybb'))).'::'.strtolower(trim((string)($item['key'] ?? '')));
}

/** Apply editable DB fields after all immutable provider defaults. */
function af_menu_apply_override(array $item, ?array $override): array
{
    $override = $override ?? [];
    $item['enabled'] = (int)($override['enabled'] ?? 1);
    $item['container'] = af_menu_normalize_container((string)($override['container'] ?? $item['default_container']));
    if (!in_array($item['container'], $item['allowed_containers'], true)) $item['container'] = $item['default_container'];
    $item['sortorder'] = (int)($override['sortorder'] ?? $item['default_sortorder']);
    $section = (string)($override['section'] ?? $item['section']);
    $item['section'] = array_key_exists($section, af_menu_sections()) ? $section : $item['section'];
    if (($override['label_override'] ?? '') !== '') $item['label'] = $override['label_override'];
    if (($override['icon_override'] ?? '') !== '') $item['icon'] = $override['icon_override'];
    $item['canonical_identity'] = af_menu_provider_identity($item);
    return $item;
}

/** Repair only duplicate provider overrides; the custom-items table is untouched. */
function af_menu_repair_duplicate_overrides(): void
{
    global $db;
    $q = $db->simple_select(AF_AM_TABLE_OVERRIDES, 'source_addon, item_key, COUNT(*) AS total', '', ['group_by'=>'source_addon, item_key', 'having'=>'COUNT(*) > 1']);
    while ($group = $db->fetch_array($q)) {
        $escaped = $db->escape_string((string)$group['item_key']);
        $source = $db->escape_string((string)$group['source_addon']);
        $where = "source_addon='{$source}' AND item_key='{$escaped}'";
        $winner = $db->fetch_array($db->simple_select(AF_AM_TABLE_OVERRIDES, '*', $where, [
            'order_by'=>'updated_at, created_at', 'order_dir'=>'DESC', 'limit'=>1
        ]));
        if (!$winner) continue;
        $db->delete_query(AF_AM_TABLE_OVERRIDES, $where);
        $db->insert_query(AF_AM_TABLE_OVERRIDES, array_intersect_key($winner, array_flip([
            'item_key','source_addon','enabled','container','section','sortorder','label_override','icon_override','created_at','updated_at'
        ])));
    }
}

function af_menu_ensure_registry_overrides(?array $registry = null): void
{
    global $db;
    $registry = $registry ?? af_menu_collect_registry();
    foreach ($registry as $key => $item) {
        $escaped = $db->escape_string($key);
        $source = (string)$item['source_addon'];
        $sourceEscaped = $db->escape_string($source);
        $where = "source_addon='{$sourceEscaped}' AND item_key='{$escaped}'";
        $exists = (int)$db->fetch_field($db->simple_select(AF_AM_TABLE_OVERRIDES, 'COUNT(*) AS total', $where), 'total');
        if ($exists) {
            // Stage-2 defaults pointed at legacy aliases. Move only pristine
            // rows; an administrator-edited row has a different updated_at.
            $row = $db->fetch_array($db->simple_select(AF_AM_TABLE_OVERRIDES, '*', $where, ['limit'=>1]));
            // Theme used to live in the generic settings section. This is a
            // one-way presentation migration so existing installs get the new
            // dedicated tab even when other placement fields were customized.
            if ($row && $key === 'theme_switcher' && $source === 'mybb'
                && (string)($row['section'] ?? '') === 'settings') {
                $db->update_query(AF_AM_TABLE_OVERRIDES, ['section'=>'theme'], $where);
                $row['section'] = 'theme';
            }
            if ($row && (int)$row['created_at'] === (int)$row['updated_at']
                && ((string)$row['container'] !== (string)$item['default_container']
                    || (string)($row['section'] ?? '') !== (string)$item['section'])) {
                $db->update_query(AF_AM_TABLE_OVERRIDES, [
                    'container'=>$item['default_container'],
                    'section'=>(string)$item['section'],
                    'sortorder'=>(int)$item['default_sortorder'],
                ], $where);
            }
            continue; // Provider reloads must never overwrite administrator choices.
        }
        $db->insert_query(AF_AM_TABLE_OVERRIDES, ['item_key'=>$key, 'source_addon'=>$source, 'enabled'=>1,
            'container'=>$item['default_container'], 'section'=>(string)$item['section'], 'sortorder'=>(int)$item['default_sortorder'],
            'label_override'=>null, 'icon_override'=>null, 'created_at'=>TIME_NOW, 'updated_at'=>TIME_NOW]);
    }
}

function af_menu_get_overrides(): array
{
    global $db;
    $out = [];
    $q = $db->simple_select(AF_AM_TABLE_OVERRIDES, '*');
    while ($row = $db->fetch_array($q)) {
        $identity = strtolower((string)$row['source_addon']).'::'.strtolower((string)$row['item_key']);
        if (!isset($out[$identity]) || [(int)$row['updated_at'], (int)$row['created_at']] > [(int)$out[$identity]['updated_at'], (int)$out[$identity]['created_at']]) {
            $out[$identity] = $row;
        }
    }
    return $out;
}

/** Safely adopt demonstrable manual copies of the three historical provider links. */
function af_menu_migrate_known_provider_copies(array $registry): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    $known = ['advancedappearance::presets'=>true, 'advancedappearance::fitting_room'=>true,
        'advancedpostcounter::post_activity'=>true];
    $normalizeUrl = static function (string $url): string {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return strtolower(ltrim((string)preg_replace('~^https?://[^/]+/~i', '', $url), '/'));
    };
    foreach ($registry as $provider) {
        if (!isset($known[af_menu_provider_identity($provider)])) continue;
        $providerUrl = $normalizeUrl((string)($provider['action']['url'] ?? ''));
        if ($providerUrl === '') continue;
        $q = $db->simple_select(AF_AM_TABLE_ITEMS, '*');
        while ($custom = $db->fetch_array($q)) {
            $urlMatches = $normalizeUrl((string)$custom['url']) === $providerUrl;
            $labelMatches = trim((string)$custom['title']) === trim((string)$provider['label']);
            $keyMatches = strtolower(trim((string)$custom['slug'])) === (string)$provider['key'];
            // A label alone can be legitimate custom content and is never deleted.
            if (!$urlMatches || (!$labelMatches && !$keyMatches)) continue;
            $source = $db->escape_string((string)$provider['source_addon']);
            $key = $db->escape_string((string)$provider['key']);
            $where = "source_addon='{$source}' AND item_key='{$key}'";
            $db->update_query(AF_AM_TABLE_OVERRIDES, [
                'enabled'=>(int)$custom['enabled'],
                'container'=>af_menu_normalize_container((string)($custom['container'] ?? $custom['location'])),
                'section'=>af_menu_normalize_section((string)($custom['section'] ?? 'links')),
                'sortorder'=>(int)$custom['sort_order'],
                'label_override'=>$labelMatches ? null : (string)$custom['title'],
                'icon_override'=>trim((string)$custom['icon']) === trim((string)$provider['icon']) ? null : (string)$custom['icon'],
                'updated_at'=>TIME_NOW,
            ], $where);
            $db->delete_query(AF_AM_TABLE_ITEMS, "id='".(int)$custom['id']."'");
        }
    }
}

function af_menu_configured_registry(bool $ensure = true): array
{
    $registry = af_menu_collect_registry();
    if ($ensure) {
        af_menu_ensure_registry_overrides($registry);
        af_menu_migrate_known_provider_copies($registry);
    }
    $overrides = af_menu_get_overrides();
    foreach ($registry as $key => &$item) {
        $o = $overrides[af_menu_provider_identity($item)] ?? null;
        $item = af_menu_apply_override($item, $o);
    }
    unset($item);
    uasort($registry, static fn($a, $b) => [$a['container'],$a['sortorder'],$a['key']] <=> [$b['container'],$b['sortorder'],$b['key']]);
    return $registry;
}

/** Presentation switch for legacy providers that still own their frontend markup. */
function af_menu_system_item_enabled(string $key): bool
{
    $items = af_menu_configured_registry();
    return !isset($items[$key]) || !empty($items[$key]['enabled']);
}

/* =========================
   BOOTSTRAP / ENSURE
   ========================= */

function af_advancedmenu_ensure_installed(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    global $db, $cache;

    // 1) Таблица
    if (method_exists($db, 'table_exists') && !$db->table_exists(AF_AM_TABLE_ITEMS)) {
        af_advancedmenu_install_db();
    } else {
        // На всякий случай: если table_exists нет (старые обёртки), пробуем CREATE IF NOT EXISTS
        af_advancedmenu_install_db();
    }

    // 2) Настройки (делаем идемпотентно)
    af_advancedmenu_install_settings();

    // 3) Шаблоны (не обязательно, но полезно для кастомизации li)
    af_advancedmenu_sync_templates();

    // 4) Кэш
    if (!is_object($cache)) {
        return;
    }
    $cache->update(AF_AM_CACHE_KEY, null);
}

function af_advancedmenu_install_db(): void
{
    global $db;

    $collation = $db->build_create_table_collation();

    $db->write_query("
        CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `location` varchar(10) NOT NULL DEFAULT 'top',
            `container` varchar(24) NOT NULL DEFAULT 'main',
            `section` varchar(24) NOT NULL DEFAULT 'links',
            `slug` varchar(64) NOT NULL,
            `title` varchar(255) NOT NULL,
            `url` varchar(500) NOT NULL,
            `icon` varchar(255) NOT NULL DEFAULT '',
            `hint` varchar(255) NOT NULL DEFAULT '',
            `sort_order` int NOT NULL DEFAULT 10,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `visibility` varchar(255) NOT NULL DEFAULT '',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_loc_slug` (`location`, `slug`),
            KEY `idx_loc_sort` (`location`, `sort_order`, `id`)
        ) {$collation}
    ");

    $db->write_query("CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX.AF_AM_TABLE_OVERRIDES."` (
        `item_key` varchar(64) NOT NULL, `source_addon` varchar(64) NOT NULL DEFAULT '', `enabled` tinyint(1) NOT NULL DEFAULT 1,
        `container` varchar(24) NOT NULL DEFAULT 'main', `section` varchar(24) NULL, `sortorder` int NOT NULL DEFAULT 100,
        `label_override` varchar(255) NULL, `icon_override` varchar(255) NULL,
        `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`source_addon`,`item_key`), KEY `idx_container_sort` (`container`,`sortorder`)
    ) {$collation}");

    // Мягкий апгрейд: если таблица была создана раньше без icon/hint — добавим.
    if (method_exists($db, 'field_exists')) {
        if (!$db->field_exists('icon', AF_AM_TABLE_ITEMS)) {
            $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` ADD COLUMN `icon` varchar(255) NOT NULL DEFAULT '' AFTER `url`");
        }
        if (!$db->field_exists('hint', AF_AM_TABLE_ITEMS)) {
            $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` ADD COLUMN `hint` varchar(255) NOT NULL DEFAULT '' AFTER `icon`");
        }
        if (!$db->field_exists('container', AF_AM_TABLE_ITEMS)) {
            $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` ADD COLUMN `container` varchar(24) NOT NULL DEFAULT 'main' AFTER `location`");
            $db->write_query("UPDATE `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` SET `container`=CASE WHEN `location`='panel' THEN 'user_drawer' ELSE 'main' END");
        }
        if (!$db->field_exists('section', AF_AM_TABLE_OVERRIDES)) {
            $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_OVERRIDES."` ADD COLUMN `section` varchar(24) NULL AFTER `container`");
        }
        if (!$db->field_exists('source_addon', AF_AM_TABLE_OVERRIDES)) {
            $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_OVERRIDES."` ADD COLUMN `source_addon` varchar(64) NOT NULL DEFAULT '' AFTER `item_key`");
        }
        if (!$db->field_exists('section', AF_AM_TABLE_ITEMS)) {
            $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` ADD COLUMN `section` varchar(24) NOT NULL DEFAULT 'links' AFTER `container`");
        }
    } else {
        // fallback (если внезапно нет field_exists) — не трогаем, чтобы не падать.
    }

    // Old installations used item_key alone as the primary key. Backfill the
    // owner where it is unambiguous, then enforce the canonical pair.
    $registry = af_menu_collect_registry();
    foreach ($registry as $key => $item) {
        $escaped = $db->escape_string((string)$key);
        $db->update_query(AF_AM_TABLE_OVERRIDES, ['source_addon'=>(string)$item['source_addon']], "item_key='{$escaped}' AND source_addon=''");
    }
    $primaryColumns = [];
    $indexes = $db->write_query("SHOW INDEX FROM `".TABLE_PREFIX.AF_AM_TABLE_OVERRIDES."` WHERE Key_name='PRIMARY'");
    while ($index = $db->fetch_array($indexes)) $primaryColumns[(int)$index['Seq_in_index']] = (string)$index['Column_name'];
    ksort($primaryColumns);
    if (array_values($primaryColumns) !== ['source_addon', 'item_key']) {
        $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_OVERRIDES."` DROP PRIMARY KEY, ADD PRIMARY KEY (`source_addon`,`item_key`)");
    }

    af_menu_repair_duplicate_overrides();
    af_menu_ensure_registry_overrides();
}

function af_advancedmenu_install_settings(): void
{
    global $db, $cache;

    $group_title = 'AdvancedMenu — меню шапки';
    $group_desc  = 'Управление пунктами меню в шапке: верхнее меню (top_links) и меню панели пользователя (panel_links). Можно дописывать или полностью заменять, а также скрывать старые пункты по “паттернам”.';

    // 1) Дедупликация групп
    $gids = [];
    $qg = $db->simple_select('settinggroups', 'gid', "name='af_advancedmenu'", ['order_by' => 'gid', 'order_dir' => 'ASC']);
    while ($r = $db->fetch_array($qg)) {
        $gids[] = (int)$r['gid'];
    }

    if (empty($gids)) {
        $db->insert_query('settinggroups', [
            'name'        => 'af_advancedmenu',
            'title'       => $group_title,
            'description' => $group_desc,
            'disporder'   => 100,
            'isdefault'   => 0,
        ]);
        $gid = (int)$db->insert_id();
    } else {
        $gid = (int)$gids[0];

        if (count($gids) > 1) {
            $extras = array_slice($gids, 1);
            foreach ($extras as $badGid) {
                $db->update_query('settings', ['gid' => $gid], "gid='".(int)$badGid."'");
                $db->delete_query('settinggroups', "gid='".(int)$badGid."'");
            }
        }

        $db->update_query('settinggroups', [
            'title'       => $group_title,
            'description' => $group_desc,
            'disporder'   => 100,
            'isdefault'   => 0,
        ], "gid='{$gid}'");
    }

    $ensure = function(
        string $name,
        string $title,
        string $desc,
        string $optionscode,
        string $value,
        int $disporder
    ) use ($db, $gid): void {
        $row = $db->fetch_array($db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]));
        $sid = isset($row['sid']) ? (int)$row['sid'] : 0;

        if ($sid > 0) {
            $db->update_query('settings', [
                'title'       => $title,
                'description' => $desc,
                'optionscode' => $optionscode,
                'disporder'   => $disporder,
                'gid'         => $gid,
            ], "sid='{$sid}'");
            return;
        }

        $db->insert_query('settings', [
            'name'        => $name,
            'title'       => $title,
            'description' => $desc,
            'optionscode' => $optionscode,
            'value'       => $value,
            'disporder'   => $disporder,
            'gid'         => $gid,
        ]);
    };

    $ensure('af_advancedmenu_enabled', 'Включить AdvancedMenu', 'Общий рубильник аддона. Если выключено — меню не меняем вообще.', 'yesno', '1', 1);

    $ensure('af_advancedmenu_top_mode', 'Верхнее меню (top_links): режим',
        "append — оставить старые пункты и добавить новые.\nreplace — заменить содержимое <ul> на ваши пункты (можно сохранить “защищённые” через защищённые паттерны).",
        "select\nappend=append\nreplace=replace", 'append', 10);

    $ensure('af_advancedmenu_panel_mode', 'Меню панели пользователя (panel_links): режим',
        "append — оставить старые пункты и добавить новые.\nreplace — заменить содержимое <ul> на ваши пункты (и при желании сохранить “защищённые”, например колокольчик/аккаунты).",
        "select\nappend=append\nreplace=replace", 'append', 20);

    $ensure('af_advancedmenu_top_hide', 'Верхнее меню: скрыть старые пункты (паттерны)',
        "Через запятую. Если HTML <li> содержит подстроку — пункт будет удалён из top_links.\nПример: private.php, memberlist.php, misc.php?action=help",
        'text', '', 30);

    $ensure('af_advancedmenu_panel_hide', 'Панель пользователя: скрыть старые пункты (паттерны)',
        "Через запятую. Если HTML <li> содержит подстроку — пункт будет удалён из panel_links.\nПример: private.php, search.php?action=getdaily",
        'text', '', 40);

    // НОВОЕ: user_links (buddylink/searchlink/pmslink)
    $ensure('af_advancedmenu_user_hide', 'Меню user_links: скрыть старые пункты (паттерны)',
        "Это <ul class=\"menu user_links\"> из header_welcomeblock_member.\nЧерез запятую. Если HTML <li> содержит подстроку — пункт будет удалён.\nПример: private.php (чтобы убрать ЛС из user_links).",
        'text', '', 45);

    $ensure('af_advancedmenu_panel_protect', 'Панель: защищённые пункты (паттерны)',
        "Работает только в panel_links и только в режиме replace.\nЕсли <li> содержит подстроку из списка — этот пункт сохраняется (например колокольчик/аккаунты).",
        'text', 'af_aam_,af-aam,af_aas_,af-aas,af_aam_header_link,af_aas_switch,action=af_aas,action=af_aam', 50);

    $ensure('af_advancedmenu_panel_protect_pos', 'Панель: позиция защищённых пунктов',
        "start — поставить защищённые пункты перед вашими.\nend — поставить защищённые пункты после ваших.",
        "select\nstart=start\nend=end", 'end', 60);

    $ensure('af_advancedmenu_top_protect', 'Верхнее меню: защищённые пункты (паттерны)',
        "Работает только в top_links и только в режиме replace.\nЕсли <li> содержит подстроку из списка — пункт будет сохранён.",
        'text', '', 65);

    $ensure('af_advancedmenu_top_protect_pos', 'Верхнее меню: позиция защищённых пунктов',
        "start — поставить защищённые пункты перед вашими.\nend — поставить защищённые пункты после ваших.",
        "select\nstart=start\nend=end", 'end', 66);

    $ensure('af_advancedmenu_extra_targets', 'Доп. меню (классы <ul>)',
        "Через запятую: классы <ul>, куда можно вставлять пункты.\nПример: footer_links, my_custom_menu",
        'text', '', 67);

    $ensure(
        'af_advancedmenu_top_strip_imgs',
        'Верхнее меню: убрать <img> у пунктов (паттерны)',
        "Через запятую. Если <li> содержит подстроку — из него будет удалён тег <img ...>.\nПример: search.php (чтобы убрать лупу).",
        'text',
        'search.php',
        68
    );

    $ensure(
        'af_advancedmenu_panel_strip_imgs',
        'Панель: убрать <img> у пунктов (паттерны)',
        "Через запятую. Если <li> содержит подстроку — из него будет удалён тег <img ...>.\nОбычно панель без картинок, но оставим на всякий.",
        'text',
        '',
        69
    );

    $ensure('af_advancedmenu_assets', 'Подключать CSS/JS AdvancedMenu',
        'Если выключено — ассеты не будут внедряться в <head>.',
        'yesno', '1', 70);

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    if (is_object($cache)) {
        $cache->update('settings', null);
    }
}

/* =========================
   TEMPLATES SYNC
   ========================= */

function af_advancedmenu_sync_templates(): void
{
    global $db;

    $src = AF_ADDONS.AF_AM_ID.'/templates/advancedmenu.html';
    if (!is_file($src) || !is_readable($src)) {
        return;
    }

    $html = file_get_contents($src);
    if ($html === false || trim($html) === '') {
        return;
    }

    $templates = af_advancedmenu_parse_templates($html);
    if (empty($templates)) {
        return;
    }

    foreach ($templates as $name => $tpl) {
        $title = $db->escape_string($name);
        $exists = (int)$db->fetch_field($db->simple_select('templates', 'tid', "title='{$title}' AND sid='-1'"), 'tid');

        $data = [
            'title'    => $name,
            'template' => $db->escape_string($tpl),
            'sid'      => -1,
            'version'  => 1800,
            'dateline' => TIME_NOW,
        ];

        if ($exists > 0) {
            $db->update_query('templates', $data, "tid='{$exists}'");
        } else {
            $db->insert_query('templates', $data);
        }
    }
}

/**
 * Парсер шаблонов из файла advancedmenu.html:
 * <!-- TEMPLATE: tpl_name --> ...html...
 */
function af_advancedmenu_parse_templates(string $html): array
{
    $out = [];

    $pattern = '~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-]+)\s*-->\s*(.*?)\s*(?=(<!--\s*TEMPLATE:\s*[a-zA-Z0-9_\-]+\s*-->|$))~si';
    if (!preg_match_all($pattern, $html, $m, PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($m as $row) {
        $name = trim($row[1]);
        $tpl  = trim($row[2]);
        if ($name !== '' && $tpl !== '') {
            $out[$name] = $tpl;
        }
    }

    return $out;
}

/* =========================
   LANG
   ========================= */

function af_advancedmenu_load_lang(bool $admin = false): void
{
    global $lang;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    // AF core обычно сам подцепляет advancedfunctionality_{addon}.lang.php
    // но тут мягкая подстраховка.
    $file = $admin ? 'advancedfunctionality_advancedmenu' : 'advancedfunctionality_advancedmenu';
    if (method_exists($lang, 'load')) {
        // (true,true) в MyBB = admin/lang fallback, зависит от версии; лишним не будет
        $lang->load($file, true, true);
    }
}

/* =========================
   DATA / CACHE
   ========================= */

function af_advancedmenu_get_items(bool $force_db = false): array
{
    global $cache, $db;

    if (!$force_db && is_object($cache)) {
        $cached = $cache->read(AF_AM_CACHE_KEY);
        if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
            return $cached['items'];
        }
    }

    $items = [];
    $q = $db->simple_select(AF_AM_TABLE_ITEMS, '*', '', ['order_by' => 'location, sort_order, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $items[] = $row;
    }

    if (is_object($cache)) {
        $cache->update(AF_AM_CACHE_KEY, ['items' => $items, 'ts' => TIME_NOW]);
    }

    return $items;
}

function af_advancedmenu_rebuild_cache(): void
{
    global $cache;
    if (is_object($cache)) {
        $cache->update(AF_AM_CACHE_KEY, null);
    }
    af_advancedmenu_get_items(true);
}

/* =========================
   RENDER HELPERS
   ========================= */

function af_advancedmenu_normalize_url(string $url): string
{
    global $mybb;

    $url = trim($url);
    if ($url === '') {
        return '#';
    }

    // Уже абсолютная
    if (preg_match('~^(https?:)?//~i', $url)) {
        return $url;
    }

    // bburl
    $bburl = isset($mybb->settings['bburl']) ? rtrim($mybb->settings['bburl'], '/') : '';

    // относительная от корня
    if ($url[0] === '/') {
        return $bburl.$url;
    }

    // относительная "page.php"
    return $bburl.'/'.ltrim($url, '/');
}

/** Expand the small, explicit URL variable registry at render time only. */
function af_advancedmenu_expand_url_placeholders(string $url): ?string
{
    global $mybb;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if (strpos($url, '{uid}') !== false && $uid <= 0) return null;
    return strtr($url, ['{uid}' => (string)$uid, '{username}' => rawurlencode((string)($mybb->user['username'] ?? ''))]);
}

/** Match the path and every query parameter required by the menu URL. */
function af_advancedmenu_url_is_active(string $url): bool
{
    global $mybb;
    $expanded = af_advancedmenu_expand_url_placeholders(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($expanded === null || $expanded === '' || $expanded === '#') return false;
    $target = parse_url(af_advancedmenu_normalize_url($expanded));
    if (!is_array($target) || empty($target['path'])) return false;
    $board = parse_url((string)($mybb->settings['bburl'] ?? ''));
    if (!empty($target['host']) && (!is_array($board) || strcasecmp((string)$target['host'], (string)($board['host'] ?? '')) !== 0)) return false;
    $request = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''));
    if (!is_array($request)) return false;
    $normalPath = static fn(string $path): string => rtrim('/'.ltrim($path, '/'), '/') ?: '/';
    if ($normalPath((string)($target['path'] ?? '')) !== $normalPath((string)($request['path'] ?? ''))) return false;
    parse_str((string)($target['query'] ?? ''), $required);
    parse_str((string)($request['query'] ?? ''), $actual);
    foreach ($required as $key => $value) {
        if (!array_key_exists($key, $actual) || (string)$actual[$key] !== (string)$value) return false;
    }
    return true;
}

function af_advancedmenu_parse_csv(string $csv): array
{
    $csv = (string)$csv;

    // поддерживаем ввод:
    // - через запятую
    // - через точку с запятой
    // - через перенос строки
    // - через табы/множественные пробелы (как разделители)
    //
    // Пример (всё ок):
    // usercp.php
    // memberlist.php, private.php; search.php
    //
    $parts = preg_split('~[,\r\n;]+~', $csv);

    if (!is_array($parts)) {
        $parts = [$csv];
    }

    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);

        // если кто-то вставил несколько паттернов через пробелы,
        // но без запятых/переносов — разобьём дополнительно
        if ($p !== '' && preg_match('~\s{2,}~', $p)) {
            foreach (preg_split('~\s{2,}~', $p) as $pp) {
                $pp = trim((string)$pp);
                if ($pp !== '') {
                    $out[] = $pp;
                }
            }
            continue;
        }

        if ($p !== '') {
            $out[] = $p;
        }
    }

    return array_values(array_unique($out));
}

function af_advancedmenu_get_user_group_ids(): array
{
    global $mybb;

    $ids = [];

    $ug = isset($mybb->user['usergroup']) ? (int)$mybb->user['usergroup'] : 0;
    if ($ug > 0) {
        $ids[] = $ug;
    }

    $add = isset($mybb->user['additionalgroups']) ? (string)$mybb->user['additionalgroups'] : '';
    if ($add !== '') {
        foreach (explode(',', $add) as $g) {
            $g = (int)trim($g);
            if ($g > 0) {
                $ids[] = $g;
            }
        }
    }

    $ids = array_values(array_unique($ids));
    return $ids;
}

/**
 * visibility форматы:
 *  - ''            => всем
 *  - 'guests'      => только гостям
 *  - 'users'       => только авторизованным
 *  - 'groups:1,4'  => только группам (gid)
 */
function af_advancedmenu_item_is_visible(array $item): bool
{
    global $mybb;

    $isGuest = empty($mybb->user['uid']) || (int)$mybb->user['uid'] === 0;

    // авто-санити: usercp/private для гостя скрываем даже если админ забыл visibility
    if ($isGuest) {
        $rawUrl = isset($item['url']) ? (string)$item['url'] : '';
        if (strpos($rawUrl, '{uid}') !== false) return false;
        if ($rawUrl !== '') {
            $u = strtolower($rawUrl);
            if (strpos($u, 'usercp.php') !== false || strpos($u, 'private.php') !== false) {
                return false;
            }
        }
    }

    $vis = isset($item['visibility']) ? trim((string)$item['visibility']) : '';
    if ($vis === '' || strcasecmp($vis, 'all') === 0) {
        return true;
    }

    if (strcasecmp($vis, 'guest') === 0 || strcasecmp($vis, 'guests') === 0) {
        return $isGuest;
    }

    if (
        strcasecmp($vis, 'user') === 0 ||
        strcasecmp($vis, 'users') === 0 ||
        strcasecmp($vis, 'members') === 0 ||
        strcasecmp($vis, 'registered') === 0
    ) {
        return !$isGuest;
    }

    if (stripos($vis, 'groups:') === 0) {
        $list = trim(substr($vis, 7));
        if ($list === '') {
            return false;
        }

        $allowed = [];
        foreach (explode(',', $list) as $g) {
            $g = (int)trim($g);
            if ($g > 0) {
                $allowed[] = $g;
            }
        }
        if (empty($allowed)) {
            return false;
        }

        $userGids = af_advancedmenu_get_user_group_ids();
        foreach ($userGids as $gid) {
            if (in_array((int)$gid, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    // неизвестный формат — безопаснее скрыть
    return false;
}


/**
 * Очень простая "санитарка" под админский ввод:
 * - режем <script>
 * - режем on*=
 */
function af_advancedmenu_sanitize_icon_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Если в БД/инпуте прилетело &lt;img ...&gt; — раскодируем обратно в HTML
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // На всякий случай — если там двойная кодировка, декодим второй раз (но аккуратно)
    if (strpos($decoded, '&lt;') !== false || strpos($decoded, '&amp;lt;') !== false) {
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $html = trim($decoded);

    // режем <script>
    $html = preg_replace('~<\s*script\b.*?>.*?<\s*/\s*script\s*>~si', '', $html);

    // режем on*=
    $html = preg_replace('~\son\w+\s*=\s*(["\']).*?\1~si', '', $html);

    // примитивная защита от javascript: в href/src
    $html = preg_replace('~\b(href|src)\s*=\s*(["\'])\s*javascript\s*:[^"\']*\2~si', '$1=$2#$2', $html);

    return trim((string)$html);
}

function af_advancedmenu_apply_strip_img_patterns(string $ulInner, array $patterns): string
{
    $patterns = array_values(array_filter(array_map('trim', $patterns), static function($v) {
        return $v !== '';
    }));

    if (empty($patterns)) {
        return $ulInner;
    }

    $lis = af_advancedmenu_split_li($ulInner);
    if (empty($lis)) {
        return $ulInner;
    }

    $out = [];
    foreach ($lis as $li) {
        $match = false;
        foreach ($patterns as $p) {
            if (stripos($li, $p) !== false) {
                $match = true;
                break;
            }
        }

        if ($match) {
            // вырезаем <img ...> внутри этого li
            $li = preg_replace('~<img\b[^>]*>~i', '', $li);
        }

        $out[] = $li;
    }

    return implode("\n", $out);
}

function af_advancedmenu_css_escape(string $s): string
{
    // безопасно для вставки в CSS-строку в одинарных/двойных кавычках
    $s = (string)$s;
    $s = str_replace(["\\", "\r", "\n", "\t"], ["\\\\", " ", " ", " "], $s);
    $s = str_replace(["'", '"'], ["\\'", '\\"'], $s);
    // режем управляющие
    $s = preg_replace('~[\x00-\x1F\x7F]~', '', $s);
    return (string)$s;
}

/**
 * Понимает icon в форматах:
 *  - 🔔 (эмодзи/текст)
 *  - https://site/icon.png или /images/icon.svg (url картинки)
 *  - fa-solid fa-bell  (FontAwesome классы)
 *  - legacy: <img src="..."> или <i class="..."></i> (подхватим)
 */
function af_advancedmenu_icon_parse(string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['type' => 'none', 'value' => ''];
    }

    // если прилетело в виде &lt;img ...&gt; — декодим
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = trim($decoded);

    // legacy <img src="...">
    if (stripos($decoded, '<img') !== false) {
        if (preg_match('~\bsrc\s*=\s*(["\'])(.*?)\1~si', $decoded, $m)) {
            $src = trim((string)$m[2]);
            if ($src !== '') {
                return ['type' => 'img', 'value' => $src];
            }
        }
    }

    // legacy <i class="...">
    if (stripos($decoded, '<i') !== false) {
        if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~si', $decoded, $m)) {
            $cls = trim((string)$m[2]);
            if ($cls !== '') {
                return ['type' => 'fa', 'value' => $cls];
            }
        }
    }

    // если похоже на URL картинки
    if (preg_match('~^(https?:)?//~i', $decoded) || str_starts_with($decoded, '/') || str_ends_with(strtolower($decoded), '.png') || str_ends_with(strtolower($decoded), '.jpg') || str_ends_with(strtolower($decoded), '.jpeg') || str_ends_with(strtolower($decoded), '.gif') || str_ends_with(strtolower($decoded), '.svg') || str_ends_with(strtolower($decoded), '.webp')) {
        return ['type' => 'img', 'value' => $decoded];
    }

    // если похоже на FontAwesome классы
    if (preg_match('~\bfa[a-z-]*\b~i', $decoded) || preg_match('~\bfa-[a-z0-9-]+\b~i', $decoded)) {
        return ['type' => 'fa', 'value' => $decoded];
    }

    // иначе — текст/эмодзи
    return ['type' => 'text', 'value' => $decoded];
}

function af_advancedmenu_render_item(array $item): string
{
    global $templates;

    $location = ($item['location'] === 'panel') ? 'panel' : 'top';
    $slug     = (string)$item['slug'];
    $title    = htmlspecialchars_uni((string)$item['title']);
    $expandedUrl = af_advancedmenu_expand_url_placeholders((string)$item['url']);
    if ($expandedUrl === null) return '';
    $url      = htmlspecialchars_uni(af_advancedmenu_normalize_url($expandedUrl));
    $activeClass = af_advancedmenu_url_is_active((string)$item['url']) ? ' is-active' : '';

    // ПОДСКАЗКА (tooltip)
    // Делаем "двойной" вывод:
    // - data-af-am-tip для нашего CSS-tooltip
    // - title как нативный fallback (и полезно для доступности)
    $hintRaw = isset($item['hint']) ? (string)$item['hint'] : '';
    $hintRaw = trim($hintRaw);

    $hintAttr = '';
    if ($hintRaw !== '') {
        $hintRaw = strip_tags($hintRaw);
        $hintRaw = preg_replace('~\s+~u', ' ', $hintRaw);
        $hintRaw = trim((string)$hintRaw);

        if ($hintRaw !== '') {
            $hintEsc = htmlspecialchars_uni($hintRaw);
            $hintAttr = ' data-af-am-tip="' . $hintEsc . '" title="' . $hintEsc . '"';
        }
    }

    // ИКОНКА
    $iconRaw = isset($item['icon']) ? (string)$item['icon'] : '';
    $icon    = af_advancedmenu_icon_parse($iconRaw);

    $iconHtml = '';
    if ($icon['type'] === 'text' && $icon['value'] !== '') {
        $txt = af_advancedmenu_css_escape((string)$icon['value']);
        $style = '--af-am-icon-text:"'.$txt.'";';
        $iconHtml = '<span class="af-am-ico af-am-ico-text" style="'.htmlspecialchars_uni($style).'" aria-hidden="true"></span>';
    } elseif ($icon['type'] === 'img' && $icon['value'] !== '') {
        $src = af_advancedmenu_normalize_url((string)$icon['value']);
        $srcEsc = af_advancedmenu_css_escape($src);
        $style = "--af-am-icon-url:url('".$srcEsc."');";
        $iconHtml = '<span class="af-am-ico af-am-ico-img" style="'.htmlspecialchars_uni($style).'" aria-hidden="true"></span>';
    } elseif ($icon['type'] === 'fa' && $icon['value'] !== '') {
        $cls = preg_replace('~[^a-z0-9_\-\s]~i', '', (string)$icon['value']);
        $cls = trim(preg_replace('~\s+~', ' ', $cls));

        if ($cls !== '') {
            if (!preg_match('~\bfa-(solid|regular|brands|light|thin|duotone)\b~i', $cls)) {
                $cls = 'fa-solid '.$cls;
            }
            if (!preg_match('~\bfa-[a-z0-9-]+\b~i', $cls)) {
                $cls = '';
            }
        }

        if ($cls !== '') {
            $iconHtml = '<i class="af-am-ico af-am-ico-fa '.htmlspecialchars_uni($cls).'" aria-hidden="true"></i>';
        }
    }

    $tplName = 'af_advancedmenu_item';
    $tpl = '';
    if (is_object($templates) && method_exists($templates, 'get')) {
        $tpl = (string)$templates->get($tplName);
    }

    if ($tpl !== '') {
        $af_am_location = $location;
        $af_am_slug     = $slug;
        $af_am_title    = $title;
        $af_am_url      = $url;
        $af_am_icon     = $iconHtml;
        $af_am_active_class = $activeClass;

        // ВАЖНО: оставляем переменную как ты уже делала, но теперь она несёт и data и title.
        $af_am_hint_attr = $hintAttr;

        $out = '';
        eval("\$out = \"$tpl\";");
        if ($activeClass !== '' && strpos($out, 'is-active') === false) {
            $out = (string)preg_replace('~class=(["\'])([^"\']*\baf-am-item\b[^"\']*)\1~i', 'class=$1$2 is-active$1', $out, 1);
        }
        return (string)$out;
    }

    // fallback HTML (если нет шаблона)
    return '<li class="af-am-item af-am-'.$location.' af-am-'.$slug.$activeClass.'"><a class="af-am-link" href="'.$url.'"'.$hintAttr.'>'.$iconHtml.'<span class="af-am-title">'.$title.'</span></a></li>';
}

function af_advancedmenu_resolve_target(string $location): string
{
    $loc = trim(strtolower($location));

    // Обратная совместимость: старые значения 'top'/'panel'
    if ($loc === 'top') {
        return 'top_links';
    }
    if ($loc === 'panel') {
        return 'panel_links';
    }

    // Новые “типы меню”: разрешаем указывать прямо класс UL (например footer_links)
    // Оставим только безопасные символы класса
    $loc = preg_replace('~[^a-z0-9_\-]~i', '', $loc);
    return $loc !== '' ? $loc : 'top_links';
}

function af_advancedmenu_build_menu_html(string $targetUlClass): string
{
    $items = af_advancedmenu_get_items();
    $target = af_advancedmenu_resolve_target($targetUlClass);

    $out = '';
    foreach ($items as $it) {
        if ((int)$it['enabled'] !== 1) {
            continue;
        }

        if (!af_advancedmenu_item_is_visible($it)) {
            continue;
        }

        $itemTarget = af_advancedmenu_resolve_target((string)$it['location']);
        if ($itemTarget !== $target) {
            continue;
        }

        $out .= af_advancedmenu_render_item($it)."\n";
    }

    return $out;
}

/** Render a registry item without changing the trigger contract owned by its addon. */
function af_advancedmenu_render_registry_item(array $item): string
{
    $key = preg_replace('~[^a-z0-9_-]~i', '', (string)$item['key']);
    $label = htmlspecialchars_uni((string)$item['label']);
    $action = (array)($item['action'] ?? []);
    $type = (string)($item['type'] ?? 'link');
    if ($type === 'widget') return af_advancedmenu_render_widget($item);
    $providerRenderer = $action['trigger_renderer'] ?? null;
    if ($type === 'modal' && is_callable($providerRenderer)) return (string)$providerRenderer($item);
    $classes = 'af-am-link af-am-system-link';
    $attrs = '';

    if ($type === 'modal') {
        $classes .= ' af-am-modal-trigger';
        $triggerClass = preg_replace('~[^a-z0-9 _-]~i', '', (string)($action['trigger_class'] ?? ''));
        if ($triggerClass !== '') $classes .= ' '.$triggerClass;
        // Keep a real provider URL when one is declared.  JavaScript still
        // intercepts the modal trigger, while no-JS/error paths retain the
        // owner's fallback instead of becoming a dead "#" link.
        $rawUrl = html_entity_decode((string)($action['url'] ?? '#'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = $rawUrl === '#' ? '#' : af_advancedmenu_normalize_url($rawUrl);
        $selector = (string)($action['trigger_selector'] ?? '');
        if (preg_match('~^#([a-z][a-z0-9_-]*)$~i', $selector, $match)) {
            $attrs .= ' id="'.htmlspecialchars_uni($match[1]).'"';
        }
        $modal = (string)($action['modal_selector'] ?? '');
        if ($modal !== '') $attrs .= ' data-af-am-modal="'.htmlspecialchars_uni($modal).'" aria-haspopup="dialog" aria-expanded="false"';
        if (($action['handler'] ?? '') === 'MyBB.popupWindow') $attrs .= ' onclick="MyBB.popupWindow(this.href); return false;"';
    } else {
        $href = af_advancedmenu_normalize_url((string)($action['url'] ?? '#'));
        if ($type === 'link' && af_advancedmenu_url_is_active((string)($action['url'] ?? ''))) $classes .= ' is-active';
    }

    $icon = '';
    $iconClasses = preg_replace('~[^a-z0-9 _-]~i', '', (string)($item['icon'] ?? ''));
    if ($iconClasses !== '') $icon = '<i class="af-am-ico '.htmlspecialchars_uni($iconClasses).'" aria-hidden="true"></i>';
    $badge = af_menu_item_badge($item);
    $badgeHtml = ($badge !== null && (int)$badge > 0)
        ? '<span class="af-am-badge" aria-label="'.htmlspecialchars_uni((string)$badge).'">'.htmlspecialchars_uni((string)$badge).'</span>' : '';

    return '<li class="af-am-item af-am-system af-am-'.$key.'"><a class="'.$classes.'" href="'.htmlspecialchars_uni($href).'"'.$attrs.'>'
        .$icon.'<span class="af-am-title">'.$label.'</span>'.$badgeHtml.'</a></li>';
}

/**
 * Render provider-owned interactive content without manufacturing an URL.
 * Providers receive their registered item (including widget_config) and own
 * the controls and persistence mechanism; AdvancedMenu owns only placement.
 */
function af_advancedmenu_render_widget(array $item): string
{
    $renderer = $item['renderer'] ?? null;
    if (!is_callable($renderer)) return '';
    $content = (string)$renderer($item);
    if (trim($content) === '') return '';
    $key = preg_replace('~[^a-z0-9_-]~i', '', (string)($item['key'] ?? 'widget'));
    $label = htmlspecialchars_uni((string)($item['label'] ?? ''));
    return '<li class="af-am-widget af-am-widget-'.$key.'" data-af-am-widget="'.$key.'">'
        .($label !== '' ? '<div class="af-am-widget-label">'.$label.'</div>' : '')
        .'<div class="af-am-widget-content">'.$content.'</div></li>';
}

/** Reuse MyBB's footer theme provider, including its real options and submit flow. */
function af_advancedmenu_render_theme_widget(array $item = []): string
{
    global $theme_select;
    $content = trim((string)($theme_select ?? ''));
    if ($content === '') $content = '<p class="af-am-preference-notice">Выбор темы недоступен: он ограничен настройками форума.</p>';
    // MyBB's theme selector already saves on change; its submit button is redundant.
    if (preg_match('~<select\b~i', $content)) {
        $content = preg_replace('~<input\b(?=[^>]*\btype=["\']submit["\'])[^>]*>|<button\b(?=[^>]*\btype=["\']submit["\'])[^>]*>.*?</button>~is', '', $content);
    }
    // AdvancedMenu is only the widget host. Presentation owners may append
    // independent controls without teaching navigation how they are stored.
    foreach (($GLOBALS['af_theme_switcher_preference_providers'] ?? []) as $provider) {
        if (is_callable($provider)) {
            $content .= (string)$provider($item);
        }
    }
    return $content;
}

/** Merge provider and custom items using the single ACP sort order. */
function af_advancedmenu_build_container_html(string $container): string
{
    $rows = [];
    $seenProviders = [];
    foreach (af_menu_configured_registry() as $item) {
        if (($item['container'] ?? '') !== $container || empty($item['enabled']) || !af_menu_item_is_visible($item)) continue;
        $identity = (string)($item['canonical_identity'] ?? af_menu_provider_identity($item));
        if (isset($seenProviders[$identity])) continue;
        $seenProviders[$identity] = true;
        $rows[] = ['sort'=>(int)$item['sortorder'], 'key'=>(string)$item['key'], 'html'=>af_advancedmenu_render_registry_item($item)];
    }
    foreach (af_advancedmenu_get_items() as $item) {
        $itemContainer = af_menu_normalize_container((string)($item['container'] ?? $item['location'] ?? 'main'));
        if ($itemContainer !== $container || (int)$item['enabled'] !== 1 || !af_advancedmenu_item_is_visible($item)) continue;
        $rows[] = ['sort'=>(int)$item['sort_order'], 'key'=>'custom_'.(int)$item['id'], 'html'=>af_advancedmenu_render_item($item)];
    }
    usort($rows, static fn(array $a, array $b): int => [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']]);
    return implode("\n", array_column($rows, 'html'));
}

/** Drawer sections are presentation metadata; items retain the global ACP order. */
function af_advancedmenu_build_drawer_html(): string
{
    $sections = af_menu_sections();
    if (empty($GLOBALS['mybb']->user['uid'])) $sections = array_intersect_key($sections, ['theme'=>true]);
    $items = af_menu_configured_registry();
    $custom = af_advancedmenu_get_items();
    $seenProviders = [];
    $panels = [];

    foreach ($sections as $section => $title) {
        $rows = [];
        foreach ($items as $item) {
            if (($item['container'] ?? '') !== 'user_drawer' || ($item['section'] ?? 'links') !== $section
                || empty($item['enabled']) || !af_menu_item_is_visible($item)) continue;
            $identity = (string)($item['canonical_identity'] ?? af_menu_provider_identity($item));
            if (isset($seenProviders[$identity])) continue;
            $seenProviders[$identity] = true;
            $html = af_advancedmenu_render_registry_item($item);
            if ($html !== '') $rows[] = ['sort'=>(int)$item['sortorder'], 'key'=>(string)$item['key'], 'html'=>$html];
        }
        foreach ($custom as $item) {
            if (af_menu_normalize_container((string)($item['container'] ?? $item['location'] ?? 'main')) !== 'user_drawer'
                || af_menu_normalize_section((string)($item['section'] ?? 'links')) !== $section
                || (int)$item['enabled'] !== 1 || !af_advancedmenu_item_is_visible($item)) continue;
            $rows[] = ['sort'=>(int)$item['sort_order'], 'key'=>'custom_'.(int)$item['id'], 'html'=>af_advancedmenu_render_item($item)];
        }
        // Keep the four registered sections addressable even when ACP hides every item.
        if (!$rows) $rows[] = ['sort'=>0, 'key'=>'empty', 'html'=>'<li class="af-am-empty">Нет доступных пунктов</li>'];
        usort($rows, static fn(array $a, array $b): int => [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']]);
        $panels[$section] = [
            'title'=>$title,
            'html'=>implode("\n", array_column($rows, 'html')),
        ];
    }

    if (!$panels) return '';

    $tabs = '';
    $content = '';
    $first = true;
    foreach ($panels as $section => $panel) {
        $sectionEsc = htmlspecialchars_uni($section);
        $titleEsc = htmlspecialchars_uni((string)$panel['title']);
        $tabId = 'af-am-tab-'.$sectionEsc;
        $panelId = 'af-am-panel-'.$sectionEsc;
        $activeClass = $first ? ' is-active' : '';

        $tabs .= '<button type="button" class="af-am-drawer-tab'.$activeClass.'" id="'.$tabId.'" role="tab"'
            .' aria-selected="'.($first ? 'true' : 'false').'" aria-controls="'.$panelId.'" tabindex="'.($first ? '0' : '-1').'"'
            .' data-af-am-tab="'.$sectionEsc.'">'.$titleEsc.'</button>';
        $content .= '<section class="af-am-drawer-panel'.$activeClass.'" id="'.$panelId.'" role="tabpanel"'
            .' aria-labelledby="'.$tabId.'" data-af-am-panel="'.$sectionEsc.'"'.($first ? '' : ' hidden').'>'
            .'<ul class="af-am-drawer-list">'.$panel['html'].'</ul></section>';
        $first = false;
    }

    return '<div class="af-am-drawer-tabs" data-af-am-tabs="1">'
        .'<div class="af-am-drawer-tablist" role="tablist" aria-label="Разделы меню пользователя">'.$tabs.'</div>'
        .'<div class="af-am-drawer-panels">'.$content.'</div></div>';
}



/** Guest account actions; members use the rail avatar and registry sections. */
function af_advancedmenu_render_drawer_account(): string
{
    // Identity and login actions live only on the rail.
    return '';
}

/** Avatar ownership and last-visit formatting stay with existing providers. */
function af_advancedmenu_render_user_avatar(): string
{
    global $mybb, $lang;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        $guest = ['uid'=>0, 'username'=>'Гость', 'avatar'=>''];
        $avatar = function_exists('af_avatar_render') ? af_avatar_render($guest, 'drawer', ['img_class'=>'af-am-control-avatar-image', 'decorative'=>true, 'allow_letter'=>false]) : '';
        if ($avatar === '') {
            $src = str_replace('{theme}', (string)($GLOBALS['theme']['imgdir'] ?? 'images'), (string)($mybb->settings['useravatar'] ?? 'images/default_avatar.png'));
            $avatar = '<img class="af-am-control-avatar-image" src="'.htmlspecialchars_uni($src).'" alt="">';
        }
        return '<div class="af-am-avatar-control"><button type="button" class="af-am-guest-avatar" aria-label="Добро пожаловать, гость!" aria-describedby="af-am-account-tooltip">'.$avatar.'</button>'
            .'<div id="af-am-account-tooltip" class="af-am-account-tooltip" role="tooltip" hidden>Добро пожаловать, гость!</div></div>';
    }
    $name = htmlspecialchars_uni((string)($mybb->user['username'] ?? 'User'));
    $avatar = function_exists('af_avatar_render')
        ? af_avatar_render((array)$mybb->user, 'drawer', ['img_class'=>'af-am-control-avatar-image']) : '';
    if ($avatar === '') {
        // Core MyBB fallback when the shared AF provider is inactive.
        $image = function_exists('format_avatar') ? format_avatar((string)($mybb->user['avatar'] ?? ''), '36x36') : [];
        $src = (string)(($image['image'] ?? '') ?: ($mybb->settings['useravatar'] ?? 'images/default_avatar.png'));
        $src = str_replace('{theme}', (string)($GLOBALS['theme']['imgdir'] ?? 'images'), $src);
        $avatar = '<a href="member.php?action=profile&amp;uid='.$uid.'"><img class="af-am-control-avatar-image" src="'.htmlspecialchars_uni($src).'" alt="'.$name.'"></a>';
    }
    // The provider already returns a profile anchor: never nest another link.
    $avatar = preg_replace_callback('/<a\b/i', static fn() => '<a aria-describedby="af-am-account-tooltip" aria-label="Профиль: '.$name.'"', $avatar, 1);
    $visit = (int)($mybb->user['lastvisit'] ?? 0);
    $date = $visit > 0 && function_exists('my_date') ? (string)my_date('relative', $visit) : '—';
    return '<div class="af-am-avatar-control">'.$avatar
        .'<button type="button" class="af-am-account-info" aria-label="Информация об аккаунте" aria-controls="af-am-account-tooltip" aria-expanded="false">i</button>'
        .'<div id="af-am-account-tooltip" class="af-am-account-tooltip" role="tooltip" hidden><strong>Добро пожаловать!</strong><span>'.$name.'</span><small>'
        .htmlspecialchars_uni((string)($lang->welcome_lastvisit ?? 'Последний визит: ')).$date.'</small></div></div>';
}

function af_advancedmenu_render_user_controls(): string
{
    $sections = af_menu_sections();
    if (empty($GLOBALS['mybb']->user['uid'])) $sections = ['theme'=>'Оформление'];
    $html = '<nav class="af-am-user-controls" aria-label="Управление аккаунтом">';
    $icons = ['profile'=>'fa-user', 'links'=>'fa-link', 'settings'=>'fa-gear', 'theme'=>'fa-palette'];
    foreach ($sections as $section => $label) {
        $html .= '<button type="button" class="af-am-category-control" data-af-am-category="'.$section.'" aria-label="'.htmlspecialchars_uni($label).'" aria-controls="af-am-user-drawer" aria-expanded="false"><i class="fa-solid '.$icons[$section].'" aria-hidden="true"></i></button>';
    }
    return $html.'</nav>';
}

/**
 * Guest navigation retains the existing MyBB login/register actions.
 */
function af_advancedmenu_render_guest_account_bar(): string
{
    global $mybb;

    if (!empty($mybb->user['uid'])) {
        return '';
    }

    return '<section class="af-am-guest-account" aria-label="Гостевой аккаунт">'
        .'<span class="af-am-guest-greeting">Привет, гость</span>'
        .'<div class="af-am-guest-actions">'
        .'<a class="af-am-guest-action af-am-guest-action--login" href="member.php?action=login" aria-label="Войти" data-af-am-tip="Войти"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span class="af-am-title">Войти</span></a>'
        .'<a class="af-am-guest-action af-am-guest-action--register" href="member.php?action=register" aria-label="Регистрация" data-af-am-tip="Регистрация"><i class="fa-solid fa-user-plus" aria-hidden="true"></i><span class="af-am-title">Регистрация</span></a>'
        .'</div></section>';
}

function af_advancedmenu_render_frontend_nav(): string
{
    $main = af_advancedmenu_build_container_html('main');
    $secondary = af_advancedmenu_build_container_html('secondary');
    $drawer = af_advancedmenu_build_drawer_html();
    // The flow wrapper measures zero top occlusion for the existing ATF sticky
    // contract. Its fixed child owns the whole rail, including every category.
    return '<div class="af-am-shell af-am-navigation" data-af-am-navigation="1">'
        .'<div class="af-am-rail"><div class="af-am-rail-scroll">'
        .af_advancedmenu_render_user_avatar().af_advancedmenu_render_guest_account_bar()
        .(trim($main) !== '' ? '<nav class="af-am-bar af-am-main" aria-label="Основное меню"><ul class="af-am-list">'.$main.'</ul></nav>' : '')
        .(trim($secondary) !== '' ? '<nav class="af-am-bar af-am-secondary" aria-label="Дополнительное меню"><ul class="af-am-list">'.$secondary.'</ul></nav>' : '')
        .af_advancedmenu_render_user_controls()
        .'</div></div></div><div class="af-am-drawer-shell" data-af-am-drawer-shell hidden>'
        .'<button class="af-am-drawer-overlay" type="button" tabindex="-1" aria-label="Закрыть пользовательское меню"></button>'
        .'<aside id="af-am-user-drawer" class="af-am-drawer" role="dialog" aria-modal="true" aria-label="Пользовательское меню" tabindex="-1">'
        .'<div class="af-am-drawer-header"><strong data-af-am-drawer-title>Меню пользователя</strong><button class="af-am-drawer-close" type="button" aria-label="Закрыть пользовательское меню">&times;</button></div>'
        .'<div class="af-am-drawer-body">'.af_advancedmenu_render_drawer_account().$drawer.'</div></aside></div>';
}

/** Remove duplicate legacy controls while retaining provider-owned runtime. */
function af_advancedmenu_remove_legacy_modal_triggers(string $page): string
{
    return (string)preg_replace(
        '~<a\b[^>]*id=["\'](?:af_aas_trigger|af_aam_header_link)["\'][^>]*>(?:(?!<a\b|</a\s*>).)*</a\s*>~is',
        '',
        $page
    );
}

function af_advancedmenu_install_frontend_nav(string &$page): void
{
    global $theme_select;
    if (strpos($page, 'data-af-am-navigation="1"') !== false) return;
    // Content-only documents (for example a Character Sheet iframe) keep the
    // shared head/assets, but deliberately opt out of all site navigation.
    if (preg_match('~<body\b[^>]*\bdata-af-layout\s*=\s*(["\'])content-only\1~i', $page)) return;

    // The modal implementations bind to these stable IDs. Remove only the
    // legacy anchors, never their surrounding provider widget. In particular,
    // AAS places its modal after the anchor inside the same template variable;
    // matching from <li> to a later </li> can therefore consume the modal and
    // the beginning of the next menu item.
    $page = af_advancedmenu_remove_legacy_modal_triggers($page);

    $nav = af_advancedmenu_render_frontend_nav();
    $memberBodyClass = !empty($GLOBALS['mybb']->user['uid']) ? ' af-am-member' : ' af-am-guest';
    // MyBB.changeTheme() addresses the provider form by its stable id. Move
    // that exact form into the drawer instead of cloning it into invalid DOM.
    if (strpos($nav, 'data-af-am-widget="theme_switcher"') !== false && !empty($theme_select)) {
        $page = str_replace((string)$theme_select, '', $page);
    }
    $page = (string)preg_replace_callback('~<body\b([^>]*)>~i', static function (array $m) use ($nav, $memberBodyClass): string {
        $attrs = $m[1];
        if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~i', $attrs)) {
            $attrs = (string)preg_replace('~\bclass\s*=\s*(["\'])(.*?)\1~i', 'class=$1$2 af-advancedmenu-layout'.$memberBodyClass.'$1', $attrs, 1);
        } else {
            $attrs .= ' class="af-advancedmenu-layout'.$memberBodyClass.'"';
        }
        return '<body'.$attrs.'>'.$nav;
    }, $page, 1);
}

function af_advancedmenu_set_template_vars(): void
{
    $items = af_advancedmenu_get_items();

    $topAgg   = '';
    $panelAgg = '';

    foreach ($items as $it) {
        if ((int)$it['enabled'] !== 1) {
            continue;
        }

        if (!af_advancedmenu_item_is_visible($it)) {
            continue;
        }

        $loc  = ($it['location'] === 'panel') ? 'panel' : 'top';
        $slug = (string)$it['slug'];
        if ($slug === '') {
            continue;
        }

        $li = af_advancedmenu_render_item($it);

        if ($loc === 'top') {
            $GLOBALS['menu_'.$slug] = $li;
            $topAgg .= $li."\n";
        } else {
            $GLOBALS['panel_'.$slug] = $li;
            $panelAgg .= $li."\n";
        }
    }

    $GLOBALS['af_advancedmenu_top']   = $topAgg;
    $GLOBALS['af_advancedmenu_panel'] = $panelAgg;
}

/* =========================
   FRONT GUARDS
   ========================= */
function af_advancedmenu_is_frontend_context(): bool
{
    global $mybb;

    // ACP — никогда не трогаем
    if (defined('IN_ADMINCP') && IN_ADMINCP) {
        return false;
    }

    // redirect page (не трогаем такие страницы)
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'misc.php') {
        $action = isset($mybb->input['action']) ? (string)$mybb->input['action'] : '';
        if ($action === 'redirect') {
            return false;
        }
    }

    return true;
}


/* =========================
   HTML MUTATION
   ========================= */
function af_advancedmenu_split_li(string $ulInner): array
{
    $html = (string)$ulInner;
    $len  = strlen($html);
    if ($len === 0) {
        return [];
    }

    $out = [];
    $pos = 0;

    while (true) {
        $start = stripos($html, '<li', $pos);
        if ($start === false) {
            break;
        }

        // найдём конец открывающего <li ...>
        $openEnd = strpos($html, '>', $start);
        if ($openEnd === false) {
            break;
        }

        $depth = 0;
        $scan  = $start;

        // балансируем li / /li
        while (true) {
            if (!preg_match('~</?li\b~i', $html, $m, PREG_OFFSET_CAPTURE, $scan)) {
                // нет закрытия — выходим, чтобы не ломать страницу
                $pos = $openEnd + 1;
                break;
            }

            $tagPos = (int)$m[0][1];
            $tag    = strtolower($m[0][0]);

            if ($tag === '<li') {
                $depth++;
                $scan = $tagPos + 3;
                continue;
            }

            // </li
            if ($tag === '</li') {
                $depth--;
                $closeEnd = strpos($html, '>', $tagPos);
                if ($closeEnd === false) {
                    $pos = $openEnd + 1;
                    break;
                }

                $scan = $closeEnd + 1;

                if ($depth <= 0) {
                    $block = substr($html, $start, ($closeEnd - $start + 1));
                    $out[] = $block;
                    $pos   = $closeEnd + 1;
                    break;
                }

                continue;
            }

            // safety
            $scan = $tagPos + 1;
        }
    }

    return $out;
}


function af_advancedmenu_apply_hide_patterns(string $ulInner, array $patterns): string
{
    $patterns = array_values(array_filter(array_map('trim', $patterns), static function($v) {
        return $v !== '';
    }));

    if (empty($patterns)) {
        return $ulInner;
    }

    $lis = af_advancedmenu_split_li($ulInner);
    if (empty($lis)) {
        // Если не смогли корректно выделить <li> — лучше не ломать разметку
        return $ulInner;
    }

    $kept = [];
    foreach ($lis as $li) {
        $remove = false;
        foreach ($patterns as $p) {
            if (stripos($li, $p) !== false) {
                $remove = true;
                break;
            }
        }
        if (!$remove) {
            $kept[] = $li;
        }
    }

    // Если реально всё удалили — так и возвращаем пусто (это ожидаемое поведение!)
    if (empty($kept)) {
        return '';
    }

    return implode("\n", $kept);
}


function af_advancedmenu_extract_protected_lis(string $ulInner, array $protectPatterns): string
{
    if (empty($protectPatterns)) {
        return '';
    }

    $lis = af_advancedmenu_split_li($ulInner);
    if (empty($lis)) {
        return '';
    }

    $out = [];
    foreach ($lis as $li) {
        foreach ($protectPatterns as $p) {
            if ($p === '') continue;
            if (stripos($li, $p) !== false) {
                $out[] = $li;
                break;
            }
        }
    }

    return implode("\n", $out);
}

function af_advancedmenu_inject_assets(string &$page): void
{
    global $mybb;

    if (strpos($page, AF_AM_ASSETS_MARK) !== false) {
        return;
    }
    if (empty($mybb->settings['af_advancedmenu_assets'])) {
        return;
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    // URL'ы ассетов
    $cssUrl = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedmenu/assets/advancedmenu.css';
    $jsUrl  = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedmenu/assets/advancedmenu.js';

    // Авто-версия: берём время изменения файлов (чтобы всегда грузилась свежая)
    $cssPath = MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancedmenu/assets/advancedmenu.css';
    $jsPath  = MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancedmenu/assets/advancedmenu.js';

    $cssV = (is_file($cssPath) ? (string)@filemtime($cssPath) : (string)TIME_NOW);
    $jsV  = (is_file($jsPath) ? (string)@filemtime($jsPath)  : (string)TIME_NOW);

    // Если bburl уже содержит ?, то аккуратно подцепим &v=
    $css = $cssUrl.(strpos($cssUrl, '?') !== false ? '&' : '?').'v='.$cssV;
    $js  = $jsUrl.(strpos($jsUrl, '?') !== false ? '&' : '?').'v='.$jsV;

    $tags = "\n".AF_AM_ASSETS_MARK."\n"
        .'<link rel="stylesheet" href="'.$css.'" />'."\n"
        .'<script type="text/javascript" src="'.$js.'" defer="defer"></script>'."\n";

    // Вставка в head, если есть
    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $tags.'</head>', $page, 1);
        return;
    }

    // Фолбэк: перед </body>
    if (stripos($page, '</body>') !== false) {
        $page = preg_replace('~</body>~i', $tags.'</body>', $page, 1);
    }
}

function af_advancedmenu_apply_to_ul(
    string &$page,
    string $ulClassNeedle,
    string $mode,
    string $newLisHtml,
    array $hidePatterns,
    array $protectPatterns = [],
    string $protectPos = 'end',
    array $stripImgPatterns = []
    ): void {
    if ($page === '' || $ulClassNeedle === '') {
        return;
    }

    $needle = trim($ulClassNeedle);
    if ($needle === '') {
        return;
    }

    $mode       = ($mode === 'replace') ? 'replace' : 'append';
    $protectPos = ($protectPos === 'start') ? 'start' : 'end';

    $hidePatterns = array_values(array_filter(array_map('trim', $hidePatterns), static function($v) {
        return $v !== '';
    }));
    $protectPatterns = array_values(array_filter(array_map('trim', $protectPatterns), static function($v) {
        return $v !== '';
    }));
    $stripImgPatterns = array_values(array_filter(array_map('trim', $stripImgPatterns), static function($v) {
        return $v !== '';
    }));

    // допускаем panel_links <-> panel-links
    $needles = [
        strtolower($needle),
        strtolower(str_replace('_', '-', $needle)),
        strtolower(str_replace('-', '_', $needle)),
    ];
    $needles = array_values(array_unique(array_filter($needles)));

    $html = (string)$page;
    $len  = strlen($html);

    $out = '';
    $pos = 0;

    while (true) {
        $ulStart = stripos($html, '<ul', $pos);
        if ($ulStart === false) {
            $out .= substr($html, $pos);
            break;
        }

        // текст до <ul
        $out .= substr($html, $pos, $ulStart - $pos);

        // конец открывающего <ul ...>
        $ulOpenEnd = strpos($html, '>', $ulStart);
        if ($ulOpenEnd === false) {
            $out .= substr($html, $ulStart);
            break;
        }

        $ulOpenTag = substr($html, $ulStart, ($ulOpenEnd - $ulStart + 1));

        // class=""
        $classStr = '';
        if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~si', $ulOpenTag, $cm)) {
            $classStr = (string)$cm[2];
        }

        // id=""
        $idStr = '';
        if (preg_match('~\bid\s*=\s*(["\'])(.*?)\1~si', $ulOpenTag, $im)) {
            $idStr = (string)$im[2];
        }

        // Это наш UL?
        $isTarget = false;

        // 1) match по class: по токенам
        if ($classStr !== '') {
            $tokens = preg_split('~\s+~', trim($classStr));
            $tokens = array_values(array_filter(array_map('strtolower', (array)$tokens), static fn($v) => $v !== ''));
            foreach ($tokens as $tok) {
                if (in_array($tok, $needles, true)) {
                    $isTarget = true;
                    break;
                }
            }
        }

        // 2) match по id (важно для тем, где panel_links/user_links оформлены id-шником)
        if (!$isTarget && $idStr !== '') {
            $idLower = strtolower(trim($idStr));
            if (in_array($idLower, $needles, true)) {
                $isTarget = true;
            }
        }

        // найдём закрывающий </ul> с балансом вложенных ul
        $depth = 1;
        $scan  = $ulOpenEnd + 1;
        $ulCloseStart = null;
        $ulCloseEnd   = null;

        while ($scan < $len) {
            if (!preg_match('~</?ul\b~i', $html, $m, PREG_OFFSET_CAPTURE, $scan)) {
                break;
            }

            $tagPos = (int)$m[0][1];
            $tag    = strtolower($m[0][0]);

            if ($tag === '<ul') {
                $depth++;
                $scan = $tagPos + 3;
                continue;
            }

            if ($tag === '</ul') {
                $depth--;
                $closeEnd = strpos($html, '>', $tagPos);
                if ($closeEnd === false) {
                    break;
                }

                if ($depth <= 0) {
                    $ulCloseStart = $tagPos;
                    $ulCloseEnd   = $closeEnd;
                    break;
                }

                $scan = $closeEnd + 1;
                continue;
            }

            $scan = $tagPos + 1;
        }

        if ($ulCloseStart === null || $ulCloseEnd === null) {
            $out .= substr($html, $ulStart);
            break;
        }

        $ulInner = substr($html, $ulOpenEnd + 1, $ulCloseStart - ($ulOpenEnd + 1));
        $ulCloseTag = substr($html, $ulCloseStart, ($ulCloseEnd - $ulCloseStart + 1));

        if (!$isTarget) {
            $out .= $ulOpenTag.$ulInner.$ulCloseTag;
            $pos = $ulCloseEnd + 1;
            continue;
        }

        $origInner = $ulInner;

        // hide
        if (!empty($hidePatterns)) {
            $ulInner = af_advancedmenu_apply_hide_patterns($ulInner, $hidePatterns);
        }

        // strip img
        if (!empty($stripImgPatterns)) {
            $ulInner = af_advancedmenu_apply_strip_img_patterns($ulInner, $stripImgPatterns);
        }

        // protected — берём из оригинала
        $protectedHtml = '';
        if ($mode === 'replace' && !empty($protectPatterns)) {
            $protectedHtml = trim(af_advancedmenu_extract_protected_lis($origInner, $protectPatterns));
            if ($protectedHtml !== '' && !empty($stripImgPatterns)) {
                $protectedHtml = trim(af_advancedmenu_apply_strip_img_patterns($protectedHtml, $stripImgPatterns));
            }
        }

        $newLis = trim((string)$newLisHtml);

        if ($mode === 'append') {
            $finalInner = trim((string)$ulInner);
            if ($finalInner !== '' && $newLis !== '') {
                $finalInner .= "\n";
            }
            $finalInner .= $newLis;
        } else {
            // replace
            $finalInner = $newLis;

            if ($protectedHtml !== '') {
                if ($protectPos === 'start') {
                    $finalInner = $protectedHtml.($finalInner !== '' ? "\n".$finalInner : '');
                } else {
                    $finalInner = ($finalInner !== '' ? $finalInner."\n" : '').$protectedHtml;
                }
            }
        }

        $finalInner = trim($finalInner);

        // собираем обратно
        $out .= $ulOpenTag."\n".$finalInner."\n".$ulCloseTag;
        $pos = $ulCloseEnd + 1;
    }

    $page = $out;
}

/* =========================
   AF HOOKS
   ========================= */

/**
 * Вызывается ядром AF на global_start (лениво).
 */
function af_advancedmenu_init(): void
{
    global $mybb;

    af_advancedmenu_ensure_installed();
    af_advancedmenu_load_lang(false);
    af_menu_collect_registry();

    if (empty($mybb->settings['af_advancedmenu_enabled'])) {
        return;
    }

    // Подготовим {$menu_slug} / {$panel_slug} / агрегаты
    af_advancedmenu_set_template_vars();
}

/**
 * Вызывается ядром AF на pre_output_page(&$page).
 */
function af_advancedmenu_pre_output(string &$page = ''): void
{
    global $mybb;

    if (strpos($page, AF_AM_APPLIED_MARK) !== false) {
        return;
    }
    if (!af_advancedmenu_is_frontend_context()) {
        return;
    }
    if (function_exists('af_frontend_asset_allowed')
        && !af_frontend_asset_allowed(AF_AM_ID, 'pre_output')) {
        return;
    }

    af_advancedmenu_ensure_installed();

    if (empty($mybb->settings['af_advancedmenu_enabled'])) {
        return;
    }

    af_advancedmenu_inject_assets($page);

    // All three navigation surfaces are controlled by the registry. Legacy
    // lists remain only as hidden compatibility hooks for their owning addons.
    af_advancedmenu_install_frontend_nav($page);
    $page .= "\n".AF_AM_APPLIED_MARK."\n";
}
