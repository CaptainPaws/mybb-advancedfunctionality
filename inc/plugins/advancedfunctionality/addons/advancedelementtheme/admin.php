<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) { die('No direct access'); }

class AF_Admin_Advancedelementtheme
{
    public static function dispatch(string $action = ''): string
    {
        global $mybb;
        $error = '';
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            try {
                $palette = [];
                foreach (['main', 'accent', 'soft', 'border'] as $token) $palette[$token] = $mybb->get_input($token);
                af_elementtheme_save_style($mybb->get_input('element_key'), $palette, $mybb->get_input('surface'));
                flash_message('Палитра сохранена.', 'success');
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedelementtheme');
            } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        $escape = static fn($value) => htmlspecialchars_uni((string)$value);
        $base = 'index.php?module=advancedfunctionality&amp;af_view=advancedelementtheme';
        $html = '<h2>AdvancedElementTheme</h2><p>Identity: KB arpg_element. Пустое значение снимает override и использует базовую палитру.</p>';
        if ($error !== '') $html .= '<p class="error">' . $escape($error) . '</p>';
        $html .= '<table class="general"><thead><tr><th>Название KB</th><th>Key</th><th>В KB</th><th>Style</th><th>Состояние</th><th>Палитра</th></tr></thead><tbody>';
        $rows = af_elementtheme_get_rows();
        foreach ($rows as $key => $row) {
            $element = $row['element'];
            $title = $element['label_ru'] ?? $element['label_en'] ?? '—';
            $html .= '<tr><td>' . $escape($title) . '</td><td>' . $escape($key) . '</td><td>' . ($row['in_kb'] ? 'Да' : 'Нет') . '</td><td>' . ($row['has_style'] ? 'Да' : 'Нет') . '</td><td>' . $escape($row['state']) . '</td><td><a href="' . $base . '&amp;element_key=' . rawurlencode($key) . '">Изменить</a></td></tr>';
        }
        $html .= '</tbody></table>';
        $key = af_elementtheme_normalize_key($mybb->get_input('element_key'));
        if (isset($rows[$key])) {
            $surface = $mybb->get_input('surface');
            if (!in_array($surface, af_elementtheme_surfaces(), true)) $surface = '';
            $stored = af_elementtheme_overrides();
            $palette = $surface === '' ? ($stored['styles'][$key] ?? []) : ($stored['surfaces'][$key][$surface] ?? []);
            if ($error !== '') foreach (['main', 'accent', 'soft', 'border'] as $token) $palette[$token] = $mybb->get_input($token);
            $inherited = af_elementtheme_get_style($key);
            $html .= '<h3>' . $escape($key) . '</h3><p><a href="' . $base . '&amp;element_key=' . rawurlencode($key) . '">Global</a>';
            foreach (af_elementtheme_surfaces() as $name) $html .= ' | <a href="' . $base . '&amp;element_key=' . rawurlencode($key) . '&amp;surface=' . $name . '">' . $name . '</a>';
            $html .= '</p><form method="post" action="' . $base . '"><input type="hidden" name="my_post_key" value="' . $escape($mybb->post_code) . '"><input type="hidden" name="element_key" value="' . $escape($key) . '"><input type="hidden" name="surface" value="' . $escape($surface) . '"><p>Surface: ' . $escape($surface ?: 'global') . '</p>';
            foreach (['main' => 'Основной цвет', 'accent' => 'Акцент', 'soft' => 'Мягкий фон', 'border' => 'Граница'] as $token => $label) {
                $fallback = $surface === '' ? (af_elementtheme_default_styles()[$key][$token] ?? '') : ($inherited[$token] ?? '');
                $html .= '<p><label>' . $label . ' <input type="text" name="' . $token . '" value="' . $escape($palette[$token] ?? '') . '" placeholder="' . $escape($fallback) . '"></label></p>';
            }
            $html .= '<p>Форматы: #hex, rgb()/rgba(), hsl()/hsla(), transparent. Surface overrides необязательны.</p><button type="submit">Сохранить</button></form>';
        }
        echo $html;
        return $html;
    }
}
