<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__ . '/css.php';
require_once __DIR__ . '/effects.php';

function af_advancedelementtheme_install(): bool
{
    af_elementtheme_ensure_schema();
    af_elementtheme_invalidate();
    return true;
}
function af_advancedelementtheme_activate(): bool { return af_advancedelementtheme_install(); }
function af_advancedelementtheme_upgrade(): bool { return af_advancedelementtheme_install(); }
// Deactivation/uninstallation deliberately retain presentation metadata. No templates are owned here.
function af_advancedelementtheme_uninstall(): void { af_elementtheme_invalidate(); }

function af_elementtheme_ensure_schema(): void
{
    global $db;
    $collation = $db->build_create_table_collation();
    if (!$db->table_exists('af_element_theme_styles')) {
        $db->write_query('CREATE TABLE ' . TABLE_PREFIX . 'af_element_theme_styles (
            element_key varchar(190) NOT NULL,
            palette_json text NOT NULL,
            PRIMARY KEY (element_key)
        ) ENGINE=InnoDB ' . $collation);
    }
    if (!$db->table_exists('af_element_theme_surfaces')) {
        $db->write_query('CREATE TABLE ' . TABLE_PREFIX . 'af_element_theme_surfaces (
            element_key varchar(190) NOT NULL,
            surface varchar(24) NOT NULL,
            palette_json text NOT NULL,
            PRIMARY KEY (element_key, surface)
        ) ENGINE=InnoDB ' . $collation);
    }
}

function af_elementtheme_normalize_key(string $value): string
{
    $value = strtolower(trim($value));
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,189}$/D', $value) ? $value : '';
}

/** Load declarations only, never KB init, lifecycle or frontend hooks/assets. */
function af_elementtheme_load_kb_api(): bool
{
    if (function_exists('af_kb_get_public_type_options')) return true;
    $bootstrap = function_exists('af_get_addon_bootstrap_path') ? af_get_addon_bootstrap_path('knowledgebase') : null;
    if ($bootstrap && is_readable($bootstrap)) require_once $bootstrap;
    return function_exists('af_kb_get_public_type_options');
}

/** Active public KB entries only; request-local identity and availability are separate. */
function af_elementtheme_get_elements(): array
{
    if (isset($GLOBALS['af_elementtheme_elements'])) return $GLOBALS['af_elementtheme_elements'];
    $elements = [];
    $GLOBALS['af_elementtheme_registry_status'] = ['loaded' => false, 'error' => ''];
    try {
        if (!af_elementtheme_load_kb_api()) throw new RuntimeException('KB public API is unavailable');
        if (function_exists('af_kb_public_type_options_available') && !af_kb_public_type_options_available()) {
            throw new RuntimeException('KB public registry storage is unavailable');
        }
        foreach (af_kb_get_public_type_options('arpg_element', 1000) as $row) {
            $key = af_elementtheme_normalize_key((string)($row['key'] ?? ''));
            if ($key !== '') $elements[$key] = $row;
        }
        $GLOBALS['af_elementtheme_registry_status']['loaded'] = true;
    } catch (Throwable $e) {
        $elements = [];
        $GLOBALS['af_elementtheme_registry_status']['error'] = $e->getMessage();
        error_log('[AF ElementTheme] KB registry: ' . $e->getMessage());
    }
    return $GLOBALS['af_elementtheme_elements'] = $elements;
}
function af_elementtheme_registry_status(): array
{
    af_elementtheme_get_elements();
    return $GLOBALS['af_elementtheme_registry_status'];
}

/** Compatibility reads only. A real KB key always takes precedence over an alias. */
function af_elementtheme_aliases(): array { return ['dark' => 'shadow']; }
function af_elementtheme_resolve_key(string $value): string
{
    $key = af_elementtheme_normalize_key($value);
    $elements = af_elementtheme_get_elements();
    if (isset($elements[$key])) return $key;
    $key = af_elementtheme_aliases()[$key] ?? '';
    return isset($elements[$key]) ? $key : '';
}
/** Presentation eligibility delegates approval to the existing character provider. */
function af_elementtheme_resolve_surface_key(int $uid, string $surface, string $value, int $tid = 0): string
{
    if ($surface === 'application') return af_elementtheme_resolve_key($value);
    if ($uid <= 0 || !in_array($surface, ['profile', 'postbit', 'sheet'], true)
        || !function_exists('af_characterworkflow_resolve_active_application')
        || !function_exists('af_apui_is_approved_character_application')) return '';
    $active = af_characterworkflow_resolve_active_application($uid);
    $activeTid = (int)($active['tid'] ?? 0);
    if ($activeTid <= 0 || ($tid > 0 && $tid !== $activeTid)
        || !af_apui_is_approved_character_application($uid, $activeTid, (array)($active['relation'] ?? []))) return '';
    return af_elementtheme_resolve_key($value);
}
/** Batch existing workflow rows, never store permissions in the persistent theme cache. */
function af_elementtheme_preload_surface_contexts(array $uids): void
{
    if (!function_exists('af_characterworkflow_resolve_active_application') || !function_exists('af_cwf_preload_rows')) return;
    $tids = [];
    foreach ($uids as $uid) {
        $active = af_characterworkflow_resolve_active_application((int)$uid);
        if ((int)($active['tid'] ?? 0) > 0) $tids[] = (int)$active['tid'];
    }
    af_cwf_preload_rows($tids);
}
function af_elementtheme_is_known_key(string $key): bool
{
    return isset(af_elementtheme_get_elements()[$key]);
}
function af_elementtheme_surfaces(): array { return ['application', 'sheet', 'postbit', 'profile']; }

/** Renderer fact: call on the full-page/modal caller, without owning its templates. */
function af_elementtheme_mark_surface(string $surface, string $key = ''): void
{
    if (!in_array($surface, af_elementtheme_surfaces(), true)) return;
    $GLOBALS['af_elementtheme_rendered_surfaces'][$surface] = true;
    if ($key !== '') $GLOBALS['af_elementtheme_surface_keys'][$surface][$key] = true;
}

/** Compatibility for callers of the original four-token API. */
function af_elementtheme_validate_palette(array $palette): array
{
    return af_elementtheme_palette_from_variables(af_elementtheme_normalize_metadata($palette)['variables']);
}
function af_elementtheme_standard_tokens(): array { return ['main', 'accent', 'soft', 'border', 'contrast']; }
function af_elementtheme_palette_from_variables(array $variables): array
{
    $palette = [];
    foreach ($variables as $name => $value) {
        $token = substr($name, strlen('--af-element-'));
        $palette[in_array($token, af_elementtheme_standard_tokens(), true) ? $token : $name] = $value;
    }
    return $palette;
}
function af_elementtheme_variables_from_palette(array $palette): array
{
    $variables = [];
    foreach ($palette as $token => $value) $variables[str_starts_with($token, '--af-element-') ? $token : '--af-element-' . $token] = $value;
    return $variables;
}

/** JSON adapter: old palette maps remain readable without any table migration. */
function af_elementtheme_normalize_metadata(array $data): array
{
    $variables = array_key_exists('variables', $data) ? $data['variables'] : af_elementtheme_variables_from_palette(array_diff_key($data, ['effects' => true, 'custom_css' => true]));
    if (!is_array($variables)) throw new InvalidArgumentException('Variables должны быть объектом.');
    $out = [];
    foreach ($variables as $name => $value) {
        if (!is_string($name) || !preg_match('/^--af-element-[a-z][a-z0-9_-]*$/D', $name)) throw new InvalidArgumentException('Недопустимое имя CSS variable. Разрешены --af-element-*.');
        if (!is_scalar($value)) throw new InvalidArgumentException('Недопустимое значение CSS variable.');
        $value = trim((string)$value);
        if ($value === '') continue;
        af_elementtheme_validate_value($value);
        $out[$name] = $value;
    }
    $customCss = $data['custom_css'] ?? '';
    if (!is_string($customCss)) throw new InvalidArgumentException('Custom CSS должен быть строкой.');
    af_elementtheme_validate_custom_css($customCss);
    $metadata = ['variables' => $out, 'custom_css' => trim($customCss)];
    if (isset($data['effects'])) {
        if (!is_array($data['effects'])) throw new InvalidArgumentException('Недопустимые настройки эффекта.');
        $metadata['effects'] = af_elementtheme_normalize_effects($data['effects']);
    }
    return $metadata;
}

/** Source file is the default registry of styles, including unbound legacy keys. */
function af_elementtheme_default_styles(): array
{
    if (isset($GLOBALS['af_elementtheme_defaults'])) return $GLOBALS['af_elementtheme_defaults'];
    $styles = [];
    $css = (string)@file_get_contents(__DIR__ . '/assets/element-theme.css');
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER);
    foreach ($blocks as $block) {
        preg_match_all('/\[data-element="([a-z0-9_-]+)"\]/', $block[1], $keys);
        preg_match_all('/--af-element-([a-z][a-z0-9_-]*)\s*:\s*([^;]+);/', $block[2], $tokens, PREG_SET_ORDER);
        $palette = [];
        foreach ($tokens as $token) $palette[in_array($token[1], af_elementtheme_standard_tokens(), true) ? $token[1] : '--af-element-' . $token[1]] = trim($token[2]);
        foreach ($keys[1] as $key) $styles[$key] = $palette;
    }
    return $GLOBALS['af_elementtheme_defaults'] = $styles;
}

/** Cache only presentation metadata; KB changes take effect on the next request. */
function af_elementtheme_overrides(): array
{
    global $db, $cache;
    if (isset($GLOBALS['af_elementtheme_overrides'])) return $GLOBALS['af_elementtheme_overrides'];
    $data = is_object($cache) ? $cache->read('af_elementtheme') : false;
    if (!is_array($data) || ($data['format'] ?? 0) !== 7 || !isset($data['styles'], $data['surfaces'], $data['css'], $data['effects'])) {
        $data = ['format' => 7, 'styles' => [], 'surfaces' => [], 'css' => ''];
        if (is_object($db)) {
            foreach (['styles', 'surfaces'] as $kind) {
                $table = 'af_element_theme_' . $kind;
                if (!$db->table_exists($table)) continue;
                $query = $db->simple_select($table);
                while ($row = $db->fetch_array($query)) {
                    $key = af_elementtheme_normalize_key((string)$row['element_key']);
                    if ($key === '') continue;
                    try { $palette = af_elementtheme_normalize_metadata((array)json_decode($row['palette_json'], true)); }
                    catch (InvalidArgumentException $e) { continue; }
                    if ($kind === 'styles') $data['styles'][$key] = $palette;
                    elseif (in_array($row['surface'], af_elementtheme_surfaces(), true)) $data['surfaces'][$key][$row['surface']] = $palette;
                }
            }
        }
        $data['css'] = af_elementtheme_compile_overrides($data);
        $data['effects'] = af_elementtheme_compile_effects($data['styles']);
        if (is_object($cache)) $cache->update('af_elementtheme', $data);
    }
    return $GLOBALS['af_elementtheme_overrides'] = $data;
}

function af_elementtheme_compile_overrides(array $data): string
{
    $order = '@layer af_elementtheme_surface_custom, af_elementtheme_global_custom;';
    $css = '';
    foreach ($data['styles'] as $key => $metadata) {
        $css .= af_elementtheme_compile_style($key, $metadata);
    }
    foreach ($data['surfaces'] as $key => $surfaces) {
        foreach ($surfaces as $surface => $metadata) $css .= af_elementtheme_compile_style($key, $metadata, $surface);
    }
    return $css === '' ? '' : $order . $css;
}
function af_elementtheme_compile_style(string $key, array $metadata, string $surface = ''): string
{
    if (af_elementtheme_normalize_key($key) !== $key || ($surface !== '' && !in_array($surface, af_elementtheme_surfaces(), true))) throw new InvalidArgumentException('Недопустимый CSS scope.');
    $metadata = af_elementtheme_normalize_metadata($metadata);
    $selector = '[data-element="' . $key . '"]' . ($surface === '' ? '' : '[data-element-surface="' . $surface . '"]');
    $declarations = '';
    foreach ($metadata['variables'] as $name => $value) $declarations .= $name . ':' . preg_replace('/\s*!\s*important\s*$/i', '', $value) . ' !important;';
    $css = $declarations === '' ? '' : $selector . '{' . $declarations . '}';
    // Native scope confines complex selectors and :scope to the root/subtree and
    // stops at nested element roots. Conditional @rules remain inside this scope.
    // Important layers make explicitly authored ACP properties beat component rules,
    // including late/unlayered ATF CSS. Surface precedes global in important order.
    if ($metadata['custom_css'] !== '') {
        $layer = $surface === '' ? 'af_elementtheme_global_custom' : 'af_elementtheme_surface_custom';
        $css .= '@layer ' . $layer . '{@scope (' . $selector . ':not([data-af-element-effect-only])) to (:scope [data-element]) {' . af_elementtheme_prioritize_custom_css($metadata['custom_css']) . '}}';
    }
    return $css;
}
function af_elementtheme_get_metadata(string $key, string $surface = ''): array
{
    $key = af_elementtheme_normalize_key($key);
    $stored = af_elementtheme_overrides();
    return ($surface === '' ? ($stored['styles'][$key] ?? null) : ($stored['surfaces'][$key][$surface] ?? null))
        ?? ['variables' => [], 'custom_css' => ''];
}
function af_elementtheme_get_variables(string $key, string $surface = ''): array
{
    $key = af_elementtheme_normalize_key($key);
    $variables = array_replace(af_elementtheme_variables_from_palette(af_elementtheme_default_styles()[$key] ?? []), af_elementtheme_get_metadata($key)['variables']);
    return $surface === '' ? $variables : array_replace($variables, af_elementtheme_get_metadata($key, $surface)['variables']);
}
/** Keep the public palette lookup shape used by existing consumers. */
function af_elementtheme_get_style(string $key): array
{
    return af_elementtheme_palette_from_variables(af_elementtheme_get_variables($key));
}
function af_elementtheme_get_surface_style(string $key, string $surface): array
{
    return af_elementtheme_palette_from_variables(af_elementtheme_get_variables($key, $surface));
}
function af_elementtheme_get_rows(): array
{
    $elements = af_elementtheme_get_elements();
    $loaded = af_elementtheme_registry_status()['loaded'];
    $stored = af_elementtheme_overrides();
    $keys = array_unique(array_merge(array_keys($elements), array_keys(af_elementtheme_default_styles()), array_keys($stored['styles']), array_keys($stored['surfaces'])));
    $rows = [];
    foreach ($keys as $key) {
        $bound = isset($elements[$key]);
        $style = af_elementtheme_get_style($key);
        $metadata = af_elementtheme_get_metadata($key);
        $hasStyle = (bool)$style || isset($metadata['effects']) || $metadata['custom_css'] !== '' || !empty($stored['surfaces'][$key]);
        $alias = !$bound ? (af_elementtheme_aliases()[$key] ?? '') : '';
        $state = !$loaded ? 'KB unavailable' : ($alias !== '' ? 'Alias → ' . $alias : ($bound ? ($hasStyle ? 'Bound' : 'Missing style') : 'Legacy / unbound'));
        $rows[$key] = ['key' => $key, 'element' => $elements[$key] ?? [], 'in_kb' => $loaded ? $bound : null, 'has_style' => $hasStyle, 'alias' => $alias,
            'kind' => !$loaded ? 'KB binding unavailable' : ($bound ? 'Canonical element' : ($alias !== '' ? 'Legacy alias' : 'Legacy standalone/unbound style')), 'state' => $state];
    }
    return $rows;
}
function af_elementtheme_invalidate(): void
{
    global $cache;
    unset($GLOBALS['af_elementtheme_overrides']);
    if (is_object($cache)) $cache->delete('af_elementtheme');
}
function af_elementtheme_save_style(string $key, array $palette, string $surface = ''): void
{
    global $db;
    $key = af_elementtheme_normalize_key($key);
    if ($key === '' || ($surface !== '' && !in_array($surface, af_elementtheme_surfaces(), true))) throw new InvalidArgumentException('Недопустимый key/surface.');
    // Existing unbound styles remain editable, but aliases are never new identities.
    if (!af_elementtheme_registry_status()['loaded']) throw new InvalidArgumentException('Не удалось загрузить реестр arpg_element из Knowledge Base. Сохранение недоступно.');
    $canonical = af_elementtheme_resolve_key($key);
    if ($canonical !== '') $key = $canonical;
    elseif (!isset(af_elementtheme_get_rows()[$key])) throw new InvalidArgumentException('Создайте элемент в KB.');
    if ($surface === '' && !array_key_exists('effects', $palette)) {
        $existing = af_elementtheme_get_metadata($key);
        if (isset($existing['effects'])) $palette['effects'] = $existing['effects'];
    }
    $palette = af_elementtheme_normalize_metadata($palette);
    try { $json = json_encode($palette, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new InvalidArgumentException('Стиль содержит недопустимый UTF-8.'); }
    if (strlen($json) > 65535) throw new InvalidArgumentException('Стиль превышает максимальный размер metadata (65 KB).');
    $table = $surface === '' ? 'af_element_theme_styles' : 'af_element_theme_surfaces';
    $where = "element_key='" . $db->escape_string($key) . "'";
    if ($surface !== '') $where .= " AND surface='" . $db->escape_string($surface) . "'";
    $db->delete_query($table, $where);
    if ($palette['variables'] || $palette['custom_css'] !== '' || isset($palette['effects'])) {
        $row = ['element_key' => $db->escape_string($key), 'palette_json' => $db->escape_string($json)];
        if ($surface !== '') $row['surface'] = $surface;
        $db->insert_query($table, $row);
    }
    af_elementtheme_invalidate();
}

/** Response-aware owner delivery, also covers existing templates without surface attributes. */
function af_advancedelementtheme_pre_output(string &$page): void
{
    $hasSurface = !empty($GLOBALS['af_elementtheme_rendered_surfaces']) || !empty($GLOBALS['af_charactersheets_has_frontend_component']) || (bool)preg_match('/\bclass=["\'][^"\']*\b(?:af-atf-display|af-cs-page|atf-post|atf-profile|af-apui-profile-page|af-kb-char-element)\b/', $page);
    if (!$hasSurface || !function_exists('af_frontend_asset_allowed')
        || !af_frontend_asset_allowed('advancedelementtheme', 'pre_output', null, ['has_element_surface' => true])) return;
    // Resolve once from the viewed owner's approved provider, not competing globals.
    $profileUid = (int)($GLOBALS['memprofile']['uid'] ?? 0);
    $profileKey = $profileUid > 0 && function_exists('af_apui_profile_element_key')
        ? af_apui_profile_element_key($profileUid)
        : ($GLOBALS['atf_profile_context']['appearance']['element_theme_key'] ?? $GLOBALS['af_apui_profile_element'] ?? null);
    if ($profileUid > 0 && $profileKey !== null) {
        $GLOBALS['af_apui_profile_element'] = htmlspecialchars_uni($profileKey);
        if (isset($GLOBALS['atf_profile_context'])) $GLOBALS['atf_profile_context']['appearance']['element_theme_key'] = $profileKey;
    }
    // Old DB templates receive attributes at output time, without changing template ownership/backups.
    $page = preg_replace_callback('/<(?:div|article|main)\b[^>]*\bclass=["\'][^"\']*\b(?:af-atf-display|af-cs-page|atf-post|atf-profile|af-apui-profile-page)\b[^"\']*["\'][^>]*>/i', static function ($match) use ($profileKey, $profileUid) {
        $tag = $match[0];
        preg_match('/\bclass=(["\'])(.*?)\1/i', $tag, $class);
        $classes = preg_split('/\s+/', trim($class[2] ?? ''));
        if (!array_intersect($classes, ['af-cs-page', 'atf-post', 'atf-profile', 'af-apui-profile-page', 'af-atf-display'])) return $tag;
        $surface = in_array('af-cs-page', $classes, true) ? 'sheet' : (in_array('atf-post', $classes, true) ? 'postbit' : (array_intersect($classes, ['atf-profile', 'af-apui-profile-page']) ? 'profile' : 'application'));
        $hasElement = preg_match('/\bdata-element=(["\'])(.*?)\1/', $tag, $currentElement);
        if ($surface === 'profile' && $profileKey !== null && ($profileUid > 0 || isset($GLOBALS['atf_profile_context']['appearance']['element_theme_key']) || !$hasElement || $currentElement[2] === '')) {
            $attribute = 'data-element="' . af_elementtheme_resolve_key((string)$profileKey) . '"';
            $tag = preg_match('/\bdata-element=(["\'])(.*?)\1/', $tag) ? preg_replace('/\bdata-element=(["\'])(.*?)\1/', $attribute, $tag) : substr($tag, 0, -1) . ' ' . $attribute . '>';
        }
        if (!str_contains($tag, 'data-element-surface=')) $tag = substr($tag, 0, -1) . ' data-element-surface="' . $surface . '">';
        return preg_replace_callback('/\bdata-element=(["\'])(.*?)\1/', static fn($m) => 'data-element="' . af_elementtheme_resolve_key($m[2]) . '"', $tag) ?? $tag;
    }, $page) ?? $page;
    // Approved ATF/APUI context is copied to the profile page host, never viewer data.
    if ($profileKey !== null) {
        $page = preg_replace_callback('/<body\b[^>]*>/i', static function ($match) use ($profileKey) {
            $tag = $match[0];
            if (!preg_match('/\bclass=(["\'])(.*?)\1/i', $tag, $class)) return $tag;
            $classes = preg_split('/\s+/', trim($class[2]));
            if (!in_array('af-apui-member-profile-page', $classes, true)) return $tag;
            $tag = preg_replace('/\sdata-(?:element(?:-surface)?|af-element-effect-(?:host|only))(?:=(["\']).*?\1)?(?=\s|>)/i', '', $tag);
            return substr($tag, 0, -1) . ' data-element="' . af_elementtheme_resolve_key((string)$profileKey) . '" data-element-surface="profile" data-af-element-effect-host="profile-page" data-af-element-effect-only>';
        }, $page) ?? $page;
    }
    if (stripos($page, '</head>') === false) return;
    $base = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/element-theme.css';
    $version = (string)(@filemtime(__DIR__ . '/assets/element-theme.css') ?: '1');
    $html = preg_match('/<link\b[^>]*\sdata-af-element-theme(?:\s|>|=)/i', $page) ? '' : '<link rel="stylesheet" href="' . htmlspecialchars_uni($base . '?v=' . $version) . '" data-af-element-theme>';
    $css = af_elementtheme_overrides()['css'];
    if ($css !== '' && !preg_match('/<style\b[^>]*\sdata-af-element-theme-overrides(?:\s|>|=)/i', $page)) $html .= '<style data-af-element-theme-overrides>' . $css . '</style>';
    $effects = af_elementtheme_overrides()['effects'];
    if ($effects['settings'] && af_elementtheme_effects_needed($page, $effects['settings']) && !str_contains($page, 'data-af-element-effects-config')) {
        $assetRoot = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/';
        $effectVersion = (string)max((int)filemtime(__DIR__ . '/assets/element-effects.css'), (int)filemtime(__DIR__ . '/assets/element-effects.js'), (int)filemtime(__DIR__ . '/assets/element-canvas-engine.js'));
        $html .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($assetRoot . 'element-effects.css?v=' . $effectVersion) . '" data-af-element-effects>'
            . '<style data-af-element-effects-overrides>' . $effects['css'] . '</style>'
            . '<script type="application/json" data-af-element-effects-config>' . json_encode($effects['settings'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
            . '<script src="' . htmlspecialchars_uni($assetRoot . 'element-canvas-engine.js?v=' . $effectVersion) . '" defer></script>'
            . '<script src="' . htmlspecialchars_uni($assetRoot . 'element-effects.js?v=' . $effectVersion) . '" defer></script>';
    }
    if ($html === '') return;
    $page = preg_replace_callback('~</head>~i', static fn() => $html . "\n</head>", $page, 1) ?? $page;
}
