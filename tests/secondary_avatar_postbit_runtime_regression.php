<?php

// Exercise the real APF resolver and ATF composer rather than inspecting PHP
// source. This is the request-local data flow used by MyBB's postbit hook.
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
define('TABLE_PREFIX', 'mybb_');

function htmlspecialchars_uni(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

final class SecondaryAvatarDb
{
    public int $selects = 0;
    public array $values = [];

    public function table_exists(string $table): bool
    {
        if ($table !== 'af_apf_values') {
            throw new RuntimeException('APF passed a prefixed table name to the MyBB DB API.');
        }
        return true;
    }

    public function escape_string(string $value): string
    {
        return addslashes($value);
    }

    public function simple_select(string $table, string $fields, string $where, array $options = []): array
    {
        ++$this->selects;
        preg_match("~uid='(\\d+)'~", $where, $match);
        return ['field_value' => $this->values[(int)($match[1] ?? 0)] ?? ''];
    }

    public function fetch_field(array $query, string $field): string
    {
        return (string)($query[$field] ?? '');
    }
}

function secondary_avatar_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$plugins = new class {
    public function add_hook(...$unused): void {}
};
$mybb = (object)['settings' => [
    'bburl' => 'https://warprift.test',
    'af_advancedprofilefields_enabled' => '1',
]];
$db = new SecondaryAvatarDb();
$db->values[123] = 'uploads/avatars/secondary_123_0123456789abcdef0123456789abcdef.png';

require AF_ADDONS . 'advancedprofilefields/advancedprofilefields.php';
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';

$primary = '<img src="/uploads/avatars/avatar_123.png" alt="Author">';
$post = ['uid' => 123, 'username' => 'Author', 'useravatar' => $primary];
af_apf_postbit_secondary_avatar($post);
secondary_avatar_assert(
    $post['af_apf_secondary_avatar_url'] === 'https://warprift.test/uploads/avatars/secondary_123_0123456789abcdef0123456789abcdef.png',
    'APF did not resolve uid=123 through the canonical system-value helper.'
);
af_adaptivethemeframework_compose_postbit($post);
secondary_avatar_assert($post['af_atf_primary_avatar'] !== '', 'Primary avatar was lost during composition.');
secondary_avatar_assert($post['af_atf_secondary_avatar'] !== '', 'Secondary avatar was lost during composition.');
secondary_avatar_assert($post['af_atf_primary_avatar'] !== $post['af_atf_secondary_avatar'], 'Avatar surfaces were collapsed.');
secondary_avatar_assert($post['useravatar'] === $primary, 'ATF replaced MyBB useravatar.');
secondary_avatar_assert(substr_count($post['af_atf_primary_avatar'] . $post['af_atf_secondary_avatar'], '<img') === 2, 'Composition did not produce two images.');

// A repeated post by the same author must use APF's request cache.
$repeat = ['uid' => 123, 'username' => 'Author', 'useravatar' => $primary];
af_apf_postbit_secondary_avatar($repeat);
secondary_avatar_assert($db->selects === 1, 'Repeated uid caused another APF value query.');

$missing = ['uid' => 124, 'username' => 'No portrait', 'useravatar' => $primary];
af_apf_postbit_secondary_avatar($missing);
af_adaptivethemeframework_compose_postbit($missing);
secondary_avatar_assert($missing['af_atf_primary_avatar'] !== '', 'Missing secondary removed primary avatar.');
secondary_avatar_assert($missing['af_atf_secondary_avatar'] === '', 'Missing secondary rendered an image.');

$guest = ['uid' => 0, 'username' => 'Guest', 'useravatar' => $primary];
af_apf_postbit_secondary_avatar($guest);
af_adaptivethemeframework_compose_postbit($guest);
secondary_avatar_assert($guest['af_atf_primary_avatar'] !== '' && $guest['af_atf_secondary_avatar'] === '', 'Guest composition is unsafe.');

echo "Secondary-avatar postbit runtime regression passed.\n";
