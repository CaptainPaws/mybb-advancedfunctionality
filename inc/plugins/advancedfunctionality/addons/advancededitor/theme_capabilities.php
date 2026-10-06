<?php
/** Move declared feature sections out of historical global bundles without discarding edits. */
function af_advancededitor_feature_theme_map(): array
{
    static $map;
    if ($map !== null) return $map;
    $map = [];
    if (!function_exists('af_current_theme_tid') || !function_exists('af_theme_stylesheet_get_bundle_row')) return $map;
    foreach (af_get_theme_inheritance_tids(af_current_theme_tid()) as $tid) {
        $dir = MYBB_ROOT . 'cache/af_advancededitor_capabilities/theme' . (int)$tid . '/';
        $index = $dir . 'registry.json';
        $saved = is_file($index) ? json_decode((string)file_get_contents($index), true) : null;
        if (!is_array($saved) || ($saved['version'] ?? 0) !== 1) {
            $saved = af_advancededitor_migrate_theme_capabilities((int)$tid, $dir);
        }
        foreach ((array)($saved['files'] ?? []) as $file => $url) if (!isset($map[$file])) $map[$file] = $url;
    }
    return $map;
}

function af_advancededitor_feature_css_url(string $file): string
{
    $map = af_advancededitor_feature_theme_map();
    return isset($map[$file]) ? af_advancededitor_url($map[$file]) : af_advancededitor_addon_file_url($file);
}

function af_advancededitor_detach_feature_sections(string $css, array $files): array
{
    $parsed = af_theme_stylesheet_parse_bundle($css);
    if (empty($parsed['ok'])) return ['ok' => false];
    $sections = [];
    foreach ($parsed['sections'] as $section) {
        $meta = $section['meta'];
        if (($meta['addon_id'] ?? '') !== AF_AE_ID) continue;
        $file = af_theme_stylesheet_canonical_source_file(AF_AE_ID, (string)($meta['source_file'] ?? ''));
        if (!isset($files[$file])) continue;
        $sections[] = $section;
    }
    // Offsets belong to the original source; remove sections from right to left.
    usort($sections, static fn($a, $b) => $b['start'] <=> $a['start']);
    $updated = $css;
    foreach ($sections as $section) $updated = substr($updated, 0, $section['start']) . substr($updated, $section['end']);
    return ['ok' => true, 'source' => $updated, 'sections' => $sections];
}

function af_advancededitor_migrate_theme_capabilities(int $tid, string $dir): array
{
    global $db;
    $manifest = require __DIR__ . '/manifest.php';
    $files = [];
    foreach ($manifest['theme_stylesheets'] as $entry) {
        if (!empty($entry['exclude_autodiscovery'])) $files[af_theme_stylesheet_canonical_source_file(AF_AE_ID, $entry['file'])] = true;
    }
    $row = af_theme_stylesheet_get_bundle_row($tid);
    $saved = ['version' => 1, 'files' => []];
    if (!$row) return $saved;
    $old = (string)$row['stylesheet'];
    $split = af_advancededitor_detach_feature_sections($old, $files);
    if (empty($split['ok'])) return [];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return [];
    foreach ($split['sections'] as $section) {
        $meta = $section['meta'];
        $file = af_theme_stylesheet_canonical_source_file(AF_AE_ID, $meta['source_file']);
        $body = (string)$section['body'];
        $seedHash = (string)($meta['seed_body_sha1'] ?? '');
        // Upstream assets remain authoritative unless a theme actually edited this section.
        if (empty($meta['manual_override']) && $seedHash !== '' && hash_equals($seedHash, sha1($body))) continue;
        $name = sha1($file) . '-' . sha1($body) . '.css';
        if (@file_put_contents($dir . $name, $body, LOCK_EX) === false) return [];
        $saved['files'][$file] = 'cache/af_advancededitor_capabilities/theme' . $tid . '/' . $name;
        if ($file === 'assets/advancededitor.css') {
            // Retain theme-edited shell rules, while dialogs stay in the lazy stylesheet.
            preg_match_all('~([^{}]+)\{([^{}]*)\}~s', preg_replace('~/\*.*?\*/~s', '', $body), $rules, PREG_SET_ORDER);
            $shell = '';
            foreach ($rules as $rule) {
                $selectors = array_filter(array_map('trim', explode(',', $rule[1])), static fn($s) => preg_match('~sceditor-(?:toolbar|button|container|textarea)|quickreply_e~', $s) && !str_contains($s, 'dropdown'));
                if ($selectors) $shell .= implode(',', $selectors) . '{' . $rule[2] . "}\n";
            }
            if ($shell !== '') {
                $shellName = 'shell-' . sha1($shell) . '.css';
                if (@file_put_contents($dir . $shellName, $shell, LOCK_EX) === false) return [];
                $saved['files']['shell_override'] = 'cache/af_advancededitor_capabilities/theme' . $tid . '/' . $shellName;
            }
        }
    }
    if ($split['sections']) {
        $recovery = af_theme_stylesheet_create_recovery($tid, $row, $old);
        if (empty($recovery['ok'])) return [];
        $sid = (int)$row['sid'];
        $updated = $split['source'];
        $db->update_query('themestylesheets', ['stylesheet' => af_theme_stylesheet_db_css($updated), 'lastmodified' => TIME_NOW],
            "sid='{$sid}' AND tid='{$tid}' AND stylesheet='" . $db->escape_string($old) . "'");
        if (method_exists($db, 'affected_rows') && $db->affected_rows() === 0) return [];
        af_theme_stylesheet_cache_row($tid, $sid, $updated);
        $state = af_theme_stylesheet_bundle_state($tid);
        if ($state) $db->update_query(AF_THEME_STYLESHEETS_TABLE, ['last_synced_checksum' => sha1($updated), 'updated_at' => TIME_NOW], "id='" . (int)$state['id'] . "'");
    }
    @file_put_contents($dir . 'registry.json', json_encode($saved, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $saved;
}
