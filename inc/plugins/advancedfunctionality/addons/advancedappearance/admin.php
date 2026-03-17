<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('No direct access');
}

if (!defined('AF_AA_ID')) {
    define('AF_AA_ID', 'advancedappearance');
}

class AF_Admin_Advancedappearance
{
    public static function dispatch(): void
    {
        global $mybb;

        if (function_exists('af_aa_ensure_schema')) {
            af_aa_ensure_schema();
        }
        if (function_exists('af_aa_ensure_settings')) {
            af_aa_ensure_settings();
        }
        if (function_exists('rebuild_settings')) {
            rebuild_settings();
        }

        $section = $mybb->get_input('section');
        if ($section !== 'assignments') {
            $section = 'presets';
        }

        if ($section === 'assignments') {
            self::handleAssignments();
            return;
        }

        self::handlePresets();
    }

    private static function baseUrl(string $section = 'presets'): string
    {
        return 'index.php?module=advancedfunctionality&af_view=' . AF_AA_ID . '&section=' . $section;
    }

    private static function renderNav(string $section): void
    {
        echo '<div style="margin-bottom:14px;">';
        echo '<a class="button" style="margin-right:8px;" href="' . htmlspecialchars_uni(self::baseUrl('presets')) . '">Каталог пресетов</a>';
        echo '<a class="button" href="' . htmlspecialchars_uni(self::baseUrl('assignments')) . '">Назначения пользователям</a>';
        echo '</div>';

        if ($section === 'presets') {
            echo '<p>Создание/редактирование каталога пресетов для target <code>apui_theme_pack</code>.</p>';
        } else {
            echo '<p>Ручные назначения пресетов пользователям (entity_type=<code>user</code>).</p>';
        }
    }

    private static function handlePresets(): void
    {
        global $db, $mybb;

        $base = self::baseUrl('presets');
        $action = $mybb->get_input('action');

        if ($mybb->request_method === 'post') {
            if (function_exists('verify_post_check')) {
                verify_post_check($mybb->get_input('my_post_key'), true);
            }

            if ($action === 'save') {
                self::savePreset();
                self::redirectWithMessage($base, 'Пресет сохранён.', 'success');
            }

            if ($action === 'delete') {
                $id = (int)$mybb->get_input('id');
                if ($id > 0) {
                    $db->delete_query(AF_AA_PRESETS_TABLE_NAME, "id='" . $id . "'");
                }
                self::redirectWithMessage($base, 'Пресет удалён.', 'success');
            }

            if ($action === 'toggle') {
                $id = (int)$mybb->get_input('id');
                $enabled = (int)$mybb->get_input('enabled');
                if ($id > 0) {
                    $db->update_query(AF_AA_PRESETS_TABLE_NAME, [
                        'enabled' => $enabled ? 1 : 0,
                        'updated_at' => TIME_NOW,
                    ], "id='" . $id . "'");
                }
                self::redirectWithMessage($base, 'Статус пресета обновлён.', 'success');
            }
        }

        $editId = (int)$mybb->get_input('edit');
        $editPreset = [];
        if ($editId > 0) {
            $query = $db->simple_select(AF_AA_PRESETS_TABLE_NAME, '*', "id='" . $editId . "'", ['limit' => 1]);
            $editPreset = (array)$db->fetch_array($query);
        }

        $settings = self::presetSettingsFromRow($editPreset);

        self::renderNav('presets');
        self::renderPresetForm($base, $editPreset, $settings);

        $query = $db->simple_select(AF_AA_PRESETS_TABLE_NAME, '*', '', ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);

        echo '<h3 style="margin-top:18px;">Список пресетов</h3>';
        echo '<table class="table table-bordered">';
        echo '<tr><th>ID</th><th>Slug</th><th>Название</th><th>Target</th><th>Вкл</th><th>Сорт.</th><th>Действия</th></tr>';

        while ($row = $db->fetch_array($query)) {
            $id = (int)$row['id'];
            $enabled = (int)$row['enabled'] === 1;

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td><code>' . htmlspecialchars_uni((string)$row['slug']) . '</code></td>';
            echo '<td>' . htmlspecialchars_uni((string)$row['title']) . '</td>';
            echo '<td><code>' . htmlspecialchars_uni((string)$row['target_key']) . '</code></td>';
            echo '<td>' . ($enabled ? 'Да' : 'Нет') . '</td>';
            echo '<td>' . (int)$row['sortorder'] . '</td>';
            echo '<td>';
            echo '<a href="' . htmlspecialchars_uni($base . '&edit=' . $id) . '">Редактировать</a> | ';

            echo '<form action="' . htmlspecialchars_uni($base . '&action=toggle&id=' . $id) . '" method="post" style="display:inline;">';
            echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            echo '<input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '">';
            echo '<button type="submit" class="button button_small">' . ($enabled ? 'Выключить' : 'Включить') . '</button>';
            echo '</form> ';

            echo '<form action="' . htmlspecialchars_uni($base . '&action=delete&id=' . $id) . '" method="post" style="display:inline;" onsubmit="return confirm(\'Удалить пресет?\');">';
            echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            echo '<button type="submit" class="button button_small">Удалить</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    private static function renderPresetForm(string $base, array $preset, array $settings): void
    {
        global $mybb;

        $id = (int)($preset['id'] ?? 0);
        $targetKey = (string)($preset['target_key'] ?? AF_AA_TARGET_APUI_THEME_PACK);
        if ($targetKey !== AF_AA_TARGET_APUI_THEME_PACK) {
            $targetKey = AF_AA_TARGET_APUI_THEME_PACK;
        }

        echo '<h3>' . ($id > 0 ? 'Редактирование пресета #' . $id : 'Создать пресет') . '</h3>';
        echo '<form action="' . htmlspecialchars_uni($base . '&action=save') . '" method="post">';
        echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';

        echo '<table class="table table-bordered">';
        self::inputRow('Slug', 'slug', (string)($preset['slug'] ?? ''));
        self::inputRow('Название', 'title', (string)($preset['title'] ?? ''));
        self::textareaRow('Описание', 'description', (string)($preset['description'] ?? ''), 3);
        self::inputRow('Preview image', 'preview_image', (string)($preset['preview_image'] ?? ''));
        self::inputRow('Target key', 'target_key', $targetKey, true);
        self::inputRow('Sort order', 'sortorder', (string)($preset['sortorder'] ?? '0'));

        self::inputRow('member_profile_body_cover_url', 'settings[member_profile_body_cover_url]', $settings['member_profile_body_cover_url']);
        self::inputRow('member_profile_body_tile_url', 'settings[member_profile_body_tile_url]', $settings['member_profile_body_tile_url']);

        echo '<tr><td><strong>member_profile_body_bg_mode</strong></td><td>';
        echo '<select name="settings[member_profile_body_bg_mode]">';
        echo '<option value="cover"' . ($settings['member_profile_body_bg_mode'] === 'cover' ? ' selected' : '') . '>cover</option>';
        echo '<option value="tile"' . ($settings['member_profile_body_bg_mode'] === 'tile' ? ' selected' : '') . '>tile</option>';
        echo '</select></td></tr>';

        self::inputRow('member_profile_body_overlay', 'settings[member_profile_body_overlay]', $settings['member_profile_body_overlay']);
        self::inputRow('profile_banner_url', 'settings[profile_banner_url]', $settings['profile_banner_url']);
        self::inputRow('profile_banner_overlay', 'settings[profile_banner_overlay]', $settings['profile_banner_overlay']);
        self::inputRow('postbit_author_bg_url', 'settings[postbit_author_bg_url]', $settings['postbit_author_bg_url']);
        self::inputRow('postbit_author_overlay', 'settings[postbit_author_overlay]', $settings['postbit_author_overlay']);
        self::inputRow('postbit_name_bg_url', 'settings[postbit_name_bg_url]', $settings['postbit_name_bg_url']);
        self::inputRow('postbit_name_overlay', 'settings[postbit_name_overlay]', $settings['postbit_name_overlay']);
        self::inputRow('postbit_plaque_bg_url', 'settings[postbit_plaque_bg_url]', $settings['postbit_plaque_bg_url']);
        self::inputRow('postbit_plaque_overlay', 'settings[postbit_plaque_overlay]', $settings['postbit_plaque_overlay']);
        echo '</table>';

        echo '<button type="submit" class="button button_yes"><span class="text">Сохранить пресет</span></button>';
        echo '</form>';
    }

    private static function savePreset(): void
    {
        global $db, $mybb;

        $id = (int)$mybb->get_input('id');

        $slugRaw = trim((string)$mybb->get_input('slug'));
        $slug = preg_replace('~[^a-z0-9_\-]+~i', '-', strtolower($slugRaw)) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'preset-' . TIME_NOW;
        }

        $targetKey = (string)$mybb->get_input('target_key');
        if ($targetKey !== AF_AA_TARGET_APUI_THEME_PACK) {
            $targetKey = AF_AA_TARGET_APUI_THEME_PACK;
        }

        $settingsInput = $mybb->get_input('settings', MyBB::INPUT_ARRAY);
        if (!is_array($settingsInput)) {
            $settingsInput = [];
        }

        $defaults = function_exists('af_aa_get_apui_defaults') ? af_aa_get_apui_defaults() : [];
        $settings = function_exists('af_aa_decode_and_sanitize_preset_settings')
            ? af_aa_decode_and_sanitize_preset_settings(json_encode($settingsInput, JSON_UNESCAPED_UNICODE), $defaults)
            : $settingsInput;

        $payload = [
            'slug' => $db->escape_string($slug),
            'title' => $db->escape_string(trim((string)$mybb->get_input('title'))),
            'description' => $db->escape_string(trim((string)$mybb->get_input('description'))),
            'preview_image' => $db->escape_string(function_exists('af_aa_sanitize_image_url') ? af_aa_sanitize_image_url((string)$mybb->get_input('preview_image'), '') : ''),
            'target_key' => $db->escape_string($targetKey),
            'settings_json' => $db->escape_string(json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'sortorder' => (int)$mybb->get_input('sortorder'),
            'updated_at' => TIME_NOW,
        ];

        if ($id > 0) {
            $db->update_query(AF_AA_PRESETS_TABLE_NAME, $payload, "id='" . $id . "'");
            return;
        }

        $payload['enabled'] = 1;
        $payload['created_at'] = TIME_NOW;

        $db->insert_query(AF_AA_PRESETS_TABLE_NAME, $payload);
    }

    private static function handleAssignments(): void
    {
        global $db, $mybb;

        $base = self::baseUrl('assignments');
        $action = $mybb->get_input('action');

        if ($mybb->request_method === 'post') {
            if (function_exists('verify_post_check')) {
                verify_post_check($mybb->get_input('my_post_key'), true);
            }

            if ($action === 'save_assignment') {
                self::saveAssignment();
                self::redirectWithMessage($base, 'Назначение сохранено.', 'success');
            }

            if ($action === 'remove_assignment') {
                $uid = (int)$mybb->get_input('uid');
                if ($uid > 0) {
                    $db->delete_query(
                        AF_AA_ASSIGNMENTS_TABLE_NAME,
                        "entity_type='user' AND entity_id='" . $uid . "' AND target_key='" . $db->escape_string(AF_AA_TARGET_APUI_THEME_PACK) . "'"
                    );
                }
                self::redirectWithMessage($base, 'Назначение снято.', 'success');
            }
        }

        self::renderNav('assignments');
        self::renderAssignmentForm($base);

        $query = $db->write_query(
            "SELECT a.id, a.entity_id, a.preset_id, a.is_enabled, u.username, p.title AS preset_title"
            . " FROM " . AF_AA_ASSIGNMENTS_TABLE . " a"
            . " LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid=a.entity_id)"
            . " LEFT JOIN " . AF_AA_PRESETS_TABLE . " p ON (p.id=a.preset_id)"
            . " WHERE a.entity_type='user' AND a.target_key='" . $db->escape_string(AF_AA_TARGET_APUI_THEME_PACK) . "'"
            . " ORDER BY a.id DESC"
        );

        echo '<h3 style="margin-top:18px;">Текущие назначения</h3>';
        echo '<table class="table table-bordered">';
        echo '<tr><th>ID</th><th>UID</th><th>Пользователь</th><th>Preset ID</th><th>Пресет</th><th>Вкл</th><th>Действия</th></tr>';

        while ($row = $db->fetch_array($query)) {
            $uid = (int)$row['entity_id'];
            echo '<tr>';
            echo '<td>' . (int)$row['id'] . '</td>';
            echo '<td>' . $uid . '</td>';
            echo '<td>' . htmlspecialchars_uni((string)($row['username'] ?? 'uid:' . $uid)) . '</td>';
            echo '<td>' . (int)$row['preset_id'] . '</td>';
            echo '<td>' . htmlspecialchars_uni((string)($row['preset_title'] ?? '—')) . '</td>';
            echo '<td>' . ((int)$row['is_enabled'] === 1 ? 'Да' : 'Нет') . '</td>';
            echo '<td>';
            echo '<form action="' . htmlspecialchars_uni($base . '&action=remove_assignment') . '" method="post" style="display:inline;" onsubmit="return confirm(\'Снять назначение?\');">';
            echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            echo '<input type="hidden" name="uid" value="' . $uid . '">';
            echo '<button type="submit" class="button button_small">Снять</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    private static function renderAssignmentForm(string $base): void
    {
        global $db, $mybb;

        $presetOptions = '<option value="0">-- выберите пресет --</option>';
        $query = $db->simple_select(AF_AA_PRESETS_TABLE_NAME, 'id,title', "target_key='" . $db->escape_string(AF_AA_TARGET_APUI_THEME_PACK) . "' AND enabled='1'", ['order_by' => 'sortorder,id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $presetOptions .= '<option value="' . (int)$row['id'] . '">' . (int)$row['id'] . ' — ' . htmlspecialchars_uni((string)$row['title']) . '</option>';
        }

        echo '<h3>Назначить пресет пользователю</h3>';
        echo '<form action="' . htmlspecialchars_uni($base . '&action=save_assignment') . '" method="post">';
        echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        echo '<table class="table table-bordered">';
        self::inputRow('UID', 'uid', '');
        self::inputRow('или Username (exact)', 'username', '');
        echo '<tr><td><strong>Preset</strong></td><td><select name="preset_id">' . $presetOptions . '</select></td></tr>';
        echo '<tr><td><strong>is_enabled</strong></td><td><select name="is_enabled"><option value="1">1</option><option value="0">0</option></select></td></tr>';
        echo '</table>';
        echo '<button type="submit" class="button button_yes"><span class="text">Сохранить назначение</span></button>';
        echo '</form>';
    }

    private static function saveAssignment(): void
    {
        global $db, $mybb;

        $uid = (int)$mybb->get_input('uid');
        if ($uid <= 0) {
            $username = trim((string)$mybb->get_input('username'));
            if ($username !== '') {
                $query = $db->simple_select('users', 'uid', "username='" . $db->escape_string($username) . "'", ['limit' => 1]);
                $uid = (int)$db->fetch_field($query, 'uid');
            }
        }

        $presetId = (int)$mybb->get_input('preset_id');
        $isEnabled = $mybb->get_input('is_enabled') === '0' ? 0 : 1;

        if ($uid <= 0 || $presetId <= 0) {
            return;
        }

        $exists = $db->simple_select(
            AF_AA_ASSIGNMENTS_TABLE_NAME,
            'id',
            "entity_type='user' AND entity_id='" . $uid . "' AND target_key='" . $db->escape_string(AF_AA_TARGET_APUI_THEME_PACK) . "'",
            ['limit' => 1]
        );
        $existingId = (int)$db->fetch_field($exists, 'id');

        $payload = [
            'preset_id' => $presetId,
            'is_enabled' => $isEnabled,
            'updated_at' => TIME_NOW,
        ];

        if ($existingId > 0) {
            $db->update_query(AF_AA_ASSIGNMENTS_TABLE_NAME, $payload, "id='" . $existingId . "'");
            return;
        }

        $payload += [
            'entity_type' => $db->escape_string('user'),
            'entity_id' => $uid,
            'target_key' => $db->escape_string(AF_AA_TARGET_APUI_THEME_PACK),
            'created_at' => TIME_NOW,
        ];

        $db->insert_query(AF_AA_ASSIGNMENTS_TABLE_NAME, $payload);
    }

    private static function presetSettingsFromRow(array $row): array
    {
        $defaults = function_exists('af_aa_get_apui_defaults') ? af_aa_get_apui_defaults() : [];
        $json = (string)($row['settings_json'] ?? '');

        if (!function_exists('af_aa_decode_and_sanitize_preset_settings')) {
            return $defaults;
        }

        return af_aa_decode_and_sanitize_preset_settings($json, $defaults);
    }

    private static function inputRow(string $label, string $name, string $value, bool $readonly = false): void
    {
        echo '<tr><td style="width:300px;"><strong>' . htmlspecialchars_uni($label) . '</strong></td><td>';
        echo '<input type="text" class="text_input" style="width:100%;" name="' . htmlspecialchars_uni($name) . '" value="' . htmlspecialchars_uni($value) . '"' . ($readonly ? ' readonly' : '') . '>';
        echo '</td></tr>';
    }

    private static function textareaRow(string $label, string $name, string $value, int $rows = 4): void
    {
        echo '<tr><td><strong>' . htmlspecialchars_uni($label) . '</strong></td><td>';
        echo '<textarea name="' . htmlspecialchars_uni($name) . '" rows="' . $rows . '" style="width:100%;">' . htmlspecialchars_uni($value) . '</textarea>';
        echo '</td></tr>';
    }

    private static function redirectWithMessage(string $url, string $message, string $type): void
    {
        if (function_exists('flash_message')) {
            flash_message($message, $type);
        }

        if (function_exists('admin_redirect')) {
            admin_redirect($url);
        }
    }
}
