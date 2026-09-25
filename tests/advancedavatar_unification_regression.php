<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$avatarFile = $root . '/inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php';
$avatar = file_get_contents($avatarFile);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality.php');
$alerts = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php');

if (is_file($root . '/inc/plugins/advancedfunctionality/addons/advancedonlineavatar/manifest.php')) {
    throw new RuntimeException('Legacy AdvancedOnlineAvatar is still discoverable');
}

foreach (['function af_avatar_render(', "'online.php'", 'af_avatar_render_online_page', '$profileUrlRaw', '$profileUrlHtml'] as $needle) {
    if (!str_contains($avatar, $needle)) {
        throw new RuntimeException("Unified avatar contract is missing: {$needle}");
    }
}

if (str_contains($avatar, 'htmlspecialchars_uni(get_profile_link(')
    || str_contains($avatar, "str_replace('&amp;', '&'")) {
    throw new RuntimeException('Avatar profile URLs still use double escaping or entity replacement');
}

if (!str_contains($avatar, "'/member.php?action=profile&uid=' . \$uid")
    || !str_contains($avatar, '$profileUrlHtml = htmlspecialchars_uni($profileUrlRaw);')) {
    throw new RuntimeException('Avatar profile URLs are not kept raw until the HTML attribute boundary');
}

if (str_contains($avatar, "get_profile_link((int)\$mybb->user['uid'])") || str_contains($avatar, 'uid=1')) {
    throw new RuntimeException('Avatar links still depend on the viewer or a constant uid');
}

if (!str_contains($avatar, "['uid' => 0") || !str_contains($avatar, 'if ($uid === 0)')) {
    throw new RuntimeException('Guest/fallback rendering is not explicitly unlinked');
}

if (!str_contains($core, 'af_migrate_advancedavatar_addons()')
    || !str_contains($core, "af_advancedonlineavatar_enabled")
    || !str_contains($core, "af_advancedposteravatar_enabled")) {
    throw new RuntimeException('Legacy enabled-state migration is missing');
}

if (!str_contains($alerts, "THIS_SCRIPT === 'charactersheets.php'")
    || !str_contains($alerts, 'af_aam_is_charactersheets_surface()')) {
    throw new RuntimeException('CharacterSheets alerts exclusion is missing');
}

echo "AdvancedAvatar unification and CharacterSheets surface contract passed.\n";
