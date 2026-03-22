<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('No direct access');
}

if (!defined('AF_APUI_ID')) {
    define('AF_APUI_ID', 'advancedprofileui');
}

class AF_Admin_Advancedprofileui
{
    public static function dispatch(): void
    {
        global $mybb, $db;

        if (function_exists('af_apui_ensure_settings')) {
            af_apui_ensure_settings();
        }
        if (function_exists('rebuild_settings')) {
            rebuild_settings();
        }

        $redirectUrl = 'index.php?module=advancedfunctionality&af_view=' . AF_APUI_ID;

        $fields = [
            'af_' . AF_APUI_ID . '_enabled',

            'af_' . AF_APUI_ID . '_member_profile_body_cover_url',
            'af_' . AF_APUI_ID . '_member_profile_body_tile_url',
            'af_' . AF_APUI_ID . '_member_profile_body_bg_mode',
            'af_' . AF_APUI_ID . '_member_profile_body_overlay',
            'af_' . AF_APUI_ID . '_profile_banner_url',
            'af_' . AF_APUI_ID . '_profile_banner_overlay',
            'af_' . AF_APUI_ID . '_member_profile_css',

            'af_' . AF_APUI_ID . '_postbit_author_bg_url',
            'af_' . AF_APUI_ID . '_postbit_author_overlay',
            'af_' . AF_APUI_ID . '_postbit_name_bg_url',
            'af_' . AF_APUI_ID . '_postbit_name_overlay',
            'af_' . AF_APUI_ID . '_postbit_plaque_bg_url',
            'af_' . AF_APUI_ID . '_postbit_plaque_overlay',
            'af_' . AF_APUI_ID . '_postbit_plaque_media_image_url',
            'af_' . AF_APUI_ID . '_postbit_plaque_media_icon_class',
            'af_' . AF_APUI_ID . '_postbit_plaque_media_overlay',
            'af_' . AF_APUI_ID . '_postbit_plaque_media_css',
            'af_' . AF_APUI_ID . '_postbit_plaque_title_default',
            'af_' . AF_APUI_ID . '_postbit_plaque_subtitle_default',
            'af_' . AF_APUI_ID . '_postbit_plaque_icon_url',
            'af_' . AF_APUI_ID . '_postbit_plaque_icon_glyph',
            'af_' . AF_APUI_ID . '_postbit_plaque_icon_bg',
            'af_' . AF_APUI_ID . '_postbit_plaque_icon_overlay',
            'af_' . AF_APUI_ID . '_postbit_plaque_icon_border',
            'af_' . AF_APUI_ID . '_postbit_plaque_icon_color',
            'af_' . AF_APUI_ID . '_postbit_plaque_icon_size',
            'af_' . AF_APUI_ID . '_postbit_css',

            'af_' . AF_APUI_ID . '_thread_body_cover_url',
            'af_' . AF_APUI_ID . '_thread_body_tile_url',
            'af_' . AF_APUI_ID . '_thread_body_bg_mode',
            'af_' . AF_APUI_ID . '_thread_body_overlay',
            'af_' . AF_APUI_ID . '_thread_banner_url',
            'af_' . AF_APUI_ID . '_thread_banner_overlay',
            'af_' . AF_APUI_ID . '_thread_css',

            'af_' . AF_APUI_ID . '_sheet_bg_url',
            'af_' . AF_APUI_ID . '_sheet_bg_overlay',
            'af_' . AF_APUI_ID . '_sheet_panel_bg',
            'af_' . AF_APUI_ID . '_sheet_panel_border',
            'af_' . AF_APUI_ID . '_sheet_css',

            'af_' . AF_APUI_ID . '_application_bg_url',
            'af_' . AF_APUI_ID . '_application_bg_overlay',
            'af_' . AF_APUI_ID . '_application_panel_bg',
            'af_' . AF_APUI_ID . '_application_panel_border',
            'af_' . AF_APUI_ID . '_application_css',

            'af_' . AF_APUI_ID . '_inventory_bg_url',
            'af_' . AF_APUI_ID . '_inventory_bg_overlay',
            'af_' . AF_APUI_ID . '_inventory_panel_bg',
            'af_' . AF_APUI_ID . '_inventory_panel_border',
            'af_' . AF_APUI_ID . '_inventory_css',

            'af_' . AF_APUI_ID . '_achievements_bg_url',
            'af_' . AF_APUI_ID . '_achievements_bg_overlay',
            'af_' . AF_APUI_ID . '_achievements_panel_bg',
            'af_' . AF_APUI_ID . '_achievements_panel_border',
            'af_' . AF_APUI_ID . '_achievements_css',
        ];

        $enumFields = [
            'af_' . AF_APUI_ID . '_enabled' => ['0', '1'],
            'af_' . AF_APUI_ID . '_member_profile_body_bg_mode' => ['cover', 'tile'],
            'af_' . AF_APUI_ID . '_thread_body_bg_mode' => ['cover', 'tile'],
        ];

        if ($mybb->request_method === 'post' && $mybb->get_input('save_apui_settings') === '1') {
            if (function_exists('verify_post_check')) {
                verify_post_check($mybb->get_input('my_post_key'), true);
            }

            foreach ($fields as $fieldName) {
                $value = trim((string)$mybb->get_input($fieldName));

                if (isset($enumFields[$fieldName]) && !in_array($value, $enumFields[$fieldName], true)) {
                    $value = $enumFields[$fieldName][0];
                }

                $db->update_query(
                    'settings',
                    ['value' => $db->escape_string($value)],
                    "name='" . $db->escape_string($fieldName) . "'"
                );
            }

            if (function_exists('rebuild_settings')) {
                rebuild_settings();
            }

            if (function_exists('flash_message')) {
                flash_message('Настройки AdvancedProfileUI сохранены.', 'success');
            }

            if (function_exists('admin_redirect')) {
                admin_redirect($redirectUrl);
            }

            echo '<div class="success">Настройки AdvancedProfileUI сохранены.</div>';
        }

        $values = [];
        foreach ($fields as $fieldName) {
            $values[$fieldName] = htmlspecialchars_uni((string)($mybb->settings[$fieldName] ?? ''));
        }

        $enabled = (string)($mybb->settings['af_' . AF_APUI_ID . '_enabled'] ?? '1');
        if ($enabled !== '0') {
            $enabled = '1';
        }

        $mode = (string)($mybb->settings['af_' . AF_APUI_ID . '_member_profile_body_bg_mode'] ?? 'cover');
        if ($mode !== 'tile') {
            $mode = 'cover';
        }

        $threadMode = (string)($mybb->settings['af_' . AF_APUI_ID . '_thread_body_bg_mode'] ?? 'cover');
        if ($threadMode !== 'tile') {
            $threadMode = 'cover';
        }

        echo '<form action="' . htmlspecialchars_uni($redirectUrl) . '" method="post">';
        echo '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
        echo '<input type="hidden" name="save_apui_settings" value="1">';

        echo '<div class="page_messagetype">';
        echo '<p>Здесь задаются базовые стили APUI для member_profile, postbit_classic, showthread, sheet, application, inventory и achievements. AdvancedAppearance может частично перекрывать эти значения пресетом без сброса уже сохранённых setting values.</p>';
        echo '</div>';

        echo '<table class="table table-bordered">';

        echo '<tr><th colspan="2">Общие</th></tr>';
        echo '<tr><td style="width:260px;"><strong>Включить AdvancedProfileUI</strong></td><td><select name="af_' . AF_APUI_ID . '_enabled"><option value="1"' . ($enabled === '1' ? ' selected' : '') . '>Да</option><option value="0"' . ($enabled === '0' ? ' selected' : '') . '>Нет</option></select></td></tr>';

        echo '<tr><th colspan="2">member_profile</th></tr>';
        echo '<tr><td style="width:260px;"><strong>Фон body (большое изображение)</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_member_profile_body_cover_url" value="' . $values['af_' . AF_APUI_ID . '_member_profile_body_cover_url'] . '"></td></tr>';
        echo '<tr><td><strong>Фон body (бесшовная плитка)</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_member_profile_body_tile_url" value="' . $values['af_' . AF_APUI_ID . '_member_profile_body_tile_url'] . '"></td></tr>';
        echo '<tr><td><strong>Режим фона body</strong></td><td><select name="af_' . AF_APUI_ID . '_member_profile_body_bg_mode"><option value="cover"' . ($mode === 'cover' ? ' selected' : '') . '>cover</option><option value="tile"' . ($mode === 'tile' ? ' selected' : '') . '>tile</option></select></td></tr>';
        echo '<tr><td><strong>Оверлей фона body</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_member_profile_body_overlay" value="' . $values['af_' . AF_APUI_ID . '_member_profile_body_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Баннер по умолчанию</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_profile_banner_url" value="' . $values['af_' . AF_APUI_ID . '_profile_banner_url'] . '"></td></tr>';
        echo '<tr><td><strong>Оверлей баннера</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_profile_banner_overlay" value="' . $values['af_' . AF_APUI_ID . '_profile_banner_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Пользовательский CSS</strong></td><td><textarea name="af_' . AF_APUI_ID . '_member_profile_css" rows="8" style="width:100%;">' . $values['af_' . AF_APUI_ID . '_member_profile_css'] . '</textarea></td></tr>';

        echo '<tr><th colspan="2">postbit_classic</th></tr>';
        echo '<tr><td><strong>Фон профиля по умолчанию</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_author_bg_url" value="' . $values['af_' . AF_APUI_ID . '_postbit_author_bg_url'] . '"></td></tr>';
        echo '<tr><td><strong>Оверлей фона профиля</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_author_overlay" value="' . $values['af_' . AF_APUI_ID . '_postbit_author_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Фон никнейма по умолчанию</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_name_bg_url" value="' . $values['af_' . AF_APUI_ID . '_postbit_name_bg_url'] . '"></td></tr>';
        echo '<tr><td><strong>Оверлей никнейма</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_name_overlay" value="' . $values['af_' . AF_APUI_ID . '_postbit_name_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Фон нижней плашки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_bg_url" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_bg_url'] . '"></td></tr>';
        echo '<tr><td><strong>Оверлей нижней плашки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_overlay" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>URL картинки медиа-блока</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_media_image_url" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_media_image_url'] . '"></td></tr>';
        echo '<tr><td><strong>Fallback icon class медиа-блока</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_media_icon_class" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_media_icon_class'] . '"></td></tr>';
        echo '<tr><td><strong>Overlay медиа-блока</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_media_overlay" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_media_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Дополнительный CSS медиа-блока</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_media_css" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_media_css'] . '"></td></tr>';
        echo '<tr><td><strong>Заголовок плашки по умолчанию</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_title_default" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_title_default'] . '"></td></tr>';
        echo '<tr><td><strong>Подзаголовок плашки по умолчанию</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_subtitle_default" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_subtitle_default'] . '"></td></tr>';
        echo '<tr><td><strong>URL иконки плашки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_icon_url" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_icon_url'] . '"></td></tr>';
        echo '<tr><td><strong>Fallback-символ иконки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_icon_glyph" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_icon_glyph'] . '"></td></tr>';
        echo '<tr><td><strong>Фон контейнера иконки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_icon_bg" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_icon_bg'] . '"></td></tr>';
        echo '<tr><td><strong>Overlay контейнера иконки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_icon_overlay" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_icon_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Рамка контейнера иконки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_icon_border" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_icon_border'] . '"></td></tr>';
        echo '<tr><td><strong>Цвет fallback-иконки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_icon_color" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_icon_color'] . '"></td></tr>';
        echo '<tr><td><strong>Размер иконки</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_postbit_plaque_icon_size" value="' . $values['af_' . AF_APUI_ID . '_postbit_plaque_icon_size'] . '"></td></tr>';
        echo '<tr><td><strong>Пользовательский CSS</strong></td><td><textarea name="af_' . AF_APUI_ID . '_postbit_css" rows="10" style="width:100%;">' . $values['af_' . AF_APUI_ID . '_postbit_css'] . '</textarea></td></tr>';

        echo '<tr><th colspan="2">showthread</th></tr>';
        echo '<tr><td><strong>Фон body (большое изображение)</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_thread_body_cover_url" value="' . $values['af_' . AF_APUI_ID . '_thread_body_cover_url'] . '"></td></tr>';
        echo '<tr><td><strong>Фон body (бесшовная плитка)</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_thread_body_tile_url" value="' . $values['af_' . AF_APUI_ID . '_thread_body_tile_url'] . '"></td></tr>';
        echo '<tr><td><strong>Режим фона body</strong></td><td><select name="af_' . AF_APUI_ID . '_thread_body_bg_mode"><option value="cover"' . ($threadMode === 'cover' ? ' selected' : '') . '>cover</option><option value="tile"' . ($threadMode === 'tile' ? ' selected' : '') . '>tile</option></select></td></tr>';
        echo '<tr><td><strong>Оверлей фона body</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_thread_body_overlay" value="' . $values['af_' . AF_APUI_ID . '_thread_body_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Баннер темы по умолчанию</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_thread_banner_url" value="' . $values['af_' . AF_APUI_ID . '_thread_banner_url'] . '"></td></tr>';
        echo '<tr><td><strong>Оверлей баннера темы</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_thread_banner_overlay" value="' . $values['af_' . AF_APUI_ID . '_thread_banner_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Пользовательский CSS</strong></td><td><textarea name="af_' . AF_APUI_ID . '_thread_css" rows="8" style="width:100%;">' . $values['af_' . AF_APUI_ID . '_thread_css'] . '</textarea></td></tr>';

        echo '<tr><th colspan="2">character sheet</th></tr>';
        echo '<tr><td><strong>Background image URL</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_sheet_bg_url" value="' . $values['af_' . AF_APUI_ID . '_sheet_bg_url'] . '"></td></tr>';
        echo '<tr><td><strong>Background overlay</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_sheet_bg_overlay" value="' . $values['af_' . AF_APUI_ID . '_sheet_bg_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card background</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_sheet_panel_bg" value="' . $values['af_' . AF_APUI_ID . '_sheet_panel_bg'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card border</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_sheet_panel_border" value="' . $values['af_' . AF_APUI_ID . '_sheet_panel_border'] . '"></td></tr>';
        echo '<tr><td><strong>Custom CSS</strong></td><td><textarea name="af_' . AF_APUI_ID . '_sheet_css" rows="8" style="width:100%;">' . $values['af_' . AF_APUI_ID . '_sheet_css'] . '</textarea></td></tr>';

        echo '<tr><th colspan="2">application</th></tr>';
        echo '<tr><td><strong>Background image URL</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_application_bg_url" value="' . $values['af_' . AF_APUI_ID . '_application_bg_url'] . '"></td></tr>';
        echo '<tr><td><strong>Background overlay</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_application_bg_overlay" value="' . $values['af_' . AF_APUI_ID . '_application_bg_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card background</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_application_panel_bg" value="' . $values['af_' . AF_APUI_ID . '_application_panel_bg'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card border</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_application_panel_border" value="' . $values['af_' . AF_APUI_ID . '_application_panel_border'] . '"></td></tr>';
        echo '<tr><td><strong>Custom CSS</strong></td><td><textarea name="af_' . AF_APUI_ID . '_application_css" rows="8" style="width:100%;">' . $values['af_' . AF_APUI_ID . '_application_css'] . '</textarea></td></tr>';

        echo '<tr><th colspan="2">inventory</th></tr>';
        echo '<tr><td><strong>Background image URL</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_inventory_bg_url" value="' . $values['af_' . AF_APUI_ID . '_inventory_bg_url'] . '"></td></tr>';
        echo '<tr><td><strong>Background overlay</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_inventory_bg_overlay" value="' . $values['af_' . AF_APUI_ID . '_inventory_bg_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card background</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_inventory_panel_bg" value="' . $values['af_' . AF_APUI_ID . '_inventory_panel_bg'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card border</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_inventory_panel_border" value="' . $values['af_' . AF_APUI_ID . '_inventory_panel_border'] . '"></td></tr>';
        echo '<tr><td><strong>Custom CSS</strong></td><td><textarea name="af_' . AF_APUI_ID . '_inventory_css" rows="8" style="width:100%;">' . $values['af_' . AF_APUI_ID . '_inventory_css'] . '</textarea></td></tr>';

        echo '<tr><th colspan="2">achievements</th></tr>';
        echo '<tr><td><strong>Background image URL</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_achievements_bg_url" value="' . $values['af_' . AF_APUI_ID . '_achievements_bg_url'] . '"></td></tr>';
        echo '<tr><td><strong>Background overlay</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_achievements_bg_overlay" value="' . $values['af_' . AF_APUI_ID . '_achievements_bg_overlay'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card background</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_achievements_panel_bg" value="' . $values['af_' . AF_APUI_ID . '_achievements_panel_bg'] . '"></td></tr>';
        echo '<tr><td><strong>Panel/card border</strong></td><td><input type="text" class="text_input" style="width:100%;" name="af_' . AF_APUI_ID . '_achievements_panel_border" value="' . $values['af_' . AF_APUI_ID . '_achievements_panel_border'] . '"></td></tr>';
        echo '<tr><td><strong>Custom CSS</strong></td><td><textarea name="af_' . AF_APUI_ID . '_achievements_css" rows="8" style="width:100%;">' . $values['af_' . AF_APUI_ID . '_achievements_css'] . '</textarea></td></tr>';

        echo '</table>';

        echo '<div style="margin-top:12px;">';
        echo '<button type="submit" class="button button_yes"><span class="text">Сохранить настройки</span></button>';
        echo '</div>';
        echo '</form>';
    }
}
