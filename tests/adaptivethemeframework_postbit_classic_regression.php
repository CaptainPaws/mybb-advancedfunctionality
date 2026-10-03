<?php
// Task 14: ATF owns only postbit_classic and renders the complete post contract.
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');

function af_apui_get_profile_character_payload(int $uid): array
{
    $values = [101 => '', 102 => 'fire', 103 => 'water', 104 => 'not-an-element'];
    return ['fields' => ['character_element' => ['value' => $values[$uid] ?? '']]];
}

function af_atf_resolve_element_theme_key(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['fire', 'water'], true) ? $value : '';
}

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
    "{\$post['postdate']}", "{\$post['posturl']}",
    "{\$post['message']}", "{\$post['editedmsg']}", "{\$post['attachments']}",
    "{\$post['signature']}", "{\$post['iplogged']}", "{\$post['poststatus']}",
    "{\$post['input_editreason']}", "{\$post['af_aa_user_class']}",
] as $variable) {
    atf_classic_assert(str_contains($template, $variable), "Classic post omits {$variable}");
}

foreach (array_filter(af_adaptivethemeframework_slots(), static fn(string $slot): bool => str_starts_with($slot, 'post.')) as $slot) {
    if ($slot === 'post.author.meta') continue; // Reserved compatibility slot; native meta is composed in the template.
    atf_classic_assert(str_contains($template, "{\$post['af_atf_slots']['{$slot}']}"), "Classic post does not render {$slot}");
}

foreach (['<article', 'atf-post__topbar', 'atf-post__layout', 'atf-post__sidebar',
          'atf-post__primary-avatar', 'atf-post__secondary-avatar',
          'atf-post__profile-actions', 'atf-post__sheet-action',
          'atf-post__meta-line', 'atf-post__content', 'atf-post__message',
          'atf-post__management', 'atf-post__reputation-corner'] as $anchor) {
    atf_classic_assert(str_contains($template, $anchor), "Semantic post markup omits {$anchor}");
}
atf_classic_assert(!str_contains($template, 'af-apui-postbit'), 'ATF copied the APUI postbit hierarchy.');
atf_classic_assert(!str_contains($template, "{\$post['subject']}"), 'Per-post subject is still rendered.');
atf_classic_assert(strpos($template, 'atf-post__moderation') < strpos($template, 'atf-post__sheet-action'), 'Moderation is not the first leading control.');
atf_classic_assert(strpos($template, 'atf-post__management') > strpos($template, 'atf-post__message'), 'Management is not below the post body.');

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
atf_classic_assert(str_contains($css, '--atf-post-accent: var(--atf-neutral-accent)'), 'Posts without an element are not explicitly neutral.');
atf_classic_assert(str_contains($css, '.atf-post.af-atf-display[data-element]:not([data-element=""])'), 'Element tokens are not gated by a real element value.');
atf_classic_assert(str_contains($css, '.atf-post__name a { color: var(--atf-post-accent); }'), 'Nickname does not consume the post element accent.');
atf_classic_assert(str_contains($css, 'border-radius: 100% 0 0 0'), 'Reputation decoration is not a distinct corner arc.');
atf_classic_assert(!preg_match('~\.atf-post__sheet-accent[^}]*clip-path:\s*polygon~s', $css), 'Character Sheet decoration is still a polygon blob.');
atf_classic_assert((bool)preg_match('~\.atf-post__topbar\s*\{[^}]*position:\s*sticky;[^}]*grid-template-columns:\s*auto minmax\(0, 1fr\) auto;~s', $css), 'The full-width topbar is not sticky or does not give identity the flexible column.');
atf_classic_assert(str_contains($css, 'var(--af-apui-postbit-author-bg-image, none)'), 'ATF author panel ignores the AdvancedAppearance profile background.');
atf_classic_assert(str_contains($css, 'var(--af-apui-postbit-name-bg-image, none)'), 'ATF nickname ignores the AdvancedAppearance nickname background.');
atf_classic_assert((bool)preg_match('~@media \(max-width: 48rem\).*?\.atf-post__topbar \{ position: static;.*?\.atf-post__sidebar-inner \{ position: static;~s', $css), 'Mobile does not disable both sticky surfaces.');

atf_classic_assert(af_adaptivethemeframework_post_element(101) === '', 'Missing element must resolve to neutral.');
atf_classic_assert(af_adaptivethemeframework_post_element(102) === 'fire', 'Fire element was not preserved.');
atf_classic_assert(af_adaptivethemeframework_post_element(103) === 'water', 'Water element was not preserved.');
atf_classic_assert(af_adaptivethemeframework_post_element(104) === '', 'Unknown element must not generate an accent.');

$atfSource = (string)file_get_contents(AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php');
atf_classic_assert(str_contains($atfSource, 'function af_atf_resolve_element_theme_key'), 'AdvancedThreadFields has no canonical element resolver.');
atf_classic_assert(str_contains($atfSource, '$elementThemeKey = af_atf_resolve_element_theme_key($val);'), 'Questionnaire rendering bypasses the canonical element resolver.');

atf_classic_assert(af_adaptivethemeframework_post_text_count('Привет [b]мир[/b]') === 10, 'UTF-8/MyCode character count is incorrect.');
atf_classic_assert(af_adaptivethemeframework_post_text_count('[img]https://example.test/a.jpg[/img]Текст') === 5, 'Non-text MyCode payload is counted.');

echo "ATF postbit_classic semantic/slot contract passed.\n";
