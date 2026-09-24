<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$addon = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php');
$mentionsJs = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/assets/aam_mentions.js');

function extractAamFunction(string $source, string $name): string
{
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) throw new RuntimeException("Missing {$name}");
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}' && --$depth === 0) return substr($source, $start, $i - $start + 1);
    }
    throw new RuntimeException("Unterminated {$name}");
}

function af_aam_is_enabled(): bool { return true; }
eval(extractAamFunction($addon, 'af_aam_pre_output_page'));

$mybb = (object)['user' => ['uid' => 42]];
$af_aam_js = '<script>window.my_post_key="token";</script><script src="/a/advancedalertsandmentions.js"></script><script src="/a/aam_mentions.js"></script>';
$af_aam_css = '<link rel="stylesheet" href="/a/advancedalertsandmentions.css">';
$af_aam_header_icon = '<li><a id="af_aam_header_link">Alerts</a></li>';
$af_aam_modal = '<div id="af_aam_modal">Modal</div>';

$page = '<html><head></head><body><div class="custom" id="panel"><ul><li>Profile</li></ul></div><main>quick reply</main></body></html>';
af_aam_pre_output_page($page);
foreach (['advancedalertsandmentions.js', 'aam_mentions.js', 'advancedalertsandmentions.css', 'af_aam_header_link', 'af_aam_modal'] as $needle) {
    if (substr_count($page, $needle) !== 1) throw new RuntimeException("Missing or duplicate frontend fragment: {$needle}");
}
if (strpos($page, 'af_aam_header_link') > strpos($page, '</ul>')) {
    throw new RuntimeException('Alert trigger was not inserted into the member panel list');
}

// A second pre-output pass and themes which still contain the legacy template
// placeholders must never duplicate controls or scripts.
af_aam_pre_output_page($page);
foreach (['advancedalertsandmentions.js', 'af_aam_header_link', 'af_aam_modal'] as $needle) {
    if (substr_count($page, $needle) !== 1) throw new RuntimeException("Pre-output is not idempotent: {$needle}");
}

if (!str_contains($mentionsJs, "document.addEventListener('input'")
    || !str_contains($mentionsJs, "classList.contains('af-aam-mention-user')")
    || !str_contains($mentionsJs, "classList.contains('af-aam-mention-button')")) {
    throw new RuntimeException('Mention autocomplete or delegated username/button handlers are missing');
}
if (!str_contains($mentionsJs, "'&my_post_key=' + encodeURIComponent(window.my_post_key)")) {
    throw new RuntimeException('Mention suggestions do not send the MyBB CSRF token');
}
if (!str_contains($addon, "verify_post_check(\$mybb->get_input('my_post_key'), true)")) {
    throw new RuntimeException('Mention suggestion endpoint does not verify the MyBB CSRF token');
}

echo "Advanced Alerts and Mentions frontend bootstrap contract passed.\n";
