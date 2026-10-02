<?php
/**
 * AF Addon: Adaptive Theme Framework.
 *
 * This file deliberately owns composition only.  Templates decide where a slot
 * is rendered; providers never need to patch a template or know its DOM.
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

define('AF_ADAPTIVETHEMEFRAMEWORK_ID', 'adaptivethemeframework');
define('AF_ADAPTIVETHEMEFRAMEWORK_BASE', AF_ADDONS . AF_ADAPTIVETHEMEFRAMEWORK_ID . '/');
define('AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME', 'af_adaptivethemeframework_template_ownership');
define('AF_ADAPTIVETHEMEFRAMEWORK_VERSION', '0.13.0');

/** Load activation compatibility callbacks declared by enabled AF addons. */
function af_adaptivethemeframework_discover_compatibility_providers(): void
{
    if (!function_exists('af_discover_addons')) {
        return;
    }
    global $mybb;
    foreach (af_discover_addons() as $manifest) {
        $id = strtolower(trim((string)($manifest['id'] ?? '')));
        $providers = $manifest['compatibility_providers'] ?? [];
        $callback = is_array($providers) ? ($providers[AF_ADAPTIVETHEMEFRAMEWORK_ID] ?? null) : null;
        if ($id === '' || !is_string($callback) || $callback === '') {
            continue;
        }
        $enabled = function_exists('af_is_addon_enabled')
            ? af_is_addon_enabled($id)
            : (string)($mybb->settings['af_' . $id . '_enabled'] ?? '0') === '1';
        if (!$enabled) {
            continue;
        }
        $bootstrap = (string)($manifest['bootstrap'] ?? '');
        if ($bootstrap !== '' && is_file($bootstrap)) {
            require_once $bootstrap;
        }
        if (is_callable($callback)) {
            $callback();
        }
    }
}

/**
 * Register a narrowly scoped compatibility normalizer.
 *
 * A normalizer receives the template name and current bytes. It must return
 * null when it cannot make an exact, attributable transformation, or an array
 * containing normalized_content, owner, source and transformation_type.
 * ATF deliberately does not inspect or clean third-party markup itself.
 */
function af_adaptivethemeframework_register_compatibility_normalizer(string $identity, callable $normalizer, string $diagnosticPrefix = 'normalizer'): bool
{
    $identity = strtolower(trim($identity));
    if (!preg_match('~^[a-z][a-z0-9_.:-]*$~', $identity)
        || isset($GLOBALS['af_adaptivethemeframework_compatibility_normalizers'][$identity])) {
        return false;
    }
    $GLOBALS['af_adaptivethemeframework_compatibility_normalizers'][$identity] = $normalizer;
    $GLOBALS['af_adaptivethemeframework_compatibility_normalizer_prefixes'][$identity]
        = preg_match('~^[a-z][a-z0-9_]*$~', $diagnosticPrefix) ? $diagnosticPrefix : 'normalizer';
    return true;
}

/** @return array<string, string>|null */
function af_adaptivethemeframework_normalize_compatible_template(string $templateName, string $current): ?array
{
    af_adaptivethemeframework_discover_compatibility_providers();
    // Providers may load earlier than ATF during an enable request. Import
    // their declarations now, without knowing which addons supplied them.
    foreach (($GLOBALS['af_adaptivethemeframework_pending_compatibility_normalizers'] ?? []) as $identity => $normalizer) {
        if (is_callable($normalizer)) {
            $prefix = (string)($GLOBALS['af_adaptivethemeframework_pending_compatibility_normalizer_prefixes'][$identity] ?? 'normalizer');
            af_adaptivethemeframework_register_compatibility_normalizer((string)$identity, $normalizer, $prefix);
        }
    }
    unset($GLOBALS['af_adaptivethemeframework_pending_compatibility_normalizers']);
    unset($GLOBALS['af_adaptivethemeframework_pending_compatibility_normalizer_prefixes']);

    $normalizers = $GLOBALS['af_adaptivethemeframework_compatibility_normalizers'] ?? [];
    $trace = [
        'template' => $templateName,
        'normalizers' => array_keys($normalizers),
        'normalizer_count' => count($normalizers),
        'calls' => [],
    ];
    foreach ($normalizers as $identity => $normalizer) {
        $result = $normalizer($templateName, $current);
        $call = [
            'identity' => (string)$identity,
            'diagnostic_prefix' => (string)($GLOBALS['af_adaptivethemeframework_compatibility_normalizer_prefixes'][$identity] ?? 'normalizer'),
            'called' => true,
            'returned' => is_array($result) ? 'normalized_content' : 'null',
        ];
        if (is_array($result) && array_key_exists('normalized_content', $result)) {
            $call['changed'] = (string)$result['normalized_content'] !== $current;
            $call['normalized_checksum'] = af_adaptivethemeframework_checksum((string)$result['normalized_content']);
            if (isset($result['diagnostic']) && is_array($result['diagnostic'])) {
                $call += $result['diagnostic'];
            }
        }
        $trace['calls'][] = $call;
        if (!is_array($result)
            || !array_key_exists('normalized_content', $result)
            || trim((string)($result['owner'] ?? '')) === ''
            || trim((string)($result['source'] ?? '')) === ''
            || trim((string)($result['transformation_type'] ?? '')) === '') {
            continue;
        }
        $normalized = (string)$result['normalized_content'];
        // A provider cannot claim compatibility without changing any bytes.
        if ($normalized === $current) {
            continue;
        }
        $result['normalized_content'] = $normalized;
        $GLOBALS['af_adaptivethemeframework_normalizer_diagnostic'] = $trace;
        return $result;
    }
    $GLOBALS['af_adaptivethemeframework_normalizer_diagnostic'] = $trace;
    return null;
}

/** Render body-free evidence collected while compatibility normalization ran. */
function af_adaptivethemeframework_normalizer_diagnostic(string $seed, string $current, ?array $compatibility): string
{
    $trace = $GLOBALS['af_adaptivethemeframework_normalizer_diagnostic'] ?? [];
    $lines = [
        'template=' . (string)($trace['template'] ?? ''),
        'normalizers=' . implode(',', (array)($trace['normalizers'] ?? [])),
        'normalizer_count=' . (int)($trace['normalizer_count'] ?? 0),
    ];
    foreach ((array)($trace['calls'] ?? []) as $call) {
        $prefix = (string)($call['diagnostic_prefix'] ?? 'normalizer') . '_';
        $lines[] = $prefix . 'called=yes';
        $lines[] = $prefix . 'returned=' . (string)($call['returned'] ?? 'null');
        if (array_key_exists('changed', $call)) $lines[] = $prefix . 'changed=' . ($call['changed'] ? 'yes' : 'no');
        foreach (['start_marker_count', 'end_marker_count', 'marker_count', 'variable_count', 'normalized_checksum'] as $field) {
            if (array_key_exists($field, $call)) $lines[] = $field . '=' . $call[$field];
        }
    }
    $normalized = $compatibility === null ? $current : (string)$compatibility['normalized_content'];
    $matchesExactSeed = hash_equals(af_adaptivethemeframework_checksum($seed), af_adaptivethemeframework_checksum($current));
    $matchesCanonicalSeed = hash_equals(
        af_adaptivethemeframework_canonical_template_checksum($seed),
        af_adaptivethemeframework_canonical_template_checksum($current)
    );
    $matchesCompatibleSeed = $compatibility !== null && hash_equals(
        af_adaptivethemeframework_checksum($seed),
        af_adaptivethemeframework_checksum($normalized)
    );
    $matchesCanonicalCompatibleSeed = $compatibility !== null && hash_equals(
        af_adaptivethemeframework_canonical_template_checksum($seed),
        af_adaptivethemeframework_canonical_template_checksum($normalized)
    );
    $limit = min(strlen($normalized), strlen($seed));
    $offset = $limit;
    for ($i = 0; $i < $limit; $i++) { if ($normalized[$i] !== $seed[$i]) { $offset = $i; break; } }
    $lines[] = 'matches_exact_seed=' . ($matchesExactSeed ? 'yes' : 'no');
    $lines[] = 'matches_canonical_seed=' . ($matchesCanonicalSeed ? 'yes' : 'no');
    $lines[] = 'matches_compatible_seed=' . ($matchesCompatibleSeed ? 'yes' : 'no');
    $lines[] = 'matches_canonical_compatible_seed=' . ($matchesCanonicalCompatibleSeed ? 'yes' : 'no');
    $matchesCompatibleBoundaryNewlineSeed = $compatibility !== null
        && af_adaptivethemeframework_matches_compatible_boundary_newline($seed, $normalized);
    $lines[] = 'matches_compatible_boundary_newline_seed=' . ($matchesCompatibleBoundaryNewlineSeed ? 'yes' : 'no');
    // Retain the original field for consumers of the existing diagnostic.
    $lines[] = 'matches_seed=' . (($matchesCompatibleSeed || ($compatibility === null && $matchesExactSeed)) ? 'yes' : 'no');
    if (!$matchesCompatibleSeed && !($compatibility === null && $matchesExactSeed)) {
        $lines[] = 'length_current=' . strlen($current);
        $lines[] = 'length_seed=' . strlen($seed);
        $lines[] = 'first_differing_offset=' . $offset;
        $lines[] = af_adaptivethemeframework_byte_diff_diagnostic($current, $seed);
    }
    return implode("\n", $lines);
}

function af_adaptivethemeframework_init(): void
{
    global $plugins;
    af_adaptivethemeframework_register_thread_providers();
    af_adaptivethemeframework_register_post_providers();
    af_adaptivethemeframework_register_profile_providers();
    $isPrivateRoute = defined('THIS_SCRIPT') && THIS_SCRIPT === 'private.php';
    if ($isPrivateRoute) {
        af_adaptivethemeframework_register_pm_providers();
    }
    if (is_object($plugins) && method_exists($plugins, 'add_hook')) {
        $plugins->add_hook('pre_output_page', 'af_adaptivethemeframework_mark_page', 100);
        // MyBB has finished deriving every forumdisplay value at this point.
        // Compose provider slots here rather than teaching providers about the
        // topic-card DOM or patching their legacy template variables.
        $plugins->add_hook('forumdisplay_thread_end', 'af_adaptivethemeframework_compose_thread_card', 100);
        $plugins->add_hook('showthread_end', 'af_adaptivethemeframework_compose_showthread', 100);
        $plugins->add_hook('postbit', 'af_adaptivethemeframework_compose_postbit', 1000);
        $plugins->add_hook('postbit_prev', 'af_adaptivethemeframework_compose_postbit', 1000);
        $plugins->add_hook('postbit_pm', 'af_adaptivethemeframework_compose_postbit', 1000);
        // Run after profile addons have produced their permission-filtered
        // values. The ATF-owned template consumes only these composed slots.
        $plugins->add_hook('member_profile_end', 'af_adaptivethemeframework_compose_profile', 1000);
        if ($isPrivateRoute) {
            // Each hook runs after its surface has prepared the native values,
            // but before its legacy private_* parent template is evaluated.
            foreach ([
                'private_end', 'private_results_end', 'private_advanced_search',
                'private_send_end', 'private_read_end', 'private_tracking_end',
                'private_folders_end', 'private_empty_end', 'private_export_end',
            ] as $hook) {
                $plugins->add_hook($hook, 'af_adaptivethemeframework_compose_pm_workspace', 1000);
            }
        }
    }
}

/** Register native PM workspace fragments without claiming a private_* template. */
function af_adaptivethemeframework_register_pm_providers(): bool
{
    $providers = [
        ['pm_navigation', 'pm.navigation', static fn(array $context): string =>
            (string)($context['native']['usercp_navigation'] ?? '')
            . (string)($context['native']['folder_navigation'] ?? '')
            . (string)($context['native']['folder_options'] ?? '')],
        ['pm_quota', 'pm.quota', static fn(array $context): string =>
            (string)($context['native']['quota'] ?? '')],
        ['pm_notice', 'pm.notice', static fn(array $context): string =>
            (string)($context['native']['limit_warning'] ?? '')],
        ['pm_pagination', 'pm.pagination', static fn(array $context): string =>
            (string)($context['native']['pagination'] ?? '')],
        ['pm_actions', 'pm.actions', static fn(array $context): string =>
            (string)($context['native']['compose_link'] ?? '')
            . (string)($context['native']['empty_export_link'] ?? '')],
    ];
    $registered = false;
    foreach ($providers as [$key, $slot, $renderer]) {
        $registered = af_adaptivethemeframework_register_component([
            'owner' => 'mybb', 'key' => $key, 'slot' => $slot, 'renderer' => $renderer,
        ]) || $registered;
    }
    return $registered;
}

/**
 * Closed PM workspace contract. All HTML is transported after MyBB rendered
 * it; ATF does not derive folders, permissions, quota, actions, or tokens.
 */
function af_adaptivethemeframework_pm_context(array $state, array $values): array
{
    $nativeMap = [
        'usercpnav' => 'usercp_navigation',
        'folderjump' => 'folder_navigation',
        'folderoplist' => 'folder_options',
        'pmspacebar' => 'quota',
        'limitwarning' => 'limit_warning',
        'multipage' => 'pagination',
        'composelink' => 'compose_link',
        'emptyexportlink' => 'empty_export_link',
    ];
    $native = [];
    foreach ($nativeMap as $source => $target) {
        $native[$target] = (string)($values[$source] ?? '');
    }

    return [
        'route' => 'private.php',
        'action' => (string)($state['action'] ?? ''),
        'folder' => [
            'id' => max(0, (int)($state['folder_id'] ?? 0)),
            'name' => (string)($state['folder_name'] ?? ''),
        ],
        'user' => ['uid' => max(0, (int)($state['uid'] ?? 0))],
        'identity' => [
            'url' => (string)($state['current_url'] ?? ''),
            'action' => (string)($state['action'] ?? ''),
        ],
        'native' => $native,
    ];
}

/** Materialize the PM slots while leaving every legacy template untouched. */
function af_adaptivethemeframework_compose_pm_workspace(): void
{
    global $mybb, $fid, $foldername;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'private.php') {
        return;
    }
    $action = is_object($mybb) ? (string)($mybb->input['action'] ?? '') : '';
    $uid = is_object($mybb) ? (int)($mybb->user['uid'] ?? 0) : 0;
    $currentUrl = function_exists('get_current_location')
        ? (string)get_current_location()
        : (string)($_SERVER['REQUEST_URI'] ?? '');
    $names = [
        'usercpnav', 'folderjump', 'folderoplist', 'pmspacebar',
        'limitwarning', 'multipage', 'composelink', 'emptyexportlink',
    ];
    $values = [];
    foreach ($names as $name) {
        $values[$name] = (string)($GLOBALS[$name] ?? '');
    }
    $context = af_adaptivethemeframework_pm_context([
        'action' => $action,
        'folder_id' => (int)($fid ?? ($mybb->input['fid'] ?? 0)),
        'folder_name' => (string)($foldername ?? ''),
        'uid' => $uid,
        'current_url' => $currentUrl,
    ], $values);
    foreach (['navigation', 'quota', 'notice', 'pagination', 'actions',
              'content', 'before_content', 'after_content'] as $name) {
        $GLOBALS['atf_pm_' . $name] = af_adaptivethemeframework_render_slot('pm.' . $name, $context);
    }
    $GLOBALS['atf_pm_context'] = $context;
}

/** Native profile fragments stay rendered by MyBB, preserving its permissions. */
function af_adaptivethemeframework_register_profile_providers(): bool
{
    $registered = af_adaptivethemeframework_register_component([
        'owner' => 'mybb', 'key' => 'profile_main', 'slot' => 'profile.main',
        'renderer' => static fn(array $context): string =>
            (string)($context['native']['profilefields'] ?? '')
            . (string)($context['native']['contact_details'] ?? '')
            . (string)($context['native']['signature'] ?? ''),
    ]);
    $registered = af_adaptivethemeframework_register_component([
        'owner' => 'mybb', 'key' => 'profile_actions', 'slot' => 'profile.after_content',
        'renderer' => static fn(array $context): string => implode('', [
            (string)($context['native']['modoptions'] ?? ''),
            (string)($context['native']['adminoptions'] ?? ''),
            (string)($context['native']['buddy_options'] ?? ''),
            (string)($context['native']['ignore_options'] ?? ''),
            (string)($context['native']['report_options'] ?? ''),
        ]),
    ]) || $registered;
    return $registered;
}

/** A closed, deliberately whitelisted profile context (never $GLOBALS). */
function af_adaptivethemeframework_profile_context(array $member, array $values): array
{
    global $mybb;
    $uid = max(0, (int)($member['uid'] ?? 0));
    $viewerUid = is_object($mybb) ? max(0, (int)($mybb->user['uid'] ?? 0)) : 0;
    $memberKeys = ['uid', 'username', 'usergroup', 'displaygroup', 'avatar', 'usertitle', 'regdate', 'lastactive'];
    return [
        'uid' => $uid,
        'username' => (string)($member['username'] ?? ''),
        'member' => array_intersect_key($member, array_flip($memberKeys)),
        'identity' => array_intersect_key($values, array_flip([
            'formattedname', 'avatar', 'usertitle', 'groupimage', 'userstars',
            'online_status', 'memregdate', 'memlastvisitdate', 'awaybit', 'bannedbit',
        ])),
        'appearance' => [
            'uid_class' => $uid > 0 ? 'af-aa-profile-user-' . $uid : '',
            'payload' => $uid > 0 && function_exists('af_aa_build_user_css_payload')
                ? (array)af_aa_build_user_css_payload($uid)
                : [],
        ],
        'native' => array_intersect_key($values, array_flip([
            'profilefields', 'contact_details', 'signature', 'modoptions',
            'adminoptions', 'buddy_options', 'ignore_options', 'report_options',
        ])),
        'viewer' => ['uid' => $viewerUid, 'is_owner' => $uid > 0 && $viewerUid === $uid],
        'sections' => (array)($values['sections'] ?? []),
        'providers' => (array)($values['providers'] ?? []),
    ];
}

/** Resolve every profile slot for the ATF-owned member_profile template. */
function af_adaptivethemeframework_compose_profile(): void
{
    global $memprofile;
    if (!is_array($memprofile)) return;
    $names = [
        'formattedname', 'avatar', 'usertitle', 'groupimage', 'userstars', 'online_status',
        'memregdate', 'memlastvisitdate', 'awaybit', 'bannedbit', 'profilefields',
        'contact_details', 'signature', 'modoptions', 'adminoptions', 'buddy_options',
        'ignore_options', 'report_options',
    ];
    $values = [];
    foreach ($names as $name) $values[$name] = (string)($GLOBALS[$name] ?? '');
    $values['sections'] = [
        'info' => (string)($GLOBALS['af_apui_forum_info_grid'] ?? ''),
        'sheet' => (string)($GLOBALS['af_apui_character_sheet_tab'] ?? ''),
        'application' => (string)($GLOBALS['af_apui_application_tab'] ?? ''),
        'timeline' => (string)($GLOBALS['af_apui_timeline_tab'] ?? ''),
        'activity' => (string)($GLOBALS['af_apui_activity_tab'] ?? ''),
    ];
    $values['providers'] = [
        'balance' => (string)($memprofile['balance'] ?? ''),
        'post_counter' => (string)($memprofile['advancedpostcounter'] ?? ''),
    ];
    $context = af_adaptivethemeframework_profile_context($memprofile, $values);
    foreach (['hero', 'navigation', 'forum_info', 'character_sheet', 'application', 'timeline',
        'activity', 'balance', 'post_counter', 'before_content', 'main', 'after_content'] as $name) {
        $GLOBALS['atf_profile_' . $name] = af_adaptivethemeframework_render_slot('profile.' . $name, $context);
    }
    $GLOBALS['atf_profile_context'] = $context;
}

/** Register native controls as one indivisible, permission-safe provider. */
function af_adaptivethemeframework_register_post_providers(): bool
{
    return af_adaptivethemeframework_register_component([
        'owner' => 'mybb', 'key' => 'post_actions', 'slot' => 'post.actions',
        'renderer' => static fn(array $context): string => (string)($context['post']['actions_html'] ?? ''),
    ]);
}

/**
 * Closed post-provider contract: pid/tid/uid and a curated `post` payload.
 * The payload is deliberately not the complete MyBB row. Native controls are
 * passed through rather than rebuilt, preserving permissions and tokens.
 */
function af_adaptivethemeframework_post_context(array $post): array
{
    $actionKeys = [
        'button_email', 'button_pm', 'button_www', 'button_find', 'button_rep',
        'button_edit', 'button_quickdelete', 'button_quickrestore', 'button_quote',
        'button_multiquote', 'button_report', 'button_warn', 'button_purgespammer',
        'button_approve', 'button_unapprove', 'button_restore',
        'button_reply_pm', 'button_replyall_pm', 'button_forward_pm', 'button_delete_pm',
    ];
    $actions = '';
    foreach ($actionKeys as $key) $actions .= (string)($post[$key] ?? '');
    $keys = [
        'username', 'profilelink', 'useravatar', 'usertitle', 'groupimage', 'userstars',
        'postdate', 'posturl', 'subject', 'subject_extra', 'icon', 'editedmsg',
        'attachments', 'signature', 'iplogged', 'poststatus', 'input_editreason',
        'af_aa_user_class', 'af_apui_presence_html', 'af_apui_profile_fields_html',
        'af_apui_author_statistics_html', 'af_apui_actionbar_html', 'af_apui_rail_html',
        'af_apui_plaque_html', 'advancedpostcounter', 'af_apc_atf_html',
    ];
    $data = ['actions_html' => $actions];
    foreach ($keys as $key) $data[$key] = (string)($post[$key] ?? '');
    return ['pid' => max(0, (int)($post['pid'] ?? 0)),
        'tid' => max(0, (int)($post['tid'] ?? 0)),
        'uid' => max(0, (int)($post['uid'] ?? 0)), 'post' => $data];
}

/** Materialize every post slot without imposing a DOM or changing templates. */
function af_adaptivethemeframework_compose_postbit(array &$post): void
{
    $context = af_adaptivethemeframework_post_context($post);
    $post['af_atf_context'] = ['pid' => $context['pid'], 'tid' => $context['tid'], 'uid' => $context['uid']];
    $post['af_atf_slots'] = [];
    foreach (af_adaptivethemeframework_slots() as $slot) {
        if (strncmp($slot, 'post.', 5) === 0) {
            $post['af_atf_slots'][$slot] = af_adaptivethemeframework_render_slot($slot, $context);
        }
    }
}

/** Register MyBB-owned thread data which a future showthread layout composes. */
function af_adaptivethemeframework_register_thread_providers(): bool
{
    return af_adaptivethemeframework_register_component([
        'owner' => 'mybb',
        'key' => 'thread_breadcrumbs',
        'slot' => 'thread.breadcrumbs',
        'renderer' => 'af_adaptivethemeframework_render_thread_breadcrumbs',
    ]);
}

/** Breadcrumb HTML is supplied by showthread composition, never recovered from the DOM. */
function af_adaptivethemeframework_render_thread_breadcrumbs(array $context): string
{
    return (string)($context['breadcrumbs_html'] ?? '');
}

/**
 * Closed data contract for a future ATF showthread presentation.
 *
 * Rendered MyBB controls stay explicit because recreating them would risk
 * dropping permissions, tokens, element ids, or JavaScript behaviour.
 */
function af_adaptivethemeframework_showthread_context(array $thread, int $fid, array $rendered = []): array
{
    $context = [
        'tid' => max(0, (int)($thread['tid'] ?? 0)),
        'fid' => max(0, $fid),
        'uid' => max(0, (int)($thread['uid'] ?? 0)),
        'username' => (string)($thread['username'] ?? ''),
        'subject' => (string)($thread['subject'] ?? ''),
        'dateline' => max(0, (int)($thread['dateline'] ?? 0)),
        'lastpost' => max(0, (int)($thread['lastpost'] ?? 0)),
        'posts_html' => (string)($rendered['posts_html'] ?? ''),
        'breadcrumbs_html' => (string)($rendered['breadcrumbs_html'] ?? ''),
    ];
    foreach (['quickreply_html', 'moderation_html', 'poll_html', 'thread_tools_html',
              'pagination_html', 'javascript_html'] as $key) {
        $context[$key] = (string)($rendered[$key] ?? '');
    }
    return $context;
}

/** Compose the five ATF thread slots after MyBB has rendered every control. */
function af_adaptivethemeframework_compose_showthread(): void
{
    global $thread, $fid, $header, $posts, $quickreply, $moderationoptions, $pollbox,
           $multipage, $printthread, $sendthread, $addremovesubscription, $addpoll;
    global $atf_thread_breadcrumbs, $atf_thread_meta, $atf_thread_fields,
           $atf_thread_before_posts, $atf_thread_after_posts;

    if (!is_array($thread)) {
        return;
    }
    // The stock header contains the same late-bound token. Move it rather than
    // duplicating the breadcrumb trail in the ATF shell.
    $header = str_replace('<navigation>', '', (string)$header);
    $context = af_adaptivethemeframework_showthread_context($thread, (int)$fid, [
        // parse_page() expands this native token after the showthread template
        // is evaluated, so the slot receives the final MyBB breadcrumb trail.
        'breadcrumbs_html' => '<navigation>',
        'posts_html' => (string)($posts ?? ''),
        'quickreply_html' => (string)($quickreply ?? ''),
        'moderation_html' => (string)($moderationoptions ?? ''),
        'poll_html' => (string)($pollbox ?? ''),
        'thread_tools_html' => (string)($printthread ?? '') . (string)($sendthread ?? '')
            . (string)($addremovesubscription ?? '') . (string)($addpoll ?? ''),
        'pagination_html' => (string)($multipage ?? ''),
    ]);

    $atf_thread_breadcrumbs = af_adaptivethemeframework_render_slot('thread.breadcrumbs', $context);
    $atf_thread_meta = af_adaptivethemeframework_render_slot('thread.meta', $context);
    $atf_thread_fields = af_adaptivethemeframework_render_slot('thread.atf_fields', $context);
    $atf_thread_before_posts = af_adaptivethemeframework_render_slot('thread.before_posts', $context);
    $atf_thread_after_posts = af_adaptivethemeframework_render_slot('thread.after_posts', $context);
}

/** Remove the table cell owned by a stock child template, retaining its body. */
function af_adaptivethemeframework_topic_cell_content(string $html): string
{
    if (preg_match('~^\s*<td\b[^>]*>(.*)</td>\s*$~is', $html, $match)) {
        return (string)$match[1];
    }
    return $html;
}

/** Compose forumdisplay-only values consumed by the owned topic-card seed. */
function af_adaptivethemeframework_compose_thread_card(): void
{
    global $thread, $fid, $rating, $modbit;

    if (!is_array($thread)) {
        return;
    }
    $context = af_adaptivethemeframework_thread_card_context($thread, (int)$fid);
    $thread['atf_meta_chips'] = af_adaptivethemeframework_render_slot('thread.meta_chips', $context);
    $thread['atf_lastposter_avatar'] = af_adaptivethemeframework_render_slot('thread.lastposter_avatar', $context);

    // The stock rating and moderation child templates are <td> elements. The
    // card is deliberately not a restyled table row, so retain their complete
    // controls/content while dropping only that obsolete outer table cell.
    $thread['atf_rating'] = af_adaptivethemeframework_topic_cell_content((string)($rating ?? ''));
    $thread['atf_modbit'] = af_adaptivethemeframework_topic_cell_content((string)($modbit ?? ''));
}

/** Add the activation marker and resolve server-rendered forum-card slots. */
function af_adaptivethemeframework_mark_page(string &$page): void
{
    $page = (string)preg_replace_callback(
        '~<atf-forum-avatar\s+fid="(\d+)"\s+uid="(\d+)"></atf-forum-avatar>~',
        static function (array $match): string {
            return af_adaptivethemeframework_render_slot(
                'forum.lastposter_avatar',
                ['fid' => (int)$match[1], 'lastposteruid' => (int)$match[2], 'lastposter' => '']
            );
        },
        $page
    );

    if ($page === '' || stripos($page, '<body') === false) {
        return;
    }
    $page = (string)preg_replace_callback(
        '~<body\b([^>]*)>~i',
        static function (array $match): string {
            $attributes = $match[1];
            if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $attributes, $classMatch, PREG_OFFSET_CAPTURE)) {
                $classes = preg_split('~\s+~', trim($classMatch[2][0])) ?: [];
                if (!in_array('atf-active', $classes, true)) {
                    $classes[] = 'atf-active';
                }
                $replacement = 'class=' . $classMatch[1][0] . implode(' ', array_filter($classes)) . $classMatch[1][0];
                $attributes = substr_replace($attributes, $replacement, (int)$classMatch[0][1], strlen($classMatch[0][0]));
            } else {
                $attributes .= ' class="atf-active"';
            }
            return '<body' . $attributes . '>';
        },
        $page,
        1
    );
}

/** The public, compatibility-audited slot catalogue. */
function af_adaptivethemeframework_slots(): array
{
    return [
        'profile.hero', 'profile.navigation', 'profile.forum_info',
        'profile.character_sheet', 'profile.application', 'profile.timeline',
        'profile.activity', 'profile.balance', 'profile.post_counter',
        'profile.before_content', 'profile.main', 'profile.after_content',
        'post.author.identity', 'post.author.meta', 'post.author.profile_fields',
        'post.author.rail', 'post.author.plaque', 'post.author.character',
        'post.post_counter', 'post.before_body', 'post.after_body', 'post.actions',
        'thread.breadcrumbs', 'thread.meta', 'thread.atf_fields',
        'thread.before_posts', 'thread.after_posts', 'forum.lastposter_avatar',
        'thread.lastposter_avatar', 'thread.meta_chips',
        'header.primary_navigation', 'header.secondary_navigation',
        'header.user_navigation', 'header.assets', 'footer.components',
        'footer.modals',
        'pm.navigation', 'pm.quota', 'pm.notice', 'pm.pagination',
        'pm.actions', 'pm.content', 'pm.before_content', 'pm.after_content',
    ];
}

/** Stable provider identity; placement is intentionally not part of it. */
function af_adaptivethemeframework_component_identity(string $owner, string $key): string
{
    return strtolower(trim($owner)) . '::' . strtolower(trim($key));
}

/**
 * Register one server-rendered component.
 *
 * Required fields: owner, key, slot and a callable renderer (or static html).
 * Optional fields: sortorder, visibility, context, legacy and legacy_position.
 * `legacy` is an explicit value/callback, not a DOM marker lookup. This permits
 * a temporary dual render while an addon migrates its old template variable.
 */
function af_adaptivethemeframework_register_component(array $component): bool
{
    $owner = strtolower(trim((string)($component['owner'] ?? '')));
    $key = strtolower(trim((string)($component['key'] ?? '')));
    $slot = strtolower(trim((string)($component['slot'] ?? '')));
    if (!preg_match('~^[a-z][a-z0-9_]*$~', $owner)
        || !preg_match('~^[a-z][a-z0-9_.-]*$~', $key)
        || !in_array($slot, af_adaptivethemeframework_slots(), true)) {
        return false;
    }
    if (!is_callable($component['renderer'] ?? null) && !array_key_exists('html', $component)) {
        return false;
    }

    $identity = af_adaptivethemeframework_component_identity($owner, $key);
    if (isset($GLOBALS['af_adaptivethemeframework_components'][$identity])) {
        return false;
    }

    $component = array_merge([
        'sortorder' => 100,
        'visibility' => true,
        'context' => [],
        'legacy' => null,
        'legacy_position' => 'after',
    ], $component);
    $component['owner'] = $owner;
    $component['key'] = $key;
    $component['slot'] = $slot;
    $component['sortorder'] = (int)$component['sortorder'];
    $component['identity'] = $identity;
    $component['legacy_position'] = $component['legacy_position'] === 'before' ? 'before' : 'after';
    $GLOBALS['af_adaptivethemeframework_components'][$identity] = $component;
    return true;
}

function af_adaptivethemeframework_owner_is_enabled(string $owner): bool
{
    if (in_array($owner, ['mybb', AF_ADAPTIVETHEMEFRAMEWORK_ID], true)) {
        return true;
    }
    if (function_exists('af_is_addon_enabled')) {
        return af_is_addon_enabled($owner);
    }
    global $mybb;
    $setting = 'af_' . $owner . '_enabled';
    return !isset($mybb->settings[$setting]) || (string)$mybb->settings[$setting] === '1';
}

function af_adaptivethemeframework_component_matches_context(array $component, array $context): bool
{
    $rule = $component['context'] ?? [];
    if (is_callable($rule)) {
        return (bool)$rule($context, $component);
    }
    if (!is_array($rule)) {
        return false;
    }
    foreach ($rule as $name => $expected) {
        if (!array_key_exists($name, $context)) {
            return false;
        }
        $allowed = is_array($expected) ? $expected : [$expected];
        if (!in_array($context[$name], $allowed, true)) {
            return false;
        }
    }
    return true;
}

/** @return array<string, mixed> */
function af_adaptivethemeframework_components_for_slot(string $slot, array $context = []): array
{
    if (!in_array($slot, af_adaptivethemeframework_slots(), true)) {
        return [];
    }
    $result = [];
    foreach (($GLOBALS['af_adaptivethemeframework_components'] ?? []) as $identity => $component) {
        if ($component['slot'] !== $slot || !af_adaptivethemeframework_owner_is_enabled($component['owner'])) {
            continue;
        }
        $visible = $component['visibility'];
        if (is_callable($visible)) {
            $visible = $visible($context, $component);
        } elseif (!is_bool($visible) && !is_int($visible)) {
            // An unresolved callback name must not become visible merely
            // because a non-empty string is truthy.
            $visible = false;
        }
        if (!$visible || !af_adaptivethemeframework_component_matches_context($component, $context)) {
            continue;
        }
        $result[$identity] = $component;
    }
    uasort($result, static fn(array $a, array $b): int =>
        [$a['sortorder'], $a['identity']] <=> [$b['sortorder'], $b['identity']]
    );
    return $result;
}

function af_adaptivethemeframework_render_value($value, array $context, array $component): string
{
    return (string)(is_callable($value) ? $value($context, $component) : ($value ?? ''));
}

/** Render all eligible components in deterministic order. */
function af_adaptivethemeframework_render_slot(string $slot, array $context = []): string
{
    $html = '';
    foreach (af_adaptivethemeframework_components_for_slot($slot, $context) as $component) {
        $rendered = array_key_exists('html', $component)
            ? (string)$component['html']
            : af_adaptivethemeframework_render_value($component['renderer'], $context, $component);
        $legacy = af_adaptivethemeframework_render_value($component['legacy'], $context, $component);
        $html .= $component['legacy_position'] === 'before'
            ? $legacy . $rendered
            : $rendered . $legacy;
    }
    return $html;
}

/**
 * Build the deliberately small context shared by future ATF thread cards.
 *
 * Providers receive values, never the complete MyBB thread/global state.  A
 * last-post pid is optional because not every forumdisplay query exposes it.
 */
function af_adaptivethemeframework_thread_card_context(array $thread, int $fid): array
{
    $context = [
        'tid' => max(0, (int)($thread['tid'] ?? 0)),
        'fid' => max(0, $fid),
        'subject' => (string)($thread['subject'] ?? ''),
        'lastposteruid' => max(0, (int)($thread['lastposteruid'] ?? 0)),
        'lastposter' => (string)($thread['lastposter'] ?? ''),
    ];
    if (isset($thread['lastpostpid']) && (int)$thread['lastpostpid'] > 0) {
        $context['lastpostpid'] = (int)$thread['lastpostpid'];
    }
    return $context;
}

function af_adaptivethemeframework_install(): bool
{
    return af_adaptivethemeframework_schema_readiness();
}

function af_adaptivethemeframework_is_installed(): bool
{
    global $db;
    if (!is_object($db)) {
        return true;
    }
    return $db->table_exists(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME);
}

function af_adaptivethemeframework_activate(): bool
{
    return af_adaptivethemeframework_acquire_templates();
}

function af_adaptivethemeframework_deactivate(): bool
{
    return af_adaptivethemeframework_release_templates();
}

function af_adaptivethemeframework_uninstall(): bool
{
    // Keep the ledger: conflicts contain the only durable copy of both versions.
    return af_adaptivethemeframework_release_templates();
}

function af_adaptivethemeframework_index_seed(): string
{
    $seed = @file_get_contents(AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/index.html');
    return is_string($seed) ? $seed : '';
}

/** @return array<string, string> ATF-owned template names and seed files. */
function af_adaptivethemeframework_template_seeds(): array
{
    return [
        'index' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/index.html',
        'forumbit_depth2_forum' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumbit_depth2_forum.html',
        'forumbit_depth2_forum_lastpost' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumbit_depth2_forum_lastpost.html',
        'forumdisplay_thread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumdisplay_thread.html',
        'member_profile' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/member_profile.html',
        'showthread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/showthread.html',
        'postbit_classic' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/postbit_classic.html',
        'private' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private.html',
        'private_messagebit' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_messagebit.html',
        'private_messagebit_icon' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_messagebit_icon.html',
        'private_messagebit_denyreceipt' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_messagebit_denyreceipt.html',
        'private_multiple_recipients' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_multiple_recipients.html',
        'private_multiple_recipients_user' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_multiple_recipients_user.html',
        'private_multiple_recipients_bcc' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_multiple_recipients_bcc.html',
        'private_jump_folders' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_jump_folders.html',
        'private_jump_folders_folder' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_jump_folders_folder.html',
        'private_move' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_move.html',
        'private_orderarrow' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_orderarrow.html',
        'private_pmspace' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_pmspace.html',
        'private_composelink' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_composelink.html',
        'private_emptyexportlink' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_emptyexportlink.html',
        'private_limitwarning' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_limitwarning.html',
        'private_nomessages' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_nomessages.html',
        'private_advanced_search' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_advanced_search.html',
        'private_advanced_search_folders' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_advanced_search_folders.html',
        'private_search_results' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_search_results.html',
        'private_search_messagebit' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_search_messagebit.html',
        'private_search_results_nomessages' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_search_results_nomessages.html',
    ];
}

function af_adaptivethemeframework_checksum(string $content): string
{
    return hash('sha256', $content);
}

/**
 * Canonicalize only transport-level template whitespace for checksum proof.
 *
 * This value must never be persisted as template content.  In particular, it
 * deliberately leaves indentation, internal whitespace, comments and markup
 * untouched.
 */
function af_adaptivethemeframework_canonical_template_content(string $content): string
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $content = (string)preg_replace('/[ \t]+(?=\n)/', '', $content);
    return (string)preg_replace('/\n*\z/', "\n", $content);
}

function af_adaptivethemeframework_canonical_template_checksum(string $content): string
{
    return af_adaptivethemeframework_checksum(
        af_adaptivethemeframework_canonical_template_content($content)
    );
}

/**
 * Prove one very narrow recovery case after a trusted compatibility normalizer:
 * the normalized template is the ATF seed with exactly one logical line break
 * missing. This covers legacy injections whose cleanup consumed the CRLF/LF at
 * the insertion boundary, without accepting arbitrary whitespace or markup edits.
 */
function af_adaptivethemeframework_matches_compatible_boundary_newline(string $seed, string $candidate): bool
{
    $seedCanonical = af_adaptivethemeframework_canonical_template_content($seed);
    $candidateCanonical = af_adaptivethemeframework_canonical_template_content($candidate);

    if ($seedCanonical === $candidateCanonical) {
        return true;
    }

    // After EOL canonicalization, one consumed CRLF becomes exactly one missing "\n".
    if (strlen($seedCanonical) !== strlen($candidateCanonical) + 1) {
        return false;
    }

    $limit = strlen($candidateCanonical);
    $offset = 0;
    while ($offset < $limit && $seedCanonical[$offset] === $candidateCanonical[$offset]) {
        $offset++;
    }

    if (($seedCanonical[$offset] ?? '') !== "\n") {
        return false;
    }

    return substr($seedCanonical, 0, $offset)
        . substr($seedCanonical, $offset + 1)
        === $candidateCanonical;
}

/** Return bounded, escaped byte evidence without logging the whole template. */
function af_adaptivethemeframework_byte_diff_diagnostic(string $current, string $seed): string
{
    $currentLength = strlen($current);
    $seedLength = strlen($seed);
    $limit = min($currentLength, $seedLength);
    $prefix = 0;
    while ($prefix < $limit && $current[$prefix] === $seed[$prefix]) {
        $prefix++;
    }
    $suffix = 0;
    while ($suffix < $currentLength - $prefix
        && $suffix < $seedLength - $prefix
        && $current[$currentLength - $suffix - 1] === $seed[$seedLength - $suffix - 1]) {
        $suffix++;
    }
    $before = min(64, $prefix);
    $currentWindow = substr($current, $prefix - $before, $before + min(64, $currentLength - $prefix));
    $seedWindow = substr($seed, $prefix - $before, $before + min(64, $seedLength - $prefix));
    $escape = static function (string $value): string {
        return addcslashes($value, "\0..\37\177..\377\\\"");
    };
    $hex = static function (string $value, int $offset): string {
        return $offset < strlen($value) ? sprintf('%02x', ord($value[$offset])) : 'EOF';
    };
    return 'common_prefix_length=' . $prefix . "\n"
        . 'common_suffix_length=' . $suffix . "\n"
        . 'current_byte_at_difference=0x' . $hex($current, $prefix) . "\n"
        . 'seed_byte_at_difference=0x' . $hex($seed, $prefix) . "\n"
        . 'current_window_escaped="' . $escape($currentWindow) . '"' . "\n"
        . 'seed_window_escaped="' . $escape($seedWindow) . '"';
}

/** Escape one raw string immediately before passing it to a MyBB write helper. */
function af_adaptivethemeframework_db_string(string $value): string
{
    global $db;
    return $db->escape_string($value);
}

/** Record a body-free activation breadcrumb before every fallible database operation. */
function af_adaptivethemeframework_activation_stage(string $stage): void
{
    $GLOBALS['af_adaptivethemeframework_activation_stage'] = $stage;
    if (empty($GLOBALS['af_adaptivethemeframework_shutdown_registered'])) {
        $GLOBALS['af_adaptivethemeframework_shutdown_registered'] = true;
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $fatal = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
                if (function_exists('af_store_addon_lifecycle_diagnostic')) {
                    af_store_addon_lifecycle_diagnostic(
                        AF_ADAPTIVETHEMEFRAMEWORK_ID,
                        'enable',
                        (string)($GLOBALS['af_adaptivethemeframework_activation_stage'] ?? 'unknown'),
                        $fatal
                    );
                }
                error_log('[ATF activation] stage=' . ($GLOBALS['af_adaptivethemeframework_activation_stage'] ?? 'unknown')
                    . ' fatal=' . $error['message']);
            }
        });
    }
}

function af_adaptivethemeframework_activation_failure(Throwable $error): bool
{
    $stage = (string)($GLOBALS['af_adaptivethemeframework_activation_stage'] ?? 'unknown');
    error_log('[ATF activation] stage=' . $stage . ' exception=' . get_class($error) . ': ' . $error->getMessage());
    if (function_exists('af_store_addon_lifecycle_diagnostic')) {
        af_store_addon_lifecycle_diagnostic(AF_ADAPTIVETHEMEFRAMEWORK_ID, 'enable', $stage, $error);
    }
    return false;
}

/** @return array<string, string> */
function af_adaptivethemeframework_ownership_columns(): array
{
    return [
        'id' => 'int unsigned NOT NULL AUTO_INCREMENT',
        'template_name' => "varchar(120) NOT NULL DEFAULT ''",
        'template_sid' => 'int NOT NULL DEFAULT 0',
        'template_tid' => 'int unsigned NOT NULL DEFAULT 0',
        'previous_exists' => 'tinyint(1) NOT NULL DEFAULT 0',
        'previous_content' => 'mediumtext NOT NULL',
        'previous_checksum' => "char(64) NOT NULL DEFAULT ''",
        'previous_dateline' => 'int unsigned NOT NULL DEFAULT 0',
        'atf_seed_content' => 'mediumtext NOT NULL',
        'atf_seed_checksum' => "char(64) NOT NULL DEFAULT ''",
        'atf_installed_checksum' => "char(64) NOT NULL DEFAULT ''",
        'atf_version' => "varchar(32) NOT NULL DEFAULT ''",
        // A legacy row gains this value and therefore can never prove ownership.
        'ownership_state' => "varchar(24) NOT NULL DEFAULT 'migration_review'",
        'current_content' => 'mediumtext NOT NULL',
        'created_at' => 'int unsigned NOT NULL DEFAULT 0',
        'updated_at' => 'int unsigned NOT NULL DEFAULT 0',
        'restored_at' => 'int unsigned NOT NULL DEFAULT 0',
    ];
}

function af_adaptivethemeframework_ensure_ownership_schema(): bool
{
    global $db;
    if (!is_object($db)) {
        return true;
    }
    $table = AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME;
    af_adaptivethemeframework_activation_stage('ensure_schema');
    if (!$db->table_exists($table)) {
        af_adaptivethemeframework_activation_stage('create_ownership_table');
        $collation = $db->build_create_table_collation();
        // Do not force MyISAM: the server/project default may provide transactional DDL/DML.
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . $table . " (
            id int unsigned NOT NULL AUTO_INCREMENT,
            template_name varchar(120) NOT NULL DEFAULT '', template_sid int NOT NULL DEFAULT 0,
            template_tid int unsigned NOT NULL DEFAULT 0, previous_exists tinyint(1) NOT NULL DEFAULT 0,
            previous_content mediumtext NOT NULL, previous_checksum char(64) NOT NULL DEFAULT '',
            previous_dateline int unsigned NOT NULL DEFAULT 0, atf_seed_content mediumtext NOT NULL,
            atf_seed_checksum char(64) NOT NULL DEFAULT '', atf_installed_checksum char(64) NOT NULL DEFAULT '',
            atf_version varchar(32) NOT NULL DEFAULT '', ownership_state varchar(24) NOT NULL DEFAULT 'migration_review',
            current_content mediumtext NOT NULL, created_at int unsigned NOT NULL DEFAULT 0,
            updated_at int unsigned NOT NULL DEFAULT 0, restored_at int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY template_lease (template_sid, template_name),
            KEY ownership_state (ownership_state), KEY template_tid (template_tid)
        ){$collation}");
    } else {
        foreach (af_adaptivethemeframework_ownership_columns() as $column => $definition) {
            af_adaptivethemeframework_activation_stage('inspect_column[' . $column . ']');
            if (!$db->field_exists($column, $table)) {
                af_adaptivethemeframework_activation_stage('add_column[' . $column . ']');
                $db->add_column($table, $column, $definition);
            }
        }
        $indexes = ['template_lease' => 'UNIQUE KEY template_lease (template_sid, template_name)', 'ownership_state' => 'KEY ownership_state (ownership_state)', 'template_tid' => 'KEY template_tid (template_tid)'];
        foreach ($indexes as $name => $definition) {
            af_adaptivethemeframework_activation_stage('inspect_index[' . $name . ']');
            if (method_exists($db, 'index_exists') && !$db->index_exists($table, $name)) {
                if ($name === 'template_lease') {
                    af_adaptivethemeframework_activation_stage('check_duplicate_leases');
                    $duplicates = $db->write_query(
                        'SELECT template_sid, template_name, COUNT(*) AS cnt FROM '.TABLE_PREFIX.$table
                        .' GROUP BY template_sid, template_name HAVING COUNT(*) > 1 LIMIT 1'
                    );
                    $duplicate = $db->fetch_array($duplicates);
                    if ($duplicate) {
                        throw new RuntimeException(
                            'duplicate ownership leases require manual reconciliation for template set '
                            .(int)$duplicate['template_sid'].' and template '.(string)$duplicate['template_name']
                        );
                    }
                }
                af_adaptivethemeframework_activation_stage('add_index[' . $name . ']');
                $db->write_query('ALTER TABLE ' . TABLE_PREFIX . $table . ' ADD ' . $definition);
            }
        }
    }
    af_adaptivethemeframework_activation_stage('validate_schema');
    if (!$db->table_exists($table)) {
        throw new RuntimeException('ownership table does not exist after schema migration');
    }
    foreach (array_keys(af_adaptivethemeframework_ownership_columns()) as $column) {
        if (!$db->field_exists($column, $table)) {
            throw new RuntimeException('ownership column is missing after schema migration: ' . $column);
        }
    }
    if (method_exists($db, 'index_exists')) {
        foreach (['template_lease', 'ownership_state', 'template_tid'] as $index) {
            if (!$db->index_exists($table, $index)) {
                throw new RuntimeException('ownership index is missing after schema migration: ' . $index);
            }
        }
    }
    return true;
}

/** Schema-only diagnostic path: it never reads from or writes to templates. */
function af_adaptivethemeframework_schema_readiness(): bool
{
    try {
        return af_adaptivethemeframework_ensure_ownership_schema();
    } catch (Throwable $error) {
        return af_adaptivethemeframework_activation_failure($error);
    }
}

/** Acquire reversible, per-template-set leases. Master sid=-2 is read only. */
function af_adaptivethemeframework_acquire_templates(): bool
{
    af_adaptivethemeframework_discover_compatibility_providers();
    foreach (af_adaptivethemeframework_template_seeds() as $name => $path) {
        if (!af_adaptivethemeframework_acquire_template($name, $path)) {
            return false;
        }
    }
    af_adaptivethemeframework_refresh_template_cache();
    return true;
}

/** Build a body-free ownership error containing all checksum evidence. */
function af_adaptivethemeframework_ownership_conflict(
    string $templateName,
    int $sid,
    string $state,
    string $currentChecksum,
    string $previousChecksum,
    string $installedChecksum,
    string $seedChecksum,
    string $diagnostic = ''
): RuntimeException {
    return new RuntimeException(
        "ATF ownership conflict:\n"
        . 'template=' . $templateName . "\n"
        . 'sid=' . $sid . "\n"
        . 'state=' . $state . "\n"
        . 'current=' . $currentChecksum . "\n"
        . 'previous=' . $previousChecksum . "\n"
        . 'installed=' . $installedChecksum . "\n"
        . 'seed=' . $seedChecksum
        . ($diagnostic === '' ? '' : "\n" . $diagnostic)
    );
}

function af_adaptivethemeframework_acquire_template(string $templateName, string $seedPath): bool
{
    global $db;
    if (!is_object($db)) {
        return true;
    }
    try {
        $templateNameSql = $db->escape_string($templateName);
        if (!af_adaptivethemeframework_ensure_ownership_schema()) {
            throw new RuntimeException('ownership schema validation failed');
        }
        af_adaptivethemeframework_activation_stage('validate_template_seed[template=' . $templateName . ']');
        $seed = @file_get_contents($seedPath);
        $seed = is_string($seed) ? $seed : '';
        if ($seed === '') {
            throw new RuntimeException('template seed is empty or unreadable');
        }
        $seedChecksum = af_adaptivethemeframework_checksum($seed);
        $now = defined('TIME_NOW') ? TIME_NOW : time();
        af_adaptivethemeframework_activation_stage('load_master_template[template=' . $templateName . ']');
        $master = $db->fetch_array($db->simple_select('templates', '*', "title='{$templateNameSql}' AND sid='-2'", ['limit' => 1]));
        if (!$master) {
            throw new RuntimeException('master template (sid=-2) was not found');
        }
        af_adaptivethemeframework_activation_stage('load_templatesets');
        $sets = $db->simple_select('templatesets', 'sid', 'sid>0', ['order_by' => 'sid']);
        while ($set = $db->fetch_array($sets)) {
            $sid = (int)$set['sid'];
            af_adaptivethemeframework_activation_stage('inspect_template[template=' . $templateName . ',sid=' . $sid . ']');
            $live = $db->fetch_array($db->simple_select('templates', '*', "title='{$templateNameSql}' AND sid='{$sid}'", ['limit' => 1]));
            $exists = !empty($live);
            $current = (string)($exists ? $live['template'] : $master['template']);
            af_adaptivethemeframework_activation_stage('load_lease[template=' . $templateName . ',sid=' . $sid . ']');
            $lease = $db->fetch_array($db->simple_select(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, '*', "template_sid='{$sid}' AND template_name='{$templateNameSql}'", ['limit' => 1]));

            if ($lease) {
                $currentChecksum = af_adaptivethemeframework_checksum($current);
                $previousChecksum = (string)$lease['previous_checksum'];
                $installedChecksum = (string)$lease['atf_installed_checksum'];
                $previousValid = $previousChecksum !== ''
                    && hash_equals($previousChecksum, af_adaptivethemeframework_checksum((string)$lease['previous_content']));
                $matchesPrevious = $previousValid && hash_equals($previousChecksum, $currentChecksum);
                $matchesInstalled = $installedChecksum !== '' && hash_equals($installedChecksum, $currentChecksum);
                $matchesSeed = hash_equals($seedChecksum, $currentChecksum);
                $matchesCanonicalSeed = $previousValid && !$matchesSeed && hash_equals(
                    af_adaptivethemeframework_canonical_template_checksum($seed),
                    af_adaptivethemeframework_canonical_template_checksum($current)
                );
                $compatibility = (!$matchesPrevious && !$matchesInstalled && !$matchesSeed)
                    ? af_adaptivethemeframework_normalize_compatible_template($templateName, $current)
                    : null;
                $matchesCompatibleSeed = $compatibility !== null
                    && hash_equals($seedChecksum, af_adaptivethemeframework_checksum($compatibility['normalized_content']));
                $matchesCanonicalCompatibleSeed = $compatibility !== null
                    && $previousValid
                    && hash_equals(
                        af_adaptivethemeframework_canonical_template_checksum($seed),
                        af_adaptivethemeframework_canonical_template_checksum(
                            (string)$compatibility['normalized_content']
                        )
                    );
                $matchesCompatibleBoundaryNewlineSeed = $compatibility !== null
                    && $previousValid
                    && af_adaptivethemeframework_matches_compatible_boundary_newline(
                        $seed,
                        (string)$compatibility['normalized_content']
                    );
                $diagnostic = '';
                if (!$matchesPrevious && !$matchesInstalled && !$matchesSeed) {
                    $diagnostic = af_adaptivethemeframework_normalizer_diagnostic($seed, $current, $compatibility)
                        . "\nsid=" . $sid;
                    // Emit the same body-free evidence for both successful
                    // compatibility recovery and fail-closed conflicts.
                    error_log("[ATF compatibility]\n" . $diagnostic);
                }

                // Reconcile every activation from checksum evidence. States such as
                // manual_override are diagnostic, not permanent locks.
                if (!$matchesPrevious && !$matchesInstalled && !($matchesSeed && $previousValid) && !$matchesCanonicalSeed && !$matchesCompatibleSeed && !$matchesCanonicalCompatibleSeed && !$matchesCompatibleBoundaryNewlineSeed) {
                    af_adaptivethemeframework_activation_stage('update_lease[template=' . $templateName . ',sid=' . $sid . ']');
                    $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => af_adaptivethemeframework_db_string('manual_override'), 'current_content' => af_adaptivethemeframework_db_string($current), 'updated_at' => $now], "id='".(int)$lease['id']."'");
                    throw af_adaptivethemeframework_ownership_conflict($templateName, $sid, (string)$lease['ownership_state'], $currentChecksum, $previousChecksum, $installedChecksum, $seedChecksum, $diagnostic);
                }

                // Preserve the original pre-ATF backup. Only normalize ownership
                // and seed metadata; never replace previous_* during recovery.
                af_adaptivethemeframework_activation_stage('update_lease[template=' . $templateName . ',sid=' . $sid . ']');
                $provenInstalledChecksum = ($matchesSeed || $matchesCanonicalSeed || $matchesCompatibleSeed || $matchesCanonicalCompatibleSeed || $matchesCompatibleBoundaryNewlineSeed) ? $seedChecksum : $installedChecksum;
                $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['atf_seed_content' => af_adaptivethemeframework_db_string($seed), 'atf_seed_checksum' => af_adaptivethemeframework_db_string($seedChecksum), 'atf_installed_checksum' => af_adaptivethemeframework_db_string($provenInstalledChecksum), 'atf_version' => af_adaptivethemeframework_db_string(AF_ADAPTIVETHEMEFRAMEWORK_VERSION), 'ownership_state' => af_adaptivethemeframework_db_string('owned'), 'current_content' => af_adaptivethemeframework_db_string(''), 'updated_at' => $now, 'restored_at' => 0], "id='".(int)$lease['id']."'");
                if ($matchesSeed) {
                    continue;
                }
            } else {
                $record = ['template_name' => af_adaptivethemeframework_db_string($templateName), 'template_sid' => $sid, 'template_tid' => $exists ? (int)$live['tid'] : 0, 'previous_exists' => $exists ? 1 : 0, 'previous_content' => af_adaptivethemeframework_db_string($current), 'previous_checksum' => af_adaptivethemeframework_db_string(af_adaptivethemeframework_checksum($current)), 'previous_dateline' => (int)($exists ? ($live['dateline'] ?? 0) : ($master['dateline'] ?? 0)), 'atf_seed_content' => af_adaptivethemeframework_db_string($seed), 'atf_seed_checksum' => af_adaptivethemeframework_db_string($seedChecksum), 'atf_installed_checksum' => af_adaptivethemeframework_db_string($seedChecksum), 'atf_version' => af_adaptivethemeframework_db_string(AF_ADAPTIVETHEMEFRAMEWORK_VERSION), 'ownership_state' => af_adaptivethemeframework_db_string('owned'), 'current_content' => af_adaptivethemeframework_db_string(''), 'created_at' => $now, 'updated_at' => $now, 'restored_at' => 0];
                af_adaptivethemeframework_activation_stage('create_lease[template=' . $templateName . ',sid=' . $sid . ']');
                $db->insert_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, $record);
            }

            af_adaptivethemeframework_activation_stage(($exists ? 'write_template' : 'insert_template_override') . '[template=' . $templateName . ',sid=' . $sid . ']');
            if ($exists) {
                $db->update_query('templates', ['template' => af_adaptivethemeframework_db_string($seed), 'dateline' => $now], "tid='".(int)$live['tid']."'");
                $tid = (int)$live['tid'];
            } else {
                // MyBB 1.8.40 columns: title, template, sid, version, status, dateline.
                $tid = (int)$db->insert_query('templates', ['title' => af_adaptivethemeframework_db_string($templateName), 'template' => af_adaptivethemeframework_db_string($seed), 'sid' => $sid, 'version' => af_adaptivethemeframework_db_string('1840'), 'status' => af_adaptivethemeframework_db_string(''), 'dateline' => $now]);
                $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['template_tid' => $tid], "template_sid='{$sid}' AND template_name='{$templateNameSql}'");
            }
            af_adaptivethemeframework_activation_stage('verify_template[template=' . $templateName . ',sid=' . $sid . ']');
            $installed = $db->fetch_array($db->simple_select('templates', 'tid,template', "title='{$templateNameSql}' AND sid='{$sid}'", ['limit' => 1]));
            if (!$installed || !hash_equals($seedChecksum, af_adaptivethemeframework_checksum((string)$installed['template']))) {
                $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => af_adaptivethemeframework_db_string('write_failed'), 'updated_at' => $now], "template_sid='{$sid}' AND template_name='{$templateNameSql}'");
                throw new RuntimeException('installed template checksum verification failed for template ' . $templateName . ' in template set ' . $sid);
            }
            $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['atf_installed_checksum' => af_adaptivethemeframework_db_string($seedChecksum), 'ownership_state' => af_adaptivethemeframework_db_string('owned'), 'updated_at' => $now], "template_sid='{$sid}' AND template_name='{$templateNameSql}'");
        }
        return true;
    } catch (Throwable $error) {
        return af_adaptivethemeframework_activation_failure($error);
    }
}

function af_adaptivethemeframework_release_templates(): bool
{
    global $db;
    if (!is_object($db)) {
        return true;
    }
    if (!$db->table_exists(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME)) {
        return true;
    }
    $now = defined('TIME_NOW') ? TIME_NOW : time();
    $query = $db->simple_select(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, '*', "ownership_state='owned'");
    $ok = true;
    while ($lease = $db->fetch_array($query)) {
        $sid = (int)$lease['template_sid'];
        $templateNameRaw = (string)$lease['template_name'];
        $templateName = $db->escape_string($templateNameRaw);
        af_adaptivethemeframework_activation_stage('restore[template=' . $templateNameRaw . ',sid=' . $sid . ']');
        $live = $db->fetch_array($db->simple_select('templates', '*', "title='{$templateName}' AND sid='{$sid}'", ['limit' => 1]));
        $liveMatchesInstalled = $live
            && hash_equals((string)$lease['atf_installed_checksum'], af_adaptivethemeframework_checksum((string)$live['template']));
        $storedSeedValid = (string)$lease['atf_seed_checksum'] !== '' && hash_equals(
            (string)$lease['atf_seed_checksum'],
            af_adaptivethemeframework_checksum((string)$lease['atf_seed_content'])
        );
        $liveMatchesCanonicalSeed = $live && $storedSeedValid && hash_equals(
            af_adaptivethemeframework_canonical_template_checksum((string)$lease['atf_seed_content']),
            af_adaptivethemeframework_canonical_template_checksum((string)$live['template'])
        );
        if (!$liveMatchesInstalled && !$liveMatchesCanonicalSeed) {
            $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => af_adaptivethemeframework_db_string('restore_conflict'), 'current_content' => af_adaptivethemeframework_db_string((string)($live['template'] ?? '')), 'updated_at' => $now], "id='".(int)$lease['id']."'");
            $ok = false;
            continue;
        }
        if ((int)$lease['previous_exists'] === 1) {
            $db->update_query('templates', ['template' => af_adaptivethemeframework_db_string((string)$lease['previous_content']), 'dateline' => (int)$lease['previous_dateline']], "tid='".(int)$live['tid']."'");
        } else {
            $db->delete_query('templates', "tid='".(int)$live['tid']."'");
        }
        $restored = $db->fetch_array($db->simple_select('templates', 'tid,template,dateline', "title='{$templateName}' AND sid='{$sid}'", ['limit' => 1]));
        $restoreVerified = (int)$lease['previous_exists'] === 1
            ? ($restored && hash_equals((string)$lease['previous_checksum'], af_adaptivethemeframework_checksum((string)$restored['template'])))
            : !$restored;
        if (!$restoreVerified) {
            $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => af_adaptivethemeframework_db_string('restore_failed'), 'current_content' => af_adaptivethemeframework_db_string((string)($restored['template'] ?? '')), 'updated_at' => $now], "id='".(int)$lease['id']."'");
            $ok = false;
            break;
        }
        $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => af_adaptivethemeframework_db_string('restored'), 'updated_at' => $now, 'restored_at' => $now], "id='".(int)$lease['id']."'");
    }
    af_adaptivethemeframework_refresh_template_cache();
    return $ok;
}

function af_adaptivethemeframework_refresh_template_cache(): void
{
    // MyBB 1.8.40 has no cache_templatesets() API. Templates are loaded from
    // the templates table into the per-request template object, so activation
    // and deactivation require no persistent cache rebuild.
}
