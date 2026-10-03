<?php
// Task 14: ATF owns only postbit_classic and renders the complete post contract.
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';

function atf_classic_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$seeds = af_adaptivethemeframework_template_seeds();
atf_classic_assert(isset($seeds['postbit_classic']), 'postbit_classic is outside generic ownership.');
atf_classic_assert(!isset($seeds['postbit']), 'Horizontal postbit must remain unowned.');
$template = (string)file_get_contents($seeds['postbit_classic']);

foreach ([
    "{\$post['pid']}", "{\$post['uid']}", "{\$post['af_atf_primary_avatar']}",
    "{\$post['af_atf_secondary_avatar']}",
    "{\$post['af_post_char_count_formatted']}",
    "{\$post['usertitle']}", "{\$post['groupimage']}", "{\$post['userstars']}",
    "{\$post['postdate']}", "{\$post['posturl']}", "{\$post['subject']}",
    "{\$post['message']}", "{\$post['editedmsg']}", "{\$post['attachments']}",
    "{\$post['signature']}", "{\$post['iplogged']}", "{\$post['poststatus']}",
    "{\$post['input_editreason']}", "{\$post['af_aa_user_class']}",
] as $variable) {
    atf_classic_assert(str_contains($template, $variable), "Classic post omits {$variable}");
}

foreach (array_filter(af_adaptivethemeframework_slots(), static fn(string $slot): bool => str_starts_with($slot, 'post.')) as $slot) {
    atf_classic_assert(str_contains($template, "{\$post['af_atf_slots']['{$slot}']}"), "Classic post does not render {$slot}");
}

foreach (['<article', 'atf-post__topbar', 'atf-post__layout', 'atf-post__sidebar',
          'atf-post__primary-avatar', 'atf-post__secondary-avatar',
          'atf-post__profile-actions', 'atf-post__sheet-action',
          'atf-post__meta-line', 'atf-post__content', 'atf-post__message',
          'atf-post__top-actions'] as $anchor) {
    atf_classic_assert(str_contains($template, $anchor), "Semantic post markup omits {$anchor}");
}
atf_classic_assert(!str_contains($template, 'af-apui-postbit'), 'ATF copied the APUI postbit hierarchy.');

$source = (string)file_get_contents(AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php');
foreach (['button_edit', 'button_quickdelete', 'button_quickrestore', 'button_quote',
          'button_multiquote', 'button_report', 'button_warn', 'button_purgespammer',
          'button_approve', 'button_unapprove', 'button_restore', 'button_rep'] as $control) {
    atf_classic_assert(str_contains($source, "'{$control}'"), "Action provider omits {$control}");
}

$css = (string)file_get_contents(AF_ADDONS . 'adaptivethemeframework/assets/adaptivethemeframework.css');
atf_classic_assert(str_contains($css, 'grid-template-columns: minmax(13.75rem, 18.75rem) minmax(0, 1fr)'), 'Desktop author rail is missing.');
atf_classic_assert((bool)preg_match('~\.atf-post__sidebar\s*\{\s*position:\s*sticky;~', $css), 'Desktop author rail is not sticky.');
atf_classic_assert((bool)preg_match('~\.atf-post\s*\{\s*margin-block:[^;]+;\s*overflow:\s*visible;~', $css), 'Post overflow still breaks sticky positioning.');
atf_classic_assert((bool)preg_match('~@media \(max-width: 48rem\).*?\.atf-post__layout \{ grid-template-columns: minmax\(0, 1fr\); \}.*?\.atf-post__sidebar \{ position: static;~s', $css), 'The same post does not collapse or disable sticky on mobile.');

atf_classic_assert(af_adaptivethemeframework_post_text_count('Привет [b]мир[/b]') === 10, 'UTF-8/MyCode character count is incorrect.');
atf_classic_assert(af_adaptivethemeframework_post_text_count('[img]https://example.test/a.jpg[/img]Текст') === 5, 'Non-text MyCode payload is counted.');

echo "ATF postbit_classic semantic/slot contract passed.\n";
