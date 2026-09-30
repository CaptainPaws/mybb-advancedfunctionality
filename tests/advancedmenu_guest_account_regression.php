<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');

function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$mybb = (object)[
    'settings' => ['bburl' => 'https://board.test'],
    'user' => ['uid' => 0],
    'usergroup' => [],
];
require AF_ADDONS.'advancedmenu/advancedmenu.php';

$guestBar = af_advancedmenu_render_guest_account_bar();
foreach (['Привет, гость', 'member.php?action=login', 'Войти', 'member.php?action=register', 'Регистрация'] as $needle) {
    if (strpos($guestBar, $needle) === false) {
        throw new RuntimeException('Guest account bar is missing: '.$needle);
    }
}
if (substr_count($guestBar, 'af-am-guest-account') !== 1) {
    throw new RuntimeException('Guest account bar must have one canonical root.');
}

$mybb->user = ['uid' => 42, 'username' => 'Member'];
if (af_advancedmenu_render_guest_account_bar() !== '') {
    throw new RuntimeException('Guest account bar was exposed to an authenticated member.');
}

$source = file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php');
if (!preg_match('~af-am-secondary[^\n]+</nav>\'\s*\.af_advancedmenu_render_guest_account_bar\(\)~', $source)) {
    throw new RuntimeException('Guest account bar is not rendered directly below secondary navigation.');
}

$css = file_get_contents(AF_ADDONS.'advancedmenu/assets/advancedmenu.css');
foreach (['.af-advancedmenu-layout #panel', '.af-am-guest-account', '@media (max-width: 480px)'] as $needle) {
    if (strpos($css, $needle) === false) {
        throw new RuntimeException('Guest account layout contract is missing: '.$needle);
    }
}

echo "advancedmenu guest account regression: OK\n";
