<?php

$root = dirname(__DIR__);
$apf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php');
$apui = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php');
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php');
$template = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/templates/usercp_avatar.html');

foreach ([$apf, $apui, $atf, $template] as $source) {
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
if (!str_contains($apui, 'function af_apui_get_profile_avatars')
    || !str_contains($apui, "'primary_avatar'")
    || !str_contains($apui, "'secondary_avatar'")) {
    throw new RuntimeException('AdvancedProfileUI does not provide independent avatar values.');
}
if (!str_contains($atf, "'avatars' => " . '$avatars')) {
    throw new RuntimeException('ATF profile context does not transport the avatar provider.');
}

echo "AdvancedProfileFields secondary avatar regression checks passed.\n";
