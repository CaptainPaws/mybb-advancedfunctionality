<?php

declare(strict_types=1);

define('IN_MYBB', true);
define('AF_ADDONS', __DIR__ . '/');

function htmlspecialchars_uni($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_avatar($avatar, $dimensions = '', $max_dimensions = ''): array
{
    return ['image' => (string)$avatar];
}

require_once dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php';

$mybb = (object)['settings' => [
    'bburl' => 'https://forum.example.test',
    'useravatar' => 'images/default_avatar.png',
    'af_advancedposteravatar_onerror' => 0,
    'af_advancedposteravatar_letter' => 0,
    'af_advancedposteravatar_size' => 44,
]];
$theme = ['imgdir' => 'images'];
$lang = (object)['guest' => 'Guest'];

foreach (['post', 'online'] as $context) {
    foreach ([2, 15] as $uid) {
        $html = af_avatar_render(['uid' => $uid, 'username' => 'User ' . $uid], $context);
        $expectedMarkupUrl = 'https://forum.example.test/member.php?action=profile&amp;uid=' . $uid;
        $expectedNavigationUrl = 'https://forum.example.test/member.php?action=profile&uid=' . $uid;

        if (!str_contains($html, 'href="' . $expectedMarkupUrl . '"')) {
            throw new RuntimeException("{$context} avatar for uid {$uid} does not contain a singly escaped href");
        }
        if (str_contains($html, '&amp;amp;')) {
            throw new RuntimeException("{$context} avatar for uid {$uid} contains a double-escaped href");
        }
        if (!preg_match('/href="([^"]+)"/', $html, $href)
            || html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $expectedNavigationUrl) {
            throw new RuntimeException("{$context} avatar for uid {$uid} resolves to the wrong navigation URL");
        }
    }
}

echo "AdvancedAvatar profile URLs are escaped once for post and online avatars.\n";
