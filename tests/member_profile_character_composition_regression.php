<?php

$root = dirname(__DIR__);
$apui = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php');
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$template = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/templates/member_profile.html');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.css');

foreach ([$apui, $atf, $template, $css] as $source) {
    if ($source === false) throw new RuntimeException('Profile composition source is missing.');
}

foreach ([
    'ATF backend DTO' => 'function af_atf_get_profile_character_payload(int $tid): array',
    'accepted application transport' => "['application_tid']",
    'unified profile stats' => 'function af_apui_render_profile_stats(array $context): string',
    'character workspace' => 'function af_apui_render_profile_character_workspace(array $context): string',
    'stats slot' => "'slot' => 'profile.stats'",
] as $label => $needle) {
    $haystack = $label === 'ATF backend DTO' ? $atf : $apui;
    if (!str_contains($haystack, $needle)) throw new RuntimeException($label . ' contract is missing.');
}

$characterKeys = [
    'character_name_ru', 'character_origin', 'character_origin_variant', 'character_class',
    'character_faction', 'character_weapon', 'character_activity', 'character_age',
    'character_height', 'character_weight', 'character_gen', 'character_app',
];
foreach ($characterKeys as $key) {
    if (!str_contains($atf, "'" . $key . "'")) throw new RuntimeException('Missing profile character key: ' . $key);
}

if (!str_contains($template, '{$atf_profile_stats}')
    || !str_contains($template, '{$atf_profile_before_content}')
    || str_contains($template, '{$atf_profile_balance}')
    || str_contains($template, '{$atf_profile_post_counter}')) {
    throw new RuntimeException('ATF profile must render one unified statistics surface.');
}
if (!str_contains($css, 'grid-template-columns: minmax(0, 1fr) minmax(240px, 320px) minmax(0, 1fr)')
    || !str_contains($css, '.af-apui-character-portrait__image')
    || !str_contains($css, 'object-fit: cover')
    || !str_contains($css, 'max-height: 250px')
    || !str_contains($css, 'overflow-y: auto')
    || !str_contains($css, 'max-width: 300px')) {
    throw new RuntimeException('Responsive three-column portrait composition is incomplete.');
}

if (!str_contains($apui, "['primary_avatar'] ?? ''")
    || !str_contains($apui, "['secondary_avatar'] ?? ''")) {
    throw new RuntimeException('Hero and character portrait must consume independent avatar roles.');
}

echo "Member profile character composition regression checks passed.\n";
