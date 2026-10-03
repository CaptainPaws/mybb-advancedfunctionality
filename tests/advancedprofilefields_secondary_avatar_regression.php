<?php

$root = dirname(__DIR__);
$apf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php');
$apui = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php');
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php');
$template = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/templates/usercp_avatar.html');
$manifest = require $root . '/inc/plugins/advancedfunctionality/addons/advancedprofilefields/manifest.php';
$apuiPostbit = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/templates/postbit_classic.html');
$atfPostbit = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/templates/postbit_classic.html');
$apuiProfile = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/templates/member_profile.html');
$apuiCss = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/advancedprofileui.css');
$atfCss = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.css');

foreach ([$apf, $apui, $atf, $template, $apuiPostbit, $atfPostbit, $apuiProfile, $apuiCss, $atfCss] as $source) {
    if ($source === false) {
        throw new RuntimeException('Secondary-avatar integration source is missing.');
    }
}

$requirements = [
    'APF system-field table' => "define('AF_APF_VALUES_TABLE', 'af_apf_values')",
    'idempotent schema' => 'CREATE TABLE IF NOT EXISTS',
    'public read helper' => 'function af_apf_get_secondary_avatar(int $uid): string',
    'real MIME inspection' => 'FILEINFO_MIME_TYPE',
    'HTTP upload verification' => 'is_uploaded_file($tmp)',
    'cryptographic filename' => 'random_bytes(16)',
    'owner-bound path' => "'/secondary_' . " . '$uid' . " . '_'",
    'CSRF validation' => 'verify_post_check',
    'isolated action' => "do_apf_secondary_avatar",
];
foreach ($requirements as $label => $needle) {
    if (!str_contains($apf, $needle)) {
        throw new RuntimeException($label . ' contract is missing.');
    }
}

if (str_contains($apf, "ALTER TABLE") && str_contains($apf, "users")) {
    throw new RuntimeException('Secondary avatar must not add a users column.');
}
if (!str_contains($template, '{$af_apf_secondary_avatar}')) {
    throw new RuntimeException('ATF avatar page does not expose the APF component slot.');
}
if (!str_contains($apf, 'function af_apf_normalize_atf_avatar_template')
    || !str_contains($apf, 'secondary_avatar_atf_seed_upgrade')) {
    throw new RuntimeException('APF must provide the ATF avatar seed-upgrade normalizer.');
}
if (!str_contains($apf, 'secondary_avatar_legacy_disable_output')
    || !str_contains($apf, 'exact_known_legacy_disable_upgrade')) {
    throw new RuntimeException('APF must recover the exact legacy disable output left in usercp_avatar.');
}
$provider = $manifest['compatibility_providers']['adaptivethemeframework'] ?? null;
if (!is_array($provider)
    || ($provider['callback'] ?? '') !== 'af_apf_register_atf_compatibility_normalizer'
    || empty($provider['load_when_disabled'])) {
    throw new RuntimeException('APF avatar seed migration must remain discoverable while APF is disabled.');
}
if (str_contains($apf, "'usercp_avatar' => [")) {
    throw new RuntimeException('APF must not patch ATF-owned usercp_avatar directly.');
}
if (!str_contains($apui, 'function af_apui_get_profile_avatars')
    || !str_contains($apui, "'primary_avatar'")
    || !str_contains($apui, "'secondary_avatar'")) {
    throw new RuntimeException('AdvancedProfileUI does not provide independent avatar values.');
}
if (!str_contains($atf, "'avatars' => " . '$avatars')) {
    throw new RuntimeException('ATF profile context does not transport the avatar provider.');
}
if (str_contains($apf, 'Размер изображения не должен превышать')
    || !str_contains($apf, 'Рекомендуемый размер: 200×250 px')) {
    throw new RuntimeException('Secondary-avatar dimensions must be a display recommendation, not upload validation.');
}
if (!str_contains($apf, 'af_apf_system_value_cache')) {
    throw new RuntimeException('Secondary-avatar storage reads must use a request-local cache.');
}
if (!str_contains($apuiPostbit, "{\$post['af_apui_display_avatar']}")) {
    throw new RuntimeException('APUI postbit does not preserve the MyBB avatar payload.');
}
foreach (["{\$post['af_atf_primary_avatar']}", "{\$post['af_atf_secondary_avatar']}"] as $avatarSlot) {
    if (!str_contains($atfPostbit, $avatarSlot)) {
        throw new RuntimeException('ATF postbit does not expose both independent avatar slots.');
    }
}
if (!str_contains($apuiProfile, '{$af_apui_profile_portrait}')
    || !str_contains($apui, "'display_avatar'")
    || !str_contains($atf, "'af_apui_display_avatar'")) {
    throw new RuntimeException('Profile/ATF avatar transport is incomplete.');
}
foreach ([$apuiCss, $atfCss] as $css) {
    if (!str_contains($css, 'object-fit: cover') || !str_contains($css, 'object-position: center center')) {
        throw new RuntimeException('Avatar crop styling is incomplete.');
    }
}

echo "AdvancedProfileFields secondary avatar regression checks passed.\n";
