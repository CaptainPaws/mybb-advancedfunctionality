<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('No direct access');
}

if (!defined('AF_AA_ID')) {
    define('AF_AA_ID', 'advancedappearance');
}

if (!defined('AF_AA_TARGET_APUI_THEME_PACK')) {
    define('AF_AA_TARGET_APUI_THEME_PACK', 'apui_theme_pack');
}

if (!defined('AF_AA_TARGET_APUI_PROFILE_PACK')) {
    define('AF_AA_TARGET_APUI_PROFILE_PACK', 'apui_profile_pack');
}

if (!defined('AF_AA_TARGET_APUI_POSTBIT_PACK')) {
    define('AF_AA_TARGET_APUI_POSTBIT_PACK', 'apui_postbit_pack');
}

if (!defined('AF_AA_TARGET_APUI_APPLICATION_PACK')) {
    define('AF_AA_TARGET_APUI_APPLICATION_PACK', 'apui_application_pack');
}

if (!defined('AF_AA_TARGET_APUI_SHEET_PACK')) {
    define('AF_AA_TARGET_APUI_SHEET_PACK', 'apui_sheet_pack');
}

if (!defined('AF_AA_TARGET_APUI_INVENTORY_PACK')) {
    define('AF_AA_TARGET_APUI_INVENTORY_PACK', 'apui_inventory_pack');
}

if (!defined('AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK')) {
    define('AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK', 'apui_achievements_pack');
}

if (!defined('AF_AA_TARGET_APUI_FRAGMENT_PACK')) {
    define('AF_AA_TARGET_APUI_FRAGMENT_PACK', 'apui_fragment_pack');
}

if (!defined('AF_AA_PRESETS_TABLE_NAME')) {
    define('AF_AA_PRESETS_TABLE_NAME', 'af_aa_presets');
}

if (!defined('AF_AA_ASSIGNMENTS_TABLE_NAME')) {
    define('AF_AA_ASSIGNMENTS_TABLE_NAME', 'af_aa_assignments');
}

if (!defined('AF_AA_PRESETS_TABLE') && defined('TABLE_PREFIX')) {
    define('AF_AA_PRESETS_TABLE', TABLE_PREFIX . AF_AA_PRESETS_TABLE_NAME);
}

if (!defined('AF_AA_ASSIGNMENTS_TABLE') && defined('TABLE_PREFIX')) {
    define('AF_AA_ASSIGNMENTS_TABLE', TABLE_PREFIX . AF_AA_ASSIGNMENTS_TABLE_NAME);
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

    private static function baseUrl(string $section = 'presets', string $do = ''): string
    {
        $url = 'index.php?module=advancedfunctionality&af_view=' . AF_AA_ID . '&section=' . $section;

        if ($do !== '') {
            $url .= '&do=' . rawurlencode($do);
        }

        return $url;
    }

    private static function resolvePresetDo(?string $do = null): string
    {
        global $mybb;

        if ($do === null) {
            $do = (string)$mybb->get_input('do');
        }

        $allowed = [
            'themepack',
            'profilepack',
            'postbitpack',
            'applicationpack',
            'sheetpack',
            'inventorypack',
            'achievementspack',
            'fragmentpack',
        ];

        if (!in_array($do, $allowed, true)) {
            return 'themepack';
        }

        return $do;
    }

    private static function targetKeyForDo(string $do): string
    {
        switch (self::resolvePresetDo($do)) {
            case 'profilepack':
                return AF_AA_TARGET_APUI_PROFILE_PACK;
            case 'postbitpack':
                return AF_AA_TARGET_APUI_POSTBIT_PACK;
            case 'applicationpack':
                return AF_AA_TARGET_APUI_APPLICATION_PACK;
            case 'sheetpack':
                return AF_AA_TARGET_APUI_SHEET_PACK;
            case 'inventorypack':
                return AF_AA_TARGET_APUI_INVENTORY_PACK;
            case 'achievementspack':
                return AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK;
            case 'fragmentpack':
                return AF_AA_TARGET_APUI_FRAGMENT_PACK;
            case 'themepack':
            default:
                return AF_AA_TARGET_APUI_THEME_PACK;
        }
    }

    private static function doForTarget(string $targetKey): string
    {
        $targetKey = trim((string)$targetKey);

        switch ($targetKey) {
            case AF_AA_TARGET_APUI_PROFILE_PACK:
                return 'profilepack';
            case AF_AA_TARGET_APUI_POSTBIT_PACK:
                return 'postbitpack';
            case AF_AA_TARGET_APUI_APPLICATION_PACK:
                return 'applicationpack';
            case AF_AA_TARGET_APUI_SHEET_PACK:
                return 'sheetpack';
            case AF_AA_TARGET_APUI_INVENTORY_PACK:
                return 'inventorypack';
            case AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK:
                return 'achievementspack';
            case AF_AA_TARGET_APUI_FRAGMENT_PACK:
                return 'fragmentpack';
            case AF_AA_TARGET_APUI_THEME_PACK:
            default:
                return 'themepack';
        }
    }

    private static function fragmentOptions(): array
    {
        if (function_exists('af_aa_get_supported_fragment_keys')) {
            return af_aa_get_supported_fragment_keys();
        }

        return [
            'profile_body' => 'Профиль: фон body',
            'profile_banner' => 'Профиль: баннер',
            'profile_avatar_frame' => 'Профиль: рамка аватара',
            'postbit_author' => 'Постбит: фон карточки автора',
            'postbit_name' => 'Постбит: блок никнейма',
            'postbit_plaque' => 'Постбит: нижняя плашка',
            'postbit_avatar_frame' => 'Постбит: рамка аватара',
        ];
    }

    private static function getBaseSettingsDefaults(): array
    {
        $defaults = [
            'member_profile_body_cover_url' => '',
            'member_profile_body_tile_url' => '',
            'member_profile_body_bg_mode' => 'cover',
            'member_profile_body_overlay' => 'none',
            'profile_banner_url' => '',
            'profile_banner_overlay' => 'none',
            'postbit_author_bg_url' => '',
            'postbit_author_overlay' => 'none',
            'postbit_name_bg_url' => '',
            'postbit_name_overlay' => 'none',
            'postbit_plaque_bg_url' => '',
            'postbit_plaque_overlay' => 'none',
            'custom_css' => '',
            'fragment_key' => 'profile_banner',
        ];

        if (function_exists('af_aa_get_apui_defaults')) {
            $runtimeDefaults = af_aa_get_apui_defaults();
            if (is_array($runtimeDefaults)) {
                $defaults = array_merge($defaults, $runtimeDefaults);
            }
        }

        if (!isset($defaults['custom_css'])) {
            $defaults['custom_css'] = '';
        }

        if (!isset($defaults['fragment_key'])) {
            $defaults['fragment_key'] = 'profile_banner';
        }

        return $defaults;
    }

    private static function humanTargetLabel(string $targetKey, array $settings = []): string
    {
        if ($targetKey === AF_AA_TARGET_APUI_THEME_PACK) {
            return 'Общий пак темы';
        }

        if ($targetKey === AF_AA_TARGET_APUI_PROFILE_PACK) {
            return 'Пак профиля';
        }

        if ($targetKey === AF_AA_TARGET_APUI_POSTBIT_PACK) {
            return 'Пак постбита';
        }

        if ($targetKey === AF_AA_TARGET_APUI_APPLICATION_PACK) {
            return 'Пак анкеты';
        }

        if ($targetKey === AF_AA_TARGET_APUI_SHEET_PACK) {
            return 'Пак листа персонажа';
        }

        if ($targetKey === AF_AA_TARGET_APUI_INVENTORY_PACK) {
            return 'Пак инвентаря';
        }

        if ($targetKey === AF_AA_TARGET_APUI_ACHIEVEMENTS_PACK) {
            return 'Пак ачивок';
        }

        if ($targetKey === AF_AA_TARGET_APUI_FRAGMENT_PACK) {
            $fragmentKey = (string)($settings['fragment_key'] ?? '');
            $labelMap = self::fragmentOptions();
            $fragmentLabel = $labelMap[$fragmentKey] ?? $fragmentKey;

            return 'Дробный пак: ' . $fragmentLabel;
        }

        if (strpos($targetKey, AF_AA_TARGET_APUI_FRAGMENT_PACK . ':') === 0) {
            $fragmentKey = substr($targetKey, strlen(AF_AA_TARGET_APUI_FRAGMENT_PACK . ':'));
            $labelMap = self::fragmentOptions();
            $fragmentLabel = $labelMap[$fragmentKey] ?? $fragmentKey;

            return 'Назначение: ' . $fragmentLabel;
        }

        return $targetKey;
    }

    private static function renderNav(string $section, string $do = 'themepack'): void
    {
        echo '<div style="margin-bottom:14px;">';
        echo '<a class="button" style="margin-right:8px;" href="' . htmlspecialchars_uni(self::baseUrl('presets', self::resolvePresetDo($do))) . '">Каталог пресетов</a>';
        echo '<a class="button" href="' . htmlspecialchars_uni(self::baseUrl('assignments')) . '">Назначения пользователям</a>';
        echo '</div>';

        if ($section === 'presets') {
            $tabs = [
                'themepack' => 'Общие пак-темы',
                'profilepack' => 'Страница профиля',
                'postbitpack' => 'Постбит в теме',
                'applicationpack' => 'Анкеты',
                'sheetpack' => 'Листы персонажа',
                'inventorypack' => 'Инвентарь',
                'achievementspack' => 'Ачивки',
                'fragmentpack' => 'Разное',
            ];

            echo '<div style="margin:0 0 14px; display:flex; flex-wrap:wrap; gap:8px;">';
            foreach ($tabs as $tabKey => $title) {
                $isActive = ($tabKey === self::resolvePresetDo($do));
                $style = $isActive
                    ? 'background:#2c3350;border-color:#4f5b89;'
                    : '';

                echo '<a class="button" style="' . $style . '" href="' . htmlspecialchars_uni(self::baseUrl('presets', $tabKey)) . '">' . htmlspecialchars_uni($title) . '</a>';
            }
            echo '</div>';

            switch (self::resolvePresetDo($do)) {
                case 'profilepack':
                    echo '<p>Раздельные пресеты только для оформления профиля. Здесь доступны профильные изображения, оверлеи и пользовательский CSS.</p>';
                    break;

                case 'postbitpack':
                    echo '<p>Раздельные пресеты только для оформления постбита. Здесь доступны настройки авторского блока, никнейма, плашки и пользовательский CSS.</p>';
                    break;

                case 'applicationpack':
                    echo '<p>Пресеты только для UI анкеты. Сохраняются и применяются как отдельная категория surface-паков.</p>';
                    break;

                case 'sheetpack':
                    echo '<p>Пресеты только для листа персонажа. Меняют фон, оверлей, панели и custom CSS поверхности sheet.</p>';
                    break;

                case 'inventorypack':
                    echo '<p>Пресеты только для инвентаря. Меняют фон, оверлей, панели и custom CSS поверхности inventory.</p>';
                    break;

                case 'achievementspack':
                    echo '<p>Пресеты только для ачивок. Меняют фон, оверлей, панели и custom CSS поверхности achievements.</p>';
                    break;

                case 'fragmentpack':
                    echo '<p>Дробные пресеты для отдельных участков профиля/постбита. Выбери участок, который будет изменяться. Для сложной кастомизации используй CSS-блок.</p>';
                    break;

                case 'themepack':
                default:
                    echo '<p>Общие паки темы, которые применяются и к профилю, и к постбиту. Можно задавать картинки, оверлеи и пользовательский CSS.</p>';
                    break;
            }
        } else {
            echo '<p>Ручные назначения пресетов пользователям. Назначение определяется по выбранному пресету и его target.</p>';
        }
    }

    private static function handlePresets(): void
    {
        global $db, $mybb;

        $do = self::resolvePresetDo();
        $base = self::baseUrl('presets', $do);
        $action = (string)$mybb->get_input('action');

        if ($mybb->request_method === 'post') {
            if (function_exists('verify_post_check')) {
                verify_post_check($mybb->get_input('my_post_key'), true);
            }

            if ($action === 'save') {
                self::savePreset($do);
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
            $row = $db->fetch_array($query);
            if (is_array($row)) {
                $editPreset = $row;

                $rowDo = self::doForTarget((string)($row['target_key'] ?? ''));
                if ($rowDo !== $do) {
                    $do = $rowDo;
                    $base = self::baseUrl('presets', $do);
                }
            }
        }

        $settings = self::presetSettingsFromRow($editPreset);
        $targetKey = self::targetKeyForDo($do);

        self::renderNav('presets', $do);
        self::renderPresetForm($base, $editPreset, $settings, $do);

        $where = "target_key='" . $db->escape_string($targetKey) . "'";
        $query = $db->simple_select(
            AF_AA_PRESETS_TABLE_NAME,
            '*',
            $where,
            ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']
        );

        echo '<h3 style="margin-top:18px;">Список пресетов</h3>';
        echo '<table class="table table-bordered">';
        echo '<tr><th>ID</th><th>Slug</th><th>Название</th><th>Тип</th><th>Вкл</th><th>Сорт.</th><th>Действия</th></tr>';

        while ($row = $db->fetch_array($query)) {
            $id = (int)$row['id'];
            $enabled = (int)$row['enabled'] === 1;
            $rowSettings = self::presetSettingsFromRow($row);
            $rowDo = self::doForTarget((string)$row['target_key']);

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td><code>' . htmlspecialchars_uni((string)$row['slug']) . '</code></td>';
            echo '<td>' . htmlspecialchars_uni((string)$row['title']) . '</td>';
            echo '<td>' . htmlspecialchars_uni(self::humanTargetLabel((string)$row['target_key'], $rowSettings)) . '</td>';
            echo '<td>' . ($enabled ? 'Да' : 'Нет') . '</td>';
            echo '<td>' . (int)$row['sortorder'] . '</td>';
            echo '<td>';

            echo '<a href="' . htmlspecialchars_uni(self::baseUrl('presets', $rowDo) . '&edit=' . $id) . '">Редактировать</a> | ';

            echo '<form action="' . htmlspecialchars_uni(self::baseUrl('presets', $rowDo) . '&action=toggle&id=' . $id) . '" method="post" style="display:inline;">';
            echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            echo '<input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '">';
            echo '<button type="submit" class="button button_small">' . ($enabled ? 'Выключить' : 'Включить') . '</button>';
            echo '</form> ';

            echo '<form action="' . htmlspecialchars_uni(self::baseUrl('presets', $rowDo) . '&action=delete&id=' . $id) . '" method="post" style="display:inline;" onsubmit="return confirm(\'Удалить пресет?\');">';
            echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            echo '<button type="submit" class="button button_small">Удалить</button>';
            echo '</form>';

            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    private static function renderPresetForm(string $base, array $preset, array $settings, string $do): void
    {
        global $mybb;

        $do = self::resolvePresetDo($do);
        $id = (int)($preset['id'] ?? 0);
        $targetKey = self::targetKeyForDo($do);

        $titleMap = [
            'themepack' => 'Создать общий пак темы',
            'profilepack' => 'Создать пак профиля',
            'postbitpack' => 'Создать пак постбита',
            'applicationpack' => 'Создать пак анкеты',
            'sheetpack' => 'Создать пак листа персонажа',
            'inventorypack' => 'Создать пак инвентаря',
            'achievementspack' => 'Создать пак ачивок',
            'fragmentpack' => 'Создать дробный пак',
        ];

        $title = $titleMap[$do] ?? 'Создать пресет';
        if ($id > 0) {
            $title = 'Редактирование пресета #' . $id;
        }

        echo '<div style="display:grid;grid-template-columns:minmax(0,1fr) 620px;gap:20px;align-items:start;">';

        echo '<div>';
        echo '<h3>' . htmlspecialchars_uni($title) . '</h3>';
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

        if ($do === 'themepack') {
            self::renderThemePackFields($settings);
        } elseif ($do === 'profilepack') {
            self::renderProfilePackFields($settings);
        } elseif ($do === 'postbitpack') {
            self::renderPostbitPackFields($settings);
        } elseif ($do === 'applicationpack') {
            self::renderSurfacePackFields($settings, 'application', 'Оформление анкеты');
        } elseif ($do === 'sheetpack') {
            self::renderSurfacePackFields($settings, 'sheet', 'Оформление листа персонажа');
        } elseif ($do === 'inventorypack') {
            self::renderSurfacePackFields($settings, 'inventory', 'Оформление инвентаря');
        } elseif ($do === 'achievementspack') {
            self::renderSurfacePackFields($settings, 'achievements', 'Оформление ачивок');
        } else {
            self::renderFragmentPackFields($settings);
        }

        echo '</table>';

        echo '<button type="submit" class="button button_yes"><span class="text">Сохранить пресет</span></button>';
        echo '</form>';
        echo '</div>';

        echo '<div>';
        self::renderPresetExamplesAside($do, $settings);
        echo '</div>';

        echo '</div>';
    }

    private static function renderPresetExamplesAside(string $do, array $settings): void
    {
        $do = self::resolvePresetDo($do);
        $examples = self::getPresetCssExamples($do, $settings);

        echo '<div style="position:sticky;top:16px;">';
        echo '<div class="table table-bordered" style="padding:14px;">';
        echo '<h3 style="margin:0 0 12px;">Примеры CSS-шаблонов</h3>';
        echo '<p style="margin:0 0 12px;" class="smalltext">';
        echo 'Скопируй нужный шаблон в поле <strong>Custom CSS</strong>, затем отредактируй под себя. ';
        echo 'Поддерживаются плейсхолдеры <code>{{selector}}</code> и <code>{{body_selector}}</code>.';
        echo '</p>';

        foreach ($examples as $example) {
            self::renderPresetExampleCard(
                (string)($example['title'] ?? ''),
                (string)($example['description'] ?? ''),
                (string)($example['code'] ?? '')
            );
        }

        echo '</div>';
        echo '</div>';
    }

    private static function renderPresetExampleCard(string $title, string $description, string $code): void
    {
        $copyJs = "var wrap=this.parentNode.parentNode;var ta=wrap?wrap.querySelector('textarea'):null;"
            . "if(!ta){return false;}ta.focus();ta.select();"
            . "try{if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(ta.value);}else{document.execCommand('copy');}}catch(e){try{document.execCommand('copy');}catch(_e){}}"
            . "return false;";

        echo '<div style="border:1px solid #343b51;border-radius:10px;background:#171b29;padding:12px;margin-bottom:14px;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:8px;">';
        echo '<div>';
        echo '<div style="font-weight:700;margin-bottom:6px;">' . htmlspecialchars_uni($title) . '</div>';

        if ($description !== '') {
            echo '<div class="smalltext" style="line-height:1.5;">' . htmlspecialchars_uni($description) . '</div>';
        }

        echo '</div>';
        echo '<button type="button" class="button button_small" onclick="' . htmlspecialchars_uni($copyJs) . '">Скопировать</button>';
        echo '</div>';

        echo '<textarea readonly rows="12" style="width:100%;font-family:Consolas,Monaco,monospace;white-space:pre;">'
            . htmlspecialchars_uni($code)
            . '</textarea>';
        echo '</div>';
    }

    private static function getPresetCssExamples(string $do, array $settings): array
    {
        $do = self::resolvePresetDo($do);

        switch ($do) {
            case 'profilepack':
                return self::getProfilePackCssExamples();

            case 'postbitpack':
                return self::getPostbitPackCssExamples();

            case 'applicationpack':
                return self::getSurfacePackCssExamples('application', 'Анкета');

            case 'sheetpack':
                return self::getSurfacePackCssExamples('sheet', 'Лист персонажа');

            case 'inventorypack':
                return self::getSurfacePackCssExamples('inventory', 'Инвентарь');

            case 'achievementspack':
                return self::getSurfacePackCssExamples('achievements', 'Ачивки');

            case 'fragmentpack':
                return self::getFragmentPackCssExamples($settings);

            case 'themepack':
            default:
                return self::getThemePackCssExamples();
        }
    }

    private static function getThemePackCssExamples(): array
    {
        return [
            [
                'title' => 'Общий пак: неоновое стекло',
                'description' => 'Шаблон сразу для профиля и постбита. Хорошая стартовая база для sci-fi / cyberpunk оформления.',
                'code' => <<<'CSS'
    /* Общий пак темы: неоновое стекло */
    {{body_selector}} {
    background-color: #0b0d14;
    }

    {{selector}} .af-apui-profile-hero,
    {{selector}} .af-apui-profile-tabs,
    {{selector}} .af-apui-postbit-author__inner,
    {{selector}} .af-apui-postbit-content {
    border-color: rgba(143, 109, 255, .28);
    box-shadow: 0 0 28px rgba(92, 46, 188, .18);
    }

    {{selector}} .af-apui-profile-tabs__content,
    {{selector}} .af-apui-postbit-profilefields,
    {{selector}} .af-apui-postbit-userdetails {
    background: rgba(8, 12, 22, .62);
    backdrop-filter: blur(10px);
    }

    {{selector}} .af-apui-postbit-name-wrap,
    {{selector}} .af-apui-postbit-plaque {
    text-shadow: 0 0 16px rgba(199, 184, 255, .24);
    }
    CSS
            ],
            [
                'title' => 'Общий пак: мрачная готика',
                'description' => 'Подходит для тёмных миров, хоррора, магии и более тяжёлой декоративности.',
                'code' => <<<'CSS'
    /* Общий пак темы: мрачная готика */
    {{selector}} .af-apui-profile-hero,
    {{selector}} .af-apui-profile-tabs,
    {{selector}} .af-apui-postbit-author__inner,
    {{selector}} .af-apui-postbit-content {
    border-color: rgba(148, 109, 109, .30);
    box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.04),
        0 10px 30px rgba(0,0,0,.35);
    }

    {{selector}} .af-apui-profile-hero__inner,
    {{selector}} .af-apui-profile-tabs__content,
    {{selector}} .af-apui-postbit-profilefields,
    {{selector}} .af-apui-postbit-userdetails {
    background: linear-gradient(180deg, rgba(10,10,10,.38), rgba(20,10,10,.56));
    }

    {{selector}} .af-apui-postbit-name-wrap a,
    {{selector}} .af-apui-profile-name-wrap {
    letter-spacing: .04em;
    text-shadow: 0 0 12px rgba(160, 80, 80, .18);
    }
    CSS
            ],
            [
                'title' => 'Общий пак: мягкий blur UI',
                'description' => 'Более спокойный шаблон с акцентом на матовое стекло, скругления и мягкие тени.',
                'code' => <<<'CSS'
    /* Общий пак темы: мягкий blur UI */
    {{selector}} .af-apui-profile-hero,
    {{selector}} .af-apui-profile-tabs,
    {{selector}} .af-apui-postbit-author__inner,
    {{selector}} .af-apui-postbit-content {
    border-radius: 16px;
    overflow: hidden;
    }

    {{selector}} .af-apui-profile-tabs__content,
    {{selector}} .af-apui-postbit-profilefields,
    {{selector}} .af-apui-postbit-userdetails {
    background: rgba(16, 20, 31, .52);
    backdrop-filter: blur(14px);
    }

    {{selector}} .af-apui-postbit-plaque {
    border-radius: 0 0 16px 16px;
    }
    CSS
            ],
        ];
    }

    private static function getProfilePackCssExamples(): array
    {
        return [
            [
                'title' => 'Профиль: акцент на баннер и герой-блок',
                'description' => 'Шаблон для усиления баннера, имени и главного hero-блока.',
                'code' => <<<'CSS'
    /* Профиль: акцент на hero и banner */
    {{selector}} .af-apui-profile-hero {
    border-color: rgba(111, 84, 255, .28);
    box-shadow: 0 0 30px rgba(86, 54, 172, .18);
    }

    {{selector}} .af-apui-profile-hero__banner {
    filter: saturate(1.08) contrast(1.05);
    }

    {{selector}} .af-apui-profile-hero__banner::after {
    background: linear-gradient(
        180deg,
        rgba(6, 8, 18, .08) 0%,
        rgba(18, 20, 38, .24) 44%,
        rgba(8, 10, 20, .86) 100%
    );
    }

    {{selector}} .af-apui-profile-name-wrap {
    text-shadow: 0 0 18px rgba(203, 191, 255, .28);
    }
    CSS
            ],
            [
                'title' => 'Профиль: стеклянные карточки',
                'description' => 'Для вкладок, инфо-плиток и мета-блоков на странице профиля.',
                'code' => <<<'CSS'
    /* Профиль: стеклянные карточки */
    {{selector}} .af-apui-profile-tabs,
    {{selector}} .af-apui-card,
    {{selector}} .af-apui-profile-meta-item,
    {{selector}} .af-apui-info-item {
    background: rgba(12, 16, 26, .50);
    backdrop-filter: blur(12px);
    border-color: rgba(255,255,255,.10);
    }

    {{selector}} .af-apui-profile-tabs__nav {
    background: rgba(20, 24, 38, .72);
    }
    CSS
            ],
            [
                'title' => 'Профиль: рамка аватара',
                'description' => 'Шаблон под декоративную рамку аватара и мягкое свечение.',
                'code' => <<<'CSS'
    /* Профиль: рамка аватара */
    {{selector}} .af-apui-profile-avatar-frame {
    border-color: rgba(175, 145, 255, .42);
    box-shadow:
        0 0 0 1px rgba(255,255,255,.05),
        0 0 24px rgba(118, 83, 240, .18);
    background: rgba(0, 0, 0, .28);
    }

    {{selector}} .af-apui-profile-avatar-frame img {
    filter: saturate(1.06);
    }
    CSS
            ],
        ];
    }

    private static function getPostbitPackCssExamples(): array
    {
        return [
            [
                'title' => 'Постбит: усиленная карточка автора',
                'description' => 'База для оформления author-блока с мягким свечением и стеклом.',
                'code' => <<<'CSS'
    /* Постбит: карточка автора */
    {{selector}} .af-apui-postbit-author__inner {
    border-color: rgba(121, 93, 255, .28);
    box-shadow: 0 0 24px rgba(73, 44, 163, .16);
    }

    {{selector}} .af-apui-postbit-profilefields,
    {{selector}} .af-apui-postbit-userdetails {
    background: rgba(10, 12, 18, .58);
    backdrop-filter: blur(10px);
    }
    CSS
            ],
            [
                'title' => 'Постбит: никнейм и плашка',
                'description' => 'Шаблон для красивого name block и плакетки листа персонажа.',
                'code' => <<<'CSS'
    /* Постбит: name + plaque */
    {{selector}} .af-apui-postbit-name-wrap {
    border-color: rgba(255,255,255,.16);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.04);
    }

    {{selector}} .af-apui-postbit-name-wrap a {
    letter-spacing: .03em;
    text-shadow: 0 0 14px rgba(224, 214, 255, .22);
    }

    {{selector}} .af-apui-postbit-plaque {
    box-shadow: 0 10px 20px rgba(0,0,0,.25);
    transition: transform .18s ease, box-shadow .18s ease;
    }

    {{selector}} .af-apui-postbit-plaque:hover {
    transform: translateY(-1px);
    box-shadow: 0 14px 26px rgba(0,0,0,.32);
    }
    CSS
            ],
            [
                'title' => 'Постбит: рамка аватара и онлайн-статус',
                'description' => 'Подойдёт, если хочешь сделать акцент на аватаре и точке статуса.',
                'code' => <<<'CSS'
    /* Постбит: avatar + online */
    {{selector}} .af-apui-postbit-avatar-frame {
    border-color: rgba(171, 142, 255, .38);
    box-shadow: 0 0 18px rgba(90, 60, 182, .18);
    }

    {{selector}} .af-apui-postbit-online .af-apui-presence-dot--online {
    box-shadow:
        0 0 0 1px rgba(0,0,0,.28),
        0 0 12px rgba(47, 211, 93, .55);
    }
    CSS
            ],
        ];
    }


    private static function getSurfacePackCssExamples(string $surfaceKey, string $surfaceLabel): array
    {
        $selector = '{{selector}} [data-af-apui-surface="' . $surfaceKey . '"]';

        return [
            [
                'title' => $surfaceLabel . ': атмосферный фон и рамки',
                'description' => 'Стартовый шаблон для отдельной UI-поверхности с акцентом на фон и панели.',
                'code' => "/* {$surfaceLabel}: фон и панели */
{$selector} {
background-blend-mode: normal;
}

{$selector},
{$selector} .af-apui-surface-page,
{$selector} .af-inv-panel,
{$selector} .af-cs-section {
border-color: rgba(163, 134, 255, .20);
box-shadow: 0 12px 28px rgba(0,0,0,.24);
}"
            ],
            [
                'title' => $surfaceLabel . ': стеклянные карточки',
                'description' => 'Подходит для модалок и полноэкранных страниц с матовым стеклом.',
                'code' => "/* {$surfaceLabel}: glass ui */
{$selector} .af-apui-surface-page > *,
{$selector} .af-inv-panel,
{$selector} .af-cs-section,
{$selector} .af-achievements-card {
background: rgba(15, 18, 28, .56);
backdrop-filter: blur(12px);
border-color: rgba(255,255,255,.10);
}"
            ],
        ];
    }

    private static function getFragmentPackCssExamples(array $settings): array
    {
        $fragmentKey = (string)($settings['fragment_key'] ?? 'profile_banner');
        $fragmentOptions = self::fragmentOptions();

        if (!isset($fragmentOptions[$fragmentKey])) {
            $fragmentKey = 'profile_banner';
        }

        $currentTitle = $fragmentOptions[$fragmentKey] ?? $fragmentKey;
        $currentCode = self::getCurrentFragmentCssTemplate($fragmentKey);

        return [
            [
                'title' => 'Шаблон для выбранного участка: ' . $currentTitle,
                'description' => 'Это стартовый шаблон именно под тот fragment, который сейчас выбран в форме.',
                'code' => $currentCode,
            ],
            [
                'title' => 'Fragment: рамка аватара постбита',
                'description' => 'Пример точечной кастомизации только рамки аватара.',
                'code' => <<<'CSS'
    /* Fragment: рамка аватара постбита */
    {{selector}} .af-apui-postbit-avatar-frame {
    border-color: rgba(160, 126, 255, .46);
    box-shadow:
        0 0 0 1px rgba(255,255,255,.06),
        0 0 22px rgba(92, 58, 205, .20);
    background: rgba(0,0,0,.24);
    }
    CSS
            ],
            [
                'title' => 'Fragment: плашка листа персонажа',
                'description' => 'Пример, если пользователь хочет изменить только кнопку/плашку.',
                'code' => <<<'CSS'
    /* Fragment: плашка листа персонажа */
    {{selector}} .af-apui-postbit-plaque {
    border-color: rgba(255,255,255,.16);
    text-shadow: 0 0 12px rgba(255,255,255,.18);
    box-shadow: 0 10px 20px rgba(0,0,0,.24);
    }

    {{selector}} .af-apui-postbit-plaque:hover {
    transform: translateY(-1px);
    }
    CSS
            ],
        ];
    }

    private static function getCurrentFragmentCssTemplate(string $fragmentKey): string
    {
        switch ($fragmentKey) {
            case 'profile_body':
                return <<<'CSS'
    /* Fragment: профиль — фон body */
    {{body_selector}} {
    background-color: #090b12;
    background-blend-mode: normal;
    }
    CSS;

            case 'profile_banner':
                return <<<'CSS'
    /* Fragment: профиль — баннер */
    {{selector}} .af-apui-profile-hero__banner {
    filter: saturate(1.08) contrast(1.04);
    }

    {{selector}} .af-apui-profile-hero__banner::after {
    background: linear-gradient(
        180deg,
        rgba(8, 10, 16, .08) 0%,
        rgba(10, 12, 24, .24) 42%,
        rgba(4, 6, 12, .86) 100%
    );
    }
    CSS;

            case 'profile_avatar_frame':
                return <<<'CSS'
    /* Fragment: профиль — рамка аватара */
    {{selector}} .af-apui-profile-avatar-frame {
    border-color: rgba(169, 138, 255, .42);
    box-shadow: 0 0 22px rgba(87, 55, 182, .18);
    background: rgba(0,0,0,.24);
    }
    CSS;

            case 'postbit_author':
                return <<<'CSS'
    /* Fragment: постбит — карточка автора */
    {{selector}} .af-apui-postbit-author__inner {
    border-color: rgba(138, 111, 255, .30);
    box-shadow: 0 0 24px rgba(80, 44, 184, .16);
    }
    CSS;

            case 'postbit_name':
                return <<<'CSS'
    /* Fragment: постбит — блок никнейма */
    {{selector}} .af-apui-postbit-name-wrap {
    border-color: rgba(255,255,255,.16);
    }

    {{selector}} .af-apui-postbit-name-wrap a {
    text-shadow: 0 0 14px rgba(229, 220, 255, .18);
    }
    CSS;

            case 'postbit_plaque':
                return <<<'CSS'
    /* Fragment: постбит — плашка / кнопка листа */
    {{selector}} .af-apui-postbit-plaque {
    box-shadow: 0 10px 20px rgba(0,0,0,.24);
    transition: transform .18s ease, box-shadow .18s ease;
    }

    {{selector}} .af-apui-postbit-plaque:hover {
    transform: translateY(-1px);
    }
    CSS;

            case 'postbit_avatar_frame':
                return <<<'CSS'
    /* Fragment: постбит — рамка аватара */
    {{selector}} .af-apui-postbit-avatar-frame {
    border-color: rgba(164, 132, 255, .40);
    box-shadow: 0 0 20px rgba(80, 48, 178, .16);
    }
    CSS;

            default:
                return <<<'CSS'
    /* Fragment: общий шаблон */
    {{selector}} .your-target-class {
    /* твои стили */
    }
    CSS;
        }
    }
    private static function renderThemePackFields(array $settings): void
    {
        echo '<tr><th colspan="2">Профиль</th></tr>';
        self::renderProfilePackFieldsInner($settings);

        echo '<tr><th colspan="2">Постбит</th></tr>';
        self::renderPostbitPackFieldsInner($settings);

        echo '<tr><th colspan="2">Пользовательский CSS</th></tr>';
        self::textareaRow(
            'Custom CSS',
            'settings[custom_css]',
            (string)($settings['custom_css'] ?? ''),
            12,
            'Подсказка: используй {{selector}} и {{body_selector}} для привязки CSS к конкретному пользователю.'
        );
    }

    private static function renderProfilePackFields(array $settings): void
    {
        echo '<tr><th colspan="2">Оформление профиля</th></tr>';
        self::renderProfilePackFieldsInner($settings);

        echo '<tr><th colspan="2">Пользовательский CSS</th></tr>';
        self::textareaRow(
            'Custom CSS',
            'settings[custom_css]',
            (string)($settings['custom_css'] ?? ''),
            12,
            'Подсказка: используй {{selector}} и {{body_selector}}. Этот CSS должен менять только профильный UI.'
        );
    }

    private static function renderPostbitPackFields(array $settings): void
    {
        echo '<tr><th colspan="2">Оформление постбита</th></tr>';
        self::renderPostbitPackFieldsInner($settings);

        echo '<tr><th colspan="2">Пользовательский CSS</th></tr>';
        self::textareaRow(
            'Custom CSS',
            'settings[custom_css]',
            (string)($settings['custom_css'] ?? ''),
            12,
            'Подсказка: используй {{selector}}. Этот CSS должен менять только постбит.'
        );
    }

    private static function renderSurfacePackFields(array $settings, string $surfaceKey, string $heading): void
    {
        echo '<tr><th colspan="2">' . htmlspecialchars_uni($heading) . '</th></tr>';
        self::inputRow('Background URL', 'settings[' . $surfaceKey . '_bg_url]', (string)($settings[$surfaceKey . '_bg_url'] ?? ''));
        self::inputRow('Background overlay', 'settings[' . $surfaceKey . '_bg_overlay]', (string)($settings[$surfaceKey . '_bg_overlay'] ?? ''));
        self::inputRow('Panel background', 'settings[' . $surfaceKey . '_panel_bg]', (string)($settings[$surfaceKey . '_panel_bg'] ?? ''));
        self::inputRow('Panel border', 'settings[' . $surfaceKey . '_panel_border]', (string)($settings[$surfaceKey . '_panel_border'] ?? ''));

        echo '<tr><th colspan="2">Пользовательский CSS</th></tr>';
        self::textareaRow(
            'Custom CSS',
            'settings[custom_css]',
            (string)($settings['custom_css'] ?? ''),
            12,
            'Подсказка: используй {{selector}}. Этот CSS должен менять только выбранную UI-поверхность.'
        );
    }

    private static function renderFragmentPackFields(array $settings): void
    {
        $fragmentKey = (string)($settings['fragment_key'] ?? 'profile_banner');
        $fragmentOptions = self::fragmentOptions();

        if (!isset($fragmentOptions[$fragmentKey])) {
            $fragmentKey = 'profile_banner';
        }

        echo '<tr><th colspan="2">Дробная кастомизация</th></tr>';

        echo '<tr><td style="width:300px;"><strong>Участок</strong></td><td>';
        echo '<select name="settings[fragment_key]">';
        foreach ($fragmentOptions as $key => $label) {
            echo '<option value="' . htmlspecialchars_uni($key) . '"' . ($fragmentKey === $key ? ' selected' : '') . '>' . htmlspecialchars_uni($label) . '</option>';
        }
        echo '</select>';
        echo '<div class="smalltext" style="margin-top:6px;">Будут применяться только поля выбранного участка + CSS.</div>';
        echo '</td></tr>';

        echo '<tr><th colspan="2">Поля для отдельных участков</th></tr>';

        self::inputRow('member_profile_body_cover_url', 'settings[member_profile_body_cover_url]', $settings['member_profile_body_cover_url']);
        self::inputRow('member_profile_body_tile_url', 'settings[member_profile_body_tile_url]', $settings['member_profile_body_tile_url']);

        echo '<tr><td><strong>member_profile_body_bg_mode</strong></td><td>';
        echo '<select name="settings[member_profile_body_bg_mode]">';
        echo '<option value="cover"' . ((string)$settings['member_profile_body_bg_mode'] === 'cover' ? ' selected' : '') . '>cover</option>';
        echo '<option value="tile"' . ((string)$settings['member_profile_body_bg_mode'] === 'tile' ? ' selected' : '') . '>tile</option>';
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

        echo '<tr><th colspan="2">Пользовательский CSS</th></tr>';
        self::textareaRow(
            'Custom CSS',
            'settings[custom_css]',
            (string)($settings['custom_css'] ?? ''),
            12,
            'Для рамок, декоративных элементов и точечной кастомизации. Используй {{selector}} и {{body_selector}}.'
        );
    }

    private static function renderProfilePackFieldsInner(array $settings): void
    {
        self::inputRow('member_profile_body_cover_url', 'settings[member_profile_body_cover_url]', $settings['member_profile_body_cover_url']);
        self::inputRow('member_profile_body_tile_url', 'settings[member_profile_body_tile_url]', $settings['member_profile_body_tile_url']);

        echo '<tr><td><strong>member_profile_body_bg_mode</strong></td><td>';
        echo '<select name="settings[member_profile_body_bg_mode]">';
        echo '<option value="cover"' . ((string)$settings['member_profile_body_bg_mode'] === 'cover' ? ' selected' : '') . '>cover</option>';
        echo '<option value="tile"' . ((string)$settings['member_profile_body_bg_mode'] === 'tile' ? ' selected' : '') . '>tile</option>';
        echo '</select></td></tr>';

        self::inputRow('member_profile_body_overlay', 'settings[member_profile_body_overlay]', $settings['member_profile_body_overlay']);
        self::inputRow('profile_banner_url', 'settings[profile_banner_url]', $settings['profile_banner_url']);
        self::inputRow('profile_banner_overlay', 'settings[profile_banner_overlay]', $settings['profile_banner_overlay']);
    }

    private static function renderPostbitPackFieldsInner(array $settings): void
    {
        self::inputRow('postbit_author_bg_url', 'settings[postbit_author_bg_url]', $settings['postbit_author_bg_url']);
        self::inputRow('postbit_author_overlay', 'settings[postbit_author_overlay]', $settings['postbit_author_overlay']);
        self::inputRow('postbit_name_bg_url', 'settings[postbit_name_bg_url]', $settings['postbit_name_bg_url']);
        self::inputRow('postbit_name_overlay', 'settings[postbit_name_overlay]', $settings['postbit_name_overlay']);
        self::inputRow('postbit_plaque_bg_url', 'settings[postbit_plaque_bg_url]', $settings['postbit_plaque_bg_url']);
        self::inputRow('postbit_plaque_overlay', 'settings[postbit_plaque_overlay]', $settings['postbit_plaque_overlay']);
    }

    private static function savePreset(string $do): void
    {
        global $db, $mybb;

        $do = self::resolvePresetDo($do);
        $id = (int)$mybb->get_input('id');

        $slugRaw = trim((string)$mybb->get_input('slug'));
        $slug = preg_replace('~[^a-z0-9_\-]+~i', '-', strtolower($slugRaw)) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'preset-' . TIME_NOW;
        }

        $targetKey = self::targetKeyForDo($do);

        $settingsInput = $mybb->get_input('settings', MyBB::INPUT_ARRAY);
        if (!is_array($settingsInput)) {
            $settingsInput = [];
        }

        $defaults = self::getBaseSettingsDefaults();
        if (function_exists('af_aa_decode_and_sanitize_preset_settings')) {
            $settings = af_aa_decode_and_sanitize_preset_settings(
                json_encode($settingsInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $defaults,
                $targetKey
            );
        } else {
            $settings = array_merge($defaults, $settingsInput);
        }

        $previewImage = (string)$mybb->get_input('preview_image');
        if (function_exists('af_aa_sanitize_image_url')) {
            $previewImage = af_aa_sanitize_image_url($previewImage, '');
        } else {
            $previewImage = '';
        }

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
        $action = (string)$mybb->get_input('action');

        if ($mybb->request_method === 'post') {
            if (function_exists('verify_post_check')) {
                verify_post_check($mybb->get_input('my_post_key'), true);
            }

            if ($action === 'save_assignment') {
                self::saveAssignment();
                self::redirectWithMessage($base, 'Назначение сохранено.', 'success');
            }

            if ($action === 'remove_assignment') {
                $assignmentId = (int)$mybb->get_input('assignment_id');
                if ($assignmentId > 0) {
                    $db->delete_query(AF_AA_ASSIGNMENTS_TABLE_NAME, "id='" . $assignmentId . "'");
                }

                self::redirectWithMessage($base, 'Назначение снято.', 'success');
            }
        }

        self::renderNav('assignments');
        self::renderAssignmentForm($base);

        $query = $db->write_query(
            "SELECT a.id, a.entity_id, a.target_key, a.preset_id, a.is_enabled, u.username, p.title AS preset_title, p.settings_json, p.target_key AS preset_target_key"
            . " FROM " . AF_AA_ASSIGNMENTS_TABLE . " a"
            . " LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid=a.entity_id)"
            . " LEFT JOIN " . AF_AA_PRESETS_TABLE . " p ON (p.id=a.preset_id)"
            . " WHERE a.entity_type='user'"
            . " ORDER BY a.id DESC"
        );

        echo '<h3 style="margin-top:18px;">Текущие назначения</h3>';
        echo '<table class="table table-bordered">';
        echo '<tr><th>ID</th><th>UID</th><th>Пользователь</th><th>Тип</th><th>Preset ID</th><th>Пресет</th><th>Вкл</th><th>Действия</th></tr>';

        while ($row = $db->fetch_array($query)) {
            $uid = (int)$row['entity_id'];

            $presetSettings = [];
            if (!empty($row['settings_json'])) {
                $presetSettings = self::presetSettingsFromRow([
                    'settings_json' => (string)$row['settings_json'],
                    'target_key' => (string)($row['preset_target_key'] ?? ''),
                ]);
            }

            echo '<tr>';
            echo '<td>' . (int)$row['id'] . '</td>';
            echo '<td>' . $uid . '</td>';
            echo '<td>' . htmlspecialchars_uni((string)($row['username'] ?? ('uid:' . $uid))) . '</td>';
            echo '<td>' . htmlspecialchars_uni(self::humanTargetLabel((string)$row['target_key'], $presetSettings)) . '</td>';
            echo '<td>' . (int)$row['preset_id'] . '</td>';
            echo '<td>' . htmlspecialchars_uni((string)($row['preset_title'] ?? '—')) . '</td>';
            echo '<td>' . ((int)$row['is_enabled'] === 1 ? 'Да' : 'Нет') . '</td>';
            echo '<td>';

            echo '<form action="' . htmlspecialchars_uni($base . '&action=remove_assignment') . '" method="post" style="display:inline;" onsubmit="return confirm(\'Снять назначение?\');">';
            echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            echo '<input type="hidden" name="assignment_id" value="' . (int)$row['id'] . '">';
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

        $query = $db->simple_select(
            AF_AA_PRESETS_TABLE_NAME,
            'id,title,target_key,settings_json',
            "enabled='1'",
            ['order_by' => 'target_key, sortorder, id', 'order_dir' => 'ASC']
        );

        while ($row = $db->fetch_array($query)) {
            $settings = self::presetSettingsFromRow($row);
            $label = self::humanTargetLabel((string)$row['target_key'], $settings);

            $presetOptions .= '<option value="' . (int)$row['id'] . '">'
                . (int)$row['id'] . ' — '
                . htmlspecialchars_uni((string)$row['title'])
                . ' [' . htmlspecialchars_uni($label) . ']'
                . '</option>';
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

        $presetQuery = $db->simple_select(AF_AA_PRESETS_TABLE_NAME, '*', "id='" . $presetId . "'", ['limit' => 1]);
        $preset = $db->fetch_array($presetQuery);

        if (!is_array($preset) || empty($preset)) {
            return;
        }

        $presetSettings = self::presetSettingsFromRow($preset);
        $assignmentTargetKey = (string)($preset['target_key'] ?? '');

        if ($assignmentTargetKey === AF_AA_TARGET_APUI_FRAGMENT_PACK) {
            $fragmentKey = (string)($presetSettings['fragment_key'] ?? '');
            if ($fragmentKey === '') {
                return;
            }

            $assignmentTargetKey = AF_AA_TARGET_APUI_FRAGMENT_PACK . ':' . $fragmentKey;
        }

        if ($assignmentTargetKey === '') {
            return;
        }

        $exists = $db->simple_select(
            AF_AA_ASSIGNMENTS_TABLE_NAME,
            'id',
            "entity_type='user' AND entity_id='" . $uid . "' AND target_key='" . $db->escape_string($assignmentTargetKey) . "'",
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
            'target_key' => $db->escape_string($assignmentTargetKey),
            'created_at' => TIME_NOW,
        ];

        $db->insert_query(AF_AA_ASSIGNMENTS_TABLE_NAME, $payload);
    }

    private static function presetSettingsFromRow(array $row): array
    {
        $defaults = self::getBaseSettingsDefaults();
        $json = (string)($row['settings_json'] ?? '');
        $targetKey = (string)($row['target_key'] ?? '');

        if (!function_exists('af_aa_decode_and_sanitize_preset_settings')) {
            return $defaults;
        }

        return af_aa_decode_and_sanitize_preset_settings($json, $defaults, $targetKey);
    }

    private static function inputRow(string $label, string $name, mixed $value, bool $readonly = false): void
    {
        if (is_array($value) || is_object($value) || $value === null) {
            $value = '';
        } else {
            $value = (string)$value;
        }

        echo '<tr><td style="width:300px;"><strong>' . htmlspecialchars_uni($label) . '</strong></td><td>';
        echo '<input type="text" class="text_input" style="width:100%;" name="' . htmlspecialchars_uni($name) . '" value="' . htmlspecialchars_uni($value) . '"' . ($readonly ? ' readonly' : '') . '>';
        echo '</td></tr>';
    }

    private static function textareaRow(string $label, string $name, mixed $value, int $rows = 4, string $hint = ''): void
    {
        if (is_array($value) || is_object($value) || $value === null) {
            $value = '';
        } else {
            $value = (string)$value;
        }

        echo '<tr><td><strong>' . htmlspecialchars_uni($label) . '</strong></td><td>';
        echo '<textarea name="' . htmlspecialchars_uni($name) . '" rows="' . (int)$rows . '" style="width:100%;">' . htmlspecialchars_uni($value) . '</textarea>';

        if ($hint !== '') {
            echo '<div class="smalltext" style="margin-top:6px;">' . htmlspecialchars_uni($hint) . '</div>';
        }

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
