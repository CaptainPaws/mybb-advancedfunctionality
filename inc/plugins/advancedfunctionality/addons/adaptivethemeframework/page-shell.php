<?php
/** ATF owns page geometry; component-specific responsive CSS stays with its owner. */
if (!defined('IN_MYBB') || !defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

function af_adaptivethemeframework_page_setting_definitions(): array
{
    return [
        'af_atf_page_max_width' => ['Максимальная ширина страницы', 1200, 800, 1920],
        'af_atf_page_padding' => ['Внутренние отступы', 20, 8, 48],
        'af_atf_page_mobile_gutter' => ['Боковые отступы на мобильных', 12, 4, 32],
        'af_atf_breakpoint_tablet' => ['Tablet breakpoint', 1024, 242, 2560],
        'af_atf_breakpoint_mobile' => ['Mobile breakpoint', 768, 241, 2559],
        'af_atf_breakpoint_narrow' => ['Narrow mobile breakpoint', 480, 240, 2558],
    ];
}

/** Validate at the server boundary, including settings saved directly in MyBB ACP. */
function af_adaptivethemeframework_validate_page_settings(array $settings): array
{
    $values = [];
    foreach (af_adaptivethemeframework_page_setting_definitions() as $key => $definition) {
        [, $default, $min, $max] = $definition;
        $raw = (string)($settings[$key] ?? $default);
        $value = preg_match('/^\d+$/D', $raw) ? (int)$raw : $default;
        $values[$key] = max($min, min($max, $value));
    }
    // Invalid ordering falls back as a unit; never emit contradictory media ranges.
    if ($values['af_atf_breakpoint_narrow'] >= $values['af_atf_breakpoint_mobile']
        || $values['af_atf_breakpoint_mobile'] >= $values['af_atf_breakpoint_tablet']) {
        foreach (['tablet', 'mobile', 'narrow'] as $name) {
            $key = 'af_atf_breakpoint_' . $name;
            $values[$key] = af_adaptivethemeframework_page_setting_definitions()[$key][1];
        }
    }
    return $values;
}

/** Lifecycle-only registration. Preserve saved values, including the enabled switch. */
function af_adaptivethemeframework_ensure_page_settings(): void
{
    global $db;
    if (!is_object($db)) return;
    $group = 'af_adaptivethemeframework';
    af_ensure_settinggroup($group, 'AF: Adaptive Theme Framework', 'Единая рамка страниц и адаптивность. Значения в px; narrow < mobile < tablet.');
    foreach (af_adaptivethemeframework_page_setting_definitions() as $key => $definition) {
        [$title, $default, $min, $max] = $definition;
        $existing = $db->fetch_array($db->simple_select('settings', 'value', "name='" . $db->escape_string($key) . "'", ['limit' => 1]));
        $value = $existing ? (string)$existing['value'] : (string)$default;
        af_ensure_setting($group, $key, $title, "{$min}–{$max}px. narrow < mobile < tablet. Некорректный порядок возвращает breakpoints к 480/768/1024px.", 'numeric', $value, 10 + array_search($key, array_keys(af_adaptivethemeframework_page_setting_definitions()), true));
    }
    if (function_exists('rebuild_settings')) rebuild_settings();
}

/** Promote an existing full-page semantic root, without touching children or DB ownership. */
function af_adaptivethemeframework_wrap_page_content(string $page, string $rootClass): string
{
    if (!af_adaptivethemeframework_presentation_is_active()
        || stripos($page, '<body') === false || strpos($page, 'atf-page-shell') !== false) return $page;
    return (string)preg_replace_callback(
        '~(<(?:div|main)\b[^>]*\bclass=)(["\'])([^"\']*)\2~i',
        static function (array $match) use ($rootClass): string {
            $classes = preg_split('/\s+/', trim($match[3])) ?: [];
            if (!in_array($rootClass, $classes, true)) return $match[0];
            if (!in_array('pun', $classes, true)) $classes[] = 'pun';
            $classes[] = 'atf-page-shell';
            return $match[1] . $match[2] . implode(' ', $classes) . $match[2];
        },
        $page
    );
}

/** Small generated CSS in both Theme and File mode; no generated stylesheet bundle. */
function af_adaptivethemeframework_inject_page_settings(string &$page): void
{
    if (stripos($page, '</head>') === false || stripos($page, '<body') === false
        || strpos($page, 'data-atf-page-settings') !== false) return;
    $values = af_adaptivethemeframework_validate_page_settings((array)($GLOBALS['mybb']->settings ?? []));
    $variables = '';
    foreach ($values as $key => $value) {
        $variables .= '--atf-' . str_replace('_', '-', substr($key, 7)) . ':' . $value . 'px;';
    }
    $tablet = $values['af_atf_breakpoint_tablet'];
    $mobile = $values['af_atf_breakpoint_mobile'];
    $narrow = $values['af_atf_breakpoint_narrow'];
    $css = 'body.atf-active[data-atf-page-settings]{' . $variables
        . '--atf-page-gap:var(--atf-page-padding);--atf-page-content-width:min(100%,var(--atf-page-max-width));}';
    $css .= <<<CSS
body.atf-active.af-rwd-enabled .pun.atf-page-shell,
body.atf-active.af-rwd-enabled #content.pun.atf-page-shell {
  width: var(--atf-page-content-width) !important;
  max-width: var(--atf-page-max-width) !important;
  min-width: 0 !important;
  margin-inline: auto !important;
  padding-inline: var(--atf-page-padding) !important;
}
@media (max-width: {$tablet}px) {
  body.atf-active[data-atf-page-settings] { --atf-component-gap: var(--atf-space-3); }
}
@media (max-width: {$mobile}px) {
  body.atf-active[data-atf-page-settings] {
    --atf-page-content-width: min(calc(100% - 2 * var(--atf-page-mobile-gutter)), var(--atf-page-max-width));
  }
  body.atf-active[data-atf-page-settings] .pun.atf-page-shell,
  body.atf-active[data-atf-page-settings] #content.pun.atf-page-shell { width: var(--atf-page-content-width); }
  body.atf-active[data-atf-page-settings] .atf-grid, body.atf-active[data-atf-page-settings] .atf-options-grid { grid-template-columns: minmax(0, 1fr); }
  body.atf-active[data-atf-page-settings] .atf-card { padding: var(--atf-space-4); }
}
@media (max-width: {$narrow}px) {
  body.atf-active[data-atf-page-settings] { --atf-component-gap: var(--atf-space-2); }
  body.atf-active[data-atf-page-settings] .atf-button { width: 100%; }
}
CSS;
    $page = (string)preg_replace('~<body\b~i', '<body data-atf-page-settings="1"', $page, 1);
    $page = (string)preg_replace_callback('~</head>~i', static fn() => '<style data-atf-page-settings-css>' . $css . '</style></head>', $page, 1);
}
