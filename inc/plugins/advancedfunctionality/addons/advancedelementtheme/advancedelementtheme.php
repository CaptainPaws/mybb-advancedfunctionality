<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

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

/** Active public KB entries only; no persistent identity/title mirror. */
function af_elementtheme_get_elements(): array
{
    if (isset($GLOBALS['af_elementtheme_elements'])) return $GLOBALS['af_elementtheme_elements'];
    $elements = [];
    if (function_exists('af_kb_get_public_type_options')) {
        try {
            foreach (af_kb_get_public_type_options('arpg_element', 1000) as $row) {
                $key = af_elementtheme_normalize_key((string)($row['key'] ?? ''));
                if ($key !== '') $elements[$key] = $row;
            }
        } catch (Throwable $e) { $elements = []; }
    }
    return $GLOBALS['af_elementtheme_elements'] = $elements;
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
function af_elementtheme_is_known_key(string $key): bool
{
    return isset(af_elementtheme_get_elements()[$key]);
}
function af_elementtheme_surfaces(): array { return ['application', 'sheet', 'postbit', 'profile']; }

/** Safe color values only; never accept CSS declarations, URLs or arbitrary functions. */
function af_elementtheme_validate_palette(array $palette): array
{
    $out = [];
    foreach (['main', 'accent', 'soft', 'border'] as $token) {
        $value = trim((string)($palette[$token] ?? ''));
        if ($value === '') continue;
        if (!preg_match('/^(?:#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})|transparent|(?:rgb|rgba|hsl|hsla)\(\s*[0-9.%+,\s-]+\))$/iD', $value)) {
            throw new InvalidArgumentException('Недопустимый цвет: ' . $token);
        }
        $out[$token] = $value;
    }
    return $out;
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
        preg_match_all('/--af-element-(main|accent|soft|border)\s*:\s*([^;]+);/', $block[2], $tokens, PREG_SET_ORDER);
        $palette = [];
        foreach ($tokens as $token) $palette[$token[1]] = trim($token[2]);
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
    if (!is_array($data) || !isset($data['styles'], $data['surfaces'], $data['css'])) {
        $data = ['styles' => [], 'surfaces' => [], 'css' => ''];
        if (is_object($db)) {
            foreach (['styles', 'surfaces'] as $kind) {
                $table = 'af_element_theme_' . $kind;
                if (!$db->table_exists($table)) continue;
                $query = $db->simple_select($table);
                while ($row = $db->fetch_array($query)) {
                    $key = af_elementtheme_normalize_key((string)$row['element_key']);
                    if ($key === '') continue;
                    try { $palette = af_elementtheme_validate_palette((array)json_decode($row['palette_json'], true)); }
                    catch (InvalidArgumentException $e) { continue; }
                    if ($kind === 'styles') $data['styles'][$key] = $palette;
                    elseif (in_array($row['surface'], af_elementtheme_surfaces(), true)) $data['surfaces'][$key][$row['surface']] = $palette;
                }
            }
        }
        $data['css'] = af_elementtheme_compile_overrides($data);
        if (is_object($cache)) $cache->update('af_elementtheme', $data);
    }
    return $GLOBALS['af_elementtheme_overrides'] = $data;
}

function af_elementtheme_compile_overrides(array $data): string
{
    $css = '';
    foreach ($data['styles'] as $key => $palette) {
        $css .= af_elementtheme_css_rule('[data-element="' . $key . '"]', $palette);
    }
    foreach ($data['surfaces'] as $key => $surfaces) {
        foreach ($surfaces as $surface => $palette) {
            $css .= af_elementtheme_css_rule('[data-element="' . $key . '"][data-element-surface="' . $surface . '"]', $palette);
        }
    }
    return $css;
}
function af_elementtheme_css_rule(string $selector, array $palette): string
{
    if (!$palette) return '';
    $declarations = '';
    foreach ($palette as $token => $value) $declarations .= '--af-element-' . $token . ':' . $value . ';';
    return $selector . '{' . $declarations . '}';
}

/** Presentation lookup can inspect an unbound key; runtime identity still uses resolve_key(). */
function af_elementtheme_get_style(string $key): array
{
    $key = af_elementtheme_normalize_key($key);
    return array_replace(af_elementtheme_default_styles()[$key] ?? [], af_elementtheme_overrides()['styles'][$key] ?? []);
}
function af_elementtheme_get_surface_style(string $key, string $surface): array
{
    $key = af_elementtheme_normalize_key($key);
    return array_replace(af_elementtheme_get_style($key), af_elementtheme_overrides()['surfaces'][$key][$surface] ?? []);
}
function af_elementtheme_get_rows(): array
{
    $elements = af_elementtheme_get_elements();
    $keys = array_unique(array_merge(array_keys($elements), array_keys(af_elementtheme_default_styles()), array_keys(af_elementtheme_overrides()['styles']), array_keys(af_elementtheme_overrides()['surfaces'])));
    $rows = [];
    foreach ($keys as $key) {
        $bound = isset($elements[$key]);
        $style = af_elementtheme_get_style($key);
        $rows[$key] = ['key' => $key, 'element' => $elements[$key] ?? [], 'in_kb' => $bound, 'has_style' => (bool)$style,
            'state' => $bound ? ($style ? 'Bound' : 'Missing style') : 'Legacy / unbound'];
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
    $canonical = af_elementtheme_resolve_key($key);
    if ($canonical !== '') $key = $canonical;
    elseif (!isset(af_elementtheme_get_rows()[$key])) throw new InvalidArgumentException('Создайте элемент в KB.');
    $palette = af_elementtheme_validate_palette($palette);
    $table = $surface === '' ? 'af_element_theme_styles' : 'af_element_theme_surfaces';
    $where = "element_key='" . $db->escape_string($key) . "'";
    if ($surface !== '') $where .= " AND surface='" . $db->escape_string($surface) . "'";
    $db->delete_query($table, $where);
    if ($palette) {
        $row = ['element_key' => $db->escape_string($key), 'palette_json' => $db->escape_string(json_encode($palette))];
        if ($surface !== '') $row['surface'] = $surface;
        $db->insert_query($table, $row);
    }
    af_elementtheme_invalidate();
}

/** Response-aware owner delivery, also covers existing templates without surface attributes. */
function af_advancedelementtheme_pre_output(string &$page): void
{
    $hasSurface = !empty($GLOBALS['af_charactersheets_has_frontend_component']) || (bool)preg_match('/\bclass=["\'][^"\']*\b(?:af-atf-display|af-cs-page|atf-post|atf-profile|af-apui-profile-page|af-kb-char-element)\b/', $page);
    if (!$hasSurface || !function_exists('af_frontend_asset_allowed')
        || !af_frontend_asset_allowed('advancedelementtheme', 'pre_output', null, ['has_element_surface' => true])) return;
    // Old DB templates receive attributes at output time, without changing template ownership/backups.
    $page = preg_replace_callback('/<(?:div|article|main)\b[^>]*\bclass=["\'][^"\']*\b(?:af-atf-display|af-cs-page|atf-post|atf-profile|af-apui-profile-page)\b[^"\']*["\'][^>]*>/i', static function ($match) {
        $tag = $match[0];
        $surface = str_contains($tag, 'af-cs-page') ? 'sheet' : (str_contains($tag, 'atf-post') ? 'postbit' : ((str_contains($tag, 'atf-profile') || str_contains($tag, 'af-apui-profile-page')) ? 'profile' : 'application'));
        if (!str_contains($tag, 'data-element-surface=')) $tag = substr($tag, 0, -1) . ' data-element-surface="' . $surface . '">';
        return preg_replace_callback('/\bdata-element=(["\'])(.*?)\1/', static fn($m) => 'data-element="' . af_elementtheme_resolve_key($m[2]) . '"', $tag) ?? $tag;
    }, $page) ?? $page;
    if (stripos($page, '</head>') === false || str_contains($page, 'data-af-element-theme')) return;
    $base = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/element-theme.css';
    $version = (string)(@filemtime(__DIR__ . '/assets/element-theme.css') ?: '1');
    $html = '<link rel="stylesheet" href="' . htmlspecialchars_uni($base . '?v=' . $version) . '" data-af-element-theme>';
    $css = af_elementtheme_overrides()['css'];
    if ($css !== '') $html .= '<style data-af-element-theme-overrides>' . $css . '</style>';
    $page = preg_replace('~</head>~i', $html . "\n</head>", $page, 1) ?? $page;
}
