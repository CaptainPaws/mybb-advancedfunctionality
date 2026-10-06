<?php
$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
$php = file_get_contents($addon . '/adaptivethemeframework.php');
define('IN_MYBB', true);
require_once $addon . '/ownership.php';
$css = file_get_contents($addon . '/assets/adaptivethemeframework.css');

$roots = ['newthread', 'newreply', 'editpost'];
foreach ($roots as $name) {
    $template = file_get_contents($addon . '/templates/' . $name . '.html');
    if (!str_contains($template, 'class="pun atf-page-shell atf-page atf-compose"')
        || !str_contains($template, 'atf-compose__editor')
        || !str_contains($template, 'atf-compose__actions')) {
        throw new RuntimeException($name . ' does not use the shared compose shell.');
    }
    if (preg_match('~<(?:table|tr|td|thead|tfoot)\\b|class="[^"]*\\b(?:thead|trow1|trow2|tfoot)\\b~i', $template)) {
        throw new RuntimeException($name . ' contains legacy table presentation.');
    }
    if (!array_key_exists($name, af_adaptivethemeframework_template_seeds())) {
        throw new RuntimeException($name . ' is not registered for ATF ownership.');
    }
}

foreach (['posticons', 'post_subscription_method', 'newthread_postpoll', 'editpost_delete'] as $name) {
    $template = file_get_contents($addon . '/templates/' . $name . '.html');
    if (preg_match('~<(?:table|tr|td)\\b~i', $template)) {
        throw new RuntimeException($name . ' retained table markup.');
    }
    if (str_contains($template, 'type="checkbox"') || str_contains($template, 'type="radio"')) {
        if (!str_contains($template, '<label')) {
            throw new RuntimeException($name . ' contains an unlabelled choice control.');
        }
    }
}

if (str_contains($php, "'codebuttons' => AF_ADAPTIVETHEMEFRAMEWORK_BASE")) {
    throw new RuntimeException('ATF must not own or alter SCEditor initialization.');
}
$composeCss = strstr($css, '/* ATF compose:');
if ($composeCss === false || str_contains($composeCss, '100vw')) {
    throw new RuntimeException('Compose CSS is missing its scope or introduces viewport width sizing.');
}
if (preg_match('~body\\.atf-active\\s+(?:table|td|input)\\s*\\{~', $css)) {
    throw new RuntimeException('Compose migration introduced a global element override.');
}

echo "ATF compose regression checks passed.\n";
