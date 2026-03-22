<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

define('AF_AA_ID', 'advancedappearance');
define('AF_AA_BASE', AF_ADDONS . AF_AA_ID . '/');
define('AF_AA_ASSETS_DIR', AF_AA_BASE . 'assets/');
define('AF_AA_PRESETS_TABLE_NAME', 'af_aa_presets');
define('AF_AA_ASSIGNMENTS_TABLE_NAME', 'af_aa_assignments');
define('AF_AA_PRESETS_TABLE', TABLE_PREFIX . AF_AA_PRESETS_TABLE_NAME);
define('AF_AA_ASSIGNMENTS_TABLE', TABLE_PREFIX . AF_AA_ASSIGNMENTS_TABLE_NAME);
define('AF_AA_ASSET_MARK', '<!--af_aa_assets-->');

define('AF_AA_TARGET_APUI_THEME_PACK', 'apui_theme_pack');
define('AF_AA_TARGET_APUI_PROFILE_PACK', 'apui_profile_pack');
define('AF_AA_TARGET_APUI_POSTBIT_PACK', 'apui_postbit_pack');
define('AF_AA_TARGET_APUI_THREAD_PACK', 'apui_thread_pack');
define('AF_AA_TARGET_APUI_APPLICATION_PACK', 'apui_application_pack');
define('AF_AA_TARGET_APUI_SHEET_PACK', 'apui_sheet_pack');
define('AF_AA_TARGET_APUI_INVENTORY_PACK', 'apui_inventory_pack');
define('AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK', 'apui_achievements_pack');
define('AF_AA_TARGET_APUI_FRAGMENT_PACK', 'apui_fragment_pack');

define('AF_AA_ALIAS_APSTUDIO', 'apstudio.php');
define('AF_AA_ALIAS_FITTINGROOM', 'fittingroom.php');
define('AF_AA_ALIAS_APSTUDIO_MARK', 'AF_AA_APSTUDIO_PAGE_ALIAS');
define('AF_AA_ALIAS_FITTINGROOM_MARK', 'AF_AA_FITTINGROOM_PAGE_ALIAS');

define('AF_AA_TPL_APSTUDIO', 'advancedappearance_apstudio');
define('AF_AA_TPL_FITTINGROOM', 'advancedappearance_fittingroom');

function af_aa_is_preview_script(?string $script = null): bool
{
    if ($script === null) {
        $script = defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '';
    }

    $script = trim(strtolower((string)$script));

    return $script === strtolower(AF_AA_ALIAS_APSTUDIO)
        || $script === strtolower(AF_AA_ALIAS_FITTINGROOM);
}

function af_advancedappearance_install(): void
{
    af_aa_ensure_schema();
    af_aa_ensure_settings();
    af_aa_ensure_front_templates();
    af_aa_install_page_aliases();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedappearance_activate(): void
{
    af_aa_ensure_schema();
    af_aa_ensure_settings();
    af_aa_ensure_front_templates();
    af_aa_install_page_aliases();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedappearance_deactivate(): void
{
    af_aa_remove_page_aliases();
}

function af_advancedappearance_uninstall(): void
{
    global $db;

    af_aa_remove_page_aliases();
    af_aa_remove_front_templates();

    if ($db->table_exists(AF_AA_PRESETS_TABLE_NAME)) {
        $db->drop_table(AF_AA_PRESETS_TABLE_NAME);
    }

    if ($db->table_exists(AF_AA_ASSIGNMENTS_TABLE_NAME)) {
        $db->drop_table(AF_AA_ASSIGNMENTS_TABLE_NAME);
    }

    $db->delete_query('settings', "name LIKE 'af_" . AF_AA_ID . "_%'");
    $db->delete_query('settinggroups', "name='af_" . AF_AA_ID . "'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_aa_is_enabled(): bool
{
    global $mybb;

    return !empty($mybb->settings['af_' . AF_AA_ID . '_enabled']);
}

function af_aa_ensure_settings(): void
{
    if (!function_exists('af_ensure_settinggroup') || !function_exists('af_ensure_setting')) {
        return;
    }

    af_ensure_settinggroup(
        'af_' . AF_AA_ID,
        'AdvancedAppearance',
        'Каталог визуальных пресетов для APUI и их назначения пользователям.'
    );

    af_ensure_setting(
        'af_' . AF_AA_ID,
        'af_' . AF_AA_ID . '_enabled',
        'Включить AdvancedAppearance',
        'Включает применение пресетов к APUI через runtime CSS.',
        'yesno',
        '1',
        1
    );
}

function af_aa_ensure_schema(): void
{
    global $db;

    $charset = method_exists($db, 'build_create_table_collation')
        ? $db->build_create_table_collation()
        : 'ENGINE=InnoDB';

    $db->write_query(
        "CREATE TABLE IF NOT EXISTS " . AF_AA_PRESETS_TABLE . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(120) NOT NULL,
            title VARCHAR(191) NOT NULL,
            description TEXT NOT NULL,
            preview_image VARCHAR(512) NOT NULL DEFAULT '',
            target_key VARCHAR(100) NOT NULL,
            settings_json MEDIUMTEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sortorder INT NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slug_target (slug, target_key),
            KEY idx_target_enabled_sort (target_key, enabled, sortorder)
        ) " . $charset
    );

    $db->write_query(
        "CREATE TABLE IF NOT EXISTS " . AF_AA_ASSIGNMENTS_TABLE . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT UNSIGNED NOT NULL,
            target_key VARCHAR(100) NOT NULL,
            preset_id INT UNSIGNED NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_entity_target (entity_type, entity_id, target_key),
            KEY idx_target_entity (target_key, entity_type, entity_id),
            KEY idx_preset (preset_id)
        ) " . $charset
    );
}

function af_aa_register_hooks(): void
{
    global $plugins;

    // Сбор uid на страницах с постбитами
    $plugins->add_hook('postbit', 'af_aa_collect_uid_from_postbit', 50);
    $plugins->add_hook('postbit_prev', 'af_aa_collect_uid_from_postbit', 50);
    $plugins->add_hook('postbit_pm', 'af_aa_collect_uid_from_postbit', 50);

    // Сбор uid на странице профиля
    $plugins->add_hook('member_profile_end', 'af_aa_collect_uid_from_member_profile', 50);

    // Выбор пресета темы в формах создания/редактирования темы.
    $plugins->add_hook('newthread_start', 'af_aa_newthread_start', 50);
    $plugins->add_hook('editpost_start', 'af_aa_editpost_start', 50);
    $plugins->add_hook('newthread_do_newthread_start', 'af_aa_newthread_do_start', 50);
    $plugins->add_hook('editpost_do_editpost_start', 'af_aa_editpost_do_start', 50);
    $plugins->add_hook('newthread_do_newthread_end', 'af_aa_newthread_do_end', 50);
    $plugins->add_hook('editpost_do_editpost_end', 'af_aa_editpost_do_end', 50);

    // Финальный инжект runtime CSS в готовую страницу
    $plugins->add_hook('pre_output_page', 'af_aa_pre_output_page', 1);
}
af_aa_register_hooks();

function af_aa_get_supported_fragment_keys(): array
{
    return [
        'profile_body' => 'Профиль: фон body',
        'profile_banner' => 'Профиль: баннер',
        'profile_avatar_frame' => 'Профиль: рамка аватара',
        'postbit_author' => 'Постбит: фон карточки автора',
        'postbit_name' => 'Постбит: блок никнейма',
        'postbit_plaque' => 'Постбит: нижняя плашка',
        'postbit_plaque_icon' => 'Постбит: иконка нижней плашки',
        'postbit_avatar_frame' => 'Постбит: рамка аватара',
        'thread_body' => 'Тема: фон страницы',
        'thread_banner' => 'Тема: баннер темы',
    ];
}

function af_aa_normalize_target_key(string $targetKey): string
{
    return mb_strtolower(trim($targetKey));
}

function af_aa_get_supported_targets_registry(): array
{
    static $registry = null;

    if (is_array($registry)) {
        return $registry;
    }

    $registry = [
        AF_AA_TARGET_APUI_THEME_PACK => [
            'do' => 'themepack',
            'group' => 'theme_pack',
            'label' => 'Общие пак-темы',
            'human_label' => 'Общий пак темы',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_PROFILE_PACK => [
            'do' => 'profilepack',
            'group' => 'profile_pack',
            'label' => 'Профили',
            'human_label' => 'Пак профиля',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_POSTBIT_PACK => [
            'do' => 'postbitpack',
            'group' => 'postbit_pack',
            'label' => 'Постбиты',
            'human_label' => 'Пак постбита',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_THREAD_PACK => [
            'do' => 'threadpack',
            'group' => 'thread_pack',
            'label' => 'Страница темы',
            'human_label' => 'Пак страницы темы',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_APPLICATION_PACK => [
            'do' => 'applicationpack',
            'group' => 'application_pack',
            'label' => 'Анкеты',
            'human_label' => 'Пак анкеты',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_SHEET_PACK => [
            'do' => 'sheetpack',
            'group' => 'sheet_pack',
            'label' => 'Листы персонажа',
            'human_label' => 'Пак листа персонажа',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_INVENTORY_PACK => [
            'do' => 'inventorypack',
            'group' => 'inventory_pack',
            'label' => 'Инвентарь',
            'human_label' => 'Пак инвентаря',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK => [
            'do' => 'achievementspack',
            'group' => 'achievements_pack',
            'label' => 'Ачивки',
            'human_label' => 'Пак ачивок',
            'preset_target' => true,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ],
        AF_AA_TARGET_APUI_FRAGMENT_PACK => [
            'do' => 'fragmentpack',
            'group' => 'fragment_pack',
            'label' => 'Разное',
            'human_label' => 'Дробный пак',
            'preset_target' => true,
            'assignable' => false,
            'purchasable' => true,
            'useable' => true,
        ],
    ];

    foreach (af_aa_get_supported_fragment_keys() as $fragmentKey => $fragmentLabel) {
        $registry[AF_AA_TARGET_APUI_FRAGMENT_PACK . ':' . $fragmentKey] = [
            'do' => 'fragmentpack',
            'group' => 'fragment_pack',
            'label' => 'Разное · ' . $fragmentLabel,
            'human_label' => 'Назначение: ' . $fragmentLabel,
            'fragment_key' => $fragmentKey,
            'preset_target' => false,
            'assignable' => true,
            'purchasable' => true,
            'useable' => true,
        ];
    }

    return $registry;
}

function af_aa_get_supported_target_keys(array $filters = []): array
{
    $keys = [];

    foreach (af_aa_get_supported_targets_registry() as $targetKey => $meta) {
        $include = true;

        foreach ($filters as $filterKey => $expected) {
            if (($meta[$filterKey] ?? null) !== $expected) {
                $include = false;
                break;
            }
        }

        if ($include) {
            $keys[] = $targetKey;
        }
    }

    return $keys;
}

function af_aa_is_supported_target(string $targetKey, array $filters = []): bool
{
    $targetKey = af_aa_normalize_target_key($targetKey);
    if ($targetKey === '') {
        return false;
    }

    $meta = af_aa_get_target_meta($targetKey);
    if (!$meta) {
        return false;
    }

    foreach ($filters as $filterKey => $expected) {
        if (($meta[$filterKey] ?? null) !== $expected) {
            return false;
        }
    }

    return true;
}

function af_aa_get_target_meta(string $targetKey): array
{
    $targetKey = af_aa_normalize_target_key($targetKey);
    $registry = af_aa_get_supported_targets_registry();

    return isset($registry[$targetKey]) ? $registry[$targetKey] : [];
}

function af_aa_get_target_group_labels(): array
{
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

function af_aa_get_all_assignment_target_keys(): array
{
    return af_aa_get_supported_target_keys(['assignable' => true]);
}

function af_aa_collect_uid_from_postbit(array &$post): void
{
    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    if (!isset($GLOBALS['af_aa_uids_on_page']) || !is_array($GLOBALS['af_aa_uids_on_page'])) {
        $GLOBALS['af_aa_uids_on_page'] = [];
    }

    $GLOBALS['af_aa_uids_on_page'][$uid] = $uid;
}

function af_aa_collect_uid_from_member_profile(): void
{
    global $memprofile;

    $uid = (int)($memprofile['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    if (!isset($GLOBALS['af_aa_uids_on_page']) || !is_array($GLOBALS['af_aa_uids_on_page'])) {
        $GLOBALS['af_aa_uids_on_page'] = [];
    }

    $GLOBALS['af_aa_uids_on_page'][$uid] = $uid;
}

function af_aa_thread_selector_field_name(): string
{
    return 'af_aa_thread_preset_id';
}

function af_aa_reset_thread_form_state(): void
{
    $GLOBALS['af_aa_thread_preset_html'] = '';
    $GLOBALS['af_aa_thread_preset_selected'] = 0;
    $GLOBALS['af_aa_thread_preset_options'] = [];
}

function af_aa_get_post_thread_context_by_pid(int $pid): array
{
    global $db;

    $pid = (int)$pid;
    if ($pid <= 0) {
        return [];
    }

    $query = $db->query(
        "SELECT p.pid, p.tid, p.uid AS post_uid, t.fid, t.uid AS thread_uid, t.firstpost"
        . " FROM " . TABLE_PREFIX . "posts p"
        . " LEFT JOIN " . TABLE_PREFIX . "threads t ON (t.tid=p.tid)"
        . " WHERE p.pid='" . $pid . "'"
        . " LIMIT 1"
    );
    $row = $db->fetch_array($query);
    if (!is_array($row) || empty($row)) {
        return [];
    }

    $row['pid'] = (int)($row['pid'] ?? 0);
    $row['tid'] = (int)($row['tid'] ?? 0);
    $row['fid'] = (int)($row['fid'] ?? 0);
    $row['post_uid'] = (int)($row['post_uid'] ?? 0);
    $row['thread_uid'] = (int)($row['thread_uid'] ?? 0);
    $row['firstpost'] = (int)($row['firstpost'] ?? 0);
    $row['is_first'] = ($row['pid'] > 0 && $row['firstpost'] > 0 && $row['pid'] === $row['firstpost']);

    return $row;
}

function af_aa_get_thread_row(int $tid): array
{
    global $db;

    $tid = (int)$tid;
    if ($tid <= 0) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select('threads', '*', "tid='" . $tid . "'", ['limit' => 1]));
    if (!is_array($row) || empty($row)) {
        return [];
    }

    $row['tid'] = (int)($row['tid'] ?? 0);
    $row['uid'] = (int)($row['uid'] ?? 0);
    $row['fid'] = (int)($row['fid'] ?? 0);
    $row['firstpost'] = (int)($row['firstpost'] ?? 0);

    return $row;
}

function af_aa_is_thread_author(int $uid, array $thread): bool
{
    return $uid > 0 && $uid === (int)($thread['uid'] ?? 0);
}

function af_aa_get_inventory_owned_thread_presets(int $uid): array
{
    $uid = (int)$uid;
    if ($uid <= 0) {
        return [];
    }

    if (!function_exists('af_inv_get_items') || !function_exists('af_advinv_resolve_appearance_item')) {
        $invBootstrap = AF_ADDONS . 'advancedinventory/advancedinventory.php';
        if (is_file($invBootstrap)) {
            require_once $invBootstrap;
        }
    }

    if (!function_exists('af_inv_get_items') || !function_exists('af_advinv_resolve_appearance_item')) {
        return [];
    }

    $itemsPayload = af_inv_get_items($uid, [
        'page' => 1,
        'perPage' => 500,
        'enrich' => true,
    ]);

    $presets = [];
    foreach ((array)($itemsPayload['items'] ?? []) as $item) {
        $appearanceInfo = (array)af_advinv_resolve_appearance_item((array)$item);
        $appearanceMeta = is_array($appearanceInfo['appearance_meta'] ?? null)
            ? (array)$appearanceInfo['appearance_meta']
            : [];

        $targetKey = trim((string)($item['appearance_target'] ?? ($appearanceInfo['target_key'] ?? ($appearanceMeta['target_key'] ?? ''))));
        if ($targetKey !== AF_AA_TARGET_APUI_THREAD_PACK) {
            continue;
        }

        $presetId = (int)($item['appearance_preset_id'] ?? ($appearanceInfo['preset_id'] ?? ($appearanceMeta['preset_id'] ?? 0)));
        if ($presetId <= 0) {
            continue;
        }

        $preset = af_aa_get_preset_by_id($presetId);
        if (empty($preset) || (string)($preset['target_key'] ?? '') !== AF_AA_TARGET_APUI_THREAD_PACK) {
            continue;
        }

        $presets[$presetId] = $preset;
    }

    uasort($presets, static function (array $a, array $b): int {
        $sort = ((int)($a['sortorder'] ?? 0)) <=> ((int)($b['sortorder'] ?? 0));
        if ($sort !== 0) {
            return $sort;
        }

        return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });

    return $presets;
}

function af_aa_user_owns_thread_preset(int $uid, int $presetId): bool
{
    $uid = (int)$uid;
    $presetId = (int)$presetId;

    if ($uid <= 0 || $presetId <= 0) {
        return false;
    }

    $presets = af_aa_get_inventory_owned_thread_presets($uid);
    return isset($presets[$presetId]);
}

function af_aa_get_valid_thread_preset(int $tid): array
{
    $tid = (int)$tid;
    if ($tid <= 0) {
        return [];
    }

    $assignment = af_aa_get_active_assignment('thread', $tid, AF_AA_TARGET_APUI_THREAD_PACK);
    if (empty($assignment)) {
        return [];
    }

    $thread = af_aa_get_thread_row($tid);
    if (empty($thread)) {
        return [];
    }

    $presetId = (int)($assignment['preset_id'] ?? 0);
    if ($presetId <= 0 || !af_aa_user_owns_thread_preset((int)($thread['uid'] ?? 0), $presetId)) {
        return [];
    }

    $preset = af_aa_get_preset_by_id($presetId);
    if (empty($preset) || (string)($preset['target_key'] ?? '') !== AF_AA_TARGET_APUI_THREAD_PACK) {
        return [];
    }

    return [
        'assignment' => $assignment,
        'preset' => $preset,
        'thread' => $thread,
    ];
}

function af_aa_get_thread_preset_settings(int $tid, array $defaults): array
{
    $resolved = af_aa_get_valid_thread_preset($tid);
    if (empty($resolved['preset'])) {
        return [];
    }

    return [
        'preset' => $resolved['preset'],
        'settings' => af_aa_decode_and_sanitize_preset_settings((string)($resolved['preset']['settings_json'] ?? ''), $defaults, AF_AA_TARGET_APUI_THREAD_PACK),
        'assignment' => (array)($resolved['assignment'] ?? []),
        'thread' => (array)($resolved['thread'] ?? []),
    ];
}

function af_aa_upsert_thread_assignment(int $tid, int $uid, int $presetId): void
{
    global $db;

    $tid = (int)$tid;
    $uid = (int)$uid;
    $presetId = (int)$presetId;

    if ($tid <= 0 || $uid <= 0) {
        return;
    }

    if ($presetId <= 0) {
        $db->delete_query(AF_AA_ASSIGNMENTS_TABLE_NAME, "entity_type='thread' AND entity_id='" . $tid . "' AND target_key='" . $db->escape_string(AF_AA_TARGET_APUI_THREAD_PACK) . "'");
        unset($GLOBALS['af_aa_assignment_cache_runtime']['thread:' . $tid . ':' . AF_AA_TARGET_APUI_THREAD_PACK]);
        return;
    }

    $existing = af_aa_get_active_assignment('thread', $tid, AF_AA_TARGET_APUI_THREAD_PACK);
    $data = [
        'entity_type' => 'thread',
        'entity_id' => $tid,
        'target_key' => AF_AA_TARGET_APUI_THREAD_PACK,
        'preset_id' => $presetId,
        'is_enabled' => 1,
        'updated_at' => TIME_NOW,
    ];

    if (!empty($existing['id'])) {
        $db->update_query(AF_AA_ASSIGNMENTS_TABLE_NAME, $data, "id='" . (int)$existing['id'] . "'");
    } else {
        $data['created_at'] = TIME_NOW;
        $db->insert_query(AF_AA_ASSIGNMENTS_TABLE_NAME, $data);
    }

    unset($GLOBALS['af_aa_assignment_cache_runtime']['thread:' . $tid . ':' . AF_AA_TARGET_APUI_THREAD_PACK]);
}

function af_aa_resolve_posted_thread_preset_id(array $thread, int $actorUid, ?int $fallbackPresetId = null): int
{
    global $mybb;

    $fallback = $fallbackPresetId === null ? (int)($mybb->input[af_aa_thread_selector_field_name()] ?? 0) : (int)$fallbackPresetId;
    $selectedPresetId = isset($mybb->input[af_aa_thread_selector_field_name()])
        ? (int)$mybb->get_input(af_aa_thread_selector_field_name(), MyBB::INPUT_INT)
        : $fallback;

    if ($selectedPresetId <= 0) {
        return 0;
    }

    if (!af_aa_is_thread_author($actorUid, $thread)) {
        return 0;
    }

    $preset = af_aa_get_preset_by_id($selectedPresetId);
    if (empty($preset) || (string)($preset['target_key'] ?? '') !== AF_AA_TARGET_APUI_THREAD_PACK) {
        return 0;
    }

    return af_aa_user_owns_thread_preset($actorUid, $selectedPresetId) ? $selectedPresetId : 0;
}

function af_aa_build_thread_preset_select_html(int $uid, int $selectedPresetId): string
{
    $options = ['<option value="0">По умолчанию</option>'];
    $presets = af_aa_get_inventory_owned_thread_presets($uid);

    foreach ($presets as $presetId => $preset) {
        $selected = ((int)$selectedPresetId === (int)$presetId) ? ' selected="selected"' : '';
        $options[] = '<option value="' . (int)$presetId . '"' . $selected . '>' . htmlspecialchars_uni((string)($preset['title'] ?? ('Preset #' . $presetId))) . '</option>';
    }

    return '<tr class="af-aa-thread-preset-row">'
        . '<td class="trow2"><strong>Пресет темы</strong><br /><span class="smalltext">Выбери оформление этой темы. Доступны только купленные пресеты темы.</span></td>'
        . '<td class="trow2"><select name="' . htmlspecialchars_uni(af_aa_thread_selector_field_name()) . '" class="select">' . implode('', $options) . '</select></td>'
        . '</tr>';
}

function af_aa_prepare_thread_preset_form(int $uid, int $selectedPresetId, bool $allowed): void
{
    af_aa_reset_thread_form_state();

    if ($uid <= 0 || !$allowed) {
        return;
    }

    $GLOBALS['af_aa_thread_preset_selected'] = max(0, (int)$selectedPresetId);
    $GLOBALS['af_aa_thread_preset_html'] = af_aa_build_thread_preset_select_html($uid, (int)$selectedPresetId);
}

function af_aa_inject_thread_preset_field(string $page): string
{
    $html = trim((string)($GLOBALS['af_aa_thread_preset_html'] ?? ''));
    if ($html === '') {
        return $page;
    }

    if (strpos($page, 'af-aa-thread-preset-row') !== false) {
        return $page;
    }

    $formPattern = '~<form\b[^>]*>.*?</form>~is';
    $injected = false;

    $updated = preg_replace_callback($formPattern, static function (array $matches) use ($html, &$injected) {
        $formHtml = (string)($matches[0] ?? '');

        if ($injected || $formHtml === '') {
            return $formHtml;
        }

        $hasSubject = (bool)preg_match(
            '~<input\b[^>]*\bname=(["\'])subject\1[^>]*>~is',
            $formHtml
        );

        $hasMessage = (bool)preg_match(
            '~<textarea\b[^>]*\bname=(["\'])message\1[^>]*>.*?</textarea>~is',
            $formHtml
        );

        if (!$hasSubject || !$hasMessage) {
            return $formHtml;
        }

        $subjectRowPattern = '~(<tr\b[^>]*>.*?<input\b[^>]*\bname=(["\'])subject\2[^>]*>.*?</tr>)~is';
        $subjectInjected = preg_replace($subjectRowPattern, '$1' . "\n" . $html, $formHtml, 1, $subjectCount);

        if ($subjectCount > 0 && is_string($subjectInjected) && $subjectInjected !== $formHtml) {
            $injected = true;
            return $subjectInjected;
        }

        $messageRowPattern = '~(<tr\b[^>]*>.*?<textarea\b[^>]*\bname=(["\'])message\2[^>]*>.*?</textarea>.*?</tr>)~is';
        $messageInjected = preg_replace($messageRowPattern, $html . "\n" . '$1', $formHtml, 1, $messageCount);

        if ($messageCount > 0 && is_string($messageInjected) && $messageInjected !== $formHtml) {
            $injected = true;
            return $messageInjected;
        }

        return $formHtml;
    }, $page);

    if ($injected && is_string($updated) && $updated !== '') {
        return $updated;
    }

    return $page;
}

function af_aa_newthread_start(): void
{
    global $mybb;

    af_aa_reset_thread_form_state();

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $selectedPresetId = isset($mybb->input[af_aa_thread_selector_field_name()])
        ? (int)$mybb->get_input(af_aa_thread_selector_field_name(), MyBB::INPUT_INT)
        : 0;

    $selectedPresetId = af_aa_resolve_posted_thread_preset_id(['uid' => $uid], $uid, $selectedPresetId);
    af_aa_prepare_thread_preset_form($uid, $selectedPresetId, true);
}

function af_aa_editpost_start(): void
{
    global $mybb, $pid;

    af_aa_reset_thread_form_state();

    $uid = (int)($mybb->user['uid'] ?? 0);
    $pid = isset($pid) ? (int)$pid : (int)$mybb->get_input('pid', MyBB::INPUT_INT);
    if ($uid <= 0 || $pid <= 0) {
        return;
    }

    $context = af_aa_get_post_thread_context_by_pid($pid);
    if (empty($context['is_first']) || !af_aa_is_thread_author($uid, ['uid' => (int)($context['thread_uid'] ?? 0)])) {
        return;
    }

    $currentAssignment = af_aa_get_valid_thread_preset((int)($context['tid'] ?? 0));
    $currentPresetId = (int)($currentAssignment['preset']['id'] ?? 0);
    $selectedPresetId = isset($mybb->input[af_aa_thread_selector_field_name()])
        ? af_aa_resolve_posted_thread_preset_id(['uid' => (int)($context['thread_uid'] ?? 0)], $uid, (int)$mybb->get_input(af_aa_thread_selector_field_name(), MyBB::INPUT_INT))
        : $currentPresetId;

    af_aa_prepare_thread_preset_form($uid, $selectedPresetId, true);
}

function af_aa_newthread_do_start(): void
{
    af_aa_newthread_start();
}

function af_aa_editpost_do_start(): void
{
    af_aa_editpost_start();
}

function af_aa_newthread_do_end(): void
{
    global $mybb, $tid;

    $tid = (int)($tid ?? 0);
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($tid <= 0 || $uid <= 0) {
        return;
    }

    $thread = af_aa_get_thread_row($tid);
    if (empty($thread) || !af_aa_is_thread_author($uid, $thread)) {
        return;
    }

    $presetId = af_aa_resolve_posted_thread_preset_id($thread, $uid);
    af_aa_upsert_thread_assignment($tid, $uid, $presetId);
}

function af_aa_editpost_do_end(): void
{
    global $mybb, $pid, $post, $thread, $tid;

    $uid = (int)($mybb->user['uid'] ?? 0);
    $pid = isset($pid) ? (int)$pid : (int)$mybb->get_input('pid', MyBB::INPUT_INT);
    if ($uid <= 0 || $pid <= 0) {
        return;
    }

    $context = af_aa_get_post_thread_context_by_pid($pid);
    if (empty($context['is_first'])) {
        return;
    }

    $threadRow = is_array($thread ?? null) && !empty($thread) ? $thread : af_aa_get_thread_row((int)($context['tid'] ?? ($tid ?? 0)));
    if (empty($threadRow) || !af_aa_is_thread_author($uid, $threadRow)) {
        return;
    }

    $presetId = af_aa_resolve_posted_thread_preset_id($threadRow, $uid);
    af_aa_upsert_thread_assignment((int)($threadRow['tid'] ?? 0), $uid, $presetId);
}

function af_aa_render_thread_runtime_css(int $tid): string
{
    $defaults = af_aa_get_apui_defaults();
    $threadPreset = af_aa_get_thread_preset_settings($tid, $defaults);
    if (empty($threadPreset['settings'])) {
        return '';
    }

    $settings = (array)$threadPreset['settings'];
    $threadMode = af_aa_sanitize_bg_mode((string)($settings['thread_body_bg_mode'] ?? 'cover'), 'cover');
    $selectedThreadBodyImage = $threadMode === 'tile'
        ? af_aa_css_url_value((string)($settings['thread_body_tile_url'] ?? ''))
        : af_aa_css_url_value((string)($settings['thread_body_cover_url'] ?? ''));

    if ($selectedThreadBodyImage === 'none') {
        $selectedThreadBodyImage = $threadMode === 'tile'
            ? af_aa_css_url_value((string)($settings['thread_body_cover_url'] ?? ''))
            : af_aa_css_url_value((string)($settings['thread_body_tile_url'] ?? ''));

        if ($selectedThreadBodyImage !== 'none') {
            $threadMode = $threadMode === 'tile' ? 'cover' : 'tile';
        }
    }

    $css = '';
    $threadBodyDeclarations = [];
    $threadOverlay = trim((string)($settings['thread_body_overlay'] ?? 'none'));
    if ($threadOverlay !== '' && strtolower($threadOverlay) !== 'none') {
        $threadBodyDeclarations[] = '--af-apui-thread-body-overlay:' . af_aa_css_raw_value($threadOverlay, 'none') . ';';
    }
    if ($selectedThreadBodyImage !== 'none') {
        $threadBodyDeclarations[] = 'background-image:' . $selectedThreadBodyImage . ';';
        $threadBodyDeclarations[] = 'background-repeat:' . ($threadMode === 'tile' ? 'repeat' : 'no-repeat') . ';';
        $threadBodyDeclarations[] = 'background-position:' . ($threadMode === 'tile' ? 'left top' : 'center center') . ';';
        $threadBodyDeclarations[] = 'background-attachment:' . ($threadMode === 'tile' ? 'scroll' : 'fixed') . ';';
        $threadBodyDeclarations[] = 'background-size:' . ($threadMode === 'tile' ? 'auto' : 'cover') . ';';
    }
    if ($threadBodyDeclarations) {
        $css .= 'body.af-apui-thread-page{' . implode('', $threadBodyDeclarations) . "}\n";
    }

    $threadBanner = af_aa_css_url_value((string)($settings['thread_banner_url'] ?? ''));
    $threadBannerOverlay = af_aa_css_raw_value((string)($settings['thread_banner_overlay'] ?? 'none'), 'none');
    if ($threadBanner !== 'none') {
        $css .= '.af-apui-thread-hero__banner{background-image:' . $threadBanner . ";}\n";
    }
    if ($threadBannerOverlay !== 'none') {
        $css .= '.af-apui-thread-hero__banner::after{background:' . $threadBannerOverlay . ";}\n";
    }

    $customCss = trim((string)($settings['custom_css'] ?? ''));
    if ($customCss !== '') {
        $payload = [
            'selector' => 'body.af-apui-thread-page',
            'body_selector' => 'body.af-apui-thread-page',
        ];
        $css .= af_aa_render_scoped_custom_css($customCss, $payload);
    }

    return $css !== '' ? '<style id="af-aa-thread-runtime-css">' . $css . '</style>' . "\n" : '';
}

function af_aa_pre_output_page(string &$page): void
{
    if (defined('IN_ADMINCP') || $page === '') {
        return;
    }

    // На preview-страницах ничего не трогаем.
    if (af_aa_is_preview_script()) {
        return;
    }

    // Сначала всегда вычищаем preview-assets, если они случайно попали в обычную страницу.
    $page = af_aa_strip_asset_includes($page);

    if (!af_aa_is_enabled()) {
        return;
    }

    $uids = [];

    if (!empty($GLOBALS['af_aa_uids_on_page']) && is_array($GLOBALS['af_aa_uids_on_page'])) {
        foreach ($GLOBALS['af_aa_uids_on_page'] as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $uids[$uid] = $uid;
            }
        }
    }

    // На случай, если страница профиля не попала в collector по какой-то причине
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'member.php') {
        global $memprofile;
        $profileUid = (int)($memprofile['uid'] ?? 0);
        if ($profileUid > 0) {
            $uids[$profileUid] = $profileUid;
        }
    }

    $runtimeCss = $uids ? af_aa_render_page_css(array_values($uids)) : '';

    if (defined('THIS_SCRIPT') && (string)THIS_SCRIPT === 'showthread.php') {
        global $thread, $tid, $mybb;

        $threadId = (int)($thread['tid'] ?? ($tid ?? $mybb->get_input('tid', MyBB::INPUT_INT)));
        if ($threadId > 0) {
            $runtimeCss .= af_aa_render_thread_runtime_css($threadId);
        }
    }

    if ($runtimeCss !== '') {
        $page = af_aa_inject_runtime_css($page, $runtimeCss);
    }

    if (defined('THIS_SCRIPT') && ((string)THIS_SCRIPT === 'newthread.php' || (string)THIS_SCRIPT === 'editpost.php')) {
        $page = af_aa_inject_thread_preset_field($page);
    }
}

function af_aa_strip_asset_includes(string $page): string
{
    $patterns = [
        '~<!--\s*af_aa_assets\s*-->\s*~i',
        '~<link\b[^>]*href=(["\'])[^"\']*advancedappearance\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
        '~<script\b[^>]*src=(["\'])[^"\']*advancedappearance\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~is',
        '~<style\b[^>]*id=(["\'])af-aa-runtime-css\1[^>]*>.*?</style>\s*~is',
        '~<style\b[^>]*data-aa-preview-custom-css[^>]*>.*?</style>\s*~is',
    ];

    foreach ($patterns as $pattern) {
        $page = preg_replace($pattern, '', $page) ?? $page;
    }

    return $page;
}

function af_aa_inject_runtime_css(string $page, string $css): string
{
    $css = trim($css);
    if ($css === '') {
        return $page;
    }

    // если вдруг старый runtime-css уже есть в HTML — убираем, чтобы не плодить дубли
    $page = (string)preg_replace(
        '~<style\b[^>]*id=(["\'])af-aa-runtime-css\1[^>]*>.*?</style>\s*~is',
        '',
        $page
    );

    if (stripos($page, '</head>') !== false) {
        return str_ireplace('</head>', $css . "\n</head>", $page);
    }

    return $css . "\n" . $page;
}

function af_aa_add_ver(string $url, string $absFile): string
{
    $ver = is_file($absFile) ? (int)@filemtime($absFile) : 0;
    if ($ver <= 0) {
        return $url;
    }

    return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $ver;
}

function af_aa_get_active_assignment(string $entityType, int $entityId, string $targetKey): array
{
    global $db;

    $entityType = trim(strtolower($entityType));
    $targetKey = trim((string)$targetKey);
    $entityId = (int)$entityId;

    if ($entityId <= 0 || $entityType === '' || $targetKey === '') {
        return [];
    }

    if (!isset($GLOBALS['af_aa_assignment_cache_runtime']) || !is_array($GLOBALS['af_aa_assignment_cache_runtime'])) {
        $GLOBALS['af_aa_assignment_cache_runtime'] = [];
    }

    $cacheKey = $entityType . ':' . $entityId . ':' . $targetKey;
    if (array_key_exists($cacheKey, $GLOBALS['af_aa_assignment_cache_runtime'])) {
        return $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey];
    }

    // 1. Сначала штатный источник: assignments
    $where = "entity_type='" . $db->escape_string($entityType) . "'"
        . " AND entity_id='" . $entityId . "'"
        . " AND target_key='" . $db->escape_string($targetKey) . "'"
        . " AND is_enabled='1'";

    $query = $db->simple_select(AF_AA_ASSIGNMENTS_TABLE_NAME, '*', $where, ['limit' => 1]);
    $row = $db->fetch_array($query);

    // 2. Fallback на active-слоты из магазина/инвентаря
    if (!is_array($row) && $entityType === 'user' && $db->table_exists('af_aa_active')) {
        $active = $db->fetch_array(
            $db->simple_select(
                'af_aa_active',
                '*',
                "entity_type='user' AND entity_id='" . $entityId . "' AND target_key='" . $db->escape_string($targetKey) . "' AND is_enabled='1'",
                ['limit' => 1]
            )
        );

        if (is_array($active)) {
            $itemId = (int)($active['item_id'] ?? 0);
            $item = [];

            if (($itemId > 0) && (!function_exists('af_inv_get_item_for_owner') || !function_exists('af_advinv_resolve_appearance_item'))) {
                $invBootstrap = AF_ADDONS . 'advancedinventory/advancedinventory.php';
                if (is_file($invBootstrap)) {
                    require_once $invBootstrap;
                }
            }

            // Новый канон: current inventory
            if ($itemId > 0 && function_exists('af_inv_get_item_for_owner')) {
                $item = (array)af_inv_get_item_for_owner($entityId, $itemId);
            }

            // Fallback напрямую в текущую таблицу
            if (!$item && $itemId > 0 && $db->table_exists('af_advinv_items')) {
                $item = (array)$db->fetch_array(
                    $db->simple_select(
                        'af_advinv_items',
                        '*',
                        "id='" . $itemId . "' AND uid='" . $entityId . "'",
                        ['limit' => 1]
                    )
                );
            }

            // Legacy fallback
            if (!$item && $itemId > 0 && $db->table_exists('af_inventory_items')) {
                $item = (array)$db->fetch_array(
                    $db->simple_select(
                        'af_inventory_items',
                        '*',
                        "inv_id='" . $itemId . "' AND uid='" . $entityId . "'",
                        ['limit' => 1]
                    )
                );
            }

            $presetId = 0;

            if ($item) {
                if (function_exists('af_advinv_resolve_appearance_item')) {
                    $appearanceInfo = (array)af_advinv_resolve_appearance_item($item);
                    $presetId = (int)($appearanceInfo['preset_id'] ?? 0);
                }

                if ($presetId <= 0) {
                    $kbKey = trim((string)($item['kb_key'] ?? ''));
                    if (strpos($kbKey, 'appearance:') === 0) {
                        $presetId = (int)substr($kbKey, strlen('appearance:'));
                    }
                }

                if ($presetId <= 0) {
                    $meta = @json_decode((string)($item['meta_json'] ?? '{}'), true);
                    if (is_array($meta)) {
                        $presetId = (int)($meta['appearance']['preset_id'] ?? 0);
                    }
                }
            }

            if ($presetId > 0) {
                $row = [
                    'id' => 0,
                    'entity_type' => 'user',
                    'entity_id' => $entityId,
                    'target_key' => $targetKey,
                    'preset_id' => $presetId,
                    'is_enabled' => 1,
                    'created_at' => (int)($active['applied_at'] ?? 0),
                    'updated_at' => (int)($active['applied_at'] ?? 0),
                ];
            }
        }
    }

    if (!is_array($row)) {
        $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey] = [];
        return [];
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['entity_id'] = (int)($row['entity_id'] ?? 0);
    $row['preset_id'] = (int)($row['preset_id'] ?? 0);
    $row['is_enabled'] = (int)($row['is_enabled'] ?? 0);

    $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey] = $row;
    return $row;
}

function af_aa_get_preset_by_id(int $presetId): array
{
    global $db;

    $presetId = (int)$presetId;
    if ($presetId <= 0) {
        return [];
    }

    if (!isset($GLOBALS['af_aa_preset_cache_runtime']) || !is_array($GLOBALS['af_aa_preset_cache_runtime'])) {
        $GLOBALS['af_aa_preset_cache_runtime'] = [];
    }

    if (array_key_exists($presetId, $GLOBALS['af_aa_preset_cache_runtime'])) {
        return $GLOBALS['af_aa_preset_cache_runtime'][$presetId];
    }

    $query = $db->simple_select(AF_AA_PRESETS_TABLE_NAME, '*', "id='" . $presetId . "' AND enabled='1'", ['limit' => 1]);
    $row = $db->fetch_array($query);
    if (!is_array($row)) {
        $GLOBALS['af_aa_preset_cache_runtime'][$presetId] = [];
        return [];
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['enabled'] = (int)($row['enabled'] ?? 0);
    $row['sortorder'] = (int)($row['sortorder'] ?? 0);

    $GLOBALS['af_aa_preset_cache_runtime'][$presetId] = $row;
    return $row;
}

function af_aa_get_apui_defaults(): array
{
    $mode = af_aa_sanitize_bg_mode(af_aa_get_apui_setting('member_profile_body_bg_mode', 'cover'), 'cover');

    return [
        'member_profile_body_cover_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('member_profile_body_cover_url', ''), ''),
        'member_profile_body_tile_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('member_profile_body_tile_url', ''), ''),
        'member_profile_body_bg_mode' => $mode,
        'member_profile_body_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('member_profile_body_overlay', 'none'), 'none'),
        'profile_banner_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('profile_banner_url', ''), ''),
        'profile_banner_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('profile_banner_overlay', 'none'), 'none'),
        'thread_body_cover_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('thread_body_cover_url', ''), ''),
        'thread_body_tile_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('thread_body_tile_url', ''), ''),
        'thread_body_bg_mode' => af_aa_sanitize_bg_mode(af_aa_get_apui_setting('thread_body_bg_mode', 'cover'), 'cover'),
        'thread_body_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('thread_body_overlay', 'none'), 'none'),
        'thread_banner_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('thread_banner_url', ''), ''),
        'thread_banner_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('thread_banner_overlay', 'none'), 'none'),
        'postbit_author_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_author_bg_url', ''), ''),
        'postbit_author_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_author_overlay', 'none'), 'none'),
        'postbit_name_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_name_bg_url', ''), ''),
        'postbit_name_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_name_overlay', 'none'), 'none'),
        'postbit_plaque_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_plaque_bg_url', ''), ''),
        'postbit_plaque_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_plaque_overlay', 'none'), 'none'),
        'postbit_plaque_media_image_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_plaque_media_image_url', ''), ''),
        'postbit_plaque_media_icon_class' => trim((string)af_aa_get_apui_setting('postbit_plaque_media_icon_class', '')),
        'postbit_plaque_media_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_plaque_media_overlay', 'none'), 'none'),
        'postbit_plaque_media_css' => trim((string)af_aa_get_apui_setting('postbit_plaque_media_css', '')),
        'postbit_plaque_title_default' => trim((string)af_aa_get_apui_setting('postbit_plaque_title_default', 'Profile plaque')),
        'postbit_plaque_subtitle_default' => trim((string)af_aa_get_apui_setting('postbit_plaque_subtitle_default', 'Decorative media slot')),
        'postbit_plaque_title' => '',
        'postbit_plaque_subtitle' => '',
        'postbit_plaque_icon_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_plaque_icon_url', ''), ''),
        'postbit_plaque_icon_glyph' => af_aa_sanitize_icon_glyph(af_aa_get_apui_setting('postbit_plaque_icon_glyph', '★'), '★'),
        'postbit_plaque_icon_bg' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_plaque_icon_bg', 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))'), 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))'),
        'postbit_plaque_icon_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_plaque_icon_overlay', 'none'), 'none'),
        'postbit_plaque_icon_border' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_plaque_icon_border', 'rgba(255,255,255,.18)'), 'rgba(255,255,255,.18)'),
        'postbit_plaque_icon_color' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_plaque_icon_color', '#f6f1cf'), '#f6f1cf'),
        'postbit_plaque_icon_size' => af_aa_sanitize_css_size(af_aa_get_apui_setting('postbit_plaque_icon_size', '26px'), '26px'),
        'sheet_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('sheet_bg_url', ''), ''),
        'sheet_bg_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('sheet_bg_overlay', 'none'), 'none'),
        'sheet_panel_bg' => af_aa_sanitize_overlay(af_aa_get_apui_setting('sheet_panel_bg', 'rgba(0,0,0,.12)'), 'rgba(0,0,0,.12)'),
        'sheet_panel_border' => af_aa_sanitize_overlay(af_aa_get_apui_setting('sheet_panel_border', 'rgba(255,255,255,.12)'), 'rgba(255,255,255,.12)'),
        'application_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('application_bg_url', ''), ''),
        'application_bg_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('application_bg_overlay', 'none'), 'none'),
        'application_panel_bg' => af_aa_sanitize_overlay(af_aa_get_apui_setting('application_panel_bg', 'rgba(6,12,26,.58)'), 'rgba(6,12,26,.58)'),
        'application_panel_border' => af_aa_sanitize_overlay(af_aa_get_apui_setting('application_panel_border', 'rgba(255,255,255,.10)'), 'rgba(255,255,255,.10)'),
        'inventory_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('inventory_bg_url', ''), ''),
        'inventory_bg_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('inventory_bg_overlay', 'none'), 'none'),
        'inventory_panel_bg' => af_aa_sanitize_overlay(af_aa_get_apui_setting('inventory_panel_bg', 'rgba(21,25,34,.92)'), 'rgba(21,25,34,.92)'),
        'inventory_panel_border' => af_aa_sanitize_overlay(af_aa_get_apui_setting('inventory_panel_border', 'rgba(255,255,255,.12)'), 'rgba(255,255,255,.12)'),
        'achievements_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('achievements_bg_url', ''), ''),
        'achievements_bg_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('achievements_bg_overlay', 'none'), 'none'),
        'achievements_panel_bg' => af_aa_sanitize_overlay(af_aa_get_apui_setting('achievements_panel_bg', 'rgba(13,17,28,.74)'), 'rgba(13,17,28,.74)'),
        'achievements_panel_border' => af_aa_sanitize_overlay(af_aa_get_apui_setting('achievements_panel_border', 'rgba(255,255,255,.12)'), 'rgba(255,255,255,.12)'),
        'custom_css' => '',
        'fragment_key' => 'profile_banner',
    ];
}

function af_aa_get_user_preset_settings_for_target(int $uid, string $assignmentTargetKey, array $defaults): array
{
    $uid = (int)$uid;
    if ($uid <= 0 || $assignmentTargetKey === '') {
        return [];
    }

    $assignment = af_aa_get_active_assignment('user', $uid, $assignmentTargetKey);
    if (empty($assignment)) {
        return [];
    }

    $preset = af_aa_get_preset_by_id((int)($assignment['preset_id'] ?? 0));
    if (empty($preset)) {
        return [];
    }

    $targetKey = (string)($preset['target_key'] ?? '');
    $settings = af_aa_decode_and_sanitize_preset_settings((string)($preset['settings_json'] ?? ''), $defaults, $targetKey);

    return [
        'preset' => $preset,
        'settings' => $settings,
    ];
}

function af_aa_merge_keys(array $base, array $override, array $keys): array
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $override)) {
            $base[$key] = $override[$key];
        }
    }

    return $base;
}

function af_aa_build_user_css_payload(int $uid): array
{
    $uid = (int)$uid;
    if ($uid <= 0) {
        return [];
    }

    if (!isset($GLOBALS['af_aa_payload_cache_runtime']) || !is_array($GLOBALS['af_aa_payload_cache_runtime'])) {
        $GLOBALS['af_aa_payload_cache_runtime'] = [];
    }

    if (array_key_exists($uid, $GLOBALS['af_aa_payload_cache_runtime'])) {
        return $GLOBALS['af_aa_payload_cache_runtime'][$uid];
    }

    $defaults = af_aa_get_apui_defaults();

    $threadKeys = [
        'thread_body_cover_url',
        'thread_body_tile_url',
        'thread_body_bg_mode',
        'thread_body_overlay',
        'thread_banner_url',
        'thread_banner_overlay',
    ];

    $threadBodyKeys = [
        'thread_body_cover_url',
        'thread_body_tile_url',
        'thread_body_bg_mode',
        'thread_body_overlay',
    ];

    $threadBannerKeys = [
        'thread_banner_url',
        'thread_banner_overlay',
    ];

    $profileKeys = [
        'member_profile_body_cover_url',
        'member_profile_body_tile_url',
        'member_profile_body_bg_mode',
        'member_profile_body_overlay',
        'profile_banner_url',
        'profile_banner_overlay',
    ];

    $bodyKeys = [
        'member_profile_body_cover_url',
        'member_profile_body_tile_url',
        'member_profile_body_bg_mode',
        'member_profile_body_overlay',
    ];

    $bannerKeys = [
        'profile_banner_url',
        'profile_banner_overlay',
    ];

    $postbitKeys = [
        'postbit_author_bg_url',
        'postbit_author_overlay',
        'postbit_name_bg_url',
        'postbit_name_overlay',
        'postbit_plaque_bg_url',
        'postbit_plaque_overlay',
        'postbit_plaque_media_image_url',
        'postbit_plaque_media_icon_class',
        'postbit_plaque_media_overlay',
        'postbit_plaque_media_css',
        'postbit_plaque_title',
        'postbit_plaque_subtitle',
        'postbit_plaque_title_default',
        'postbit_plaque_subtitle_default',
        'postbit_plaque_icon_url',
        'postbit_plaque_icon_glyph',
        'postbit_plaque_icon_bg',
        'postbit_plaque_icon_overlay',
        'postbit_plaque_icon_border',
        'postbit_plaque_icon_color',
        'postbit_plaque_icon_size',
    ];

    $authorKeys = [
        'postbit_author_bg_url',
        'postbit_author_overlay',
    ];

    $nameKeys = [
        'postbit_name_bg_url',
        'postbit_name_overlay',
    ];

    $plaqueKeys = [
        'postbit_plaque_bg_url',
        'postbit_plaque_overlay',
        'postbit_plaque_media_image_url',
        'postbit_plaque_media_icon_class',
        'postbit_plaque_media_overlay',
        'postbit_plaque_media_css',
        'postbit_plaque_title',
        'postbit_plaque_subtitle',
        'postbit_plaque_title_default',
        'postbit_plaque_subtitle_default',
        'postbit_plaque_icon_url',
        'postbit_plaque_icon_glyph',
        'postbit_plaque_icon_bg',
        'postbit_plaque_icon_overlay',
        'postbit_plaque_icon_border',
        'postbit_plaque_icon_color',
        'postbit_plaque_icon_size',
    ];

    $modalKeys = [
        'sheet_bg_url','sheet_bg_overlay','sheet_panel_bg','sheet_panel_border',
        'application_bg_url','application_bg_overlay','application_panel_bg','application_panel_border',
        'inventory_bg_url','inventory_bg_overlay','inventory_panel_bg','inventory_panel_border',
        'achievements_bg_url','achievements_bg_overlay','achievements_panel_bg','achievements_panel_border',
    ];

    $profileSettings = af_aa_merge_keys([], $defaults, $profileKeys);
    $postbitSettings = af_aa_merge_keys([], $defaults, $postbitKeys);
    $threadSettings = af_aa_merge_keys([], $defaults, $threadKeys);
    $modalSettings = af_aa_merge_keys([], $defaults, $modalKeys);
    $customCssBlocks = [];

    $surfaceKeyMap = [
        AF_AA_TARGET_APUI_THREAD_PACK => [
            'keys' => $threadKeys,
        ],
        AF_AA_TARGET_APUI_APPLICATION_PACK => [
            'keys' => ['application_bg_url', 'application_bg_overlay', 'application_panel_bg', 'application_panel_border'],
        ],
        AF_AA_TARGET_APUI_SHEET_PACK => [
            'keys' => ['sheet_bg_url', 'sheet_bg_overlay', 'sheet_panel_bg', 'sheet_panel_border'],
        ],
        AF_AA_TARGET_APUI_INVENTORY_PACK => [
            'keys' => ['inventory_bg_url', 'inventory_bg_overlay', 'inventory_panel_bg', 'inventory_panel_border'],
        ],
        AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK => [
            'keys' => ['achievements_bg_url', 'achievements_bg_overlay', 'achievements_panel_bg', 'achievements_panel_border'],
        ],
    ];

    $themePack = af_aa_get_user_preset_settings_for_target($uid, AF_AA_TARGET_APUI_THEME_PACK, $defaults);
    if (!empty($themePack)) {
        $themeSettings = (array)$themePack['settings'];
        $profileSettings = af_aa_merge_keys($profileSettings, $themeSettings, $profileKeys);
        $postbitSettings = af_aa_merge_keys($postbitSettings, $themeSettings, $postbitKeys);
        $threadSettings = af_aa_merge_keys($threadSettings, $themeSettings, $threadKeys);
        $modalSettings = af_aa_merge_keys($modalSettings, $themeSettings, $modalKeys);

        if (!empty($themeSettings['custom_css'])) {
            $customCssBlocks[] = (string)$themeSettings['custom_css'];
        }
    }

    $profilePack = af_aa_get_user_preset_settings_for_target($uid, AF_AA_TARGET_APUI_PROFILE_PACK, $defaults);
    if (!empty($profilePack)) {
        $profilePackSettings = (array)$profilePack['settings'];
        $profileSettings = af_aa_merge_keys($profileSettings, $profilePackSettings, $profileKeys);
        $modalSettings = af_aa_merge_keys($modalSettings, $profilePackSettings, $modalKeys);

        if (!empty($profilePackSettings['custom_css'])) {
            $customCssBlocks[] = (string)$profilePackSettings['custom_css'];
        }
    }

    $postbitPack = af_aa_get_user_preset_settings_for_target($uid, AF_AA_TARGET_APUI_POSTBIT_PACK, $defaults);
    if (!empty($postbitPack)) {
        $postbitPackSettings = (array)$postbitPack['settings'];
        $postbitSettings = af_aa_merge_keys($postbitSettings, $postbitPackSettings, $postbitKeys);
        $modalSettings = af_aa_merge_keys($modalSettings, $postbitPackSettings, $modalKeys);

        if (!empty($postbitPackSettings['custom_css'])) {
            $customCssBlocks[] = (string)$postbitPackSettings['custom_css'];
        }
    }

    foreach ($surfaceKeyMap as $surfaceTargetKey => $surfaceMeta) {
        $surfacePack = af_aa_get_user_preset_settings_for_target($uid, $surfaceTargetKey, $defaults);
        if (empty($surfacePack)) {
            continue;
        }

        $surfaceSettings = (array)$surfacePack['settings'];
        $modalSettings = af_aa_merge_keys($modalSettings, $surfaceSettings, (array)$surfaceMeta['keys']);

        if (!empty($surfaceSettings['custom_css'])) {
            $customCssBlocks[] = (string)$surfaceSettings['custom_css'];
        }
    }

    foreach (array_keys(af_aa_get_supported_fragment_keys()) as $fragmentKey) {
        $fragmentTarget = AF_AA_TARGET_APUI_FRAGMENT_PACK . ':' . $fragmentKey;
        $fragmentPack = af_aa_get_user_preset_settings_for_target($uid, $fragmentTarget, $defaults);

        if (empty($fragmentPack)) {
            continue;
        }

        $fragmentSettings = (array)$fragmentPack['settings'];

        switch ($fragmentKey) {
            case 'thread_body':
                $threadSettings = af_aa_merge_keys($threadSettings, $fragmentSettings, $threadBodyKeys);
                break;

            case 'thread_banner':
                $threadSettings = af_aa_merge_keys($threadSettings, $fragmentSettings, $threadBannerKeys);
                break;

            case 'profile_body':
                $profileSettings = af_aa_merge_keys($profileSettings, $fragmentSettings, $bodyKeys);
                break;

            case 'profile_banner':
                $profileSettings = af_aa_merge_keys($profileSettings, $fragmentSettings, $bannerKeys);
                break;

            case 'postbit_author':
                $postbitSettings = af_aa_merge_keys($postbitSettings, $fragmentSettings, $authorKeys);
                break;

            case 'postbit_name':
                $postbitSettings = af_aa_merge_keys($postbitSettings, $fragmentSettings, $nameKeys);
                break;

            case 'postbit_plaque':
                $postbitSettings = af_aa_merge_keys($postbitSettings, $fragmentSettings, $plaqueKeys);
                break;

            case 'profile_avatar_frame':
            case 'postbit_avatar_frame':
            default:
                break;
        }

        if (!empty($fragmentSettings['custom_css'])) {
            $customCssBlocks[] = (string)$fragmentSettings['custom_css'];
        }
    }

    $mode = af_aa_sanitize_bg_mode((string)($profileSettings['member_profile_body_bg_mode'] ?? 'cover'), 'cover');

    $selectedBodyImage = $mode === 'tile'
        ? af_aa_css_url_value((string)($profileSettings['member_profile_body_tile_url'] ?? ''))
        : af_aa_css_url_value((string)($profileSettings['member_profile_body_cover_url'] ?? ''));

    if ($selectedBodyImage === 'none') {
        $selectedBodyImage = $mode === 'tile'
            ? af_aa_css_url_value((string)($profileSettings['member_profile_body_cover_url'] ?? ''))
            : af_aa_css_url_value((string)($profileSettings['member_profile_body_tile_url'] ?? ''));

        if ($selectedBodyImage !== 'none') {
            $mode = $mode === 'tile' ? 'cover' : 'tile';
        }
    }

    $threadMode = af_aa_sanitize_bg_mode((string)($threadSettings['thread_body_bg_mode'] ?? 'cover'), 'cover');
    $selectedThreadBodyImage = $threadMode === 'tile'
        ? af_aa_css_url_value((string)($threadSettings['thread_body_tile_url'] ?? ''))
        : af_aa_css_url_value((string)($threadSettings['thread_body_cover_url'] ?? ''));

    if ($selectedThreadBodyImage === 'none') {
        $selectedThreadBodyImage = $threadMode === 'tile'
            ? af_aa_css_url_value((string)($threadSettings['thread_body_cover_url'] ?? ''))
            : af_aa_css_url_value((string)($threadSettings['thread_body_tile_url'] ?? ''));

        if ($selectedThreadBodyImage !== 'none') {
            $threadMode = $threadMode === 'tile' ? 'cover' : 'tile';
        }
    }

    $selector = '.af-aa-user-' . $uid;

    $payload = [
        'uid' => $uid,
        'selector' => $selector,
        'body_selector' => 'body.af-apui-member-profile-page.af-aa-user-' . $uid,
        'vars' => [
            '--af-apui-profile-banner-image' => af_aa_css_url_value((string)($profileSettings['profile_banner_url'] ?? '')),
            '--af-apui-profile-banner-overlay' => af_aa_css_raw_value((string)($profileSettings['profile_banner_overlay'] ?? 'none'), 'none'),
            '--af-apui-thread-banner-image' => af_aa_css_url_value((string)($threadSettings['thread_banner_url'] ?? '')),
            '--af-apui-thread-banner-overlay' => af_aa_css_raw_value((string)($threadSettings['thread_banner_overlay'] ?? 'none'), 'none'),
            '--af-apui-postbit-author-bg-image' => af_aa_css_url_value((string)($postbitSettings['postbit_author_bg_url'] ?? '')),
            '--af-apui-postbit-author-overlay' => af_aa_css_raw_value((string)($postbitSettings['postbit_author_overlay'] ?? 'none'), 'none'),
            '--af-apui-postbit-name-bg-image' => af_aa_css_url_value((string)($postbitSettings['postbit_name_bg_url'] ?? '')),
            '--af-apui-postbit-name-overlay' => af_aa_css_raw_value((string)($postbitSettings['postbit_name_overlay'] ?? 'none'), 'none'),
            '--af-apui-postbit-plaque-bg-image' => af_aa_css_url_value((string)($postbitSettings['postbit_plaque_bg_url'] ?? '')),
            '--af-apui-postbit-plaque-overlay' => af_aa_css_raw_value((string)($postbitSettings['postbit_plaque_overlay'] ?? 'none'), 'none'),
            '--af-apui-postbit-plaque-media-overlay' => af_aa_css_raw_value((string)($postbitSettings['postbit_plaque_media_overlay'] ?? 'none'), 'none'),
            '--af-apui-postbit-plaque-icon-bg' => af_aa_css_raw_value((string)($postbitSettings['postbit_plaque_icon_bg'] ?? 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))'), 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))'),
            '--af-apui-postbit-plaque-icon-overlay' => af_aa_css_raw_value((string)($postbitSettings['postbit_plaque_icon_overlay'] ?? 'none'), 'none'),
            '--af-apui-postbit-plaque-icon-border' => af_aa_css_raw_value((string)($postbitSettings['postbit_plaque_icon_border'] ?? 'rgba(255,255,255,.18)'), 'rgba(255,255,255,.18)'),
            '--af-apui-postbit-plaque-icon-color' => af_aa_css_raw_value((string)($postbitSettings['postbit_plaque_icon_color'] ?? '#f6f1cf'), '#f6f1cf'),
            '--af-apui-postbit-plaque-icon-size' => af_aa_css_raw_value((string)($postbitSettings['postbit_plaque_icon_size'] ?? '26px'), '26px'),
            '--af-apui-modal-sheet-bg-image' => af_aa_css_url_value((string)($modalSettings['sheet_bg_url'] ?? '')),
            '--af-apui-modal-sheet-bg-overlay' => af_aa_css_raw_value((string)($modalSettings['sheet_bg_overlay'] ?? 'none'), 'none'),
            '--af-apui-modal-sheet-panel-bg' => af_aa_css_raw_value((string)($modalSettings['sheet_panel_bg'] ?? 'rgba(0,0,0,.12)'), 'rgba(0,0,0,.12)'),
            '--af-apui-modal-sheet-panel-border' => af_aa_css_raw_value((string)($modalSettings['sheet_panel_border'] ?? 'rgba(255,255,255,.12)'), 'rgba(255,255,255,.12)'),
            '--af-apui-modal-application-bg-image' => af_aa_css_url_value((string)($modalSettings['application_bg_url'] ?? '')),
            '--af-apui-modal-application-bg-overlay' => af_aa_css_raw_value((string)($modalSettings['application_bg_overlay'] ?? 'none'), 'none'),
            '--af-apui-modal-application-panel-bg' => af_aa_css_raw_value((string)($modalSettings['application_panel_bg'] ?? 'rgba(6,12,26,.58)'), 'rgba(6,12,26,.58)'),
            '--af-apui-modal-application-panel-border' => af_aa_css_raw_value((string)($modalSettings['application_panel_border'] ?? 'rgba(255,255,255,.10)'), 'rgba(255,255,255,.10)'),
            '--af-apui-modal-inventory-bg-image' => af_aa_css_url_value((string)($modalSettings['inventory_bg_url'] ?? '')),
            '--af-apui-modal-inventory-bg-overlay' => af_aa_css_raw_value((string)($modalSettings['inventory_bg_overlay'] ?? 'none'), 'none'),
            '--af-apui-modal-inventory-panel-bg' => af_aa_css_raw_value((string)($modalSettings['inventory_panel_bg'] ?? 'rgba(21,25,34,.92)'), 'rgba(21,25,34,.92)'),
            '--af-apui-modal-inventory-panel-border' => af_aa_css_raw_value((string)($modalSettings['inventory_panel_border'] ?? 'rgba(255,255,255,.12)'), 'rgba(255,255,255,.12)'),
            '--af-apui-modal-achievements-bg-image' => af_aa_css_url_value((string)($modalSettings['achievements_bg_url'] ?? '')),
            '--af-apui-modal-achievements-bg-overlay' => af_aa_css_raw_value((string)($modalSettings['achievements_bg_overlay'] ?? 'none'), 'none'),
            '--af-apui-modal-achievements-panel-bg' => af_aa_css_raw_value((string)($modalSettings['achievements_panel_bg'] ?? 'rgba(13,17,28,.74)'), 'rgba(13,17,28,.74)'),
            '--af-apui-modal-achievements-panel-border' => af_aa_css_raw_value((string)($modalSettings['achievements_panel_border'] ?? 'rgba(255,255,255,.12)'), 'rgba(255,255,255,.12)'),
        ],
        'body' => [
            'overlay' => af_aa_css_raw_value((string)($profileSettings['member_profile_body_overlay'] ?? 'none'), 'none'),
            'image' => $selectedBodyImage,
            'repeat' => $mode === 'tile' ? 'repeat' : 'no-repeat',
            'position' => $mode === 'tile' ? 'left top' : 'center center',
            'attachment' => $mode === 'tile' ? 'scroll' : 'fixed',
            'size' => $mode === 'tile' ? 'auto' : 'cover',
        ],
        'thread_body' => [
            'overlay' => af_aa_css_raw_value((string)($threadSettings['thread_body_overlay'] ?? 'none'), 'none'),
            'image' => $selectedThreadBodyImage,
            'repeat' => $threadMode === 'tile' ? 'repeat' : 'no-repeat',
            'position' => $threadMode === 'tile' ? 'left top' : 'center center',
            'attachment' => $threadMode === 'tile' ? 'scroll' : 'fixed',
            'size' => $threadMode === 'tile' ? 'auto' : 'cover',
        ],
        'custom_css_blocks' => $customCssBlocks,
    ];

    $GLOBALS['af_aa_payload_cache_runtime'][$uid] = $payload;
    return $payload;
}

function af_aa_render_page_css(array $uidsOnPage): string
{
    $uids = [];
    foreach ($uidsOnPage as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) {
            $uids[$uid] = $uid;
        }
    }

    if (empty($uids)) {
        return '';
    }

    af_aa_prime_runtime_cache(array_values($uids));

    $css = '';

    foreach ($uids as $uid) {
        $payload = af_aa_build_user_css_payload($uid);
        if (empty($payload)) {
            continue;
        }

        $selector = (string)($payload['selector'] ?? '');
        $bodySelector = (string)($payload['body_selector'] ?? '');
        $threadBodySelector = 'body.af-apui-thread-page.af-aa-user-' . $uid;

        if ($selector === '') {
            continue;
        }

        $vars = isset($payload['vars']) && is_array($payload['vars'])
            ? $payload['vars']
            : [];

        $css .= $selector . '{';
        foreach ($vars as $varName => $varValue) {
            $varName = trim((string)$varName);
            $varValue = trim((string)$varValue);

            if ($varName === '' || $varValue === '') {
                continue;
            }

            $css .= $varName . ':' . $varValue . ';';
        }
        $css .= "}\n";

        $body = isset($payload['body']) && is_array($payload['body'])
            ? $payload['body']
            : [];

        $memberBodyDeclarations = [];

        $memberOverlay = trim((string)($body['overlay'] ?? 'none'));
        if ($memberOverlay !== '' && strtolower($memberOverlay) !== 'none') {
            $memberBodyDeclarations[] = '--af-apui-member-profile-body-overlay:' . $memberOverlay . ';';
        }

        $memberImage = trim((string)($body['image'] ?? 'none'));
        if ($memberImage !== '' && strtolower($memberImage) !== 'none') {
            $memberBodyDeclarations[] = 'background-image:' . $memberImage . ';';
            $memberBodyDeclarations[] = 'background-repeat:' . trim((string)($body['repeat'] ?? 'no-repeat')) . ';';
            $memberBodyDeclarations[] = 'background-position:' . trim((string)($body['position'] ?? 'center center')) . ';';
            $memberBodyDeclarations[] = 'background-attachment:' . trim((string)($body['attachment'] ?? 'fixed')) . ';';
            $memberBodyDeclarations[] = 'background-size:' . trim((string)($body['size'] ?? 'cover')) . ';';
        }

        if ($bodySelector !== '' && !empty($memberBodyDeclarations)) {
            $css .= $bodySelector . '{' . implode('', $memberBodyDeclarations) . "}\n";
        }

        $threadBody = isset($payload['thread_body']) && is_array($payload['thread_body'])
            ? $payload['thread_body']
            : [];

        $threadBodyDeclarations = [];

        $threadOverlay = trim((string)($threadBody['overlay'] ?? 'none'));
        if ($threadOverlay !== '' && strtolower($threadOverlay) !== 'none') {
            $threadBodyDeclarations[] = '--af-apui-thread-body-overlay:' . $threadOverlay . ';';
        }

        $threadImage = trim((string)($threadBody['image'] ?? 'none'));
        if ($threadImage !== '' && strtolower($threadImage) !== 'none') {
            $threadBodyDeclarations[] = 'background-image:' . $threadImage . ';';
            $threadBodyDeclarations[] = 'background-repeat:' . trim((string)($threadBody['repeat'] ?? 'no-repeat')) . ';';
            $threadBodyDeclarations[] = 'background-position:' . trim((string)($threadBody['position'] ?? 'center center')) . ';';
            $threadBodyDeclarations[] = 'background-attachment:' . trim((string)($threadBody['attachment'] ?? 'fixed')) . ';';
            $threadBodyDeclarations[] = 'background-size:' . trim((string)($threadBody['size'] ?? 'cover')) . ';';
        }

        if (!empty($threadBodyDeclarations)) {
            $css .= $threadBodySelector . '{' . implode('', $threadBodyDeclarations) . "}\n";
        }

        if (!empty($payload['custom_css_blocks']) && is_array($payload['custom_css_blocks'])) {
            foreach ($payload['custom_css_blocks'] as $cssBlock) {
                $css .= af_aa_render_scoped_custom_css((string)$cssBlock, $payload);
            }
        }
    }

    if ($css === '') {
        return '';
    }

    return '<style id="af-aa-runtime-css">' . $css . '</style>' . "\n";
}

function af_aa_prime_runtime_cache(array $uids): void
{
    global $db;

    $uids = array_values(array_filter(array_map('intval', $uids), static function ($uid) {
        return $uid > 0;
    }));

    if (empty($uids)) {
        return;
    }

    if (!isset($GLOBALS['af_aa_assignment_cache_runtime']) || !is_array($GLOBALS['af_aa_assignment_cache_runtime'])) {
        $GLOBALS['af_aa_assignment_cache_runtime'] = [];
    }

    if (!isset($GLOBALS['af_aa_preset_cache_runtime']) || !is_array($GLOBALS['af_aa_preset_cache_runtime'])) {
        $GLOBALS['af_aa_preset_cache_runtime'] = [];
    }

    $in = implode(',', $uids);

    $targetList = array_map(static function ($target) use ($db) {
        return "'" . $db->escape_string($target) . "'";
    }, af_aa_get_all_assignment_target_keys());

    $assignmentsByPreset = [];

    $queryAssignments = $db->write_query(
        "SELECT * FROM " . AF_AA_ASSIGNMENTS_TABLE
        . " WHERE entity_type='user'"
        . " AND is_enabled='1'"
        . " AND entity_id IN (" . $in . ")"
        . " AND target_key IN (" . implode(',', $targetList) . ")"
    );

    while ($row = $db->fetch_array($queryAssignments)) {
        $uid = (int)($row['entity_id'] ?? 0);
        $targetKey = (string)($row['target_key'] ?? '');

        if ($uid <= 0 || $targetKey === '') {
            continue;
        }

        $row['id'] = (int)($row['id'] ?? 0);
        $row['entity_id'] = (int)($row['entity_id'] ?? 0);
        $row['preset_id'] = (int)($row['preset_id'] ?? 0);
        $row['is_enabled'] = (int)($row['is_enabled'] ?? 0);

        $cacheKey = 'user:' . $uid . ':' . $targetKey;
        $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey] = $row;

        $presetId = (int)($row['preset_id'] ?? 0);
        if ($presetId > 0) {
            $assignmentsByPreset[$presetId] = $presetId;
        }
    }

    if (empty($assignmentsByPreset)) {
        return;
    }

    $presetIn = implode(',', array_values($assignmentsByPreset));

    $queryPresets = $db->write_query(
        "SELECT * FROM " . AF_AA_PRESETS_TABLE
        . " WHERE id IN (" . $presetIn . ")"
        . " AND enabled='1'"
    );

    while ($row = $db->fetch_array($queryPresets)) {
        $pid = (int)($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $row['id'] = $pid;
        $row['enabled'] = (int)($row['enabled'] ?? 0);
        $row['sortorder'] = (int)($row['sortorder'] ?? 0);

        $GLOBALS['af_aa_preset_cache_runtime'][$pid] = $row;
    }
}

function af_aa_decode_and_sanitize_preset_settings(string $json, array $defaults, string $targetKey = ''): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $out = $defaults;

    $out['member_profile_body_cover_url'] = af_aa_sanitize_image_url(
        (string)($decoded['member_profile_body_cover_url'] ?? ''),
        (string)($defaults['member_profile_body_cover_url'] ?? '')
    );

    $out['member_profile_body_tile_url'] = af_aa_sanitize_image_url(
        (string)($decoded['member_profile_body_tile_url'] ?? ''),
        (string)($defaults['member_profile_body_tile_url'] ?? '')
    );

    $out['member_profile_body_bg_mode'] = af_aa_sanitize_bg_mode(
        (string)($decoded['member_profile_body_bg_mode'] ?? ''),
        (string)($defaults['member_profile_body_bg_mode'] ?? 'cover')
    );

    $out['member_profile_body_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['member_profile_body_overlay'] ?? ''),
        (string)($defaults['member_profile_body_overlay'] ?? 'none')
    );

    $out['thread_body_cover_url'] = af_aa_sanitize_image_url(
        (string)($decoded['thread_body_cover_url'] ?? ''),
        (string)($defaults['thread_body_cover_url'] ?? '')
    );

    $out['thread_body_tile_url'] = af_aa_sanitize_image_url(
        (string)($decoded['thread_body_tile_url'] ?? ''),
        (string)($defaults['thread_body_tile_url'] ?? '')
    );

    $out['thread_body_bg_mode'] = af_aa_sanitize_bg_mode(
        (string)($decoded['thread_body_bg_mode'] ?? ''),
        (string)($defaults['thread_body_bg_mode'] ?? 'cover')
    );

    $out['thread_body_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['thread_body_overlay'] ?? ''),
        (string)($defaults['thread_body_overlay'] ?? 'none')
    );

    $out['thread_banner_url'] = af_aa_sanitize_image_url(
        (string)($decoded['thread_banner_url'] ?? ''),
        (string)($defaults['thread_banner_url'] ?? '')
    );

    $out['thread_banner_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['thread_banner_overlay'] ?? ''),
        (string)($defaults['thread_banner_overlay'] ?? 'none')
    );

    $out['profile_banner_url'] = af_aa_sanitize_image_url(
        (string)($decoded['profile_banner_url'] ?? ''),
        (string)($defaults['profile_banner_url'] ?? '')
    );

    $out['profile_banner_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['profile_banner_overlay'] ?? ''),
        (string)($defaults['profile_banner_overlay'] ?? 'none')
    );

    $out['postbit_author_bg_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_author_bg_url'] ?? ''),
        (string)($defaults['postbit_author_bg_url'] ?? '')
    );

    $out['postbit_author_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_author_overlay'] ?? ''),
        (string)($defaults['postbit_author_overlay'] ?? 'none')
    );

    $out['postbit_name_bg_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_name_bg_url'] ?? ''),
        (string)($defaults['postbit_name_bg_url'] ?? '')
    );

    $out['postbit_name_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_name_overlay'] ?? ''),
        (string)($defaults['postbit_name_overlay'] ?? 'none')
    );

    $out['postbit_plaque_bg_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_plaque_bg_url'] ?? ''),
        (string)($defaults['postbit_plaque_bg_url'] ?? '')
    );

    $out['postbit_plaque_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_plaque_overlay'] ?? ''),
        (string)($defaults['postbit_plaque_overlay'] ?? 'none')
    );
    $out['postbit_plaque_media_image_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_plaque_media_image_url'] ?? ''),
        (string)($defaults['postbit_plaque_media_image_url'] ?? '')
    );
    $out['postbit_plaque_media_icon_class'] = trim((string)($decoded['postbit_plaque_media_icon_class'] ?? ($defaults['postbit_plaque_media_icon_class'] ?? '')));
    $out['postbit_plaque_media_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_plaque_media_overlay'] ?? ''),
        (string)($defaults['postbit_plaque_media_overlay'] ?? 'none')
    );
    $out['postbit_plaque_media_css'] = trim((string)($decoded['postbit_plaque_media_css'] ?? ($defaults['postbit_plaque_media_css'] ?? '')));
    $out['postbit_plaque_title'] = trim((string)($decoded['postbit_plaque_title'] ?? ($defaults['postbit_plaque_title'] ?? '')));
    $out['postbit_plaque_subtitle'] = trim((string)($decoded['postbit_plaque_subtitle'] ?? ($defaults['postbit_plaque_subtitle'] ?? '')));
    $out['postbit_plaque_title_default'] = trim((string)($decoded['postbit_plaque_title_default'] ?? ($defaults['postbit_plaque_title_default'] ?? 'Profile plaque')));
    $out['postbit_plaque_subtitle_default'] = trim((string)($decoded['postbit_plaque_subtitle_default'] ?? ($defaults['postbit_plaque_subtitle_default'] ?? 'Decorative media slot')));
    $out['postbit_plaque_icon_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_plaque_icon_url'] ?? ''),
        (string)($defaults['postbit_plaque_icon_url'] ?? '')
    );
    $out['postbit_plaque_icon_glyph'] = af_aa_sanitize_icon_glyph(
        (string)($decoded['postbit_plaque_icon_glyph'] ?? ''),
        (string)($defaults['postbit_plaque_icon_glyph'] ?? '★')
    );
    $out['postbit_plaque_icon_bg'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_plaque_icon_bg'] ?? ''),
        (string)($defaults['postbit_plaque_icon_bg'] ?? 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))')
    );
    $out['postbit_plaque_icon_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_plaque_icon_overlay'] ?? ''),
        (string)($defaults['postbit_plaque_icon_overlay'] ?? 'none')
    );
    $out['postbit_plaque_icon_border'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_plaque_icon_border'] ?? ''),
        (string)($defaults['postbit_plaque_icon_border'] ?? 'rgba(255,255,255,.18)')
    );
    $out['postbit_plaque_icon_color'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_plaque_icon_color'] ?? ''),
        (string)($defaults['postbit_plaque_icon_color'] ?? '#f6f1cf')
    );
    $out['postbit_plaque_icon_size'] = af_aa_sanitize_css_size(
        (string)($decoded['postbit_plaque_icon_size'] ?? ''),
        (string)($defaults['postbit_plaque_icon_size'] ?? '26px')
    );

    $out['sheet_bg_url'] = af_aa_sanitize_image_url((string)($decoded['sheet_bg_url'] ?? ''), (string)($defaults['sheet_bg_url'] ?? ''));
    $out['sheet_bg_overlay'] = af_aa_sanitize_overlay((string)($decoded['sheet_bg_overlay'] ?? ''), (string)($defaults['sheet_bg_overlay'] ?? 'none'));
    $out['sheet_panel_bg'] = af_aa_sanitize_overlay((string)($decoded['sheet_panel_bg'] ?? ''), (string)($defaults['sheet_panel_bg'] ?? 'rgba(0,0,0,.12)'));
    $out['sheet_panel_border'] = af_aa_sanitize_overlay((string)($decoded['sheet_panel_border'] ?? ''), (string)($defaults['sheet_panel_border'] ?? 'rgba(255,255,255,.12)'));
    $out['application_bg_url'] = af_aa_sanitize_image_url((string)($decoded['application_bg_url'] ?? ''), (string)($defaults['application_bg_url'] ?? ''));
    $out['application_bg_overlay'] = af_aa_sanitize_overlay((string)($decoded['application_bg_overlay'] ?? ''), (string)($defaults['application_bg_overlay'] ?? 'none'));
    $out['application_panel_bg'] = af_aa_sanitize_overlay((string)($decoded['application_panel_bg'] ?? ''), (string)($defaults['application_panel_bg'] ?? 'rgba(6,12,26,.58)'));
    $out['application_panel_border'] = af_aa_sanitize_overlay((string)($decoded['application_panel_border'] ?? ''), (string)($defaults['application_panel_border'] ?? 'rgba(255,255,255,.10)'));
    $out['inventory_bg_url'] = af_aa_sanitize_image_url((string)($decoded['inventory_bg_url'] ?? ''), (string)($defaults['inventory_bg_url'] ?? ''));
    $out['inventory_bg_overlay'] = af_aa_sanitize_overlay((string)($decoded['inventory_bg_overlay'] ?? ''), (string)($defaults['inventory_bg_overlay'] ?? 'none'));
    $out['inventory_panel_bg'] = af_aa_sanitize_overlay((string)($decoded['inventory_panel_bg'] ?? ''), (string)($defaults['inventory_panel_bg'] ?? 'rgba(21,25,34,.92)'));
    $out['inventory_panel_border'] = af_aa_sanitize_overlay((string)($decoded['inventory_panel_border'] ?? ''), (string)($defaults['inventory_panel_border'] ?? 'rgba(255,255,255,.12)'));
    $out['achievements_bg_url'] = af_aa_sanitize_image_url((string)($decoded['achievements_bg_url'] ?? ''), (string)($defaults['achievements_bg_url'] ?? ''));
    $out['achievements_bg_overlay'] = af_aa_sanitize_overlay((string)($decoded['achievements_bg_overlay'] ?? ''), (string)($defaults['achievements_bg_overlay'] ?? 'none'));
    $out['achievements_panel_bg'] = af_aa_sanitize_overlay((string)($decoded['achievements_panel_bg'] ?? ''), (string)($defaults['achievements_panel_bg'] ?? 'rgba(13,17,28,.74)'));
    $out['achievements_panel_border'] = af_aa_sanitize_overlay((string)($decoded['achievements_panel_border'] ?? ''), (string)($defaults['achievements_panel_border'] ?? 'rgba(255,255,255,.12)'));

    $out['custom_css'] = af_aa_sanitize_custom_css((string)($decoded['custom_css'] ?? ''));
    $out['fragment_key'] = af_aa_sanitize_fragment_key(
        (string)($decoded['fragment_key'] ?? ''),
        (string)($defaults['fragment_key'] ?? 'profile_banner')
    );

    return $out;
}

function af_aa_sanitize_image_url(string $url, string $fallback = ''): string
{
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }

    if (preg_match('~[\r\n<>]~', $url)) {
        return $fallback;
    }

    if (stripos($url, 'javascript:') !== false) {
        return $fallback;
    }

    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return $fallback;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return $fallback;
    }

    return $url;
}

function af_aa_sanitize_icon_glyph(string $value, string $fallback = '★'): string
{
    $value = trim($value);
    $value = preg_replace('~[\x00-\x1F\x7F]+~u', '', $value) ?? '';
    if ($value === '') {
        return $fallback;
    }

    return function_exists('my_substr') ? my_substr($value, 0, 3) : mb_substr($value, 0, 3);
}

function af_aa_sanitize_css_size(string $value, string $fallback = '26px'): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    if (!preg_match('~^(?:\d+(?:\.\d+)?)(?:px|rem|em|%)$~i', $value)) {
        return $fallback;
    }

    return $value;
}

function af_aa_sanitize_bg_mode(string $mode, string $fallback = 'cover'): string
{
    $mode = strtolower(trim($mode));
    if ($mode !== 'cover' && $mode !== 'tile') {
        return $fallback;
    }

    return $mode;
}

function af_aa_sanitize_overlay(string $value, string $fallback = 'none'): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    if (strpos($value, ';') !== false) {
        return $fallback;
    }

    if (preg_match('~[\r\n]~', $value)) {
        return $fallback;
    }

    if (stripos($value, '<style') !== false || stripos($value, '</style') !== false) {
        return $fallback;
    }

    if (stripos($value, 'javascript:') !== false || strpos($value, '<') !== false || strpos($value, '>') !== false) {
        return $fallback;
    }

    return $value;
}

function af_aa_sanitize_fragment_key(string $fragmentKey, string $fallback = 'profile_banner'): string
{
    $fragmentKey = trim($fragmentKey);
    $allowed = af_aa_get_supported_fragment_keys();

    if (!isset($allowed[$fragmentKey])) {
        return $fallback;
    }

    return $fragmentKey;
}

function af_aa_sanitize_custom_css(string $css): string
{
    $css = trim($css);
    if ($css === '') {
        return '';
    }

    $css = str_replace(['</style', '<style'], ['<\/style', ''], $css);

    return trim($css);
}

function af_aa_css_url_value(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return 'none';
    }

    $safe = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $url);

    return 'url("' . $safe . '")';
}

function af_aa_css_raw_value(string $value, string $default = 'none'): string
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }

    $value = str_replace(["\r", "\n", ';'], [' ', ' ', ''], $value);
    $value = str_replace(['</style', '<style'], ['<\\/style', ''], $value);

    return trim($value);
}

function af_aa_prefix_simple_css(string $css, string $scopeSelector): string
{
    if ($css === '' || $scopeSelector === '') {
        return '';
    }

    if (strpos($css, '@') !== false) {
        return $css;
    }

    $result = preg_replace_callback(
        '~(^|})\s*([^{@}][^{]*)\{~m',
        static function ($m) use ($scopeSelector) {
            $lead = $m[1];
            $selectorList = trim($m[2]);

            if ($selectorList === '') {
                return $m[0];
            }

            $parts = array_map('trim', explode(',', $selectorList));
            foreach ($parts as &$part) {
                if ($part === '') {
                    continue;
                }

                if (strpos($part, $scopeSelector) === 0) {
                    continue;
                }

                $part = $scopeSelector . ' ' . $part;
            }
            unset($part);

            return $lead . ' ' . implode(', ', $parts) . ' {';
        },
        $css
    );

    return is_string($result) ? $result : $css;
}

function af_aa_render_scoped_custom_css(string $css, array $payload): string
{
    $css = af_aa_sanitize_custom_css($css);
    if ($css === '') {
        return '';
    }

    $selector = (string)($payload['selector'] ?? '');
    $bodySelector = (string)($payload['body_selector'] ?? '');

    if ($selector === '') {
        return '';
    }

    $containsPlaceholder = strpos($css, '{{selector}}') !== false || strpos($css, '{{body_selector}}') !== false;

    $css = str_replace(
        ['{{selector}}', '{{body_selector}}'],
        [$selector, $bodySelector],
        $css
    );

    if (!$containsPlaceholder) {
        $css = af_aa_prefix_simple_css($css, $selector);
    }

    return $css . "\n";
}

function af_aa_get_apui_setting(string $suffix, string $default = ''): string
{
    global $mybb;

    $key = 'af_advancedprofileui_' . $suffix;
    if (!isset($mybb->settings[$key])) {
        return $default;
    }

    return trim((string)$mybb->settings[$key]);
}

function af_aa_root_path(): string
{
    if (defined('MYBB_ROOT')) {
        return MYBB_ROOT;
    }

    return dirname(dirname(dirname(dirname(dirname(dirname(__FILE__)))))) . '/';
}

function af_aa_template_map(): array
{
    return [
        AF_AA_TPL_APSTUDIO => 'advancedappearance_apstudio.html',
        AF_AA_TPL_FITTINGROOM => 'advancedappearance_fittingroom.html',
    ];
}

function af_aa_get_template_source(string $templateName): string
{
    global $templates;

    $template = '';

    if (isset($templates) && is_object($templates) && method_exists($templates, 'get')) {
        $template = (string)$templates->get($templateName);
    }

    if ($template !== '') {
        return $template;
    }

    $map = af_aa_template_map();
    if (!isset($map[$templateName])) {
        return '<div class="error">Template missing: ' . htmlspecialchars_uni($templateName) . '</div>';
    }

    $file = AF_AA_BASE . 'templates/' . $map[$templateName];
    if (!is_file($file)) {
        return '<div class="error">Template file missing: ' . htmlspecialchars_uni($map[$templateName]) . '</div>';
    }

    return (string)file_get_contents($file);
}

function af_aa_ensure_front_templates(): void
{
    global $db;

    $map = af_aa_template_map();

    foreach ($map as $templateName => $fileName) {
        $file = AF_AA_BASE . 'templates/' . $fileName;
        if (!is_file($file)) {
            continue;
        }

        $content = (string)file_get_contents($file);

        $query = $db->simple_select('templates', 'tid', "title='" . $db->escape_string($templateName) . "'", ['limit' => 1]);
        $existingTid = (int)$db->fetch_field($query, 'tid');

        $payload = [
            'title' => $db->escape_string($templateName),
            'template' => $db->escape_string($content),
            'sid' => -2,
            'version' => $db->escape_string('1839'),
            'dateline' => TIME_NOW,
        ];

        if ($existingTid > 0) {
            $db->update_query('templates', $payload, "tid='" . $existingTid . "'");
            continue;
        }

        $db->insert_query('templates', $payload);
    }
}

function af_aa_remove_front_templates(): void
{
    global $db;

    foreach (array_keys(af_aa_template_map()) as $templateName) {
        $db->delete_query('templates', "title='" . $db->escape_string($templateName) . "'");
    }
}

function af_aa_install_page_aliases(): void
{
    af_aa_sync_alias_file(AF_AA_ALIAS_APSTUDIO, AF_AA_ASSETS_DIR . 'apstudio.php', AF_AA_ALIAS_APSTUDIO_MARK);
    af_aa_sync_alias_file(AF_AA_ALIAS_FITTINGROOM, AF_AA_ASSETS_DIR . 'fittingroom.php', AF_AA_ALIAS_FITTINGROOM_MARK);
}

function af_aa_remove_page_aliases(): void
{
    af_aa_delete_owned_alias(AF_AA_ALIAS_APSTUDIO, AF_AA_ALIAS_APSTUDIO_MARK);
    af_aa_delete_owned_alias(AF_AA_ALIAS_FITTINGROOM, AF_AA_ALIAS_FITTINGROOM_MARK);
}

function af_aa_sync_alias_file(string $rootFileName, string $sourceFile, string $signature): void
{
    $rootFile = af_aa_root_path() . $rootFileName;

    if (!is_file($sourceFile)) {
        return;
    }

    if (!is_file($rootFile) || af_aa_alias_is_owned($rootFile, $signature)) {
        @copy($sourceFile, $rootFile);
    }
}

function af_aa_delete_owned_alias(string $rootFileName, string $signature): void
{
    $rootFile = af_aa_root_path() . $rootFileName;

    if (!is_file($rootFile)) {
        return;
    }

    if (!af_aa_alias_is_owned($rootFile, $signature)) {
        return;
    }

    @unlink($rootFile);
}

function af_aa_alias_is_owned(string $filePath, string $signature): bool
{
    if (!is_file($filePath)) {
        return false;
    }

    $content = @file_get_contents($filePath);
    if (!is_string($content)) {
        return false;
    }

    return strpos($content, $signature) !== false;
}

function af_aa_is_admin_user(int $uid = 0): bool
{
    global $db, $mybb;

    $uid = $uid > 0 ? $uid : (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    if (function_exists('is_super_admin') && is_super_admin($uid)) {
        return true;
    }

    $usergroup = (int)($mybb->user['usergroup'] ?? 0);
    if ($uid === (int)($mybb->user['uid'] ?? 0) && $usergroup === 4) {
        return true;
    }

    $additional = array_filter(array_map('intval', explode(',', (string)($mybb->user['additionalgroups'] ?? ''))));
    if ($uid === (int)($mybb->user['uid'] ?? 0) && in_array(4, $additional, true)) {
        return true;
    }

    if ($db->table_exists('adminoptions')) {
        $query = $db->simple_select('adminoptions', 'uid', "uid='" . $uid . "'", ['limit' => 1]);
        $found = (int)$db->fetch_field($query, 'uid');
        if ($found > 0) {
            return true;
        }
    }

    return false;
}

function af_aa_get_front_defaults(): array
{
    $defaults = af_aa_get_apui_defaults();

    if (!isset($defaults['custom_css'])) {
        $defaults['custom_css'] = '';
    }

    if (!isset($defaults['fragment_key'])) {
        $defaults['fragment_key'] = 'profile_banner';
    }

    return $defaults;
}

function af_aa_resolve_preset_do(string $do = ''): string
{
    $do = trim(strtolower($do));

    $allowed = array_unique(array_column(af_aa_get_supported_targets_registry(), 'do'));

    if (!in_array($do, $allowed, true)) {
        return 'themepack';
    }

    return $do;
}

function af_aa_target_key_for_do(string $do): string
{
    $do = af_aa_resolve_preset_do($do);

    foreach (af_aa_get_supported_targets_registry() as $targetKey => $meta) {
        if (!empty($meta['preset_target']) && (string)($meta['do'] ?? '') === $do) {
            return $targetKey;
        }
    }

    return AF_AA_TARGET_APUI_THEME_PACK;
}

function af_aa_do_for_target(string $targetKey): string
{
    $meta = af_aa_get_target_meta($targetKey);

    return (string)($meta['do'] ?? 'themepack');
}

function af_aa_get_front_tabs(): array
{
    $tabs = [];

    foreach (af_aa_get_supported_targets_registry() as $meta) {
        if (empty($meta['preset_target'])) {
            continue;
        }

        $do = (string)($meta['do'] ?? '');
        $label = (string)($meta['label'] ?? '');

        if ($do !== '' && $label !== '' && !isset($tabs[$do])) {
            $tabs[$do] = $label;
        }
    }

    return $tabs;
}

function af_aa_human_target_label(string $targetKey, array $settings = []): string
{
    if ($targetKey === AF_AA_TARGET_APUI_FRAGMENT_PACK) {
        $fragmentKey = (string)($settings['fragment_key'] ?? '');
        $labelMap = af_aa_get_supported_fragment_keys();
        $fragmentLabel = $labelMap[$fragmentKey] ?? $fragmentKey;

        return 'Дробный пак: ' . $fragmentLabel;
    }

    $meta = af_aa_get_target_meta($targetKey);
    if (!empty($meta['human_label'])) {
        return (string)$meta['human_label'];
    }

    return $targetKey;
}

function af_aa_front_settings_from_row(array $row): array
{
    $defaults = af_aa_get_front_defaults();
    $json = (string)($row['settings_json'] ?? '');
    $targetKey = (string)($row['target_key'] ?? '');

    return af_aa_decode_and_sanitize_preset_settings($json, $defaults, $targetKey);
}

function af_aa_get_preset_row(int $presetId): array
{
    global $db;

    $presetId = (int)$presetId;
    if ($presetId <= 0) {
        return [];
    }

    $query = $db->simple_select(AF_AA_PRESETS_TABLE_NAME, '*', "id='" . $presetId . "'", ['limit' => 1]);
    $row = $db->fetch_array($query);

    return is_array($row) ? $row : [];
}

function af_aa_fetch_presets_for_do(string $do, bool $enabledOnly = false): array
{
    global $db;

    $targetKey = af_aa_target_key_for_do($do);
    $where = "target_key='" . $db->escape_string($targetKey) . "'";

    if ($enabledOnly) {
        $where .= " AND enabled='1'";
    }

    $rows = [];
    $query = $db->simple_select(
        AF_AA_PRESETS_TABLE_NAME,
        '*',
        $where,
        ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']
    );

    while ($row = $db->fetch_array($query)) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function af_aa_save_front_preset(string $do): int
{
    global $db, $mybb;

    $do = af_aa_resolve_preset_do($do);
    $id = (int)$mybb->get_input('id');

    $slugRaw = trim((string)$mybb->get_input('slug'));
    $slug = preg_replace('~[^a-z0-9_\-]+~i', '-', strtolower($slugRaw)) ?? '';
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'preset-' . TIME_NOW;
    }

    $targetKey = af_aa_target_key_for_do($do);

    $settingsInput = $mybb->get_input('settings', MyBB::INPUT_ARRAY);
    if (!is_array($settingsInput)) {
        $settingsInput = [];
    }

    $settings = af_aa_decode_and_sanitize_preset_settings(
        json_encode($settingsInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        af_aa_get_front_defaults(),
        $targetKey
    );

    $previewImage = af_aa_sanitize_image_url((string)$mybb->get_input('preview_image'), '');

    $payload = [
        'slug' => $db->escape_string($slug),
        'title' => $db->escape_string(trim((string)$mybb->get_input('title'))),
        'description' => $db->escape_string(trim((string)$mybb->get_input('description'))),
        'preview_image' => $db->escape_string($previewImage),
        'target_key' => $db->escape_string($targetKey),
        'settings_json' => $db->escape_string(json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'sortorder' => (int)$mybb->get_input('sortorder'),
        'updated_at' => TIME_NOW,
    ];

    if ($id > 0) {
        $db->update_query(AF_AA_PRESETS_TABLE_NAME, $payload, "id='" . $id . "'");
        return $id;
    }

    $payload['enabled'] = 1;
    $payload['created_at'] = TIME_NOW;

    $newId = (int)$db->insert_query(AF_AA_PRESETS_TABLE_NAME, $payload);
    return $newId;
}

function af_aa_delete_front_preset(int $presetId): void
{
    global $db;

    $presetId = (int)$presetId;
    if ($presetId <= 0) {
        return;
    }

    $db->delete_query(AF_AA_PRESETS_TABLE_NAME, "id='" . $presetId . "'");
}

function af_aa_toggle_front_preset(int $presetId, int $enabled): void
{
    global $db;

    $presetId = (int)$presetId;
    if ($presetId <= 0) {
        return;
    }

    $db->update_query(
        AF_AA_PRESETS_TABLE_NAME,
        [
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => TIME_NOW,
        ],
        "id='" . $presetId . "'"
    );
}

function af_aa_page_asset_tags(): string
{
    global $mybb;

    if (!af_aa_is_preview_script()) {
        return '';
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $base = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AA_ID . '/assets';

    $cssUrl = af_aa_add_ver($base . '/advancedappearance.css', AF_AA_ASSETS_DIR . 'advancedappearance.css');
    $jsUrl = af_aa_add_ver($base . '/advancedappearance.js', AF_AA_ASSETS_DIR . 'advancedappearance.js');

    $out = '';
    $out .= "\n" . '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n";
    $out .= '<script src="' . htmlspecialchars_uni($jsUrl) . '" defer></script>' . "\n";

    return $out;
}

function af_aa_ensure_header_bits(): void
{
    global $templates, $headerinclude, $header, $footer;

    if ($headerinclude === '' && isset($templates)) {
        eval('$headerinclude = "' . $templates->get('headerinclude') . '";');
    }

    if ($header === '' && isset($templates)) {
        eval('$header = "' . $templates->get('header') . '";');
    }

    if ($footer === '' && isset($templates)) {
        eval('$footer = "' . $templates->get('footer') . '";');
    }
}

function af_aa_escape_attr_json(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        $json = '{}';
    }

    return htmlspecialchars_uni($json);
}

function af_aa_render_tabs_html(string $baseFile, string $currentDo): string
{
    $tabs = af_aa_get_front_tabs();
    $currentDo = af_aa_resolve_preset_do($currentDo);

    $html = '<div class="af-aa-tabs" role="tablist">';

    foreach ($tabs as $do => $label) {
        $isActive = $do === $currentDo;
        $class = 'af-aa-tab' . ($isActive ? ' is-active' : '');
        $url = $baseFile . '?do=' . rawurlencode($do);

        $html .= '<a class="' . $class . '" href="' . htmlspecialchars_uni($url) . '">' . htmlspecialchars_uni($label) . '</a>';
    }

    $html .= '</div>';

    return $html;
}

function af_aa_front_field(string $label, string $inputHtml, string $hint = ''): string
{
    $html = '<label class="af-aa-field">';
    $html .= '<span class="af-aa-field__label">' . htmlspecialchars_uni($label) . '</span>';
    $html .= $inputHtml;

    if ($hint !== '') {
        $html .= '<span class="af-aa-field__hint">' . htmlspecialchars_uni($hint) . '</span>';
    }

    $html .= '</label>';

    return $html;
}

function af_aa_front_input(string $label, string $name, string $value, array $attrs = [], string $hint = ''): string
{
    $type = (string)($attrs['type'] ?? 'text');
    unset($attrs['type']);

    $attrHtml = '';
    foreach ($attrs as $attrName => $attrValue) {
        if (is_bool($attrValue)) {
            if ($attrValue) {
                $attrHtml .= ' ' . htmlspecialchars_uni((string)$attrName);
            }
            continue;
        }

        $attrHtml .= ' ' . htmlspecialchars_uni((string)$attrName) . '="' . htmlspecialchars_uni((string)$attrValue) . '"';
    }

    $input = '<input class="af-aa-input" type="' . htmlspecialchars_uni($type) . '"'
        . ' name="' . htmlspecialchars_uni($name) . '"'
        . ' value="' . htmlspecialchars_uni($value) . '"'
        . $attrHtml
        . '>';

    return af_aa_front_field($label, $input, $hint);
}

function af_aa_front_textarea(string $label, string $name, string $value, array $attrs = [], string $hint = ''): string
{
    $rows = (int)($attrs['rows'] ?? 4);
    unset($attrs['rows']);

    $attrHtml = '';
    foreach ($attrs as $attrName => $attrValue) {
        if (is_bool($attrValue)) {
            if ($attrValue) {
                $attrHtml .= ' ' . htmlspecialchars_uni((string)$attrName);
            }
            continue;
        }

        $attrHtml .= ' ' . htmlspecialchars_uni((string)$attrName) . '="' . htmlspecialchars_uni((string)$attrValue) . '"';
    }

    $input = '<textarea class="af-aa-input af-aa-input--textarea"'
        . ' name="' . htmlspecialchars_uni($name) . '"'
        . ' rows="' . $rows . '"'
        . $attrHtml
        . '>' . htmlspecialchars_uni($value) . '</textarea>';

    return af_aa_front_field($label, $input, $hint);
}

function af_aa_front_select(string $label, string $name, string $selectedValue, array $options, array $attrs = [], string $hint = ''): string
{
    $attrHtml = '';
    foreach ($attrs as $attrName => $attrValue) {
        if (is_bool($attrValue)) {
            if ($attrValue) {
                $attrHtml .= ' ' . htmlspecialchars_uni((string)$attrName);
            }
            continue;
        }

        $attrHtml .= ' ' . htmlspecialchars_uni((string)$attrName) . '="' . htmlspecialchars_uni((string)$attrValue) . '"';
    }

    $input = '<select class="af-aa-input af-aa-input--select" name="' . htmlspecialchars_uni($name) . '"' . $attrHtml . '>';
    foreach ($options as $value => $labelText) {
        $input .= '<option value="' . htmlspecialchars_uni((string)$value) . '"'
            . ((string)$value === $selectedValue ? ' selected' : '')
            . '>' . htmlspecialchars_uni((string)$labelText) . '</option>';
    }
    $input .= '</select>';

    return af_aa_front_field($label, $input, $hint);
}

function af_aa_build_profile_fields_html(array $settings, bool $includeCustomCss = false): string
{
    $html = '<section class="af-aa-panel af-aa-form-section">';
    $html .= '<h3 class="af-aa-panel__title">Профиль</h3>';
    $html .= '<div class="af-aa-form-grid">';

    $html .= af_aa_front_input('Cover URL', 'settings[member_profile_body_cover_url]', (string)$settings['member_profile_body_cover_url'], [
        'data-aa-setting' => 'member_profile_body_cover_url',
    ]);

    $html .= af_aa_front_input('Tile URL', 'settings[member_profile_body_tile_url]', (string)$settings['member_profile_body_tile_url'], [
        'data-aa-setting' => 'member_profile_body_tile_url',
    ]);

    $html .= af_aa_front_select('Body mode', 'settings[member_profile_body_bg_mode]', (string)$settings['member_profile_body_bg_mode'], [
        'cover' => 'cover',
        'tile' => 'tile',
    ], [
        'data-aa-setting' => 'member_profile_body_bg_mode',
    ]);

    $html .= af_aa_front_input('Body overlay', 'settings[member_profile_body_overlay]', (string)$settings['member_profile_body_overlay'], [
        'data-aa-setting' => 'member_profile_body_overlay',
    ], 'Например: linear-gradient(180deg, rgba(0,0,0,.10), rgba(0,0,0,.65))');

    $html .= af_aa_front_input('Banner URL', 'settings[profile_banner_url]', (string)$settings['profile_banner_url'], [
        'data-aa-setting' => 'profile_banner_url',
    ]);

    $html .= af_aa_front_input('Banner overlay', 'settings[profile_banner_overlay]', (string)$settings['profile_banner_overlay'], [
        'data-aa-setting' => 'profile_banner_overlay',
    ]);

    if ($includeCustomCss) {
        $html .= af_aa_front_textarea('Custom CSS', 'settings[custom_css]', (string)$settings['custom_css'], [
            'rows' => 10,
            'data-aa-setting' => 'custom_css',
        ], 'Поддерживаются плейсхолдеры {{selector}} и {{body_selector}}.');
    }

    $html .= '</div>';
    $html .= '</section>';

    return $html;
}

function af_aa_build_thread_fields_html(array $settings, bool $includeCustomCss = false): string
{
    $html = '<section class="af-aa-panel af-aa-form-section">';
    $html .= '<h3 class="af-aa-panel__title">Страница темы</h3>';
    $html .= '<div class="af-aa-form-grid">';

    $html .= af_aa_front_input('Cover URL', 'settings[thread_body_cover_url]', (string)$settings['thread_body_cover_url'], [
        'data-aa-setting' => 'thread_body_cover_url',
    ]);
    $html .= af_aa_front_input('Tile URL', 'settings[thread_body_tile_url]', (string)$settings['thread_body_tile_url'], [
        'data-aa-setting' => 'thread_body_tile_url',
    ]);
    $html .= af_aa_front_select('Body mode', 'settings[thread_body_bg_mode]', (string)$settings['thread_body_bg_mode'], [
        'cover' => 'cover',
        'tile' => 'tile',
    ], [
        'data-aa-setting' => 'thread_body_bg_mode',
    ]);
    $html .= af_aa_front_input('Body overlay', 'settings[thread_body_overlay]', (string)$settings['thread_body_overlay'], [
        'data-aa-setting' => 'thread_body_overlay',
    ]);
    $html .= af_aa_front_input('Banner URL', 'settings[thread_banner_url]', (string)$settings['thread_banner_url'], [
        'data-aa-setting' => 'thread_banner_url',
    ]);
    $html .= af_aa_front_input('Banner overlay', 'settings[thread_banner_overlay]', (string)$settings['thread_banner_overlay'], [
        'data-aa-setting' => 'thread_banner_overlay',
    ]);

    if ($includeCustomCss) {
        $html .= af_aa_front_textarea('Custom CSS', 'settings[custom_css]', (string)$settings['custom_css'], [
            'rows' => 10,
            'data-aa-setting' => 'custom_css',
        ], 'Поддерживаются плейсхолдеры {{selector}} и {{body_selector}}.');
    }

    $html .= '</div>';
    $html .= '</section>';

    return $html;
}

function af_aa_build_postbit_fields_html(array $settings, bool $includeCustomCss = false): string
{
    $html = '<section class="af-aa-panel af-aa-form-section">';
    $html .= '<h3 class="af-aa-panel__title">Постбит</h3>';
    $html .= '<div class="af-aa-form-grid">';

    $html .= af_aa_front_input('Author background URL', 'settings[postbit_author_bg_url]', (string)$settings['postbit_author_bg_url'], [
        'data-aa-setting' => 'postbit_author_bg_url',
    ]);

    $html .= af_aa_front_input('Author overlay', 'settings[postbit_author_overlay]', (string)$settings['postbit_author_overlay'], [
        'data-aa-setting' => 'postbit_author_overlay',
    ]);

    $html .= af_aa_front_input('Name background URL', 'settings[postbit_name_bg_url]', (string)$settings['postbit_name_bg_url'], [
        'data-aa-setting' => 'postbit_name_bg_url',
    ]);

    $html .= af_aa_front_input('Name overlay', 'settings[postbit_name_overlay]', (string)$settings['postbit_name_overlay'], [
        'data-aa-setting' => 'postbit_name_overlay',
    ]);

    $html .= af_aa_front_input('Plaque background URL', 'settings[postbit_plaque_bg_url]', (string)$settings['postbit_plaque_bg_url'], [
        'data-aa-setting' => 'postbit_plaque_bg_url',
    ]);

    $html .= af_aa_front_input('Plaque overlay', 'settings[postbit_plaque_overlay]', (string)$settings['postbit_plaque_overlay'], [
        'data-aa-setting' => 'postbit_plaque_overlay',
    ]);
    $html .= af_aa_front_input('Plaque media image URL', 'settings[postbit_plaque_media_image_url]', (string)$settings['postbit_plaque_media_image_url'], [
        'data-aa-setting' => 'postbit_plaque_media_image_url',
    ]);
    $html .= af_aa_front_input('Plaque media icon class', 'settings[postbit_plaque_media_icon_class]', (string)$settings['postbit_plaque_media_icon_class'], [
        'data-aa-setting' => 'postbit_plaque_media_icon_class',
    ]);
    $html .= af_aa_front_input('Plaque media overlay', 'settings[postbit_plaque_media_overlay]', (string)$settings['postbit_plaque_media_overlay'], [
        'data-aa-setting' => 'postbit_plaque_media_overlay',
    ]);
    $html .= af_aa_front_input('Plaque media CSS', 'settings[postbit_plaque_media_css]', (string)$settings['postbit_plaque_media_css'], [
        'data-aa-setting' => 'postbit_plaque_media_css',
    ]);
    $html .= af_aa_front_input('Plaque title', 'settings[postbit_plaque_title]', (string)$settings['postbit_plaque_title'], [
        'data-aa-setting' => 'postbit_plaque_title',
    ]);
    $html .= af_aa_front_input('Plaque subtitle', 'settings[postbit_plaque_subtitle]', (string)$settings['postbit_plaque_subtitle'], [
        'data-aa-setting' => 'postbit_plaque_subtitle',
    ]);
    $html .= af_aa_front_input('Plaque default title', 'settings[postbit_plaque_title_default]', (string)$settings['postbit_plaque_title_default'], [
        'data-aa-setting' => 'postbit_plaque_title_default',
    ]);
    $html .= af_aa_front_input('Plaque default subtitle', 'settings[postbit_plaque_subtitle_default]', (string)$settings['postbit_plaque_subtitle_default'], [
        'data-aa-setting' => 'postbit_plaque_subtitle_default',
    ]);
    $html .= af_aa_front_input('Plaque icon URL (legacy fallback)', 'settings[postbit_plaque_icon_url]', (string)$settings['postbit_plaque_icon_url'], [
        'data-aa-setting' => 'postbit_plaque_icon_url',
    ]);
    $html .= af_aa_front_input('Plaque icon glyph', 'settings[postbit_plaque_icon_glyph]', (string)$settings['postbit_plaque_icon_glyph'], [
        'data-aa-setting' => 'postbit_plaque_icon_glyph',
    ]);
    $html .= af_aa_front_input('Plaque icon background', 'settings[postbit_plaque_icon_bg]', (string)$settings['postbit_plaque_icon_bg'], [
        'data-aa-setting' => 'postbit_plaque_icon_bg',
    ]);
    $html .= af_aa_front_input('Plaque icon overlay', 'settings[postbit_plaque_icon_overlay]', (string)$settings['postbit_plaque_icon_overlay'], [
        'data-aa-setting' => 'postbit_plaque_icon_overlay',
    ]);
    $html .= af_aa_front_input('Plaque icon border', 'settings[postbit_plaque_icon_border]', (string)$settings['postbit_plaque_icon_border'], [
        'data-aa-setting' => 'postbit_plaque_icon_border',
    ]);
    $html .= af_aa_front_input('Plaque icon color', 'settings[postbit_plaque_icon_color]', (string)$settings['postbit_plaque_icon_color'], [
        'data-aa-setting' => 'postbit_plaque_icon_color',
    ]);
    $html .= af_aa_front_input('Plaque icon size', 'settings[postbit_plaque_icon_size]', (string)$settings['postbit_plaque_icon_size'], [
        'data-aa-setting' => 'postbit_plaque_icon_size',
    ]);

    if ($includeCustomCss) {
        $html .= af_aa_front_textarea('Custom CSS', 'settings[custom_css]', (string)$settings['custom_css'], [
            'rows' => 10,
            'data-aa-setting' => 'custom_css',
        ], 'Этот CSS должен менять только макет постбита.');
    }

    $html .= '</div>';
    $html .= '</section>';

    return $html;
}

function af_aa_build_fragment_fields_html(array $settings): string
{
    $fragmentOptions = af_aa_get_supported_fragment_keys();
    $fragmentKey = (string)($settings['fragment_key'] ?? 'profile_banner');

    if (!isset($fragmentOptions[$fragmentKey])) {
        $fragmentKey = 'profile_banner';
    }

    $html = '<section class="af-aa-panel af-aa-form-section">';
    $html .= '<h3 class="af-aa-panel__title">Разное / дробный пак</h3>';
    $html .= '<div class="af-aa-form-grid">';

    $html .= af_aa_front_select('Участок', 'settings[fragment_key]', $fragmentKey, $fragmentOptions, [
        'data-aa-setting' => 'fragment_key',
    ], 'Для preview меняется только выбранный участок + custom CSS.');

    $html .= af_aa_front_input('Cover URL', 'settings[member_profile_body_cover_url]', (string)$settings['member_profile_body_cover_url'], [
        'data-aa-setting' => 'member_profile_body_cover_url',
    ]);

    $html .= af_aa_front_input('Tile URL', 'settings[member_profile_body_tile_url]', (string)$settings['member_profile_body_tile_url'], [
        'data-aa-setting' => 'member_profile_body_tile_url',
    ]);

    $html .= af_aa_front_select('Body mode', 'settings[member_profile_body_bg_mode]', (string)$settings['member_profile_body_bg_mode'], [
        'cover' => 'cover',
        'tile' => 'tile',
    ], [
        'data-aa-setting' => 'member_profile_body_bg_mode',
    ]);

    $html .= af_aa_front_input('Body overlay', 'settings[member_profile_body_overlay]', (string)$settings['member_profile_body_overlay'], [
        'data-aa-setting' => 'member_profile_body_overlay',
    ]);

    $html .= af_aa_front_input('Banner URL', 'settings[profile_banner_url]', (string)$settings['profile_banner_url'], [
        'data-aa-setting' => 'profile_banner_url',
    ]);

    $html .= af_aa_front_input('Banner overlay', 'settings[profile_banner_overlay]', (string)$settings['profile_banner_overlay'], [
        'data-aa-setting' => 'profile_banner_overlay',
    ]);
    $html .= af_aa_front_input('Thread cover URL', 'settings[thread_body_cover_url]', (string)$settings['thread_body_cover_url'], [
        'data-aa-setting' => 'thread_body_cover_url',
    ]);
    $html .= af_aa_front_input('Thread tile URL', 'settings[thread_body_tile_url]', (string)$settings['thread_body_tile_url'], [
        'data-aa-setting' => 'thread_body_tile_url',
    ]);
    $html .= af_aa_front_select('Thread body mode', 'settings[thread_body_bg_mode]', (string)$settings['thread_body_bg_mode'], [
        'cover' => 'cover',
        'tile' => 'tile',
    ], [
        'data-aa-setting' => 'thread_body_bg_mode',
    ]);
    $html .= af_aa_front_input('Thread body overlay', 'settings[thread_body_overlay]', (string)$settings['thread_body_overlay'], [
        'data-aa-setting' => 'thread_body_overlay',
    ]);
    $html .= af_aa_front_input('Thread banner URL', 'settings[thread_banner_url]', (string)$settings['thread_banner_url'], [
        'data-aa-setting' => 'thread_banner_url',
    ]);
    $html .= af_aa_front_input('Thread banner overlay', 'settings[thread_banner_overlay]', (string)$settings['thread_banner_overlay'], [
        'data-aa-setting' => 'thread_banner_overlay',
    ]);

    $html .= af_aa_front_input('Author background URL', 'settings[postbit_author_bg_url]', (string)$settings['postbit_author_bg_url'], [
        'data-aa-setting' => 'postbit_author_bg_url',
    ]);

    $html .= af_aa_front_input('Author overlay', 'settings[postbit_author_overlay]', (string)$settings['postbit_author_overlay'], [
        'data-aa-setting' => 'postbit_author_overlay',
    ]);

    $html .= af_aa_front_input('Name background URL', 'settings[postbit_name_bg_url]', (string)$settings['postbit_name_bg_url'], [
        'data-aa-setting' => 'postbit_name_bg_url',
    ]);

    $html .= af_aa_front_input('Name overlay', 'settings[postbit_name_overlay]', (string)$settings['postbit_name_overlay'], [
        'data-aa-setting' => 'postbit_name_overlay',
    ]);

    $html .= af_aa_front_input('Plaque background URL', 'settings[postbit_plaque_bg_url]', (string)$settings['postbit_plaque_bg_url'], [
        'data-aa-setting' => 'postbit_plaque_bg_url',
    ]);

    $html .= af_aa_front_input('Plaque overlay', 'settings[postbit_plaque_overlay]', (string)$settings['postbit_plaque_overlay'], [
        'data-aa-setting' => 'postbit_plaque_overlay',
    ]);
    $html .= af_aa_front_input('Plaque media image URL', 'settings[postbit_plaque_media_image_url]', (string)$settings['postbit_plaque_media_image_url'], [
        'data-aa-setting' => 'postbit_plaque_media_image_url',
    ]);
    $html .= af_aa_front_input('Plaque media icon class', 'settings[postbit_plaque_media_icon_class]', (string)$settings['postbit_plaque_media_icon_class'], [
        'data-aa-setting' => 'postbit_plaque_media_icon_class',
    ]);
    $html .= af_aa_front_input('Plaque media overlay', 'settings[postbit_plaque_media_overlay]', (string)$settings['postbit_plaque_media_overlay'], [
        'data-aa-setting' => 'postbit_plaque_media_overlay',
    ]);
    $html .= af_aa_front_input('Plaque media CSS', 'settings[postbit_plaque_media_css]', (string)$settings['postbit_plaque_media_css'], [
        'data-aa-setting' => 'postbit_plaque_media_css',
    ]);
    $html .= af_aa_front_input('Plaque title', 'settings[postbit_plaque_title]', (string)$settings['postbit_plaque_title'], [
        'data-aa-setting' => 'postbit_plaque_title',
    ]);
    $html .= af_aa_front_input('Plaque subtitle', 'settings[postbit_plaque_subtitle]', (string)$settings['postbit_plaque_subtitle'], [
        'data-aa-setting' => 'postbit_plaque_subtitle',
    ]);
    $html .= af_aa_front_input('Plaque default title', 'settings[postbit_plaque_title_default]', (string)$settings['postbit_plaque_title_default'], [
        'data-aa-setting' => 'postbit_plaque_title_default',
    ]);
    $html .= af_aa_front_input('Plaque default subtitle', 'settings[postbit_plaque_subtitle_default]', (string)$settings['postbit_plaque_subtitle_default'], [
        'data-aa-setting' => 'postbit_plaque_subtitle_default',
    ]);
    $html .= af_aa_front_input('Plaque icon URL (legacy fallback)', 'settings[postbit_plaque_icon_url]', (string)$settings['postbit_plaque_icon_url'], [
        'data-aa-setting' => 'postbit_plaque_icon_url',
    ]);
    $html .= af_aa_front_input('Plaque icon glyph', 'settings[postbit_plaque_icon_glyph]', (string)$settings['postbit_plaque_icon_glyph'], [
        'data-aa-setting' => 'postbit_plaque_icon_glyph',
    ]);
    $html .= af_aa_front_input('Plaque icon background', 'settings[postbit_plaque_icon_bg]', (string)$settings['postbit_plaque_icon_bg'], [
        'data-aa-setting' => 'postbit_plaque_icon_bg',
    ]);
    $html .= af_aa_front_input('Plaque icon overlay', 'settings[postbit_plaque_icon_overlay]', (string)$settings['postbit_plaque_icon_overlay'], [
        'data-aa-setting' => 'postbit_plaque_icon_overlay',
    ]);
    $html .= af_aa_front_input('Plaque icon border', 'settings[postbit_plaque_icon_border]', (string)$settings['postbit_plaque_icon_border'], [
        'data-aa-setting' => 'postbit_plaque_icon_border',
    ]);
    $html .= af_aa_front_input('Plaque icon color', 'settings[postbit_plaque_icon_color]', (string)$settings['postbit_plaque_icon_color'], [
        'data-aa-setting' => 'postbit_plaque_icon_color',
    ]);
    $html .= af_aa_front_input('Plaque icon size', 'settings[postbit_plaque_icon_size]', (string)$settings['postbit_plaque_icon_size'], [
        'data-aa-setting' => 'postbit_plaque_icon_size',
    ]);

    $html .= af_aa_front_textarea('Custom CSS', 'settings[custom_css]', (string)$settings['custom_css'], [
        'rows' => 10,
        'data-aa-setting' => 'custom_css',
    ], 'Для точечной кастомизации. Можно использовать CSS из ACP-примеров.');

    $html .= '</div>';
    $html .= '</section>';

    return $html;
}

function af_aa_build_surface_fields_html(array $settingsOrSurfaceMap, array $settings = [], bool $includeCustomCss = false): string
{
    $surfaceMap = [
        'sheet' => 'Лист персонажа',
        'application' => 'Анкета',
        'inventory' => 'Инвентарь',
        'achievements' => 'Ачивки',
        'thread' => 'Страница темы',
    ];

    $looksLikeSurfaceMap = true;
    foreach ($settingsOrSurfaceMap as $surfaceKey => $surfaceLabel) {
        if (!in_array((string)$surfaceKey, ['sheet', 'application', 'inventory', 'achievements', 'thread'], true) || !is_string($surfaceLabel)) {
            $looksLikeSurfaceMap = false;
            break;
        }
    }

    if ($looksLikeSurfaceMap && !empty($settingsOrSurfaceMap)) {
        $surfaceMap = $settingsOrSurfaceMap;
    } else {
        $settings = $settingsOrSurfaceMap;
    }

    $html = '';

    foreach ($surfaceMap as $surfaceKey => $surfaceLabel) {
        $html .= '<section class="af-aa-panel af-aa-form-section">';
        $html .= '<h3 class="af-aa-panel__title">' . htmlspecialchars_uni($surfaceLabel) . '</h3>';
        $html .= '<div class="af-aa-form-grid">';
        $html .= af_aa_front_input('Background URL', 'settings[' . $surfaceKey . '_bg_url]', (string)($settings[$surfaceKey . '_bg_url'] ?? ''), [
            'data-aa-setting' => $surfaceKey . '_bg_url',
        ]);
        $html .= af_aa_front_input('Background overlay', 'settings[' . $surfaceKey . '_bg_overlay]', (string)($settings[$surfaceKey . '_bg_overlay'] ?? ''), [
            'data-aa-setting' => $surfaceKey . '_bg_overlay',
        ]);
        $html .= af_aa_front_input('Panel background', 'settings[' . $surfaceKey . '_panel_bg]', (string)($settings[$surfaceKey . '_panel_bg'] ?? ''), [
            'data-aa-setting' => $surfaceKey . '_panel_bg',
        ]);
        $html .= af_aa_front_input('Panel border', 'settings[' . $surfaceKey . '_panel_border]', (string)($settings[$surfaceKey . '_panel_border'] ?? ''), [
            'data-aa-setting' => $surfaceKey . '_panel_border',
        ]);
        if ($includeCustomCss) {
            $html .= af_aa_front_textarea('Custom CSS', 'settings[custom_css]', (string)($settings['custom_css'] ?? ''), [
                'rows' => 10,
                'data-aa-setting' => 'custom_css',
            ], 'Этот CSS должен менять только выбранную поверхность.');
        }
        $html .= '</div>';
        $html .= '</section>';
    }

    return $html;
}

function af_aa_build_studio_form_html(string $do, array $preset, array $settings): string
{
    global $mybb;

    $do = af_aa_resolve_preset_do($do);
    $id = (int)($preset['id'] ?? 0);
    $targetKey = af_aa_target_key_for_do($do);

    $titleMap = [
        'themepack' => 'Конструктор: общий пак темы',
        'profilepack' => 'Конструктор: пак профиля',
        'postbitpack' => 'Конструктор: пак постбита',
        'threadpack' => 'Конструктор: пак страницы темы',
        'applicationpack' => 'Конструктор: пак анкеты',
        'sheetpack' => 'Конструктор: пак листа персонажа',
        'inventorypack' => 'Конструктор: пак инвентаря',
        'achievementspack' => 'Конструктор: пак ачивок',
        'fragmentpack' => 'Конструктор: дробный пак',
    ];

    $html = '<form class="af-aa-panel af-aa-form" method="post" action="apstudio.php?do=' . rawurlencode($do) . '" data-aa-form>';
    $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
    $html .= '<input type="hidden" name="action" value="save">';
    $html .= '<input type="hidden" name="id" value="' . $id . '">';

    $html .= '<div class="af-aa-panel__head">';
    $html .= '<div>';
    $html .= '<h2 class="af-aa-panel__title">' . htmlspecialchars_uni($titleMap[$do] ?? 'Конструктор') . '</h2>';
    $html .= '<p class="af-aa-panel__desc">Фронтовое создание и редактирование пресетов без ACP.</p>';
    $html .= '</div>';
    if ($id > 0) {
        $html .= '<span class="af-aa-chip">Редактирование #' . $id . '</span>';
    }
    $html .= '</div>';

    $html .= '<section class="af-aa-panel af-aa-form-section">';
    $html .= '<h3 class="af-aa-panel__title">Общие данные</h3>';
    $html .= '<div class="af-aa-form-grid">';
    $html .= af_aa_front_input('Slug', 'slug', (string)($preset['slug'] ?? ''));
    $html .= af_aa_front_input('Название', 'title', (string)($preset['title'] ?? ''));
    $html .= af_aa_front_textarea('Описание', 'description', (string)($preset['description'] ?? ''), ['rows' => 4]);
    $html .= af_aa_front_input('Preview image', 'preview_image', (string)($preset['preview_image'] ?? ''), [], 'Это картинка карточки пресета в каталоге.');
    $html .= af_aa_front_input('Target key', 'target_key', $targetKey, ['readonly' => true]);
    $html .= af_aa_front_input('Sort order', 'sortorder', (string)($preset['sortorder'] ?? '0'), ['type' => 'number']);
    $html .= '</div>';
    $html .= '</section>';

    switch ($do) {
        case 'profilepack':
            $html .= af_aa_build_profile_fields_html($settings, true);
            break;

        case 'postbitpack':
            $html .= af_aa_build_postbit_fields_html($settings, true);
            break;

        case 'threadpack':
            $html .= af_aa_build_thread_fields_html($settings, true);
            break;

        case 'applicationpack':
            $html .= af_aa_build_surface_fields_html(['application' => 'Анкета'], $settings, true);
            break;

        case 'sheetpack':
            $html .= af_aa_build_surface_fields_html(['sheet' => 'Лист персонажа'], $settings, true);
            break;

        case 'inventorypack':
            $html .= af_aa_build_surface_fields_html(['inventory' => 'Инвентарь'], $settings, true);
            break;

        case 'achievementspack':
            $html .= af_aa_build_surface_fields_html(['achievements' => 'Ачивки'], $settings, true);
            break;

        case 'fragmentpack':
            $html .= af_aa_build_fragment_fields_html($settings);
            break;

        case 'themepack':
        default:
            $html .= af_aa_build_profile_fields_html($settings, false);
            $html .= af_aa_build_thread_fields_html($settings, false);
            $html .= af_aa_build_postbit_fields_html($settings, false);
            $html .= af_aa_build_surface_fields_html($settings);
            $html .= '<section class="af-aa-panel af-aa-form-section">';
            $html .= '<h3 class="af-aa-panel__title">Пользовательский CSS</h3>';
            $html .= '<div class="af-aa-form-grid">';
            $html .= af_aa_front_textarea('Custom CSS', 'settings[custom_css]', (string)$settings['custom_css'], [
                'rows' => 12,
                'data-aa-setting' => 'custom_css',
            ], 'Поддерживаются {{selector}} и {{body_selector}}.');
            $html .= '</div>';
            $html .= '</section>';
            break;
    }

    $html .= '<div class="af-aa-actions">';
    $html .= '<button class="button" type="submit">Сохранить пресет</button>';
    $html .= '<button class="button" type="button" data-aa-preview-from-form>Открыть превью</button>';
    $html .= '<a class="button" href="apstudio.php?do=' . rawurlencode($do) . '">Создать новый</a>';
    $html .= '</div>';
    $html .= '</form>';

    return $html;
}

function af_aa_build_preset_card_html(array $row, string $do, bool $studioMode = false): string
{
    global $mybb;

    $settings = af_aa_front_settings_from_row($row);
    $targetLabel = af_aa_human_target_label((string)$row['target_key'], $settings);
    $enabled = (int)($row['enabled'] ?? 0) === 1;
    $id = (int)($row['id'] ?? 0);
    $title = (string)($row['title'] ?? '');
    $description = trim((string)($row['description'] ?? ''));
    $previewImage = trim((string)($row['preview_image'] ?? ''));
    $settingsJson = af_aa_escape_attr_json($settings);

    $imageHtml = '<div class="af-aa-card__image af-aa-card__image--empty">Нет preview</div>';
    if ($previewImage !== '') {
        $imageHtml = '<div class="af-aa-card__image" style="background-image:url(\'' . htmlspecialchars_uni($previewImage) . '\');"></div>';
    }

    $html = '<article class="af-aa-card"'
        . ' data-aa-card'
        . ' data-aa-settings="' . $settingsJson . '"'
        . ' data-aa-title="' . htmlspecialchars_uni($title) . '"'
        . ' data-aa-description="' . htmlspecialchars_uni($description !== '' ? $description : $targetLabel) . '">';

    $html .= $imageHtml;
    $html .= '<div class="af-aa-card__body">';
    $html .= '<div class="af-aa-card__meta">';
    $html .= '<span class="af-aa-chip">' . htmlspecialchars_uni($targetLabel) . '</span>';
    if ($studioMode) {
        $html .= '<span class="af-aa-chip ' . ($enabled ? 'is-success' : 'is-muted') . '">' . ($enabled ? 'Вкл' : 'Выкл') . '</span>';
        $html .= '<span class="af-aa-chip">sort: ' . (int)($row['sortorder'] ?? 0) . '</span>';
    }
    $html .= '</div>';

    $html .= '<h3 class="af-aa-card__title">' . htmlspecialchars_uni($title !== '' ? $title : ('Preset #' . $id)) . '</h3>';
    $html .= '<p class="af-aa-card__desc">' . htmlspecialchars_uni($description !== '' ? $description : 'Без описания') . '</p>';

    $html .= '<div class="af-aa-card__actions">';
    $html .= '<button class="button button_small" type="button" data-aa-preview-from-card>Показать в превью</button>';

    if ($studioMode) {
        $html .= '<a class="button button_small" href="apstudio.php?do=' . rawurlencode($do) . '&edit=' . $id . '">Редактировать</a>';

        $html .= '<form method="post" action="apstudio.php?do=' . rawurlencode($do) . '" class="af-aa-inline-form">';
        $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        $html .= '<input type="hidden" name="action" value="toggle">';
        $html .= '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '">';
        $html .= '<button class="button button_small" type="submit">' . ($enabled ? 'Выключить' : 'Включить') . '</button>';
        $html .= '</form>';

        $html .= '<form method="post" action="apstudio.php?do=' . rawurlencode($do) . '" class="af-aa-inline-form" onsubmit="return confirm(\'Удалить пресет?\');">';
        $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        $html .= '<input type="hidden" name="action" value="delete">';
        $html .= '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<button class="button button_small" type="submit">Удалить</button>';
        $html .= '</form>';
    }

    $html .= '</div>';
    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

function af_aa_build_cards_grid_html(array $rows, string $do, bool $studioMode = false): string
{
    if (empty($rows)) {
        return '<div class="af-aa-panel"><div class="af-aa-empty">Пока тут нет пресетов в этой вкладке.</div></div>';
    }

    $html = '<div class="af-aa-card-grid">';
    foreach ($rows as $row) {
        $html .= af_aa_build_preset_card_html($row, $do, $studioMode);
    }
    $html .= '</div>';

    return $html;
}

function af_aa_pick_initial_preset(array $rows, int $preferredId = 0): array
{
    if ($preferredId > 0) {
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) === $preferredId) {
                return $row;
            }
        }
    }

    if (!empty($rows)) {
        return $rows[0];
    }

    return [];
}

function af_aa_render_template_output(string $templateName, array $vars): string
{
    // Подтягиваем в scope шаблона нужные глобалы MyBB,
    // чтобы {$headerinclude}, {$header}, {$footer}, {$htmloption} и т.д. реально работали.
    $globalsToImport = [
        'mybb',
        'lang',
        'theme',
        'templates',
        'headerinclude',
        'header',
        'footer',
        'htmloption',
        'charset',
        'stylesheets',
        'plugins'
    ];

    foreach ($globalsToImport as $globalName) {
        if (array_key_exists($globalName, $GLOBALS)) {
            ${$globalName} = $GLOBALS[$globalName];
        }
    }

    extract($vars, EXTR_SKIP);

    $template = af_aa_get_template_source($templateName);
    $out = '';
    eval('$out = "' . $template . '";');

    return (string)$out;
}

function af_aa_preview_abs_url(string $url): string
{
    global $mybb;

    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    if (strpos($url, '//') === 0) {
        return '';
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');

    return $bburl . '/' . ltrim($url, '/');
}

function af_aa_preview_initials(string $username): string
{
    $username = trim((string)(preg_replace('~\s+~u', ' ', $username) ?? $username));
    if ($username === '') {
        return 'U';
    }

    $parts = preg_split('~[\s\-_]+~u', $username) ?: [$username];
    $letters = [];

    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }

        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            $letters[] = mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
        } else {
            $letters[] = strtoupper(substr($part, 0, 1));
        }

        if (count($letters) >= 2) {
            break;
        }
    }

    if (empty($letters)) {
        return 'U';
    }

    return implode('', $letters);
}

function af_aa_preview_render_avatar(string $avatarUrl, string $username, bool $small = false): string
{
    if ($avatarUrl !== '') {
        return '<img src="' . htmlspecialchars_uni($avatarUrl) . '"'
            . ' alt="' . htmlspecialchars_uni($username) . '"'
            . ' class="af-aa-preview-avatar-image' . ($small ? ' af-aa-preview-avatar-image--small' : '') . '"'
            . ' style="display:block;width:100%;height:100%;object-fit:cover;">';
    }

    $class = 'af-aa-mock-avatar' . ($small ? ' af-aa-mock-avatar--small' : '');
    return '<div class="' . $class . '">' . htmlspecialchars_uni(af_aa_preview_initials($username)) . '</div>';
}

function af_aa_preview_get_group_title(int $gid): string
{
    global $db;

    if ($gid <= 0) {
        return '';
    }

    if (!isset($GLOBALS['af_aa_preview_group_title_cache']) || !is_array($GLOBALS['af_aa_preview_group_title_cache'])) {
        $GLOBALS['af_aa_preview_group_title_cache'] = [];
    }

    if (isset($GLOBALS['af_aa_preview_group_title_cache'][$gid])) {
        return (string)$GLOBALS['af_aa_preview_group_title_cache'][$gid];
    }

    $query = $db->simple_select('usergroups', 'title', "gid='" . (int)$gid . "'", ['limit' => 1]);
    $title = trim((string)$db->fetch_field($query, 'title'));

    $GLOBALS['af_aa_preview_group_title_cache'][$gid] = $title;

    return $title;
}

function af_aa_preview_format_birthday(string $birthday): string
{
    $birthday = trim($birthday);
    if ($birthday === '') {
        return '—';
    }

    $parts = array_map('intval', explode('-', $birthday));
    $day = (int)($parts[0] ?? 0);
    $month = (int)($parts[1] ?? 0);
    $year = (int)($parts[2] ?? 0);

    if ($day <= 0 || $month <= 0 || $year <= 0) {
        return '—';
    }

    try {
        $birthdayDate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $today = new DateTimeImmutable('today');
        $age = $birthdayDate->diff($today)->y;

        return sprintf('%02d.%02d.%04d • %d', $day, $month, $year, $age);
    } catch (Throwable $e) {
        return '—';
    }
}

function af_aa_build_preview_user_context(int $uid = 0): array
{
    global $mybb, $db;

    $uid = $uid > 0 ? $uid : (int)($mybb->user['uid'] ?? 0);

    $user = [];
    if ($uid > 0) {
        $query = $db->simple_select(
            'users',
            'uid,username,usergroup,displaygroup,avatar,regdate,birthday,lastactive,invisible',
            "uid='" . $uid . "'",
            ['limit' => 1]
        );
        $row = $db->fetch_array($query);
        if (is_array($row)) {
            $user = $row;
        }
    }

    if (empty($user)) {
        $user = [
            'uid' => 0,
            'username' => trim((string)($mybb->user['username'] ?? '')) ?: 'Гость',
            'usergroup' => (int)($mybb->user['usergroup'] ?? 1),
            'displaygroup' => (int)($mybb->user['displaygroup'] ?? 0),
            'avatar' => trim((string)($mybb->user['avatar'] ?? '')),
            'regdate' => (int)($mybb->user['regdate'] ?? 0),
            'birthday' => trim((string)($mybb->user['birthday'] ?? '')),
            'lastactive' => (int)($mybb->user['lastactive'] ?? 0),
            'invisible' => (int)($mybb->user['invisible'] ?? 0),
        ];
    }

    $uid = (int)($user['uid'] ?? 0);
    $username = trim((string)($user['username'] ?? ''));
    if ($username === '') {
        $username = 'Гость';
    }

    $groupId = (int)($user['displaygroup'] ?? 0);
    if ($groupId <= 0) {
        $groupId = (int)($user['usergroup'] ?? 0);
    }

    $groupTitle = af_aa_preview_get_group_title($groupId);
    if ($groupTitle === '') {
        $groupTitle = $uid > 0 ? 'Пользователь' : 'Гость';
    }

    $avatarUrl = af_aa_preview_abs_url((string)($user['avatar'] ?? ''));

    $registrationValue = (int)($user['regdate'] ?? 0) > 0
        ? my_date((string)($mybb->settings['dateformat'] ?? 'd.m.Y'), (int)$user['regdate'])
        : '—';

    $birthdayValue = af_aa_preview_format_birthday((string)($user['birthday'] ?? ''));
    $localTimeValue = my_date((string)($mybb->settings['timeformat'] ?? 'H:i'), TIME_NOW);

    $isOnline = $uid > 0
        && (int)($user['invisible'] ?? 0) !== 1
        && (int)($user['lastactive'] ?? 0) >= (TIME_NOW - 900);

    $presenceLabel = $isOnline ? 'online' : 'offline';
    $presenceDotClass = $isOnline ? 'af-apui-presence-dot--online' : 'af-apui-presence-dot--offline';

    $profileUrl = $uid > 0
        ? 'member.php?action=profile&uid=' . $uid
        : 'javascript:void(0)';

    $profileFieldsHtml =
        htmlspecialchars_uni('UID: ' . ($uid > 0 ? $uid : '—'))
        . '<br>'
        . htmlspecialchars_uni('Группа: ' . $groupTitle)
        . '<br>'
        . htmlspecialchars_uni('Статус: ' . $presenceLabel);

    $detailsHtml =
        htmlspecialchars_uni('Регистрация: ' . $registrationValue)
        . '<br>'
        . htmlspecialchars_uni('Локальное время: ' . $localTimeValue);

    return [
        'af_aa_preview_uid' => $uid,
        'af_aa_preview_uid_class' => htmlspecialchars_uni($uid > 0 ? 'af-aa-user-' . $uid : 'af-aa-user-guest'),
        'af_aa_preview_username' => htmlspecialchars_uni($username),
        'af_aa_preview_profile_url' => htmlspecialchars_uni($profileUrl),
        'af_aa_preview_group_title' => htmlspecialchars_uni($groupTitle),
        'af_aa_preview_registration_value' => htmlspecialchars_uni($registrationValue),
        'af_aa_preview_birthday_value' => htmlspecialchars_uni($birthdayValue),
        'af_aa_preview_local_time_value' => htmlspecialchars_uni($localTimeValue),
        'af_aa_preview_presence_label' => htmlspecialchars_uni($presenceLabel),
        'af_aa_preview_presence_dot_class' => htmlspecialchars_uni($presenceDotClass),
        'af_aa_preview_state_chip' => htmlspecialchars_uni($uid > 0 ? ('uid ' . $uid) : 'guest'),
        'af_aa_preview_avatar_large_html' => af_aa_preview_render_avatar($avatarUrl, $username, false),
        'af_aa_preview_avatar_small_html' => af_aa_preview_render_avatar($avatarUrl, $username, true),
        'af_aa_preview_profilefields_html' => $profileFieldsHtml,
        'af_aa_preview_details_html' => $detailsHtml,
    ];
}

function af_aa_render_apstudio_page(): void
{
    global $mybb, $headerinclude;

    if (!af_aa_is_enabled() || !af_aa_is_admin_user()) {
        error_no_permission();
    }

    af_aa_ensure_schema();
    af_aa_ensure_front_templates();

    $do = af_aa_resolve_preset_do((string)$mybb->get_input('do'));
    $baseUrl = 'apstudio.php?do=' . rawurlencode($do);

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'), true);

        $action = trim((string)$mybb->get_input('action'));

        if ($action === 'save') {
            $savedId = af_aa_save_front_preset($do);
            redirect($baseUrl . '&edit=' . $savedId, 'Пресет сохранён.');
        }

        if ($action === 'toggle') {
            af_aa_toggle_front_preset((int)$mybb->get_input('id'), (int)$mybb->get_input('enabled'));
            redirect($baseUrl, 'Статус пресета обновлён.');
        }

        if ($action === 'delete') {
            af_aa_delete_front_preset((int)$mybb->get_input('id'));
            redirect($baseUrl, 'Пресет удалён.');
        }
    }

    $editId = (int)$mybb->get_input('edit');
    $editPreset = [];
    if ($editId > 0) {
        $editPreset = af_aa_get_preset_row($editId);
        if (!empty($editPreset)) {
            $rowDo = af_aa_do_for_target((string)($editPreset['target_key'] ?? ''));
            if ($rowDo !== $do) {
                redirect('apstudio.php?do=' . rawurlencode($rowDo) . '&edit=' . $editId, 'Открыт нужный раздел для редактирования пресета.');
            }
        }
    }

    $settings = af_aa_front_settings_from_row($editPreset);
    $rows = af_aa_fetch_presets_for_do($do, false);
    $previewUser = af_aa_build_preview_user_context((int)($mybb->user['uid'] ?? 0));

    $initialPreviewTitle = !empty($editPreset)
        ? (string)($editPreset['title'] ?? 'Новый пресет')
        : 'Новый пресет';

    $initialPreviewDescription = !empty($editPreset)
        ? (string)($editPreset['description'] ?? af_aa_human_target_label(af_aa_target_key_for_do($do), $settings))
        : af_aa_human_target_label(af_aa_target_key_for_do($do), $settings);

    add_breadcrumb('Конструктор пресетов', AF_AA_ALIAS_APSTUDIO);

    af_aa_ensure_header_bits();
    $headerinclude .= af_aa_page_asset_tags();

    $templateVars = [
        'af_aa_tabs_html' => af_aa_render_tabs_html('apstudio.php', $do),
        'af_aa_form_html' => af_aa_build_studio_form_html($do, $editPreset, $settings),
        'af_aa_cards_html' => af_aa_build_cards_grid_html($rows, $do, true),
        'af_aa_preview_seed_json' => af_aa_escape_attr_json($settings),
        'af_aa_preview_title' => htmlspecialchars_uni($initialPreviewTitle),
        'af_aa_preview_description' => htmlspecialchars_uni($initialPreviewDescription),
    ];

    $page = af_aa_render_template_output(
        AF_AA_TPL_APSTUDIO,
        array_merge($templateVars, $previewUser)
    );

    output_page($page);
    exit;
}

function af_aa_render_fittingroom_page(): void
{
    global $mybb, $headerinclude;

    if (!af_aa_is_enabled()) {
        error_no_permission();
    }

    af_aa_ensure_schema();
    af_aa_ensure_front_templates();

    $do = af_aa_resolve_preset_do((string)$mybb->get_input('do'));
    $rows = af_aa_fetch_presets_for_do($do, true);
    $preferredId = (int)$mybb->get_input('preview');
    $initialPreset = af_aa_pick_initial_preset($rows, $preferredId);
    $initialSettings = !empty($initialPreset) ? af_aa_front_settings_from_row($initialPreset) : af_aa_get_front_defaults();
    $previewUser = af_aa_build_preview_user_context((int)($mybb->user['uid'] ?? 0));

    $initialTitle = !empty($initialPreset)
        ? (string)($initialPreset['title'] ?? 'Примерка')
        : 'Примерочная';

    $initialDescription = !empty($initialPreset)
        ? (string)($initialPreset['description'] ?? af_aa_human_target_label((string)$initialPreset['target_key'], $initialSettings))
        : 'Здесь можно посмотреть готовые пресеты и примерить их на своём профиле и своём постбите.';

    add_breadcrumb('Примерочная', AF_AA_ALIAS_FITTINGROOM);

    af_aa_ensure_header_bits();
    $headerinclude .= af_aa_page_asset_tags();

    $templateVars = [
        'af_aa_tabs_html' => af_aa_render_tabs_html('fittingroom.php', $do),
        'af_aa_cards_html' => af_aa_build_cards_grid_html($rows, $do, false),
        'af_aa_preview_seed_json' => af_aa_escape_attr_json($initialSettings),
        'af_aa_preview_title' => htmlspecialchars_uni($initialTitle),
        'af_aa_preview_description' => htmlspecialchars_uni($initialDescription),
    ];

    $page = af_aa_render_template_output(
        AF_AA_TPL_FITTINGROOM,
        array_merge($templateVars, $previewUser)
    );

    output_page($page);
    exit;
}
