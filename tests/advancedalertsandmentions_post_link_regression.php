<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$addonPath = $root.'/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php';
$formatterPath = $root.'/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentionsformatters.php';
$addon = file_get_contents($addonPath);
$formatter = file_get_contents($formatterPath);

function extractAamPostLinkFunction(string $source, string $name): string
{
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) {
        throw new RuntimeException("Missing {$name}");
    }
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}' && --$depth === 0) {
            return substr($source, $start, $i - $start + 1);
        }
    }
    throw new RuntimeException("Unterminated {$name}");
}

// Model first, middle and last post positions on each page of a three-page
// topic. The stub deliberately returns an HTML-escaped MyBB helper URL, as the
// real get_post_link() does when friendly URLs are disabled.
function get_post_link($pid, $tid = 0): string
{
    $pages = [101 => 1, 110 => 1, 120 => 1, 121 => 2, 130 => 2, 140 => 2, 141 => 3, 150 => 3, 160 => 3];
    return 'showthread.php?tid='.(int)$tid.'&amp;page='.$pages[(int)$pid].'&amp;pid='.(int)$pid;
}

eval(extractAamPostLinkFunction($formatter, 'af_aam_get_post_url'));

foreach ([101, 110, 120, 121, 130, 140, 141, 150, 160] as $pid) {
    $expectedPage = intdiv($pid - 101, 20) + 1;
    $expected = 'showthread.php?tid=77&page='.$expectedPage.'&pid='.$pid.'#pid'.$pid;
    if (af_aam_get_post_url($pid, 77) !== $expected) {
        throw new RuntimeException("Post {$pid} did not preserve the helper-selected page and anchor");
    }
}

if (af_aam_get_post_url(0, 77) !== 'showthread.php?tid=77') {
    throw new RuntimeException('Legacy alert without pid does not fall back to its thread');
}

foreach (['post_threadauthor', 'subscribed_thread', 'quoted', 'mention', 'subscribed_forum'] as $type) {
    $caseStart = strpos($formatter, "case '{$type}':");
    $caseEnd = strpos($formatter, 'break;', $caseStart);
    $case = substr($formatter, $caseStart, $caseEnd - $caseStart);
    if (!str_contains($case, 'af_aam_get_post_url($pid, $tid)')) {
        throw new RuntimeException("Post-based alert {$type} bypasses the MyBB post-link helper");
    }
}

if (!str_contains($addon, "datahandler_post_insert_post_end', 'af_aam_post_insert_end")
    || !str_contains($addon, "datahandler_post_insert_thread_end', 'af_aam_thread_insert_end")) {
    throw new RuntimeException('Alerts are created before MyBB exposes the inserted pid');
}
if (str_contains($addon, "['order_by' => 'dateline', 'order_dir' => 'DESC', 'limit' => 1]")) {
    throw new RuntimeException('Post pid is still guessed from an earlier author post');
}

foreach (["'quoted'", "'mention'", "'post_threadauthor'", "'subscribed_thread'"] as $type) {
    if (!str_contains($addon, $type)) {
        throw new RuntimeException("Missing alert producer {$type}");
    }
}
if (substr_count($addon, "'pid'     =>") < 5 || substr_count($addon, "'tid'     =>") < 5) {
    throw new RuntimeException('Post-based alert payloads do not consistently persist tid and pid');
}

echo "Advanced Alerts and Mentions exact post-link contract passed.\n";
