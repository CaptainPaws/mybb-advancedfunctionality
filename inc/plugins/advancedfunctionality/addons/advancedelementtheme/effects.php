<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_elementtheme_effect_presets(): array
{
    return ['stardust' => 'Звёздная пыль', 'embers' => 'Искры', 'mist' => 'Дымка', 'aura' => 'Сияние', 'electric' => 'Электрические импульсы', 'frost' => 'Кристаллы'];
}
function af_elementtheme_effect_defaults(string $key = ''): array
{
    // Presentation suggestions only, never an identity allow-list; all are opt-in.
    return ['enabled' => false, 'preset' => ['fire' => 'embers', 'shadow' => 'stardust', 'water' => 'mist'][$key] ?? 'aura',
        'intensity' => 50, 'speed' => 50, 'density' => 50, 'opacity' => 35, 'color' => '', 'surfaces' => ['profile', 'sheet']];
}
function af_elementtheme_normalize_effects(array $data): array
{
    $out = array_replace(af_elementtheme_effect_defaults(), $data);
    if (!in_array($out['enabled'], [true, false, 0, 1, '0', '1'], true)) throw new InvalidArgumentException('Недопустимый переключатель эффекта.');
    $out['enabled'] = (bool)$out['enabled'];
    if (!is_string($out['preset']) || !array_key_exists($out['preset'], af_elementtheme_effect_presets())) throw new InvalidArgumentException('Неизвестный пресет эффекта.');
    foreach (['intensity', 'speed', 'density', 'opacity'] as $field) {
        if (!is_scalar($out[$field]) || !preg_match('/^\d{1,3}$/D', (string)$out[$field]) || (int)$out[$field] > 100) throw new InvalidArgumentException('Параметры эффекта должны быть от 0 до 100.');
        $out[$field] = (int)$out[$field];
    }
    if (!is_string($out['color'])) throw new InvalidArgumentException('Недопустимый цвет эффекта.');
    $out['color'] = trim($out['color']);
    // Restrict overrides to literal CSS colors; auto follows the actual surface palette.
    if (strlen($out['color']) > 128 || ($out['color'] !== '' && !preg_match('/^(?:#[a-f0-9]{3,4}|#[a-f0-9]{6}|#[a-f0-9]{8}|(?:rgb|rgba|hsl|hsla)\([\d\s.,%+\/-]+\))$/iD', $out['color']))) throw new InvalidArgumentException('Цвет частиц: hex, RGB(A), HSL(A) или пустое значение для палитры.');
    if (!is_array($out['surfaces']) || array_filter($out['surfaces'], static fn($surface) => !is_string($surface) || !in_array($surface, af_elementtheme_surfaces(), true))) throw new InvalidArgumentException('Недопустимые поверхности эффекта.');
    $out['surfaces'] = array_values(array_unique($out['surfaces']));
    return array_intersect_key($out, af_elementtheme_effect_defaults());
}
function af_elementtheme_get_effects(string $key): array
{
    return af_elementtheme_get_metadata($key)['effects'] ?? af_elementtheme_effect_defaults($key);
}

function af_elementtheme_compile_effects(array $styles): array
{
    $css = ''; $settings = [];
    foreach ($styles as $key => $metadata) {
        $key = (string)$key;
        if (af_elementtheme_normalize_key($key) !== $key) throw new InvalidArgumentException('Недопустимый key эффекта.');
        $effect = isset($metadata['effects']) ? af_elementtheme_normalize_effects($metadata['effects']) : null;
        if (!$effect || !$effect['enabled'] || !$effect['surfaces']) continue;
        $settings[$key] = $effect;
        foreach ($effect['surfaces'] as $surface) {
            $root = '[data-element="' . $key . '"][data-element-surface="' . $surface . '"]';
            $color = $effect['color'] ?: 'var(--af-element-accent,var(--af-element-main,transparent))';
            // Static fallback/opacity only; particles and motion belong to the shared engine.
            $css .= '@scope (' . $root . ') to (:scope [data-element]) {'
                . ':scope[data-af-element-effect-host]>[data-af-element-effect],[data-af-element-effect-host]>[data-af-element-effect]{'
                . '--af-effect-color:' . $color . ';--af-effect-opacity:' . ($effect['opacity'] / 100) . ';}}' . "\n";
        }
    }
    return ['css' => $css, 'settings' => $settings];
}

/** Exact renderer facts first. Legacy DOM roots remain a compatibility fallback. */
function af_elementtheme_effects_needed(string $page, array $settings): bool
{
    foreach ($settings as $key => $effect) {
        // Unbound metadata never creates a frontend identity.
        if (af_elementtheme_resolve_key($key) !== $key) continue;
        foreach ($effect['surfaces'] as $surface) {
            if (!empty($GLOBALS['af_elementtheme_surface_keys'][$surface][$key])) return true;
            if ($surface === 'sheet' && !empty($GLOBALS['af_charactersheets_has_frontend_component'])) {
                foreach ($GLOBALS['af_elementtheme_surface_keys'] ?? [] as $keys) if (!empty($keys[$key])) return true;
            }
            preg_match_all('/<[^>]+\bdata-element=["\']' . preg_quote($key, '/') . '["\'][^>]*>/i', $page, $tags);
            foreach ($tags[0] as $tag) if (preg_match('/\bdata-element-surface=["\']' . $surface . '["\']/', $tag)) return true;
        }
    }
    return false;
}
