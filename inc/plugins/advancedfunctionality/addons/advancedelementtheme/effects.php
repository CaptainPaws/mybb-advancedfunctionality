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

function af_elementtheme_effect_points(): array
{
    return [[8,73],[19,28],[31,84],[43,13],[57,65],[69,37],[82,81],[94,19],[13,48],[38,51],[61,91],[88,55]];
}

/** Fixed non-repeating points: no particle DOM or JS animation loops. Max 12 (postbit/mobile 3). */
function af_elementtheme_effect_texture(int $density, bool $light = false): string
{
    $points = af_elementtheme_effect_points();
    $count = min($light ? 3 : 12, (int)ceil($density * ($light ? 3 : 12) / 100));
    $layers = [];
    foreach (array_slice($points, 0, $count) as $i => $point) {
        $layers[] = 'radial-gradient(circle at ' . $point[0] . '% ' . $point[1] . '%,var(--af-effect-color) 0 ' . ($i % 3 === 0 ? '1.6px' : '1px') . ',transparent 3px)';
    }
    return $layers ? implode(',', $layers) : 'none';
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
            $textures = [
                'mist' => 'radial-gradient(ellipse at 25% 70%,var(--af-effect-color),transparent 62%),radial-gradient(ellipse at 85% 25%,var(--af-effect-color),transparent 65%)',
                'aura' => 'conic-gradient(from 40deg at 70% 60%,transparent,var(--af-effect-color),transparent 55%,var(--af-effect-color),transparent)',
                'electric' => 'linear-gradient(115deg,transparent 38%,var(--af-effect-color) 38.2%,transparent 38.6%),linear-gradient(65deg,transparent 68%,var(--af-effect-color) 68.2%,transparent 68.6%)',
                'frost' => 'conic-gradient(from 45deg at 32% 35%,transparent 0 24%,var(--af-effect-color) 25%,transparent 26% 74%,var(--af-effect-color) 75%,transparent 76%)',
            ];
            $css .= '@scope (' . $root . ') to (:scope [data-element]) {'
                . '[data-af-element-effect-host]{--af-effect-enabled:1;}'
                . '[data-af-element-effect-host]>[data-af-element-effect]{'
                . '--af-effect-color:' . $color . ';--af-effect-opacity:' . ($effect['opacity'] / 100) . ';'
                . '--af-effect-strength:' . ($effect['intensity'] / 100) . ';--af-effect-duration:' . (32 - $effect['speed'] * .24) . 's;'
                . '--af-effect-points:' . af_elementtheme_effect_texture($effect['density'], $surface === 'postbit') . ';'
                . '--af-effect-light-points:' . af_elementtheme_effect_texture($effect['density'], true) . ';'
                . '--af-effect-field:' . (isset($textures[$effect['preset']]) ? $textures[$effect['preset']] . ($effect['preset'] === 'frost' ? ',var(--af-effect-points)' : '') : 'var(--af-effect-points)') . ';'
                . '--af-effect-animation:af-effect-' . $effect['preset'] . ';}}
';
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
