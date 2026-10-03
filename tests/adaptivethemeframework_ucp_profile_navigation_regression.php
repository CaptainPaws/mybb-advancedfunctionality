<?php
$root = dirname(__DIR__);
$runtime = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php');
$manifest = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php');

foreach ([
    "$def('profile','', 'usercp.php',['action'=>'profile']",
    "'avatar','do_avatar','changename','do_changename','editsig','do_editsig'",
    "$def('profile.avatar','profile','usercp.php',['action'=>'avatar'],'ucp_nav_change_avatar','user',20,$access",
    "$def('profile.username','profile','usercp.php',['action'=>'changename'],'atf_ucp_nav_username','user',30",
    "$def('profile.signature','profile','usercp.php',['action'=>'editsig'],'ucp_nav_edit_sig','file',40",
    "$def('profile.public','profile','member.php',['action'=>'profile','uid'=>'{uid}'],'atf_ucp_nav_public_profile','user',50",
] as $needle) {
    if (strpos($runtime, $needle) === false) {
        throw new RuntimeException('Missing UCP profile navigation contract: '.$needle);
    }
}

if (strpos($runtime, "$def('security.username'") !== false) {
    throw new RuntimeException('Username change still belongs to the Security navigation group.');
}
if (strpos($runtime, "$def('security','', 'usercp.php',['action'=>'password'],'atf_ucp_nav_security','shield',40,$access,['password','do_password','email','do_email','changename','do_changename'])") !== false) {
    throw new RuntimeException('Security domain still claims changename actions.');
}
foreach ([
    "'atf_ucp_nav_username' => 'Изменить имя'",
    "'atf_ucp_nav_username' => 'Change username'",
] as $needle) {
    if (strpos($manifest, $needle) === false) {
        throw new RuntimeException('Missing username tab label: '.$needle);
    }
}

echo "ATF UCP profile navigation regression: OK\n";
