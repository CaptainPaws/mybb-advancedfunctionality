<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) { die('No direct access'); }

class AF_Admin_Advancedelementtheme
{
    private const BASE = 'index.php?module=advancedfunctionality&af_view=advancedelementtheme';
    private const TOKENS = ['main' => 'Основной цвет', 'accent' => 'Акцент', 'soft' => 'Мягкий фон', 'border' => 'Граница', 'contrast' => 'Контраст'];

    private static function escape($value): string { return htmlspecialchars_uni((string)$value); }
    private static function editorUrl(string $key, string $surface = ''): string
    {
        return self::BASE . '&action=edit&element_key=' . rawurlencode($key) . ($surface === '' ? '' : '&surface=' . rawurlencode($surface));
    }
    private static function submittedMetadata(): array
    {
        global $mybb;
        $variables = [];
        foreach (self::TOKENS as $token => $label) $variables['--af-element-' . $token] = $mybb->get_input($token);
        $names = $mybb->input['extra_names'] ?? [];
        $values = $mybb->input['extra_values'] ?? [];
        if (!is_array($names) || !is_array($values)) throw new InvalidArgumentException('Недопустимые дополнительные variables.');
        foreach ($names as $index => $name) {
            if (!is_scalar($name) || !is_scalar($values[$index] ?? '')) throw new InvalidArgumentException('Недопустимая variable.');
            $name = trim((string)$name); $value = trim((string)($values[$index] ?? ''));
            if ($name === '' && $value === '') continue;
            if (array_key_exists($name, $variables)) throw new InvalidArgumentException('Имя variable повторяется: ' . $name);
            $variables[$name] = $value;
        }
        return ['variables' => $variables, 'custom_css' => $mybb->get_input('custom_css')];
    }

    public static function dispatch(string $action = ''): string
    {
        global $mybb;
        $action = $action ?: $mybb->get_input('action');
        $key = af_elementtheme_normalize_key($mybb->get_input('element_key'));
        $surface = $mybb->get_input('surface');
        if ($surface !== 'effects' && !in_array($surface, af_elementtheme_surfaces(), true)) $surface = '';
        $error = ''; $submitted = null;
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $action = 'edit';
            try {
                if ($surface === 'effects') {
                    $submitted = af_elementtheme_get_metadata(af_elementtheme_resolve_key($key) ?: $key);
                    $submitted['effects'] = af_elementtheme_normalize_effects([
                        'enabled' => $mybb->get_input('effect_enabled') === '1', 'preset' => $mybb->get_input('effect_preset'),
                        'intensity' => $mybb->get_input('effect_intensity'), 'speed' => $mybb->get_input('effect_speed'),
                        'density' => $mybb->get_input('effect_density'), 'opacity' => $mybb->get_input('effect_opacity'),
                        'color' => $mybb->get_input('effect_color'), 'surfaces' => $mybb->input['effect_surfaces'] ?? [],
                    ]);
                } else $submitted = self::submittedMetadata();
                $target = af_elementtheme_resolve_key($key) ?: $key;
                af_elementtheme_save_style($key, $submitted, $surface === 'effects' ? '' : $surface);
                flash_message('Стиль сохранён.', 'success');
                admin_redirect(self::editorUrl($target, $surface));
            } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        $rows = af_elementtheme_get_rows();
        $status = af_elementtheme_registry_status();
        $header = '<h2>AdvancedElementTheme</h2>';
        if (!$status['loaded']) {
            $header .= '<div class="error" role="alert">Не удалось загрузить реестр arpg_element из Knowledge Base.</div>';
            if ($status['error'] !== '') $header .= '<p class="af-et-diagnostic">' . self::escape($status['error']) . '</p>';
        }
        if ($error !== '') $header .= '<div class="error" role="alert">' . self::escape($error) . '</div>';
        $assetRoot = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/';
        $css = '<link rel="stylesheet" href="' . self::escape($assetRoot . 'advancedelementtheme-admin.css?v=3') . '">';
        $js = '<script src="' . self::escape($assetRoot . 'element-canvas-engine.js?v=' . filemtime(__DIR__ . '/assets/element-canvas-engine.js')) . '" defer></script><script src="' . self::escape($assetRoot . 'advancedelementtheme-admin.js?v=4') . '" defer></script>';
        // AF has already output the ACP header before controller dispatch.
        $header = $css . $js . $header;
        if ($action === 'edit') {
            $html = $header . (isset($rows[$key]) ? self::editor($key, $surface, $rows[$key], $submitted) : '<p>Стиль не найден.</p><a href="' . self::escape(self::BASE . '&action=list') . '">← Назад к элементам</a>');
        } else {
            $html = $header . self::listing($rows);
        }
        $html = '<div class="af-et-admin">' . $html . '</div>';
        echo $html;
        return $html;
    }

    private static function listing(array $rows): string
    {
        global $mybb;
        $filter = $mybb->get_input('filter');
        $html = '<nav class="af-et-filters" aria-label="Фильтр элементов">';
        foreach (['' => 'Все', 'Bound' => 'Bound', 'legacy' => 'Legacy', 'Missing style' => 'Missing style'] as $value => $label) {
            $html .= '<a href="' . self::escape(self::BASE . '&action=list&filter=' . rawurlencode($value)) . '"' . ($filter === $value ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }
        $html .= '</nav><table class="general af-et-list"><thead><tr><th>Название KB</th><th>Key</th><th>KB</th><th>Style</th><th>Status</th><th>Palette preview</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $key => $row) {
            if ($filter === 'legacy' && !str_starts_with($row['state'], 'Legacy') && !str_starts_with($row['state'], 'Alias')) continue;
            if (in_array($filter, ['Bound', 'Missing style'], true) && $row['state'] !== $filter) continue;
            $title = $row['element']['label_ru'] ?? $row['element']['label_en'] ?? '—';
            $preview = '';
            foreach (array_slice(af_elementtheme_standard_tokens(), 0, 4) as $token) {
                $value = af_elementtheme_get_variables($key)['--af-element-' . $token] ?? '';
                $preview .= '<span class="af-et-swatch" title="' . self::escape($token . ': ' . ($value ?: 'neutral')) . '"' . ($value !== '' ? ' style="background:' . self::escape($value) . '"' : '') . '></span>';
            }
            $target = $row['alias'] !== '' && af_elementtheme_is_known_key($row['alias']) ? $row['alias'] : $key;
            $html .= '<tr><td>' . self::escape($title) . '</td><td>' . self::escape($key) . '<small>' . self::escape($row['kind']) . '</small></td><td>' . ($row['in_kb'] === null ? '—' : ($row['in_kb'] ? 'Да' : 'Нет')) . '</td><td>' . ($row['has_style'] ? 'Да' : 'Нет') . '</td><td>' . self::escape($row['state']) . '</td><td><div class="af-et-swatches">' . $preview . '</div></td><td><a href="' . self::escape(self::editorUrl($target)) . '">Изменить</a></td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function editor(string $key, string $surface, array $row, ?array $submitted): string
    {
        global $mybb;
        $metadata = $submitted ?? af_elementtheme_get_metadata($key, $surface);
        $inherited = $surface === '' ? af_elementtheme_variables_from_palette(af_elementtheme_default_styles()[$key] ?? []) : af_elementtheme_get_variables($key);
        $html = '<a class="af-et-back" href="' . self::escape(self::BASE . '&action=list') . '">← Назад к элементам</a><h3>' . self::escape($key) . '</h3><section class="af-et-kb"><h4>Knowledge Base</h4>';
        if ($row['in_kb'] === true) {
            $route = function_exists('af_kb_url') ? af_kb_url(['type' => 'arpg_element', 'key' => $key]) : 'kb.php?type=arpg_element&key=' . rawurlencode($key);
            $url = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/' . ltrim($route, '/');
            $html .= '<dl><dt>Название</dt><dd>' . self::escape($row['element']['label_ru'] ?? $row['element']['label_en'] ?? $key) . '</dd><dt>Type</dt><dd>arpg_element</dd><dt>Key</dt><dd>' . self::escape($key) . '</dd><dt>Status</dt><dd>' . self::escape($row['state']) . '</dd></dl><a href="' . self::escape($url) . '" target="_blank" rel="noopener">Открыть в Knowledge Base</a>';
        } elseif ($row['in_kb'] === null) $html .= '<p>Связь с KB временно недоступна. Status: KB unavailable.</p>';
        elseif ($row['alias'] !== '') $html .= '<p>Legacy alias. Status: ' . self::escape($row['state']) . '.</p><a href="' . self::escape(self::editorUrl($row['alias'], $surface)) . '">Открыть canonical style</a>';
        else $html .= '<p>Запись с key <code>' . self::escape($key) . '</code> пока отсутствует. Status: Legacy / unbound.</p>';
        $html .= '</section><nav class="af-et-tabs" aria-label="Surface">';
        foreach (['' => 'Global', 'application' => 'Application', 'sheet' => 'Sheet', 'postbit' => 'Postbit', 'profile' => 'Profile', 'effects' => 'Эффекты'] as $name => $label) {
            $html .= '<a class="af-et-tab' . ($surface === $name ? ' is-active' : '') . '" href="' . self::escape(self::editorUrl($key, $name)) . '"' . ($surface === $name ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }
        if ($surface === 'effects') return $html . '</nav>' . self::effectsEditor($key, $row, $submitted);
        $html .= '</nav><form class="af-et-editor" method="post" action="' . self::escape(self::editorUrl($key, $surface)) . '"><input type="hidden" name="my_post_key" value="' . self::escape($mybb->post_code ?? '') . '"><input type="hidden" name="element_key" value="' . self::escape($key) . '"><input type="hidden" name="surface" value="' . self::escape($surface) . '"><h4>' . ($surface === '' ? 'Global palette' : self::escape(ucfirst($surface)) . ' variables') . '</h4><p>Пустое значение: ' . ($surface === '' ? 'inherit default CSS' : 'inherit global') . '.</p>';
        foreach (self::TOKENS as $token => $label) {
            $name = '--af-element-' . $token; $value = $metadata['variables'][$name] ?? ''; $fallback = $inherited[$name] ?? '';
            $picker = self::hexColor($value ?: $fallback);
            $html .= '<div class="af-et-color-row"><label for="af-et-' . $token . '">' . $label . ' <code>' . $name . '</code></label><div><input id="af-et-' . $token . '" class="text_input" type="text" name="' . $token . '" value="' . self::escape($value) . '" placeholder="' . self::escape($fallback) . '" data-af-et-color-text><input type="color" value="' . ($picker ?: '#000000') . '" aria-label="' . $label . ' — color picker" data-af-et-color-picker' . ($picker === '' ? ' disabled title="Используйте text input для RGBA/HSL/functions"' : '') . '></div><small>Inherited: <code>' . self::escape($fallback ?: 'neutral / не задано') . '</code></small></div>';
        }
        $html .= '<h4>Дополнительные CSS variables</h4><p>Разрешены только имена <code>--af-element-*</code>.</p><table class="general af-et-extra"><thead><tr><th>Variable name</th><th>Value</th><th>Inherited</th><th></th></tr></thead><tbody data-af-et-extra-rows>';
        $extra = array_unique(array_merge(array_keys($metadata['variables']), array_keys($inherited)));
        foreach ($extra as $name) {
            if (array_key_exists(substr($name, strlen('--af-element-')), self::TOKENS)) continue;
            $html .= self::variableRow($name, $metadata['variables'][$name] ?? '', $inherited[$name] ?? '');
        }
        $html .= self::variableRow('', '', '') . '</tbody></table><button type="button" data-af-et-add-variable>+ Добавить переменную</button><template data-af-et-variable-template>' . self::variableRow('', '', '') . '</template><h4><label for="af-et-custom-css">Custom CSS</label></h4><p>CSS действует только в <code>[data-element="' . self::escape($key) . '"]' . ($surface === '' ? '' : '[data-element-surface="' . self::escape($surface) . '"]') . '</code>. Для root используйте <code>:scope</code>. Поддерживаются @media, @supports, @container; global definitions (@import, @font-face, @keyframes) запрещены.</p><textarea id="af-et-custom-css" name="custom_css" rows="18" spellcheck="false">' . self::escape($metadata['custom_css']) . '</textarea><button class="af-et-save" type="submit"' . ($row['in_kb'] === null ? ' disabled' : '') . '>Сохранить</button></form>';
        return $html;
    }
    private static function effectsEditor(string $key, array $row, ?array $submitted): string
    {
        global $mybb;
        $effect = $submitted['effects'] ?? af_elementtheme_get_effects($key);
        $assetRoot = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/';
        $html = '<link rel="stylesheet" href="' . self::escape($assetRoot . 'element-effects.css?v=2') . '"><form class="af-et-editor af-et-effects-editor" data-af-et-effects-editor method="post" action="' . self::escape(self::editorUrl($key, 'effects')) . '"><input type="hidden" name="my_post_key" value="' . self::escape($mybb->post_code ?? '') . '"><h4>Эффекты</h4><label><input type="checkbox" name="effect_enabled" value="1"' . ($effect['enabled'] ? ' checked' : '') . '> Включить анимацию</label><p>Эффект заполняет фон профиля, листа и анкеты. Postbit использует облегчённый бюджет частиц; при большом числе видимых эффектов качество адаптируется автоматически. При reduced motion остаётся статичная декорация.</p><label>Пресет анимации <select name="effect_preset">';
        foreach (af_elementtheme_effect_presets() as $name => $label) $html .= '<option value="' . $name . '"' . ($effect['preset'] === $name ? ' selected' : '') . '>' . self::escape($label) . '</option>';
        $html .= '</select></label>';
        foreach (['intensity' => 'Интенсивность', 'speed' => 'Скорость', 'density' => 'Плотность частиц', 'opacity' => 'Прозрачность (видимость)'] as $name => $label) {
            $html .= '<label class="af-et-effect-range">' . $label . ' <input type="range" name="effect_' . $name . '" min="0" max="100" value="' . $effect[$name] . '"><output>' . $effect[$name] . '%</output></label>';
        }
        $html .= '<label>Цвет частиц <input type="text" name="effect_color" value="' . self::escape($effect['color']) . '" placeholder="Автоматически из surface palette" data-af-et-effect-color><input type="color" data-af-et-effect-picker value="' . (self::hexColor($effect['color']) ?: '#ffffff') . '" aria-label="Цвет частиц"></label><small>Пустое поле — актуальный accent поверхности; поддерживаются hex, RGB(A), HSL(A).</small><fieldset><legend>Область применения</legend>';
        foreach (af_elementtheme_surfaces() as $surface) $html .= '<label><input type="checkbox" name="effect_surfaces[]" value="' . $surface . '"' . (in_array($surface, $effect['surfaces'], true) ? ' checked' : '') . '> ' . ucfirst($surface) . '</label> ';
        $html .= '</fieldset><h4>Предпросмотр</h4><label>Поверхность <select data-af-et-effect-preview-surface>';
        foreach (af_elementtheme_surfaces() as $surface) $html .= '<option value="' . $surface . '"' . ($surface === 'profile' ? ' selected' : '') . '>' . ucfirst($surface) . '</option>';
        $html .= '</select></label><p>Предпросмотр использует сохранённую палитру выбранной поверхности. Настройки эффектов обновляются до сохранения.</p><div class="af-et-effect-preview" data-af-et-effect-preview data-element="' . self::escape($key) . '" data-element-surface="profile"><div data-af-element-effect-host><span hidden data-af-element-effect aria-hidden="true"></span><strong>Страница персонажа</strong><p>Текст, аватар и кнопки остаются над декоративным слоем.</p><button type="button">Пример кнопки</button></div></div>';
        $palettes = [];
        foreach (af_elementtheme_surfaces() as $surface) $palettes[$surface] = af_elementtheme_get_variables($key, $surface);
        $html .= '<script type="application/json" data-af-et-effect-preview-data>' . json_encode(['palettes' => $palettes], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script><button class="af-et-save" type="submit"' . ($row['in_kb'] === null ? ' disabled' : '') . '>Сохранить эффекты</button></form>';
        return $html;
    }
    private static function hexColor(string $value): string
    {
        if (preg_match('/^#[a-f0-9]{6}$/iD', $value)) return $value;
        if (preg_match('/^#([a-f0-9])([a-f0-9])([a-f0-9])$/iD', $value, $m)) return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        return '';
    }
    private static function variableRow(string $name, string $value, string $inherited): string
    {
        return '<tr><td><input type="text" name="extra_names[]" value="' . self::escape($name) . '" placeholder="--af-element-glow"></td><td><input type="text" name="extra_values[]" value="' . self::escape($value) . '" placeholder="' . self::escape($inherited) . '"></td><td><code>' . self::escape($inherited ?: '—') . '</code></td><td><button type="button" data-af-et-remove-variable>Удалить</button></td></tr>';
    }
}
